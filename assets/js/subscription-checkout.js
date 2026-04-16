jQuery(document).ready(function($) {

    let optionsData = {
        daily: { "1": "Every Day" },
        weekly: { "1": "Every Week", "2": "Every 2 Weeks", "3": "Every 3 Weeks" },
        monthly: { "1": "Every Month", "2": "Every 2 Months" },
        quarterly: { "1": "Every Quarter", "2": "Every 2 Quarters", "3": "Every 3 Quarters" },
        yearly: { "1": "Every Year" }
    };

    function toggleIntervalField() {
        
        const selectedPlan = $('select[name="upay_subscription_plan"]').val();
        const $intervalSelect = $('#upay_subscription_interval');
        const intervalRow = $intervalSelect.closest('.form-row');

        // Clear current options first
        $intervalSelect.empty().append('<option value="">Select Interval</option>');
        let isLoggedIn = !!wcUser.isLoggedIn;

        // Check if the selected plan has data in optionsData
        if (optionsData[selectedPlan]) {
            if (!isLoggedIn) {
                showToast('Since You are not logged in, Your Purchase will be one time only.', 3000);
                $intervalSelect.append($('<option></option>').val('one_time'));
                return;
            }
            intervalRow.show();
            
            // Loop through data and append new options
            $.each(optionsData[selectedPlan], function(value, text) {
                $intervalSelect.append($('<option></option>').val(value).text(text));
            });
        } else {
            if(selectedPlan === 'daily'){
                if (!isLoggedIn) {
                    showToast('Since You are not logged in, Your Purchase will be one time only.', 3000);
                    $intervalSelect.append($('<option></option>').val('one_time'));
                    return;
                }
            }
            intervalRow.hide();
            $intervalSelect.val('');
        }
    }

    // Initial load
    toggleIntervalField();

    // On change
    $("select[name='upay_subscription_plan']").on('change', function() {
        toggleIntervalField();
    });

    // WooCommerce re-render
    $(document.body).on('updated_checkout', toggleIntervalField);
});
