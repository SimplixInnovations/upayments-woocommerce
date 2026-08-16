jQuery(function($) {
    function hidePlaceOrderButtonIfNeeded() {
        let selectedPaymentMethod = $('input[name="payment_method"]:checked').val();
        if (selectedPaymentMethod === 'upayments') { // Replace 'upayments' with the ID of your custom payment method
            $('button#place_order').hide();
        } else {
            $('button#place_order').show();
        }
    }

    function handlePaymentMethodChange() {
        hidePlaceOrderButtonIfNeeded();
    }

    $('form.checkout').on('change', 'input[name="payment_method"]', function() {
        handlePaymentMethodChange();
    });

    // Listen for page load and DOM ready
    $(document).ready(function() {
        hidePlaceOrderButtonIfNeeded(); // Check on DOM ready
        checkApplePayAvailability();
        // Check after a short delay to handle redirection (if needed)
        setTimeout(function() {
            hidePlaceOrderButtonIfNeeded();
            checkApplePayAvailability();
        }, 500); // Adjust the delay time if needed
    });

    // Listen for AJAX Complete event (to handle cases like redirection)
    $(document).ajaxComplete(function() {
        hidePlaceOrderButtonIfNeeded();
        checkApplePayAvailability();
    });

    let customPaymentMethodId = 'upayments';

    // Check if the form exists and the chosen payment method is not already set
    if ($('form.checkout').length > 0 && $('input[name="payment_method"]:checked').val() !== customPaymentMethodId) {
        // Trigger click event on the desired payment method
        $('input[name="payment_method"][value="' + customPaymentMethodId + '"]').click();
    }

});
function checkApplePayAvailability() {
    justEat = {
        applePay: {
            supportedByDevice: function () {
                return "ApplePaySession" in window;
            },
            getMerchantIdentifier: function () {
                return "merchant.com.upayments.ustore";
            }
        }
    };
        
    let merchantIdentifier = justEat.applePay.getMerchantIdentifier();
    if (merchantIdentifier && justEat.applePay.supportedByDevice()) {        
        // Determine whether to display the Apple Pay button. See this link for details
        // on the two different approaches: https://developer.apple.com/documentation/applepayjs/checking_if_apple_pay_is_available
        if (ApplePaySession.canMakePayments()) {            
        console.log('apple pay available');
        }else{
            ApplePaySession.canMakePaymentsWithActiveCard(merchantIdentifier).then(function (canMakePayments) {
                if (canMakePayments) {
                    console.log('apple pay available');
                } else {
                    console.log('apple not available');
                }
            });
        }
    }else{
        console.log('apple not available');
    } 
}

function submitUpayButton(buttonValue) {
    jQuery('#upayment_payment_type').val(buttonValue);
    jQuery('#card_token').val('');
    if (buttonValue !== 'cc') {
        jQuery('#save_card').val('0');
        let checkbox = document.getElementById('chkSaveCard');
        if (checkbox) {
            checkbox.checked = false;
        }
    }
    jQuery('form.checkout').submit();
}

function submitSavedCard(objButton) {
    jQuery('#upayment_payment_type').val('cc');
    jQuery('#card_token').val(objButton.value);
    jQuery('#save_card').val('0');
    let checkbox = document.getElementById('chkSaveCard');
    if (checkbox) {
        checkbox.checked = false;
    }
    jQuery('form.checkout').submit();
}

function toggleSaveCard(loggedUser) {
    let checkbox = document.getElementById('chkSaveCard');
    let saveCardInput = jQuery('#save_card');

    if (loggedUser === false) {
        checkbox.checked = false;
        saveCardInput.val('0');
        showToast('Please log in to save or use a saved card.', 3000);
        return;
    }

    // User logged in
    saveCardInput.val(checkbox.checked ? '1' : '0');
}

function showToast(message, duration = 3000) {
    const toast = document.getElementById('wc-toast');

    toast.innerHTML = message;
    toast.classList.add('show');

    setTimeout(() => {
        toast.classList.remove('show');
    }, duration);
}
