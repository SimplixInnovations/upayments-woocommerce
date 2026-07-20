<?php

namespace UPayments\Subscription\Helpers;

defined('ABSPATH') || exit;

class Utils
{
    public static function isSubscriptionOrder($order)
    {
        return in_array(
            $order->get_meta('_upay_subscription_plan'),
            ['daily', 'weekly', 'monthly', 'yearly'],
            true
        );
    }

    public static function cartHasRestrictedProducts()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product_id = $item['product_id'];

            // Product-level restriction
            if (get_post_meta($product_id, '_upay_disable_subscription', true) === 'yes') {
                return true;
            }

            // Hard-coded restriction example
            if (in_array($product_id, [123, 456], true)) {
                return true;
            }
        }

        return false;
    }

    public static function cartHasCustomType()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            if ($product && $product->get_type() === 'custom_type') {
                return true;
            }
        }

        return false;
    }

    public static function cartHasNormalProduct()
    {
        if (!WC()->cart) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $item) {
            $product = $item['data'];
            if ($product && $product->get_type() !== 'custom_type') {
                return true;
            }
        }

        return false;
    }
}
