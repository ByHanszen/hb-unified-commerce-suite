<?php
namespace HB\UCS\Modules\Bundles\Frontend;

use HB\UCS\Modules\Bundles\Admin\BundleSettings;
use HB\UCS\Modules\Bundles\Support\BundleData;

if (!defined('ABSPATH')) exit;

final class BundleFrontend {
    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('woocommerce_woosb_add_to_cart', [$this, 'render_add_to_cart']);
    }

    public function enqueue_assets(): void {
        if (!is_product()) {
            return;
        }
        $product = wc_get_product(get_queried_object_id());
        if (!BundleData::is_bundle_product($product)) {
            return;
        }
        $base = plugins_url('src/Modules/Bundles/assets/', HB_UCS_PLUGIN_FILE);
        $version = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';
        wp_enqueue_style('hb-ucs-bundles-frontend', $base . 'frontend-hb-ucs-bundles.css', [], $version);
        wp_enqueue_script('hb-ucs-bundles-frontend', $base . 'frontend-hb-ucs-bundles.js', ['jquery'], $version, true);
        wp_localize_script('hb-ucs-bundles-frontend', 'hbUcsBundles', [
            'locale' => str_replace('_', '-', determine_locale()),
            'currency' => get_woocommerce_currency(),
            'priceDecimals' => wc_get_price_decimals(),
            'requiredError' => __('Kies eerst alle verplichte opties.', 'hb-ucs'),
            'quantityError' => __('Controleer de gekozen aantallen.', 'hb-ucs'),
            'stockError' => __('Een gekozen onderdeel is niet voldoende op voorraad.', 'hb-ucs'),
            'emptyError' => __('Kies minimaal één onderdeel.', 'hb-ucs'),
            'totalError' => __('De gekozen samenstelling valt buiten de toegestane prijsgrenzen.', 'hb-ucs'),
        ]);
    }

    public function render_add_to_cart(): void {
        global $product;
        if (!BundleData::is_bundle_product($product)) {
            return;
        }
        $items = method_exists($product, 'get_items') ? $product->get_items() : BundleData::normalize_product_items($product);
        if (empty($items)) {
            echo '<p class="stock out-of-stock">' . esc_html__('Deze bundel heeft nog geen onderdelen.', 'hb-ucs') . '</p>';
            return;
        }

        $settings = BundleSettings::get();
        $layout = (string) ($product->get_meta('woosb_layout') ?: 'default');
        if ($layout === 'default') {
            $layout = (string) $settings['layout'];
        }
        $sectionTitle = (string) ($product->get_meta('woosb_section_title') ?: $settings['section_title']);
        $editing = $this->get_editing_cart_item($product->get_id());
        $editSelection = !empty($editing['woosb_ids']) ? BundleData::parse_selection((string) $editing['woosb_ids']) : [];
        $fixedPrice = method_exists($product, 'is_fixed_price') && $product->is_fixed_price();
        $config = [
            'bundleId' => $product->get_id(),
            'fixedPrice' => $fixedPrice,
            'fixedTotal' => (float) wc_get_price_to_display($product),
            'discount' => method_exists($product, 'get_discount_percentage') ? (float) $product->get_discount_percentage() : 0,
            'discountAmount' => method_exists($product, 'get_discount_amount') ? (float) $product->get_discount_amount() : 0,
            'minCount' => max(0.0, (float) $product->get_meta('woosb_limit_whole_min')),
            'maxCount' => max(0.0, (float) $product->get_meta('woosb_limit_whole_max')),
            'useTotalLimits' => (string) $product->get_meta('woosb_total_limits') === 'on',
            'minTotal' => max(0, (float) $product->get_meta('woosb_total_limits_min')),
            'maxTotal' => max(0, (float) $product->get_meta('woosb_total_limits_max')),
        ];

        do_action('woocommerce_before_add_to_cart_form');
        echo '<form class="cart hb-ucs-bundle-form" action="' . esc_url(apply_filters('woocommerce_add_to_cart_form_action', $product->get_permalink())) . '" method="post" enctype="multipart/form-data">';
        wp_nonce_field('hb_ucs_add_bundle_' . $product->get_id(), 'hb_ucs_bundle_nonce');
        echo '<section class="hb-ucs-bundle hb-ucs-bundle--' . esc_attr($layout) . '" data-config="' . esc_attr(wp_json_encode($config)) . '">';
        echo '<header class="hb-ucs-bundle__header"><h2>' . esc_html($sectionTitle) . '</h2>';
        $before = (string) $product->get_meta('woosb_before_text');
        if ($before !== '') {
            echo '<div class="hb-ucs-bundle__intro">' . wp_kses_post(wpautop(do_shortcode($before))) . '</div>';
        }
        echo '</header><div class="hb-ucs-bundle__items">';

        $currentGroup = null;
        foreach ($items as $key => $item) {
            if (empty($item['id'])) {
                $tag = (string) ($item['type'] ?? 'p');
                if ($tag === 'none') {
                    echo '<div class="hb-ucs-bundle__content-row">' . wp_kses_post((string) ($item['text'] ?? '')) . '</div>';
                } else {
                    $tag = in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span'], true) ? $tag : 'p';
                    echo '<' . tag_escape($tag) . ' class="hb-ucs-bundle__content-row">' . wp_kses_post((string) ($item['text'] ?? '')) . '</' . tag_escape($tag) . '>';
                }
                continue;
            }
            $group = trim((string) ($item['group'] ?? ''));
            if ($group !== '' && $group !== $currentGroup) {
                echo '<h3 class="hb-ucs-bundle__group">' . esc_html($group) . '</h3>';
                $currentGroup = $group;
            }
            $selected = isset($editSelection[$key]) ? $editSelection[$key] : [];
            $this->render_component((string) $key, $item, $selected, $settings);
        }
        echo '</div>';

        echo '<aside class="hb-ucs-bundle__summary" aria-live="polite"><h3>' . esc_html((string) $settings['summary_title']) . '</h3><ul class="hb-ucs-bundle__summary-list"></ul><div class="hb-ucs-bundle__total"><span>' . esc_html((string) $settings['total_text']) . '</span><strong class="hb-ucs-bundle__total-value"></strong></div></aside>';
        echo '<div class="hb-ucs-bundle__notice" role="alert" hidden></div>';
        $after = (string) $product->get_meta('woosb_after_text');
        if ($after !== '') {
            echo '<div class="hb-ucs-bundle__after">' . wp_kses_post(wpautop(do_shortcode($after))) . '</div>';
        }
        echo '</section>';

        echo '<input type="hidden" name="woosb_ids" class="hb-ucs-bundle-selection" value="" />';
        if (!empty($editing['key'])) {
            echo '<input type="hidden" name="hb_ucs_bundle_edit_key" value="' . esc_attr((string) $editing['key']) . '" />';
        }
        do_action('woocommerce_before_add_to_cart_button');
        if (!$product->is_sold_individually()) {
            woocommerce_quantity_input(['min_value' => 1, 'max_value' => $product->get_max_purchase_quantity(), 'input_value' => !empty($editing['quantity']) ? (int) $editing['quantity'] : 1], $product);
        }
        echo '<button type="submit" name="add-to-cart" value="' . esc_attr($product->get_id()) . '" class="single_add_to_cart_button button alt hb-ucs-bundle__submit">' . esc_html(!empty($editing['key']) ? __('Bundel bijwerken', 'hb-ucs') : (string) $settings['add_button_text']) . '</button>';
        do_action('woocommerce_after_add_to_cart_button');
        echo '</form>';
        do_action('woocommerce_after_add_to_cart_form');
    }

    private function render_component(string $key, array $item, array $selected, array $settings): void {
        $source = wc_get_product((int) $item['id']);
        if (!$source || BundleData::is_bundle_product($source)) {
            return;
        }
        $optional = !empty($item['optional']);
        $qty = isset($selected['qty']) ? (float) $selected['qty'] : (float) ($item['qty'] ?? 1);
        $min = $optional ? max(0, (float) ($item['min'] ?? 0)) : $qty;
        $max = $optional && ($item['max'] ?? '') !== '' ? max($min, (float) $item['max']) : ($optional ? 999999 : $qty);
        $title = trim((string) ($item['customer_title'] ?? '')) ?: $source->get_name();
        $description = trim((string) ($item['customer_description'] ?? ''));
        if ($description === '' && !empty($settings['show_descriptions'])) {
            $description = $source->get_short_description();
        }
        $selectedId = !empty($selected['id']) ? (int) $selected['id'] : ($source->is_type('variable') ? 0 : $source->get_id());
        $attrs = !empty($selected['attrs']) ? BundleData::sanitize_attributes($selected['attrs']) : [];
        $allowedTerms = isset($item['terms']) && is_array($item['terms']) ? $item['terms'] : [];
        $price = (float) wc_get_price_to_display($source);
        $regular = (float) wc_get_price_to_display($source, ['price' => (float) ($source->get_regular_price() ?: $source->get_price())]);
        $component = [
            'key' => $key,
            'sourceId' => $source->get_id(),
            'selectedId' => $selectedId,
            'variable' => $source->is_type('variable'),
            'optional' => $optional,
            'qty' => $qty,
            'min' => $min,
            'max' => $max,
            'price' => $price,
            'regularPrice' => $regular,
            'stock' => $source->get_max_purchase_quantity(),
            'purchasable' => $source->is_purchasable() && $source->is_in_stock(),
            'attributes' => $attrs,
        ];
        $classes = ['hb-ucs-bundle__item'];
        $classes[] = $optional ? 'is-optional' : 'is-required';
        $classes[] = $source->is_in_stock() ? 'is-in-stock' : 'is-out-of-stock';
        echo '<article class="' . esc_attr(implode(' ', $classes)) . '" data-component="' . esc_attr(wp_json_encode($component)) . '">';
        if (!empty($settings['show_images'])) {
            echo '<div class="hb-ucs-bundle__image">' . wp_kses_post($source->get_image('woocommerce_thumbnail')) . '</div>';
        }
        echo '<div class="hb-ucs-bundle__details"><div class="hb-ucs-bundle__title-row"><h4>' . esc_html($title) . '</h4>';
        if (!empty($item['badge'])) {
            echo '<span class="hb-ucs-bundle__badge">' . esc_html((string) $item['badge']) . '</span>';
        }
        echo '</div><span class="hb-ucs-bundle__kind">' . esc_html($optional ? (string) $settings['optional_text'] : (string) $settings['required_text']) . '</span>';
        if ($description !== '') {
            echo '<div class="hb-ucs-bundle__description">' . wp_kses_post(wpautop($description)) . '</div>';
        }
        if ($source->is_type('variable')) {
            $this->render_variation_choices($source, $attrs, $allowedTerms);
        }
        if (!empty($settings['show_stock'])) {
            echo '<div class="hb-ucs-bundle__stock">' . wp_kses_post(wc_get_stock_html($source)) . '</div>';
        }
        echo '</div><div class="hb-ucs-bundle__choice">';
        if (!empty($settings['show_prices'])) {
            echo '<div class="hb-ucs-bundle__price">' . wp_kses_post($source->get_price_html()) . '</div>';
        }
        if ($optional) {
            woocommerce_quantity_input(['input_value' => $qty, 'min_value' => $min, 'max_value' => $max, 'input_name' => 'hb_ucs_bundle_qty_' . $key, 'classes' => ['input-text', 'qty', 'text', 'hb-ucs-bundle__qty']], $source);
        } else {
            echo '<div class="hb-ucs-bundle__fixed-qty"><span>' . esc_html__('Aantal', 'hb-ucs') . '</span><strong>' . esc_html(wc_format_localized_decimal($qty)) . '</strong></div>';
        }
        echo '</div>';
        if ($source->is_type('variable')) {
            echo '<script type="application/json" class="hb-ucs-bundle__variations">' . wp_json_encode($this->variation_data($source, $allowedTerms)) . '</script>';
        }
        echo '</article>';
    }

    private function render_variation_choices(\WC_Product_Variable $product, array $selected, array $allowedTerms): void {
        echo '<div class="hb-ucs-bundle__variation-fields">';
        foreach ($product->get_variation_attributes() as $attributeName => $options) {
            $fieldName = 'attribute_' . sanitize_title($attributeName);
            $termKey = sanitize_title((string) $attributeName);
            $allowed = isset($allowedTerms[$termKey]) && is_array($allowedTerms[$termKey]) ? $allowedTerms[$termKey] : [];
            echo '<label><span>' . esc_html(wc_attribute_label($attributeName, $product)) . '</span><select class="hb-ucs-bundle__attribute" data-attribute="' . esc_attr($fieldName) . '"><option value="">' . esc_html__('Maak een keuze', 'hb-ucs') . '</option>';
            foreach ($options as $option) {
                if (!empty($allowed) && !in_array(sanitize_title((string) $option), $allowed, true)) {
                    continue;
                }
                $label = taxonomy_exists($attributeName) ? (($term = get_term_by('slug', $option, $attributeName)) ? $term->name : $option) : $option;
                echo '<option value="' . esc_attr($option) . '" ' . selected((string) ($selected[$fieldName] ?? ''), (string) $option, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select></label>';
        }
        echo '</div>';
    }

    private function variation_data(\WC_Product_Variable $product, array $allowedTerms): array {
        $data = [];
        foreach ($product->get_available_variations() as $variation) {
            if (empty($variation['variation_id'])) {
                continue;
            }
            $attributes = BundleData::sanitize_attributes((array) ($variation['attributes'] ?? []));
            if (!$this->variation_is_allowed($attributes, $allowedTerms)) {
                continue;
            }
            $data[] = [
                'id' => (int) $variation['variation_id'],
                'attributes' => $attributes,
                'price' => (float) ($variation['display_price'] ?? 0),
                'regularPrice' => (float) ($variation['display_regular_price'] ?? ($variation['display_price'] ?? 0)),
                'purchasable' => !empty($variation['is_purchasable']) && !empty($variation['is_in_stock']),
                'stock' => isset($variation['max_qty']) && $variation['max_qty'] !== '' ? (float) $variation['max_qty'] : -1,
                'priceHtml' => (string) ($variation['price_html'] ?? ''),
                'image' => (string) ($variation['image']['src'] ?? ''),
            ];
        }
        return $data;
    }

    private function variation_is_allowed(array $attributes, array $allowedTerms): bool {
        foreach ($allowedTerms as $termKey => $allowed) {
            if (!is_array($allowed) || empty($allowed)) {
                continue;
            }
            $attributeKey = 'attribute_' . sanitize_title((string) $termKey);
            $value = sanitize_title((string) ($attributes[$attributeKey] ?? ''));
            // An empty variation attribute is WooCommerce's wildcard and is constrained by the selected dropdown value.
            if ($value !== '' && !in_array($value, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    private function get_editing_cart_item(int $productId): array {
        if (!function_exists('WC') || !WC()->cart || empty($_GET['hb_ucs_bundle_edit'])) {
            return [];
        }
        $key = sanitize_key((string) wp_unslash($_GET['hb_ucs_bundle_edit']));
        $item = WC()->cart->get_cart_item($key);
        if (!is_array($item) || (int) ($item['product_id'] ?? 0) !== $productId || empty($item['woosb_ids'])) {
            return [];
        }
        $item['key'] = $key;
        return $item;
    }
}
