<?php
// =============================
// src/Modules/OrderOverviewStatus/Admin/OrderOverviewStatusAdminPage.php
// =============================
namespace HB\UCS\Modules\OrderOverviewStatus\Admin;

use HB\UCS\Core\Settings as CoreSettings;
use HB\UCS\Modules\OrderOverviewStatus\Storage\SettingsStore;
use HB\UCS\Modules\OrderOverviewStatus\Support\Permissions;

if (!defined('ABSPATH')) exit;

class OrderOverviewStatusAdminPage {
    private SettingsStore $settings;

    public function __construct() {
        $this->settings = new SettingsStore();
    }

    public function register_handlers(): void {
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_post_hb_ucs_order_overview_status_save_settings', [$this, 'handle_save_settings']);
    }

    public function enqueue_assets(string $hook): void {
        if (!isset($_GET['page']) || (string) $_GET['page'] !== 'hb-ucs-order-overview-status') {
            return;
        }

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 5) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/Modules/OrderOverviewStatus/assets/', $pluginFile));
        $ver = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';

        wp_enqueue_style('hb-ucs-order-overview-status-admin', $base . 'admin-order-overview-status.css', [], $ver);
        wp_enqueue_style('dashicons');
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_script('hb-ucs-order-overview-status-admin', $base . 'admin-order-overview-status.js', ['jquery', 'jquery-ui-sortable'], $ver, true);
        wp_localize_script('hb-ucs-order-overview-status-admin', 'HB_UCS_ORDER_OVERVIEW_STATUS_ADMIN', [
            'mode' => 'settings',
            'palette' => $this->settings->get_color_palette(),
            'i18n' => [
                'remove' => __('Verwijderen', 'hb-ucs'),
                'emptyLabel' => __('Nieuwe status', 'hb-ucs'),
                'emptySlugHint' => __('Laat leeg voor automatische slug', 'hb-ucs'),
                'dragToSort' => __('Sleep om volgorde te wijzigen', 'hb-ucs'),
            ],
        ]);
    }

    public function render(): void {
        if (!Permissions::can_manage_module()) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        $main = get_option(CoreSettings::OPT, []);
        $enabled = !empty(($main['modules'] ?? [])['order_overview_status']);
        $settings = $this->settings->get();
        $statuses = $this->settings->get_statuses();
        $palette = $this->settings->get_color_palette();

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Besteloverzicht statussen', 'hb-ucs') . '</h1>';
        echo '<p class="description">' . esc_html__('Beheer hier extra interne statussen die als losse dropdownkolom in het WooCommerce bestellingenoverzicht verschijnen.', 'hb-ucs') . '</p>';

        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is vereist voor deze module.', 'hb-ucs') . '</p></div>';
            echo '</div>';
            return;
        }

        if (!$enabled) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Deze module is uitgeschakeld. Activeer hem hieronder of via HB UCS → Modules.', 'hb-ucs') . '</p></div>';
        }

        $this->render_notices();

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_order_overview_status_save_settings" />';
        wp_nonce_field('hb_ucs_order_overview_status_save_settings', 'hb_ucs_order_overview_status_nonce');

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">' . esc_html__('Module actief', 'hb-ucs') . '</th><td>';
        echo '<label><input type="checkbox" name="hb_ucs_main_modules[order_overview_status]" value="1" ' . checked($enabled, true, false) . ' /> ' . esc_html__('Activeer deze module', 'hb-ucs') . '</label>';
        echo '<p class="description">' . esc_html__('Als actief verschijnt in het WooCommerce bestellingenoverzicht een extra kolom met direct bewerkbare dropdownstatus.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Beschikbare statussen', 'hb-ucs') . '</th><td>';
        echo '<p class="description">' . sprintf(esc_html__('Elke status krijgt een label, slug en één vaste WooCommerce-kleur. Laat de slug leeg om die automatisch uit het label te laten genereren. Het label mag maximaal %d tekens bevatten.', 'hb-ucs'), SettingsStore::MAX_LABEL_LENGTH) . '</p>';
        echo '<table class="widefat striped" style="max-width:900px;">';
        echo '<thead><tr>';
        echo '<th style="width:6%;">' . esc_html__('Volgorde', 'hb-ucs') . '</th>';
        echo '<th style="width:28%;">' . esc_html__('Label', 'hb-ucs') . '</th>';
        echo '<th style="width:26%;">' . esc_html__('Slug', 'hb-ucs') . '</th>';
        echo '<th style="width:26%;">' . esc_html__('Kleur', 'hb-ucs') . '</th>';
        echo '<th style="width:14%;">' . esc_html__('Actie', 'hb-ucs') . '</th>';
        echo '</tr></thead>';
        echo '<tbody id="hb-ucs-order-overview-status-rows">';

        if (empty($statuses)) {
            $statuses = [
                [
                    'id' => '',
                    'label' => '',
                    'color' => SettingsStore::DEFAULT_COLOR,
                ],
            ];
        }

        foreach ($statuses as $index => $status) {
            $this->render_status_row((int) $index, (string) ($status['label'] ?? ''), (string) ($status['id'] ?? ''), (string) ($status['color'] ?? SettingsStore::DEFAULT_COLOR), $palette);
        }

        echo '</tbody>';
        echo '</table>';
        echo '<p><button type="button" class="button" id="hb-ucs-add-order-overview-status">' . esc_html__('Status toevoegen', 'hb-ucs') . '</button></p>';
        echo '<script type="text/template" id="tmpl-hb-ucs-order-overview-status-row">';
        $this->render_status_row('__index__', '', '', SettingsStore::DEFAULT_COLOR, $palette);
        echo '</script>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Uninstall cleanup', 'hb-ucs') . '</th><td>';
        echo '<label><input type="checkbox" name="order_overview_status_settings[delete_data_on_uninstall]" value="1" ' . checked(!empty($settings['delete_data_on_uninstall']), true, false) . ' /> ' . esc_html__('Bij verwijderen ook module-instellingen en opgeslagen overzichtsstatussen verwijderen', 'hb-ucs') . '</label>';
        echo '</td></tr>';

        echo '</table>';

        submit_button(__('Opslaan', 'hb-ucs'));
        echo '</form>';
        echo '</div>';
    }

    public function handle_save_settings(): void {
        if (!Permissions::can_manage_module()) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        check_admin_referer('hb_ucs_order_overview_status_save_settings', 'hb_ucs_order_overview_status_nonce');

        $rawSettings = isset($_POST['order_overview_status_settings']) && is_array($_POST['order_overview_status_settings'])
            ? $_POST['order_overview_status_settings']
            : [];

        $this->settings->update($rawSettings);
        $this->update_module_toggle();

        $redirect = add_query_arg([
            'page' => 'hb-ucs-order-overview-status',
            'updated' => 'true',
        ], admin_url('admin.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    private function render_notices(): void {
        if (empty($_GET['updated'])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Instellingen opgeslagen.', 'hb-ucs') . '</p></div>';
    }

    /**
     * @param int|string $index
     * @param array<string,array{label:string,background:string,text:string}> $palette
     */
    private function render_status_row($index, string $label, string $id, string $color, array $palette): void {
        $palette = !empty($palette) ? $palette : $this->settings->get_color_palette();
        if (!isset($palette[$color])) {
            $color = SettingsStore::DEFAULT_COLOR;
        }

        $preview = $palette[$color];

        echo '<tr class="hb-ucs-order-overview-status-row">';
        echo '<td class="hb-ucs-order-overview-status-sort">';
        echo '<button type="button" class="button-link hb-ucs-order-overview-status-handle" aria-label="' . esc_attr__('Sleep om volgorde te wijzigen', 'hb-ucs') . '" title="' . esc_attr__('Sleep om volgorde te wijzigen', 'hb-ucs') . '">';
        echo '<span class="dashicons dashicons-menu"></span>';
        echo '</button>';
        echo '</td>';
        echo '<td><input type="text" class="regular-text" maxlength="' . esc_attr((string) SettingsStore::MAX_LABEL_LENGTH) . '" name="order_overview_status_settings[statuses][' . esc_attr((string) $index) . '][label]" value="' . esc_attr($label) . '" placeholder="' . esc_attr__('Bijvoorbeeld: In productie', 'hb-ucs') . '" /></td>';
        echo '<td><input type="text" class="regular-text code" name="order_overview_status_settings[statuses][' . esc_attr((string) $index) . '][id]" value="' . esc_attr($id) . '" placeholder="' . esc_attr__('Automatisch genereren', 'hb-ucs') . '" /></td>';
        echo '<td>';
        echo '<div class="hb-ucs-order-overview-status-color-field">';
        echo '<select class="hb-ucs-order-overview-status-color-select" name="order_overview_status_settings[statuses][' . esc_attr((string) $index) . '][color]">';
        foreach ($palette as $paletteKey => $paletteItem) {
            echo '<option value="' . esc_attr($paletteKey) . '" data-background="' . esc_attr($paletteItem['background']) . '" data-text="' . esc_attr($paletteItem['text']) . '" ' . selected($color, $paletteKey, false) . '>' . esc_html($paletteItem['label']) . '</option>';
        }
        echo '</select>';
        echo '<span class="hb-ucs-order-overview-status-color-preview" style="--hb-ucs-preview-bg:' . esc_attr($preview['background']) . ';--hb-ucs-preview-text:' . esc_attr($preview['text']) . ';">' . esc_html__('Voorbeeld', 'hb-ucs') . '</span>';
        echo '</div>';
        echo '</td>';
        echo '<td><button type="button" class="button-link-delete hb-ucs-remove-order-overview-status">' . esc_html__('Verwijderen', 'hb-ucs') . '</button></td>';
        echo '</tr>';
    }

    private function update_module_toggle(): void {
        $main = get_option(CoreSettings::OPT, []);
        if (!is_array($main)) {
            $main = [];
        }

        if (!isset($main['modules']) || !is_array($main['modules'])) {
            $main['modules'] = [];
        }

        $enabled = !empty($_POST['hb_ucs_main_modules']['order_overview_status']);
        $main['modules']['order_overview_status'] = $enabled ? 1 : 0;

        update_option(CoreSettings::OPT, $main, false);
    }
}
