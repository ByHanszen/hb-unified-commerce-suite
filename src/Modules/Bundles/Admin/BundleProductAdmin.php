<?php
namespace HB\UCS\Modules\Bundles\Admin;

use HB\UCS\Modules\Bundles\Support\BundleData;

if (!defined('ABSPATH')) exit;

final class BundleProductAdmin {
    public function init(): void {
        add_filter('product_type_selector', [$this, 'add_product_type']);
        add_filter('woocommerce_product_data_tabs', [$this, 'add_product_tab']);
        add_action('woocommerce_product_data_panels', [$this, 'render_panel']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product'], 30);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_product_type(array $types): array {
        $types[BundleData::PRODUCT_TYPE] = __('Productbundel', 'hb-ucs');
        return $types;
    }

    public function add_product_tab(array $tabs): array {
        $tabs['hb_ucs_bundle'] = [
            'label' => __('Bundel samenstellen', 'hb-ucs'),
            'target' => 'hb_ucs_bundle_product_data',
            'class' => ['show_if_woosb'],
            'priority' => 25,
        ];
        return $tabs;
    }

    public function enqueue_assets(string $hook): void {
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || get_post_type() !== 'product') {
            return;
        }
        $base = trailingslashit(plugins_url('src/Modules/Bundles/assets/', HB_UCS_PLUGIN_FILE));
        $version = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('wc-enhanced-select');
        wp_enqueue_script('hb-ucs-bundles-admin', $base . 'admin-hb-ucs-bundles.js', ['jquery', 'jquery-ui-sortable', 'wc-enhanced-select'], $version, true);
        wp_enqueue_style('hb-ucs-bundles-admin', $base . 'admin-hb-ucs-bundles.css', [], $version);
        wp_localize_script('hb-ucs-bundles-admin', 'hbUcsBundlesAdmin', [
            'remove' => __('Verwijderen', 'hb-ucs'),
            'included' => __('Inbegrepen', 'hb-ucs'),
            'optional' => __('Keuzeonderdeel', 'hb-ucs'),
            'contentRow' => __('Tekst of tussenkop', 'hb-ucs'),
        ]);
    }

    public function render_panel(): void {
        global $post, $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : wc_get_product($post ? $post->ID : 0);
        if (!$product) {
            return;
        }
        $items = BundleData::normalize_product_items($product);
        echo '<div id="hb_ucs_bundle_product_data" class="panel woocommerce_options_panel hidden">';
        wp_nonce_field('hb_ucs_save_bundle_product', 'hb_ucs_bundle_product_nonce');
        echo '<div class="options_group hb-ucs-bundle-builder">';
        echo '<p class="form-field"><label>' . esc_html__('Product toevoegen', 'hb-ucs') . '</label>';
        echo '<select class="wc-product-search hb-ucs-bundle-search" multiple="multiple" style="width:50%" data-placeholder="' . esc_attr__('Zoek producten of variaties…', 'hb-ucs') . '" data-action="woocommerce_json_search_products_and_variations" data-exclude="' . esc_attr($product->get_id()) . '"></select> ';
        echo '<button type="button" class="button hb-ucs-bundle-add">' . esc_html__('Toevoegen aan bundel', 'hb-ucs') . '</button></p>';
        echo '<p><button type="button" class="button hb-ucs-bundle-add-content">' . esc_html__('Tekst of tussenkop toevoegen', 'hb-ucs') . '</button></p>';
        echo '<p class="description hb-ucs-bundle-help">' . esc_html__('Sleep onderdelen in de gewenste volgorde. Voeg voor een vaste variatie rechtstreeks de variatie toe; voeg een variabel product toe wanneer de klant zelf moet kiezen.', 'hb-ucs') . '</p>';
        echo '<div class="hb-ucs-bundle-items" data-empty-text="' . esc_attr__('Nog geen onderdelen toegevoegd.', 'hb-ucs') . '">';
        foreach ($items as $key => $item) {
            $this->render_item((string) $key, $item);
        }
        echo '</div></div>';

        echo '<div class="options_group">';
        woocommerce_wp_checkbox(['id' => 'woosb_disable_auto_price', 'label' => __('Vaste bundelprijs', 'hb-ucs'), 'description' => __('Gebruik de normale WooCommerce-prijs van het hoofdproduct. De onderdelen krijgen in de bestelling prijs nul.', 'hb-ucs'), 'value' => $product->get_meta('woosb_disable_auto_price'), 'cbvalue' => 'on']);
        woocommerce_wp_text_input(['id' => 'woosb_discount', 'label' => __('Bundelkorting (%)', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'max' => '99.9999', 'step' => '0.0001'], 'value' => $product->get_meta('woosb_discount')]);
        woocommerce_wp_text_input(['id' => 'woosb_discount_amount', 'label' => __('Vaste bundelkorting', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '0.0001'], 'value' => $product->get_meta('woosb_discount_amount')]);
        woocommerce_wp_text_input(['id' => 'woosb_limit_whole_min', 'label' => __('Minimum aantal onderdelen', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'value' => $product->get_meta('woosb_limit_whole_min')]);
        woocommerce_wp_text_input(['id' => 'woosb_limit_whole_max', 'label' => __('Maximum aantal onderdelen', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'value' => $product->get_meta('woosb_limit_whole_max')]);
        woocommerce_wp_checkbox(['id' => 'woosb_total_limits', 'label' => __('Prijsgrenzen gebruiken', 'hb-ucs'), 'value' => $product->get_meta('woosb_total_limits'), 'cbvalue' => 'on']);
        woocommerce_wp_text_input(['id' => 'woosb_total_limits_min', 'label' => __('Minimum bundeltotaal', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '0.01'], 'value' => $product->get_meta('woosb_total_limits_min')]);
        woocommerce_wp_text_input(['id' => 'woosb_total_limits_max', 'label' => __('Maximum bundeltotaal', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '0.01'], 'value' => $product->get_meta('woosb_total_limits_max')]);
        woocommerce_wp_select(['id' => 'woosb_shipping_fee', 'label' => __('Verzendberekening', 'hb-ucs'), 'value' => $product->get_meta('woosb_shipping_fee') ?: 'whole', 'options' => [
            'whole' => __('Alleen het hoofdproduct', 'hb-ucs'),
            'each' => __('Alleen de afzonderlijke onderdelen', 'hb-ucs'),
            'both' => __('Hoofdproduct en onderdelen', 'hb-ucs'),
        ]]);
        woocommerce_wp_checkbox(['id' => 'woosb_manage_stock', 'label' => __('Eigen bundelvoorraad beheren', 'hb-ucs'), 'description' => __('Uitgeschakeld: beschikbare voorraad wordt automatisch door de verplichte onderdelen bepaald.', 'hb-ucs'), 'value' => $product->get_meta('woosb_manage_stock'), 'cbvalue' => 'on']);
        woocommerce_wp_select(['id' => 'woosb_layout', 'label' => __('Weergave', 'hb-ucs'), 'value' => $product->get_meta('woosb_layout') ?: 'default', 'options' => [
            'default' => __('Algemene instelling', 'hb-ucs'),
            'cards' => __('Kaarten', 'hb-ucs'),
            'list' => __('Lijst', 'hb-ucs'),
        ]]);
        woocommerce_wp_text_input(['id' => 'woosb_section_title', 'label' => __('Kop samenstelling', 'hb-ucs'), 'value' => $product->get_meta('woosb_section_title')]);
        woocommerce_wp_textarea_input(['id' => 'woosb_before_text', 'label' => __('Uitleg boven de bundel', 'hb-ucs'), 'value' => $product->get_meta('woosb_before_text')]);
        woocommerce_wp_textarea_input(['id' => 'woosb_after_text', 'label' => __('Uitleg onder de bundel', 'hb-ucs'), 'value' => $product->get_meta('woosb_after_text')]);
        echo '</div></div>';
    }

    public function save_product($product): void {
        if (!$product instanceof \WC_Product || (string) $product->get_type() !== BundleData::PRODUCT_TYPE) {
            return;
        }
        if (!isset($_POST['hb_ucs_bundle_product_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hb_ucs_bundle_product_nonce'])), 'hb_ucs_save_bundle_product')) {
            return;
        }
        $rawItems = isset($_POST['hb_ucs_bundle_items']) && is_array($_POST['hb_ucs_bundle_items']) ? wp_unslash($_POST['hb_ucs_bundle_items']) : [];
        $items = BundleData::normalize_items($rawItems);
        foreach ($items as $key => $item) {
            if (empty($item['id'])) {
                continue;
            }
            $component = wc_get_product((int) $item['id']);
            if ((int) $item['id'] === (int) $product->get_id() || BundleData::is_bundle_product($component)) {
                unset($items[$key]);
                if (class_exists('WC_Admin_Meta_Boxes')) {
                    \WC_Admin_Meta_Boxes::add_error(__('Een productbundel kan niet zichzelf of een andere bundel bevatten.', 'hb-ucs'));
                }
            }
        }
        $product->update_meta_data(BundleData::META_ITEMS, $items);
        foreach (['woosb_disable_auto_price', 'woosb_total_limits', 'woosb_manage_stock'] as $key) {
            $product->update_meta_data($key, isset($_POST[$key]) ? 'on' : 'off');
        }
        foreach (['woosb_discount', 'woosb_discount_amount', 'woosb_limit_whole_min', 'woosb_limit_whole_max', 'woosb_total_limits_min', 'woosb_total_limits_max'] as $key) {
            $product->update_meta_data($key, isset($_POST[$key]) ? wc_format_decimal(wp_unslash($_POST[$key])) : '');
        }
        $shipping = sanitize_key((string) wp_unslash($_POST['woosb_shipping_fee'] ?? 'whole'));
        $product->update_meta_data('woosb_shipping_fee', in_array($shipping, ['whole', 'each', 'both'], true) ? $shipping : 'whole');
        $layout = sanitize_key((string) wp_unslash($_POST['woosb_layout'] ?? 'default'));
        $product->update_meta_data('woosb_layout', in_array($layout, ['default', 'cards', 'list'], true) ? $layout : 'default');
        $product->update_meta_data('woosb_section_title', sanitize_text_field((string) wp_unslash($_POST['woosb_section_title'] ?? '')));
        $product->update_meta_data('woosb_before_text', wp_kses_post((string) wp_unslash($_POST['woosb_before_text'] ?? '')));
        $product->update_meta_data('woosb_after_text', wp_kses_post((string) wp_unslash($_POST['woosb_after_text'] ?? '')));
    }

    private function render_item(string $key, array $item): void {
        if (empty($item['id'])) {
            $this->render_content_item($key, $item);
            return;
        }
        $product = wc_get_product((int) $item['id']);
        if (!$product || BundleData::is_bundle_product($product)) {
            return;
        }
        $prefix = 'hb_ucs_bundle_items[' . $key . ']';
        $title = $product->get_formatted_name();
        echo '<article class="hb-ucs-bundle-item" data-key="' . esc_attr($key) . '">';
        echo '<header><span class="hb-ucs-bundle-handle dashicons dashicons-move" aria-hidden="true"></span><strong>' . wp_kses_post($title) . '</strong><button type="button" class="button-link-delete hb-ucs-bundle-remove">' . esc_html__('Verwijderen', 'hb-ucs') . '</button></header>';
        echo '<input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr($product->get_id()) . '" />';
        echo '<input type="hidden" name="' . esc_attr($prefix . '[sku]') . '" value="' . esc_attr($product->get_sku()) . '" />';
        echo '<div class="hb-ucs-bundle-item-grid">';
        $this->number_field($prefix . '[qty]', __('Standaardaantal', 'hb-ucs'), $item['qty'] ?? 1, 0);
        echo '<label class="hb-ucs-bundle-optional"><span>' . esc_html__('Type', 'hb-ucs') . '</span><select name="' . esc_attr($prefix . '[optional]') . '"><option value="0" ' . selected(empty($item['optional']), true, false) . '>' . esc_html__('Vast inbegrepen', 'hb-ucs') . '</option><option value="1" ' . selected(!empty($item['optional']), true, false) . '>' . esc_html__('Keuzeonderdeel', 'hb-ucs') . '</option></select></label>';
        $this->number_field($prefix . '[min]', __('Minimum', 'hb-ucs'), $item['min'] ?? 0, 0);
        $this->number_field($prefix . '[max]', __('Maximum', 'hb-ucs'), $item['max'] ?? '', 0);
        $this->text_field($prefix . '[customer_title]', __('Klanttitel', 'hb-ucs'), $item['customer_title'] ?? '', __('Leeg gebruikt de productnaam', 'hb-ucs'));
        $this->text_field($prefix . '[badge]', __('Label', 'hb-ucs'), $item['badge'] ?? '', __('Bijv. Meest gekozen', 'hb-ucs'));
        $this->text_field($prefix . '[group]', __('Groep', 'hb-ucs'), $item['group'] ?? '', __('Bijv. Basis of Extra’s', 'hb-ucs'));
        echo '<label class="hb-ucs-bundle-wide"><span>' . esc_html__('Uitleg voor klant', 'hb-ucs') . '</span><textarea name="' . esc_attr($prefix . '[customer_description]') . '" rows="2">' . esc_textarea((string) ($item['customer_description'] ?? '')) . '</textarea></label>';
        $this->render_variation_restrictions($prefix, $product, $item);
        echo '</div></article>';
    }

    private function render_content_item(string $key, array $item): void {
        $prefix = 'hb_ucs_bundle_items[' . $key . ']';
        $type = (string) ($item['type'] ?? 'p');
        echo '<article class="hb-ucs-bundle-item hb-ucs-bundle-content-item" data-key="' . esc_attr($key) . '">';
        echo '<header><span class="hb-ucs-bundle-handle dashicons dashicons-move" aria-hidden="true"></span><strong>' . esc_html__('Tekst of tussenkop', 'hb-ucs') . '</strong><button type="button" class="button-link-delete hb-ucs-bundle-remove">' . esc_html__('Verwijderen', 'hb-ucs') . '</button></header>';
        echo '<input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="0" />';
        echo '<div class="hb-ucs-bundle-item-grid">';
        echo '<label><span>' . esc_html__('Opmaak', 'hb-ucs') . '</span><select name="' . esc_attr($prefix . '[type]') . '">';
        foreach (['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => __('Alinea', 'hb-ucs'), 'span' => __('Korte tekst', 'hb-ucs'), 'none' => __('Geen extra opmaak', 'hb-ucs')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($type, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="hb-ucs-bundle-wide"><span>' . esc_html__('Tekst', 'hb-ucs') . '</span><textarea name="' . esc_attr($prefix . '[text]') . '" rows="2">' . esc_textarea((string) ($item['text'] ?? '')) . '</textarea></label>';
        echo '</div></article>';
    }

    private function render_variation_restrictions(string $prefix, $product, array $item): void {
        $attrs = isset($item['attrs']) && is_array($item['attrs']) ? $item['attrs'] : [];
        foreach ($attrs as $name => $value) {
            echo '<input type="hidden" name="' . esc_attr($prefix . '[attrs][' . $name . ']') . '" value="' . esc_attr((string) $value) . '" />';
        }

        $terms = isset($item['terms']) && is_array($item['terms']) ? $item['terms'] : [];
        $rendered = [];
        if ($product->is_type('variable')) {
            foreach ((array) $product->get_variation_attributes() as $attributeName => $options) {
                $termKey = sanitize_title((string) $attributeName);
                $rendered[$termKey] = true;
                $selectedValues = isset($terms[$termKey]) ? (array) $terms[$termKey] : [];
                echo '<label class="hb-ucs-bundle-wide"><span>' . sprintf(esc_html__('Toegestane keuzes: %s', 'hb-ucs'), esc_html(wc_attribute_label($attributeName, $product))) . '</span>';
                echo '<select class="wc-enhanced-select" multiple="multiple" name="' . esc_attr($prefix . '[terms][' . $termKey . '][]') . '" data-placeholder="' . esc_attr__('Alle keuzes toegestaan', 'hb-ucs') . '">';
                foreach ((array) $options as $option) {
                    $option = (string) $option;
                    $label = taxonomy_exists($attributeName) ? (($term = get_term_by('slug', $option, $attributeName)) ? (string) $term->name : $option) : $option;
                    echo '<option value="' . esc_attr($option) . '" ' . selected(in_array(sanitize_title($option), $selectedValues, true), true, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select><small>' . esc_html__('Leeg betekent dat alle variaties voor dit kenmerk zijn toegestaan.', 'hb-ucs') . '</small></label>';
            }
        }
        foreach ($terms as $termKey => $values) {
            if (isset($rendered[$termKey])) {
                continue;
            }
            foreach ((array) $values as $value) {
                echo '<input type="hidden" name="' . esc_attr($prefix . '[terms][' . $termKey . '][]') . '" value="' . esc_attr((string) $value) . '" />';
            }
        }
    }

    private function number_field(string $name, string $label, $value, $min): void {
        echo '<label><span>' . esc_html($label) . '</span><input type="number" min="' . esc_attr($min) . '" step="any" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" /></label>';
    }

    private function text_field(string $name, string $label, $value, string $placeholder = ''): void {
        echo '<label><span>' . esc_html($label) . '</span><input type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr($placeholder) . '" /></label>';
    }
}
