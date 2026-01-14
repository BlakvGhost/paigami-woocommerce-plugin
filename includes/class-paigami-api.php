<?php

if (!defined('ABSPATH')) {
    exit;
}

class Paigami_WC_API {
    
    private $api_key;
    private $secret_key;
    private $test_mode;
    private $base_url;
    private $timeout = 30;
    
    public function __construct() {
        $this->api_key = $this->get_option('api_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->test_mode = 'yes' === $this->get_option('test_mode', 'yes');
        $this->base_url = $this->test_mode 
            ? 'http://127.0.0.1:8004/api/v1' 
            : 'http://127.0.0.1:8004/api/v1';
    }
    
    private function get_option($key, $default = null) {
        return get_option($key, $default);
    }
    
    public function is_configured() {
        return !empty($this->api_key);
    }
    
    public function get_api_url() {
        return $this->base_url;
    }
    
    public function get_countries() {
        return $this->request('GET', '/metadata/countries');
    }
    
    public function get_wallets($country_id) {
        return $this->request('GET', "/metadata/wallets?country_id={$country_id}");
    }
    
    public function get_checkout_options($country_id, $amount, $currency = null) {
        $params = array(
            'country_id' => $country_id,
            'amount' => $amount
        );
        
        if ($currency) {
            $params['currency'] = $currency;
        }
        
        $query = http_build_query($params);
        return $this->request('GET', "/checkout/options?{$query}");
    }
    
    public function init_payment($payment_data) {
        return $this->request('POST', '/payments/init', $payment_data);
    }
    
    public function get_payment($reference) {
        return $this->request('GET', "/payments/{$reference}");
    }
    
    public function get_payments($limit = 20, $offset = 0) {
        $params = array(
            'limit' => $limit,
            'offset' => $offset
        );
        
        $query = http_build_query($params);
        return $this->request('GET', "/payments?{$query}");
    }
    
    private function request($method, $endpoint, $data = null) {
        $url = $this->base_url . $endpoint;
        
        $args = array(
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-API-Key' => $this->api_key,
                'User-Agent' => 'Paigami-WooCommerce/' . PAIGAMI_WC_VERSION . '; ' . get_bloginfo('url')
            )
        );
        
        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            throw new Exception($response->get_error_message());
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from Paigami API');
        }
        
        if ($http_code < 200 || $http_code >= 300) {
            $message = isset($data['message']) ? $data['message'] : 'API request failed';
            
            throw new Exception($message, $http_code);
        }
        
        return $data;
    }
    
    public function validate_webhook_signature($payload, $signature, $timestamp) {
        if (empty($signature) || empty($timestamp)) {
            return false;
        }
        
        $expected_signature = hash_hmac('sha256', $payload . $timestamp, $this->secret_key);
        
        return hash_equals($expected_signature, $signature);
    }
    
    public function format_phone_number($phone, $country_code) {
        $phone = preg_replace('/[^0-9+]/', '', $phone);
        
        if (strpos($phone, '+') !== 0) {
            $country_codes = array(
                'BJ' => '+229',
                'CI' => '+225',
                'SN' => '+221',
                'TG' => '+228',
                'NG' => '+234',
                'GH' => '+233',
                'KE' => '+254',
                'UG' => '+256',
                'TZ' => '+255',
                'RW' => '+250',
                'ZM' => '+260',
                'MW' => '+265'
            );
            
            if (isset($country_codes[$country_code])) {
                $phone = $country_codes[$country_code] . ltrim($phone, '0');
            }
        }
        
        return $phone;
    }
    
    public function get_currency_symbol($currency_code) {
        $symbols = array(
            'XOF' => 'CFA',
            'NGN' => '₦',
            'GHS' => '₵',
            'KES' => 'KSh',
            'UGX' => 'USh',
            'RWF' => 'RWF',
            'TZS' => 'TSh',
            'ZMW' => 'ZK',
            'MWK' => 'MK',
            'BIF' => 'BIF'
        );
        
        return isset($symbols[$currency_code]) ? $symbols[$currency_code] : $currency_code;
    }
    
    public function log($message, $level = 'info') {
        if ($this->get_option('debug_mode', 'no') === 'yes') {
            $logger = wc_get_logger();
            $context = array('source' => 'paigami-woocommerce');
            
            switch ($level) {
                case 'error':
                    $logger->error($message, $context);
                    break;
                case 'warning':
                    $logger->warning($message, $context);
                    break;
                case 'debug':
                    $logger->debug($message, $context);
                    break;
                default:
                    $logger->info($message, $context);
                    break;
            }
        }
    }
}