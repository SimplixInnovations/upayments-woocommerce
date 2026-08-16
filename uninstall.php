<?php

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;

$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}upay_subscriptions");

delete_option('upay_subscription_settings');
delete_option('upayments_payment_methods_rate_gate_live');
delete_option('upayments_payment_methods_rate_gate_test');
delete_option('upayments_payment_methods_rate_gate');
