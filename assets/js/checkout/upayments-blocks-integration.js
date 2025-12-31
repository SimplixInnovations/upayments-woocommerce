/**
 * External dependencies
 */
import { getSetting } from '@woocommerce/settings';
import { useCallback, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';

// --- Data passed from PHP (get_payment_method_data) ---
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

// Constants for extension data keys (must match the keys used in PHP's get_response_from_api)
const NAMESPACE = 'upayments';
const PAYMENT_TYPE_KEY = 'upayment_payment_type';
const CARD_TOKEN_KEY = 'card_token';
const SAVE_CARD_KEY = 'save_card';


/**
 * Payment method content and logic.
 */
const Content = () => {
    // State to manage the user's selection
	const [ selectedPaymentType, setSelectedPaymentType ] = useState( null );
	const [ selectedCardToken, setSelectedCardToken ] = useState( null );
	const [ shouldSaveCard, setShouldSaveCard ] = useState( is_logged_in ); // Default logic from old PHP file
    
    // Get the tool to update checkout data
    const { setExtensionData } = useDispatch( 'wc/store/checkout' );

	/**
	 * Updates the extension data in the store. This data is sent to the server 
     * in the payment processing request, replacing the old hidden fields.
	 */
	const updateExtensionData = useCallback( ( type, token, save ) => {
        setExtensionData( NAMESPACE, {
            [PAYMENT_TYPE_KEY]: type,
            [CARD_TOKEN_KEY]: token,
            [SAVE_CARD_KEY]: save ? '1' : '0',
        } );
	}, [setExtensionData] );

    // Handles selecting a new payment type (e.g., KNET, CC, etc.)
    const handlePaymentTypeClick = ( type ) => {
        setSelectedPaymentType( type );
        setSelectedCardToken( null ); // Clear token if switching to a new card
        
        // Update the checkout store
        updateExtensionData( type, '', shouldSaveCard );
    };

    // Handles clicking a saved card button
    const handleSavedCardClick = ( token ) => {
        setSelectedCardToken( token );
        setSelectedPaymentType( 'cc' ); // Saved cards are credit cards
        setShouldSaveCard( false ); // Cannot 'save' an already saved card.
        
        // Update the checkout store
        updateExtensionData( 'cc', token, false );
    };

    // Handles toggling the 'Save Card' switch
    const toggleSaveCard = ( checked ) => {
        setShouldSaveCard( checked );
        
        // Only update save state if a payment type has been selected
        if ( selectedPaymentType ) {
            updateExtensionData( selectedPaymentType, selectedCardToken || '', checked );
        }
    }

    // Helper function to render the correct icons (matches your old logic)
    const getIconSrc = ( key ) => {
        if ( key === 'apple-pay-knet' ) {
            return (
                <>
                    <img src={ `${plugin_url}assets/images/apple-pay.png` } alt="Apple Pay" title="Apple Pay" />
                    <img src={ `${plugin_url}assets/images/knet.png` } alt="KNET" title="KNET" />
                </>
            );
        }
        if ( key === 'apple-pay' ) {
            return (
                <>
                    <img src={ `${plugin_url}assets/images/apple-pay.png` } alt="Apple Pay" title="Apple Pay" />
                    <img src={ `${plugin_url}assets/images/cc.png` } alt="Credit Card" title="Credit Card" />
                </>
            );
        }
        return <img src={ `${plugin_url}assets/images/${key}.png` } alt={ payment_icons[key] } title={ payment_icons[key] } />;
    }

	return (
		<div className="upayments-payment-methods">
            {/* 1. Saved Cards Section */}
            { is_whitelabled && saved_cards.length > 0 && (
                <div className="saved-cards-section">
                    <span className="payment-method-label">{ translation.saved_cards_label }</span>
                    { saved_cards.map( ( card ) => (
                        <button 
                            key={ card.token }
                            type="button" 
                            className={ `upay-payment-method ${ selectedCardToken === card.token ? 'is-selected' : '' }` }
                            onClick={ () => handleSavedCardClick( card.token ) }
                        >
                            <span className="payment-method-icon">
                                <img src={ `${plugin_url}assets/images/cc.png` } alt={ card.number } title={ card.number } />
                            </span>
                            <span className="payment-method-label">{ card.number }</span>
                            <span className="payment-method-price">{ cart_total } { currency_display }</span>
                            <span className="payment-method-icon2"><i className="fa fa-chevron-right"></i></span>
                        </button>
                    ) ) }
                    <span className="payment-method-label">{ translation.other_options_label }</span>
                </div>
            ) }

            {/* 2. Main Payment Buttons Section */}
            <div className="payment-buttons">
                { is_whitelabled ? (
                    // Whitelabeled buttons (individual buttons)
                    Object.keys( payment_icons ).map( ( key ) => (
                        <div key={ key }>
                            <button 
                                type="button" 
                                className={ `upay-payment-method ${ selectedPaymentType === key && !selectedCardToken ? 'is-selected' : '' }` }
                                onClick={ () => handlePaymentTypeClick( key ) }
                            >
                                <span className="payment-method-icon">{ getIconSrc( key ) }</span>
                                <span className="payment-method-label">{ payment_icons[key] }</span>
                                <span className="payment-method-price">{ cart_total } { currency_display }</span>
                                <span className="payment-method-icon2"><i className="fa fa-chevron-right"></i></span>
                            </button>

                            {/* Save Card Toggle (only for 'cc' method when whitelabeled) */}
                            { key === 'cc' && save_card_enabled && (
                                <label className="switch-border">
                                    { translation.save_card_label }
                                    <label className="switch">
                                        <input 
                                            type="checkbox" 
                                            id="chkSaveCard" 
                                            checked={ shouldSaveCard } 
                                            onChange={ (e) => toggleSaveCard(e.target.checked) }
                                        />
                                        <span className="slider round"></span>
                                    </label>
                                </label>
                            ) }
                        </div>
                    ) )
                ) : (
                    // Non-Whitelabeled button (single combined button)
                    <button 
                        type="button" 
                        className={ `upay-payment-method ${ selectedPaymentType === 'upayments' ? 'is-selected' : '' }` }
                        onClick={ () => handlePaymentTypeClick( 'upayments' ) }
                        id="upay-button-combined"
                    >
                        <span className="payment-method-icon" style={{ marginRight: '5px' }}>
                            { Object.keys( payment_icons ).map( ( key ) => (
                                key !== 'apple-pay-knet' && (
                                    <img 
                                        key={ key }
                                        src={ `${plugin_url}assets/images/${key}.png` } 
                                        alt={ payment_icons[key] } 
                                        title={ payment_icons[key] } 
                                    />
                                )
                            ) ) }
                        </span>
                        <span className="payment-method-price">{ cart_total } { currency_display }</span>
                        <span className="payment-method-icon2"><i className="fa fa-chevron-right"></i></span>
                    </button>
                )}
            </div>
		</div>
	);
};

// ... (Rest of the file: Label component, upaymentsBlocksIntegration object) ...