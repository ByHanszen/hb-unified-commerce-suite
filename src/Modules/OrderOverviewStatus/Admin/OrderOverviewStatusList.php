<?php
// =============================
// src/Modules/OrderOverviewStatus/Admin/OrderOverviewStatusList.php
// =============================
namespace HB\UCS\Modules\OrderOverviewStatus\Admin;

use HB\UCS\Modules\OrderOverviewStatus\Storage\SettingsStore;
use HB\UCS\Modules\OrderOverviewStatus\Support\Permissions;

if (!defined('ABSPATH')) exit;

class OrderOverviewStatusList {
    private const COLUMN_KEY = 'hb_ucs_order_overview_status';
    private const META_KEY = '_hb_ucs_order_overview_status';
    private const FILTER_PARAM = 'hb_ucs_order_overview_status';

    private SettingsStore $settings;

    public function __construct(SettingsStore $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        add_filter('manage_edit-shop_order_columns', [$this, 'filter_columns'], 25);
        add_action('manage_shop_order_posts_custom_column', [$this, 'render_legacy_column'], 25, 2);
        add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'filter_columns'], 25);
        add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_hpos_column'], 25, 2);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'render_hpos_filters'], 25, 2);
        add_filter('woocommerce_order_query_args', [$this, 'filter_hpos_order_query_args'], 25, 1);
        add_action('restrict_manage_posts', [$this, 'render_legacy_filters'], 25, 1);
        add_action('pre_get_posts', [$this, 'filter_legacy_order_query'], 25);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_hb_ucs_save_order_overview_status', [$this, 'ajax_save_status']);
    }

    /**
     * @param array<string,string> $columns
     * @return array<string,string>
     */
    public function filter_columns(array $columns): array {
        $newColumns = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            $newColumns[$key] = $label;

            if ($key === 'order_status') {
                $newColumns[self::COLUMN_KEY] = __('Extra status', 'hb-ucs');
                $inserted = true;
            }
        }

        if (!$inserted) {
            $newColumns[self::COLUMN_KEY] = __('Extra status', 'hb-ucs');
        }

        return $newColumns;
    }

    public function render_legacy_column(string $column, int $post_id): void {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        $order = wc_get_order($post_id);
        if (!$order) {
            echo '&mdash;';
            return;
        }

        $this->render_select($order);
    }

    public function render_hpos_column(string $column, $order): void {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        if (!($order instanceof \WC_Order)) {
            echo '&mdash;';
            return;
        }

        $this->render_select($order);
    }

    public function render_hpos_filters(string $orderType, string $which): void {
        if ($which !== 'top' || $orderType !== 'shop_order') {
            return;
        }

        $this->render_filter_dropdown();
    }

    public function render_legacy_filters(string $postType): void {
        if ($postType !== 'shop_order') {
            return;
        }

        $this->render_filter_dropdown();
    }

    public function enqueue_assets(string $hook): void {
        if (!Permissions::can_edit_orders()) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || empty($screen->id)) {
            return;
        }

        if ($screen->id !== 'edit-shop_order' && $screen->id !== 'woocommerce_page_wc-orders') {
            return;
        }

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 5) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/Modules/OrderOverviewStatus/assets/', $pluginFile));
        $ver = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';

        wp_enqueue_style('hb-ucs-order-overview-status-admin', $base . 'admin-order-overview-status.css', [], $ver);
        wp_enqueue_script('hb-ucs-order-overview-status-admin', $base . 'admin-order-overview-status.js', ['jquery'], $ver, true);
        wp_localize_script('hb-ucs-order-overview-status-admin', 'HB_UCS_ORDER_OVERVIEW_STATUS_ADMIN', [
            'mode' => 'orders',
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hb_ucs_save_order_overview_status'),
            'filterParam' => self::FILTER_PARAM,
            'palette' => $this->settings->get_color_palette(),
            'statusColors' => $this->settings->get_status_color_map(),
            'i18n' => [
                'saving' => __('Opslaan…', 'hb-ucs'),
                'saved' => __('Opgeslagen.', 'hb-ucs'),
                'failed' => __('Opslaan mislukt.', 'hb-ucs'),
            ],
        ]);
    }

    public function ajax_save_status(): void {
        if (!Permissions::can_edit_orders()) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }

        check_ajax_referer('hb_ucs_save_order_overview_status', 'nonce');

        $orderId = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if ($orderId <= 0) {
            wp_send_json_error(['message' => 'missing_order_id'], 400);
        }

        $order = wc_get_order($orderId);
        if (!$order) {
            wp_send_json_error(['message' => 'order_not_found'], 404);
        }

        $rawStatus = isset($_POST['status']) ? wp_unslash((string) $_POST['status']) : '';
        $status = sanitize_title($rawStatus);
        $options = $this->settings->get_status_options();

        if ($status !== '' && !isset($options[$status])) {
            wp_send_json_error(['message' => 'invalid_status'], 400);
        }

        if ($status === '') {
            $order->delete_meta_data(self::META_KEY);
        } else {
            $order->update_meta_data(self::META_KEY, $status);
        }

        $order->save();

        wp_send_json_success([
            'order_id' => $orderId,
            'status' => $status,
            'label' => $status !== '' ? (string) $options[$status] : '',
        ]);
    }

    /**
     * @param array<string,mixed> $queryArgs
     * @return array<string,mixed>
     */
    public function filter_hpos_order_query_args(array $queryArgs): array {
        $status = $this->get_active_filter_status();
        if ($status === '' || !$this->is_hpos_orders_screen()) {
            return $queryArgs;
        }

        $metaQuery = isset($queryArgs['meta_query']) && is_array($queryArgs['meta_query']) ? $queryArgs['meta_query'] : [];
        $metaQuery[] = [
            'key' => self::META_KEY,
            'value' => $status,
            'compare' => '=',
        ];
        $queryArgs['meta_query'] = $metaQuery;

        return $queryArgs;
    }

    public function filter_legacy_order_query($query): void {
        if (!($query instanceof \WP_Query) || !is_admin() || !$query->is_main_query()) {
            return;
        }

        $status = $this->get_active_filter_status();
        if ($status === '') {
            return;
        }

        $postType = $query->get('post_type');
        if ($postType !== 'shop_order') {
            return;
        }

        $metaQuery = $query->get('meta_query');
        if (!is_array($metaQuery)) {
            $metaQuery = [];
        }

        $metaQuery[] = [
            'key' => self::META_KEY,
            'value' => $status,
            'compare' => '=',
        ];

        $query->set('meta_query', $metaQuery);
    }

    private function render_filter_dropdown(): void {
        $options = $this->settings->get_status_options();
        if (empty($options)) {
            return;
        }

        $current = $this->get_active_filter_status();

        echo '<select name="' . esc_attr(self::FILTER_PARAM) . '">';
        echo '<option value="">' . esc_html__('Filter extra status', 'hb-ucs') . '</option>';
        foreach ($options as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    private function get_active_filter_status(): string {
        $status = isset($_GET[self::FILTER_PARAM]) ? sanitize_title(wp_unslash((string) $_GET[self::FILTER_PARAM])) : '';
        if ($status === '') {
            return '';
        }

        $options = $this->settings->get_status_options();

        return isset($options[$status]) ? $status : '';
    }

    private function is_hpos_orders_screen(): bool {
        if (!is_admin()) {
            return false;
        }

        if (isset($_GET['page']) && (string) $_GET['page'] === 'wc-orders') {
            $orderType = isset($_GET['type']) ? sanitize_key((string) $_GET['type']) : 'shop_order';
            return $orderType === 'shop_order';
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || empty($screen->id)) {
            return false;
        }

        return $screen->id === 'woocommerce_page_wc-orders';
    }

    private function render_select(\WC_Order $order): void {
        $payload = $this->settings->get_status_payload();
        if (empty($payload)) {
            echo '<span class="description">' . esc_html__('Geen statussen ingesteld', 'hb-ucs') . '</span>';
            return;
        }

        $palette = $this->settings->get_color_palette();
        $current = (string) $order->get_meta(self::META_KEY, true);
        $selectOptions = $payload;
        if ($current !== '' && !isset($selectOptions[$current])) {
            $selectOptions = [$current => [
                'label' => $current,
                'color' => SettingsStore::DEFAULT_COLOR,
            ]] + $selectOptions;
        }

        echo '<div class="hb-ucs-order-overview-status-cell">';
        echo '<select class="hb-ucs-order-overview-status-select" data-order-id="' . esc_attr((string) $order->get_id()) . '" data-previous="' . esc_attr($current) . '" aria-label="' . esc_attr__('Extra orderstatus', 'hb-ucs') . '">';
        echo '<option value="">' . esc_html__('— Geen —', 'hb-ucs') . '</option>';
        foreach ($selectOptions as $value => $item) {
            $label = (string) ($item['label'] ?? $value);
            $color = (string) ($item['color'] ?? SettingsStore::DEFAULT_COLOR);
            $optionStyle = '';
            if (isset($palette[$color])) {
                $optionStyle = 'background:' . $palette[$color]['background'] . ';color:' . $palette[$color]['text'] . ';';
            }
            echo '<option value="' . esc_attr($value) . '" data-color-key="' . esc_attr($color) . '" style="' . esc_attr($optionStyle) . '" ' . selected($current, $value, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<span class="spinner" style="float:none;margin:0 0 0 6px;"></span>';
        echo '</div>';
    }
}
