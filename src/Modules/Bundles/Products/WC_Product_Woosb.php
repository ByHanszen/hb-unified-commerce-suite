<?php
use HB\UCS\Modules\Bundles\Support\BundleData;

defined('ABSPATH') || exit;

if (!class_exists('WC_Product_Woosb') && class_exists('WC_Product')) {
    class WC_Product_Woosb extends WC_Product {
        /** @var array<string,array<string,mixed>> */
        protected array $hb_ucs_bundle_items = [];

        public function __construct($product = 0) {
            parent::__construct($product);
            $this->build_items();
        }

        public function get_type(): string {
            return BundleData::PRODUCT_TYPE;
        }

        public function add_to_cart_url(): string {
            // Always configure on the product page; this also guarantees the server nonce is present.
            $url = $this->get_permalink();
            return (string) apply_filters('woosb_product_add_to_cart_url', apply_filters('woocommerce_product_add_to_cart_url', $url, $this), $this);
        }

        public function get_manage_stock($context = 'view') {
            // Prevent stale WooCommerce parent stock settings from reducing stock when component stock is authoritative.
            return $this->is_manage_stock() ? parent::get_manage_stock($context) : false;
        }

        /** @return array<string,array<string,mixed>> */
        public function get_items(): array {
            return (array) apply_filters('woosb_get_items', $this->hb_ucs_bundle_items, $this);
        }

        public function build_items($items = null): void {
            $this->hb_ucs_bundle_items = BundleData::normalize_product_items($this, $items);
        }

        public function get_ids() {
            return apply_filters('woosb_get_ids', $this->get_meta(BundleData::META_ITEMS), $this);
        }

        public function get_ids_str(): string {
            return (string) apply_filters('woosb_get_ids_str', BundleData::selection_to_string($this->get_items()), $this);
        }

        public function is_fixed_price(): bool {
            return (string) $this->get_meta(BundleData::META_FIXED_PRICE) === 'on';
        }

        public function is_manage_stock(): bool {
            return (string) $this->get_meta(BundleData::META_MANAGE_STOCK) === 'on';
        }

        public function has_optional(): bool {
            foreach ($this->hb_ucs_bundle_items as $item) {
                if (!empty($item['id']) && !empty($item['optional'])) {
                    return true;
                }
            }
            return false;
        }

        public function has_variables(): bool {
            foreach ($this->hb_ucs_bundle_items as $item) {
                $child = !empty($item['id']) ? wc_get_product((int) $item['id']) : false;
                if ($child && $child->is_type('variable')) {
                    return true;
                }
            }
            return false;
        }

        public function get_discount_percentage(): float {
            if ($this->is_fixed_price() || $this->get_discount_amount() > 0) {
                return 0.0;
            }
            $discount = (float) $this->get_meta(BundleData::META_DISCOUNT);
            return $discount > 0 && $discount < 100 ? $discount : 0.0;
        }

        public function get_discount_amount(): float {
            return $this->is_fixed_price() ? 0.0 : max(0.0, (float) $this->get_meta(BundleData::META_DISCOUNT_AMOUNT));
        }

        public function get_regular_price($context = 'view') {
            if ($context !== 'view' || $this->is_fixed_price()) {
                return parent::get_regular_price($context);
            }
            return (string) $this->calculate_component_total(true);
        }

        public function get_sale_price($context = 'view') {
            if ($context !== 'view' || $this->is_fixed_price()) {
                return parent::get_sale_price($context);
            }
            $regular = $this->calculate_component_total(false);
            $final = $this->apply_bundle_discount($regular);
            return $final < $regular ? (string) $final : '';
        }

        public function get_price($context = 'view') {
            if ($context !== 'view' || $this->is_fixed_price()) {
                return parent::get_price($context);
            }
            return (string) $this->apply_bundle_discount($this->calculate_component_total(false));
        }

        public function is_purchasable(): bool {
            if (empty($this->hb_ucs_bundle_items)) {
                return false;
            }
            return parent::is_purchasable() && $this->get_stock_status() !== 'outofstock';
        }

        public function get_stock_status($context = 'view') {
            if ($this->is_manage_stock()) {
                return parent::get_stock_status($context);
            }
            foreach ($this->hb_ucs_bundle_items as $item) {
                if (empty($item['id']) || !empty($item['optional'])) {
                    continue;
                }
                $child = wc_get_product((int) $item['id']);
                if (!$child || (!$child->is_type('variable') && (!$child->is_in_stock() || !$child->is_purchasable()))) {
                    return 'outofstock';
                }
                if ($child->is_type('variable')) {
                    $hasAvailableChoice = false;
                    foreach ($this->get_allowed_variations($child, $item) as $variation) {
                        if ($variation->is_purchasable() && ($variation->is_in_stock() || $variation->backorders_allowed())) {
                            $hasAvailableChoice = true;
                            break;
                        }
                    }
                    if (!$hasAvailableChoice) {
                        return 'outofstock';
                    }
                }
            }
            return 'instock';
        }

        public function get_stock_quantity($context = 'view') {
            if ($this->is_manage_stock()) {
                return parent::get_stock_quantity($context);
            }
            $limits = [];
            foreach ($this->hb_ucs_bundle_items as $item) {
                if (empty($item['id']) || !empty($item['optional']) || (float) ($item['qty'] ?? 0) <= 0) {
                    continue;
                }
                $child = wc_get_product((int) $item['id']);
                if ($child && $child->is_type('variable')) {
                    $variationLimits = [];
                    foreach ($this->get_allowed_variations($child, $item) as $variation) {
                        if (!$variation->managing_stock() || $variation->backorders_allowed() || $variation->get_stock_quantity() === null) {
                            continue;
                        }
                        $variationLimits[] = (int) floor((float) $variation->get_stock_quantity() / (float) $item['qty']);
                    }
                    if (!empty($variationLimits)) {
                        // The customer only needs one allowed variation to be available.
                        $limits[] = max($variationLimits);
                    }
                    continue;
                }
                if (!$child || !$child->managing_stock() || $child->backorders_allowed()) {
                    continue;
                }
                $stock = $child->get_stock_quantity();
                if ($stock !== null) {
                    $limits[] = (int) floor((float) $stock / (float) $item['qty']);
                }
            }
            return empty($limits) ? null : min($limits);
        }

        public function needs_shipping() {
            $mode = (string) ($this->get_meta(BundleData::META_SHIPPING) ?: 'whole');
            return !$this->is_virtual() && $mode !== 'each';
        }

        public function add_to_cart_text(): string {
            if (!$this->is_purchasable() || !$this->is_in_stock()) {
                return (string) apply_filters('woosb_product_add_to_cart_text', __('Niet beschikbaar', 'hb-ucs'), $this);
            }
            $text = ($this->has_optional() || $this->has_variables()) ? __('Stel je bundel samen', 'hb-ucs') : __('In winkelmand', 'hb-ucs');
            return (string) apply_filters('woosb_product_add_to_cart_text', $text, $this);
        }

        public function single_add_to_cart_text(): string {
            return (string) apply_filters('woosb_product_single_add_to_cart_text', __('Bundel toevoegen', 'hb-ucs'), $this);
        }

        private function calculate_component_total(bool $regular): float {
            $total = 0.0;
            foreach ($this->hb_ucs_bundle_items as $item) {
                if (empty($item['id'])) {
                    continue;
                }
                $qty = !empty($item['optional']) ? max(0.0, (float) ($item['min'] ?? 0)) : max(0.0, (float) ($item['qty'] ?? 0));
                if ($qty <= 0) {
                    continue;
                }
                $child = wc_get_product((int) $item['id']);
                if (!$child || BundleData::is_bundle_product($child)) {
                    continue;
                }
                if ($child->is_type('variable')) {
                    $prices = [];
                    foreach ($this->get_allowed_variations($child, $item) as $variation) {
                        $rawPrice = $regular ? $variation->get_regular_price() : $variation->get_price();
                        if ($rawPrice !== '') {
                            $prices[] = max(0.0, (float) $rawPrice);
                        }
                    }
                    $price = !empty($prices) ? min($prices) : 0.0;
                } else {
                    $price = $regular ? $child->get_regular_price() : $child->get_price();
                }
                $total += max(0.0, (float) $price) * $qty;
            }
            return (float) wc_format_decimal((string) $total, wc_get_price_decimals());
        }

        private function apply_bundle_discount(float $total): float {
            $amount = $this->get_discount_amount();
            if ($amount > 0) {
                return max(0.0, $total - $amount);
            }
            $percentage = $this->get_discount_percentage();
            return max(0.0, $total * (100 - $percentage) / 100);
        }

        /** @return array<int,\WC_Product_Variation> */
        private function get_allowed_variations($product, array $item): array {
            if (!$product instanceof \WC_Product_Variable) {
                return [];
            }
            $allowedTerms = isset($item['terms']) && is_array($item['terms']) ? $item['terms'] : [];
            $variations = [];
            foreach ((array) $product->get_available_variations('objects') as $variation) {
                if (!$variation instanceof \WC_Product_Variation || !$this->variation_matches_terms($variation, $allowedTerms)) {
                    continue;
                }
                $variations[] = $variation;
            }
            return $variations;
        }

        private function variation_matches_terms(\WC_Product_Variation $variation, array $allowedTerms): bool {
            $attributes = BundleData::sanitize_attributes($variation->get_variation_attributes());
            foreach ($allowedTerms as $termKey => $allowed) {
                if (!is_array($allowed) || empty($allowed)) {
                    continue;
                }
                $attributeKey = 'attribute_' . sanitize_title((string) $termKey);
                $value = sanitize_title((string) ($attributes[$attributeKey] ?? ''));
                // Empty variation attributes are WooCommerce wildcards; the concrete customer choice is validated later.
                if ($value !== '' && !in_array($value, $allowed, true)) {
                    return false;
                }
            }
            return true;
        }
    }
}
