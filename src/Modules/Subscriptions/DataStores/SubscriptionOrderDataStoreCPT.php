<?php

namespace HB\UCS\Modules\Subscriptions\DataStores;

use HB\UCS\Modules\Subscriptions\Orders\HB_UCS_Subscription_Order;

if (!defined('ABSPATH')) exit;

class SubscriptionOrderDataStoreCPT extends \WC_Order_Data_Store_CPT {
    private const ORDER_ITEM_META_SELECTED_ATTRIBUTES = '_hb_ucs_subscription_selected_attributes';
    private const ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT = '_hb_ucs_subscription_attribute_snapshot';
    private const ORDER_ITEM_META_CATALOG_UNIT_PRICE = '_hb_ucs_subscription_catalog_unit_price';

    public function read(&$order) {
        parent::read($order);
    }

    public function read_items($order, $type) {
        $items = parent::read_items($order, $type);
        if (!empty($items)) {
            return $items;
        }

        if (!$order instanceof HB_UCS_Subscription_Order) {
            return $items;
        }

        $legacy = $this->get_order_type_subscription_data($order);

        if (empty($legacy)) {
            return $items;
        }

        switch ((string) $type) {
            case 'line_item':
                return $this->build_legacy_line_items($order, $legacy);
            case 'fee':
                return $this->build_legacy_fee_items($order, $legacy);
            case 'shipping':
                return $this->build_legacy_shipping_items($order, $legacy);
            case 'tax':
                return $this->build_legacy_tax_items($order, $legacy);
            default:
                return [];
        }
    }

    private function get_order_type_subscription_data(HB_UCS_Subscription_Order $order): array {
        $orderId = (int) $order->get_id();
        if ($orderId <= 0) {
            return [];
        }

        $items = get_post_meta($orderId, '_hb_ucs_sub_items', true);
        $feeLines = get_post_meta($orderId, '_hb_ucs_sub_fee_lines', true);
        $shippingLines = get_post_meta($orderId, '_hb_ucs_sub_shipping_lines', true);

        return [
            'scheme' => (string) $order->get_meta('_hb_ucs_subscription_scheme', true),
            'items' => is_array($items) ? $items : [],
            'fee_lines' => is_array($feeLines) ? $feeLines : [],
            'shipping_lines' => is_array($shippingLines) ? $shippingLines : [],
        ];
    }

    private function build_legacy_line_items(HB_UCS_Subscription_Order $order, array $legacy): array {
        $items = [];

        foreach ((array) ($legacy['items'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = (int) ($row['base_product_id'] ?? 0);
            $variationId = (int) ($row['base_variation_id'] ?? 0);
            $targetId = $variationId > 0 ? $variationId : $productId;
            $product = $targetId > 0 ? wc_get_product($targetId) : false;
            $qty = max(1, (int) ($row['qty'] ?? 1));
            $unitPrice = $this->normalize_decimal($row['unit_price'] ?? 0.0);
            $referenceUnitPrice = array_key_exists('catalog_unit_price', $row) && $row['catalog_unit_price'] !== '' && $row['catalog_unit_price'] !== null
                ? $this->normalize_decimal($row['catalog_unit_price'])
                : $unitPrice;
            $lineSubtotal = $this->normalize_decimal($referenceUnitPrice * $qty);
            $lineTotal = $this->normalize_decimal($unitPrice * $qty);
            $taxes = $this->normalize_item_taxes($row['taxes'] ?? []);
            $subtotalTax = $this->sum_tax_group($taxes['subtotal']);
            $lineTax = $this->sum_tax_group($taxes['total']);

            $item = new \WC_Order_Item_Product();
            $item->set_id($this->get_virtual_item_id($order->get_id(), 'line_item', (int) $index));
            $item->set_order_id($order->get_id());
            $item->set_product_id($productId);
            $item->set_variation_id($variationId);
            $item->set_quantity($qty);
            $item->set_subtotal($lineSubtotal);
            $item->set_total($lineTotal);
            $item->set_subtotal_tax($subtotalTax);
            $item->set_total_tax($lineTax);
            $item->set_taxes($taxes);
            if ($productId > 0) {
                $item->add_meta_data('_hb_ucs_subscription_base_product_id', $productId, true);
            }
            if ($variationId > 0) {
                $item->add_meta_data('_hb_ucs_subscription_base_variation_id', $variationId, true);
            }
            if (!empty($legacy['scheme'])) {
                $item->add_meta_data('_hb_ucs_subscription_scheme', (string) $legacy['scheme'], true);
            }
            if ((int) ($row['source_order_item_id'] ?? 0) > 0) {
                $item->add_meta_data('_hb_ucs_subscription_source_order_item_id', (int) $row['source_order_item_id'], true);
            }
            if (array_key_exists('catalog_unit_price', $row) && $row['catalog_unit_price'] !== '' && $row['catalog_unit_price'] !== null) {
                $item->add_meta_data(self::ORDER_ITEM_META_CATALOG_UNIT_PRICE, $this->normalize_decimal($row['catalog_unit_price']), true);
            }

            $selectedAttributes = isset($row['selected_attributes']) && is_array($row['selected_attributes']) ? $this->sanitize_selected_attributes_map($row['selected_attributes']) : [];
            $attributeSnapshot = isset($row['attribute_snapshot']) && is_array($row['attribute_snapshot'])
                ? $this->sanitize_selected_attributes_map($row['attribute_snapshot'])
                : $selectedAttributes;

            if (!empty($selectedAttributes)) {
                $item->add_meta_data(self::ORDER_ITEM_META_SELECTED_ATTRIBUTES, wp_json_encode($selectedAttributes), true);
            }
            if (!empty($attributeSnapshot)) {
                $item->add_meta_data(self::ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT, wp_json_encode($attributeSnapshot), true);
            }

            if ($product && is_object($product)) {
                $item->set_name((string) $product->get_name());
                if (method_exists($product, 'get_tax_class')) {
                    $item->set_tax_class((string) $product->get_tax_class());
                }
                if (method_exists($product, 'get_sku')) {
                    $sku = (string) $product->get_sku();
                    if ($sku !== '') {
                        $item->add_meta_data('_sku', $sku, true);
                    }
                }
            } else {
                $item->set_name(sprintf(__('Abonnementsproduct #%d', 'hb-ucs'), $targetId > 0 ? $targetId : ($index + 1)));
            }

            $displayMetaRows = [];
            if (!empty($row['display_meta']) && is_array($row['display_meta'])) {
                $displayMetaRows = (array) $row['display_meta'];
            }

            $selectedAttributeHashes = $this->get_selected_attribute_display_row_hashes($productId, $attributeSnapshot);

            if (!empty($displayMetaRows)) {
                foreach ($displayMetaRows as $displayMetaRow) {
                    if (!is_array($displayMetaRow)) {
                        continue;
                    }

                    $label = isset($displayMetaRow['label']) && is_scalar($displayMetaRow['label']) ? trim((string) $displayMetaRow['label']) : '';
                    $value = isset($displayMetaRow['value']) && is_scalar($displayMetaRow['value']) ? trim((string) $displayMetaRow['value']) : '';
                    if ($label === '' || $value === '') {
                        continue;
                    }

                    if (isset($selectedAttributeHashes[$this->get_display_meta_row_hash($label, $value)])) {
                        continue;
                    }

                    $item->add_meta_data($label, sanitize_text_field($value), true);
                }
            }

            $items[$item->get_id()] = $item;
        }

        return $items;
    }

    private function build_legacy_fee_items(HB_UCS_Subscription_Order $order, array $legacy): array {
        $items = [];

        foreach ((array) ($legacy['fee_lines'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $taxes = $this->normalize_item_taxes($row['taxes'] ?? []);
            $total = $this->normalize_decimal($row['total'] ?? 0.0);

            $item = new \WC_Order_Item_Fee();
            $item->set_id($this->get_virtual_item_id($order->get_id(), 'fee', (int) $index));
            $item->set_order_id($order->get_id());
            $item->set_name((string) ($row['name'] ?? __('Toeslag', 'hb-ucs')));
            $item->set_total($total);
            $item->set_taxes($taxes);

            $items[$item->get_id()] = $item;
        }

        return $items;
    }

    private function build_legacy_shipping_items(HB_UCS_Subscription_Order $order, array $legacy): array {
        $items = [];

        foreach ((array) ($legacy['shipping_lines'] ?? []) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $taxes = $this->normalize_item_taxes($row['taxes'] ?? []);
            $total = $this->normalize_decimal($row['total'] ?? 0.0);

            $item = new \WC_Order_Item_Shipping();
            $item->set_id($this->get_virtual_item_id($order->get_id(), 'shipping', (int) $index));
            $item->set_order_id($order->get_id());
            $item->set_method_title((string) ($row['method_title'] ?? __('Verzending', 'hb-ucs')));
            $item->set_method_id((string) ($row['method_id'] ?? 'hb_ucs_manual_shipping'));
            $item->set_instance_id((int) ($row['instance_id'] ?? 0));
            $item->set_total($total);
            $item->set_taxes($taxes);

            $items[$item->get_id()] = $item;
        }

        return $items;
    }

    private function build_legacy_tax_items(HB_UCS_Subscription_Order $order, array $legacy): array {
        $items = [];
        $taxGroups = [];

        foreach ((array) ($legacy['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $taxes = $this->normalize_item_taxes($row['taxes'] ?? []);
            foreach ($taxes['total'] as $rateKey => $taxAmount) {
                $taxGroups[$rateKey]['cart_tax'] = ($taxGroups[$rateKey]['cart_tax'] ?? 0.0) + $this->normalize_decimal($taxAmount);
                $taxGroups[$rateKey]['shipping_tax'] = $taxGroups[$rateKey]['shipping_tax'] ?? 0.0;
            }
        }

        foreach ((array) ($legacy['fee_lines'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $taxes = $this->normalize_item_taxes($row['taxes'] ?? []);
            foreach ($taxes['total'] as $rateKey => $taxAmount) {
                $taxGroups[$rateKey]['cart_tax'] = ($taxGroups[$rateKey]['cart_tax'] ?? 0.0) + $this->normalize_decimal($taxAmount);
                $taxGroups[$rateKey]['shipping_tax'] = $taxGroups[$rateKey]['shipping_tax'] ?? 0.0;
            }
        }

        foreach ((array) ($legacy['shipping_lines'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $taxes = $this->normalize_item_taxes($row['taxes'] ?? []);
            foreach ($taxes['total'] as $rateKey => $taxAmount) {
                $taxGroups[$rateKey]['cart_tax'] = $taxGroups[$rateKey]['cart_tax'] ?? 0.0;
                $taxGroups[$rateKey]['shipping_tax'] = ($taxGroups[$rateKey]['shipping_tax'] ?? 0.0) + $this->normalize_decimal($taxAmount);
            }
        }

        $index = 0;
        foreach ($taxGroups as $rateKey => $taxGroup) {
            $rateId = is_numeric($rateKey) ? absint((string) $rateKey) : 0;
            $item = new \WC_Order_Item_Tax();
            $item->set_id($this->get_virtual_item_id($order->get_id(), 'tax', $index));
            $item->set_order_id($order->get_id());

            if ($rateId > 0) {
                $item->set_rate($rateId);
            } else {
                $item->set_rate_id(0);
                $item->set_rate_code((string) $rateKey);
                $item->set_label(__('BTW', 'hb-ucs'));
                $item->set_compound(false);
                $item->set_rate_percent(0);
            }

            $item->set_tax_total($this->normalize_decimal($taxGroup['cart_tax'] ?? 0.0));
            $item->set_shipping_tax_total($this->normalize_decimal($taxGroup['shipping_tax'] ?? 0.0));

            $items[$item->get_id()] = $item;
            $index++;
        }

        return $items;
    }

    private function normalize_item_taxes($taxes): array {
        $normalized = ['subtotal' => [], 'total' => []];
        if (!is_array($taxes)) {
            return $normalized;
        }

        foreach (['subtotal', 'total'] as $group) {
            if (!isset($taxes[$group]) || !is_array($taxes[$group])) {
                continue;
            }

            foreach ($taxes[$group] as $rateKey => $taxAmount) {
                $normalizedKey = is_numeric($rateKey) ? (string) absint((string) $rateKey) : sanitize_key((string) $rateKey);
                if ($normalizedKey === '') {
                    $normalizedKey = 'manual';
                }
                $normalized[$group][$normalizedKey] = $this->normalize_decimal($taxAmount);
            }
        }

        if (empty($normalized['subtotal']) && !empty($normalized['total'])) {
            $normalized['subtotal'] = $normalized['total'];
        }

        return $normalized;
    }

    private function sum_tax_group(array $taxes): float {
        $total = 0.0;
        foreach ($taxes as $taxAmount) {
            $total += $this->normalize_decimal($taxAmount);
        }

        return $this->normalize_decimal($total);
    }

    private function get_virtual_item_id(int $orderId, string $type, int $index): int {
        $typeOffset = [
            'line_item' => 1000,
            'fee' => 2000,
            'shipping' => 3000,
            'tax' => 4000,
        ];

        return absint(($orderId * 10000) + ($typeOffset[$type] ?? 9000) + $index + 1);
    }

    private function normalize_selected_attribute_key(string $key): string {
        $key = ltrim(sanitize_key($key), '_');
        if ($key === '' || $key === 'attribute_') {
            return '';
        }

        if (strpos($key, 'attribute_') === 0) {
            return $key;
        }

        return 'attribute_' . $key;
    }

    private function sanitize_selected_attributes_map(array $attributes): array {
        $clean = [];

        foreach ($attributes as $key => $value) {
            $normalizedKey = $this->normalize_selected_attribute_key((string) $key);
            $normalizedValue = sanitize_title((string) $value);
            if ($normalizedKey === '' || $normalizedValue === '') {
                continue;
            }

            $clean[$normalizedKey] = $normalizedValue;
        }

        return $clean;
    }

    private function get_display_meta_row_hash(string $label, string $value): string {
        return strtolower(remove_accents(trim($label) . '|' . trim($value)));
    }

    private function get_selected_attribute_display_row_hashes(int $baseProductId, array $selectedAttributes): array {
        $hashes = [];
        $selectedAttributes = $this->sanitize_selected_attributes_map($selectedAttributes);
        if (empty($selectedAttributes)) {
            return $hashes;
        }

        $configByKey = [];
        if ($baseProductId > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($baseProductId);
            if ($product && is_object($product) && method_exists($product, 'get_attributes')) {
                foreach ((array) $product->get_attributes() as $attribute) {
                    if (!is_object($attribute) || !method_exists($attribute, 'get_name')) {
                        continue;
                    }

                    $name = (string) $attribute->get_name();
                    $key = 'attribute_' . sanitize_key($name);
                    if ($key === 'attribute_') {
                        continue;
                    }

                    $label = function_exists('wc_attribute_label') ? (string) wc_attribute_label($name, $product) : trim(wp_strip_all_tags(ucwords(str_replace(['pa_', '_', '-'], ['', ' ', ' '], $name)), true));
                    $options = [];
                    if (taxonomy_exists($name)) {
                        foreach ((array) wc_get_product_terms($baseProductId, $name, ['fields' => 'all']) as $term) {
                            if (!is_object($term) || !isset($term->slug, $term->name)) {
                                continue;
                            }

                            $options[(string) $term->slug] = (string) $term->name;
                        }
                    } elseif (method_exists($attribute, 'get_options')) {
                        foreach ((array) $attribute->get_options() as $option) {
                            $option = (string) $option;
                            if ($option === '') {
                                continue;
                            }

                            $options[sanitize_title($option)] = $option;
                        }
                    }

                    $configByKey[$key] = [
                        'label' => $label !== '' ? $label : $key,
                        'options' => $options,
                    ];
                }
            }
        }

        foreach ($selectedAttributes as $key => $rawValue) {
            $label = isset($configByKey[$key]['label']) ? (string) $configByKey[$key]['label'] : trim(wp_strip_all_tags(ucwords(str_replace(['attribute_', '_', '-'], ['', ' ', ' '], (string) $key)), true));
            $valueKey = sanitize_title((string) $rawValue);
            $value = isset($configByKey[$key]['options'][$valueKey]) ? (string) $configByKey[$key]['options'][$valueKey] : trim(wp_strip_all_tags(ucwords(str_replace(['_', '-'], [' ', ' '], (string) $rawValue)), true));
            if ($label === '' || $value === '') {
                continue;
            }

            $hashes[$this->get_display_meta_row_hash($label, $value)] = true;
        }

        return $hashes;
    }

    private function normalize_decimal($value): float {
        if (function_exists('wc_format_decimal')) {
            return (float) wc_format_decimal((string) $value, $this->get_internal_decimal_precision());
        }

        return round((float) $value, 6);
    }

    private function get_internal_decimal_precision(): int {
        if (function_exists('wc_get_rounding_precision')) {
            return max((int) wc_get_price_decimals(), (int) wc_get_rounding_precision());
        }

        return max(function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2, 6);
    }
}
