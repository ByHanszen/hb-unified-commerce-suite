<?php

namespace HB\UCS\Modules\Subscriptions\Admin;

use HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository;
use HB\UCS\Modules\Subscriptions\Domain\SubscriptionService;
use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;

if (!defined('ABSPATH')) exit;

class SubscriptionAdmin {
    private const ORDER_META_SUBSCRIPTION_ID = '_hb_ucs_subscription_id';

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
            do_action('hb_ucs_subscription_admin_order_type_screen', $screen, $this->service, $this->orderType);
        }
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
            $status = $this->get_subscription_status_for_order($order);

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

        $status = isset($_GET['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_status'])) : '';
        if ($status !== '') {
            $metaQuery[] = [
                'key' => '_hb_ucs_subscription_status',
                'value' => $status,
                'compare' => '=',
            ];
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

            $freshOrder = $this->get_subscription_order((int) $order->get_id());
            if ($freshOrder) {
                $this->service->get_repository()->sync_legacy_from_order($freshOrder);
            }
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
        echo 'var mirroredSubscriptionSelect=document.querySelector("select[name=\"hb_ucs_sub_status\"]");';
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
        echo 'if(mirroredSubscriptionSelect){mirroredSubscriptionSelect.value=selectedSubscriptionStatus;}';
        echo 'mappedOrderStatusInput.value=subscriptionStatusOrderMap[selectedSubscriptionStatus]||"wc-pending";';
        echo '};';
        echo 'syncSubscriptionStatus();';
        echo 'mainOrderStatusSelect.addEventListener("change", syncSubscriptionStatus);';
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
        $this->service->get_repository()->sync_legacy_from_order($order);
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

        $this->service->get_repository()->sync_legacy_from_order($order);

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

        if (isset($_POST['hb_ucs_sub_status'])) {
            $candidate = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_status']));
            if (in_array($candidate, $allowedStatuses, true)) {
                $subscriptionStatus = $candidate;
            }
        }

        if ($subscriptionStatus === '' && isset($_POST['hb_ucs_sub_status_proxy'])) {
            $candidate = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_status_proxy']));
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
        $previousDates = [
            '_hb_ucs_subscription_next_payment' => $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_next_payment', SubscriptionRepository::LEGACY_NEXT_PAYMENT_META),
            '_hb_ucs_subscription_trial_end' => $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_trial_end', SubscriptionRepository::LEGACY_TRIAL_END_META),
            '_hb_ucs_subscription_end_date' => $this->get_subscription_timestamp_for_order($order, '_hb_ucs_subscription_end_date', SubscriptionRepository::LEGACY_END_DATE_META),
        ];

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
                } elseif (method_exists($order, 'update_meta_data')) {
                    $order->update_meta_data('_hb_ucs_subscription_scheme', $scheme);
                } else {
                    update_post_meta($orderId, '_hb_ucs_subscription_scheme', $scheme);
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
        foreach ([
            'hb_ucs_sub_next_payment' => '_hb_ucs_subscription_next_payment',
            'hb_ucs_sub_trial_end' => '_hb_ucs_subscription_trial_end',
            'hb_ucs_sub_end_date' => '_hb_ucs_subscription_end_date',
        ] as $inputKey => $metaKey) {
            if (!isset($_POST[$inputKey])) {
                continue;
            }

            $timestamp = $this->parse_datetime_local((string) wp_unslash($_POST[$inputKey]));
            if ($timestamp > 0) {
                if ($metaKey === '_hb_ucs_subscription_next_payment' && method_exists($order, 'set_next_payment_timestamp')) {
                    $order->set_next_payment_timestamp($timestamp);
                } elseif (method_exists($order, 'update_meta_data')) {
                    $order->update_meta_data($metaKey, $timestamp);
                } else {
                    update_post_meta($orderId, $metaKey, $timestamp);
                }
                $savedDates[$metaKey] = $timestamp;
            } else {
                if ($metaKey === '_hb_ucs_subscription_next_payment' && method_exists($order, 'set_next_payment_timestamp')) {
                    $order->set_next_payment_timestamp(0);
                } elseif (method_exists($order, 'delete_meta_data')) {
                    $order->delete_meta_data($metaKey);
                } else {
                    delete_post_meta($orderId, $metaKey);
                }
                $savedDates[$metaKey] = 0;
            }
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }

        $shadowMetaUpdates = [
            '_hb_ucs_subscription_status' => $savedStatus,
            SubscriptionRepository::LEGACY_STATUS_META => $savedStatus,
            '_hb_ucs_subscription_scheme' => $savedScheme,
            '_hb_ucs_subscription_next_payment' => (int) ($savedDates['_hb_ucs_subscription_next_payment'] ?? 0),
            '_hb_ucs_subscription_trial_end' => (int) ($savedDates['_hb_ucs_subscription_trial_end'] ?? 0),
            '_hb_ucs_subscription_end_date' => (int) ($savedDates['_hb_ucs_subscription_end_date'] ?? 0),
        ];

        $this->persist_subscription_shadow_meta($orderId, $shadowMetaUpdates);

        $freshOrder = $this->get_subscription_order($orderId);
        if ($freshOrder) {
            $this->service->get_repository()->sync_legacy_from_order($freshOrder);
        }

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

        $freshOrder = $this->get_subscription_order((int) $order->get_id());
        if ($freshOrder) {
            $this->service->get_repository()->sync_legacy_from_order($freshOrder);
        }

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

    private function get_schedule_options(): array {
        return [
            '1w' => __('Elke week', 'hb-ucs'),
            '2w' => __('Elke 2 weken', 'hb-ucs'),
            '3w' => __('Elke 3 weken', 'hb-ucs'),
            '4w' => __('Elke 4 weken', 'hb-ucs'),
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

    private function map_subscription_status_to_order_status(string $subscriptionStatus): string {
        $subscriptionStatus = sanitize_key($subscriptionStatus);
        $map = $this->get_subscription_status_to_order_status_map();

        return $map[$subscriptionStatus] ?? 'wc-pending';
    }

    private function get_subscription_status_badge_html(string $status): string {
        $status = sanitize_key($status);
        $statuses = $this->get_subscription_statuses();
        $label = isset($statuses[$status]) ? (string) $statuses[$status] : ($status !== '' ? $status : '—');
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
