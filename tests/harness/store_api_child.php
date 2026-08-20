<?php
/**
 * Phase 9G-H12 — Residual Correction #19 — real production-execution
 * subprocess harness for Store API isolation scenarios.
 *
 * This script is a separate PHP entrypoint. The PARENT harness shells
 * out to it with --scenario / --rest / --uri / --method / --order
 * arguments and the JSON body via the UPAY_BODY env var (Windows
 * escapeshellarg corrupts JSON via colon-padding).
 *
 * Architecture (current):
 *   1. Define REST_REQUEST = true|false BEFORE production loads.
 *   2. Set REQUEST_URI / REQUEST_METHOD on $_SERVER.
 *   3. Build deliberately contradictory hostile Classic $_POST that
 *      must NEVER win over a valid Store API body when both paths
 *      are present. Classic values are chosen to disagree with the
 *      Store body on every security-sensitive field, so a successful
 *      Store path proves the Store body actually won.
 *   4. Load shared bootstrap (tests/harness/_bootstrap.php), which
 *      provides canonical WP / Woo stubs + FakeWC* + WC_Upayments_*
 *      testable classes used by both the parent harness and this
 *      child. The bootstrap uses class_exists / function_exists
 *      guards so it can be required multiple times safely.
 *   5. Configure a real WC_Upayments_InputTestable whose
 *      get_request_body_raw() returns the supplied Store API body
 *      verbatim; consumption count is tracked so the parent can
 *      distinguish the path that entered production.
 *   6. Decode the supplied body once, extract the submitted
 *      customerUniqueToken candidate and the submitted card_token
 *      (when present) so the transport stubs can dynamically echo
 *      the exact candidate / exact membership back to production
 *      without hard-coding unrelated tokens or membership arrays.
 *   7. Build the production transport envelope for the four routes
 *      (charge, create-customer-unique-token, retrieve-customer-cards,
 *      check-payment-button-status) with transport_ok=true,
 *      http_status=201, curl_errno=0, body=scalar JSON string. The
 *      Charge route uses a deterministic redirect link derived from
 *      the scenario label. The Create-token route echoes the exact
 *      submitted candidate (so production's
 *      token===submitted_candidate equality check passes) inside
 *      data.customerUniqueToken. The Retrieve-cards route uses
 *      data.customerCards (NOT data.cards — production's classifier
 *      at UPayments.php:4343 requires customerCards). Membership is
 *      set by exact-string comparison against the submitted
 *      card_token.
 *   8. Call real process_payment() on a registered FakeWCOrder with
 *      FakeWCOrderItem_Product (extends WC_Order_Item_Product) so
 *      production's instanceof gate passes.
 *   9. Emit machine-readable JSON with: scenario, REST_REQUEST
 *      observed, REQUEST_URI, REQUEST_METHOD, payload_decoded,
 *      process_payment result, notice, transport counters,
 *      transport_log, mutation counters, captured Create / Retrieve
 *      / Charge request bodies, selected_channel.
 *
 * The wrapper upay_run_store_api_child() in the parent harness
 * requires exit === 0 before parsing the JSON. Non-zero exits are
 * returned as a child_error result rather than being parsed as a
 * valid test outcome.
 *
 * Usage:
 *   UPAY_BODY='{"payment_data":{...}}' \
 *   php store_api_child.php --scenario=SP-1 --rest=true \
 *       --uri=/wc/store/v1/checkout --method=POST --order=99999
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

// --- Build deliberately CONTRADICTORY hostile Classic $_POST -------------
//
// The Classic POST is the "control" that must NEVER win over a valid
// Store API body. Every security-sensitive field is set to a value
// that disagrees with the canonical Store body used by the parent
// harness, so a successful Store path proves the Store body actually
// won. Residual Correction #18 used values that mostly agreed with
// the Store body (knet / card_token=0 / save_card=0 / one_time /
// interval=0) which left hostile-Classic vs Store-wins assertions
// meaningless. Residual Correction #19 chooses contradictions:
//
//   payment_method               upayments (must agree for routing)
//   upayment_payment_type        cc       (Store uses knet)
//   card_token                   '9999999988887777' (looks valid, but
//                              Store body has card_token=null or '0')
//   save_card                    '1'      (Store uses '0')
//   upay_subscription_plan       monthly  (Store uses one_time)
//   upay_subscription_interval   '2'      (Store uses '0')
//   upay_unique_id               'HOSTILE_CLASSIC_SHOULD_NOT_WIN'
//   cart_hash                    'hostile_classic_fake_hash'
//
// These hostile values must never appear in any captured Charge
// request body when the Store body is well-formed.
$_POST = [
    'payment_method'             => 'upayments',
    'upayment_payment_type'      => 'cc',
    'card_token'                 => '9999999988887777',
    'save_card'                  => '1',
    'upay_subscription_plan'     => 'monthly',
    'upay_subscription_interval' => '2',
    'upay_unique_id'             => 'HOSTILE_CLASSIC_SHOULD_NOT_WIN',
    'cart_hash'                  => 'hostile_classic_fake_hash',
];

// --- Load shared bootstrap (parent harness preamble) -------------------
$bootstrap = __DIR__ . '/_bootstrap.php';
if (!file_exists($bootstrap)) {
    fwrite(STDERR, "FATAL: bootstrap missing: $bootstrap\n");
    exit(1);
}
require_once $bootstrap;

// --- Decode body once; extract submitted candidate + card_token --------
$body_value = getenv('UPAY_BODY');
$payload = null;
$submitted_token_candidate = null;
$submitted_card_token = null;
if (is_string($body_value) && $body_value !== '') {
    $decoded = json_decode($body_value, true);
    if (is_array($decoded)) {
        $payload = $decoded;
        // Production sources the candidate from either:
        //   - $body['payment_data']['extensions']['upayments']['customer_unique_id']
        //     (canonical-form path)
        //   - $body['payment_data']['customer_unique_id'] (legacy path)
        // Both must agree (hostile Classic uses a different value
        // here, so the Store path must be the one that wins).
        $candidate_a = null;
        $candidate_b = null;
        if (isset($payload['payment_data']['extensions']['upayments']['customer_unique_id'])) {
            $ca = $payload['payment_data']['extensions']['upayments']['customer_unique_id'];
            if (is_string($ca) && $ca !== '') $candidate_a = $ca;
        }
        if (isset($payload['payment_data']['customer_unique_id'])) {
            $cb = $payload['payment_data']['customer_unique_id'];
            if (is_string($cb) && $cb !== '') $candidate_b = $cb;
        }
        if ($candidate_a !== null && $candidate_b !== null && $candidate_a === $candidate_b) {
            $submitted_token_candidate = $candidate_a;
        } elseif ($candidate_a !== null) {
            $submitted_token_candidate = $candidate_a;
        } elseif ($candidate_b !== null) {
            $submitted_token_candidate = $candidate_b;
        }
        // Card token may be sourced from extensions.upayments.card_token
        // (selected-card path) or payment_data.card_token (legacy path).
        $card_a = null;
        $card_b = null;
        if (isset($payload['payment_data']['extensions']['upayments']['card_token'])) {
            $cv = $payload['payment_data']['extensions']['upayments']['card_token'];
            if (is_string($cv) && $cv !== '') $card_a = $cv;
        }
        if (isset($payload['payment_data']['card_token'])) {
            $cv = $payload['payment_data']['card_token'];
            if (is_string($cv) && $cv !== '') $card_b = $cv;
        }
        if ($card_a !== null) $submitted_card_token = $card_a;
        elseif ($card_b !== null) $submitted_card_token = $card_b;
    }
}

// --- Configure state ----------------------------------------------------
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
// FakeWCOrderItem_Product extends WC_Order_Item_Product so production's
// instanceof WC_Order_Item_Product gate passes.
$order->items_meta = [new FakeWCOrderItem_Product($product, 1, '12.50')];
$state['orders_fixture'][(int) $order_id] = $order;
$state['current_user_id'] = 1;
$state['request_uri']    = $uri_value;
$state['request_method'] = $method_value;
$state['rest_request']   = ($rest_normalised === 'true');
$state['input_body']     = is_string($body_value) ? $body_value : '';
$state['post']           = $_POST;
$state['transport_log']  = [];

$ref_suffix = $scenario;
$redirect_link = sprintf(
    'https://example.test/upayments/redirect/%s',
    rawurlencode($ref_suffix)
);

// --- Build the production-shaped Charge envelope -----------------------
// transport_ok = true, http_status = 201, curl_errno = 0,
// body = scalar JSON string. The decoder yields status===true with a
// data.link string, which is the minimal valid success response.
$charge_envelope = [
    'transport_ok' => true,
    'http_status'  => 201,
    'curl_errno'   => 0,
    'body'         => wp_json_encode([
        'status' => true,
        'data'   => ['link' => $redirect_link],
    ]),
];

// --- availability_response ----------------------------------------------
// Production's getPaymentIcons() (UPayments.php line 4475) reads
// `isWhiteLabel` from the upstream response. Using `whitelabled`
// would silently exercise the Non-Whitelabel generic-checkout branch.
$state['availability_response'] = [
    'result'       => 'success',
    'isWhiteLabel' => true,
    'payButtons'   => [
        'knet'           => 1,
        'apple_pay_knet' => 1,
        'credit_card'    => 1,
        'apple_pay'      => 1,
        'samsung_pay'    => 1,
        'google_pay'     => 1,
    ],
];

// --- Create-token envelope: dynamically echo exact submitted candidate --
//
// Residual Correction #18 returned a hard-coded fake
// 'CSTOREAPI12345678' that was neither a valid canonical token (8-18
// numeric digits; CSTOREAPI12345678 starts with C) nor equal to the
// submitted candidate, so production's classifier rejected it with
// reason=token_mismatch at CustomerTokenIdentity.php:1029 and the
// create_token_calls counter was reachable but the result was
// never token_mismatch-passable.
//
// Residual Correction #19: if the parent supplied a valid canonical
// candidate, echo it back verbatim inside data.customerUniqueToken.
// Production's classifier then validates
//     $body['data']['customerUniqueToken'] === $submitted_token
// and passes (reason=success). If no candidate was submitted, the
// envelope reports an empty token (production will fail with
// reason=missing_token), which is the correct fail-closed behavior.
$create_token_body = ['status' => false, 'data' => []];
if ($submitted_token_candidate !== null
    && preg_match('/^[0-9]{8,18}$/', $submitted_token_candidate) === 1
) {
    $create_token_body = [
        'status' => true,
        'data'   => ['customerUniqueToken' => $submitted_token_candidate],
    ];
} elseif ($submitted_token_candidate !== null) {
    // Candidate present but malformed — return the malformed candidate
    // back to production so its own validator (is_valid_canonical_token)
    // performs the rejection. Production must fail closed with
    // reason=invalid_candidate.
    $create_token_body = [
        'status' => true,
        'data'   => ['customerUniqueToken' => $submitted_token_candidate],
    ];
}

// --- Retrieve-cards envelope: use data.customerCards (NOT data.cards) ---
//
// Residual Correction #18 returned data.cards (empty array). Production's
// getSavedCards() (UPayments.php:4343) explicitly requires
//     array_key_exists('customerCards', $result['data'])
// and rejects absent or non-array customerCards. Residual Correction
// #19 uses the production key. Membership is computed by exact-string
// comparison against the submitted card_token (when present):
//   - If submitted_card_token is a non-empty string AND it satisfies
//     the 8-18 digit regex, return customerCards = [{token: ...}];
//     production's membership check then succeeds.
//   - If submitted_card_token is null/empty/non-numeric, return
//     customerCards = []; production's membership check fails closed
//     with no provider mutation.
$retrieve_body = ['status' => true, 'data' => ['customerCards' => []]];
if ($submitted_card_token !== null
    && preg_match('/^[0-9]{8,18}$/', $submitted_card_token) === 1
) {
    $retrieve_body = [
        'status' => true,
        'data'   => [
            'customerCards' => [
                [
                    'token'  => $submitted_card_token,
                    'number' => '****' . substr($submitted_card_token, -4),
                    'brand'  => 'Visa',
                ],
            ],
        ],
    ];
}

// --- check-payment-button-status envelope -------------------------------
$check_status_body = wp_json_encode([
    'status' => true,
    'data'   => [
        'supported'    => true,
        'whitelabled'  => true,
        'isWhiteLabel' => true,
        'payButtons'   => [
            'knet'        => 1,
            'credit_card' => 1,
        ],
    ],
]);

$state['transport_responses_per_route'] = [
    'check-payment-button-status' => [
        'transport_ok' => true,
        'http_status'  => 201,
        'curl_errno'   => 0,
        'body'         => $check_status_body,
    ],
    'create-customer-unique-token' => [
        'transport_ok' => true,
        'http_status'  => 201,
        'curl_errno'   => 0,
        'body'         => wp_json_encode($create_token_body),
    ],
    'retrieve-customer-cards' => [
        'transport_ok' => true,
        'http_status'  => 201,
        'curl_errno'   => 0,
        'body'         => wp_json_encode($retrieve_body),
    ],
    'charge' => $charge_envelope,
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
$create_token_response_token = null;
$retrieve_response_cards = null;
foreach ($final['transport_log'] as $entry) {
    if ($entry['route'] === 'charge') {
        $charge_bodies[] = $entry['body'];
    } elseif ($entry['route'] === 'create-customer-unique-token') {
        $create_token_bodies[] = $entry['body'];
        $dec = json_decode((string) $entry['body'], true);
        if (is_array($dec) && isset($dec['data']['customerUniqueToken'])
            && is_string($dec['data']['customerUniqueToken'])
        ) {
            $create_token_response_token = $dec['data']['customerUniqueToken'];
        }
    } elseif ($entry['route'] === 'retrieve-customer-cards') {
        $retrieve_bodies[] = $entry['body'];
        $dec = json_decode((string) $entry['body'], true);
        if (is_array($dec) && isset($dec['data']['customerCards'])
            && is_array($dec['data']['customerCards'])
        ) {
            $retrieve_response_cards = $dec['data']['customerCards'];
        }
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
    'scenario'                       => $scenario,
    'rest_request_observed'          => defined('REST_REQUEST') ? (bool) REST_REQUEST : false,
    'rest_request_value'             => defined('REST_REQUEST') ? (string) REST_REQUEST : '',
    'is_store_api_via_reflection'    => $debug_is_store_api,
    'request_uri'                    => $uri_value,
    'request_method'                 => $method_value,
    'payload_decoded'                => $payload,
    'submitted_token_candidate'      => $submitted_token_candidate,
    'submitted_card_token'           => $submitted_card_token,
    'create_token_response_token'    => $create_token_response_token,
    'retrieve_response_cards'        => $retrieve_response_cards,
    'process_payment_result'         => $result,
    'process_payment_exception'      => $process_exception,
    'selected_channel'               => $selected_channel,
    'path'                           => $path,
    'body_consumed_count'            => $body_consumed,
    'transport_log'                  => $final['transport_log'],
    'create_token_calls'             => $final['create_token_calls'],
    'retrieve_calls'                 => $final['retrieve_calls'],
    'availability_calls'             => $final['availability_calls'],
    'charge_calls'                   => $final['charge_calls'],
    'last_charge_body'               => $final['last_charge_body'],
    'create_token_bodies'            => $create_token_bodies,
    'retrieve_bodies'                => $retrieve_bodies,
    'charge_bodies'                  => $charge_bodies,
    'option_creates'                 => $final['option_creates'],
    'option_writes'                  => $final['option_writes'],
    'usermeta_writes'                => $final['usermeta_writes'],
    'order_meta_writes'              => $final['order_meta_writes'],
    'identity_writes'                => $final['identity_writes'],
    'provenance_writes'              => $final['provenance_writes'],
    'secret_creates'                 => $final['secret_creates'],
    'notices'                        => $final['notices'],
    'pid'                            => getmypid(),
    'wc_loaded'                      => class_exists('WC_Upayments'),
];

echo json_encode($out) . "\n";
exit(0);
