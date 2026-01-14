<?php

if (!defined('ABSPATH')) {
    exit;
}

class Paigami_WC_Blocks_Support
{

    private $gateway;
    private $api;
    public $name = 'paigami';

    public function __construct($gateway)
    {
        $this->gateway = $gateway;
        $this->api = $gateway->get_api();
        $this->init_hooks();
    }

    private function init_hooks()
    {
        add_action('woocommerce_blocks_payment_method_type_registration', array($this, 'register_payment_method_type'));
        add_action('woocommerce_blocks_loaded', array($this, 'on_blocks_loaded'));
    }

    public function on_blocks_loaded()
    {
        // Enregistrer les données de configuration pour le frontend
        add_action('wp_enqueue_scripts', array($this, 'enqueue_block_data'), 5);
    }

    public function register_payment_method_type($payment_method_registry)
    {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        require_once PAIGAMI_WC_PLUGIN_DIR . 'includes/class-paigami-block-payment-method.php';
        $payment_method_registry->register(new Paigami_WC_Block_Payment_Method($this->gateway));
    }

    public function enqueue_block_data()
    {
        if (!is_checkout() && !is_cart()) {
            return;
        }

        $script_data = array(
            'title' => $this->gateway->get_option('title', 'Mobile Money'),
            'description' => $this->gateway->get_option('description', 'Pay with mobile money'),
            'supports' => $this->gateway->supports,
            'countries' => $this->get_cached_countries(),
            'api_url' => $this->api->get_api_url(),
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

        // Enregistrer les données avec wcSettings pour WooCommerce Blocks
        if (function_exists('wp_add_inline_script')) {
            $inline_script = sprintf(
                "var wcPaigamiSettings = %s;
                if (typeof window.wc !== 'undefined' && typeof window.wc.wcSettings !== 'undefined') {
                    window.wc.wcSettings.registerPaymentMethodData('paigami', wcPaigamiSettings);
                }",
                wp_json_encode($script_data)
            );

            wp_add_inline_script(
                'wc-blocks-registry',
                $inline_script,
                'before'
            );
        }
    }

    private function get_cached_countries()
    {
        $cache_key = 'paigami_countries_blocks';
        $countries = get_transient($cache_key);

        if (false === $countries) {
            try {
                $response = $this->api->get_countries();
                $countries = isset($response['data']) ? $response['data'] : array();
                set_transient($cache_key, $countries, 300); // Cache 5 minutes
            } catch (Exception $e) {
                $this->api->log('Error loading countries for blocks: ' . $e->getMessage(), 'error');
                $countries = array();
            }
        }

        return $countries;
    }
}
