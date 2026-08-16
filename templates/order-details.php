<?php
$payment_status = isset($payment_status) && is_scalar($payment_status)
    ? (string) $payment_status
    : '';

$upayment_id = isset($upayment_id) && is_scalar($upayment_id)
    ? (string) $upayment_id
    : '';
?>
<table class="wc-order-totals" style="border-top: 1px solid #999; margin-top:12px; padding-top:12px">
    <tbody>
        <tr>
            <td class="label"><h3 style="margin:0"><?php esc_html_e('Payment Status', 'upayments'); ?>:</h3></td>
            <td style="width:1%;"></td>
            <td class="total">
                <span class="woocommerce-Price-amount amount"><strong><?php echo esc_html($payment_status); ?></strong></span>
            </td>
        </tr>
        <tr>
            <td class="label"><h3 style="margin:0"><?php esc_html_e('UPayment ID', 'upayments'); ?>:</h3></td>
            <td style="width:1%;"></td>
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
