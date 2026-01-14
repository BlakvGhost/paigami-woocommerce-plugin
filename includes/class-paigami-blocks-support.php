<?php

if (!defined('ABSPATH')) {
    exit;
}

class Paigami_WC_Blocks_Support {
    
    private $gateway;
    private $api;
    public $name = 'paigami';
    
    public function __construct($gateway) {
        $this->gateway = $gateway;
        $this->api = $gateway->get_api(); // Utiliser la méthode publique pour accéder à l'API
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'register_payment_method_type'));
        add_action('enqueue_block_assets', array($this, 'enqueue_block_assets'));
    }
    
    public function register_payment_method_type($payment_method_registry) {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }
        
        $payment_method_registry->register(new Paigami_WC_Block_Payment_Method($this->gateway));
    }
    
    public function enqueue_block_assets() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        
        // Handle both old and new WooCommerce Blocks dependencies
        $dependencies = array('wp-element', 'wp-components');
        
        // Add the appropriate wc-blocks dependency based on what's available
        if (wp_script_is('wc-blocks-checkout', 'registered')) {
            $dependencies[] = 'wc-blocks-checkout';
        } elseif (wp_script_is('wc-blocks-registry', 'registered')) {
            $dependencies[] = 'wc-blocks-registry';
        }
        
        wp_enqueue_script(
            'paigami-blocks',
            PAIGAMI_WC_PLUGIN_URL . 'assets/js/paigami-blocks.js',
            $dependencies,
            PAIGAMI_WC_VERSION,
            true
        );
        
        wp_localize_script('paigami-blocks', 'paigami_blocks_params', array(
            'api_url' => $this->api->get_api_url(),
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('paigami_nonce'),
            'gateway_id' => 'paigami',
            'supports' => array(
                'features' => $this->gateway->supports
            ),
            'countries' => $this->get_cached_countries_for_blocks(),
            'strings' => array(
                'loading' => __('Loading...', 'paigami-woocommerce'),
                'select_country' => __('Select your country', 'paigami-woocommerce'),
                'select_wallet' => __('Select your provider', 'paigami-woocommerce'),
                'phone_label' => __('Phone Number', 'paigami-woocommerce'),
                'phone_placeholder' => '+229XXXXXXXX',
                'otp_label' => __('OTP Code', 'paigami-woocommerce'),
                'otp_placeholder' => '123456',
                'phone_required' => __('Phone number is required', 'paigami-woocommerce'),
                'otp_required' => __('OTP code is required for this provider', 'paigami-woocommerce'),
                'invalid_phone' => __('Please enter a valid phone number with country code', 'paigami-woocommerce'),
                'country_required' => __('Please select your country', 'paigami-woocommerce'),
                'wallet_required' => __('Please select your provider', 'paigami-woocommerce'),
                'conversion_info' => __('Amount will be converted to', 'paigami-woocommerce'),
                'fees_info' => __('Payment fees', 'paigami-woocommerce'),
                'total_amount' => __('Total to pay', 'paigami-woocommerce'),
            )
        ));
    }
    
    private function get_cached_countries_for_blocks() {
        try {
            if ($this->api) {
                $response = $this->api->get_countries();
                return isset($response['data']) ? $response['data'] : array();
            }
            return array();
        } catch (Exception $e) {
            return array();
        }
    }
}