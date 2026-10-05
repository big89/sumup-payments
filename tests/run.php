<?php
/**
 * End-to-end tests for the SumUp WHMCS module.
 *
 * Builds a throwaway WHMCS-like web root (fake WHMCS runtime + the real
 * module files), starts it and a fake SumUp API with PHP's built-in web
 * server, and drives the real invoice page, callback and refund code.
 *
 * Usage: php tests/run.php
 */

$repo = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/sumup-whmcs-test-' . getmypid();
$root = $tmp . '/whmcs';
$whmcsPort = 18080 + (getmypid() % 500) * 2;
$sumupPort = $whmcsPort + 1;
$whmcsUrl = "http://127.0.0.1:$whmcsPort/";
$sumupUrl = "http://127.0.0.1:$sumupPort";

// --- Build the web root ------------------------------------------------------
function copyDir($src, $dst)
{
    @mkdir($dst, 0777, true);
    foreach (scandir($src) as $f) {
        if ($f === '.' || $f === '..') continue;
        is_dir("$src/$f") ? copyDir("$src/$f", "$dst/$f") : copy("$src/$f", "$dst/$f");
    }
}
copyDir("$repo/tests/fake-whmcs", $root);
copyDir("$repo/whmcs/modules", "$root/modules");
copy("$root/viewinvoice.php", "$root/cart.php");
file_put_contents("$root/refund.php", <<<'PHP'
<?php
require __DIR__ . '/init.php';
require __DIR__ . '/modules/gateways/sumup.php';
echo json_encode(sumup_refund(getGatewayVariables('sumup') + ['transid' => $argv[1], 'amount' => (float) $argv[2], 'currency' => 'EUR']));
PHP);

$db = "$tmp/whmcs.sqlite";
$pdo = new PDO("sqlite:$db");
$pdo->exec('CREATE TABLE tblinvoices (id INTEGER PRIMARY KEY, total REAL, currency TEXT, status TEXT)');
$pdo->exec('CREATE TABLE tblaccounts (id INTEGER PRIMARY KEY AUTOINCREMENT, invoiceid INTEGER, gateway TEXT, transid TEXT, amountin REAL, amountout REAL, fees REAL)');
$pdo->exec('CREATE TABLE tblgatewaylog (id INTEGER PRIMARY KEY AUTOINCREMENT, gateway TEXT, data TEXT, result TEXT)');
$pdo->exec('CREATE TABLE tblmodulelog (id INTEGER PRIMARY KEY AUTOINCREMENT, module TEXT, action TEXT, data TEXT)');
$pdo->exec('PRAGMA journal_mode = WAL');
foreach ([1 => 50, 2 => 25, 3 => 30, 4 => 40, 5 => 100, 6 => 60, 7 => 70, 8 => 80] as $id => $total) {
    $pdo->exec("INSERT INTO tblinvoices VALUES ($id, $total, 'EUR', 'Unpaid')");
}
$pdo->exec("INSERT INTO tblaccounts (invoiceid, gateway, transid, amountin, amountout, fees) VALUES (8, 'banktransfer', 'BANK1', 30, 0, 0)");

$configFile = "$tmp/gateway.json";
function setConfig(array $overrides = [])
{
    global $configFile;
    file_put_contents($configFile, json_encode($overrides + [
        'apiKey' => 'sup_sk_test_123',
        'merchantCode' => '',
        'checkoutMode' => 'Payment Widget (on invoice page)',
        'widgetLocale' => 'en-GB',
    ]));
}
setConfig();
file_put_contents("$tmp/sumup.json", '');

$env = [
    'FAKE_WHMCS_DB' => $db,
    'FAKE_GATEWAY_CONFIG' => $configFile,
    'FAKE_SYSTEM_URL' => $whmcsUrl,
    'FAKE_SUMUP_URL' => $sumupUrl,
    'FAKE_SUMUP_STATE' => "$tmp/sumup.json",
    'FAKE_ADD_PAYMENT_DELAY_MS' => '300',
    'PHP_CLI_SERVER_WORKERS' => '4',
    'PATH' => getenv('PATH'),
];

// --- Start servers -------------------------------------------------------------
$servers = [];
foreach ([[$sumupPort, "$repo/tests/fake-sumup", "$repo/tests/fake-sumup/server.php"], [$whmcsPort, $root, null]] as $s) {
    $cmd = [PHP_BINARY, '-S', "127.0.0.1:{$s[0]}", '-t', $s[1]];
    if ($s[2]) $cmd[] = $s[2];
    $servers[] = proc_open($cmd, [1 => ['file', "$tmp/server-{$s[0]}.log", 'a'], 2 => ['file', "$tmp/server-{$s[0]}.log", 'a']], $pipes, null, $env);
}
register_shutdown_function(function () use (&$servers, $tmp) {
    foreach ($servers as $p) {
        proc_terminate($p);
    }
    if (getenv('KEEP_TMP')) {
        echo "Kept $tmp\n";
    } else {
        exec('rm -rf ' . escapeshellarg($tmp));
    }
});
foreach ([$whmcsPort, $sumupPort] as $port) {
    for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $port); $i++) usleep(100000);
}

// --- Helpers ---------------------------------------------------------------
function http($method, $url, $body = null)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = substr($raw, 0, $headerSize);
    preg_match('/^Location:\s*(\S+)/mi', $headers, $m);
    return ['status' => $status, 'location' => $m[1] ?? null, 'body' => substr($raw, $headerSize)];
}

$failures = 0;
$passes = 0;
function check($cond, $label, $detail = '')
{
    global $failures, $passes;
    if ($cond) {
        $passes++;
        echo "  ok   $label\n";
    } else {
        $failures++;
        echo "  FAIL $label" . ($detail !== '' ? "\n       $detail" : '') . "\n";
    }
}

function q($sql)
{
    global $db;
    $pdo = new PDO("sqlite:$db");
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function sumupState()
{
    global $sumupUrl;
    return json_decode(http('GET', "$sumupUrl/__test/state")['body'], true);
}

function checkoutFor($invoiceId)
{
    $rows = q("SELECT * FROM mod_sumup_checkouts WHERE invoice_id = $invoiceId ORDER BY id DESC");
    return $rows ? $rows[0] : null;
}

function payments($invoiceId)
{
    return q("SELECT * FROM tblaccounts WHERE invoiceid = $invoiceId AND gateway = 'sumup'");
}

function invoiceStatus($invoiceId)
{
    return q("SELECT status FROM tblinvoices WHERE id = $invoiceId")[0]['status'];
}

$cb = $whmcsUrl . 'modules/gateways/callback/sumup.php';

// --- Scenarios ---------------------------------------------------------------
echo "Widget checkout on the invoice page\n";
$page = http('GET', $whmcsUrl . 'viewinvoice.php?id=1');
$c1 = checkoutFor(1);
check($page['status'] === 200 && strpos($page['body'], 'SumUpCard.mount') !== false, 'invoice page embeds the Payment Widget', substr($page['body'], 0, 500));
check($c1 && strpos($page['body'], $c1['checkout_id']) !== false, 'widget is mounted with the stored checkout id');
check(strpos($page['body'], 'gateway.sumup.com/gateway/ecom/card/v2/sdk.js') !== false, 'loads the SumUp widget SDK');
check(strpos($page['body'], '"locale":"en-GB"') !== false, 'passes the configured locale');
$remote = sumupState()['checkouts'][$c1['checkout_id']] ?? null;
check($remote && $remote['merchant_code'] === 'MTEST123', 'merchant code is detected from the API key');
check($remote && $remote['amount'] == 50 && $remote['currency'] === 'EUR', 'checkout is created for the invoice balance');
check($remote && $remote['return_url'] === $cb, 'webhook URL is sent as return_url', $remote['return_url'] ?? '');
check($remote && strpos($remote['redirect_url'], 'action=return&ref=' . $c1['checkout_reference']) !== false, 'redirect_url carries the checkout reference');

http('GET', $whmcsUrl . 'viewinvoice.php?id=1');
check(count(sumupState()['checkouts']) === 1, 'reloading the invoice reuses the pending checkout');

$cart = http('GET', $whmcsUrl . 'cart.php?id=1');
check(strpos($cart['body'], 'viewinvoice.php?id=1') !== false && strpos($cart['body'], 'SumUpCard') === false, 'other pages link to the invoice instead of creating a checkout');
check(count(sumupState()['checkouts']) === 1, 'no checkout is created outside the invoice page');

echo "Customer returns before payment completes\n";
$r = http('GET', "$cb?action=return&checkout_id={$c1['checkout_id']}");
check($r['status'] === 302 && $r['location'] === $whmcsUrl . 'viewinvoice.php?id=1', 'redirects to the invoice without a result flag', json_encode($r));
check(count(payments(1)) === 0, 'no payment recorded while PENDING');

echo "Successful payment via return\n";
http('POST', "$sumupUrl/__test/pay/{$c1['checkout_id']}", '{}');
$r = http('GET', "$cb?action=return&checkout_id={$c1['checkout_id']}");
check($r['location'] === $whmcsUrl . 'viewinvoice.php?id=1&paymentsuccess=true', 'redirects with paymentsuccess', json_encode($r));
$p = payments(1);
check(count($p) === 1 && $p[0]['amountin'] == 50 && strpos($p[0]['transid'], 'TCODE') === 0, 'payment recorded with the SumUp transaction code', json_encode($p));
check(invoiceStatus(1) === 'Paid', 'invoice is marked Paid');
$tcode1 = $p[0]['transid'] ?? '';

echo "Webhook after the payment was already recorded\n";
$w = http('POST', $cb, ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => $c1['checkout_id']]);
check($w['status'] === 200 && $w['body'] === 'OK', 'webhook acknowledged');
check(count(payments(1)) === 1, 'payment is not recorded twice');
$r = http('GET', "$cb?action=return&checkout_id={$c1['checkout_id']}");
check($r['location'] === $whmcsUrl . 'viewinvoice.php?id=1&paymentsuccess=true' && count(payments(1)) === 1, 'second return is idempotent');

echo "Webhook edge cases\n";
check(http('POST', $cb, ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => 'not-ours'])['status'] === 200, 'unknown checkout is acknowledged');
check(http('POST', $cb, 'garbage')['body'] === 'Ignored', 'malformed body is ignored');
check(http('POST', $cb, ['event_type' => 'OTHER', 'id' => $c1['checkout_id']])['body'] === 'Ignored', 'other event types are ignored');
check(http('GET', $cb)['status'] === 405, 'GET without action is rejected');
$r = http('GET', "$cb?action=return&checkout_id=nope");
check($r['location'] === $whmcsUrl . 'clientarea.php?action=invoices', 'unknown checkout on return goes to the invoice list');

echo "Tampered checkout (amount mismatch)\n";
http('GET', $whmcsUrl . 'viewinvoice.php?id=2');
$c2 = checkoutFor(2);
http('POST', "$sumupUrl/__test/pay/{$c2['checkout_id']}", '{}');
http('POST', "$sumupUrl/__test/set/{$c2['checkout_id']}", ['amount' => 1.0]);
$r = http('GET', "$cb?action=return&checkout_id={$c2['checkout_id']}");
check($r['location'] === $whmcsUrl . 'viewinvoice.php?id=2&paymentfailed=true', 'redirects with paymentfailed', json_encode($r));
check(count(payments(2)) === 0, 'no payment recorded for a mismatched checkout');
check((bool) q("SELECT 1 FROM tblgatewaylog WHERE result = 'Failure' AND data LIKE '%amount does not match%'"), 'mismatch is logged to the gateway log');

echo "Failed payment, then retry\n";
http('GET', $whmcsUrl . 'viewinvoice.php?id=3');
$c3 = checkoutFor(3);
http('POST', "$sumupUrl/__test/set/{$c3['checkout_id']}", ['status' => 'FAILED']);
$r = http('GET', "$cb?action=return&checkout_id={$c3['checkout_id']}");
check($r['location'] === $whmcsUrl . 'viewinvoice.php?id=3&paymentfailed=true', 'failed payment redirects with paymentfailed');
http('GET', $whmcsUrl . 'viewinvoice.php?id=3');
$c3b = checkoutFor(3);
check($c3b['checkout_id'] !== $c3['checkout_id'], 'a new checkout is created after a failure');
check(q("SELECT status FROM mod_sumup_checkouts WHERE checkout_id = '{$c3['checkout_id']}'")[0]['status'] === 'FAILED', 'failed checkout status is stored');

echo "Hosted Checkout\n";
setConfig(['checkoutMode' => 'Hosted Checkout (SumUp payment page)', 'merchantCode' => 'MTEST123']);
$page = http('GET', $whmcsUrl . 'viewinvoice.php?id=4');
$c4 = checkoutFor(4);
check($c4['mode'] === 'hosted' && strpos($page['body'], 'https://checkout.sumup.com/pay/' . $c4['checkout_id']) !== false, 'shows a button to the hosted payment page');
check(strpos(sumupState()['requests'][count(sumupState()['requests']) - 1]['body'], '"hosted_checkout":{"enabled":true}') !== false, 'requests hosted_checkout');
http('POST', "$sumupUrl/__test/pay/{$c4['checkout_id']}", '{}');
$r = http('GET', "$cb?action=return&ref=" . urlencode($c4['checkout_reference']));
check($r['location'] === $whmcsUrl . 'viewinvoice.php?id=4&paymentsuccess=true', 'return via redirect_url reference records the payment', json_encode($r));
check(count(payments(4)) === 1, 'hosted payment recorded once');
setConfig();

echo "Currency conversion (Convert To For Processing)\n";
http('GET', $whmcsUrl . 'viewinvoice.php?id=5&convert=1');
$c5 = checkoutFor(5);
check($c5['currency'] === 'USD' && $c5['amount'] == 120 && $c5['invoice_amount'] == 100, 'charges converted amount, remembers invoice amount', json_encode($c5));
http('POST', "$sumupUrl/__test/pay/{$c5['checkout_id']}", '{}');
http('POST', $cb, ['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => $c5['checkout_id']]);
$p = payments(5);
check(count($p) === 1 && $p[0]['amountin'] == 100 && invoiceStatus(5) === 'Paid', 'invoice credited in its own currency via webhook', json_encode($p));

echo "Webhook and return at the same time\n";
http('GET', $whmcsUrl . 'viewinvoice.php?id=6');
$c6 = checkoutFor(6);
http('POST', "$sumupUrl/__test/pay/{$c6['checkout_id']}", '{}');
$mh = curl_multi_init();
$handles = [];
foreach (range(1, 4) as $i) {
    $ch = curl_init($i % 2 ? "$cb?action=return&checkout_id={$c6['checkout_id']}" : $cb);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    if (!($i % 2)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['event_type' => 'CHECKOUT_STATUS_CHANGED', 'id' => $c6['checkout_id']]));
    }
    curl_multi_add_handle($mh, $ch);
    $handles[] = $ch;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running);
check(count(payments(6)) === 1, 'concurrent notifications record exactly one payment', json_encode(payments(6)));

echo "Partially paid invoice\n";
http('GET', $whmcsUrl . 'viewinvoice.php?id=8');
check(checkoutFor(8)['amount'] == 50, 'checkout is for the remaining balance only');

echo "Refunds\n";
$out = json_decode(shell_exec('cd ' . escapeshellarg($root) . ' && ' . implode(' ', array_map(function ($k, $v) {
    return $k . '=' . escapeshellarg($v);
}, array_keys($env), $env)) . ' ' . PHP_BINARY . ' refund.php ' . escapeshellarg($tcode1) . ' 20'), true);
check(($out['status'] ?? '') === 'success' && strpos($out['transid'], $tcode1 . '-R') === 0, 'partial refund succeeds', json_encode($out));
$refunds = sumupState()['refunds'];
check(count($refunds) === 1 && $refunds[0]['transaction_id'] === 'txn-' . substr($c1['checkout_id'], 0, 8) && $refunds[0]['body'] === '{"amount":20.0}', 'refund is sent for the transaction id with the amount', json_encode($refunds));
$out = json_decode(shell_exec('cd ' . escapeshellarg($root) . ' && ' . implode(' ', array_map(function ($k, $v) {
    return $k . '=' . escapeshellarg($v);
}, array_keys($env), $env)) . ' ' . PHP_BINARY . ' refund.php NOPE 5'), true);
check(($out['status'] ?? '') === 'error', 'refund of an unknown transaction returns an error', json_encode($out));

echo "API errors\n";
setConfig(['apiKey' => 'sup_sk_wrong']);
$page = http('GET', $whmcsUrl . 'viewinvoice.php?id=7');
check(strpos($page['body'], 'alert-danger') !== false && strpos($page['body'], 'currently unavailable') !== false, 'invalid API key shows a friendly error');
check((bool) q("SELECT 1 FROM tblgatewaylog WHERE result = 'Checkout Error' AND data LIKE '%NOT_AUTHORIZED%'"), 'API error is logged');
check(!q("SELECT 1 FROM tblmodulelog WHERE data LIKE '%sup_sk_wrong%' OR data LIKE '%sup_sk_test_123%'"), 'API key never appears in the module log');
setConfig();

echo "\n$passes passed, $failures failed\n";
exit($failures ? 1 : 0);
