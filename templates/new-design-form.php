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
<div class="form-row form-row-wide">
    <?php 
    if (isset($_REQUEST["cancelled"])){ ?>
        <script>
            let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment canceled by customer", $gateway->domain); ?></div></div>';
            jQuery(document).ready(function(){
                jQuery('.woocommerce-notices-wrapper:first').html(message);
            });
        </script>
    <?php
    } elseif (isset($_REQUEST["failed"])){ ?>
    <script>
        let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment error from UPayments", $gateway->domain); ?></div></div>';
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php
    } elseif (isset($_REQUEST["suspected"])) { ?>
    <script>
        let message = '<div class="woocommerce-NoticeGroup woocommerce-NoticeGroup-checkout"><div class="woocommerce-error alert-color"><?php echo __("Payment failed for suspected fraud.", $gateway->domain); ?></div></div>';
        jQuery(document).ready(function(){
            jQuery('.woocommerce-notices-wrapper:first').html(message);
        });
    </script>
    <?php 
    } 
        $icons = null;
        $total = "0";
        $total = WC()->cart->get_total('');
        $language=get_locale();
        $currency = get_woocommerce_currency_symbol();
        if (strpos($language, 'en') === 0) {
            $currency = get_woocommerce_currency();
        }
        $whitelabled = false;
        // NOTE: The function call was $this->getPaymentIcons() in the original method.
        // Since we are in a template, we use the $gateway variable.
        $payment_data = $gateway->getPaymentIcons(); 
        if($payment_data){
            $gateway->paymentData = $payment_data;
            $icons = $payment_data['payment'];
            $whitelabled = $payment_data['whitelabled'];
        }
        if($whitelabled){
    ?>
        <div class="payment-buttons">
            <?php
            // Retrieve Saved Cards
            $loggedInUser = $gateway->get_logged_in_user_phone_number(); // Use $gateway
            if($loggedInUser['success']  && $save_card_enabled) {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="1"/>
                <?php
                $savedCards = $gateway->getSavedCards($loggedInUser['phone']); // Use $gateway
                if($savedCards && $savedCards['result'] == 'success')
                {
                    $cardList = $savedCards['data'];
                ?>
                    <span class="payment-method-label">Saved Cards</span>
                    <?php
                    foreach ($cardList as $cardkey => $cardValue) {
                    ?>

                        <button type="button" value="<?php echo $cardValue['token'];?>" onclick="submitSavedCard(this)" class="upay-payment-method" id="upay-button-cc">
                        <span class="payment-method-icon"><img src="<?php echo UP_PLUGIN_URL;?>assets/images/cc.png" alt="<?php echo $cardValue['number'];?>"  title="<?php echo $cardValue['number'];?>"/></span>
                        <span class="payment-method-label"><?php echo $cardValue['number'];?></span>
                        <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
                        <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                        </button>

                    <?php
                    }
                    ?>
                    <span class="payment-method-label">Other Options</span>
                <?php
                }
            } else {
            ?>
                <input id="save_card" type="hidden" name="save_card" value="0"/>
            <?php
            }
                foreach ($icons as $key => $value) {
            ?>
                    <button type="button" onclick="submitUpayButton('<?php echo esc_attr($key);?>')" class="upay-payment-method" id="upay-button-<?php echo esc_attr($key);?>">
                        <span class="payment-method-icon">
                            <?php
                                if($key == 'apple-pay-knet') {
                                    ?>
                                    <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('apple-pay');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                        <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('knet');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <?php
                                } elseif($key == 'apple-pay') {
                                    ?>
                                        <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('apple-pay');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                        <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr('cc');?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <?php
                                } else {
                                    ?>
                                        <img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr($key);?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/>
                                    <?php
                                }
                            ?>
                        </span>
                        <span class="payment-method-label"><?php echo esc_attr($value);?></span>
                        <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
                        <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
                    </button>
            
            <?php if($key == 'cc' && $save_card_enabled) { ?>
                    <label class="switch-border">
                        For faster and more secure checkout. Save your card details.
                        <label class="switch">
                        <?php
                            if($loggedInUser['success']) {
                                ?>
                                <input type="checkbox" id="chkSaveCard" onclick="toggleSaveCard(true);" checked>
                                <span class="slider round"></span>
                                <?php
                            } else {
                                ?>
                                <input type="checkbox" id="chkSaveCard" onclick="toggleSaveCard(false);">
                                <span class="slider round"></span>
                                <?php
                            }
                        ?>
                        </label>
                    </label>
            <?php
                    }
                }
            ?>
        </div>
    <?php
        } else {
    ?>
        <div class="payment-buttons">
            <button type="button" onclick="submitUpayButton('knet')" class="upay-payment-method">
    <?php
            foreach ($icons as $key => $value) {
                if($key != 'apple-pay-knet') {
    ?>
                <span class="payment-method-icon" style="margin-right: 5px;" id="upay-button-<?php echo esc_attr($key);?>"><img src="<?php echo UP_PLUGIN_URL;?>assets/images/<?php echo esc_attr($key);?>.png" alt="<?php echo esc_attr($value);?>"  title="<?php echo esc_attr($value);?>"/></span>
    <?php
                }
            }
    ?>
            <span class="payment-method-price"><?php echo $total;?> <?php echo $currency;?></span>
            <span class="payment-method-icon2"><i class="fa fa-chevron-right"></i></span>
            </button>
        </div>
    <?php
        }
    ?>
        <input id="upayment_payment_type" type="hidden" name="upayment_payment_type" value="upayments"/>
        <input id="card_token" type="hidden" name="card_token" value=""/>
</div>
