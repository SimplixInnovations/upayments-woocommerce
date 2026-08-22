<?php
/**
 * Phase 9G-H12 — Store API subprocess harness.
 *
 * Separate PHP entrypoint for Store API isolation scenarios. The parent
 * harness shells out with --scenario / --rest / --uri / --method / --order
 * arguments and the JSON body via UPAY_BODY env var.
 *
 * Architecture:
 *   1. Define REST_REQUEST before production loads.
 *   2. Set REQUEST_URI / REQUEST_METHOD on $_SERVER.
 *   3. Build hostile Classic $_POST (configurable via UPAY_HOSTILE_CLASSIC
 *      env var) that must never win over a valid Store API body.
 *   4. Load shared bootstrap (_bootstrap.php) for WP/Woo stubs.
 *   5. Configure WC_Upayments_InputTestable with the supplied body.
 *   6. For 'establish_then_select' mode (UPAY_IDENTITY_SETUP):
 *      - Call get_or_establish_token() to create real customer token A
 *      - Rewrite Store body card_token to distinct saved-card token B
 *      - Reset transport/mutation counters before process_payment()
 *   7. Build transport envelopes for four routes:
 *      - charge: deterministic redirect link from scenario label
 *      - create-customer-unique-token: inspects outbound request body,
 *        captures production-generated candidate, echoes it back
 *      - retrieve-customer-cards: inspects outbound customerUniqueToken,
 *        returns scenario-specific customerCards (match/mismatch mode)
 *      - check-payment-button-status: availability response
 *   8. Call real process_payment() on FakeWCOrder with
 *      FakeWCOrderItem_Product (extends WC_Order_Item_Product).
 *   9. Read identity context and provenance for persistence proof.
 *  10. Emit machine-readable JSON with all counters, bodies, and
 *      persistence proof fields.
 *
 * Usage:
 *   UPAY_BODY='{"extensions":{...}}' \
 *   UPAY_IDENTITY_SETUP='{"setup_mode":"establish_then_select",...}' \
 *   UPAY_RETRIEVE_MODE=match|'mismatch' \
 *   UPAY_HOSTILE_CLASSIC='{"upayment_payment_type":"knet",...}' \
 *   php store_api_child.php --scenario=SP-SAVE-CARD --rest=true \
 *       --uri=/wc/store/v1/checkout --method=POST
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
// UPAY_HOSTILE_CLASSIC env var overrides these defaults (JSON).
$hostile_classic_defaults = [
    'payment_method'             => 'upayments',
    'upayment_payment_type'      => 'cc',
    'card_token'                 => '9999999988887777',
    'save_card'                  => '1',
    'upay_subscription_plan'     => 'monthly',
    'upay_subscription_interval' => '2',
    'upay_unique_id'             => 'HOSTILE_CLASSIC_SHOULD_NOT_WIN',
    'cart_hash'                  => 'hostile_classic_fake_hash',
];
$hostile_classic_env = getenv('UPAY_HOSTILE_CLASSIC');
if (is_string($hostile_classic_env) && $hostile_classic_env !== '') {
    $hostile_decoded = json_decode($hostile_classic_env, true);
    if (is_array($hostile_decoded)) {
        $_POST = array_merge($hostile_classic_defaults, $hostile_decoded);
    } else {
        $_POST = $hostile_classic_defaults;
    }
} else {
    $_POST = $hostile_classic_defaults;
}

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
// instanceof gate passes.
$order->items_meta = [new FakeWCOrderItem_Product($product, 1, '12.50')];
$state['orders_fixture'][(int) $order_id] = $order;
$state['current_user_id'] = 1;
$state['request_uri']    = $uri_value;
$state['request_method'] = $method_value;
$state['rest_request']   = ($rest_normalised === 'true');
$state['input_body']     = is_string($body_value) ? $body_value : '';
$state['post']           = $_POST;
$state['transport_log']  = [];

// --- Pre-seed identity/provenance for selected-card scenarios.
// UPAY_IDENTITY_SETUP env var as JSON controls behavior:
//   'setup_mode' = 'establish_then_select': Call get_or_establish_token first,
//                  then use the established token as the selected card.
//   'setup_mode' = 'manual' (or absent): Use the provided secret/meta directly.
$identity_setup = getenv('UPAY_IDENTITY_SETUP');
$identity_setup_decoded = null;
if (is_string($identity_setup) && $identity_setup !== '') {
    $identity_setup_decoded = json_decode($identity_setup, true);
    if (is_array($identity_setup_decoded)) {
        $setup_mode = $identity_setup_decoded['setup_mode'] ?? 'manual';
        if ($setup_mode === 'manual') {
            // Manual mode: pre-seed secret and meta directly.
            if (isset($identity_setup_decoded['secret']) && is_array($identity_setup_decoded['secret'])) {
                $state['options']['upayments_token_identity_secret_v2'] = $identity_setup_decoded['secret'];
            }
            $uid = isset($identity_setup_decoded['user_id']) ? (int) $identity_setup_decoded['user_id'] : 1;
            if (isset($identity_setup_decoded['meta']) && is_array($identity_setup_decoded['meta'])) {
                foreach ($identity_setup_decoded['meta'] as $key => $value) {
                    if (!isset($state['usermeta'][$uid])) $state['usermeta'][$uid] = [];
                    $state['usermeta'][$uid][$key] = [$value];
                }
            }
        }
        // 'establish_then_select' mode is handled AFTER gateway construction below.
    }
}

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

// --- Create-token envelope: dynamically inspect actual outbound request body --
//
// Residual Correction #20: The child must NOT precompute the Create response
// from the inbound body. Production generates the candidate via
// generate_canonical_token() which uses random_int(10000000, 99999999).
// The actual candidate exists only when production sends the outbound Create
// request. Therefore the transport stub must inspect the actual $body at
// dispatch time, extract the customerUniqueToken, validate it, and echo it.
$create_token_callback = function($outbound_body) {
    $decoded = json_decode((string) $outbound_body, true);
    $candidate = null;
    if (is_array($decoded) && isset($decoded['customerUniqueToken'])
        && is_string($decoded['customerUniqueToken'])
    ) {
        $candidate = $decoded['customerUniqueToken'];
    }
    if ($candidate !== null && preg_match('/^[1-9][0-9]{7}$/', $candidate) === 1) {
        $response = [
            'transport_ok' => true,
            'http_status'  => 201,
            'curl_errno'   => 0,
            'body'         => wp_json_encode([
                'status' => true,
                'data'   => ['customerUniqueToken' => $candidate],
            ]),
        ];
        $state =& upay_test_state();
        $state['create_token_response_token'] = $candidate;
        return $response;
    }
    return [
        'transport_ok' => true,
        'http_status'  => 201,
        'curl_errno'   => 0,
        'body'         => wp_json_encode(['status' => false, 'data' => []]),
    ];
};

// --- Retrieve-cards envelope: dynamically inspect actual outbound request body.
// Use a reference so the callback sees updates from establish_then_select mode.
$retrieve_mode = getenv('UPAY_RETRIEVE_MODE') ?: 'match';
$_submitted_card_token_ref = &$submitted_card_token;
$retrieve_callback = function($outbound_body) use (&$_submitted_card_token_ref, $retrieve_mode) {
    $decoded = json_decode((string) $outbound_body, true);
    $outbound_token = null;
    if (is_array($decoded) && isset($decoded['customerUniqueToken'])
        && is_string($decoded['customerUniqueToken'])
    ) {
        $outbound_token = $decoded['customerUniqueToken'];
    }
    $state =& upay_test_state();
    $state['retrieve_outbound_token'] = $outbound_token;
    if ($_submitted_card_token_ref !== null
        && preg_match('/^[0-9]{8,18}$/', $_submitted_card_token_ref) === 1
    ) {
        if ($retrieve_mode === 'mismatch') {
            $cards = [['token' => '7654321098765432', 'number' => '****5432', 'brand' => 'Mastercard']];
            $state['retrieve_response_cards'] = $cards;
            return [
                'transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0,
                'body' => wp_json_encode(['status' => true, 'data' => ['customerCards' => $cards]]),
            ];
        }
        $cards = [['token' => $_submitted_card_token_ref, 'number' => '****' . substr($_submitted_card_token_ref, -4), 'brand' => 'Visa']];
        $state['retrieve_response_cards'] = $cards;
        return [
            'transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0,
            'body' => wp_json_encode(['status' => true, 'data' => ['customerCards' => $cards]]),
        ];
    }
    $state['retrieve_response_cards'] = [];
    return [
        'transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0,
        'body' => wp_json_encode(['status' => true, 'data' => ['customerCards' => []]]),
    ];
};

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
    'create-customer-unique-token' => $create_token_callback,
    'retrieve-customer-cards' => $retrieve_callback,
    'charge' => $charge_envelope,
];

// --- Build a real WC_Upayments_InputTestable and override the body seam -
// Set API key in state options BEFORE constructing gateway (constructor reads it).
$state['options']['woocommerce_test_api_key'] = 'test_api_key';
$state['options']['woocommerce_live_api_key'] = '';
$state['options']['woocommerce_upayments_settings'] = [
    'enable_save_card' => 'yes',
    'enable_subscriptions' => 'no',
    'testmode' => 'no',
    'test_mode' => 'no',
    'api_key' => 'test_api_key',
];
$gateway = new WC_Upayments_InputTestable();
$gateway->input_body = is_string($body_value) ? $body_value : '';

// --- establish_then_select mode: Call get_or_establish_token to create a
// real token with correct scope/generation, then use a distinct card token.
// setup must include 'card_token' for the saved card (B), distinct from
// the established customer token (A).
if (is_array($identity_setup_decoded) && ($identity_setup_decoded['setup_mode'] ?? '') === 'establish_then_select') {
    $established = \UPayments\Token\CustomerTokenIdentity::get_or_establish_token(
        $state['current_user_id'],
        'test_api_key',
        false,
        function($candidate) use ($gateway) {
            $params = wp_json_encode(array('customerUniqueToken' => $candidate));
            return $gateway->execute_upayments_request('create-customer-unique-token', 'POST', $params);
        }
    );
    if ($established['success']) {
        $established_token = $established['token'];
        $state['established_token'] = $established_token;
        $state['established_scope'] = $established['scope'];
        $state['established_generation'] = $established['secret_generation_id'];
        // Use the distinct card_token from setup (B), not the established token (A).
        $card_token_b = $identity_setup_decoded['card_token'] ?? null;
        if ($card_token_b !== null) {
            $body_decoded = json_decode($gateway->input_body, true);
            if (is_array($body_decoded) && isset($body_decoded['extensions']['upayments'])) {
                $body_decoded['extensions']['upayments']['card_token'] = $card_token_b;
                $gateway->input_body = wp_json_encode($body_decoded);
                $submitted_card_token = $card_token_b;
            }
        }
        // Reset transport and mutation counters for the actual process_payment run.
        $state['create_token_calls'] = 0;
        $state['retrieve_calls'] = 0;
        $state['charge_calls'] = 0;
        $state['transport_log'] = [];
        $state['usermeta_writes'] = 0;
        $state['order_meta_writes'] = 0;
        $state['identity_writes'] = 0;
        $state['provenance_writes'] = 0;
        $state['secret_creates'] = 0;
    }
}

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
$create_token_response_token = $final['create_token_response_token'] ?? null;
$retrieve_response_cards = $final['retrieve_response_cards'] ?? null;
foreach ($final['transport_log'] as $entry) {
    if ($entry['route'] === 'charge') {
        $charge_bodies[] = $entry['body'];
    } elseif ($entry['route'] === 'create-customer-unique-token') {
        $create_token_bodies[] = $entry['body'];
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

// --- Read identity context and provenance for persistence proof ---------
$identity_context_state = null;
$identity_scope = null;
$identity_generation = null;
$provenance_state = null;
$provenance_token = null;
$provenance_kind = null;
$provenance_source = null;
$provenance_scope = null;
$provenance_generation = null;
try {
    $ctx = \UPayments\Token\CustomerTokenIdentity::read_existing_identity_context('test_api_key', false);
    $identity_context_state = $ctx['state'] ?? null;
    $identity_scope = $ctx['scope'] ?? null;
    $identity_generation = $ctx['generation_id'] ?? null;
    if (($ctx['state'] ?? null) === 'valid' && ($ctx['scope'] ?? null) !== null && ($ctx['generation_id'] ?? null) !== null) {
        $prov = \UPayments\Token\CustomerTokenIdentity::read_provenance($state['current_user_id'], $ctx['scope'], $ctx['generation_id']);
        $provenance_state = $prov['state'] ?? null;
        if (($prov['state'] ?? null) === 'valid' && is_array($prov['record'] ?? null)) {
            $provenance_token = $prov['record']['token'] ?? null;
            $provenance_kind = $prov['record']['kind'] ?? null;
            $provenance_source = $prov['record']['source'] ?? null;
            $provenance_scope = $prov['record']['scope'] ?? null;
            $provenance_generation = $prov['record']['secret_generation_id'] ?? null;
        }
    }
} catch (Throwable $e) {
    // Persistence proof read failed — leave nulls.
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
    'established_token'              => $final['established_token'] ?? null,
    'create_token_response_token'    => $create_token_response_token,
    'retrieve_response_cards'        => $retrieve_response_cards,
    'retrieve_outbound_token'        => $final['retrieve_outbound_token'] ?? null,
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
    'identity_context_state'         => $identity_context_state,
    'identity_scope'                 => $identity_scope,
    'identity_generation'            => $identity_generation,
    'provenance_state'               => $provenance_state,
    'provenance_token'               => $provenance_token,
    'provenance_kind'                => $provenance_kind,
    'provenance_source'              => $provenance_source,
    'provenance_scope'               => $provenance_scope,
    'provenance_generation'          => $provenance_generation,
    'notices'                        => $final['notices'],
    'pid'                            => getmypid(),
    'wc_loaded'                      => class_exists('WC_Upayments'),
];

echo json_encode($out) . "\n";
exit(0);
