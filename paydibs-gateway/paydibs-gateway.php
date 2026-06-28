<?php
/**
 * Plugin Name:       Paydibs Gateway for WooCommerce
 * Description:       Paydibs is a Malaysian WooCommerce payment gateway plugin for FPX, online banking, e-wallets, and cards with fast, secure integration.
 * Author:            Paydibs
 * Author URI:        https://paydibs.com
 * Version:           4.0.7
 * Requires at least: 5.8
 * Tested up to:      6.9
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * WC requires at least: 6.0
 * WC tested up to:   9.0
 * Text Domain:       paydibs-gateway
 * Domain Path:       /languages
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PAYDIBS_GATEWAY_VERSION', '4.0.7');

register_activation_hook(__FILE__, 'paydibs_gateway_on_activate');

/**
 * Mark wizard redirect for first-time activation.
 */
function paydibs_gateway_on_activate()
{
    if ('yes' !== get_option('paydibs_gateway_setup_wizard_completed', 'no')) {
        update_option('paydibs_gateway_setup_wizard_redirect', 'yes');
    }
}

// Make sure WooCommerce is active (handles single-site, multisite, and network activation).
if (!function_exists('is_plugin_active')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
if (!is_plugin_active('woocommerce/woocommerce.php')) {
    return;
}

/**
 * Load bundled translations from languages/ (required for custom locales on WordPress < 6.7).
 */
function paydibs_gateway_load_textdomain()
{
    load_plugin_textdomain(
        'paydibs-gateway',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages'
    );
}
add_action('plugins_loaded', 'paydibs_gateway_load_textdomain', 0);

add_filter("woocommerce_payment_gateways", "paydibs_add_gateway_class");

function paydibs_add_gateway_class($gateways)
{
    $gateways[] = 'Paydibs_Payment_Gateway';
    return $gateways;
}

// Check for minimum WooCommerce version
add_action('plugins_loaded', function() {
    if (!function_exists('WC') || version_compare(WC()->version, '2.6', '<')) {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>';
            echo esc_html__('Paydibs Gateway requires WooCommerce 2.6 or later. Please update WooCommerce.', 'paydibs-gateway');
            echo '</p></div>';
        });
        return;
    }
    else{
        paydibs_initialize_gateway_class();
        paydibs_register_setup_wizard_hooks();
    }
});

/**
 * Register setup wizard hooks independently of gateway instantiation.
 */
function paydibs_register_setup_wizard_hooks()
{
    if (!is_admin()) {
        return;
    }

    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    require_once plugin_dir_path(__FILE__) . 'includes/class-paydibs-setup-wizard.php';

    $wizard = new Paydibs_Payment_Gateway_Setup_Wizard('paydibsgateway');
    $wizard->register_hooks();
}

// Hook the custom function to the 'before_woocommerce_init' action
add_action(
    "before_woocommerce_init",
    "paydibs_declare_cart_checkout_blocks_compatibility"
);

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action(
    "woocommerce_blocks_loaded",
    "paydibs_register_blocks_payment_method_type"
);

add_filter('allowed_redirect_hosts', 'paydibs_allowed_redirect_hosts');

/**
 * Custom function to declare compatibility with cart_checkout_blocks feature
 */
function paydibs_declare_cart_checkout_blocks_compatibility()
{
    if (!class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        return;
    }
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
}

/**
   * Custom function to register a payment method type

   */
function paydibs_register_blocks_payment_method_type()
{
    // Check if the required class exists
    if (
        !class_exists(
            "Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType"
        )
    ) {
        return;
    }

    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . "includes/class-paydibs-blocks-payment-method.php";

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action("woocommerce_blocks_payment_method_type_registration", function (
        Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry
    ) {
        $payment_method_registry->register(new Paydibs_Payment_Gateway_Blocks_Payment_Method());
    });
}

function paydibs_initialize_gateway_class()
{
    class Paydibs_Payment_Gateway extends WC_Payment_Gateway
    {
        // plugin code here
        const LIVE_GATEWAY_URL = "https://v3api.paydibs.com/PPG/trigger";
        const TEST_GATEWAY_URL = "https://stgapi.paydibs.com/PPG/trigger";

        /** Order meta: Paydibs MerchantPymtID sent at checkout (authoritative payment binding). */
        const META_MERCHANT_PYMT_ID = '_paydibs_merchant_pymt_id';

        /** Order meta: formatted transaction amount sent to Paydibs. */
        const META_MERCHANT_TXN_AMT = '_paydibs_merchant_txn_amt';

        /** Order meta: currency code sent to Paydibs. */
        const META_MERCHANT_CURR_CODE = '_paydibs_merchant_curr_code';

        /** Order meta: Paydibs merchant ID used for the payment attempt. */
        const META_MERCHANT_ID = '_paydibs_merchant_id';

        /**
         * WooCommerce may construct payment gateways more than once per request; bind admin hooks once.
         *
         * @var bool
         */
        private static $admin_hooks_registered = false;

        /**
         * WooCommerce may construct this gateway multiple times (checkout + blocks).
         *
         * @var bool
         */
        private static $api_hooks_registered = false;

        /*
         * Class constructor
         */
        public function __construct()
        {
            // Detect WooCommerce version
            $this->wc_version = defined('WC_VERSION') ? WC_VERSION : null;

            $this->plugin_dir = plugin_dir_url(__FILE__);

            $this->id = "paydibsgateway"; // payment gateway ID
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $this->icon = apply_filters(
                /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound */
                "woocommerce_gateway_icon",
                $this->plugin_dir . "/assets/pdbs-logo.png"
            );
            $this->has_fields = true; // for custom form
            $this->title = __("Paydibs Gateway", 'paydibs-gateway'); // vertical tab title
            $this->method_title = __("Paydibs Gateway V4.0.6", 'paydibs-gateway'); // payment method name
            $this->method_description = __(
                'Accept FPX, online banking, e-wallets, and card payments with Paydibs. Easy WooCommerce setup, secure checkout, and saved cards for returning customers.',
                'paydibs-gateway'
            ); // payment method description

            $this->callbackurl = add_query_arg(
                ["wc-api" => "paydibsgateway_webhook"],
                home_url("/")
            );
            $this->returnurl = add_query_arg(
                ["wc-api" => "paydibsgateway_result"],
                home_url("/")
            );

            // Method with all the options fields
            $this->init_form_fields();

            // Load the settings.
            $this->init_settings();
            $this->title = __("Paydibs Gateway", 'paydibs-gateway');
            //$this->description = __('Proceed with the payment via Credit/Debit Card , Online Banking, E-Wallets.', 'text-domain');
            $this->description = __(
                'Pay with Paydibs',
                'paydibs-gateway'
            );
            $this->enabled = $this->get_option("enabled");
            $this->test_mode = "yes" === $this->get_option("test_mode");
            $this->debug = "yes" === $this->get_option("debug_mode");
            $this->cod_enabled = "yes" === $this->get_option("cod_enabled");
            $this->cod_parcel_weight = $this->get_option("cod_parcel_weight", "1");
            $this->cod_parcel_length = $this->get_option("cod_parcel_length", "1");
            $this->cod_parcel_width = $this->get_option("cod_parcel_width", "1");
            $this->cod_parcel_height = $this->get_option("cod_parcel_height", "1");
            $this->cod_receiver_address_type = sanitize_text_field(
                $this->get_option("cod_receiver_address_type", "1")
            );

            if ($this->test_mode) {
                $this->merchant_id = $this->get_option("test_tenant_id");
                $this->secretKey = $this->get_option("test_secrect_key");
                $this->currency = $this->get_option("test_currency");
            } else {
                $this->merchant_id = $this->get_option("live_tenant_id");
                $this->secretKey = $this->get_option("live_secrect_key");
                $this->currency = $this->get_option("live_currency");
            }

            // Action hook to saves the settings (register once — WC can instantiate this class multiple times).
            if (is_admin() && !self::$admin_hooks_registered) {
                self::$admin_hooks_registered = true;
                add_action(
                    "woocommerce_update_options_payment_gateways_" . $this->id,
                    [$this, "process_admin_options"]
                );
                add_action('admin_notices', [$this, 'maybe_show_currency_mismatch_notice']);
                add_action('admin_notices', [$this, 'maybe_show_test_mode_notice']);
                add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
            }

            // Register payment API handlers once (blocks/checkout may construct this class multiple times).
            if (!self::$api_hooks_registered) {
                self::$api_hooks_registered = true;
                add_action('woocommerce_api_paydibsgateway_webhook', [$this, 'webhook']);
                add_action('woocommerce_api_paydibsgateway_result', [$this, 'result']);
            }
        }


        /*
         * Plugin options and setting fields
         */
        public function init_form_fields()
        {
            $supported_currencies = $this->get_supported_currencies();
            $this->form_fields = [
                "enabled" => [
                    "title" => __("Enable/Disable", 'paydibs-gateway'),
                    "label" => __("Enable Paydibs Gateway", 'paydibs-gateway'),
                    "type" => "checkbox",
                    "description" => __(
                        'Turn on to accept payments through Paydibs.',
                        'paydibs-gateway'
                    ),
                    "default" => "no",
                    "desc_tip" => true,
                ],
                "debug_mode" => [
                    "title" => __("Debug mode", 'paydibs-gateway'),
                    "label" => __("Enable Debug logging", 'paydibs-gateway'),
                    "type" => "checkbox",
                    "description" => __(
                        'Log activity to help troubleshoot issues.',
                        'paydibs-gateway'
                    ),
                    "default" => "no",
                    "desc_tip" => true,
                ],
                "test_mode" => [
                    "title" => __("Test mode", 'paydibs-gateway'),
                    "label" => __("Enable Test Mode", 'paydibs-gateway'),
                    "type" => "checkbox",
                    "description" => __(
                        'Use test API keys to run the gateway in test mode.',
                        'paydibs-gateway'
                    ),
                    "default" => "yes",
                    "desc_tip" => true,
                ],
                "test_tenant_id" => [
                    "title" => __("Test Merchant ID", 'paydibs-gateway'),
                    "type" => "text",
                ],
                "test_secrect_key" => [
                    "title" => __("Test Secret Key", 'paydibs-gateway'),
                    "type" => "password",
                ],
                "test_currency" => [
                    "title" => __("Test Currency", 'paydibs-gateway'),
                    "type" => "select",
                    "options" => ["AUTO" => __("Auto (use store currency)", 'paydibs-gateway')] + $supported_currencies,
                ],
                "live_tenant_id" => [
                    "title" => __("Live Merchant ID", 'paydibs-gateway'),
                    "type" => "text",
                ],
                "live_secrect_key" => [
                    "title" => __("Live Secret Key", 'paydibs-gateway'),
                    "type" => "password",
                ],
                "live_currency" => [
                    "title" => __("Live Currency", 'paydibs-gateway'),
                    "type" => "select",
                    "options" => ["AUTO" => __("Auto (use store currency)", 'paydibs-gateway')] + $supported_currencies,
                ],
                "cod_enabled" => [
                    "title" => __("Cash on Delivery (COD)", 'paydibs-gateway'),
                    "label" => __("Enable COD fields for Paydibs", 'paydibs-gateway'),
                    "type" => "checkbox",
                    "description" => __(
                        "Enable receiver and parcel fields required by COD payment channels.",
                        'paydibs-gateway'
                    ),
                    "default" => "no",
                    "desc_tip" => true,
                ],
                "cod_parcel_weight" => [
                    "title" => __("Default Parcel Weight (kg)", 'paydibs-gateway'),
                    "type" => "number",
                    "default" => "1",
                    "custom_attributes" => [
                        "min" => "1",
                        "max" => "30",
                        "step" => "1",
                    ],
                ],
                "cod_parcel_length" => [
                    "title" => __("Default Parcel Length (cm)", 'paydibs-gateway'),
                    "type" => "number",
                    "default" => "1",
                    "custom_attributes" => [
                        "min" => "1",
                        "step" => "1",
                    ],
                ],
                "cod_parcel_width" => [
                    "title" => __("Default Parcel Width (cm)", 'paydibs-gateway'),
                    "type" => "number",
                    "default" => "1",
                    "custom_attributes" => [
                        "min" => "1",
                        "step" => "1",
                    ],
                ],
                "cod_parcel_height" => [
                    "title" => __("Default Parcel Height (cm)", 'paydibs-gateway'),
                    "type" => "number",
                    "default" => "1",
                    "custom_attributes" => [
                        "min" => "1",
                        "step" => "1",
                    ],
                ],
                "cod_receiver_address_type" => [
                    "title" => __("Receiver Address Type", 'paydibs-gateway'),
                    "type" => "select",
                    "default" => "HME",
                    "options" => [
                        "HME" => __("Home", 'paydibs-gateway'),
                        "OFC" => __("Office", 'paydibs-gateway'),
                        "OTH" => __("Others", 'paydibs-gateway'),
                    ],
                    "desc_tip" => true,
                ],
            ];
        }

        /**
         * Return supported currencies for this gateway.
         *
         * @return array
         */
        protected function get_supported_currencies()
        {
            $currencies = [
                "MYR" => "MYR",
                "SGD" => "SGD",
                "PHP" => "PHP",
                "VND" => "VND",
                "HKD" => "HKD",
                "IDR" => "IDR",
                "KHR" => "KHR",
                "KRW" => "KRW",
                "THB" => "THB",
                "CNY" => "CNY",
            ];

            /**
             * Filter supported currencies for Paydibs Gateway.
             *
             * @param array $currencies
             */
            return apply_filters('paydibs_payment_gateway_supported_currencies', $currencies);
        }

        /**
         * Resolve currency for the current request, supporting multi-currency plugins.
         *
         * @param WC_Order|null $order
         * @return string
         */
        protected function get_effective_currency($order = null)
        {
            $configured = strtoupper(trim((string) $this->currency));
            if ($configured && $configured !== 'AUTO') {
                return $configured;
            }

            $currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';

            if (class_exists('WOOCS') && isset($GLOBALS['WOOCS']) && !empty($GLOBALS['WOOCS']->current_currency)) {
                $currency = $GLOBALS['WOOCS']->current_currency;
            } elseif (class_exists('WC_Aelia_CurrencySwitcher')) {
                $aelia = WC_Aelia_CurrencySwitcher::instance();
                if (method_exists($aelia, 'get_currency')) {
                    $currency = $aelia->get_currency();
                }
            } elseif (class_exists('WC_Multi_Currency')) {
                $woomc = WC_Multi_Currency::get_instance();
                if (method_exists($woomc, 'get_selected_currency')) {
                    $currency = $woomc->get_selected_currency();
                }
            }

            $currency = apply_filters('paydibs_payment_gateway_currency', $currency, $order);
            return strtoupper(trim((string) $currency));
        }

        /**
         * Enqueue admin scripts to toggle COD fields visibility.
         */
        public function enqueue_admin_scripts()
        {
            if (!function_exists('get_current_screen')) {
                return;
            }

            $screen = get_current_screen();
            if (!$screen || !$screen->id || strpos($screen->id, 'woocommerce_page_wc-settings') === false) {
                return;
            }

            $handle = 'paydibs-gateway-admin';
            wp_register_script($handle, '', ['jquery'], PAYDIBS_GATEWAY_VERSION, true);
            wp_enqueue_script($handle);

            $inline_js = "
                jQuery(function($){
                    function toggleCodFields(){
                        var enabled = $('#woocommerce_paydibsgateway_cod_enabled').is(':checked');
                        var fields = [
                            '#woocommerce_paydibsgateway_cod_parcel_weight',
                            '#woocommerce_paydibsgateway_cod_parcel_length',
                            '#woocommerce_paydibsgateway_cod_parcel_width',
                            '#woocommerce_paydibsgateway_cod_parcel_height',
                            '#woocommerce_paydibsgateway_cod_receiver_address_type'
                        ];
                        $.each(fields, function(_, selector){
                            $(selector).closest('tr').toggle(enabled);
                        });
                    }
                    function toggleModeFields(){
                        var testEnabled = $('#woocommerce_paydibsgateway_test_mode').is(':checked');
                        var testFields = [
                            '#woocommerce_paydibsgateway_test_tenant_id',
                            '#woocommerce_paydibsgateway_test_secrect_key',
                            '#woocommerce_paydibsgateway_test_currency'
                        ];
                        var liveFields = [
                            '#woocommerce_paydibsgateway_live_tenant_id',
                            '#woocommerce_paydibsgateway_live_secrect_key',
                            '#woocommerce_paydibsgateway_live_currency'
                        ];
                        $.each(testFields, function(_, selector){
                            $(selector).closest('tr').toggle(testEnabled);
                        });
                        $.each(liveFields, function(_, selector){
                            $(selector).closest('tr').toggle(!testEnabled);
                        });
                    }
                    toggleCodFields();
                    toggleModeFields();
                    $(document).on('change', '#woocommerce_paydibsgateway_cod_enabled', toggleCodFields);
                    $(document).on('change', '#woocommerce_paydibsgateway_test_mode', toggleModeFields);
                });
            ";
            wp_add_inline_script($handle, $inline_js);
        }

        /**
         * Check whether a currency is supported by this gateway.
         *
         * @param string $currency
         * @return bool
         */
        protected function is_currency_supported($currency)
        {
            $supported = array_keys($this->get_supported_currencies());
            return in_array($currency, $supported, true);
        }

        /**
         * Show admin notice when store currency mismatches gateway currency.
         */
        public function maybe_show_currency_mismatch_notice()
        {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            if ($this->enabled !== 'yes') {
                return;
            }

            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                if ($screen && $screen->id && strpos($screen->id, 'woocommerce_page_wc-settings') === false) {
                    return;
                }
            }

            $store_currency = function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : '';
            $store_currency = strtoupper(trim($store_currency));
            $gateway_currency = $this->get_effective_currency();
            $configured_currency = strtoupper(trim((string) $this->currency));

            if (empty($store_currency) || empty($gateway_currency)) {
                return;
            }

            $supported_list = implode(', ', array_keys($this->get_supported_currencies()));
            $message = '';

            $mode_label = $this->test_mode ? __('Test mode', 'paydibs-gateway') : __('Live mode', 'paydibs-gateway');
            $currency_settings_url = function_exists('admin_url')
                ? admin_url('admin.php?page=wc-settings&tab=general')
                : '';
            $currency_settings_link = $currency_settings_url
                ? sprintf('<a href="%s">%s</a>', esc_url($currency_settings_url), esc_html__('Change store currency', 'paydibs-gateway'))
                : esc_html__('Change store currency', 'paydibs-gateway');

            if ($configured_currency === 'AUTO') {
                return;
            }

            if (!$this->is_currency_supported($store_currency)) {
                $message = sprintf(
                    /* translators: 1: store currency, 2: gateway currency, 3: mode label, 4: settings link. */
                    __('Paydibs Gateway: Store currency %1$s, gateway currency %2$s (%3$s). %4$s', 'paydibs-gateway'),
                    esc_html($store_currency),
                    esc_html($gateway_currency),
                    esc_html($mode_label),
                    $currency_settings_link
                );
            } elseif (!empty($gateway_currency) && $store_currency !== $gateway_currency) {
                $message = sprintf(
                    /* translators: 1: store currency, 2: gateway currency, 3: mode label, 4: settings link. */
                    __('Paydibs Gateway: Store currency %1$s, gateway currency %2$s (%3$s). %4$s', 'paydibs-gateway'),
                    esc_html($store_currency),
                    esc_html($gateway_currency),
                    esc_html($mode_label),
                    $currency_settings_link
                );
            }

            if (!empty($message)) {
                echo '<div class="notice notice-warning"><p>' . wp_kses_post($message) . '</p></div>';
            }
        }

        /**
         * Warn shop managers when the gateway is enabled but still in test mode.
         */
        public function maybe_show_test_mode_notice()
        {
            if (!current_user_can('manage_woocommerce')) {
                return;
            }

            if ($this->enabled !== 'yes' || !$this->test_mode) {
                return;
            }

            if (function_exists('get_current_screen')) {
                $screen = get_current_screen();
                if (!$screen || !$screen->id) {
                    return;
                }
                if (function_exists('wc_get_screen_ids')) {
                    $wc_screens = wc_get_screen_ids();
                    if (!empty($wc_screens) && !in_array($screen->id, $wc_screens, true)) {
                        return;
                    }
                } elseif (strpos($screen->id, 'woocommerce') === false) {
                    return;
                }
            }

            $settings_url = admin_url('admin.php?page=wc-settings&tab=checkout&section=paydibsgateway');
            $settings_link = $settings_url
                ? sprintf(
                    '<a href="%s">%s</a>',
                    esc_url($settings_url),
                    esc_html__('Paydibs payment settings', 'paydibs-gateway')
                )
                : '';

            $message = $settings_link
                ? sprintf(
                    /* translators: %s: HTML link to Paydibs gateway settings */
                    __('Paydibs is in test mode. Real FPX, card, and banking charges will not run. Turn off test mode before you go live. %s', 'paydibs-gateway'),
                    $settings_link
                )
                : __('Paydibs is in test mode. Real FPX, card, and banking charges will not run. Turn off test mode before you go live.', 'paydibs-gateway');

            echo '<div class="notice notice-warning"><p>' . wp_kses_post($message) . '</p></div>';
        }

        /**
         * You will need it if you want your custom credit card form, Step 4 is about it
         */
        public function payment_fields()
        {
            if ($this->description) {
                // you can instructions for test mode, I mean test card numbers etc.
                if ($this->test_mode) {
                    $this->description .= ' ' . __(
                        'Test mode is on. You can run a test payment to try checkout.',
                        'paydibs-gateway'
                    );
                }
                $this->description = trim($this->description);
                // display the description with <p> tags etc.
                echo wp_kses_post(wpautop($this->description));
            }
        }

        /*
         * Fields validation, more in Step 5
         */
        public function validate_fields()
        {
            return true;
        }

        function generateSecurePaymentID($length = 20) {
            $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789 ';
            $charLength = strlen($characters);
            $paymentID = '';
        
            for ($i = 0; $i < $length; $i++) {
                $paymentID .= $characters[random_int(0, $charLength - 1)];
            }
        
            return trim($paymentID);
        }

        function generateTimestampedPaymentID() {
            $ts = time();
            $randomPart = $this->generateSecurePaymentID(20-strlen($ts));
            return $ts.$randomPart;
        }

        /**
         * Process the payment and return the result
         *
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id) {
            // Determine the payment gateway URL based on test mode
            $payment_url = $this->test_mode ? self::TEST_GATEWAY_URL : self::LIVE_GATEWAY_URL;
            
            // Get the WooCommerce order object
            $order = wc_get_order($order_id);
            
            if (!$order) {
                return ['result' => 'failure'];
            }

            // Prepare order items description
            $items_description = $this->prepare_order_items_description($order);
            
            // Prepare customer data
            $customer_data = $this->prepare_customer_data($order);
            
            // Generate payment ID
            $payment_id = $this->generateTimestampedPaymentID();
            
            // Prepare the payment request data
            $postdata = $this->prepare_payment_request_data(
                $order,
                $payment_id,
                $items_description,
                $customer_data
            );
            
            // Generate signature
            $signature = $this->generate_signature($postdata);
            $postdata['Sign'] = $signature;
            
            // Log the request data if debugging is enabled
            $this->log_request_data($order, $postdata, $items_description);
            
            // Build the redirect URL with query parameters
            $query = http_build_query($postdata);
            $redirect_url = "$payment_url?$query";
            
            // Return the payment result
            return [
                'result' => 'success',
                'redirect' => $redirect_url
            ];
        }

        /**
         * Prepare order items description
         *
         * @param WC_Order $order
         * @return string
         */
        protected function prepare_order_items_description($order) {
            $product_items = [];
            
            foreach ($order->get_items() as $item) {
                $product_name = $item->get_name();
                $quantity = $item->get_quantity();
                $product_items[] = $product_name . " x" . $quantity;
            }
            
            return substr(implode(", ", $product_items), 0, 500);
        }

        /**
         * Prepare customer data from order
         *
         * @param WC_Order $order
         * @return array
         */
        protected function prepare_customer_data($order) {
            $billing_first_name = $order->get_billing_first_name();
            $billing_last_name = $order->get_billing_last_name();
            
            $ip_address = '';
            if (class_exists('WC_Geolocation')) {
                $ip_address = WC_Geolocation::get_ip_address();
            } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
                $ip_address = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
            }

            $user_agent = '';
            if (!empty($_SERVER['HTTP_USER_AGENT'])) {
                $user_agent = sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT']));
            }

            return [
                'email' => $order->get_billing_email(),
                'phone' => $order->get_billing_phone(),
                'name' => trim($billing_first_name . ' ' . $billing_last_name),
                'reference_id' => $this->get_customer_reference_id($order),
                'ip' => $ip_address,
                'user_agent' => $user_agent,
                'address_1' => $order->get_billing_address_1(),
                'address_2' => $order->get_billing_address_2(),
                'city' => $order->get_billing_city(),
                'state' => $order->get_billing_state(),
                'postcode' => $order->get_billing_postcode(),
                'country' => $order->get_billing_country(),
                'ship_name' => trim($order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name()),
                'ship_phone' => $order->get_billing_phone(),
                'ship_address_1' => $order->get_shipping_address_1(),
                'ship_address_2' => $order->get_shipping_address_2(),
                'ship_city' => $order->get_shipping_city(),
                'ship_state' => $order->get_shipping_state(),
                'ship_postcode' => $order->get_shipping_postcode(),
                'ship_country' => $order->get_shipping_country(),
            ];
        }

        /**
         * Build a stable customer reference ID for card-linking flows.
         *
         * Only logged-in WooCommerce customers get a reference ID.
         * Guest checkouts intentionally do not send CustReferenceId.
         *
         * @param WC_Order $order
         * @return string
         */
        protected function get_customer_reference_id($order)
        {
            $customer_user_id = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
            if ($customer_user_id > 0) {
                return 'wc_user_' . (string) $customer_user_id;
            }

            return '';
        }

        /**
         * Prepare payment request data
         *
         * @param WC_Order $order
         * @param string $payment_id
         * @param string $items_description
         * @param array $customer_data
         * @return array
         */
        protected function prepare_payment_request_data($order, $payment_id, $items_description, $customer_data) {
            $txn_amount = $this->normalize_txn_amount($order->get_total());
            $txn_currency = $this->get_effective_currency($order);

            // Bind this checkout attempt to order meta before redirect (used on callback verification).
            $order->update_meta_data(self::META_MERCHANT_PYMT_ID, $payment_id);
            $order->update_meta_data(self::META_MERCHANT_TXN_AMT, $txn_amount);
            $order->update_meta_data(self::META_MERCHANT_CURR_CODE, $txn_currency);
            $order->update_meta_data(self::META_MERCHANT_ID, $this->merchant_id);
            $nonce = substr(str_shuffle(md5(microtime())), 0, 12);
            $order->update_meta_data('ipn_nonce', $nonce);
            $order->save();
            
            $payload = [
                'TxnType' => 'PAY',
                'MerchantID' => $this->merchant_id,
                'MerchantPymtID' => $payment_id,
                'MerchantOrdID' => $this->getOrderId($order),
                'MerchantRURL' => $this->returnurl,
                'MerchantOrdDesc' => $items_description,
                'MerchantTxnAmt' => $txn_amount,
                'MerchantCurrCode' => $txn_currency,
                'CustIP' => $customer_data['ip'],
                'CustName' => $customer_data['name'],
                'CustEmail' => $customer_data['email'],
                'CustPhone' => $customer_data['phone'],
                'MerchantCallbackURL' => $this->callbackurl,
                'Sign' => '' // Will be populated later
            ];

            // Optional customer fields (only include when provided)
            $optional_fields = [
                'CustAddr1' => $customer_data['address_1'] ?? '',
                'CustAddr2' => $customer_data['address_2'] ?? '',
                'CustCity' => $customer_data['city'] ?? '',
                'CustState' => $customer_data['state'] ?? '',
                'CustPostCode' => $customer_data['postcode'] ?? '',
                'CustCountry' => $customer_data['country'] ?? '',
                'CustUserAgent' => $customer_data['user_agent'] ?? '',
                'CustReferenceId' => $customer_data['reference_id'] ?? '',
            ];

            foreach ($optional_fields as $key => $value) {
                if (!empty($value)) {
                    $payload[$key] = $value;
                }
            }

            if ($this->cod_enabled) {
                $receiver_name = $customer_data['ship_name'] ?: $customer_data['name'];
                $receiver_phone = $customer_data['ship_phone'] ?: $customer_data['phone'];

                $payload['ReceiverName'] = $receiver_name;
                $payload['ReceiverPhoneNumber'] = $receiver_phone;
                $payload['ReceiverAddress1'] = $customer_data['ship_address_1'] ?: $customer_data['address_1'];
                $payload['ReceiverAddress2'] = $customer_data['ship_address_2'] ?: $customer_data['address_2'];
                $payload['ReceiverCity'] = $customer_data['ship_city'] ?: $customer_data['city'];
                $payload['ReceiverState'] = $customer_data['ship_state'] ?: $customer_data['state'];
                $payload['ReceiverCountry'] = $customer_data['ship_country'] ?: $customer_data['country'];
                $payload['ReceiverPostcode'] = $customer_data['ship_postcode'] ?: $customer_data['postcode'];
                $payload['ReceiverAddressType'] = $this->cod_receiver_address_type ?: '1';

                $payload['ParcelWeight'] = $this->cod_parcel_weight ?: '1';
                $payload['ParcelLength'] = $this->cod_parcel_length ?: '1';
                $payload['ParcelWidth'] = $this->cod_parcel_width ?: '1';
                $payload['ParcelHeight'] = $this->cod_parcel_height ?: '1';

                $payload['ItemCount'] = (string) max(1, array_sum(wp_list_pluck($order->get_items(), 'quantity')));
                $payload['ItemDescription'] = substr($items_description, 0, 100);
            }

            return $payload;
        }

        /**
         * Generate signature for the payment request
         *
         * @param array $postdata
         * @return string
         */
        protected function generate_signature($postdata) {
            $sign_seq = [
                'TxnType', 'MerchantID', 'MerchantPymtID', 'MerchantOrdID', 
                'MerchantRURL', 'MerchantTxnAmt', 'MerchantCurrCode', 
                'CustIP', 'MerchantCallbackURL'
            ];
            
            $t = '';
            foreach ($sign_seq as $key) {
                $t .= $postdata[$key] ?? '';
            }
            
            return bin2hex(hash('sha512', $this->secretKey . $t, true));
        }

        /**
         * Generate signature for the Query request
         *
         * @param array $postdata
         * @return string
         */
        protected function generate_query_req_signature($postdata) {
            $sign_seq = [
                'MerchantID', 'MerchantPymtID', 'MerchantTxnAmt', 'MerchantCurrCode'
            ];
            
            $t = '';
            foreach ($sign_seq as $key) {
                $t .= $postdata[$key] ?? '';
            }
            
            return bin2hex(hash('sha512', $this->secretKey . $t, true));
        }

        /**
         * Log request data if debugging is enabled
         *
         * @param WC_Order $order
         * @param array $postdata
         * @param string $items_description
         */
        protected function log_request_data($order, $postdata, $items_description) {
            if ($this->debug) {
                $this->log('Payment request data: ' . wp_json_encode($postdata));
                $this->log('Order ID: ' . $this->getOrderId($order) . ' Order Number: ' . $order->get_order_number());
                $this->log('Product items: ' . $items_description);
            }
        }

        /*
         * Webhook for Callback URL Routine
         */
        public function webhook()
        {
            $this->paymentResponseHandler(true, $this->debug);
        }

        /*
         * Webhook for Return URL Routine
         */
        public function result()
        {
          $this->paymentResponseHandler(false);
        }

        /**
         * Handle payment response from Paydibs (callback and redirect)
         * 
         * @param bool $is_callback Whether this is a callback request (true) or redirect (false)
         * @param bool $is_debug_log Whether to log debug information
         */
        public function paymentResponseHandler($is_callback = true, $is_debug_log = false) {
            // Log the trigger type
            $this->log(($is_callback) ? 'Callback triggered' : 'Redirect triggered');

            try {
                // Get and validate input data
                $post_data = $this->getValidatedInputData();
                
                // Extract and validate required parameters
                $params = $this->extractAndValidateParameters($post_data);
                
                // Verify the signature
                $this->verifySignature($params, $this->secretKey);

                //Validate from Gateway via Query
                $query_resp = $this->sendQueryRequest($params);

                if ($query_resp['PTxnStatus'] !== $params['PTxnStatus']) {
                    throw new RuntimeException(
                        esc_html(
                            sprintf(
                                /* translators: 1: merchant payment ID, 2: expected status code, 3: status code returned by gateway QUERY. */
                                __(
                                    'Transaction status verification failed for payment %1$s. Expected status %2$s; gateway query returned %3$s.',
                                    'paydibs-gateway'
                                ),
                                (string) $query_resp['MerchantPymtID'],
                                (string) $params['PTxnStatus'],
                                (string) $query_resp['PTxnStatus']
                            )
                        )
                    );
                }

                $this->assert_callback_matches_query($params, $query_resp);

                $order = $this->resolve_order_for_payment($params, $query_resp);
                
                // Process the payment using the order bound to the stored payment reference.
                $this->processPayment($params, $is_callback, $is_debug_log, $order);
                
            } catch (Exception $e) {
                $this->handleError($e, $is_callback);
            }
        }

        /**
         * Parameter keys Paydibs may send on return URL or callback (POST/GET/JSON body).
         *
         * @return string[]
         */
        protected function get_gateway_response_param_keys()
        {
            return [
                'TxnType',
                'MerchantID',
                'MerchantPymtID',
                'PTxnID',
                'MerchantOrdID',
                'MerchantTxnAmt',
                'MerchantCurrCode',
                'PTxnStatus',
                'Sign',
                'Method',
                'PTxnMsg',
                'BankRefNo',
                'AuthCode',
            ];
        }

        /**
         * Keep only expected keys and sanitize scalar values from a decoded JSON payload.
         *
         * @param array $raw Decoded JSON (associative array).
         * @return array
         */
        protected function sanitize_gateway_callback_params(array $raw)
        {
            $allowed = array_flip($this->get_gateway_response_param_keys());
            $out = [];

            foreach ($raw as $key => $value) {
                if (!is_string($key) || !isset($allowed[$key])) {
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $out[$key] = sanitize_text_field((string) $value);
            }

            return $out;
        }

        /**
         * Allowlisted scalar fields only, unchanged, for signature verification (must match gateway bytes).
         *
         * @param array $decoded Decoded JSON (associative array).
         * @return array
         */
        protected function extract_gateway_response_fields_for_verification(array $decoded)
        {
            $allowed = array_flip($this->get_gateway_response_param_keys());
            $out = [];

            foreach ($decoded as $key => $value) {
                if (!is_string($key) || !isset($allowed[$key])) {
                    continue;
                }
                if (!is_scalar($value)) {
                    continue;
                }
                $out[$key] = (string) $value;
            }

            return $out;
        }

        /**
         * Decode a Paydibs gateway JSON body, verify the signature, then return allowlisted sanitized fields.
         *
         * @param string $body        Raw HTTP response body.
         * @param string $merchant_secret Secret key used for signature verification.
         * @return array
         * @throws RuntimeException When the body is invalid, incomplete, or the signature does not match.
         */
        protected function parse_verified_gateway_api_json_response($body, $merchant_secret)
        {
            if (!is_string($body) || '' === $body) {
                throw new RuntimeException(
                    esc_html__('Empty response body from payment gateway.', 'paydibs-gateway'),
                    502
                );
            }

            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new RuntimeException(
                    esc_html__('Failed to decode JSON response from payment gateway.', 'paydibs-gateway'),
                    502
                );
            }

            if (!is_array($decoded)) {
                throw new RuntimeException(
                    esc_html__('Invalid gateway response body.', 'paydibs-gateway'),
                    502
                );
            }

            if (function_exists('wp_is_numeric_array') && wp_is_numeric_array($decoded)) {
                throw new RuntimeException(
                    esc_html__('Invalid gateway response body.', 'paydibs-gateway'),
                    502
                );
            }

            $raw = $this->extract_gateway_response_fields_for_verification($decoded);

            $required_for_sign = [
                'MerchantID',
                'MerchantPymtID',
                'PTxnID',
                'MerchantOrdID',
                'MerchantTxnAmt',
                'MerchantCurrCode',
                'PTxnStatus',
                'Sign',
            ];

            foreach ($required_for_sign as $key) {
                if (!isset($raw[$key]) || '' === $raw[$key]) {
                    throw new RuntimeException(
                        esc_html(
                            sprintf(
                                /* translators: %s: missing field name */
                                __('Gateway response missing required field: %s', 'paydibs-gateway'),
                                (string) $key
                            )
                        ),
                        502
                    );
                }
            }

            if (!array_key_exists('AuthCode', $raw)) {
                $raw['AuthCode'] = '';
            }

            $this->verifySignature($raw, $merchant_secret);

            return $this->sanitize_gateway_callback_params($raw);
        }

        /**
         * Read only expected Paydibs parameters from POST or GET (not the full superglobal).
         *
         * @return array
         */
        protected function read_gateway_params_from_request()
        {
            $out = [];
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- External Paydibs gateway redirect/callback; WordPress nonces do not apply.
            $post_src = !empty($_POST) ? wp_unslash($_POST) : [];
            // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended
            $get_src = !empty($_GET) ? wp_unslash($_GET) : [];

            foreach ($this->get_gateway_response_param_keys() as $key) {
                if (isset($post_src[$key]) && is_scalar($post_src[$key])) {
                    $out[$key] = sanitize_text_field((string) $post_src[$key]);
                } elseif (isset($get_src[$key]) && is_scalar($get_src[$key])) {
                    $out[$key] = sanitize_text_field((string) $get_src[$key]);
                }
            }

            return $out;
        }

        /**
         * Get and validate input data from JSON body or only known POST/GET keys.
         *
         * @return array
         * @throws Exception
         */
        protected function getValidatedInputData()
        {
            $post_data = [];
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Raw request body from Paydibs callback.
            $raw_input = file_get_contents('php://input');

            if (!empty($raw_input)) {
                $decoded = json_decode($raw_input, true);
                if (JSON_ERROR_NONE !== json_last_error() || !is_array($decoded)) {
                    $decoded_input = urldecode($raw_input);
                    $decoded = json_decode($decoded_input, true);
                }
                if (is_array($decoded)) {
                    $post_data = $this->sanitize_gateway_callback_params($decoded);
                }
            }

            if (empty($post_data)) {
                $post_data = $this->read_gateway_params_from_request();
            }

            if (empty($post_data)) {
                throw new Exception(esc_html__('No valid data received', 'paydibs-gateway'), 400);
            }

            return $post_data;
        }

        /**
         * Extract and validate required parameters
         * 
         * @param array $post_data
         * @return array
         * @throws Exception
         */
        protected function extractAndValidateParameters($post_data) {
            $required_params = [
                'TxnType',
                'MerchantID',
                'MerchantPymtID',
                'PTxnID',
                'MerchantOrdID',
                'MerchantTxnAmt',
                'MerchantCurrCode',
                'PTxnStatus',
                'Sign'
            ];
            
            $params = [];
            foreach ($required_params as $param) {
                if (!isset($post_data[$param])) {
                    throw new Exception(
                        esc_html(
                            sprintf(
                                /* translators: %s: parameter name */
                                __('Missing required parameter: %s', 'paydibs-gateway'),
                                (string) $param
                            )
                        ),
                        400
                    );
                }
                if (!is_scalar($post_data[$param])) {
                    throw new Exception(
                        esc_html(
                            sprintf(
                                /* translators: %s: parameter name */
                                __('Invalid parameter type for: %s', 'paydibs-gateway'),
                                (string) $param
                            )
                        ),
                        400
                    );
                }
                $params[$param] = sanitize_text_field(wp_unslash($post_data[$param]));
            }
            
            // Optional parameters
            $optional_params = [
                'Method',
                'PTxnMsg',
                'BankRefNo',
                'AuthCode'
            ];
            
            foreach ($optional_params as $param) {
                if (isset($post_data[$param]) && is_scalar($post_data[$param])) {
                    $params[$param] = sanitize_text_field(wp_unslash($post_data[$param]));
                } else {
                    $params[$param] = '';
                }
            }

            if (!ctype_digit((string) $params['MerchantOrdID']) || (int) $params['MerchantOrdID'] < 1) {
                throw new Exception(esc_html__('Invalid order reference.', 'paydibs-gateway'), 400);
            }

            if (!is_numeric($params['MerchantTxnAmt'])) {
                throw new Exception(esc_html__('Invalid transaction amount.', 'paydibs-gateway'), 400);
            }

            $curr = strtoupper($params['MerchantCurrCode']);
            if (!preg_match('/^[A-Z]{3}$/', $curr)) {
                throw new Exception(esc_html__('Invalid currency code.', 'paydibs-gateway'), 400);
            }
            $params['MerchantCurrCode'] = $curr;

            if (!preg_match('/^[0-9]{1,3}$/', $params['PTxnStatus'])) {
                throw new Exception(esc_html__('Invalid transaction status.', 'paydibs-gateway'), 400);
            }

            if (strlen($params['Sign']) < 32 || strlen($params['Sign']) > 256 || !preg_match('/^[a-fA-F0-9]+$/', $params['Sign'])) {
                throw new Exception(esc_html__('Invalid signature format.', 'paydibs-gateway'), 400);
            }

            return $params;
        }

        /**
         * Normalize monetary values for strict gateway comparisons.
         *
         * @param mixed $amount Raw amount.
         * @return string
         */
        protected function normalize_txn_amount($amount)
        {
            return number_format((float) $amount, 2, '.', '');
        }

        /**
         * Fields that must match between callback/return payload and QUERY response.
         *
         * @return string[]
         */
        protected function get_payment_binding_field_keys()
        {
            return [
                'MerchantID',
                'MerchantPymtID',
                'MerchantOrdID',
                'MerchantTxnAmt',
                'MerchantCurrCode',
            ];
        }

        /**
         * Require callback and authoritative QUERY fields to agree before completing an order.
         *
         * @param array<string, string> $params      Callback/return parameters.
         * @param array<string, string> $query_resp Verified QUERY response.
         * @throws Exception
         */
        protected function assert_callback_matches_query(array $params, array $query_resp)
        {
            foreach ($this->get_payment_binding_field_keys() as $key) {
                $callback_value = $this->normalize_binding_field_value($key, $params[$key] ?? '');
                $query_value = $this->normalize_binding_field_value($key, $query_resp[$key] ?? '');

                if ($callback_value !== $query_value) {
                    throw new Exception(
                        esc_html__(
                            'Callback and gateway query responses do not match.',
                            'paydibs-gateway'
                        ),
                        409
                    );
                }
            }
        }

        /**
         * @param string $key   Binding field name.
         * @param string $value Field value.
         * @return string
         */
        protected function normalize_binding_field_value($key, $value)
        {
            if ($key === 'MerchantTxnAmt') {
                return $this->normalize_txn_amount($value);
            }

            if ($key === 'MerchantCurrCode') {
                return strtoupper(trim($value));
            }

            return trim((string) $value);
        }

        /**
         * Resolve the WooCommerce order using stored payment meta (not request order id alone).
         *
         * @param array<string, string> $params      Callback/return parameters.
         * @param array<string, string> $query_resp Verified QUERY response.
         * @return WC_Order
         * @throws Exception
         */
        protected function resolve_order_for_payment(array $params, array $query_resp)
        {
            if ((string) $params['MerchantID'] !== (string) $this->merchant_id) {
                throw new Exception(esc_html__('Invalid merchant identifier.', 'paydibs-gateway'), 403);
            }

            $payment_id = (string) $query_resp['MerchantPymtID'];
            $order = $this->find_order_by_payment_id($payment_id);

            if (!$order) {
                $candidate = wc_get_order((int) $query_resp['MerchantOrdID']);
                if (
                    $candidate
                    && $this->getOrderId($candidate)
                    && (string) $candidate->get_meta(self::META_MERCHANT_PYMT_ID) === $payment_id
                ) {
                    $order = $candidate;
                }
            }

            if (!$order || !$this->getOrderId($order)) {
                throw new Exception(esc_html__('Order not found.', 'paydibs-gateway'), 404);
            }

            $this->assert_payment_matches_order($params, $query_resp, $order);

            return $order;
        }

        /**
         * @param string $payment_id Paydibs MerchantPymtID.
         * @return WC_Order|false
         */
        protected function find_order_by_payment_id($payment_id)
        {
            if ($payment_id === '') {
                return false;
            }

            if (function_exists('wc_get_orders')) {
                $orders = wc_get_orders([
                    'limit' => 1,
                    'meta_key' => self::META_MERCHANT_PYMT_ID,
                    'meta_value' => $payment_id,
                    'return' => 'objects',
                ]);

                if (!empty($orders) && $orders[0] instanceof WC_Order) {
                    return $orders[0];
                }
            }

            return false;
        }

        /**
         * Cross-check gateway data against order meta and totals before payment completion.
         *
         * @param array<string, string> $params      Callback/return parameters.
         * @param array<string, string> $query_resp Verified QUERY response.
         * @param WC_Order              $order       Resolved order.
         * @throws Exception
         */
        protected function assert_payment_matches_order(array $params, array $query_resp, $order)
        {
            $stored_payment_id = (string) $order->get_meta(self::META_MERCHANT_PYMT_ID);
            $stored_amount = $this->normalize_txn_amount($order->get_meta(self::META_MERCHANT_TXN_AMT));
            $stored_currency = strtoupper((string) $order->get_meta(self::META_MERCHANT_CURR_CODE));
            $stored_merchant_id = (string) $order->get_meta(self::META_MERCHANT_ID);

            if ($stored_payment_id === '') {
                throw new Exception(
                    esc_html__('Payment reference does not match this order.', 'paydibs-gateway'),
                    409
                );
            }

            $expected_amount = $stored_amount !== ''
                ? $stored_amount
                : $this->normalize_txn_amount($order->get_total());

            $expected_currency = $stored_currency !== ''
                ? $stored_currency
                : strtoupper((string) $order->get_currency());

            $binding = [
                'MerchantPymtID' => $stored_payment_id,
                'MerchantOrdID' => (string) $this->getOrderId($order),
                'MerchantTxnAmt' => $expected_amount,
                'MerchantCurrCode' => $expected_currency,
            ];

            if ($stored_merchant_id !== '') {
                $binding['MerchantID'] = $stored_merchant_id;
            }

            foreach ($binding as $key => $expected) {
                $callback_value = $this->normalize_binding_field_value($key, $params[$key] ?? '');
                $query_value = $this->normalize_binding_field_value($key, $query_resp[$key] ?? '');

                if ($callback_value !== $expected || $query_value !== $expected) {
                    throw new Exception(
                        esc_html__('Payment reference does not match this order.', 'paydibs-gateway'),
                        409
                    );
                }
            }
        }

        /**
         * Verify the payment/Query signature
         * 
         * @param array $params
         * @param string $merchant_password
         * @throws Exception
         */
        protected function verifySignature($params, $merchant_password)
        {
            $signature_data = $merchant_password .
                $params['MerchantID'] .
                $params['MerchantPymtID'] .
                $params['PTxnID'] .
                $params['MerchantOrdID'] .
                $params['MerchantTxnAmt'] .
                $params['MerchantCurrCode'] .
                $params['PTxnStatus'] .
                $params['AuthCode'];

            $computed_signature = hash('sha512', $signature_data);
            $expected = strtolower($computed_signature);
            $received = strtolower((string) $params['Sign']);

            if (!hash_equals($expected, $received)) {
                throw new Exception(esc_html__('Invalid signature.', 'paydibs-gateway'), 401);
            }
        }

        /**
         * Process the payment based on transaction status
         * 
         * @param array $params
         * @param bool $is_callback
         * @param bool $is_debug_log
         * @param WC_Order $order Order resolved via stored payment reference.
         * @throws Exception
         */
        protected function processPayment($params, $is_callback, $is_debug_log, $order) {
            if (!$order || !$this->getOrderId($order)) {
                throw new Exception(esc_html__('Order not found.', 'paydibs-gateway'), 404);
            }
            
            $order_status = $order->get_status();
            
            // Skip processing if order is already completed
            if (in_array($order_status, ['processing', 'completed', 'on-hold'])) {
                $this->sendResponse([
                    'response' => 1,
                    'message' => "Order status already updated to $order_status"
                ], $is_callback, $order);
                return;
            }
            
            // Handle different transaction statuses
            switch ($params['PTxnStatus']) {
                case '0': // Success
                    $order->payment_complete($params['PTxnID']);
                    $order->add_order_note(
                        "Payment received via Paydibs. Transaction ID: {$params['PTxnID']}"
                    );
                    $this->emptyCart();
                    $this->sendResponse([
                        'response' => 1,
                        'message' => 'Payment successful'
                    ], $is_callback, $order);
                    break;
                    
                case '2': // Pending
                    if ($is_debug_log) {
                        $this->GenerateCallbackURL($params, 0); // Success Payload
                    }
                    $order->update_status('pending', 'Payment pending at gateway');
                    $this->sendResponse([
                        'response' => 1,
                        'message' => 'Payment Pending'
                    ], $is_callback, $order);
                    break;
                    
                default: // Failed
                    if ($is_debug_log) {
                        $this->GenerateCallbackURL($params, 2); // Pending payload
                    }
                    $order->update_status(
                        'failed',
                        "Payment failed: {$params['PTxnStatus']}. Message: {$params['PTxnMsg']}"
                    );
                    $this->sendResponse([
                        'response' => -1,
                        'message' => 'Payment failed'
                    ], $is_callback, $order);
            }
        }

        /**
         * Send JSON response for Paydibs server-to-server callbacks.
         *
         * @param array $response
         * @param int   $http_code HTTP status code.
         */
        protected function send_paydibs_json_response(array $response, $http_code = 200)
        {
            nocache_headers();
            if (!headers_sent()) {
                status_header($http_code);
                header('Content-Type: application/json; charset=' . get_option('blog_charset'));
            }
            echo wp_json_encode($response);
            exit;
        }

        /**
         * Send appropriate response based on request type
         *
         * @param array $response
         * @param bool $is_callback
         * @param WC_Order|null $order
         */
        protected function sendResponse($response, $is_callback, $order = null)
        {
            if ($is_callback) {
                $this->send_paydibs_json_response($response, 200);
            }
            if ($order) {
                $redirect_url = ($response['response'] === 1)
                    ? $this->get_return_url($order)
                    : $order->get_checkout_payment_url();
                wp_safe_redirect($redirect_url);
            }
            exit();
        }

        /**
         * Empty the WooCommerce cart if it exists
         */
        protected function emptyCart() {
            // WC 2.1+ method
            if (function_exists('WC') && WC()->cart) {
                WC()->cart->empty_cart();
            }
            // Older method
            elseif (isset($woocommerce->cart)) {
                $woocommerce->cart->empty_cart();
            }
        }

        /**
         * Handle errors and send appropriate response
         * 
         * @param Exception $e
         * @param bool $is_callback
         */
        protected function handleError($e, $is_callback)
        {
            if ($this->debug) {
                $this->log($e->getMessage(), 'error');
            }

            $response = [
                'response' => -1,
                'message' => $e->getMessage(),
            ];

            if ($is_callback) {
                $code = (int) $e->getCode();
                if ($code < 100 || $code > 599) {
                    $code = 400;
                }
                $this->send_paydibs_json_response($response, $code);
            }

            wp_safe_redirect(wc_get_page_permalink('checkout'));
            exit();
        }

        /**
         * Sends a query request to the payment gateway
         *
         * @param array $params Array containing required parameters
         * @return array Response from the payment gateway
         * @throws InvalidArgumentException If required parameters are missing
         */
        public function sendQueryRequest(array $params)
        {
            $required_keys = [
                'MerchantID',
                'MerchantPymtID',
                'MerchantTxnAmt',
                'MerchantCurrCode',
            ];

            foreach ($required_keys as $key) {
                if (!array_key_exists($key, $params)) {
                    throw new InvalidArgumentException(
                        esc_html(
                            sprintf(
                                /* translators: %s: parameter name */
                                __('Missing required parameter: %s', 'paydibs-gateway'),
                                (string) $key
                            )
                        )
                    );
                }
            }

            /*
             * Rebuild the query string from the same Pay Response fields the gateway sent (plus QUERY / new Sign).
             * Only allow keys we validate for callbacks so nothing extra can be injected.
             */
            $query_params = [];
            foreach ($this->get_gateway_response_param_keys() as $key) {
                if ('Sign' === $key) {
                    continue;
                }
                if (!array_key_exists($key, $params)) {
                    continue;
                }
                $val = $params[$key];
                if (!is_scalar($val)) {
                    continue;
                }
                $query_params[ $key ] = sanitize_text_field((string) $val);
            }

            $query_params['TxnType'] = 'QUERY';
            $query_params['Sign'] = $this->generate_query_req_signature($query_params);

            $payment_url = $this->test_mode ? self::TEST_GATEWAY_URL : self::LIVE_GATEWAY_URL;

            $queryString = http_build_query($query_params);
            
            $response = wp_remote_get($payment_url . '?' . $queryString, [
                'timeout' => 30,
                'sslverify' => true,
            ]);

            if (is_wp_error($response)) {
                throw new RuntimeException(
                    esc_html(
                        sprintf(
                            /* translators: %s: error message */
                            __('HTTP error contacting payment gateway: %s', 'paydibs-gateway'),
                            sanitize_text_field((string) $response->get_error_message())
                        )
                    )
                );
            }

            $status_code = (int) wp_remote_retrieve_response_code($response);
            if (200 !== $status_code) {
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is escaped above; second constructor argument is a numeric HTTP status for Exception::getCode(), not rendered output.
                throw new RuntimeException(
                    esc_html(
                        sprintf(
                            /* translators: %d: HTTP status code */
                            __('Unexpected HTTP response code from payment gateway: %d', 'paydibs-gateway'),
                            $status_code
                        )
                    ),
                    (int) ( ( $status_code >= 400 && $status_code <= 599 ) ? $status_code : 502 )
                );
            }

            $body = wp_remote_retrieve_body($response);

            return $this->parse_verified_gateway_api_json_response($body, $this->secretKey);
        }
        

        /**
         * Log messages in a WooCommerce version-compatible way
         * 
         * @param string $message The message to log
         * @param string $level Optional. Level of the log (emergency, alert, critical, error, warning, notice, info, debug)
         * @param string $context Optional. Context for the log (defaults to your payment gateway ID)
         */
        protected function log($message, $level = 'info', $context = "paydibs") {
            if (!$this->debug) {
                return;
            }
        
            $context = $context ?: $this->id;
            
            // WC 3.0+ compatible logging
            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->log($level, $message, ['source' => $context]);
            } 
            // WC 2.1+ compatible logging
            elseif (function_exists('WC') && is_callable([WC(), 'logger'])) {
                WC()->logger()->add($context, $message);
            }
            // Fallback for very old versions
            elseif (class_exists('WC_Logger')) {
                $logger = new WC_Logger();
                $logger->add($context, $message);
            }
            // Ultimate fallback: no logging if no logger available.
        }

        public function GenerateCallbackURL($params, $PTxnStatus = 2){
            unset($params["Sign"]);

            $params['PTxnStatus'] = $PTxnStatus;
            $params['MerchantCallbackURL'] = $this->callbackurl;
            $params['MerchantRURL'] = $this->returnurl;

            $signature_data =
                $this->secretKey .
                $params['MerchantID'] .
                $params['MerchantPymtID'] .
                $params['PTxnID'] .
                $params['MerchantOrdID'] .
                $params['MerchantTxnAmt'] .
                $params['MerchantCurrCode'] .
                $params['PTxnStatus'] .
                $params['AuthCode'];

            $params['Sign'] = hash("sha512", $signature_data);

            // Convert array to query string
            $queryString = http_build_query($params);

            $this->log('Json for Callback: ' . wp_json_encode($params));
        }

        public function getOrderId($order){
            return method_exists($order, 'get_id') ? $order->get_id() : $order->id;
        }

        /**
         * Hook function that will be auto-called by WC on payment page to show button's icon for this payment gateway button
         * @return string Gateway payment button html tag to render icon images.
         */
        public function get_icon()
        {
            $image_url = $this->plugin_dir . '/assets/pdbs-banner.png';
            $image_tag =
                '<img src="' . esc_url($image_url) . '" alt="' . esc_attr__('Paydibs Gateway', 'paydibs-gateway') . '" /> ';
            $image_tag_after_filter = apply_filters(
                'paydibs_payment_gateway_icon_before_render',
                $image_tag
            );
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            $image_tag_after_filter = apply_filters(
                /* phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound */
                "woocommerce_gateway_icon",
                $image_tag_after_filter,
                $this->id
            );
            return $image_tag_after_filter;
        }
    }
}

/**
 * Allow Paydibs gateway hosts for safe redirects.
 *
 * @param array $hosts
 * @return array
 */
function paydibs_allowed_redirect_hosts($hosts)
{
    $urls = [
        Paydibs_Payment_Gateway::LIVE_GATEWAY_URL,
        Paydibs_Payment_Gateway::TEST_GATEWAY_URL,
    ];

    foreach ($urls as $url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        if (!empty($host)) {
            $hosts[] = $host;
        }
    }

    return array_values(array_unique($hosts));
}
