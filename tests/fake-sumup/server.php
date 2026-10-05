<?php
/**
 * Fake SumUp API for tests, run with `php -S host:port server.php`.
 * State lives in the JSON file named by FAKE_SUMUP_STATE.
 *
 * Test-only control endpoints:
 *   POST /__test/pay/{checkout_id}    mark checkout PAID with a transaction
 *   POST /__test/set/{checkout_id}    merge the JSON body into the checkout
 *   GET  /__test/state                dump state
 */

const API_KEY = 'sup_sk_test_123';
const MERCHANT = 'MTEST123';

$stateFile = getenv('FAKE_SUMUP_STATE');
$fp = fopen($stateFile, 'c+');
flock($fp, LOCK_EX);
$state = json_decode(stream_get_contents($fp), true) ?: ['checkouts' => [], 'refunds' => [], 'requests' => []];

function respond($status, $body = null)
{
    global $fp, $state;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($state, JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    http_response_code($status);
    header('Content-Type: application/json');
    if ($body !== null) {
        echo json_encode($body, JSON_PRESERVE_ZERO_FRACTION);
    }
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

if (strpos($path, '/__test/') === 0) {
    if ($path === '/__test/state') {
        respond(200, $state);
    }
    if (preg_match('#^/__test/pay/([\w-]+)$#', $path, $m) && isset($state['checkouts'][$m[1]])) {
        $c = &$state['checkouts'][$m[1]];
        $c['status'] = 'PAID';
        $c['transactions'] = [[
            'id' => 'txn-' . substr($m[1], 0, 8),
            'transaction_code' => 'TCODE' . strtoupper(substr($m[1], 0, 6)),
            'amount' => $c['amount'],
            'currency' => $c['currency'],
            'status' => 'SUCCESSFUL',
        ]];
        respond(200, $c);
    }
    if (preg_match('#^/__test/set/([\w-]+)$#', $path, $m) && isset($state['checkouts'][$m[1]])) {
        $state['checkouts'][$m[1]] = array_merge($state['checkouts'][$m[1]], $body);
        respond(200, $state['checkouts'][$m[1]]);
    }
    respond(404, ['message' => 'unknown test endpoint']);
}

$state['requests'][] = ['method' => $method, 'path' => $_SERVER['REQUEST_URI'], 'body' => $raw];

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer ' . API_KEY) {
    respond(401, ['error_code' => 'NOT_AUTHORIZED', 'message' => 'Invalid API key']);
}

if ($method === 'GET' && $path === '/v0.1/me') {
    respond(200, ['merchant_profile' => ['merchant_code' => MERCHANT, 'country' => 'DE']]);
}

if ($method === 'POST' && $path === '/v0.1/checkouts') {
    foreach (['checkout_reference', 'amount', 'currency', 'merchant_code'] as $field) {
        if (!isset($body[$field])) {
            respond(400, [['error_code' => 'MISSING', 'message' => 'Validation error', 'param' => $field]]);
        }
    }
    if ($body['merchant_code'] !== MERCHANT) {
        respond(403, ['error_code' => 'FORBIDDEN', 'message' => 'Merchant code mismatch']);
    }
    foreach ($state['checkouts'] as $c) {
        if ($c['checkout_reference'] === $body['checkout_reference']) {
            respond(409, ['error_code' => 'DUPLICATED_CHECKOUT', 'message' => 'Checkout already exists']);
        }
    }
    $id = sprintf('%08x-%04x-4%03x-a%03x-%012x', mt_rand(), mt_rand(0, 0xffff), mt_rand(0, 0xfff), mt_rand(0, 0xfff), mt_rand());
    $checkout = [
        'id' => $id,
        'checkout_reference' => $body['checkout_reference'],
        'amount' => $body['amount'],
        'currency' => $body['currency'],
        'merchant_code' => $body['merchant_code'],
        'description' => $body['description'] ?? '',
        'return_url' => $body['return_url'] ?? null,
        'redirect_url' => $body['redirect_url'] ?? null,
        'status' => 'PENDING',
        'date' => gmdate('c'),
        'transactions' => [],
    ];
    if (!empty($body['hosted_checkout']['enabled'])) {
        $checkout['hosted_checkout_url'] = 'https://checkout.sumup.com/pay/' . $id;
    }
    $state['checkouts'][$id] = $checkout;
    respond(201, $checkout);
}

if ($method === 'GET' && preg_match('#^/v0.1/checkouts/([\w-]+)$#', $path, $m)) {
    if (!isset($state['checkouts'][$m[1]])) {
        respond(404, ['error_code' => 'NOT_FOUND', 'message' => 'Resource not found']);
    }
    respond(200, $state['checkouts'][$m[1]]);
}

if ($method === 'GET' && $path === '/v0.1/me/transactions') {
    foreach ($state['checkouts'] as $c) {
        foreach ($c['transactions'] as $t) {
            if ($t['transaction_code'] === ($_GET['transaction_code'] ?? '')) {
                respond(200, $t);
            }
        }
    }
    respond(404, ['error_code' => 'NOT_FOUND', 'message' => 'Resource not found']);
}

if ($method === 'POST' && preg_match('#^/v0.1/me/refund/([\w-]+)$#', $path, $m)) {
    $state['refunds'][] = ['transaction_id' => $m[1], 'body' => $raw];
    respond(204);
}

respond(404, ['error_code' => 'NOT_FOUND', 'message' => 'No route']);
