<?php
/**
 * Payment form template for the New Design (V2.1.5/V2.2.1).
 *
 * This file is included in WC_Gateway_Your_Gateway::payment_fields().
 *
 * @var WC_Gateway_Your_Gateway $gateway           The gateway instance (now renamed to $this in the original context).
 * @var bool                    $save_card_enabled Flag indicating if save card is enabled.
 */

defined( 'ABSPATH' ) || exit;
?>
<style>
    .wc-toast {
    position: fixed;
    top: 30px;
    right: 30px;
    background: #F23232;
    color: #fff;
    padding: 12px 18px;
    border-radius: 6px;
    opacity: 0;
    pointer-events: none;
    transform: translateY(10px);
    transition: all 0.3s ease;
    z-index: 99999;
}

.wc-toast.show {
    opacity: 1;
    transform: translateY(0);
}
</style>
<div id="wc-toast" class="wc-toast"></div>
<div class="form-row form-row-wide">
    <?php 
    if (isset($_REQUEST["cancelled"])){
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
    } elseif (isset($_REQUEST["failed"])){
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
    } elseif (isset($_REQUEST["suspected"])) {
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
        $total = WC()->cart->get_total('');
        $language = get_locale();
        $currency = get_woocommerce_currency_symbol();
        if (strpos($language, 'en') === 0) {
            $currency = get_woocommerce_currency();
        }
        $whitelabled = false;
        $payment_data = $gateway->getPaymentIcons();
        $payment_data_valid = (
            is_array($payment_data)
            && isset($payment_data['payment'])
            && is_array($payment_data['payment'])
            && array_key_exists('whitelabled', $payment_data)
            && is_bool($payment_data['whitelabled'])
        );
        if ($payment_data_valid) {
            $gateway->paymentData = $payment_data;
            $icons = $payment_data['payment'];
            $whitelabled = $payment_data['whitelabled'];
        }
        $isSubscriptionEnabled = $gateway->get_option('enable_subscriptions') === 'yes' ? true : false;
        if ($payment_data_valid && $whitelabled) {
    ?>
        <div class="payment-buttons">
            <?php
            $user_id = get_current_user_id();
            $is_logged_in = $user_id > 0;
            if ($is_logged_in && $save_card_enabled) {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="0"/>
                <?php
                $savedCards = $gateway->getSavedCardsForCurrentUser();
                
                if (is_array($savedCards) && isset($savedCards['result']) && $savedCards['result'] === 'success' && isset($savedCards['data']) && is_array($savedCards['data']))
                {
                    $cardList = $savedCards['data'];
                ?>
                    <span class="payment-method-label"><?php esc_html_e('Saved Cards', 'upayments'); ?></span>
                    <?php
                    foreach ($cardList as $cardkey => $cardValue) {
                        if (!is_array($cardValue)) {
                            continue;
                        }
                        if (!isset($cardValue['token']) || !is_scalar($cardValue['token']) || trim((string) $cardValue['token']) === '') {
                            continue;
                        }
                        $card_token = (string) $cardValue['token'];
                        $card_number_raw = isset($cardValue['number']) && is_scalar($cardValue['number']) ? (string) $cardValue['number'] : '';
                        $card_number = esc_attr($card_number_raw);
                        $card_number_text = esc_html($card_number_raw);
                    ?>

                        <button type="button" value="<?php echo esc_attr($card_token); ?>" onclick="submitSavedCard(this)" class="upay-payment-method" id="upay-button-cc">
                        <span class="payment-method-icon"><img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/cc.png'); ?>" alt="<?php echo $card_number; ?>"  title="<?php echo $card_number; ?>"/></span>
                        <span class="payment-method-label"><?php echo $card_number_text; ?></span>
                        <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo wp_kses($currency, array()); ?></span>
                        <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                        </button>

                    <?php
                    }
                    ?>
                    <span class="payment-method-label"><?php esc_html_e('Other Options', 'upayments'); ?></span>
                <?php
                }
            } else {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="0"/>
            <?php
            }
                foreach ($icons as $key => $value) {
                    if (!is_scalar($value)) {
                        continue;
                    }
                    $key_string = (string) $key;
                    $value_string = (string) $value;
                    $key_attr = esc_attr($key_string);
                    $value_attr = esc_attr($value_string);
                    $value_text = esc_html($value_string);
                    $key_js = wp_json_encode($key_string, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
                    $onclick = 'submitUpayButton(' . $key_js . ')';
            ?>
                <button type="button" onclick="<?php echo esc_attr($onclick); ?>" class="upay-payment-method" id="upay-button-<?php echo $key_attr; ?>">
                    <span class="payment-method-icon">
                        <?php
                            if ($key_string == 'apple-pay-knet') {
                                ?>
                                <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png'); ?>" alt="<?php echo $value_attr; ?>"  title="<?php echo $value_attr; ?>"/>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/knet.png'); ?>" alt="<?php echo $value_attr; ?>"  title="<?php echo $value_attr; ?>"/>
                                <?php
                            } elseif ($key_string == 'apple-pay') {
                                ?>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/apple-pay.png'); ?>" alt="<?php echo $value_attr; ?>"  title="<?php echo $value_attr; ?>"/>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/cc.png'); ?>" alt="<?php echo $value_attr; ?>"  title="<?php echo $value_attr; ?>"/>
                                <?php
                            } else {
                                ?>
                                    <img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/' . $key_string . '.png'); ?>" alt="<?php echo $value_attr; ?>"  title="<?php echo $value_attr; ?>"/>
                                <?php
                            }
                        ?>
                    </span>
                    <span class="payment-method-label"><?php echo $value_text; ?></span>
                    <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo wp_kses($currency, array()); ?></span>
                    <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                </button>
            
            <?php if ($key_string == 'cc' && $save_card_enabled && $is_logged_in) { ?>
                <label class="switch-border">For faster and more secure checkout. Save your card details.
                    <label class="switch">
                        <?php
                            $checked = false;
                        ?>
                        <input
                            type="checkbox"
                            id="chkSaveCard"
                            onclick="toggleSaveCard(<?php echo $is_logged_in ? 'true' : 'false'; ?>);"
                        >
                        <span class="slider round"></span>
                    </label>
                </label>
            <?php
                    }
                }
            ?>
        </div>
    <?php
        } elseif ($payment_data_valid && !$whitelabled) {
    ?>
        <div class="payment-buttons">
            <button type="button" onclick="submitUpayButton('knet')" class="upay-payment-method">
    <?php
            foreach ($icons as $key => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                $key_string = (string) $key;
                $value_string = (string) $value;
                if ($key_string != 'apple-pay-knet') {
                    $key_attr = esc_attr($key_string);
                    $value_attr = esc_attr($value_string);
    ?>
                <span class="payment-method-icon" style="margin-right: 5px;" id="upay-button-<?php echo $key_attr; ?>"><img src="<?php echo esc_url(UP_PLUGIN_URL . 'assets/images/' . $key_string . '.png'); ?>" alt="<?php echo $value_attr; ?>"  title="<?php echo $value_attr; ?>"/></span>
    <?php
                }
            }
    ?>
            <span class="payment-method-price"><?php echo esc_html($total); ?> <?php echo wp_kses($currency, array()); ?></span>
            <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
            </button>
        </div>
    <?php
        }
        // If $payment_data_valid is false, render NO payment buttons.
    ?>
        <input id="upayment_payment_type" type="hidden" name="upayment_payment_type" value="upayments"/>
        <input id="card_token" type="hidden" name="card_token" value=""/>
</div>
