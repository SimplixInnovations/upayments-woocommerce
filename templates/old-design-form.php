<?php
/**
 * Payment form template for the Old Design (V2.0.8 Look).
 *
 * This file is included in WC_Gateway_Your_Gateway::payment_fields().
 *
 * @var WC_Gateway_Your_Gateway $gateway           The gateway instance.
 * @var bool                    $save_card_enabled Flag indicating if save card is enabled (unused in this design).
 */

defined( 'ABSPATH' ) || exit;
?>
<div class="form-row form-row-wide">
    <?php 
    echo wp_kses_post($gateway->description);
    if (isset($_REQUEST["cancelled"]))
    {
        $notice_html = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color">'
            . esc_html__('Payment canceled by customer', $gateway->domain)
            . '</div></div>';
    ?>
    <script>
        let message = <?php echo wp_json_encode($notice_html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php
    } elseif (isset($_REQUEST["failed"])) {
        $notice_html = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color">'
            . esc_html__('Payment error from UPayments', $gateway->domain)
            . '</div></div>';
    ?>
    <script>
        let message = <?php echo wp_json_encode($notice_html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php
    } elseif (isset($_REQUEST["suspected"])){
        $notice_html = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color">'
            . esc_html__('Payment failed for suspected fraud.', $gateway->domain)
            . '</div></div>';
    ?>
    <script>
        let message = <?php echo wp_json_encode($notice_html, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php 
    }
    $icons = array();
    $whitelabled = false;
    
    if ($gateway->paymentData == null) {
        $payment_data = $gateway->getPaymentIcons();
    } else {
        $payment_data = $gateway->paymentData;
    }
    
    $payment_data_valid = (
        is_array($payment_data)
        && isset($payment_data['payment'])
        && is_array($payment_data['payment'])
        && array_key_exists('whitelabled', $payment_data)
        && is_bool($payment_data['whitelabled'])
    );
    if ($payment_data_valid) {
        $icons = $payment_data['payment'];
        $whitelabled = $payment_data['whitelabled'];
    }
    
    if ($payment_data_valid && $whitelabled)
    {
    ?>
        <ul style="list-style: none outside;">
            <p style="display: inline"><?php esc_html_e('Select Payment Type:', 'upayments'); ?></p>
            <?php 
            foreach ($icons as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $key_string = (string) $key;
                $value_string = (string) $value;
                if ($key_string != "both") {
                    $key_attr = esc_attr($key_string);
                    $value_attr = esc_attr($value_string);
                    $value_text = esc_html($value_string);
                    if ($key_string == 'apple-pay') {
                        $icon = '<img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />
                        <img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/cc.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />';
                    } elseif ($key_string == 'apple-pay-knet') {
                        $icon = '<img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />
                        <img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/knet.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />';
                    } else {
                        $icon = '<img style="height: 13px;" src="' . esc_url(UP_PLUGIN_URL . 'assets/images/' . $key_string . '.png') . '" alt="' . $value_attr . '" title="' . $value_attr . '" />';
                    }
                        
            ?>
                <li>
                    <span class="<?php echo $key_attr; ?>-upayments-button">
                    <input id="upayment_payment_type_<?php echo $key_attr; ?>" type="radio" class="input-radio"
                            name="upayment_payment_type" value="<?php echo $key_attr; ?>"/>
                    <label for="upayment_payment_type_<?php echo $key_attr; ?>"
                            style='display: inline-block; font-family: -apple-system,blinkmacsystemfont,"Helvetica Neue",helvetica,sans-serif;'>
                        <span class="upayment_payment_type_label_text"><?php echo $value_text; ?></span>
                        <span class="upayment_payment_type_label_logo"><?php echo wp_kses_post($icon); ?></span>
                    </label>
                    </span>
                </li>
            <?php
                }
            } 
            ?>
        </ul>
    <?php
    }
    ?>
</div>
