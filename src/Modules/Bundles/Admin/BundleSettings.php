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
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
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

    public function enqueue_assets(): void {
        $page = isset($_GET['page']) ? sanitize_key((string) wp_unslash($_GET['page'])) : '';
        if ($page !== 'hb-ucs-bundles') {
            return;
        }
        $base = trailingslashit(plugins_url('src/Modules/Bundles/assets/', HB_UCS_PLUGIN_FILE));
        $version = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';
        wp_enqueue_style('hb-ucs-bundles-admin', $base . 'admin-hb-ucs-bundles.css', [], $version);
    }

    public function render(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        $settings = self::get();
        echo '<div class="wrap hb-ucs-bundles-settings">';
        echo '<header class="hb-ucs-bundles-settings__hero">';
        echo '<div><span class="hb-ucs-bundles-settings__eyebrow">' . esc_html__('HB Commerce Suite', 'hb-ucs') . '</span>';
        echo '<h1>' . esc_html__('Productbundels', 'hb-ucs') . '</h1>';
        echo '<p>' . esc_html__('Bepaal hier de standaard presentatie en klantteksten. Een afwijkende instelling op een product blijft altijd leidend.', 'hb-ucs') . '</p></div>';
        echo '<div class="hb-ucs-bundles-settings__hero-icon dashicons dashicons-screenoptions" aria-hidden="true"></div>';
        echo '</header>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instellingen opgeslagen.', 'hb-ucs') . '</p></div>';
        }

        echo '<form class="hb-ucs-bundles-settings__form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_save_bundles" />';
        wp_nonce_field('hb_ucs_save_bundles', 'hb_ucs_bundles_nonce');
        echo '<div class="hb-ucs-bundles-settings__grid">';

        $this->section_start(
            __('Presentatie', 'hb-ucs'),
            __('Kies hoeveel informatie een klant direct bij ieder bundelonderdeel ziet.', 'hb-ucs'),
            'dashicons-layout'
        );
        $this->select_row('layout', __('Standaardweergave', 'hb-ucs'), __('Kaarten geven ieder onderdeel extra aandacht; de lijst is compacter.', 'hb-ucs'), $settings, [
            'cards' => __('Duidelijke kaarten', 'hb-ucs'),
            'list' => __('Compacte lijst', 'hb-ucs'),
        ]);
        $this->checkbox_row('show_images', __('Productafbeeldingen tonen', 'hb-ucs'), __('Helpt klanten producten sneller herkennen.', 'hb-ucs'), $settings);
        $this->checkbox_row('show_descriptions', __('Uitleg per onderdeel tonen', 'hb-ucs'), __('Toont de eigen klanttekst of de korte productomschrijving.', 'hb-ucs'), $settings);
        $this->checkbox_row('show_prices', __('Prijs per onderdeel tonen', 'hb-ucs'), __('Laat de actuele componentprijs naast het onderdeel zien.', 'hb-ucs'), $settings);
        $this->checkbox_row('show_stock', __('Voorraadstatus tonen', 'hb-ucs'), __('Maakt direct duidelijk wanneer een keuze niet leverbaar is.', 'hb-ucs'), $settings);
        $this->section_end();

        $this->section_start(
            __('Winkelmand en bestelling', 'hb-ucs'),
            __('Bepaal hoe de bundel en de onderliggende productregels voor klanten worden weergegeven.', 'hb-ucs'),
            'dashicons-cart'
        );
        $this->checkbox_row('allow_cart_edit', __('Samenstelling vanuit winkelmand wijzigen', 'hb-ucs'), __('Voegt een duidelijke bewerklink toe aan de hoofdregel van de bundel.', 'hb-ucs'), $settings);
        $this->checkbox_row('hide_children_cart', __('Onderliggende regels in winkelmand verbergen', 'hb-ucs'), __('Toont alleen de hoofdregel. Voor voorraad en berekening blijven onderdelen bestaan.', 'hb-ucs'), $settings);
        $this->checkbox_row('hide_children_order', __('Onderliggende regels in bestelling verbergen', 'hb-ucs'), __('Verbergt losse onderdelen in klantgerichte besteloverzichten.', 'hb-ucs'), $settings);
        $this->section_end();

        $this->section_start(
            __('Klantteksten', 'hb-ucs'),
            __('Gebruik korte, concrete labels. Deze teksten vormen samen de uitleg op de productpagina.', 'hb-ucs'),
            'dashicons-editor-textcolor'
        );
        foreach ([
            'section_title' => [__('Kop boven samenstelling', 'hb-ucs'), __('Bijvoorbeeld: Dit pakket bevat.', 'hb-ucs')],
            'required_text' => [__('Label verplicht onderdeel', 'hb-ucs'), __('Geeft aan dat een onderdeel standaard in de bundel zit.', 'hb-ucs')],
            'optional_text' => [__('Label keuzeonderdeel', 'hb-ucs'), __('Geeft aan dat de klant het aantal zelf bepaalt.', 'hb-ucs')],
            'summary_title' => [__('Kop samenvatting', 'hb-ucs'), __('De titel boven de live samenvatting van de keuze.', 'hb-ucs')],
            'total_text' => [__('Label totaalprijs', 'hb-ucs'), __('Het label direct naast de berekende bundelprijs.', 'hb-ucs')],
            'add_button_text' => [__('Tekst bestelknop', 'hb-ucs'), __('Houd de knoptekst kort en actiegericht.', 'hb-ucs')],
        ] as $key => $definition) {
            $this->text_row($key, $definition[0], $definition[1], $settings);
        }
        $this->section_end();

        $this->section_start(
            __('Gegevensbeheer', 'hb-ucs'),
            __('Deze instelling heeft alleen effect wanneer de volledige plugin wordt verwijderd.', 'hb-ucs'),
            'dashicons-database',
            true
        );
        $this->checkbox_row(
            'delete_data_on_uninstall',
            __('Bundelinstellingen verwijderen bij de-installatie', 'hb-ucs'),
            __('Product- en ordergegevens blijven behouden; alleen de algemene module-instelling wordt verwijderd.', 'hb-ucs'),
            $settings
        );
        $this->section_end();

        echo '</div>';
        echo '<div class="hb-ucs-bundles-settings__save">';
        echo '<p>' . esc_html__('Wijzigingen gelden direct voor alle bundels die geen eigen overschrijving gebruiken.', 'hb-ucs') . '</p>';
        submit_button(__('Instellingen opslaan', 'hb-ucs'), 'primary', 'submit', false);
        echo '</div></form></div>';
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

    private function section_start(string $title, string $description, string $icon, bool $danger = false): void {
        $classes = ['hb-ucs-bundles-settings__card'];
        if ($danger) {
            $classes[] = 'is-danger';
        }
        echo '<section class="' . esc_attr(implode(' ', $classes)) . '">';
        echo '<header><span class="dashicons ' . esc_attr($icon) . '" aria-hidden="true"></span><div><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p></div></header>';
        echo '<div class="hb-ucs-bundles-settings__fields">';
    }

    private function section_end(): void {
        echo '</div></section>';
    }

    private function checkbox_row(string $key, string $label, string $description, array $settings): void {
        $id = 'hb-ucs-bundles-' . $key;
        echo '<div class="hb-ucs-bundles-settings__field hb-ucs-bundles-settings__field--toggle">';
        echo '<div><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><p>' . esc_html($description) . '</p></div>';
        echo '<label class="hb-ucs-switch" for="' . esc_attr($id) . '">';
        echo '<input id="' . esc_attr($id) . '" type="checkbox" name="hb_ucs_bundles[' . esc_attr($key) . ']" value="1" ' . checked(!empty($settings[$key]), true, false) . ' />';
        echo '<span class="hb-ucs-switch__track" aria-hidden="true"><span></span></span>';
        echo '<span class="screen-reader-text">' . esc_html($label) . '</span></label></div>';
    }

    private function text_row(string $key, string $label, string $description, array $settings): void {
        $id = 'hb-ucs-bundles-' . $key;
        echo '<div class="hb-ucs-bundles-settings__field hb-ucs-bundles-settings__field--text">';
        echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        echo '<input class="regular-text" id="' . esc_attr($id) . '" type="text" name="hb_ucs_bundles[' . esc_attr($key) . ']" value="' . esc_attr((string) ($settings[$key] ?? '')) . '" />';
        echo '<p>' . esc_html($description) . '</p></div>';
    }

    private function select_row(string $key, string $label, string $description, array $settings, array $options): void {
        $id = 'hb-ucs-bundles-' . $key;
        echo '<div class="hb-ucs-bundles-settings__field hb-ucs-bundles-settings__field--select">';
        echo '<label for="' . esc_attr($id) . '">' . esc_html($label) . '</label>';
        echo '<select id="' . esc_attr($id) . '" name="hb_ucs_bundles[' . esc_attr($key) . ']">';
        foreach ($options as $value => $name) {
            echo '<option value="' . esc_attr($value) . '" ' . selected((string) ($settings[$key] ?? ''), $value, false) . '>' . esc_html($name) . '</option>';
        }
        echo '</select><p>' . esc_html($description) . '</p></div>';
    }
}
