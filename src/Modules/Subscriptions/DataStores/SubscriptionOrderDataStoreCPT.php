<?php

namespace HB\UCS\Modules\Subscriptions\DataStores;

use HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository;
use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;
use HB\UCS\Modules\Subscriptions\Orders\HB_UCS_Subscription_Order;

if (!defined('ABSPATH')) exit;

class SubscriptionOrderDataStoreCPT extends \WC_Order_Data_Store_CPT {
    /** @var SubscriptionRepository */
    private $repository;

    public function __construct() {
        $this->repository = new SubscriptionRepository();
    }

    public function read(&$order) {
        parent::read($order);

        if (!$order instanceof HB_UCS_Subscription_Order) {
            return;
        }

        $this->hydrate_legacy_overlay($order);
    }

    public function read_items($order, $type) {
        $items = parent::read_items($order, $type);
        if (!empty($items)) {
            return $items;
        }

        if (!$order instanceof HB_UCS_Subscription_Order) {
            return $items;
        }

        $legacyPostId = $order->get_legacy_post_id('edit');
        $legacy = $legacyPostId > 0
            ? $this->repository->get_legacy_subscription_data($legacyPostId)
            : $this->repository->get_order_type_subscription_data($order);

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

    private function hydrate_legacy_overlay(HB_UCS_Subscription_Order $order): void {
        $legacyPostId = $order->get_legacy_post_id('edit');
        $legacy = $legacyPostId > 0
            ? $this->repository->get_legacy_subscription_data($legacyPostId)
            : $this->repository->get_order_type_subscription_data($order);

        if (empty($legacy)) {
            return;
        }

        $billing = isset($legacy['billing']) && is_array($legacy['billing']) ? $legacy['billing'] : [];
        $shipping = isset($legacy['shipping']) && is_array($legacy['shipping']) ? $legacy['shipping'] : [];
        $totals = isset($legacy['totals']) && is_array($legacy['totals']) ? $legacy['totals'] : [];

        $order->set_props([
            'customer_id' => (int) ($legacy['customer_id'] ?? 0),
            'payment_method' => (string) ($legacy['payment_method'] ?? ''),
            'payment_method_title' => (string) ($legacy['payment_method_title'] ?? ''),
            'billing_first_name' => (string) ($billing['first_name'] ?? ''),
            'billing_last_name' => (string) ($billing['last_name'] ?? ''),
            'billing_company' => (string) ($billing['company'] ?? ''),
            'billing_address_1' => (string) ($billing['address_1'] ?? ''),
            'billing_address_2' => (string) ($billing['address_2'] ?? ''),
            'billing_city' => (string) ($billing['city'] ?? ''),
            'billing_state' => (string) ($billing['state'] ?? ''),
            'billing_postcode' => (string) ($billing['postcode'] ?? ''),
            'billing_country' => (string) ($billing['country'] ?? ''),
            'billing_email' => (string) ($billing['email'] ?? ''),
            'billing_phone' => (string) ($billing['phone'] ?? ''),
            'shipping_first_name' => (string) ($shipping['first_name'] ?? ''),
            'shipping_last_name' => (string) ($shipping['last_name'] ?? ''),
            'shipping_company' => (string) ($shipping['company'] ?? ''),
            'shipping_address_1' => (string) ($shipping['address_1'] ?? ''),
            'shipping_address_2' => (string) ($shipping['address_2'] ?? ''),
            'shipping_city' => (string) ($shipping['city'] ?? ''),
            'shipping_state' => (string) ($shipping['state'] ?? ''),
            'shipping_postcode' => (string) ($shipping['postcode'] ?? ''),
            'shipping_country' => (string) ($shipping['country'] ?? ''),
            'customer_note' => (string) ($legacy['customer_note'] ?? ''),
        ]);

        $order->set_currency((string) ($legacy['currency'] ?? get_woocommerce_currency()));
        $order->set_prices_include_tax(!empty($legacy['prices_include_tax']));
        $order->set_discount_total(0);
        $order->set_discount_tax(0);
        $order->set_shipping_total((float) ($totals['shipping_subtotal'] ?? 0.0));
        $order->set_shipping_tax((float) ($totals['shipping_tax'] ?? 0.0));
        $order->set_cart_tax((float) ($totals['item_tax'] ?? 0.0) + (float) ($totals['fee_tax'] ?? 0.0));
        $order->set_total((float) ($totals['total'] ?? 0.0));
        $order->set_storage_version(SubscriptionOrderType::PHASE2_STORAGE_VERSION);
        $order->set_subscription_status((string) ($legacy['status'] ?? 'pending_mandate'));
        $order->set_subscription_scheme((string) ($legacy['scheme'] ?? ''));
        $order->set_next_payment_timestamp((int) ($legacy['next_payment'] ?? 0));

        if (!empty($legacy['date_created_gmt'])) {
            $order->set_date_created($legacy['date_created_gmt']);
        }

        if (!empty($legacy['date_modified_gmt'])) {
            $order->set_date_modified($legacy['date_modified_gmt']);
        }
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
            $lineSubtotal = $this->normalize_decimal($unitPrice * $qty);
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
            $item->set_total($lineSubtotal);
            $item->set_subtotal_tax($subtotalTax);
            $item->set_total_tax($lineTax);
            $item->set_taxes($taxes);

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

            if (!empty($row['selected_attributes']) && is_array($row['selected_attributes'])) {
                foreach ((array) $row['selected_attributes'] as $attributeKey => $attributeValue) {
                    $attributeKey = sanitize_key((string) $attributeKey);
                    if ($attributeKey === '') {
                        continue;
                    }

                    $item->add_meta_data($attributeKey, sanitize_text_field((string) $attributeValue), true);
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

    private function normalize_decimal($value): float {
        if (function_exists('wc_format_decimal')) {
            return (float) wc_format_decimal((string) $value, wc_get_price_decimals());
        }

        return round((float) $value, 2);
    }
}
