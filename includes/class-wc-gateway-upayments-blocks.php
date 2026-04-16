<?php
// use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;
// class WCGatewayUPaymentsBlocks extends AbstractPaymentMethodType {
//     /**
//      * The name of the payment method block. This MUST match the ID of your WC_Payment_Gateway class.
//      * Assuming your gateway ID is 'upayments_id'
//      *
//      * @var string
//      */
//     protected $name = 'upayments'; 
//     protected $gateway;
    
//     // You need to define these methods for the block to work:

//     public function __construct( $gateway ) {
//         $this->gateway = $gateway; // Store the instance
//     }

//     /**
//      * Initializes the payment method and returns the payment method data.
//      */
//     public function initialize() {
//         // You need to load the settings for the main gateway here.
//         if ($this->gateway) {
//             $this->settings = get_option( 'woocommerce_' . $this->gateway . '_settings', [] );
//         } else {
//             $this->settings = [];
//         }
//     }

//     /**
//      * Registers the scripts and styles needed for the payment method.
//      */
//     public function get_payment_method_script_handles() {
        
//         // Define the script handle for your custom React component
//         $script_handle = 'upayments-block-frontend';
//         $plugin_url = plugin_dir_url( __FILE__ );
//         $script_url = $plugin_url . '../assets/js/upayments-block.js';
//         $style_url = $plugin_url . '../assets/css/new-design.css';
        
//         // 1. Register the main block component script
//         wp_register_script(
//             $script_handle,
//             $script_url,
//             ['wc-blocks-registry','wc-settings','wp-element','wp-html-entities','wp-i18n'],
//             '3.0.0',
//             true
//         );
        
//         // 2. Localize the script with data needed by the JS component
//         wp_localize_script(
//             $script_handle,
//             'upayments_data',
//             $this->get_payment_method_data() // Pass all gateway data here
//         );

//         // We assume the new-design.css has the necessary styling
//         wp_enqueue_style( 'upayments-block-style',$style_url, [],'3.0.0');

//         return [ $script_handle ];
//     }
    
//     /**
//      * Returns an array of key/value pairs of data to be sent to the payment method's JS component.
//      * This is how you pass your feature toggles to the frontend block.
//      */
//     public function get_payment_method_data() {
//         return [
//             'title'       => $this->get_setting( 'title' ),
//             'description' => $this->get_setting( 'description' ),
//             'icon'        => $this->get_setting( 'icon_url' ),
//             'supports'    => $this->get_supported_features(),
//             // Pass the feature flags here:
//             'saveCardEnabled' => $this->get_setting( 'enable_save_card' ) == 'yes',
//             'useNewDesign'    => $this->get_setting( 'use_new_design' ) == 'yes',
//         ];
//     }
    
//     /**
//      * Returns the array of supported features.
//      */
//     public function get_supported_features() {
//         // Retrieve supported features from the main gateway class if necessary
//         return ['products', 'default_credit_card_form'];
//     }
// }
// Note: Ensure this file is included via require_once in Upayments.php

// Use the necessary Blocks Interfaces
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * UPayments Blocks Integration Class
 *
 * This class handles the integration of the UPayments gateway with the 
 * WooCommerce Block Checkout (including data and assets registration).
 */
final class WCGatewayUPaymentsBlocks extends AbstractPaymentMethodType {

    /**
     * The ID of the gateway, passed from the main plugin file.
     * @var string
     */
    protected $name;

    /**
     * @var WC_Gateway_Upayments Instance of the main gateway class.
     */
    protected $gateway;

    /**
     * Constructor
     *
     * @param string $name The gateway ID (e.g., 'upayments').
     */
    public function __construct() {
        $this->gateway = new WC_Upayments(); 
    }

    // --- Implementation of AbstractPaymentMethodType (Payment Processing) ---

    /**
     * Initializes the payment method type.
     * This is where you can add any necessary hooks.
     */
    public function initialize() {
        // No additional initialization needed here for a basic offsite redirect.
    }

    /**
     * Returns the name of the payment method.
     * This must match the ID used to register the asset.
     *
     * @return string
     */
    public function get_name() {
        return $this->gateway->id;
    }

    /**
     * Returns the title of the payment method.
     *
     * @return string
     */
    public function get_title() {
        return $this->gateway->get_title();
    }

    /**
     * Returns the description of the payment method.
     *
     * @return string
     */
    public function get_description() {
        return $this->gateway->get_description();
    }

    /**
     * Returns an array of supported features.
     *
     * @return array
     */
    public function get_supported_features() {
        // Return features supported by the main gateway class
        return array_filter( $this->gateway->supports );
    }


    // --- Implementation of PaymentMethodAsset (Frontend Assets) ---

    /**
     * Returns an array of script handles that should be enqueued.
     *
     * @return string[]
     */
    public function get_script_handles() {
        $script_handle = $this->gateway . '-blocks-integration';

        // NOTE: Use a file name like 'upayments-blocks-integration.js'
        wp_register_script(
            $script_handle,
            UP_PLUGIN_URL . 'assets/js/checkout/upayments-blocks-integration.js',
            [ 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n' ],
            $this->gateway->version, // Use the version of your main gateway class
            true
        );

        return [ $script_handle ];
    }

    /**
     * Returns an array of key/value pairs that are passed to the JS frontend.
     *
     * @return array
     */
    public function get_payment_method_data() {
        // Instantiate your main gateway class to access its methods (like getPaymentIcons, getSavedCards)
        $gateway = new WCUpayments(); 
        $whitelabled = false;
        $icons = [];
        $saved_cards = [];
        $is_logged_in = false;
        $save_card_enabled = $gateway->get_option('save_card_enabled') === 'yes';

        // 1. Get payment icons and whitelabeled status
        $payment_data = $gateway->getPaymentIcons(); 
        if ($payment_data) {
            $icons = $payment_data['payment'];
            $whitelabled = $payment_data['whitelabled'];
        }

        // 2. Get saved cards and login status
        $loggedInUser = $gateway->get_logged_in_user_phone_number();
        if ($loggedInUser['success']) {
            $is_logged_in = true;
            $savedCards = $gateway->getSavedCards($loggedInUser['phone']);
            if ($savedCards && $savedCards['result'] == 'success') {
                $saved_cards = $savedCards['data'];
            }
        }
        
        // 3. Get total and currency display
        $total = WC()->cart->get_total('');
        $language = get_locale();
        $currency_code = get_woocommerce_currency();
        
        // Matches your old logic for currency display
        if (strpos($language, 'en') === 0) {
            $currency_display = $currency_code;
        } else {
            $currency_display = get_woocommerce_currency_symbol();
        }

        // 4. Return all data to the block
        return [
            'is_whitelabled'    => $whitelabled,
            'payment_icons'     => $icons,
            'saved_cards'       => $saved_cards,
            'is_logged_in'      => $is_logged_in,
            'save_card_enabled' => $save_card_enabled,
            'cart_total'        => $total,
            'currency_display'  => $currency_display,
            'plugin_url'        => UP_PLUGIN_URL, // Ensure this constant is still available
            'translation'       => [
                'save_card_label' => __('For faster and more secure checkout. Save your card details.', $gateway->domain),
                'saved_cards_label' => __('Saved Cards', $gateway->domain),
                'other_options_label' => __('Other Options', $gateway->domain),
            ]
        ];
        // Pass any necessary configuration or custom data to the frontend JS here.
    }
}
