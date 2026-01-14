<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
    return;
}

class Paigami_WC_Block_Payment_Method extends Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType {
    
    protected $name = 'paigami';
    private $gateway;
    protected $settings;
    
    public function __construct($gateway) {
        $this->gateway = $gateway;
    }
    
    public function initialize() {
        $this->settings = get_option('woocommerce_' . $this->name . '_settings', array());
    }
    
    public function is_active() {
        return $this->gateway->is_available();
    }
    
    public function get_payment_method_script_handles() {
        wp_register_script(
            'paigami-blocks-integration',
            PAIGAMI_WC_PLUGIN_URL . 'assets/js/paigami-blocks.js',
            array(
                'wc-blocks-checkout',
                'wc-blocks-components',
                'wp-element',
                'wp-components',
                'wp-html-entities',
                'wp-i18n'
            ),
            PAIGAMI_WC_VERSION,
            true
        );
        
        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('paigami-blocks-integration', 'paigami-woocommerce', PAIGAMI_WC_PLUGIN_DIR . 'languages');
        }
        
        return 'paigami-blocks-integration';
    }
    
    public function get_payment_method_data() {
        return array(
            'title' => $this->get_setting('title', 'Mobile Money'),
            'description' => $this->get_setting('description', 'Pay with mobile money (MTN, Moov, Orange, M-Pesa, etc.)'),
            'supports' => $this->gateway->supports,
            'icon' => PAIGAMI_WC_PLUGIN_URL . 'assets/images/paigami-logo.png',
            'countries' => $this->get_cached_countries(),
            'api_url' => $this->gateway->get_api() ? $this->gateway->get_api()->get_api_url() : '',
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('paigami_nonce'),
            'strings' => array(
                'loading' => __('Loading...', 'paigami-woocommerce'),
                'select_country' => __('Select your country', 'paigami-woocommerce'),
                'select_wallet' => __('Select your provider', 'paigami-woocommerce'),
                'phone_label' => __('Phone Number', 'paigami-woocommerce'),
                'phone_placeholder' => '+229XXXXXXXX',
                'phone_help' => __('Enter your phone number with country code', 'paigami-woocommerce'),
                'otp_label' => __('OTP Code', 'paigami-woocommerce'),
                'otp_placeholder' => '123456',
                'otp_help' => __('Enter the 6-digit code sent to your phone', 'paigami-woocommerce'),
                'phone_required' => __('Phone number is required', 'paigami-woocommerce'),
                'otp_required' => __('OTP code is required for this provider', 'paigami-woocommerce'),
                'invalid_phone' => __('Please enter a valid phone number with country code', 'paigami-woocommerce'),
                'country_required' => __('Please select your country', 'paigami-woocommerce'),
                'wallet_required' => __('Please select your provider', 'paigami-woocommerce'),
                'conversion_info' => __('Amount will be converted to', 'paigami-woocommerce'),
                'fees_info' => __('Payment fees', 'paigami-woocommerce'),
                'total_amount' => __('Total to pay', 'paigami-woocommerce'),
                'processing' => __('Processing...', 'paigami-woocommerce'),
            )
        );
    }
    
    protected function get_setting($key, $default = '') {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }
    
    private function get_cached_countries() {
        $cache_key = 'paigami_countries';
        $countries = wp_cache_get($cache_key);
        
        if (false === $countries) {
            try {
                $api = $this->gateway->get_api();
                if ($api) {
                    $response = $api->get_countries();
                    $countries = isset($response['data']) ? $response['data'] : array();
                } else {
                    $countries = array();
                }
                wp_cache_set($cache_key, $countries, '', 300);
            } catch (Exception $e) {
                $countries = array();
            }
        }
        
        return $countries;
    }
}