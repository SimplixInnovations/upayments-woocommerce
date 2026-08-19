<?php
/**
 * Phase 9G-H12 / Residual Correction #16 — Task 3 (real production execution):
 * Real subprocess Store API isolation harness.
 *
 * This script is intentionally a separate PHP entrypoint. The PARENT
 * harness shells out to it with --scenario / --rest / --uri / --method
 * arguments and the JSON body via the UPAY_BODY env var (Windows
 * escapeshellarg corrupts JSON via colon-padding).
 *
 * Strategy: load the parent harness source, evaluate everything UP TO
 * the "// RUNNER" marker (this gives us the WP/Woo stubs + real
 * UPayments.php + real CustomerTokenIdentity.php + real
 * woocommerceUpaymentsInit()), then execute the scenario.
 *
 * The child:
 *   1. Defines REST_REQUEST = true|false BEFORE production loads.
 *   2. Sets REQUEST_URI / REQUEST_METHOD on $_SERVER.
 *   3. Loads parent harness preamble via `eval` (returns after RUNNER).
 *   4. Configures a hostile Classic $_POST that must NEVER win over a
 *      valid Store API body when both paths are present.
 *   5. Builds a real WC_Upayments_InputTestable whose
 *      get_request_body_raw() returns the supplied Store API body verbatim.
 *   6. Calls real process_payment() on a registered fake order with
 *      deterministic transport stubs that count provider dispatches.
 *   7. Emits machine-readable JSON with: scenario, REST_REQUEST observed,
 *      REQUEST_URI, REQUEST_METHOD, process_payment result, notice,
 *      transport counters, transport_log, mutation counters, captured
 *      Create / Retrieve / Charge request bodies, selected_channel.
 *
 * Usage:
 *   UPAY_BODY='{"payment_data":{...}}' \
 *   php store_api_child.php --scenario=SP-1 --rest=true \
 *       --uri=/wc/store/v1/checkout --method=POST
 *
 * Exit codes:
 *   0   success
 *   1   malformed arguments
 *
 * @package UPayments
 */

if (PHP_VERSION_ID < 70200) {
    fwrite(STDERR, "PHP 7.2+ required\n");
    exit(1);
}

// --- Parse CLI args -------------------------------------------------------
$scenario    = '';
$rest_value  = 'false';
$uri_value   = '';
$method_value = 'GET';
$order_id    = '99999';

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (strpos($arg, '--scenario=') === 0) {
        $scenario = substr($arg, 11);
    } elseif (strpos($arg, '--rest=') === 0) {
        $rest_value = substr($arg, 7);
    } elseif (strpos($arg, '--uri=') === 0) {
        $uri_value = substr($arg, 6);
    } elseif (strpos($arg, '--method=') === 0) {
        $method_value = strtoupper(substr($arg, 9));
    } elseif (strpos($arg, '--order=') === 0) {
        $order_id = substr($arg, 8);
    }
}

if ($scenario === '') {
    fwrite(STDERR, "FATAL: --scenario required\n");
    exit(1);
}

$rest_normalised = ($rest_value === 'true' || $rest_value === '1') ? 'true' : 'false';

// --- Define REST_REQUEST BEFORE production loads ------------------------
if (!defined('REST_REQUEST')) {
    define('REST_REQUEST', ($rest_normalised === 'true'));
}

// --- Set REQUEST_URI / REQUEST_METHOD on $_SERVER -----------------------
$_SERVER['REQUEST_URI']    = $uri_value;
$_SERVER['REQUEST_METHOD'] = $method_value;
$_SERVER['SCRIPT_NAME']    = '/index.php';

// --- Build hostile Classic $_POST (must NEVER win over Store API) -------
$_POST = [
    'payment_method'           => 'upayments',
    'upayment_payment_type'    => 'knet',
    'card_token'               => '0',
    'save_card'                => '0',
    'upay_subscription_plan'   => 'one_time',
    'upay_subscription_interval' => '0',
    'upay_unique_id'           => 'CLASSIC_HOSTILE_VALUE_SHOULD_NOT_WIN',
    'cart_hash'                => 'fake_hash',
];

// --- Load shared bootstrap (parent harness preamble) -------------------
$bootstrap = __DIR__ . '/_bootstrap.php';
if (!file_exists($bootstrap)) {
    fwrite(STDERR, "FATAL: bootstrap missing: $bootstrap\n");
    exit(1);
}
require_once $bootstrap;

// --- Configure transport stubs (deterministic, no real network) ---------
$body_value = getenv('UPAY_BODY');
$payload = null;
if (is_string($body_value) && $body_value !== '') {
    $decoded = json_decode($body_value, true);
    if (is_array($decoded)) $payload = $decoded;
}

$state =& upay_test_state();
$state['orders_fixture'] = [];
$order = new FakeWCOrder((int) $order_id, [
    'currency' => 'KWD',
    'billing'  => [
        'first_name' => 'Ahmed', 'last_name' => 'Test',
        'email'      => 'a@b.test', 'phone'   => '+96512345678',
    ],
]);
$product = new FakeWCProduct(1, 'Test Product', 'simple');
// Must use FakeWCOrderItem_Product (extends WC_Order_Item_Product) so
// production's `instanceof WC_Order_Item_Product` gate at line ~2368 passes.
$order->items_meta = [new FakeWCOrderItem_Product($product, 1, '12.50')];
$state['orders_fixture'][(int) $order_id] = $order;
$state['current_user_id'] = 1;
$state['request_uri']    = $uri_value;
$state['request_method'] = $method_value;
$state['rest_request']   = ($rest_normalised === 'true');
$state['input_body']     = is_string($body_value) ? $body_value : '';
$state['post']           = $_POST;
$state['transport_log']  = [];
$state['transport_responses_per_route'] = [
    'check-payment-button-status' => [
        'status' => 'success', 'error' => null, 'data' => ['supported' => true],
    ],
    'create-customer-unique-token' => [
        'status' => 'success', 'error' => null,
        'data'   => ['customerUniqueToken' => 'CSTOREAPI12345678'],
    ],
    'retrieve-customer-cards' => [
        'status' => 'success', 'error' => null,
        'data'   => ['cards' => []],
    ],
    'charge' => [
        'status' => 'success', 'error' => null,
        'data'   => ['reference' => 'REF-STORE-CHILD-' . $scenario],
    ],
];
// availability_response: production's getPaymentIcons() stub returns this
// verbatim. The production expects `whitelabled` as a boolean (line 2477).
$state['availability_response'] = [
    'result' => 'success',
    'whitelabled' => true,
    'payButtons' => [],
];

// --- Build a real WC_Upayments_InputTestable and override the body seam -
$gateway = new WC_Upayments_InputTestable();
$gateway->input_body = is_string($body_value) ? $body_value : '';

// DEBUG: observe is_store_api_checkout_request directly via reflection so
// the harness can assert WHICH branch production entered. The classifier is
// private; use reflection from the child.
$debug_is_store_api = null;
try {
    $ref = new ReflectionMethod('WC_Upayments', 'is_store_api_checkout_request');
    $ref->setAccessible(true);
    $debug_is_store_api = (bool) $ref->invoke(null);
} catch (Throwable $e) {
    $debug_is_store_api = 'reflection-error: ' . $e->getMessage();
}

// --- Execute real process_payment() -------------------------------------
$result = null;
$process_exception = null;
try {
    $result = $gateway->process_payment((int) $order_id);
} catch (Throwable $e) {
    $process_exception = $e->getMessage();
}

// --- Pull state after execution -----------------------------------------
$final = $GLOBALS['__upay_test_state'];

$charge_bodies = [];
$create_token_bodies = [];
$retrieve_bodies = [];
foreach ($final['transport_log'] as $entry) {
    if ($entry['route'] === 'charge') {
        $charge_bodies[] = $entry['body'];
    } elseif ($entry['route'] === 'create-customer-unique-token') {
        $create_token_bodies[] = $entry['body'];
    } elseif ($entry['route'] === 'retrieve-customer-cards') {
        $retrieve_bodies[] = $entry['body'];
    }
}

$selected_channel = 'no_redirect';
// Path classification: prefer production-internal observation over
// redirect URL. Production failures on both Store API and Classic paths
// return the same classic checkout URL, so redirect URL alone cannot
// distinguish them. Use the body-consumption observation as the
// authoritative signal: production only consumes the raw body when it
// enters the Store API / Blocks code path.
$body_consumed = (int) $gateway->body_consumed_count;
if ($body_consumed > 0) {
    $path = 'store_api';
} elseif (is_array($result) && isset($result['redirect'])) {
    $redir = (string) $result['redirect'];
    if (strpos($redir, '/checkout/') !== false) {
        $path = 'classic';
    } else {
        $path = 'other';
    }
} else {
    $path = 'other';
}
if (is_array($result) && isset($result['redirect'])) {
    $redir = (string) $result['redirect'];
    if (strpos($redir, '/wc/store/v1/checkout') !== false) {
        $selected_channel = 'store_api';
    } elseif (strpos($redir, '/checkout/') !== false) {
        $selected_channel = 'classic';
    }
}

$out = [
    'scenario'                  => $scenario,
    'rest_request_observed'     => defined('REST_REQUEST') ? (bool) REST_REQUEST : false,
    'rest_request_value'        => defined('REST_REQUEST') ? (string) REST_REQUEST : '',
    'is_store_api_via_reflection' => $debug_is_store_api,
    'request_uri'               => $uri_value,
    'request_method'            => $method_value,
    'payload_decoded'           => $payload,
    'process_payment_result'    => $result,
    'process_payment_exception' => $process_exception,
    'selected_channel'          => $selected_channel,
    'path'                      => $path,
    'body_consumed_count'       => $body_consumed,
    'transport_log'             => $final['transport_log'],
    'create_token_calls'        => $final['create_token_calls'],
    'retrieve_calls'            => $final['retrieve_calls'],
    'availability_calls'        => $final['availability_calls'],
    'charge_calls'              => $final['charge_calls'],
    'last_charge_body'          => $final['last_charge_body'],
    'create_token_bodies'       => $create_token_bodies,
    'retrieve_bodies'           => $retrieve_bodies,
    'charge_bodies'             => $charge_bodies,
    'option_creates'            => $final['option_creates'],
    'option_writes'             => $final['option_writes'],
    'usermeta_writes'           => $final['usermeta_writes'],
    'order_meta_writes'         => $final['order_meta_writes'],
    'identity_writes'           => $final['identity_writes'],
    'provenance_writes'         => $final['provenance_writes'],
    'secret_creates'            => $final['secret_creates'],
    'notices'                   => $final['notices'],
    'pid'                       => getmypid(),
    'wc_loaded'                 => class_exists('WC_Upayments'),
];

echo json_encode($out) . "\n";
exit(0);