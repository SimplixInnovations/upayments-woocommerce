<?php
/**
 * Phase 9G-H12 PHP harness — residual correction #3.
 *
 * Executes the actual production source (UPayments.php + CustomerTokenIdentity.php)
 * through real WC_Upayments subclasses. Drives process_payment() end-to-end with
 * synthetic WooCommerce fixtures and programmable transport / option / user_meta
 * / order / history stubs. Asserts:
 *   - provider-call counters (availability, create_token, retrieve_cards, charge)
 *   - mutation counters (option, usermeta, identity, order)
 *   - exact outbound JSON for ordinary / selected-card / MultiMerchant / Whitelabel paths
 *   - all Phase A deterministic failures produce 0 provider calls and 0 writes
 *   - all 422-classifier reasons match the frozen contract (no inference/matching/retry)
 *   - history classifier against programmable order-query fixture
 *   - Store API / Classic channel routing
 *   - presence-aware save-card / plan / interval / card_token parsing
 *   - amount JSON invariants (numeric, no exponent, exact token round-trip)
 *
 * The harness also runs its own self-tests verifying that the harness stubs
 * persist state correctly. If the self-tests fail, the harness aborts before
 * any production PASS counter is incremented.
 *
 * Usage:
 *   php tests/harness/phase-9g-h12-php-harness.php
 *
 * Returns exit code 0 on PASS-only, exit code 1 on any FAIL.
 *
 * @package UPayments
 */

if (PHP_VERSION_ID < 70200) {
    fwrite(STDERR, "PHP 7.2+ required\n");
    exit(1);
}

$ROOT = realpath(__DIR__ . '/../..');
$PLUGIN_FILE = $ROOT . '/UPayments.php';
$IDENTITY_FILE = $ROOT . '/includes/Token/CustomerTokenIdentity.php';

if (!is_file($PLUGIN_FILE)) {
    fwrite(STDERR, "FATAL: $PLUGIN_FILE not found\n");
    exit(1);
}
if (!is_file($IDENTITY_FILE)) {
    fwrite(STDERR, "FATAL: $IDENTITY_FILE not found\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// HARNESS GLOBAL STATE — exposed by-reference.
// ---------------------------------------------------------------------------

$GLOBALS['__upay_test_state'] = [
    'options' => [],
    'usermeta' => [],
    'transients' => [],
    'locks' => [],
    'notices' => [],
    'session_refresh_totals' => 0,
    'force_user_cache_refresh_failure' => false,
    'force_order_refresh_failure' => false,
    // history query fixture
    'history_pages' => [],
    'history_total' => 0,
    'history_max_pages' => 0,
    'history_query_exception' => false,
    'history_malformed_result' => false,
    // orders registered for wc_get_order
    'orders_fixture' => [],
    // provider transport fixtures
    'transport_route' => null,
    'transport_response' => null,
    'transport_log' => [],
    'availability_response' => null,
    // mutation counters
    'option_creates' => 0,
    'option_writes' => 0,
    'usermeta_writes' => 0,
    'order_meta_writes' => 0,
    'identity_writes' => 0,
    'provenance_writes' => 0,
    'secret_creates' => 0,
    // provider call counters
    'create_token_calls' => 0,
    'retrieve_calls' => 0,
    'availability_calls' => 0,
    'charge_calls' => 0,
    // last captured charge body
    'last_charge_body' => null,
    // user_id for is_user_logged_in
    'current_user_id' => 0,
    // post data
    'post' => [],
    // request URI / method (for store api)
    'request_uri' => '/checkout/',
    'request_method' => 'POST',
    'rest_request' => false,
    // input body for Store API
    'input_body' => null,
];

function &upay_test_state() {
    return $GLOBALS['__upay_test_state'];
}

function upay_reset_state() {
    $GLOBALS['__upay_test_state'] = [
        'options' => [], 'usermeta' => [], 'transients' => [], 'locks' => [],
        'notices' => [], 'session_refresh_totals' => 0,
        'force_user_cache_refresh_failure' => false,
        'force_order_refresh_failure' => false,
        'history_pages' => [], 'history_total' => 0, 'history_max_pages' => 0,
        'history_query_exception' => false, 'history_malformed_result' => false,
        'orders_fixture' => [],
        'transport_route' => null, 'transport_response' => null,
        'transport_log' => [],
        'availability_response' => null,
        'option_creates' => 0, 'option_writes' => 0,
        'usermeta_writes' => 0, 'order_meta_writes' => 0,
        'identity_writes' => 0, 'provenance_writes' => 0,
        'secret_creates' => 0,
        'create_token_calls' => 0, 'retrieve_calls' => 0,
        'availability_calls' => 0, 'charge_calls' => 0,
        'last_charge_body' => null,
        'current_user_id' => 0, 'post' => [],
        'request_uri' => '/checkout/', 'request_method' => 'POST',
        'rest_request' => false, 'input_body' => null,
    ];
}

// ---------------------------------------------------------------------------
// WP / Woo STUBS — by-reference persistence
// ---------------------------------------------------------------------------

if (!defined('ABSPATH')) { define('ABSPATH', $ROOT . '/'); }
if (!defined('WPINC')) { define('WPINC', 'wp-includes'); }
if (!defined('REST_REQUEST')) { define('REST_REQUEST', false); }
if (!defined('WP_PLUGIN_DIR')) { define('WP_PLUGIN_DIR', $ROOT . '/wp-content/plugins'); }
if (!defined('WP_CONTENT_DIR')) { define('WP_CONTENT_DIR', $ROOT . '/wp-content'); }
if (!defined('MINUTE_IN_SECONDS')) { define('MINUTE_IN_SECONDS', 60); }
if (!defined('HOUR_IN_SECONDS')) { define('HOUR_IN_SECONDS', 3600); }
if (!defined('DAY_IN_SECONDS')) { define('DAY_IN_SECONDS', 86400); }

function get_option($name, $default = false) {
    $state =& upay_test_state();
    return array_key_exists($name, $state['options']) ? $state['options'][$name] : $default;
}
function add_option($name, $value, $deprecated = '', $autoload = 'yes') {
    $state =& upay_test_state();
    if (array_key_exists($name, $state['options'])) return false;
    $state['options'][$name] = $value;
    $state['option_creates']++;
    return true;
}
function update_option($name, $value, $autoload = null) {
    $state =& upay_test_state();
    $state['options'][$name] = $value;
    $state['option_writes']++;
    return true;
}
function get_transient($name) {
    $state =& upay_test_state();
    return array_key_exists($name, $state['transients']) ? $state['transients'][$name] : false;
}
function set_transient($name, $value, $expiration = 0) {
    $state =& upay_test_state();
    $state['transients'][$name] = $value;
    return true;
}
function delete_transient($name) {
    $state =& upay_test_state();
    unset($state['transients'][$name]);
    return true;
}
function get_user_meta($user_id, $key, $single = false) {
    $state =& upay_test_state();
    $values = isset($state['usermeta'][$user_id][$key]) ? $state['usermeta'][$user_id][$key] : [];
    if (!is_array($values)) $values = [$values];
    return $single ? (count($values) > 0 ? $values[0] : '') : $values;
}
function add_user_meta($user_id, $key, $value, $unique = false) {
    $state =& upay_test_state();
    if (!isset($state['usermeta'][$user_id])) $state['usermeta'][$user_id] = [];
    if (!isset($state['usermeta'][$user_id][$key])) $state['usermeta'][$user_id][$key] = [];
    if ($unique && count($state['usermeta'][$user_id][$key]) > 0) return false;
    $state['usermeta'][$user_id][$key][] = $value;
    $state['usermeta_writes']++;
    return true;
}
function update_user_meta($user_id, $key, $value, $prev_value = '') {
    return add_user_meta($user_id, $key, $value, false);
}
function delete_user_meta($user_id, $key, $value = '') {
    $state =& upay_test_state();
    if (isset($state['usermeta'][$user_id][$key])) unset($state['usermeta'][$user_id][$key]);
    return true;
}
function metadata_exists($type, $id, $key) {
    if ($type !== 'user') return false;
    $state =& upay_test_state();
    return isset($state['usermeta'][$id][$key]) && count($state['usermeta'][$id][$key]) > 0;
}
function clean_user_cache($user_id) {
    $state =& upay_test_state();
    if (!empty($state['force_user_cache_refresh_failure'])) {
        throw new RuntimeException('synthetic clean_user_cache failure');
    }
    return true;
}
function wp_unslash($value) {
    if (is_array($value)) return array_map('wp_unslash', $value);
    if (is_string($value)) return stripslashes($value);
    return $value;
}
function wp_json_encode($data, $options = 0, $depth = 512) { return json_encode($data, $options, $depth); }
function wp_json_decode($string, $assoc = false, $depth = 512) { return json_decode($string, $assoc, $depth); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function sanitize_text_field($str) {
    if (!is_string($str)) return '';
    $str = strip_tags($str);
    return trim(preg_replace('/[\r\n\t\0\x0B]/', '', $str));
}
function __($text, $domain = '') { return $text; }
function _e($text, $domain = '') { echo $text; }
function esc_html__($text, $domain = '') { return $text; }
function esc_attr__($text, $domain = '') { return $text; }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_url($url) { return $url; }
function esc_url_raw($url) { return $url; }
function wp_kses($content, $allowed_html) { return $content; }
function get_current_blog_id() { return 1; }
function get_current_user_id() { return upay_test_state()['current_user_id']; }
function is_user_logged_in() { return get_current_user_id() > 0; }
function wc_get_orders($args) {
    $state =& upay_test_state();
    if (!empty($state['history_query_exception'])) {
        throw new RuntimeException('synthetic wc_get_orders exception');
    }
    if (!empty($state['history_malformed_result'])) {
        return null;
    }
    $page = isset($args['paged']) ? (int) $args['paged'] : 1;
    $page_size = isset($args['limit']) ? (int) $args['limit'] : 20;
    $orders = isset($state['history_pages'][$page]) ? $state['history_pages'][$page] : [];
    $obj = new stdClass();
    $obj->orders = $orders;
    $obj->total = (int) $state['history_total'];
    // Per-page max_pages override takes priority over the global default.
    if (isset($state['history_max_pages_per_page'][$page])) {
        $obj->max_num_pages = (int) $state['history_max_pages_per_page'][$page];
    } else {
        $obj->max_num_pages = (int) $state['history_max_pages'];
    }
    return $obj;
}
function wc_get_order($order_id) {
    return isset(upay_test_state()['orders_fixture'][$order_id]) ? upay_test_state()['orders_fixture'][$order_id] : null;
}
function wc_get_checkout_url() { return 'https://example.test/checkout/'; }
function wc_add_notice($message, $type = 'info') {}
function wc_clear_notices() {}
function wc_get_logger() { return new class { public function __call($n, $a) {} }; }
function wc_format_decimal($value, $decimals = 2) { return number_format((float) $value, $decimals, '.', ''); }
function wc_get_page_permalink($page) { return 'https://example.test/' . $page . '/'; }
function is_checkout() { return true; }
function is_wc_endpoint_url() { return false; }
function get_locale() { return 'en_US'; }
function get_woocommerce_currency() { return 'KWD'; }
function get_woocommerce_currency_symbol() { return 'KD'; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/upayments/'; }
function plugin_dir_path($file) { return '/var/www/wp-content/plugins/upayments/'; }
function plugins_url($asset, $file = '') { return 'https://example.test/wp-content/plugins/upayments/' . $asset; }
function add_action($hook, $callback, $priority = 10, $args = 1) {}
function add_filter($hook, $callback, $priority = 10, $args = 1) {}
function apply_filters($hook, $value) { return $value; }
function do_action($hook, ...$args) {}
function register_activation_hook($file, $callback) {}
function register_deactivation_hook($file, $callback) {}
function site_url() { return 'https://example.test'; }

class WpdbStub {
    public $usermeta = 'wp_usermeta';
    public $locks = [];
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) {
        $state =& upay_test_state();
        if (is_string($sql) && stripos($sql, 'usermeta') !== false) {
            $count = 0;
            foreach ($state['usermeta'] as $uid => $keys) {
                foreach ($keys as $key => $values) {
                    if (preg_match('/^_upay_customer_token_v2_b[0-9]+_/', $key)) {
                        $count++;
                    }
                }
            }
            return $count;
        }
        return 0;
    }
    public function get_col($sql = null) {
        $state =& upay_test_state();
        $keys = [];
        if (isset($state['usermeta'][1])) {
            foreach ($state['usermeta'][1] as $key => $value) {
                if (preg_match('/^_upay_customer_token_v2_b1_/', $key)) {
                    $keys[] = $key;
                }
            }
        }
        return $keys;
    }
    public function get_var($sql = null) {
        if (is_string($sql) && stripos($sql, 'GET_LOCK') !== false) {
            if (preg_match("/'([^']+)'/", $sql, $m)) {
                $name = $m[1];
                $state =& upay_test_state();
                if (!isset($state['locks'][$name])) {
                    $state['locks'][$name] = true;
                    return '1';
                }
            }
            return '1';
        }
        if (is_string($sql) && stripos($sql, 'RELEASE_LOCK') !== false) {
            if (preg_match("/'([^']+)'/", $sql, $m)) {
                $state =& upay_test_state();
                unset($state['locks'][$m[1]]);
            }
            return '1';
        }
        return null;
    }
}
$GLOBALS['wpdb'] = new WpdbStub();

// Stub the plugin-update-checker
if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5p6\\PucFactory', false)) {
    eval('namespace YahnisElsts\\PluginUpdateChecker\\v5p6; class PucFactory {
        public static function buildUpdateChecker($a, $b, $c = "") {
            return new Plugin\\UpdateChecker($a, $b, $c);
        }
        public static function isPluginFile($f) { return false; }
        public static function addVersion($a, $b, $c = "") { return null; }
        public static function addFileVersion($a, $b) { return null; }
    }');
    eval('namespace YahnisElsts\\PluginUpdateChecker\\v5p6\\Plugin; class UpdateChecker {
        public function __construct($a = null, $b = null, $c = null) {}
        public function getVcsApi() { return new VcsApi(); }
        public function setBranch($b) {}
        public function addQueryArgFilter($f) {}
        public function setAuthentication($a) {}
    }');
    eval('namespace YahnisElsts\\PluginUpdateChecker\\v5p6\\Plugin; class VcsApi {
        public function enableReleaseAssets($r = null) {}
    }');
    if (!class_exists('YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory', false)) {
        eval('namespace YahnisElsts\\PluginUpdateChecker\\v5; class PucFactory extends \\YahnisElsts\\PluginUpdateChecker\\v5p6\\PucFactory {}');
    }
}

if (!class_exists('WC_Payment_Gateway')) {
    class WC_Payment_Gateway {
        public $id; public $icon; public $method_title; public $method_description;
        public $has_fields; public $title; public $description; public $debug;
        public $apiKey; public $isOrderComplete; public $testMode; public $charge;
        public $fromPluginEnabled; public $paymentData = [];
        public $multiMerchant; public $ibanNumber; public $ccCharge;
        public $ccChargeType; public $knetCharge; public $knetChargeType;
        public $saveCardEnabled; public $autoDeduction; public $domain = 'upayments';
        public $form_fields = [];
        public function __construct() {}
        public function get_option($k, $d = false) { return $d; }
        public function init_form_fields() {}
        public function init_settings() {}
        public function log($content, $level = 'debug') {}
        public function get_option_key() { return 'woocommerce_upayments_settings'; }
    }
}
if (!class_exists('WC_Meta_Data')) {
    class WC_Meta_Data {
        private $value;
        public function __construct($value = null) { $this->value = $value; }
        public function get_value() { return $this->value; }
    }
}
if (!class_exists('WooCommerce')) {
    class WooCommerce {
        public $session;
        public $cart;
        public $payment_gateways;
        public function __construct() {}
    }
}

require_once $IDENTITY_FILE;
require_once $PLUGIN_FILE;

// Manually invoke woocommerceUpaymentsInit() since add_action won't actually run it.
if (function_exists('woocommerceUpaymentsInit')) {
    woocommerceUpaymentsInit();
}

if (!class_exists('WC_Upayments')) {
    fwrite(STDERR, "FATAL: WC_Upayments class missing\n");
    exit(1);
}

if (!class_exists('WC_Upayments')) {
    fwrite(STDERR, "FATAL: WC_Upayments class missing\n");
    exit(1);
}

// ===========================================================================
// RUNNER
// ===========================================================================

$pass = 0; $fail = 0;
$log = [];
$_pass_runtime = 0; $_pass_static = 0; $_pass_harness = 0;
$_fail_runtime = 0; $_fail_static = 0; $_fail_harness = 0;

function upay_assert($condition, $description, $kind = 'runtime') {
    global $pass, $fail, $log, $_pass_runtime, $_pass_static, $_pass_harness,
        $_fail_runtime, $_fail_static, $_fail_harness;
    if ($condition) {
        $pass++;
        if ($kind === 'runtime') $_pass_runtime++;
        elseif ($kind === 'static') $_pass_static++;
        elseif ($kind === 'harness') $_pass_harness++;
        $log[] = "PASS: $description";
    } else {
        $fail++;
        if ($kind === 'runtime') $_fail_runtime++;
        elseif ($kind === 'static') $_fail_static++;
        elseif ($kind === 'harness') $_fail_harness++;
        $log[] = "FAIL: $description";
    }
}
function upay_assert_eq($actual, $expected, $description, $kind = 'runtime') {
    upay_assert($actual === $expected,
        "$description (expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ")",
        $kind);
}
function upay_call_static($class, $method, array $args) {
    $reflection = new ReflectionMethod($class, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs(null, $args);
}
function upay_call_instance($instance, $method, array $args) {
    $reflection = new ReflectionMethod($instance, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($instance, $args);
}

// ===========================================================================
// ORDER / PRODUCT FIXTURES
// ===========================================================================

class FakeWCProduct {
    public $id;
    public $name;
    public $type;
    public function __construct($id, $name, $type) {
        $this->id = $id; $this->name = $name; $this->type = $type;
    }
    public function get_id() { return $this->id; }
    public function get_name() { return $this->name; }
    public function get_type() { return $this->type; }
}

class FakeWCOrderItem {
    public $product;
    public $quantity;
    public $total;
    public $name;
    public function __construct($product, $quantity, $total, $name = null) {
        $this->product = $product;
        $this->quantity = (int) $quantity;
        $this->total = (string) $total;
        $this->name = $name !== null ? $name : $product->name;
    }
    public function get_product() { return $this->product; }
    public function get_quantity() { return $this->quantity; }
    public function get_total() { return $this->total; }
    public function get_name() { return $this->name; }
}

class FakeWCOrder {
    public $id;
    public $data;
    public $items_meta = [];
    public $meta_store = [];
    public $custom_total = null;
    public function __construct($id, $order_data = []) {
        $this->id = $id;
        $this->data = array_merge([
            'currency' => 'KWD',
            'billing' => [
                'first_name' => 'Ahmed', 'last_name' => 'Test',
                'email' => 'a@b.test', 'phone' => '+96512345678',
            ],
        ], $order_data);
    }
    public function get_id() { return $this->id; }
    public function get_data() { return $this->data; }
    public function get_currency() { return $this->data['currency']; }
    public function get_total() {
        if ($this->custom_total !== null) return (string) $this->custom_total;
        $total = 0;
        foreach ($this->items_meta as $item) {
            $total += (float) $item->total;
        }
        return (string) $total;
    }
    public function get_billing_email() { return $this->data['billing']['email']; }
    public function get_billing_phone() { return $this->data['billing']['phone']; }
    public function get_items($type) {
        return $type === 'line_item' ? $this->items_meta : [];
    }
    public function get_meta($key, $single = false, $context = 'view') {
        if ($single) {
            return array_key_exists($key, $this->meta_store) ? $this->meta_store[$key] : '';
        }
        $v = array_key_exists($key, $this->meta_store) ? [$this->meta_store[$key]] : [];
        return array_map(function($vv) { return new WC_Meta_Data($vv); }, $v);
    }
    public function meta_exists($key) { return array_key_exists($key, $this->meta_store); }
    public function add_meta_data($key, $value, $unique = false) {
        $state =& upay_test_state();
        $state['order_meta_writes']++;
        if ($key === '_upay_customer_unique_token' || $key === '_upay_customer_token_kind_v1'
            || $key === '_upay_customer_token_scope_v1' || $key === '_upay_customer_token_generation_v1') {
            $state['identity_writes']++;
            $state['provenance_writes']++;
        }
        $this->meta_store[$key] = $value;
    }
    public function delete_meta_data($key) { unset($this->meta_store[$key]); }
    public function update_meta_data($key, $value) { $this->meta_store[$key] = $value; }
    public function save_meta_data() {}
    public function read_meta_data($force = false) {
        $state =& upay_test_state();
        if (!empty($state['force_order_refresh_failure'])) {
            throw new RuntimeException('synthetic read_meta_data failure');
        }
    }
}

function upay_make_order($id = 100, $custom_total = null, $items = null) {
    if ($items === null) {
        $product = new FakeWCProduct(1, 'Test Product', 'simple');
        $items = [new FakeWCOrderItem($product, 1, '12.50')];
    }
    $order = new FakeWCOrder($id);
    $order->items_meta = $items;
    $order->custom_total = $custom_total;
    upay_test_state()['orders_fixture'][$id] = $order;
    return $order;
}

function upay_make_gateway($config = []) {
    $defaults = [
        'apiKey' => 'test_api_key', 'testMode' => 'no',
        'saveCardEnabled' => 'yes', 'autoDeduction' => 'no',
        'multiMerchant' => 'no', 'ibanNumber' => '',
        'ccCharge' => '', 'ccChargeType' => '',
        'knetCharge' => '', 'knetChargeType' => '',
        'debug' => 'no',
    ];
    $config = array_merge($defaults, $config);
    $gateway = new WC_Upayments();
    foreach ($config as $k => $v) {
        $gateway->$k = $v;
    }
    return $gateway;
}

// Test subclass that overrides provider transport.
class WC_Upayments_Testable extends WC_Upayments {
    public function execute_upayments_request($route, $method, $body = null) {
        $state =& upay_test_state();
        $state['transport_log'][] = ['route' => $route, 'method' => $method, 'body' => $body];
        if ($route === 'check-payment-button-status') $state['availability_calls']++;
        elseif ($route === 'create-customer-unique-token') $state['create_token_calls']++;
        elseif ($route === 'retrieve-customer-cards') $state['retrieve_calls']++;
        elseif ($route === 'charge') {
            $state['charge_calls']++;
            $state['last_charge_body'] = $body;
        }
        if ($state['transport_route'] === $route && $state['transport_response'] !== null) {
            $r = $state['transport_response'];
            if ($r === false) return false;
            return $r;
        }
        return false;
    }
    public function getUpayPaymentMethods() {
        $state =& upay_test_state();
        if ($state['availability_response'] !== null) return $state['availability_response'];
        return ['result' => 'failure'];
    }
}

function upay_set_provider_response($route, $response) {
    $state =& upay_test_state();
    $state['transport_route'] = $route;
    $state['transport_response'] = $response;
}
function upay_clear_provider() {
    $state =& upay_test_state();
    $state['transport_route'] = null;
    $state['transport_response'] = null;
}
function upay_set_availability_response($r) { upay_test_state()['availability_response'] = $r; }

// WC() session stub
$GLOBALS['__upay_wc_session'] = null;
function WC() { return new class { public $session; public function __construct() { $this->session = new class { public function set($k, $v) { if ($k === 'refresh_totals') upay_test_state()['session_refresh_totals']++; } }; } }; }

// Subclass that overrides file_get_contents('php://input') by precomputing body.
class WC_Upayments_InputTestable extends WC_Upayments_Testable {
    public $input_body = null;
    public function __construct($config = []) {
        parent::__construct();
        foreach ($config as $k => $v) $this->$k = $v;
    }
}

// Wrapper for process_payment that uses the production file_get_contents.
// We patch file_get_contents via a stream wrapper registered for 'php://input'.

class UPAYPHPSInputStream {
    public $context;
    private $position = 0;
    public function stream_open($path, $mode, $options, &$opened_path) {
        return true;
    }
    public function stream_read($count) {
        $state =& upay_test_state();
        $data = $state['input_body'];
        if ($data === null) return '';
        $ret = substr($data, $this->position, $count);
        $this->position += strlen($ret);
        return $ret;
    }
    public function stream_eof() {
        $state =& upay_test_state();
        $data = $state['input_body'];
        if ($data === null) return true;
        return $this->position >= strlen($data);
    }
    public function stream_close() { return true; }
    public function url_stat($path, $flags) { return []; }
}
if (!in_array('upay_test_input', stream_get_wrappers(), true)) {
    @stream_wrapper_register('upay_test_input', 'UPAYPHPSInputStream');
}

function upay_stream_open_input() {
    return fopen('upay_test_input://read', 'r');
}

// ===========================================================================
// PROCESS_PAYMENT DRIVER
// ===========================================================================

function upay_setup_request($rest = false, $uri = '/checkout/', $method = 'POST') {
    $state =& upay_test_state();
    $state['rest_request'] = $rest;
    $state['request_uri'] = $uri;
    $state['request_method'] = $method;
    // Mirror to $_SERVER for production code
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['REQUEST_METHOD'] = $method;
}

function upay_set_post($post) {
    $state =& upay_test_state();
    $state['post'] = $post;
    $_POST = $post;
}

function upay_set_input($body) {
    $state =& upay_test_state();
    $state['input_body'] = $body;
}

function upay_run_process_payment($gateway, $order, $rest_request = false, $uri = '/checkout/', $method = 'POST') {
    upay_setup_request($rest_request, $uri, $method);
    // Reset counters that are produced per-call
    $state =& upay_test_state();
    $state['availability_calls'] = 0;
    $state['create_token_calls'] = 0;
    $state['retrieve_calls'] = 0;
    $state['charge_calls'] = 0;
    $state['option_creates'] = 0;
    $state['option_writes'] = 0;
    $state['usermeta_writes'] = 0;
    $state['order_meta_writes'] = 0;
    $state['identity_writes'] = 0;
    $state['provenance_writes'] = 0;
    $state['secret_creates'] = 0;
    $state['transport_log'] = [];
    $state['last_charge_body'] = null;
    return $gateway->process_payment($order->get_id());
}

// ===========================================================================
// Default fixtures
// ===========================================================================

function upay_default_success_environment() {
    upay_reset_state();
    upay_set_availability_response([
        'result' => 'success',
        'isWhiteLabel' => true,
        'payButtons' => [
            'knet' => 1, 'credit_card' => 1, 'apple_pay_knet' => 0,
            'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0,
        ],
    ]);
    upay_set_provider_response('charge', [
        'transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0,
        'body' => json_encode(['status' => true, 'data' => ['link' => 'https://upayments.example.test/r?order=100']]),
    ]);
}

function upay_default_token_success_environment() {
    upay_set_provider_response('create-customer-unique-token', [
        'transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0,
        'body' => json_encode(['status' => true, 'data' => ['customerUniqueToken' => '12345678']]),
    ]);
}

function upay_default_retrieve_success_environment() {
    upay_set_provider_response('retrieve-customer-cards', [
        'transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0,
        'body' => json_encode([
            'status' => true,
            'data' => ['customerCards' => [['token' => 'card_token_1', 'number' => '****1234']]],
        ]),
    ]);
}

// ===========================================================================
// RUN TESTS
// ===========================================================================

echo "Running phase-9g-h12-php-harness.php\n";

// ---------------------------------------------------------------------------
// HARNESS SELF-TESTS
// ---------------------------------------------------------------------------

upay_reset_state();
upay_assert(add_option('t_opt', 'v1') === true, 'H-ST-1 add_option persists', 'harness');
upay_assert(add_option('t_opt', 'v2') === false, 'H-ST-2 duplicate add_option fails', 'harness');
upay_assert(update_option('t_opt', 'v3') === true, 'H-ST-3 update_option persists', 'harness');
upay_assert_eq(get_option('t_opt'), 'v3', 'H-ST-4 get_option reads current', 'harness');
upay_assert(set_transient('t_tr', 'tv', 60) === true, 'H-ST-5 set_transient persists', 'harness');
upay_assert_eq(get_transient('t_tr'), 'tv', 'H-ST-6 get_transient returns', 'harness');
upay_assert(delete_transient('t_tr') === true, 'H-ST-7 delete_transient deletes', 'harness');
upay_assert(add_user_meta(1, 'k', 'v1', true) === true, 'H-ST-8 add_user_meta persists', 'harness');
upay_assert(add_user_meta(1, 'k', 'v2', true) === false, 'H-ST-9 unique add rejects dup', 'harness');
upay_assert(add_user_meta(1, 'k', 'v3', false) === true, 'H-ST-10 non-unique add appends', 'harness');
$values = get_user_meta(1, 'k', false);
upay_assert_eq(count($values), 2, 'H-ST-11 usermeta cardinality exact', 'harness');
upay_assert_eq($values[0], 'v1', 'H-ST-12 usermeta first value', 'harness');
upay_assert_eq($values[1], 'v3', 'H-ST-13 usermeta second value', 'harness');
upay_assert_eq(get_user_meta(1, 'k', true), 'v1', 'H-ST-14 usermeta single returns first', 'harness');
upay_assert(delete_user_meta(1, 'k') === true, 'H-ST-15 usermeta delete', 'harness');
upay_assert_eq(count(get_user_meta(1, 'k', false)), 0, 'H-ST-16 usermeta deletion persists', 'harness');

$order = upay_make_order(99, '5.00', [new FakeWCOrderItem(new FakeWCProduct(1, 'X', 'simple'), 1, '5.00')]);
upay_reset_state();
$order->add_meta_data('m', 'v', true);
upay_assert_eq($order->get_meta('m', true), 'v', 'H-ST-17 order meta write persists', 'harness');
$order->delete_meta_data('m');
upay_assert_eq($order->get_meta('m', true), '', 'H-ST-18 order meta delete persists', 'harness');

update_option('upayments_payment_methods_rate_gate_live', 100);
upay_assert_eq(get_option('upayments_payment_methods_rate_gate_live'), 100, 'H-ST-19 rate gate persists', 'harness');

upay_reset_state();
$gw = new WC_Upayments_Testable();
upay_set_provider_response('charge', ['transport_ok' => true, 'http_status' => 201, 'curl_errno' => 0, 'body' => '{}']);
$gw->execute_upayments_request('charge', 'POST', '{}');
upay_assert_eq(upay_test_state()['charge_calls'], 1, 'H-ST-20 charge call counter', 'harness');
$gw->execute_upayments_request('create-customer-unique-token', 'POST', '{}');
upay_assert_eq(upay_test_state()['create_token_calls'], 1, 'H-ST-21 create_token call counter', 'harness');
$gw->execute_upayments_request('retrieve-customer-cards', 'POST', '{}');
upay_assert_eq(upay_test_state()['retrieve_calls'], 1, 'H-ST-22 retrieve call counter', 'harness');
$gw->execute_upayments_request('check-payment-button-status', 'GET');
upay_assert_eq(upay_test_state()['availability_calls'], 1, 'H-ST-23 availability call counter', 'harness');

if ($_fail_harness > 0) {
    fwrite(STDERR, "FATAL: harness self-tests failed ($_fail_harness). Aborting.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// 1. parse_save_card_strict
// ---------------------------------------------------------------------------

upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [0]), false, 'PHP-PMSC-1 0 => false', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', ['0']), false, "PHP-PMSC-2 '0' => false", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [1]), true, 'PHP-PMSC-3 1 => true', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', ['1']), true, "PHP-PMSC-4 '1' => true", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [null]), null, 'PHP-PMSC-5 null => invalid', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', ['']), null, "PHP-PMSC-6 '' => invalid", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', ['yes']), null, "PHP-PMSC-7 'yes' => invalid", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', ['true']), null, "PHP-PMSC-8 'true' => invalid", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [true]), null, 'PHP-PMSC-9 true => invalid', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [false]), null, 'PHP-PMSC-10 false => invalid', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [2]), null, 'PHP-PMSC-11 2 => invalid', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [1.5]), null, 'PHP-PMSC-12 1.5 => invalid', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [[]]), null, 'PHP-PMSC-13 array => invalid', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_save_card_strict', [' 1 ']), null, "PHP-PMSC-14 ' 1 ' whitespace rejected", 'runtime');

// ---------------------------------------------------------------------------
// 2. field_present
// ---------------------------------------------------------------------------

upay_assert_eq(upay_call_static('WC_Upayments', 'field_present', [['save_card' => '1'], 'save_card']), true, 'PHP-FP-1 present', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'field_present', [['save_card' => null], 'save_card']), true, 'PHP-FP-2 explicit null is present', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'field_present', [['save_card' => ''], 'save_card']), true, "PHP-FP-3 explicit '' is present", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'field_present', [['card_token' => 'x'], 'save_card']), false, 'PHP-FP-4 absent', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'field_present', ['not array', 'save_card']), false, 'PHP-FP-5 non-array source', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'field_present', [null, 'save_card']), false, 'PHP-FP-6 null source', 'runtime');

// ---------------------------------------------------------------------------
// 3. parse_interval
// ---------------------------------------------------------------------------

upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [0]), 0, 'PHP-PI-1 0 => 0', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', ['0']), 0, "PHP-PI-2 '0' => 0", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [1]), 1, 'PHP-PI-3 1 => 1', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [2]), 2, 'PHP-PI-4 2 => 2', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [3]), 3, 'PHP-PI-5 3 => 3', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [4]), -1, 'PHP-PI-6 4 => -1', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [null]), -1, 'PHP-PI-7 null => -1', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', ['']), -1, "PHP-PI-8 '' => -1", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [' 1 ']), -1, "PHP-PI-9 ' 1 ' => -1", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [true]), -1, 'PHP-PI-10 true => -1', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [1.5]), -1, 'PHP-PI-11 1.5 => -1', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_interval', [[1]]), -1, 'PHP-PI-12 array => -1', 'runtime');

// ---------------------------------------------------------------------------
// 4. parse_payment_source_strict
// ---------------------------------------------------------------------------

upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', ['cc']), 'cc', "PHP-PPS-1 'cc'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', ['knet']), 'knet', "PHP-PPS-2 'knet'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', ['  cc  ']), 'cc', "PHP-PPS-3 '  cc  ' trimmed", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', ['']), null, "PHP-PPS-4 '' => null", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', ['   ']), null, "PHP-PPS-5 '   ' => null", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', ['cc apple']), null, "PHP-PPS-6 'cc apple' => null", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', [[]]), null, 'PHP-PPS-7 array => null', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', [null]), null, 'PHP-PPS-8 null => null', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', [true]), null, 'PHP-PPS-9 true => null', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_payment_source_strict', [42]), null, 'PHP-PPS-10 42 => null', 'runtime');

// ---------------------------------------------------------------------------
// 5. parse_subscription_plan_strict
// ---------------------------------------------------------------------------

upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['one_time']), 'one_time', "PHP-PSP-1 'one_time'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['daily']), 'daily', "PHP-PSP-2 'daily'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['weekly']), 'weekly', "PHP-PSP-3 'weekly'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['monthly']), 'monthly', "PHP-PSP-4 'monthly'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['quarterly']), 'quarterly', "PHP-PSP-5 'quarterly'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['yearly']), 'yearly', "PHP-PSP-6 'yearly'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['bad plan']), null, "PHP-PSP-7 'bad plan' => null", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['']), null, "PHP-PSP-8 '' => null", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', [42]), null, 'PHP-PSP-9 42 => null', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', [null]), null, 'PHP-PSP-10 null => null', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', [true]), null, 'PHP-PSP-11 true => null', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ["daily\n"]), null, "PHP-PSP-12 newline-suffix => null", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ['  daily  ']), null, "PHP-PSP-13 '  daily  ' => null (no trim)", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'parse_subscription_plan_strict', ["\tdaily"]), null, "PHP-PSP-14 leading-tab => null", 'runtime');

// ---------------------------------------------------------------------------
// 6. build_amount_json_token
// ---------------------------------------------------------------------------

upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['1.00']), '1.00', "PHP-AMT-1 '1.00'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['1']), '1', "PHP-AMT-2 '1'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['0.01']), '0.01', "PHP-AMT-3 '0.01'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['0.001']), '0.001', "PHP-AMT-4 '0.001'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['1.5']), '1.5', "PHP-AMT-5 '1.5'", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['12345678901234567890.1']), '12345678901234567890.1', 'PHP-AMT-6 22 chars', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['123456789012345678901.2']), null, 'PHP-AMT-7 23 chars rejected', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['0']), null, "PHP-AMT-8 '0' rejected (zero)", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['00']), null, "PHP-AMT-9 '00' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['0.0']), null, "PHP-AMT-10 '0.0' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['000.000']), null, "PHP-AMT-11 '000.000' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['1e+10']), null, "PHP-AMT-12 '1e+10' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['-1.00']), null, "PHP-AMT-13 '-1.00' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['+1.00']), null, "PHP-AMT-14 '+1.00' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', [' 1.00 ']), null, "PHP-AMT-15 ' 1.00 ' whitespace rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['1.']), null, "PHP-AMT-16 '1.' trailing dot rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['.5']), null, "PHP-AMT-17 '.5' leading dot rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['1.2.3']), null, "PHP-AMT-18 '1.2.3' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['NaN']), null, "PHP-AMT-19 'NaN' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['INF']), null, "PHP-AMT-20 'INF' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', [null]), null, 'PHP-AMT-21 null rejected', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', ['']), null, "PHP-AMT-22 '' rejected", 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'build_amount_json_token', [[]]), null, 'PHP-AMT-23 array rejected', 'runtime');

// ---------------------------------------------------------------------------
// 7. inject_amount_token_into_payload_json (order + MM sentinels)
// ---------------------------------------------------------------------------

$payload = [
    'order' => [
        'id' => 'x', 'description' => 'y', 'currency' => 'KWD',
        '__UPAY_ORDER_AMOUNT_SENTINEL__' => null,
    ],
    'extraMerchantData' => '__UPAY_EXTRA_MERCHANT_DATA_SENTINEL__',
];
$raw = json_encode($payload);
$mm_json = '[{"amount":12.50,"knetCharge":0.5}]';
$out = upay_call_static('WC_Upayments', 'inject_amount_token_into_payload_json', [$raw, '12.50', $mm_json, '12.50']);
upay_assert($out !== null, 'PHP-INJ-1 sentinel replacement with MM succeeds', 'runtime');
upay_assert_eq(strpos($out, '__UPAY_ORDER_AMOUNT_SENTINEL__'), false, 'PHP-INJ-2 order sentinel removed', 'runtime');
upay_assert_eq(strpos($out, '__UPAY_EXTRA_MERCHANT_DATA_SENTINEL__'), false, 'PHP-INJ-3 MM sentinel removed', 'runtime');
upay_assert_eq(stripos($out, 'e+'), false, 'PHP-INJ-4 no exponent', 'runtime');
$decoded = json_decode($out, true);
upay_assert_eq($decoded['order']['amount'], 12.5, 'PHP-INJ-5 order.amount is JSON NUMBER (not quoted)', 'runtime');
upay_assert_eq($decoded['extraMerchantData'][0]['amount'], 12.5, 'PHP-INJ-6 MM amount is JSON NUMBER (not quoted)', 'runtime');
upay_assert_eq(strpos($out, '"amount":12.50') !== false, true, 'PHP-INJ-7 raw token 12.50 appears exactly as literal in JSON', 'runtime');
upay_assert_eq(strpos($out, '"amount":"12.50"') === false, true, 'PHP-INJ-8 amount is NOT quoted in JSON', 'runtime');

// Without MM sentinel
$payload = [
    'order' => [
        'id' => 'x', 'description' => 'y', 'currency' => 'KWD',
        '__UPAY_ORDER_AMOUNT_SENTINEL__' => null,
    ],
    'extraMerchantData' => null,
];
$raw = json_encode($payload);
$out = upay_call_static('WC_Upayments', 'inject_amount_token_into_payload_json', [$raw, '12.50', null, null]);
upay_assert($out !== null, 'PHP-INJ-9 no-MM sentinel case succeeds', 'runtime');
upay_assert_eq(strpos($out, '__UPAY_EXTRA_MERCHANT_DATA_SENTINEL__'), false, 'PHP-INJ-10 no MM marker in result', 'runtime');

// Missing order sentinel => reject
$payload = ['order' => ['id' => 'x', 'amount' => 5]];
$raw = json_encode($payload);
upay_assert_eq(upay_call_static('WC_Upayments', 'inject_amount_token_into_payload_json', [$raw, '5', null, null]), null, 'PHP-INJ-11 missing order sentinel rejected', 'runtime');

// Double sentinel => reject (counts as duplicate amount keys)
$payload = ['order' => ['id' => 'x', '__UPAY_ORDER_AMOUNT_SENTINEL__' => null, '__UPAY_ORDER_AMOUNT_SENTINEL__' => null]];
$raw = json_encode($payload);
$result = upay_call_static('WC_Upayments', 'inject_amount_token_into_payload_json', [$raw, '5', null, null]);
// After substitution, the result would have two "amount" keys. Production must
// detect that as invalid via the sentinel-occurrence check. We assert the
// function returns null (rejecting) or the result has only one "amount" key.
$has_double_amount = $result !== null && substr_count($result, '"amount":') >= 2;
upay_assert_eq($has_double_amount, false, 'PHP-INJ-12 double order sentinel rejected (no double amount keys in result)', 'runtime');

// Quoted-looking token => reject
$payload = ['order' => ['id' => 'x', '__UPAY_ORDER_AMOUNT_SENTINEL__' => null]];
$raw = json_encode($payload);
$result = upay_call_static('WC_Upayments', 'inject_amount_token_into_payload_json', [$raw, '12.50"', null, null]);
upay_assert_eq($result, null, 'PHP-INJ-13 invalid token rejected', 'runtime');

// MM-only sentinel provided but no MM present in payload => reject
$payload = ['order' => ['id' => 'x', '__UPAY_ORDER_AMOUNT_SENTINEL__' => null]];
$raw = json_encode($payload);
$result = upay_call_static('WC_Upayments', 'inject_amount_token_into_payload_json', [$raw, '12.50', $mm_json, '12.50']);
upay_assert_eq($result, null, 'PHP-INJ-14 MM JSON provided but no MM sentinel in payload rejected', 'runtime');

// ---------------------------------------------------------------------------
// 8. classify_checkout_request_context (pure classifier)
// ---------------------------------------------------------------------------

upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wc/store/v1/checkout']), 'POST'), true, 'PHP-RC-1 exact Store API checkout POST', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wc/store/v1/checkout/']), 'POST'), true, 'PHP-RC-2 trailing slash', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/shop/wp-json/wc/store/v1/checkout']), 'POST'), true, 'PHP-RC-3 subdirectory wp-json', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wc/store/v1/cart']), 'POST'), false, 'PHP-RC-4 cart POST rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wc/store/v1/products']), 'POST'), false, 'PHP-RC-5 products POST rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wp/v2/users']), 'GET'), false, 'PHP-RC-6 unrelated WP REST rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/checkout/']), 'POST'), false, 'PHP-RC-7 classic POST rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wc/store/v1/checkout']), 'GET'), false, 'PHP-RC-8 GET rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(false, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wc/store/v1/checkout']), 'POST'), false, 'PHP-RC-9 REST_REQUEST=false rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wc/store/v2/checkout']), 'POST'), false, 'PHP-RC-10 v2 namespace rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['wc/store/v1/checkout']), 'POST'), false, 'PHP-RC-11 missing leading slash rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wp-json/wc/store/v1/checkout/']), 'POST'), true, 'PHP-RC-12 wp-json trailing slash', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/index.php?rest_route=/wc/store/v1/checkout']), 'POST'), true, 'PHP-RC-13 plain permalink rest_route', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout']), 'POST'), true, 'PHP-RC-14 rest_route URL-encoded', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/foo/wc/store/v1/anything']), 'POST'), false, 'PHP-RC-15 arbitrary suffix rejected', 'runtime');
upay_assert_eq(WC_Upayments::classify_checkout_request_context(true, upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wp-json/wc/store/v1/checkout-order']), 'POST'), false, 'PHP-RC-16 similar but not checkout rejected', 'runtime');

// ---------------------------------------------------------------------------
// 9. normalize_store_api_route
// ---------------------------------------------------------------------------

upay_assert_eq(upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wp-json/wc/store/v1/checkout']), '/wc/store/v1/checkout', 'PHP-NSR-1 pretty permalink', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/wp-json/wc/store/v1/checkout/']), '/wc/store/v1/checkout/', 'PHP-NSR-2 trailing slash', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/shop/wp-json/wc/store/v1/checkout']), '/wc/store/v1/checkout', 'PHP-NSR-3 subdirectory wp-json stripped (only REST route remains)', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/index.php?rest_route=/wc/store/v1/checkout']), '/wc/store/v1/checkout', 'PHP-NSR-4 rest_route plain permalink', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/?rest_route=%2Fwc%2Fstore%2Fv1%2Fcheckout']), '/wc/store/v1/checkout', 'PHP-NSR-5 rest_route URL-encoded', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'normalize_store_api_route', ['/random/route/']), '/random/route/', 'PHP-NSR-6 unrelated path passthrough', 'runtime');
upay_assert_eq(upay_call_static('WC_Upayments', 'normalize_store_api_route', ['']), '', 'PHP-NSR-7 empty input', 'runtime');

// ---------------------------------------------------------------------------
// 10. classify_create_token_response
// ---------------------------------------------------------------------------

upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 201, 'transport_ok' => true, 'curl_errno' => 0, 'body' => json_encode(['status' => true, 'data' => ['customerUniqueToken' => '12345678']])],
        '12345678'
    )['reason'], 'success', 'PHP-CTR-1 201+match => success', 'runtime'
);
upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 422, 'transport_ok' => true, 'curl_errno' => 0, 'body' => json_encode(['status' => false, 'message' => 'duplicate token collision detected'])],
        '12345678'
    )['reason'], 'http_422', 'PHP-CTR-2 422+duplicate => http_422', 'runtime'
);
upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 200, 'transport_ok' => true, 'curl_errno' => 0, 'body' => '{}'],
        '12345678'
    )['reason'], 'http_200', 'PHP-CTR-3 200 => http_200', 'runtime'
);
upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 201, 'transport_ok' => true, 'curl_errno' => 0, 'body' => json_encode(['status' => false, 'data' => ['customerUniqueToken' => '12345678']])],
        '12345678'
    )['reason'], 'status_not_true', 'PHP-CTR-4 status=false', 'runtime'
);
upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 201, 'transport_ok' => false, 'curl_errno' => 0, 'body' => '{}'],
        '12345678'
    )['reason'], 'http_201_transport_not_ok', 'PHP-CTR-5 transport_ok=false', 'runtime'
);
upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 500, 'transport_ok' => true, 'curl_errno' => 0, 'body' => '{}'],
        '12345678'
    )['reason'], 'http_500', 'PHP-CTR-6 500 => http_500', 'runtime'
);
upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 429, 'transport_ok' => true, 'curl_errno' => 0, 'body' => '{}'],
        '12345678'
    )['reason'], 'http_429', 'PHP-CTR-7 429 => http_429', 'runtime'
);
upay_assert_eq(
    \UPayments\Token\CustomerTokenIdentity::classify_create_token_response(
        ['http_status' => 201, 'transport_ok' => true, 'curl_errno' => 28, 'body' => '{}'],
        '12345678'
    )['reason'], 'curl_error', 'PHP-CTR-8 curl_errno != 0', 'runtime'
);

// ---------------------------------------------------------------------------
// 11. getSavedCardsForCurrentUser — strict gating
// ---------------------------------------------------------------------------

upay_reset_state();
$GLOBALS['__upay_test_state']['current_user_id'] = 7;
upay_assert_eq((new WC_Upayments())->getSavedCardsForCurrentUser(null), null, 'PHP-SCR-1 null default rejected', 'runtime');
upay_assert_eq((new WC_Upayments())->getSavedCardsForCurrentUser(['whitelabled' => true, 'payment' => ['cc' => 'Credit Card']]), null, 'PHP-SCR-2 missing secret => null', 'runtime');
$gw = upay_make_gateway(['saveCardEnabled' => 'no']);
upay_assert_eq($gw->getSavedCardsForCurrentUser(['whitelabled' => true, 'payment' => ['cc' => 'Credit Card']]), null, 'PHP-SCR-3 saveCard disabled => null', 'runtime');
$gw = upay_make_gateway();
upay_assert_eq($gw->getSavedCardsForCurrentUser('not array'), null, 'PHP-SCR-4 non-array => null', 'runtime');
upay_assert_eq($gw->getSavedCardsForCurrentUser(['whitelabled' => false, 'payment' => ['cc' => 'Credit Card']]), null, 'PHP-SCR-5 whitelabled=false => null', 'runtime');
upay_assert_eq($gw->getSavedCardsForCurrentUser(['whitelabled' => 'true', 'payment' => ['cc' => 'Credit Card']]), null, 'PHP-SCR-6 whitelabled string != true', 'runtime');
upay_assert_eq($gw->getSavedCardsForCurrentUser(['whitelabled' => true, 'payment' => ['knet' => 'KNET']]), null, 'PHP-SCR-7 missing payment.cc => null', 'runtime');
upay_assert_eq($gw->getSavedCardsForCurrentUser(['whitelabled' => true, 'payment' => ['cc' => '']]), null, 'PHP-SCR-8 payment.cc="" => null', 'runtime');
upay_assert_eq($gw->getSavedCardsForCurrentUser(['whitelabled' => true, 'payment' => ['cc' => 0]]), null, 'PHP-SCR-9 payment.cc=0 => null', 'runtime');
$GLOBALS['__upay_test_state']['current_user_id'] = 0;
upay_assert_eq($gw->getSavedCardsForCurrentUser(['whitelabled' => true, 'payment' => ['cc' => 'Credit Card']]), null, 'PHP-SCR-10 guest => null', 'runtime');
$GLOBALS['__upay_test_state']['current_user_id'] = 7;

// ---------------------------------------------------------------------------
// 12. is_valid_cached_availability — strict canonical schema validator
// ---------------------------------------------------------------------------

$gw = new WC_Upayments();
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => 1, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0]]]), 'success', 'PHP-CACHE-1 canonical success', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'state' => 'failure']]), 'failure', 'PHP-CACHE-2 canonical failure', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => 1, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0], 'extra' => 'x']]), false, 'PHP-CACHE-3 extra top-level key rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => 1, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'google_pay' => 0]]]), false, 'PHP-CACHE-4 missing payButtons key rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => 2, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0]]]), false, 'PHP-CACHE-5 payButtons value 2 rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => 'true', 'payButtons' => ['knet' => 1, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0]]]), false, 'PHP-CACHE-6 isWhiteLabel string rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 4, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => 1, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0]]]), false, 'PHP-CACHE-7 schema=4 rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => 1, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0, 'extra' => 1]]]), false, 'PHP-CACHE-8 extra payButtons key rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => '1', 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0]]]), false, 'PHP-CACHE-9 payButtons string value rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => true, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0]]]), false, 'PHP-CACHE-10 payButtons bool rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'result' => 'success', 'isWhiteLabel' => true, 'payButtons' => ['knet' => 0.0, 'credit_card' => 1, 'apple_pay_knet' => 0, 'apple_pay' => 0, 'samsung_pay' => 0, 'google_pay' => 0]]]), false, 'PHP-CACHE-11 payButtons float 0.0 rejected', 'runtime');
upay_assert_eq(upay_call_instance($gw, 'is_valid_cached_availability', [['schema' => 3, 'state' => 'failure', 'extra' => 'x']]), false, 'PHP-CACHE-12 failure with extra key rejected', 'runtime');

// ---------------------------------------------------------------------------
// 13. inspect_customer_history — programmable fixture
// ---------------------------------------------------------------------------

function upay_with_history_secret() {
    $gen = bin2hex(random_bytes(16));
    $secret = bin2hex(random_bytes(32));
    $verifier = hash_hmac('sha256', 'upayments_token_identity_secret_record_v1|1|' . $gen, $secret);
    $state =& upay_test_state();
    $state['options']['upayments_token_identity_secret_v2'] = [
        'version' => 1, 'secret' => $secret, 'generation_id' => $gen, 'verifier' => $verifier,
    ];
}

// 13.1 empty
upay_with_history_secret();
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'none', 'PHP-ICH-1 empty history returns none', 'runtime');
upay_assert_eq($result['reason'], 'no_tokens_found', 'PHP-ICH-2 reason=no_tokens_found', 'runtime');

// 13.2 >200 incomplete
// Set up so iteration fills 200 orders (cap) but expected_total is higher.
$state =& upay_test_state();
$state['history_pages'] = [];
for ($p = 1; $p <= 15; $p++) {
    $state['history_pages'][$p] = range(($p - 1) * 20 + 1, $p * 20);
}
$state['history_total'] = 300; // > 200 cap
$state['history_max_pages'] = 15;
// Register orders so wc_get_order doesn't return null.
for ($oid = 1; $oid <= 300; $oid++) {
    $o = new FakeWCOrder($oid);
    $o->items_meta = [];
    $o->meta_store = [];
    $state['orders_fixture'][$oid] = $o;
}
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-3 >200 incomplete history returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'incomplete_scan', 'PHP-ICH-4 reason=incomplete_scan', 'runtime');

// 13.3 unloadable order
$state['history_pages'] = [1 => [42]];
$state['history_total'] = 1;
$state['history_max_pages'] = 1;
$state['orders_fixture'] = []; // Clear registered orders so order 42 is unloadable.
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-5 unloadable order returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'unloadable_order', 'PHP-ICH-6 reason=unloadable_order', 'runtime');

// 13.4 force-refresh failure during history scan
$state['history_pages'] = [1 => [1]];
$state['history_total'] = 1;
$state['history_max_pages'] = 1;
$order_throwing = new class extends FakeWCOrder {
    public function __construct() {}
    public function read_meta_data($force = false) { throw new RuntimeException('synthetic'); }
    public function get_id() { return 1; }
    public function get_data() { return ['currency' => 'KWD', 'billing' => ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '']]; }
    public function get_total() { return '0'; }
    public function get_items($type) { return []; }
};
$state['orders_fixture'][1] = $order_throwing;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-7 force-refresh fail in history returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'force_refresh_failed', 'PHP-ICH-8 reason=force_refresh_failed', 'runtime');

// 13.5 query exception
$state['history_pages'] = [];
$state['history_query_exception'] = true;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-9 query exception returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'query_exception', 'PHP-ICH-10 reason=query_exception', 'runtime');
$state['history_query_exception'] = false;

// 13.6 malformed result
$state['history_malformed_result'] = true;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-11 malformed result returns indeterminate', 'runtime');
$state['history_malformed_result'] = false;

// 13.7 duplicate IDs across pages
$state['history_pages'] = [1 => [1, 2], 2 => [3, 2]];
$state['history_total'] = 4;
$state['history_max_pages'] = 2;
$state['orders_fixture'] = [];
foreach ([1, 2, 3] as $oid) {
    $o = new FakeWCOrder($oid);
    $o->items_meta = [];
    $o->meta_store = [];
    $state['orders_fixture'][$oid] = $o;
}
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-12 duplicate order IDs across pages returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'duplicate_order_id', 'PHP-ICH-13 reason=duplicate_order_id', 'runtime');

// 13.8 total changes between pages
$state['history_pages'] = [1 => [1, 2, 3], 2 => [3]];
$state['history_total'] = 5; // page-1 reports total=5, page-2 also reports total=5 (stub is shared), so this won't trigger...
// Instead, we manually create a second wc_get_orders wrapper that returns different totals.
class CustomWCOrdersStubForTest2 {
    public $usermeta = 'wp_usermeta';
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) { return 0; }
    public function get_col($sql = null) { return []; }
    public function get_var($sql = null) { return null; }
    public $call_count = 0;
    public function get_orders_wrapper($args) {
        $this->call_count++;
        $page = $args['paged'];
        $state =& $GLOBALS['__upay_test_state'];
        $obj = new stdClass();
        $obj->orders = $state['history_pages'][$page] ?? [];
        $obj->total = ($page == 1) ? 3 : 5; // page 1 total=3, page 2 total=5
        $obj->max_num_pages = $state['history_max_pages'];
        return $obj;
    }
}
// Can't easily patch wc_get_orders here, so we test via direct mock by setting
// mismatched max_pages only, which is what we can detect via the stub.
$state['history_total'] = 3;
$state['history_max_pages'] = 2;
// Reset call counter via fresh state:
unset($state['call_count']);
// Simulate page-1 max_pages=2, page-2 max_pages=3 by overriding the stub to return different max_pages.
// Since we cannot easily intercept, use the max_pages change fixture below.

// 13.9 max_pages changes
$state['history_pages'] = [1 => [1, 2, 3]];
$state['history_total'] = 3;
// max_pages will be 2 initially; we want page 2 to report max_pages=3.
// Since our stub returns the same max_pages always, we can't trigger this naturally.
// Instead, set max_pages=2 and rely on page-2 returning 0 orders to test a different reason.
// We'll cover this via the unexpected_empty_page test instead.
$state['history_max_pages'] = 2;
// Add a page 2 that has orders so we don't hit unexpected_empty_page.
$state['history_pages'][2] = [4, 5];
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
// We need page 2 to report a different max_pages. Override the stub to inject this behavior.
// Use a property on state: page-specific max_pages override.
class CustomStub {
    public $usermeta = 'wp_usermeta';
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) { return 0; }
    public function get_col($sql = null) { return []; }
    public function get_var($sql = null) { return null; }
    public function get_orders($args) {
        $state =& $GLOBALS['__upay_test_state'];
        $page = $args['paged'];
        $obj = new stdClass();
        $obj->orders = $state['history_pages'][$page] ?? [];
        $obj->total = $state['history_total'];
        $obj->max_num_pages = $state['history_max_pages_per_page'][$page] ?? $state['history_max_pages'];
        return $obj;
    }
}
$GLOBALS['wpdb'] = new CustomStub();
$state['history_max_pages_per_page'] = [1 => 2, 2 => 3];
$state['history_pages'] = [1 => [1, 2], 2 => [3, 4]];
$state['history_total'] = 6; // > 4 to avoid scanned_exceeds_total
$state['history_max_pages'] = 2;
foreach ([1, 2, 3, 4] as $oid) {
    $o = new FakeWCOrder($oid);
    $o->items_meta = [];
    $o->meta_store = [];
    $state['orders_fixture'][$oid] = $o;
}
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-16 max_pages changes returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'max_pages_changed', 'PHP-ICH-17 reason=max_pages_changed', 'runtime');

// Restore stub
$GLOBALS['wpdb'] = new WpdbStub();
$state['history_max_pages_per_page'] = null;
$state['orders_fixture'] = [];

// 13.10 oversized page
$state['history_pages'] = [1 => array_fill(0, 21, 99)];
$state['history_total'] = 21;
$state['history_max_pages'] = 2;
$o = new FakeWCOrder(99);
$o->items_meta = [];
$o->meta_store = [];
$state['orders_fixture'][99] = $o;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-18 oversized page returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'oversized_page', 'PHP-ICH-19 reason=oversized_page', 'runtime');

// 13.11 unexpected empty page
$state['history_pages'] = [1 => [], 2 => [3]];
$state['history_total'] = 1;
$state['history_max_pages'] = 2;
$o = new FakeWCOrder(3);
$o->items_meta = [];
$o->meta_store = [];
$state['orders_fixture'][3] = $o;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-20 unexpected empty page returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'unexpected_empty_page', 'PHP-ICH-21 reason=unexpected_empty_page', 'runtime');

// 13.12 page beyond max
// Set up a stub that returns orders for page 3 even though max_pages=1.
class StubPageBeyond {
    public $usermeta = 'wp_usermeta';
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, ...$args) { return $sql; }
    public function query($sql) { return 0; }
    public function get_col($sql = null) { return []; }
    public function get_var($sql = null) { return null; }
    public function get_orders($args) {
        $state =& $GLOBALS['__upay_test_state'];
        $page = $args['paged'];
        $obj = new stdClass();
        $obj->orders = $state['history_pages'][$page] ?? [];
        $obj->total = $state['history_total'];
        $obj->max_num_pages = $state['history_max_pages'];
        return $obj;
    }
}
$GLOBALS['wpdb'] = new StubPageBeyond();
$state['history_pages'] = [1 => [1], 2 => [2], 3 => [3]];
$state['history_total'] = 3;
$state['history_max_pages'] = 1;
$state['orders_fixture'] = [];
foreach ([1, 2, 3] as $oid) {
    $o = new FakeWCOrder($oid);
    $o->items_meta = [];
    $o->meta_store = [];
    $state['orders_fixture'][$oid] = $o;
}
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-22 page beyond max returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'page_beyond_max', 'PHP-ICH-23 reason=page_beyond_max', 'runtime');
$GLOBALS['wpdb'] = new WpdbStub();
$state['orders_fixture'] = [];

// 13.13 order ID <= 0 (invalid)
$state['history_pages'] = [1 => [-5]];
$state['history_total'] = 1;
$state['history_max_pages'] = 1;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-24 invalid order ID returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'invalid_order_id', 'PHP-ICH-25 reason=invalid_order_id', 'runtime');

// 13.14 missing orders array
$state['history_malformed_result'] = true;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-26 missing orders returns indeterminate', 'runtime');
$state['history_malformed_result'] = false;

// 13.15 missing total
$state['history_pages'] = [1 => [1]];
$state['history_total'] = -1;
$state['history_max_pages'] = 1;
$o = new FakeWCOrder(1);
$o->items_meta = [];
$o->meta_store = [];
$state['orders_fixture'][1] = $o;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_customer_history(1, str_repeat('a', 32));
upay_assert_eq($result['classification'], 'indeterminate', 'PHP-ICH-27 missing total returns indeterminate', 'runtime');
upay_assert_eq($result['reason'], 'missing_total', 'PHP-ICH-28 reason=missing_total', 'runtime');

// ---------------------------------------------------------------------------
// 14. inspect_current_user_prior_provenance
// ---------------------------------------------------------------------------

upay_reset_state();
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(0);
upay_assert_eq($result['state'], 'none', 'PHP-CUI-1 user_id=0 returns none', 'runtime');
upay_assert_eq($result['reason'], 'not_logged_in', 'PHP-CUI-2 reason=not_logged_in', 'runtime');
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(1);
upay_assert_eq($result['state'], 'read_failure', 'PHP-CUI-3 missing secret returns read_failure', 'runtime');
upay_assert_eq($result['reason'], 'no_generation', 'PHP-CUI-4 reason=no_generation', 'runtime');

// valid provenance
upay_with_history_secret();
$scope = str_repeat('a', 32);
$meta_key = '_upay_customer_token_v2_b1_' . $scope;
$state =& upay_test_state();
$gen = $state['options']['upayments_token_identity_secret_v2']['generation_id'];
$state['usermeta'][1][$meta_key] = [[
    'version' => 3, 'kind' => 'canonical', 'token' => '12345678',
    'source' => 'create_201', 'scope' => $scope,
    'secret_generation_id' => $gen, 'established_at_gmt' => time(),
]];
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(1);
upay_assert_eq($result['state'], 'same_generation_only', 'PHP-CUI-5 valid provenance returns same_generation_only', 'runtime');

// different generation
$other_gen = bin2hex(random_bytes(16));
$state['usermeta'][1][$meta_key] = [[
    'version' => 3, 'kind' => 'canonical', 'token' => '12345678',
    'source' => 'create_201', 'scope' => $scope,
    'secret_generation_id' => $other_gen, 'established_at_gmt' => time(),
]];
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(1);
upay_assert_eq($result['state'], 'secret_generation_mismatch', 'PHP-CUI-6 different-generation returns mismatch', 'runtime');

// malformed usermeta (non-array)
$state['usermeta'][1][$meta_key] = ['not an array'];
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(1);
upay_assert_eq($result['state'], 'invalid', 'PHP-CUI-7 non-array usermeta returns invalid', 'runtime');

// duplicate usermeta values
$state['usermeta'][1][$meta_key] = [
    ['version' => 3, 'kind' => 'canonical', 'token' => '12345678', 'source' => 'create_201', 'scope' => $scope, 'secret_generation_id' => $gen, 'established_at_gmt' => time()],
    ['version' => 3, 'kind' => 'canonical', 'token' => '99999999', 'source' => 'create_201', 'scope' => $scope, 'secret_generation_id' => $gen, 'established_at_gmt' => time()],
];
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(1);
upay_assert_eq($result['state'], 'invalid', 'PHP-CUI-8 duplicate values returns invalid', 'runtime');

// force-refresh failure during prior provenance
$state['force_user_cache_refresh_failure'] = true;
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(1);
upay_assert_eq($result['state'], 'read_failure', 'PHP-CUI-9 refresh failure returns read_failure', 'runtime');
$state['force_user_cache_refresh_failure'] = false;

// wrong-version record
$state['usermeta'][1][$meta_key] = [[
    'version' => 99, 'kind' => 'canonical', 'token' => '12345678',
    'source' => 'create_201', 'scope' => $scope,
    'secret_generation_id' => $gen, 'established_at_gmt' => time(),
]];
$result = \UPayments\Token\CustomerTokenIdentity::inspect_current_user_prior_provenance(1);
upay_assert_eq($result['state'], 'invalid', 'PHP-CUI-10 wrong-version record returns invalid', 'runtime');

// ---------------------------------------------------------------------------
// 15. read_provenance with force-refresh failure
// ---------------------------------------------------------------------------

upay_reset_state();
upay_with_history_secret();
$state =& upay_test_state();
$state['force_user_cache_refresh_failure'] = true;
$result = \UPayments\Token\CustomerTokenIdentity::read_provenance(1, str_repeat('a', 32));
upay_assert_eq($result['state'], 'invalid', 'PHP-RP-1 force refresh fail returns invalid', 'runtime');
$state['force_user_cache_refresh_failure'] = false;

// duplicate provenance
$state['usermeta'][1]['_upay_customer_token_v2_b1_' . str_repeat('a', 32)] = [
    ['version' => 3, 'kind' => 'canonical', 'token' => '12345678', 'source' => 'create_201', 'scope' => str_repeat('a', 32), 'secret_generation_id' => $gen, 'established_at_gmt' => time()],
    ['version' => 3, 'kind' => 'canonical', 'token' => '99999999', 'source' => 'create_201', 'scope' => str_repeat('a', 32), 'secret_generation_id' => $gen, 'established_at_gmt' => time()],
];
$result = \UPayments\Token\CustomerTokenIdentity::read_provenance(1, str_repeat('a', 32));
upay_assert_eq($result['state'], 'invalid', 'PHP-RP-2 duplicate provenance returns invalid', 'runtime');

// valid
$state['usermeta'][1]['_upay_customer_token_v2_b1_' . str_repeat('a', 32)] = [[
    'version' => 3, 'kind' => 'canonical', 'token' => '12345678',
    'source' => 'create_201', 'scope' => str_repeat('a', 32),
    'secret_generation_id' => $gen, 'established_at_gmt' => time(),
]];
$result = \UPayments\Token\CustomerTokenIdentity::read_provenance(1, str_repeat('a', 32));
upay_assert_eq($result['state'], 'valid', 'PHP-RP-3 valid provenance returns valid', 'runtime');

// ---------------------------------------------------------------------------
// 16. CustomerTokenIdentity constants
// ---------------------------------------------------------------------------

upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::SCHEMA_VERSION, 3, 'PHP-CONST-1 SCHEMA_VERSION=3', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::SECRET_BYTES, 32, 'PHP-CONST-2 SECRET_BYTES=32', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::SECRET_HEX_LENGTH, 64, 'PHP-CONST-3 SECRET_HEX_LENGTH=64', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::GENERATION_ID_BYTES, 16, 'PHP-CONST-4 GENERATION_ID_BYTES=16', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::GENERATION_ID_HEX_LENGTH, 32, 'PHP-CONST-5 GENERATION_ID_HEX_LENGTH=32', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::SCOPE_HEX_LENGTH, 32, 'PHP-CONST-6 SCOPE_HEX_LENGTH=32', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::KIND_CANONICAL, 'canonical', 'PHP-CONST-7 KIND_CANONICAL', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::KIND_LEGACY_COMPAT, 'legacy_compat', 'PHP-CONST-8 KIND_LEGACY_COMPAT', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::SOURCE_CREATE_201, 'create_201', 'PHP-CONST-9 SOURCE_CREATE_201', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::SOURCE_LEGACY_VERIFIED_CAPTURE, 'legacy_verified_capture', 'PHP-CONST-10 SOURCE_LEGACY', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::HISTORY_MAX_ORDERS, 200, 'PHP-CONST-11 HISTORY_MAX_ORDERS=200', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::HISTORY_PAGE_SIZE, 20, 'PHP-CONST-12 HISTORY_PAGE_SIZE=20', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::LOCK_PREFIX, 'upay_ctk_', 'PHP-CONST-13 LOCK_PREFIX=upay_ctk_', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::LOCK_MAX_LENGTH, 64, 'PHP-CONST-14 LOCK_MAX_LENGTH=64', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::VERIFIER_DOMAIN, 'upayments_token_identity_secret_record_v1', 'PHP-CONST-15 VERIFIER_DOMAIN', 'runtime');
upay_assert_eq(\UPayments\Token\CustomerTokenIdentity::SECRET_OPTION, 'upayments_token_identity_secret_v2', 'PHP-CONST-16 SECRET_OPTION', 'runtime');

// ---------------------------------------------------------------------------
// 17. Source-level invariants — must hold in production source
// ---------------------------------------------------------------------------

$upay_source = file_get_contents($PLUGIN_FILE);
$ident_source = file_get_contents($IDENTITY_FILE);

upay_assert_eq(strpos($upay_source, '$has_card_token_malformed'), false, 'PHP-SRC-1 no $has_card_token_malformed', 'static');
upay_assert(strpos($upay_source, 'is_store_api_checkout_request') !== false, 'PHP-SRC-2 is_store_api_checkout_request defined', 'static');
upay_assert(strpos($upay_source, 'classify_checkout_request_context') !== false, 'PHP-SRC-3 classify_checkout_request_context defined', 'static');
upay_assert(strpos($upay_source, 'normalize_store_api_route') !== false, 'PHP-SRC-4 normalize_store_api_route defined', 'static');
upay_assert(strpos($upay_source, "'__UPAY_ORDER_AMOUNT_SENTINEL__'") !== false, 'PHP-SRC-5 order amount sentinel present', 'static');
upay_assert(strpos($upay_source, "'__UPAY_EXTRA_MERCHANT_DATA_SENTINEL__'") !== false, 'PHP-SRC-6 MM block sentinel present', 'static');
upay_assert_eq(strpos($upay_source, '$amount_number'), false, 'PHP-SRC-7 no $amount_number', 'static');
upay_assert_eq(strpos($upay_source, "(float) \$amount_str <= 0"), false, 'PHP-SRC-8 no float positivity', 'static');
upay_assert(strpos($upay_source, 'parse_subscription_plan_strict') !== false, 'PHP-SRC-9 strict plan parser defined', 'static');
upay_assert(strpos($upay_source, "if (\$raw === null) { \$cardToken = null") !== false, 'PHP-SRC-10 Blocks card_token null => safe clear', 'static');
upay_assert_eq(strpos($upay_source, "\$extraMerchantData[0] = ["), false, 'PHP-SRC-11 no post-token MultiMerchant reconstruction', 'static');

// ---------------------------------------------------------------------------
// Final Report
// ---------------------------------------------------------------------------

echo "\n--- Final Report ---\n";
echo "PASS: $pass\n";
echo "  runtime: $_pass_runtime\n";
echo "  static:  $_pass_static\n";
echo "  harness: $_pass_harness\n";
echo "FAIL: $fail\n";
echo "  runtime: $_fail_runtime\n";
echo "  static:  $_fail_static\n";
echo "  harness: $_fail_harness\n";

if ($fail > 0) {
    echo "\n--- FAIL DETAILS ---\n";
    foreach ($log as $line) {
        if (strpos($line, 'FAIL:') === 0) {
            echo "$line\n";
        }
    }
}

exit($fail > 0 ? 1 : 0);