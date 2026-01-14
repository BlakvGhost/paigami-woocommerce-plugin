<?php

if (!defined('ABSPATH')) {
    exit;
}

class Paigami_WC_API
{
    private $api_key;
    private $secret_key;
    private $test_mode;
    private $base_url;
    private $timeout = 30;

    public function __construct()
    {
        $this->api_key = $this->get_option('api_key');
        $this->secret_key = $this->get_option('secret_key');
        $this->test_mode = 'yes' === $this->get_option('test_mode', 'yes');
        $this->base_url = $this->test_mode
            ? 'http://127.0.0.1:8004/api/v1'
            : 'http://127.0.0.1:8004/api/v1';
    }

    private function get_option($key, $default = null)
    {
        $settings = get_option('woocommerce_paigami_settings', array());
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    public function is_configured()
    {
        return !empty($this->api_key);
    }

    public function get_api_url()
    {
        return $this->base_url;
    }

    public function get_countries()
    {
        try {
            $countries = $this->request('GET', '/metadata/countries');
            // Debug: afficher la réponse complète
            error_log('Paigami API Response: ' . print_r($countries, true));

            return $countries;
        } catch (Exception $e) {
            // Debug: afficher l'erreur
            error_log('Paigami API Error in get_countries: ' . $e->getMessage());
            error_log('Error Code: ' . $e->getCode());
            // Retourner un tableau vide en cas d'erreur
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => array()
            );
        }
    }

    public function get_wallets($country_id)
    {
        try {
            return $this->request('GET', "/metadata/wallets?country_id={$country_id}");
        } catch (Exception $e) {
            error_log('Paigami API Error in get_wallets: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => array()
            );
        }
    }

    public function get_checkout_options($country_id, $amount, $currency = null)
    {
        $params = array(
            'country_id' => $country_id,
            'amount' => $amount
        );

        if ($currency) {
            $params['currency'] = $currency;
        }

        $query = http_build_query($params);

        try {
            return $this->request('GET', "/checkout/options?{$query}");
        } catch (Exception $e) {
            error_log('Paigami API Error in get_checkout_options: ' . $e->getMessage());
            return array(
                'success' => false,
                'message' => $e->getMessage(),
                'data' => array()
            );
        }
    }

    public function init_payment($payment_data)
    {
        return $this->request('POST', '/payments/init', $payment_data);
    }

    public function get_payment($reference)
    {
        return $this->request('GET', "/payments/{$reference}");
    }

    public function get_payments($limit = 20, $offset = 0)
    {
        $params = array(
            'limit' => $limit,
            'offset' => $offset
        );

        $query = http_build_query($params);
        return $this->request('GET', "/payments?{$query}");
    }

    private function request($method, $endpoint, $data = null)
    {
        $url = $this->base_url . $endpoint;

        // Debug: Log la requête
        error_log("Paigami API Request: {$method} {$url}");
        error_log("API Key configured: " . (!empty($this->api_key) ? 'Yes' : 'No'));

        $args = array(
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-API-Key' => $this->api_key,
                'User-Agent' => 'Paigami-WooCommerce/' . PAIGAMI_WC_VERSION . '; ' . get_bloginfo('url')
            ),
            'sslverify' => false // Pour le développement local uniquement
        );

        if ($data && in_array($method, array('POST', 'PUT', 'PATCH'))) {
            $args['body'] = json_encode($data);
            error_log("Request Body: " . json_encode($data));
        }

        $response = wp_remote_request($url, $args);

        // Debug: Vérifier si c'est une erreur WordPress
        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log("WordPress Error: {$error_message}");
            throw new Exception($error_message);
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        // Debug: Log la réponse brute
        error_log("HTTP Code: {$http_code}");
        error_log("Response Body: {$body}");

        // Vérifier si le body est vide
        if (empty($body)) {
            error_log("Empty response body from API");
            throw new Exception('Empty response from Paigami API');
        }

        $data = json_decode($body, true);

        // Debug: Vérifier l'erreur JSON
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON Error: " . json_last_error_msg());
            error_log("Raw Body: {$body}");
            throw new Exception('Invalid JSON response from Paigami API: ' . json_last_error_msg());
        }

        // Vérifier le code HTTP
        if ($http_code < 200 || $http_code >= 300) {
            $message = isset($data['message']) ? $data['message'] : 'API request failed';
            error_log("API Error Response: {$message}");

            // Log les détails supplémentaires si disponibles
            if (isset($data['errors'])) {
                error_log("API Errors: " . print_r($data['errors'], true));
            }

            throw new Exception($message, $http_code);
        }

        return $data;
    }

    public function validate_webhook_signature($payload, $signature, $timestamp)
    {
        if (empty($signature) || empty($timestamp)) {
            return false;
        }

        $expected_signature = hash_hmac('sha256', $payload . $timestamp, $this->secret_key);

        return hash_equals($expected_signature, $signature);
    }

    public function format_phone_number($phone, $country_code)
    {
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

    public function get_currency_symbol($currency_code)
    {
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

    public function log($message, $level = 'info')
    {
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

    /**
     * Méthode de debug pour tester la connexion API
     */
    public function test_connection()
    {
        error_log("=== Paigami API Test Connection ===");
        error_log("Base URL: {$this->base_url}");
        error_log("API Key: " . (!empty($this->api_key) ? substr($this->api_key, 0, 10) . '...' : 'NOT SET'));
        error_log("Test Mode: " . ($this->test_mode ? 'Yes' : 'No'));

        try {
            $response = $this->get_countries();
            error_log("Connection successful!");
            error_log("Response: " . print_r($response, true));
            return true;
        } catch (Exception $e) {
            error_log("Connection failed: " . $e->getMessage());
            return false;
        }
    }
}
