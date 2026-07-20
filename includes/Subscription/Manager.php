<?php

namespace UPayments\Subscription;

defined('ABSPATH') || exit;

class Manager
{
    public static function init()
    {
        add_action('upayments_payment_success', [__CLASS__, 'create']);
    }

    public static function create($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $plan = $order->get_meta('_upay_subscription_plan');

        if (!$plan || $plan === 'one_time') {
            return;
        }

        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'upay_subscriptions',
            [
                'user_id'    => $order->get_user_id(),
                'order_id'   => $order_id,
                'plan'       => $plan,
                'status'     => 'active',
                'created_at' => current_time('mysql'),
            ]
        );
    }
}
