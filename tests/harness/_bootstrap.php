<?php
/**
 * Shared bootstrap for the phase-9g-h12 harness family.
 *
 * Defines sandboxed WP/Woo function stubs, requires the production
 * plugin source (UPayments.php + CustomerTokenIdentity.php), and invokes
 * woocommerceUpaymentsInit() so both the parent PHP harness and the Store
 * API subprocess child can run real production code.
 *
 * Do NOT execute this file directly. It is loaded via require_once by
 * phase-9g-h12-php-harness.php and tests/harness/store_api_child.php.
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
    'history_total_per_page' => [],
    'history_max_pages' => 0,
    'history_max_pages_per_page' => [],
    'history_query_exception' => false,
    'history_malformed_result' => false,
    'wc_get_orders_calls' => [],
    // history appearing during bootstrap critical section
    'history_mutation_during_lock' => false,
    // secret-absent race: history / malformed appears during / before census
    'secret_state_during_bootstrap' => 'absent',
    // orders registered for wc_get_order
    'orders_fixture' => [],
    // provider transport fixtures
    'transport_route' => null,
    'transport_response' => null,
    'transport_log' => [],
    'availability_response' => null,
    // available-but-not-whitelabel fixtures
    'available_but_no_wl_response' => null,
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
    // locks acquired during a bootstrap (race-test control)
    'lock_held_names' => [],
    'force_lock_acquire_failure' => false,
    'bootstrap_call_count' => 0,
    // process_payment call counter (so we can detect re-entrancy)
    'process_payment_calls' => 0,
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
        'history_pages' => [], 'history_total' => 0, 'history_total_per_page' => [],
        'history_max_pages' => 0, 'history_max_pages_per_page' => [],
        'history_query_exception' => false, 'history_malformed_result' => false,
        'wc_get_orders_calls' => [],
        'history_mutation_during_lock' => false,
        'secret_state_during_bootstrap' => 'absent',
        'orders_fixture' => [],
        'transport_route' => null, 'transport_response' => null,
        'transport_log' => [],
        'availability_response' => null,
        'available_but_no_wl_response' => null,
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
        'lock_held_names' => [],
        'force_lock_acquire_failure' => false,
        'bootstrap_call_count' => 0,
        'process_payment_calls' => 0,
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
    $value = array_key_exists($name, $state['options']) ? $state['options'][$name] : $default;
    // Residual Correction #15: RACE-3 hook — mutate the secret AFTER the
    // first read of `upayments_token_identity_secret_v2` to simulate a
    // rotation between pre-insert and post-insert reads. Production's final
    // re-read will then observe a different generation and must roll back.
    if ($name === 'upayments_token_identity_secret_v2'
        && !empty($state['secret_mutation_after_first_read'])
        && !isset($state['_secret_mutation_consumed'])
        && is_array($value)
        && isset($value['version'])
    ) {
        $state['_secret_mutation_consumed'] = true;
        // Rotate the secret: bump generation_id and re-derive verifier.
        $old_gen = $value['generation_id'];
        $new_gen = 'ffff' . substr($old_gen, 4);
        $new_verifier = hash_hmac('sha256', 'upayments_token_identity_secret_record_v1|1|' . $new_gen, $value['secret']);
        $state['options']['upayments_token_identity_secret_v2'] = [
            'version' => $value['version'],
            'secret' => $value['secret'],
            'generation_id' => $new_gen,
            'verifier' => $new_verifier,
        ];
        return $value; // First read still returns the old value (rotation happens after).
    }
    return $value;
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
    // Residual Correction #15: WordPress semantics. When $value is provided as
    // a non-empty scalar, only matching values are removed; otherwise the
    // whole meta key is deleted.
    $state =& upay_test_state();
    if (!isset($state['delete_user_meta_calls'])) {
        $state['delete_user_meta_calls'] = [];
    }
    $state['delete_user_meta_calls'][] = [
        'user_id' => $user_id,
        'key' => $key,
        'value_provided' => ($value !== '' && $value !== null),
    ];
    if (!isset($state['usermeta'][$user_id][$key])) {
        return false;
    }
    $value_provided = ($value !== '' && $value !== null);
    if (!$value_provided) {
        unset($state['usermeta'][$user_id][$key]);
        return true;
    }
    $remaining = [];
    $deleted = false;
    foreach ($state['usermeta'][$user_id][$key] as $existing) {
        if (!$deleted && $existing === $value) {
            $deleted = true;
            continue;
        }
        $remaining[] = $existing;
    }
    if ($deleted) {
        if (count($remaining) === 0) {
            unset($state['usermeta'][$user_id][$key]);
        } else {
            $state['usermeta'][$user_id][$key] = $remaining;
        }
    }
    return $deleted;
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
    // Per-page total override (race: total count drifts between pages).
    if (isset($state['history_total_per_page'][$page])) {
        $obj->total = $state['history_total_per_page'][$page];
    } else {
        $obj->total = (int) $state['history_total'];
    }
    // Per-page max_pages override takes priority over the global default.
    if (isset($state['history_max_pages_per_page'][$page])) {
        $obj->max_num_pages = $state['history_max_pages_per_page'][$page];
    } else {
        $obj->max_num_pages = (int) $state['history_max_pages'];
    }
    $state['wc_get_orders_calls'][] = ['page' => $page, 'page_size' => $page_size];
    return $obj;
}
function wc_get_order($order_id) {
    return isset(upay_test_state()['orders_fixture'][$order_id]) ? upay_test_state()['orders_fixture'][$order_id] : null;
}
function wc_get_checkout_url() { return 'https://example.test/checkout/'; }
function wc_add_notice($message, $type = 'info') {
    $state =& upay_test_state();
    $state['notices'][] = ['type' => $type, 'message' => (string) $message];
}
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
function wp_salt($scheme = 'auth') { return 'test_salt_value_for_hmac'; }

class WpdbStub {
    public $usermeta = 'wp_usermeta';
    public $prefix = 'wp_';
    public $locks = [];
    public function esc_like($s) { return addcslashes($s, '_%\\'); }
    public function prepare($sql, ...$args) {
        $i = 0;
        $result = preg_replace_callback('/%([sd])/', function($m) use (&$i, $args) {
            if (!isset($args[$i])) return $m[1] === 's' ? "''" : '0';
            $val = $args[$i++];
            if ($m[1] === 'd') {
                return (string) ((int) $val);
            }
            return "'" . str_replace("'", "''", (string) $val) . "'";
        }, $sql);
        return $result;
    }
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
                if (!empty($state['force_lock_acquire_failure'])) {
                    return null;
                }
                if (!isset($state['locks'][$name])) {
                    $state['locks'][$name] = true;
                    $state['lock_held_names'][] = $name;
                    return '1';
                }
                return null;
            }
            return null;
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

if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType', false)) {
    eval('namespace Automattic\\WooCommerce\\Blocks\\Payments\\Integrations; class AbstractPaymentMethodType {
        protected $name = "";
        protected $settings = [];
        public function get_name() { return $this->name; }
        public function initialize() {}
        public function get_payment_method_data() { return []; }
    }');
}

if (!class_exists('WC_Upayments')) {
    fwrite(STDERR, "FATAL: WC_Upayments class missing\n");
    exit(1);
}

if (!class_exists('WC_Order_Item_Product', false)) {
    class WC_Order_Item_Product { /* production-type-guard stub */ }
}
if (!class_exists('WC_Product', false)) {
    class WC_Product { /* production-type-guard stub */ }
}
if (!class_exists('WC_Order', false)) {
    class WC_Order { /* production-type-guard stub */ }
}
if (!class_exists('WC_Payment_Gateway', false)) {
    class WC_Payment_Gateway { /* production-type-guard stub */ }
}
if (!class_exists('FakeWCProduct', false)) {
class FakeWCProduct extends \WC_Product {
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
}

/**
 * FakeWCOrderItem preserves raw fixture inputs so production code sees the
 * malformed shapes the production validator is supposed to reject. No casts
 * at construction time — production decides what's a number, what's not.
 */
if (!class_exists('FakeWCOrderItem', false)) {
class FakeWCOrderItem {
    public $product;
    public $quantity;
    public $total;
    public $name;
    public function __construct($product, $quantity, $total, $name = null) {
        $this->product = $product;
        $this->quantity = $quantity;     // raw, NOT (int) cast
        $this->total    = $total;        // raw, NOT (string) cast
        $this->name = $name !== null ? $name : $product->name;
    }
    public function get_product() { return $this->product; }
    public function get_quantity() { return $this->quantity; }
    public function get_total() { return $this->total; }
    public function get_name() { return $this->name; }
}
}

// Subclass used by tests that drive the full process_payment() flow.
// Extends WC_Order_Item_Product so the production instanceof gate at
// foreach $order->get_items('line_item') passes. FakeWCOrderItem itself
// is left untouched so the RAWITEM fixtures still exercise the strict
// (non-product) path.
if (!class_exists('FakeWCOrderItem_Product', false)) {
class FakeWCOrderItem_Product extends \WC_Order_Item_Product {
    public $product;
    public $quantity;
    public $total;
    public $name;
    public function __construct($product, $quantity, $total, $name = null) {
        $this->product = $product;
        $this->quantity = (int) $quantity; // production-grade: int strictly
        $this->total = is_string($total) ? $total : (string) $total;
        $this->name = $name !== null ? $name : $product->name;
    }
    public function get_product() { return $this->product; }
    public function get_quantity() { return $this->quantity; }
    public function get_total() { return $this->total; }
    public function get_name() { return $this->name; }
}
}

if (!class_exists('FakeWCOrder', false)) {
class FakeWCOrder extends \WC_Order {
    public $id;
    public $data;
    public $items_meta = [];
    /**
     * Multi-value meta store. $this->meta_store[$key] is an array of values —
     * it may be empty (no values), have exactly one value, have duplicates,
     * or contain malformed / non-scalar entries. get_meta() exposes these
     * faithfully to mirror real WooCommerce metadata cardinality.
     */
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
        // Deterministic decimal-string accumulation (no float) so the harness
        // cannot mask exactly the monetary bugs under review.
        $total = '0';
        foreach ($this->items_meta as $item) {
            $line = is_string($item->total) ? $item->total : (string) $item->total;
            $total = upay_decimal_string_add($total, $line);
        }
        return $total;
    }
    public function get_billing_email() { return $this->data['billing']['email']; }
    public function get_billing_phone() { return $this->data['billing']['phone']; }
    public function get_items($type) {
        return $type === 'line_item' ? $this->items_meta : [];
    }
    /**
     * Faithful multi-value metadata exposure. When $single is true, returns
     * the first value (or '' when empty). When $single is false, returns
     * WC_Meta_Data objects wrapping every stored value, mirroring WooCommerce
     * order metadata cardinality so history-inspection paths can see exactly
     * 0 / 1 / duplicate / non-scalar tuples.
     */
    public function get_meta($key, $single = false, $context = 'view') {
        $values = array_key_exists($key, $this->meta_store) ? $this->meta_store[$key] : [];
        if ($single) {
            return count($values) > 0 ? $values[0] : '';
        }
        return array_map(function($vv) { return new WC_Meta_Data($vv); }, $values);
    }
    public function meta_exists($key) {
        return array_key_exists($key, $this->meta_store)
            && count($this->meta_store[$key]) > 0;
    }
    public function add_meta_data($key, $value, $unique = false) {
        $state =& upay_test_state();
        $state['order_meta_writes']++;
        if ($key === '_upay_customer_unique_token' || $key === '_upay_customer_token_kind_v1'
            || $key === '_upay_customer_token_scope_v1' || $key === '_upay_customer_token_generation_v1') {
            $state['identity_writes']++;
            $state['provenance_writes']++;
        }
        if (!isset($this->meta_store[$key])) $this->meta_store[$key] = [];
        if ($unique) {
            // unique semantics: do not insert a duplicate scalar
            foreach ($this->meta_store[$key] as $existing) {
                if ($existing === $value) return;
            }
        }
        $this->meta_store[$key][] = $value;
    }
    public function delete_meta_data($key) { unset($this->meta_store[$key]); }
    public function update_meta_data($key, $value) {
        if (!isset($this->meta_store[$key])) $this->meta_store[$key] = [];
        // WC semantics: update_meta_data replaces the value at the same slot
        if (count($this->meta_store[$key]) > 0) {
            $this->meta_store[$key][0] = $value;
        } else {
            $this->meta_store[$key][] = $value;
        }
    }
    public function save_meta_data() {}
    public function read_meta_data($force = false) {
        $state =& upay_test_state();
        if (!empty($state['force_order_refresh_failure'])) {
            throw new RuntimeException('synthetic read_meta_data failure');
        }
    }
}
}
if (!class_exists('WC_Upayments_Testable', false)) {
class WC_Upayments_Testable extends WC_Upayments {
    public function get_option($key, $default = false) {
        // Read from settings array if available, otherwise fall back to property map.
        $state =& upay_test_state();
        $settings = $state['options']['woocommerce_upayments_settings'] ?? [];
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        $map = [
            'enable_save_card' => $this->saveCardEnabled ?? 'no',
            'enable_subscriptions' => 'no',
            'testmode' => $this->testMode ?? 'no',
            'test_mode' => $this->testMode ?? 'no',
            'api_key' => $this->apiKey ?? '',
        ];
        return isset($map[$key]) ? $map[$key] : $default;
    }
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
        // Per-route response map: if the test set responses per-route, prefer
        // the route-specific response (used when multiple provider endpoints
        // are dispatched in the same process_payment call).
        // Residual Correction #20: support callable responses for dynamic
        // transport stubs that inspect the actual outbound request body.
        if (isset($state['transport_responses_per_route'][$route])) {
            $r = $state['transport_responses_per_route'][$route];
            if (is_callable($r)) {
                return $r($body);
            }
            if ($r === false) return false;
            return $r;
        }
        if ($state['transport_route'] === $route && $state['transport_response'] !== null) {
            $r = $state['transport_response'];
            if (is_callable($r)) {
                return $r($body);
            }
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
}

// Provider transport helper functions — moved here from parent harness
// so the harness and the Store API child share the same test surface.
function upay_set_provider_response($route, $response) {
    $state =& upay_test_state();
    $state['transport_route'] = $route;
    $state['transport_response'] = $response;
}
function upay_set_provider_responses(array $route_to_response) {
    // Residual Correction #15: per-route response map for tests that dispatch
    // multiple provider endpoints in a single process_payment() call (icons,
    // create-token, charge). Each entry $route => $response is consulted by
    // the testable transport dispatcher.
    $state =& upay_test_state();
    if (!isset($state['transport_responses_per_route'])) {
        $state['transport_responses_per_route'] = [];
    }
    foreach ($route_to_response as $route => $response) {
        $state['transport_responses_per_route'][$route] = $response;
    }
}
function upay_clear_provider() {
    $state =& upay_test_state();
    $state['transport_route'] = null;
    $state['transport_response'] = null;
}
function upay_set_availability_response($r) { upay_test_state()['availability_response'] = $r; }

function WC() {
    return new class {
        public $session;
        public $cart = null;
        public function __construct() {
            $this->session = new class {
                public function set($k, $v) {
                    if ($k === 'refresh_totals') upay_test_state()['session_refresh_totals']++;
                }
            };
        }
    };
}

// WC() session stub — defined in parent harness, not duplicated here.
// (Previously caused "Cannot redeclare function WC()" when both files defined it.)

// Subclass that overrides the production get_request_body_raw() seam by
// returning a precomputed body string. The previous implementation only
// carried an unused $input_body field, so the production file_get_contents
// seam was actually executed — which meant the harness silently fell back
// to the empty body when the seam was not reachable. Now we override the
// method directly so the harness exercises an isolated, deterministic body
// regardless of php://input availability.
if (!class_exists('WC_Upayments_InputTestable', false)) {
class WC_Upayments_InputTestable extends WC_Upayments_Testable {
    public $input_body = null;
    public $body_consumed_count = 0;
    public function __construct($config = []) {
        parent::__construct();
        foreach ($config as $k => $v) $this->$k = $v;
    }
    protected function get_request_body_raw() {
        $this->body_consumed_count++;
        if (is_string($this->input_body)) {
            return $this->input_body;
        }
        // Fall through to the production seam only when the harness did
        // not precompute a body (e.g. for legacy tests that still rely
        // on the stream wrapper).
        return parent::get_request_body_raw();
    }
}
}

/**
 * Deterministic decimal-string addition (extracted from parent harness so
 * the child subprocess can call real FakeWCOrder::get_total()). Guarded so
 * the parent harness can load the bootstrap without "Cannot redeclare".
 */
if (!function_exists('upay_decimal_string_add')) {
function upay_decimal_string_add($a, $b) {
    if (!is_string($a) || !is_string($b)) return '0';
    $a_neg = strlen($a) > 0 && $a[0] === '-';
    $b_neg = strlen($b) > 0 && $b[0] === '-';
    if ($a_neg) $a = substr($a, 1);
    if ($b_neg) $b = substr($b, 1);
    $as = explode('.', $a, 2);
    $bs = explode('.', $b, 2);
    $a_int = $as[0]; $a_frac = isset($as[1]) ? $as[1] : '';
    $b_int = $bs[0]; $b_frac = isset($bs[1]) ? $bs[1] : '';
    $max = max(strlen($a_frac), strlen($b_frac));
    $a_frac = str_pad($a_frac, $max, '0');
    $b_frac = str_pad($b_frac, $max, '0');
    $carry = 0; $out_frac = '';
    for ($i = $max - 1; $i >= 0; $i--) {
        $s = ($a_frac[$i] !== '' ? ord($a_frac[$i]) - 48 : 0)
           + ($b_frac[$i] !== '' ? ord($b_frac[$i]) - 48 : 0)
           + $carry;
        $carry = intdiv($s, 10);
        $out_frac = chr(($s % 10) + 48) . $out_frac;
    }
    $a_int_padded = str_pad($a_int, max(strlen($a_int), strlen($b_int)), '0', STR_PAD_LEFT);
    $b_int_padded = str_pad($b_int, strlen($a_int_padded), '0', STR_PAD_LEFT);
    $out_int = '';
    $la = strlen($a_int_padded);
    for ($i = $la - 1; $i >= 0; $i--) {
        $s = ord($a_int_padded[$i]) - 48 + ord($b_int_padded[$i]) - 48 + $carry;
        $carry = intdiv($s, 10);
        $out_int = chr(($s % 10) + 48) . $out_int;
    }
    if ($carry > 0) $out_int = chr($carry + 48) . $out_int;
    $frac_trim = rtrim($out_frac, '0');
    $result = $out_int . ($frac_trim !== '' ? '.' . $frac_trim : '');
    if ($a_neg !== $b_neg) $result = '-' . $result;
    return $result;
}
}
