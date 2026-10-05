<?php
/**
 * Fake invoice page: renders the gateway's _link output the way WHMCS does.
 * cart.php is a copy used to test the "not on the invoice page" branch.
 */

require __DIR__ . '/init.php';
require_once __DIR__ . '/modules/gateways/sumup.php';

use WHMCS\Database\Capsule;

$invoiceId = (int) $_GET['id'];
$invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
$params = getGatewayVariables('sumup') + [
    'invoiceid' => $invoiceId,
    'description' => 'Invoice #' . $invoiceId,
    'amount' => isset($_GET['convert']) ? round(fake_invoice_balance($invoiceId) * 1.2, 2) : fake_invoice_balance($invoiceId),
    'currency' => isset($_GET['convert']) ? 'USD' : $invoice->currency,
    'clientdetails' => ['email' => 'client@example.com'],
    'langpaynow' => 'Pay Now',
];

echo '<html><body>STATUS:' . $invoice->status . "\n";
if (isset($_GET['paymentsuccess'])) {
    echo "PAYMENT-SUCCESS\n";
}
if (isset($_GET['paymentfailed'])) {
    echo "PAYMENT-FAILED\n";
}
if ($invoice->status === 'Unpaid') {
    echo sumup_link($params);
}
echo '</body></html>';
