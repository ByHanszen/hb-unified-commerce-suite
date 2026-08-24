<?php
namespace HB\UCS\Modules\Bundles\Blocks;

use Automattic\WooCommerce\Blocks\Integrations\IntegrationInterface;
use HB\UCS\Modules\Bundles\Admin\BundleSettings;
use HB\UCS\Modules\Bundles\Support\BundleData;

if (!defined('ABSPATH')) exit;

if (interface_exists(IntegrationInterface::class)) {
    final class BundleBlocksIntegration implements IntegrationInterface {
        public function get_name() {
            return 'hb-ucs-bundles';
        }

        public function initialize() {
            $version = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';
            $base = plugins_url('src/Modules/Bundles/assets/', HB_UCS_PLUGIN_FILE);
            wp_enqueue_style('hb-ucs-bundles-blocks', $base . 'blocks-hb-ucs-bundles.css', [], $version);
            wp_register_script(
                'hb-ucs-bundles-blocks',
                $base . 'blocks-hb-ucs-bundles.js',
                ['wc-blocks-checkout'],
                $version,
                true
            );
        }

        public function get_script_handles() {
            return ['hb-ucs-bundles-blocks'];
        }

        public function get_editor_script_handles() {
            return [];
        }

        public function get_script_data() {
            return [
                'editLabel' => __('Samenstelling wijzigen', 'hb-ucs'),
            ];
        }
    }
}

final class BundleBlocks {
    private const TOTAL_FIELDS = ['line_subtotal', 'line_subtotal_tax', 'line_total', 'line_total_tax'];

    public function init(): void {
        add_filter('rest_request_after_callbacks', [$this, 'enrich_cart_response'], 20, 3);
        add_filter('woocommerce_hydration_request_after_callbacks', [$this, 'enrich_cart_response'], 20, 3);
        add_filter('woocommerce_store_api_product_quantity_editable', [$this, 'quantity_editable'], 20, 3);
        add_filter('woocommerce_store_api_product_quantity_maximum', [$this, 'quantity_maximum'], 20, 3);

        foreach (['mini-cart', 'cart', 'checkout'] as $block) {
            add_action('woocommerce_blocks_' . $block . '_block_registration', [$this, 'register_integration']);
        }
    }

    public function register_integration($registry): void {
        if (class_exists(__NAMESPACE__ . '\\BundleBlocksIntegration') && is_object($registry) && method_exists($registry, 'register')) {
            $registry->register(new BundleBlocksIntegration());
        }
    }

    public function enrich_cart_response($response, $server, $request) {
        if (is_wp_error($response) || !is_object($request) || !method_exists($request, 'get_route')) {
            return $response;
        }
        if (strpos((string) $request->get_route(), '/wc/store/') === false || !is_object($response) || !method_exists($response, 'get_data') || !method_exists($response, 'set_data')) {
            return $response;
        }
        $data = $response->get_data();
        if (!is_array($data) || empty($data['items']) || !is_array($data['items']) || !function_exists('WC') || !WC()->cart) {
            return $response;
        }

        $cart = WC()->cart->get_cart();
        $settings = BundleSettings::get();
        $groupTotals = [];
        foreach ($data['items'] as $itemData) {
            $key = is_array($itemData) ? (string) ($itemData['key'] ?? '') : '';
            $cartItem = $key !== '' && isset($cart[$key]) ? $cart[$key] : null;
            $instance = is_array($cartItem) ? (string) ($cartItem[BundleData::CART_INSTANCE] ?? '') : '';
            if ($instance === '' || empty($cartItem['woosb_parent_id'])) {
                continue;
            }
            foreach (self::TOTAL_FIELDS as $field) {
                $groupTotals[$instance][$field] = (int) ($groupTotals[$instance][$field] ?? 0) + $this->read_total($itemData['totals'] ?? null, $field);
            }
        }

        foreach ($data['items'] as &$itemData) {
            if (!is_array($itemData)) {
                continue;
            }
            $key = (string) ($itemData['key'] ?? '');
            $cartItem = $key !== '' && isset($cart[$key]) ? $cart[$key] : null;
            if (!is_array($cartItem)) {
                continue;
            }
            $instance = (string) ($cartItem[BundleData::CART_INSTANCE] ?? '');
            if (!empty($cartItem['woosb_ids'])) {
                $itemData['hb_ucs_bundle_parent'] = true;
                $itemData['hb_ucs_bundle_dynamic'] = empty($cartItem['woosb_fixed_price']);
                if ($instance !== '' && empty($cartItem['woosb_fixed_price']) && !empty($groupTotals[$instance])) {
                    foreach (self::TOTAL_FIELDS as $field) {
                        $this->write_total($itemData['totals'], $field, (string) (int) ($groupTotals[$instance][$field] ?? 0));
                    }
                }
                if (!empty($settings['allow_cart_edit']) && !empty($cartItem['data']) && BundleData::is_bundle_product($cartItem['data'])) {
                    $itemData['hb_ucs_bundle_edit_url'] = esc_url_raw(add_query_arg('hb_ucs_bundle_edit', $key, $cartItem['data']->get_permalink()));
                }
            } elseif (!empty($cartItem['woosb_parent_id'])) {
                $itemData['hb_ucs_bundle_child'] = true;
                $itemData['hb_ucs_bundle_hidden'] = !empty($settings['hide_children_cart']);
                $itemData['hb_ucs_bundle_parent_name'] = wp_strip_all_tags((string) get_the_title((int) $cartItem['woosb_parent_id']), true);
                if (isset($itemData['quantity_limits']) && is_object($itemData['quantity_limits'])) {
                    $itemData['quantity_limits']->editable = false;
                } elseif (isset($itemData['quantity_limits']) && is_array($itemData['quantity_limits'])) {
                    $itemData['quantity_limits']['editable'] = false;
                }
            }
            if (!empty($cartItem['woosb_fixed_price'])) {
                $itemData['hb_ucs_bundle_fixed'] = true;
            }
        }
        unset($itemData);

        $response->set_data($data);
        return $response;
    }

    public function quantity_editable($editable, $product, $cartItem = null) {
        return is_array($cartItem) && !empty($cartItem['woosb_parent_id']) ? false : $editable;
    }

    public function quantity_maximum($maximum, $product, $cartItem = null) {
        if (!is_array($cartItem) || empty($cartItem['woosb_ids']) || empty($cartItem['woosb_keys']) || !function_exists('WC') || !WC()->cart) {
            return $maximum;
        }

        $cartQuantities = method_exists(WC()->cart, 'get_cart_item_quantities') ? WC()->cart->get_cart_item_quantities() : [];
        $currentGroup = [];
        $perBundle = [];
        $stockProducts = [];
        foreach ((array) $cartItem['woosb_keys'] as $childKey) {
            $child = WC()->cart->get_cart_item((string) $childKey);
            if (!is_array($child) || empty($child['data']) || !is_object($child['data'])) {
                continue;
            }
            $childProduct = $child['data'];
            if (!$childProduct->managing_stock() || $childProduct->backorders_allowed()) {
                continue;
            }
            $stockId = method_exists($childProduct, 'get_stock_managed_by_id') ? (int) $childProduct->get_stock_managed_by_id() : (int) $childProduct->get_id();
            $stockProduct = $stockId > 0 ? wc_get_product($stockId) : false;
            if (!$stockProduct || $stockProduct->get_stock_quantity() === null) {
                continue;
            }
            $currentGroup[$stockId] = (float) ($currentGroup[$stockId] ?? 0.0) + max(0.0, (float) ($child['quantity'] ?? 0));
            $perBundle[$stockId] = (float) ($perBundle[$stockId] ?? 0.0) + max(0.0, (float) ($child['woosb_qty'] ?? 0));
            $stockProducts[$stockId] = $stockProduct;
        }

        $limit = (float) $maximum;
        foreach ($perBundle as $stockId => $componentQuantity) {
            if ($componentQuantity <= 0) {
                continue;
            }
            $otherQuantity = max(0.0, (float) ($cartQuantities[$stockId] ?? 0.0) - (float) ($currentGroup[$stockId] ?? 0.0));
            $available = max(0.0, (float) $stockProducts[$stockId]->get_stock_quantity() - $otherQuantity);
            $limit = min($limit, floor($available / $componentQuantity));
        }
        return wc_stock_amount(max(0.0, $limit));
    }

    private function read_total($totals, string $field): int {
        if (is_object($totals) && isset($totals->{$field})) {
            return (int) $totals->{$field};
        }
        return is_array($totals) ? (int) ($totals[$field] ?? 0) : 0;
    }

    private function write_total(&$totals, string $field, string $value): void {
        if (is_object($totals)) {
            $totals->{$field} = $value;
        } elseif (is_array($totals)) {
            $totals[$field] = $value;
        }
    }
}
