<?php

if (!defined('ABSPATH')) {
    exit;
}

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class Paydibs_Payment_Gateway_Blocks_Payment_Method extends AbstractPaymentMethodType
{

    private $gateway;
    protected $name = 'paydibsgateway';

    public function initialize()
    {
        $this->settings = get_option('woocommerce_paydibsgateway_settings', []);

        if (function_exists('WC') && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (isset($gateways['paydibsgateway']) && $gateways['paydibsgateway'] instanceof Paydibs_Payment_Gateway) {
                $this->gateway = $gateways['paydibsgateway'];
                return;
            }
        }

        $this->gateway = new Paydibs_Payment_Gateway();
    }

    public function is_active()
    {
        return $this->gateway->is_available();
    }

    public function get_payment_method_script_handles()
    {

        wp_register_script(
            'paydibs-gateway-blocks-integration',
            plugin_dir_url(__FILE__) . 'block/checkout.js',
            [
                'wc-blocks-registry',
                'wc-settings',
                'wp-element',
                'wp-html-entities',
                'wp-i18n',
            ],
            defined('PAYDIBS_GATEWAY_VERSION') ? PAYDIBS_GATEWAY_VERSION : null,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations(
                'paydibs-gateway-blocks-integration',
                'paydibs-gateway',
                dirname(__DIR__) . '/languages'
            );
        }

        return ['paydibs-gateway-blocks-integration'];
    }

    public function get_payment_method_data()
    {
        return [
            'title' => $this->gateway->title,
            'description' => $this->gateway->description,
        ];
    }
}
