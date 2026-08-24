<?php
namespace HB\UCS\Modules\Bundles\Orders;

use HB\UCS\Modules\Bundles\Admin\BundleSettings;
use HB\UCS\Modules\Bundles\Support\BundleData;

if (!defined('ABSPATH')) exit;

final class BundleOrders {
    private bool $expandingAdminItem = false;

    public function init(): void {
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'create_order_line_item'], 30, 4);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'hidden_order_item_meta']);
        add_filter('woocommerce_order_item_class', [$this, 'order_item_class'], 20, 3);
        add_filter('woocommerce_order_item_visible', [$this, 'order_item_visible'], 20, 2);
        add_filter('woocommerce_order_item_name', [$this, 'order_item_name'], 20, 2);
        add_action('woocommerce_order_item_meta_start', [$this, 'render_order_bundle_details'], 20, 3);
        add_filter('woocommerce_order_formatted_line_subtotal', [$this, 'formatted_line_subtotal'], 9999, 3);
        add_action('woocommerce_ajax_add_order_item_meta', [$this, 'expand_admin_bundle_item'], 30, 3);
        add_filter('woocommerce_order_again_cart_item_data', [$this, 'order_again_cart_item_data'], 20, 3);
        add_action('woocommerce_cart_loaded_from_session', [$this, 'clean_order_again_children'], 30);
    }

    public function create_order_line_item($item, string $cartItemKey, array $values, $order): void {
        $instance = (string) ($values[BundleData::CART_INSTANCE] ?? '');
        if ($instance !== '') {
            $item->update_meta_data(BundleData::ORDER_INSTANCE, $instance);
        }
        if (!empty($values['woosb_ids'])) {
            $item->update_meta_data('_woosb_ids', (string) $values['woosb_ids']);
            $item->update_meta_data('_woosb_price', (float) ($values['woosb_price'] ?? 0));
            $item->update_meta_data('_woosb_fixed_price', !empty($values['woosb_fixed_price']) ? 'yes' : 'no');
            $item->update_meta_data('_woosb_discount', (float) ($values['woosb_discount'] ?? 0));
            $item->update_meta_data('_woosb_discount_amount', (float) ($values['woosb_discount_amount'] ?? 0));
            $item->update_meta_data(BundleData::ORDER_ROLE, 'parent');
            if (!empty($values[BundleData::CART_SNAPSHOT])) {
                $item->update_meta_data(BundleData::ORDER_SNAPSHOT, $values[BundleData::CART_SNAPSHOT]);
            }
        } elseif (!empty($values['woosb_parent_id'])) {
            $item->update_meta_data('_woosb_parent_id', (int) $values['woosb_parent_id']);
            $item->update_meta_data(BundleData::ORDER_ROLE, 'child');
            if (!empty($values['hb_ucs_bundle_component_key'])) {
                $item->update_meta_data('_hb_ucs_bundle_component_key', sanitize_key((string) $values['hb_ucs_bundle_component_key']));
            }
        }
    }

    public function hidden_order_item_meta(array $hidden): array {
        return array_values(array_unique(array_merge($hidden, [
            '_woosb_parent_id', '_woosb_ids', '_woosb_price',
            '_woosb_fixed_price', '_woosb_discount', '_woosb_discount_amount', BundleData::ORDER_INSTANCE,
            BundleData::ORDER_SNAPSHOT, BundleData::ORDER_ROLE,
            '_hb_ucs_bundle_component_key',
        ])));
    }

    public function order_item_class(string $class, $item, $order = null): string {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return $class;
        }
        $role = (string) $item->get_meta(BundleData::ORDER_ROLE, true);
        if ($role === 'parent' || ($role === '' && $item->get_meta('_woosb_ids', true))) {
            $class .= ' hb-ucs-bundle-order-parent';
        } elseif ($role === 'child' || ($role === '' && $item->get_meta('_woosb_parent_id', true))) {
            $class .= ' hb-ucs-bundle-order-child';
        }
        return trim($class);
    }

    public function order_item_visible(bool $visible, $item): bool {
        $settings = BundleSettings::get();
        if (!empty($settings['hide_children_order']) && is_object($item) && method_exists($item, 'get_meta') && $item->get_meta('_woosb_parent_id', true)) {
            return false;
        }
        return $visible;
    }

    public function order_item_name(string $name, $item): string {
        if (is_object($item) && method_exists($item, 'get_meta') && $item->get_meta('_woosb_parent_id', true)) {
            return '<span class="hb-ucs-bundle-order-child-name">↳ ' . $name . '</span>';
        }
        return $name;
    }

    public function render_order_bundle_details($itemId, $item, $order = null): void {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return;
        }
        $snapshot = $item->get_meta(BundleData::ORDER_SNAPSHOT, true);
        if (!is_array($snapshot) || empty($snapshot['components'])) {
            return;
        }
        echo '<div class="hb-ucs-bundle-order-summary"><strong>' . esc_html__('Dit pakket bevat:', 'hb-ucs') . '</strong><ul>';
        foreach ((array) $snapshot['components'] as $component) {
            $qty = wc_format_localized_decimal((float) ($component['quantity'] ?? 0));
            $name = (string) ($component['name'] ?? '');
            if ($name !== '') {
                echo '<li>' . esc_html($qty . ' × ' . $name) . '</li>';
            }
        }
        echo '</ul></div>';
    }

    public function formatted_line_subtotal(string $subtotal, $item, $order = null): string {
        if (is_object($item) && method_exists($item, 'get_meta') && $item->get_meta('_woosb_ids', true) && (float) $item->get_meta('_woosb_price', true) > 0 && (float) $item->get_total() <= 0) {
            $currency = $order && method_exists($order, 'get_currency') ? $order->get_currency() : get_woocommerce_currency();
            return wc_price((float) $item->get_meta('_woosb_price', true) * max(1, (float) $item->get_quantity()), ['currency' => $currency]);
        }
        return $subtotal;
    }

    public function expand_admin_bundle_item($orderItemId, $orderItem, $order): void {
        if ($this->expandingAdminItem || !is_object($orderItem) || !is_object($order) || !method_exists($orderItem, 'get_product')) {
            return;
        }
        $bundle = $orderItem->get_product();
        if (!BundleData::is_bundle_product($bundle)) {
            return;
        }
        $selection = $this->default_selection($bundle);
        if (empty($selection)) {
            $order->add_order_note(__('Bundel kon niet worden uitgeklapt: kies bij variabele onderdelen eerst een standaardvariatie.', 'hb-ucs'));
            return;
        }
        $instance = BundleData::generate_instance_id();
        $fixed = method_exists($bundle, 'is_fixed_price') && $bundle->is_fixed_price();
        $bundleQty = max(1, (float) $orderItem->get_quantity());
        $baseTotal = 0.0;
        foreach ($selection as $component) {
            $child = wc_get_product((int) $component['id']);
            if ($child) {
                $baseTotal += max(0.0, (float) $child->get_price()) * (float) $component['qty'];
            }
        }
        $targetTotal = $baseTotal;
        if (method_exists($bundle, 'get_discount_amount') && $bundle->get_discount_amount() > 0) {
            $targetTotal = max(0.0, $baseTotal - (float) $bundle->get_discount_amount());
        } elseif (method_exists($bundle, 'get_discount_percentage') && $bundle->get_discount_percentage() > 0) {
            $targetTotal = max(0.0, $baseTotal * (100 - (float) $bundle->get_discount_percentage()) / 100);
        }
        $ratio = $baseTotal > 0 ? $targetTotal / $baseTotal : 0.0;
        $orderItem->update_meta_data('_woosb_ids', BundleData::selection_to_string($selection));
        $orderItem->update_meta_data('_woosb_price', $fixed ? (float) $orderItem->get_total() / $bundleQty : $targetTotal);
        $orderItem->update_meta_data(BundleData::ORDER_INSTANCE, $instance);
        $orderItem->update_meta_data(BundleData::ORDER_SNAPSHOT, BundleData::build_snapshot($bundle, $selection));
        $orderItem->update_meta_data(BundleData::ORDER_ROLE, 'parent');
        if (!$fixed) {
            $orderItem->set_subtotal(0);
            $orderItem->set_total(0);
            $orderItem->set_taxes(['subtotal' => [], 'total' => []]);
        }
        $orderItem->save();

        $this->expandingAdminItem = true;
        try {
            foreach ($selection as $key => $component) {
                $child = wc_get_product((int) $component['id']);
                if (!$child) {
                    continue;
                }
                $unit = $fixed ? 0.0 : max(0.0, (float) $child->get_price() * $ratio);
                $qty = (float) $component['qty'] * $bundleQty;
                $childItem = new \WC_Order_Item_Product();
                $childItem->set_product($child);
                $childItem->set_quantity($qty);
                $childItem->set_subtotal($unit * $qty);
                $childItem->set_total($unit * $qty);
                $childItem->add_meta_data('_woosb_parent_id', $bundle->get_id(), true);
                $childItem->add_meta_data(BundleData::ORDER_INSTANCE, $instance, true);
                $childItem->add_meta_data(BundleData::ORDER_ROLE, 'child', true);
                $childItem->add_meta_data('_hb_ucs_bundle_component_key', (string) $key, true);
                $order->add_item($childItem);
            }
            if (method_exists($order, 'calculate_taxes')) {
                $order->calculate_taxes();
            }
            $order->calculate_totals(false);
            $order->save();
        } finally {
            $this->expandingAdminItem = false;
        }
    }

    public function order_again_cart_item_data(array $data, $item, $order = null): array {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return $data;
        }
        if ($ids = $item->get_meta('_woosb_ids', true)) {
            $data['woosb_ids'] = (string) $ids;
            $fixedMeta = (string) $item->get_meta('_woosb_fixed_price', true);
            $data['woosb_fixed_price'] = $fixedMeta !== ''
                ? in_array($fixedMeta, ['1', 'yes', 'on', 'true'], true) : (float) $item->get_total() > 0;
            $data[BundleData::CART_INSTANCE] = BundleData::generate_instance_id();
            $data[BundleData::CART_SNAPSHOT] = $item->get_meta(BundleData::ORDER_SNAPSHOT, true);
            $data['hb_ucs_bundle_order_again_parent'] = 1;
        } elseif ($item->get_meta('_woosb_parent_id', true)) {
            $data['hb_ucs_bundle_order_again_child'] = 1;
        }
        return $data;
    }

    public function clean_order_again_children($cart): void {
        if (!is_object($cart) || !isset($cart->cart_contents)) {
            return;
        }
        foreach ($cart->cart_contents as $key => $item) {
            if (!empty($item['hb_ucs_bundle_order_again_child'])) {
                unset($cart->cart_contents[$key]);
            }
        }
    }

    private function default_selection($bundle): array {
        $selection = [];
        foreach ((array) $bundle->get_items() as $key => $definition) {
            if (empty($definition['id']) || (float) ($definition['qty'] ?? 0) <= 0) {
                continue;
            }
            $product = wc_get_product((int) $definition['id']);
            if (!$product) {
                return [];
            }
            if ($product->is_type('variable')) {
                $dataStore = \WC_Data_Store::load('product');
                $variationId = $dataStore->find_matching_product_variation($product, $product->get_default_attributes());
                $product = $variationId ? wc_get_product($variationId) : false;
                if (!$product) {
                    return [];
                }
            }
            $selection[(string) $key] = [
                'id' => $product->get_id(),
                'sku' => $product->get_sku(),
                'qty' => (float) $definition['qty'],
                'attrs' => $product instanceof \WC_Product_Variation ? $product->get_variation_attributes() : [],
            ];
        }
        return $selection;
    }
}
