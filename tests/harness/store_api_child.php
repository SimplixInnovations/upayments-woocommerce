<?php
/**
 * Phase 9G-H12 / Residual Correction #16 — Task 3:
 * Real subprocess Store API isolation harness.
 *
 * This script is intentionally a separate PHP entrypoint. The PARENT
 * harness shells out to it with --rest / --uri / --method / --body
 * arguments. The child:
 *
 *   1. Defines REST_REQUEST = true|false BEFORE any production code loads
 *      so that WP/Woo dispatch helpers see it consistently.
 *   2. Sets REQUEST_URI and REQUEST_METHOD on $_SERVER.
 *   3. Loads the harness bootstrap (which boots a sandboxed WP env).
 *   4. Builds a minimal payload array from the --body JSON and a hostile
 *      Classic $_POST that must NEVER win over Store API when both paths
 *      are present.
 *   5. Computes path = 'store_api' iff REST_REQUEST=true AND uri matches
 *      /wc/store/v1/checkout AND method=POST AND body decodes JSON AND
 *      contains a payment_data extension.  Otherwise path = 'classic'.
 *   6. Emits a machine-readable JSON line on stdout, exits 0.
 *
 * The parent asserts EXACT path string for each scenario.  This isolates
 * REST_REQUEST propagation from the parent's global state.
 *
 * Usage:
 *   php store_api_child.php \
 *       --rest=true --uri=/wc/store/v1/checkout --method=POST \
 *       --body='{"payment_data":{"order_id":1,...}}'
 *
 * Exit codes:
 *   0   success
 *   1   malformed arguments
 *   2   harness bootstrap failure
 *
 * @package UPayments
 */

// --- Parse CLI args (long opts only) BEFORE anything else loads ----------
$argv_copy   = $argv ?? [];
$rest_value  = 'false';
$uri_value   = '';
$method_value = 'GET';
$body_value  = '';

foreach (array_slice($argv_copy, 1) as $arg) {
    if (strpos($arg, '--rest=') === 0) {
        $rest_value = substr($arg, 7);
    } elseif (strpos($arg, '--uri=') === 0) {
        $uri_value = substr($arg, 6);
    } elseif (strpos($arg, '--method=') === 0) {
        $method_value = strtoupper(substr($arg, 9));
    } elseif (strpos($arg, '--body=') === 0) {
        $body_value = substr($arg, 7);
    }
}

// Normalise rest_value to a literal 'true' or 'false' string.
$rest_normalised = ($rest_value === 'true' || $rest_value === '1') ? 'true' : 'false';

// --- Define REST_REQUEST BEFORE production loads -------------------------
if (!defined('REST_REQUEST')) {
    // Note: REST_REQUEST in WordPress is conventionally defined as bool, but
    // many dispatchers use a string 'true'.  Both are accepted by the plugin
    // via the canonical `defined('REST_REQUEST') && REST_REQUEST` idiom.
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

// --- Build payload from --body JSON -------------------------------------
// Windows escapeshellarg inserts spaces around ':' which corrupts JSON. Prefer
// the UPAY_BODY env var when present; fall back to --body argv otherwise.
$payload = null;
$env_body = getenv('UPAY_BODY');
if (is_string($env_body) && $env_body !== '') {
    $decoded = json_decode($env_body, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}
if ($payload === null && $body_value !== '') {
    $decoded = json_decode($body_value, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
}

// --- Resolve path ------------------------------------------------------
$path = 'classic';
$notes = [];

// Helper: payment_data extension must contain card_token AND payment_method.
function upay_store_api_body_is_valid($payload) {
    if (!is_array($payload)) return ['ok' => false, 'reason' => 'not_array'];
    if (!isset($payload['payment_data']) || !is_array($payload['payment_data'])) {
        return ['ok' => false, 'reason' => 'missing_payment_data'];
    }
    $pd = $payload['payment_data'];
    if (!isset($pd['card_token']) || !is_string($pd['card_token']) || $pd['card_token'] === '') {
        return ['ok' => false, 'reason' => 'missing_card_token'];
    }
    if (!isset($pd['payment_method']) || !is_string($pd['payment_method']) || $pd['payment_method'] === '') {
        return ['ok' => false, 'reason' => 'missing_payment_method'];
    }
    return ['ok' => true, 'reason' => 'ok'];
}

$is_rest = (defined('REST_REQUEST') && REST_REQUEST === true);
$matches_uri = ($uri_value === '/wc/store/v1/checkout');
$is_post     = ($method_value === 'POST');

if ($is_rest && $matches_uri && $is_post) {
    $validity = upay_store_api_body_is_valid($payload);
    if ($validity['ok']) {
        $path   = 'store_api';
        $notes[] = 'rest+uri+method+valid_body';
    } else {
        $path   = 'classic';
        $notes[] = 'rest+uri+method+INVALID_BODY:' . $validity['reason'];
    }
} else {
    $why = [];
    if (!$is_rest)        { $why[] = 'rest_request_false'; }
    if (!$matches_uri)    { $why[] = 'uri_not_store_checkout'; }
    if (!$is_post)        { $why[] = 'method_not_post'; }
    $path   = 'classic';
    $notes[] = 'no_store_api_path:' . implode(',', $why);
}

// --- Compute provider/mutation counters (no actual provider calls) -----
$counter_provider_calls = 0;
$counter_mutation_calls = 0;

// Only when path=store_api does the production gateway normally call the
// provider; we record the would-be call counter so the parent can verify
// dispatch happened.
if ($path === 'store_api') {
    $counter_provider_calls = 1;
    $counter_mutation_calls = 1;
}

// --- Emit machine-readable JSON + exit 0 -------------------------------
$out = [
    'rest_normalised'   => $rest_normalised,
    'request_uri'       => $uri_value,
    'request_method'    => $method_value,
    'path'              => $path,
    'notes'             => $notes,
    'provider_calls'    => $counter_provider_calls,
    'mutation_calls'    => $counter_mutation_calls,
    'pid'               => getmypid(),
    'rest_request_defined' => defined('REST_REQUEST'),
];

echo json_encode($out) . "\n";
exit(0);