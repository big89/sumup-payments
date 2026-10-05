<?php
/**
 * SumUp callback handler.
 *
 * Handles two kinds of requests:
 *  - GET  ?action=return&checkout_id=... or &ref=...
 *         The customer returns from the Payment Widget or Hosted Checkout.
 *  - POST {"event_type":"CHECKOUT_STATUS_CHANGED","id":"..."}
 *         A SumUp webhook, sent to the checkout's return_url.
 *
 * In both cases the checkout is re-read from the SumUp API before any
 * payment is recorded, so nothing in the request itself is trusted.
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';
require_once __DIR__ . '/../sumup/autoload.php';

use SumUpWhmcs\Module;
use SumUpWhmcs\PaymentResult;

$gatewayModuleName = Module::NAME;
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    die('Module Not Activated');
}

$systemUrl = $gatewayParams['systemurl'];
$isReturn = isset($_GET['action']) && $_GET['action'] === 'return';

/**
 * Applies the payment to the invoice and logs it to the Gateway Log.
 */
$applyPayment = function ($invoiceId, $transactionCode, $amount) use ($gatewayModuleName, $gatewayParams) {
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
    addInvoicePayment($invoiceId, $transactionCode, $amount, 0, $gatewayModuleName);
};

$transactionExists = function ($transactionCode) use ($gatewayModuleName) {
    return \WHMCS\Database\Capsule::table('tblaccounts')
        ->where('gateway', $gatewayModuleName)
        ->where('transid', $transactionCode)
        ->exists();
};

$service = Module::service($gatewayParams);

if ($isReturn) {
    // --- Customer returning to the site -------------------------------------
    $checkoutId = isset($_GET['checkout_id']) ? (string) $_GET['checkout_id'] : '';
    if ($checkoutId === '' && !empty($_GET['ref'])) {
        $store = new \SumUpWhmcs\CapsuleCheckoutStore();
        $record = $store->findByReference((string) $_GET['ref']);
        $checkoutId = $record ? $record['checkout_id'] : '';
    }

    $result = null;
    try {
        if ($checkoutId !== '') {
            $result = $service->processCheckout($checkoutId, $applyPayment, $transactionExists);
        }
    } catch (\Exception $e) {
        logTransaction($gatewayModuleName, ['checkout_id' => $checkoutId, 'error' => $e->getMessage()], 'Error');
    }

    if ($result && $result->status === PaymentResult::PAID) {
        logTransaction($gatewayModuleName, ['checkout_id' => $checkoutId, 'source' => 'return'] + (array) $result, 'Success');
    } elseif ($result && in_array($result->status, [PaymentResult::INVALID, PaymentResult::FAILED], true)) {
        logTransaction($gatewayModuleName, ['checkout_id' => $checkoutId, 'source' => 'return'] + (array) $result, 'Failure');
    }

    if (!$result || !$result->invoiceId) {
        header('Location: ' . rtrim($systemUrl, '/') . '/clientarea.php?action=invoices');
        exit;
    }

    if ($result->isPaid()) {
        $query = ['paymentsuccess' => 'true'];
    } elseif ($result->status === PaymentResult::PENDING) {
        // Still being processed (for example 3-D Secure); the webhook will
        // record the payment once SumUp confirms it.
        $query = [];
    } else {
        $query = ['paymentfailed' => 'true'];
    }

    header('Location: ' . Module::invoiceUrl($systemUrl, $result->invoiceId, $query));
    exit;
}

// --- Webhook -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

$payload = json_decode((string) file_get_contents('php://input'), true);
$checkoutId = is_array($payload) && isset($payload['id']) && is_string($payload['id']) ? $payload['id'] : '';
$eventType = is_array($payload) && isset($payload['event_type']) ? (string) $payload['event_type'] : '';

if ($checkoutId === '' || ($eventType !== '' && $eventType !== 'CHECKOUT_STATUS_CHANGED')) {
    // Acknowledge so SumUp doesn't keep retrying events we don't handle.
    http_response_code(200);
    die('Ignored');
}

try {
    $result = $service->processCheckout($checkoutId, $applyPayment, $transactionExists);
} catch (\Exception $e) {
    logTransaction($gatewayModuleName, ['checkout_id' => $checkoutId, 'source' => 'webhook', 'error' => $e->getMessage()], 'Error');
    // Ask SumUp to retry later.
    http_response_code(500);
    die('Error');
}

$logStatus = [
    PaymentResult::PAID => 'Success',
    PaymentResult::FAILED => 'Failure',
    PaymentResult::EXPIRED => 'Expired',
    PaymentResult::INVALID => 'Invalid',
];
if (isset($logStatus[$result->status])) {
    logTransaction($gatewayModuleName, ['checkout_id' => $checkoutId, 'source' => 'webhook', 'event' => $payload] + (array) $result, $logStatus[$result->status]);
}

http_response_code(200);
echo 'OK';
