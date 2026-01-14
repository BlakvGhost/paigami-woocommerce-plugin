<?php

if (!defined('ABSPATH')) {
    exit;
}

class Paigami_WC_Gateway extends WC_Payment_Gateway
{

    private $api;

    // Declare properties to avoid deprecation warnings
    public $id;
    public $icon;
    public $has_fields;
    public $method_title;
    public $method_description;
    public $supports;
    public $title;
    public $description;
    public $enabled;
    public $test_mode;
    public $blocks_support;
    public $form_fields;
    public $settings;

    public function __construct()
    {
        $this->id = 'paigami';
        $this->icon = apply_filters('woocommerce_paigami_icon', PAIGAMI_WC_PLUGIN_URL . 'assets/images/paigami-logo.png');
        $this->has_fields = true;
        $this->method_title = __('Paigami Unified Payments', 'paigami-woocommerce');
        $this->method_description = __('Paigami is a smart payment orchestrator that automatically routes payments to the best available gateway based on country, fees, and success rates.', 'paigami-woocommerce');

        $this->supports = array(
            'products',
            'refunds'
        );

        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->test_mode = 'yes' === $this->get_option('test_mode');

        $this->api = new Paigami_WC_API();

        add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
        add_action('woocommerce_api_paigami_webhook', array($this, 'handle_webhook'));
        add_action('wp_ajax_paigami_get_wallets', array($this, 'ajax_get_wallets'));
        add_action('wp_ajax_nopriv_paigami_get_wallets', array($this, 'ajax_get_wallets'));
        add_action('wp_ajax_paigami_get_checkout_options', array($this, 'ajax_get_checkout_options'));
        add_action('wp_ajax_nopriv_paigami_get_checkout_options', array($this, 'ajax_get_checkout_options'));

        // Initialize Blocks support if available
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            add_action('woocommerce_blocks_loaded', array($this, 'init_blocks_support'));
        } else {
            $this->blocks_support = null;
        }
    }

    /**
     * Initialize Blocks support
     */
    public function init_blocks_support()
    {
        if (!class_exists('Paigami_WC_Blocks_Support')) {
            require_once PAIGAMI_WC_PLUGIN_DIR . 'includes/class-paigami-blocks-support.php';
        }
        $this->blocks_support = new Paigami_WC_Blocks_Support($this);
    }

    public function init_form_fields()
    {
        $this->form_fields = array(
            'enabled' => array(
                'title' => __('Enable/Disable', 'paigami-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable Paigami Unified Payments', 'paigami-woocommerce'),
                'default' => 'no'
            ),
            'title' => array(
                'title' => __('Title', 'paigami-woocommerce'),
                'type' => 'text',
                'description' => __('This controls the title which the user sees during checkout.', 'paigami-woocommerce'),
                'default' => __('Mobile Money', 'paigami-woocommerce'),
                'desc_tip' => true
            ),
            'description' => array(
                'title' => __('Description', 'paigami-woocommerce'),
                'type' => 'textarea',
                'description' => __('This controls the description which the user sees during checkout.', 'paigami-woocommerce'),
                'default' => __('Pay with mobile money (MTN, Moov, Orange, M-Pesa, etc.)', 'paigami-woocommerce')
            ),
            'test_mode' => array(
                'title' => __('Test Mode', 'paigami-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable test mode', 'paigami-woocommerce'),
                'default' => 'yes',
                'description' => __('Place the payment gateway in test mode using test API keys.', 'paigami-woocommerce')
            ),
            'api_key' => array(
                'title' => __('API Key', 'paigami-woocommerce'),
                'type' => 'text',
                'description' => __('Get your API keys from your Paigami dashboard.', 'paigami-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'secret_key' => array(
                'title' => __('Secret Key', 'paigami-woocommerce'),
                'type' => 'text',
                'description' => __('Get your secret keys from your Paigami dashboard.', 'paigami-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'webhook_secret' => array(
                'title' => __('Webhook Secret', 'paigami-woocommerce'),
                'type' => 'text',
                'description' => __('Optional: webhook secret for additional security.', 'paigami-woocommerce'),
                'default' => '',
                'desc_tip' => true
            ),
            'debug_mode' => array(
                'title' => __('Debug Mode', 'paigami-woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable debug logging', 'paigami-woocommerce'),
                'default' => 'no',
                'description' => __('Log API requests and responses for debugging.', 'paigami-woocommerce')
            )
        );
    }

    public function admin_options()
    {
?>
        <h3><?php echo $this->method_title; ?></h3>
        <p><?php echo $this->method_description; ?></p>

        <?php if (!$this->api->is_configured() && $this->enabled === 'yes'): ?>
            <div class="notice notice-error">
                <p><?php _e('Paigami API keys are required. Please configure your API keys below.', 'paigami-woocommerce'); ?></p>
            </div>
        <?php endif; ?>

        <table class="form-table">
            <?php $this->generate_settings_html(); ?>
        </table>

        <h4><?php _e('Webhook Configuration', 'paigami-woocommerce'); ?></h4>
        <p><?php _e('Configure the following webhook URL in your Paigami dashboard:', 'paigami-woocommerce'); ?></p>
        <code><?php echo home_url('/wc-api/paigami_webhook'); ?></code>
    <?php
    }

    public function payment_fields()
    {
        if ($description = $this->get_description()) {
            echo wpautop(wptexturize($description));
        }

        if (!$this->api->is_configured()) {
            echo '<div class="woocommerce-error">' . __('Payment gateway is not properly configured. Please contact support.', 'paigami-woocommerce') . '</div>';
            return;
        }

        $this->payment_form();
    }

    private function payment_form()
    {
        $countries = $this->get_cached_countries();

        if (!$countries) {
            echo '<div class="woocommerce-info">' . __('Loading payment options...', 'paigami-woocommerce') . '</div>';
            return;
        }

    ?>
        <div id="paigami-payment-form">
            <div class="paigami-field">
                <label for="paigami-country"><?php _e('Country', 'paigami-woocommerce'); ?> <span class="required">*</span></label>
                <select id="paigami-country" name="paigami_country" class="paigami-select" required>
                    <option value=""><?php _e('Select your country', 'paigami-woocommerce'); ?></option>
                    <?php foreach ($countries as $country): ?>
                        <option value="<?php echo esc_attr($country['id']); ?>" data-currency="<?php echo esc_attr($country['currency']); ?>">
                            <?php echo esc_html($country['name']); ?> (<?php echo esc_html($country['currency']); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="paigami-field" id="paigami-wallet-field" style="display: none;">
                <label for="paigami-wallet"><?php _e('Mobile Money Provider', 'paigami-woocommerce'); ?> <span class="required">*</span></label>
                <select id="paigami-wallet" name="paigami_wallet" class="paigami-select" required>
                    <option value=""><?php _e('Select your provider', 'paigami-woocommerce'); ?></option>
                </select>
                <div class="wallet-logo" id="wallet-logo"></div>
            </div>

            <div class="paigami-field" id="paigami-phone-field" style="display: none;">
                <label for="paigami-phone"><?php _e('Phone Number', 'paigami-woocommerce'); ?> <span class="required">*</span></label>
                <input type="tel" id="paigami-phone" name="paigami_phone" class="paigami-input" placeholder="+229XXXXXXXX" required>
                <small class="paigami-hint"><?php _e('Enter your phone number with country code', 'paigami-woocommerce'); ?></small>
            </div>

            <div class="paigami-field" id="paigami-otp-field" style="display: none;">
                <label for="paigami-otp"><?php _e('OTP Code', 'paigami-woocommerce'); ?> <span class="required">*</span></label>
                <input type="text" id="paigami-otp" name="paigami_otp" class="paigami-input" placeholder="123456" maxlength="6">
                <small class="paigami-hint"><?php _e('Enter the 6-digit code sent to your phone', 'paigami-woocommerce'); ?></small>
            </div>

            <div class="paigami-conversion-info" id="paigami-conversion" style="display: none;">
                <div class="conversion-amount">
                    <span class="original-amount"></span>
                    <span class="conversion-arrow">→</span>
                    <span class="converted-amount"></span>
                </div>
                <small class="conversion-rate"></small>
            </div>

            <div class="paigami-fees-info" id="paigami-fees" style="display: none;">
                <div class="fees-breakdown"></div>
                <div class="total-amount"></div>
            </div>
        </div>

        <div id="paigami-loading" style="display: none;">
            <div class="paigami-spinner"></div>
            <p><?php _e('Processing...', 'paigami-woocommerce'); ?></p>
        </div>
<?php
    }

    public function validate_fields()
    {
        if (!$this->api->is_configured()) {
            wc_add_notice(__('Payment gateway is not configured.', 'paigami-woocommerce'), 'error');
            return false;
        }

        $country = isset($_POST['paigami_country']) ? sanitize_text_field($_POST['paigami_country']) : '';
        $wallet = isset($_POST['paigami_wallet']) ? sanitize_text_field($_POST['paigami_wallet']) : '';
        $phone = isset($_POST['paigami_phone']) ? sanitize_text_field($_POST['paigami_phone']) : '';
        $otp = isset($_POST['paigami_otp']) ? sanitize_text_field($_POST['paigami_otp']) : '';

        if (empty($country)) {
            wc_add_notice(__('Please select your country.', 'paigami-woocommerce'), 'error');
        }

        if (empty($wallet)) {
            wc_add_notice(__('Please select your mobile money provider.', 'paigami-woocommerce'), 'error');
        }

        if (empty($phone)) {
            wc_add_notice(__('Please enter your phone number.', 'paigami-woocommerce'), 'error');
        } elseif (!preg_match('/^\+[1-9]\d{1,14}$/', $phone)) {
            wc_add_notice(__('Please enter a valid phone number with country code.', 'paigami-woocommerce'), 'error');
        }

        return true;
    }

    public function ajax_get_wallets()
    {
        check_ajax_referer('paigami_nonce', 'nonce');

        $country_id = isset($_POST['country_id']) ? sanitize_text_field($_POST['country_id']) : '';

        if (empty($country_id)) {
            wp_send_json_error(array('message' => __('Country ID is required', 'paigami-woocommerce')));
        }

        try {
            $wallets = $this->api->get_wallets($country_id);
            wp_send_json_success($wallets);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    public function ajax_get_checkout_options()
    {
        check_ajax_referer('paigami_nonce', 'nonce');

        $country_id = isset($_POST['country_id']) ? sanitize_text_field($_POST['country_id']) : '';
        $amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
        $currency = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : '';

        if (empty($country_id) || $amount <= 0) {
            wp_send_json_error(array('message' => __('Invalid parameters', 'paigami-woocommerce')));
        }

        try {
            $options = $this->api->get_checkout_options($country_id, $amount * 100, $currency);
            wp_send_json_success($options);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }

    private function get_cached_countries()
    {
        $cache_key = 'paigami_countries';
        $countries = wp_cache_get($cache_key);

        if (false === $countries) {
            try {
                $response = $this->api->get_countries();
                $countries = isset($response['data']) ? $response['data'] : array();
                wp_cache_set($cache_key, $countries, '', 300); // Cache for 5 minutes
            } catch (Exception $e) {
                $countries = array();
            }
        }

        return $countries;
    }

    /**
     * Public method to access API instance
     */
    public function get_api()
    {
        return $this->api;
    }

    /**
     * Traiter les données de paiement des Blocks
     */
    public function process_payment($order_id)
    {
        $order = wc_get_order($order_id);

        if (!$order) {
            return array(
                'result' => 'failure',
                'redirect' => ''
            );
        }

        try {
            // Récupérer les données depuis POST ou depuis les métadonnées de la commande (Blocks)
            $country = $this->get_post_value('paigami_country');
            $wallet = $this->get_post_value('paigami_wallet');
            $phone = $this->get_post_value('paigami_phone');
            $otp = $this->get_post_value('paigami_otp');

            // Validation
            if (empty($country) || empty($wallet) || empty($phone)) {
                throw new Exception(__('Missing required payment information.', 'paigami-woocommerce'));
            }

            $order->update_meta_data('_paigami_country', $country);
            $order->update_meta_data('_paigami_wallet', $wallet);
            $order->update_meta_data('_paigami_phone', $phone);

            if (!empty($otp)) {
                $order->update_meta_data('_paigami_otp', $otp);
            }

            $payment_data = array(
                'amount' => (int) ($order->get_total() * 100),
                'currency_id' => $this->get_currency_id($order->get_currency()),
                'wallet_provider_id' => $wallet,
                'country_id' => $country,
                'customer_phone' => $phone,
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_formatted_billing_full_name(),
                'metadata' => array(
                    'order_id' => $order_id,
                    'order_number' => $order->get_order_number(),
                    'site_url' => home_url()
                ),
                'callback_url' => home_url('/wc-api/paigami_webhook'),
                'return_url' => $this->get_return_url($order),
                'idempotency_key' => 'wc_order_' . $order_id . '_' . time()
            );

            if (!empty($otp)) {
                $payment_data['otp_code'] = $otp;
            }

            $response = $this->api->init_payment($payment_data);

            if ($response && isset($response['success']) && $response['success']) {
                $payment_response = $response['data'];

                $order->update_meta_data('_paigami_reference', $payment_response['reference']);
                $order->update_meta_data('_paigami_status', $payment_response['status']);

                if (isset($payment_response['payment_url'])) {
                    $order->update_meta_data('_paigami_payment_url', $payment_response['payment_url']);
                }

                $order->set_transaction_id($payment_response['reference']);
                $order->save();

                WC()->cart->empty_cart();

                if (!empty($payment_response['payment_url'])) {
                    return array(
                        'result' => 'success',
                        'redirect' => $payment_response['payment_url']
                    );
                } else {
                    return array(
                        'result' => 'success',
                        'redirect' => $this->get_return_url($order)
                    );
                }
            } else {
                throw new Exception(isset($response['message']) ? $response['message'] : 'Payment initialization failed');
            }
        } catch (Exception $e) {
            $this->api->log('Payment processing error: ' . $e->getMessage(), 'error');
            wc_add_notice(__('Payment error: ', 'paigami-woocommerce') . $e->getMessage(), 'error');

            return array(
                'result' => 'failure',
                'redirect' => ''
            );
        }
    }

    /**
     * Récupérer les données POST ou Block
     */
    public function get_post_data()
    {
        if ( ! empty( $this->data ) && is_array( $this->data ) ) {
            return $this->data;
        }
        return $_POST; // WPCS: CSRF ok, input var ok.
    }

    /**
     * Récupérer une valeur spécifique depuis POST ou Block
     */
    private function get_post_value($key)
    {
        // Vérifier d'abord dans $_POST (checkout classique)
        if (isset($_POST[$key])) {
            return sanitize_text_field($_POST[$key]);
        }

        // Vérifier dans les données de paiement des Blocks
        if (isset($_POST['payment_data'])) {
            $payment_data = json_decode(stripslashes($_POST['payment_data']), true);
            if (isset($payment_data[$key])) {
                return sanitize_text_field($payment_data[$key]);
            }
        }

        return '';
    }

    private function get_currency_id($currency_code)
    {
        return $currency_code;
    }
}
