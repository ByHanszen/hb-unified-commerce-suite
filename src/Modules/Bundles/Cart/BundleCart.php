<?php
namespace HB\UCS\Modules\Bundles\Cart;

use HB\UCS\Modules\Bundles\Admin\BundleSettings;
use HB\UCS\Modules\Bundles\Support\BundleData;

if (!defined('ABSPATH')) exit;

final class BundleCart {
    private bool $addingChildren = false;
    private bool $removingGroup = false;
    /** @var array<int,array<string,array<string,mixed>>> */
    private array $validatedSelections = [];

    public function init(): void {
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 30, 5);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 50, 4);
        add_action('woocommerce_add_to_cart', [$this, 'add_children'], 30, 6);
        add_action('woocommerce_before_calculate_totals', [$this, 'calculate_prices'], 9999);
        add_action('woocommerce_cart_item_removed', [$this, 'remove_related_items'], 10, 2);
        add_action('woocommerce_restore_cart_item', [$this, 'restore_related_items'], 10, 2);
        add_filter('woocommerce_cart_item_remove_link', [$this, 'cart_item_remove_link'], 20, 2);
        add_filter('woocommerce_cart_item_quantity', [$this, 'cart_item_quantity'], 20, 3);
        add_filter('woocommerce_update_cart_validation', [$this, 'validate_cart_quantity'], 30, 4);
        add_filter('woocommerce_cart_item_class', [$this, 'cart_item_class'], 20, 3);
        add_filter('woocommerce_mini_cart_item_class', [$this, 'cart_item_class'], 20, 3);
        add_filter('woocommerce_get_item_data', [$this, 'cart_item_data'], 20, 2);
        add_action('woocommerce_after_cart_item_name', [$this, 'cart_edit_link'], 20, 2);
        add_filter('woocommerce_cart_item_visible', [$this, 'cart_item_visible'], 20, 3);
        add_filter('woocommerce_checkout_cart_item_visible', [$this, 'cart_item_visible'], 20, 3);
        add_filter('woocommerce_widget_cart_item_visible', [$this, 'cart_item_visible'], 20, 3);
        add_filter('woocommerce_cart_shipping_packages', [$this, 'shipping_packages'], 20);
        add_filter('woocommerce_cart_item_price', [$this, 'display_parent_price'], 9999, 2);
        add_filter('woocommerce_cart_item_subtotal', [$this, 'display_parent_subtotal'], 9999, 2);
    }

    public function validate_add_to_cart($passed, $productId, $quantity, $variationId = 0, $variations = []) {
        if (!$passed || $this->addingChildren) {
            return $passed;
        }
        if (isset($_REQUEST['order_again'])) {
            return $passed;
        }
        $product = wc_get_product((int) $productId);
        if (!BundleData::is_bundle_product($product)) {
            return $passed;
        }
        $nonce = isset($_REQUEST['hb_ucs_bundle_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['hb_ucs_bundle_nonce'])) : '';
        if ($nonce === '' || !wp_verify_nonce($nonce, 'hb_ucs_add_bundle_' . (int) $productId)) {
            wc_add_notice(__('De bundelpagina is verlopen. Vernieuw de pagina en probeer opnieuw.', 'hb-ucs'), 'error');
            return false;
        }
        $compact = isset($_REQUEST['woosb_ids']) ? BundleData::clean_compact_string((string) $_REQUEST['woosb_ids']) : '';
        $submitted = BundleData::parse_selection($compact);
        $definitions = method_exists($product, 'get_items') ? $product->get_items() : BundleData::normalize_product_items($product);
        $validated = [];
        $count = 0.0;
        $total = 0.0;
        $bundleQty = max(1.0, (float) $quantity);

        foreach ($submitted as $key => $selected) {
            if (!isset($definitions[$key]) || empty($definitions[$key]['id'])) {
                wc_add_notice(__('De verzonden bundelsamenstelling is niet meer geldig.', 'hb-ucs'), 'error');
                return false;
            }
        }

        foreach ($definitions as $key => $definition) {
            if (empty($definition['id'])) {
                continue;
            }
            $source = wc_get_product((int) $definition['id']);
            if (!$source || BundleData::is_bundle_product($source)) {
                wc_add_notice(__('Een onderdeel van deze bundel is niet meer beschikbaar.', 'hb-ucs'), 'error');
                return false;
            }
            $optional = !empty($definition['optional']);
            $selected = $submitted[$key] ?? null;
            $selectedQty = $selected ? max(0.0, (float) ($selected['qty'] ?? 0)) : 0.0;
            $expectedQty = max(0.0, (float) ($definition['qty'] ?? 0));
            $min = $optional ? max(0.0, (float) ($definition['min'] ?? 0)) : $expectedQty;
            $max = $optional && ($definition['max'] ?? '') !== '' ? max($min, (float) $definition['max']) : ($optional ? PHP_FLOAT_MAX : $expectedQty);

            if (!$optional && (!$selected || abs($selectedQty - $expectedQty) > 0.000001)) {
                wc_add_notice(sprintf(__('Het verplichte onderdeel “%s” heeft een ongeldig aantal.', 'hb-ucs'), $source->get_name()), 'error');
                return false;
            }
            if ($optional && ($selectedQty < $min || $selectedQty > $max)) {
                wc_add_notice(sprintf(__('Het aantal van “%s” moet tussen %s en %s liggen.', 'hb-ucs'), $source->get_name(), wc_format_localized_decimal($min), $max === PHP_FLOAT_MAX ? '∞' : wc_format_localized_decimal($max)), 'error');
                return false;
            }
            if ($selectedQty <= 0) {
                continue;
            }

            $chosen = $this->resolve_selected_product($source, $selected ?: [], $definition);
            if (!$chosen) {
                wc_add_notice(sprintf(__('Kies een geldige uitvoering van “%s”.', 'hb-ucs'), $source->get_name()), 'error');
                return false;
            }
            if (!$chosen->is_purchasable() || !$chosen->is_in_stock()) {
                wc_add_notice(sprintf(__('“%s” is momenteel niet bestelbaar.', 'hb-ucs'), $chosen->get_name()), 'error');
                return false;
            }
            $requiredStock = $selectedQty * $bundleQty;
            if ($chosen->managing_stock() && !$chosen->backorders_allowed() && !$chosen->has_enough_stock($requiredStock)) {
                wc_add_notice(sprintf(__('Er is onvoldoende voorraad van “%s”.', 'hb-ucs'), $chosen->get_name()), 'error');
                return false;
            }
            $attrs = $chosen instanceof \WC_Product_Variation ? BundleData::sanitize_attributes($chosen->get_variation_attributes()) : [];
            $validated[$key] = [
                'id' => $chosen->get_id(),
                'sku' => $chosen->get_sku(),
                'qty' => $selectedQty,
                'attrs' => $attrs,
            ];
            $count += $selectedQty;
            $total += (float) wc_get_price_to_display($chosen, ['qty' => $selectedQty]);
        }

        $minCount = max(0.0, (float) $product->get_meta('woosb_limit_whole_min'));
        $maxCount = max(0.0, (float) $product->get_meta('woosb_limit_whole_max'));
        if (empty($validated) || ($minCount > 0 && $count < $minCount) || ($maxCount > 0 && $count > $maxCount)) {
            wc_add_notice(__('Het totale aantal gekozen bundelonderdelen is niet toegestaan.', 'hb-ucs'), 'error');
            return false;
        }
        if (method_exists($product, 'get_discount_amount') && $product->get_discount_amount() > 0) {
            $total = max(0.0, $total - (float) $product->get_discount_amount());
        } elseif (method_exists($product, 'get_discount_percentage') && $product->get_discount_percentage() > 0) {
            $total *= (100 - (float) $product->get_discount_percentage()) / 100;
        }
        if ((string) $product->get_meta('woosb_total_limits') === 'on') {
            $minTotal = max(0.0, (float) $product->get_meta('woosb_total_limits_min'));
            $maxTotal = max(0.0, (float) $product->get_meta('woosb_total_limits_max'));
            if (($minTotal > 0 && $total < $minTotal) || ($maxTotal > 0 && $total > $maxTotal)) {
                wc_add_notice(__('De prijs van de gekozen samenstelling valt buiten de toegestane grenzen.', 'hb-ucs'), 'error');
                return false;
            }
        }
        $this->validatedSelections[(int) $productId] = $validated;
        return true;
    }

    public function add_cart_item_data(array $cartItemData, int $productId, int $variationId = 0, int $quantity = 1): array {
        if ($this->addingChildren) {
            unset($cartItemData['hb_ucs_subs'], $cartItemData['hb_ucs_subs_key']);
            return $cartItemData;
        }
        $product = wc_get_product($productId);
        if (!BundleData::is_bundle_product($product)) {
            return $cartItemData;
        }
        $selection = $this->validatedSelections[$productId] ?? [];
        if (empty($selection) && isset($_REQUEST['woosb_ids'])) {
            $selection = BundleData::parse_selection((string) $_REQUEST['woosb_ids']);
        }
        if (empty($selection) && !empty($cartItemData['woosb_ids'])) {
            // Order-again and other trusted WooCommerce flows supply the selection in cart item data.
            $selection = BundleData::parse_selection((string) $cartItemData['woosb_ids']);
        }
        if (empty($selection)) {
            return $cartItemData;
        }
        unset($this->validatedSelections[$productId]);
        $instance = !empty($cartItemData[BundleData::CART_INSTANCE])
            ? (string) $cartItemData[BundleData::CART_INSTANCE] : BundleData::generate_instance_id();
        $compact = BundleData::selection_to_string($selection);
        $cartItemData['woosb_ids'] = $compact;
        $cartItemData['woosb_key'] = $instance;
        $cartItemData['woosb_fixed_price'] = method_exists($product, 'is_fixed_price') && $product->is_fixed_price();
        $cartItemData['woosb_discount'] = method_exists($product, 'get_discount_percentage') ? (float) $product->get_discount_percentage() : 0.0;
        $cartItemData['woosb_discount_amount'] = method_exists($product, 'get_discount_amount') ? (float) $product->get_discount_amount() : 0.0;
        $cartItemData[BundleData::CART_INSTANCE] = $instance;
        $cartItemData[BundleData::CART_SNAPSHOT] = BundleData::build_snapshot($product, $selection);
        if (!empty($_REQUEST['hb_ucs_bundle_edit_key']) && function_exists('WC') && WC()->cart) {
            $editKey = sanitize_key((string) wp_unslash($_REQUEST['hb_ucs_bundle_edit_key']));
            $old = WC()->cart->get_cart_item($editKey);
            if (is_array($old) && (int) ($old['product_id'] ?? 0) === $productId && !empty($old['woosb_ids'])) {
                $cartItemData['hb_ucs_bundle_edit_key'] = $editKey;
            }
        }
        return $cartItemData;
    }

    public function add_children($cartItemKey, $productId, $quantity, $variationId, $variation, $cartItemData): void {
        if ($this->addingChildren || empty($cartItemData['woosb_ids']) || !isset(WC()->cart->cart_contents[$cartItemKey])) {
            return;
        }
        $selection = BundleData::parse_selection((string) $cartItemData['woosb_ids']);
        $parent = &WC()->cart->cart_contents[$cartItemKey];
        $parent['woosb_keys'] = [];
        $instance = (string) ($parent[BundleData::CART_INSTANCE] ?? BundleData::generate_instance_id());
        $editKey = !empty($cartItemData['hb_ucs_bundle_edit_key']) ? (string) $cartItemData['hb_ucs_bundle_edit_key'] : '';
        $removedEditKey = '';
        if ($editKey !== '' && $editKey !== $cartItemKey && is_array(WC()->cart->get_cart_item($editKey))) {
            // Remove the old group before adding replacements so stock and sold-individually checks see a replacement, not a duplicate.
            WC()->cart->remove_cart_item($editKey);
            if (isset(WC()->cart->removed_cart_contents[$editKey])) {
                $removedEditKey = $editKey;
            }
        }
        $this->addingChildren = true;
        try {
            foreach ($selection as $key => $component) {
                $chosen = wc_get_product((int) $component['id']);
                $requiredQuantity = max(0.0, (float) $component['qty']) * max(0.0, (float) $quantity);
                if (!$chosen || BundleData::is_bundle_product($chosen) || $requiredQuantity <= 0 || !$chosen->is_purchasable() || !$chosen->is_in_stock()) {
                    throw new \RuntimeException('Bundle component is not purchasable.');
                }
                if ($chosen->managing_stock() && !$chosen->backorders_allowed() && !$chosen->has_enough_stock($requiredQuantity)) {
                    throw new \RuntimeException('Bundle component stock is insufficient.');
                }
                $childProductId = $chosen instanceof \WC_Product_Variation ? $chosen->get_parent_id() : $chosen->get_id();
                $childVariationId = $chosen instanceof \WC_Product_Variation ? $chosen->get_id() : 0;
                $attrs = $chosen instanceof \WC_Product_Variation ? $chosen->get_variation_attributes() : [];
                $childData = [
                    'woosb_qty' => (float) $component['qty'],
                    'woosb_parent_id' => (int) $productId,
                    'woosb_parent_key' => $cartItemKey,
                    'woosb_fixed_price' => !empty($parent['woosb_fixed_price']),
                    'woosb_discount' => (float) ($parent['woosb_discount'] ?? 0),
                    'woosb_discount_amount' => (float) ($parent['woosb_discount_amount'] ?? 0),
                    'hb_ucs_bundle_component_key' => (string) $key,
                    BundleData::CART_INSTANCE => $instance,
                ];
                $childKey = WC()->cart->add_to_cart($childProductId, (float) $component['qty'] * (float) $quantity, $childVariationId, $attrs, $childData);
                if (!$childKey) {
                    throw new \RuntimeException('Could not add bundle component.');
                }
                $parent['woosb_keys'][] = $childKey;
            }
        } catch (\Throwable $error) {
            if (isset(WC()->cart->cart_contents[$cartItemKey])) {
                WC()->cart->remove_cart_item($cartItemKey);
            }
            if ($removedEditKey !== '' && isset(WC()->cart->removed_cart_contents[$removedEditKey])) {
                WC()->cart->restore_cart_item($removedEditKey);
            }
            wc_add_notice(__('De bundel kon niet volledig aan de winkelmand worden toegevoegd. Probeer het opnieuw.', 'hb-ucs'), 'error');
        } finally {
            $this->addingChildren = false;
        }
        if ($editKey !== '' && $editKey !== $cartItemKey && $removedEditKey === '') {
            WC()->cart->remove_cart_item($editKey);
        }
    }

    public function calculate_prices($cart): void {
        if (!is_object($cart) || (is_admin() && !wp_doing_ajax())) {
            return;
        }
        $contents = $cart->get_cart();
        foreach ($contents as $parentKey => $parent) {
            if (empty($parent['woosb_ids']) || empty($parent[BundleData::CART_INSTANCE]) || empty($parent['data'])) {
                continue;
            }
            $fixed = !empty($parent['woosb_fixed_price']);
            $parentQty = max(0.0, (float) ($parent['quantity'] ?? 0));
            $children = [];
            $baseTotal = 0.0;
            foreach ((array) ($parent['woosb_keys'] ?? []) as $childKey) {
                if (!isset($cart->cart_contents[$childKey])) {
                    continue;
                }
                $child = &$cart->cart_contents[$childKey];
                if ((string) ($child[BundleData::CART_INSTANCE] ?? '') !== (string) $parent[BundleData::CART_INSTANCE]) {
                    continue;
                }
                $componentQty = max(0.0, (float) ($child['woosb_qty'] ?? 0));
                $child['quantity'] = $componentQty * $parentQty;
                $fresh = wc_get_product((int) (!empty($child['variation_id']) ? $child['variation_id'] : $child['product_id']));
                $unit = $fresh ? max(0.0, (float) $fresh->get_price()) : 0.0;
                $children[$childKey] = ['unit' => $unit, 'component_qty' => $componentQty];
                $baseTotal += $unit * $componentQty;
                unset($child);
            }

            if ($fixed) {
                $parentPrice = (float) $parent['data']->get_price('edit');
                if (!empty($parent['hb_ucs_subs']['final_price'])) {
                    $parentPrice = max(0.0, (float) $parent['hb_ucs_subs']['final_price']);
                }
                $cart->cart_contents[$parentKey]['data']->set_price($parentPrice);
                foreach ($children as $childKey => $row) {
                    $cart->cart_contents[$childKey]['data']->set_price(0);
                }
                $cart->cart_contents[$parentKey]['woosb_price'] = $parentPrice;
                continue;
            }

            $targetTotal = $baseTotal;
            $discountAmount = max(0.0, (float) ($parent['woosb_discount_amount'] ?? 0));
            $discount = max(0.0, (float) ($parent['woosb_discount'] ?? 0));
            if ($discountAmount > 0) {
                $targetTotal = max(0.0, $baseTotal - $discountAmount);
            } elseif ($discount > 0) {
                $targetTotal = max(0.0, $baseTotal * (100 - $discount) / 100);
            }
            if (!empty($parent['hb_ucs_subs']) && isset($parent['hb_ucs_subs']['base_price'], $parent['hb_ucs_subs']['final_price'])) {
                $subscriptionBase = max(0.0, (float) $parent['hb_ucs_subs']['base_price']);
                $subscriptionFinal = max(0.0, (float) $parent['hb_ucs_subs']['final_price']);
                if ($subscriptionBase > 0) {
                    $targetTotal *= $subscriptionFinal / $subscriptionBase;
                }
            }
            $ratio = $baseTotal > 0 ? $targetTotal / $baseTotal : 0.0;
            foreach ($children as $childKey => $row) {
                $price = (float) wc_format_decimal((string) ($row['unit'] * $ratio), wc_get_price_decimals());
                $cart->cart_contents[$childKey]['data']->set_price(max(0.0, $price));
            }
            $cart->cart_contents[$parentKey]['data']->set_price(0);
            $cart->cart_contents[$parentKey]['data']->set_tax_status('none');
            $cart->cart_contents[$parentKey]['woosb_price'] = (float) wc_format_decimal((string) $targetTotal, wc_get_price_decimals());
        }
    }

    public function remove_related_items(string $removedKey, $cart): void {
        if ($this->removingGroup || !isset($cart->removed_cart_contents[$removedKey])) {
            return;
        }
        $removed = $cart->removed_cart_contents[$removedKey];
        $instance = (string) ($removed[BundleData::CART_INSTANCE] ?? '');
        $relatedKeys = [];
        if ($instance !== '') {
            foreach ($cart->cart_contents as $key => $item) {
                if ((string) ($item[BundleData::CART_INSTANCE] ?? '') === $instance) {
                    $relatedKeys[] = (string) $key;
                }
            }
        } elseif (!empty($removed['woosb_keys'])) {
            $relatedKeys = array_map('strval', (array) $removed['woosb_keys']);
        } elseif (!empty($removed['woosb_parent_key'])) {
            $relatedKeys[] = (string) $removed['woosb_parent_key'];
        }
        $this->removingGroup = true;
        try {
            foreach (array_values(array_unique($relatedKeys)) as $relatedKey) {
                if (isset($cart->cart_contents[$relatedKey])) {
                    $cart->remove_cart_item($relatedKey);
                }
            }
        } finally {
            $this->removingGroup = false;
        }
    }

    public function restore_related_items(string $cartItemKey, $cart): void {
        $item = $cart->get_cart_item($cartItemKey);
        if (!is_array($item) || empty($item['woosb_ids'])) {
            return;
        }
        $instance = (string) ($item[BundleData::CART_INSTANCE] ?? '');
        $childKeys = [];
        if ($instance !== '') {
            foreach ($cart->removed_cart_contents as $childKey => $removed) {
                if ((string) ($removed[BundleData::CART_INSTANCE] ?? '') === $instance && !empty($removed['woosb_parent_id'])) {
                    $childKeys[] = (string) $childKey;
                }
            }
        } else {
            $childKeys = array_map('strval', (array) ($item['woosb_keys'] ?? []));
        }
        foreach ($childKeys as $childKey) {
            if (isset($cart->removed_cart_contents[$childKey])) {
                $cart->restore_cart_item($childKey);
            }
        }
    }

    public function cart_item_remove_link(string $link, string $cartItemKey): string {
        $item = WC()->cart ? WC()->cart->get_cart_item($cartItemKey) : null;
        return is_array($item) && !empty($item['woosb_parent_id']) ? '' : $link;
    }

    public function cart_item_quantity($html, string $cartItemKey, array $item) {
        return !empty($item['woosb_parent_id']) ? '<span class="hb-ucs-bundle-child-qty">' . esc_html(wc_format_localized_decimal((float) $item['quantity'])) . '</span>' : $html;
    }

    public function validate_cart_quantity($passed, string $cartItemKey, array $values, $quantity) {
        if (!$passed || empty($values['woosb_ids']) || empty($values['woosb_keys']) || !function_exists('WC') || !WC()->cart) {
            return $passed;
        }
        $desiredParentQuantity = max(0.0, (float) $quantity);
        $cartQuantities = method_exists(WC()->cart, 'get_cart_item_quantities') ? WC()->cart->get_cart_item_quantities() : [];
        $currentGroup = [];
        $desiredGroup = [];
        $stockProducts = [];
        foreach ((array) $values['woosb_keys'] as $childKey) {
            $child = WC()->cart->get_cart_item((string) $childKey);
            if (!is_array($child) || empty($child['data']) || !is_object($child['data'])) {
                continue;
            }
            $product = $child['data'];
            if (!$product->managing_stock() || $product->backorders_allowed()) {
                continue;
            }
            $stockId = method_exists($product, 'get_stock_managed_by_id') ? (int) $product->get_stock_managed_by_id() : (int) $product->get_id();
            if ($stockId <= 0) {
                continue;
            }
            $stockProduct = wc_get_product($stockId);
            if (!$stockProduct) {
                continue;
            }
            $currentGroup[$stockId] = (float) ($currentGroup[$stockId] ?? 0.0) + max(0.0, (float) ($child['quantity'] ?? 0));
            $desiredGroup[$stockId] = (float) ($desiredGroup[$stockId] ?? 0.0) + max(0.0, (float) ($child['woosb_qty'] ?? 0)) * $desiredParentQuantity;
            $stockProducts[$stockId] = $stockProduct;
        }
        foreach ($desiredGroup as $stockId => $desiredQuantity) {
            $otherQuantity = max(0.0, (float) ($cartQuantities[$stockId] ?? 0.0) - (float) ($currentGroup[$stockId] ?? 0.0));
            if (!$stockProducts[$stockId]->has_enough_stock($otherQuantity + $desiredQuantity)) {
                wc_add_notice(sprintf(__('Er is onvoldoende voorraad van “%s” voor dit bundelaantal.', 'hb-ucs'), $stockProducts[$stockId]->get_name()), 'error');
                return false;
            }
        }
        return $passed;
    }

    public function cart_item_class(string $class, array $item, string $key = ''): string {
        if (!empty($item['woosb_parent_id'])) {
            $class .= ' hb-ucs-bundle-cart-child';
        } elseif (!empty($item['woosb_ids'])) {
            $class .= ' hb-ucs-bundle-cart-parent';
        }
        return trim($class);
    }

    public function cart_item_data(array $data, array $item): array {
        if (empty($item['woosb_ids']) || empty($item[BundleData::CART_SNAPSHOT]['components'])) {
            return $data;
        }
        $parts = [];
        foreach ((array) $item[BundleData::CART_SNAPSHOT]['components'] as $component) {
            $parts[] = wc_format_localized_decimal((float) ($component['quantity'] ?? 0)) . ' × ' . (string) ($component['name'] ?? '');
        }
        $data[] = ['key' => __('Samenstelling', 'hb-ucs'), 'value' => implode('; ', $parts), 'display' => esc_html(implode('; ', $parts))];
        return $data;
    }

    public function cart_edit_link(array $item, string $cartItemKey): void {
        $settings = BundleSettings::get();
        if (empty($settings['allow_cart_edit']) || empty($item['woosb_ids']) || empty($item['data']) || !BundleData::is_bundle_product($item['data'])) {
            return;
        }
        $url = add_query_arg('hb_ucs_bundle_edit', $cartItemKey, $item['data']->get_permalink());
        echo '<p class="hb-ucs-bundle-edit"><a href="' . esc_url($url) . '">' . esc_html__('Samenstelling wijzigen', 'hb-ucs') . '</a></p>';
    }

    public function cart_item_visible(bool $visible, array $item, string $key = ''): bool {
        $settings = BundleSettings::get();
        return !empty($settings['hide_children_cart']) && !empty($item['woosb_parent_id']) ? false : $visible;
    }

    public function shipping_packages(array $packages): array {
        foreach ($packages as &$package) {
            foreach ((array) ($package['contents'] ?? []) as $key => $item) {
                if (!empty($item['woosb_parent_id'])) {
                    $parent = wc_get_product((int) $item['woosb_parent_id']);
                    $mode = $parent ? (string) ($parent->get_meta(BundleData::META_SHIPPING) ?: 'whole') : 'whole';
                    if ($mode === 'whole') {
                        unset($package['contents'][$key]);
                    }
                } elseif (!empty($item['woosb_ids']) && !empty($item['data'])) {
                    $mode = (string) ($item['data']->get_meta(BundleData::META_SHIPPING) ?: 'whole');
                    if ($mode === 'each') {
                        unset($package['contents'][$key]);
                    }
                }
            }
        }
        unset($package);
        return $packages;
    }

    public function display_parent_price(string $price, array $item): string {
        if (!empty($item['woosb_ids']) && empty($item['woosb_fixed_price']) && isset($item['woosb_price'])) {
            return wc_price((float) $item['woosb_price']);
        }
        return $price;
    }

    public function display_parent_subtotal(string $subtotal, array $item): string {
        if (!empty($item['woosb_ids']) && empty($item['woosb_fixed_price']) && isset($item['woosb_price'])) {
            return wc_price((float) $item['woosb_price'] * (float) ($item['quantity'] ?? 1));
        }
        return $subtotal;
    }

    private function resolve_selected_product($source, array $selected, array $definition) {
        $selectedId = max(0, (int) ($selected['id'] ?? 0));
        if ($source->is_type('variable')) {
            $chosen = $selectedId > 0 ? wc_get_product($selectedId) : false;
            if (!$chosen instanceof \WC_Product_Variation || (int) $chosen->get_parent_id() !== (int) $source->get_id()) {
                return false;
            }
            $allowedTerms = isset($definition['terms']) && is_array($definition['terms']) ? $definition['terms'] : [];
            foreach (BundleData::sanitize_attributes($chosen->get_variation_attributes()) as $name => $value) {
                $termKey = preg_replace('/^attribute_/', '', $name);
                if (!empty($allowedTerms[$termKey]) && !in_array($value, (array) $allowedTerms[$termKey], true)) {
                    return false;
                }
            }
            return $chosen;
        }
        return $selectedId === (int) $source->get_id() ? $source : false;
    }
}
