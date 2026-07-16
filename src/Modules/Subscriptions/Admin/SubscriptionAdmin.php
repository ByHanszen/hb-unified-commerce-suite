<?php

namespace HB\UCS\Modules\Subscriptions\Admin;

use HB\UCS\Core\Settings;
use HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository;
use HB\UCS\Modules\Subscriptions\Domain\SubscriptionService;
use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;
use HB\UCS\Modules\Subscriptions\Support\SubscriptionSyncLogger;

if (!defined('ABSPATH')) exit;

class SubscriptionAdmin {
    private const ORDER_META_SUBSCRIPTION_ID = '_hb_ucs_subscription_id';

    /** @var array<int, bool> */
    private static $deferredSyncOrderIds = [];

    /** @var array<int, array<int, array<string, mixed>>> */
    private static $postedShippingLinesByOrderId = [];

    /** @var SubscriptionService */
    private $service;

    /** @var SubscriptionOrderType */
    private $orderType;

    public function __construct(SubscriptionService $service, SubscriptionOrderType $orderType) {
        $this->service = $service;
        $this->orderType = $orderType;
    }

    public function init(): void {
        if (!is_admin()) {
            return;
        }

        add_action('current_screen', [$this, 'handle_current_screen']);
        add_action('admin_menu', [$this, 'hide_legacy_subscription_menu'], 99);
        add_action('admin_notices', [$this, 'render_bulk_action_notice']);
        add_action('admin_head', [$this, 'render_order_type_list_styles']);
        add_action('admin_footer', [$this, 'render_order_type_list_scripts']);

        add_filter('woocommerce_before_' . $this->orderType->get_type() . '_list_table_view_links', [$this, 'filter_order_type_view_links']);
        add_filter('woocommerce_' . $this->orderType->get_type() . '_list_table_request', [$this, 'filter_order_type_request']);
        add_filter('woocommerce_' . $this->orderType->get_type() . '_list_table_default_statuses', [$this, 'filter_order_type_default_statuses']);
        add_filter('woocommerce_' . $this->orderType->get_type() . '_list_table_columns', [$this, 'filter_order_type_columns']);
        add_filter('woocommerce_' . $this->orderType->get_type() . '_list_table_sortable_columns', [$this, 'filter_order_type_sortable_columns']);
        add_action('woocommerce_' . $this->orderType->get_type() . '_list_table_custom_column', [$this, 'render_order_type_column'], 10, 2);
        add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'render_order_type_filters'], 10, 2);
        add_filter('woocommerce_' . $this->orderType->get_type() . '_list_table_prepare_items_query_args', [$this, 'filter_order_type_query_args']);
        add_filter('woocommerce_' . $this->orderType->get_type() . '_list_table_order_count', [$this, 'filter_order_type_count'], 10, 2);

        add_filter('woocommerce_order_actions', [$this, 'filter_order_actions'], 10, 2);
        add_action('woocommerce_order_action_hb_ucs_create_renewal_order', [$this, 'handle_create_renewal_order_action']);
        add_action('woocommerce_order_action_hb_ucs_sync_customer_addresses', [$this, 'handle_sync_customer_addresses_action']);
        add_action('woocommerce_order_action_hb_ucs_pause_subscription', [$this, 'handle_pause_subscription_action']);
        add_action('woocommerce_order_action_hb_ucs_resume_subscription', [$this, 'handle_resume_subscription_action']);
        add_action('woocommerce_order_action_hb_ucs_cancel_subscription', [$this, 'handle_cancel_subscription_action']);
        add_action('woocommerce_process_shop_order_meta', [$this, 'normalize_order_type_posted_status'], 5, 2);
        add_action('woocommerce_process_shop_order_meta', [$this, 'save_order_type_screen'], 60, 2);
        add_action('shutdown', [$this, 'process_deferred_order_type_sync'], 20);

        foreach ($this->get_order_screen_ids() as $screenId) {
            add_action('add_meta_boxes_' . $screenId, [$this, 'register_order_type_meta_boxes']);
            add_filter('bulk_actions-' . $screenId, [$this, 'filter_order_type_bulk_actions']);
            add_filter('handle_bulk_actions-' . $screenId, [$this, 'handle_order_type_bulk_actions'], 10, 3);
        }
    }

    public function handle_current_screen($screen): void {
        if (!$screen instanceof \WP_Screen) {
            return;
        }

        if ($this->is_legacy_subscription_screen($screen)) {
            $this->redirect_legacy_screen($screen);
            return;
        }

        if ($this->is_subscription_order_type_screen()) {
            $this->maybe_materialize_current_subscription_order_items();
            do_action('hb_ucs_subscription_admin_order_type_screen', $screen, $this->service, $this->orderType);
        }
    }

    private function maybe_materialize_current_subscription_order_items(): void {
        $orderId = isset($_GET['id']) ? (int) absint((string) wp_unslash($_GET['id'])) : 0;
        if ($orderId <= 0) {
            $orderId = isset($_GET['post']) ? (int) absint((string) wp_unslash($_GET['post'])) : 0;
        }
        if ($orderId <= 0) {
            return;
        }

        $order = $this->get_subscription_order($orderId);
        if (!$order) {
            return;
        }

        $this->service->get_repository()->materialize_order_type_items_from_meta($orderId);
    }

    public function is_legacy_subscription_screen(?\WP_Screen $screen = null): bool {
        $screen = $screen ?: get_current_screen();
        if (!$screen instanceof \WP_Screen) {
            return false;
        }

        if ((string) $screen->post_type !== SubscriptionRepository::LEGACY_POST_TYPE) {
            return false;
        }

        return in_array((string) $screen->base, ['post', 'edit'], true);
    }

    public function is_subscription_order_type_screen(): bool {
        if (!class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::is_order_edit_screen($this->orderType->get_type())
            || \Automattic\WooCommerce\Utilities\OrderUtil::is_order_list_table_screen($this->orderType->get_type())
            || \Automattic\WooCommerce\Utilities\OrderUtil::is_new_order_screen($this->orderType->get_type());
    }

    public function hide_legacy_subscription_menu(): void {
        remove_submenu_page('woocommerce', 'edit.php?post_type=' . SubscriptionRepository::LEGACY_POST_TYPE);
    }

    public function register_order_type_meta_boxes($postOrOrder): void {
        $order = $this->get_subscription_order($postOrOrder);
        if (!$order) {
            return;
        }

        $screenId = $this->get_active_order_screen_id();
        if ($screenId === '') {
            return;
        }

        add_meta_box('hb-ucs-subscription-schedule', __('Abonnement schema', 'hb-ucs'), [$this, 'render_schedule_meta_box'], $screenId, 'side', 'default');
        add_meta_box('hb-ucs-subscription-related-orders', __('Gerelateerde bestellingen', 'hb-ucs'), [$this, 'render_related_orders_meta_box'], $screenId, 'normal', 'default');
    }

    public function render_schedule_meta_box($postOrOrder): void {
        $order = $this->get_subscription_order($postOrOrder);
        if (!$order) {
            echo '<p>' . esc_html__('Onbekend abonnement.', 'hb-ucs') . '</p>';
            return;
        }

        $status = $this->get_subscription_status_for_order($order);
        $scheme = (string) $order->get_meta('_hb_ucs_subscription_scheme', true);
        $nextPayment = $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_next_payment', SubscriptionRepository::LEGACY_NEXT_PAYMENT_META);
        $trialEnd = $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_trial_end', SubscriptionRepository::LEGACY_TRIAL_END_META);
        $endDate = $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_end_date', SubscriptionRepository::LEGACY_END_DATE_META);

        $this->log_subscription_sync('admin.render_schedule_meta_box', $order, [
            'rendered_status' => $status,
            'rendered_scheme' => $scheme,
            'rendered_next_payment' => $nextPayment,
            'rendered_trial_end' => $trialEnd,
            'rendered_end_date' => $endDate,
        ]);

        echo '<p><label for="hb_ucs_sub_status"><strong>' . esc_html__('Status', 'hb-ucs') . '</strong></label><br/>';
        echo '<select name="hb_ucs_sub_status" id="hb_ucs_sub_status" style="width:100%;">';
        foreach ($this->get_subscription_statuses() as $statusKey => $label) {
            echo '<option value="' . esc_attr($statusKey) . '" ' . selected($status, $statusKey, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label for="hb_ucs_sub_scheme"><strong>' . esc_html__('Betaling', 'hb-ucs') . '</strong></label><br/>';
        echo '<select name="hb_ucs_sub_scheme" id="hb_ucs_sub_scheme" style="width:100%;">';
        foreach ($this->get_schedule_options() as $schemeKey => $label) {
            echo '<option value="' . esc_attr($schemeKey) . '" ' . selected($scheme, $schemeKey, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label for="hb_ucs_sub_next_payment"><strong>' . esc_html__('Volgende betaling', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="datetime-local" name="hb_ucs_sub_next_payment" id="hb_ucs_sub_next_payment" value="' . esc_attr($this->format_datetime_local($nextPayment)) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_trial_end"><strong>' . esc_html__('Einde proefabonnement', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="datetime-local" name="hb_ucs_sub_trial_end" id="hb_ucs_sub_trial_end" value="' . esc_attr($this->format_datetime_local($trialEnd)) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_end_date"><strong>' . esc_html__('Einddatum', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="datetime-local" name="hb_ucs_sub_end_date" id="hb_ucs_sub_end_date" value="' . esc_attr($this->format_datetime_local($endDate)) . '" style="width:100%;" />';
        echo '</p>';
    }

    public function render_related_orders_meta_box($postOrOrder): void {
        $order = $this->get_subscription_order($postOrOrder);
        if (!$order) {
            echo '<p>' . esc_html__('Onbekend abonnement.', 'hb-ucs') . '</p>';
            return;
        }

        $parentOrderId = (int) $order->get_meta(SubscriptionRepository::LEGACY_PARENT_ORDER_ID_META, true);
        $renewalOrders = [];

        if (function_exists('wc_get_orders')) {
            $renewalOrders = wc_get_orders([
                'limit' => 50,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_key' => self::ORDER_META_SUBSCRIPTION_ID,
                'meta_value' => (string) $order->get_id(),
            ]);
        }

        echo '<table class="widefat striped wc-order-list-table"><thead><tr>';
        echo '<th>' . esc_html__('#', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Type', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Status', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Datum', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Totaal', 'hb-ucs') . '</th>';
        echo '</tr></thead><tbody>';

        if ($parentOrderId > 0 && function_exists('wc_get_order')) {
            $parentOrder = wc_get_order($parentOrderId);
            if ($parentOrder && is_object($parentOrder)) {
                echo $this->render_related_order_row($parentOrder, __('Start', 'hb-ucs'));
            }
        }

        foreach ((array) $renewalOrders as $renewalOrder) {
            if (!$renewalOrder || !is_object($renewalOrder) || !method_exists($renewalOrder, 'get_id')) {
                continue;
            }
            echo $this->render_related_order_row($renewalOrder, __('Renewal', 'hb-ucs'));
        }

        echo '</tbody></table>';
    }

    public function filter_order_type_columns(array $columns): array {
        $newColumns = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_status') {
                $newColumns['hb_ucs_subscription_status'] = __('Abonnementsstatus', 'hb-ucs');
                $newColumns['hb_ucs_subscription_schedule'] = __('Schema', 'hb-ucs');
                $newColumns['hb_ucs_subscription_next_payment'] = __('Volgende betaling', 'hb-ucs');
                $newColumns['hb_ucs_subscription_orders'] = __('Bestellingen', 'hb-ucs');
                continue;
            }

            $newColumns[$key] = $label;
        }
        return $newColumns;
    }

    public function render_order_type_column(string $column, $order): void {
        $order = $this->get_subscription_order($order);
        if (!$order) {
            return;
        }

        if ($column === 'hb_ucs_subscription_status') {
            $status = $this->get_display_subscription_status_for_order($order);

            echo wp_kses_post($this->get_subscription_status_badge_html($status));
            return;
        }

        if ($column === 'hb_ucs_subscription_schedule') {
            $scheme = (string) $order->get_meta('_hb_ucs_subscription_scheme', true);
            $options = $this->get_schedule_options();
            echo esc_html($options[$scheme] ?? ($scheme !== '' ? $scheme : '—'));
            return;
        }

        if ($column === 'hb_ucs_subscription_next_payment') {
            $nextPayment = $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_next_payment', SubscriptionRepository::LEGACY_NEXT_PAYMENT_META);
            echo esc_html($nextPayment > 0 ? $this->format_datetime_for_site($nextPayment) : '—');
            return;
        }

        if ($column === 'hb_ucs_subscription_orders') {
            echo esc_html((string) $this->count_related_orders((int) $order->get_id(), (int) $order->get_meta(SubscriptionRepository::LEGACY_PARENT_ORDER_ID_META, true)));
        }
    }

    public function render_order_type_filters(string $orderType, string $which): void {
        if ($orderType !== $this->orderType->get_type() || $which !== 'top') {
            return;
        }

        $currentStatus = isset($_GET['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_status'])) : '';
        $currentScheme = isset($_GET['hb_ucs_sub_scheme']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_scheme'])) : '';

        echo '<select name="hb_ucs_sub_status">';
        echo '<option value="">' . esc_html__('Alle statussen', 'hb-ucs') . '</option>';
        foreach ($this->get_subscription_statuses() as $statusKey => $label) {
            echo '<option value="' . esc_attr($statusKey) . '" ' . selected($currentStatus, $statusKey, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        echo '<select name="hb_ucs_sub_scheme">';
        echo '<option value="">' . esc_html__('Alle frequenties', 'hb-ucs') . '</option>';
        foreach ($this->get_schedule_options() as $schemeKey => $label) {
            echo '<option value="' . esc_attr($schemeKey) . '" ' . selected($currentScheme, $schemeKey, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function filter_order_type_sortable_columns(array $columns): array {
        $columns['hb_ucs_subscription_next_payment'] = 'hb_ucs_subscription_next_payment';

        return $columns;
    }

    public function filter_order_type_query_args(array $queryArgs): array {
        $metaQuery = isset($queryArgs['meta_query']) && is_array($queryArgs['meta_query']) ? $queryArgs['meta_query'] : [];
        $currentOrderStatus = $this->get_current_order_type_status_request();

        $status = isset($_GET['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_status'])) : '';
        if ($status !== '' && $currentOrderStatus !== 'trash') {
            $metaQuery[] = $this->build_subscription_status_meta_query($status);

            $orderStatuses = $this->get_query_order_statuses_for_subscription_status($status);
            if (!empty($orderStatuses)) {
                $queryArgs['status'] = $orderStatuses;
            }
        }

        $scheme = isset($_GET['hb_ucs_sub_scheme']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_scheme'])) : '';
        if ($scheme !== '') {
            $metaQuery[] = [
                'key' => '_hb_ucs_subscription_scheme',
                'value' => $scheme,
                'compare' => '=',
            ];
        }

        if (!empty($metaQuery)) {
            $queryArgs['meta_query'] = $metaQuery;
        }

        if ($currentOrderStatus === 'trash') {
            $queryArgs['status'] = ['trash'];
        }

        $orderby = isset($_GET['orderby']) ? sanitize_key((string) wp_unslash($_GET['orderby'])) : '';
        if ($orderby === 'hb_ucs_subscription_next_payment') {
            $queryArgs['meta_key'] = '_hb_ucs_subscription_next_payment';
            $queryArgs['orderby'] = 'meta_value_num';
            $queryArgs['meta_type'] = 'NUMERIC';
        }

        return $queryArgs;
    }

    public function filter_order_type_count(int $count, array $statuses): int {
        $statuses = array_values(array_filter(array_map('strval', $statuses)));
        if (empty($statuses)) {
            return 0;
        }

        $statusFilter = isset($_GET['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_status'])) : '';

        return $this->count_subscriptions_by_order_statuses($statuses, $statusFilter);
    }

    public function filter_order_type_request(array $request): array {
        $status = isset($request['status']) ? sanitize_key((string) $request['status']) : '';
        if ($status !== '' && $status !== 'all' && $status !== 'trash') {
            $request['status'] = '';
        }

        return $request;
    }

    public function filter_order_type_default_statuses(array $statuses): array {
        if (!function_exists('wc_get_order_statuses')) {
            return $statuses;
        }

        return array_keys(wc_get_order_statuses());
    }

    public function filter_order_type_view_links(array $views): array {
        $currentStatus = isset($_GET['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_status'])) : '';
        $currentOrderStatus = $this->get_current_order_type_status_request();
        $links = [];
        $links['all'] = $this->build_order_type_view_link('', __('Alle', 'hb-ucs'), $this->count_subscriptions_for_status(''), $currentStatus === '' && $currentOrderStatus !== 'trash');

        foreach ($this->get_subscription_statuses() as $statusKey => $label) {
            $count = $this->count_subscriptions_for_status($statusKey);
            if ($count <= 0) {
                continue;
            }

            $links[$statusKey] = $this->build_order_type_view_link($statusKey, $label, $count, $currentStatus === $statusKey);
        }

        $trashCount = $this->count_subscriptions_in_trash();
        if ($trashCount > 0 || $currentOrderStatus === 'trash') {
            $links['trash'] = $this->build_order_type_trash_view_link(__('Prullenbak', 'hb-ucs'), $trashCount, $currentOrderStatus === 'trash');
        }

        return $links;
    }

    private function count_subscriptions_for_status(string $subscriptionStatus = ''): int {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $queryArgs = [
            'type' => $this->orderType->get_type(),
            'status' => array_keys(wc_get_order_statuses()),
            'limit' => -1,
            'return' => 'ids',
        ];

        $schemeFilter = isset($_GET['hb_ucs_sub_scheme']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_scheme'])) : '';

        $orderIds = wc_get_orders($queryArgs);
        if (!is_array($orderIds) || empty($orderIds)) {
            return 0;
        }

        $count = 0;
        foreach ($orderIds as $orderId) {
            $orderId = (int) $orderId;
            if ($orderId <= 0) {
                continue;
            }

            $order = $this->get_subscription_order($orderId);
            if (!$order) {
                continue;
            }

            $currentStatus = $this->get_subscription_status_for_order($order);
            if ($subscriptionStatus !== '' && $currentStatus !== $subscriptionStatus) {
                continue;
            }

            if ($schemeFilter !== '') {
                $currentScheme = method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_scheme', true) : '';
                if ($currentScheme !== $schemeFilter) {
                    continue;
                }
            }

            $count++;
        }

        return $count;
    }

    private function count_subscriptions_by_order_statuses(array $orderStatuses, string $subscriptionStatusFilter = ''): int {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $orderIds = wc_get_orders([
            'type' => $this->orderType->get_type(),
            'status' => $orderStatuses,
            'limit' => -1,
            'return' => 'ids',
        ]);

        if (!is_array($orderIds) || empty($orderIds)) {
            return 0;
        }

        $schemeFilter = isset($_GET['hb_ucs_sub_scheme']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_scheme'])) : '';
        $count = 0;

        foreach ($orderIds as $orderId) {
            $orderId = (int) $orderId;
            if ($orderId <= 0) {
                continue;
            }

            $order = $this->get_subscription_order($orderId);
            if (!$order) {
                continue;
            }

            $currentStatus = $this->get_subscription_status_for_order($order);
            if ($subscriptionStatusFilter !== '' && $currentStatus !== $subscriptionStatusFilter) {
                continue;
            }

            if ($schemeFilter !== '') {
                $currentScheme = method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_scheme', true) : '';
                if ($currentScheme !== $schemeFilter) {
                    continue;
                }
            }

            $count++;
        }

        return $count;
    }

    private function build_order_type_view_link(string $status, string $label, int $count, bool $current): string {
        $queryArgs = [];

        foreach (['hb_ucs_sub_scheme', 'orderby', 'order', 's'] as $key) {
            if (!isset($_GET[$key])) {
                continue;
            }

            $value = wp_unslash($_GET[$key]);
            if (!is_scalar($value) || $value === '') {
                continue;
            }

            $queryArgs[$key] = sanitize_text_field((string) $value);
        }

        if ($status !== '') {
            $queryArgs['hb_ucs_sub_status'] = $status;
        }

        $url = add_query_arg($queryArgs, $this->get_order_type_list_url());

        return '<a href="' . esc_url($url) . '"' . ($current ? ' class="current" aria-current="page"' : '') . '>' . esc_html($label) . ' <span class="count">(' . esc_html((string) $count) . ')</span></a>';
    }

    private function build_order_type_trash_view_link(string $label, int $count, bool $current): string {
        $queryArgs = [];

        foreach (['hb_ucs_sub_scheme', 'orderby', 'order', 's'] as $key) {
            if (!isset($_GET[$key])) {
                continue;
            }

            $value = wp_unslash($_GET[$key]);
            if (!is_scalar($value) || $value === '') {
                continue;
            }

            $queryArgs[$key] = sanitize_text_field((string) $value);
        }

        $queryArgs['status'] = 'trash';
        unset($queryArgs['hb_ucs_sub_status']);

        $url = add_query_arg($queryArgs, $this->get_order_type_list_url());

        return '<a href="' . esc_url($url) . '"' . ($current ? ' class="current" aria-current="page"' : '') . '>' . esc_html($label) . ' <span class="count">(' . esc_html((string) $count) . ')</span></a>';
    }

    private function count_subscriptions_in_trash(): int {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $orderIds = wc_get_orders([
            'type' => $this->orderType->get_type(),
            'status' => ['trash'],
            'limit' => -1,
            'return' => 'ids',
        ]);

        return is_array($orderIds) ? count($orderIds) : 0;
    }

    private function get_current_order_type_status_request(): string {
        if (!isset($_GET['status'])) {
            return '';
        }

        $status = wp_unslash($_GET['status']);
        if (is_array($status)) {
            $status = reset($status);
        }

        return is_scalar($status) ? sanitize_key((string) $status) : '';
    }

    public function filter_order_type_bulk_actions(array $actions): array {
        unset(
            $actions['mark_processing'],
            $actions['mark_on-hold'],
            $actions['mark_completed'],
            $actions['mark_cancelled']
        );

        $actions['hb_ucs_mark_subscription_paused'] = __('Pauzeer abonnementen', 'hb-ucs');
        $actions['hb_ucs_mark_subscription_active'] = __('Hervat abonnementen', 'hb-ucs');
        $actions['hb_ucs_mark_subscription_cancelled'] = __('Annuleer abonnementen', 'hb-ucs');
        return $actions;
    }

    public function handle_order_type_bulk_actions(string $redirectTo, string $action, array $ids): string {
        $statusMap = [
            'hb_ucs_mark_subscription_paused' => 'paused',
            'hb_ucs_mark_subscription_active' => 'active',
            'hb_ucs_mark_subscription_cancelled' => 'cancelled',
        ];

        if (!isset($statusMap[$action])) {
            return $redirectTo;
        }

        $updated = 0;
        foreach ($ids as $orderId) {
            $order = $this->get_subscription_order((int) $orderId);
            if (!$order) {
                continue;
            }

            $this->apply_subscription_status_to_order($order, $statusMap[$action]);
            if (method_exists($order, 'save')) {
                $order->save();
            }

            $this->persist_subscription_shadow_meta((int) $order->get_id(), [
                '_hb_ucs_subscription_status' => $statusMap[$action],
                SubscriptionRepository::LEGACY_STATUS_META => $statusMap[$action],
            ]);

            $this->service->get_repository()->sync_order_type_self((int) $order->get_id(), false);
            $updated++;
        }

        return add_query_arg('hb_ucs_bulk_updated', $updated, $redirectTo);
    }

    public function render_bulk_action_notice(): void {
        if (!$this->is_subscription_order_type_screen()) {
            return;
        }

        $updated = isset($_GET['hb_ucs_bulk_updated']) ? (int) absint((string) wp_unslash($_GET['hb_ucs_bulk_updated'])) : 0;
        if ($updated <= 0) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(_n('%d abonnement bijgewerkt.', '%d abonnementen bijgewerkt.', $updated, 'hb-ucs'), $updated)) . '</p></div>';
    }

    public function render_order_type_list_styles(): void {
        if (!$this->is_subscription_order_type_screen()) {
            return;
        }

        echo '<style>';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .subsubsub a[href*="status=wc-"],';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .subsubsub li:has(a[href*="status=wc-"]),';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' select[name="status"],';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .subsubsub a[href*="status=wc-"],';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .subsubsub li:has(a[href*="status=wc-"]),';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' select[name="status"]{display:none!important;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-order_number .order-preview,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-order_number .order-preview{display:none!important;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-order_number .order_date.small-screen-only,';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-order_number .order_status.small-screen-only,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-order_number .order_date.small-screen-only,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-order_number .order_status.small-screen-only{display:none!important;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-hb_ucs_subscription_status,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .column-hb_ucs_subscription_status{width:150px;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge{display:inline-flex;align-items:center;min-height:24px;padding:2px 10px;border-radius:999px;font-weight:600;line-height:1.4;background:#f0f0f1;color:#1d2327;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--active,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--active{background:#edfaef;color:#0a7a2f;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--pending_mandate,';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--payment_pending,';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--on-hold,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--pending_mandate,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--payment_pending,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--on-hold{background:#fff8e5;color:#8a6100;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--paused,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--paused{background:#fff8e5;color:#8a6100;}';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--cancelled,';
        echo '.woocommerce_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--expired,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--cancelled,';
        echo '.admin_page_wc-orders--' . esc_attr($this->orderType->get_type()) . ' .hb-ucs-subscription-status-badge--expired{background:#f0f0f1;color:#50575e;}';
        echo '</style>';
    }

    public function render_order_type_list_scripts(): void {
        if (!$this->is_subscription_order_type_screen()) {
            return;
        }

        $dateLabels = $this->get_current_list_order_date_labels();
        $subscriptionStatuses = [];
        foreach ($this->get_subscription_statuses() as $statusKey => $label) {
            $subscriptionStatuses[] = [
                'value' => (string) $statusKey,
                'label' => (string) $label,
            ];
        }
        $statusOrderMap = $this->get_subscription_status_to_order_status_map();
        $screenBodyClasses = [
            'woocommerce_page_wc-orders--' . $this->orderType->get_type(),
            'admin_page_wc-orders--' . $this->orderType->get_type(),
        ];

        echo '<script>';
        echo '(function(){';
        echo 'var isSubscriptionScreen=' . wp_json_encode(array_values($screenBodyClasses)) . '.some(function(className){return document.body && document.body.classList.contains(className);});';
        echo 'if(!isSubscriptionScreen){return;}';
        echo 'document.querySelectorAll(".subsubsub a[href*=\"status=wc-\"]").forEach(function(link){';
        echo 'var item=link.closest("li");';
        echo 'if(item){item.remove();return;}';
        echo 'link.remove();';
        echo '});';
        echo 'document.querySelectorAll("select[name=\"status\"] option").forEach(function(option){';
        echo 'if((option.value||"").indexOf("wc-")===0){option.remove();}';
        echo '});';
        echo 'document.querySelectorAll("select[name=\"status\"]").forEach(function(select){';
        echo 'if(select.options.length<=1){select.style.display="none";}';
        echo '});';
        echo 'var subscriptionStatuses=' . wp_json_encode($subscriptionStatuses) . ';';
        echo 'var subscriptionStatusOrderMap=' . wp_json_encode($statusOrderMap) . ';';
        echo 'var mainOrderStatusSelect=document.querySelector("#order_status");';
        echo 'if(mainOrderStatusSelect){';
        echo 'var currentSubscriptionStatus="";';
        echo 'var mirroredSubscriptionSelects=Array.prototype.slice.call(document.querySelectorAll("select[name=\"hb_ucs_sub_status\"]"));';
        echo 'var mirroredSubscriptionSelect=mirroredSubscriptionSelects.length?mirroredSubscriptionSelects[0]:null;';
        echo 'if(mirroredSubscriptionSelect){currentSubscriptionStatus=mirroredSubscriptionSelect.value||"";}';
        echo 'if(!currentSubscriptionStatus&&mainOrderStatusSelect.dataset.hbUcsCurrentSubscriptionStatus){currentSubscriptionStatus=mainOrderStatusSelect.dataset.hbUcsCurrentSubscriptionStatus;}';
        echo 'if(!mainOrderStatusSelect.dataset.hbUcsProxyInitialized){';
        echo 'mainOrderStatusSelect.dataset.hbUcsProxyInitialized="1";';
        echo 'mainOrderStatusSelect.name="hb_ucs_sub_status_proxy";';
        echo 'var mappedInput=document.createElement("input");';
        echo 'mappedInput.type="hidden";';
        echo 'mappedInput.name="order_status";';
        echo 'mappedInput.id="hb_ucs_mapped_order_status";';
        echo 'mainOrderStatusSelect.parentNode.insertBefore(mappedInput, mainOrderStatusSelect);';
        echo '}';
        echo 'var mappedOrderStatusInput=document.querySelector("#hb_ucs_mapped_order_status");';
        echo 'if(mappedOrderStatusInput){';
        echo 'mainOrderStatusSelect.innerHTML="";';
        echo 'subscriptionStatuses.forEach(function(optionData){';
        echo 'var option=document.createElement("option");';
        echo 'option.value=optionData.value;';
        echo 'option.textContent=optionData.label;';
        echo 'if(optionData.value===currentSubscriptionStatus){option.selected=true;}';
        echo 'mainOrderStatusSelect.appendChild(option);';
        echo '});';
        echo 'if(!mainOrderStatusSelect.value&&subscriptionStatuses.length){mainOrderStatusSelect.value=currentSubscriptionStatus||subscriptionStatuses[0].value;}';
        echo 'var syncSubscriptionStatus=function(){';
        echo 'var selectedSubscriptionStatus=mainOrderStatusSelect.value||"";';
        echo 'mirroredSubscriptionSelects.forEach(function(select){select.value=selectedSubscriptionStatus;});';
        echo 'mappedOrderStatusInput.value=subscriptionStatusOrderMap[selectedSubscriptionStatus]||"wc-pending";';
        echo '};';
        echo 'syncSubscriptionStatus();';
        echo 'mainOrderStatusSelect.addEventListener("change", syncSubscriptionStatus);';
        echo 'var mainOrderForm=mainOrderStatusSelect.closest("form");';
        echo 'if(mainOrderForm&&!mainOrderForm.dataset.hbUcsStatusSyncBound){';
        echo 'mainOrderForm.dataset.hbUcsStatusSyncBound="1";';
        echo 'mainOrderForm.addEventListener("submit", syncSubscriptionStatus);';
        echo '}';
        echo 'if(window.jQuery){';
        echo 'var $mainOrderStatusSelect=window.jQuery(mainOrderStatusSelect);';
        echo 'if($mainOrderStatusSelect.hasClass("select2-hidden-accessible")||$mainOrderStatusSelect.hasClass("enhanced")){';
        echo '$mainOrderStatusSelect.trigger("change.select2");';
        echo '}';
        echo '}';
        echo '}';
        echo '}';
        echo 'document.querySelectorAll("select[name=\"hb_ucs_sub_status\"]").forEach(function(select){';
        echo 'var currentValue=select.value||"";';
        echo 'var currentLabels=Array.from(select.options).map(function(option){return (option.value||"")+"::"+(option.textContent||"").trim();});';
        echo 'var expectedLabels=subscriptionStatuses.map(function(option){return option.value+"::"+option.label;});';
        echo 'var isDifferent=currentLabels.length!==expectedLabels.length||expectedLabels.some(function(entry,index){return currentLabels[index]!==entry;});';
        echo 'if(!isDifferent){return;}';
        echo 'select.innerHTML="";';
        echo 'subscriptionStatuses.forEach(function(optionData){';
        echo 'var option=document.createElement("option");';
        echo 'option.value=optionData.value;';
        echo 'option.textContent=optionData.label;';
        echo 'if(optionData.value===currentValue){option.selected=true;}';
        echo 'select.appendChild(option);';
        echo '});';
        echo 'if(!select.value&&subscriptionStatuses.length){select.value=currentValue||subscriptionStatuses[0].value;}';
        echo 'if(window.jQuery){';
        echo 'var $select=window.jQuery(select);';
        echo 'if($select.hasClass("select2-hidden-accessible")||$select.hasClass("enhanced")){';
        echo '$select.trigger("change.select2");';
        echo '}';
        echo '}';
        echo '});';
        echo 'var labels=' . wp_json_encode($dateLabels) . ';';
        echo 'Object.keys(labels).forEach(function(orderId){';
        echo 'var node=document.querySelector("#order-"+orderId+" .column-order_date time");';
        echo 'if(!node){return;}';
        echo 'node.textContent=labels[orderId];';
        echo 'node.setAttribute("title", labels[orderId]);';
        echo '});';
        echo '})();';
        echo '</script>';
    }

    public function filter_order_actions(array $actions, $order): array {
        $order = $this->get_subscription_order($order);
        if (!$order) {
            return $actions;
        }

        $status = $this->get_subscription_status_for_order($order);

        $actions['hb_ucs_create_renewal_order'] = __('Maak renewal order aan', 'hb-ucs');
        $actions['hb_ucs_sync_customer_addresses'] = __('Synchroniseer klantadressen', 'hb-ucs');

        if ($status === 'paused' || $status === 'on-hold') {
            $actions['hb_ucs_resume_subscription'] = __('Hervat abonnement', 'hb-ucs');
        } elseif (!in_array($status, ['cancelled', 'expired'], true)) {
            $actions['hb_ucs_pause_subscription'] = __('Pauzeer abonnement', 'hb-ucs');
        }

        if ($status !== 'cancelled') {
            $actions['hb_ucs_cancel_subscription'] = __('Annuleer abonnement', 'hb-ucs');
        }

        return $actions;
    }

    public function handle_create_renewal_order_action($order): void {
        $order = $this->get_subscription_order($order);
        if (!$order) {
            return;
        }

        apply_filters('hb_ucs_subscription_admin_execute_action', null, 'create_renewal_order', (int) $order->get_id(), $order);
        $this->service->get_repository()->sync_order_type_self((int) $order->get_id(), false);
    }

    public function handle_sync_customer_addresses_action($order): void {
        $order = $this->get_subscription_order($order);
        if (!$order) {
            return;
        }

        $userId = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
        if ($userId <= 0) {
            return;
        }

        $billing = $this->build_user_address_snapshot($userId, 'billing');
        $shipping = $this->build_user_address_snapshot($userId, 'shipping');

        update_post_meta((int) $order->get_id(), SubscriptionRepository::LEGACY_BILLING_META, $billing);
        update_post_meta((int) $order->get_id(), SubscriptionRepository::LEGACY_SHIPPING_META, $shipping);

        foreach ($billing as $field => $value) {
            update_post_meta((int) $order->get_id(), '_billing_' . $field, $value);
        }
        foreach ($shipping as $field => $value) {
            update_post_meta((int) $order->get_id(), '_shipping_' . $field, $value);
        }

        if (method_exists($order, 'set_address')) {
            $order->set_address($billing, 'billing');
            $order->set_address($shipping, 'shipping');
        }
        if (method_exists($order, 'save')) {
            $order->save();
        }

        $this->service->get_repository()->sync_order_type_self((int) $order->get_id(), false);

        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(__('Factuur- en verzendadres opnieuw geladen vanaf het klantprofiel.', 'hb-ucs'));
        }
    }

    public function handle_pause_subscription_action($order): void {
        $this->handle_subscription_status_action($order, 'paused', __('Abonnement is gepauzeerd vanuit de backend.', 'hb-ucs'));
    }

    public function handle_resume_subscription_action($order): void {
        $this->handle_subscription_status_action($order, 'active', __('Abonnement is hervat vanuit de backend.', 'hb-ucs'));
    }

    public function handle_cancel_subscription_action($order): void {
        $this->handle_subscription_status_action($order, 'cancelled', __('Abonnement is geannuleerd vanuit de backend.', 'hb-ucs'));
    }

    public function normalize_order_type_posted_status(int $orderId, $order): void {
        $order = $this->get_subscription_order($order ?: $orderId);
        if (!$order || !current_user_can('edit_shop_orders')) {
            return;
        }

        $allowedStatuses = array_keys($this->get_subscription_statuses());
        $subscriptionStatus = '';

        if (isset($_POST['hb_ucs_sub_status_proxy'])) {
            $candidate = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_status_proxy']));
            if (in_array($candidate, $allowedStatuses, true)) {
                $subscriptionStatus = $candidate;
            }
        }

        if ($subscriptionStatus === '' && isset($_POST['hb_ucs_sub_status'])) {
            $candidate = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_status']));
            if (in_array($candidate, $allowedStatuses, true)) {
                $subscriptionStatus = $candidate;
            }
        }

        if ($subscriptionStatus === '') {
            return;
        }

        $_POST['hb_ucs_sub_status'] = $subscriptionStatus;
        $_POST['order_status'] = $this->map_subscription_status_to_order_status($subscriptionStatus);
    }

    public function save_order_type_screen(int $orderId, $order): void {
        $order = $this->get_subscription_order($order ?: $orderId);
        if (!$order || !current_user_can('edit_shop_orders')) {
            return;
        }

        $previousStatus = $this->get_subscription_status_for_order($order);
        $previousScheme = (string) $order->get_meta('_hb_ucs_subscription_scheme', true);
        $previousUserId = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
        $previousPaymentMethod = method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '';
        $previousPaymentMethodTitle = method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : '';
        $previousShippingLabel = $this->get_primary_shipping_line_label($this->get_subscription_shipping_lines_for_order($orderId, $order));
        $previousDates = [
            '_hb_ucs_subscription_next_payment' => $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_next_payment', SubscriptionRepository::LEGACY_NEXT_PAYMENT_META),
            '_hb_ucs_subscription_trial_end' => $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_trial_end', SubscriptionRepository::LEGACY_TRIAL_END_META),
            '_hb_ucs_subscription_end_date' => $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_end_date', SubscriptionRepository::LEGACY_END_DATE_META),
        ];

        $this->log_subscription_sync('admin.save.start', $order, [
            'previous_status' => $previousStatus,
            'previous_scheme' => $previousScheme,
            'previous_dates' => $previousDates,
            'posted_status' => isset($_POST['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_status'])) : '',
            'posted_scheme' => isset($_POST['hb_ucs_sub_scheme']) ? sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_scheme'])) : '',
            'posted_next_payment' => isset($_POST['hb_ucs_sub_next_payment']) ? (string) wp_unslash($_POST['hb_ucs_sub_next_payment']) : '',
        ]);

        $allowedStatuses = array_keys($this->get_subscription_statuses());
        $savedStatus = $previousStatus;
        if (isset($_POST['hb_ucs_sub_status'])) {
            $status = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_status']));
            if (in_array($status, $allowedStatuses, true)) {
                $this->apply_subscription_status_to_order($order, $status);
                $savedStatus = $status;
            }
        }

        $savedScheme = $previousScheme;
        if (isset($_POST['hb_ucs_sub_scheme'])) {
            $scheme = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_scheme']));
            $options = $this->get_schedule_options();
            if (isset($options[$scheme])) {
                if (method_exists($order, 'set_subscription_scheme')) {
                    $order->set_subscription_scheme($scheme);
                } else {
                    $this->update_subscription_order_meta_pair(
                        $order,
                        $orderId,
                        '_hb_ucs_subscription_scheme',
                        SubscriptionRepository::LEGACY_SCHEME_META,
                        $scheme
                    );
                }
                $savedScheme = $scheme;
            }
        }

        $savedUserId = $previousUserId;
        if (isset($_POST['hb_ucs_sub_user_id'])) {
            $userId = (int) absint((string) wp_unslash($_POST['hb_ucs_sub_user_id']));
            update_post_meta($orderId, SubscriptionRepository::LEGACY_USER_ID_META, (string) $userId);
            $savedUserId = $userId;

            if (method_exists($order, 'set_customer_id')) {
                $order->set_customer_id($userId);
            }
        }

        $paymentMethod = $previousPaymentMethod;
        if (isset($_POST['hb_ucs_sub_payment_method'])) {
            $paymentMethod = sanitize_text_field((string) wp_unslash($_POST['hb_ucs_sub_payment_method']));
            update_post_meta($orderId, SubscriptionRepository::LEGACY_PAYMENT_METHOD_META, $paymentMethod);

            if (method_exists($order, 'set_payment_method')) {
                $order->set_payment_method($paymentMethod);
            }
        }

        $savedPaymentMethodTitle = $previousPaymentMethodTitle;
        if (isset($_POST['hb_ucs_sub_payment_method_title'])) {
            $paymentMethodTitle = sanitize_text_field((string) wp_unslash($_POST['hb_ucs_sub_payment_method_title']));
            if ($paymentMethodTitle === '' && $paymentMethod !== '') {
                $paymentMethodTitle = $this->get_payment_method_title_for_admin($paymentMethod);
            }

            update_post_meta($orderId, SubscriptionRepository::LEGACY_PAYMENT_METHOD_TITLE_META, $paymentMethodTitle);
            $savedPaymentMethodTitle = $paymentMethodTitle;
            if (method_exists($order, 'set_payment_method_title')) {
                $order->set_payment_method_title($paymentMethodTitle);
            }
        }

        if (isset($_POST['hb_ucs_items_present'])) {
            $this->persist_posted_subscription_items($orderId, $order);
        }

        if (isset($_POST['hb_ucs_fees_present'])) {
            $this->persist_posted_subscription_fee_lines($orderId);
        }

        $savedShippingLines = null;
        if (isset($_POST['hb_ucs_shipping_lines_present'])) {
            $savedShippingLines = $this->persist_posted_subscription_shipping_lines($orderId, $order);
        }

        foreach ([
            'hb_ucs_sub_mollie_customer_id' => SubscriptionRepository::LEGACY_MOLLIE_CUSTOMER_ID_META,
            'hb_ucs_sub_mollie_mandate_id' => SubscriptionRepository::LEGACY_MOLLIE_MANDATE_ID_META,
            'hb_ucs_sub_last_payment_id' => SubscriptionRepository::LEGACY_LAST_PAYMENT_ID_META,
        ] as $inputKey => $metaKey) {
            if (!isset($_POST[$inputKey])) {
                continue;
            }

            $value = sanitize_text_field((string) wp_unslash($_POST[$inputKey]));
            if ($value !== '') {
                update_post_meta($orderId, $metaKey, $value);
            } else {
                delete_post_meta($orderId, $metaKey);
            }
        }

        if (isset($_POST['hb_ucs_sub_payment_mode'])) {
            $paymentMode = sanitize_text_field((string) wp_unslash($_POST['hb_ucs_sub_payment_mode']));
            if ($paymentMode !== '') {
                update_post_meta($orderId, '_mollie_payment_mode', $paymentMode);
                if (method_exists($order, 'update_meta_data')) {
                    $order->update_meta_data('_mollie_payment_mode', $paymentMode);
                }
            } else {
                delete_post_meta($orderId, '_mollie_payment_mode');
                if (method_exists($order, 'delete_meta_data')) {
                    $order->delete_meta_data('_mollie_payment_mode');
                }
            }
        }

        $mollieMetaMap = [
            SubscriptionRepository::LEGACY_MOLLIE_CUSTOMER_ID_META => '_mollie_customer_id',
            SubscriptionRepository::LEGACY_MOLLIE_MANDATE_ID_META => '_mollie_mandate_id',
            SubscriptionRepository::LEGACY_LAST_PAYMENT_ID_META => '_mollie_payment_id',
        ];

        if (method_exists($order, 'update_meta_data') && method_exists($order, 'delete_meta_data')) {
            foreach ($mollieMetaMap as $subscriptionMetaKey => $orderMetaKey) {
                $value = (string) get_post_meta($orderId, $subscriptionMetaKey, true);
                if ($value !== '') {
                    $order->update_meta_data($orderMetaKey, $value);
                } else {
                    $order->delete_meta_data($orderMetaKey);
                }
            }
        }

        $savedDates = $previousDates;
        $manualNextPaymentOverride = null;
        foreach ([
            'hb_ucs_sub_next_payment' => [
                'primary' => '_hb_ucs_subscription_next_payment',
                'legacy' => SubscriptionRepository::LEGACY_NEXT_PAYMENT_META,
            ],
            'hb_ucs_sub_trial_end' => [
                'primary' => '_hb_ucs_subscription_trial_end',
                'legacy' => SubscriptionRepository::LEGACY_TRIAL_END_META,
            ],
            'hb_ucs_sub_end_date' => [
                'primary' => '_hb_ucs_subscription_end_date',
                'legacy' => SubscriptionRepository::LEGACY_END_DATE_META,
            ],
        ] as $inputKey => $metaKeys) {
            if (!isset($_POST[$inputKey])) {
                continue;
            }

            $metaKey = (string) ($metaKeys['primary'] ?? '');
            $legacyMetaKey = (string) ($metaKeys['legacy'] ?? '');
            if ($metaKey === '' || $legacyMetaKey === '') {
                continue;
            }

            $timestamp = $this->parse_datetime_local((string) wp_unslash($_POST[$inputKey]));
            $previousTimestamp = (int) ($previousDates[$metaKey] ?? 0);
            if (
                $inputKey === 'hb_ucs_sub_next_payment'
                && $this->is_create_renewal_order_request()
                && $timestamp > 0
            ) {
                $persistedTimestamp = $this->get_persisted_subscription_timestamp_for_order(
                    $orderId,
                    '_hb_ucs_subscription_next_payment',
                    SubscriptionRepository::LEGACY_NEXT_PAYMENT_META
                );
                $previousTimestamp = (int) ($previousDates['_hb_ucs_subscription_next_payment'] ?? 0);

                if ($persistedTimestamp > 0 && $persistedTimestamp !== $timestamp && $timestamp === $previousTimestamp) {
                    $timestamp = $persistedTimestamp;
                }
            }

            if ($timestamp > 0) {
                if ($metaKey === '_hb_ucs_subscription_next_payment' && method_exists($order, 'set_next_payment_timestamp')) {
                    $order->set_next_payment_timestamp($timestamp);
                } else {
                    $this->update_subscription_order_meta_pair($order, $orderId, $metaKey, $legacyMetaKey, $timestamp);
                }
                $savedDates[$metaKey] = $timestamp;
                if ($inputKey === 'hb_ucs_sub_next_payment' && $timestamp !== $previousTimestamp) {
                    $manualNextPaymentOverride = '1';
                }
            } else {
                if ($metaKey === '_hb_ucs_subscription_next_payment' && method_exists($order, 'set_next_payment_timestamp')) {
                    $order->set_next_payment_timestamp(0);
                } else {
                    $this->update_subscription_order_meta_pair($order, $orderId, $metaKey, $legacyMetaKey, null, true);
                }
                $savedDates[$metaKey] = 0;
                if ($inputKey === 'hb_ucs_sub_next_payment' && $previousTimestamp > 0) {
                    $manualNextPaymentOverride = '';
                }
            }
        }

        if ($manualNextPaymentOverride !== null) {
            if ($manualNextPaymentOverride !== '') {
                if (method_exists($order, 'update_meta_data')) {
                    $order->update_meta_data(SubscriptionRepository::MANUAL_NEXT_PAYMENT_OVERRIDE_META, '1');
                } else {
                    update_post_meta($orderId, SubscriptionRepository::MANUAL_NEXT_PAYMENT_OVERRIDE_META, '1');
                }
            } else {
                if (method_exists($order, 'delete_meta_data')) {
                    $order->delete_meta_data(SubscriptionRepository::MANUAL_NEXT_PAYMENT_OVERRIDE_META);
                } else {
                    delete_post_meta($orderId, SubscriptionRepository::MANUAL_NEXT_PAYMENT_OVERRIDE_META);
                }
            }
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }

        $this->log_subscription_sync('admin.save.after_order_save', $order, [
            'saved_status' => $savedStatus,
            'saved_scheme' => $savedScheme,
            'saved_dates' => $savedDates,
        ]);

        $shadowMetaUpdates = [
            '_hb_ucs_subscription_status' => $savedStatus,
            SubscriptionRepository::LEGACY_STATUS_META => $savedStatus,
            '_hb_ucs_subscription_scheme' => $savedScheme,
            SubscriptionRepository::LEGACY_SCHEME_META => $savedScheme,
            '_hb_ucs_subscription_next_payment' => (int) ($savedDates['_hb_ucs_subscription_next_payment'] ?? 0),
            SubscriptionRepository::LEGACY_NEXT_PAYMENT_META => (int) ($savedDates['_hb_ucs_subscription_next_payment'] ?? 0),
            '_hb_ucs_subscription_trial_end' => (int) ($savedDates['_hb_ucs_subscription_trial_end'] ?? 0),
            SubscriptionRepository::LEGACY_TRIAL_END_META => (int) ($savedDates['_hb_ucs_subscription_trial_end'] ?? 0),
            '_hb_ucs_subscription_end_date' => (int) ($savedDates['_hb_ucs_subscription_end_date'] ?? 0),
            SubscriptionRepository::LEGACY_END_DATE_META => (int) ($savedDates['_hb_ucs_subscription_end_date'] ?? 0),
        ];

        if ($manualNextPaymentOverride !== null) {
            $shadowMetaUpdates[SubscriptionRepository::MANUAL_NEXT_PAYMENT_OVERRIDE_META] = $manualNextPaymentOverride;
        }

        $this->persist_subscription_shadow_meta($orderId, $shadowMetaUpdates);

        $this->log_subscription_sync('admin.save.after_shadow_meta', $order, [
            'shadow_meta_updates' => $shadowMetaUpdates,
        ]);

        $this->schedule_deferred_order_type_sync($orderId);

        $this->log_subscription_sync('admin.save.after_sync_legacy', $order, [
            'linked_legacy_post_id' => 0,
            'sync_result' => [],
            'deferred_sync_scheduled' => true,
        ]);

        $changes = [];

        if ($previousStatus !== $savedStatus) {
            $statuses = $this->get_subscription_statuses();
            $changes[] = sprintf(
                __('Status: %1$s → %2$s.', 'hb-ucs'),
                $statuses[$previousStatus] ?? ($previousStatus !== '' ? $previousStatus : '—'),
                $statuses[$savedStatus] ?? ($savedStatus !== '' ? $savedStatus : '—')
            );
        }

        if ($previousScheme !== $savedScheme) {
            $schemes = $this->get_schedule_options();
            $changes[] = sprintf(
                __('Frequentie: %1$s → %2$s.', 'hb-ucs'),
                $schemes[$previousScheme] ?? ($previousScheme !== '' ? $previousScheme : '—'),
                $schemes[$savedScheme] ?? ($savedScheme !== '' ? $savedScheme : '—')
            );
        }

        foreach ([
            '_hb_ucs_subscription_next_payment' => __('Volgende betaling', 'hb-ucs'),
            '_hb_ucs_subscription_trial_end' => __('Einde proefabonnement', 'hb-ucs'),
            '_hb_ucs_subscription_end_date' => __('Einddatum', 'hb-ucs'),
        ] as $metaKey => $label) {
            $oldValue = (int) ($previousDates[$metaKey] ?? 0);
            $newValue = (int) ($savedDates[$metaKey] ?? 0);
            if ($oldValue === $newValue) {
                continue;
            }

            $changes[] = sprintf(
                __('%1$s: %2$s → %3$s.', 'hb-ucs'),
                $label,
                $oldValue > 0 ? $this->format_datetime_for_site($oldValue) : '—',
                $newValue > 0 ? $this->format_datetime_for_site($newValue) : '—'
            );
        }

        if ($previousUserId !== $savedUserId) {
            $changes[] = sprintf(
                __('Klant: %1$s → %2$s.', 'hb-ucs'),
                $this->get_customer_label_for_note($previousUserId),
                $this->get_customer_label_for_note($savedUserId)
            );
        }

        $previousPaymentLabel = $previousPaymentMethodTitle !== '' ? $previousPaymentMethodTitle : $previousPaymentMethod;
        $newPaymentLabel = $savedPaymentMethodTitle !== '' ? $savedPaymentMethodTitle : $paymentMethod;
        if ($previousPaymentLabel !== $newPaymentLabel) {
            $changes[] = sprintf(
                __('Betaalmethode: %1$s → %2$s.', 'hb-ucs'),
                $previousPaymentLabel !== '' ? $previousPaymentLabel : '—',
                $newPaymentLabel !== '' ? $newPaymentLabel : '—'
            );
        }

        if ($savedShippingLines !== null) {
            $newShippingLabel = $this->get_primary_shipping_line_label($savedShippingLines);
            if ($previousShippingLabel !== $newShippingLabel) {
                $changes[] = sprintf(
                    __('Verzendmethode: %1$s → %2$s.', 'hb-ucs'),
                    $previousShippingLabel !== '' ? $previousShippingLabel : '—',
                    $newShippingLabel !== '' ? $newShippingLabel : '—'
                );
            }
        }

        if (!empty($changes) && method_exists($order, 'add_order_note')) {
            $order->add_order_note(sprintf(
                __('Abonnement bijgewerkt via de backend. %s', 'hb-ucs'),
                implode(' ', $changes)
            ));
        }
    }

    public function get_service(): SubscriptionService {
        return $this->service;
    }

    public function process_deferred_order_type_sync(): void {
        if (empty(self::$deferredSyncOrderIds)) {
            return;
        }

        $orderIds = array_keys(self::$deferredSyncOrderIds);
        self::$deferredSyncOrderIds = [];
        $repository = $this->service->get_repository();

        foreach ($orderIds as $orderId) {
            $orderId = (int) $orderId;
            if ($orderId <= 0) {
                continue;
            }

            $order = $this->get_subscription_order($orderId);
            if (!$order) {
                continue;
            }

            $linkedLegacyPostId = $repository->get_linked_legacy_post_id($order);
            $syncResult = $repository->sync_order_type_self($orderId, false);

            if (isset(self::$postedShippingLinesByOrderId[$orderId])) {
                $postedShippingLines = self::$postedShippingLinesByOrderId[$orderId];
                unset(self::$postedShippingLinesByOrderId[$orderId]);

                update_post_meta($orderId, SubscriptionRepository::LEGACY_SHIPPING_LINES_META, $postedShippingLines);
                $order = $this->get_subscription_order($orderId);
                if ($order && is_object($order)) {
                    if (method_exists($order, 'update_meta_data')) {
                        $order->update_meta_data(SubscriptionRepository::LEGACY_SHIPPING_LINES_META, $postedShippingLines);
                    }
                    $this->replace_subscription_order_shipping_items($order, $postedShippingLines);
                    if (method_exists($order, 'save')) {
                        $order->save();
                    }
                }
            }

            $this->log_subscription_sync('admin.deferred_sync.completed', $order, [
                'linked_legacy_post_id' => $linkedLegacyPostId,
                'sync_result' => is_array($syncResult) ? $syncResult : [],
            ]);
        }
    }

    private function redirect_legacy_screen(\WP_Screen $screen): void {
        $redirectUrl = '';

        if ((string) $screen->base === 'edit') {
            $redirectUrl = $this->get_order_type_list_url();
        } else {
            $legacySubscriptionId = isset($_GET['post']) ? (int) absint((string) wp_unslash($_GET['post'])) : 0;
            if ($legacySubscriptionId > 0) {
                $this->service->ensure_order_type_record($legacySubscriptionId);
                $redirectUrl = $this->service->get_preferred_editor_url($legacySubscriptionId);
            }
        }

        if ($redirectUrl === '') {
            return;
        }

        wp_safe_redirect($redirectUrl);
        exit;
    }

    private function schedule_deferred_order_type_sync(int $orderId): void {
        if ($orderId <= 0) {
            return;
        }

        self::$deferredSyncOrderIds[$orderId] = true;
    }

    private function get_order_screen_ids(): array {
        return [
            'woocommerce_page_wc-orders--' . $this->orderType->get_type(),
            'admin_page_wc-orders--' . $this->orderType->get_type(),
        ];
    }

    private function get_active_order_screen_id(): string {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen instanceof \WP_Screen && in_array((string) $screen->id, $this->get_order_screen_ids(), true)) {
            return (string) $screen->id;
        }

        return $this->get_order_screen_ids()[0];
    }

    private function get_order_type_list_url(): string {
        return admin_url('admin.php?page=wc-orders--' . $this->orderType->get_type());
    }

    private function get_current_list_order_date_labels(): array {
        if (!$this->is_subscription_order_type_screen() || !function_exists('wc_get_orders')) {
            return [];
        }

        $statuses = isset($_GET['status']) ? (array) wp_unslash($_GET['status']) : [];
        $statuses = array_values(array_filter(array_map('sanitize_key', $statuses)));
        if (empty($statuses) || in_array('all', $statuses, true)) {
            $statuses = array_intersect(
                array_keys(wc_get_order_statuses()),
                get_post_stati(['show_in_admin_all_list' => true], 'names')
            );
        }

        $page = isset($_GET['paged']) ? max(1, (int) absint((string) wp_unslash($_GET['paged']))) : 1;
        $limit = (int) get_user_option('edit_' . $this->orderType->get_type() . '_per_page');
        if ($limit <= 0) {
            $limit = 20;
        }

        $queryArgs = [
            'type' => $this->orderType->get_type(),
            'status' => $statuses,
            'limit' => $limit,
            'page' => $page,
            'orderby' => isset($_GET['orderby']) ? sanitize_text_field((string) wp_unslash($_GET['orderby'])) : 'date',
            'order' => isset($_GET['order']) ? sanitize_text_field((string) wp_unslash($_GET['order'])) : 'DESC',
            'return' => 'objects',
        ];

        $queryArgs = $this->filter_order_type_query_args($queryArgs);
        $orders = wc_get_orders($queryArgs);
        if (!is_array($orders)) {
            return [];
        }

        $format = get_option('date_format') . ' ' . get_option('time_format');
        $labels = [];
        foreach ($orders as $order) {
            if (!$order || !is_object($order) || !method_exists($order, 'get_id') || !method_exists($order, 'get_date_created')) {
                continue;
            }

            $dateCreated = $order->get_date_created();
            if (!$dateCreated) {
                continue;
            }

            $labels[(string) $order->get_id()] = $dateCreated->date_i18n($format);
        }

        return $labels;
    }

    private function get_subscription_order($postOrOrder) {
        if ($postOrOrder && is_object($postOrOrder) && method_exists($postOrOrder, 'get_type') && (string) $postOrOrder->get_type() === $this->orderType->get_type()) {
            return $postOrOrder;
        }

        if ($postOrOrder && is_object($postOrOrder) && isset($postOrOrder->ID)) {
            $postOrOrder = (int) $postOrOrder->ID;
        }

        if (is_numeric($postOrOrder) && function_exists('wc_get_order')) {
            $order = wc_get_order((int) $postOrOrder);
            if ($order && is_object($order) && method_exists($order, 'get_type') && (string) $order->get_type() === $this->orderType->get_type()) {
                return $order;
            }
        }

        return null;
    }

    private function get_subscription_status_for_order($order): string {
        if (!$order || !is_object($order)) {
            return 'pending_mandate';
        }

        $status = method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_status', true) : '';
        if ($status === '') {
            $status = method_exists($order, 'get_meta') ? (string) $order->get_meta(SubscriptionRepository::LEGACY_STATUS_META, true) : '';
        }

        if ($status !== '') {
            return $status;
        }

        $orderStatus = method_exists($order, 'get_status') ? (string) $order->get_status() : 'pending';
        switch ($orderStatus) {
            case 'processing':
            case 'completed':
                return 'active';
            case 'on-hold':
                return 'on-hold';
            case 'cancelled':
                return 'cancelled';
            case 'failed':
                return 'expired';
            case 'pending':
            default:
                return 'pending_mandate';
        }
    }

    private function get_display_subscription_status_for_order($order): string {
        if ($this->is_order_in_trash($order)) {
            return 'trash';
        }

        return $this->get_subscription_status_for_order($order);
    }

    private function is_order_in_trash($order): bool {
        if (!$order || !is_object($order)) {
            return false;
        }

        $orderStatus = method_exists($order, 'get_status') ? sanitize_key((string) $order->get_status()) : '';
        if ($orderStatus === 'trash') {
            return true;
        }

        $orderId = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;

        return $orderId > 0 && get_post_status($orderId) === 'trash';
    }

    private function get_subscription_timestamp_for_order($order, string $primaryMetaKey, string $fallbackMetaKey): int {
        if (!$order || !is_object($order) || !method_exists($order, 'get_meta')) {
            return 0;
        }

        $timestamp = (int) $order->get_meta($primaryMetaKey, true);
        if ($timestamp > 0) {
            return $timestamp;
        }

        return (int) $order->get_meta($fallbackMetaKey, true);
    }

    private function get_persisted_subscription_timestamp_for_order(int $orderId, string $primaryMetaKey, string $fallbackMetaKey): int {
        if ($orderId <= 0) {
            return 0;
        }

        $timestamp = (int) get_post_meta($orderId, $primaryMetaKey, true);
        if ($timestamp > 0) {
            return $timestamp;
        }

        return (int) get_post_meta($orderId, $fallbackMetaKey, true);
    }

    private function is_create_renewal_order_request(): bool {
        if (!isset($_POST['wc_order_action'])) {
            return false;
        }

        return sanitize_key((string) wp_unslash($_POST['wc_order_action'])) === 'hb_ucs_create_renewal_order';
    }

    private function handle_subscription_status_action($order, string $status, string $note): void {
        $order = $this->get_subscription_order($order);
        if (!$order) {
            return;
        }

        $this->apply_subscription_status_to_order($order, $status);
        if (method_exists($order, 'save')) {
            $order->save();
        }

        $this->persist_subscription_shadow_meta((int) $order->get_id(), [
            '_hb_ucs_subscription_status' => $status,
            SubscriptionRepository::LEGACY_STATUS_META => $status,
        ]);

        $this->service->get_repository()->sync_order_type_self((int) $order->get_id(), false);

        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note($note);
        }
    }

    private function apply_subscription_status_to_order($order, string $status): void {
        $status = sanitize_key($status);

        if (method_exists($order, 'set_subscription_status')) {
            $order->set_subscription_status($status);
        } elseif (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('_hb_ucs_subscription_status', $status);
        }

        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data(SubscriptionRepository::LEGACY_STATUS_META, $status);
        }

        $mappedOrderStatus = preg_replace('/^wc-/', '', $this->map_subscription_status_to_order_status($status));
        if ($mappedOrderStatus !== '' && method_exists($order, 'set_status')) {
            $order->set_status($mappedOrderStatus, '', true);
        }
    }

    private function persist_subscription_shadow_meta(int $orderId, array $metaMap): void {
        if ($orderId <= 0) {
            return;
        }

        foreach ($metaMap as $metaKey => $metaValue) {
            if ($metaValue === '' || $metaValue === null || $metaValue === []) {
                delete_post_meta($orderId, (string) $metaKey);
                continue;
            }

            update_post_meta($orderId, (string) $metaKey, $metaValue);
        }
    }

    private function update_subscription_order_meta_pair($order, int $orderId, string $primaryMetaKey, string $legacyMetaKey, $value, bool $delete = false): void {
        if ($primaryMetaKey === '' || $legacyMetaKey === '') {
            return;
        }

        if (!$delete) {
            if ($order && is_object($order) && method_exists($order, 'update_meta_data')) {
                $order->update_meta_data($primaryMetaKey, $value);
                $order->update_meta_data($legacyMetaKey, $value);
                return;
            }

            if ($orderId > 0) {
                update_post_meta($orderId, $primaryMetaKey, $value);
                update_post_meta($orderId, $legacyMetaKey, $value);
            }

            return;
        }

        if ($order && is_object($order) && method_exists($order, 'delete_meta_data')) {
            $order->delete_meta_data($primaryMetaKey);
            $order->delete_meta_data($legacyMetaKey);
            return;
        }

        if ($orderId > 0) {
            delete_post_meta($orderId, $primaryMetaKey);
            delete_post_meta($orderId, $legacyMetaKey);
        }
    }

    private function log_subscription_sync(string $event, $order, array $context = []): void {
        $orderId = ($order && is_object($order) && method_exists($order, 'get_id')) ? (int) $order->get_id() : 0;
        $legacyPostId = $orderId > 0 ? $this->service->get_repository()->get_linked_legacy_post_id($order) : 0;

        $orderStatus = ($order && is_object($order) && method_exists($order, 'get_status')) ? (string) $order->get_status() : '';
        $orderSubscriptionStatus = ($order && is_object($order) && method_exists($order, 'get_meta')) ? (string) $order->get_meta('_hb_ucs_subscription_status', true) : '';
        $orderNextPayment = ($order && is_object($order) && method_exists($order, 'get_meta')) ? (int) $order->get_meta('_hb_ucs_subscription_next_payment', true) : 0;

        $legacyStatus = $legacyPostId > 0 ? (string) get_post_meta($legacyPostId, SubscriptionRepository::LEGACY_STATUS_META, true) : '';
        $legacyNextPayment = $legacyPostId > 0 ? (int) get_post_meta($legacyPostId, SubscriptionRepository::LEGACY_NEXT_PAYMENT_META, true) : 0;

        SubscriptionSyncLogger::debug($event, array_merge([
            'order_id' => $orderId,
            'legacy_post_id' => $legacyPostId,
            'order_status' => $orderStatus,
            'order_subscription_status' => $orderSubscriptionStatus,
            'order_next_payment' => $orderNextPayment,
            'legacy_status' => $legacyStatus,
            'legacy_next_payment' => $legacyNextPayment,
            'status_mismatch' => $orderSubscriptionStatus !== $legacyStatus,
            'next_payment_mismatch' => $orderNextPayment !== $legacyNextPayment,
        ], $context));
    }

    private function build_user_address_snapshot(int $userId, string $type): array {
        if ($userId <= 0) {
            return [];
        }

        $fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country', 'email', 'phone'];
        $snapshot = [];
        foreach ($fields as $field) {
            $metaKey = $type . '_' . $field;
            $value = (string) get_user_meta($userId, $metaKey, true);
            if ($value !== '') {
                $snapshot[$field] = $value;
            }
        }

        return $snapshot;
    }

    private function persist_posted_subscription_items(int $orderId, $order): array {
        $rawItems = isset($_POST['hb_ucs_items']) && is_array($_POST['hb_ucs_items'])
            ? (array) wp_unslash($_POST['hb_ucs_items'])
            : [];
        $existingItems = $this->get_subscription_items_for_order($orderId, $order);
        $normalized = [];

        foreach (array_values($rawItems) as $index => $rawItem) {
            if (!is_array($rawItem) || !empty($rawItem['remove'])) {
                continue;
            }

            $fallback = isset($existingItems[$index]) && is_array($existingItems[$index]) ? $existingItems[$index] : [];
            $item = $this->normalize_subscription_item_for_admin($rawItem, $fallback);
            if ($item !== null) {
                $normalized[] = $item;
            }
        }

        update_post_meta($orderId, SubscriptionRepository::LEGACY_ITEMS_META, $normalized);
        if ($order && is_object($order) && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data(SubscriptionRepository::LEGACY_ITEMS_META, $normalized);
        }
        $this->replace_subscription_order_line_items($order, $normalized);

        return $normalized;
    }

    private function get_subscription_items_for_order(int $orderId, $order): array {
        $stored = $order && is_object($order) && method_exists($order, 'get_meta')
            ? $order->get_meta(SubscriptionRepository::LEGACY_ITEMS_META, true)
            : get_post_meta($orderId, SubscriptionRepository::LEGACY_ITEMS_META, true);
        $lines = [];

        if (!is_array($stored)) {
            return [];
        }

        foreach (array_values($stored) as $row) {
            if (is_array($row)) {
                $lines[] = $row;
            }
        }

        return $lines;
    }

    private function normalize_subscription_item_for_admin(array $item, array $fallback = []): ?array {
        $selectedId = isset($item['product_id']) ? (int) absint((string) $item['product_id']) : (int) ($fallback['base_product_id'] ?? 0);
        $postedVariationId = isset($item['variation_id']) ? (int) absint((string) $item['variation_id']) : 0;
        $selectedAttributes = isset($item['selected_attributes']) && is_array($item['selected_attributes'])
            ? $this->sanitize_subscription_selected_attributes((array) $item['selected_attributes'])
            : (isset($fallback['selected_attributes']) && is_array($fallback['selected_attributes']) ? $this->sanitize_subscription_selected_attributes((array) $fallback['selected_attributes']) : []);

        if ($selectedId <= 0 || !function_exists('wc_get_product')) {
            return null;
        }

        $product = wc_get_product($selectedId);
        if (!$product || !is_object($product)) {
            return null;
        }

        $baseProductId = $selectedId;
        $baseVariationId = 0;

        if (method_exists($product, 'is_type') && $product->is_type('variation')) {
            $baseVariationId = method_exists($product, 'get_id') ? (int) $product->get_id() : $selectedId;
            $baseProductId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
            $selectedAttributes = $this->get_subscription_variation_attributes_for_admin($product);
        } elseif (method_exists($product, 'is_type') && $product->is_type('variable')) {
            $baseProductId = $selectedId;
            $baseVariationId = $this->resolve_subscription_variation_for_admin($product, $postedVariationId, $selectedAttributes);
            if ($baseVariationId <= 0) {
                return null;
            }
        }

        if ($baseProductId <= 0) {
            return null;
        }

        $qty = isset($item['qty']) ? (int) absint((string) $item['qty']) : (int) ($fallback['qty'] ?? 1);
        if ($qty <= 0) {
            $qty = 1;
        }

        $unitPrice = isset($item['unit_price'])
            ? (float) wc_format_decimal((string) $item['unit_price'])
            : (float) ($fallback['unit_price'] ?? 0.0);
        $taxes = isset($fallback['taxes']) && is_array($fallback['taxes']) ? (array) $fallback['taxes'] : [];
        $displayMeta = isset($item['meta']) && is_array($item['meta']) ? $this->sanitize_subscription_display_meta_for_admin((array) $item['meta']) : [];

        return [
            'base_product_id' => $baseProductId,
            'base_variation_id' => $baseVariationId,
            'source_order_item_id' => (int) ($fallback['source_order_item_id'] ?? 0),
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'price_includes_tax' => (int) ($fallback['price_includes_tax'] ?? 0),
            'taxes' => $taxes,
            'selected_attributes' => $selectedAttributes,
            'attribute_snapshot' => $selectedAttributes,
            'display_meta' => $displayMeta,
        ];
    }

    private function replace_subscription_order_line_items($order, array $items): void {
        if (!$order || !is_object($order) || !method_exists($order, 'get_items') || !method_exists($order, 'remove_item') || !method_exists($order, 'add_item')) {
            return;
        }

        foreach ((array) $order->get_items('line_item') as $itemId => $existingItem) {
            $order->remove_item($itemId);
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $baseProductId = (int) ($item['base_product_id'] ?? 0);
            $baseVariationId = (int) ($item['base_variation_id'] ?? 0);
            $targetProductId = $baseVariationId > 0 ? $baseVariationId : $baseProductId;
            $product = $targetProductId > 0 && function_exists('wc_get_product') ? wc_get_product($targetProductId) : false;
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $lineTotal = (float) ($item['unit_price'] ?? 0.0) * $qty;

            $orderItem = new \WC_Order_Item_Product();
            if ($product && is_object($product) && method_exists($orderItem, 'set_product')) {
                $orderItem->set_product($product);
            } else {
                $orderItem->set_product_id($baseProductId);
                $orderItem->set_variation_id($baseVariationId);
            }
            if ($baseVariationId > 0 && method_exists($orderItem, 'set_variation_id')) {
                $orderItem->set_variation_id($baseVariationId);
            }
            $orderItem->set_quantity($qty);
            $orderItem->set_subtotal($lineTotal);
            $orderItem->set_total($lineTotal);
            if (method_exists($orderItem, 'set_taxes')) {
                $orderItem->set_taxes(isset($item['taxes']) && is_array($item['taxes']) ? (array) $item['taxes'] : []);
            }
            $orderItem->add_meta_data('_hb_ucs_subscription_base_product_id', $baseProductId, true);
            if ($baseVariationId > 0) {
                $orderItem->add_meta_data('_hb_ucs_subscription_base_variation_id', $baseVariationId, true);
            }
            if (!empty($item['selected_attributes'])) {
                $orderItem->add_meta_data('_hb_ucs_subscription_selected_attributes', wp_json_encode((array) $item['selected_attributes']), true);
                $orderItem->add_meta_data('_hb_ucs_subscription_attribute_snapshot', wp_json_encode((array) $item['selected_attributes']), true);
            }
            foreach ((array) ($item['display_meta'] ?? []) as $displayMetaRow) {
                if (!is_array($displayMetaRow)) {
                    continue;
                }
                $label = trim((string) ($displayMetaRow['label'] ?? ''));
                $value = trim((string) ($displayMetaRow['value'] ?? ''));
                if ($label !== '' && $value !== '') {
                    $orderItem->add_meta_data($label, $value, true);
                }
            }

            $order->add_item($orderItem);
        }

        if (method_exists($order, 'update_taxes')) {
            $order->update_taxes();
        }
        if (method_exists($order, 'calculate_totals')) {
            $order->calculate_totals(false);
        }
    }

    private function resolve_subscription_variation_for_admin($product, int $variationId, array $selectedAttributes): int {
        if ($variationId > 0 && function_exists('wc_get_product')) {
            $variation = wc_get_product($variationId);
            if ($variation && is_object($variation) && method_exists($variation, 'is_type') && $variation->is_type('variation')) {
                $parentId = method_exists($variation, 'get_parent_id') ? (int) $variation->get_parent_id() : 0;
                $productId = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
                if ($parentId > 0 && $parentId === $productId) {
                    return $variationId;
                }
            }
        }

        if (empty($selectedAttributes) || !class_exists('WC_Data_Store')) {
            return 0;
        }

        try {
            $dataStore = \WC_Data_Store::load('product');
            if (!is_object($dataStore) || !method_exists($dataStore, 'find_matching_product_variation')) {
                return 0;
            }

            $variationAttributes = [];
            foreach ($selectedAttributes as $key => $value) {
                if ($value === '') {
                    continue;
                }
                $attributeKey = strpos((string) $key, 'attribute_') === 0 ? (string) $key : 'attribute_' . (string) $key;
                $variationAttributes[$attributeKey] = (string) $value;
            }

            return (int) $dataStore->find_matching_product_variation($product, $variationAttributes);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function get_subscription_variation_attributes_for_admin($variation): array {
        if (!$variation || !is_object($variation) || !method_exists($variation, 'get_attributes')) {
            return [];
        }

        return $this->sanitize_subscription_selected_attributes((array) $variation->get_attributes());
    }

    private function sanitize_subscription_selected_attributes(array $attributes): array {
        $normalized = [];
        foreach ($attributes as $key => $value) {
            $attributeKey = sanitize_title((string) $key);
            $attributeValue = sanitize_title((string) $value);
            if ($attributeKey !== '' && $attributeValue !== '') {
                $normalized[$attributeKey] = $attributeValue;
            }
        }

        return $normalized;
    }

    private function sanitize_subscription_display_meta_for_admin(array $rows): array {
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = sanitize_text_field((string) ($row['label'] ?? ''));
            $value = sanitize_text_field((string) ($row['value'] ?? ''));
            if ($label !== '' && $value !== '') {
                $normalized[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        return $normalized;
    }

    private function persist_posted_subscription_fee_lines(int $orderId): array {
        $rawLines = isset($_POST['hb_ucs_fees']) && is_array($_POST['hb_ucs_fees'])
            ? (array) wp_unslash($_POST['hb_ucs_fees'])
            : [];
        $normalized = [];

        foreach (array_values($rawLines) as $rawLine) {
            if (!is_array($rawLine) || !empty($rawLine['remove'])) {
                continue;
            }

            $line = $this->normalize_subscription_fee_line_for_admin($rawLine);
            if ($line !== null) {
                $normalized[] = $line;
            }
        }

        update_post_meta($orderId, SubscriptionRepository::LEGACY_FEE_LINES_META, $normalized);

        return $normalized;
    }

    private function persist_posted_subscription_shipping_lines(int $orderId, $order): array {
        $rawLines = isset($_POST['hb_ucs_shipping_lines']) && is_array($_POST['hb_ucs_shipping_lines'])
            ? (array) wp_unslash($_POST['hb_ucs_shipping_lines'])
            : [];
        $existingLines = $this->get_subscription_shipping_lines_for_order($orderId, $order);
        $normalized = [];

        foreach (array_values($rawLines) as $index => $rawLine) {
            if (!is_array($rawLine) || !empty($rawLine['remove'])) {
                continue;
            }

            $fallback = isset($existingLines[$index]) && is_array($existingLines[$index]) ? $existingLines[$index] : [];
            $line = $this->normalize_subscription_shipping_line_for_admin($rawLine, $fallback);
            if ($line !== null) {
                $normalized[] = $line;
            }
        }

        update_post_meta($orderId, SubscriptionRepository::LEGACY_SHIPPING_LINES_META, $normalized);
        self::$postedShippingLinesByOrderId[$orderId] = $normalized;
        if ($order && is_object($order) && method_exists($order, 'update_meta_data')) {
            $order->update_meta_data(SubscriptionRepository::LEGACY_SHIPPING_LINES_META, $normalized);
        }
        $this->replace_subscription_order_shipping_items($order, $normalized);
        if ($order && is_object($order) && method_exists($order, 'save')) {
            $order->save();
        }

        return $normalized;
    }

    private function replace_subscription_order_shipping_items($order, array $shippingLines): void {
        if (!$order || !is_object($order) || !method_exists($order, 'get_items') || !method_exists($order, 'remove_item') || !method_exists($order, 'add_item')) {
            return;
        }

        foreach ((array) $order->get_items('shipping') as $itemId => $existingItem) {
            $order->remove_item($itemId);
        }

        foreach ($shippingLines as $shippingLine) {
            if (!is_array($shippingLine)) {
                continue;
            }

            $item = new \WC_Order_Item_Shipping();
            if (method_exists($item, 'set_method_title')) {
                $item->set_method_title((string) ($shippingLine['method_title'] ?? __('Verzending', 'hb-ucs')));
            }
            if (method_exists($item, 'set_method_id')) {
                $item->set_method_id((string) ($shippingLine['method_id'] ?? ''));
            }
            if (method_exists($item, 'set_instance_id')) {
                $item->set_instance_id((int) ($shippingLine['instance_id'] ?? 0));
            }
            if (!empty($shippingLine['rate_key'])) {
                $item->add_meta_data('_hb_ucs_shipping_rate_key', sanitize_text_field((string) $shippingLine['rate_key']), true);
            }
            $item->set_total((float) ($shippingLine['total'] ?? 0.0));
            if (method_exists($item, 'set_taxes')) {
                $item->set_taxes(isset($shippingLine['taxes']) && is_array($shippingLine['taxes']) ? (array) $shippingLine['taxes'] : []);
            }

            $order->add_item($item);
        }

        if (method_exists($order, 'update_taxes')) {
            $order->update_taxes();
        }
        if (method_exists($order, 'calculate_totals')) {
            $order->calculate_totals(false);
        }
    }

    private function get_subscription_shipping_lines_for_order(int $orderId, $order): array {
        $stored = $order && is_object($order) && method_exists($order, 'get_meta')
            ? $order->get_meta(SubscriptionRepository::LEGACY_SHIPPING_LINES_META, true)
            : get_post_meta($orderId, SubscriptionRepository::LEGACY_SHIPPING_LINES_META, true);
        $hasStoredShippingLines = ($order && is_object($order) && method_exists($order, 'meta_exists') && $order->meta_exists(SubscriptionRepository::LEGACY_SHIPPING_LINES_META))
            || metadata_exists('post', $orderId, SubscriptionRepository::LEGACY_SHIPPING_LINES_META);
        $lines = [];

        if (is_array($stored)) {
            foreach (array_values($stored) as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $line = $this->normalize_subscription_shipping_line_for_admin($row);
                if ($line !== null) {
                    $lines[] = $line;
                }
            }

            return $lines;
        }

        if ($hasStoredShippingLines) {
            return [];
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            return [];
        }

        foreach ((array) $order->get_items('shipping') as $item) {
            if (!$item || !is_object($item)) {
                continue;
            }

            $line = $this->normalize_subscription_shipping_line_for_admin([
                'rate_key' => method_exists($item, 'get_meta') ? (string) $item->get_meta('_hb_ucs_shipping_rate_key', true) : '',
                'method_title' => method_exists($item, 'get_method_title') ? (string) $item->get_method_title() : '',
                'method_id' => method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '',
                'instance_id' => method_exists($item, 'get_instance_id') ? (int) $item->get_instance_id() : 0,
                'total' => method_exists($item, 'get_total') ? $item->get_total() : 0,
                'taxes' => method_exists($item, 'get_taxes') ? (array) $item->get_taxes() : [],
            ]);

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function get_primary_shipping_line_label(array $shippingLines): string {
        if (empty($shippingLines)) {
            return '';
        }

        $firstLine = reset($shippingLines);
        if (!is_array($firstLine)) {
            return '';
        }

        $methodTitle = trim((string) ($firstLine['method_title'] ?? ''));
        if ($methodTitle !== '') {
            return $methodTitle;
        }

        return trim((string) ($firstLine['method_id'] ?? ''));
    }

    private function normalize_subscription_fee_line_for_admin(array $line): ?array {
        $name = sanitize_text_field((string) ($line['name'] ?? ''));
        $total = isset($line['total']) ? (float) wc_format_decimal((string) $line['total']) : 0.0;
        $taxes = isset($line['taxes']) && is_array($line['taxes'])
            ? $this->normalize_subscription_tax_groups_for_admin($line['taxes'])
            : [];

        if ($name === '' && abs($total) < 0.0001 && empty($taxes)) {
            return null;
        }

        return [
            'name' => $name !== '' ? $name : __('Kosten', 'hb-ucs'),
            'total' => $total,
            'taxes' => $taxes,
        ];
    }

    private function normalize_subscription_shipping_line_for_admin(array $line, array $fallback = []): ?array {
        $methodId = sanitize_text_field((string) ($line['method_id'] ?? ($fallback['method_id'] ?? '')));
        $methodTitle = sanitize_text_field((string) ($line['method_title'] ?? ($fallback['method_title'] ?? '')));
        $instanceId = isset($line['instance_id'])
            ? (int) absint((string) $line['instance_id'])
            : (int) ($fallback['instance_id'] ?? 0);
        $rateKey = sanitize_text_field((string) ($line['rate_key'] ?? ($fallback['rate_key'] ?? '')));
        $total = isset($line['total'])
            ? (float) wc_format_decimal((string) $line['total'])
            : (isset($fallback['total']) ? (float) wc_format_decimal((string) $fallback['total']) : 0.0);
        $taxes = isset($line['taxes']) && is_array($line['taxes'])
            ? $this->normalize_subscription_tax_groups_for_admin($line['taxes'])
            : (isset($fallback['taxes']) && is_array($fallback['taxes']) ? $this->normalize_subscription_tax_groups_for_admin($fallback['taxes']) : []);

        if ($methodId === '' && $methodTitle === '' && abs($total) < 0.0001 && empty($taxes)) {
            return null;
        }

        if ($rateKey === '' && $methodId !== '') {
            $rateKey = $methodId . ':' . max(0, $instanceId);
        }

        return [
            'rate_key' => $rateKey,
            'method_id' => $methodId,
            'method_title' => $methodTitle !== '' ? $methodTitle : __('Verzending', 'hb-ucs'),
            'instance_id' => max(0, $instanceId),
            'total' => $total,
            'taxes' => $taxes,
        ];
    }

    private function normalize_subscription_tax_groups_for_admin(array $taxes): array {
        $normalized = [];

        foreach ($taxes as $taxGroupKey => $taxGroupValues) {
            if (!is_array($taxGroupValues)) {
                continue;
            }

            $groupKey = sanitize_key((string) $taxGroupKey);
            if ($groupKey === '') {
                continue;
            }

            foreach ($taxGroupValues as $taxRateId => $taxAmount) {
                $rateKey = is_numeric($taxRateId)
                    ? (string) absint((string) $taxRateId)
                    : sanitize_key((string) $taxRateId);
                if ($rateKey === '') {
                    continue;
                }

                if (!isset($normalized[$groupKey])) {
                    $normalized[$groupKey] = [];
                }

                $normalized[$groupKey][$rateKey] = wc_format_decimal((string) $taxAmount, wc_get_price_decimals());
            }
        }

        return $normalized;
    }

    private function get_schedule_options(): array {
        $supported = [
            '1w' => 1,
            '2w' => 2,
            '3w' => 3,
            '4w' => 4,
            '5w' => 5,
            '6w' => 6,
            '7w' => 7,
            '8w' => 8,
        ];

        $settings = get_option(Settings::OPT_SUBSCRIPTIONS, []);
        $frequencies = isset($settings['frequencies']) && is_array($settings['frequencies']) ? $settings['frequencies'] : [];
        $options = [];

        foreach ($supported as $schemeKey => $interval) {
            $row = isset($frequencies[$schemeKey]) && is_array($frequencies[$schemeKey]) ? $frequencies[$schemeKey] : [];
            $label = isset($row['label']) ? trim((string) $row['label']) : '';
            if ($label === '') {
                $label = $interval === 1
                    ? __('Elke week', 'hb-ucs')
                    : sprintf(__('Elke %d weken', 'hb-ucs'), $interval);
            }
            $options[$schemeKey] = $label;
        }

        return $options;
    }

    private function build_subscription_status_meta_query(string $status): array {
        return [
            'relation' => 'OR',
            [
                'key' => '_hb_ucs_subscription_status',
                'value' => $status,
                'compare' => '=',
            ],
            [
                'key' => SubscriptionRepository::LEGACY_STATUS_META,
                'value' => $status,
                'compare' => '=',
            ],
        ];
    }

    private function get_subscription_statuses(): array {
        return [
            'active' => __('Actief', 'hb-ucs'),
            'pending_mandate' => __('Wacht op mandate', 'hb-ucs'),
            'payment_pending' => __('Betaling in behandeling', 'hb-ucs'),
            'on-hold' => __('In de wacht', 'hb-ucs'),
            'paused' => __('Gepauzeerd', 'hb-ucs'),
            'cancelled' => __('Geannuleerd', 'hb-ucs'),
            'expired' => __('Verlopen', 'hb-ucs'),
        ];
    }

    private function get_subscription_status_to_order_status_map(): array {
        return [
            'active' => 'wc-processing',
            'pending_mandate' => 'wc-pending',
            'payment_pending' => 'wc-pending',
            'on-hold' => 'wc-on-hold',
            'paused' => 'wc-on-hold',
            'cancelled' => 'wc-cancelled',
            'expired' => 'wc-cancelled',
        ];
    }

    private function get_query_order_statuses_for_subscription_status(string $subscriptionStatus): array {
        switch (sanitize_key($subscriptionStatus)) {
            case 'active':
                return ['wc-processing', 'wc-completed'];
            case 'pending_mandate':
            case 'payment_pending':
                return ['wc-pending'];
            case 'on-hold':
            case 'paused':
                return ['wc-on-hold'];
            case 'cancelled':
                return ['wc-cancelled'];
            case 'expired':
                return ['wc-failed'];
            default:
                return [];
        }
    }

    private function map_subscription_status_to_order_status(string $subscriptionStatus): string {
        $subscriptionStatus = sanitize_key($subscriptionStatus);
        $map = $this->get_subscription_status_to_order_status_map();

        return $map[$subscriptionStatus] ?? 'wc-pending';
    }

    private function get_subscription_status_badge_html(string $status): string {
        $status = sanitize_key($status);
        $statuses = $this->get_subscription_statuses();
        $label = $status === 'trash'
            ? __('Verwijderd', 'hb-ucs')
            : (isset($statuses[$status]) ? (string) $statuses[$status] : ($status !== '' ? $status : '—'));
        $class = 'hb-ucs-subscription-status-badge hb-ucs-subscription-status-badge--' . sanitize_html_class($status !== '' ? $status : 'unknown');

        return '<span class="' . esc_attr($class) . '">' . esc_html($label) . '</span>';
    }

    private function parse_datetime_local(string $raw): int {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }

        try {
            $date = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $raw, wp_timezone());
            return $date ? (int) $date->getTimestamp() : 0;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function format_datetime_local(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }

        try {
            return wp_date('Y-m-d\\TH:i', $timestamp, wp_timezone());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function format_datetime_for_site(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }

        try {
            return wp_date(get_option('date_format', 'Y-m-d') . ' ' . get_option('time_format', 'H:i'), $timestamp, wp_timezone());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function get_customer_label_for_note(int $userId): string {
        if ($userId <= 0) {
            return __('Gast', 'hb-ucs');
        }

        $user = get_user_by('id', $userId);
        if (!$user instanceof \WP_User) {
            return sprintf(__('Klant #%d', 'hb-ucs'), $userId);
        }

        $name = trim((string) $user->display_name);
        $email = trim((string) $user->user_email);

        if ($name !== '' && $email !== '') {
            return sprintf('%1$s (%2$s)', $name, $email);
        }

        if ($name !== '') {
            return $name;
        }

        if ($email !== '') {
            return $email;
        }

        return sprintf(__('Klant #%d', 'hb-ucs'), $userId);
    }

    private function get_payment_method_title_for_admin(string $paymentMethod): string {
        $paymentMethod = trim($paymentMethod);
        if ($paymentMethod === '') {
            return '';
        }

        if ($paymentMethod === 'mollie_wc_gateway_directdebit') {
            return 'SEPA Direct Debit';
        }

        if (function_exists('WC') && WC() && WC()->payment_gateways()) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if (is_array($gateways) && isset($gateways[$paymentMethod]) && is_object($gateways[$paymentMethod])) {
                $gateway = $gateways[$paymentMethod];
                if (method_exists($gateway, 'get_title')) {
                    $title = wp_strip_all_tags((string) $gateway->get_title(), true);
                    if ($title !== '') {
                        return $title;
                    }
                }
                if (isset($gateway->title)) {
                    $title = wp_strip_all_tags((string) $gateway->title, true);
                    if ($title !== '') {
                        return $title;
                    }
                }
            }
        }

        return $paymentMethod;
    }

    private function count_related_orders(int $subscriptionId, int $parentOrderId): int {
        if ($subscriptionId <= 0 || !function_exists('wc_get_orders')) {
            return $parentOrderId > 0 ? 1 : 0;
        }

        $count = $parentOrderId > 0 ? 1 : 0;
        $orders = wc_get_orders([
            'limit' => 1,
            'paginate' => true,
            'return' => 'ids',
            'meta_key' => self::ORDER_META_SUBSCRIPTION_ID,
            'meta_value' => (string) $subscriptionId,
            'status' => array_keys(wc_get_order_statuses()),
        ]);

        if (is_object($orders) && isset($orders->total)) {
            $count += (int) $orders->total;
        }

        return $count;
    }

    private function render_related_order_row($order, string $label): string {
        $orderId = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        $link = admin_url('post.php?post=' . $orderId . '&action=edit');
        if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            $link = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($orderId);
        }

        $date = method_exists($order, 'get_date_created') && $order->get_date_created() ? $this->format_datetime_for_site((int) $order->get_date_created()->getTimestamp()) : '—';
        $status = method_exists($order, 'get_status') ? wc_get_order_status_name((string) $order->get_status()) : '—';
        $total = method_exists($order, 'get_formatted_order_total') ? $order->get_formatted_order_total() : '';

        return '<tr>'
            . '<td><a href="' . esc_url($link) . '">#' . esc_html((string) $orderId) . '</a></td>'
            . '<td>' . esc_html($label) . '</td>'
            . '<td>' . esc_html($status) . '</td>'
            . '<td>' . esc_html($date) . '</td>'
            . '<td>' . wp_kses_post($total !== '' ? $total : '—') . '</td>'
            . '</tr>';
    }
}
