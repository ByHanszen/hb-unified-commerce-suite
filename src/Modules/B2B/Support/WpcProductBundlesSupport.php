<?php
// =============================
// src/Modules/B2B/Support/WpcProductBundlesSupport.php
// =============================
namespace HB\UCS\Modules\B2B\Support;

if (!defined('ABSPATH')) exit;

class WpcProductBundlesSupport {
    private const CART_PARENT_KEYS = ['woosb_parent_id', 'woosb_parent_key'];
    private const CART_CHILDREN_KEYS = ['woosb_keys'];
    private const ORDER_PARENT_META_KEYS = ['_woosb_parent_id', 'woosb_parent_id', '_woosb_parent_key', 'woosb_parent_key'];
    private const ORDER_CHILDREN_META_KEYS = ['_woosb_keys', 'woosb_keys'];

    public function is_bundle_product($product): bool {
        if (!is_object($product) || !method_exists($product, 'get_type')) {
            return false;
        }

        return (string) $product->get_type() === 'woosb';
    }

    public function is_bundle_child_cart_item(array $cartItem): bool {
        foreach (self::CART_PARENT_KEYS as $key) {
            if (!empty($cartItem[$key])) {
                return true;
            }
        }

        return false;
    }

    public function is_bundle_parent_cart_item(array $cartItem): bool {
        foreach (self::CART_CHILDREN_KEYS as $key) {
            if (!empty($cartItem[$key])) {
                return true;
            }
        }

        $product = $cartItem['data'] ?? null;
        return $this->is_bundle_product($product);
    }

    public function is_bundle_cart_item(array $cartItem): bool {
        return $this->is_bundle_child_cart_item($cartItem) || $this->is_bundle_parent_cart_item($cartItem);
    }

    public function should_preserve_cart_item_price(array $cartItem): bool {
        if (!$this->is_bundle_cart_item($cartItem)) {
            return false;
        }

        $currentPrice = $this->get_cart_item_unit_price($cartItem);
        return $currentPrice !== null && $currentPrice <= 0.0;
    }

    public function is_bundle_child_order_item($item): bool {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return false;
        }

        foreach (self::ORDER_PARENT_META_KEYS as $key) {
            $value = $item->get_meta($key, true);
            if ($value !== '' && $value !== null) {
                return true;
            }
        }

        return false;
    }

    public function is_bundle_parent_order_item($item, $product = null): bool {
        if (is_object($item) && method_exists($item, 'get_meta')) {
            foreach (self::ORDER_CHILDREN_META_KEYS as $key) {
                $value = $item->get_meta($key, true);
                if ($value !== '' && $value !== null) {
                    return true;
                }
            }
        }

        return $this->is_bundle_product($product);
    }

    public function is_bundle_managed_order_item($item, $product = null): bool {
        return $this->is_bundle_child_order_item($item) || $this->is_bundle_parent_order_item($item, $product);
    }

    public function should_preserve_order_item_price($item, $product = null): bool {
        if (!$this->is_bundle_managed_order_item($item, $product)) {
            return false;
        }

        $subtotal = $this->get_order_item_amount($item, 'get_subtotal');
        $total = $this->get_order_item_amount($item, 'get_total');

        return $subtotal <= 0.0 && $total <= 0.0;
    }

    public function get_bundle_order_item_base_unit_price_ex($item): ?float {
        if (!is_object($item) || !method_exists($item, 'get_quantity')) {
            return null;
        }

        $qty = max(1, (int) $item->get_quantity());
        $subtotal = $this->get_order_item_amount($item, 'get_subtotal');
        if ($subtotal > 0.0) {
            return $subtotal / $qty;
        }

        $total = $this->get_order_item_amount($item, 'get_total');
        if ($total > 0.0) {
            return $total / $qty;
        }

        return 0.0;
    }

    private function get_cart_item_unit_price(array $cartItem): ?float {
        $product = $cartItem['data'] ?? null;
        if (!is_object($product) || !method_exists($product, 'get_price')) {
            return null;
        }

        $price = (string) $product->get_price();
        return is_numeric($price) ? (float) $price : null;
    }

    private function get_order_item_amount($item, string $method): float {
        if (!is_object($item) || !method_exists($item, $method)) {
            return 0.0;
        }

        return max(0.0, (float) $item->{$method}());
    }
}