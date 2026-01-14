<?php
/**
 * Plugin Name: Paigami Unified Payments for WooCommerce
 * Plugin URI: https://paigami.com
 * Description: Paigami is a smart payment orchestrator that automatically routes payments to the best available gateway based on country, fees, and success rates.
 * Version: 1.0.0
 * Author: Paigami
 * Author URI: https://paigami.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: paigami-woocommerce
 * Domain Path: /languages
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 * Requires at least: 5.8
 * Tested up to: 6.4
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PAIGAMI_WC_VERSION', '1.0.0');
define('PAIGAMI_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAIGAMI_WC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('PAIGAMI_WC_PLUGIN_BASENAME', plugin_basename(__FILE__));

class Paigami_WC_Plugin {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    public function init() {
        if (!class_exists('WC_Payment_Gateway')) {
            add_action('admin_notices', array($this, 'woocommerce_notices'));
            return;
        }
        
        $this->includes();
        $this->init_hooks();
        
        add_filter('woocommerce_payment_gateways', array($this, 'add_gateway'));
        add_action('woocommerce_api_paigami_webhook', array($this, 'handle_webhook'));
    }
    
    public function declare_hpos_compatibility() {
        if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
    
    private function includes() {
        require_once PAIGAMI_WC_PLUGIN_DIR . 'includes/class-paigami-api.php';
        require_once PAIGAMI_WC_PLUGIN_DIR . 'includes/class-paigami-gateway.php';
        require_once PAIGAMI_WC_PLUGIN_DIR . 'includes/class-paigami-webhook.php';
        
        // Load Blocks support if available
        if (class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            require_once PAIGAMI_WC_PLUGIN_DIR . 'includes/class-paigami-blocks-support.php';
            require_once PAIGAMI_WC_PLUGIN_DIR . 'includes/class-paigami-block-payment-method.php';
        }
    }
    
    private function init_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        
        add_action('init', array($this, 'load_textdomain'));
    }
    
    public function enqueue_scripts() {
        if (!is_checkout() && !is_wc_endpoint_url('order-pay')) {
            return;
        }
        
        wp_enqueue_script(
            'paigami-checkout',
            PAIGAMI_WC_PLUGIN_URL . 'assets/js/paigami-checkout.js',
            array('jquery'),
            PAIGAMI_WC_VERSION,
            true
        );
        
        wp_enqueue_style(
            'paigami-checkout',
            PAIGAMI_WC_PLUGIN_URL . 'assets/css/paigami-checkout.css',
            array(),
            PAIGAMI_WC_VERSION
        );
        
        wp_localize_script('paigami-checkout', 'paigami_params', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('paigami_nonce'),
            'api_url' => $this->get_api_url(),
            'loading_text' => __('Chargement...', 'paigami-woocommerce'),
            'select_country' => __('Sélectionner votre pays', 'paigami-woocommerce'),
            'select_wallet' => __('Sélectionner votre opérateur', 'paigami-woocommerce'),
            'phone_required' => __('Le numéro de téléphone est requis', 'paigami-woocommerce'),
            'otp_required' => __('Le code OTP est requis pour cet opérateur', 'paigami-woocommerce'),
        ));
    }
    
    public function admin_enqueue_scripts($hook) {
        if ('woocommerce_page_wc-settings' === $hook) {
            wp_enqueue_style(
                'paigami-admin',
                PAIGAMI_WC_PLUGIN_URL . 'assets/css/paigami-admin.css',
                array(),
                PAIGAMI_WC_VERSION
            );
        }
    }
    
    public function load_textdomain() {
        load_plugin_textdomain(
            'paigami-woocommerce',
            false,
            dirname(PAIGAMI_WC_PLUGIN_BASENAME) . '/languages'
        );
    }
    
    public function add_gateway($gateways) {
        $gateways[] = 'Paigami_WC_Gateway';
        return $gateways;
    }
    
    public function handle_webhook() {
        $webhook = new Paigami_WC_Webhook();
        $webhook->handle();
    }
    
    public function woocommerce_notices() {
        ?>
        <div class="error notice">
            <p><?php _e('Paigami Payment Gateway requires WooCommerce to be installed and active.', 'paigami-woocommerce'); ?></p>
        </div>
        <?php
    }
    
    public function activate() {
        if (!get_option('paigami_wc_version')) {
            add_option('paigami_wc_version', PAIGAMI_WC_VERSION);
        }
        
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function get_api_url() {
        $test_mode = 'yes' === get_option('paigami_test_mode', 'yes');
        return $test_mode 
            ? 'http://127.0.0.1:8004/api/v1' 
            : 'http://127.0.0.1:8004/api/v1';
    }
}

Paigami_WC_Plugin::get_instance();