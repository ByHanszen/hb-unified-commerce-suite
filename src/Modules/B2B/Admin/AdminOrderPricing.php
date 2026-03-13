<?php
// =============================
// src/Modules/B2B/Admin/AdminOrderPricing.php
// =============================
namespace HB\UCS\Modules\B2B\Admin;

use HB\UCS\Modules\B2B\Engine\PriceEngine;
use HB\UCS\Modules\B2B\Storage\SettingsStore;
use HB\UCS\Modules\B2B\Support\Validator;

if (!defined('ABSPATH')) exit;

class AdminOrderPricing {
    private PriceEngine $engine;

    public function __construct(PriceEngine $engine) {
        $this->engine = $engine;
    }

    public function init(): void {
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_box']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_hb_ucs_b2b_recalc_order_prices', [$this, 'ajax_recalc']);
    }

    public function enqueue(string $hook): void {
        if (!Validator::can_manage()) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || empty($screen->id)) return;

        if ($screen->id !== 'shop_order' && $screen->id !== 'woocommerce_page_wc-orders') {
            return;
        }

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 5) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/Modules/B2B/assets/', $pluginFile));
        $ver = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';

        $opt = get_option(SettingsStore::OPT, []);
        $auto = is_array($opt) && !empty($opt['admin_auto_recalc_on_customer_change']);

        wp_enqueue_script('hb-ucs-b2b-order', $base . 'admin-order-b2b.js', ['jquery'], $ver, true);
        wp_localize_script('hb-ucs-b2b-order', 'HB_UCS_B2B_ORDER', [
            'nonce' => wp_create_nonce('hb_ucs_b2b_recalc_order_prices'),
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'autoRecalcOnCustomerChange' => $auto ? 1 : 0,
            'i18n' => [
                'working' => __('Herberekenen…', 'hb-ucs'),
                'done' => __('Prijzen herberekend.', 'hb-ucs'),
                'failed' => __('Herberekenen mislukt.', 'hb-ucs'),
                'missingOrder' => __('Order is nog niet opgeslagen.', 'hb-ucs'),
                'needsSaveOrder' => __('Sla de order eerst op voordat je herberekent.', 'hb-ucs'),
                'needsSaveItems' => __('Sla eerst de orderregels op (knop “Order items opslaan”) en probeer daarna opnieuw.', 'hb-ucs'),
            ],
        ]);
    }

    public function render_box($order): void {
        if (!Validator::can_manage()) return;
        if (!($order instanceof \WC_Order)) return;

        echo '<div class="order_data_column">';
        echo '<h4>' . esc_html__('B2B prijzen', 'hb-ucs') . '</h4>';
        echo '<p class="description">' . esc_html__('Gebruik dit om bestaande orderregels opnieuw te prijzen op basis van de geselecteerde klant (ex btw).', 'hb-ucs') . '</p>';
        echo '<p>';
        echo '<button type="button" class="button" id="hb-ucs-b2b-recalc" data-order-id="' . (int)$order->get_id() . '">' . esc_html__('Herbereken prijzen volgens klant', 'hb-ucs') . '</button>';
        echo '</p>';
        echo '</div>';
    }

    public function ajax_recalc(): void {
        if (!Validator::can_manage()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        check_ajax_referer('hb_ucs_b2b_recalc_order_prices', 'nonce');

        $order_id = isset($_POST['order_id']) ? (int) $_POST['order_id'] : 0;
        if ($order_id <= 0) {
            wp_send_json_error(['message' => 'missing_order_id'], 400);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'order_not_found'], 404);
        }

        $customer_id = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : (int) $order->get_customer_id();
        if ($customer_id !== (int) $order->get_customer_id()) {
            $order->set_customer_id($customer_id);
        }

        $settings = new SettingsStore();
        $s = $settings->get();
        $baseMode = (string)($s['price_base'] ?? 'regular');

        foreach ($order->get_items('line_item') as $item_id => $item) {
            if (!($item instanceof \WC_Order_Item_Product)) continue;
            $product = $item->get_product();
            if (!$product) continue;

            // Base = regular/current depending on B2B setting, then apply B2B if any.
            $regular = method_exists($product, 'get_regular_price') ? (string) $product->get_regular_price('edit') : '';
            $current = method_exists($product, 'get_price') ? (string) $product->get_price('edit') : '';
            $reg = is_numeric($regular) ? (float) $regular : null;
            $cur = is_numeric($current) ? (float) $current : null;
            $base_raw = ($baseMode === 'current') ? ($cur ?? $reg) : ($reg ?? $cur);
            if ($base_raw === null) {
                continue;
            }

            $base_ex = (float) wc_get_price_excluding_tax($product, ['price' => $base_raw, 'qty' => 1]);

            $new_ex = $this->engine->resolve_price_ex($product, $customer_id, $base_ex);
            if ($new_ex === null) {
                $new_ex = $base_ex;
            }

            $qty = max(1, (int) $item->get_quantity());
            $line_subtotal_ex = $base_ex * $qty;
            $line_total_ex = $new_ex * $qty;

            // IMPORTANT: Keep original subtotal and discounted total so Woo shows a line discount.
            $item->set_subtotal($line_subtotal_ex);
            $item->set_total($line_total_ex);
        }

        // Recalculate taxes & totals.
        $order->calculate_taxes();
        $order->calculate_totals(false);
        $order->save();

        // Return updated items HTML so the admin UI can refresh without reloading the page.
        $items_html = '';
        if (function_exists('WC')) {
            $wc = WC();
            if ($wc && method_exists($wc, 'plugin_path')) {
                $path = trailingslashit($wc->plugin_path()) . 'includes/admin/meta-boxes/views/html-order-items.php';
                if (is_string($path) && file_exists($path)) {
                    $order = wc_get_order($order_id);
                    ob_start();
                    include $path;
                    $items_html = (string) ob_get_clean();
                }
            }
        }

        wp_send_json_success([
            'order_id' => $order_id,
            'total' => $order->get_total(),
            'html' => $items_html,
        ]);
    }
}
