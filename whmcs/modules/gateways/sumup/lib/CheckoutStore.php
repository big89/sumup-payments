<?php

namespace SumUpWhmcs;

/**
 * Persists the SumUp checkouts created for WHMCS invoices.
 *
 * A record is an associative array with the keys:
 * invoice_id, checkout_id, checkout_reference, mode, merchant_code,
 * invoice_amount, amount, currency, status, hosted_url,
 * transaction_code, transaction_id, processed_at, created_at.
 */
interface CheckoutStore
{
    /**
     * Returns the newest PENDING checkout for the invoice that matches the
     * amount, currency and mode, or null.
     */
    public function findPending($invoiceId, $amount, $currency, $mode);

    public function findByCheckoutId($checkoutId);

    public function findByReference($reference);

    public function insert(array $record);

    public function updateStatus($checkoutId, $status);

    /**
     * Atomically marks a checkout as processed. Returns true only for the
     * first caller, so a payment is never applied twice when the webhook and
     * the customer's return arrive at the same time.
     */
    public function claim($checkoutId, $transactionCode, $transactionId);

    /**
     * Undoes claim() when applying the payment to the invoice failed.
     */
    public function release($checkoutId);
}

/**
 * CheckoutStore backed by the WHMCS database (Laravel query builder).
 */
class CapsuleCheckoutStore implements CheckoutStore
{
    const TABLE = 'mod_sumup_checkouts';

    private static $schemaChecked = false;

    public function __construct()
    {
        if (!self::$schemaChecked) {
            self::ensureSchema();
            self::$schemaChecked = true;
        }
    }

    public static function ensureSchema()
    {
        $schema = \WHMCS\Database\Capsule::schema();
        if ($schema->hasTable(self::TABLE)) {
            return;
        }
        $schema->create(self::TABLE, function ($table) {
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->index();
            $table->string('checkout_id', 64)->unique();
            $table->string('checkout_reference', 90)->unique();
            $table->string('mode', 16);
            $table->string('merchant_code', 32);
            $table->decimal('invoice_amount', 16, 2);
            $table->decimal('amount', 16, 2);
            $table->string('currency', 3);
            $table->string('status', 20);
            $table->text('hosted_url')->nullable();
            $table->string('transaction_code', 64)->nullable();
            $table->string('transaction_id', 64)->nullable();
            $table->dateTime('processed_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });
    }

    private function table()
    {
        return \WHMCS\Database\Capsule::table(self::TABLE);
    }

    private static function toArray($row)
    {
        return $row ? (array) $row : null;
    }

    public function findPending($invoiceId, $amount, $currency, $mode)
    {
        return self::toArray($this->table()
            ->where('invoice_id', (int) $invoiceId)
            ->where('amount', Money::toApi($amount))
            ->where('currency', $currency)
            ->where('mode', $mode)
            ->where('status', 'PENDING')
            ->whereNull('processed_at')
            ->orderBy('id', 'desc')
            ->first());
    }

    public function findByCheckoutId($checkoutId)
    {
        return self::toArray($this->table()->where('checkout_id', $checkoutId)->first());
    }

    public function findByReference($reference)
    {
        return self::toArray($this->table()->where('checkout_reference', $reference)->first());
    }

    public function insert(array $record)
    {
        $now = date('Y-m-d H:i:s');
        $record['created_at'] = $now;
        $record['updated_at'] = $now;
        $this->table()->insert($record);
    }

    public function updateStatus($checkoutId, $status)
    {
        $this->table()->where('checkout_id', $checkoutId)->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function claim($checkoutId, $transactionCode, $transactionId)
    {
        $now = date('Y-m-d H:i:s');
        $affected = $this->table()
            ->where('checkout_id', $checkoutId)
            ->whereNull('processed_at')
            ->update([
                'status' => 'PAID',
                'transaction_code' => $transactionCode,
                'transaction_id' => $transactionId,
                'processed_at' => $now,
                'updated_at' => $now,
            ]);

        return $affected === 1;
    }

    public function release($checkoutId)
    {
        $this->table()->where('checkout_id', $checkoutId)->update([
            'processed_at' => null,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
