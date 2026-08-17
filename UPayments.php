<?php
/**
 * Plugin Name: UPayments
 * Plugin URI: https://developers.upayments.com/reference/woocommerce
 * Description: UPayments Plugin with Unified payment gateway supporting Old/New design, Save Card, and Multimerchant. Supports Block Checkout, Auto Deduction for Subscriptions, Bookable Products.
 * Version: 3.1.1
 * Author: <a href="https://developers.upayments.com/reference/woocommerce" target="_blank">UPayments Company</a>
 * Author URI: https://developers.upayments.com/reference/woocommerce
 * Requires at least: 5.6
 * Requires PHP: 7.2+
 * License: MIT
 * Text Domain: upayments
 * Domain Path: /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define("UP_PLUGIN_URL", plugin_dir_url(__FILE__));
define("UP_PLUGIN_PATH", plugin_dir_path(__FILE__));
define('UPAYMENTS_PLUGIN_FILE', __FILE__ );

require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';
require_once __DIR__ . '/includes/Token/CustomerTokenIdentity.php';

use UPayments\Subscription\Cron\Scheduler;
use UPayments\Subscription\Checkout\Fields;
use UPayments\Subscription\Manager;
use UPayments\Token\CustomerTokenIdentity;
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/upaymentskwt/woocommerce',
    __FILE__,
    'upayments-V2.2.1'
);

// Optional: use releases instead of tags
$updateChecker->getVcsApi()->enableReleaseAssets();

add_action( 'plugins_loaded', 'woocommerceUpaymentsInit' );
function woocommerceUpaymentsInit() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'upaymentsMissingWcNotice' );
        return;
    }
    class WC_Upayments extends WC_Payment_Gateway {
        public $domain = 'upayments';
        public $debug;
        public $apiKey;
        public $testMode;
        public $isOrderComplete;
        public $fromPluginEnabled;
        public $paymentData;

        public $multiMerchant;
        public $ibanNumber;
        public $knetCharge;
        public $knetChargeType;
        public $ccCharge;
        public $ccChargeType;
        public $saveCardEnabled;
        public $charge;
        public $autoDeduction;

        /**
         * Static allowlist of plugin-supported whitelabel payment sources.
         * Does NOT include 'create-invoice' — this plugin does not expose
         * invoice creation as a checkout method.
         */
        private static $ALLOWED_PAYMENT_SOURCES = array(
            'knet',
            'cc',
            'apple-pay',
            'apple-pay-knet',
            'samsung-pay',
            'google-pay',
        );

        /**
         * Static allowlist of accepted subscription plans.
         */
        private static $ALLOWED_SUBSCRIPTION_PLANS = array(
            'one_time',
            'daily',
            'weekly',
            'monthly',
            'quarterly',
            'yearly',
        );

        /**
         * Plan-specific allowed intervals.
         * one_time => 0 only; daily => 1; weekly => 1-3; monthly => 1-2;
         * quarterly => 1-3; yearly => 1.
         */
        private static $ALLOWED_INTERVALS = array(
            'one_time'  => array(0),
            'daily'     => array(1),
            'weekly'    => array(1, 2, 3),
            'monthly'   => array(1, 2),
            'quarterly' => array(1, 2, 3),
            'yearly'    => array(1),
        );

        /**
         * Normalize save-card request value to strict boolean.
         *
         * Only '1' or integer 1 are treated as true.
         * All other values (including 'true', 'yes', '2', arrays) => false.
         *
         * @param mixed $value Raw request value.
         * Strict tri-state parser: absent/0/'0' => false; 1/'1' => true; else invalid.
         * @param mixed $value Raw input.
         * @return bool|null true/false for valid, null for invalid.
         */
        private static function parse_save_card_strict($value) {
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                return false;
            }
            if ($value === 1 || $value === '1') {
                return true;
            }
            return null; // invalid
        }

        /**
         * Validate a subscription plan against the static allowlist.
         *
         * @param string $plan Plan identifier.
         * @return bool
         */
        private static function is_valid_subscription_plan(string $plan): bool {
            return in_array($plan, self::$ALLOWED_SUBSCRIPTION_PLANS, true);
        }

        /**
         * Parse an interval value strictly.
         *
         * Accepts only exact integer values 0, 1, 2, 3 or their string equivalents.
         * Returns -1 for any malformed input.
         *
         * @param mixed $value Raw interval value.
         * @return int Parsed interval or -1 if invalid.
         */
        private static function parse_interval($value): int {
            if ($value === null || $value === '' || $value === 0 || $value === '0') {
                return 0;
            }
            if ($value === 1 || $value === '1') {
                return 1;
            }
            if ($value === 2 || $value === '2') {
                return 2;
            }
            if ($value === 3 || $value === '3') {
                return 3;
            }
            return -1;
        }

        private static function is_valid_subscription_interval(string $plan, int $interval): bool {
            if (!isset(self::$ALLOWED_INTERVALS[$plan])) {
                return false;
            }
            return in_array($interval, self::$ALLOWED_INTERVALS[$plan], true);
        }

        /**
         * Validate and normalize a UPayments redirect URL.
         *
         * Accepts only absolute http/https URLs with a non-empty host.
         * Does NOT force same-origin — UPayments payment URLs are external.
         *
         * @param mixed $value Raw redirect value from provider response.
         * @return string|null Normalized URL or null if invalid.
         */
        private function normalize_upayments_redirect_url($value) {
            if (!is_scalar($value)) {
                return null;
            }
            $url = trim((string) $value);
            if ($url === '') {
                return null;
            }
            $parts = parse_url($url);
            if ($parts === false || !isset($parts['scheme']) || !isset($parts['host'])) {
                return null;
            }
            $scheme = strtolower($parts['scheme']);
            if ($scheme !== 'http' && $scheme !== 'https') {
                return null;
            }
            return $url;
        }

        /**
         * Payment-method availability rate-gate constants.
         */
        private static $RATE_GATE_COOLDOWN = 65;

        /**
         * Get the mode-specific durable rate-gate option name.
         *
         * Uses separate options per mode to prevent cross-mode lost-update races.
         * Live and test workers acquire different advisory locks and write to
         * different options, so they cannot overwrite each other's cooldown.
         *
         * @return string Option name.
         */
        private function get_payment_methods_rate_gate_option_name() {
            $mode = $this->getMode() ? 'test' : 'live';
            return 'upayments_payment_methods_rate_gate_' . $mode;
        }

        /**
         * Generate a credential-scoped transient name for payment-method results.
         *
         * The fingerprint is derived from test/live mode + API key + WordPress salt.
         * A credential change therefore invalidates old cached results.
         *
         * @return string Transient name (within WordPress 172-char limit).
         */
        private function get_payment_methods_transient_name() {
            $mode = $this->getMode() ? 'test' : 'live';
            $fingerprint = hash_hmac('sha256', $mode . '|' . $this->apiKey, wp_salt('auth'));
            $short_hash = substr($fingerprint, 0, 16);
            return 'upay_pm_v3_' . $short_hash;
        }

        /**
         * Deterministic MySQL advisory lock name for site + mode.
         *
         * Contains no credentials. Max 64 chars.
         *
         * @return string Lock name.
         */
        private function get_payment_methods_lock_name() {
            global $wpdb;
            $mode = $this->getMode() ? 'test' : 'live';
            $db_name = defined('DB_NAME') ? DB_NAME : '';
            $prefix = $wpdb->prefix;
            $blog_id = (string) get_current_blog_id();
            $lock_input = $db_name . '|' . $prefix . '|' . $blog_id . '|' . $mode;
            $lock_hash = substr(hash('sha256', $lock_input), 0, 16);
            return 'upay_pm_' . $lock_hash;
        }

        /**
         * Acquire a MySQL named advisory lock with timeout 0.
         *
         * @return int 1 = acquired, 0 = contention, -1 = error/unsupported.
         */
        private function acquire_payment_methods_lock() {
            global $wpdb;
            $lock_name = $this->get_payment_methods_lock_name();
            $result = $wpdb->get_var(
                $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock_name)
            );
            if ($result === '1' || $result === 1) {
                return 1;
            }
            if ($result === '0' || $result === 0) {
                return 0;
            }
            return -1;
        }

        /**
         * Release a MySQL named advisory lock.
         *
         * @return bool True if released or not owned.
         */
        private function release_payment_methods_lock() {
            global $wpdb;
            $lock_name = $this->get_payment_methods_lock_name();
            $wpdb->get_var(
                $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock_name)
            );
            return true;
        }

        /**
         * Read the durable rate-gate not_before for current mode.
         *
         * Uses a mode-specific option (no shared mutable array).
         *
         * @return array{not_before: int}
         */
        private function get_payment_methods_rate_gate() {
            $option_name = $this->get_payment_methods_rate_gate_option_name();
            $not_before = (int) get_option($option_name, 0);
            return array('not_before' => $not_before);
        }

        /**
         * Persist the durable rate-gate not_before for current mode.
         *
         * Uses a mode-specific option (no shared mutable array).
         *
         * @param int $not_before Unix timestamp.
         * @return bool True on success.
         */
        private function set_payment_methods_rate_gate($not_before) {
            $option_name = $this->get_payment_methods_rate_gate_option_name();
            return update_option($option_name, (int) $not_before, false);
        }

        /**
         * Read cached payment-method result from transient.
         *
         * Returns the cached array on fresh hit, or null on miss/expiry.
         *
         * @return array|null
         */
        private function get_cached_payment_methods() {
            $transient_name = $this->get_payment_methods_transient_name();
            $cached = get_transient($transient_name);
            if (is_array($cached)) {
                return $cached;
            }
            return null;
        }

        /**
         * Section Z: Canonical availability cache schema validator.
         * Only accepts strict schema3 success or failure shapes.
         */
        private function is_valid_cached_availability($cached) {
            if (!is_array($cached)) {
                return false;
            }
            // Failure sentinel.
            if (isset($cached['state']) && $cached['state'] === 'failure'
                && isset($cached['schema']) && $cached['schema'] === 3
                && count($cached) === 2
            ) {
                return 'failure';
            }
            // Success: strict schema3.
            if (!isset($cached['schema']) || $cached['schema'] !== 3) {
                return false;
            }
            if (!isset($cached['result']) || $cached['result'] !== 'success') {
                return false;
            }
            if (!isset($cached['isWhiteLabel']) || !is_bool($cached['isWhiteLabel'])) {
                return false;
            }
            if (!isset($cached['payButtons']) || !is_array($cached['payButtons'])) {
                return false;
            }
            $known = array('knet', 'credit_card', 'apple_pay_knet', 'apple_pay', 'samsung_pay', 'google_pay');
            foreach ($known as $btn) {
                if (!isset($cached['payButtons'][$btn]) || !is_int($cached['payButtons'][$btn])) {
                    return false;
                }
                if ($cached['payButtons'][$btn] !== 0 && $cached['payButtons'][$btn] !== 1) {
                    return false;
                }
            }
            return 'success';
        }

        private function get_failure_sentinel() {
            return array('schema' => 3, 'state' => 'failure');
        }

        /**
         * Write payment-method result to transient cache.
         *
         * @param array $result   Result to cache.
         * @param int   $not_before Gate expiry timestamp (TTL = remaining window).
         */
        private function set_cached_payment_methods($result, $not_before) {
            $transient_name = $this->get_payment_methods_transient_name();
            $ttl = max(1, $not_before - time());
            set_transient($transient_name, $result, $ttl);
        }

        public function __construct() {
            // Define ID, title, description, and settings.
            $this->id                 = 'upayments';
            $this->icon = UP_PLUGIN_URL . "assets/images/logo.png";
            $this->method_title       = __("UPayments", $this->domain);
            $this->method_description = __("UPayments Plugin allows merchants to accept KNET, Cards, Samsung Pay, Apple Pay, Google Pay Payments. 
            Supports Block Checkout, Auto Deduction for Subscriptions.", $this->domain);
            $this->has_fields         = true; // Required for custom forms like Save Card/Design variations.

            // Define user set variables
            $this->title = '';
            $this->description = $this->get_option("description");
            $this->debug = $this->get_option("debug");
            $this->apiKey = $this->get_option("api_key");
            $this->isOrderComplete = $this->get_option('is_order_complete');
            $this->testMode = $this->get_option("test_mode");
            $this->charge = $this->get_option('charge');
            $this->fromPluginEnabled = false;
            $this->paymentData = array();

            //MultimerchantData
            $this->multiMerchant = $this->get_option("enable_multimerchant");
            $this->ibanNumber = $this->get_option("iban_number");
            $this->ccCharge = $this->get_option("cc_charge");
            $this->ccChargeType = $this->get_option("cc_charge_type");
            $this->knetCharge = $this->get_option("knet_charge");
            $this->knetChargeType = $this->get_option("knet_charge_type");
            $this->saveCardEnabled = $this->get_option("enable_save_card");
            $this->autoDeduction = $this->get_option("enable_subscriptions");

            // Load settings and hooks
            $this->init_form_fields();
            $this->init_settings();

            // Register action hook for saving settings (critical for all new toggles)
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
            
            // Custom hooks for front-end rendering, scripts, etc.
            add_filter("woocommerce_get_order_item_totals", [$this, "add_order_item_totals"], 10, 3);
            add_action("woocommerce_api_" . strtolower("WC_UPayments") , [$this, "check_ipn_response", ]);
            add_filter("woocommerce_gateway_icon", [$this, "custom_payment_gateway_icons"], 10, 2);
            add_action("woocommerce_admin_order_data_after_order_details", [$this, "admin_order_details"], 10, 3);
            add_action("admin_footer", [$this, "UPayments_admin_footer"], 10, 3);
            add_action("admin_enqueue_scripts", [$this, "admin_enqueue_scripts"]);
            add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
            
            // Handlers to Display Thankyou Page after successful payment
            add_action("woocommerce_thankyou_" . $this->id, function ($order_id) {
                $this->thankyou_page($order_id);
            });
            
            // Handlers for Subscription Module
            $this->initializeSubscriptionModule();
            
            // My Account link for Login users to view their orders and saved cards
            add_action('woocommerce_before_checkout_form', function () {

                if (!function_exists('WC') || !WC()->session) {
                    return;
                }

                $account_url = wc_get_page_permalink('myaccount');

                echo '<div class="checkout-my-account-link">';
                echo '<a href="' . esc_url($account_url) . '" target="_blank">';
                echo __('Go to My Account', 'woocommerce');
                echo '</a>';
                echo '</div>';
                
                $gateways = WC()->payment_gateways()->get_available_payment_gateways();
                
                if (!isset($gateways['upayments'])) {
                    return;
                }
                
                $upay = $gateways['upayments'];
                
                if (WC()->session->get('chosen_payment_method') === 'upayments' && $upay->get_option('make_default_gateway') === 'no') {
                    WC()->session->set('chosen_payment_method', null);
                }
            }, 5);
            // Save Card & Subscriptions validation
            add_filter('woocommerce_settings_api_sanitized_fields_upayments', function ($settings) {
                $save_card      = isset($settings['enable_save_card']) && !empty($settings['enable_save_card']);
                $subscriptions  = isset($settings['enable_subscriptions']) && !empty($settings['enable_subscriptions']);
                if ($subscriptions && !$save_card) {
                    wc_add_notice(
                        __('Save Card must be enabled when Subscriptions are enabled.', 'woocommerce'),
                        'error'
                    );
                    $settings['enable_save_card'] = 'yes';
                }

                return $settings;
            });

            add_filter('woocommerce_default_gateway', function ($default) {
                wc_get_logger()->info(
                    'Default gateway filter hit. Current default: ' . $default,
                    ['source' => 'upayments-debug']
                );

                if ($this->get_option('make_default_gateway') === 'yes') {
                    wc_get_logger()->info('UPayments set as default', ['source' => 'upayments-debug']);
                    return 'upayments';
                }

                return $default;
            });

            add_filter('woocommerce_add_to_cart_validation', [$this, 'restrictMixedCartProducts'], 10, 3);
            add_action('woocommerce_before_shop_loop_item_title', [$this, 'renderSubscriptionBadgeInProductList'], 9);
        }

        public function init_form_fields() {
            $this->form_fields = array(
                "enabled" => array(
                    "title" => __("Active", $this->domain) , 
                    "type" => "checkbox", 
                    "label" => __(" ", $this->domain) , 
                    "default" => "yes"
                ), 
                'make_default_gateway' => [
                    'title'       => __('Default Gateway', $this->domain),
                    'type'        => 'checkbox',
                    'label'       => __('Make UPayments the default payment method at checkout', $this->domain),
                    'default'     => 'no',
                    'description' => __('If enabled, UPayments will be preselected at checkout. Merchants can still reorder gateways.', $this->domain),
                ],
                "title" => array(
                    "title" => __("Title", $this->domain) , 
                    "type" => "text", 
                    "description" => __("This controls the title which the user sees during checkout.", $this->domain) , 
                    "default" => $this->method_title, 
                    "desc_tip" => true
                ), 
                "description" => array(
                    "title" => __("Description", $this->domain) , 
                    "type" => "textarea", 
                    "description" => __("Instructions that the customer will see on your checkout.", $this->domain),
                    "default" => $this->method_description, 
                    "desc_tip" => true
                ),
                "api_key" => array(
                    "title" => __("Api Key", $this->domain) , 
                    "type" => "text", 
                    "description" => __("Copy/paste values from UPayments dashboard", $this->domain), 
                    "default" => "", 
                    "desc_tip" => true
                ),
                "debug" => array(
                    "title" => __("Debug logging", $this->domain),
                    "type" => "checkbox",
                    "label" => __("Log non-sensitive UPayments diagnostic events to WooCommerce logs.", $this->domain),
                    "default" => "no"
                ),
                "test_mode" => array(
                    "title" => __("Test Mode", $this->domain),
                    "type" => "checkbox",
                    "label" => __(" ", $this->domain),
                    "default" => "no"
                ), 
                "is_order_complete" => array(   
                    "title" => __('Show paid orders as "Completed"?', $this->domain),   
                    "type" => "checkbox",   
                    "label" => __(" ", $this->domain),  
                    "default" => "yes"  
                ),
                'save_card_section_title' => array(
                    'title' => __( 'Card Tokenization & Design', $this->domain ),
                    'type'  => 'title',
                    'description' => '',
                ),
                'use_new_design' => array(
                    'title'   => __( 'Use New Design', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Use the modern design (if unchecked uses classic design)', $this->domain ),
                    'default' => 'yes', // Default to New Design
                ),
                'enable_save_card' => array(
                    'title'   => __( 'Enable Save Card', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Allow customers to save card details (Tokenization)', $this->domain ),
                    'default' => 'yes', // Default to Enabled (per V2.2.1)
                ),
                // 'checkout_blocks_title' => array(
                //     'title' => __( 'WooCommerce Block Checkout', $this->domain ),
                //     'type'  => 'title',
                // ),
                // 'enable_block_checkout' => array(
                //     'title'   => __( 'Enable Block Checkout', $this->domain ),
                //     'type'    => 'text',
                //     'description'   => __( 'Enable compatibility with the new WooCommerce Checkout Block', $this->domain ),
                // ),
                //disabled block setting for now.
                // 'enable_block_checkout' => array(
                //     'title'   => __( 'Enable Block Checkout', $this->domain ),
                //     'type'    => 'checkbox',
                //     'label'   => __( 'Enable compatibility with the new WooCommerce Checkout Block', $this->domain ),
                //     'default' => 'yes',
                // ),
                'multimerchant_section_title' => array(
                    'title' => __( 'Multimerchant Configuration', $this->domain ),
                    'type'  => 'title',
                ),
                'enable_multimerchant' => array(
                    'title'   => __( 'Enable Multimerchant', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Handle Merchant Account & Charges', $this->domain ),
                    'default' => 'no',
                ),
                'iban_number' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'cc_charge' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'cc_charge_type' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'knet_charge' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'knet_charge_type' => array(
                    'type' => 'text',
                    'css'  => 'display:none;',
                ),
                'multimerchant_accounts' => array(
                    'title'       => __( 'Multimerchant Accounts', $this->domain ),
                    'type'        => 'multimerchant_repeater',
                    'description' => __( 'Manage IBAN and charges for Main-Merchant.', $this->domain ),
                ),
                'autodeduction_section_title' => array(
                    'title' => __( 'Subscription Configuration', $this->domain ),
                    'type'  => 'title',
                ),
                'enable_subscriptions' => array(
                    'title'   => __( 'Enable Subscriptions', $this->domain ),
                    'type'    => 'checkbox',
                    'label'   => __( 'Enable subscription payments', $this->domain ),
                    'default' => 'no',
                    "desc_tip" => true,
                    "description" => __( "Only Subscription Products are allowed at checkout If Subscription is enabled.", $this->domain ),
                ),
            );
        }

        public function UPayments_admin_footer()
        {
            include_once UP_PLUGIN_PATH . 'includes/admin-footer.php';
        }

        public function get_logged_in_user_phone_number() {
            
            // Check if the user is logged in
            if (is_user_logged_in()) {
                // Get the current user ID
                $user_id = get_current_user_id();
                $billing_phone = get_user_meta($user_id, 'billing_phone', true);
                
                if ($billing_phone) {
                    $phone = str_replace(' ', '', $billing_phone); // Replaces all spaces with hyphens.
                    $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
                    if (substr($phone, 0, 1) === '0') {
                        $phone = '1' . substr($phone, 1);
                    }
                    if($phone) {
                        return ['success' => true, 'phone' => $phone];
                    }
                }
                return ['success' => true, 'phone' => ''];
            }
            if (function_exists('WC') && WC()->customer) {
                $billing_phone = WC()->customer->get_billing_phone();
                
                if (!empty($billing_phone)) {
                    $phone = str_replace(' ', '', $billing_phone); // Replaces all spaces with hyphens.
                    $phone = preg_replace('/[^A-Za-z0-9\-]/','',$phone);
                    if (substr($phone, 0, 1) === '0') {
                        $phone = '1' . substr($phone, 1);
                    }
                    return ['success' => true, 'phone' => $phone];
                }
            }
            return ['success' => false, 'phone' => ''];
        }

        public function add_order_item_totals($total_rows, $order, $tax_display)
        {
            $payment_status = $order->get_meta('UPayments_Result');
            $upayment_id = $order->get_meta('UPayments_PaymentID');

            $new_total_rows = [];

            foreach ($total_rows as $key => $total)
            {
                $new_total_rows[$key] = $total;
                if ("payment_method" === $key)
                {
                    $new_total_rows["payment_status"] = ["label" => "Payment Status:", "value" => $payment_status, ];
                    if (!empty($upayment_id))
                    {
                        $new_total_rows["upayment_id"] = ["label" => "UPayment ID:", "value" => $upayment_id, ];
                    }
                }
            }

            return $new_total_rows;
        }

        /**
         * Output for the order received page.
         *
         * Display-only. No payment-state mutation is performed here.
         * The verified WooCommerce order status and authoritative UPayments
         * metadata are read from the order; no $_GET parameter is trusted to
         * alter payment state.
         */
        public function thankyou_page($order_id) {
            if (!$order_id) {return;}

            $order = wc_get_order($order_id);

            if (!$order) {return;}

            $payment_status = $order->get_meta('UPayments_Result');
            $upayment_id    = $order->get_meta('UPayments_PaymentID');

            $style = "width: 100%;  margin-bottom: 1rem; background: #212B5F; padding: 20px; color: #fff; font-size: 22px;";

            // Display-only: derive the displayed status from the verified
            // order state. No field from $_GET is allowed to mutate the order.
            $status = $order->get_status();
            ?>
                <div class="upayments-thankyou-wrapper" data-order-id="<?php echo esc_attr($order_id); ?>">
            <?php 
                if ($status == "wait"){
            ?>
                <style>
                    .payment-panel-wait .img-container {
                        text-align: center;
                    }
                    .payment-panel-wait .img-container img{
                        display: inline-block !important;
                    }
                </style>
                <div class="payment-panel-wait">
                    <h3><?php esc_html_e("We are retrieving your payment status from UPayments, please wait...", $this->domain); ?></h3>
                    <div class="img-container"><img src="<?php echo UP_PLUGIN_PATH; ?>assets/images/loader.gif" /></div>
                </div>
            <?php
            } 
            ?>
                <div class="payment-panel-wait">
                    <h3><?php esc_html_e('We are retrieving your payment status...', $this->domain ); ?></h3>
                </div>
                <div class="payment-panel-pending" style="<?php echo $status == "pending" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                        <?php esc_html_e("Your payment status is pending, we will update the status as soon as we receive notification from UPayments.", $this->domain); ?>
                    </div>
                </div>
                <div class="payment-panel-completed" style="<?php echo $status == "completed" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                    <?php esc_html_e("Your payment is successful with UPayments.", $this->domain); ?>
                        <img style="width:100px" src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/check.png'); ?>"/>
                    </div>
                </div>
                <div class="payment-panel-failed" style="<?php echo $status == "failed" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                    <?php esc_html_e("Your payment is failed with UPayments.", $this->domain); ?>
                    </div>
                </div>
                <div class="payment-panel-cancelled" style="<?php echo $status == "cancelled" ? "display: block" : "display: none"; ?>">
                    <div style="<?php echo $style; ?>">
                        <?php
                            if (isset($status_message) && !empty($status_message)){
                                echo $status_message;
                            }else{
                                esc_html_e("Your order is cancelled.", $this->domain);
                            }
                        ?>
                    </div>
                </div>
                <div class="payment-panel-error" style="display: none">
                    <div class="message-holder">
                        <?php esc_html_e("Something went wrong, please contact the merchant.", $this->domain); ?>
                    </div>
                </div>
                <div class="upayment-status-holder" style="display: none">
                    <li class="woocommerce-order-overview__payment-status status">
                        <?php esc_html_e("Payment Status:", "woocommerce"); ?>
                        <strong id="upayment-status-holder-strong"><?php echo wp_kses_post($payment_status); ?></strong>
                    </li>
                </div>
                <div class="upayment-id-holder" style="display: none">
                    <li class="woocommerce-order-overview__payment-id payment-id">
                        <?php esc_html_e("UPayment ID:", "woocommerce"); ?>
                        <strong id="upayment-id-holder-strong"><?php echo wp_kses_post($upayment_id); ?></strong>
                    </li>
                </div>
            </div>
        <?php
        }

        public function get_payment_staus()
        {
            $status = "wait";
            $message = "";

            try{
                $order_id = (int)sanitize_text_field($_GET["wc_order_id"]);
                if ($order_id == 0)
                {
                    throw new \Exception(__("Order not found.", $this->domain));
                }

                $payment_status = get_post_meta($order_id, "UPayments_WHS", true);
                if ($payment_status && !empty($payment_status))
                {
                    $status = $payment_status;
                }
            }catch(\Exception $e){
                $status = "error";
                $message = $e->getMessage();
            }
            $this->log($status);
            $data = ["status" => $status, "message" => $message, ];

            echo json_encode($data);
            die();
        }

        /**
         * Execute a hardened authenticated UPayments HTTP request.
         *
         * PHASE 8S: Low-level transport helper for the four legacy authenticated
         * UPayments API calls (charge, create-customer-unique-token,
         * check-payment-button-status, retrieve-customer-cards). It is NOT used
         * by verify_payment_status() (PR #7 trust anchor) or the Scheduler
         * auto-deduct dispatcher (PR #8), each of which has its own separately
         * reviewed transport policy.
         *
         * Transport policy:
         *   - explicit TLS verification (defense in depth; even where libcurl
         *     defaults are already secure, we set both flags explicitly);
         *   - redirects disabled (no redirect requirement is established;
         *     preserve endpoint identity; avoid method/body ambiguity on
         *     cross-host hops);
         *   - finite connect (5s) and total (15s) timeouts;
         *   - Bearer Authorization applied for the entire call.
         *
         * SECURITY: This helper does NOT log raw request bodies, raw response
         * bodies, raw curl_error text, the Authorization header, or any token.
         * Callers classify the structured outcome and remain responsible for
         * redacting provider messages before showing them to customers.
         *
         * PHP 8.5 deprecates curl_close(). On PHP 8.0+ the handle is a
         * \CurlHandle object that is released when the last reference is
         * dropped; we therefore skip curl_close() on PHP 8.0+ and only fall
         * back to it on PHP < 8.0 (the plugin's minimum supported version).
         *
         * @param string      $route   API route relative to the API base.
         * @param string      $method  Uppercase HTTP method: 'GET' or 'POST'.
         * @param string|null $body    JSON-encoded request body, or null for GET.
         * @return array{transport_ok: bool, body: string|null, http_status: int, curl_errno: int}
         */
        private function execute_upayments_request($route, $method, $body = null)
        {
            $outcome = array(
                'transport_ok' => false,
                'body'         => null,
                'http_status'  => 0,
                'curl_errno'   => 0,
            );

            $method = is_string($method) ? strtoupper(trim($method)) : 'GET';
            if ($method !== 'GET' && $method !== 'POST') {
                return $outcome;
            }

            $ch = curl_init();
            if ($ch === false) {
                return $outcome;
            }

            $options = array(
                CURLOPT_URL            => $this->getAPIUrl($route),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_USERAGENT      => $this->getUserAgent(),
                CURLOPT_ENCODING       => '',
                CURLOPT_HTTPHEADER     => array(
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ),
            );

            if ($method === 'POST') {
                $options[CURLOPT_POST]       = true;
                $options[CURLOPT_POSTFIELDS] = (string) $body;
            } else {
                $options[CURLOPT_HTTPGET] = true;
            }

            $configured = true;
            foreach ($options as $option => $value) {
                if (!@curl_setopt($ch, $option, $value)) {
                    $configured = false;
                    break;
                }
            }

            if (!$configured) {
                if (PHP_VERSION_ID < 80000) {
                    @curl_close($ch);
                }
                $ch = null;
                return $outcome;
            }

            $response = curl_exec($ch);
            $errno    = curl_errno($ch);
            $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (PHP_VERSION_ID < 80000) {
                @curl_close($ch);
            }
            $ch = null;

            $outcome['http_status']  = $status;
            $outcome['curl_errno']   = $errno;
            $outcome['body']         = ($response === false) ? null : (string) $response;
            $outcome['transport_ok'] = ($response !== false)
                && ($errno === 0)
                && ($status >= 200)
                && ($status < 300);

            return $outcome;
        }

        /**
         * Verify a UPayments payment status through the Bearer-authenticated
         * Get Payment Status API and bind the response to the given WooCommerce order.
         *
         * SECURITY: This is the authoritative trust path for main checkout
         * browser-return and webhook paid-state transitions. Inbound callback
         * fields (browser return / webhook) are NEVER authoritative.
         * Authentication is the UPayments server-side response, schema-validated
         * and bound to the order. The subscription auto-deduction Scheduler has
         * its own separate payment flow and is out of scope of this helper.
         *
         * @param WC_Order $order
         * @param string   $track_id  Lookup cursor received from the callback.
         * @return array{
         *     verified: bool,
         *     transaction: array|null,
         *     reason: string
         * }
         */
        private function verify_payment_status($order, $track_id)
        {
            $result = array(
                'verified'    => false,
                'transaction' => null,
                'reason'      => '',
            );

            try {
                if (!$order instanceof WC_Order) {
                    $result['reason'] = 'invalid_order';
                    return $result;
                }

                $track_id = is_string($track_id) ? trim($track_id) : '';
                if ($track_id === '') {
                    $result['reason'] = 'missing_track_id';
                    return $result;
                }

                $local_order_id = (string) $order->get_id();
                $local_currency = $this->getCurrencyCode($order->get_currency());
                $local_upay_order_id = $order->get_meta('UPayments_order_id');
                if (!is_string($local_upay_order_id) || $local_upay_order_id === '') {
                    $result['reason'] = 'missing_local_upay_order_id';
                    return $result;
                }

                $url = $this->getAPIUrl('get-payment-status/' . rawurlencode($track_id));

                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($ch, CURLOPT_TIMEOUT, 15);
                curl_setopt($ch, CURLOPT_USERAGENT, $this->getUserAgent());
                curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    'Accept: application/json',
                    'Authorization: Bearer ' . $this->apiKey,
                ));

                $response_body = curl_exec($ch);
                $curl_errno    = curl_errno($ch);
                $curl_error    = curl_error($ch);
                $http_code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($response_body === false || $curl_errno !== 0) {
                    $result['reason'] = 'network_error';
                    $this->log('UPayments payment status verification failed (network).', 'warning');
                    return $result;
                }

                if ($http_code !== 201) {
                    $result['reason'] = 'unexpected_http_' . $http_code;
                    $this->log('UPayments payment status verification failed (HTTP status).', 'warning');
                    return $result;
                }

                $decoded = json_decode((string) $response_body, true);
                if (!is_array($decoded) || empty($decoded['status']) || $decoded['status'] !== true) {
                    $result['reason'] = 'invalid_top_level';
                    $this->log('UPayments payment status verification failed (top-level status).', 'warning');
                    return $result;
                }

                $transaction = isset($decoded['data']['transaction']) && is_array($decoded['data']['transaction'])
                    ? $decoded['data']['transaction']
                    : null;
                if ($transaction === null) {
                    $result['reason'] = 'missing_transaction';
                    $this->log('UPayments payment status verification failed (missing transaction).', 'warning');
                    return $result;
                }

                // Required-field gating.
                $required = array('result', 'track_id', 'merchant_requested_order_id', 'total_price', 'currency_type', 'payment_id', 'payment_type', 'reference');
                foreach ($required as $field) {
                    if (!array_key_exists($field, $transaction) || $transaction[$field] === null || $transaction[$field] === '') {
                        $result['reason'] = 'missing_field_' . $field;
                        $this->log('UPayments transaction binding failed.', 'warning');
                        return $result;
                    }
                }

                // B1 — track_id echo.
                if ((string) $transaction['track_id'] !== $track_id) {
                    $result['reason'] = 'binding_track_id';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B2 — merchant_requested_order_id == UPayments_order_id.
                if ((string) $transaction['merchant_requested_order_id'] !== $local_upay_order_id) {
                    $result['reason'] = 'binding_merchant_requested_order_id';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B3 — reference == WooCommerce order id.
                if ((string) $transaction['reference'] !== $local_order_id) {
                    $result['reason'] = 'binding_reference';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B4 — currency.
                $expected_currency = strtoupper(trim($local_currency));
                $verified_currency = strtoupper(trim((string) $transaction['currency_type']));
                if ($expected_currency === '' || $verified_currency !== $expected_currency) {
                    $result['reason'] = 'binding_currency';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // B5 — amount (decimal-safe, normalized string comparison).
                $verified_amount = (string) $transaction['total_price'];
                if (!is_numeric($verified_amount)) {
                    $result['reason'] = 'amount_not_numeric';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }
                $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;
                $expected_amount = wc_format_decimal((string) $order->get_total(), $decimals);
                $normalized_amount = wc_format_decimal($verified_amount, $decimals);
                if ($normalized_amount !== $expected_amount) {
                    $result['reason'] = 'binding_amount';
                    $this->log('UPayments transaction binding failed.', 'warning');
                    return $result;
                }

                // CAPTURED-only policy.
                if ((string) $transaction['result'] !== 'CAPTURED') {
                    $result['reason'] = 'not_captured';
                    return $result;
                }

                $result['verified']    = true;
                $result['transaction'] = $transaction;
                $result['reason']      = 'captured';
                return $result;
            } catch (\Throwable $e) {
                // Fail-closed: any unexpected internal exception during verification
                // must not leak transport or data details and must not authorize a paid transition.
                $result['verified']    = false;
                $result['transaction'] = null;
                $result['reason']      = 'verification_exception';
                $this->log('UPayments payment status verification failed (verification exception).', 'warning');
                return $result;
            }
        }

        /**
         * Neutral fallback URL for verification outcomes that must not disclose
         * the WooCommerce order-received URL.
         *
         * The WooCommerce order-received URL contains a privileged `?key=` token
         * that authorizes viewing that order without further authentication. A
         * browser request that has not yet bound authoritatively to a UPayments
         * transaction must NEVER be redirected to such a URL.
         *
         * The fallback:
         *  - contains no WooCommerce order key;
         *  - is not an order-pay URL;
         *  - does not invite immediate repayment;
         *  - carries a static `upayments_verification=pending` marker so the
         *    destination page can render a friendly pending state.
         *
         * @return string
         */
        private function get_payment_verification_fallback_url()
        {
            $base = is_user_logged_in()
                ? wc_get_page_permalink('myaccount')
                : home_url('/');

            return add_query_arg('upayments_verification', 'pending', $base);
        }

        /**
         * Process the customer browser return from UPayments.
         *
         * SECURITY: The inbound $_GET result/payment_id/track_id/post_date/tran_id/
         * ref/auth fields are NEVER authoritative. Only a verified authoritative
         * Get Payment Status response with all bindings satisfied and
         * result === 'CAPTURED' may authorize the WooCommerce paid-state transition.
         * Browser paths that fail local preflight or unauthenticated binding MUST
         * be redirected to the neutral fallback URL — never to the order-received
         * URL, which embeds the WooCommerce order key.
         */
        public function return_from_upayments()
        {
            if (!isset($_GET["wc_order_id"])) {
                $this->log("Return callback received without wc_order_id.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $raw_order_id = sanitize_text_field(wp_unslash($_GET["wc_order_id"]));
            $order_id = absint($raw_order_id);
            if ($order_id <= 0) {
                $this->log("Return callback received with invalid wc_order_id.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $order = wc_get_order($order_id);
            if (!$order instanceof WC_Order) {
                $this->log("Return callback received but order could not be loaded.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            if ($order->get_payment_method() !== $this->id) {
                $this->log("Return callback received for non-UPayments order.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            // Order preconditions: require locally generated UPayments_order_id.
            $local_upay_order_id = $order->get_meta('UPayments_order_id');
            if (!is_string($local_upay_order_id) || $local_upay_order_id === '') {
                $this->log("Return callback received but UPayments_order_id is missing.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $track_id = isset($_GET["track_id"])
                ? sanitize_text_field(wp_unslash($_GET["track_id"]))
                : '';
            if ($track_id === '') {
                $this->log("Return callback received without track_id.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            // A2 — requested_order_id is a cheap local preflight, NOT authentication.
            // Required to be present and strictly equal to local UPayments_order_id
            // BEFORE any authenticated status request is made. Paid-state authority
            // still requires Bearer-authenticated Get Payment Status + B1-B5 +
            // authoritative result === 'CAPTURED'.
            $requested_order_id = isset($_GET["requested_order_id"])
                ? sanitize_text_field(wp_unslash($_GET["requested_order_id"]))
                : '';
            if ($requested_order_id === '' || $requested_order_id !== $local_upay_order_id) {
                $this->log("Return callback requested_order_id preflight failed.", 'warning');
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            // A1 — _upay_verified_capture means the original capture has already
            // been authoritatively verified. A later callback/URL replay must
            // NEVER be allowed to overwrite subsequent WooCommerce lifecycle states
            // (refunded, custom fulfillment, merchant/admin status changes, etc.).
            // Short-circuit unconditionally on the flag alone. We still use the
            // neutral fallback so a public replay never receives a fresh
            // order-received URL.
            if ((string) $order->get_meta('_upay_verified_capture') === '1') {
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            // A5 — never resurrect a refunded order. The refund status itself
            // prohibits order mutation. Do not disclose an order-received URL
            // for a non-captured path; use the neutral fallback.
            if ($order->has_status('refunded')) {
                $this->log("Return callback received for refunded order; leaving status unchanged.");
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }

            $this->log("Return callback received; verifying payment status.");

            // Browser-side fail-closed exception containment. The internal
            // verifier already catches Throwable, but the verification success
            // path in this handler performs metadata writes, status transition,
            // verified-flag writes, order save, and cart handling. Any unexpected
            // Throwable in this section must not mark the order failed, must not
            // cancel, must not set verified-success flags, must not log
            // transport/data details, and must not return the customer to
            // checkout.
            try {
                $verification = $this->verify_payment_status($order, $track_id);

                if (!$verification['verified']) {
                    $reason = (string) $verification['reason'];

                    // The remote transaction identity has been authenticated
                    // and bound even though payment is not CAPTURED. However,
                    // Get Payment Status authenticates the UPayments transaction,
                    // NOT the browser requester. There is therefore no reason to
                    // disclose WooCommerce's order-received URL (which embeds the
                    // ?key= order-key bearer token) for a payment that is NOT
                    // CAPTURED. Backend order status remains unchanged.
                    if ($reason === 'not_captured') {
                        $this->log("Return callback: authenticated response not CAPTURED.");
                        wp_safe_redirect($this->get_payment_verification_fallback_url());
                        exit();
                    }

                    // All other reasons are transport / HTTP / schema / binding
                    // failures. They must not disclose the WooCommerce order key.
                    $this->log("Return callback: verification failed (" . $reason . ").");
                    wp_safe_redirect($this->get_payment_verification_fallback_url());
                    exit();
                }

                $transaction = $verification['transaction'];
                $verified_payment_id = (string) $transaction['payment_id'];

                // Write verified metadata from the authenticated response only.
                $order->update_meta_data('UPayments_Result', (string) $transaction['result']);
                $order->update_meta_data('UPayments_PaymentID', $verified_payment_id);
                $order->update_meta_data('UPayments_TrackID', (string) $transaction['track_id']);
                $order->update_meta_data('UPayments_payment_type', (string) $transaction['payment_type']);
                // UPayments_Ref comes from the authenticated transaction.reference field
                // (not the legacy unverified callback 'ref' field). See A8.
                $order->update_meta_data('UPayments_Ref', (string) $transaction['reference']);
                $order->update_meta_data('_payment_method_title', 'UPayments');

                // A4 — capture update_status() return value; only set success flags
                // after a successful WooCommerce state transition (or when the order
                // is already in the exact target paid state).
                $current_status = $order->get_status();
                $paid_order_status = 'processing';
                if ($current_status === 'completed' || $this->getIsOrderComplete()) {
                    $paid_order_status = 'completed';
                }

                $status_transition_ok = true;
                if ($current_status !== $paid_order_status) {
                    $status_transition_ok = $order->update_status(
                        $paid_order_status,
                        __('Payment successful with UPayments. PaymentID: ', $this->domain) . $verified_payment_id
                    );
                }

                if (!$status_transition_ok) {
                    $this->log("Return callback: WooCommerce update_status returned false; verified flags not written.", 'warning');
                    wp_safe_redirect($this->get_payment_verification_fallback_url());
                    exit();
                }

                // Set verified flag AFTER successful transition.
                $order->update_meta_data('_upay_verified_capture', 1);
                // Backward-compatibility write for legacy readers (not a security gate).
                $order->update_meta_data('UPayments_webhook_triggered', 1);
                $order->save();

                $this->log("UPayments CAPTURED status verified.");

                if (function_exists('WC') && WC() && WC()->cart) {
                    WC()->cart->empty_cart();
                }

                wp_safe_redirect($this->get_return_url($order));
                exit();
            } catch (\Throwable $e) {
                // A6 — fail-closed exception containment. Never mark failed;
                // never mark cancelled; never deliberately roll the order back
                // (rollback is out of scope and could cause more damage); never
                // set a success flag merely because an exception occurred; do
                // not empty the cart; do not log $e->getMessage() — it could
                // contain transport/data details.
                $this->log("Return callback: unexpected internal error during verified payment processing.", 'warning');
                wp_safe_redirect($this->get_payment_verification_fallback_url());
                exit();
            }
        }

        /**
         * Handle the UPayments server-to-server webhook (notificationUrl).
         *
         * SECURITY: The inbound $_REQUEST result/payment_id/track_id/post_date/
         * tran_id/ref/auth fields are NEVER authoritative. Only a verified
         * authoritative Get Payment Status response with all bindings satisfied
         * and result === 'CAPTURED' may authorize the WooCommerce paid-state
         * transition. Internal exceptions must NOT mark the order failed.
         */
        public function web_hook_handler()
        {
            $this->log("Webhook received; verifying payment status.");

            try {
                if (!isset($_REQUEST["wc_order_id"])) {
                    $this->log("Webhook received without wc_order_id.");
                    exit();
                }

                $raw_order_id = sanitize_text_field(wp_unslash($_REQUEST["wc_order_id"]));
                $order_id = absint($raw_order_id);
                if ($order_id <= 0) {
                    $this->log("Webhook received with invalid wc_order_id.");
                    exit();
                }

                $order = wc_get_order($order_id);
                if (!$order instanceof WC_Order) {
                    $this->log("Webhook received but order could not be loaded.");
                    exit();
                }

                if ($order->get_payment_method() !== $this->id) {
                    $this->log("Webhook received for non-UPayments order.");
                    exit();
                }

                // Order preconditions: require locally generated UPayments_order_id.
                $local_upay_order_id = $order->get_meta('UPayments_order_id');
                if (!is_string($local_upay_order_id) || $local_upay_order_id === '') {
                    $this->log("Webhook received but UPayments_order_id is missing.");
                    exit();
                }

                $track_id = isset($_REQUEST["track_id"])
                    ? sanitize_text_field(wp_unslash($_REQUEST["track_id"]))
                    : '';
                if ($track_id === '') {
                    $this->log("Webhook received without track_id.");
                    exit();
                }

                // A2 — requested_order_id is a cheap local preflight, NOT authentication.
                // Required to be present and strictly equal to local UPayments_order_id
                // BEFORE any authenticated status request is made. Paid-state authority
                // still requires Bearer-authenticated Get Payment Status + B1-B5 +
                // authoritative result === 'CAPTURED'.
                $requested_order_id = isset($_REQUEST["requested_order_id"])
                    ? sanitize_text_field(wp_unslash($_REQUEST["requested_order_id"]))
                    : '';
                if ($requested_order_id === '' || $requested_order_id !== $local_upay_order_id) {
                    $this->log("Webhook requested_order_id preflight failed.", 'warning');
                    exit();
                }

                // A1 — _upay_verified_capture means the original capture has already
                // been authoritatively verified. Webhook must never drive lifecycle state
                // again after a verified capture.
                if ((string) $order->get_meta('_upay_verified_capture') === '1') {
                    exit();
                }

                // A5 — never resurrect a refunded order.
                if ($order->has_status('refunded')) {
                    $this->log("Webhook received for refunded order; leaving status unchanged.");
                    exit();
                }

                $verification = $this->verify_payment_status($order, $track_id);

                if (!$verification['verified']) {
                    $this->log("Webhook: verification failed (" . $verification['reason'] . ").");
                    exit();
                }

                $transaction = $verification['transaction'];
                $verified_payment_id = (string) $transaction['payment_id'];

                // Write verified metadata from the authenticated response only.
                $order->update_meta_data('UPayments_Result', (string) $transaction['result']);
                $order->update_meta_data('UPayments_PaymentID', $verified_payment_id);
                $order->update_meta_data('UPayments_TrackID', (string) $transaction['track_id']);
                $order->update_meta_data('UPayments_payment_type', (string) $transaction['payment_type']);
                // UPayments_Ref comes from the authenticated transaction.reference field
                // (not the legacy unverified callback 'ref' field). See A8.
                $order->update_meta_data('UPayments_Ref', (string) $transaction['reference']);
                $order->update_meta_data('_payment_method_title', 'UPayments');

                // A4 — capture update_status() return value; only set success flags
                // after a successful WooCommerce state transition (or when the order
                // is already in the exact target paid state).
                $current_status = $order->get_status();
                $paid_order_status = 'processing';
                if ($current_status === 'completed' || $this->getIsOrderComplete()) {
                    $paid_order_status = 'completed';
                }

                $status_transition_ok = true;
                if ($current_status !== $paid_order_status) {
                    $status_transition_ok = $order->update_status(
                        $paid_order_status,
                        __('Payment successful with UPayments. PaymentID: ', $this->domain) . $verified_payment_id
                    );
                }

                if (!$status_transition_ok) {
                    $this->log("Webhook: WooCommerce update_status returned false; verified flags not written.", 'warning');
                    exit();
                }

                // Set verified flag AFTER successful transition.
                $order->update_meta_data('_upay_verified_capture', 1);
                // Backward-compatibility write for legacy readers (not a security gate).
                $order->update_meta_data('UPayments_webhook_triggered', 1);
                $order->save();

                $this->log("UPayments CAPTURED status verified.");

                exit();
            } catch (\Throwable $e) {
                // A6 — fail-closed exception containment. An unexpected internal
                // Throwable must not mark payment failed, must not cancel, must not
                // empty the cart, must not set the verified-success flag, and must
                // not include transport/data details in the logged diagnostic.
                $this->log("Webhook: unexpected internal error during verification.", 'warning');
                exit();
            }
        }

        public function check_ipn_response()
        {
            global $woocommerce;
            if (isset($_GET["get_order_status"])){
                $this->get_payment_staus();
            }elseif (isset($_GET["page"])){
                $this->return_from_upayments();
            }else{
                $this->web_hook_handler();
            }
            exit();
        }

        // Process payment (must use feature flags to route API calls)
        public function process_payment( $order_id ) {
            global $woocommerce;

            // Section Y: Defensive order boundary.
            if (!is_numeric($order_id) || (int) $order_id <= 0) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            $order = wc_get_order((int) $order_id);
            if (!$order || !($order instanceof \WC_Order)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            $whitelabled = false;
            $order_data = $order->get_data();
            $order_total = $order->get_total();

            $success_url = site_url() . "/?wc-api=wc_upayments&page=success&wc_order_id=" . $order_id;
            $error_url = site_url() . "/?wc-api=wc_upayments&page=error&wc_order_id=" . $order_id;
            $ipn_url = site_url() . "/?wc-api=wc_upayments&wc_order_id=" . $order_id;

            $unique_order_id = md5($order_id * time());
            $product_name = [];
            $product_price = [];
            $product_qty = [];
            $product_type = [];

            $productArrayNew = [];
            $cart_has_custom_product = false;
            $order_has_subscription_product = false;
            $order_has_normal_product = false;

            $i=0;

            foreach ($order->get_items('line_item') as $item)
            {
                // Section J: Product boundary — fail, do not skip.
                if (!$item || !($item instanceof \WC_Order_Item_Product)) {
                    $this->log('Invalid line item in order.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                /** @var WC_Order_Item_Product $item */
                $product = $item->get_product();
                if (!$product || !($product instanceof \WC_Product)) {
                    $this->log('Unloadable product in order.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Section D: Use order-line values, not current catalog price.
                $qty = $item->get_quantity();
                if (!is_numeric($qty) || (int) $qty <= 0 || (int) $qty > 9999999) {
                    $this->log('Invalid product quantity.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $qty = (int) $qty;

                $line_total = $item->get_total();
                if (!is_numeric($line_total) || (float) $line_total < 0) {
                    $this->log('Invalid line total.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Derive provider-compatible unit price from order line.
                $unit_price = $qty > 0 ? round((float) $line_total / $qty, 2) : 0;

                // Section F: UTF-8 safe truncation.
                $normalized_name = $this->truncate_provider_text($item->get_name(), 255);
                $normalized_description = $this->truncate_provider_text($item->get_name(), 255);

                if($product->get_type() === 'custom_type'){
                    $cart_has_custom_product = true;
                    $order_has_subscription_product = true;
                } else {
                    $order_has_normal_product = true;
                }

                // Section C: Use normalized values in payload.
                $productArrayNew[$i] = array(
                    'name'        => $normalized_name,
                    'description' => $normalized_description,
                    'price'       => $unit_price,
                    'quantity'    => $qty,
                    'type'        => $product->get_type(),
                );
                $i++;
            }

            if (empty($productArrayNew)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            if($this->paymentData == null ) {
                $payment_data = $this->getPaymentIcons();
            } else {
                $payment_data = $this->paymentData;
            }

            // Availability state must not fail open to KNET.
            // Require valid payment_data with boolean whitelabled key.
            if (!is_array($payment_data)
                || !array_key_exists('whitelabled', $payment_data)
                || !is_bool($payment_data['whitelabled'])
            ) {
                $this->log('Payment methods availability unavailable or malformed.', 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            $whitelabled = $payment_data['whitelabled'];

            // Central checkout defaults — initialized before Classic/Blocks branching.
            $src                   = null; // Section O: Non-Whitelabel uses hosted checkout, no specific source.
            $cardToken             = null;
            $isSaveCard            = false;
            $isSaveCardRequested   = false;
            $subscription_plan     = 'one_time';
            $subscription_interval = 0;
            $user_id               = get_current_user_id();

            // Section AN: Detect Store API/REST independently.
            $is_store_api = defined('REST_REQUEST') && REST_REQUEST;
            $is_blocks_request = false;
            $request_data = null;
            $extension_data = array();

            if ($is_store_api) {
                // Store API: parse JSON only, never consume Classic POST.
                $raw_input = file_get_contents('php://input');
                if (is_string($raw_input) && $raw_input !== '') {
                    $request_data = json_decode($raw_input, true);
                }
                if (!is_array($request_data)) {
                    // Malformed JSON in Store API context — reject.
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                if (isset($request_data['extensions'])) {
                    if (!is_array($request_data['extensions'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    if (array_key_exists('upayments', $request_data['extensions'])) {
                        if (!is_array($request_data['extensions']['upayments'])) {
                            wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                        }
                        $extension_data = $request_data['extensions']['upayments'];
                    }
                    // Missing upayments namespace => empty extension data (not Classic fallback).
                }
                $is_blocks_request = true;
            }
            // Classic POST path is only for actual Classic checkout (not Store API).

            if ($is_blocks_request) {
                // Blocks path: read save_card and card_token only.
                // Section AC: Reject non-scalar security-sensitive fields.
                if (isset($extension_data['card_token'])) {
                    if (!is_scalar($extension_data['card_token'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $cardToken = trim((string) $extension_data['card_token']);
                }

                if (isset($extension_data['save_card'])) {
                    $parsed_save = self::parse_save_card_strict($extension_data['save_card']);
                    if ($parsed_save === null) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $isSaveCardRequested = $parsed_save;
                }

                if (isset($extension_data['upay_subscription_plan'])) {
                    if (!is_scalar($extension_data['upay_subscription_plan'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_plan = sanitize_text_field($extension_data['upay_subscription_plan']);
                }
                if (isset($extension_data['upay_subscription_interval'])) {
                    if (!is_scalar($extension_data['upay_subscription_interval'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_interval = self::parse_interval($extension_data['upay_subscription_interval']);
                }
            } else {
                // Classic path: require scalar + wp_unslash before sanitizing.
                // Section AC: Reject non-scalar security-sensitive fields.
                $this->log("Whitelabled: " . ($whitelabled ? "true" : "false"));

                if (isset($_POST["save_card"])) {
                    if (!is_scalar($_POST["save_card"])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $parsed_save = self::parse_save_card_strict(wp_unslash($_POST["save_card"]));
                    if ($parsed_save === null) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $isSaveCardRequested = $parsed_save;
                }

                if (isset($_POST["card_token"])) {
                    if (!is_scalar($_POST["card_token"])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $cardToken = trim((string) wp_unslash($_POST["card_token"]));
                }

                if (isset($_POST['upay_subscription_plan'])) {
                    if (!is_scalar($_POST['upay_subscription_plan'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_plan = sanitize_text_field(wp_unslash($_POST['upay_subscription_plan']));
                }
                if (isset($_POST['upay_subscription_interval'])) {
                    if (!is_scalar($_POST['upay_subscription_interval'])) {
                        wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    $subscription_interval = self::parse_interval(wp_unslash($_POST['upay_subscription_interval']));
                }
            }

            // === PAYMENT SOURCE RESOLUTION ===
            // Determine payment source based on whitelabel state.
            // Non-whitelabel: source is always 'knet' (client input ignored).
            // Whitelabel: source must be explicitly provided by client.
            if ($whitelabled) {
                // Whitelabel: read client-supplied source.
                $raw_src = null;
                if ($is_blocks_request) {
                    if (isset($extension_data['upayment_payment_type'])) {
                        if (!is_scalar($extension_data['upayment_payment_type'])) {
                            wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                        }
                        $raw_src = trim((string) sanitize_text_field($extension_data['upayment_payment_type']));
                    }
                } else {
                    if (isset($_POST["upayment_payment_type"])) {
                        if (!is_scalar($_POST["upayment_payment_type"])) {
                            wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                            return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                        }
                        $raw_src = trim((string) sanitize_text_field(wp_unslash($_POST["upayment_payment_type"])));
                    }
                }

                // Whitelabel source must be explicit: missing/empty/array → reject.
                if ($raw_src === null || $raw_src === '') {
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                $src = $raw_src;
            }
            // Non-whitelabel: $src remains null (hosted checkout).

            // Derive selected-card state after both Blocks/Classic paths resolved.
            $has_selected_card = is_string($cardToken) && $cardToken !== '';

            // === CROSS-PATH VALIDATION (applies to both Classic and Blocks) ===

            // Section C: Source validation only for Whitelabel.
            if ($whitelabled) {
                // Payment source server allowlist.
                if ($src === null || !in_array($src, self::$ALLOWED_PAYMENT_SOURCES, true)) {
                    $this->log('Invalid payment source rejected.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                // Whitelabel enabled-method check: fail closed if payment map unavailable.
                if (!is_array($payment_data)
                    || !isset($payment_data['payment'])
                    || !is_array($payment_data['payment'])
                ) {
                    $this->log('Whitelabel: payment method map unavailable.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                if (!isset($payment_data['payment'][$src])) {
                    $this->log('Disabled payment source rejected: ' . $src, 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid UPayments payment method.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
            } else {
                // Non-Whitelabel: $src must be null (hosted checkout).
                if ($src !== null) {
                    $this->log('Non-Whitelabel: source must be null.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // Subscription plan allowlist.
            if (!self::is_valid_subscription_plan($subscription_plan)) {
                $this->log('Invalid subscription plan rejected: ' . $subscription_plan, 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Please select a valid payment type.", $this->domain), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            // Section N: Subscription-context enforcement with mixed-order rejection.
            // Uses order-derived composition from the authoritative line-item pass above.
            if ($subscription_plan !== 'one_time') {
                if ($this->autoDeduction !== 'yes'
                    || !$order_has_subscription_product
                    || $order_has_normal_product
                ) {
                    $this->log('Subscription plan rejected: mixed order or invalid context.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Please select a valid payment type.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // Guest subscriptions must fail server-side.
                if (!is_user_logged_in()) {
                    $this->log('Subscription checkout rejected for guest.');
                    wc_add_notice(__("Please log in to purchase a subscription.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // Subscription checkout requires cc.
                if ($src !== 'cc') {
                    $this->log('Subscription checkout requires cc payment source.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Subscription payments require Credit Card.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // Save-card feature must be enabled for subscriptions.
                if ($this->saveCardEnabled !== 'yes') {
                    $this->log('Subscription checkout requires save-card feature enabled.');
                    wc_add_notice(__("Please select a valid payment type.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
                // For new card (no existing saved card token), require explicit save-card opt-in.
                // For existing saved card (cardToken present), do NOT require save-card toggle.
                if (!$has_selected_card && !$isSaveCardRequested) {
                    $this->log("Subscription checkout with new card requires save-card opt-in.");
                    wc_add_notice(__("Please Enable Save Card Toggle to Proceed with Subscription Purchase.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }
            }

            // Interval validation (uses strict parser).
            if (!self::is_valid_subscription_interval($subscription_plan, $subscription_interval)) {
                wc_add_notice(__("Please select a valid Billing Interval.", $this->domain), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            // === POST-VALIDATION METADATA ===
            $customer_unq_token = null;
            $credit_card_token = $cardToken;

            // === SERVER-SIDE SAVE-CARD REQUEST CONTRACT (Section T) ===
            // An explicit Save Card request is valid ONLY for:
            // logged-in, Save Card enabled, whitelabel, CC source, NEW card.
            if ($isSaveCardRequested) {
                if ($user_id <= 0
                    || $this->saveCardEnabled !== 'yes'
                    || !$whitelabled
                    || $src !== 'cc'
                    || $has_selected_card
                ) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // === EFFECTIVE SAVE CARD (Section U) ===
            // After contract validation: only new CC + explicit opt-in.
            $isSaveCard = $isSaveCardRequested && !$has_selected_card;

            // Read phone through WC Order API (works for both Classic and Blocks).
            $billing_phone_raw = $order->get_billing_phone();
            $phone = is_scalar($billing_phone_raw) ? (string) $billing_phone_raw : '';
            // Preserve legacy normalization used by existing token flow.
            $phone = str_replace(' ', '', $phone);
            $phone = preg_replace('/[^A-Za-z0-9\-]/', '', $phone);

            // Provider mobile: separate representation for API customer.mobile.
            // Only send when explicit international format can be safely established.
            $provider_mobile = '';
            if (is_scalar($billing_phone_raw)) {
                $raw = trim((string) $billing_phone_raw);
                if (strlen($raw) > 1 && $raw[0] === '+') {
                    $candidate = preg_replace('/[\s\-\(\)]+/', '', $raw);
                    if (preg_match('/^\+[0-9]+$/', $candidate) && strlen($candidate) <= 15) {
                        $provider_mobile = $candidate;
                    }
                }
            }

            // Determine if this transaction requires a customer token.
            $requires_token = $isSaveCard || $subscription_plan !== 'one_time';
            $canonical_token = null;
            $token_kind = null;
            $token_scope = null;
            $token_generation = null;

            // Compute customer.uniqueId using pre-PR16 compatibility behavior.
            $billing_phone_raw = $order->get_billing_phone();
            $customer_unique_id = '';
            if (is_scalar($billing_phone_raw)) {
                $phone_normalized = str_replace(' ', '', (string) $billing_phone_raw);
                $phone_normalized = preg_replace('/[^A-Za-z0-9\-]/', '', $phone_normalized);
                if ($user_id > 0 && !empty($phone_normalized)) {
                    $customer_unique_id = $phone_normalized . $user_id;
                } elseif (!empty($phone_normalized)) {
                    $customer_unique_id = $phone_normalized;
                }
                if (substr($customer_unique_id, 0, 1) === '0') {
                    $customer_unique_id = '1' . substr($customer_unique_id, 1);
                }
            }

            // Clear stale PR16 attempt metadata before token work.
            // Preserve legacy/unscoped evidence for Phase 9I migration.
            if (!CustomerTokenIdentity::clear_stale_pr16_attempt_metadata($order)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section I: Force-fresh current order metadata after cleanup.
            if (!CustomerTokenIdentity::force_refresh_order_meta($order)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section K: Check for residual migration evidence on current order (cardinality helper).
            // Token-dependent operations must not proceed alongside preserved legacy/corrupt evidence.
            $token_dependent_operation = $isSaveCard || $subscription_plan !== 'one_time' || $has_selected_card;

            if ($token_dependent_operation) {
                $residual_keys = array(
                    '_upay_customer_unique_token',
                    '_upay_customer_token_kind_v1',
                    '_upay_customer_token_scope_v1',
                    '_upay_customer_token_generation_v1',
                    '_upay_credit_card_token',
                );
                $has_residual_evidence = false;
                foreach ($residual_keys as $rkey) {
                    $r_card = CustomerTokenIdentity::get_historical_meta_cardinality($order, $rkey);
                    if ($r_card['status'] === CustomerTokenIdentity::META_EXACTLY_ONE) {
                        // Empty string card token is not usable evidence.
                        if ($rkey === '_upay_credit_card_token' && (string) $r_card['value'] === '') {
                            continue;
                        }
                        $has_residual_evidence = true;
                        break;
                    }
                    if ($r_card['status'] === CustomerTokenIdentity::META_DUPLICATE_OR_INVALID) {
                        $has_residual_evidence = true;
                        break;
                    }
                }
                if ($has_residual_evidence) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // Section J: Stage UPayments_Checkout_Selected AFTER cleanup/refresh/residual gate.
            if ($whitelabled) {
                $order->delete_meta_data("UPayments_Checkout_Selected");
                $order->add_meta_data("UPayments_Checkout_Selected", $src);
            }

            // CASE: Selected saved card requires membership validation.
            if ($has_selected_card) {
                if ($user_id <= 0) {
                    wc_add_notice(__('Please log in to use a saved card.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if ($this->saveCardEnabled !== 'yes') {
                    wc_add_notice(__('Please select a valid payment type.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if (!$whitelabled || $src !== 'cc') {
                    wc_add_notice(__('Please select a valid payment method.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Section Q: Use read-only identity scope for selected-card path.
                $scope = CustomerTokenIdentity::get_existing_scope_fingerprint($this->apiKey, $this->getMode());
                if ($scope === null) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                // Section Q: Use read-only generation for selected-card path.
                $existing_generation = CustomerTokenIdentity::get_existing_generation_id();
                if ($existing_generation === null) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $provenance = CustomerTokenIdentity::read_provenance($user_id, $scope);
                if ($provenance['state'] !== CustomerTokenIdentity::STATE_VALID) {
                    wc_add_notice(__('Please log in to use a saved card.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $canonical_token = $provenance['record']['token'];
                $token_kind = $provenance['record']['kind'];
                $token_scope = $scope;
                $token_generation = isset($provenance['record']['secret_generation_id'])
                    ? $provenance['record']['secret_generation_id']
                    : null;

                $gateway = $this;
                $membership_valid = CustomerTokenIdentity::verify_card_membership(
                    $credit_card_token,
                    $canonical_token,
                    function($token) use ($gateway) {
                        return $gateway->getSavedCards($token);
                    }
                );

                if (!$membership_valid) {
                    wc_add_notice(__('Please select a valid payment method.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $isSaveCard = false;
            }
            // CASE: Save Card or subscription requires canonical token.
            elseif ($requires_token) {
                if ($user_id <= 0) {
                    wc_add_notice(__('Please log in to save a card or purchase a subscription.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if ($this->saveCardEnabled !== 'yes') {
                    wc_add_notice(__('Please select a valid payment type.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $gateway = $this;
                $token_result = CustomerTokenIdentity::get_or_establish_token(
                    $user_id,
                    $this->apiKey,
                    $this->getMode(),
                    function($candidate) use ($gateway) {
                        $params = wp_json_encode(array('customerUniqueToken' => $candidate));
                        return $gateway->execute_upayments_request('create-customer-unique-token', 'POST', $params);
                    }
                );

                if (!$token_result['success']) {
                    $this->log('Token establishment failed: ' . $token_result['reason'], 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $canonical_token = $token_result['token'];
                $token_kind = isset($token_result['kind']) ? $token_result['kind'] : null;
                $token_scope = isset($token_result['scope']) ? $token_result['scope'] : null;
                $token_generation = isset($token_result['secret_generation_id']) ? $token_result['secret_generation_id'] : null;
            }
            // CASE: Ordinary payment — no canonical token required.
            else {
                $canonical_token = null;
                $token_kind = null;
                $token_scope = null;
                $token_generation = null;
                $isSaveCard = false;
            }

            // Write current attempt snapshots.
            // Section J: Ordinary payment (null tuple) must NOT initialize token identity.
            $is_ordinary_payment = ($canonical_token === null && $token_kind === null && $token_scope === null && $token_generation === null);

            if (!$is_ordinary_payment) {
                // Section S: Use read-only authoritative expected scope/generation.
                // For freshly established tokens, these are already known from the result.
                // For selected-card tokens, they were already validated above.
                $expected_scope = CustomerTokenIdentity::get_existing_scope_fingerprint($this->apiKey, $this->getMode());
                $expected_generation = CustomerTokenIdentity::get_existing_generation_id();

                if (!CustomerTokenIdentity::validate_token_runtime_context(
                    $canonical_token,
                    $token_kind,
                    $token_scope,
                    $token_generation,
                    $expected_scope !== null ? $expected_scope : '',
                    $expected_generation !== null ? $expected_generation : ''
                )) {
                    $this->log('Runtime token context validation failed.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // Section H: Unique snapshot writes.
            if ($has_selected_card) {
                $order->delete_meta_data("_upay_credit_card_token");
                $order->add_meta_data("_upay_credit_card_token", $credit_card_token, true);
            }

            if (!$is_ordinary_payment) {
                $order->delete_meta_data("_upay_customer_unique_token");
                $order->delete_meta_data("_upay_customer_token_kind_v1");
                $order->delete_meta_data("_upay_customer_token_scope_v1");
                $order->delete_meta_data("_upay_customer_token_generation_v1");
                $order->add_meta_data("_upay_customer_unique_token", $canonical_token, true);
                $order->add_meta_data("_upay_customer_token_kind_v1", $token_kind, true);
                $order->add_meta_data("_upay_customer_token_scope_v1", $token_scope, true);
                $order->add_meta_data("_upay_customer_token_generation_v1", $token_generation, true);
            }

            $order->save_meta_data();

            // Section M: Durable persistence verification before Charge.
            if (!$is_ordinary_payment || $has_selected_card) {
                $verify_order = wc_get_order($order_id);
                if (!$verify_order) {
                    $this->log('Persistence verification: unable to reload order.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                if (!CustomerTokenIdentity::force_refresh_order_meta($verify_order)) {
                    $this->log('Persistence verification: force refresh failed.', 'warning');
                    wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }

                $verify_keys = array();
                if (!$is_ordinary_payment) {
                    $verify_keys['_upay_customer_unique_token'] = $canonical_token;
                    $verify_keys['_upay_customer_token_kind_v1'] = $token_kind;
                    $verify_keys['_upay_customer_token_scope_v1'] = $token_scope;
                    $verify_keys['_upay_customer_token_generation_v1'] = $token_generation;
                }
                if ($has_selected_card) {
                    $verify_keys['_upay_credit_card_token'] = $credit_card_token;
                }

                foreach ($verify_keys as $vkey => $expected_value) {
                    $v_card = CustomerTokenIdentity::get_historical_meta_cardinality($verify_order, $vkey);
                    if ($v_card['status'] !== CustomerTokenIdentity::META_EXACTLY_ONE) {
                        $this->log('Persistence verification failed: ' . $vkey, 'warning');
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                    if ((string) $v_card['value'] !== (string) $expected_value) {
                        $this->log('Persistence verification value mismatch: ' . $vkey, 'warning');
                        wc_add_notice(__('Payment request could not be completed. Please try again.', 'upayments'), 'error');
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                }
            }

            $extraMerchantData = null;
            if ($this->multiMerchant == "yes") {
                $this->log("multiMerchant enabled");
                if(isset($this->ibanNumber) && isset($this->knetCharge) && $this->knetCharge > 0 && isset($this->ccCharge) &&((float) $this->knetCharge > 0) && (float) $this->ccCharge > 0) {

                    $extraMerchantData[0] = [
                        "amount" =>  $order_total,
                        "knetCharge" => (float) $this->knetCharge,
                        "knetChargeType" =>  $this->knetChargeType,
                        "ccCharge" => $this->ccCharge,
                        "ccChargeType" => $this->ccChargeType,
                        "ibanNumber" => $this->ibanNumber
                    ];
                }
                $this->log("Multimerchant payment data prepared.");
            }

            // Section E: Build base provider payload as PHP array.
            // Section G: Order field preflight.
            $order_description = 'WooCommerce order #' . $order_id;
            if (strlen($order_description) > 500) {
                $order_description = substr($order_description, 0, 500);
            }
            $currency = $this->getCurrencyCode($order_data["currency"]);
            if (!preg_match('/^[A-Z]{3}$/', $currency)) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }
            $amount_str = (string) $order_total;
            // Section I: Strict positive plain-decimal grammar.
            if (!preg_match('/^[0-9]+(?:\.[0-9]+)?$/', $amount_str)
                || strlen($amount_str) > 22
                || strpos($amount_str, 'e') !== false
                || strpos($amount_str, 'E') !== false
                || (float) $amount_str <= 0
            ) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }
            // Final amount as JSON number, not quoted string.
            $amount_number = (float) $amount_str;

            // Section H: Reference preflight.
            $reference_id = (string) $order_id;
            if (strlen($reference_id) > 35) {
                wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section M: Callback URL validation — absolute http/https with host.
            $urls_to_validate = array(
                'returnUrl'       => $success_url,
                'cancelUrl'       => $error_url,
                'notificationUrl' => $ipn_url,
            );
            foreach ($urls_to_validate as $url_key => $url_val) {
                if (!is_scalar($url_val) || (string) $url_val === '' || strlen((string) $url_val) > 250) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $parsed = wp_parse_url((string) $url_val);
                if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])
                    || ($parsed['scheme'] !== 'http' && $parsed['scheme'] !== 'https')
                    || $parsed['host'] === ''
                ) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
            }

            // Section L: Customer payload normalization.
            $customer_name = $order_data["billing"]["first_name"] . " " . $order_data["billing"]["last_name"];
            if (function_exists('mb_substr')) {
                $customer_name = mb_substr($customer_name, 0, 50);
            } else {
                $customer_name = substr($customer_name, 0, 50);
            }

            $customer_data = array(
                'name' => $customer_name,
            );
            $email = isset($order_data["billing"]["email"]) && is_scalar($order_data["billing"]["email"]) ? (string) $order_data["billing"]["email"] : '';
            if ($email !== '' && strlen($email) <= 50 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $customer_data['email'] = $email;
            }
            if ($customer_unique_id !== '' && strlen($customer_unique_id) <= 50) {
                $customer_data['uniqueId'] = $customer_unique_id;
            }
            if ($provider_mobile !== '') {
                $customer_data['mobile'] = $provider_mobile;
            }

            // Build payload as PHP array (Section B: single order key).
            $payload = array(
                'returnUrl'       => $success_url,
                'cancelUrl'       => $error_url,
                'notificationUrl' => $ipn_url,
                'products'        => $productArrayNew,
                'order'           => array(
                    'id'          => $unique_order_id,
                    'description' => $order_description,
                    'currency'    => $currency,
                    'amount'      => $amount_number,
                ),
                'reference'       => array(
                    'id' => $reference_id,
                ),
                'customer'        => $customer_data,
                'plugin'          => array(
                    'src' => 'woocommerce',
                ),
                'is_whitelabled'  => $whitelabled,
                'language'        => 'en',
                'isSaveCard'      => $isSaveCard,
                'tokens'          => array(
                    'creditCard'          => $credit_card_token,
                    'customerUniqueToken' => $canonical_token,
                ),
                'device'          => array(
                    'browser'          => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/107.0.0.0 Safari/537.36 OPR/93.0.0.0',
                    'browserDetails'   => array(
                        'screenWidth'                 => '1920',
                        'screenHeight'                => '1080',
                        'colorDepth'                  => '24',
                        'javaEnabled'                 => 'false',
                        'language'                    => 'en',
                        'timeZone'                    => '-180',
                        '3DSecureChallengeWindowSize' => '500_X_600',
                    ),
                ),
                'extraMerchantData' => $extraMerchantData,
            );

            // Section D: Whitelabel paymentGateway only.
            if ($whitelabled && $src !== null) {
                if (strlen($src) > 11) {
                    wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                $payload['paymentGateway'] = array('src' => $src);
            }

            $params = wp_json_encode($payload);
            if (!is_string($params) || $params === '') {
                $this->log('Charge payload encoding failed.', 'warning');
                wc_add_notice(__('Payment request could not be completed. Please try again.', $this->domain), 'error');
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            $this->log(__("Create payment request prepared.", $this->domain));

            $transport = $this->execute_upayments_request('charge', 'POST', $params);

            // Section S: Strict HTTP 201 check for Charge.
            if (!is_array($transport)
                || !isset($transport['transport_ok']) || !$transport['transport_ok']
                || !isset($transport['http_status']) || (int) $transport['http_status'] !== 201
                || !isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0
                || !isset($transport['body']) || !is_scalar($transport['body'])
            ) {
                $this->log('UPayments charge request failed.', 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }

            $response = (string) $transport['body'];
            $this->log('Create payment HTTP response received.');

            // Charge response processing — hardened structural validation.
            // Use \Throwable to catch TypeError from PHP 8+ malformed structures.
            try
            {
                if (!$response){
                    $this->log('Charge response: empty body.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                $result = json_decode($response, true);
                $this->log(__("Create payment response received.", $this->domain));

                // A. json_decode result MUST be array.
                if (!is_array($result)){
                    $this->log('Charge response: malformed JSON.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                // B. Status must be boolean true/false. Reject non-boolean.
                if (!array_key_exists('status', $result) || !is_bool($result['status'])) {
                    $this->log('Charge response: status not boolean.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                if ($result['status'] === false) {
                    $this->log('Charge response: provider declared failure.', 'warning');
                    WC()->session->set("refresh_totals", true);
                    wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                    return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                }

                // C/D. Status=true: require structural data.
                if ($result['status'] === true){
                    // Require data to be an array.
                    if (!isset($result['data']) || !is_array($result['data'])) {
                        $this->log('Charge response: status=true but data missing/invalid.', 'warning');
                        WC()->session->set("refresh_totals", true);
                        wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                    }

                    // E. Determine redirect URL: prefer data.link, fallback to data.transactionData.redirect_url.
                    $redirect_url = null;

                    if (isset($result['data']['link']) && is_scalar($result['data']['link'])) {
                        $redirect_url = $this->normalize_upayments_redirect_url($result['data']['link']);
                    }

                    if ($redirect_url === null
                        && isset($result['data']['transactionData'])
                        && is_array($result['data']['transactionData'])
                        && isset($result['data']['transactionData']['redirect_url'])
                        && is_scalar($result['data']['transactionData']['redirect_url'])
                    ) {
                        $redirect_url = $this->normalize_upayments_redirect_url($result['data']['transactionData']['redirect_url']);
                    }

                    // Require a valid redirect URL.
                    if ($redirect_url === null) {
                        $this->log('Charge response: no valid redirect URL found.', 'warning');
                        WC()->session->set("refresh_totals", true);
                        wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                        return ["result" => "failure", "redirect" => wc_get_checkout_url()];
                    }

                    // Valid success path.
                    if($subscription_plan && $subscription_plan !== 'one_time') {
                        $order->delete_meta_data('_upay_subscription_plan');
                        $order->add_meta_data('_upay_subscription_plan', $subscription_plan);
                        $order->delete_meta_data('_upay_subscription_interval');
                        $order->add_meta_data('_upay_subscription_interval', $subscription_interval);
                        $order->delete_meta_data('_upay_subscription_status');
                        $order->add_meta_data('_upay_subscription_status', 'active');
                        $order->delete_meta_data('UPayments_AutoDeduction');
                        $order->add_meta_data('UPayments_AutoDeduction', 'no');
                        $order->save_meta_data();
                    }

                    $order->delete_meta_data("UPayments_order_id");
                    $order->add_meta_data("UPayments_order_id", $unique_order_id);
                    $order->save_meta_data();

                    return ["result" => "success", "redirect" => $redirect_url];
                }

                // Unrecognized response structure.
                $this->log('Charge response: unrecognized structure.', 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];

            } catch (\Throwable $e) {
                // Fail-closed: catch TypeError (PHP 8+) and Exception (PHP 7.2+).
                // Do NOT log $e->getMessage() — may contain internal/provider details.
                $this->log('Charge response: unexpected error during processing.', 'warning');
                WC()->session->set("refresh_totals", true);
                wc_add_notice(__("Payment request could not be completed. Please try again.", $this->domain), "error");
                return ["result" => "failure", "redirect" => wc_get_checkout_url()];
            }
        }

        // Frontend payment fields (must use feature flags for design)
        public function payment_fields() {
            $save_card_enabled  = ('yes' == $this->get_option('enable_save_card'));
            $template_args = array('gateway' => $this,'save_card_enabled' => ('yes' == $save_card_enabled));
            // Check setting for design toggle
            $use_new_design = ($this->get_option('use_new_design') == 'yes') ? true : false;
            
            wc_get_template(
                $use_new_design ? 'new-design-form.php' : 'old-design-form.php',
                $template_args,
                '', // Preserve WooCommerce's default theme template override path.
                untrailingslashit( plugin_dir_path( __FILE__ ) ) . '/templates/'
            );
        }
        
        /**
         * enqueue_scripts
         *
         * @return void
         */
        public function enqueue_scripts() {
            $plugin_url = plugin_dir_url( __FILE__ );
            wp_enqueue_style('customer-new-style', $plugin_url . 'assets/css/customer.css', array(), '3.0.0' );
            // Check if we are on the checkout page AND the gateway is active
            if ( ! is_checkout() || ! $this->is_available() ) {
                return;
            }
            
            // Always enqueue core scripts (e.g., utility functions, global validation)
            wp_enqueue_style('google-fonts', 'https://fonts.googleapis.com/css2?family=Almarai&display=swap');
            wp_enqueue_style('font-awesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css');

            if (is_checkout() && !is_wc_endpoint_url()) {
                if ($this->get_option('use_new_design') == 'yes') {
                    // Load New Design specific resources (Modal handling, modern API SDK)
                    wp_enqueue_style('custom-checkout-new-style', $plugin_url . 'assets/css/new-design.css', array(), '3.0.0' );
                    wp_enqueue_script('custom-checkout-script', $plugin_url . 'assets/js/new-upay.js', array('jquery'), '3.0.0', true );
                } else {
                    // Load Old Design specific resources (Inline form handling, legacy API SDK)
                    wp_enqueue_style('custom-checkout-old-style', $plugin_url . 'assets/css/old-design.css', array(), '3.0.0' );
                    wp_enqueue_script('custom-checkout-old-script', $plugin_url . 'assets/js/old-upay.js', array('jquery'), '3.0.0', true );
                }
                wp_enqueue_script('upayments-subscription-checkout', $plugin_url. 'assets/js/subscription-checkout.js', array('jquery'),'3.0.0',true);
                wp_localize_script('upayments-subscription-checkout', 'wcUser', [
                    'isLoggedIn' => is_user_logged_in(),
                    'userId'     => get_current_user_id(),
                ]);
            }            
            
            // Localize data needed by the JavaScript (e.g., API keys, environment settings)
            wp_localize_script( 'your-gateway-core', 'YourGatewayParams', array(
                'isNewDesign' => $this->get_option('use_new_design') == 'yes',
            ));
        }

        /**
         * Enqueue admin scripts for the custom repeater.
         */
        public function admin_enqueue_scripts() {
            $plugin_url = plugin_dir_url( __FILE__ );
            
            // Check if we are on the correct gateway settings page
            if ( isset( $_GET['page'] ) && $_GET['page'] == 'wc-settings' && isset( $_GET['tab'] ) && $_GET['tab'] == 'checkout' && isset( $_GET['section'] ) && $_GET['section'] == $this->id ) {    
                
                wp_enqueue_style('upayments-multimerchant-style',$plugin_url.'assets/css/admin-style.css', [], '3.0.0' );
                wp_enqueue_script('upayments-multimerchant-repeater',$plugin_url.'assets/js/multimerchant-repeater.js',array('jquery'), '3.0.0',true);
            }

            // Check to ensure we are only loading this script on *our* settings page.
            $screen = get_current_screen();

            // The screen ID for WooCommerce payment settings pages often looks like 'woocommerce_page_wc-settings'
            if ( $screen && $screen->id === 'woocommerce_page_wc-settings' && isset( $_GET['tab'] ) && $_GET['tab'] === 'checkout' ) {
                // Enqueue the custom admin logic script
                wp_enqueue_script(
                    'upayments-admin-logic',$plugin_url.'assets/js/admin-settings.js',array( 'jquery' ),'3.0.0',true
                );
                
                // Also enqueue a small style block to make the disabled row visually distinct
                wp_add_inline_style(
                    'woocommerce_admin_styles', '.upayments-disabled-setting { opacity: 0.5; pointer-events: none; }'
                );
            }
        }

        public function admin_order_details($order)
        {
            if ($order->get_payment_method() === $this->id)
            {
                $payment_status_raw = $order->get_meta('UPayments_Result', true);
                $payment_status = is_scalar($payment_status_raw) ? (string) $payment_status_raw : '';

                $upayment_id_raw = $order->get_meta('UPayments_PaymentID', true);
                $upayment_id = is_scalar($upayment_id_raw) ? (string) $upayment_id_raw : '';

                if ($payment_status !== '' || $upayment_id !== '') { ?>
                    <table class="wc-order-totals" style="border-top: 1px solid #999; margin-top:12px; padding-top:12px">
            <tbody>
                            <tr>
                                <td class="label"><h3 style="margin:0"><?php esc_html_e('Payment Status', 'upayments'); ?>:</h3></td>
                <td width="1%"></td>
                <td class="total">
                                    <span class="woocommerce-Price-amount amount"><strong><?php echo esc_html($payment_status); ?></strong></span>
                                </td>
                            </tr>
                            <tr>
                <td class="label"><h3 style="margin:0"><?php esc_html_e('UPayment ID', 'upayments'); ?>:</h3></td>
                <td width="1%"></td>
                <td class="total">
                                    <span class="woocommerce-Price-amount amount">
                                        <strong>
                                        <?php echo esc_html($upayment_id); ?>
                                        </strong>
                                    </span>
                                </td>
                            </tr>
                            
                        </tbody>
                    </table>
            <?php
                }
            }
        }

        public function custom_payment_gateway_icons($icon, $gateway_id)
        {
            foreach (WC()->payment_gateways->get_available_payment_gateways() as $gateway){
                if ($gateway->id == $gateway_id){
                    $title = $gateway->get_title();
                    break;
                }
            }
            if ($gateway_id == "upayments"){
                $icon = '<span>Pay securely with <img src="'.UP_PLUGIN_URL.'assets/images/upayment.png" alt="UPayemnts"  title="UPayments" style="height: 24px !important; padding-left:4px;"/></span>';
            }
            return $icon;
        }

        /**
         * Process Gateway Settings Form Fields.
         */
        public function process_admin_options()
        {
            $this->init_settings();
            $post_data = $this->get_post_data();

            if (empty($post_data["woocommerce_upayments_api_key"])){
                WC_Admin_Settings::add_error(__("Please enter UPayments API Key", $this->domain));
            }else{
                if(isset($post_data['woocommerce_upayments_enable_multimerchant']) && $post_data['woocommerce_upayments_enable_multimerchant'] == 1) {
                    if(empty($post_data['woocommerce_upayments_iban_number']) || empty($post_data['woocommerce_upayments_cc_charge']) || empty($post_data['woocommerce_upayments_cc_charge_type']) || empty($post_data['woocommerce_upayments_knet_charge']) || empty($post_data['woocommerce_upayments_knet_charge_type'])) {
                        WC_Admin_Settings::add_error(__("Please enter Multimerchant Configuration", $this->domain));
                    }
                } else {
                    $post_data['woocommerce_upayments_iban_number'] = null;
                    $post_data['woocommerce_upayments_cc_charge'] = null;
                    $post_data['woocommerce_upayments_cc_charge_type'] = null;
                    $post_data['woocommerce_upayments_knet_charge'] = null;
                    $post_data['woocommerce_upayments_knet_charge_type'] = null;
                }
                foreach ($this->get_form_fields() as $key => $field)
                {
                    $setting_value = $this->get_field_value($key, $field, $post_data);
                    $this->settings[$key] = $setting_value;
                }
                delete_option("upayments_maat");
                return update_option($this->get_option_key() , apply_filters("woocommerce_settings_api_sanitized_fields_" . $this->id, $this->settings));
            }
        }

        public function get_multimerchant_credentials( $order ) {
            // 1. Check if Multimerchant is enabled at all
            if ($this->get_option( 'enable_multimerchant' ) == 'no') {
                return $this->get_default_credentials();
            }
            
            // 2. Retrieve and parse the stored rules
            $rules_json = $this->get_option( 'multimerchant_accounts', '[]' );
            $rules = json_decode( $rules_json, true );

            if (!is_array($rules) || empty($rules)) {
                // Fallback if rules are enabled but not configured
                $this->log( 'Multimerchant enabled but no rules found. Using default credentials.', 'error' );
                return $this->get_default_credentials();
            }

            // --- Core Routing Logic ---
            
            foreach ( $rules as $rule ) {
                $condition_type  = $rule['condition_type'] ?? '';
                $condition_value = $rule['condition_value'] ?? '';

                // If a rule has no condition, skip it (it won't match anything specific)
                if ( empty( $condition_type ) || empty( $condition_value ) ) {
                    continue;
                }

                $match_found = false;

                switch ( $condition_type ) {
                    case 'fixed':
                        // Check if the order currency matches the rule value (e.g., USD, EUR)
                        if ($condition_value === 'fixed') {
                            $match_found = true;
                        }
                        break;
                    case 'percentage':
                        // Check if the billing country matches the rule value (e.g., US, DE)
                        if ($condition_value === 'percentage') {
                            $match_found = true;
                        }
                        break;
                    default:
                        // Unhandled condition type
                        break;
                }

                if ( $match_found ) {
                    $this->log( "Multimerchant routing rule matched.", 'info' );
                    return [
                        'merchant_id' => $rule['merchant_id'],
                        'api_key'     => $rule['api_key'],
                    ];
                }
            }
            // 3. Fallback: If no custom rule matched, use default credentials
            $this->log( 'No specific routing rule matched. Using default credentials.', 'info' );
            return $this->get_default_credentials();
        }

        public function get_default_credentials() {
            // Assuming your default credentials are stored as standard gateway options
            return [
                'merchant_id' => $this->get_option( 'default_merchant_id' ),
                'api_key'     => $this->get_option( 'default_api_key' ),
            ];
        }

        /**
         * Generate the HTML for the Multimerchant Repeater field.
         * This is where the table structure for the rules is defined.
         * * @param string $key The field key (multimerchant_accounts).
         * @param array $data Field data from init_form_fields().
         * @return string HTML output for the field.
         */
        public function generate_multimerchant_repeater_html( $key, $data ) {
            // Get stored rules (value is a JSON string, must be decoded)
            $settings = $this->get_option( $key, $data['default'] );
            $rules = json_decode( $settings, true );
            if ( ! is_array( $rules ) ) {
                $rules = [];
            }

            $conditions = [
                'fixed'      => __( 'Fixed', $this->domain ),
                'percentage'       => __( 'Percentage', $this->domain ),
            ];

            // Pass the repeater HTML to a dedicated function for cleanliness
            ob_start();
            ?>
            <tr valign="top" class="upayments-multimerchant-repeater">
                <th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ); ?></th>
                <td class="forminp forminp-<?php echo esc_attr( sanitize_title( $data['type'] ) ); ?>">
                    <p class="description"><?php echo wp_kses_post( $data['description'] ); ?></p>
                    <div id="multimerchant_repeater_container">
                        <table class="widefat wc_input_multimerchant_repeater" cellspacing="0">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'IBAN Number', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'Knet Charge', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'Knet Charge Type', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'CC Charge', $this->domain ); ?></th>
                                    <th><?php esc_html_e( 'CC Charge Type', $this->domain ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr class="">
                                    <td>
                                        <input type="text" name="woocommerce_upayments_iban_number" data-field="iban_number" value="<?php echo $this->get_option('iban_number'); ?>" placeholder="<?php esc_html_e('KWK00445...', $this->domain); ?>" style="width: 400px;"/>
                                    </td>
                                    <td>
                                        <input type="number" name="woocommerce_upayments_knet_charge" data-field="knet_charge" value="<?php echo $this->get_option('knet_charge'); ?>" placeholder="<?php esc_html_e('0.000', $this->domain);?>" min="0.000" max="10.000" step="0.010"/>
                                    </td>
                                    <td>
                                        <select data-field="knet_charge_type" name="woocommerce_upayments_knet_charge_type">
                                            <option value=""><?php esc_html_e( 'Select', $this->domain ); ?></option>
                                            <?php foreach ( $conditions as $val => $label ) : ?>
                                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $this->get_option('knet_charge_type') ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="woocommerce_upayments_cc_charge" data-field="cc_charge" value="<?php echo $this->get_option('cc_charge'); ?>" placeholder="<?php esc_html_e('0.000', $this->domain); ?>" min="0.000" max="10.000" step="0.010"/>
                                    </td>
                                    <td>
                                        <select data-field="cc_charge_type" name="woocommerce_upayments_cc_charge_type">
                                            <option value=""><?php esc_html_e( 'Select', $this->domain ); ?></option>
                                            <?php foreach ( $conditions as $val => $label ) : ?>
                                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $val, $this->get_option('cc_charge_type') ); ?>>
                                                    <?php echo esc_html( $label ); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                </tr>
                            </tbody>                           
                        </table>
                    </div>
                    <input type="hidden" name="woocommerce_<?php echo esc_attr( $this->id ); ?>_<?php echo esc_attr( $key ); ?>"  id="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $settings ); ?>" />
                </td>
            </tr>
            <?php
            return ob_get_clean();
        }

        /**
         * Custom logic to sanitize and save the JSON data from the repeater field.
         * * @param string $value The raw POST value for the field.
         * @return string The sanitized and JSON-encoded string.
         */
        public function validate_multimerchant_repeater_field( $key, $value ) {
            // Decode the JSON string
            $rules = json_decode( stripslashes( $value ), true );
            
            if ( ! is_array( $rules ) ) {
                return '[]';
            }

            // Basic sanitation loop
            $sanitized_rules = [];
            foreach ( $rules as $rule ) {
                $sanitized_rules[] = array(
                    'iban_number'      => sanitize_text_field( $rule['iban_number'] ?? '' ),
                    'knet_charge'       => sanitize_text_field( $rule['knet_charge'] ?? '' ),
                    'knet_charge_type'           => wc_clean( $rule['knet_charge_type'] ?? '' ), 
                    'cc_charge'    => sanitize_text_field( $rule['cc_charge'] ?? '' ),
                    'cc_charge_type'    => wc_clean( $rule['cc_charge_type'] ?? '' ),
                );
            }

            // Re-encode the sanitized array back into a JSON string for storage
            return json_encode( $sanitized_rules );
        }

        public function getSiteName()
        {
            return __("Woocommerce", $this->domain);
        }

        public function getIsOrderComplete() {  
            $flag = true;   
            if ($this->isOrderComplete == 'no') { 
                $flag = false;  
            }   
            return $flag;   
        }

        public function getMode() {
            $mode = true;
            if ($this->testMode == 'no') {
                $mode = false;
            }
            return $mode;
        }
        
        public function getAPIUrl($apiRoute = "")
        {   
            $url = "https://apiv2api.upayments.com/api/v1/" . $apiRoute;
            if ($this->getMode()) {
                $url = "https://sandboxapi.upayments.com/api/v1/" . $apiRoute;
            }
            return $url;
        }

        public function getAPIUrlForCreateToken()
        {
            $url = "https://apiv2api.upayments.com/api/v1/create-customer-unique-token";
            if ($this->getMode()) {
                $url = "https://sandboxapi.upayments.com/api/v1/create-customer-unique-token";
            }
            return $url;
        }

        public function getAPIUrlForCheckPaymentButtonStatus() {
            $url = "https://apiv2api.upayments.com/api/v1/check-payment-button-status";
            if ($this->getMode()) {
                $url = "https://sandboxapi.upayments.com/api/v1/check-payment-button-status";
            }
            return $url;
        }

        public function getAPIUrlForRetreiveCards() {
            $url = "https://apiv2api.upayments.com/api/v1/retrieve-customer-cards";
            if ($this->getMode()) {
                $url = "https://sandboxapi.upayments.com/api/v1/retrieve-customer-cards";
            }
            return $url;
        }

        public function getUserAgent(){
            $userAgent = 'UpaymentsWoocommercePlugin/2.2.1';
            if ($this->getMode()) {
                $userAgent = 'SandboxUpaymentsWoocommercePlugin/2.2.1';
            }
            return $userAgent;
        }
        
        public function getCurrencyCode($code)
        {
            return $code;
        }

        public function encrypt($param)
        {
            return base64_encode($param);
        }

        public function decrypt($param)
        {
            return base64_decode($param);
        }

        public function getApiKey()
        {
            return password_hash($this->apiKey, PASSWORD_BCRYPT);
        }

        /**
         * Get customer unique token from phone.
         *
         * @deprecated Retained temporarily to avoid undefined-method breakage for
         *             third-party customizations. New code must use CustomerTokenIdentity.
         *             Future code-quality/public-API phase will decide final removal.
         * @param string $phone Unused. Previously used as customer token.
         * @return string Empty string. Phone is no longer used as token identity.
         */
        public function getCustomerUniqueToken($phone)
        {
            return '';
        }

        public function getUpayPaymentMethods()
        {
            $api_key = $this->apiKey;
            if (empty($api_key)) {
                return null;
            }

            // --- FAILURE SENTINEL (used for cached failures) ---
            $failure_sentinel = $this->get_failure_sentinel();

            // -------------------------------------------------------
            // STEP 1: Check credential-scoped result cache.
            // -------------------------------------------------------
            $cached = $this->get_cached_payment_methods();
            if ($cached !== null) {
                $cache_status = $this->is_valid_cached_availability($cached);
                if ($cache_status === 'success') {
                    return $cached;
                }
                if ($cache_status === 'failure') {
                    wc_clear_notices();
                    wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                // Malformed cache: treat as miss and fall through.
            }

            // -------------------------------------------------------
            // STEP 2: Cache miss — attempt to acquire advisory lock.
            // -------------------------------------------------------
            $lock_result = $this->acquire_payment_methods_lock();

            if ($lock_result !== 1) {
                // Lock NOT acquired (contention or error).
                // Re-check cache ONCE — another worker may have populated it.
                $cached = $this->get_cached_payment_methods();
                if ($cached !== null) {
                    $cache_status = $this->is_valid_cached_availability($cached);
                    if ($cache_status === 'success') {
                        return $cached;
                    }
                    if ($cache_status === 'failure') {
                        wc_clear_notices();
                        wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                }
                // No cache available and lock not acquired: fail closed.
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // -------------------------------------------------------
            // STEP 3: Lock acquired — check durable rate gate.
            // -------------------------------------------------------
            $gate = $this->get_payment_methods_rate_gate();
            $now = time();

            // Re-check cache under lock (another worker may have refreshed).
            $cached = $this->get_cached_payment_methods();
            if ($cached !== null) {
                $cache_status = $this->is_valid_cached_availability($cached);
                if ($cache_status === 'success') {
                    $this->release_payment_methods_lock();
                    return $cached;
                }
                if ($cache_status === 'failure') {
                    $this->release_payment_methods_lock();
                    wc_clear_notices();
                    wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                    return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                }
                // Malformed cache: DO NOT RELEASE lock.
                // Treat as cache miss while retaining ownership.
                // The durable gate check below will determine next action.
            }

            // Check if cooldown is still active.
            if ($now < $gate['not_before']) {
                // Gate still active: release lock, fail closed.
                $this->release_payment_methods_lock();
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // -------------------------------------------------------
            // STEP 4: Refresh authorized — persist gate BEFORE HTTP.
            // -------------------------------------------------------
            $new_not_before = $now + self::$RATE_GATE_COOLDOWN;
            $persisted = $this->set_payment_methods_rate_gate($new_not_before);

            if (!$persisted) {
                // Cannot persist durable gate: fail closed (do not risk double-call).
                $this->release_payment_methods_lock();
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Verify persistence.
            $verify_gate = $this->get_payment_methods_rate_gate();
            if ($verify_gate['not_before'] < $new_not_before) {
                // Persistence did not stick: fail closed.
                $this->release_payment_methods_lock();
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Release lock BEFORE outbound HTTP.
            $this->release_payment_methods_lock();

            // -------------------------------------------------------
            // STEP 5: Execute hardened provider request.
            // -------------------------------------------------------
            $transport = $this->execute_upayments_request('check-payment-button-status', 'GET');

            // Section AH: Consolidated transport guard (no unsafe first dereference).
            if (!is_array($transport)
                || !isset($transport['transport_ok']) || $transport['transport_ok'] !== true
                || !isset($transport['http_status']) || (int) $transport['http_status'] !== 201
                || !isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0
                || !isset($transport['body']) || !is_scalar($transport['body'])
                || (string) $transport['body'] === ''
            ) {
                $this->set_cached_payment_methods($failure_sentinel, $new_not_before);
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            $result = json_decode((string) $transport['body'], true);
            if (!is_array($result)) {
                $this->set_cached_payment_methods($failure_sentinel, $new_not_before);
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section O: Strict status === true check. Do NOT accept '1', 1, 'true', etc.
            if (!array_key_exists('status', $result) || $result['status'] !== true) {
                $this->set_cached_payment_methods($failure_sentinel, $new_not_before);
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            if (!isset($result['data']) || !is_array($result['data'])) {
                $this->set_cached_payment_methods($failure_sentinel, $new_not_before);
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section AC: Normalize availability payload to canonical schema.
            $payment_methods = $result['data'];

            if (!array_key_exists('isWhiteLabel', $payment_methods)) {
                $this->set_cached_payment_methods($failure_sentinel, $new_not_before);
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            $wl = $payment_methods['isWhiteLabel'];
            if ($wl === true || $wl === 1 || $wl === '1') {
                $normalized_wl = true;
            } elseif ($wl === false || $wl === 0 || $wl === '0') {
                $normalized_wl = false;
            } else {
                $this->set_cached_payment_methods($failure_sentinel, $new_not_before);
                wc_clear_notices();
                wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
            }

            // Section Y: Build canonical payButtons with all six known keys.
            $known_buttons = array('knet', 'credit_card', 'apple_pay_knet', 'apple_pay', 'samsung_pay', 'google_pay');
            $normalized_buttons = array();
            $raw_buttons = isset($payment_methods['payButtons']) && is_array($payment_methods['payButtons'])
                ? $payment_methods['payButtons']
                : array();

            foreach ($known_buttons as $btn) {
                if (array_key_exists($btn, $raw_buttons)) {
                    $bv = $raw_buttons[$btn];
                    if ($bv === true || $bv === 1 || $bv === '1') {
                        $normalized_buttons[$btn] = 1;
                    } elseif ($bv === false || $bv === 0 || $bv === '0') {
                        $normalized_buttons[$btn] = 0;
                    } else {
                        $this->set_cached_payment_methods($failure_sentinel, $new_not_before);
                        wc_clear_notices();
                        wc_add_notice(__("Payment methods could not be loaded. Please try again.", $this->domain), "error");
                        return array('result' => 'failure', 'redirect' => wc_get_checkout_url());
                    }
                } else {
                    $normalized_buttons[$btn] = 0;
                }
            }

            // Cache only canonical schema (Section Y).
            $canonical_cache = array(
                'schema'       => 3,
                'result'       => 'success',
                'isWhiteLabel' => $normalized_wl,
                'payButtons'   => $normalized_buttons,
            );

            // Expose to getPaymentIcons with full structure.
            $payment_methods['result'] = 'success';
            $payment_methods['isWhiteLabel'] = $normalized_wl;
            $payment_methods['payButtons'] = $normalized_buttons;
            // Cache canonical schema (Section Y).
            $this->set_cached_payment_methods($canonical_cache, $new_not_before);
            return $payment_methods;
        }

        public function getSavedCards($customer_token)
        {
            $api_key = $this->apiKey;
            if (empty($api_key) || !is_scalar($customer_token) || (string) $customer_token === '') {
                return null;
            }

            // Strict request input: must be ASCII numeric, 8-18 digits.
            $token_str = (string) $customer_token;
            if (!preg_match('/^[0-9]{8,18}$/', $token_str)) {
                return null;
            }

            $params = wp_json_encode(array("customerUniqueToken" => $token_str));
            $transport = $this->execute_upayments_request('retrieve-customer-cards', 'POST', $params);

            if (!is_array($transport)) {
                return null;
            }

            if (!isset($transport['transport_ok']) || !$transport['transport_ok']) {
                return null;
            }

            if (!isset($transport['http_status']) || (int) $transport['http_status'] !== 201) {
                return null;
            }

            if (!isset($transport['curl_errno']) || (int) $transport['curl_errno'] !== 0) {
                return null;
            }

            if (!isset($transport['body']) || !is_scalar($transport['body'])) {
                return null;
            }

            $result = json_decode((string) $transport['body'], true);
            if (!is_array($result)) {
                return null;
            }

            if (!array_key_exists('status', $result) || $result['status'] !== true) {
                return null;
            }

            if (!isset($result['data']) || !is_array($result['data'])) {
                return null;
            }

            if (!array_key_exists('customerCards', $result['data']) || !is_array($result['data']['customerCards'])) {
                return null;
            }

            return array(
                'data' => $result['data']['customerCards'],
                'result' => 'success',
            );
        }

        /**
         * Section AH: Defense in depth — require normalized payment state.
         */
        public function getSavedCardsForCurrentUser($payment_data = null)
        {
            $user_id = get_current_user_id();
            if ($user_id <= 0) {
                return null;
            }

            if ($this->saveCardEnabled !== 'yes') {
                return null;
            }

            // Section AH: Require normalized payment state.
            if ($payment_data === null) {
                $payment_data = $this->getPaymentIcons();
            }
            if (!is_array($payment_data)
                || !isset($payment_data['whitelabled'])
                || $payment_data['whitelabled'] !== true
                || !isset($payment_data['payment'])
                || !is_array($payment_data['payment'])
                || !isset($payment_data['payment']['cc'])
            ) {
                return null;
            }

            $gateway = $this;
            return CustomerTokenIdentity::get_saved_cards_for_current_user(
                $user_id,
                $this->apiKey,
                $this->getMode(),
                function($token) use ($gateway) {
                    return $gateway->getSavedCards($token);
                }
            );
        }

        /**
         * UTF-8 safe provider text truncation.
         * PHP 7.2 compatible, no mandatory mbstring dependency.
         */
        private function truncate_provider_text($value, $max_chars) {
            if (!is_scalar($value)) {
                return '';
            }
            $str = (string) $value;
            if ($str === '') {
                return '';
            }
            // Remove invalid UTF-8 sequences using PCRE (always available).
            $str = preg_replace('/[\x00-\x7F][\x80-\xBF]+/u', '', $str);
            $str = preg_replace('/[\xC0-\xDF](?![\x80-\xBF])/u', '', $str);
            $str = preg_replace('/[\xE0-\xEF](?![\x80-\xBF]{2})/u', '', $str);
            $str = preg_replace('/[\xF0-\xF7](?![\x80-\xBF]{3})/u', '', $str);
            if ($str === '' || $str === null) {
                return '';
            }
            // Fast path: mbstring available.
            if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                if (mb_strlen($str, 'UTF-8') <= $max_chars) {
                    return $str;
                }
                return mb_substr($str, 0, $max_chars, 'UTF-8');
            }
            // PCRE fallback: count code points, then safely extract.
            $matches = array();
            if (preg_match_all('/./us', $str, $matches) === false) {
                return '';
            }
            $chars = $matches[0];
            if (count($chars) <= $max_chars) {
                return $str;
            }
            return implode('', array_slice($chars, 0, $max_chars));
        }

        public function getPaymentIcons()
        {
            $data = $this->getUpayPaymentMethods();

            // Fail safely if upstream did not return a usable success payload.
            if (!is_array($data)
                || !isset($data['result'])
                || $data['result'] !== 'success') {
                return;
            }

            // Admin toggle (feature on/off)
            $isSubscriptionFeatureEnabled = ($this->autoDeduction === 'yes');

            // Cart state
            $hasSubscriptionProduct = \UPayments\Subscription\Helpers\Utils::cartHasCustomType();
            $hasNormalProduct      = \UPayments\Subscription\Helpers\Utils::cartHasNormalProduct();

            // Subscription context = feature enabled AND subscription product in cart
            $isSubscriptionContext = $isSubscriptionFeatureEnabled && $hasSubscriptionProduct && !$hasNormalProduct;

            $payment_methods = isset($data['payButtons']) && is_array($data['payButtons'])
                ? $data['payButtons']
                : array();

            $whitelabled = isset($data['isWhiteLabel']) && $data['isWhiteLabel'] === true;
            $methods     = [];

            // Section P: Non-Whitelabel generic checkout must always be available.
            $methods['payment'] = array();

            // If ONLY normal products in cart → allow all methods
            if (!$isSubscriptionContext) {
                if (isset($payment_methods['knet']) && $payment_methods['knet'] === 1) {
                    $methods['payment']['knet'] = __('KNET', $this->domain);
                }

                if (isset($payment_methods['apple_pay_knet']) && $payment_methods['apple_pay_knet'] === 1) {
                    $methods['payment']['apple-pay-knet'] = __('Apple Pay KNET', $this->domain);
                }

                if (isset($payment_methods['credit_card']) && $payment_methods['credit_card'] === 1) {
                    $methods['payment']['cc'] = __('Credit Card', $this->domain);
                }

                if (isset($payment_methods['apple_pay']) && $payment_methods['apple_pay'] === 1) {
                    $methods['payment']['apple-pay'] = __('Apple Pay Credit Card', $this->domain);
                }

                if (isset($payment_methods['samsung_pay']) && $payment_methods['samsung_pay'] === 1) {
                    $methods['payment']['samsung-pay'] = __('Samsung Pay', $this->domain);
                }

                if (isset($payment_methods['google_pay']) && $payment_methods['google_pay'] === 1) {
                    $methods['payment']['google-pay'] = __('Google Pay', $this->domain);
                }
            } else { // If subscription product in cart → ONLY CC allowed (per API requirement)
                if (isset($payment_methods['credit_card']) && $payment_methods['credit_card'] === 1) {
                    $methods['payment']['cc'] = __('Credit Card', $this->domain);
                }
            }

            $methods['whitelabled'] = $whitelabled;
            return $methods;
        }

        public function log($content, $level = 'debug')
        {
            // Diagnostic logging is explicitly opt-in.
            // WooCommerce checkbox values resolve to the string 'yes' or 'no';
            // the string 'no' is truthy in PHP, so a loose check enables logging
            // even when the merchant intends Debug = disabled.
            if ($this->debug !== 'yes') {
                return;
            }

            if (!function_exists('wc_get_logger')) {
                return;
            }

            $allowed_levels = array('debug', 'info', 'notice', 'warning', 'error');
            if (!in_array($level, $allowed_levels, true)) {
                $level = 'debug';
            }

            if (is_array($content) || is_object($content)) {
                $content = '[complex diagnostic data omitted]';
            }

            wc_get_logger()->{$level}(
                (string) $content,
                array('source' => 'upayments')
            );
        }
        
        /**
         * initializeSubscriptionModule
         * Handle Subscription Module Initialization If Enabled from Admin Settings
         * @return void
         */
        public function initializeSubscriptionModule()
        {
            // Always load classes (they self-check enable flag)
            require_once __DIR__ . '/includes/Subscription/Checkout/Fields.php';
            require_once __DIR__ . '/includes/Subscription/Manager.php';
            require_once __DIR__ . '/includes/Subscription/Helpers/Utils.php';            
            Fields::init();
            Manager::init();
        }

        /**
         * Build API payload for invoice / subscription
         *
         * @param WC_Order $order
         * @return array
         */
        protected function build_api_payload($order)
        {
            $payload = [
                'order_id' => $order->get_id(),
                'amount'   => $order->get_total(),
                'currency' => $order->get_currency(),
                'customer' => [
                    'email' => $order->get_billing_email(),
                    'name'  => $order->get_formatted_billing_full_name(),
                ],
            ];

            $plan = $order->get_meta('_upay_subscription_plan');

            if ($plan && $plan !== 'one_time') {

                $interval = (int) $order->get_meta('_upay_subscription_interval');

                $payload['subscription'] = [
                    'enabled'            => true,
                    'type'               => 'recurring',
                    'plan'               => $plan,
                    'interval'           => $interval,
                    'period'             => $plan === 'yearly' ? 'year' : 'month',
                    'start_immediately'  => true,
                ];
            }

            return $payload;
        }

        /**
         * Render subscription summary in admin order view
         *
         * @param WC_Order $order
         * @return array
         */
        public function render_subscription_summary($order)
        {
            $plan     = $order->get_meta('_upay_subscription_plan');
            $interval = (int) $order->get_meta('_upay_subscription_interval');
            $autoDeduction = $order->get_meta('UPayments_AutoDeduction');
            $lastBilled = $order->get_meta('_upay_last_billed_at');
            $order_date = $order->get_date_created();
            $order_paid_date = $order->get_date_paid();
            $order_completed_date = $order->get_date_completed();
            
            $started_at = $order_paid_date ?: $order_completed_date ?: $order_date;
            if (!$started_at) {
                return;
            }
            if (is_string($started_at)) {
                $started_at = new DateTime($started_at);
            }

            // Dates
            $timezone = wp_timezone();
            
            $last_billed_dt = !empty($lastBilled) ? new DateTime($lastBilled, $timezone) : null;
            
            // Calculate next billing
            $next_billing_dt = Scheduler::getNextBillingDate($started_at, $plan, $interval);
            if (!$next_billing_dt) {
                return;
            }

            $SubscriptionStatus = $order->get_meta('_upay_subscription_status');
            if($SubscriptionStatus === 'active') {
                $SubscriptionStatus = '<span class="upay-status-active">'. ucfirst($SubscriptionStatus) .'</span>';
            } elseif($SubscriptionStatus === 'paused') {
                $SubscriptionStatus = '<span class="upay-status-paused">'. ucfirst($SubscriptionStatus) .'</span>';
                } elseif($SubscriptionStatus === 'cancelled') {
                $SubscriptionStatus = '<span class="upay-status-cancelled">'. ucfirst($SubscriptionStatus) .'</span>';
            } else {
                $SubscriptionStatus = ucfirst($SubscriptionStatus);
            }

            if (!$plan || $plan === 'one_time') {
                return;
            }

            $period = '';
            if ($plan === 'yearly') {
                $period = 'Year';
            } elseif($plan === 'monthly') {
                $period = 'Month';
            } elseif($plan === 'weekly') {
                $period = 'Week';
            } else {
                $period = 'Day';
            }

            echo '<div class="upay-subscription-summary">';
            echo '<h4>' . esc_html__('Subscription Details', 'upayments') . '</h4>';
            if($autoDeduction === 'no'){
                echo '<p><strong>Subscription Status:</strong> ' . wp_kses_post($SubscriptionStatus) . '</p>';
            }
            echo '<p><strong>Plan:</strong> ' . esc_html(ucfirst($plan)) . '</p>';
            echo '<p><strong>Interval:</strong> Every ' . esc_html($interval) . ' ' . esc_html($period) . '(s)</p>';
            if($autoDeduction === 'yes' && empty($last_billed_dt)) {
                echo '<p><strong>Auto Deduction Order:</strong> Yes</p>';
            } else {
                if($SubscriptionStatus !== 'cancelled') {
                    echo '<p><strong>Next Billing Date:</strong> ' . esc_html($next_billing_dt->format('Y-m-d H:i:s')) . '</p>';
                }
                if(!empty($last_billed_dt)){ 
                    echo '<p><strong>Last Billed at:</strong> ' . esc_html($last_billed_dt->format('Y-m-d H:i:s')) . '</p>';
                }
            }
            echo '</div>';
        }

        /**
         * restrictMixedCartProducts
         * Function to restrict adding subscription products together with normal products in the cart
         * @param  mixed $passed
         * @param  mixed $product_id
         * @param  mixed $quantity
         * @return void
         */
        public function restrictMixedCartProducts($passed, $product_id, $quantity)
        {
            if (!function_exists('WC') || !WC()->cart) {
                return $passed;
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                return $passed;
            }

            $is_subscription_product = ($product->get_type() === 'custom_type');

            // Current cart state
            $cart_has_subscription = \UPayments\Subscription\Helpers\Utils::cartHasCustomType();
            $cart_has_normal       = \UPayments\Subscription\Helpers\Utils::cartHasNormalProduct();

            // If cart already has subscription product, block adding normal products
            if ($cart_has_subscription && !$is_subscription_product) {
                wc_add_notice(
                    __('You can only add subscription products to the cart when a subscription item is present.', $this->domain),
                    'error'
                );
                return false;
            }

            // If cart already has normal products, block adding subscription products
            if ($cart_has_normal && $is_subscription_product) {
                wc_add_notice(
                    __('Subscription products cannot be added together with normal products. Please complete your current purchase first.', $this->domain),
                    'error'
                );
                return false;
            }

            return $passed;
        }
        
        /**
         * renderSubscriptionBadgeInProductList
         * Function to render subscription badge in product list if the product is subscription type
         * @return void
         */
        public function renderSubscriptionBadgeInProductList()
        {
            global $product;

            if (!$product instanceof WC_Product) {
                return;
            }

            if ($product->get_type() !== 'custom_type') {
                return;
            }

            echo '<span class="upay-subscription-badge"><strong>🔁 Subscription</strong></span>';
        }
    }

    add_action(
        'woocommerce_admin_order_data_after_billing_address',
        function($order) {
            foreach ($order->get_items('line_item') as $item)
            {
                $product = $item->get_product();
                if($product->get_type() === 'custom_type'){
                    $gateway = new WC_Upayments();
                    $gateway->render_subscription_summary($order);
                }
            }
        },
        10, 1
    );
}

/**
 * upaymentsMissingWcNotice
 * If Woocommerce Plugin is not active/installed show admin notice to install/activate Woocommerce
 * @return void
 */
function upaymentsMissingWcNotice() {
    ?>
    <div class="error notice">
        <p><?php _e( '<b>UPayments Gateway</b> requires WooCommerce to be installed and active!', 'upayments' ); ?></p>
    </div>
    <?php
}

add_filter("woocommerce_payment_gateways", "addUpaymentsGatewayClass");
function addUpaymentsGatewayClass($methods)
{
    $methods[] = "WC_UPayments";
    return $methods;
}

add_filter("woocommerce_available_payment_gateways", "enableUpaymentsGateway");
function enableUpaymentsGateway($available_gateways)
{
    if (is_admin()){
        return $available_gateways;
    }

    if (isset($available_gateways["upayments"])){
        // Move UPayments to the end unless merchant explicitly reordered
        $upay = $available_gateways['upayments'];
        unset($available_gateways['upayments']);
        $available_gateways['upayments'] = $upay;

        $settings = get_option("woocommerce_upayments_settings");

        if (empty($settings["api_key"])){
            unset($available_gateways["upayments"]);
        }

        if (is_checkout() && isset($available_gateways['cod']) && (isset($settings['enable_autodeduction']) && $settings['enable_autodeduction'] === 'yes')) {
            unset($available_gateways['cod']);
        }

        if (WC()->session->get('chosen_payment_method') === 'upayments' && (isset($settings['make_default_gateway']) && $settings['make_default_gateway'] !== 'yes')) {
            WC()->session->set('chosen_payment_method', null);
        }
    }

    $supported_currencies = ["KWD", "SAR", "USD", "BHD", "EUR", "OMR", "QAR", "AED", ];
    if (!in_array(get_woocommerce_currency() , $supported_currencies)){
        unset($available_gateways["upayments"]);
    }
    
    return $available_gateways;
}

add_action('admin_head', function () {
    ?>
    <style>
        /* hide the entire row if input is hidden */
        .woocommerce table.form-table tr:has(input[style*="display:none"]) {
            display: none;
        }
        .upay-status-active { color: #2ecc71; font-weight: 600; }
        .upay-status-paused { color: #f39c12; font-weight: 600; }
        .upay-status-cancelled { color: #e74c3c; font-weight: 600; }
    </style>
    <?php
});

// Declare compatibility with WooCommerce's Cart & Checkout blocks (WooBlocks)
add_action( 'before_woocommerce_init', function() {
    if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            __FILE__,
            true
        );
    }
});

// payment method registry
add_action( 'woocommerce_blocks_loaded', function() {
    add_action( 'woocommerce_blocks_payment_method_type_registration', function( $payment_method_registry ) {
        require_once __DIR__ . '/includes/class-wc-gateway-upayments-blocks.php';
        $payment_method_registry->register(
            new WCGatewayUPaymentsBlocks( __FILE__ )
        );
    });
});

register_activation_hook(__FILE__, 'myPaymentPluginSetupCheckout');
function myPaymentPluginSetupCheckout() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_notices', 'upaymentsMissingWcNotice' );
        return;
    }
    $checkout_page_id = wc_get_page_id('checkout');
    if (!$checkout_page_id) {
        return;
    }

    $post = get_post($checkout_page_id);
    if (!$post) {
        return;
    }

    $has_shortcode = has_shortcode($post->post_content, 'woocommerce_checkout');
    $has_block     = has_block('woocommerce/checkout', $post->post_content);

    $use_blocks = false;
    $settings = get_option("woocommerce_upayments_settings");
    if (isset($settings['enable_block_checkout']) && $settings['enable_block_checkout'] === 'yes') {
        $use_blocks = true;
    }

    if (!$has_shortcode && !$has_block && !$use_blocks) {
        wp_update_post([
            'ID'           => $checkout_page_id,
            'post_content' => '[woocommerce_checkout]', // default: classic
        ]);
    }
}

/* Subscription Product Data Handler from product Data Page - Start */
add_action( 'init', function () {
    if ( ! class_exists( 'WooCommerce' ) ) {
        return;
    }
    if ( class_exists( 'WC_Product_Simple' ) ) {
        class WCProductCustomType extends WC_Product_Simple {
            public function get_type() {
                return 'custom_type';
            }
        }
    }
});

add_filter( 'product_type_selector', 'addCustomProductType' );
function addCustomProductType( $types ){
    $types[ 'custom_type' ] = __( 'Subscription Product', 'upayments' );
    return $types;
}

add_filter( 'woocommerce_product_class', 'mapCustomProductClass', 10, 2 );
function mapCustomProductClass( $classname, $product_type ) {
    if ( $product_type === 'custom_type' ) { // Must match the key in your dropdown
        $classname = 'WCProductCustomType';
    }
    return $classname;
}

add_action( 'woocommerce_custom_type_add_to_cart', 'woocommerce_simple_add_to_cart', 30 );

add_action( 'admin_footer', 'customProductTypes' );
function customProductTypes() {
    if ( 'product' != get_post_type() ) { return ;}
    ?>
    <script type='text/javascript'>
        jQuery( document ).ready( function() {
            // Options like 'virtual' or 'downloadable' can be shown/hidden
            // or specific tabs can be toggled.
            jQuery( '.options_group.pricing' ).addClass( 'show_if_custom_type' );
            jQuery( '.inventory_options' ).addClass( 'show_if_custom_type' );
            
            // Force WooCommerce to trigger the show/hide logic
            jQuery( 'select#product-type' ).change();
            jQuery('.show_if_simple').addClass('show_if_custom_type');
        });
    </script>
    <?php
}

add_filter( 'woocommerce_product_data_tabs', 'addCustomDataTab' );
function addCustomDataTab( $tabs ) {
    $tabs['custom_settings'] = array(
        'label'    => __( 'Custom Settings', 'upayments' ),
        'target'   => 'custom_product_data_panel', // This matches the ID in the next step
        'class'    => array( 'show_if_custom_type' ), // Only show for your product type
        'priority' => 25,
    );
    return $tabs;
}

add_action( 'woocommerce_product_data_panels', 'addCustomDataPanel' );
function addCustomDataPanel() {
    ?>
    <div id="custom_product_data_panel" class="panel woocommerce_options_panel hidden">
        <div class="options_group">
            <?php
            // Create a custom text field
            woocommerce_wp_text_input( array(
                'id'          => '_custom_field_id',
                'label'       => __( 'Custom Field', 'upayments' ),
                'placeholder' => 'Enter value here',
                'desc_tip'    => 'true',
                'description' => __( 'This is a description of the field.', 'upayments' ),
            ) );
            ?>
        </div>
    </div>
    <?php
}

add_action( 'woocommerce_process_product_meta', 'saveCustomFieldData' );
function saveCustomFieldData( $post_id ) {
    $custom_field_value = isset( $_POST['_custom_field_id'] ) ? $_POST['_custom_field_id'] : '';
    
    if ( ! empty( $custom_field_value ) ) {
        update_post_meta( $post_id, '_custom_field_id', sanitize_text_field( $custom_field_value ) );
    }
}

add_action( 'woocommerce_single_product_summary', 'displayCustomFieldOnFrontend', 10 );
function displayCustomFieldOnFrontend() {
    global $product;

    // 1. Check if the product exists and is your custom type
    if ( ! is_object( $product ) || ! $product->is_type( 'custom_type' ) ) {
        return;
    }

    if ( $product->is_type( 'custom_type' ) ) {
        // 2. Fetch the data using the field ID we used during the save process
        $custom_data = get_post_meta( $product->get_id(), '_custom_field_id', true );
    
        // 3. Output the data safely
        if ( ! empty( $custom_data ) ) {
            echo '<div class="custom-product-info">';
            echo '<strong style="background: #ffcc00; padding: 5px 10px; border-radius: 3px;">' . esc_html( $custom_data ) . '</strong>';
            echo '</div>';
        }
    }

}

add_filter( 'woocommerce_get_item_data', 'displayCustomDataInCart', 10, 2 );
function displayCustomDataInCart( $item_data, $cart_item ) {
    // 1. Get the product ID from the cart item
    $product_id = $cart_item['product_id'];
    
    // 2. Fetch the custom meta
    $custom_value = get_post_meta( $product_id, '_custom_field_id', true );

    // 3. Add it to the display array if it exists
    if ( ! empty( $custom_value ) ) {
        $item_data[] = array(
            'key'     => __( 'Special Feature', 'upayments' ),
            'value'   => $custom_value,
            'display' => '', // Optional: format for display
        );
    }

    return $item_data;
}

add_action( 'woocommerce_checkout_create_order_line_item', 'saveCustomDataToOrderItems', 10, 4 );
function saveCustomDataToOrderItems( $item, $cart_item_key, $values, $order ) {
    // 1. Get the product ID
    $product_id = $values['product_id'];
    
    // 2. Fetch the custom meta from the product
    $custom_value = get_post_meta( $product_id, '_custom_field_id', true );

    // 3. Add the meta to the order item
    if ( ! empty( $custom_value ) ) {
        $item->add_meta_data( __( 'Special Feature', 'upayments' ), $custom_value );
    }
}

add_action('woocommerce_order_details_after_order_table', function ($order) {

    if (!$order instanceof WC_Order || !is_user_logged_in()) {
        return;
    }

    if ((int) $order->get_user_id() !== get_current_user_id()) {
        return;
    }

    if ($order->get_meta('_upay_subscription_status') === 'cancelled') {
        return;
    }

    // Subscription meta
    $plan       = $order->get_meta('_upay_subscription_plan');
    $interval   = (int) $order->get_meta('_upay_subscription_interval');
    if (!$plan) {return;}
    
    $order_date = $order->get_date_created();
    $order_paid_date = $order->get_date_paid();
    $order_completed_date = $order->get_date_completed();
    $last_billed = $order->get_meta('_upay_last_billed_at');
    $isAutoDeduction = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
    $started_at = $order_paid_date ?: $order_completed_date ?: $order_date;
    if (!$started_at) {
        return;
    }
    if (is_string($started_at)) {
        $started_at = new DateTime($started_at);
    }
    
    // Dates
    $timezone = wp_timezone();
    
    $last_billed_dt = !empty($last_billed) ? new DateTime($last_billed, $timezone) : null;
        
    // Calculate next billing
    $next_billing_dt = Scheduler::getNextBillingDate($started_at, $plan, $interval);
    if (!$next_billing_dt) {
        return;
    }

    // Not a subscription order → don’t show anything
    if (!$plan || !$interval) {
        return;
    }

    // Format labels
    $plan_labels = [
        'daily'     => 'Daily',
        'weekly'    => 'Weekly',
        'monthly'   => 'Monthly',
        'quarterly' => 'Quarterly',
        'yearly'    => 'Yearly',
    ];

    $interval_labels = [
        'daily' => [
            1 => 'Every Day',
        ],
        'weekly' => [
            1 => 'Every Week',
            2 => 'Every 2 Weeks',
            3 => 'Every 3 Weeks',
        ],
        'monthly' => [
            1 => 'Every Month',
            2 => 'Every 2 Months',
        ],
        'quarterly' => [
            1 => 'Every Quarter',
            2 => 'Every 2 Quarters',
            3 => 'Every 3 Quarters',
        ],
        'yearly' => [
            1 => 'Every Year',
        ],
    ];
    ?>

    <section class="woocommerce-subscription-details">
        <h2><?php esc_html_e('Subscription Details', 'woocommerce'); ?></h2>

        <table class="shop_table shop_table_responsive" style="border: 1px solid;">
            <tbody>
                <tr>
                    <th style="border: 1px solid;"><?php esc_html_e('Plan', 'woocommerce'); ?></th>
                    <td style="border: 1px solid;"><?php echo esc_html($plan_labels[$plan] ?? ucfirst($plan)); ?></td>
                </tr>
                <tr>
                    <th style="border: 1px solid;"><?php esc_html_e('Interval', 'woocommerce'); ?></th>
                    <td style="border: 1px solid;"><?php echo esc_html($interval_labels[$plan][$interval] ?? $interval); ?></td>
                </tr>
                <tr>
                    <th style="border: 1px solid;"><?php esc_html_e('Started On', 'woocommerce'); ?></th>
                    <td style="border: 1px solid;"><?php echo esc_html($started_at ? $started_at->format('Y-m-d H:i:s') : '-'); ?></td>
                </tr>
                <?php if(!$isAutoDeduction) { ?>
                    <tr>
                        <th style="border: 1px solid;"><?php esc_html_e('Last Billed On', 'woocommerce'); ?></th>
                        <td style="border: 1px solid;"><?php echo esc_html($last_billed_dt ? $last_billed_dt->format('Y-m-d H:i:s') : '-'); ?></td>
                    </tr>
                    <tr>
                        <th style="border: 1px solid;"><?php esc_html_e('Next Billing Date', 'woocommerce'); ?></th>
                        <td style="border: 1px solid;"><?php echo esc_html($next_billing_dt ? $next_billing_dt->format('Y-m-d H:i:s') : '-'); ?></td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </section>

    <?php
        $isAutoDeductionOrder = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
        if (!$isAutoDeductionOrder) {
            $unsubscribe_url = wp_nonce_url(
                add_query_arg([
                    'upay_action' => 'unsubscribe',
                    'order_id'    => $order->get_id(),
                ], wc_get_account_endpoint_url('view-order')),
                'upay_unsubscribe_' . $order->get_id()
            );
    ?>
    <p class="upay-subscription-actions">
        <a href="<?php echo esc_url($unsubscribe_url); ?>"
            class="button upay-unsubscribe-button"
            onclick="return confirm('<?php esc_attr_e('Are you sure you want to unsubscribe?', 'woocommerce'); ?>');">
            <?php esc_html_e('Unsubscribe', 'woocommerce'); ?>
        </a>
    </p>

    <?php
            $status = $order->get_meta('_upay_subscription_status') ?: 'active';
            $action = $status === 'paused' ? 'resume' : 'pause';
            $label  = $status === 'paused' ? 'Resume Subscription' : 'Pause Subscription';

            $url = wp_nonce_url(
                add_query_arg([
                    'upay_action' => $action,
                    'order_id'    => $order->get_id(),
                ], wc_get_account_endpoint_url('view-order')),
                'upay_' . $action . '_' . $order->get_id()
            );
    ?>
    <p class="upay-subscription-actions">
        <a href="<?php echo esc_url($url); ?>" class="button upay-pause-resume-button">
            <?php echo esc_html($label); ?>
        </a>
    </p>
    <?php
    }
});

add_action('woocommerce_before_account_orders', function () {
    $current = sanitize_text_field($_GET['subscription_filter'] ?? '');
    ?>
    <form method="get" class="upay-orders-filter" action="<?php echo esc_url( add_query_arg( null, null ) ); ?>">
        <input type="hidden" name="page_id" value="<?php echo esc_attr($_GET['page_id'] ?? 12); ?>">
        <input type="hidden" name="orders" value="">

        <label for="subscription_filter">Select Order Type:</label>
        <select id="subscription_filter" name="subscription_filter" onchange="this.form.submit()">
            <option value="">All orders</option>
            <option value="active" <?php selected($current, 'active'); ?>>Active subscriptions</option>
            <option value="paused" <?php selected($current, 'paused'); ?>>Paused subscriptions</option>
            <option value="cancelled" <?php selected($current, 'cancelled'); ?>>Cancelled subscriptions</option>
        </select>
    </form>
    <?php
});

add_filter('woocommerce_my_account_my_orders_query', function ($args) {
    if (empty($_GET['subscription_filter'])) {
        return $args;
    }
    $filter = sanitize_text_field($_GET['subscription_filter']);
    $args['meta_query'][] = [
        'key'   => '_upay_subscription_status',
        'value' => $filter,
    ];
    return $args;
});

add_filter('woocommerce_my_account_my_orders_columns', function ($columns) {
    $new_columns = [];
    foreach ($columns as $key => $label) {
        $new_columns[$key] = $label;

        if ($key === 'order-status') {
            $new_columns['order_type'] = __('Type', 'woocommerce');
            $new_columns['order_status'] = __('Status', 'woocommerce');
        }
    }
    return $new_columns;
});

add_action('woocommerce_my_account_my_orders_column_order_type', function ($order) {
    $isAutoDeduction = $order->get_meta('UPayments_AutoDeduction') === 'yes' ? true : false;
    echo $isAutoDeduction ? __('Auto Deduction', 'woocommerce') : __('Regular', 'woocommerce');
});

add_action('woocommerce_my_account_my_orders_column_order_status', function ($order) {
    $status = $order->get_meta('_upay_subscription_status');
    if (!$status) {
        echo '—';
        return;
    }
    echo '<span class="upay-status upay-status-' . esc_attr($status) . '">' . esc_html(ucfirst($status)) . '</span>';
});
/* Subscription Product Data Handler from product Data Page - End */

add_action('woocommerce_init', function () {
    require_once __DIR__ . '/includes/Subscription/Cron/Scheduler.php';
    Scheduler::init();
});

add_action('init', function () {
    $action = isset($_GET['upay_action'])
        ? sanitize_key(wp_unslash($_GET['upay_action']))
        : '';

    $order_id = isset($_GET['order_id'])
        ? absint(wp_unslash($_GET['order_id']))
        : 0;

    if (empty($action) || empty($order_id)) {
        return;
    }

    $allowed_actions = array('unsubscribe', 'pause', 'resume');
    if (!in_array($action, $allowed_actions, true)) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Authorization: nonce is CSRF protection, not authorization.
    if (!is_user_logged_in() || get_current_user_id() !== $order->get_user_id()) {
        wc_add_notice(__('Unauthorized request.', 'woocommerce'), 'error');
        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }

    // Nonce verification: required for every state-changing action.
    $nonce = isset($_GET['_wpnonce'])
        ? sanitize_text_field(wp_unslash($_GET['_wpnonce']))
        : '';

    if ($action === 'unsubscribe') {
        $nonce_action = 'upay_unsubscribe_' . $order_id;
    } else {
        $nonce_action = 'upay_' . $action . '_' . $order_id;
    }

    if (empty($nonce) || !wp_verify_nonce($nonce, $nonce_action)) {
        wc_add_notice(__('Invalid request.', 'woocommerce'), 'error');
        wp_safe_redirect(wc_get_account_endpoint_url('orders'));
        exit;
    }

    if ($action === 'unsubscribe') {
        $order->update_meta_data('_upay_subscription_status', 'cancelled');
        wc_add_notice(__('Your subscription has been cancelled.', 'woocommerce'), 'success');
    } elseif ($action === 'pause') {
        $order->update_meta_data('_upay_subscription_status', 'paused');
        wc_add_notice(__('Subscription paused.', 'woocommerce'), 'success');
    } elseif ($action === 'resume') {
        $order->update_meta_data('_upay_subscription_status', 'active');
        wc_add_notice(__('Subscription resumed.', 'woocommerce'), 'success');
    }

    $order->save();
    wp_safe_redirect(wc_get_account_endpoint_url('view-order') . $order_id);
    exit;
});
