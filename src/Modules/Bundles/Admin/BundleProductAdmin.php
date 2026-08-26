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
            'included' => __('Vast inbegrepen', 'hb-ucs'),
            'optional' => __('Keuzeonderdeel', 'hb-ucs'),
            'contentRow' => __('Tekst of tussenkop', 'hb-ucs'),
            'content' => __('Inhoud', 'hb-ucs'),
            'collapse' => __('Onderdeel inklappen', 'hb-ucs'),
            'expand' => __('Onderdeel uitklappen', 'hb-ucs'),
            'itemSingular' => __('onderdeel', 'hb-ucs'),
            'itemPlural' => __('onderdelen', 'hb-ucs'),
            'added' => __('Onderdeel toegevoegd.', 'hb-ucs'),
            'removed' => __('Onderdeel verwijderd.', 'hb-ucs'),
            'defaultQuantity' => __('Standaardaantal', 'hb-ucs'),
            'type' => __('Type', 'hb-ucs'),
            'minimum' => __('Minimum', 'hb-ucs'),
            'maximum' => __('Maximum', 'hb-ucs'),
            'customerTitle' => __('Klanttitel', 'hb-ucs'),
            'customerTitlePlaceholder' => __('Leeg gebruikt de productnaam', 'hb-ucs'),
            'badge' => __('Label', 'hb-ucs'),
            'badgePlaceholder' => __('Bijv. Meest gekozen', 'hb-ucs'),
            'group' => __('Groep', 'hb-ucs'),
            'groupPlaceholder' => __('Bijv. Basis of Extra’s', 'hb-ucs'),
            'customerDescription' => __('Uitleg voor klant', 'hb-ucs'),
            'format' => __('Opmaak', 'hb-ucs'),
            'text' => __('Tekst', 'hb-ucs'),
            'paragraph' => __('Alinea', 'hb-ucs'),
            'shortText' => __('Korte tekst', 'hb-ucs'),
            'noMarkup' => __('Geen extra opmaak', 'hb-ucs'),
        ]);
    }

    public function render_panel(): void {
        global $post, $product_object;
        $product = $product_object instanceof \WC_Product ? $product_object : wc_get_product($post ? $post->ID : 0);
        if (!$product) {
            return;
        }
        $items = BundleData::normalize_product_items($product);
        $itemCount = count($items);

        echo '<div id="hb_ucs_bundle_product_data" class="panel woocommerce_options_panel hidden">';
        wp_nonce_field('hb_ucs_save_bundle_product', 'hb_ucs_bundle_product_nonce');
        echo '<div class="options_group hb-ucs-bundle-builder">';
        echo '<header class="hb-ucs-bundle-builder__hero">';
        echo '<div class="hb-ucs-bundle-builder__hero-icon"><span class="dashicons dashicons-screenoptions" aria-hidden="true"></span></div>';
        echo '<div class="hb-ucs-bundle-builder__hero-copy"><span class="hb-ucs-bundle-builder__eyebrow">' . esc_html__('Bundelbouwer', 'hb-ucs') . '</span>';
        echo '<h3>' . esc_html__('Stel de inhoud stap voor stap samen', 'hb-ucs') . '</h3>';
        echo '<p>' . esc_html__('Voeg producten, variaties en verduidelijkende tekst toe. De volgorde hieronder is ook de volgorde die de klant ziet.', 'hb-ucs') . '</p></div>';
        echo '<div class="hb-ucs-bundle-builder__count" aria-live="polite"><strong>' . esc_html((string) $itemCount) . '</strong><span data-singular="' . esc_attr__('onderdeel', 'hb-ucs') . '" data-plural="' . esc_attr__('onderdelen', 'hb-ucs') . '">' . esc_html($itemCount === 1 ? __('onderdeel', 'hb-ucs') : __('onderdelen', 'hb-ucs')) . '</span></div>';
        echo '</header>';

        echo '<div class="hb-ucs-bundle-toolbar">';
        echo '<label class="hb-ucs-bundle-toolbar__search"><span>' . esc_html__('Producten zoeken', 'hb-ucs') . '</span>';
        echo '<select class="wc-product-search hb-ucs-bundle-search" multiple="multiple" data-placeholder="' . esc_attr__('Zoek op productnaam, SKU of variatie…', 'hb-ucs') . '" data-action="woocommerce_json_search_products_and_variations" data-exclude="' . esc_attr($product->get_id()) . '"></select></label>';
        echo '<div class="hb-ucs-bundle-toolbar__actions">';
        echo '<button type="button" class="button button-primary hb-ucs-bundle-add" disabled><span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>' . esc_html__('Product toevoegen', 'hb-ucs') . '</button>';
        echo '<button type="button" class="button hb-ucs-bundle-add-content"><span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span>' . esc_html__('Tekstregel toevoegen', 'hb-ucs') . '</button>';
        echo '</div></div>';
        echo '<div class="hb-ucs-bundle-help"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><p>' . esc_html__('Sleep onderdelen aan de handgreep in de gewenste volgorde. Voeg een vaste variatie rechtstreeks toe; gebruik een variabel product als de klant een keuze moet maken.', 'hb-ucs') . '</p></div>';
        echo '<p class="screen-reader-text hb-ucs-bundle-status" aria-live="polite"></p>';
        echo '<div class="hb-ucs-bundle-items" data-empty-text="' . esc_attr__('Nog geen onderdelen toegevoegd. Zoek hierboven een product of begin met een tekstregel.', 'hb-ucs') . '">';
        foreach ($items as $key => $item) {
            $this->render_item((string) $key, $item);
        }
        echo '</div></div>';

        echo '<div class="options_group hb-ucs-bundle-product-settings">';
        echo '<header class="hb-ucs-bundle-product-settings__header"><span class="hb-ucs-bundle-builder__eyebrow">' . esc_html__('Gedrag en presentatie', 'hb-ucs') . '</span><h3>' . esc_html__('Bundelinstellingen', 'hb-ucs') . '</h3><p>' . esc_html__('Prijs, grenzen en logistiek worden op de server opnieuw gecontroleerd wanneer de klant bestelt.', 'hb-ucs') . '</p></header>';
        echo '<div class="hb-ucs-bundle-settings-grid">';

        $this->settings_card_start(__('Prijs', 'hb-ucs'), __('Kies een vaste hoofdprijs of laat de onderdelen samen het totaal bepalen.', 'hb-ucs'), 'dashicons-money-alt');
        woocommerce_wp_checkbox(['id' => 'woosb_disable_auto_price', 'label' => __('Vaste bundelprijs', 'hb-ucs'), 'description' => __('Gebruik de normale WooCommerce-prijs van het hoofdproduct. De onderdelen krijgen in de bestelling prijs nul.', 'hb-ucs'), 'value' => $product->get_meta('woosb_disable_auto_price'), 'cbvalue' => 'on']);
        woocommerce_wp_text_input(['id' => 'woosb_discount', 'label' => __('Bundelkorting (%)', 'hb-ucs'), 'description' => __('Wordt alleen gebruikt bij een dynamische bundelprijs.', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'max' => '99.9999', 'step' => '0.0001'], 'value' => $product->get_meta('woosb_discount')]);
        woocommerce_wp_text_input(['id' => 'woosb_discount_amount', 'label' => __('Vaste bundelkorting', 'hb-ucs'), 'description' => __('Een vast bedrag heeft voorrang op de procentuele korting.', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '0.0001'], 'value' => $product->get_meta('woosb_discount_amount')]);
        $this->settings_card_end();

        $this->settings_card_start(__('Keuzegrenzen', 'hb-ucs'), __('Beperk het totaal aantal gekozen onderdelen of de uiteindelijke prijs.', 'hb-ucs'), 'dashicons-filter');
        woocommerce_wp_text_input(['id' => 'woosb_limit_whole_min', 'label' => __('Minimum aantal onderdelen', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'value' => $product->get_meta('woosb_limit_whole_min')]);
        woocommerce_wp_text_input(['id' => 'woosb_limit_whole_max', 'label' => __('Maximum aantal onderdelen', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '1'], 'value' => $product->get_meta('woosb_limit_whole_max')]);
        woocommerce_wp_checkbox(['id' => 'woosb_total_limits', 'label' => __('Prijsgrenzen gebruiken', 'hb-ucs'), 'description' => __('Controleert het berekende bundeltotaal vóór toevoegen aan de winkelmand.', 'hb-ucs'), 'value' => $product->get_meta('woosb_total_limits'), 'cbvalue' => 'on']);
        woocommerce_wp_text_input(['id' => 'woosb_total_limits_min', 'label' => __('Minimum bundeltotaal', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '0.01'], 'value' => $product->get_meta('woosb_total_limits_min')]);
        woocommerce_wp_text_input(['id' => 'woosb_total_limits_max', 'label' => __('Maximum bundeltotaal', 'hb-ucs'), 'type' => 'number', 'custom_attributes' => ['min' => '0', 'step' => '0.01'], 'value' => $product->get_meta('woosb_total_limits_max')]);
        $this->settings_card_end();

        $this->settings_card_start(__('Voorraad en verzending', 'hb-ucs'), __('Bepaal welke regels gewicht, verzendkosten en voorraad leveren.', 'hb-ucs'), 'dashicons-products');
        woocommerce_wp_select(['id' => 'woosb_shipping_fee', 'label' => __('Verzendberekening', 'hb-ucs'), 'value' => $product->get_meta('woosb_shipping_fee') ?: 'whole', 'options' => [
            'whole' => __('Alleen het hoofdproduct', 'hb-ucs'),
            'each' => __('Alleen de afzonderlijke onderdelen', 'hb-ucs'),
            'both' => __('Hoofdproduct en onderdelen', 'hb-ucs'),
        ]]);
        woocommerce_wp_checkbox(['id' => 'woosb_manage_stock', 'label' => __('Eigen bundelvoorraad beheren', 'hb-ucs'), 'description' => __('Uitgeschakeld: beschikbare voorraad wordt automatisch door de verplichte onderdelen bepaald.', 'hb-ucs'), 'value' => $product->get_meta('woosb_manage_stock'), 'cbvalue' => 'on']);
        $this->settings_card_end();

        $this->settings_card_start(__('Tekst en weergave', 'hb-ucs'), __('Overschrijf voor dit product de algemene vormgeving en uitleg.', 'hb-ucs'), 'dashicons-welcome-write-blog');
        woocommerce_wp_select(['id' => 'woosb_layout', 'label' => __('Weergave', 'hb-ucs'), 'value' => $product->get_meta('woosb_layout') ?: 'default', 'options' => [
            'default' => __('Algemene instelling', 'hb-ucs'),
            'cards' => __('Kaarten', 'hb-ucs'),
            'list' => __('Lijst', 'hb-ucs'),
        ]]);
        woocommerce_wp_text_input(['id' => 'woosb_section_title', 'label' => __('Kop samenstelling', 'hb-ucs'), 'placeholder' => __('Gebruik de algemene kop', 'hb-ucs'), 'value' => $product->get_meta('woosb_section_title')]);
        woocommerce_wp_textarea_input(['id' => 'woosb_before_text', 'label' => __('Uitleg boven de bundel', 'hb-ucs'), 'value' => $product->get_meta('woosb_before_text')]);
        woocommerce_wp_textarea_input(['id' => 'woosb_after_text', 'label' => __('Uitleg onder de bundel', 'hb-ucs'), 'value' => $product->get_meta('woosb_after_text')]);
        $this->settings_card_end();

        echo '</div></div></div>';
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
        $optional = !empty($item['optional']);
        $classes = ['hb-ucs-bundle-item'];
        if ($optional) {
            $classes[] = 'is-optional';
        }
        $sku = $product->get_sku();
        $productTypes = function_exists('wc_get_product_types') ? wc_get_product_types() : [];
        $typeLabel = isset($productTypes[$product->get_type()]) ? $productTypes[$product->get_type()] : ($product->is_type('variation') ? __('Variatie', 'hb-ucs') : ucfirst((string) $product->get_type()));

        echo '<article class="' . esc_attr(implode(' ', $classes)) . '" data-key="' . esc_attr($key) . '">';
        echo '<header class="hb-ucs-bundle-item__header">';
        echo '<span class="hb-ucs-bundle-handle dashicons dashicons-move" role="button" tabindex="0" aria-label="' . esc_attr__('Onderdeel verslepen', 'hb-ucs') . '"></span>';
        echo '<span class="hb-ucs-bundle-item__index" aria-hidden="true"></span>';
        echo '<span class="hb-ucs-bundle-item__thumb">' . wp_kses_post($product->get_image('woocommerce_thumbnail')) . '</span>';
        echo '<span class="hb-ucs-bundle-item__heading"><strong>' . wp_kses_post($title) . '</strong><span class="hb-ucs-bundle-item__meta">' . esc_html($typeLabel);
        if ($sku !== '') {
            echo ' <span aria-hidden="true">•</span> ' . esc_html(sprintf(__('SKU %s', 'hb-ucs'), $sku));
        }
        echo '</span></span>';
        echo '<span class="hb-ucs-bundle-item__state">' . esc_html($optional ? __('Keuzeonderdeel', 'hb-ucs') : __('Vast inbegrepen', 'hb-ucs')) . '</span>';
        echo '<button type="button" class="button-link hb-ucs-bundle-toggle" aria-expanded="true" aria-label="' . esc_attr__('Onderdeel inklappen', 'hb-ucs') . '"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>';
        echo '<button type="button" class="button-link-delete hb-ucs-bundle-remove"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Verwijderen', 'hb-ucs') . '</span></button>';
        echo '</header>';
        echo '<input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="' . esc_attr($product->get_id()) . '" />';
        echo '<input type="hidden" name="' . esc_attr($prefix . '[sku]') . '" value="' . esc_attr($sku) . '" />';
        echo '<div class="hb-ucs-bundle-item__body"><div class="hb-ucs-bundle-item-grid">';
        $this->number_field($prefix . '[qty]', __('Standaardaantal', 'hb-ucs'), $item['qty'] ?? 1, 0);
        echo '<label class="hb-ucs-bundle-optional"><span>' . esc_html__('Type', 'hb-ucs') . '</span><select name="' . esc_attr($prefix . '[optional]') . '"><option value="0" ' . selected(empty($item['optional']), true, false) . '>' . esc_html__('Vast inbegrepen', 'hb-ucs') . '</option><option value="1" ' . selected(!empty($item['optional']), true, false) . '>' . esc_html__('Keuzeonderdeel', 'hb-ucs') . '</option></select></label>';
        $this->number_field($prefix . '[min]', __('Minimum', 'hb-ucs'), $item['min'] ?? 0, 0);
        $this->number_field($prefix . '[max]', __('Maximum', 'hb-ucs'), $item['max'] ?? '', 0);
        $this->text_field($prefix . '[customer_title]', __('Klanttitel', 'hb-ucs'), $item['customer_title'] ?? '', __('Leeg gebruikt de productnaam', 'hb-ucs'));
        $this->text_field($prefix . '[badge]', __('Label', 'hb-ucs'), $item['badge'] ?? '', __('Bijv. Meest gekozen', 'hb-ucs'));
        $this->text_field($prefix . '[group]', __('Groep', 'hb-ucs'), $item['group'] ?? '', __('Bijv. Basis of Extra’s', 'hb-ucs'));
        echo '<label class="hb-ucs-bundle-wide"><span>' . esc_html__('Uitleg voor klant', 'hb-ucs') . '</span><textarea name="' . esc_attr($prefix . '[customer_description]') . '" rows="3">' . esc_textarea((string) ($item['customer_description'] ?? '')) . '</textarea><small>' . esc_html__('Houd deze uitleg kort; de klant ziet hem direct onder de productnaam.', 'hb-ucs') . '</small></label>';
        $this->render_variation_restrictions($prefix, $product, $item);
        echo '</div></div></article>';
    }

    private function render_content_item(string $key, array $item): void {
        $prefix = 'hb_ucs_bundle_items[' . $key . ']';
        $type = (string) ($item['type'] ?? 'p');
        echo '<article class="hb-ucs-bundle-item hb-ucs-bundle-content-item" data-key="' . esc_attr($key) . '">';
        echo '<header class="hb-ucs-bundle-item__header">';
        echo '<span class="hb-ucs-bundle-handle dashicons dashicons-move" role="button" tabindex="0" aria-label="' . esc_attr__('Tekstregel verslepen', 'hb-ucs') . '"></span>';
        echo '<span class="hb-ucs-bundle-item__index" aria-hidden="true"></span>';
        echo '<span class="hb-ucs-bundle-item__thumb hb-ucs-bundle-item__thumb--content"><span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span></span>';
        echo '<span class="hb-ucs-bundle-item__heading"><strong>' . esc_html__('Tekst of tussenkop', 'hb-ucs') . '</strong><span class="hb-ucs-bundle-item__meta">' . esc_html__('Verduidelijkt de samenstelling voor de klant', 'hb-ucs') . '</span></span>';
        echo '<span class="hb-ucs-bundle-item__state">' . esc_html__('Inhoud', 'hb-ucs') . '</span>';
        echo '<button type="button" class="button-link hb-ucs-bundle-toggle" aria-expanded="true" aria-label="' . esc_attr__('Onderdeel inklappen', 'hb-ucs') . '"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>';
        echo '<button type="button" class="button-link-delete hb-ucs-bundle-remove"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Verwijderen', 'hb-ucs') . '</span></button>';
        echo '</header>';
        echo '<input type="hidden" name="' . esc_attr($prefix . '[id]') . '" value="0" />';
        echo '<div class="hb-ucs-bundle-item__body"><div class="hb-ucs-bundle-item-grid">';
        echo '<label><span>' . esc_html__('Opmaak', 'hb-ucs') . '</span><select name="' . esc_attr($prefix . '[type]') . '">';
        foreach (['h1' => 'H1', 'h2' => 'H2', 'h3' => 'H3', 'h4' => 'H4', 'h5' => 'H5', 'h6' => 'H6', 'p' => __('Alinea', 'hb-ucs'), 'span' => __('Korte tekst', 'hb-ucs'), 'none' => __('Geen extra opmaak', 'hb-ucs')] as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($type, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></label>';
        echo '<label class="hb-ucs-bundle-wide"><span>' . esc_html__('Tekst', 'hb-ucs') . '</span><textarea name="' . esc_attr($prefix . '[text]') . '" rows="3">' . esc_textarea((string) ($item['text'] ?? '')) . '</textarea></label>';
        echo '</div></div></article>';
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
                echo '<label class="hb-ucs-bundle-wide hb-ucs-bundle-variation-limit"><span>' . sprintf(esc_html__('Toegestane keuzes: %s', 'hb-ucs'), esc_html(wc_attribute_label($attributeName, $product))) . '</span>';
                echo '<select class="wc-enhanced-select" multiple="multiple" name="' . esc_attr($prefix . '[terms][' . $termKey . '][]') . '" data-placeholder="' . esc_attr__('Alle keuzes toegestaan', 'hb-ucs') . '">';
                foreach ((array) $options as $option) {
                    $option = (string) $option;
                    $label = taxonomy_exists($attributeName) ? (($term = get_term_by('slug', $option, $attributeName)) ? (string) $term->name : $option) : $option;
                    echo '<option value="' . esc_attr($option) . '" ' . selected(in_array(sanitize_title($option), $selectedValues, true), true, false) . '>' . esc_html($label) . '</option>';
                }
                echo '</select><small>' . esc_html__('Laat dit veld leeg om alle variaties voor dit kenmerk toe te staan.', 'hb-ucs') . '</small></label>';
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

    private function settings_card_start(string $title, string $description, string $icon): void {
        echo '<section class="hb-ucs-bundle-settings-card">';
        echo '<header><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span><div><h4>' . esc_html($title) . '</h4><p>' . esc_html($description) . '</p></div></header>';
        echo '<div class="hb-ucs-bundle-settings-card__fields">';
    }

    private function settings_card_end(): void {
        echo '</div></section>';
    }

    private function number_field(string $name, string $label, $value, $min): void {
        echo '<label><span>' . esc_html($label) . '</span><input type="number" min="' . esc_attr($min) . '" step="any" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" /></label>';
    }

    private function text_field(string $name, string $label, $value, string $placeholder = ''): void {
        echo '<label><span>' . esc_html($label) . '</span><input type="text" name="' . esc_attr($name) . '" value="' . esc_attr((string) $value) . '" placeholder="' . esc_attr($placeholder) . '" /></label>';
    }
}
