<?php
/**
 * Minimal fake of the WHMCS runtime used by the SumUp module tests.
 * Implements only the functions the module calls, on a SQLite database.
 */

define('WHMCS', true);

require_once __DIR__ . '/Capsule.php';

use WHMCS\Database\Capsule;

if (getenv('FAKE_SUMUP_URL')) {
    define('SUMUP_WHMCS_API_BASE_URL', getenv('FAKE_SUMUP_URL'));
}

function fake_gateway_config()
{
    return json_decode(file_get_contents(getenv('FAKE_GATEWAY_CONFIG')), true);
}

function getGatewayVariables($name)
{
    $config = fake_gateway_config();
    return $config + [
        'type' => 'Invoices',
        'name' => $name,
        'paymentmethod' => $name,
        'systemurl' => getenv('FAKE_SYSTEM_URL'),
    ];
}

function checkCbInvoiceID($invoiceId, $gatewayName)
{
    if (!Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->exists()) {
        logTransaction($gatewayName, ['invoiceid' => $invoiceId], 'Invoice ID Not Found');
        die('Invoice ID Not Found');
    }
    return (int) $invoiceId;
}

function addInvoicePayment($invoiceId, $transId, $amount, $fee, $gateway)
{
    if (getenv('FAKE_ADD_PAYMENT_DELAY_MS')) {
        usleep((int) getenv('FAKE_ADD_PAYMENT_DELAY_MS') * 1000);
    }
    Capsule::table('tblaccounts')->insert([
        'invoiceid' => (int) $invoiceId,
        'gateway' => $gateway,
        'transid' => $transId,
        'amountin' => (float) $amount,
        'amountout' => 0,
        'fees' => (float) $fee,
    ]);
    if (fake_invoice_balance($invoiceId) <= 0.0001) {
        Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->update(['status' => 'Paid']);
    }
    return true;
}

function fake_invoice_balance($invoiceId)
{
    $invoice = Capsule::table('tblinvoices')->where('id', (int) $invoiceId)->first();
    $paid = 0;
    foreach (Capsule::table('tblaccounts')->where('invoiceid', (int) $invoiceId)->get() as $row) {
        $paid += $row->amountin - $row->amountout;
    }
    return round($invoice->total - $paid, 2);
}

function localAPI($command, $values = [])
{
    if ($command === 'GetInvoice') {
        $invoice = Capsule::table('tblinvoices')->where('id', (int) $values['invoiceid'])->first();
        if (!$invoice) {
            return ['result' => 'error', 'message' => 'Invoice ID Not Found'];
        }
        return ['result' => 'success', 'invoiceid' => $invoice->id, 'total' => $invoice->total, 'balance' => fake_invoice_balance($invoice->id)];
    }
    return ['result' => 'error', 'message' => 'Not implemented in fake'];
}

function logTransaction($gateway, $data, $status)
{
    Capsule::table('tblgatewaylog')->insert([
        'gateway' => $gateway,
        'data' => is_array($data) ? json_encode($data) : (string) $data,
        'result' => $status,
    ]);
}

function logModuleCall($module, $action, $request, $response, $processed = '', $replace = [])
{
    $text = $request . "\n" . $response;
    foreach ($replace as $secret) {
        if ($secret !== '') {
            $text = str_replace($secret, '***', $text);
        }
    }
    Capsule::table('tblmodulelog')->insert(['module' => $module, 'action' => $action, 'data' => $text]);
}
