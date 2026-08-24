<?php
namespace HB\UCS\Modules\Bundles\Admin;

if (!defined('ABSPATH')) exit;

final class BundleSettings {
    public const OPTION = 'hb_ucs_bundles_settings';

    public static function defaults(): array {
        return [
            'layout' => 'cards',
            'show_images' => 1,
            'show_descriptions' => 1,
            'show_prices' => 1,
            'show_stock' => 1,
            'allow_cart_edit' => 1,
            'hide_children_cart' => 0,
            'hide_children_order' => 0,
            'section_title' => __('Dit pakket bevat', 'hb-ucs'),
            'required_text' => __('Inbegrepen', 'hb-ucs'),
            'optional_text' => __('Zelf te kiezen', 'hb-ucs'),
            'summary_title' => __('Jouw samenstelling', 'hb-ucs'),
            'total_text' => __('Totaal', 'hb-ucs'),
            'add_button_text' => __('Bundel toevoegen', 'hb-ucs'),
            'delete_data_on_uninstall' => 0,
        ];
    }

    public static function get(): array {
        $stored = get_option(self::OPTION, []);
        return array_replace(self::defaults(), is_array($stored) ? $stored : []);
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'menu'], 30);
        add_action('admin_post_hb_ucs_save_bundles', [$this, 'save']);
    }

    public function menu(): void {
        add_submenu_page(
            'hb-ucs',
            __('Productbundels', 'hb-ucs'),
            __('Productbundels', 'hb-ucs'),
            'manage_woocommerce',
            'hb-ucs-bundles',
            [$this, 'render']
        );
    }

    public function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        $settings = self::get();
        echo '<div class="wrap hb-ucs-bundles-settings"><h1>' . esc_html__('Productbundels', 'hb-ucs') . '</h1>';
        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instellingen opgeslagen.', 'hb-ucs') . '</p></div>';
        }
        echo '<p>' . esc_html__('Deze instellingen zijn de standaard voor alle bundels. Per product kunnen teksten en de weergave worden overschreven.', 'hb-ucs') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_save_bundles" />';
        wp_nonce_field('hb_ucs_save_bundles', 'hb_ucs_bundles_nonce');
        echo '<table class="form-table" role="presentation"><tbody>';
        $this->select_row('layout', __('Standaardweergave', 'hb-ucs'), $settings, [
            'cards' => __('Duidelijke kaarten', 'hb-ucs'),
            'list' => __('Compacte lijst', 'hb-ucs'),
        ]);
        foreach ([
            'show_images' => __('Productafbeeldingen tonen', 'hb-ucs'),
            'show_descriptions' => __('Uitleg per onderdeel tonen', 'hb-ucs'),
            'show_prices' => __('Prijs per onderdeel tonen', 'hb-ucs'),
            'show_stock' => __('Voorraadstatus tonen', 'hb-ucs'),
            'allow_cart_edit' => __('Samenstelling vanuit winkelmand wijzigen', 'hb-ucs'),
            'hide_children_cart' => __('Onderliggende regels in winkelmand verbergen', 'hb-ucs'),
            'hide_children_order' => __('Onderliggende regels in bestelling verbergen', 'hb-ucs'),
        ] as $key => $label) {
            $this->checkbox_row($key, $label, $settings);
        }
        foreach ([
            'section_title' => __('Kop boven samenstelling', 'hb-ucs'),
            'required_text' => __('Label verplicht onderdeel', 'hb-ucs'),
            'optional_text' => __('Label keuzeonderdeel', 'hb-ucs'),
            'summary_title' => __('Kop samenvatting', 'hb-ucs'),
            'total_text' => __('Label totaalprijs', 'hb-ucs'),
            'add_button_text' => __('Tekst bestelknop', 'hb-ucs'),
        ] as $key => $label) {
            $this->text_row($key, $label, $settings);
        }
        $this->checkbox_row('delete_data_on_uninstall', __('Bundelinstellingen verwijderen bij de-installatie', 'hb-ucs'), $settings);
        echo '</tbody></table>';
        submit_button(__('Instellingen opslaan', 'hb-ucs'));
        echo '</form></div>';
    }

    public function save(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        check_admin_referer('hb_ucs_save_bundles', 'hb_ucs_bundles_nonce');
        $raw = isset($_POST['hb_ucs_bundles']) && is_array($_POST['hb_ucs_bundles']) ? wp_unslash($_POST['hb_ucs_bundles']) : [];
        $defaults = self::defaults();
        $clean = $defaults;
        $clean['layout'] = in_array((string) ($raw['layout'] ?? ''), ['cards', 'list'], true) ? (string) $raw['layout'] : 'cards';
        foreach (['show_images', 'show_descriptions', 'show_prices', 'show_stock', 'allow_cart_edit', 'hide_children_cart', 'hide_children_order', 'delete_data_on_uninstall'] as $key) {
            $clean[$key] = empty($raw[$key]) ? 0 : 1;
        }
        foreach (['section_title', 'required_text', 'optional_text', 'summary_title', 'total_text', 'add_button_text'] as $key) {
            $value = sanitize_text_field((string) ($raw[$key] ?? ''));
            $clean[$key] = $value !== '' ? $value : $defaults[$key];
        }
        update_option(self::OPTION, $clean, false);
        wp_safe_redirect(add_query_arg(['page' => 'hb-ucs-bundles', 'updated' => '1'], admin_url('admin.php')));
        exit;
    }

    private function checkbox_row(string $key, string $label, array $settings): void {
        echo '<tr><th scope="row">' . esc_html($label) . '</th><td><label><input type="checkbox" name="hb_ucs_bundles[' . esc_attr($key) . ']" value="1" ' . checked(!empty($settings[$key]), true, false) . ' /> ' . esc_html__('Ingeschakeld', 'hb-ucs') . '</label></td></tr>';
    }

    private function text_row(string $key, string $label, array $settings): void {
        echo '<tr><th scope="row"><label for="hb-ucs-bundles-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input class="regular-text" id="hb-ucs-bundles-' . esc_attr($key) . '" type="text" name="hb_ucs_bundles[' . esc_attr($key) . ']" value="' . esc_attr((string) ($settings[$key] ?? '')) . '" /></td></tr>';
    }

    private function select_row(string $key, string $label, array $settings, array $options): void {
        echo '<tr><th scope="row"><label for="hb-ucs-bundles-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><select id="hb-ucs-bundles-' . esc_attr($key) . '" name="hb_ucs_bundles[' . esc_attr($key) . ']">';
        foreach ($options as $value => $name) {
            echo '<option value="' . esc_attr($value) . '" ' . selected((string) ($settings[$key] ?? ''), $value, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select></td></tr>';
    }
}
