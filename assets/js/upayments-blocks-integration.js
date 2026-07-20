wp.domReady( function () {
    if ( ! window.wc || ! wc.wcBlocksRegistry ) {
        console.error('Woo Blocks not ready');
        return;
    }

    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { getSetting } = wc.wcSettings;
    const { useState, createElement } = wp.element;
    const settings = getSetting( 'upayments_data', {} );
    const {
        is_whitelabled,
        payment_icons,
        saved_cards,
        is_logged_in,
        save_card_enabled,
        cart_total,
        currency_display,
        plugin_url,
        translation
    } = settings;

    /**
     * Payment method content and logic.
     */
    const Content = (props) => {

            const { useDispatch, select } = wp.data;
            const { setExtensionData } = useDispatch('wc/store/checkout');
            const [toast, setToast] = useState({ message: '', show: false });

            const NAMESPACE = 'upayments';
            const PAYMENT_TYPE_KEY = 'upayment_payment_type';
            const CARD_TOKEN_KEY = 'card_token';
            const SAVE_CARD_KEY = 'save_card';
            const IS_USER_LOGGED_IN = 'is_user_logged_in';

            // ✅ Get current stored values (persistent, no React state)
            const checkoutData = select('wc/store/checkout').getExtensionData() || {};
            const selectedPaymentType = checkoutData?.[NAMESPACE]?.[PAYMENT_TYPE_KEY] || null;
            const selectedCardToken = checkoutData?.[NAMESPACE]?.[CARD_TOKEN_KEY] || null;
            const shouldSaveCard = checkoutData?.[NAMESPACE]?.[SAVE_CARD_KEY] === '1';

            // New state for subscription fields
            const [plan, setPlan] = useState(checkoutData?.[NAMESPACE]?.['upay_subscription_plan'] || 'one_time');
            const [interval, setInterval] = useState(checkoutData?.[NAMESPACE]?.['upay_subscription_interval'] || '');

            let optionsData = {
                daily: { "1": "Every Day" },
                weekly: { "1": "Every Week", "2": "Every 2 Weeks", "3": "Every 3 Weeks" },
                monthly: { "1": "Every Month", "2": "Every 2 Months" },
                quarterly: { "1": "Every Quarter", "2": "Every 2 Quarters", "3": "Every 3 Quarters" },
                yearly: { "1": "Every Year" }
            };

            const showToast = (msg) => {
                setToast({ message: msg, show: true });
                setTimeout(() => setToast({ message: '', show: false }), 3000);
            };

            // ✅ HANDLE PAYMENT TYPE CLICK
            const handlePaymentTypeClick = (type) => {
                console.log('Selected:', type);

                setExtensionData(NAMESPACE, {
                    [PAYMENT_TYPE_KEY]: type,
                    [CARD_TOKEN_KEY]: '',
                    [SAVE_CARD_KEY]: shouldSaveCard ? '1' : '0',
                    [IS_USER_LOGGED_IN]: is_logged_in,

                });
            };

            // ✅ HANDLE SAVED CARD CLICK
            const handleSavedCardClick = (token) => {
                console.log('Saved card:', token);

                setExtensionData(NAMESPACE, {
                    [PAYMENT_TYPE_KEY]: 'cc',
                    [CARD_TOKEN_KEY]: token,
                    [SAVE_CARD_KEY]: shouldSaveCard ? '1' : '0',
                    [IS_USER_LOGGED_IN]: is_logged_in,
                });
            };

            // ✅ TOGGLE SAVE CARD
            const toggleSaveCard = (checked) => {

                let checkbox = document.getElementById('chkSaveCard');
                let saveCardInput = jQuery('#save_card');

                if (is_logged_in === false) {
                    checkbox.checked = false;
                    saveCardInput.val('0');
                    showToast('Please Login to use the Save Card feature.');
                    return;
                }

                let phone = document.getElementById('billing_phone').value;
                if (phone === '') {
                    checkbox.checked = false;
                    saveCardInput.val('0');
                    showToast('Please update your mobile number to use the Save Card feature.');
                    return;
                }

                setExtensionData(NAMESPACE, {
                    [PAYMENT_TYPE_KEY]: selectedPaymentType,
                    [CARD_TOKEN_KEY]: selectedCardToken || '',
                    [SAVE_CARD_KEY]: checked ? '1' : '0',
                    [IS_USER_LOGGED_IN]: is_logged_in,
                });
            };

            // ✅ Change in Purchase Type
            const handleSubscriptionChange = (newPlan, newInterval) => {
                // If the plan changed, reset the interval to empty
                const finalInterval = newPlan === plan ? newInterval : '';
                
                setPlan(newPlan);
                setInterval(finalInterval);
                
                setExtensionData(NAMESPACE, {
                    ...checkoutData[NAMESPACE],
                    'upay_subscription_plan': newPlan,
                    'upay_subscription_interval': finalInterval
                });
            };
            
            // Helper function to render the correct icons (matches your old logic)
            const getIconSrc = ( key ) => {
                if ( key === 'apple-pay-knet' ) {
                    return wp.element.createElement(
                        'div',
                        { style: { display: 'flex', gap: '5px' } },
                        wp.element.createElement( 'img', {
                            src: `${plugin_url}assets/images/apple-pay.png`,
                            alt: 'Apple Pay',
                            title: 'Apple Pay',
                        }),
                        wp.element.createElement( 'img', {
                            src: `${plugin_url}assets/images/knet.png`,
                            alt: 'KNET',
                            title: 'KNET',
                        })
                    );
                }

                if ( key === 'apple-pay' ) {
                    return wp.element.createElement(
                        'div',
                        { style: { display: 'flex', gap: '5px' } },
                        wp.element.createElement( 'img', {
                            src: `${plugin_url}assets/images/apple-pay.png`,
                            alt: 'Apple Pay',
                            title: 'Apple Pay',
                        }),
                        wp.element.createElement( 'img', {
                            src: `${plugin_url}assets/images/cc.png`,
                            alt: 'Credit Card',
                            title: 'Credit Card',
                        })
                    );
                }

                return wp.element.createElement( 'img', {
                    src: `${plugin_url}assets/images/${key}.png`,
                    alt: payment_icons[key],
                    title: payment_icons[key],
                });
            };
            return wp.element.createElement(
                'div',
                { className: 'upayments-payment-methods' },

                is_subscription_enabled && createElement(
                    'div',
                    { className: 'upay-subscription-fields', style: { marginBottom: '15px' } },
                    
                    createElement(
                        'label',
                        { 
                            htmlFor: 'upay_subscription_plan',
                            style: { 
                                display: 'block', 
                                marginBottom: '5px', 
                                fontWeight: '600',
                                fontSize: '0.9em'
                            }
                        },
                        'Purchase Type',
                        createElement(
                            'span',
                            { 
                                className: 'wc-block-components-validation-error', // Standard Woo class for errors/stars
                                style: { color: 'red', marginLeft: '3px' },
                                'aria-hidden': 'true' 
                            },
                            '*'
                        )
                    ),
                    // Purchase Type Dropdown
                    createElement('select', {
                        id: 'upay_subscription_plan',
                        value: plan,
                        required: true, // Native HTML validation
                        className: 'wc-block-components-select__input',
                        onChange: (e) => handleSubscriptionChange(e.target.value),
                        style: { width: '100%', padding: '10px', marginBottom: '10px' }
                    }, 
                        createElement('option', { value: 'one_time' }, 'One-time'),
                        createElement('option', { value: 'daily' }, 'Daily Subscription'),
                        createElement('option', { value: 'weekly' }, 'Weekly Subscription'),
                        createElement('option', { value: 'monthly' }, 'Monthly Subscription'),
                        createElement('option', { value: 'yearly' }, 'Yearly Subscription')
                    ),

                    // Billing Interval Dropdown (Conditional)
                    plan !== 'one_time' && optionsData[plan] && createElement(
                        'div',
                        { className: 'upay-interval-wrapper', style: { marginTop: '10px' } },
                        
                        createElement(
                            'label',
                            { 
                                htmlFor: 'upay_subscription_interval',
                                style: { display: 'block', marginBottom: '5px', fontWeight: '600', fontSize: '0.9em' }
                            },
                            'Billing Interval ',
                            createElement('span', { style: { color: 'red' } }, '*')
                        ),

                        createElement(
                            'select', 
                            {
                                id: 'upay_subscription_interval',
                                value: interval,
                                required: true,
                                className: 'wc-block-components-select__input',
                                onChange: (e) => handleSubscriptionChange(plan, e.target.value),
                                style: { width: '100%', padding: '10px' }
                            },
                            // Default Option
                            createElement('option', { value: '' }, 'Select interval'),
                            
                            // Map through your optionsData based on the current plan
                            Object.keys(optionsData[plan]).map((key) => 
                                createElement('option', { key: key, value: key }, optionsData[plan][key])
                            )
                        )
                    )
                ),
                
                // 1. Saved Cards Section
                is_whitelabled && saved_cards.length > 0 && createElement(
                    'div',
                    { className: 'saved-cards-section' },

                    createElement(
                        'span',
                        { className: 'payment-method-label' },
                        translation.saved_cards_label || 'Saved Cards'
                    ),

                    saved_cards.map( ( card ) =>
                        createElement(
                            'button',
                            {
                                key: card.token,
                                type: 'button',
                                className: `upay-payment-method ${ selectedCardToken === card.token ? 'is-selected' : '' }`,
                                onClick: () => handleSavedCardClick( card.token ),
                            },
                            createElement(
                                'span',
                                { className: 'payment-method-icon' },
                                createElement( 'img', {
                                    src: `${ plugin_url }assets/images/cc.png`,
                                    alt: card.number,
                                    title: card.number,
                                })
                            ),
                            createElement( 'span', { className: 'payment-method-label' }, card.number ),
                            createElement( 'span', { className: 'payment-method-price' }, `${ cart_total } ${ currency_display }` ),
                            createElement(
                                'span',
                                { className: 'payment-method-icon2' },
                                createElement( 'i', { className: 'fa fa-chevron-right' } )
                            )
                        )
                    ),

                    createElement(
                        'span',
                        { className: 'payment-method-label' },
                        translation.other_options_label
                    )
                ),

                // 2. Main Payment Buttons Section
                createElement(
                    'div',
                    { className: 'payment-buttons' },

                    is_whitelabled ? 
                    Object.keys( payment_icons ).map( ( key ) =>
                    createElement( 'div', { key },
                        createElement(
                            'button',
                            {
                                type: 'button',
                                className: `upay-payment-method ${ selectedPaymentType === key && ! selectedCardToken ? 'is-selected' : '' }`,
                                onClick: () => handlePaymentTypeClick( key ),
                            },
                            createElement( 'span', { className: 'payment-method-icon' }, getIconSrc( key ) ),
                            createElement( 'span', { className: 'payment-method-label' }, payment_icons[ key ] ),
                            createElement( 'span', { className: 'payment-method-price' }, `${ cart_total } ${ currency_display }` ),
                            createElement(
                                'span',
                                { className: 'payment-method-icon2' },
                                createElement( 'i', { className: 'fa fa-chevron-right' } )
                            )
                        ),
                        


                        key === 'cc' && save_card_enabled && createElement(
                            'label',
                            { className: 'switch-border' },
                            translation.save_card_label,
                            createElement(
                                'label',
                                { htmlFor: 'chkSaveCard', className: 'switch' },
                                createElement( 'input', {
                                    type: 'checkbox',
                                    id: 'chkSaveCard',
                                    checked: shouldSaveCard,
                                    onChange: ( e ) => toggleSaveCard( e.target.checked ),
                                }),
                                createElement( 'span', { className: 'slider round' } )
                            )
                        )
                    ))
                    : createElement( 'button',
                        {
                            type: 'button',
                            className: `upay-payment-method ${ selectedPaymentType === 'upayments' ? 'is-selected' : '' }`,
                            onClick: () => handlePaymentTypeClick( 'upayments' ),
                            id: 'upay-button-combined',
                        },
                        createElement( 'span',
                            { className: 'payment-method-icon', style: { marginRight: '5px' } },
                            Object.keys( payment_icons ).map( ( key ) =>
                                key === 'apple-pay-knet'
                                    ? null
                                    : createElement( 'img', {
                                        key,
                                        src: `${ plugin_url }assets/images/${ key }.png`,
                                        alt: payment_icons[ key ],
                                        title: payment_icons[ key ],
                                    })
                            )
                        ),
                        createElement( 'span', { className: 'payment-method-price' }, `${ cart_total } ${ currency_display }` ),
                        createElement( 
                            'span',
                            { className: 'payment-method-icon2' },
                            createElement( 'i', { className: 'fa fa-chevron-right' } )
                        )
                    )
                )
            );
        };

    registerPaymentMethod({
        name: 'upayments',
        label: 'Upayments',
        content: wp.element.createElement( Content ),
        edit: wp.element.createElement( Content ),
        canMakePayment: () => true,
        ariaLabel: 'Upayments',
        supports: {
            features: [ 'products' ],
        },
    });

});
