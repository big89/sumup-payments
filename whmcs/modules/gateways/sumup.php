<?php
/**
 * SumUp payment gateway module for WHMCS.
 *
 * Customers pay invoices with the SumUp Payment Widget (embedded on the
 * invoice page) or SumUp Hosted Checkout. Payments are confirmed through
 * modules/gateways/callback/sumup.php, both when the customer returns and
 * via SumUp webhooks. Refunds can be issued from the WHMCS admin area.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/sumup/autoload.php';

use SumUpWhmcs\Module;
use SumUpWhmcs\PaymentService;
use SumUpWhmcs\Renderer;

function sumup_MetaData()
{
    return [
        'DisplayName' => 'SumUp',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    ];
}

function sumup_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'SumUp',
        ],
        'apiKey' => [
            'FriendlyName' => 'Secret API Key',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Create one in the SumUp Dashboard under Developer settings &rarr; API keys. Use a sandbox merchant account\'s key for testing.',
        ],
        'merchantCode' => [
            'FriendlyName' => 'Merchant Code',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'Optional. Leave empty to detect it from the API key.',
        ],
        'checkoutMode' => [
            'FriendlyName' => 'Checkout Type',
            'Type' => 'dropdown',
            'Options' => 'Payment Widget (on invoice page),Hosted Checkout (SumUp payment page)',
            'Description' => 'Payment Widget keeps customers on your invoice page; Hosted Checkout redirects them to a page hosted by SumUp.',
        ],
        'widgetLocale' => [
            'FriendlyName' => 'Widget Locale',
            'Type' => 'text',
            'Size' => '10',
            'Description' => 'Optional, for example en-GB, de-DE, fr-FR. Leave empty for automatic.',
        ],
    ];
}

/**
 * Shows the payment form on the invoice page.
 */
function sumup_link($params)
{
    $invoiceId = (int) $params['invoiceid'];
    $systemUrl = $params['systemurl'];
    $payLabel = !empty($params['langpaynow']) ? $params['langpaynow'] : 'Pay Now';

    // Only create a checkout on the invoice page itself; elsewhere (for
    // example the order confirmation page) link to the invoice.
    $script = isset($_SERVER['SCRIPT_NAME']) ? basename($_SERVER['SCRIPT_NAME']) : '';
    if ($script !== 'viewinvoice.php') {
        return Renderer::invoiceButton(Module::invoiceUrl($systemUrl, $invoiceId), $payLabel);
    }

    $mode = Module::mode($params);

    try {
        $record = Module::service($params)->getOrCreateCheckout([
            'invoice_id' => $invoiceId,
            // When "Convert To For Processing" is set, amount/currency are the
            // converted values; the invoice itself is credited in its own currency.
            'invoice_amount' => sumup_invoiceBalance($invoiceId, $params['amount']),
            'amount' => $params['amount'],
            'currency' => $params['currency'],
            'description' => $params['description'],
            'mode' => $mode,
            'webhook_url' => Module::callbackUrl($systemUrl),
            'return_url' => Module::callbackUrl($systemUrl, ['action' => 'return']),
        ]);
    } catch (\Exception $e) {
        logTransaction(Module::NAME, ['invoiceid' => $invoiceId, 'error' => $e->getMessage()], 'Checkout Error');
        return Renderer::error('Online payment is currently unavailable. Please try again later or contact us.');
    }

    if ($mode === PaymentService::MODE_HOSTED) {
        return Renderer::hostedButton($record['hosted_url'], $payLabel);
    }

    return Renderer::widget([
        'checkout_id' => $record['checkout_id'],
        'return_url' => Module::callbackUrl($systemUrl, ['action' => 'return', 'checkout_id' => $record['checkout_id']]),
        'amount' => $record['amount'],
        'currency' => $record['currency'],
        'locale' => isset($params['widgetLocale']) ? trim($params['widgetLocale']) : '',
        'email' => isset($params['clientdetails']['email']) ? $params['clientdetails']['email'] : '',
    ]);
}

/**
 * Returns the invoice's outstanding balance in the invoice currency.
 */
function sumup_invoiceBalance($invoiceId, $fallback)
{
    $invoice = localAPI('GetInvoice', ['invoiceid' => (int) $invoiceId]);
    if (isset($invoice['result'], $invoice['balance']) && $invoice['result'] === 'success') {
        return $invoice['balance'];
    }

    return $fallback;
}

/**
 * Refunds a payment (fully or partially) from the WHMCS admin area.
 */
function sumup_refund($params)
{
    $transactionCode = (string) $params['transid'];

    try {
        $refundId = Module::service($params)->refund($transactionCode, $params['amount']);
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'rawdata' => ['transid' => $transactionCode, 'error' => $e->getMessage()],
        ];
    }

    return [
        'status' => 'success',
        'rawdata' => ['transid' => $transactionCode, 'amount' => $params['amount'], 'refund' => $refundId],
        'transid' => $refundId,
        'fees' => 0,
    ];
}
