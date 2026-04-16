jQuery(function ($) {

    let is_status_received = false;

    $('.upayment-status-holder').insertAfter('.woocommerce-order-overview__payment-method');
    $('.upayment-id-holder').insertAfter('.woocommerce-order-overview__payment-status');

    $('.entry-header .entry-title').text(upaymentsData.i18n_order_status);
    $('.woocommerce-thankyou-order-received').hide();
    $('.woocommerce-thankyou-order-details').hide();
    $('.woocommerce-order-details').hide();
    $('.woocommerce-customer-details').hide();

    show_upayments_status();

    function show_upayments_status(type = '') {
        $('.payment-panel-wait').hide();
        $('.woocommerce-order-details').show();
        $('.woocommerce-customer-details').show();

        if (type.length > 0) {
            $('.payment-panel-' + type).show();
        }
    }

    check_upayments_payment_status();

    function check_upayments_payment_status() {
        function upayments_status_loop() {
            if (is_status_received) {
                return;
            }

            if (typeof(upayments_status_ajax_url) !== "undefined") {
                jQuery.getJSON(upayments_status_ajax_url, {'order_id' : '<?php echo $order_id; ?>'}, function (data) {
                    if (data.status == 'wait') {-
                    setTimeout(upayments_status_loop, 2000);
                    } else if (data.status == 'error') {
                    show_upayments_status('error');
                    is_status_received = true;
                    } else if (data.status == 'pending') {
                    show_upayments_status('pending');
                    is_status_received = true;
                    } else if (data.status == 'failed') {
                    show_upayments_status('failed');
                    is_status_received = true;
                    } else if (data.status == 'completed') {
                    show_upayments_status('completed');
                    is_status_received = true;
                    }
            });
            }
        }
        upayments_status_loop();
    }

});