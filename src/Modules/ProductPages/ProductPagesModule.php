<?php

namespace HB\UCS\Modules\ProductPages;

use HB\UCS\Core\Settings;

if (!defined('ABSPATH')) exit;

class ProductPagesModule {
    private const META_SINGLE_ADD_TO_CART_TEXT = '_hb_ucs_single_add_to_cart_text';
    private const META_ARCHIVE_ADD_TO_CART_TEXT = '_hb_ucs_archive_add_to_cart_text';

    /** @var array<string,mixed>|null */
    private $settingsCache = null;

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        if (is_admin()) {
            add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_fields']);
            add_action('woocommerce_admin_process_product_object', [$this, 'save_product_fields']);
        }

        add_filter('woocommerce_product_single_add_to_cart_text', [$this, 'filter_single_add_to_cart_text'], 20, 2);
        add_filter('woocommerce_product_add_to_cart_text', [$this, 'filter_archive_add_to_cart_text'], 20, 2);
    }

    public function render_product_fields(): void {
        global $post;

        if (!$post || (string) $post->post_type !== 'product') {
            return;
        }

        $product = wc_get_product((int) $post->ID);
        if (!$product || ((int) $product->get_parent_id() > 0)) {
            return;
        }

        echo '<div class="options_group">';

        woocommerce_wp_text_input([
            'id' => self::META_SINGLE_ADD_TO_CART_TEXT,
            'label' => __('Knoptekst productpagina', 'hb-ucs'),
            'description' => __('Overschrijft de add-to-cart knoptekst op de productpagina, inclusief standaard WooCommerce en Elementor WooCommerce productwidgets.', 'hb-ucs'),
            'desc_tip' => true,
            'value' => (string) get_post_meta((int) $post->ID, self::META_SINGLE_ADD_TO_CART_TEXT, true),
        ]);

        woocommerce_wp_text_input([
            'id' => self::META_ARCHIVE_ADD_TO_CART_TEXT,
            'label' => __('Knoptekst shop/archive', 'hb-ucs'),
            'description' => __('Overschrijft de add-to-cart knoptekst op shop-, categorie-, loop- en standaard Elementor WooCommerce productoverzichten.', 'hb-ucs'),
            'desc_tip' => true,
            'value' => (string) get_post_meta((int) $post->ID, self::META_ARCHIVE_ADD_TO_CART_TEXT, true),
        ]);

        echo '</div>';
    }

    public function save_product_fields($product): void {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        if (method_exists($product, 'get_parent_id') && (int) $product->get_parent_id() > 0) {
            return;
        }

        $this->save_text_meta(
            $product,
            self::META_SINGLE_ADD_TO_CART_TEXT,
            isset($_POST[self::META_SINGLE_ADD_TO_CART_TEXT]) ? $_POST[self::META_SINGLE_ADD_TO_CART_TEXT] : ''
        );

        $this->save_text_meta(
            $product,
            self::META_ARCHIVE_ADD_TO_CART_TEXT,
            isset($_POST[self::META_ARCHIVE_ADD_TO_CART_TEXT]) ? $_POST[self::META_ARCHIVE_ADD_TO_CART_TEXT] : ''
        );
    }

    public function filter_single_add_to_cart_text(string $text, $product = null): string {
        return $this->get_custom_button_text($product, self::META_SINGLE_ADD_TO_CART_TEXT, $text);
    }

    public function filter_archive_add_to_cart_text(string $text, $product = null): string {
        return $this->get_custom_button_text($product, self::META_ARCHIVE_ADD_TO_CART_TEXT, $text);
    }

    private function save_text_meta($product, string $metaKey, $rawValue): void {
        $value = sanitize_text_field((string) wp_unslash($rawValue));

        if ($value === '') {
            if (method_exists($product, 'delete_meta_data')) {
                $product->delete_meta_data($metaKey);
            }
            return;
        }

        if (method_exists($product, 'update_meta_data')) {
            $product->update_meta_data($metaKey, $value);
        }
    }

    private function get_custom_button_text($product, string $metaKey, string $fallback): string {
        $product = $this->normalize_product($product);
        if (!$product) {
            return $fallback;
        }

        $sourceProduct = $this->get_text_source_product($product);
        if (!$sourceProduct) {
            return $fallback;
        }

        $customText = trim((string) get_post_meta((int) $sourceProduct->get_id(), $metaKey, true));

        if ($customText !== '') {
            return $customText;
        }

        $defaultText = $this->get_default_button_text($sourceProduct, $metaKey);

        return $defaultText !== '' ? $defaultText : $fallback;
    }

    private function get_default_button_text(\WC_Product $product, string $metaKey): string {
        $typeKey = $this->get_product_type_key($product);
        if ($typeKey === '') {
            return '';
        }

        $settings = $this->get_settings();
        $settingKey = $metaKey === self::META_SINGLE_ADD_TO_CART_TEXT
            ? 'default_single_text_' . $typeKey
            : 'default_archive_text_' . $typeKey;

        return trim((string) ($settings[$settingKey] ?? ''));
    }

    private function get_product_type_key(\WC_Product $product): string {
        if ($product->is_type('bundle')) {
            return 'bundle';
        }

        if ($product->is_type('variable')) {
            return 'variable';
        }

        if ($product->is_type('simple')) {
            return 'simple';
        }

        return '';
    }

    private function get_settings(): array {
        if ($this->settingsCache === null) {
            $defaults = [
                'default_single_text_simple' => '',
                'default_single_text_variable' => '',
                'default_single_text_bundle' => '',
                'default_archive_text_simple' => '',
                'default_archive_text_variable' => '',
                'default_archive_text_bundle' => '',
            ];

            $settings = get_option(Settings::OPT_PRODUCT_PAGES, []);
            $this->settingsCache = array_replace($defaults, is_array($settings) ? $settings : []);
        }

        return $this->settingsCache;
    }

    private function get_text_source_product(\WC_Product $product): ?\WC_Product {
        if ($product->is_type('variation')) {
            $parentId = (int) $product->get_parent_id();
            if ($parentId > 0 && function_exists('wc_get_product')) {
                $parentProduct = wc_get_product($parentId);
                return $parentProduct instanceof \WC_Product ? $parentProduct : null;
            }
        }

        return $product;
    }

    private function normalize_product($product): ?\WC_Product {
        if ($product instanceof \WC_Product) {
            return $product;
        }

        if (is_numeric($product) && function_exists('wc_get_product')) {
            $resolved = wc_get_product((int) $product);
            return $resolved instanceof \WC_Product ? $resolved : null;
        }

        return null;
    }

}