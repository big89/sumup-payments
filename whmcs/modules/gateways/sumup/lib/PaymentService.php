<?php

namespace SumUpWhmcs;

class PaymentResult
{
    const PAID = 'paid';
    const ALREADY_PROCESSED = 'already_processed';
    const PENDING = 'pending';
    const FAILED = 'failed';
    const EXPIRED = 'expired';
    const UNKNOWN = 'unknown';
    const INVALID = 'invalid';

    public $status;
    public $invoiceId;
    public $transactionCode;
    public $amount;
    public $message;

    public function __construct($status, $invoiceId = null, $transactionCode = null, $amount = null, $message = '')
    {
        $this->status = $status;
        $this->invoiceId = $invoiceId;
        $this->transactionCode = $transactionCode;
        $this->amount = $amount;
        $this->message = $message;
    }

    public function isPaid()
    {
        return $this->status === self::PAID || $this->status === self::ALREADY_PROCESSED;
    }
}

/**
 * Creates SumUp checkouts for invoices and turns paid checkouts into
 * invoice payments. Contains no WHMCS calls so it can be unit-tested; the
 * caller passes in callbacks for the WHMCS side.
 */
class PaymentService
{
    const MODE_WIDGET = 'widget';
    const MODE_HOSTED = 'hosted';

    private $client;
    private $store;
    private $merchantCode;

    /**
     * @param string|null $merchantCode Leave empty to detect it from the API key.
     */
    public function __construct(SumUpClient $client, CheckoutStore $store, $merchantCode = null)
    {
        $this->client = $client;
        $this->store = $store;
        $this->merchantCode = trim((string) $merchantCode);
    }

    public function getMerchantCode()
    {
        if ($this->merchantCode === '') {
            $this->merchantCode = $this->client->getMerchantCode();
        }

        return $this->merchantCode;
    }

    /**
     * Returns a payable checkout for the invoice, reusing a pending one for
     * the same amount when possible so page reloads don't create new ones.
     *
     * @param array $o invoice_id, invoice_amount, amount, currency, description,
     *                 mode, webhook_url, return_url
     *
     * @return array The stored checkout record.
     */
    public function getOrCreateCheckout(array $o)
    {
        $invoiceId = (int) $o['invoice_id'];
        $amount = Money::toApi($o['amount']);
        $currency = strtoupper($o['currency']);
        $mode = $o['mode'] === self::MODE_HOSTED ? self::MODE_HOSTED : self::MODE_WIDGET;

        if ($amount <= 0) {
            throw new \InvalidArgumentException('The amount to pay must be greater than zero.');
        }

        $existing = $this->store->findPending($invoiceId, $amount, $currency, $mode);
        if ($existing && $this->isStillPayable($existing)) {
            return $existing;
        }

        $reference = 'WHMCS-' . $invoiceId . '-' . bin2hex(random_bytes(6));
        $merchantCode = $this->getMerchantCode();

        $payload = [
            'checkout_reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'merchant_code' => $merchantCode,
            'description' => (string) $o['description'],
            'return_url' => $o['webhook_url'],
            'redirect_url' => self::appendQuery($o['return_url'], ['ref' => $reference]),
        ];
        if ($mode === self::MODE_HOSTED) {
            $payload['hosted_checkout'] = ['enabled' => true];
        }

        $checkout = $this->client->createCheckout($payload);
        if (empty($checkout['id'])) {
            throw new ApiException('SumUp did not return a checkout id.', 200, json_encode($checkout));
        }
        $hostedUrl = isset($checkout['hosted_checkout_url']) ? (string) $checkout['hosted_checkout_url'] : null;
        if ($mode === self::MODE_HOSTED && !$hostedUrl) {
            throw new ApiException(
                'SumUp did not return a Hosted Checkout URL. Make sure Hosted Checkout is available for your account.',
                200,
                json_encode($checkout)
            );
        }

        $record = [
            'invoice_id' => $invoiceId,
            'checkout_id' => (string) $checkout['id'],
            'checkout_reference' => $reference,
            'mode' => $mode,
            'merchant_code' => $merchantCode,
            'invoice_amount' => Money::toApi($o['invoice_amount']),
            'amount' => $amount,
            'currency' => $currency,
            'status' => isset($checkout['status']) ? (string) $checkout['status'] : 'PENDING',
            'hosted_url' => $hostedUrl,
            'transaction_code' => null,
            'transaction_id' => null,
            'processed_at' => null,
        ];
        $this->store->insert($record);

        return $record;
    }

    private function isStillPayable(array $record)
    {
        try {
            $checkout = $this->client->getCheckout($record['checkout_id']);
        } catch (ApiException $e) {
            if ($e->getHttpStatus() === 404) {
                $this->store->updateStatus($record['checkout_id'], 'NOT_FOUND');
                return false;
            }
            throw $e;
        }

        $status = isset($checkout['status']) ? (string) $checkout['status'] : '';
        if ($status !== 'PENDING') {
            // Paid checkouts are recorded by the webhook / return handler,
            // failed or expired ones are replaced with a new checkout.
            $this->store->updateStatus($record['checkout_id'], $status ?: 'UNKNOWN');
            return false;
        }

        return true;
    }

    /**
     * Checks a checkout with SumUp and, if it is paid, records the payment.
     *
     * Never trust data sent by the browser or a webhook: the checkout is
     * always re-read from the SumUp API and matched against what was stored
     * when it was created.
     *
     * @param string   $checkoutId
     * @param callable $applyPayment      fn(int $invoiceId, string $transactionCode, float $amount)
     * @param callable $transactionExists fn(string $transactionCode): bool
     */
    public function processCheckout($checkoutId, callable $applyPayment, callable $transactionExists)
    {
        $record = $this->store->findByCheckoutId($checkoutId);
        if (!$record) {
            return new PaymentResult(PaymentResult::UNKNOWN, null, null, null, 'Checkout is not known to this WHMCS installation.');
        }
        $invoiceId = (int) $record['invoice_id'];

        if (!empty($record['processed_at'])) {
            return new PaymentResult(PaymentResult::ALREADY_PROCESSED, $invoiceId, $record['transaction_code'], $record['invoice_amount']);
        }

        $checkout = $this->client->getCheckout($record['checkout_id']);

        $mismatch = $this->findMismatch($record, $checkout);
        if ($mismatch !== null) {
            return new PaymentResult(PaymentResult::INVALID, $invoiceId, null, null, $mismatch);
        }

        $status = isset($checkout['status']) ? (string) $checkout['status'] : '';
        if ($status !== 'PAID') {
            $this->store->updateStatus($record['checkout_id'], $status ?: 'UNKNOWN');
            $map = [
                'PENDING' => PaymentResult::PENDING,
                'FAILED' => PaymentResult::FAILED,
                'EXPIRED' => PaymentResult::EXPIRED,
            ];
            return new PaymentResult(isset($map[$status]) ? $map[$status] : PaymentResult::UNKNOWN, $invoiceId);
        }

        list($transactionCode, $transactionId) = self::extractTransaction($checkout);
        if ($transactionCode === null) {
            $transactionCode = (string) $checkout['id'];
        }

        if (call_user_func($transactionExists, $transactionCode)) {
            // Recorded earlier (for example by hand). Just mark it as done.
            $this->store->claim($record['checkout_id'], $transactionCode, $transactionId);
            return new PaymentResult(PaymentResult::ALREADY_PROCESSED, $invoiceId, $transactionCode, $record['invoice_amount']);
        }

        if (!$this->store->claim($record['checkout_id'], $transactionCode, $transactionId)) {
            return new PaymentResult(PaymentResult::ALREADY_PROCESSED, $invoiceId, $transactionCode, $record['invoice_amount']);
        }

        try {
            call_user_func($applyPayment, $invoiceId, $transactionCode, (float) $record['invoice_amount']);
        } catch (\Exception $e) {
            $this->store->release($record['checkout_id']);
            throw $e;
        }

        return new PaymentResult(PaymentResult::PAID, $invoiceId, $transactionCode, (float) $record['invoice_amount']);
    }

    /**
     * Refunds a payment identified by its SumUp transaction code.
     *
     * @return string A reference for the refund to store in WHMCS.
     */
    public function refund($transactionCode, $amount)
    {
        $transaction = $this->client->getTransactionByCode($transactionCode);
        if (empty($transaction['id'])) {
            throw new ApiException('SumUp transaction ' . $transactionCode . ' was not found.', 404, json_encode($transaction));
        }

        $this->client->refundTransaction($transaction['id'], $amount);

        return $transactionCode . '-R' . date('YmdHis');
    }

    private function findMismatch(array $record, array $checkout)
    {
        $checks = [
            'id' => [$record['checkout_id'], isset($checkout['id']) ? $checkout['id'] : null],
            'checkout_reference' => [$record['checkout_reference'], isset($checkout['checkout_reference']) ? $checkout['checkout_reference'] : null],
            'merchant_code' => [$record['merchant_code'], isset($checkout['merchant_code']) ? $checkout['merchant_code'] : null],
            'currency' => [strtoupper($record['currency']), isset($checkout['currency']) ? strtoupper($checkout['currency']) : null],
        ];
        foreach ($checks as $field => $pair) {
            if ((string) $pair[0] !== (string) $pair[1]) {
                return 'Checkout ' . $field . ' does not match (expected ' . $pair[0] . ', got ' . var_export($pair[1], true) . ').';
            }
        }
        if (!isset($checkout['amount']) || !Money::equals($record['amount'], $checkout['amount'])) {
            return 'Checkout amount does not match (expected ' . $record['amount'] . ').';
        }

        return null;
    }

    /**
     * @return array{0:string|null,1:string|null} Transaction code and id.
     */
    private static function extractTransaction(array $checkout)
    {
        $transactions = isset($checkout['transactions']) && is_array($checkout['transactions']) ? $checkout['transactions'] : [];
        $chosen = null;
        foreach ($transactions as $transaction) {
            if (isset($transaction['status']) && $transaction['status'] === 'SUCCESSFUL') {
                $chosen = $transaction;
                break;
            }
        }
        if ($chosen === null && $transactions) {
            $chosen = $transactions[0];
        }

        $code = !empty($chosen['transaction_code']) ? (string) $chosen['transaction_code']
            : (!empty($checkout['transaction_code']) ? (string) $checkout['transaction_code'] : null);
        $id = !empty($chosen['id']) ? (string) $chosen['id']
            : (!empty($checkout['transaction_id']) ? (string) $checkout['transaction_id'] : null);

        return [$code, $id];
    }

    public static function appendQuery($url, array $params)
    {
        return $url . (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
}
