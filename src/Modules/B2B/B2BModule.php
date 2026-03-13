<?php
// =============================
// src/Modules/B2B/B2BModule.php
// =============================
namespace HB\UCS\Modules\B2B;

use HB\UCS\Modules\B2B\Admin\AdminOrderPricing;
use HB\UCS\Modules\B2B\Checkout\MethodVisibility;
use HB\UCS\Modules\B2B\Engine\PriceEngine;
use HB\UCS\Modules\B2B\Storage\SettingsStore;
use HB\UCS\Modules\B2B\Support\Context;

if (!defined('ABSPATH')) exit;

class B2BModule {
    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Allow admin-ajax (order edit) to price for selected customer.
        add_action('init', [$this, 'maybe_force_user_from_admin_order_ajax'], 1);

        $settings = new SettingsStore();
        $methodVisibility = new MethodVisibility($settings);
        $priceEngine = new PriceEngine($settings);

        // Shipping / payment visibility in checkout.
        add_filter('woocommerce_package_rates', [$methodVisibility, 'filter_package_rates'], 100, 2);
        add_filter('woocommerce_available_payment_gateways', [$methodVisibility, 'filter_payment_gateways'], 100);

        // Pricing: product + variation.
        add_filter('woocommerce_product_get_price', [$priceEngine, 'filter_product_price'], 1000, 2);
        add_filter('woocommerce_product_variation_get_price', [$priceEngine, 'filter_product_price'], 1000, 2);

        // Cart consistency: ensure cart items use the same computed price.
        add_action('woocommerce_before_calculate_totals', [$priceEngine, 'reprice_cart_items'], 1000, 1);

        // Price display
        add_filter('woocommerce_get_price_html', [$priceEngine, 'filter_price_html'], 99, 2);
        add_filter('woocommerce_cart_item_price', [$priceEngine, 'filter_cart_item_price'], 99, 3);
        add_filter('woocommerce_cart_item_subtotal', [$priceEngine, 'filter_cart_item_subtotal'], 99, 3);

        // Admin order integrations.
        (new AdminOrderPricing($priceEngine))->init();
    }

    public function maybe_force_user_from_admin_order_ajax(): void {
        if (!is_admin() || !wp_doing_ajax()) return;

        $action = isset($_REQUEST['action']) ? (string) wp_unslash($_REQUEST['action']) : '';
        $orderAjaxActions = [
            'woocommerce_add_order_item',
            'woocommerce_add_order_item_meta',
            'woocommerce_save_order_items',
            'woocommerce_calc_line_taxes',
            'woocommerce_calc_taxes',
            'woocommerce_remove_order_item',
        ];
        if (!in_array($action, $orderAjaxActions, true)) return;

        $order_id = isset($_REQUEST['order_id']) ? (int) $_REQUEST['order_id'] : 0;
        if ($order_id <= 0) return;

        $order = wc_get_order($order_id);
        if (!$order) return;

        $customer_id = (int) $order->get_customer_id();
        if ($customer_id > 0) {
            Context::set_forced_user_id($customer_id);
        }
    }
}
