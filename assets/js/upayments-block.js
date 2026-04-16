const { registerPaymentMethod } = wc.wcBlocksRegistry;
const { getSetting } = wc.wcSettings;
const { useEffect, useState } = wp.element;
const { decodeEntities } = wp.htmlEntities;

// Get data passed from the PHP class (WCGatewayUPaymentsBlocks)
const settings = getSetting( 'upayments_data', {} ); 

const defaultTitle = decodeEntities( settings.title ) || 'UPayments';
const defaultDescription = decodeEntities( settings.description ) || 'Pay with UPayments using various methods.';

/**
 * Content component for the UPayments payment method in the Blocks checkout.
 */
const Content = ({ setValidationErrors, setPaymentMethodData }) => {
    // State to hold the selected payment type (knet, visa, etc.)
    const [ selectedType, setSelectedType ] = useState(null); 

    // This runs whenever the selectedType changes and sets the data for the API
    useEffect(() => {
        setPaymentMethodData({
            upayment_payment_type: selectedType,
            // Include other necessary fields for the API, like card_token if needed
        });

        // Basic validation: ensure a type is selected before checkout completion
        if (selectedType === null) {
            setValidationErrors({
                upayment_type_error: 'Please select a payment type.',
            });
        } else {
            setValidationErrors({});
        }
    }, [selectedType, setPaymentMethodData, setValidationErrors]);

    // Renders the list of payment options (like your new-design.php)
    // NOTE: This assumes your icons/options are structured in the settings.
    const paymentMethods = [
        { key: 'knet', label: 'KNET', icon: 'KNET icon HTML' },
        { key: 'visa', label: 'Credit/Debit Card', icon: 'CC icon HTML' },
        // ... dynamically load your payment types from settings if possible
    ];

    // NO JSX - Use raw JavaScript createElement
    return wp.element.createElement(
        'div', 
        { className: 'upayments-block-payment-methods' },
        paymentMethods.map(method => (
            wp.element.createElement(
                'div', 
                { 
                    key: method.key, 
                    className: `upay-payment-method ${selectedType === method.key ? 'is-selected' : ''}`,
                    onClick: () => setSelectedType(method.key)
                },
                // ... create children (input, label, icon) using createElement
            )
        ))
    );
};

// Configuration for the UPayments Payment Method Block
const UPaymentsBlock = {
    name: 'upayments',
    label: defaultTitle,
    content: <Content />,
    edit: <Content />, // Use the same component for edit view
    // Icon can be set here if you have an SVG
    ariaLabel: defaultTitle,
    description: defaultDescription,
    supports: {
        features: settings.supports,
    },
};

// Register the payment method in the block registry
registerPaymentMethod(UPaymentsBlock);