<?php

namespace SumUpWhmcs;

/**
 * Thin client for the parts of the SumUp REST API this module uses.
 *
 * Authentication uses a SumUp secret API key sent as a bearer token.
 */
class SumUpClient
{
    const BASE_URL = 'https://api.sumup.com';

    private $apiKey;
    private $transport;
    private $baseUrl;
    private $logger;

    /**
     * @param string             $apiKey    SumUp secret API key.
     * @param HttpTransport|null $transport Defaults to cURL.
     * @param callable|null      $logger    fn(string $action, $request, $response, $parsed)
     */
    public function __construct($apiKey, ?HttpTransport $transport = null, ?callable $logger = null, $baseUrl = self::BASE_URL)
    {
        $this->apiKey = trim((string) $apiKey);
        $this->transport = $transport ?: new CurlTransport();
        $this->logger = $logger;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Returns the merchant code of the account that owns the API key.
     */
    public function getMerchantCode()
    {
        $me = $this->request('GET', '/v0.1/me', null, 'GetMerchantProfile');
        if (empty($me['merchant_profile']['merchant_code'])) {
            throw new ApiException('SumUp did not return a merchant code for this API key.', 200, json_encode($me));
        }

        return (string) $me['merchant_profile']['merchant_code'];
    }

    public function createCheckout(array $payload)
    {
        return $this->request('POST', '/v0.1/checkouts', $payload, 'CreateCheckout');
    }

    public function getCheckout($checkoutId)
    {
        return $this->request('GET', '/v0.1/checkouts/' . rawurlencode($checkoutId), null, 'GetCheckout');
    }

    /**
     * Looks up a transaction by its human-readable transaction code.
     */
    public function getTransactionByCode($transactionCode)
    {
        return $this->request(
            'GET',
            '/v0.1/me/transactions?transaction_code=' . rawurlencode($transactionCode),
            null,
            'GetTransaction'
        );
    }

    /**
     * Refunds a transaction. Omit $amount for a full refund.
     */
    public function refundTransaction($transactionId, $amount = null)
    {
        // An empty JSON object requests a full refund.
        $payload = $amount === null ? new \stdClass() : ['amount' => Money::toApi($amount)];

        return $this->request('POST', '/v0.1/me/refund/' . rawurlencode($transactionId), $payload, 'Refund');
    }

    private function request($method, $path, $payload, $action)
    {
        if ($this->apiKey === '') {
            throw new ApiException('The SumUp API key is not configured.', 0, '');
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
            'User-Agent' => 'sumup-whmcs/' . Module::VERSION,
        ];
        $body = null;
        if ($payload !== null) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($payload, JSON_PRESERVE_ZERO_FRACTION);
        }

        list($status, $raw) = $this->transport->send($method, $this->baseUrl . $path, $headers, $body);
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        if ($this->logger) {
            call_user_func($this->logger, $action, $method . ' ' . $path . ($body !== null ? "\n" . $body : ''), $raw, $decoded);
        }

        if ($status < 200 || $status >= 300) {
            throw new ApiException(self::errorMessage($status, $decoded), $status, $raw);
        }
        if ($raw !== '' && !is_array($decoded)) {
            throw new ApiException('SumUp returned an invalid response.', $status, $raw);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private static function errorMessage($status, $decoded)
    {
        $detail = '';
        if (is_array($decoded)) {
            // SumUp returns either an error object or a list of error objects.
            $first = isset($decoded[0]) && is_array($decoded[0]) ? $decoded[0] : $decoded;
            foreach (['message', 'detail', 'error_message', 'title'] as $key) {
                if (!empty($first[$key]) && is_string($first[$key])) {
                    $detail = $first[$key];
                    break;
                }
            }
            if (!empty($first['error_code']) && is_string($first['error_code'])) {
                $detail = $first['error_code'] . ($detail !== '' ? ': ' . $detail : '');
            }
        }

        return 'SumUp API error (HTTP ' . $status . ')' . ($detail !== '' ? ': ' . $detail : '');
    }
}
