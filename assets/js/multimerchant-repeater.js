jQuery(document).ready(function($) {

    var $repeaterTable = $('.wc_input_multimerchant_repeater');
    var $hiddenInput = $('#multimerchant_routing_details');
    var $body = $repeaterTable.find('tbody');
    var $templateRow = $body.find('.multimerchant-row-template').clone();

    // ----------------------------------------------------
    // 1. INITIALIZATION & SETUP
    // ----------------------------------------------------

    // Remove the template row from the table view, but keep the clone
    $body.find('.multimerchant-row-template').hide(); 

    // ----------------------------------------------------
    // 2. ADD RULE
    // ----------------------------------------------------
    $repeaterTable.on('click', '.add_multimerchant_rule', function(e) {
        e.preventDefault();
        
        // Clone the template, clean it up, and append it to the body
        var $newRow = $templateRow.clone();
        $newRow.removeClass('multimerchant-row-template').addClass('multimerchant-row').show();
        
        // Clear any placeholder values if necessary
        $newRow.find('input[type="text"], input[type="password"]').val('');
        $newRow.find('select').prop('selectedIndex', 0);

        $body.append($newRow);

        $(this).hide();
    });

    // ----------------------------------------------------
    // 3. REMOVE RULE
    // ----------------------------------------------------
    $repeaterTable.on('click', '.remove_multimerchant_rule', function(e) {
        e.preventDefault();
        $('.add_multimerchant_rule').show();
        // Remove the parent <tr> element
        $(this).closest('tr').remove();
    });

    // ----------------------------------------------------
    // 4. PACKAGING DATA ON SAVE (CRITICAL)
    // ----------------------------------------------------
    
    // Bind to the WooCommerce settings save event (Place Order button click)
    $('form#mainform').on('submit', function() {
        var rules = [];

        // Iterate through all visible rule rows
        $body.find('.multimerchant-row').each(function() {
            var $row = $(this);
            var rule = {};

            // Collect data from each input field using the 'data-field' attribute
            $row.find('[data-field]').each(function() {
                var fieldName = $(this).data('field');
                rule[fieldName] = $(this).val();
            });

            if (Object.keys(rule).length > 0) {
                rules.push(rule);
            }
        });

        // Convert the collected array of rules into a JSON string
        var jsonRules = JSON.stringify(rules);

        // Update the hidden input field with the JSON string.
        // The PHP validation function (validate_multimerchant_repeater_field) will read this.
        $hiddenInput.val(jsonRules);
        
        // The form will now proceed with the submission.
    });
});
