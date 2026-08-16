<?php

namespace UPayments\Subscription\Checkout;

use UPayments\Subscription\Helpers\Utils;

defined('ABSPATH') || exit;

class Fields
{
    private static $ALLOWED_PLANS = array(
        'one_time',
        'daily',
        'weekly',
        'monthly',
        'quarterly',
        'yearly',
    );

    private static $ALLOWED_INTERVALS = array(
        'one_time'  => array(0),
        'daily'     => array(1),
        'weekly'    => array(1, 2, 3),
        'monthly'   => array(1, 2),
        'quarterly' => array(1, 2, 3),
        'yearly'    => array(1),
    );

    public static function init()
    {
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate']);
        add_filter('woocommerce_checkout_fields', [__CLASS__, 'add']);
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save'], 20, 1);
    }

    public static function validate()
    {
        if (!self::is_subscription_context()) {
            return;
        }

        $plan = '';
        if (isset($_POST['upay_subscription_plan']) && is_scalar($_POST['upay_subscription_plan'])) {
            $plan = sanitize_text_field(wp_unslash($_POST['upay_subscription_plan']));
        }

        if ($plan === '') {
            wc_add_notice(__('Please select a payment type.', 'upayments'), 'error');
            return;
        }

        if (!in_array($plan, self::$ALLOWED_PLANS, true)) {
            wc_add_notice(__('Invalid payment type selected.', 'upayments'), 'error');
            return;
        }

        $interval = self::parse_interval(
            isset($_POST['upay_subscription_interval']) && is_scalar($_POST['upay_subscription_interval'])
                ? wp_unslash($_POST['upay_subscription_interval'])
                : null
        );

        if (!isset(self::$ALLOWED_INTERVALS[$plan]) || !in_array($interval, self::$ALLOWED_INTERVALS[$plan], true)) {
            wc_add_notice(__('Invalid billing interval selected for the chosen plan.', 'upayments'), 'error');
        }
    }

    public static function add($fields)
    {
        $gateway = self::getGateway();

        if (!$gateway || $gateway->get_option('enable_subscriptions') !== 'yes' || Utils::cartHasRestrictedProducts() || !Utils::cartHasCustomType()) {
            return $fields;
        }

        $fields['billing']['upay_subscription_plan'] = [
            'type'     => 'select',
            'label'    => __('Purchase Type', 'upayments'),
            'required' => true,
            'options'  => [
                'one_time' => __('One-time', 'upayments'),
                'daily'    => __('Daily Subscription', 'upayments'),
                'weekly'   => __('Weekly Subscription', 'upayments'),
                'monthly'  => __('Monthly Subscription', 'upayments'),
                'quarterly'   => __('Quarterly Subscription', 'upayments'),
                'yearly'   => __('Yearly Subscription', 'upayments'),
            ],
            'priority' => 120,
        ];

        $fields['billing']['upay_subscription_interval'] = [
            'type'     => 'select',
            'label'    => __('Billing Interval', 'upayments'),
            'required' => false,
            'options'  => [
                ''  => __('Select interval', 'upayments'),
            ],
            'priority' => 121,
        ];

        return $fields;
    }

    public static function save($order)
    {
        if (!self::is_subscription_context()) {
            return;
        }

        if (!isset($_POST['upay_subscription_plan']) || !is_scalar($_POST['upay_subscription_plan'])) {
            return;
        }

        $plan = sanitize_text_field(wp_unslash($_POST['upay_subscription_plan']));
        if (!in_array($plan, self::$ALLOWED_PLANS, true)) {
            return;
        }

        $interval = self::parse_interval(
            isset($_POST['upay_subscription_interval']) && is_scalar($_POST['upay_subscription_interval'])
                ? wp_unslash($_POST['upay_subscription_interval'])
                : null
        );

        if (!isset(self::$ALLOWED_INTERVALS[$plan]) || !in_array($interval, self::$ALLOWED_INTERVALS[$plan], true)) {
            return;
        }

        if ($plan === 'one_time') {
            return;
        }

        $order->update_meta_data('_upay_subscription_plan', $plan);
        $order->update_meta_data('_upay_subscription_interval', $interval);
    }

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

    private static function is_subscription_context(): bool {
        $gateway = self::getGateway();
        if (!$gateway) {
            return false;
        }
        if ($gateway->get_option('enable_subscriptions') !== 'yes') {
            return false;
        }
        $selected_gateway = '';
        if (isset($_POST['payment_method']) && is_scalar($_POST['payment_method'])) {
            $selected_gateway = sanitize_key(wp_unslash($_POST['payment_method']));
        }
        if ($selected_gateway !== 'upayments') {
            return false;
        }
        if (!Utils::cartHasCustomType()) {
            return false;
        }
        if (Utils::cartHasRestrictedProducts()) {
            return false;
        }
        return true;
    }

    protected static function getGateway()
    {
        if (!function_exists('WC')) {
            return null;
        }

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();

        return $gateways['upayments'] ?? null;
    }
}
