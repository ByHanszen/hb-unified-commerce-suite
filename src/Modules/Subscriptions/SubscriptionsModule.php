<?php
// =============================
// src/Modules/Subscriptions/SubscriptionsModule.php
// =============================
namespace HB\UCS\Modules\Subscriptions;

use HB\UCS\Core\Settings;
use HB\UCS\Modules\Subscriptions\Support\SubscriptionSyncLogger;

if (!defined('ABSPATH')) exit;

class SubscriptionsModule {
    private const RENEWAL_CRON_RECURRENCE = 'hb_ucs_every_minute';
    private const ACCOUNT_ENDPOINT = 'abonnementen';
    private const PRODUCT_PICKER_MENU_LOCATION = 'hb_ucs_subscription_product_picker';
    private const ORDER_META_CONTAINS_SUBSCRIPTION = '_hb_ucs_contains_subscription';
    private const SHOP_ORDER_SUBSCRIPTION_FILTER_PARAM = 'hb_ucs_subscription_context';

    private const META_ENABLED = '_hb_ucs_subs_enabled';
    private const META_PRICE_PREFIX = '_hb_ucs_subs_price_'; // suffix: 1w|2w|3w|4w|5w|6w|7w|8w

    private const META_DISC_ENABLED_PREFIX = '_hb_ucs_subs_disc_enabled_';
    private const META_DISC_TYPE_PREFIX = '_hb_ucs_subs_disc_type_';      // percent|fixed
    private const META_DISC_VALUE_PREFIX = '_hb_ucs_subs_disc_value_';    // decimal

    private const CART_KEY = 'hb_ucs_subs';

    private const SUB_META_STATUS = '_hb_ucs_sub_status';
    private const SUB_META_USER_ID = '_hb_ucs_sub_user_id';
    private const SUB_META_PARENT_ORDER_ID = '_hb_ucs_sub_parent_order_id';
    private const SUB_META_BASE_PRODUCT_ID = '_hb_ucs_sub_base_product_id';
    private const SUB_META_BASE_VARIATION_ID = '_hb_ucs_sub_base_variation_id';
    private const SUB_META_SCHEME = '_hb_ucs_sub_scheme';
    private const SUB_META_INTERVAL = '_hb_ucs_sub_interval';
    private const SUB_META_PERIOD = '_hb_ucs_sub_period';
    private const SUB_META_NEXT_PAYMENT = '_hb_ucs_sub_next_payment'; // unix timestamp
    private const SUB_META_UNIT_PRICE = '_hb_ucs_sub_unit_price';
    private const SUB_META_QTY = '_hb_ucs_sub_qty';
    private const SUB_META_PAYMENT_METHOD = '_hb_ucs_sub_payment_method';
    private const SUB_META_PAYMENT_METHOD_TITLE = '_hb_ucs_sub_payment_method_title';
    private const SUB_META_MOLLIE_CUSTOMER_ID = '_hb_ucs_sub_mollie_customer_id';
    private const SUB_META_MOLLIE_MANDATE_ID = '_hb_ucs_sub_mollie_mandate_id';
    private const SUB_META_LAST_PAYMENT_ID = '_hb_ucs_sub_last_payment_id';
    private const SUB_META_ITEMS = '_hb_ucs_sub_items';
    private const SUB_META_FEE_LINES = '_hb_ucs_sub_fee_lines';
    private const SUB_META_MANUAL_TAX_RATES = '_hb_ucs_sub_manual_tax_rates';
    private const SUB_META_BILLING = '_hb_ucs_sub_billing';
    private const SUB_META_SHIPPING = '_hb_ucs_sub_shipping';
    private const SUB_META_SHIPPING_LINES = '_hb_ucs_sub_shipping_lines';

    private const SUB_META_TRIAL_END = '_hb_ucs_sub_trial_end'; // unix timestamp (optional)
    private const SUB_META_END_DATE = '_hb_ucs_sub_end_date'; // unix timestamp (optional)
    private const SUB_META_LAST_ORDER_ID = '_hb_ucs_sub_last_order_id';
    private const SUB_META_LAST_ORDER_DATE = '_hb_ucs_sub_last_order_date'; // unix timestamp
    private const ORDER_META_RECURRING_CREATED = '_hb_ucs_subs_recurring_created';
    private const ORDER_META_SUBSCRIPTION_ID = '_hb_ucs_subscription_id';
    private const ORDER_META_RENEWAL = '_hb_ucs_subscription_renewal';
    private const ORDER_META_RENEWAL_MODE = '_hb_ucs_subscription_renewal_mode';
    private const ORDER_META_RENEWAL_PROCESSED = '_hb_ucs_subscription_renewal_processed';
    private const ORDER_META_RENEWAL_NEXT_PAYMENT = '_hb_ucs_subscription_renewal_next_payment';
    private const ORDER_META_MOLLIE_PAYMENT_ID = '_hb_ucs_mollie_payment_id';
    private const ORDER_ITEM_META_SELECTED_ATTRIBUTES = '_hb_ucs_subscription_selected_attributes';
    private const ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT = '_hb_ucs_subscription_attribute_snapshot';
    private const ORDER_ITEM_META_SOURCE_ORDER_ITEM_ID = '_hb_ucs_subscription_source_order_item_id';
    private const ORDER_ITEM_META_CATALOG_UNIT_PRICE = '_hb_ucs_subscription_catalog_unit_price';

    /** @var \HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository|null */
    private $subscriptionRepository = null;

    /** @var \HB\UCS\Modules\Subscriptions\Domain\SubscriptionService|null */
    private $subscriptionService = null;

    /** @var \HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType|null */
    private $subscriptionOrderType = null;

    /** @var \HB\UCS\Modules\Subscriptions\Admin\SubscriptionAdmin|null */
    private $subscriptionAdmin = null;

    /** @var array<string,mixed>|null */
    private $settingsCache = null;

    /** @var array<int,array{is_subscription_order:bool,subscription_id:int,type:string}> */
    private $shopOrderSubscriptionContextCache = [];

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $this->bootstrap_phase1_architecture();

        add_action('init', [$this, 'register_account_endpoint']);
        add_action('init', [$this, 'register_product_picker_menu_location']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_fields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_fields']);
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_fields'], 10, 2);

        if (is_admin()) {
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
            add_action('woocommerce_admin_order_data_after_billing_address', [$this, 'render_subscription_mollie_admin_fields'], 20, 1);
            add_action('wp_ajax_hb_ucs_subscription_product_data', [$this, 'handle_subscription_product_data_ajax']);
            add_action('wp_ajax_hb_ucs_subscription_customer_details', [$this, 'handle_subscription_customer_details_ajax']);
            add_action('wp_ajax_hb_ucs_subscription_add_tax_rate', [$this, 'handle_subscription_add_tax_rate_ajax']);
            add_action('wp_ajax_hb_ucs_subscription_remove_tax_rate', [$this, 'handle_subscription_remove_tax_rate_ajax']);

            add_filter('manage_edit-shop_order_columns', [$this, 'filter_shop_order_subscription_columns'], 20);
            add_action('manage_shop_order_posts_custom_column', [$this, 'render_shop_order_subscription_column_legacy'], 20, 2);
            add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'filter_shop_order_subscription_columns'], 20);
            add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_shop_order_subscription_column_hpos'], 20, 2);
            add_action('restrict_manage_posts', [$this, 'render_shop_order_subscription_filters_legacy'], 20, 1);
            add_action('woocommerce_order_list_table_restrict_manage_orders', [$this, 'render_shop_order_subscription_filters_hpos'], 20, 2);
            add_action('pre_get_posts', [$this, 'filter_shop_order_subscription_query_legacy'], 20);
            add_filter('woocommerce_order_query_args', [$this, 'filter_shop_order_subscription_query_hpos'], 20, 1);

            add_action('admin_post_hb_ucs_subs_run_now', [$this, 'handle_run_renewals_now']);
            add_action('admin_post_hb_ucs_subs_create_demo', [$this, 'handle_create_demo_subscription']);
        }

        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 20);
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_purchase_options'], 15);
        add_filter('woocommerce_available_variation', [$this, 'add_variation_subscription_data'], 10, 3);
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 4);
        add_filter('woocommerce_add_to_cart_product_id', [$this, 'maybe_swap_product_id'], 10, 1);
        add_filter('woocommerce_add_to_cart_variation_id', [$this, 'maybe_swap_variation_id'], 10, 1);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);
        add_action('woocommerce_before_calculate_totals', [$this, 'maybe_apply_manual_subscription_pricing'], 20, 1);

        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_first_subscription_payment_gateways'], 20);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_first_payment_method'], 10, 2);

        add_filter('woocommerce_mollie_wc_gateway_ideal_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);
        add_filter('woocommerce_mollie_wc_gateway_idealpayment_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);
        add_filter('woocommerce_mollie_wc_gateway_creditcard_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);
        add_filter('woocommerce_mollie_wc_gateway_creditcardpayment_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);

        add_action('woocommerce_payment_complete', [$this, 'maybe_create_subscriptions_from_order'], 20, 1);
        add_action('woocommerce_checkout_order_processed', [$this, 'maybe_create_subscriptions_from_manual_order'], 20, 3);
        add_action('woocommerce_store_api_checkout_order_processed', [$this, 'maybe_handle_store_api_checkout_order_processed'], 20, 1);
        add_action('woocommerce_thankyou', [$this, 'maybe_create_subscriptions_from_manual_order'], 20, 1);
        add_action('woocommerce_order_status_on-hold', [$this, 'maybe_create_subscriptions_from_manual_order'], 20, 1);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_create_subscriptions_from_manual_order'], 20, 1);
        add_action('woocommerce_payment_complete', [$this, 'maybe_mark_manual_renewal_paid'], 30, 1);
        add_action('woocommerce_order_status_on-hold', [$this, 'maybe_promote_manual_subscription_order_to_processing'], 30, 1);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_mark_manual_renewal_paid'], 30, 1);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_mark_manual_renewal_paid'], 30, 1);
        add_filter('woocommerce_email_classes', [$this, 'register_renewal_email_classes']);
        add_action('woocommerce_order_status_on-hold', [$this, 'maybe_trigger_on_hold_renewal_email'], 40, 1);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_trigger_processing_renewal_email'], 40, 1);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_trigger_completed_renewal_email'], 40, 1);
        add_filter('woocommerce_email_enabled_new_order', [$this, 'maybe_disable_default_order_email_for_subscription_context'], 10, 2);
        add_filter('woocommerce_email_enabled_cancelled_order', [$this, 'maybe_disable_default_order_email_for_subscription_context'], 10, 2);
        add_filter('woocommerce_email_enabled_failed_order', [$this, 'maybe_disable_default_order_email_for_subscription_context'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_processing_order', [$this, 'maybe_disable_default_order_email_for_subscription_context'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_completed_order', [$this, 'maybe_disable_default_order_email_for_subscription_context'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [$this, 'maybe_disable_default_order_email_for_subscription_context'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_invoice', [$this, 'maybe_disable_default_order_email_for_subscription_context'], 10, 2);

        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('init', [$this, 'ensure_cron_scheduled']);
        add_action('hb_ucs_subs_process_renewals', [$this, 'process_due_renewals']);

        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('admin_post_nopriv_hb_ucs_run_renewals', [$this, 'handle_renewals_admin_post']);
        add_action('admin_post_hb_ucs_run_renewals', [$this, 'handle_renewals_admin_post']);
        add_action('template_redirect', [$this, 'maybe_handle_mollie_webhook']);
        add_action('template_redirect', [$this, 'maybe_handle_renewals_cron_request']);
        add_action('template_redirect', [$this, 'maybe_handle_account_subscription_action']);
        add_action('wp_ajax_hb_ucs_subscription_product_picker_search', [$this, 'handle_subscription_product_picker_search_ajax']);
        add_action('wp_ajax_nopriv_hb_ucs_subscription_product_picker_search', [$this, 'handle_subscription_product_picker_search_ajax']);

        add_filter('woocommerce_account_menu_items', [$this, 'add_account_menu_item']);
        add_action('woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', [$this, 'render_account_endpoint']);
        add_shortcode('hb_ucs_subscriptions_account', [$this, 'render_account_shortcode']);
        add_action('woocommerce_save_account_details', [$this, 'sync_subscriptions_from_account_details'], 20, 1);
        add_action('woocommerce_customer_save_address', [$this, 'sync_subscriptions_from_customer_address'], 20, 2);

        if (did_action('elementor/loaded')) {
            add_action('elementor/element/woocommerce-my-account/section_menu_icon_content/before_section_end', [$this, 'extend_elementor_my_account_widget_tabs'], 10, 2);
        }

        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);
        add_action('woocommerce_checkout_create_order', [$this, 'mark_order_subscription_meta'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_meta'], 10, 4);
        add_action('woocommerce_reduce_order_stock', [$this, 'maybe_reduce_base_stock'], 10, 1);
        add_action('woocommerce_restore_order_stock', [$this, 'maybe_restore_base_stock'], 10, 1);
        add_filter('woocommerce_hidden_order_itemmeta', [$this, 'filter_hidden_order_itemmeta'], 20, 1);
        add_filter('woocommerce_order_item_get_formatted_meta_data', [$this, 'filter_order_item_formatted_meta_data'], 20, 2);
    }

    private function bootstrap_phase1_architecture(): void {
        $this->get_subscription_order_type()->init();
        $this->get_subscription_admin()->init();
    }

    private function get_subscription_repository() {
        if (!$this->subscriptionRepository instanceof \HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository) {
            $this->subscriptionRepository = new \HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository();
        }

        return $this->subscriptionRepository;
    }

    private function get_subscription_service() {
        if (!$this->subscriptionService instanceof \HB\UCS\Modules\Subscriptions\Domain\SubscriptionService) {
            $this->subscriptionService = new \HB\UCS\Modules\Subscriptions\Domain\SubscriptionService($this->get_subscription_repository());
        }

        return $this->subscriptionService;
    }

    private function get_subscription_order_type() {
        if (!$this->subscriptionOrderType instanceof \HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType) {
            $this->subscriptionOrderType = new \HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType($this->get_subscription_repository());
        }

        return $this->subscriptionOrderType;
    }

    private function get_subscription_admin() {
        if (!$this->subscriptionAdmin instanceof \HB\UCS\Modules\Subscriptions\Admin\SubscriptionAdmin) {
            $this->subscriptionAdmin = new \HB\UCS\Modules\Subscriptions\Admin\SubscriptionAdmin($this->get_subscription_service(), $this->get_subscription_order_type());
        }

        return $this->subscriptionAdmin;
    }

    private function sync_subscription_order_type_record(int $subId): void {
        if ($subId <= 0) {
            return;
        }

        $record = $this->get_subscription_repository()->find($subId);
        if (is_array($record) && (($record['storage'] ?? '') === 'order_type')) {
            $this->get_subscription_repository()->sync_order_type_self($subId, false);
            return;
        }

        $this->get_subscription_repository()->ensure_order_type_record($subId, true);
    }

    private function apply_account_subscription_status_to_order($subscription, string $status): void {
        $status = sanitize_key($status);

        if (method_exists($subscription, 'set_subscription_status')) {
            $subscription->set_subscription_status($status);
        } elseif (method_exists($subscription, 'update_meta_data')) {
            $subscription->update_meta_data('_hb_ucs_subscription_status', $status);
        }

        if (method_exists($subscription, 'update_meta_data')) {
            $subscription->update_meta_data(self::SUB_META_STATUS, $status);
        }

        $mappedOrderStatus = $this->map_account_subscription_status_to_order_status($status);
        if ($mappedOrderStatus !== '' && method_exists($subscription, 'set_status')) {
            $subscription->set_status($mappedOrderStatus, '', true);
        }
    }

    private function map_account_subscription_status_to_order_status(string $status): string {
        $map = [
            'active' => 'processing',
            'pending_mandate' => 'pending',
            'payment_pending' => 'pending',
            'on-hold' => 'on-hold',
            'paused' => 'on-hold',
            'cancelled' => 'cancelled',
            'expired' => 'cancelled',
        ];

        return $map[sanitize_key($status)] ?? 'pending';
    }

    private function persist_account_subscription_shadow_meta(int $subId, array $metaMap): bool {
        if ($subId <= 0) {
            return false;
        }

        $changed = false;

        foreach ($metaMap as $metaKey => $metaValue) {
            $changed = $this->update_subscription_post_meta_if_changed($subId, (string) $metaKey, $metaValue) || $changed;
        }

        return $changed;
    }

    private function persist_subscription_runtime_state(int $subId, string $status = '', ?int $nextPayment = null): bool {
        if ($subId <= 0) {
            return false;
        }

        $metaMap = [];

        if ($status !== '') {
            $metaMap[self::SUB_META_STATUS] = $status;
            $metaMap['_hb_ucs_subscription_status'] = $status;
        }

        if ($nextPayment !== null) {
            $metaMap[self::SUB_META_NEXT_PAYMENT] = (int) $nextPayment;
            $metaMap['_hb_ucs_subscription_next_payment'] = (int) $nextPayment;
        }

        if (empty($metaMap)) {
            return false;
        }

        $changed = false;

        foreach ($this->get_subscription_runtime_state_target_ids($subId) as $targetId) {
            $changed = $this->persist_account_subscription_shadow_meta($targetId, $metaMap) || $changed;
        }

        return $changed;
    }

    private function get_subscription_schedule_step_seconds(int $subId): int {
        $schedule = $this->repair_subscription_schedule_meta($subId);
        $interval = max(1, (int) ($schedule['interval'] ?? 0));
        $period = sanitize_key((string) ($schedule['period'] ?? 'week'));

        if ($period === '' || $period === 'week' || $period === 'weeks') {
            return $interval * WEEK_IN_SECONDS;
        }

        return $interval * WEEK_IN_SECONDS;
    }

    private function get_subscription_minimum_next_payment(int $subId, int $referenceTimestamp = 0): int {
        if ($subId <= 0) {
            return 0;
        }

        $step = $this->get_subscription_schedule_step_seconds($subId);
        if ($step <= 0) {
            return 0;
        }

        if ($referenceTimestamp <= 0) {
            $referenceTimestamp = (int) get_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, true);
        }

        if ($referenceTimestamp <= 0) {
            return 0;
        }

        return $referenceTimestamp + $step;
    }

    private function normalize_subscription_next_payment(int $subId, int $candidateNextPayment = 0, int $referenceTimestamp = 0): int {
        if ($subId <= 0) {
            return 0;
        }

        $minimumNextPayment = $this->get_subscription_minimum_next_payment($subId, $referenceTimestamp);

        if ($candidateNextPayment <= 0) {
            return $minimumNextPayment;
        }

        if ($minimumNextPayment > 0 && $candidateNextPayment < $minimumNextPayment) {
            return $minimumNextPayment;
        }

        return $candidateNextPayment;
    }

    private function get_order_reference_timestamp($order): int {
        if (!$order || !is_object($order)) {
            return 0;
        }

        foreach (['get_date_paid', 'get_date_completed', 'get_date_created'] as $method) {
            if (!method_exists($order, $method)) {
                continue;
            }

            $date = $order->{$method}();
            if ($date) {
                return (int) $date->getTimestamp();
            }
        }

        return 0;
    }

    private function persist_subscription_last_order_state(int $subId, int $orderId, ?int $orderDate = null): bool {
        if ($subId <= 0 || $orderId <= 0) {
            return false;
        }

        $metaMap = [
            self::SUB_META_LAST_ORDER_ID => (string) $orderId,
        ];

        if ($orderDate !== null && $orderDate > 0) {
            $metaMap[self::SUB_META_LAST_ORDER_DATE] = (string) $orderDate;
        }

        $changed = false;
        foreach ($this->get_subscription_runtime_state_target_ids($subId) as $targetId) {
            foreach ($metaMap as $metaKey => $metaValue) {
                $changed = $this->update_subscription_post_meta_if_changed($targetId, $metaKey, $metaValue) || $changed;
            }
        }

        return $changed;
    }

    private function update_subscription_post_meta_if_changed(int $postId, string $metaKey, $metaValue): bool {
        if ($postId <= 0 || $metaKey === '') {
            return false;
        }

        if ($metaValue === '' || $metaValue === null || $metaValue === []) {
            if (!metadata_exists('post', $postId, $metaKey)) {
                return false;
            }

            delete_post_meta($postId, $metaKey);
            return true;
        }

        $current = get_post_meta($postId, $metaKey, true);
        if ($this->subscription_meta_values_equal($current, $metaValue)) {
            return false;
        }

        update_post_meta($postId, $metaKey, $metaValue);
        return true;
    }

    private function subscription_meta_values_equal($currentValue, $newValue): bool {
        if (is_array($currentValue) || is_array($newValue)) {
            return maybe_serialize($currentValue) === maybe_serialize($newValue);
        }

        if (is_bool($currentValue) || is_bool($newValue)) {
            return (bool) $currentValue === (bool) $newValue;
        }

        return (string) $currentValue === (string) $newValue;
    }

    /**
     * @return int[]
     */
    private function get_subscription_runtime_state_target_ids(int $subId): array {
        if ($subId <= 0) {
            return [];
        }

        $targetIds = [$subId];
        $record = $this->get_subscription_repository()->find($subId);

        if (is_array($record) && (($record['storage'] ?? '') === 'order_type')) {
            $legacyPostId = $this->get_subscription_repository()->get_linked_legacy_post_id($subId);
            if ($legacyPostId > 0) {
                $targetIds[] = $legacyPostId;
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $targetIds))));
    }

    /**
     * @return int[]
     */
    private function get_related_subscription_ids(int $subId): array {
        if ($subId <= 0) {
            return [];
        }

        $ids = [$subId];
        $record = $this->get_subscription_repository()->find($subId);

        if (is_array($record) && (($record['storage'] ?? '') === 'order_type')) {
            $legacyPostId = $this->get_subscription_repository()->get_linked_legacy_post_id($subId);
            if ($legacyPostId > 0) {
                $ids[] = $legacyPostId;
            }
        } else {
            $orderTypeRecord = $this->get_subscription_repository()->find_by_legacy_post_id($subId);
            if (is_array($orderTypeRecord) && (int) ($orderTypeRecord['id'] ?? 0) > 0) {
                $ids[] = (int) $orderTypeRecord['id'];
            }
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    /**
     * @return string[]
     */
    private function get_subscription_runtime_statuses(int $subId): array {
        $statuses = [];

        foreach ($this->get_subscription_runtime_state_target_ids($subId) as $targetId) {
            foreach ([self::SUB_META_STATUS, '_hb_ucs_subscription_status'] as $metaKey) {
                $status = sanitize_key((string) get_post_meta($targetId, $metaKey, true));
                if ($status !== '') {
                    $statuses[] = $status;
                }
            }
        }

        return array_values(array_unique($statuses));
    }

    private function get_subscription_runtime_blocking_status(int $subId): string {
        foreach ($this->get_subscription_runtime_statuses($subId) as $status) {
            if (in_array($status, ['on-hold', 'paused', 'cancelled', 'expired'], true)) {
                return $status;
            }
        }

        return '';
    }

    private function subscription_is_eligible_for_auto_renewal(int $subId): bool {
        if ($subId <= 0) {
            return false;
        }

        $statuses = $this->get_subscription_runtime_statuses($subId);

        if (empty($statuses)) {
            return false;
        }

        foreach ($statuses as $status) {
            if ($status !== 'active') {
                return false;
            }
        }

        return true;
    }

    private function persist_linked_legacy_subscription_items(int $subId, array $items): void {
        return;
    }

    private function persist_linked_legacy_subscription_shipping_lines(int $subId, array $lines): void {
        return;
    }

    private function persist_linked_legacy_subscription_addresses(int $subId, array $billing, array $shipping): void {
        return;
    }

    private function refresh_subscription_contact_snapshots_and_shipping(int $subId, array $billing, array $shipping): void {
        if ($subId <= 0) {
            return;
        }

        update_post_meta($subId, self::SUB_META_BILLING, $billing);
        update_post_meta($subId, self::SUB_META_SHIPPING, $shipping);
        $this->persist_linked_legacy_subscription_addresses($subId, $billing, $shipping);

        $items = $this->recalculate_subscription_item_taxes($subId, $this->get_subscription_items($subId));
        $this->persist_subscription_items($subId, $items);
        $this->persist_linked_legacy_subscription_items($subId, $items);

        $shippingLines = $this->calculate_subscription_shipping_lines($subId, $items);
        $this->persist_subscription_shipping_lines($subId, $shippingLines);
        $this->persist_linked_legacy_subscription_shipping_lines($subId, $shippingLines);
        $this->sync_subscription_order_type_record($subId);
    }

    private function order_contains_subscription($order): bool {
        if (!$order || !is_object($order)) {
            return false;
        }

        if (method_exists($order, 'get_meta')) {
            $flag = (string) $order->get_meta(self::ORDER_META_CONTAINS_SUBSCRIPTION, true);
            if ($flag === 'yes') {
                return true;
            }
        }

        if (!method_exists($order, 'get_items')) {
            return false;
        }

        foreach ($order->get_items('line_item') as $item) {
            if (!is_object($item) || !method_exists($item, 'get_meta')) {
                continue;
            }

            $scheme = (string) $item->get_meta('_hb_ucs_subscription_scheme', true);
            if ($scheme !== '' && $scheme !== '0') {
                return true;
            }
        }

        return false;
    }

    private function mollie_customer_storage_enabled(): bool {
        return get_option('mollie-payments-for-woocommerce_customer_details', 'yes') === 'yes';
    }

    private function get_mollie_payment_description($order): string {
        if (!$order || !is_object($order)) {
            return __('Bestelling', 'hb-ucs');
        }

        $orderNumber = method_exists($order, 'get_order_number') ? trim((string) $order->get_order_number()) : '';
        $fullName = method_exists($order, 'get_formatted_billing_full_name') ? trim(wp_strip_all_tags((string) $order->get_formatted_billing_full_name(), true)) : '';

        if ($fullName === '') {
            $firstName = method_exists($order, 'get_billing_first_name') ? trim((string) $order->get_billing_first_name()) : '';
            $lastName = method_exists($order, 'get_billing_last_name') ? trim((string) $order->get_billing_last_name()) : '';
            $fullName = trim($firstName . ' ' . $lastName);
        }

        $description = trim(sprintf('Bestelling %s %s', $orderNumber, $fullName));

        return $description !== '' ? $description : __('Bestelling', 'hb-ucs');
    }

    public function maybe_mark_mollie_first_payment(array $data, $order): array {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return $data;
        }
        if (!$this->order_contains_subscription($order)) {
            return $data;
        }

        if (isset($data['payment']) && is_array($data['payment'])) {
            $data['payment']['sequenceType'] = 'first';
            unset($data['payment']['captureMode'], $data['payment']['captureDelay']);
        } else {
            $data['sequenceType'] = 'first';
        }

        unset($data['captureMode'], $data['captureDelay']);

        return $data;
    }

    private function get_admin_template_path(string $template): string {
        return dirname(__DIR__, 3) . '/templates/admin/' . ltrim($template, '/');
    }

    private function render_admin_template(string $template, array $args = []): void {
        $path = $this->get_admin_template_path($template);
        if (!file_exists($path)) {
            return;
        }

        extract($args, EXTR_SKIP);
        include $path;
    }

    private function format_datetime_local(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable('@' . $timestamp);
            $dt = $dt->setTimezone(wp_timezone());
            return $dt->format('Y-m-d\\TH:i');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function format_date_input(int $timestamp): string {
        if ($timestamp <= 0) {
            return '';
        }

        try {
            $dt = new \DateTimeImmutable('@' . $timestamp);
            $dt = $dt->setTimezone(wp_timezone());
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function get_wp_date_format(): string {
        $format = (string) get_option('date_format');
        return $format !== '' ? $format : 'Y-m-d';
    }

    private function get_wp_time_format(): string {
        $format = (string) get_option('time_format');
        return $format !== '' ? $format : 'H:i';
    }

    private function get_wp_datetime_format(): string {
        return trim($this->get_wp_date_format() . ' ' . $this->get_wp_time_format());
    }

    private function format_wp_date(int $timestamp, ?string $format = null): string {
        if ($timestamp <= 0) {
            return '';
        }

        $format = $format !== null && $format !== '' ? $format : $this->get_wp_date_format();

        try {
            return wp_date($format, $timestamp, wp_timezone());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function format_wp_datetime(int $timestamp, ?string $format = null): string {
        if ($timestamp <= 0) {
            return '';
        }

        $format = $format !== null && $format !== '' ? $format : $this->get_wp_datetime_format();

        try {
            return wp_date($format, $timestamp, wp_timezone());
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function format_wc_datetime_for_site_settings($value, bool $includeTime = true): string {
        if (!$value || !is_object($value) || !method_exists($value, 'getTimestamp')) {
            return '';
        }

        return $includeTime
            ? $this->format_wp_datetime((int) $value->getTimestamp())
            : $this->format_wp_date((int) $value->getTimestamp());
    }

    private function get_wp_timezone_label(): string {
        $tzString = wp_timezone_string();
        if ($tzString !== '') {
            return $tzString;
        }

        $offset = (float) get_option('gmt_offset', 0);
        $sign = $offset < 0 ? '-' : '+';
        $hours = (int) floor(abs($offset));
        $minutes = (int) round((abs($offset) - $hours) * 60);

        return sprintf('UTC%s%02d:%02d', $sign, $hours, $minutes);
    }

    private function parse_datetime_local(string $raw): int {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }
        try {
            $tz = wp_timezone();
            $dt = \DateTimeImmutable::createFromFormat('Y-m-d\\TH:i', $raw, $tz);
            if (!$dt) {
                return 0;
            }
            return (int) $dt->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function resolve_account_next_payment_timestamp(string $raw): int {
        $raw = trim($raw);
        if ($raw === '') {
            return 0;
        }

        try {
            $tz = wp_timezone();
            $selectedDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);
            if (!$selectedDate) {
                return 0;
            }

            $now = new \DateTimeImmutable('now', $tz);
            $todayStart = $now->setTime(0, 0, 0);
            if ($selectedDate < $todayStart) {
                return 0;
            }

            if ($selectedDate->format('Y-m-d') === $todayStart->format('Y-m-d')) {
                return (int) $now->modify('+1 hour')->getTimestamp();
            }

            return (int) $selectedDate->setTime(0, 5, 0)->getTimestamp();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function get_admin_product_label(int $productId): string {
        if ($productId <= 0 || !function_exists('wc_get_product')) {
            return '';
        }

        $product = wc_get_product($productId);
        if (!$product || !is_object($product)) {
            return '';
        }

        $label = '';
        if (method_exists($product, 'get_formatted_name')) {
            $label = (string) $product->get_formatted_name();
        } elseif (method_exists($product, 'get_name')) {
            $label = (string) $product->get_name();
        }

        $label = wp_strip_all_tags($label, true);
        $label = html_entity_decode($label, ENT_QUOTES, get_bloginfo('charset') ?: 'UTF-8');
        $label = preg_replace('/\s+/', ' ', $label);

        return trim((string) $label);
    }

    private function get_admin_customer_label(int $userId): string {
        if ($userId <= 0) {
            return '';
        }

        $user = get_user_by('id', $userId);
        if (!$user || !is_object($user)) {
            return '';
        }

        $label = (string) $user->display_name;
        if ($label === '') {
            $label = (string) $user->user_login;
        }
        if (!empty($user->user_email)) {
            $label .= ' (' . $user->user_email . ')';
        }

        return trim($label);
    }

    private function get_subscription_admin_customer_name(int $userId): string {
        if ($userId <= 0) {
            return '';
        }

        $user = get_user_by('id', $userId);
        if (!$user || !is_object($user)) {
            return '';
        }

        $name = trim((string) $user->display_name);
        if ($name === '') {
            $name = trim((string) $user->user_login);
        }

        return $name;
    }

    private function get_subscription_admin_title_text(int $subId): string {
        if ($subId <= 0) {
            return '';
        }

        $userId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
        $customer = $this->get_subscription_admin_customer_name($userId);

        if ($customer !== '') {
            return sprintf(__('nummer%1$d voor %2$s', 'hb-ucs'), $subId, $customer);
        }

        return sprintf(__('nummer%d', 'hb-ucs'), $subId);
    }

    private function get_subscription_status_badge_html(string $status): string {
        $statuses = $this->get_subscription_statuses();
        $label = $statuses[$status] ?? ($status !== '' ? $status : '—');
        $classMap = [
            'active' => 'status-completed',
            'pending_mandate' => 'status-pending',
            'payment_pending' => 'status-processing',
            'on-hold' => 'status-on-hold',
            'paused' => 'status-on-hold',
            'cancelled' => 'status-cancelled',
            'expired' => 'status-failed',
        ];
        $className = $classMap[$status] ?? ($status !== '' ? sanitize_html_class('status-' . $status) : '');

        return '<mark class="order-status ' . esc_attr($className) . '"><span>' . esc_html((string) $label) . '</span></mark>';
    }

    private function format_admin_relative_date(int $timestamp): string {
        if ($timestamp <= 0) {
            return '—';
        }

        $diff = $timestamp - time();
        $human = human_time_diff(time(), $timestamp);

        if ($human === '') {
            return $this->format_wp_date($timestamp);
        }

        if ($diff >= 0) {
            return sprintf(__('%s vanaf nu', 'hb-ucs'), $human);
        }

        return sprintf(__('%s geleden', 'hb-ucs'), $human);
    }

    private function get_subscription_schedule_label(string $scheme, int $interval = 0): string {
        $resolved = $this->resolve_subscription_schedule($scheme, $interval, 'week');
        $settings = $this->get_settings();
        $freqs = isset($settings['frequencies']) && is_array($settings['frequencies']) ? $settings['frequencies'] : [];
        if ($resolved['scheme'] !== '' && isset($freqs[$resolved['scheme']]) && is_array($freqs[$resolved['scheme']]) && !empty($freqs[$resolved['scheme']]['label'])) {
            return (string) $freqs[$resolved['scheme']]['label'];
        }

        return sprintf(__('iedere %d week', 'hb-ucs'), (int) $resolved['interval']);
    }

    private function parse_subscription_interval_from_scheme(string $scheme, int $fallback = 0): int {
        if (preg_match('/^(\d+)w$/', $scheme, $matches)) {
            return max(1, (int) $matches[1]);
        }

        return $fallback > 0 ? $fallback : 1;
    }

    private function resolve_subscription_schedule(string $scheme = '', int $interval = 0, string $period = ''): array {
        $scheme = sanitize_key($scheme);
        $period = sanitize_key($period);
        $freqs = $this->get_configured_frequencies(false);

        if ($scheme !== '' && isset($freqs[$scheme]) && is_array($freqs[$scheme])) {
            $resolvedInterval = (int) ($freqs[$scheme]['interval'] ?? 0);
            $resolvedPeriod = sanitize_key((string) ($freqs[$scheme]['period'] ?? 'week'));

            if ($resolvedInterval > 0) {
                return [
                    'scheme' => $scheme,
                    'interval' => $resolvedInterval,
                    'period' => $resolvedPeriod !== '' ? $resolvedPeriod : 'week',
                ];
            }
        }

        $interval = $this->parse_subscription_interval_from_scheme($scheme, $interval);
        if ($period === '') {
            $period = 'week';
        }
        if ($scheme === '') {
            $scheme = $interval . 'w';
        }

        return [
            'scheme' => $scheme,
            'interval' => $interval,
            'period' => $period,
        ];
    }

    private function repair_subscription_schedule_meta(int $subId): array {
        if ($subId <= 0) {
            return $this->resolve_subscription_schedule();
        }

        $scheme = (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
        if ($scheme === '') {
            $scheme = (string) get_post_meta($subId, '_hb_ucs_subscription_scheme', true);
        }

        $interval = (int) get_post_meta($subId, self::SUB_META_INTERVAL, true);
        if ($interval <= 0) {
            $interval = (int) get_post_meta($subId, '_hb_ucs_subscription_interval', true);
        }

        $period = (string) get_post_meta($subId, self::SUB_META_PERIOD, true);
        if ($period === '') {
            $period = (string) get_post_meta($subId, '_hb_ucs_subscription_period', true);
        }

        $resolved = $this->resolve_subscription_schedule($scheme, $interval, $period);
        $scheduleMeta = [
            self::SUB_META_SCHEME => (string) $resolved['scheme'],
            '_hb_ucs_subscription_scheme' => (string) $resolved['scheme'],
            self::SUB_META_INTERVAL => (int) $resolved['interval'],
            '_hb_ucs_subscription_interval' => (int) $resolved['interval'],
            self::SUB_META_PERIOD => (string) $resolved['period'],
            '_hb_ucs_subscription_period' => (string) $resolved['period'],
        ];

        foreach ($this->get_related_subscription_ids($subId) as $targetId) {
            foreach ($scheduleMeta as $metaKey => $metaValue) {
                $this->update_subscription_post_meta_if_changed($targetId, $metaKey, $metaValue);
            }
        }

        return $resolved;
    }

    private function get_subscription_payment_method_label(int $subId): string {
        $title = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
        $method = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD, true);

        if ($title !== '') {
            return $title;
        }

        return $method !== '' ? $method : '—';
    }

    private function get_subscription_mollie_meta_context(int $subId): array {
        $originalContext = [
            'paymentId' => (string) get_post_meta($subId, self::SUB_META_LAST_PAYMENT_ID, true),
            'customerId' => (string) get_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, true),
            'mandateId' => (string) get_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, true),
            'paymentMode' => '',
        ];

        $context = [
            'paymentId' => $originalContext['paymentId'],
            'customerId' => $originalContext['customerId'],
            'mandateId' => $originalContext['mandateId'],
            'paymentMode' => $originalContext['paymentMode'],
        ];

        $order = function_exists('wc_get_order') ? wc_get_order($subId) : null;
        if ($order && is_object($order) && method_exists($order, 'get_meta')) {
            $orderContext = $this->extract_mollie_context_from_order($order, true);
            foreach ($orderContext as $key => $value) {
                if (($context[$key] ?? '') === '' && $value !== '') {
                    $context[$key] = $value;
                }
            }
        }

        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $parentOrder = ($parentOrderId > 0 && function_exists('wc_get_order')) ? wc_get_order($parentOrderId) : null;
        if ($parentOrder && is_object($parentOrder)) {
            $parentContext = $this->extract_mollie_context_from_order($parentOrder, true);
            foreach ($parentContext as $key => $value) {
                if (($context[$key] ?? '') === '' && $value !== '') {
                    $context[$key] = $value;
                }
            }
        }

        $lastOrderId = (int) get_post_meta($subId, self::SUB_META_LAST_ORDER_ID, true);
        if ($lastOrderId > 0 && $lastOrderId !== $parentOrderId && function_exists('wc_get_order')) {
            $lastOrder = wc_get_order($lastOrderId);
            if ($lastOrder && is_object($lastOrder)) {
                $lastOrderContext = $this->extract_mollie_context_from_order($lastOrder, true);
                foreach ($lastOrderContext as $key => $value) {
                    if (($context[$key] ?? '') === '' && $value !== '') {
                        $context[$key] = $value;
                    }
                }
            }
        }

        if ($context['paymentMode'] === '' && $context['paymentId'] !== '') {
            $context['paymentMode'] = $this->get_current_mollie_payment_mode();
        }

        $needsPersist = $context['paymentId'] !== $originalContext['paymentId']
            || $context['customerId'] !== $originalContext['customerId']
            || $context['mandateId'] !== $originalContext['mandateId']
            || $context['paymentMode'] !== $originalContext['paymentMode'];

        if ($needsPersist) {
            if ($context['paymentId'] !== '') {
                update_post_meta($subId, self::SUB_META_LAST_PAYMENT_ID, $context['paymentId']);
            }
            if ($context['customerId'] !== '') {
                update_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $context['customerId']);
            }
            if ($context['mandateId'] !== '') {
                update_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, $context['mandateId']);
            }

            $paymentMethod = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD, true);
            $paymentMethodTitle = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
            $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
            $parentOrder = ($parentOrderId > 0 && function_exists('wc_get_order')) ? wc_get_order($parentOrderId) : null;
            $this->hydrate_subscription_order_payment_data($subId, $paymentMethod, $paymentMethodTitle, $context, $parentOrder);
        }

        return $context;
    }

    public function render_subscription_mollie_admin_fields($order): void {
        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || !method_exists($order, 'get_id')) {
            return;
        }

        if ((string) $order->get_type() !== $this->get_subscription_order_type()->get_type()) {
            return;
        }

        $subId = (int) $order->get_id();
        if ($subId <= 0) {
            return;
        }

        $mollieMeta = $this->get_subscription_mollie_meta_context($subId);
        $paymentMethodTitle = method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : '';
        if ($paymentMethodTitle === '') {
            $paymentMethodTitle = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
        }

        $paymentMethod = method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '';
        if ($paymentMethod === '') {
            $paymentMethod = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD, true);
        }

        echo '<div class="hb-ucs-subscription-mollie-admin-fields">';

        echo '<p><strong>' . esc_html__('Abonnementsbetaling', 'hb-ucs') . '</strong></p>';

        echo '<p><label for="hb_ucs_sub_payment_method"><strong>' . esc_html__('Betaalmethode code', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="text" name="hb_ucs_sub_payment_method" id="hb_ucs_sub_payment_method" value="' . esc_attr($paymentMethod) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_payment_method_title"><strong>' . esc_html__('Betaalmethode label', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="text" name="hb_ucs_sub_payment_method_title" id="hb_ucs_sub_payment_method_title" value="' . esc_attr($paymentMethodTitle) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_last_payment_id"><strong>' . esc_html__('Mollie Payment ID', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="text" name="hb_ucs_sub_last_payment_id" id="hb_ucs_sub_last_payment_id" value="' . esc_attr((string) ($mollieMeta['paymentId'] ?? '')) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_payment_mode"><strong>' . esc_html__('Mollie Payment Mode', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="text" name="hb_ucs_sub_payment_mode" id="hb_ucs_sub_payment_mode" value="' . esc_attr((string) ($mollieMeta['paymentMode'] ?? '')) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_mollie_customer_id"><strong>' . esc_html__('Mollie Customer ID', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="text" name="hb_ucs_sub_mollie_customer_id" id="hb_ucs_sub_mollie_customer_id" value="' . esc_attr((string) ($mollieMeta['customerId'] ?? '')) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_mollie_mandate_id"><strong>' . esc_html__('Mollie Mandate ID', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="text" name="hb_ucs_sub_mollie_mandate_id" id="hb_ucs_sub_mollie_mandate_id" value="' . esc_attr((string) ($mollieMeta['mandateId'] ?? '')) . '" style="width:100%;" />';
        echo '<span class="description">' . esc_html__('Laat een veld leeg om de opgeslagen waarde te verwijderen.', 'hb-ucs') . '</span>';
        echo '</p>';

        echo '</div>';
    }

    private function get_subscription_admin_payment_method_options(): array {
        $options = [];

        $subscriptionIds = function_exists('wc_get_orders')
            ? wc_get_orders([
                'type' => $this->get_subscription_order_type()->get_type(),
                'limit' => 200,
                'return' => 'ids',
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => array_keys(wc_get_order_statuses()),
            ])
            : [];

        foreach ((array) $subscriptionIds as $subId) {
            $method = (string) get_post_meta((int) $subId, self::SUB_META_PAYMENT_METHOD, true);
            if ($method === '' || isset($options[$method])) {
                continue;
            }

            $options[$method] = $this->get_subscription_payment_method_label((int) $subId);
        }

        asort($options);

        return $options;
    }

    private function get_subscription_start_timestamp(int $subId): int {
        $post = get_post($subId);
        if (!$post || !isset($post->post_date_gmt)) {
            return 0;
        }

        return strtotime((string) $post->post_date_gmt . ' GMT') ?: 0;
    }

    private function get_subscription_orders_admin_url(int $subId): string {
        return admin_url('edit.php?post_type=shop_order&hb_ucs_subscription_id=' . $subId);
    }

    private function format_address_lines(array $parts): string {
        $lines = [];
        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part !== '') {
                $lines[] = $part;
            }
        }

        if (empty($lines)) {
            return '—';
        }

        return implode('<br/>', array_map('esc_html', $lines));
    }

    private function get_editable_subscription_address_snapshot(int $subId, string $type, int $userId = 0, $order = null): array {
        $snapshot = $this->get_subscription_address_snapshot($subId, $type, $userId, $order);

        $defaults = [
            'first_name' => '',
            'last_name' => '',
            'company' => '',
            'address_1' => '',
            'address_2' => '',
            'postcode' => '',
            'city' => '',
            'state' => '',
            'country' => '',
            'email' => '',
            'phone' => '',
        ];

        return wp_parse_args(is_array($snapshot) ? $snapshot : [], $defaults);
    }

    private function sanitize_subscription_address_input(array $raw, string $type): array {
        $address = [
            'first_name' => sanitize_text_field((string) ($raw['first_name'] ?? '')),
            'last_name' => sanitize_text_field((string) ($raw['last_name'] ?? '')),
            'company' => sanitize_text_field((string) ($raw['company'] ?? '')),
            'address_1' => sanitize_text_field((string) ($raw['address_1'] ?? '')),
            'address_2' => sanitize_text_field((string) ($raw['address_2'] ?? '')),
            'postcode' => sanitize_text_field((string) ($raw['postcode'] ?? '')),
            'city' => sanitize_text_field((string) ($raw['city'] ?? '')),
            'state' => sanitize_text_field((string) ($raw['state'] ?? '')),
            'country' => strtoupper(sanitize_text_field((string) ($raw['country'] ?? ''))),
        ];

        if ($type === 'billing') {
            $address['email'] = sanitize_email((string) ($raw['email'] ?? ''));
            $address['phone'] = sanitize_text_field((string) ($raw['phone'] ?? ''));
        }

        return $address;
    }

    private function render_subscription_address_fields(string $type, array $address): void {
        $prefix = 'hb_ucs_sub_' . $type . '_';
        $namePrefix = 'hb_ucs_sub_' . $type;

        echo '<p class="form-field form-field-first _' . esc_attr($type) . '_first_name_field"><label for="' . esc_attr($prefix . 'first_name') . '">' . esc_html__('Voornaam', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[first_name]') . '" id="' . esc_attr($prefix . 'first_name') . '" value="' . esc_attr((string) ($address['first_name'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-last _' . esc_attr($type) . '_last_name_field"><label for="' . esc_attr($prefix . 'last_name') . '">' . esc_html__('Achternaam', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[last_name]') . '" id="' . esc_attr($prefix . 'last_name') . '" value="' . esc_attr((string) ($address['last_name'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-wide _' . esc_attr($type) . '_company_field"><label for="' . esc_attr($prefix . 'company') . '">' . esc_html__('Bedrijf', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[company]') . '" id="' . esc_attr($prefix . 'company') . '" value="' . esc_attr((string) ($address['company'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-wide _' . esc_attr($type) . '_address_1_field"><label for="' . esc_attr($prefix . 'address_1') . '">' . esc_html__('Adres regel 1', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[address_1]') . '" id="' . esc_attr($prefix . 'address_1') . '" value="' . esc_attr((string) ($address['address_1'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-wide _' . esc_attr($type) . '_address_2_field"><label for="' . esc_attr($prefix . 'address_2') . '">' . esc_html__('Adres regel 2', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[address_2]') . '" id="' . esc_attr($prefix . 'address_2') . '" value="' . esc_attr((string) ($address['address_2'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-first _' . esc_attr($type) . '_postcode_field"><label for="' . esc_attr($prefix . 'postcode') . '">' . esc_html__('Postcode', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[postcode]') . '" id="' . esc_attr($prefix . 'postcode') . '" value="' . esc_attr((string) ($address['postcode'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-last _' . esc_attr($type) . '_city_field"><label for="' . esc_attr($prefix . 'city') . '">' . esc_html__('Plaats', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[city]') . '" id="' . esc_attr($prefix . 'city') . '" value="' . esc_attr((string) ($address['city'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-first _' . esc_attr($type) . '_state_field"><label for="' . esc_attr($prefix . 'state') . '">' . esc_html__('Provincie', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[state]') . '" id="' . esc_attr($prefix . 'state') . '" value="' . esc_attr((string) ($address['state'] ?? '')) . '" /></p>';
        echo '<p class="form-field form-field-last _' . esc_attr($type) . '_country_field"><label for="' . esc_attr($prefix . 'country') . '">' . esc_html__('Landcode', 'hb-ucs') . '</label><input type="text" class="short" maxlength="2" name="' . esc_attr($namePrefix . '[country]') . '" id="' . esc_attr($prefix . 'country') . '" value="' . esc_attr((string) ($address['country'] ?? '')) . '" /></p>';
        if ($type === 'billing') {
            echo '<p class="form-field form-field-first _billing_email_field"><label for="' . esc_attr($prefix . 'email') . '">' . esc_html__('E-mailadres', 'hb-ucs') . '</label><input type="email" class="short" name="' . esc_attr($namePrefix . '[email]') . '" id="' . esc_attr($prefix . 'email') . '" value="' . esc_attr((string) ($address['email'] ?? '')) . '" /></p>';
            echo '<p class="form-field form-field-last _billing_phone_field"><label for="' . esc_attr($prefix . 'phone') . '">' . esc_html__('Telefoon', 'hb-ucs') . '</label><input type="text" class="short" name="' . esc_attr($namePrefix . '[phone]') . '" id="' . esc_attr($prefix . 'phone') . '" value="' . esc_attr((string) ($address['phone'] ?? '')) . '" /></p>';
        }
    }

    private function render_subscription_address_display(string $type, array $address): void {
        $lines = [];
        $name = trim((string) ($address['first_name'] ?? '') . ' ' . (string) ($address['last_name'] ?? ''));
        if ($name !== '') {
            $lines[] = esc_html($name);
        }
        if (!empty($address['company'])) {
            $lines[] = esc_html((string) $address['company']);
        }
        if (!empty($address['address_1'])) {
            $lines[] = esc_html((string) $address['address_1']);
        }
        if (!empty($address['address_2'])) {
            $lines[] = esc_html((string) $address['address_2']);
        }
        $cityLine = trim((string) ($address['postcode'] ?? '') . ' ' . (string) ($address['city'] ?? ''));
        if ($cityLine !== '') {
            $lines[] = esc_html($cityLine);
        }
        if (!empty($address['country'])) {
            $lines[] = esc_html((string) $address['country']);
        }

        echo '<div class="hb-ucs-address-preview">';

        if (!empty($lines)) {
            echo '<p class="hb-ucs-address-preview__lines">' . implode('<br/>', $lines) . '</p>';
        } else {
            echo '<p class="none_set hb-ucs-address-preview__lines"><strong>' . esc_html__('Adres:', 'hb-ucs') . '</strong> <span class="hb-ucs-address-preview__empty">' . esc_html__('Geen adres ingesteld.', 'hb-ucs') . '</span></p>';
        }

        if ($type === 'billing') {
            if (!empty($address['email'])) {
                echo '<p class="hb-ucs-address-preview__email"><strong>' . esc_html__('E-mailadres', 'hb-ucs') . ':</strong> <a href="mailto:' . esc_attr((string) $address['email']) . '">' . esc_html((string) $address['email']) . '</a></p>';
            } else {
                echo '<p class="hb-ucs-address-preview__email" style="display:none;"></p>';
            }
            if (!empty($address['phone'])) {
                echo '<p class="hb-ucs-address-preview__phone"><strong>' . esc_html__('Telefoon', 'hb-ucs') . ':</strong> ' . wp_kses_post(wc_make_phone_clickable((string) $address['phone'])) . '</p>';
            } else {
                echo '<p class="hb-ucs-address-preview__phone" style="display:none;"></p>';
            }
        }

        echo '</div>';
    }

    private function get_subscription_admin_item_preview(int $selectedId, string $scheme, int $qty): array {
        return $this->get_subscription_admin_item_preview_details($selectedId, $scheme, $qty);
    }

    private function get_subscription_admin_item_preview_details(int $selectedId, string $scheme, int $qty, array $selectedAttributes = [], ?float $manualUnitPrice = null, bool $syncCatalogPrice = true, int $subId = 0): array {
        $qty = max(1, $qty);
        $product = $selectedId > 0 ? wc_get_product($selectedId) : false;
        $editorProductId = $selectedId;
        $editorProductLabel = $selectedId > 0 ? $this->get_admin_product_label($selectedId) : '';
        $attributeProduct = $product;
        $normalizedAttributes = [];
        $requiresSelection = false;
        $resolvedSelectionId = $selectedId;
        $sku = $product && is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';

        if ($product && is_object($product) && method_exists($product, 'is_type') && $product->is_type('variation')) {
            $editorProductId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : $selectedId;
            $editorProductLabel = $editorProductId > 0 ? $this->get_admin_product_label($editorProductId) : $editorProductLabel;
            $attributeProduct = $editorProductId > 0 ? wc_get_product($editorProductId) : $product;
            $normalizedAttributes = $this->get_selected_attributes_from_variation($product);
        } elseif ($product && is_object($product) && method_exists($product, 'is_type') && $product->is_type('variable')) {
            $attributeProduct = $product;
            $editorProductId = $selectedId;
            $editorProductLabel = $this->get_admin_product_label($selectedId);
            $normalizedAttributes = $this->normalize_selected_attributes_for_product($product, $selectedAttributes);
            $resolvedSelectionId = $this->resolve_variation_id_from_attributes($product, $normalizedAttributes);

            foreach ($this->get_variable_product_attribute_config($product, true) as $attributeConfig) {
                $key = (string) ($attributeConfig['key'] ?? '');
                if ($key === '' || empty($normalizedAttributes[$key])) {
                    $requiresSelection = true;
                    break;
                }
            }

            if (!$requiresSelection && $resolvedSelectionId <= 0) {
                $resolvedSelectionId = $selectedId;
            }
        }

        $attributeConfig = $this->get_variable_product_attribute_config($attributeProduct, true);
        $previewItem = null;
        if (!$requiresSelection && $resolvedSelectionId > 0) {
            $previewItem = $this->build_subscription_item_from_selection($resolvedSelectionId, $scheme, $qty, null, $normalizedAttributes);
        }

        if ($previewItem && $manualUnitPrice !== null && !$syncCatalogPrice) {
            $previewItem['unit_price'] = (float) wc_format_decimal((string) $manualUnitPrice, wc_get_price_decimals());
            $previewItem['price_includes_tax'] = 0;
        }

        $customer = $subId > 0 ? $this->get_subscription_tax_customer($subId) : null;
        $totals = $previewItem ? $this->get_subscription_admin_item_totals($previewItem, $customer) : [
            'unit_subtotal' => 0.0,
            'unit_total' => 0.0,
            'line_subtotal' => 0.0,
            'line_tax' => 0.0,
            'line_total' => 0.0,
            'tax_breakdown' => [],
        ];

        $resolvedProductId = $previewItem ? (int) (($previewItem['base_variation_id'] ?? 0) ?: ($previewItem['base_product_id'] ?? 0)) : 0;
        $resolvedProduct = $resolvedProductId > 0 ? wc_get_product($resolvedProductId) : false;
        $variationSummary = '';
        $label = $editorProductLabel;

        if ($previewItem) {
            $label = $this->get_subscription_item_label($previewItem);
            if ($resolvedProduct && is_object($resolvedProduct) && method_exists($resolvedProduct, 'get_sku')) {
                $sku = (string) $resolvedProduct->get_sku();
            }
            $normalizedAttributes = $this->get_subscription_item_attribute_snapshot($previewItem);
            $variationSummary = $this->get_subscription_item_variation_summary($previewItem);
        }

        return [
            'preview_item' => $previewItem,
            'label' => $label,
            'sku' => $sku,
            'unit_price' => $totals['unit_subtotal'],
            'line_subtotal' => $totals['line_subtotal'],
            'line_tax' => $totals['line_tax'],
            'line_total' => $totals['line_total'],
            'tax_breakdown' => $totals['tax_breakdown'],
            'unit_price_html' => function_exists('wc_price') ? wc_price($totals['unit_subtotal']) : number_format((float) $totals['unit_subtotal'], 2, '.', ''),
            'line_subtotal_html' => function_exists('wc_price') ? wc_price($totals['line_subtotal']) : number_format((float) $totals['line_subtotal'], 2, '.', ''),
            'line_tax_html' => function_exists('wc_price') ? wc_price($totals['line_tax']) : number_format((float) $totals['line_tax'], 2, '.', ''),
            'line_total_html' => function_exists('wc_price') ? wc_price($totals['line_total']) : number_format((float) $totals['line_total'], 2, '.', ''),
            'variation_summary' => $variationSummary,
            'requires_selection' => $requiresSelection,
            'attribute_config' => $attributeConfig,
            'selected_attributes' => $normalizedAttributes,
            'editor_product_id' => $editorProductId,
            'editor_product_label' => $editorProductLabel,
            'resolved_product_id' => $resolvedProductId,
        ];
    }

    private function get_subscription_tax_customer(int $subId, $fallbackOrder = null) {
        if ($subId <= 0 || !class_exists('WC_Customer')) {
            return null;
        }

        try {
            $customer = new \WC_Customer(0, true);
        } catch (\Throwable $e) {
            return null;
        }

        $userId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
        $billing = $this->get_subscription_address_snapshot($subId, 'billing', $userId, $fallbackOrder);
        $shipping = $this->get_subscription_address_snapshot($subId, 'shipping', $userId, $fallbackOrder);

        $billingCountry = (string) ($billing['country'] ?? '');
        $billingState = (string) ($billing['state'] ?? '');
        $billingPostcode = (string) ($billing['postcode'] ?? '');
        $billingCity = (string) ($billing['city'] ?? '');
        $shippingCountry = (string) ($shipping['country'] ?? '');
        $shippingState = (string) ($shipping['state'] ?? '');
        $shippingPostcode = (string) ($shipping['postcode'] ?? '');
        $shippingCity = (string) ($shipping['city'] ?? '');

        if ($billingCountry === '' && $shippingCountry !== '') {
            $billingCountry = $shippingCountry;
            $billingState = $shippingState;
            $billingPostcode = $shippingPostcode;
            $billingCity = $shippingCity;
        }

        if ($shippingCountry === '' && $billingCountry !== '') {
            $shippingCountry = $billingCountry;
            $shippingState = $billingState;
            $shippingPostcode = $billingPostcode;
            $shippingCity = $billingCity;
        }

        if (method_exists($customer, 'set_billing_country')) {
            $customer->set_billing_country($billingCountry);
        }
        if (method_exists($customer, 'set_billing_state')) {
            $customer->set_billing_state($billingState);
        }
        if (method_exists($customer, 'set_billing_postcode')) {
            $customer->set_billing_postcode($billingPostcode);
        }
        if (method_exists($customer, 'set_billing_city')) {
            $customer->set_billing_city($billingCity);
        }
        if (method_exists($customer, 'set_shipping_country')) {
            $customer->set_shipping_country($shippingCountry);
        }
        if (method_exists($customer, 'set_shipping_state')) {
            $customer->set_shipping_state($shippingState);
        }
        if (method_exists($customer, 'set_shipping_postcode')) {
            $customer->set_shipping_postcode($shippingPostcode);
        }
        if (method_exists($customer, 'set_shipping_city')) {
            $customer->set_shipping_city($shippingCity);
        }
        if (method_exists($customer, 'set_is_vat_exempt')) {
            $customer->set_is_vat_exempt(false);
        }

        return $customer;
    }

    private function normalize_subscription_item_taxes($taxes): array {
        $normalized = ['subtotal' => [], 'total' => []];
        if (!is_array($taxes)) {
            return $normalized;
        }

        foreach (['subtotal', 'total'] as $group) {
            if (!isset($taxes[$group]) || !is_array($taxes[$group])) {
                continue;
            }

            foreach ($taxes[$group] as $rateKey => $taxAmount) {
                $normalizedKey = is_numeric($rateKey) ? (string) absint((string) $rateKey) : sanitize_key((string) $rateKey);
                if ($normalizedKey === '') {
                    $normalizedKey = 'manual';
                }

                $normalized[$group][$normalizedKey] = (float) wc_format_decimal((string) $taxAmount);
            }
        }

        if (empty($normalized['subtotal']) && !empty($normalized['total'])) {
            $normalized['subtotal'] = $normalized['total'];
        }

        if (empty($normalized['total']) && !empty($normalized['subtotal'])) {
            $normalized['total'] = $normalized['subtotal'];
        }

        return $normalized;
    }

    private function get_subscription_item_taxes(array $item, $customer = null): array {
        $storedTaxes = $this->normalize_subscription_item_taxes($item['taxes'] ?? []);
        if (!empty($storedTaxes['total'])) {
            return $storedTaxes;
        }

        if (!wc_tax_enabled() || !class_exists('WC_Tax')) {
            return $storedTaxes;
        }

        $qty = max(1, (int) ($item['qty'] ?? 1));
        $lineSubtotal = (float) wc_format_decimal((string) ($this->get_subscription_item_storage_unit_price($item) * $qty));
        if ($lineSubtotal <= 0) {
            return $storedTaxes;
        }

        $taxBreakdown = [];
        foreach ($this->get_subscription_item_tax_rates($item, $customer) as $rateId => $rateData) {
            $amounts = \WC_Tax::calc_tax($lineSubtotal, [(string) $rateId => $rateData], false);
            $rateKey = (string) absint((string) $rateId);
            if ($rateKey === '0') {
                continue;
            }

            $taxBreakdown[$rateKey] = isset($amounts[$rateId]) ? (float) wc_format_decimal((string) $amounts[$rateId]) : 0.0;
        }

        return [
            'subtotal' => $taxBreakdown,
            'total' => $taxBreakdown,
        ];
    }

    private function normalize_subscription_tax_amounts(array $taxes): array {
        $normalized = [];
        $taxGroups = isset($taxes['total']) && is_array($taxes['total']) ? $taxes['total'] : $taxes;

        foreach ($taxGroups as $rateKey => $taxAmount) {
            if (is_array($taxAmount)) {
                continue;
            }

            $normalizedKey = is_numeric($rateKey) ? (string) absint((string) $rateKey) : sanitize_key((string) $rateKey);
            if ($normalizedKey === '') {
                continue;
            }

            $normalized[$normalizedKey] = (float) wc_format_decimal((string) $taxAmount);
        }

        return $normalized;
    }

    private function get_subscription_tax_column_label(string $rateKey): string {
        if ($rateKey === 'manual') {
            return __('BTW', 'hb-ucs');
        }

        $rateId = (int) absint($rateKey);
        if ($rateId <= 0 || !class_exists('WC_Tax')) {
            return __('BTW', 'hb-ucs');
        }

        $label = (string) \WC_Tax::get_rate_label($rateId);
        $percent = (string) \WC_Tax::get_rate_percent($rateId);
        $code = (string) \WC_Tax::get_rate_code($rateId);

        if ($label === '') {
            $label = $code !== '' ? $code : __('BTW', 'hb-ucs');
        }
        if ($percent !== '' && stripos($label, $percent) === false) {
            $label .= ' ' . $percent;
        }

        return trim($label);
    }

    private function get_subscription_tax_column_definition(string $rateKey, array $rateData = []): array {
        return [
            'key' => $rateKey,
            'label' => $this->get_subscription_tax_column_label($rateKey),
            'sort' => isset($rateData['priority']) ? (int) $rateData['priority'] : 999,
            'source' => isset($rateData['source']) ? (string) $rateData['source'] : 'computed',
        ];
    }

    private function get_subscription_manual_tax_rates(int $subId): array {
        $stored = get_post_meta($subId, self::SUB_META_MANUAL_TAX_RATES, true);
        $rates = [];

        if (!is_array($stored)) {
            return $rates;
        }

        foreach ($stored as $rateId) {
            $rateId = (int) absint((string) $rateId);
            if ($rateId <= 0) {
                continue;
            }
            $rates[] = $rateId;
        }

        return array_values(array_unique($rates));
    }

    private function persist_subscription_manual_tax_rates(int $subId, array $rateIds): void {
        $normalized = [];
        foreach ($rateIds as $rateId) {
            $rateId = (int) absint((string) $rateId);
            if ($rateId <= 0) {
                continue;
            }
            $normalized[] = $rateId;
        }

        update_post_meta($subId, self::SUB_META_MANUAL_TAX_RATES, array_values(array_unique($normalized)));
    }

    private function add_subscription_manual_tax_rate(int $subId, int $rateId): bool {
        if ($subId <= 0 || $rateId <= 0) {
            return false;
        }

        $rates = $this->get_subscription_manual_tax_rates($subId);
        if (in_array($rateId, $rates, true)) {
            return false;
        }

        $rates[] = $rateId;
        $this->persist_subscription_manual_tax_rates($subId, $rates);

        return true;
    }

    private function remove_subscription_manual_tax_rate(int $subId, int $rateId): bool {
        if ($subId <= 0 || $rateId <= 0) {
            return false;
        }

        $rates = array_values(array_filter($this->get_subscription_manual_tax_rates($subId), static function (int $storedRateId) use ($rateId): bool {
            return $storedRateId !== $rateId;
        }));

        $this->persist_subscription_manual_tax_rates($subId, $rates);

        return true;
    }

    private function get_available_subscription_admin_tax_rates(): array {
        global $wpdb;

        if (!isset($wpdb->prefix) || !class_exists('WC_Tax')) {
            return [];
        }

        $classesOptions = function_exists('wc_get_product_tax_class_options') ? wc_get_product_tax_class_options() : [];
        $rates = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}woocommerce_tax_rates ORDER BY tax_rate_name, tax_rate_class, tax_rate_priority LIMIT 100");
        if (!is_array($rates)) {
            return [];
        }

        $options = [];
        foreach ($rates as $rate) {
            $rateId = isset($rate->tax_rate_id) ? (int) $rate->tax_rate_id : 0;
            if ($rateId <= 0) {
                continue;
            }

            $rateClass = isset($rate->tax_rate_class) ? (string) $rate->tax_rate_class : '';
            $options[] = [
                'id' => $rateId,
                'label' => (string) \WC_Tax::get_rate_label($rate),
                'class' => isset($classesOptions[$rateClass]) ? (string) $classesOptions[$rateClass] : ($rateClass !== '' ? $rateClass : '—'),
                'code' => (string) \WC_Tax::get_rate_code($rate),
                'percent' => (string) \WC_Tax::get_rate_percent($rate),
            ];
        }

        return $options;
    }

    private function get_subscription_item_tax_rates(array $item, $customer = null): array {
        if (!wc_tax_enabled() || !class_exists('WC_Tax')) {
            return [];
        }

        $productId = (int) (($item['base_variation_id'] ?? 0) ?: ($item['base_product_id'] ?? 0));
        $product = $productId > 0 ? wc_get_product($productId) : false;
        if (!$product || !is_object($product) || !method_exists($product, 'get_tax_status') || $product->get_tax_status() !== 'taxable') {
            return [];
        }

        $taxClass = method_exists($product, 'get_tax_class') ? (string) $product->get_tax_class() : '';

        try {
            return (array) \WC_Tax::get_rates($taxClass, $customer);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function get_subscription_shipping_tax_rates(array $items, $customer = null): array {
        if (!wc_tax_enabled() || !class_exists('WC_Tax')) {
            return [];
        }

        $shippingTaxClass = get_option('woocommerce_shipping_tax_class');
        if ($shippingTaxClass === false) {
            $shippingTaxClass = 'inherit';
        }

        $taxClass = $shippingTaxClass !== 'inherit' ? (string) $shippingTaxClass : null;
        if ($taxClass === null) {
            $itemClasses = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $productId = (int) (($item['base_variation_id'] ?? 0) ?: ($item['base_product_id'] ?? 0));
                $product = $productId > 0 ? wc_get_product($productId) : false;
                if (!$product || !is_object($product) || !method_exists($product, 'get_tax_status') || $product->get_tax_status() !== 'taxable') {
                    continue;
                }

                $itemClasses[] = method_exists($product, 'get_tax_class') ? (string) $product->get_tax_class() : '';
            }

            $itemClasses = array_values(array_unique($itemClasses));
            if (empty($itemClasses)) {
                return [];
            }

            $taxClass = in_array('', $itemClasses, true) ? '' : (string) reset($itemClasses);
        }

        try {
            return (array) \WC_Tax::get_shipping_tax_rates($taxClass, $customer);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function get_subscription_admin_tax_columns(int $subId, array $items, array $shippingLines = [], array $feeLines = [], $fallbackOrder = null): array {
        $columns = [];
        $customer = $this->get_subscription_tax_customer($subId, $fallbackOrder);

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            foreach ($this->get_subscription_item_tax_rates($item, $customer) as $rateId => $rateData) {
                $rateKey = (string) absint((string) $rateId);
                if ($rateKey === '0' || isset($columns[$rateKey])) {
                    continue;
                }
                $rateData = is_array($rateData) ? $rateData : [];
                $rateData['source'] = 'computed';
                $columns[$rateKey] = $this->get_subscription_tax_column_definition($rateKey, $rateData);
            }
        }

        foreach ($feeLines as $feeLine) {
            if (!is_array($feeLine)) {
                continue;
            }
            foreach (array_keys($this->normalize_subscription_tax_amounts(isset($feeLine['taxes']) && is_array($feeLine['taxes']) ? $feeLine['taxes'] : [])) as $rateKey) {
                $rateKey = (string) $rateKey;
                if ($rateKey === '' || isset($columns[$rateKey])) {
                    continue;
                }
                $columns[$rateKey] = $this->get_subscription_tax_column_definition($rateKey, ['source' => 'computed']);
            }
        }

        foreach ($shippingLines as $shippingLine) {
            if (!is_array($shippingLine)) {
                continue;
            }
            foreach (array_keys($this->normalize_subscription_tax_amounts(isset($shippingLine['taxes']) && is_array($shippingLine['taxes']) ? $shippingLine['taxes'] : [])) as $rateKey) {
                $rateKey = (string) $rateKey;
                if ($rateKey === '' || isset($columns[$rateKey])) {
                    continue;
                }
                $columns[$rateKey] = $this->get_subscription_tax_column_definition($rateKey, ['source' => 'computed']);
            }
        }

        foreach ($this->get_subscription_shipping_tax_rates($items, $customer) as $rateId => $rateData) {
            $rateKey = (string) absint((string) $rateId);
            if ($rateKey === '0' || isset($columns[$rateKey])) {
                continue;
            }
            $rateData = is_array($rateData) ? $rateData : [];
            $rateData['source'] = 'computed';
            $columns[$rateKey] = $this->get_subscription_tax_column_definition($rateKey, $rateData);
        }

        foreach ($this->get_subscription_manual_tax_rates($subId) as $manualRateId) {
            $rateKey = (string) $manualRateId;
            if (isset($columns[$rateKey])) {
                $columns[$rateKey]['source'] = 'manual';
                continue;
            }
            $columns[$rateKey] = $this->get_subscription_tax_column_definition($rateKey, ['source' => 'manual']);
        }

        uasort($columns, static function (array $left, array $right): int {
            return ($left['sort'] ?? 999) <=> ($right['sort'] ?? 999);
        });

        return $columns;
    }

    private function get_subscription_admin_item_totals(array $item, $customer = null): array {
        $totals = $this->get_subscription_item_order_totals($item, $customer);
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $unitSubtotal = (float) wc_format_decimal((string) ($qty > 0 ? (($totals['line_subtotal'] ?? 0.0) / $qty) : ($totals['line_subtotal'] ?? 0.0)));
        $lineSubtotal = (float) ($totals['line_subtotal'] ?? 0.0);
        $lineTax = (float) ($totals['line_tax'] ?? 0.0);
        $lineTotal = (float) ($totals['line_total'] ?? 0.0);
        $taxBreakdown = isset($totals['tax_breakdown']) && is_array($totals['tax_breakdown']) ? $totals['tax_breakdown'] : [];

        if ($this->should_display_subscription_prices_including_tax()) {
            $lineTotal = $this->get_subscription_item_display_amount($item, $qty, true);
        }
        $unitTotal = (float) wc_format_decimal((string) ($qty > 0 ? ($lineTotal / $qty) : $lineTotal));

        return [
            'unit_subtotal' => $unitSubtotal,
            'unit_total' => $unitTotal,
            'line_subtotal' => $lineSubtotal,
            'line_tax' => $lineTax,
            'line_total' => $lineTotal,
            'tax_breakdown' => $taxBreakdown,
        ];
    }

    private function get_subscription_item_order_totals(array $item, $customer = null): array {
        $liveOrderTotals = $this->get_subscription_item_live_order_totals($item);
        if (!empty($liveOrderTotals)) {
            $taxBreakdown = $this->normalize_subscription_tax_amounts($this->get_subscription_item_taxes($item, $customer)['total']);

            return [
                'line_subtotal' => (float) wc_format_decimal((string) ($liveOrderTotals['line_subtotal'] ?? 0.0)),
                'line_tax' => (float) wc_format_decimal((string) ($liveOrderTotals['line_tax'] ?? 0.0)),
                'line_total' => (float) wc_format_decimal((string) ($liveOrderTotals['line_total'] ?? 0.0)),
                'tax_breakdown' => $taxBreakdown,
            ];
        }

        $qty = max(1, (int) ($item['qty'] ?? 1));
        $taxBreakdown = $this->normalize_subscription_tax_amounts($this->get_subscription_item_taxes($item, $customer)['total']);
        $lineTax = (float) wc_format_decimal((string) array_sum($taxBreakdown));

        if (!empty($item['price_includes_tax'])) {
            $lineTotal = (float) wc_format_decimal((string) (((float) ($item['unit_price'] ?? 0.0)) * $qty));
            $lineSubtotal = (float) wc_format_decimal((string) max(0.0, $lineTotal - $lineTax));

            return [
                'line_subtotal' => $lineSubtotal,
                'line_tax' => $lineTax,
                'line_total' => $lineTotal,
                'tax_breakdown' => $taxBreakdown,
            ];
        }

        $unitSubtotal = (float) $this->get_subscription_item_storage_unit_price($item);
        $lineSubtotal = (float) wc_format_decimal((string) ($unitSubtotal * $qty));

        return [
            'line_subtotal' => $lineSubtotal,
            'line_tax' => $lineTax,
            'line_total' => (float) wc_format_decimal((string) ($lineSubtotal + $lineTax)),
            'tax_breakdown' => $taxBreakdown,
        ];
    }

    private function round_subscription_order_item_amount(float $amount): float {
        $decimals = function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2;

        return (float) wc_format_decimal((string) $amount, $decimals);
    }

    private function get_subscription_fee_line_totals(array $feeLine): array {
        $subtotal = (float) wc_format_decimal((string) ($feeLine['total'] ?? 0.0));
        $taxBreakdown = $this->normalize_subscription_tax_amounts(isset($feeLine['taxes']) && is_array($feeLine['taxes']) ? $feeLine['taxes'] : []);
        $tax = (float) wc_format_decimal((string) array_sum($taxBreakdown));

        return [
            'line_subtotal' => $subtotal,
            'line_tax' => $tax,
            'line_total' => (float) wc_format_decimal((string) ($subtotal + $tax)),
            'tax_breakdown' => $taxBreakdown,
        ];
    }

    private function get_subscription_shipping_line_tax_total(array $shippingLine): float {
        $taxes = isset($shippingLine['taxes']) && is_array($shippingLine['taxes']) ? $shippingLine['taxes'] : [];
        $sum = 0.0;

        if (isset($taxes['total']) && is_array($taxes['total'])) {
            foreach ($taxes['total'] as $taxAmount) {
                $sum += (float) wc_format_decimal((string) $taxAmount);
            }

            return (float) wc_format_decimal((string) $sum);
        }

        foreach ($taxes as $taxGroup) {
            if (!is_array($taxGroup)) {
                continue;
            }

            foreach ($taxGroup as $taxAmount) {
                $sum += (float) wc_format_decimal((string) $taxAmount);
            }
        }

        return (float) wc_format_decimal((string) $sum);
    }

    private function get_subscription_shipping_line_totals(array $shippingLine): array {
        $subtotal = (float) wc_format_decimal((string) ($shippingLine['total'] ?? 0.0));
        $taxBreakdown = $this->normalize_subscription_tax_amounts(isset($shippingLine['taxes']) && is_array($shippingLine['taxes']) ? $shippingLine['taxes'] : []);
        $tax = (float) wc_format_decimal((string) array_sum($taxBreakdown));
        $total = (float) wc_format_decimal((string) ($subtotal + $tax));

        return [
            'line_subtotal' => $subtotal,
            'line_tax' => $tax,
            'line_total' => $total,
            'tax_breakdown' => $taxBreakdown,
        ];
    }

    private function get_subscription_admin_totals(array $items, array $shippingLines = [], array $feeLines = [], int $subId = 0, $fallbackOrder = null): array {
        $itemSubtotal = 0.0;
        $itemTax = 0.0;
        $itemTotal = 0.0;
        $feeSubtotal = 0.0;
        $feeTax = 0.0;
        $feeTotal = 0.0;
        $shippingSubtotal = 0.0;
        $shippingTax = 0.0;
        $shippingTotal = 0.0;
        $taxBreakdown = [];
        $customer = $subId > 0 ? $this->get_subscription_tax_customer($subId, $fallbackOrder) : null;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $rowTotals = $this->get_subscription_admin_item_totals($item, $customer);
            $itemSubtotal += (float) ($rowTotals['line_subtotal'] ?? 0.0);
            $itemTax += (float) ($rowTotals['line_tax'] ?? 0.0);
            $itemTotal += (float) ($rowTotals['line_total'] ?? 0.0);
            foreach ((array) ($rowTotals['tax_breakdown'] ?? []) as $rateKey => $rateAmount) {
                $taxBreakdown[(string) $rateKey] = (float) ($taxBreakdown[(string) $rateKey] ?? 0.0) + (float) $rateAmount;
            }
        }

        foreach ($feeLines as $feeLine) {
            if (!is_array($feeLine)) {
                continue;
            }

            $rowTotals = $this->get_subscription_fee_line_totals($feeLine);
            $feeSubtotal += (float) ($rowTotals['line_subtotal'] ?? 0.0);
            $feeTax += (float) ($rowTotals['line_tax'] ?? 0.0);
            $feeTotal += (float) ($rowTotals['line_total'] ?? 0.0);
            foreach ((array) ($rowTotals['tax_breakdown'] ?? []) as $rateKey => $rateAmount) {
                $taxBreakdown[(string) $rateKey] = (float) ($taxBreakdown[(string) $rateKey] ?? 0.0) + (float) $rateAmount;
            }
        }

        foreach ($shippingLines as $shippingLine) {
            if (!is_array($shippingLine)) {
                continue;
            }

            $rowTotals = $this->get_subscription_shipping_line_totals($shippingLine);
            $shippingSubtotal += (float) ($rowTotals['line_subtotal'] ?? 0.0);
            $shippingTax += (float) ($rowTotals['line_tax'] ?? 0.0);
            $shippingTotal += (float) ($rowTotals['line_total'] ?? 0.0);
            foreach ((array) ($rowTotals['tax_breakdown'] ?? []) as $rateKey => $rateAmount) {
                $taxBreakdown[(string) $rateKey] = (float) ($taxBreakdown[(string) $rateKey] ?? 0.0) + (float) $rateAmount;
            }
        }

        $subtotal = $itemSubtotal + $feeSubtotal + $shippingSubtotal;
        $tax = $itemTax + $feeTax + $shippingTax;
        $total = $itemTotal + $feeTotal + $shippingTotal;

        return [
            'item_subtotal' => (float) wc_format_decimal((string) $itemSubtotal),
            'item_tax' => (float) wc_format_decimal((string) $itemTax),
            'item_total' => (float) wc_format_decimal((string) $itemTotal),
            'fee_subtotal' => (float) wc_format_decimal((string) $feeSubtotal),
            'fee_tax' => (float) wc_format_decimal((string) $feeTax),
            'fee_total' => (float) wc_format_decimal((string) $feeTotal),
            'shipping_subtotal' => (float) wc_format_decimal((string) $shippingSubtotal),
            'shipping_tax' => (float) wc_format_decimal((string) $shippingTax),
            'shipping_total' => (float) wc_format_decimal((string) $shippingTotal),
            'subtotal' => (float) wc_format_decimal((string) $subtotal),
            'tax' => (float) wc_format_decimal((string) $tax),
            'total' => (float) wc_format_decimal((string) $total),
            'tax_breakdown' => array_map(static function ($amount) {
                return (float) wc_format_decimal((string) $amount);
            }, $taxBreakdown),
        ];
    }

    private function render_subscription_admin_tax_cells(array $taxColumns, array $taxBreakdown, bool $editing, string $viewClassPrefix, string $inputClassPrefix = '', string $inputNamePrefix = '', bool $readOnlyInput = true): void {
        foreach ($taxColumns as $column) {
            $rateKey = (string) ($column['key'] ?? '');
            $safeKey = sanitize_html_class($rateKey !== '' ? $rateKey : 'tax');
            $amount = (float) ($taxBreakdown[$rateKey] ?? 0.0);
            echo '<td class="line_tax hb-ucs-tax-cell hb-ucs-tax-cell-' . esc_attr($safeKey) . '" width="1%" data-tax-rate="' . esc_attr($rateKey) . '" data-tax-amount="' . esc_attr((string) wc_format_decimal((string) $amount, wc_get_price_decimals())) . '">';
            echo '<div class="view ' . esc_attr($viewClassPrefix) . '-tax-view">' . wp_kses_post(function_exists('wc_price') ? wc_price($amount) : number_format($amount, 2, '.', '')) . '</div>';
            echo '<div class="edit">';
            if ($inputNamePrefix !== '') {
                echo '<input type="text" class="line_tax wc_input_price hb-ucs-tax-input ' . esc_attr($inputClassPrefix) . '" name="' . esc_attr($inputNamePrefix . '[' . $rateKey . ']') . '" value="' . esc_attr((string) wc_format_decimal((string) $amount, wc_get_price_decimals())) . '"' . ($readOnlyInput ? ' readonly="readonly"' : '') . ' />';
            } else {
                echo '<input type="text" class="line_tax wc_input_price hb-ucs-tax-input ' . esc_attr($inputClassPrefix) . '" value="' . esc_attr((string) wc_format_decimal((string) $amount, wc_get_price_decimals())) . '" readonly="readonly" />';
            }
            echo '</div>';
            echo '</td>';
        }
    }

    private function render_admin_subscription_fee_row(int $rowIndex, array $feeLine, array $taxColumns = [], bool $editing = false): void {
        $name = (string) ($feeLine['name'] ?? __('Kosten', 'hb-ucs'));
        $totals = $this->get_subscription_fee_line_totals($feeLine);

        $this->render_admin_template('subscription-order-fee.php', [
            'editing' => $editing,
            'name' => $name,
            'rowIndex' => $rowIndex,
            'taxColumns' => $taxColumns,
            'totals' => $totals,
        ]);
    }

    private function render_admin_subscription_shipping_row(int $rowIndex, array $shippingLine, array $taxColumns = [], bool $editing = false, array $items = []): void {
        unset($items);

        $methodTitle = (string) ($shippingLine['method_title'] ?? '');
        $methodId = (string) ($shippingLine['method_id'] ?? '');
        $totals = $this->get_subscription_shipping_line_totals($shippingLine);
        $displayMetaRows = [];

        $this->render_admin_template('subscription-order-shipping.php', [
            'displayMetaRows' => $displayMetaRows,
            'editing' => $editing,
            'methodId' => $methodId,
            'methodTitle' => $methodTitle,
            'rowIndex' => $rowIndex,
            'taxColumns' => $taxColumns,
            'totals' => $totals,
        ]);
    }

    private function render_admin_subscription_item_row(int $rowIndex, array $item, string $scheme, array $taxColumns = [], $customer = null, bool $editing = false): void {
        $currentBaseProductId = (int) ($item['base_product_id'] ?? 0);
        $currentVariationId = (int) ($item['base_variation_id'] ?? 0);
        $currentSelectedId = $currentVariationId > 0 ? $currentVariationId : $currentBaseProductId;
        $currentEditProductId = $currentBaseProductId > 0 ? $currentBaseProductId : $currentSelectedId;
        $currentQty = max(1, (int) ($item['qty'] ?? 1));
        $currentLabel = $this->get_subscription_item_label($item);
        $currentEditProductLabel = $this->get_admin_product_label($currentEditProductId);
        $totals = $this->get_subscription_admin_item_totals($item, $customer);
        $product = $currentSelectedId > 0 ? wc_get_product($currentSelectedId) : false;
        $sku = $product && is_object($product) && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
        $productLink = $currentEditProductId > 0 ? admin_url('post.php?post=' . $currentEditProductId . '&action=edit') : '';
        $thumbnailHtml = '';

        if ($product && is_object($product) && method_exists($product, 'get_image')) {
            $thumbnailHtml = (string) $product->get_image('thumbnail', ['title' => ''], false);
        }

        $selectedAttributes = $this->get_subscription_item_attribute_snapshot($item);
        $displayMetaRows = $this->get_subscription_item_display_meta($item);
        $variationSummary = $this->get_subscription_item_variation_summary($item);

        $this->render_admin_template('subscription-order-item.php', [
            'currentBaseProductId' => $currentBaseProductId,
            'currentEditProductId' => $currentEditProductId,
            'currentEditProductLabel' => $currentEditProductLabel,
            'currentLabel' => $currentLabel,
            'currentQty' => $currentQty,
            'currentVariationId' => $currentVariationId,
            'displayMetaRows' => $displayMetaRows,
            'editing' => $editing,
            'productLink' => $productLink,
            'rowIndex' => $rowIndex,
            'scheme' => $scheme,
            'selectedAttributes' => $selectedAttributes,
            'sku' => $sku,
            'taxColumns' => $taxColumns,
            'thumbnailHtml' => $thumbnailHtml,
            'totals' => $totals,
            'variationSummary' => $variationSummary,
        ]);
    }

    private function get_subscription_contact_snapshot(int $userId, $order, int $subId = 0): array {
        $billingEmail = '';
        $billingPhone = '';
        $billingAddress = '—';
        $shippingAddress = '—';

        if ($subId > 0) {
            $billingSnapshot = $this->get_subscription_address_snapshot($subId, 'billing', $userId, $order);
            $shippingSnapshot = $this->get_subscription_address_snapshot($subId, 'shipping', $userId, $order);

            if (!empty($billingSnapshot)) {
                $billingEmail = (string) ($billingSnapshot['email'] ?? '');
                $billingPhone = (string) ($billingSnapshot['phone'] ?? '');
                $billingAddress = $this->format_address_lines([
                    trim((string) ($billingSnapshot['first_name'] ?? '') . ' ' . (string) ($billingSnapshot['last_name'] ?? '')),
                    (string) ($billingSnapshot['company'] ?? ''),
                    (string) ($billingSnapshot['address_1'] ?? ''),
                    (string) ($billingSnapshot['address_2'] ?? ''),
                    trim((string) ($billingSnapshot['postcode'] ?? '') . ' ' . (string) ($billingSnapshot['city'] ?? '')),
                    (string) ($billingSnapshot['country'] ?? ''),
                ]);
            }

            if (!empty($shippingSnapshot)) {
                $shippingAddress = $this->format_address_lines([
                    trim((string) ($shippingSnapshot['first_name'] ?? '') . ' ' . (string) ($shippingSnapshot['last_name'] ?? '')),
                    (string) ($shippingSnapshot['company'] ?? ''),
                    (string) ($shippingSnapshot['address_1'] ?? ''),
                    (string) ($shippingSnapshot['address_2'] ?? ''),
                    trim((string) ($shippingSnapshot['postcode'] ?? '') . ' ' . (string) ($shippingSnapshot['city'] ?? '')),
                    (string) ($shippingSnapshot['country'] ?? ''),
                ]);
            }
        }

        if ($order && is_object($order)) {
            if ($billingEmail === '' && method_exists($order, 'get_billing_email')) {
                $billingEmail = (string) $order->get_billing_email();
            }
            if ($billingPhone === '' && method_exists($order, 'get_billing_phone')) {
                $billingPhone = (string) $order->get_billing_phone();
            }
            if (($billingAddress === '—' || $shippingAddress === '—') && method_exists($order, 'get_address')) {
                $billing = (array) $order->get_address('billing');
                $shipping = (array) $order->get_address('shipping');

                if ($billingAddress === '—') {
                    $billingAddress = $this->format_address_lines([
                        trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? '')),
                        $billing['company'] ?? '',
                        $billing['address_1'] ?? '',
                        $billing['address_2'] ?? '',
                        trim(($billing['postcode'] ?? '') . ' ' . ($billing['city'] ?? '')),
                        $billing['country'] ?? '',
                    ]);
                }

                if ($shippingAddress === '—') {
                    $shippingAddress = $this->format_address_lines([
                        trim(($shipping['first_name'] ?? '') . ' ' . ($shipping['last_name'] ?? '')),
                        $shipping['company'] ?? '',
                        $shipping['address_1'] ?? '',
                        $shipping['address_2'] ?? '',
                        trim(($shipping['postcode'] ?? '') . ' ' . ($shipping['city'] ?? '')),
                        $shipping['country'] ?? '',
                    ]);
                }
            }
        }

        if ($userId > 0) {
            $user = get_user_by('id', $userId);
            if ($user && is_object($user)) {
                if ($billingEmail === '') {
                    $billingEmail = (string) $user->user_email;
                }
                if ($billingPhone === '') {
                    $billingPhone = (string) get_user_meta($userId, 'billing_phone', true);
                }
                if ($billingAddress === '—') {
                    $billingAddress = $this->format_address_lines([
                        trim((string) get_user_meta($userId, 'billing_first_name', true) . ' ' . (string) get_user_meta($userId, 'billing_last_name', true)),
                        (string) get_user_meta($userId, 'billing_company', true),
                        (string) get_user_meta($userId, 'billing_address_1', true),
                        (string) get_user_meta($userId, 'billing_address_2', true),
                        trim((string) get_user_meta($userId, 'billing_postcode', true) . ' ' . (string) get_user_meta($userId, 'billing_city', true)),
                        (string) get_user_meta($userId, 'billing_country', true),
                    ]);
                }
                if ($shippingAddress === '—') {
                    $shippingAddress = $this->format_address_lines([
                        trim((string) get_user_meta($userId, 'shipping_first_name', true) . ' ' . (string) get_user_meta($userId, 'shipping_last_name', true)),
                        (string) get_user_meta($userId, 'shipping_company', true),
                        (string) get_user_meta($userId, 'shipping_address_1', true),
                        (string) get_user_meta($userId, 'shipping_address_2', true),
                        trim((string) get_user_meta($userId, 'shipping_postcode', true) . ' ' . (string) get_user_meta($userId, 'shipping_city', true)),
                        (string) get_user_meta($userId, 'shipping_country', true),
                    ]);
                }
            }
        }

        return [
            'billing_email' => $billingEmail,
            'billing_phone' => $billingPhone,
            'billing_address' => $billingAddress,
            'shipping_address' => $shippingAddress,
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

    private function get_frontend_order_status_label(string $status): string {
        $normalizedStatus = strpos($status, 'wc-') === 0 ? $status : 'wc-' . $status;

        if (function_exists('wc_get_order_status_name')) {
            return (string) wc_get_order_status_name($normalizedStatus);
        }

        $statuses = function_exists('wc_get_order_statuses') ? (array) wc_get_order_statuses() : [];

        return (string) ($statuses[$normalizedStatus] ?? ucfirst(str_replace(['wc-', '-'], ['', ' '], $normalizedStatus)));
    }

    private function get_available_subscription_admin_actions(int $subId): array {
        $status = (string) get_post_meta($subId, self::SUB_META_STATUS, true);
        $actions = [
            'create_renewal_order' => __('Maak renewal order aan', 'hb-ucs'),
            'sync_customer_addresses' => __('Synchroniseer klantadressen', 'hb-ucs'),
        ];

        if ($status === 'paused' || $status === 'on-hold') {
            $actions['resume_subscription'] = __('Hervat abonnement', 'hb-ucs');
        } elseif (!in_array($status, ['cancelled', 'expired'], true)) {
            $actions['pause_subscription'] = __('Pauzeer abonnement', 'hb-ucs');
        }

        if ($status !== 'cancelled') {
            $actions['cancel_subscription'] = __('Annuleer abonnement', 'hb-ucs');
        }

        return $actions;
    }

    private function get_subscription_admin_notes(int $subId): array {
        if ($subId <= 0) {
            return [];
        }

        $comments = get_comments([
            'post_id' => $subId,
            'type' => 'order_note',
            'status' => 'approve',
            'orderby' => 'comment_ID',
            'order' => 'DESC',
        ]);

        if (!is_array($comments)) {
            return [];
        }

        $notes = [];
        foreach ($comments as $comment) {
            if (!$comment instanceof \WP_Comment) {
                continue;
            }

            $dateCreated = null;
            if (!empty($comment->comment_date_gmt)) {
                try {
                    $dateCreated = new \WC_DateTime($comment->comment_date_gmt, new \DateTimeZone('GMT'));
                    $dateCreated->setTimezone(wp_timezone());
                } catch (\Throwable $e) {
                    $dateCreated = null;
                }
            }

            $addedBy = trim((string) $comment->comment_author);
            if ($addedBy === '') {
                $addedBy = __('Systeem', 'hb-ucs');
            }

            $notes[] = (object) [
                'id' => (int) $comment->comment_ID,
                'content' => (string) $comment->comment_content,
                'date_created' => $dateCreated,
                'added_by' => $addedBy,
                'customer_note' => get_comment_meta((int) $comment->comment_ID, 'is_customer_note', true) === '1',
            ];
        }

        return $notes;
    }

    private function add_subscription_admin_note(int $subId, string $content, bool $customerNote = false): int {
        $content = trim($content);
        if ($subId <= 0 || $content === '') {
            return 0;
        }

        if (function_exists('wc_get_order')) {
            $order = wc_get_order($subId);
            if ($order && is_object($order) && method_exists($order, 'add_order_note')) {
                return (int) $order->add_order_note($content, $customerNote ? 1 : 0, is_user_logged_in());
            }
        }

        $user = wp_get_current_user();
        $author = $user instanceof \WP_User && $user->exists() ? $user->display_name : __('Systeem', 'hb-ucs');

        $commentId = wp_insert_comment([
            'comment_post_ID' => $subId,
            'comment_author' => $author,
            'comment_author_email' => $user instanceof \WP_User && $user->exists() ? (string) $user->user_email : '',
            'comment_content' => $content,
            'comment_type' => 'order_note',
            'comment_agent' => 'WooCommerce',
            'user_id' => $user instanceof \WP_User && $user->exists() ? (int) $user->ID : 0,
            'comment_approved' => 1,
        ]);

        if ($commentId > 0 && $customerNote) {
            update_comment_meta($commentId, 'is_customer_note', '1');
        }

        return (int) $commentId;
    }

    private function process_subscription_admin_action(int $subId, string $action): void {
        if ($subId <= 0 || $action === '') {
            return;
        }

        switch ($action) {
            case 'create_renewal_order':
                $result = $this->create_renewal_order_and_payment($subId, true);
                if (is_wp_error($result)) {
                    $this->add_subscription_admin_note($subId, sprintf(__('Actie mislukt: %s', 'hb-ucs'), $result->get_error_message()));
                    break;
                }

                $renewalOrderId = (int) $result;
                $message = $renewalOrderId > 0
                    ? sprintf(__('Renewal order #%d is handmatig aangemaakt vanuit de abonnement editor.', 'hb-ucs'), $renewalOrderId)
                    : __('Renewal order is handmatig aangemaakt vanuit de abonnement editor.', 'hb-ucs');
                $this->add_subscription_admin_note($subId, $message);
                break;

            case 'sync_customer_addresses':
                $userId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
                if ($userId > 0) {
                    $this->refresh_subscription_contact_snapshots_and_shipping(
                        $subId,
                        $this->build_user_contact_snapshot($userId, 'billing'),
                        $this->build_user_contact_snapshot($userId, 'shipping')
                    );
                    $this->add_subscription_admin_note($subId, __('Factuur- en verzendadres opnieuw geladen vanaf het klantprofiel.', 'hb-ucs'));
                }
                break;

            case 'pause_subscription':
                $this->persist_subscription_runtime_state($subId, 'paused');
                $this->add_subscription_admin_note($subId, __('Abonnement is gepauzeerd vanuit de backend.', 'hb-ucs'));
                break;

            case 'resume_subscription':
                $this->persist_subscription_runtime_state($subId, 'active');
                $this->add_subscription_admin_note($subId, __('Abonnement is hervat vanuit de backend.', 'hb-ucs'));
                break;

            case 'cancel_subscription':
                $this->persist_subscription_runtime_state($subId, 'cancelled');
                $this->add_subscription_admin_note($subId, __('Abonnement is geannuleerd vanuit de backend.', 'hb-ucs'));
                break;
        }

        $this->sync_subscription_order_type_record($subId);
    }

    public function execute_subscription_admin_action($result, string $action, int $subId, $order = null) {
        if ($subId <= 0 || $action === '') {
            return $result;
        }

        $this->process_subscription_admin_action($subId, $action);

        if ($order && is_object($order) && method_exists($order, 'get_id')) {
            $this->get_subscription_repository()->sync_order_type_self((int) $order->get_id(), false);
        }

        return true;
    }

    public function add_account_menu_item(array $items): array {
        $newItems = [];
        $inserted = false;

        foreach ($items as $key => $label) {
            $newItems[$key] = $label;
            if ($key === 'orders') {
                $newItems[self::ACCOUNT_ENDPOINT] = __('Abonnementen', 'hb-ucs');
                $inserted = true;
            }
        }

        if (!$inserted) {
            $newItems[self::ACCOUNT_ENDPOINT] = __('Abonnementen', 'hb-ucs');
        }

        return $newItems;
    }

    private function get_account_subscription_url(int $subId = 0): string {
        $url = function_exists('wc_get_account_endpoint_url') ? wc_get_account_endpoint_url(self::ACCOUNT_ENDPOINT) : home_url('/');
        if ($subId > 0) {
            $url = add_query_arg('subscription_id', $subId, $url);
        }
        return $url;
    }

    public function render_account_endpoint(): void {
        if (!is_user_logged_in()) {
            echo '<p>' . esc_html__('Log in om je abonnementen te beheren.', 'hb-ucs') . '</p>';
            return;
        }

        echo '<div class="e-my-account-tab e-my-account-tab__edit-account hb-ucs-elementor-account-tab">';
        echo '<div class="woocommerce-MyAccount-content-wrapper hb-ucs-elementor-account-content">';

        if (function_exists('wc_print_notices')) {
            wc_print_notices();
        }

        $userId = (int) get_current_user_id();
        $subId = isset($_GET['subscription_id']) ? (int) absint((string) wp_unslash($_GET['subscription_id'])) : 0;
        $subscription = $subId > 0 ? $this->get_account_subscription_for_user($subId, $userId) : null;

        if ($subId > 0 && !$subscription) {
            echo '<div class="woocommerce-error" role="alert">' . esc_html__('Dit abonnement kon niet worden gevonden.', 'hb-ucs') . '</div>';
        }

        if ($subscription) {
            $subscriptionId = method_exists($subscription, 'get_id') ? (int) $subscription->get_id() : 0;
            $this->render_account_subscription_detail($subscriptionId, $userId);
            echo '</div>';
            echo '</div>';
            return;
        }

        $this->render_account_subscription_list($userId);
        echo '</div>';
        echo '</div>';
    }

    public function render_account_shortcode(array $atts = []): string {
        unset($atts);

        ob_start();
        $this->render_account_endpoint();
        return (string) ob_get_clean();
    }

    public function extend_elementor_my_account_widget_tabs($element, array $args = []): void {
        unset($args);

        if (!is_object($element) || !method_exists($element, 'get_controls') || !method_exists($element, 'update_control')) {
            return;
        }

        $controls = $element->get_controls();
        if (!isset($controls['tabs']['default']) || !is_array($controls['tabs']['default'])) {
            return;
        }

        $defaults = $controls['tabs']['default'];
        foreach ($defaults as $tab) {
            if (($tab['field_key'] ?? '') === self::ACCOUNT_ENDPOINT) {
                return;
            }
        }

        $updatedDefaults = [];
        $inserted = false;
        foreach ($defaults as $tab) {
            $updatedDefaults[] = $tab;
            if (($tab['field_key'] ?? '') === 'orders') {
                $updatedDefaults[] = [
                    'field_key' => self::ACCOUNT_ENDPOINT,
                    'field_label' => __('Abonnementen', 'hb-ucs'),
                    'tab_name' => __('Abonnementen', 'hb-ucs'),
                ];
                $inserted = true;
            }
        }

        if (!$inserted) {
            $updatedDefaults[] = [
                'field_key' => self::ACCOUNT_ENDPOINT,
                'field_label' => __('Abonnementen', 'hb-ucs'),
                'tab_name' => __('Abonnementen', 'hb-ucs'),
            ];
        }

        $element->update_control('tabs', [
            'default' => $updatedDefaults,
        ]);
    }

    private function render_account_subscription_list(int $userId): void {
        $subscriptionIds = $this->get_user_subscription_ids($userId);
        $displayIncludingTax = $this->should_display_account_subscription_prices_including_tax();

        echo '<div class="woocommerce-account-hb-ucs-subscriptions hb-ucs-account-shell hb-ucs-account-shell--list">';
        echo '<div class="hb-ucs-account-hero">';
        echo '<div class="hb-ucs-account-hero__content">';
        echo '<span class="hb-ucs-account-eyebrow">' . esc_html__('Mijn account', 'hb-ucs') . '</span>';
        echo '<h2 class="hb-ucs-account-title">' . esc_html__('Mijn abonnementen', 'hb-ucs') . '</h2>';
        echo '<p class="hb-ucs-account-intro">' . esc_html__('Beheer je leveringen, bekijk je planning en pas je abonnementen aan vanuit één overzicht.', 'hb-ucs') . '</p>';
        echo '</div>';
        echo '</div>';

        if (empty($subscriptionIds)) {
            echo '<div class="hb-ucs-empty-state">';
            echo '<h3>' . esc_html__('Nog geen actieve abonnementen', 'hb-ucs') . '</h3>';
            echo '<p>' . esc_html__('Je hebt momenteel geen actieve abonnementen.', 'hb-ucs') . '</p>';
            echo '</div>';
            echo '</div>';
            return;
        }

        echo '<div class="hb-ucs-subscription-card-grid">';

        foreach ($subscriptionIds as $subId) {
            $status = (string) get_post_meta($subId, self::SUB_META_STATUS, true);
            $statusLabel = $this->get_subscription_statuses()[$status] ?? ($status !== '' ? $status : '—');
            $items = $this->get_subscription_items($subId);
            $nextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
            $locked = $this->subscription_has_locked_orders($subId);

            SubscriptionSyncLogger::debug('frontend.render_account_subscription_list.item', [
                'subscription_id' => $subId,
                'status' => $status,
                'next_payment' => $nextPayment,
                'scheme' => (string) get_post_meta($subId, self::SUB_META_SCHEME, true),
            ]);

            $itemSummary = [];
            foreach ($items as $item) {
                $label = $this->get_subscription_item_label($item);
                $qty = (int) ($item['qty'] ?? 1);
                $itemSummary[] = $qty > 1 ? sprintf('%s × %d', $label, $qty) : $label;
            }

            echo '<article class="hb-ucs-subscription-card woocommerce-MyAccount-content-wrapper woocommerce-EditAccountForm">';
            echo '<div class="hb-ucs-subscription-card__header">';
            echo '<div>';
            echo '<span class="hb-ucs-subscription-card__label">' . esc_html(sprintf(__('Abonnement #%d', 'hb-ucs'), $subId)) . '</span>';
            echo '<h3>' . esc_html(!empty($itemSummary) ? $itemSummary[0] : sprintf(__('Abonnement #%d', 'hb-ucs'), $subId)) . '</h3>';
            echo '</div>';
            echo '<span class="hb-ucs-status-badge hb-ucs-status-badge--' . esc_attr($this->get_account_subscription_status_badge_class($status)) . '">' . esc_html($statusLabel) . '</span>';
            echo '</div>';
            echo '<div class="hb-ucs-subscription-card__meta">';
            echo '<div class="hb-ucs-subscription-card__meta-item"><span>' . esc_html__('Volgende orderdatum', 'hb-ucs') . '</span><strong>' . esc_html($nextPayment > 0 ? $this->format_wp_datetime($nextPayment) : '—') . '</strong></div>';
            echo '<div class="hb-ucs-subscription-card__meta-item"><span>' . esc_html__('Frequentie', 'hb-ucs') . '</span><strong>' . esc_html($this->get_subscription_scheme_label((string) get_post_meta($subId, self::SUB_META_SCHEME, true))) . '</strong></div>';
            $subscriptionTotal = $this->get_subscription_total_amount($subId, $displayIncludingTax);
            echo '<div class="hb-ucs-subscription-card__meta-item"><span>' . esc_html__('Totaal', 'hb-ucs') . '</span><strong>' . wp_kses_post(function_exists('wc_price') ? wc_price($subscriptionTotal) : number_format($subscriptionTotal, 2, '.', '')) . '</strong></div>';
            echo '</div>';
            if (!empty($itemSummary)) {
                echo '<div class="hb-ucs-subscription-card__items">';
                foreach (array_slice($itemSummary, 0, 3) as $summary) {
                    echo '<span class="hb-ucs-chip">' . esc_html($summary) . '</span>';
                }
                if (count($itemSummary) > 3) {
                    echo '<span class="hb-ucs-chip hb-ucs-chip--muted">+' . esc_html((string) (count($itemSummary) - 3)) . '</span>';
                }
                echo '</div>';
            }
            if ($locked) {
                echo '<p class="hb-ucs-subscription-card__notice">' . esc_html__('Er staat nog een open bestelling klaar; nieuwe wijzigingen gelden alleen voor volgende leveringen.', 'hb-ucs') . '</p>';
            }
            echo '<div class="hb-ucs-subscription-card__footer">';
            echo '<a class="button hb-ucs-button hb-ucs-button--primary" href="' . esc_url($this->get_account_subscription_url($subId)) . '">' . esc_html__('Beheren', 'hb-ucs') . '</a>';
            echo '</div>';
            echo '</article>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function render_account_subscription_detail(int $subId, int $userId): void {
        $status = (string) get_post_meta($subId, self::SUB_META_STATUS, true);
        $statusLabel = $this->get_subscription_statuses()[$status] ?? ($status !== '' ? $status : '—');
        $scheme = (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
        $paymentMethodTitle = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
        $mollieMeta = $this->get_subscription_mollie_meta_context($subId);
        $nextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);

        SubscriptionSyncLogger::debug('frontend.render_account_subscription_detail', [
            'subscription_id' => $subId,
            'user_id' => $userId,
            'status' => $status,
            'next_payment' => $nextPayment,
            'scheme' => $scheme,
        ]);

        $locked = $this->subscription_has_locked_orders($subId);
        $items = $this->get_subscription_items($subId);
        $productOptions = $this->get_manageable_subscription_product_options($scheme);
        $contact = $this->get_subscription_contact_snapshot($userId, null, $subId);
        $relatedOrders = $this->get_subscription_related_orders($subId, $userId);
        $manageDisabled = in_array($status, ['cancelled', 'expired'], true);
        $totalAmount = $this->get_subscription_total_amount($subId, $this->should_display_account_subscription_prices_including_tax());
        $scheduleModalId = 'hb-ucs-schedule-modal-' . $subId;
        $contactPanelId = 'hb-ucs-panel-contact-' . $subId;
        $ordersPanelId = 'hb-ucs-panel-orders-' . $subId;
        $summaryDate = $nextPayment > 0 ? $this->format_wp_date($nextPayment) : '—';
        $priceBreakdown = $this->get_account_subscription_price_breakdown($subId);
        $availableShippingRates = $this->get_available_subscription_shipping_rates($subId, $items, $subscription);
        $selectedShippingRateKey = $this->get_selected_subscription_shipping_rate_key($subId, $availableShippingRates, $subscription);
        echo '<div class="woocommerce-account-hb-ucs-subscription hb-ucs-account-shell hb-ucs-account-shell--detail">';
        echo '<p class="hb-ucs-account-backlink"><a href="' . esc_url($this->get_account_subscription_url()) . '">← ' . esc_html__('Terug naar abonnementen', 'hb-ucs') . '</a></p>';
        echo '<section class="hb-ucs-account-hero hb-ucs-account-hero--detail hb-ucs-account-hero--stacked">';
        echo '<div class="hb-ucs-account-hero__content">';
        echo '<span class="hb-ucs-account-eyebrow">' . esc_html__('Mijn account', 'hb-ucs') . '</span>';
        echo '<h2 class="hb-ucs-account-title">' . esc_html__('Mijn Abonnementen', 'hb-ucs') . '</h2>';
        echo '<p class="hb-ucs-account-intro">' . esc_html(sprintf(__('Abonnement #%d', 'hb-ucs'), $subId)) . '</p>';
        echo '<div class="hb-ucs-subscription-summary-pill hb-ucs-status-badge hb-ucs-status-badge--' . esc_attr($this->get_account_subscription_status_badge_class($status)) . '">';
        echo '<span class="hb-ucs-subscription-summary-pill__status">' . esc_html($statusLabel) . '</span>';
        echo '<span aria-hidden="true">•</span>';
        echo '<span class="hb-ucs-subscription-summary-pill__delivery">' . esc_html__('Volgende orderdatum:', 'hb-ucs') . ' ' . esc_html($summaryDate) . '</span>';
        echo '</div>';
        echo '<p class="hb-ucs-account-note">' . esc_html__('Afhankelijk van de betaalmethode kan de verzending 1 tot 3 dagen duren.', 'hb-ucs') . '</p>';
        echo '<div class="hb-ucs-hero-meta hb-ucs-hero-meta--summary">';
        echo '<div class="hb-ucs-hero-meta__item">';
        echo '<span>' . esc_html__('Frequentie', 'hb-ucs') . '</span>';
        echo '<strong>' . esc_html($this->get_subscription_scheme_label($scheme)) . '</strong>';
        echo '</div>';
        echo '<div class="hb-ucs-hero-meta__item">';
        echo '<span>' . esc_html__('Abonnementswaarde', 'hb-ucs') . '</span>';
        echo '<strong>' . wp_kses_post(function_exists('wc_price') ? wc_price($totalAmount) : number_format($totalAmount, 2, '.', '')) . '</strong>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

        if ($locked) {
            $onHoldLabel = $this->get_frontend_order_status_label('on-hold');
            $processingLabel = $this->get_frontend_order_status_label('processing');

            echo '<div class="woocommerce-info hb-ucs-inline-notice" role="status">' . esc_html(sprintf(__('Er staat al een gekoppelde bestelling met status "%1$s" of "%2$s". Wijzigingen aan dit abonnement passen die open bestelling niet meer aan en annuleren die ook niet.', 'hb-ucs'), $onHoldLabel, $processingLabel)) . '</div>';
        }

        echo '<section class="hb-ucs-panel hb-ucs-panel--secondary woocommerce-MyAccount-content-wrapper">';
        echo '<div class="hb-ucs-panel__header hb-ucs-panel__header--compact">';
        echo '<h3>' . esc_html__('Prijsopbouw per levering', 'hb-ucs') . '</h3>';
        echo '<p>' . esc_html((string) ($priceBreakdown['tax_note'] ?? '')) . '</p>';
        echo '</div>';
        echo '<div class="hb-ucs-info-list hb-ucs-info-list--price-breakdown">';
        foreach ((array) ($priceBreakdown['rows'] ?? []) as $row) {
            echo '<div class="hb-ucs-info-list__row">';
            echo '<span>' . esc_html((string) ($row['label'] ?? '')) . '</span>';
            echo '<strong>' . wp_kses_post($this->format_subscription_frontend_price((float) ($row['amount'] ?? 0.0))) . '</strong>';
            echo '</div>';
        }
        echo '<div class="hb-ucs-info-list__row hb-ucs-info-list__row--total">';
        echo '<span>' . esc_html__('Abonnementswaarde totaal', 'hb-ucs') . '</span>';
        echo '<strong>' . wp_kses_post($this->format_subscription_frontend_price((float) ($priceBreakdown['total'] ?? 0.0))) . '</strong>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

        echo '<section class="hb-ucs-panel hb-ucs-panel-actions hb-ucs-panel-actions--overview woocommerce-MyAccount-content-wrapper">';
        echo '<div class="hb-ucs-quick-actions-grid">';

        echo '<form method="post" class="woocommerce-form hb-ucs-quick-action-card hb-ucs-quick-action-card--pause">';
        wp_nonce_field('hb_ucs_account_subscription_' . $subId, 'hb_ucs_account_subscription_nonce');
        echo '<input type="hidden" name="hb_ucs_subscription_id" value="' . esc_attr((string) $subId) . '" />';
        echo '<input type="hidden" name="hb_ucs_subscription_action" value="' . esc_attr($status === 'paused' ? 'resume' : 'pause') . '" />';
        echo '<button type="submit" class="button hb-ucs-quick-action-card__button" ' . disabled($manageDisabled, true, false) . '>';
        echo '<span class="hb-ucs-quick-action-card__icon hb-ucs-quick-action-card__icon--pause" aria-hidden="true">&#10074;&#10074;</span>';
        echo '<span class="hb-ucs-quick-action-card__copy"><strong>' . esc_html($status === 'paused' ? __('Hervat Abonnement', 'hb-ucs') : __('Pauzeer Abonnement', 'hb-ucs')) . '</strong><small>' . esc_html($status === 'paused' ? __('Momenteel gepauzeerd', 'hb-ucs') : __('Tijdelijk stopzetten', 'hb-ucs')) . '</small></span>';
        echo '</button>';
        echo '</form>';

        echo '<div class="hb-ucs-quick-action-card hb-ucs-quick-action-card--schedule">';
        echo '<button type="button" class="button hb-ucs-quick-action-card__button" data-hb-ucs-open-modal="' . esc_attr($scheduleModalId) . '" aria-haspopup="dialog" aria-controls="' . esc_attr($scheduleModalId) . '">';
        echo '<span class="hb-ucs-quick-action-card__icon hb-ucs-quick-action-card__icon--schedule" aria-hidden="true">&#128197;</span>';
        echo '<span class="hb-ucs-quick-action-card__copy"><strong>' . esc_html__('Wijzig Orderdatum', 'hb-ucs') . '</strong><small>' . esc_html($summaryDate) . '</small></span>';
        echo '</button>';
        echo '</div>';

        echo '<form method="post" class="woocommerce-form hb-ucs-quick-action-card hb-ucs-quick-action-card--cancel">';
        wp_nonce_field('hb_ucs_account_subscription_' . $subId, 'hb_ucs_account_subscription_nonce');
        echo '<input type="hidden" name="hb_ucs_subscription_id" value="' . esc_attr((string) $subId) . '" />';
        echo '<input type="hidden" name="hb_ucs_subscription_action" value="cancel" />';
        echo '<button type="submit" class="button hb-ucs-quick-action-card__button" ' . disabled($manageDisabled || $status === 'cancelled', true, false) . ' onclick="return window.confirm(' . wp_json_encode(__('Weet je zeker dat je dit abonnement wilt annuleren?', 'hb-ucs')) . ');">';
        echo '<span class="hb-ucs-quick-action-card__icon hb-ucs-quick-action-card__icon--cancel" aria-hidden="true">&#10005;</span>';
        echo '<span class="hb-ucs-quick-action-card__copy"><strong>' . esc_html__('Annuleer Abonnement', 'hb-ucs') . '</strong><small>' . esc_html__('Definitief stopzetten', 'hb-ucs') . '</small></span>';
        echo '</button>';
        echo '</form>';

        echo '</div>';
        echo '</section>';

        echo '<div class="hb-ucs-product-modal hb-ucs-schedule-modal" id="' . esc_attr($scheduleModalId) . '" hidden aria-hidden="true">';
        echo '<div class="hb-ucs-product-modal__backdrop" data-hb-ucs-close-modal="1"></div>';
        echo '<div class="hb-ucs-product-modal__dialog hb-ucs-schedule-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="hb-ucs-schedule-modal-title-' . esc_attr((string) $subId) . '">';
        echo '<div class="hb-ucs-product-modal__header hb-ucs-schedule-modal__header">';
        echo '<div>';
        echo '<h3 id="hb-ucs-schedule-modal-title-' . esc_attr((string) $subId) . '">' . esc_html__('Volgende orderdatum aanpassen', 'hb-ucs') . '</h3>';
        echo '<p>' . esc_html__('Kies een nieuwe datum voor de volgende order van dit abonnement.', 'hb-ucs') . '</p>';
        echo '</div>';
        echo '<button type="button" class="hb-ucs-product-modal__close" data-hb-ucs-close-modal="1" aria-label="' . esc_attr__('Sluiten', 'hb-ucs') . '">×</button>';
        echo '</div>';
        echo '<form method="post" class="woocommerce-form woocommerce-EditAccountForm hb-ucs-schedule-modal__form">';
        wp_nonce_field('hb_ucs_account_subscription_' . $subId, 'hb_ucs_account_subscription_nonce');
        echo '<input type="hidden" name="hb_ucs_subscription_id" value="' . esc_attr((string) $subId) . '" />';
        echo '<input type="hidden" name="hb_ucs_subscription_action" value="update_schedule" />';
        echo '<input type="hidden" name="scheme" value="' . esc_attr($scheme) . '" />';
        echo '<div class="hb-ucs-schedule-modal__body">';
        echo '<div class="hb-ucs-schedule-modal__summary woocommerce-info">' . esc_html__('Deze wijziging geldt voor toekomstige orders. Afhankelijk van de betaalmethode kan de verzending 1 tot 3 dagen duren. Openstaande bestellingen blijven ongewijzigd.', 'hb-ucs') . '</div>';
        echo '<label class="hb-ucs-footer-field hb-ucs-footer-field--modal"><span>' . esc_html__('Volgende orderdatum', 'hb-ucs') . '</span><input type="date" class="input-text" name="next_payment" value="' . esc_attr($this->format_date_input($nextPayment)) . '" min="' . esc_attr($this->format_date_input(time())) . '" ' . disabled($manageDisabled, true, false) . ' /></label>';
        echo '<div class="hb-ucs-schedule-modal__meta"><span>' . esc_html__('Frequentie', 'hb-ucs') . '</span><strong>' . esc_html($this->get_subscription_scheme_label($scheme)) . '</strong></div>';
        echo '</div>';
        echo '<div class="hb-ucs-schedule-modal__actions">';
        echo '<button type="button" class="button hb-ucs-button hb-ucs-button--secondary" data-hb-ucs-close-modal="1">' . esc_html__('Sluiten', 'hb-ucs') . '</button>';
        echo '<button type="submit" class="button hb-ucs-button hb-ucs-button--primary" ' . disabled($manageDisabled, true, false) . '>' . esc_html__('Orderdatum opslaan', 'hb-ucs') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</div>';
        echo '</div>';

        echo '<section class="hb-ucs-panel hb-ucs-products-panel woocommerce-MyAccount-content-wrapper">';
        echo '<div class="hb-ucs-panel__header hb-ucs-panel__header--compact hb-ucs-panel__header--products">';
        echo '<h3>' . esc_html__('Mijn Producten', 'hb-ucs') . '</h3>';
        echo '</div>';
        echo '<form method="post" class="woocommerce-form woocommerce-EditAccountForm woocommerce-form-hb-ucs-subscription-items hb-ucs-subscription-items-form">';
        wp_nonce_field('hb_ucs_account_subscription_' . $subId, 'hb_ucs_account_subscription_nonce');
        echo '<input type="hidden" name="hb_ucs_subscription_id" value="' . esc_attr((string) $subId) . '" />';
        echo '<input type="hidden" name="hb_ucs_subscription_action" value="update_items" />';
        echo '<input type="hidden" name="scheme" value="' . esc_attr($scheme) . '" />';
        $itemsListId = 'hb-ucs-subscription-items-list-' . $subId;
        $itemTemplateId = 'hb-ucs-subscription-item-template-' . $subId;
        echo '<div id="' . esc_attr($itemsListId) . '" class="hb-ucs-subscription-items-list hb-ucs-subscription-items-list--compact">';
        foreach ($items as $index => $item) {
            $this->render_account_subscription_item_editor_row((string) $index, $item, $manageDisabled, $productOptions, $subId);
        }
        echo '<div class="hb-ucs-products-add">';
        echo '<button type="button" class="button hb-ucs-products-add__button hb-ucs-open-product-modal" data-hb-ucs-add-item-template="' . esc_attr($itemTemplateId) . '" data-hb-ucs-add-item-list="' . esc_attr($itemsListId) . '" data-picker-title="' . esc_attr__('Kies een product', 'hb-ucs') . '" ' . disabled($manageDisabled, true, false) . '>' . esc_html__('＋ Product Toevoegen', 'hb-ucs') . '</button>';
        echo '</div>';
        echo '</div>';
        ob_start();
        $this->render_account_subscription_item_editor_row('__HB_UCS_INDEX__', [
            'display_label' => __('Nieuw product', 'hb-ucs'),
            'variation_summary' => __('Kies product en variaties via de modal.', 'hb-ucs'),
            'qty' => 1,
        ], $manageDisabled, $productOptions, $subId);
        echo '<template id="' . esc_attr($itemTemplateId) . '">' . ob_get_clean() . '</template>';
        $this->render_manageable_product_picker_modal($productOptions, $manageDisabled);
        echo '<div class="hb-ucs-subscription-items-footer hb-ucs-subscription-items-footer--dashboard">';
        echo '<button type="submit" class="button hb-ucs-button hb-ucs-button--primary" ' . disabled($manageDisabled, true, false) . '>' . esc_html__('Wijzigingen opslaan', 'hb-ucs') . '</button>';
        echo '</div>';
        echo '</form>';
        echo '</section>';

        echo '<section class="hb-ucs-panel hb-ucs-panel--secondary woocommerce-MyAccount-content-wrapper">';
        echo '<div class="hb-ucs-panel__header hb-ucs-panel__header--compact">';
        echo '<h3>' . esc_html__('Verzendmethode', 'hb-ucs') . '</h3>';
        echo '</div>';
        if (empty($availableShippingRates)) {
            echo '<p class="woocommerce-info hb-ucs-inline-help">' . esc_html__('Er zijn momenteel geen verzendmethodes beschikbaar voor dit abonnement. Controleer het verzendadres en de WooCommerce verzendzones.', 'hb-ucs') . '</p>';
        } else {
            echo '<form method="post" class="woocommerce-form woocommerce-EditAccountForm hb-ucs-shipping-method-form">';
            wp_nonce_field('hb_ucs_account_subscription_' . $subId, 'hb_ucs_account_subscription_nonce');
            echo '<input type="hidden" name="hb_ucs_subscription_id" value="' . esc_attr((string) $subId) . '" />';
            echo '<input type="hidden" name="hb_ucs_subscription_action" value="update_shipping_method" />';
            echo '<label class="hb-ucs-footer-field"><span>' . esc_html__('Beschikbare verzendmethodes', 'hb-ucs') . '</span>';
            echo '<select name="hb_ucs_subscription_shipping_rate" class="input-text" ' . disabled($manageDisabled, true, false) . '>';
            foreach ($availableShippingRates as $rate) {
                if (!is_array($rate)) {
                    continue;
                }

                $rateKey = $this->get_subscription_shipping_rate_key($rate);
                if ($rateKey === '') {
                    continue;
                }

                echo '<option value="' . esc_attr($rateKey) . '" ' . selected($selectedShippingRateKey, $rateKey, false) . '>' . esc_html($this->format_account_subscription_shipping_rate_label($rate)) . '</option>';
            }
            echo '</select></label>';
            echo '<div class="hb-ucs-subscription-items-footer hb-ucs-subscription-items-footer--dashboard">';
            echo '<button type="submit" class="button hb-ucs-button hb-ucs-button--primary" ' . disabled($manageDisabled, true, false) . '>' . esc_html__('Verzendmethode opslaan', 'hb-ucs') . '</button>';
            echo '</div>';
            echo '</form>';
        }
        echo '</section>';

        echo '<div class="hb-ucs-detail-secondary">';

        echo '<section id="' . esc_attr($contactPanelId) . '" class="hb-ucs-panel hb-ucs-panel--secondary woocommerce-MyAccount-content-wrapper">';
        echo '<div class="hb-ucs-panel__header hb-ucs-panel__header--compact">';
        echo '<h3>' . esc_html__('Klant- en adresgegevens', 'hb-ucs') . '</h3>';
        echo '</div>';
        echo '<div class="hb-ucs-info-list">';
        echo '<div class="hb-ucs-info-list__row"><span>' . esc_html__('E-mail', 'hb-ucs') . '</span><strong>' . esc_html((string) ($contact['billing_email'] ?? '—')) . '</strong></div>';
        echo '<div class="hb-ucs-info-list__row"><span>' . esc_html__('Telefoon', 'hb-ucs') . '</span><strong>' . esc_html((string) ($contact['billing_phone'] ?? '—')) . '</strong></div>';
        echo '<div class="hb-ucs-info-list__row"><span>' . esc_html__('Betaalmethode', 'hb-ucs') . '</span><strong>' . esc_html($paymentMethodTitle !== '' ? $paymentMethodTitle : '—') . '</strong></div>';
        echo '</div>';
        echo '<div class="hb-ucs-address-grid">';
        echo '<div class="hb-ucs-address-block">';
        echo '<h4>' . esc_html__('Factuuradres', 'hb-ucs') . '</h4>';
        echo '<div>' . wp_kses_post((string) ($contact['billing_address'] ?? '—')) . '</div>';
        echo '</div>';
        echo '<div class="hb-ucs-address-block">';
        echo '<h4>' . esc_html__('Verzendadres', 'hb-ucs') . '</h4>';
        echo '<div>' . wp_kses_post((string) ($contact['shipping_address'] ?? '—')) . '</div>';
        echo '</div>';
        echo '</div>';
        echo '</section>';

        if (!empty($relatedOrders)) {
            echo '<section id="' . esc_attr($ordersPanelId) . '" class="hb-ucs-panel hb-ucs-panel--secondary woocommerce-MyAccount-content-wrapper">';
            echo '<div class="hb-ucs-panel__header hb-ucs-panel__header--compact">';
            echo '<h3>' . esc_html__('Gerelateerde bestellingen', 'hb-ucs') . '</h3>';
            echo '</div>';
            echo '<div class="hb-ucs-related-orders">';
            foreach ($relatedOrders as $row) {
                $order = $row['order'];
                if (!$order || !is_object($order) || !method_exists($order, 'get_view_order_url')) {
                    continue;
                }
                echo '<a class="hb-ucs-related-order" href="' . esc_url($order->get_view_order_url()) . '">';
                echo '<div class="hb-ucs-related-order__main"><strong>#' . esc_html((string) $order->get_id()) . '</strong><span>' . esc_html((string) $row['type']) . '</span></div>';
                echo '<div class="hb-ucs-related-order__meta"><span>' . esc_html(wc_get_order_status_name($order->get_status())) . '</span><span>' . esc_html($this->format_wc_datetime_for_site_settings($order->get_date_created())) . '</span><strong>' . wp_kses_post($order->get_formatted_order_total()) . '</strong></div>';
                echo '</a>';
            }
            echo '</div>';
            echo '</section>';
        }

        echo '</div>';
        echo '</div>';
    }

    private function get_account_subscription_status_badge_class(string $status): string {
        switch ($status) {
            case 'active':
                return 'active';
            case 'paused':
            case 'on-hold':
                return 'paused';
            case 'cancelled':
            case 'expired':
                return 'cancelled';
        }

        return 'neutral';
    }

    private function get_account_subscription_item_image_html(array $item): string {
        if (!function_exists('wc_placeholder_img')) {
            return '';
        }

        $productId = (int) (($item['base_variation_id'] ?? 0) > 0 ? ($item['base_variation_id'] ?? 0) : ($item['base_product_id'] ?? 0));
        $product = $productId > 0 ? wc_get_product($productId) : null;
        if ($product && is_object($product) && method_exists($product, 'get_image')) {
            $image = (string) $product->get_image('woocommerce_thumbnail', ['class' => 'hb-ucs-subscription-item-card__image-el']);
            if ($image !== '') {
                return $image;
            }
        }

        return (string) wc_placeholder_img('woocommerce_thumbnail', ['class' => 'hb-ucs-subscription-item-card__image-el']);
    }

    public function maybe_handle_account_subscription_action(): void {
        if (!is_user_logged_in() || !function_exists('is_account_page') || !is_account_page()) {
            return;
        }
        if (!isset($_POST['hb_ucs_subscription_action'], $_POST['hb_ucs_subscription_id'])) {
            return;
        }

        $subId = (int) absint((string) wp_unslash($_POST['hb_ucs_subscription_id']));
        $action = sanitize_key((string) wp_unslash($_POST['hb_ucs_subscription_action']));
        if ($subId <= 0 || $action === '') {
            return;
        }

        $nonce = isset($_POST['hb_ucs_account_subscription_nonce']) ? (string) wp_unslash($_POST['hb_ucs_account_subscription_nonce']) : '';
        if (!wp_verify_nonce($nonce, 'hb_ucs_account_subscription_' . $subId)) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Je sessie is verlopen. Probeer het opnieuw.', 'hb-ucs'), 'error');
            }
            wp_safe_redirect($this->get_account_subscription_url($subId));
            exit;
        }

        $userId = (int) get_current_user_id();
        $subscription = $this->get_account_subscription_for_user($subId, $userId);
        if (!$subscription) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice(__('Dit abonnement is niet beschikbaar in jouw account.', 'hb-ucs'), 'error');
            }
            wp_safe_redirect($this->get_account_subscription_url());
            exit;
        }

        $locked = $this->subscription_has_locked_orders($subId);
        $status = method_exists($subscription, 'get_subscription_status')
            ? (string) $subscription->get_subscription_status()
            : (string) get_post_meta($subId, self::SUB_META_STATUS, true);
        $manageDisabled = in_array($status, ['cancelled', 'expired'], true);
        $didUpdateSubscription = false;
        $syncViaOrderObject = false;
        $saveSubscriptionObject = true;
        $shadowMetaUpdates = [];

        SubscriptionSyncLogger::debug('frontend.account_action.start', [
            'subscription_id' => $subId,
            'action' => $action,
            'current_status' => $status,
            'current_next_payment' => method_exists($subscription, 'get_next_payment_timestamp')
                ? (int) $subscription->get_next_payment_timestamp()
                : (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true),
        ]);

        switch ($action) {
            case 'pause':
                if ($manageDisabled) {
                    wc_add_notice(__('Dit abonnement kan nu niet gepauzeerd worden.', 'hb-ucs'), 'error');
                    break;
                }
                $this->apply_account_subscription_status_to_order($subscription, 'paused');
                $shadowMetaUpdates = [
                    '_hb_ucs_subscription_status' => 'paused',
                    self::SUB_META_STATUS => 'paused',
                ];
                $this->add_subscription_admin_note($subId, __('Klant heeft het abonnement gepauzeerd via Mijn Account.', 'hb-ucs'));
                $didUpdateSubscription = true;
                $syncViaOrderObject = true;
                wc_add_notice(__('Het abonnement is gepauzeerd.', 'hb-ucs'));
                break;

            case 'resume':
                if ($manageDisabled) {
                    wc_add_notice(__('Dit abonnement kan nu niet hervat worden.', 'hb-ucs'), 'error');
                    break;
                }
                $this->apply_account_subscription_status_to_order($subscription, 'active');
                $nextPayment = method_exists($subscription, 'get_next_payment_timestamp')
                    ? (int) $subscription->get_next_payment_timestamp()
                    : (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
                if ($nextPayment <= time()) {
                    $nextPayment = (int) $this->calculate_next_payment_timestamp($subId);
                }
                if (method_exists($subscription, 'set_next_payment_timestamp')) {
                    $subscription->set_next_payment_timestamp($nextPayment);
                } elseif (method_exists($subscription, 'update_meta_data')) {
                    $subscription->update_meta_data('_hb_ucs_subscription_next_payment', $nextPayment);
                }
                if (method_exists($subscription, 'update_meta_data')) {
                    $subscription->update_meta_data(self::SUB_META_NEXT_PAYMENT, $nextPayment);
                }
                $shadowMetaUpdates = [
                    '_hb_ucs_subscription_status' => 'active',
                    self::SUB_META_STATUS => 'active',
                    '_hb_ucs_subscription_next_payment' => $nextPayment,
                    self::SUB_META_NEXT_PAYMENT => $nextPayment,
                ];
                $this->add_subscription_admin_note($subId, __('Klant heeft het abonnement hervat via Mijn Account.', 'hb-ucs'));
                $didUpdateSubscription = true;
                $syncViaOrderObject = true;
                wc_add_notice(__('Het abonnement is hervat.', 'hb-ucs'));
                break;

            case 'cancel':
                if ($manageDisabled) {
                    wc_add_notice(__('Dit abonnement kan nu niet geannuleerd worden.', 'hb-ucs'), 'error');
                    break;
                }
                $this->apply_account_subscription_status_to_order($subscription, 'cancelled');
                $endTimestamp = time();
                if (method_exists($subscription, 'update_meta_data')) {
                    $subscription->update_meta_data(self::SUB_META_END_DATE, $endTimestamp);
                    $subscription->update_meta_data('_hb_ucs_subscription_end_date', $endTimestamp);
                }
                $shadowMetaUpdates = [
                    '_hb_ucs_subscription_status' => 'cancelled',
                    self::SUB_META_STATUS => 'cancelled',
                    '_hb_ucs_subscription_end_date' => $endTimestamp,
                    self::SUB_META_END_DATE => $endTimestamp,
                ];
                $this->add_subscription_admin_note($subId, __('Klant heeft het abonnement geannuleerd via Mijn Account.', 'hb-ucs'));
                $didUpdateSubscription = true;
                $syncViaOrderObject = true;
                wc_add_notice(__('Het abonnement is geannuleerd.', 'hb-ucs'));
                break;

            case 'update_schedule':
                if ($manageDisabled) {
                    wc_add_notice(__('Dit abonnement kan nu niet worden aangepast.', 'hb-ucs'), 'error');
                    break;
                }
                $scheme = isset($_POST['scheme']) ? sanitize_key((string) wp_unslash($_POST['scheme'])) : '';
                $freqs = $this->get_enabled_frequencies();
                if ($scheme === '' || !isset($freqs[$scheme])) {
                    wc_add_notice(__('Kies een geldige frequentie.', 'hb-ucs'), 'error');
                    break;
                }
                $nextPaymentRaw = isset($_POST['next_payment']) ? sanitize_text_field((string) wp_unslash($_POST['next_payment'])) : '';
                $nextPayment = $this->resolve_account_next_payment_timestamp($nextPaymentRaw);
                if ($nextPayment <= time()) {
                    wc_add_notice(__('Kies een geldige datum van vandaag of later.', 'hb-ucs'), 'error');
                    break;
                }
                $existingScheme = (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
                $existingNextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
                $interval = (int) ($freqs[$scheme]['interval'] ?? 1);
                $period = (string) ($freqs[$scheme]['period'] ?? 'week');
                if (method_exists($subscription, 'set_subscription_scheme')) {
                    $subscription->set_subscription_scheme($scheme);
                } elseif (method_exists($subscription, 'update_meta_data')) {
                    $subscription->update_meta_data('_hb_ucs_subscription_scheme', $scheme);
                }
                if (method_exists($subscription, 'set_next_payment_timestamp')) {
                    $subscription->set_next_payment_timestamp($nextPayment);
                } elseif (method_exists($subscription, 'update_meta_data')) {
                    $subscription->update_meta_data('_hb_ucs_subscription_next_payment', $nextPayment);
                }
                if (method_exists($subscription, 'update_meta_data')) {
                    $subscription->update_meta_data(self::SUB_META_SCHEME, $scheme);
                    $subscription->update_meta_data(self::SUB_META_INTERVAL, $interval);
                    $subscription->update_meta_data(self::SUB_META_PERIOD, $period);
                    $subscription->update_meta_data(self::SUB_META_NEXT_PAYMENT, $nextPayment);
                    $subscription->update_meta_data('_hb_ucs_subscription_interval', $interval);
                    $subscription->update_meta_data('_hb_ucs_subscription_period', $period);
                }
                $shadowMetaUpdates = [
                    '_hb_ucs_subscription_scheme' => $scheme,
                    self::SUB_META_SCHEME => $scheme,
                    '_hb_ucs_subscription_interval' => $interval,
                    self::SUB_META_INTERVAL => $interval,
                    '_hb_ucs_subscription_period' => $period,
                    self::SUB_META_PERIOD => $period,
                    '_hb_ucs_subscription_next_payment' => $nextPayment,
                    self::SUB_META_NEXT_PAYMENT => $nextPayment,
                ];
                $scheduleNote = $this->build_account_subscription_schedule_update_note($existingScheme, $existingNextPayment, $scheme, $nextPayment);
                if ($scheduleNote !== '') {
                    $this->add_subscription_admin_note($subId, $scheduleNote);
                }
                $didUpdateSubscription = true;
                $syncViaOrderObject = true;
                wc_add_notice(__('De planning van het abonnement is bijgewerkt.', 'hb-ucs'));
                break;

            case 'update_items':
                if ($manageDisabled) {
                    wc_add_notice(__('De artikelen kunnen nu niet worden aangepast.', 'hb-ucs'), 'error');
                    break;
                }

                $scheduleScheme = isset($_POST['scheme']) ? sanitize_key((string) wp_unslash($_POST['scheme'])) : (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
                $freqs = $this->get_enabled_frequencies();
                if ($scheduleScheme === '' || !isset($freqs[$scheduleScheme])) {
                    wc_add_notice(__('Kies een geldige frequentie.', 'hb-ucs'), 'error');
                    break;
                }

                $existingItems = $this->get_subscription_items($subId);
                $postedItems = isset($_POST['items']) && is_array($_POST['items']) ? wp_unslash($_POST['items']) : [];
                $newItems = [];
                $hasInvalidSelection = false;

                foreach ($postedItems as $index => $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (!empty($row['_hb_ucs_remove']) || !empty($row['remove'])) {
                        continue;
                    }

                    $selectedId = isset($row['product_id']) ? (int) absint((string) $row['product_id']) : 0;
                    $selectedAttributes = isset($row['selected_attributes']) && is_array($row['selected_attributes']) ? $row['selected_attributes'] : [];
                    $postedDisplayMeta = isset($row['meta']) && is_array($row['meta']) ? $row['meta'] : [];
                    $qty = isset($row['qty']) ? (int) absint((string) $row['qty']) : 1;
                    $existingItem = isset($existingItems[$index]) && is_array($existingItems[$index]) ? $existingItems[$index] : null;
                    $existingSelectedId = $existingItem ? (int) ($existingItem['base_product_id'] ?? 0) : 0;
                    $existingSelectedAttributes = $existingItem ? $this->get_subscription_item_attribute_snapshot($existingItem) : [];
                    $fallbackUnit = ($existingItem && $existingSelectedId === $selectedId) ? (float) ($existingItem['unit_price'] ?? 0.0) : null;

                    if ($existingItem && $existingSelectedId === $selectedId && empty($selectedAttributes)) {
                        $selectedAttributes = $this->get_subscription_item_attribute_snapshot($existingItem);
                    }

                    $item = $this->build_subscription_item_from_selection(
                        $selectedId,
                        $scheduleScheme,
                        $qty,
                        $fallbackUnit,
                        is_array($selectedAttributes) ? $selectedAttributes : []
                    );
                    $currentSelectedAttributes = $item ? $this->get_subscription_item_attribute_snapshot($item) : [];
                    $currentVariationId = $item ? (int) ($item['base_variation_id'] ?? 0) : 0;
                    $existingVariationId = $existingItem ? (int) ($existingItem['base_variation_id'] ?? 0) : 0;

                    $canReuseExistingItemMeta = $existingItem
                        && $existingSelectedId === $selectedId
                        && $existingVariationId === $currentVariationId
                        && $existingSelectedAttributes === $currentSelectedAttributes
                        && $currentVariationId > 0;

                    if (!$item) {
                        if ($selectedId > 0) {
                            $hasInvalidSelection = true;
                            break;
                        }
                        continue;
                    }

                    if (!empty($postedDisplayMeta)) {
                        $item['display_meta'] = $postedDisplayMeta;
                    } elseif ($canReuseExistingItemMeta && !empty($existingItem['display_meta'])) {
                        $item['display_meta'] = $existingItem['display_meta'];
                    } elseif ($currentVariationId <= 0) {
                        $item['display_meta'] = [];
                    }

                    $newItems[] = $item;
                }

                if ($hasInvalidSelection) {
                    wc_add_notice(__('Kies voor ieder geselecteerd product een geldige combinatie van product en variaties voordat je opslaat.', 'hb-ucs'), 'error');
                    break;
                }

                if (empty($newItems)) {
                    wc_add_notice(__('Een abonnement moet minimaal één product bevatten.', 'hb-ucs'), 'error');
                    break;
                }

                $itemsNote = $this->build_account_subscription_items_update_note($existingItems, $newItems);
                $newItems = $this->recalculate_subscription_item_taxes($subId, $newItems, $subscription);
                $this->persist_subscription_items($subId, $newItems);
                $this->persist_linked_legacy_subscription_items($subId, $newItems);
                $shippingLines = $this->calculate_subscription_shipping_lines($subId, $newItems, $subscription);
                $this->persist_subscription_shipping_lines($subId, $shippingLines);
                $this->persist_linked_legacy_subscription_shipping_lines($subId, $shippingLines);
                if ($itemsNote !== '') {
                    $this->add_subscription_admin_note($subId, $itemsNote);
                }
                $didUpdateSubscription = true;
                $saveSubscriptionObject = false;
                wc_add_notice(__('De abonnementartikelen zijn bijgewerkt.', 'hb-ucs'));
                break;

            case 'update_shipping_method':
                if ($manageDisabled) {
                    wc_add_notice(__('De verzendmethode kan nu niet worden aangepast.', 'hb-ucs'), 'error');
                    break;
                }

                $selectedRateKey = isset($_POST['hb_ucs_subscription_shipping_rate'])
                    ? sanitize_text_field((string) wp_unslash($_POST['hb_ucs_subscription_shipping_rate']))
                    : '';
                $currentItems = $this->get_subscription_items($subId);
                if (empty($currentItems)) {
                    wc_add_notice(__('Een abonnement zonder producten heeft geen verzendmethode.', 'hb-ucs'), 'error');
                    break;
                }

                $availableRates = $this->get_available_subscription_shipping_rates($subId, $currentItems, $subscription);
                if (empty($availableRates)) {
                    wc_add_notice(__('Er zijn geen verzendmethodes beschikbaar voor dit abonnement.', 'hb-ucs'), 'error');
                    break;
                }

                $previousRateKey = $this->get_selected_subscription_shipping_rate_key($subId, $availableRates, $subscription);
                $shippingLines = $this->calculate_subscription_shipping_lines($subId, $currentItems, $subscription, $selectedRateKey);
                if (empty($shippingLines)) {
                    wc_add_notice(__('De gekozen verzendmethode is niet beschikbaar.', 'hb-ucs'), 'error');
                    break;
                }

                $this->persist_subscription_shipping_lines($subId, $shippingLines);
                $this->persist_linked_legacy_subscription_shipping_lines($subId, $shippingLines);

                $newRateKey = $this->get_subscription_shipping_rate_key($shippingLines[0]);
                if ($newRateKey !== '' && $newRateKey !== $previousRateKey) {
                    $this->add_subscription_admin_note(
                        $subId,
                        sprintf(
                            __('Klant heeft de verzendmethode aangepast via Mijn Account naar: %s.', 'hb-ucs'),
                            (string) ($shippingLines[0]['method_title'] ?? __('Verzendmethode', 'hb-ucs'))
                        )
                    );
                }

                $didUpdateSubscription = true;
                $saveSubscriptionObject = false;
                wc_add_notice(__('De verzendmethode van het abonnement is bijgewerkt.', 'hb-ucs'));
                break;
        }

        if ($didUpdateSubscription) {
            if ($saveSubscriptionObject && method_exists($subscription, 'save')) {
                $subscription->save();
            }

            if (!empty($shadowMetaUpdates)) {
                $this->persist_account_subscription_shadow_meta($subId, $shadowMetaUpdates);
            }

            if ($syncViaOrderObject) {
                $this->get_subscription_repository()->sync_order_type_self((int) $subscription->get_id(), false);
            } else {
                $this->sync_subscription_order_type_record($subId);
            }

            SubscriptionSyncLogger::debug('frontend.account_action.end', [
                'subscription_id' => $subId,
                'action' => $action,
                'sync_via_order_object' => $syncViaOrderObject,
                'status_after' => (string) get_post_meta($subId, self::SUB_META_STATUS, true),
                'next_payment_after' => (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true),
            ]);
        }

        wp_safe_redirect($this->get_account_subscription_url($subId));
        exit;
    }

    private function get_account_subscription_for_user(int $subId, int $userId) {
        if ($subId <= 0 || $userId <= 0) {
            return null;
        }

        if (!function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($subId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== $this->get_subscription_order_type()->get_type()) {
            return null;
        }

        if (!$this->subscription_belongs_to_user($subId, $userId)) {
            return null;
        }

        return $order;
    }

    private function get_user_subscription_ids(int $userId): array {
        if ($userId <= 0) {
            return [];
        }

        $subscriptionIds = function_exists('wc_get_orders')
            ? wc_get_orders([
                'type' => $this->get_subscription_order_type()->get_type(),
                'limit' => -1,
                'return' => 'ids',
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => array_keys(wc_get_order_statuses()),
            ])
            : [];

        $subscriptionIds = array_map('intval', is_array($subscriptionIds) ? $subscriptionIds : []);

        $subscriptionIds = array_values(array_filter($subscriptionIds, function (int $subId) use ($userId): bool {
            return $this->subscription_belongs_to_user($subId, $userId);
        }));

        $claimableIds = $this->find_claimable_subscription_ids_for_user($userId);
        if (!empty($claimableIds)) {
            $subscriptionIds = array_values(array_unique(array_merge($subscriptionIds, $claimableIds)));
            usort($subscriptionIds, static function (int $left, int $right): int {
                $leftDate = (string) get_post_field('post_date', $left);
                $rightDate = (string) get_post_field('post_date', $right);
                if ($leftDate === $rightDate) {
                    return $right <=> $left;
                }

                return strcmp($rightDate, $leftDate);
            });
        }

        SubscriptionSyncLogger::debug('frontend.get_user_subscription_ids', [
            'user_id' => $userId,
            'subscription_ids' => $subscriptionIds,
        ]);

        return $subscriptionIds;
    }

    private function subscription_belongs_to_user(int $subId, int $userId): bool {
        if ($subId <= 0 || $userId <= 0 || !function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($subId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== $this->get_subscription_order_type()->get_type()) {
            return false;
        }

        $customerId = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
        if ($customerId > 0) {
            return $customerId === $userId;
        }

        $ownerId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
        if ($ownerId > 0) {
            return $ownerId === $userId;
        }

        $fallbackCustomerId = (int) get_post_meta($subId, '_customer_user', true);
        if ($fallbackCustomerId > 0) {
            return $fallbackCustomerId === $userId;
        }

        return false;
    }

    private function find_claimable_subscription_ids_for_user(int $userId): array {
        if ($userId <= 0) {
            return [];
        }

        $user = get_user_by('id', $userId);
        $email = $user && is_object($user) ? strtolower(trim((string) $user->user_email)) : '';
        if ($email === '') {
            return [];
        }

        $posts = function_exists('wc_get_orders')
            ? wc_get_orders([
                'type' => $this->get_subscription_order_type()->get_type(),
                'limit' => 200,
                'return' => 'ids',
                'orderby' => 'date',
                'order' => 'DESC',
                'status' => array_keys(wc_get_order_statuses()),
            ])
            : [];

        $matches = [];
        foreach ((array) $posts as $subId) {
            $subId = (int) $subId;
            if ($subId <= 0) {
                continue;
            }

            $ownerId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
            $customerId = function_exists('wc_get_order') && ($order = wc_get_order($subId)) && is_object($order) && method_exists($order, 'get_customer_id')
                ? (int) $order->get_customer_id()
                : 0;

            if ($customerId === $userId) {
                $matches[] = $subId;
                continue;
            }

            if ($customerId > 0) {
                continue;
            }

            if ($ownerId === $userId) {
                $matches[] = $subId;
                continue;
            }

            if ($ownerId > 0) {
                continue;
            }

            $billing = get_post_meta($subId, self::SUB_META_BILLING, true);
            $billing = is_array($billing) ? $billing : [];
            $billingEmail = strtolower(trim((string) ($billing['email'] ?? '')));
            if ($billingEmail === '' || $billingEmail !== $email) {
                continue;
            }

            update_post_meta($subId, self::SUB_META_USER_ID, (string) $userId);

            if ($order && is_object($order) && method_exists($order, 'set_customer_id') && method_exists($order, 'save')) {
                $order->set_customer_id($userId);
                $order->save();
            } else {
                update_post_meta($subId, '_customer_user', (string) $userId);
            }

            $matches[] = $subId;
        }

        return array_values(array_unique(array_map('intval', $matches)));
    }

    private function get_subscription_scheme_label(string $scheme): string {
        $freqs = $this->get_enabled_frequencies();
        if (isset($freqs[$scheme]) && !empty($freqs[$scheme]['label'])) {
            return (string) $freqs[$scheme]['label'];
        }
        return $scheme !== '' ? $scheme : __('Onbekend', 'hb-ucs');
    }

    private function get_subscription_item_change_key(array $item): string {
        $productId = (int) ($item['base_product_id'] ?? 0);
        $variationId = (int) ($item['base_variation_id'] ?? 0);
        $attributes = $this->get_subscription_item_attribute_snapshot($item);
        ksort($attributes);

        return implode('|', [
            (string) $productId,
            (string) $variationId,
            wp_json_encode($attributes),
        ]);
    }

    private function summarize_subscription_items_for_logging(array $items): array {
        $summary = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $key = $this->get_subscription_item_change_key($item);
            if ($key === '') {
                continue;
            }

            if (!isset($summary[$key])) {
                $summary[$key] = [
                    'label' => $this->get_subscription_item_label($item),
                    'qty' => 0,
                ];
            }

            $summary[$key]['qty'] += max(1, (int) ($item['qty'] ?? 1));
        }

        return $summary;
    }

    private function format_subscription_items_for_logging(array $items): string {
        $parts = [];

        foreach ($items as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $label = trim((string) ($entry['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $parts[] = sprintf('%1$s × %2$d', $label, max(1, (int) ($entry['qty'] ?? 1)));
        }

        return implode(', ', $parts);
    }

    private function build_account_subscription_items_update_note(array $existingItems, array $newItems): string {
        $existingSummary = $this->summarize_subscription_items_for_logging($existingItems);
        $newSummary = $this->summarize_subscription_items_for_logging($newItems);

        $added = [];
        $removed = [];
        $quantityChanges = [];

        foreach ($newSummary as $key => $entry) {
            if (!isset($existingSummary[$key])) {
                $added[$key] = $entry;
                continue;
            }

            $oldQty = max(1, (int) ($existingSummary[$key]['qty'] ?? 1));
            $newQty = max(1, (int) ($entry['qty'] ?? 1));
            if ($oldQty !== $newQty) {
                $quantityChanges[] = sprintf(
                    __('%1$s van %2$d naar %3$d', 'hb-ucs'),
                    (string) ($entry['label'] ?? __('Onbekend product', 'hb-ucs')),
                    $oldQty,
                    $newQty
                );
            }
        }

        foreach ($existingSummary as $key => $entry) {
            if (!isset($newSummary[$key])) {
                $removed[$key] = $entry;
            }
        }

        $changes = [];
        if (!empty($added)) {
            $changes[] = sprintf(__('Toegevoegd: %s.', 'hb-ucs'), $this->format_subscription_items_for_logging($added));
        }
        if (!empty($removed)) {
            $changes[] = sprintf(__('Verwijderd: %s.', 'hb-ucs'), $this->format_subscription_items_for_logging($removed));
        }
        if (!empty($quantityChanges)) {
            $changes[] = sprintf(__('Aantal gewijzigd: %s.', 'hb-ucs'), implode(', ', $quantityChanges));
        }

        if (empty($changes)) {
            return '';
        }

        return sprintf(
            __('Klant heeft abonnementartikelen aangepast via Mijn Account. %s', 'hb-ucs'),
            implode(' ', $changes)
        );
    }

    private function build_account_subscription_schedule_update_note(string $oldScheme, int $oldNextPayment, string $newScheme, int $newNextPayment): string {
        $changes = [];

        if ($oldScheme !== $newScheme) {
            $changes[] = sprintf(
                __('Frequentie: %1$s → %2$s.', 'hb-ucs'),
                $this->get_subscription_scheme_label($oldScheme),
                $this->get_subscription_scheme_label($newScheme)
            );
        }

        $oldDateLabel = $oldNextPayment > 0 ? $this->format_wp_date($oldNextPayment) : __('Onbekend', 'hb-ucs');
        $newDateLabel = $newNextPayment > 0 ? $this->format_wp_date($newNextPayment) : __('Onbekend', 'hb-ucs');
        if ($oldDateLabel !== $newDateLabel) {
            $changes[] = sprintf(
                __('Volgende leverdatum: %1$s → %2$s.', 'hb-ucs'),
                $oldDateLabel,
                $newDateLabel
            );
        }

        if (empty($changes)) {
            return '';
        }

        return sprintf(
            __('Klant heeft de planning aangepast via Mijn Account. %s', 'hb-ucs'),
            implode(' ', $changes)
        );
    }

    private function get_manageable_subscription_product_options(string $scheme = ''): array {
        if (!function_exists('wc_get_products')) {
            return [
                'scheme' => $scheme,
                'items' => [],
                'lookup' => [],
                'categories' => [],
                'menu_filters' => [],
                'variable_configs' => [],
                'variation_attribute_configs' => [],
                'variation_lookup' => [],
            ];
        }

        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
            'type' => ['simple', 'variable'],
        ]);

        if (!is_array($products)) {
            $products = [];
        }

        $options = [
            'scheme' => $scheme,
            'items' => [],
            'lookup' => [],
            'categories' => [],
            'menu_filters' => [],
            'variable_configs' => [],
            'variation_attribute_configs' => [],
            'variation_lookup' => [],
        ];

        foreach ($products as $product) {
            if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
                continue;
            }

            $productId = (int) $product->get_id();
            if (get_post_meta($productId, self::META_ENABLED, true) !== 'yes') {
                continue;
            }

            $categoryTerms = get_the_terms($productId, 'product_cat');
            $categoryIds = [];
            $categoryNames = [];
            if (is_array($categoryTerms)) {
                foreach ($categoryTerms as $term) {
                    if (!$term || !isset($term->term_id, $term->name)) {
                        continue;
                    }
                    $termId = (int) $term->term_id;
                    if ($termId <= 0) {
                        continue;
                    }
                    $categoryIds[] = $termId;
                    $categoryNames[] = (string) $term->name;
                    $options['categories'][$termId] = (string) $term->name;
                }
            }

            $imageHtml = '';
            if (method_exists($product, 'get_image')) {
                $imageHtml = (string) $product->get_image('woocommerce_thumbnail', ['class' => 'hb-ucs-subscription-item-card__image-el']);
            }
            if ($imageHtml === '' && function_exists('wc_placeholder_img')) {
                $imageHtml = (string) wc_placeholder_img('woocommerce_thumbnail', ['class' => 'hb-ucs-subscription-item-card__image-el']);
            }
            $pricing = $scheme !== '' ? $this->get_product_page_subscription_pricing($productId, $product, $scheme) : null;
            $priceHtml = $pricing
                ? (string) html_entity_decode(wp_strip_all_tags($this->format_subscription_price_html((float) ($pricing['base'] ?? 0.0), (float) ($pricing['final'] ?? 0.0), (string) ($pricing['badge'] ?? '')), true), ENT_QUOTES, 'UTF-8')
                : (method_exists($product, 'get_price_html') ? (string) wp_strip_all_tags($product->get_price_html(), true) : '');

            if (method_exists($product, 'is_type') && $product->is_type('variable') && method_exists($product, 'get_children')) {
                $variableConfig = $this->get_variable_product_attribute_config($product, true);
                if (!empty($variableConfig)) {
                    $options['variable_configs'][$productId] = $variableConfig;
                }
                $variationAttributeConfig = $this->get_variable_product_attribute_config($product, true);
                if (!empty($variationAttributeConfig)) {
                    $options['variation_attribute_configs'][$productId] = $variationAttributeConfig;
                }
                $options['variation_lookup'][$productId] = $this->get_variable_product_variation_lookup($product, $scheme);
                $entry = [
                    'id' => $productId,
                    'target_id' => $productId,
                    'label' => wp_strip_all_tags((string) $product->get_name() . ' — ' . __('kies opties', 'hb-ucs'), true),
                    'summary' => __('Kies variaties na toevoegen in de productrij.', 'hb-ucs'),
                    'type' => 'variable_parent',
                    'categories' => $categoryIds,
                    'category_names' => $categoryNames,
                    'selected_attributes' => [],
                    'image_html' => $imageHtml,
                    'price_html' => $priceHtml,
                ];
                $options['lookup'][$productId] = $entry;
                $options['items'][] = $entry;

                continue;
            }

            $label = wp_strip_all_tags((string) $product->get_name(), true);
            $entry = [
                'id' => $productId,
                'label' => $label !== '' ? $label : ('#' . $productId),
                'summary' => '',
                'type' => 'simple',
                'categories' => $categoryIds,
                'category_names' => $categoryNames,
                'image_html' => $imageHtml,
                'price_html' => $priceHtml,
            ];
            $options['items'][] = $entry;
            $options['lookup'][$productId] = $entry;
        }

        asort($options['categories'], SORT_NATURAL | SORT_FLAG_CASE);
        $options['menu_filters'] = $this->get_manageable_product_picker_filters($options['categories']);
        usort($options['items'], static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $options;
    }

    private function filter_manageable_product_picker_items(array $items, string $searchTerm = '', array $categoryIds = []): array {
        $normalizedSearchTerm = trim($searchTerm);
        $normalizedSearchTerm = function_exists('mb_strtolower') ? mb_strtolower($normalizedSearchTerm, 'UTF-8') : strtolower($normalizedSearchTerm);
        $searchNeedle = function_exists('remove_accents') ? remove_accents($normalizedSearchTerm) : $normalizedSearchTerm;
        $categoryIds = array_values(array_filter(array_map('intval', $categoryIds)));

        return array_values(array_filter($items, static function (array $item) use ($searchNeedle, $categoryIds): bool {
            $itemCategoryIds = isset($item['categories']) && is_array($item['categories'])
                ? array_values(array_filter(array_map('intval', $item['categories'])))
                : [];

            if (!empty($categoryIds) && empty(array_intersect($categoryIds, $itemCategoryIds))) {
                return false;
            }

            if ($searchNeedle === '') {
                return true;
            }

            $haystackParts = [
                (string) ($item['label'] ?? ''),
                (string) ($item['summary'] ?? ''),
                implode(' ', array_map('strval', isset($item['category_names']) && is_array($item['category_names']) ? $item['category_names'] : [])),
            ];

            $haystack = implode(' ', $haystackParts);
            $haystack = function_exists('mb_strtolower') ? mb_strtolower($haystack, 'UTF-8') : strtolower($haystack);
            $haystack = function_exists('remove_accents') ? remove_accents($haystack) : $haystack;

            return strpos($haystack, $searchNeedle) !== false;
        }));
    }

    private function render_manageable_product_picker_items_html(array $items, bool $disabled): string {
        ob_start();

        if (empty($items)) {
            echo '<p class="hb-ucs-product-modal__empty">' . esc_html__('Er zijn geen geschikte producten beschikbaar.', 'hb-ucs') . '</p>';
            return (string) ob_get_clean();
        }

        foreach ($items as $item) {
            $itemId = (int) ($item['id'] ?? 0);
            $itemLabel = (string) ($item['label'] ?? '');
            $categoryIds = isset($item['categories']) && is_array($item['categories']) ? implode(',', array_map('intval', $item['categories'])) : '';
            $categoryNames = isset($item['category_names']) && is_array($item['category_names']) ? implode(', ', array_map('strval', $item['category_names'])) : '';
            $summary = isset($item['summary']) ? (string) $item['summary'] : '';
            $searchText = strtolower(trim($itemLabel . ' ' . $categoryNames . ' ' . $summary));
            $targetId = (int) ($item['target_id'] ?? $itemId);
            $selectedAttributes = isset($item['attribute_snapshot']) && is_array($item['attribute_snapshot'])
                ? $item['attribute_snapshot']
                : (isset($item['selected_attributes']) && is_array($item['selected_attributes']) ? $item['selected_attributes'] : []);
            $imageHtml = isset($item['image_html']) ? (string) $item['image_html'] : '';
            $priceHtml = isset($item['price_html']) ? (string) $item['price_html'] : '';
            $itemContentHtml = $this->render_manageable_product_picker_item_content_html($item, $imageHtml, $priceHtml, $summary, $categoryNames);
            $itemClass = 'hb-ucs-product-modal__item';
            if ($this->should_use_manageable_product_picker_loop_template()) {
                $itemClass .= ' hb-ucs-product-modal__item--templated';
            }

            echo '<div class="' . esc_attr($itemClass) . '" role="button" tabindex="' . esc_attr($disabled ? '-1' : '0') . '" aria-disabled="' . esc_attr($disabled ? 'true' : 'false') . '" data-product-id="' . esc_attr((string) $itemId) . '" data-target-product-id="' . esc_attr((string) $targetId) . '" data-product-label="' . esc_attr($itemLabel) . '" data-product-summary="' . esc_attr($summary) . '" data-product-price="' . esc_attr($priceHtml) . '" data-product-image="' . esc_attr(base64_encode($imageHtml)) . '" data-product-categories="' . esc_attr($categoryIds) . '" data-product-search="' . esc_attr($searchText) . '" data-selected-attributes="' . esc_attr(wp_json_encode($selectedAttributes)) . '">';
            echo $itemContentHtml;
            echo '</div>';
        }

        return (string) ob_get_clean();
    }

    private function render_manageable_product_picker_item_content_html(array $item, string $imageHtml, string $priceHtml, string $summary, string $categoryNames): string {
        $templateHtml = $this->render_manageable_product_picker_loop_template_html($item);
        if ($templateHtml !== '') {
            return '<span class="hb-ucs-product-modal__item-template">' . $templateHtml . '</span>';
        }

        ob_start();
        echo '<span class="hb-ucs-product-modal__item-media">' . wp_kses_post($imageHtml) . '</span>';
        echo '<span class="hb-ucs-product-modal__item-body">';
        echo '<span class="hb-ucs-product-modal__item-copy">';
        echo '<strong class="hb-ucs-product-modal__item-title">' . esc_html((string) ($item['label'] ?? '')) . '</strong>';
        if ($priceHtml !== '') {
            echo '<span class="hb-ucs-product-modal__item-price">' . esc_html($priceHtml) . '</span>';
        }
        if ($summary !== '') {
            echo '<span class="hb-ucs-product-modal__item-summary">' . esc_html($summary) . '</span>';
        }
        if ($categoryNames !== '') {
            echo '<span class="hb-ucs-product-modal__item-categories">' . esc_html($categoryNames) . '</span>';
        }
        echo '</span>';
        echo '<span class="hb-ucs-product-modal__item-action">' . esc_html__('Selecteer', 'hb-ucs') . '</span>';
        echo '</span>';

        return (string) ob_get_clean();
    }

    private function should_use_manageable_product_picker_loop_template(): bool {
        return $this->get_manageable_product_picker_loop_template_id() > 0;
    }

    private function get_manageable_product_picker_loop_template_id(): int {
        $settings = $this->get_settings();
        return isset($settings['product_picker_loop_template_id']) ? max(0, (int) $settings['product_picker_loop_template_id']) : 0;
    }

    private function render_manageable_product_picker_loop_template_html(array $item): string {
        $templateId = $this->get_manageable_product_picker_loop_template_id();
        if ($templateId <= 0 || !class_exists('Elementor\\Plugin')) {
            return '';
        }

        $productId = (int) ($item['target_id'] ?? $item['id'] ?? 0);
        if ($productId <= 0) {
            return '';
        }

        $productPost = get_post($productId);
        if (!$productPost || !($productPost instanceof \WP_Post)) {
            return '';
        }

        $frontend = \Elementor\Plugin::instance()->frontend ?? null;
        if (!$frontend || !method_exists($frontend, 'get_builder_content_for_display')) {
            return '';
        }

        global $post, $product;

        $previousPost = $post ?? null;
        $previousProduct = $product ?? null;
        $rendered = '';

        $post = $productPost;
        setup_postdata($productPost);
        $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;

        try {
            $rendered = (string) $frontend->get_builder_content_for_display($templateId, true);
        } catch (\Throwable $exception) {
            $rendered = '';
        }

        if ($previousPost instanceof \WP_Post) {
            $post = $previousPost;
            setup_postdata($previousPost);
        } else {
            unset($GLOBALS['post']);
        }

        if ($previousProduct) {
            $product = $previousProduct;
        } else {
            unset($GLOBALS['product']);
        }

        return trim($rendered);
    }

    private function get_manageable_product_picker_filters(array $availableCategories): array {
        $menuFilters = $this->get_manageable_product_picker_menu_filters($availableCategories);
        if (!empty($menuFilters)) {
            return $menuFilters;
        }

        $filters = [[
            'key' => 'all',
            'label' => __('Alle producten', 'hb-ucs'),
            'category_ids' => [],
            'default' => true,
        ]];

        foreach ($availableCategories as $termId => $termName) {
            $termId = (int) $termId;
            if ($termId <= 0) {
                continue;
            }

            $filters[] = [
                'key' => 'term-' . $termId,
                'label' => (string) $termName,
                'category_ids' => [$termId],
                'default' => false,
            ];
        }

        return $filters;
    }

    private function get_manageable_product_picker_menu_filters(array $availableCategories): array {
        if (empty($availableCategories) || !function_exists('get_nav_menu_locations') || !function_exists('wp_get_nav_menu_items')) {
            return [];
        }

        $locations = get_nav_menu_locations();
        $menuId = isset($locations[self::PRODUCT_PICKER_MENU_LOCATION]) ? (int) $locations[self::PRODUCT_PICKER_MENU_LOCATION] : 0;
        if ($menuId <= 0) {
            return [];
        }

        $menuItems = wp_get_nav_menu_items($menuId, [
            'update_post_term_cache' => false,
        ]);
        if (!is_array($menuItems) || empty($menuItems)) {
            return [];
        }

        $availableCategoryIds = array_values(array_filter(array_map('intval', array_keys($availableCategories))));
        $filters = [[
            'key' => 'all',
            'label' => __('Alle producten', 'hb-ucs'),
            'category_ids' => [],
            'default' => true,
        ]];

        foreach ($menuItems as $menuItem) {
            if (!is_object($menuItem) || (int) ($menuItem->menu_item_parent ?? 0) !== 0) {
                continue;
            }

            $label = trim((string) ($menuItem->title ?? ''));
            if ($label === '') {
                continue;
            }

            $classes = isset($menuItem->classes) && is_array($menuItem->classes)
                ? array_map('sanitize_html_class', array_filter(array_map('strval', $menuItem->classes)))
                : [];
            $isAllFilter = in_array('hb-ucs-filter-all', $classes, true);

            if ((string) ($menuItem->type ?? '') === 'custom' && $isAllFilter) {
                $filters[] = [
                    'key' => 'menu-item-' . (int) ($menuItem->ID ?? 0),
                    'label' => $label,
                    'category_ids' => [],
                    'default' => false,
                ];
                continue;
            }

            if ((string) ($menuItem->object ?? '') !== 'product_cat') {
                continue;
            }

            $termId = (int) ($menuItem->object_id ?? 0);
            if ($termId <= 0) {
                continue;
            }

            $categoryIds = [$termId];
            $descendants = get_term_children($termId, 'product_cat');
            if (!is_wp_error($descendants) && is_array($descendants)) {
                foreach ($descendants as $descendantId) {
                    $descendantId = (int) $descendantId;
                    if ($descendantId > 0) {
                        $categoryIds[] = $descendantId;
                    }
                }
            }

            $categoryIds = array_values(array_unique(array_intersect($categoryIds, $availableCategoryIds)));
            if (empty($categoryIds)) {
                continue;
            }

            $filters[] = [
                'key' => 'term-' . $termId,
                'label' => $label,
                'category_ids' => $categoryIds,
                'default' => false,
            ];
        }

        return count($filters) > 1 ? $filters : [];
    }

    private function render_manageable_product_select(string $name, int $selectedId, bool $disabled, array $productOptions, string $placeholder = '', string $selectedLabel = '', string $attributesName = '', array $selectedAttributes = []): void {
        $fieldId = 'hb_ucs_product_picker_' . trim((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $name), '_');
        $emptyLabel = $placeholder !== '' ? $placeholder : __('Nog geen product gekozen', 'hb-ucs');
        $baseLabel = $selectedId > 0 ? $this->get_manageable_product_base_label($selectedId, $productOptions) : $emptyLabel;
        $displayLabel = $baseLabel;

        if ($selectedId > 0) {
            $displayLabel = $this->get_manageable_product_picker_label($selectedId, $selectedAttributes, $productOptions, $selectedLabel);
        } elseif ($selectedLabel !== '') {
            $displayLabel = $selectedLabel;
        }

        echo '<div class="hb-ucs-product-picker-field">';
        echo '<input type="hidden" name="' . esc_attr($name) . '" id="' . esc_attr($fieldId) . '" class="hb-ucs-product-picker-value" value="' . esc_attr((string) $selectedId) . '" />';
        echo '<div class="hb-ucs-product-picker-summary" hidden aria-hidden="true">';
        echo '<span class="hb-ucs-product-picker-label" data-empty-label="' . esc_attr($emptyLabel) . '" data-base-label="' . esc_attr($selectedId > 0 ? $baseLabel : $emptyLabel) . '">' . esc_html($displayLabel) . '</span>';
        echo '</div>';
        if ($attributesName !== '') {
            echo '<div class="hb-ucs-product-picker-attributes" data-attributes-name="' . esc_attr($attributesName) . '" hidden aria-hidden="true">';
            $this->render_manageable_product_attribute_fields($selectedId, $selectedAttributes, $attributesName, $disabled, $productOptions);
            echo '</div>';
        }
        echo '</div>';
    }

    private function get_manageable_product_base_label(int $selectedId, array $productOptions): string {
        if ($selectedId <= 0) {
            return '';
        }

        $lookup = isset($productOptions['lookup']) && is_array($productOptions['lookup']) ? $productOptions['lookup'] : [];
        if (isset($lookup[$selectedId]['label']) && (string) $lookup[$selectedId]['label'] !== '') {
            return (string) $lookup[$selectedId]['label'];
        }

        $product = wc_get_product($selectedId);
        if ($product && is_object($product) && method_exists($product, 'get_name')) {
            return wp_strip_all_tags((string) $product->get_name(), true);
        }

        return '#'. $selectedId;
    }

    private function format_manageable_product_selected_attributes(int $selectedId, array $selectedAttributes): string {
        if ($selectedId <= 0 || empty($selectedAttributes)) {
            return '';
        }

        $product = wc_get_product($selectedId);
        if (!$product || !is_object($product)) {
            return '';
        }

        $config = $this->get_variable_product_attribute_config($product, true);
        if (empty($config)) {
            return '';
        }

        $parts = [];
        foreach ($config as $attribute) {
            $key = (string) ($attribute['key'] ?? '');
            if ($key === '' || empty($selectedAttributes[$key])) {
                continue;
            }

            $label = (string) ($attribute['label'] ?? $key);
            $value = (string) $selectedAttributes[$key];
            foreach ((array) ($attribute['options'] ?? []) as $option) {
                if ((string) ($option['value'] ?? '') === $value) {
                    $value = (string) ($option['label'] ?? $value);
                    break;
                }
            }

            if ($value !== '') {
                $parts[] = sprintf('%s: %s', $label, $value);
            }
        }

        return implode(' | ', $parts);
    }

    private function get_manageable_product_picker_label(int $selectedId, array $selectedAttributes, array $productOptions, string $selectedLabel = ''): string {
        $baseLabel = $this->get_manageable_product_base_label($selectedId, $productOptions);
        $summary = $this->format_manageable_product_selected_attributes($selectedId, $selectedAttributes);

        if ($summary !== '') {
            return $baseLabel . ' — ' . $summary;
        }

        return $selectedLabel !== '' ? $selectedLabel : $baseLabel;
    }

    private function get_subscription_action_icon_markup(string $icon): string {
        if ($icon === 'trash') {
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M8 6V4.8c0-.66.54-1.2 1.2-1.2h5.6c.66 0 1.2.54 1.2 1.2V6"/><path d="M6.2 6l.9 12.02c.05.73.66 1.29 1.39 1.29h7.02c.73 0 1.34-.56 1.39-1.29L17.8 6"/><path d="M10 10.2v5.6"/><path d="M14 10.2v5.6"/></svg>';
        }

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 1 1 3 3L7 19l-4 1 1-4 12.5-12.5Z"/></svg>';
    }

    private function render_account_subscription_item_editor_row(string $rowKey, array $item, bool $manageDisabled, array $productOptions, int $subId = 0): void {
        $selectedId = (int) ($item['base_product_id'] ?? 0);
        $selectedAttributes = $this->get_subscription_item_attribute_snapshot($item);
        $variationSummary = $this->get_subscription_item_variation_summary($item);
        $qty = max(1, (int) ($item['qty'] ?? 1));
        $deliveryPrice = $this->get_subscription_item_display_amount(
            $item,
            $qty,
            $this->should_display_account_subscription_prices_including_tax(),
            $subId > 0 ? $this->get_subscription_tax_customer($subId) : null
        );
        $deliveryPriceHtml = $deliveryPrice > 0 ? (function_exists('wc_price') ? wc_price($deliveryPrice) : number_format($deliveryPrice, 2, '.', '')) : '';
        $itemLabel = isset($item['display_label']) && (string) $item['display_label'] !== '' ? (string) $item['display_label'] : ($selectedId > 0 ? $this->get_subscription_item_label($item) : __('Nieuw product', 'hb-ucs'));
        $productFieldName = 'items[' . $rowKey . '][product_id]';
        $productFieldId = 'hb_ucs_product_picker_' . trim((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $productFieldName), '_');
        $editLabel = $selectedId > 0 ? __('Product wijzigen', 'hb-ucs') : __('Product kiezen', 'hb-ucs');
        $removeFieldName = 'items[' . $rowKey . '][_hb_ucs_remove]';
        $trashIcon = $this->get_subscription_action_icon_markup('trash');
        $pencilIcon = $this->get_subscription_action_icon_markup('pencil');

        echo '<article class="hb-ucs-subscription-item-card hb-ucs-subscription-item-card--compact hb-ucs-subscription-item-card--dashboard woocommerce-MyAccount-content-wrapper woocommerce-EditAccountForm" data-hb-ucs-item-row="1">';
        echo '<input type="hidden" class="hb-ucs-remove-input" name="' . esc_attr($removeFieldName) . '" value="0" />';
        echo '<div class="hb-ucs-subscription-item-card__media">' . wp_kses_post($this->get_account_subscription_item_image_html($item)) . '</div>';
        echo '<div class="hb-ucs-subscription-item-card__content">';
        echo '<div class="hb-ucs-subscription-item-card__top hb-ucs-subscription-item-card__top--compact">';
        echo '<div class="hb-ucs-subscription-item-card__heading">';
        echo '<h4>' . esc_html($itemLabel) . '</h4>';
        if ($deliveryPriceHtml !== '') {
            echo '<p class="hb-ucs-product-card__price">' . wp_kses_post($deliveryPriceHtml) . ' <span>' . esc_html__('per levering', 'hb-ucs') . '</span></p>';
        }
        echo '<p class="hb-ucs-product-card__variation-summary">' . esc_html($variationSummary) . '</p>';
        echo '</div>';
        echo '<div class="hb-ucs-product-card__inline-actions">';
        echo '<div class="hb-ucs-product-card__qty">';
        echo '<span class="hb-ucs-product-card__meta-label">' . esc_html__('Aantal', 'hb-ucs') . '</span>';
        echo '<div class="quantity hb-ucs-quantity-field hb-ucs-quantity-field--compact hb-ucs-quantity-field--stepper">';
        echo '<button type="button" class="hb-ucs-qty-stepper__button minus" data-hb-ucs-qty-step="-1" ' . disabled($manageDisabled, true, false) . '>&minus;</button>';
        echo '<input type="number" class="input-text qty text" min="1" step="1" inputmode="numeric" name="items[' . esc_attr($rowKey) . '][qty]" value="' . esc_attr((string) $qty) . '" ' . disabled($manageDisabled, true, false) . ' />';
        echo '<button type="button" class="hb-ucs-qty-stepper__button plus" data-hb-ucs-qty-step="1" ' . disabled($manageDisabled, true, false) . '>+</button>';
        echo '</div>';
        echo '</div>';
        echo '<button type="button" class="hb-ucs-product-card__edit hb-ucs-product-card__icon-action hb-ucs-product-card__icon-action--edit hb-ucs-open-product-modal" data-picker-input="' . esc_attr($productFieldId) . '" data-picker-title="' . esc_attr__('Kies een product', 'hb-ucs') . '" aria-label="' . esc_attr($editLabel) . '" title="' . esc_attr($editLabel) . '" ' . disabled($manageDisabled, true, false) . '>' . $pencilIcon . '<span class="screen-reader-text">' . esc_html($editLabel) . '</span></button>';
        echo '<button type="button" class="hb-ucs-product-card__trash hb-ucs-product-card__icon-action hb-ucs-product-card__icon-action--remove" data-hb-ucs-remove-toggle="' . esc_attr($removeFieldName) . '" aria-label="' . esc_attr__('Product verwijderen', 'hb-ucs') . '" title="' . esc_attr__('Product verwijderen', 'hb-ucs') . '" aria-pressed="false" ' . disabled($manageDisabled, true, false) . '>' . $trashIcon . '<span class="screen-reader-text">' . esc_html__('Product verwijderen', 'hb-ucs') . '</span></button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="hb-ucs-product-card__editor" hidden aria-hidden="true">';
        echo '<p class="hb-ucs-subscription-item-card__editor-help">' . esc_html__('Kies eerst een hoofdartikel via het potlood en selecteer daarna hier de gewenste productopties.', 'hb-ucs') . '</p>';
        echo '<div class="hb-ucs-subscription-item-card__body hb-ucs-subscription-item-card__body--visible">';
        echo '<div class="hb-ucs-subscription-item-card__picker hb-ucs-subscription-item-card__picker--editor">';
        $this->render_manageable_product_select(
            $productFieldName,
            $selectedId,
            $manageDisabled,
            $productOptions,
            __('Zoek product of variatie…', 'hb-ucs'),
            $selectedId > 0 ? $this->get_subscription_item_label($item) : '',
            'items[' . $rowKey . '][selected_attributes]',
            $selectedAttributes
        );
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</article>';
    }

    private function render_manageable_product_attribute_fields(int $productId, array $selectedAttributes, string $attributesName, bool $disabled, array $productOptions): void {
        $configs = isset($productOptions['variable_configs']) && is_array($productOptions['variable_configs']) ? $productOptions['variable_configs'] : [];
        $config = isset($configs[$productId]) && is_array($configs[$productId]) ? $configs[$productId] : [];
        if (empty($config)) {
            return;
        }

        echo '<div class="hb-ucs-product-picker-attribute-grid">';
        foreach ($config as $attribute) {
            $key = (string) ($attribute['key'] ?? '');
            $label = (string) ($attribute['label'] ?? $key);
            $options = isset($attribute['options']) && is_array($attribute['options']) ? $attribute['options'] : [];
            if ($key === '' || empty($options)) {
                continue;
            }

            $selectedValue = isset($selectedAttributes[$key]) ? (string) $selectedAttributes[$key] : '';
            echo '<p class="form-row form-row-wide">';
            echo '<label>' . esc_html($label) . '</label>';
            echo '<select class="input-text" name="' . esc_attr($attributesName . '[' . $key . ']') . '" ' . disabled($disabled, true, false) . '>';
            echo '<option value="">' . esc_html__('Kies een optie…', 'hb-ucs') . '</option>';
            foreach ($options as $option) {
                $value = (string) ($option['value'] ?? '');
                $optionLabel = (string) ($option['label'] ?? $value);
                echo '<option value="' . esc_attr($value) . '" ' . selected($selectedValue, $value, false) . '>' . esc_html($optionLabel) . '</option>';
            }
            echo '</select>';
            echo '</p>';
        }
        echo '</div>';
    }

    private function render_manageable_product_picker_modal(array $productOptions, bool $disabled): void {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        $rendered = true;

        $menuFilters = isset($productOptions['menu_filters']) && is_array($productOptions['menu_filters']) ? $productOptions['menu_filters'] : [];
        $items = isset($productOptions['items']) && is_array($productOptions['items']) ? $productOptions['items'] : [];
        $variableConfigs = isset($productOptions['variable_configs']) && is_array($productOptions['variable_configs']) ? $productOptions['variable_configs'] : [];
        $variationAttributeConfigs = isset($productOptions['variation_attribute_configs']) && is_array($productOptions['variation_attribute_configs']) ? $productOptions['variation_attribute_configs'] : [];

        echo '<script type="application/json" id="hb-ucs-product-picker-config">' . wp_json_encode([
            'scheme' => (string) ($productOptions['scheme'] ?? ''),
            'variableConfigs' => $variableConfigs,
            'variationAttributeConfigs' => $variationAttributeConfigs,
            'variationLookup' => isset($productOptions['variation_lookup']) && is_array($productOptions['variation_lookup']) ? $productOptions['variation_lookup'] : [],
            'chooseOptionLabel' => __('Kies een optie…', 'hb-ucs'),
            'noResultsLabel' => __('Geen producten gevonden voor deze filters.', 'hb-ucs'),
            'loadingLabel' => __('Producten laden…', 'hb-ucs'),
        ]) . '</script>';

        echo '<div class="hb-ucs-product-modal" id="hb-ucs-product-modal" hidden aria-hidden="true">';
        echo '<div class="hb-ucs-product-modal__backdrop" data-close-product-modal="1"></div>';
        echo '<div class="hb-ucs-product-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="hb-ucs-product-modal-title">';
        echo '<div class="hb-ucs-product-modal__header">';
        echo '<div class="hb-ucs-product-modal__heading">';
        echo '<h3 id="hb-ucs-product-modal-title">' . esc_html__('Kies een product', 'hb-ucs') . '</h3>';
        echo '<p>' . esc_html__('Zoek een product, filter op de categorieën uit je WordPress-menu en kies direct het juiste artikel voor deze levering.', 'hb-ucs') . '</p>';
        echo '</div>';
        echo '<button type="button" class="hb-ucs-product-modal__close" data-close-product-modal="1" aria-label="' . esc_attr__('Sluiten', 'hb-ucs') . '">×</button>';
        echo '</div>';
        echo '<div class="hb-ucs-product-modal__toolbar">';
        echo '<label class="hb-ucs-product-modal__search-field">';
        echo '<span class="hb-ucs-product-modal__search-icon" aria-hidden="true">⌕</span>';
        echo '<input type="search" class="hb-ucs-product-modal__search" placeholder="' . esc_attr__('Zoek op productnaam, variatie of categorie…', 'hb-ucs') . '" />';
        echo '</label>';
        echo '</div>';
        if (!empty($menuFilters)) {
            echo '<div class="hb-ucs-product-modal__menu" role="tablist" aria-label="' . esc_attr__('Productcategorieën', 'hb-ucs') . '">';
            foreach ($menuFilters as $index => $filter) {
                $filterKey = (string) ($filter['key'] ?? ('filter-' . $index));
                $label = (string) ($filter['label'] ?? '');
                $filterCategoryIds = isset($filter['category_ids']) && is_array($filter['category_ids']) ? implode(',', array_map('intval', $filter['category_ids'])) : '';
                $isDefault = !empty($filter['default']) || $index === 0;
                if ($label === '') {
                    continue;
                }

                echo '<button type="button" class="hb-ucs-product-modal__menu-button' . ($isDefault ? ' is-active' : '') . '" data-filter-key="' . esc_attr($filterKey) . '" data-filter-categories="' . esc_attr($filterCategoryIds) . '" data-filter-default="' . ($isDefault ? '1' : '0') . '" aria-pressed="' . ($isDefault ? 'true' : 'false') . '">' . esc_html($label) . '</button>';
            }
            echo '</div>';
        }
        echo '<div class="hb-ucs-product-modal__results">';
        echo $this->render_manageable_product_picker_items_html($items, $disabled);
        echo '<p class="hb-ucs-product-modal__no-results" hidden>' . esc_html__('Geen producten gevonden voor deze filters.', 'hb-ucs') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public function handle_subscription_product_picker_search_ajax(): void {
        check_ajax_referer('hb_ucs_subscription_frontend', 'nonce');

        $scheme = isset($_REQUEST['scheme']) ? sanitize_key((string) wp_unslash($_REQUEST['scheme'])) : '';
        $searchTerm = isset($_REQUEST['term']) ? sanitize_text_field((string) wp_unslash($_REQUEST['term'])) : '';
        $rawCategoryIds = isset($_REQUEST['category_ids']) ? (array) wp_unslash($_REQUEST['category_ids']) : [];
        if (count($rawCategoryIds) === 1 && is_string($rawCategoryIds[0]) && strpos($rawCategoryIds[0], ',') !== false) {
            $rawCategoryIds = explode(',', $rawCategoryIds[0]);
        }

        $categoryIds = array_values(array_filter(array_map('intval', $rawCategoryIds)));
        $options = $this->get_manageable_subscription_product_options($scheme);
        $items = $this->filter_manageable_product_picker_items((array) ($options['items'] ?? []), $searchTerm, $categoryIds);

        wp_send_json_success([
            'html' => $this->render_manageable_product_picker_items_html($items, false),
            'count' => count($items),
        ]);
    }

    private function get_variation_option_label($variation, int $fallbackId = 0): string {
        if ($variation instanceof \WC_Product_Variation) {
            $parts = [];
            $parentId = (int) $variation->get_parent_id();
            $parent = $parentId > 0 ? wc_get_product($parentId) : null;
            $variationAttributes = (array) $variation->get_variation_attributes();

            if ($parent && is_object($parent) && method_exists($parent, 'get_attributes')) {
                foreach ((array) $parent->get_attributes() as $attributeObject) {
                    if (!is_object($attributeObject) || !method_exists($attributeObject, 'get_name')) {
                        continue;
                    }

                    $attributeName = (string) $attributeObject->get_name();
                    if ($attributeName === '') {
                        continue;
                    }

                    $attributeKey = 'attribute_' . sanitize_title($attributeName);
                    if ($attributeKey === 'attribute_') {
                        continue;
                    }

                    $attributeValue = isset($variationAttributes[$attributeKey]) ? (string) $variationAttributes[$attributeKey] : '';
                    if ($attributeValue === '' && method_exists($variation, 'get_attribute')) {
                        $attributeValue = (string) $variation->get_attribute($attributeName);
                    }
                    if ($attributeValue === '') {
                        continue;
                    }

                    $label = wc_attribute_label($attributeName, $parent);
                    if (taxonomy_exists($attributeName)) {
                        $term = get_term_by('slug', $attributeValue, $attributeName);
                        if ($term && !is_wp_error($term)) {
                            $attributeValue = (string) $term->name;
                        }
                    } elseif (method_exists($attributeObject, 'get_options')) {
                        foreach ((array) $attributeObject->get_options() as $optionValue) {
                            $optionValue = (string) $optionValue;
                            if ($optionValue === '') {
                                continue;
                            }
                            if ($optionValue === $attributeValue || sanitize_title($optionValue) === $attributeValue) {
                                $attributeValue = $optionValue;
                                break;
                            }
                        }
                    }

                    $parts[] = sprintf('%s: %s', $label !== '' ? $label : $attributeName, $attributeValue);
                }
            }

            if (!empty($parts)) {
                return wp_strip_all_tags(implode(' | ', $parts), true);
            }
        }

        return $fallbackId > 0 ? ('#' . $fallbackId) : '';
    }

    private function normalize_selected_attribute_key(string $key): string {
        $key = ltrim(sanitize_key($key), '_');

        if ($key === '' || $key === 'attribute_') {
            return '';
        }

        if (strpos($key, 'attribute_') === 0) {
            return $key;
        }

        return 'attribute_' . $key;
    }

    private function get_selected_attribute_key_aliases(string $key): array {
        $normalizedKey = $this->normalize_selected_attribute_key($key);
        if ($normalizedKey === '' || $normalizedKey === 'attribute_') {
            return [];
        }

        $aliases = [$normalizedKey];
        $metaKey = str_replace('attribute_', '', $normalizedKey);
        if ($metaKey !== '') {
            $aliases[] = $metaKey;

            if (strpos($metaKey, 'pa_') === 0) {
                $suffix = substr($metaKey, 3);
                if ($suffix !== '') {
                    $aliases[] = 'attribute_' . $suffix;
                    $aliases[] = $suffix;
                }
            } else {
                $aliases[] = 'attribute_pa_' . $metaKey;
                $aliases[] = 'pa_' . $metaKey;
            }
        }

        return array_values(array_unique(array_filter(array_map('sanitize_key', $aliases))));
    }

    private function get_attribute_config_lookup(array $config): array {
        $lookup = [];

        foreach ($config as $attribute) {
            if (!is_array($attribute)) {
                continue;
            }

            $canonicalKey = (string) ($attribute['key'] ?? '');
            if ($canonicalKey === '' || $canonicalKey === 'attribute_') {
                continue;
            }

            foreach ($this->get_selected_attribute_key_aliases($canonicalKey) as $aliasKey) {
                if ($aliasKey === '') {
                    continue;
                }

                $lookup[$aliasKey] = $attribute;
            }
        }

        return $lookup;
    }

    private function has_selected_attribute_value(array $selectedAttributes, string $key): bool {
        foreach ($this->get_selected_attribute_key_aliases($key) as $aliasKey) {
            if (!empty($selectedAttributes[$aliasKey])) {
                return true;
            }
        }

        return false;
    }

    private function is_subscription_selected_attribute_meta_key(string $metaKey): bool {
        $normalizedKey = ltrim(sanitize_key($metaKey), '_');

        if ($normalizedKey === '') {
            return false;
        }

        return strpos($normalizedKey, 'attribute_') === 0 || strpos($normalizedKey, 'pa_') === 0;
    }

    private function sanitize_selected_attributes_map(array $attributes): array {
        $clean = [];

        foreach ($attributes as $key => $value) {
            $key = $this->normalize_selected_attribute_key((string) $key);
            $value = sanitize_title((string) $value);

            if ($key === '' || $key === 'attribute_' || $value === '') {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function normalize_subscription_item_display_label(string $label): string {
        $label = trim(wp_strip_all_tags($label, true));
        if ($label === '') {
            return '';
        }

        $normalizedLabel = ltrim(sanitize_key($label), '_');
        if (strpos($normalizedLabel, 'attribute_') === 0 || strpos($normalizedLabel, 'pa_') === 0) {
            $label = $this->get_order_item_display_meta_label($normalizedLabel);
        }

        return trim(wp_strip_all_tags($label, true));
    }

    private function get_subscription_item_display_row_hash(string $label, string $value): string {
        return strtolower(remove_accents($this->normalize_subscription_item_display_label($label) . '|' . trim($value)));
    }

    private function sanitize_subscription_item_display_meta(array $rows): array {
        $clean = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = isset($row['label']) && is_scalar($row['label']) ? $this->normalize_subscription_item_display_label((string) $row['label']) : '';
            $value = isset($row['value']) && is_scalar($row['value']) ? trim(html_entity_decode(wp_strip_all_tags((string) $row['value'], true), ENT_QUOTES, 'UTF-8')) : '';

            if ($label === '' || $value === '') {
                continue;
            }

            $normalizedLabel = ltrim(sanitize_key($label), '_');
            if ($normalizedLabel !== '' && in_array($normalizedLabel, $this->get_subscription_order_item_display_meta_excluded_keys(), true)) {
                continue;
            }

            $hash = $this->get_subscription_item_display_row_hash($label, $value);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $clean[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $clean;
    }

    private function exclude_subscription_item_attribute_display_meta(array $rows, int $baseProductId): array {
        $rows = $this->sanitize_subscription_item_display_meta($rows);
        if ($baseProductId <= 0) {
            return $rows;
        }

        $attributeDisplayLabels = $this->get_subscription_item_attribute_display_meta_labels($baseProductId);
        if (empty($attributeDisplayLabels)) {
            return $rows;
        }

        $filtered = [];
        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? '');
            $normalizedLabel = ltrim(sanitize_key($this->normalize_subscription_item_display_label($label)), '_');
            if ($normalizedLabel !== '' && in_array($normalizedLabel, $attributeDisplayLabels, true)) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    private function get_subscription_item_display_meta(array $item): array {
        $stored = isset($item['display_meta']) && is_array($item['display_meta']) ? $item['display_meta'] : [];

        return $this->exclude_subscription_item_attribute_display_meta(
            $stored,
            (int) ($item['base_product_id'] ?? 0)
        );
    }

    private function should_skip_order_item_display_meta_key(string $metaKey): bool {
        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return true;
        }

        $normalizedKey = ltrim(sanitize_key($metaKey), '_');
        if ($normalizedKey === '') {
            return true;
        }

        if ($this->is_subscription_selected_attribute_meta_key($normalizedKey)) {
            return true;
        }

        if (in_array($normalizedKey, $this->get_subscription_order_item_display_meta_excluded_keys(), true)) {
            return true;
        }

        return strpos($metaKey, '_') === 0;
    }

    private function should_skip_formatted_order_item_display_meta(string $metaKey, string $label): bool {
        $label = trim($label);
        if ($label === '') {
            return true;
        }

        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return false;
        }

        $normalizedKey = ltrim(sanitize_key($metaKey), '_');
        if ($normalizedKey === '' || $this->is_subscription_selected_attribute_meta_key($normalizedKey)) {
            return true;
        }

        return in_array($normalizedKey, $this->get_subscription_order_item_display_meta_excluded_keys(), true);
    }

    private function get_subscription_order_item_display_meta_excluded_keys(): array {
        return [
            ltrim(sanitize_key(self::ORDER_ITEM_META_SELECTED_ATTRIBUTES), '_'),
            ltrim(sanitize_key(self::ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT), '_'),
            ltrim(sanitize_key(self::ORDER_ITEM_META_SOURCE_ORDER_ITEM_ID), '_'),
            'hb_ucs_subscription_base_product_id',
            'hb_ucs_subscription_base_variation_id',
            'hb_ucs_subscription_scheme',
            'reduced_stock',
            'sku',
        ];
    }

    private function get_subscription_order_item_hidden_meta_keys(): array {
        return [
            self::ORDER_ITEM_META_SELECTED_ATTRIBUTES,
            self::ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT,
            self::ORDER_ITEM_META_SOURCE_ORDER_ITEM_ID,
            self::ORDER_ITEM_META_CATALOG_UNIT_PRICE,
            '_hb_ucs_subscription_base_product_id',
            '_hb_ucs_subscription_base_variation_id',
            '_hb_ucs_subscription_scheme',
            '_reduced_stock',
        ];
    }

    private function get_subscription_order_item_base_ids($item, bool $resolveParentProduct = false): array {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return [0, 0];
        }

        $baseProductId = (int) $item->get_meta('_hb_ucs_subscription_base_product_id', true);
        $baseVariationId = (int) $item->get_meta('_hb_ucs_subscription_base_variation_id', true);

        if ($resolveParentProduct && $baseProductId <= 0 && $baseVariationId > 0 && function_exists('wc_get_product')) {
            $variation = wc_get_product($baseVariationId);
            if ($variation && is_object($variation) && method_exists($variation, 'get_parent_id')) {
                $baseProductId = (int) $variation->get_parent_id();
            }
        }

        return [$baseProductId, $baseVariationId];
    }

    private function is_subscription_order_item($item): bool {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return false;
        }

        [$baseProductId, $baseVariationId] = $this->get_subscription_order_item_base_ids($item);

        return $baseProductId > 0
            || $baseVariationId > 0
            || (string) $item->get_meta('_hb_ucs_subscription_scheme', true) !== ''
            || (int) $item->get_meta(self::ORDER_ITEM_META_SOURCE_ORDER_ITEM_ID, true) > 0;
    }

    private function get_order_item_display_meta_label(string $metaKey): string {
        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return '';
        }

        $metaKey = (string) preg_replace('/^_+/', '', $metaKey);
        $label = str_replace(['pa_', 'attribute_'], '', $metaKey);
        $label = str_replace(['-', '_'], ' ', $label);

        return trim(wp_strip_all_tags(ucwords($label), true));
    }

    private function get_variable_product_attribute_config($product, bool $variationOnly = false): array {
        if (!$product || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable') || !method_exists($product, 'get_attributes')) {
            return [];
        }

        $config = [];
        foreach ((array) $product->get_attributes() as $attributeObject) {
            if (!is_object($attributeObject) || !method_exists($attributeObject, 'get_name')) {
                continue;
            }
            if ($variationOnly && method_exists($attributeObject, 'get_variation') && !$attributeObject->get_variation()) {
                continue;
            }

            $attributeName = (string) $attributeObject->get_name();
            if ($attributeName === '') {
                continue;
            }

            $attributeKey = 'attribute_' . sanitize_title($attributeName);
            if ($attributeKey === 'attribute_') {
                continue;
            }

            $options = [];
            if (taxonomy_exists($attributeName)) {
                foreach ((array) $attributeObject->get_terms() as $term) {
                    if (!$term || is_wp_error($term) || !isset($term->slug, $term->name)) {
                        continue;
                    }
                    $options[] = [
                        'value' => (string) $term->slug,
                        'label' => (string) $term->name,
                    ];
                }
            } elseif (method_exists($attributeObject, 'get_options')) {
                foreach ((array) $attributeObject->get_options() as $optionValue) {
                    $optionValue = (string) $optionValue;
                    if ($optionValue === '') {
                        continue;
                    }
                    $options[] = [
                        'value' => sanitize_title($optionValue),
                        'label' => $optionValue,
                    ];
                }
            }

            if (empty($options)) {
                continue;
            }

            $config[] = [
                'key' => $attributeKey,
                'label' => wc_attribute_label($attributeName, $product),
                'options' => $options,
            ];
        }

        return $config;
    }

    private function get_variable_product_variation_lookup($product, string $scheme = ''): array {
        if (!$product || !is_object($product) || !($product instanceof \WC_Product_Variable) || !method_exists($product, 'get_children')) {
            return [];
        }

        $rows = [];

        foreach ((array) $product->get_children() as $variationId) {
            $variationId = (int) $variationId;
            if ($variationId <= 0) {
                continue;
            }

            $variation = wc_get_product($variationId);
            if (!$variation || !is_object($variation) || !($variation instanceof \WC_Product_Variation)) {
                continue;
            }
            if (method_exists($variation, 'get_status') && $variation->get_status() !== 'publish') {
                continue;
            }

            $imageHtml = method_exists($variation, 'get_image') ? (string) $variation->get_image('woocommerce_thumbnail', ['class' => 'hb-ucs-subscription-item-card__image-el']) : '';
            if ($imageHtml === '' && method_exists($product, 'get_image')) {
                $imageHtml = (string) $product->get_image('woocommerce_thumbnail', ['class' => 'hb-ucs-subscription-item-card__image-el']);
            }
            if ($imageHtml === '' && function_exists('wc_placeholder_img')) {
                $imageHtml = (string) wc_placeholder_img('woocommerce_thumbnail', ['class' => 'hb-ucs-subscription-item-card__image-el']);
            }

            $parentId = method_exists($variation, 'get_parent_id') ? (int) $variation->get_parent_id() : 0;
            $summary = $this->get_variation_option_label($variation, $parentId);
            $priceHtml = '';
            $pricing = $scheme !== '' ? $this->get_product_page_subscription_pricing($variationId, $variation, $scheme, $parentId) : null;
            if ($pricing) {
                $priceHtml = (string) html_entity_decode(
                    wp_strip_all_tags(
                        $this->format_subscription_price_html(
                            (float) ($pricing['base'] ?? 0.0),
                            (float) ($pricing['final'] ?? 0.0),
                            (string) ($pricing['badge'] ?? '')
                        ),
                        true
                    ),
                    ENT_QUOTES,
                    'UTF-8'
                );
            } elseif (method_exists($variation, 'get_price_html')) {
                $priceHtml = (string) wp_strip_all_tags($variation->get_price_html(), true);
            }

            $rows[] = [
                'id' => $variationId,
                'parent_id' => $parentId,
                'label' => wp_strip_all_tags((string) $variation->get_name(), true),
                'summary' => $summary,
                'attributes' => $this->get_selected_attributes_from_variation($variation),
                'price_html' => $priceHtml,
                'image_html' => $imageHtml,
            ];
        }

        return $rows;
    }

    private function get_selected_attributes_from_variation($variation): array {
        if (!$variation || !is_object($variation) || !($variation instanceof \WC_Product_Variation)) {
            return [];
        }

        $out = [];
        foreach ((array) $variation->get_variation_attributes() as $key => $value) {
            $out[(string) $key] = is_scalar($value) ? (string) $value : '';
        }

        return $this->sanitize_selected_attributes_map($out);
    }

    private function get_selected_attributes_from_order_item($item, int $baseProductId = 0, int $baseVariationId = 0, bool $includeFormattedMeta = true): array {
        $selectedAttributes = [];

        if (is_object($item) && method_exists($item, 'get_meta')) {
            $storedSelectedAttributes = $item->get_meta(self::ORDER_ITEM_META_SELECTED_ATTRIBUTES, true);
            if (is_string($storedSelectedAttributes) && $storedSelectedAttributes !== '') {
                $decodedSelectedAttributes = json_decode($storedSelectedAttributes, true);
                if (is_array($decodedSelectedAttributes)) {
                    $normalizedSelectedAttributes = $this->normalize_selected_attributes_from_variation_payload($decodedSelectedAttributes, $baseProductId);
                    if (!empty($normalizedSelectedAttributes)) {
                        $selectedAttributes = $normalizedSelectedAttributes;
                    }
                }
            }
        }

        $product = null;
        $config = [];
        $configByMetaKey = [];
        $metaCandidates = [];

        if ($baseVariationId > 0 && function_exists('wc_get_product')) {
            $variation = wc_get_product($baseVariationId);
            foreach ($this->get_selected_attributes_from_variation($variation) as $attributeKey => $attributeValue) {
                if ($attributeKey === '' || $attributeValue === '' || $this->has_selected_attribute_value($selectedAttributes, $attributeKey)) {
                    continue;
                }

                $selectedAttributes[$attributeKey] = $attributeValue;
            }
        }

        if ($baseProductId > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($baseProductId);
            if ($product && is_object($product)) {
                $config = $this->get_variable_product_attribute_config($product);
                foreach ($config as $attribute) {
                    $key = (string) ($attribute['key'] ?? '');
                    if ($key === '' || $key === 'attribute_') {
                        continue;
                    }

                    foreach ($this->get_selected_attribute_key_aliases($key) as $aliasKey) {
                        if ($aliasKey === '') {
                            continue;
                        }

                        $configByMetaKey[$aliasKey] = $attribute;
                    }
                }
            }
        }

        if (!is_object($item) || !method_exists($item, 'get_meta_data')) {
            return $selectedAttributes;
        }

        foreach ((array) $item->get_meta_data() as $meta) {
            if (!is_object($meta) || !method_exists($meta, 'get_data')) {
                continue;
            }

            $data = (array) $meta->get_data();
            $rawKey = (string) ($data['key'] ?? '');
            $rawValue = is_scalar($data['value'] ?? null) ? (string) $data['value'] : '';
            $key = sanitize_key($rawKey);
            $value = sanitize_title($rawValue);

            if ($key === '' || $key === 'attribute_' || $value === '') {
                if ($rawKey !== '' && $rawValue !== '') {
                    $metaCandidates[$rawKey] = $rawValue;
                }
                continue;
            }

            if (strpos($key, 'attribute_') === 0) {
                if (!$this->has_selected_attribute_value($selectedAttributes, $key)) {
                    $selectedAttributes[$key] = $value;
                }
            } elseif (isset($configByMetaKey[$key]) && is_array($configByMetaKey[$key])) {
                $canonicalKey = (string) ($configByMetaKey[$key]['key'] ?? '');
                $normalizedValue = $this->normalize_selected_attribute_value_from_config($rawValue, $configByMetaKey[$key]);
                if ($canonicalKey !== '' && $normalizedValue !== '' && !$this->has_selected_attribute_value($selectedAttributes, $canonicalKey)) {
                    $selectedAttributes[$canonicalKey] = $normalizedValue;
                }
            }

            $metaCandidates[$rawKey] = $rawValue;
            $metaCandidates[$key] = $rawValue;
            $metaCandidates[str_replace('attribute_', '', $key)] = $rawValue;
        }

        if ($includeFormattedMeta && method_exists($item, 'get_formatted_meta_data')) {
            foreach ((array) $item->get_formatted_meta_data('', true) as $meta) {
                if (!is_object($meta)) {
                    continue;
                }

                $displayKey = isset($meta->display_key) ? (string) $meta->display_key : '';
                $displayValue = isset($meta->display_value) ? html_entity_decode(wp_strip_all_tags((string) $meta->display_value, true), ENT_QUOTES, 'UTF-8') : '';
                if ($displayKey === '' || $displayValue === '') {
                    continue;
                }

                $metaCandidates[$displayKey] = $displayValue;
                $metaCandidates[sanitize_title($displayKey)] = $displayValue;
                $metaCandidates[sanitize_key($displayKey)] = $displayValue;
            }
        }

        if (!empty($config) && method_exists($item, 'get_meta')) {
            foreach ($config as $attribute) {
                $key = (string) ($attribute['key'] ?? '');
                $label = (string) ($attribute['label'] ?? '');
                if ($key === '' || $key === 'attribute_' || !empty($selectedAttributes[$key])) {
                    continue;
                }

                $rawValue = '';
                if ($label !== '') {
                    $rawValue = (string) $item->get_meta($label, true);
                }
                if ($rawValue === '') {
                    $rawValue = (string) $item->get_meta(str_replace('attribute_', '', $key), true);
                }
                if ($rawValue === '') {
                    $rawValue = (string) $item->get_meta($key, true);
                }
                if ($rawValue === '' && isset($metaCandidates[$label])) {
                    $rawValue = (string) $metaCandidates[$label];
                }
                if ($rawValue === '' && isset($metaCandidates[sanitize_title($label)])) {
                    $rawValue = (string) $metaCandidates[sanitize_title($label)];
                }
                if ($rawValue === '' && isset($metaCandidates[sanitize_key($label)])) {
                    $rawValue = (string) $metaCandidates[sanitize_key($label)];
                }
                if ($rawValue === '' && isset($metaCandidates[$key])) {
                    $rawValue = (string) $metaCandidates[$key];
                }
                if ($rawValue === '' && isset($metaCandidates[str_replace('attribute_', '', $key)])) {
                    $rawValue = (string) $metaCandidates[str_replace('attribute_', '', $key)];
                }

                $normalizedValue = $this->normalize_selected_attribute_value_from_config($rawValue, $attribute);
                if ($normalizedValue === '') {
                    continue;
                }

                $selectedAttributes[$key] = $normalizedValue;
            }
        }

        if ($product && is_object($product) && method_exists($product, 'is_type') && $product->is_type('variable')) {
            return $this->normalize_selected_attributes_for_product($product, $selectedAttributes);
        }

        return $this->sanitize_selected_attributes_map($selectedAttributes);
    }

    private function get_attribute_snapshot_from_order_item($item, int $baseProductId = 0, int $baseVariationId = 0): array {
        if (is_object($item) && method_exists($item, 'get_meta')) {
            $storedSnapshot = $item->get_meta(self::ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT, true);
            if (is_string($storedSnapshot) && $storedSnapshot !== '') {
                $decodedSnapshot = json_decode($storedSnapshot, true);
                if (is_array($decodedSnapshot)) {
                    $normalizedSnapshot = $this->normalize_selected_attributes_from_variation_payload($decodedSnapshot, $baseProductId);
                    if (!empty($normalizedSnapshot)) {
                        return $normalizedSnapshot;
                    }
                }
            }
        }

        return $this->get_selected_attributes_from_order_item($item, $baseProductId, $baseVariationId, false);
    }

    private function get_selected_attribute_display_rows(int $baseProductId, array $selectedAttributes): array {
        $rows = [];
        $seen = [];
        $selectedAttributes = $this->sanitize_selected_attributes_map($selectedAttributes);
        $configByKey = [];

        if ($baseProductId > 0) {
            $product = wc_get_product($baseProductId);
            if ($product && is_object($product)) {
                foreach ($this->get_variable_product_attribute_config($product) as $attribute) {
                    $key = (string) ($attribute['key'] ?? '');
                    if ($key === '') {
                        continue;
                    }

                    $configByKey[$key] = $attribute;
                }
            }
        }

        foreach ($selectedAttributes as $key => $rawValue) {
            $key = sanitize_key((string) $key);
            $rawValue = (string) $rawValue;
            if ($key === '' || $rawValue === '') {
                continue;
            }

            $label = '';
            $value = '';

            if (isset($configByKey[$key]) && is_array($configByKey[$key])) {
                $attribute = $configByKey[$key];
                $label = (string) ($attribute['label'] ?? $key);
                $value = $rawValue;
                foreach ((array) ($attribute['options'] ?? []) as $option) {
                    $optionValue = (string) ($option['value'] ?? '');
                    if ($optionValue === $rawValue) {
                        $value = (string) ($option['label'] ?? $rawValue);
                        break;
                    }
                }
            } else {
                $label = $this->get_order_item_display_meta_label($key);
                $value = trim(wp_strip_all_tags(ucwords(str_replace(['-', '_'], ' ', $rawValue)), true));
            }

            if ($label === '' || $value === '') {
                continue;
            }

            $hash = $this->get_subscription_item_display_row_hash($label, $value);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $rows[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    private function get_subscription_item_attribute_display_meta_labels(int $baseProductId): array {
        $labels = [];

        if ($baseProductId <= 0 || !function_exists('wc_get_product')) {
            return $labels;
        }

        $product = wc_get_product($baseProductId);
        if (!$product || !is_object($product)) {
            return $labels;
        }

        foreach ($this->get_variable_product_attribute_config($product) as $attribute) {
            $key = (string) ($attribute['key'] ?? '');
            $label = (string) ($attribute['label'] ?? '');

            if ($label !== '') {
                $labels[] = ltrim(sanitize_key($this->normalize_subscription_item_display_label($label)), '_');
            }

            if ($key !== '') {
                foreach ($this->get_selected_attribute_key_aliases($key) as $aliasKey) {
                    $labels[] = ltrim(sanitize_key($aliasKey), '_');
                    $labels[] = ltrim(sanitize_key(str_replace('attribute_', '', $aliasKey)), '_');
                    $labels[] = ltrim(sanitize_key($this->normalize_subscription_item_display_label($this->get_order_item_display_meta_label($aliasKey))), '_');
                }
            }
        }

        return array_values(array_unique(array_filter($labels)));
    }

    private function sanitize_subscription_item_order_totals_snapshot(array $orderTotals): array {
        $normalized = [];

        foreach (['line_subtotal', 'line_tax', 'line_total'] as $key) {
            if (!array_key_exists($key, $orderTotals)) {
                continue;
            }

            $normalized[$key] = (float) wc_format_decimal((string) $orderTotals[$key]);
        }

        return $normalized;
    }

    private function get_subscription_item_order_totals_from_order_item($item): array {
        if (!$item || !is_object($item)) {
            return [];
        }

        $lineSubtotal = method_exists($item, 'get_subtotal') ? (float) $item->get_subtotal() : 0.0;
        $lineTotalExcludingTax = method_exists($item, 'get_total') ? (float) $item->get_total() : $lineSubtotal;
        $lineTax = method_exists($item, 'get_total_tax') ? (float) $item->get_total_tax() : 0.0;

        return $this->sanitize_subscription_item_order_totals_snapshot([
            'line_subtotal' => $lineSubtotal,
            'line_tax' => $lineTax,
            'line_total' => $lineTotalExcludingTax + $lineTax,
        ]);
    }

    private function get_subscription_item_live_order_totals(array $item): array {
        $liveTotals = [];

        foreach (['line_subtotal', 'line_tax', 'line_total'] as $key) {
            if (!array_key_exists($key, $item)) {
                continue;
            }

            $liveTotals[$key] = $item[$key];
        }

        return $this->sanitize_subscription_item_order_totals_snapshot($liveTotals);
    }

    private function get_display_meta_rows_from_order_item($item, int $baseProductId = 0, array $selectedAttributes = [], bool $includeFormattedMeta = true): array {
        if (!is_object($item)) {
            return [];
        }

        $rows = [];
        $seen = [];
        $attributeDisplayLabels = $this->get_subscription_item_attribute_display_meta_labels($baseProductId);
        $selectedRows = $this->get_subscription_item_attribute_display_rows([
            'base_product_id' => $baseProductId,
            'selected_attributes' => $selectedAttributes,
        ]);

        foreach ($selectedRows as $selectedRow) {
            $selectedLabel = (string) ($selectedRow['label'] ?? '');
            $selectedValue = (string) ($selectedRow['value'] ?? '');
            if ($selectedLabel === '' || $selectedValue === '') {
                continue;
            }

            $seen[$this->get_subscription_item_display_row_hash($selectedLabel, $selectedValue)] = true;
        }

        if ($includeFormattedMeta && (method_exists($item, 'get_all_formatted_meta_data') || method_exists($item, 'get_formatted_meta_data'))) {
            $formattedMeta = method_exists($item, 'get_all_formatted_meta_data')
                ? (array) $item->get_all_formatted_meta_data('', true)
                : (array) $item->get_formatted_meta_data('', true);

            foreach ($formattedMeta as $meta) {
                if (!is_object($meta)) {
                    continue;
                }

                $metaKey = isset($meta->key) ? (string) $meta->key : '';
                $label = isset($meta->display_key) ? trim(wp_strip_all_tags((string) $meta->display_key, true)) : '';
                $value = isset($meta->display_value) ? trim(html_entity_decode(wp_strip_all_tags((string) $meta->display_value, true), ENT_QUOTES, 'UTF-8')) : '';
                $normalizedLabel = ltrim(sanitize_key($this->normalize_subscription_item_display_label($label)), '_');

                if (
                    $label === ''
                    || $value === ''
                    || $this->should_skip_formatted_order_item_display_meta($metaKey, $label)
                    || ($normalizedLabel !== '' && in_array($normalizedLabel, $attributeDisplayLabels, true))
                ) {
                    continue;
                }

                $hash = $this->get_subscription_item_display_row_hash($label, $value);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $rows[] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        if (method_exists($item, 'get_meta_data')) {
            foreach ((array) $item->get_meta_data() as $meta) {
                if (!is_object($meta) || !method_exists($meta, 'get_data')) {
                    continue;
                }

                $data = (array) $meta->get_data();
                $metaKey = isset($data['key']) && is_scalar($data['key']) ? (string) $data['key'] : '';
                $metaValue = isset($data['value']) && is_scalar($data['value']) ? trim((string) $data['value']) : '';

                if ($metaValue === '' || $this->should_skip_order_item_display_meta_key($metaKey)) {
                    continue;
                }

                $label = $this->get_order_item_display_meta_label($metaKey);
                $normalizedLabel = ltrim(sanitize_key($this->normalize_subscription_item_display_label($label)), '_');
                if ($label === '' || ($normalizedLabel !== '' && in_array($normalizedLabel, $attributeDisplayLabels, true))) {
                    continue;
                }

                $hash = $this->get_subscription_item_display_row_hash($label, $metaValue);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $rows[] = [
                    'label' => $label,
                    'value' => $metaValue,
                ];
            }
        }

        return $this->sanitize_subscription_item_display_meta($rows);
    }

    private function normalize_selected_attributes_from_variation_payload(array $variationData, int $baseProductId = 0): array {
        if (empty($variationData)) {
            return [];
        }

        $product = $baseProductId > 0 && function_exists('wc_get_product') ? wc_get_product($baseProductId) : null;
        $config = $product && is_object($product) ? $this->get_variable_product_attribute_config($product) : [];
        $configByCanonicalKey = [];
        $configLookup = $this->get_attribute_config_lookup($config);

        foreach ($config as $attribute) {
            $canonicalKey = (string) ($attribute['key'] ?? '');
            if ($canonicalKey === '' || $canonicalKey === 'attribute_') {
                continue;
            }

            $configByCanonicalKey[$canonicalKey] = $attribute;
        }

        $normalized = [];
        foreach ($variationData as $key => $value) {
            $rawKey = (string) $key;
            $rawValue = is_scalar($value) ? (string) $value : '';
            $sanitizedKey = sanitize_key($rawKey);

            if ($sanitizedKey === '' || $rawValue === '') {
                continue;
            }

            $attribute = null;
            $canonicalKey = '';

            if (strpos($sanitizedKey, 'attribute_') === 0 && isset($configByCanonicalKey[$sanitizedKey])) {
                $canonicalKey = $sanitizedKey;
                $attribute = $configByCanonicalKey[$sanitizedKey];
            } elseif (strpos($sanitizedKey, 'attribute_') === 0) {
                $canonicalKey = $sanitizedKey;
            }

            if ($attribute === null) {
                foreach ($this->get_selected_attribute_key_aliases($sanitizedKey) as $aliasKey) {
                    if (!isset($configLookup[$aliasKey]) || !is_array($configLookup[$aliasKey])) {
                        continue;
                    }

                    $attribute = $configLookup[$aliasKey];
                    $canonicalKey = (string) ($attribute['key'] ?? '');
                    break;
                }
            }

            if ($canonicalKey === '' || $canonicalKey === 'attribute_') {
                continue;
            }

            $normalizedValue = is_array($attribute)
                ? $this->normalize_selected_attribute_value_from_config($rawValue, $attribute)
                : sanitize_title($rawValue);

            if ($normalizedValue === '') {
                continue;
            }

            $normalized[$canonicalKey] = $normalizedValue;
        }

        return $this->sanitize_selected_attributes_map($normalized);
    }

    private function normalize_selected_attribute_value_from_config(string $rawValue, array $attribute): string {
        $rawValue = html_entity_decode(wp_strip_all_tags(trim($rawValue), true), ENT_QUOTES, 'UTF-8');
        if ($rawValue === '') {
            return '';
        }

        $rawValueSanitized = sanitize_title($rawValue);
        foreach ((array) ($attribute['options'] ?? []) as $option) {
            $optionValue = (string) ($option['value'] ?? '');
            $optionLabel = (string) ($option['label'] ?? $optionValue);

            if ($optionValue === '') {
                continue;
            }

            if ($rawValue === $optionValue || $rawValueSanitized === $optionValue) {
                return $optionValue;
            }

            if ($rawValue === $optionLabel || $rawValueSanitized === sanitize_title($optionLabel)) {
                return $optionValue;
            }
        }

        return $rawValueSanitized;
    }

    private function get_subscription_item_selected_attributes(array $item): array {
        $stored = isset($item['selected_attributes']) && is_array($item['selected_attributes']) ? $item['selected_attributes'] : [];
        if (!empty($stored)) {
            $baseProductId = (int) ($item['base_product_id'] ?? 0);
            if ($baseProductId > 0 && function_exists('wc_get_product')) {
                $product = wc_get_product($baseProductId);
                if ($product && is_object($product) && method_exists($product, 'is_type') && $product->is_type('variable')) {
                    return $this->normalize_selected_attributes_for_product($product, $stored);
                }
            }

            return $this->sanitize_selected_attributes_map($stored);
        }

        $variationId = (int) ($item['base_variation_id'] ?? 0);
        if ($variationId > 0) {
            $variation = wc_get_product($variationId);
            return $this->get_selected_attributes_from_variation($variation);
        }

        return [];
    }

    private function get_subscription_item_attribute_snapshot(array $item): array {
        $stored = isset($item['attribute_snapshot']) && is_array($item['attribute_snapshot']) ? $item['attribute_snapshot'] : [];
        if (empty($stored)) {
            $stored = isset($item['selected_attributes']) && is_array($item['selected_attributes']) ? $item['selected_attributes'] : [];
        }

        if (empty($stored)) {
            return [];
        }

        $baseProductId = (int) ($item['base_product_id'] ?? 0);
        if ($baseProductId > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($baseProductId);
            if ($product && is_object($product) && method_exists($product, 'is_type') && $product->is_type('variable')) {
                return $this->normalize_selected_attributes_from_variation_payload($stored, $baseProductId);
            }
        }

        return $this->sanitize_selected_attributes_map($stored);
    }

    private function normalize_selected_attributes_for_product($product, array $selectedAttributes): array {
        return $this->normalize_selected_attributes_for_product_scope($product, $selectedAttributes, false);
    }

    private function normalize_selected_attributes_for_product_scope($product, array $selectedAttributes, bool $variationOnly = false): array {
        if (!$product || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
            return [];
        }

        $selectedAttributes = $this->sanitize_selected_attributes_map($selectedAttributes);
        $normalized = [];
        $config = $this->get_variable_product_attribute_config($product, $variationOnly);
        $configLookup = $this->get_attribute_config_lookup($config);
        foreach ($config as $attribute) {
            $key = (string) ($attribute['key'] ?? '');
            if ($key === '') {
                continue;
            }

            $value = '';
            foreach ($this->get_selected_attribute_key_aliases($key) as $aliasKey) {
                if (!isset($selectedAttributes[$aliasKey])) {
                    continue;
                }

                $rawValue = (string) $selectedAttributes[$aliasKey];
                $value = $this->normalize_selected_attribute_value_from_config($rawValue, $attribute);
                if ($value !== '') {
                    break;
                }
            }

            if ($value === '' && isset($configLookup[$key]) && is_array($configLookup[$key])) {
                $value = isset($selectedAttributes[$key]) ? sanitize_title((string) $selectedAttributes[$key]) : '';
            }

            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    private function resolve_variation_id_from_attributes($product, array $selectedAttributes): int {
        if (!$product || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
            return 0;
        }

        $normalized = $this->normalize_selected_attributes_for_product_scope($product, $selectedAttributes, true);
        if (empty($normalized)) {
            return 0;
        }

        $config = $this->get_variable_product_attribute_config($product, true);
        foreach ($config as $attribute) {
            $key = (string) ($attribute['key'] ?? '');
            if ($key === '' || empty($normalized[$key])) {
                return 0;
            }
        }

        try {
            $dataStore = \WC_Data_Store::load('product');
            if ($dataStore && method_exists($dataStore, 'find_matching_product_variation')) {
                $matchedId = (int) $dataStore->find_matching_product_variation($product, $normalized);
                if ($matchedId > 0) {
                    return $matchedId;
                }
            }
        } catch (\Throwable $e) {
        }

        if (!method_exists($product, 'get_children')) {
            return 0;
        }

        foreach ((array) $product->get_children() as $variationId) {
            $variationId = (int) $variationId;
            if ($variationId <= 0) {
                continue;
            }

            $variation = wc_get_product($variationId);
            if (!$variation || !is_object($variation) || !($variation instanceof \WC_Product_Variation)) {
                continue;
            }
            if (method_exists($variation, 'get_status') && $variation->get_status() !== 'publish') {
                continue;
            }

            $variationAttributes = [];
            foreach ((array) $variation->get_variation_attributes() as $attributeKey => $attributeValue) {
                $attributeKey = sanitize_key((string) $attributeKey);
                $attributeValue = (string) $attributeValue;
                if ($attributeKey === '') {
                    continue;
                }
                $variationAttributes[$attributeKey] = $attributeValue;
            }

            $isMatch = true;
            foreach ($normalized as $attributeKey => $attributeValue) {
                $currentValue = isset($variationAttributes[$attributeKey]) ? (string) $variationAttributes[$attributeKey] : '';
                if ($currentValue === '') {
                    continue;
                }

                $currentValueSanitized = sanitize_title($currentValue);
                $selectedValueSanitized = sanitize_title((string) $attributeValue);
                if ($currentValueSanitized !== $selectedValueSanitized && $currentValue !== (string) $attributeValue) {
                    $isMatch = false;
                    break;
                }
            }

            if ($isMatch) {
                return $variationId;
            }
        }

        return 0;
    }

    private function get_subscription_item_label(array $item): string {
        $variationId = (int) ($item['base_variation_id'] ?? 0);
        $productId = (int) ($item['base_product_id'] ?? 0);
        $targetId = $variationId > 0 ? $variationId : $productId;
        if ($targetId <= 0 || !function_exists('wc_get_product')) {
            return __('Onbekend product', 'hb-ucs');
        }

        $product = wc_get_product($targetId);
        if (!$product || !is_object($product)) {
            return __('Onbekend product', 'hb-ucs');
        }

        $attributeSummary = $this->get_subscription_item_variation_summary($item);
        if ($attributeSummary !== '') {
            $baseProduct = $productId > 0 ? wc_get_product($productId) : null;
            $baseLabel = $baseProduct && is_object($baseProduct) && method_exists($baseProduct, 'get_name') ? (string) $baseProduct->get_name() : (string) $product->get_name();

            return wp_strip_all_tags($baseLabel . ' — ' . $attributeSummary, true);
        }

        if (method_exists($product, 'is_type') && $product->is_type('variation')) {
            $parentId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
            $parent = $parentId > 0 ? wc_get_product($parentId) : null;
            $prefix = $parent && is_object($parent) && method_exists($parent, 'get_name') ? $parent->get_name() . ' — ' : '';
            $variationText = $this->get_variation_option_label($product, $targetId);
            return wp_strip_all_tags($prefix . $variationText, true);
        }

        return wp_strip_all_tags((string) $product->get_name(), true);
    }

    private function get_subscription_item_variation_summary(array $item): string {
        $displayRows = $this->get_subscription_item_attribute_display_rows($item);

        if (!empty($displayRows)) {
            $parts = [];
            foreach ($displayRows as $row) {
                $label = (string) ($row['label'] ?? '');
                $value = (string) ($row['value'] ?? '');
                if ($label === '' || $value === '') {
                    continue;
                }

                $parts[] = $label . ': ' . $value;
            }

            if (!empty($parts)) {
                return implode(' | ', $parts);
            }
        }

        $variationId = (int) ($item['base_variation_id'] ?? 0);
        if ($variationId > 0) {
            $variation = wc_get_product($variationId);
            return $this->get_variation_option_label($variation, $variationId);
        }

        return '';
    }

    private function get_subscription_item_attribute_display_rows(array $item): array {
        $baseProductId = (int) ($item['base_product_id'] ?? 0);
        $selectedAttributes = $this->get_subscription_item_attribute_snapshot($item);
        $rows = $this->get_selected_attribute_display_rows($baseProductId, $selectedAttributes);
        $attributeDisplayLabels = $this->get_subscription_item_attribute_display_meta_labels($baseProductId);

        $seen = [];
        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? '');
            $value = (string) ($row['value'] ?? '');
            if ($label === '' || $value === '') {
                continue;
            }

            $seen[$this->get_subscription_item_display_row_hash($label, $value)] = true;
        }

        foreach ($this->get_subscription_item_display_meta($item) as $row) {
            $label = (string) ($row['label'] ?? '');
            $value = (string) ($row['value'] ?? '');
            if ($label === '' || $value === '') {
                continue;
            }

             $normalizedLabel = ltrim(sanitize_key($this->normalize_subscription_item_display_label($label)), '_');
            if ($normalizedLabel !== '' && in_array($normalizedLabel, $attributeDisplayLabels, true)) {
                continue;
            }

            $hash = $this->get_subscription_item_display_row_hash($label, $value);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $rows[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    private function normalize_subscription_item(array $item): ?array {
        $baseProductId = (int) ($item['base_product_id'] ?? 0);
        $baseVariationId = (int) ($item['base_variation_id'] ?? 0);
        $sourceOrderItemId = max(0, (int) ($item['source_order_item_id'] ?? 0));
        $qty = (int) ($item['qty'] ?? 1);
        $unitPrice = isset($item['unit_price']) ? (float) wc_format_decimal((string) $item['unit_price']) : 0.0;
        $priceIncludesTax = !empty($item['price_includes_tax']);
        $selectedAttributes = isset($item['selected_attributes']) && is_array($item['selected_attributes']) ? $item['selected_attributes'] : [];
        $displayMeta = isset($item['display_meta']) && is_array($item['display_meta']) ? $item['display_meta'] : [];
        $taxes = $this->normalize_subscription_item_taxes($item['taxes'] ?? []);
        $hasExplicitLineTotals = array_key_exists('line_subtotal', $item)
            || array_key_exists('line_tax', $item)
            || array_key_exists('line_total', $item);
        $lineTotals = $hasExplicitLineTotals
            ? $this->sanitize_subscription_item_order_totals_snapshot([
                'line_subtotal' => $item['line_subtotal'] ?? null,
                'line_tax' => $item['line_tax'] ?? null,
                'line_total' => $item['line_total'] ?? null,
            ])
            : [];

        if ($baseProductId <= 0) {
            return null;
        }
        if ($qty <= 0) {
            $qty = 1;
        }

        $normalizedAttributes = $this->sanitize_selected_attributes_map($selectedAttributes);
        $attributeSnapshot = isset($item['attribute_snapshot']) && is_array($item['attribute_snapshot'])
            ? $this->sanitize_selected_attributes_map($item['attribute_snapshot'])
            : $normalizedAttributes;
        $normalizedDisplayMeta = $this->exclude_subscription_item_attribute_display_meta($displayMeta, $baseProductId);

        if ($priceIncludesTax && $unitPrice > 0) {
            $priceIncludesTax = $this->should_subscription_item_price_be_treated_as_including_tax(
                $baseVariationId > 0 ? $baseVariationId : $baseProductId,
                $unitPrice,
                $qty,
                $taxes
            );
        } elseif ($unitPrice > 0 && !empty($taxes['total'])) {
            $productId = $baseVariationId > 0 ? $baseVariationId : $baseProductId;
            if (
                $productId > 0
                && $this->should_subscription_item_price_be_treated_as_including_tax($productId, $unitPrice, $qty, $taxes)
            ) {
                $storageUnitPrice = $this->get_subscription_item_storage_unit_price([
                    'base_product_id' => $baseProductId,
                    'base_variation_id' => max(0, $baseVariationId),
                    'unit_price' => $unitPrice,
                    'price_includes_tax' => 1,
                    'taxes' => $taxes,
                ]);

                if ($storageUnitPrice > 0 && abs($storageUnitPrice - $unitPrice) > 0.0001) {
                    $unitPrice = $storageUnitPrice;
                }
            }
        }

        $normalized = [
            'base_product_id' => $baseProductId,
            'base_variation_id' => max(0, $baseVariationId),
            'source_order_item_id' => $sourceOrderItemId,
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'price_includes_tax' => $priceIncludesTax ? 1 : 0,
            'taxes' => $taxes,
            'selected_attributes' => $normalizedAttributes,
            'attribute_snapshot' => $attributeSnapshot,
            'display_meta' => $normalizedDisplayMeta,
        ];

        if (!empty($lineTotals)) {
            $normalized['line_subtotal'] = (float) ($lineTotals['line_subtotal'] ?? 0.0);
            $normalized['line_tax'] = (float) ($lineTotals['line_tax'] ?? 0.0);
            $normalized['line_total'] = (float) ($lineTotals['line_total'] ?? 0.0);
        }

        $normalized['catalog_unit_price'] = $this->resolve_subscription_item_catalog_unit_price($item, $normalized);

        return $normalized;
    }

    private function resolve_subscription_item_catalog_unit_price(array $rawItem, array $normalizedItem): float {
        $explicitCatalogUnitPrice = null;

        if (array_key_exists('catalog_unit_price', $rawItem) && $rawItem['catalog_unit_price'] !== '' && $rawItem['catalog_unit_price'] !== null) {
            $explicitCatalogUnitPrice = (float) wc_format_decimal((string) $rawItem['catalog_unit_price']);
        }

        if ($explicitCatalogUnitPrice !== null && $explicitCatalogUnitPrice >= 0) {
            return $explicitCatalogUnitPrice;
        }

        $scheme = isset($rawItem['scheme']) ? sanitize_key((string) $rawItem['scheme']) : '';
        if ($scheme !== '') {
            $catalogTargetPrice = $this->get_subscription_item_catalog_target_price($normalizedItem, $scheme);
            if ($catalogTargetPrice !== null) {
                return $catalogTargetPrice;
            }
        }

        $sourceOrderItemId = (int) ($normalizedItem['source_order_item_id'] ?? 0);
        $sourceUnitPrice = $this->get_source_order_item_storage_unit_price($sourceOrderItemId);
        if ($sourceUnitPrice !== null) {
            return $sourceUnitPrice;
        }

        return $this->get_subscription_item_storage_unit_price($normalizedItem);
    }

    private function get_subscription_item_catalog_unit_price(array $item): float {
        if (array_key_exists('catalog_unit_price', $item) && $item['catalog_unit_price'] !== '' && $item['catalog_unit_price'] !== null) {
            return (float) wc_format_decimal((string) $item['catalog_unit_price']);
        }

        return $this->get_subscription_item_storage_unit_price($item);
    }

    private function is_subscription_item_catalog_linked(array $item): bool {
        $catalogUnitPrice = $this->get_subscription_item_catalog_unit_price($item);
        $storageUnitPrice = $this->get_subscription_item_storage_unit_price($item);

        return abs($storageUnitPrice - $catalogUnitPrice) < 0.0001;
    }

    private function get_source_order_item_storage_unit_price(int $sourceOrderItemId): ?float {
        if ($sourceOrderItemId <= 0 || !function_exists('WC') || !WC()->order_factory) {
            return null;
        }

        $item = WC()->order_factory->get_order_item($sourceOrderItemId);
        if (!$item || !is_object($item)) {
            return null;
        }

        $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1;
        if ($qty <= 0) {
            $qty = 1;
        }

        $lineTotal = method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
        return (float) wc_format_decimal((string) ($lineTotal / $qty));
    }

    private function get_subscription_item_catalog_target_price(array $item, string $scheme, $overrideProduct = null, int $overrideProductId = 0): ?float {
        $scheme = sanitize_key($scheme);
        if ($scheme === '') {
            return null;
        }

        $baseProductId = (int) ($item['base_product_id'] ?? 0);
        $baseVariationId = (int) ($item['base_variation_id'] ?? 0);

        if ($baseVariationId > 0) {
            if ($overrideProductId > 0 && $overrideProductId === $baseVariationId && $overrideProduct && is_object($overrideProduct)) {
                $basePrice = $this->get_product_current_storage_price($overrideProduct);
                if ($basePrice === null) {
                    return null;
                }

                $parentId = method_exists($overrideProduct, 'get_parent_id')
                    ? (int) $overrideProduct->get_parent_id()
                    : (int) wp_get_post_parent_id($baseVariationId);

                $pricing = $this->get_subscription_pricing($baseVariationId, $basePrice, $scheme, $parentId);
                return isset($pricing['final']) ? (float) wc_format_decimal((string) $pricing['final']) : null;
            }

            return $this->get_variation_subscription_price($baseVariationId, $scheme);
        }

        if ($baseProductId <= 0) {
            return null;
        }

        if ($overrideProductId > 0 && $overrideProductId === $baseProductId && $overrideProduct && is_object($overrideProduct)) {
            $basePrice = $this->get_product_current_storage_price($overrideProduct);
            if ($basePrice === null) {
                return null;
            }

            $pricing = $this->get_subscription_pricing($baseProductId, $basePrice, $scheme);
            return isset($pricing['final']) ? (float) wc_format_decimal((string) $pricing['final']) : null;
        }

        return $this->get_base_subscription_price($baseProductId, $scheme);
    }

    private function get_subscription_items(int $subId, bool $persistRepairs = true, bool $preserveStoredPricing = false): array {
        $subscriptionOrder = $this->get_subscription_order_object($subId);
        if ($subscriptionOrder) {
            $orderItems = $this->extract_subscription_items_from_order($subscriptionOrder);
            if (!empty($orderItems)) {
                return $this->maybe_repair_subscription_item_display_meta($subId, $orderItems, false);
            }
        }

        $stored = get_post_meta($subId, self::SUB_META_ITEMS, true);
        $items = [];
        $didRepairStoredItems = false;

        if (is_array($stored)) {
            foreach ($stored as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $rawUnitPrice = isset($row['unit_price']) ? (float) wc_format_decimal((string) $row['unit_price']) : 0.0;
                $rawPriceIncludesTax = !empty($row['price_includes_tax']) ? 1 : 0;
                $item = $this->normalize_subscription_item($row);
                if ($item) {
                    if ($preserveStoredPricing) {
                        $item['unit_price'] = $rawUnitPrice;
                        $item['price_includes_tax'] = $rawPriceIncludesTax;
                        if (array_key_exists('catalog_unit_price', $row) && $row['catalog_unit_price'] !== '' && $row['catalog_unit_price'] !== null) {
                            $item['catalog_unit_price'] = (float) wc_format_decimal((string) $row['catalog_unit_price']);
                        }
                    } elseif (
                        abs(((float) ($item['unit_price'] ?? 0.0)) - $rawUnitPrice) > 0.0001
                        || (int) ($item['price_includes_tax'] ?? 0) !== $rawPriceIncludesTax
                    ) {
                        $didRepairStoredItems = true;
                    }
                    $items[] = $item;
                }
            }
        }

        if (!empty($items)) {
            if ($persistRepairs && $didRepairStoredItems) {
                $this->persist_subscription_items($subId, $items);
            }
            $items = $this->maybe_repair_subscription_item_display_meta($subId, $items, $persistRepairs);
            return $items;
        }

        $legacyItem = $this->normalize_subscription_item([
            'base_product_id' => (int) get_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID, true),
            'base_variation_id' => (int) get_post_meta($subId, self::SUB_META_BASE_VARIATION_ID, true),
            'qty' => (int) get_post_meta($subId, self::SUB_META_QTY, true),
            'unit_price' => (string) get_post_meta($subId, self::SUB_META_UNIT_PRICE, true),
        ]);

        return $legacyItem ? [$legacyItem] : [];
    }

    private function get_subscription_order_object(int $subId) {
        if ($subId <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($subId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_type')) {
            return null;
        }

        return (string) $order->get_type() === $this->get_subscription_order_type()->get_type() ? $order : null;
    }

    private function extract_subscription_items_from_order($order): array {
        $items = [];
        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            return $items;
        }

        $scheme = method_exists($order, 'get_meta') ? sanitize_key((string) $order->get_meta('_hb_ucs_subscription_scheme', true)) : '';

        foreach ((array) $order->get_items('line_item') as $item) {
            if (!$item || !is_object($item)) {
                continue;
            }

            $baseProductId = method_exists($item, 'get_meta') ? (int) $item->get_meta('_hb_ucs_subscription_base_product_id', true) : 0;
            $baseVariationId = method_exists($item, 'get_meta') ? (int) $item->get_meta('_hb_ucs_subscription_base_variation_id', true) : 0;

            if ($baseProductId <= 0 && method_exists($item, 'get_product_id')) {
                $baseProductId = (int) $item->get_product_id();
            }
            if ($baseVariationId <= 0 && method_exists($item, 'get_variation_id')) {
                $baseVariationId = (int) $item->get_variation_id();
            }

            if ($baseProductId <= 0) {
                continue;
            }

            $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1;
            if ($qty <= 0) {
                $qty = 1;
            }

            $lineTotal = method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
            $lineSubtotal = method_exists($item, 'get_subtotal') ? (float) $item->get_subtotal() : $lineTotal;
            $lineTax = method_exists($item, 'get_total_tax') ? (float) $item->get_total_tax() : 0.0;
            $unitPrice = $qty > 0 ? (float) wc_format_decimal((string) ($lineTotal / $qty)) : 0.0;
            $selectedAttributes = $this->get_selected_attributes_from_order_item($item, $baseProductId, $baseVariationId);
            $attributeSnapshot = $this->get_attribute_snapshot_from_order_item($item, $baseProductId, $baseVariationId);
            $displayMeta = $this->get_display_meta_rows_from_order_item($item, $baseProductId, $selectedAttributes);

            $normalized = $this->normalize_subscription_item([
                'base_product_id' => $baseProductId,
                'base_variation_id' => $baseVariationId,
                'source_order_item_id' => method_exists($item, 'get_meta') ? (int) $item->get_meta(self::ORDER_ITEM_META_SOURCE_ORDER_ITEM_ID, true) : 0,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'catalog_unit_price' => method_exists($item, 'get_meta') ? $item->get_meta(self::ORDER_ITEM_META_CATALOG_UNIT_PRICE, true) : '',
                'scheme' => $scheme,
                'price_includes_tax' => 0,
                'taxes' => method_exists($item, 'get_taxes') ? (array) $item->get_taxes() : [],
                'selected_attributes' => $selectedAttributes,
                'attribute_snapshot' => $attributeSnapshot,
                'display_meta' => $displayMeta,
                'line_subtotal' => $lineSubtotal,
                'line_tax' => $lineTax,
                'line_total' => $lineTotal + $lineTax,
            ]);

            if ($normalized) {
                $items[] = $normalized;
            }
        }

        return $items;
    }

    private function maybe_repair_subscription_item_display_meta(int $subId, array $items, bool $persistRepairs = true): array {
        if ($subId <= 0 || empty($items)) {
            return $items;
        }

        $didRepair = false;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $currentSelectedAttributes = isset($item['selected_attributes']) && is_array($item['selected_attributes'])
                ? $this->sanitize_selected_attributes_map($item['selected_attributes'])
                : [];
            $resolvedSelectedAttributes = $this->get_subscription_item_selected_attributes($item);
            $currentAttributeSnapshot = isset($item['attribute_snapshot']) && is_array($item['attribute_snapshot'])
                ? $this->sanitize_selected_attributes_map($item['attribute_snapshot'])
                : [];
            $resolvedAttributeSnapshot = $this->get_subscription_item_attribute_snapshot($item);

            if ($resolvedSelectedAttributes !== $currentSelectedAttributes) {
                $item['selected_attributes'] = $resolvedSelectedAttributes;
                $didRepair = true;
            }
            if ($resolvedAttributeSnapshot !== $currentAttributeSnapshot) {
                $item['attribute_snapshot'] = $resolvedAttributeSnapshot;
                $didRepair = true;
            }

            $currentDisplayMeta = isset($item['display_meta']) && is_array($item['display_meta']) ? $item['display_meta'] : [];
            $normalizedDisplayMeta = $this->exclude_subscription_item_attribute_display_meta(
                $currentDisplayMeta,
                (int) ($item['base_product_id'] ?? 0)
            );
            if ($normalizedDisplayMeta !== $currentDisplayMeta) {
                $item['display_meta'] = $normalizedDisplayMeta;
                $didRepair = true;
            }

            $items[$index] = $item;
        }

        if ($persistRepairs && $didRepair) {
            $this->persist_subscription_items($subId, $items);
        }

        return $items;
    }

    private function get_subscription_item_effective_display_meta(int $subId, array $item): array {
        return $this->get_subscription_item_display_meta($item);
    }

    private function persist_subscription_items(int $subId, array $items): void {
        $customer = $subId > 0 ? $this->get_subscription_tax_customer($subId) : null;
        $scheme = $subId > 0 ? sanitize_key((string) get_post_meta($subId, self::SUB_META_SCHEME, true)) : '';
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            if ($scheme !== '' && !isset($item['scheme'])) {
                $item['scheme'] = $scheme;
            }

            $row = $this->normalize_subscription_item($item);
            if ($row) {
                if (empty($row['taxes']['total'])) {
                    $row['taxes'] = $this->get_subscription_item_taxes($row, $customer);
                }
                $normalized[] = $row;
            }
        }

        if (empty($normalized)) {
            $this->persist_subscription_order_type_line_items($subId, []);
            delete_post_meta($subId, self::SUB_META_ITEMS);
            delete_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID);
            delete_post_meta($subId, self::SUB_META_BASE_VARIATION_ID);
            delete_post_meta($subId, self::SUB_META_QTY);
            delete_post_meta($subId, self::SUB_META_UNIT_PRICE);
            return;
        }

        $this->persist_subscription_order_type_line_items($subId, $normalized);

        update_post_meta($subId, self::SUB_META_ITEMS, $normalized);

        $first = $normalized[0];
        update_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID, (string) ($first['base_product_id'] ?? 0));
        update_post_meta($subId, self::SUB_META_BASE_VARIATION_ID, (string) ($first['base_variation_id'] ?? 0));
        update_post_meta($subId, self::SUB_META_QTY, (string) ($first['qty'] ?? 1));
        update_post_meta($subId, self::SUB_META_UNIT_PRICE, (string) wc_format_decimal((string) $this->get_subscription_item_storage_unit_price($first), wc_get_price_decimals()));
    }

    private function sync_existing_subscription_prices_for_product(int $productId, int $variationId = 0, $overrideProduct = null): void {
        if ($productId <= 0 || !$this->recurring_enabled() || $this->get_engine() !== 'manual' || !function_exists('wc_get_orders')) {
            return;
        }

        $subscriptionIds = wc_get_orders([
            'type' => $this->get_subscription_order_type()->get_type(),
            'limit' => -1,
            'return' => 'ids',
            'status' => array_keys(wc_get_order_statuses()),
        ]);

        if (!is_array($subscriptionIds) || empty($subscriptionIds)) {
            return;
        }

        foreach ($subscriptionIds as $subscriptionId) {
            $subId = (int) $subscriptionId;
            if ($subId <= 0) {
                continue;
            }

            $scheme = sanitize_key((string) get_post_meta($subId, self::SUB_META_SCHEME, true));
            if ($scheme === '') {
                continue;
            }

            $items = $this->get_subscription_items($subId);
            if (empty($items)) {
                continue;
            }

            $didChange = false;

            foreach ($items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $matchesProduct = $variationId > 0
                    ? (int) ($item['base_variation_id'] ?? 0) === $variationId
                    : (int) ($item['base_product_id'] ?? 0) === $productId;

                if (!$matchesProduct || !$this->is_subscription_item_catalog_linked($item)) {
                    continue;
                }

                $targetUnitPrice = $this->get_subscription_item_catalog_target_price(
                    $item,
                    $scheme,
                    $overrideProduct,
                    $variationId > 0 ? $variationId : $productId
                );

                if ($targetUnitPrice === null) {
                    continue;
                }

                $currentStorageUnitPrice = $this->get_subscription_item_storage_unit_price($item);
                $currentCatalogUnitPrice = $this->get_subscription_item_catalog_unit_price($item);

                if (abs($currentStorageUnitPrice - $targetUnitPrice) < 0.0001 && abs($currentCatalogUnitPrice - $targetUnitPrice) < 0.0001) {
                    continue;
                }

                $item['unit_price'] = $targetUnitPrice;
                $item['catalog_unit_price'] = $targetUnitPrice;
                $item['price_includes_tax'] = 0;
                $item['taxes'] = [];
                $items[$index] = $item;
                $didChange = true;
            }

            if (!$didChange) {
                continue;
            }

            $this->persist_subscription_items($subId, $items);
            $this->sync_subscription_order_type_record($subId);
        }
    }

    private function recalculate_subscription_item_taxes(int $subId, array $items, $fallbackOrder = null): array {
        $customer = $subId > 0 ? $this->get_subscription_tax_customer($subId, $fallbackOrder) : null;
        $recalculated = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $row = $this->normalize_subscription_item($item);
            if (!$row) {
                continue;
            }

            $row['unit_price'] = $this->get_subscription_item_storage_unit_price($row);
            $row['price_includes_tax'] = 0;
            $row['taxes'] = $this->get_subscription_item_taxes(array_merge($row, ['taxes' => []]), $customer);
            $recalculated[] = $row;
        }

        return $recalculated;
    }

    private function normalize_subscription_shipping_line(array $line): ?array {
        $methodId = sanitize_text_field((string) ($line['method_id'] ?? ''));
        $methodTitle = sanitize_text_field((string) ($line['method_title'] ?? ''));
        $instanceId = (int) ($line['instance_id'] ?? 0);
        $rateKey = sanitize_text_field((string) ($line['rate_key'] ?? ''));
        $total = isset($line['total']) ? (float) wc_format_decimal((string) $line['total']) : 0.0;
        $tax = isset($line['tax']) ? (float) wc_format_decimal((string) $line['tax']) : 0.0;
        $taxes = isset($line['taxes']) && is_array($line['taxes']) ? $line['taxes'] : [];

        if ($methodId === '' && $methodTitle === '') {
            return null;
        }

        if ($tax > 0 && empty($taxes)) {
            $taxes = [
                'total' => [
                    'manual' => wc_format_decimal((string) $tax, wc_get_price_decimals()),
                ],
            ];
        }

        $normalizedTaxes = [];
        foreach ($taxes as $taxGroupKey => $taxGroupValues) {
            if (!is_array($taxGroupValues)) {
                continue;
            }

            $groupKey = sanitize_key((string) $taxGroupKey);
            if ($groupKey === '') {
                continue;
            }

            $normalizedTaxes[$groupKey] = [];
            foreach ($taxGroupValues as $taxRateId => $taxAmount) {
                $rateKey = is_numeric($taxRateId) ? (string) absint((string) $taxRateId) : sanitize_key((string) $taxRateId);
                if ($rateKey === '') {
                    continue;
                }
                $normalizedTaxes[$groupKey][$rateKey] = wc_format_decimal((string) $taxAmount, wc_get_price_decimals());
            }
        }

        return [
            'rate_key' => $rateKey !== '' ? $rateKey : ($methodId !== '' ? $methodId . ':' . max(0, $instanceId) : ''),
            'method_id' => $methodId,
            'method_title' => $methodTitle,
            'instance_id' => max(0, $instanceId),
            'total' => $total,
            'taxes' => $normalizedTaxes,
        ];
    }

    private function get_subscription_shipping_lines(int $subId): array {
        $subscriptionOrder = $this->get_subscription_order_object($subId);
        if ($subscriptionOrder) {
            return $this->extract_subscription_shipping_lines($subscriptionOrder);
        }

        $stored = get_post_meta($subId, self::SUB_META_SHIPPING_LINES, true);
        $lines = [];
        $seen = [];

        if (!is_array($stored)) {
            return $lines;
        }

        foreach ($stored as $row) {
            if (!is_array($row)) {
                continue;
            }

            $line = $this->normalize_subscription_shipping_line($row);
            if ($line) {
                $hash = $this->get_subscription_shipping_line_hash($line);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function normalize_subscription_fee_line(array $line): ?array {
        $name = sanitize_text_field((string) ($line['name'] ?? ''));
        $total = isset($line['total']) ? (float) wc_format_decimal((string) $line['total']) : 0.0;
        $tax = isset($line['tax']) ? (float) wc_format_decimal((string) $line['tax']) : 0.0;
        $taxes = isset($line['taxes']) && is_array($line['taxes']) ? $line['taxes'] : [];

        if ($name === '' && $total === 0.0 && $tax === 0.0 && empty($taxes)) {
            return null;
        }

        if ($tax > 0 && empty($taxes)) {
            $taxes = [
                'total' => [
                    'manual' => wc_format_decimal((string) $tax, wc_get_price_decimals()),
                ],
            ];
        }

        $normalizedTaxes = ['total' => []];
        if (isset($taxes['total']) && is_array($taxes['total'])) {
            foreach ($taxes['total'] as $taxKey => $taxAmount) {
                $normalizedKey = is_numeric($taxKey) ? (string) absint((string) $taxKey) : sanitize_key((string) $taxKey);
                if ($normalizedKey === '') {
                    $normalizedKey = 'manual';
                }
                $normalizedTaxes['total'][$normalizedKey] = wc_format_decimal((string) $taxAmount, wc_get_price_decimals());
            }
        }

        return [
            'name' => $name !== '' ? $name : __('Kosten', 'hb-ucs'),
            'total' => $total,
            'taxes' => $normalizedTaxes,
        ];
    }

    private function get_subscription_fee_lines(int $subId): array {
        $subscriptionOrder = $this->get_subscription_order_object($subId);
        if ($subscriptionOrder) {
            return $this->extract_subscription_fee_lines($subscriptionOrder);
        }

        $stored = get_post_meta($subId, self::SUB_META_FEE_LINES, true);
        $lines = [];
        $seen = [];

        if (!is_array($stored)) {
            return $lines;
        }

        foreach ($stored as $row) {
            if (!is_array($row)) {
                continue;
            }

            $line = $this->normalize_subscription_fee_line($row);
            if ($line) {
                $hash = $this->get_subscription_fee_line_hash($line);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function persist_subscription_fee_lines(int $subId, array $lines): void {
        $normalized = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $row = $this->normalize_subscription_fee_line($line);
            if ($row) {
                $hash = $this->get_subscription_fee_line_hash($row);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $normalized[] = $row;
            }
        }

        $this->persist_subscription_order_type_fee_lines($subId, $normalized);

        update_post_meta($subId, self::SUB_META_FEE_LINES, $normalized);
    }

    private function get_subscription_fee_line_hash(array $line): string {
        return wp_json_encode([
            'name' => (string) ($line['name'] ?? ''),
            'total' => wc_format_decimal((string) ($line['total'] ?? 0.0), wc_get_price_decimals()),
            'taxes' => isset($line['taxes']) && is_array($line['taxes']) ? $line['taxes'] : [],
        ]);
    }

    private function extract_subscription_fee_lines($order): array {
        $lines = [];
        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            return $lines;
        }

        foreach ($order->get_items('fee') as $feeItem) {
            if (!$feeItem || !is_object($feeItem)) {
                continue;
            }

            $line = $this->normalize_subscription_fee_line([
                'name' => method_exists($feeItem, 'get_name') ? (string) $feeItem->get_name() : '',
                'total' => method_exists($feeItem, 'get_total') ? (float) $feeItem->get_total() : 0.0,
                'taxes' => method_exists($feeItem, 'get_taxes') ? (array) $feeItem->get_taxes() : [],
            ]);

            if ($line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function get_effective_subscription_shipping_lines(int $subId, $fallbackOrder = null, bool $persistRecovered = true): array {
        $subscriptionOrder = $this->get_subscription_order_object($subId);
        if ($subscriptionOrder) {
            return $this->extract_subscription_shipping_lines($subscriptionOrder);
        }

        $lines = $this->get_subscription_shipping_lines($subId);
        if (!empty($lines) || metadata_exists('post', $subId, self::SUB_META_SHIPPING_LINES)) {
            return $lines;
        }

        if (!$fallbackOrder) {
            $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
            $fallbackOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        }

        return $fallbackOrder ? $this->extract_subscription_shipping_lines($fallbackOrder) : [];
    }

    private function get_effective_subscription_fee_lines(int $subId, $fallbackOrder = null, bool $persistRecovered = true): array {
        $subscriptionOrder = $this->get_subscription_order_object($subId);
        if ($subscriptionOrder) {
            return $this->extract_subscription_fee_lines($subscriptionOrder);
        }

        $lines = $this->get_subscription_fee_lines($subId);
        if (!empty($lines) || metadata_exists('post', $subId, self::SUB_META_FEE_LINES)) {
            return $lines;
        }

        if (!$fallbackOrder) {
            $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
            $fallbackOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        }

        return $fallbackOrder ? $this->extract_subscription_fee_lines($fallbackOrder) : [];
    }

    private function persist_subscription_shipping_lines(int $subId, array $lines): void {
        $normalized = [];
        $seen = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $row = $this->normalize_subscription_shipping_line($line);
            if ($row) {
                $hash = $this->get_subscription_shipping_line_hash($row);
                if (isset($seen[$hash])) {
                    continue;
                }

                $seen[$hash] = true;
                $normalized[] = $row;
            }
        }

        $this->persist_subscription_order_type_shipping_lines($subId, $normalized);

        update_post_meta($subId, self::SUB_META_SHIPPING_LINES, $normalized);
    }

    private function persist_subscription_order_type_line_items(int $subId, array $items): void {
        $order = $this->get_subscription_order_object($subId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_items') || !method_exists($order, 'remove_item') || !method_exists($order, 'add_item')) {
            return;
        }

        foreach ((array) $order->get_items('line_item') as $itemId => $existingItem) {
            $order->remove_item($itemId);
        }

        $customer = $this->get_subscription_tax_customer($subId, $order);
        $scheme = method_exists($order, 'get_meta') ? sanitize_key((string) $order->get_meta(self::SUB_META_SCHEME, true)) : '';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $baseProductId = (int) ($item['base_product_id'] ?? 0);
            $baseVariationId = (int) ($item['base_variation_id'] ?? 0);
            $targetProductId = $baseVariationId > 0 ? $baseVariationId : $baseProductId;
            $product = $targetProductId > 0 && function_exists('wc_get_product') ? wc_get_product($targetProductId) : false;
            $qty = max(1, (int) ($item['qty'] ?? 1));
            $lineTotals = $this->get_subscription_item_order_totals($item, $customer);
            $itemTaxes = $this->normalize_subscription_item_taxes($item['taxes'] ?? []);
            $lineTax = $this->round_subscription_order_item_amount((float) array_sum($this->normalize_subscription_tax_amounts($itemTaxes['total'] ?? [])));
            $subtotalTax = $this->round_subscription_order_item_amount((float) array_sum($this->normalize_subscription_tax_amounts($itemTaxes['subtotal'] ?? [])));
            $lineSubtotal = $this->round_subscription_order_item_amount((float) ($lineTotals['line_subtotal'] ?? 0.0));
            $lineTotalExcludingTax = $this->round_subscription_order_item_amount(max(0.0, ((float) ($lineTotals['line_total'] ?? 0.0)) - (float) $lineTax));

            $orderItem = new \WC_Order_Item_Product();
            if ($product && is_object($product) && method_exists($orderItem, 'set_product')) {
                $orderItem->set_product($product);
            } else {
                $orderItem->set_product_id($baseProductId);
                $orderItem->set_variation_id($baseVariationId);
            }

            $selectedAttributes = $this->get_subscription_item_selected_attributes($item);
            $attributeSnapshot = $this->get_subscription_item_attribute_snapshot($item);
            if ($baseVariationId > 0 && method_exists($orderItem, 'set_variation_id')) {
                $orderItem->set_variation_id($baseVariationId);
            }
            $orderItem->set_quantity($qty);
            $orderItem->set_subtotal($lineSubtotal);
            $orderItem->set_total($lineTotalExcludingTax);
            if (method_exists($orderItem, 'set_subtotal_tax')) {
                $orderItem->set_subtotal_tax($subtotalTax);
            }
            if (method_exists($orderItem, 'set_total_tax')) {
                $orderItem->set_total_tax($lineTax);
            }
            if (method_exists($orderItem, 'set_taxes')) {
                $orderItem->set_taxes($itemTaxes);
            }

            $orderItem->add_meta_data('_hb_ucs_subscription_base_product_id', $baseProductId, true);
            if ($baseVariationId > 0) {
                $orderItem->add_meta_data('_hb_ucs_subscription_base_variation_id', $baseVariationId, true);
            }
            if ((int) ($item['source_order_item_id'] ?? 0) > 0) {
                $orderItem->add_meta_data(self::ORDER_ITEM_META_SOURCE_ORDER_ITEM_ID, (int) $item['source_order_item_id'], true);
            }
            $orderItem->add_meta_data(self::ORDER_ITEM_META_CATALOG_UNIT_PRICE, $this->get_subscription_item_catalog_unit_price($item), true);
            if ($scheme !== '') {
                $orderItem->add_meta_data('_hb_ucs_subscription_scheme', $scheme, true);
            }

            if (!empty($selectedAttributes)) {
                $orderItem->add_meta_data(self::ORDER_ITEM_META_SELECTED_ATTRIBUTES, wp_json_encode($selectedAttributes), true);
            }
            if (!empty($attributeSnapshot)) {
                $orderItem->add_meta_data(self::ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT, wp_json_encode($attributeSnapshot), true);
            }

            foreach ($this->get_subscription_item_effective_display_meta($subId, $item) as $displayMetaRow) {
                $label = (string) ($displayMetaRow['label'] ?? '');
                $value = (string) ($displayMetaRow['value'] ?? '');
                if ($label === '' || $value === '') {
                    continue;
                }

                $orderItem->add_meta_data($label, $value, true);
            }

            $order->add_item($orderItem);
        }

        $this->persist_subscription_order_type_totals($order);
    }

    private function persist_subscription_order_type_fee_lines(int $subId, array $lines): void {
        $order = $this->get_subscription_order_object($subId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_items') || !method_exists($order, 'remove_item') || !method_exists($order, 'add_item')) {
            return;
        }

        foreach ((array) $order->get_items('fee') as $itemId => $existingItem) {
            $order->remove_item($itemId);
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lineTotals = $this->get_subscription_fee_line_totals($line);
            $feeItem = new \WC_Order_Item_Fee();
            $feeItem->set_name((string) ($line['name'] ?? __('Kosten', 'hb-ucs')));
            $feeItem->set_total((float) ($lineTotals['line_subtotal'] ?? 0.0));
            if (method_exists($feeItem, 'set_taxes')) {
                $feeItem->set_taxes(isset($line['taxes']) && is_array($line['taxes']) ? (array) $line['taxes'] : []);
            }
            $order->add_item($feeItem);
        }

        $this->persist_subscription_order_type_totals($order);
    }

    private function persist_subscription_order_type_shipping_lines(int $subId, array $lines): void {
        $order = $this->get_subscription_order_object($subId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_items') || !method_exists($order, 'remove_item') || !method_exists($order, 'add_item')) {
            return;
        }

        foreach ((array) $order->get_items('shipping') as $itemId => $existingItem) {
            $order->remove_item($itemId);
        }

        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $lineTotals = $this->get_subscription_shipping_line_totals($line);
            $shippingItem = new \WC_Order_Item_Shipping();
            $shippingItem->set_method_title((string) ($line['method_title'] ?? 'Shipping'));
            $shippingItem->set_method_id((string) ($line['method_id'] ?? ''));
            $shippingItem->set_instance_id((int) ($line['instance_id'] ?? 0));
            $shippingItem->set_total((float) ($lineTotals['line_subtotal'] ?? 0.0));
            if (!empty($line['rate_key'])) {
                $shippingItem->add_meta_data('_hb_ucs_shipping_rate_key', sanitize_text_field((string) $line['rate_key']), true);
            }
            if (method_exists($shippingItem, 'set_taxes')) {
                $shippingItem->set_taxes(isset($line['taxes']) && is_array($line['taxes']) ? (array) $line['taxes'] : []);
            }
            $order->add_item($shippingItem);
        }

        $this->persist_subscription_order_type_totals($order);
    }

    private function persist_subscription_order_type_totals($order): void {
        if (!$order || !is_object($order)) {
            return;
        }

        if (method_exists($order, 'calculate_totals')) {
            $order->calculate_totals(false);
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    private function get_subscription_shipping_line_hash(array $line): string {
        return wp_json_encode([
            'rate_key' => (string) ($line['rate_key'] ?? ''),
            'method_id' => (string) ($line['method_id'] ?? ''),
            'method_title' => (string) ($line['method_title'] ?? ''),
            'instance_id' => (int) ($line['instance_id'] ?? 0),
            'total' => wc_format_decimal((string) ($line['total'] ?? 0.0), wc_get_price_decimals()),
            'taxes' => isset($line['taxes']) && is_array($line['taxes']) ? $line['taxes'] : [],
        ]);
    }

    private function get_subscription_shipping_displayed_subtotal(int $subId, array $items, $fallbackOrder = null): float {
        $displayIncludingTax = function_exists('wc_tax_enabled')
            && wc_tax_enabled()
            && get_option('woocommerce_tax_display_cart', 'excl') === 'incl';
        $customer = $this->get_subscription_tax_customer($subId, $fallbackOrder);
        $subtotal = 0.0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty = max(1, (int) ($item['qty'] ?? 1));
            $subtotal += $this->get_subscription_item_display_amount($item, $qty, $displayIncludingTax, $customer);
        }

        return (float) wc_format_decimal((string) $subtotal);
    }

    private function is_subscription_free_shipping_method_available($shippingMethod, array $package, int $subId, array $items, $fallbackOrder = null): bool {
        if (!$shippingMethod || !is_object($shippingMethod)) {
            return false;
        }

        $requires = isset($shippingMethod->requires) ? (string) $shippingMethod->requires : '';
        $hasCoupon = false;
        $hasMetMinAmount = false;

        if (in_array($requires, ['coupon', 'either', 'both'], true)) {
            $hasCoupon = false;
        }

        if (in_array($requires, ['min_amount', 'either', 'both'], true)) {
            $subtotal = $this->get_subscription_shipping_displayed_subtotal($subId, $items, $fallbackOrder);
            $minAmount = isset($shippingMethod->min_amount)
                ? (float) wc_format_decimal((string) $shippingMethod->min_amount)
                : 0.0;

            if ($subtotal >= $minAmount) {
                $hasMetMinAmount = true;
            }
        }

        switch ($requires) {
            case 'min_amount':
                return $hasMetMinAmount;
            case 'coupon':
                return $hasCoupon;
            case 'both':
                return $hasCoupon && $hasMetMinAmount;
            case 'either':
                return $hasCoupon || $hasMetMinAmount;
            default:
                return true;
        }
    }

    private function get_available_subscription_shipping_rates(int $subId, array $items, $fallbackOrder = null): array {
        if ($subId <= 0 || empty($items) || !function_exists('WC') || !WC() || !WC()->shipping()) {
            return [];
        }

        $shipping = WC()->shipping();
        if (!method_exists($shipping, 'calculate_shipping') || !method_exists($shipping, 'get_packages')) {
            return [];
        }

        $destination = $this->get_subscription_address_snapshot($subId, 'shipping', (int) get_post_meta($subId, self::SUB_META_USER_ID, true), $fallbackOrder);
        if (empty($destination)) {
            $destination = $this->get_subscription_address_snapshot($subId, 'billing', (int) get_post_meta($subId, self::SUB_META_USER_ID, true), $fallbackOrder);
        }

        $contents = [];
        $contentsCost = 0.0;

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $productId = (int) (($item['base_variation_id'] ?? 0) ?: ($item['base_product_id'] ?? 0));
            $product = $productId > 0 ? wc_get_product($productId) : false;
            if (!$product || !is_object($product)) {
                continue;
            }

            $qty = max(1, (int) ($item['qty'] ?? 1));
            $lineSubtotal = (float) wc_format_decimal((string) ($this->get_subscription_item_storage_unit_price($item) * $qty));
            $lineTaxes = $this->normalize_subscription_tax_amounts($this->get_subscription_item_taxes($item, $this->get_subscription_tax_customer($subId, $fallbackOrder))['total']);
            $lineTaxTotal = (float) wc_format_decimal((string) array_sum($lineTaxes));
            $contentsCost += $lineSubtotal;

            $contents['hb_ucs_sub_' . $index] = [
                'key' => 'hb_ucs_sub_' . $index,
                'product_id' => (int) ($item['base_product_id'] ?? 0),
                'variation_id' => (int) ($item['base_variation_id'] ?? 0),
                'variation' => (array) ($item['attribute_snapshot'] ?? ($item['selected_attributes'] ?? [])),
                'quantity' => $qty,
                'data' => $product,
                'line_subtotal' => $lineSubtotal,
                'line_total' => $lineSubtotal,
                'line_tax' => $lineTaxTotal,
                'line_subtotal_tax' => $lineTaxTotal,
            ];
        }

        if (empty($contents)) {
            return [];
        }

        $package = [
            'contents' => $contents,
            'contents_cost' => (float) wc_format_decimal((string) $contentsCost),
            'applied_coupons' => [],
            'coupon_discount_amounts' => [],
            'user' => [
                'ID' => (int) get_post_meta($subId, self::SUB_META_USER_ID, true),
            ],
            'destination' => [
                'country' => (string) ($destination['country'] ?? ''),
                'state' => (string) ($destination['state'] ?? ''),
                'postcode' => (string) ($destination['postcode'] ?? ''),
                'city' => (string) ($destination['city'] ?? ''),
                'address' => (string) ($destination['address_1'] ?? ''),
                'address_1' => (string) ($destination['address_1'] ?? ''),
                'address_2' => (string) ($destination['address_2'] ?? ''),
            ],
            'cart_subtotal' => (float) wc_format_decimal((string) $contentsCost),
        ];

        $freeShippingAvailabilityFilter = function ($isAvailable, $filterPackage, $shippingMethod) use ($package, $subId, $items, $fallbackOrder) {
            if (!$shippingMethod || !is_object($shippingMethod) || (string) ($shippingMethod->id ?? '') !== 'free_shipping') {
                return $isAvailable;
            }

            $packageHash = md5(wp_json_encode([
                'destination' => (array) ($package['destination'] ?? []),
                'contents_cost' => (float) ($package['contents_cost'] ?? 0.0),
                'cart_subtotal' => (float) ($package['cart_subtotal'] ?? 0.0),
            ]));
            $filterPackageHash = md5(wp_json_encode([
                'destination' => (array) (($filterPackage['destination'] ?? [])),
                'contents_cost' => (float) (($filterPackage['contents_cost'] ?? 0.0)),
                'cart_subtotal' => (float) (($filterPackage['cart_subtotal'] ?? 0.0)),
            ]));

            if ($packageHash !== $filterPackageHash) {
                return $isAvailable;
            }

            return $this->is_subscription_free_shipping_method_available($shippingMethod, $filterPackage, $subId, $items, $fallbackOrder);
        };

        try {
            if (class_exists('HB\\UCS\\Modules\\B2B\\Support\\Context')) {
                \HB\UCS\Modules\B2B\Support\Context::set_forced_user_id((int) get_post_meta($subId, self::SUB_META_USER_ID, true));
            }

            if (method_exists($shipping, 'reset_shipping')) {
                $shipping->reset_shipping();
            }
            if (WC()->session && method_exists(WC()->session, 'set')) {
                WC()->session->set('shipping_for_package_0', null);
            }

            add_filter('woocommerce_shipping_free_shipping_is_available', $freeShippingAvailabilityFilter, 10, 3);

            $shipping->calculate_shipping([$package]);
            $packages = (array) $shipping->get_packages();
        } catch (\Throwable $e) {
            $packages = [];
        } finally {
            remove_filter('woocommerce_shipping_free_shipping_is_available', $freeShippingAvailabilityFilter, 10);
        }

        if (class_exists('HB\\UCS\\Modules\\B2B\\Support\\Context')) {
            \HB\UCS\Modules\B2B\Support\Context::set_forced_user_id(0);
        }

        $rates = isset($packages[0]['rates']) && is_array($packages[0]['rates']) ? $packages[0]['rates'] : [];
        if (empty($rates)) {
            return [];
        }

        $availableRates = [];
        foreach ($rates as $rate) {
            if (!$rate || !is_object($rate)) {
                continue;
            }

            $normalized = $this->normalize_subscription_shipping_line([
                'rate_key' => method_exists($rate, 'get_id') ? (string) $rate->get_id() : '',
                'method_id' => method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '',
                'method_title' => method_exists($rate, 'get_label') ? (string) $rate->get_label() : '',
                'instance_id' => method_exists($rate, 'get_instance_id') ? (int) $rate->get_instance_id() : 0,
                'total' => method_exists($rate, 'get_cost') ? (float) $rate->get_cost() : 0.0,
                'taxes' => method_exists($rate, 'get_taxes') ? ['total' => (array) $rate->get_taxes()] : [],
            ]);

            if ($normalized) {
                $availableRates[] = $normalized;
            }
        }

        return $availableRates;
    }

    private function resolve_subscription_shipping_rate_selection(array $availableRates, string $preferredRateKey = '', array $existingLines = []): ?array {
        if (!empty($preferredRateKey)) {
            foreach ($availableRates as $rate) {
                if ($this->get_subscription_shipping_rate_key((array) $rate) === $preferredRateKey) {
                    return is_array($rate) ? $rate : null;
                }
            }
        }

        foreach ($existingLines as $existingLine) {
            if (!is_array($existingLine)) {
                continue;
            }

            $existingMethodId = (string) ($existingLine['method_id'] ?? '');
            $existingInstanceId = (int) ($existingLine['instance_id'] ?? 0);
            foreach ($availableRates as $rate) {
                if (!is_array($rate)) {
                    continue;
                }

                if (
                    $existingMethodId === (string) ($rate['method_id'] ?? '')
                    && $existingInstanceId === (int) ($rate['instance_id'] ?? 0)
                ) {
                    return $rate;
                }
            }
        }

        foreach ($availableRates as $rate) {
            if (is_array($rate) && (string) ($rate['method_id'] ?? '') === 'free_shipping') {
                return $rate;
            }
        }

        $firstRate = reset($availableRates);

        return is_array($firstRate) ? $firstRate : null;
    }

    private function calculate_subscription_shipping_lines(int $subId, array $items, $fallbackOrder = null, string $preferredRateKey = ''): array {
        $availableRates = $this->get_available_subscription_shipping_rates($subId, $items, $fallbackOrder);
        if (empty($availableRates)) {
            return [];
        }

        $selectedRate = $this->resolve_subscription_shipping_rate_selection(
            $availableRates,
            $preferredRateKey,
            $this->get_subscription_shipping_lines($subId)
        );
        if (!$selectedRate) {
            return [];
        }

        return [$selectedRate];
    }

    private function extract_subscription_shipping_lines($order): array {
        $lines = [];
        if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
            return $lines;
        }

        foreach ($order->get_items('shipping') as $shipItem) {
            if (!$shipItem || !is_object($shipItem)) {
                continue;
            }

            $line = $this->normalize_subscription_shipping_line([
                'rate_key' => method_exists($shipItem, 'get_meta') ? (string) $shipItem->get_meta('_hb_ucs_shipping_rate_key', true) : '',
                'method_id' => method_exists($shipItem, 'get_method_id') ? (string) $shipItem->get_method_id() : '',
                'method_title' => method_exists($shipItem, 'get_method_title') ? (string) $shipItem->get_method_title() : '',
                'instance_id' => method_exists($shipItem, 'get_instance_id') ? (int) $shipItem->get_instance_id() : 0,
                'total' => method_exists($shipItem, 'get_total') ? (float) $shipItem->get_total() : 0.0,
                'taxes' => method_exists($shipItem, 'get_taxes') ? (array) $shipItem->get_taxes() : [],
            ]);

            if ($line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    private function build_subscription_item_from_selection(int $selectedId, string $scheme, int $qty = 1, ?float $fallbackUnitPrice = null, array $selectedAttributes = []): ?array {
        if ($selectedId <= 0 || !function_exists('wc_get_product')) {
            return null;
        }

        $selectedAttributes = $this->sanitize_selected_attributes_map($selectedAttributes);
        $attributeSnapshot = $selectedAttributes;
        $product = wc_get_product($selectedId);
        if (!$product || !is_object($product)) {
            return null;
        }

        $baseProductId = $selectedId;
        $baseVariationId = 0;
        if (method_exists($product, 'is_type') && $product->is_type('variation')) {
            $baseVariationId = $selectedId;
            $baseProductId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
            $variationAttributes = $this->get_selected_attributes_from_variation($product);
            $selectedAttributes = array_merge($variationAttributes, $selectedAttributes);

            if ($baseProductId > 0) {
                $parentProduct = wc_get_product($baseProductId);
                if ($parentProduct && is_object($parentProduct)) {
                    $selectedAttributes = $this->normalize_selected_attributes_for_product($parentProduct, $selectedAttributes);
                } else {
                    $selectedAttributes = $this->sanitize_selected_attributes_map($selectedAttributes);
                }
            } else {
                $selectedAttributes = $this->sanitize_selected_attributes_map($selectedAttributes);
            }
        } elseif (method_exists($product, 'is_type') && $product->is_type('variable')) {
            $selectedAttributes = $this->normalize_selected_attributes_for_product($product, $selectedAttributes);
            $baseVariationId = $this->resolve_variation_id_from_attributes($product, $selectedAttributes);
            $variationProduct = $baseVariationId > 0 ? wc_get_product($baseVariationId) : null;
            if ($variationProduct && is_object($variationProduct)) {
                $selectedAttributes = $this->normalize_selected_attributes_for_product(
                    $product,
                    array_merge($this->get_selected_attributes_from_variation($variationProduct), $selectedAttributes)
                );
            }
        }

        if ($baseProductId <= 0 || get_post_meta($baseProductId, self::META_ENABLED, true) !== 'yes') {
            return null;
        }

        $unitPrice = null;
        $pricingProductId = $baseVariationId > 0 ? $baseVariationId : $baseProductId;
        $pricingProduct = $pricingProductId > 0 ? wc_get_product($pricingProductId) : null;

        if (
            $pricingProduct
            && is_object($pricingProduct)
            && get_option('woocommerce_prices_include_tax', 'no') === 'yes'
            && $this->should_display_subscription_prices_including_tax()
        ) {
            $pricing = $this->get_product_page_subscription_pricing(
                $pricingProductId,
                $pricingProduct,
                $scheme,
                $baseVariationId > 0 ? $baseProductId : 0
            );

            if ($pricing && isset($pricing['final'])) {
                $unitPrice = $this->get_product_price_storage_amount($pricingProduct, (float) $pricing['final'], 1);
            }
        }

        if ($unitPrice === null) {
            if ($baseVariationId > 0) {
                $unitPrice = $this->get_variation_subscription_price($baseVariationId, $scheme);
            } else {
                $unitPrice = $this->get_base_subscription_price($baseProductId, $scheme);
            }
        }

        if ($unitPrice === null) {
            $unitPrice = $fallbackUnitPrice !== null ? (float) $fallbackUnitPrice : 0.0;
        }

        return $this->normalize_subscription_item([
            'base_product_id' => $baseProductId,
            'base_variation_id' => $baseVariationId,
            'qty' => max(1, $qty),
            'unit_price' => $unitPrice,
            'selected_attributes' => $selectedAttributes,
            'attribute_snapshot' => $attributeSnapshot,
        ]);
    }

    private function get_subscription_total_amount(int $subId, bool $includeTax = false): float {
        if ($includeTax) {
            $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
            $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
            $totals = $this->get_subscription_admin_totals(
                $this->get_subscription_items($subId),
                $this->get_effective_subscription_shipping_lines($subId, $parentOrder),
                $this->get_effective_subscription_fee_lines($subId, $parentOrder),
                $subId,
                $parentOrder
            );

            return (float) ($totals['total'] ?? 0.0);
        }

        $amount = 0.0;
        foreach ($this->get_subscription_items($subId) as $item) {
            $amount += ($this->get_subscription_item_storage_unit_price($item) * (int) ($item['qty'] ?? 1));
        }

        foreach ($this->get_effective_subscription_fee_lines($subId) as $feeLine) {
            if (!is_array($feeLine)) {
                continue;
            }

            $lineTotals = $this->get_subscription_fee_line_totals($feeLine);
            $amount += $includeTax ? (float) ($lineTotals['line_total'] ?? 0.0) : (float) ($lineTotals['line_subtotal'] ?? 0.0);
        }

        foreach ($this->get_effective_subscription_shipping_lines($subId) as $shippingLine) {
            if (!is_array($shippingLine)) {
                continue;
            }

            $lineTotals = $this->get_subscription_shipping_line_totals($shippingLine);
            $amount += $includeTax ? (float) ($lineTotals['line_total'] ?? 0.0) : (float) ($lineTotals['line_subtotal'] ?? 0.0);
        }

        return (float) wc_format_decimal((string) $amount);
    }

    private function get_account_subscription_price_breakdown(int $subId): array {
        $includeTax = $this->should_display_account_subscription_prices_including_tax();
        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        $customer = $this->get_subscription_tax_customer($subId, $parentOrder);
        $rows = [];

        foreach ($this->get_subscription_items($subId) as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty = max(1, (int) ($item['qty'] ?? 1));
            $label = $this->get_subscription_item_label($item);
            if ($qty > 1) {
                $label = sprintf(__('%1$s × %2$d', 'hb-ucs'), $label, $qty);
            }

            $rows[] = [
                'label' => $label,
                'amount' => $this->get_subscription_item_display_amount($item, $qty, $includeTax, $customer),
            ];
        }

        foreach ($this->get_effective_subscription_shipping_lines($subId, $parentOrder) as $shippingLine) {
            if (!is_array($shippingLine)) {
                continue;
            }

            $lineTotals = $this->get_subscription_shipping_line_totals($shippingLine);
            $methodTitle = trim((string) ($shippingLine['method_title'] ?? ''));

            $rows[] = [
                'label' => $methodTitle !== ''
                    ? sprintf(__('Verzendkosten — %s', 'hb-ucs'), $methodTitle)
                    : __('Verzendkosten', 'hb-ucs'),
                'amount' => (float) ($includeTax ? ($lineTotals['line_total'] ?? 0.0) : ($lineTotals['line_subtotal'] ?? 0.0)),
            ];
        }

        foreach ($this->get_effective_subscription_fee_lines($subId, $parentOrder) as $feeLine) {
            if (!is_array($feeLine)) {
                continue;
            }

            $lineTotals = $this->get_subscription_fee_line_totals($feeLine);
            $feeName = trim((string) ($feeLine['name'] ?? ''));

            $rows[] = [
                'label' => $feeName !== ''
                    ? sprintf(__('Toeslag — %s', 'hb-ucs'), $feeName)
                    : __('Toeslag', 'hb-ucs'),
                'amount' => (float) ($includeTax ? ($lineTotals['line_total'] ?? 0.0) : ($lineTotals['line_subtotal'] ?? 0.0)),
            ];
        }

        return [
            'rows' => $rows,
            'total' => $this->get_subscription_total_amount($subId, $includeTax),
            'tax_note' => $includeTax
                ? __('Alle bedragen zijn inclusief btw per levering.', 'hb-ucs')
                : __('Alle bedragen zijn exclusief btw per levering.', 'hb-ucs'),
        ];
    }

    private function format_account_subscription_shipping_rate_label(array $rate): string {
        $label = trim((string) ($rate['method_title'] ?? ''));
        if ($label === '') {
            $label = __('Verzendmethode', 'hb-ucs');
        }

        $lineTotals = $this->get_subscription_shipping_line_totals($rate);
        $amount = $this->should_display_account_subscription_prices_including_tax()
            ? (float) ($lineTotals['line_total'] ?? 0.0)
            : (float) ($lineTotals['line_subtotal'] ?? 0.0);

        return sprintf(
            __('%1$s — %2$s', 'hb-ucs'),
            $label,
            wp_strip_all_tags($this->format_subscription_frontend_price($amount))
        );
    }

    private function get_subscription_shipping_rate_key(array $rate): string {
        $rateKey = sanitize_text_field((string) ($rate['rate_key'] ?? ''));
        if ($rateKey !== '') {
            return $rateKey;
        }

        $methodId = sanitize_text_field((string) ($rate['method_id'] ?? ''));
        $instanceId = (int) ($rate['instance_id'] ?? 0);

        if ($methodId === '') {
            return '';
        }

        return $methodId . ':' . $instanceId;
    }

    private function get_selected_subscription_shipping_rate_key(int $subId, array $availableRates, $fallbackOrder = null): string {
        if (empty($availableRates)) {
            return '';
        }

        $currentLines = $this->get_effective_subscription_shipping_lines($subId, $fallbackOrder);
        foreach ($currentLines as $line) {
            if (!is_array($line)) {
                continue;
            }

            $currentMethodId = (string) ($line['method_id'] ?? '');
            $currentInstanceId = (int) ($line['instance_id'] ?? 0);
            foreach ($availableRates as $rate) {
                if (!is_array($rate)) {
                    continue;
                }

                if (
                    $currentMethodId === (string) ($rate['method_id'] ?? '')
                    && $currentInstanceId === (int) ($rate['instance_id'] ?? 0)
                ) {
                    return $this->get_subscription_shipping_rate_key($rate);
                }
            }
        }

        $selectedRate = $this->resolve_subscription_shipping_rate_selection($availableRates);

        return $selectedRate ? $this->get_subscription_shipping_rate_key($selectedRate) : '';
    }

    private function format_subscription_frontend_price(float $amount): string {
        return function_exists('wc_price') ? (string) wc_price($amount) : number_format($amount, 2, '.', '');
    }

    private function should_display_account_subscription_prices_including_tax(): bool {
        return $this->should_display_subscription_prices_including_tax();
    }

    private function should_display_subscription_prices_including_tax(): bool {
        if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
            return false;
        }

        return get_option('woocommerce_tax_display_shop', 'excl') === 'incl';
    }

    private function get_subscription_item_display_amount(array $item, int $qty = 1, bool $includeTax = true, $customer = null): float {
        $liveOrderTotals = $this->get_subscription_item_live_order_totals($item);
        if (!empty($liveOrderTotals)) {
            return (float) wc_format_decimal((string) ($includeTax
                ? ($liveOrderTotals['line_total'] ?? 0.0)
                : ($liveOrderTotals['line_subtotal'] ?? 0.0)));
        }

        $qty = max(1, $qty);
        $unitSubtotal = $this->get_subscription_item_storage_unit_price($item);
        if ($unitSubtotal <= 0) {
            return 0.0;
        }

        $lineSubtotal = (float) wc_format_decimal((string) ($unitSubtotal * $qty));
        if (!$includeTax) {
            return $lineSubtotal;
        }

        $taxBreakdown = $this->normalize_subscription_tax_amounts($this->get_subscription_item_taxes($item, $customer)['total']);
        $lineTax = (float) wc_format_decimal((string) array_sum($taxBreakdown));
        if ($lineTax > 0) {
            return (float) wc_format_decimal((string) ($lineSubtotal + $lineTax));
        }

        $productId = (int) ($item['base_variation_id'] ?? 0);
        if ($productId <= 0) {
            $productId = (int) ($item['base_product_id'] ?? 0);
        }

        $product = $productId > 0 ? wc_get_product($productId) : false;
        if ($product && is_object($product)) {
            return $this->get_product_price_display_amount($product, $unitSubtotal, $qty, $includeTax);
        }

        $unitPrice = (float) ($item['unit_price'] ?? 0.0);
        if (!empty($item['price_includes_tax']) && $unitPrice > 0) {
            return (float) wc_format_decimal((string) ($unitPrice * $qty));
        }

        return $lineSubtotal;
    }

    private function get_product_price_display_amount($product, float $price, int $qty = 1, bool $includeTax = true): float {
        $qty = max(1, $qty);
        $amount = $price * $qty;

        if (!$includeTax || !function_exists('wc_tax_enabled') || !wc_tax_enabled() || !function_exists('wc_get_price_including_tax')) {
            return (float) wc_format_decimal((string) $amount);
        }

        if (!$product || !is_object($product)) {
            return (float) wc_format_decimal((string) $amount);
        }

        return (float) wc_format_decimal((string) wc_get_price_including_tax($product, [
            'qty' => $qty,
            'price' => $price,
        ]));
    }

    private function get_product_price_storage_amount($product, float $displayPrice, int $qty = 1): float {
        $qty = max(1, $qty);
        $amount = $displayPrice * $qty;

        if (!function_exists('wc_tax_enabled') || !wc_tax_enabled() || !function_exists('wc_get_price_excluding_tax')) {
            return (float) wc_format_decimal((string) $amount);
        }

        if (!$product || !is_object($product)) {
            return (float) wc_format_decimal((string) $amount);
        }

        return (float) wc_format_decimal((string) wc_get_price_excluding_tax($product, [
            'qty' => $qty,
            'price' => $displayPrice,
        ]));
    }

    private function get_product_current_storage_price($product): ?float {
        if (!$product || !is_object($product) || !method_exists($product, 'get_price')) {
            return null;
        }

        $priceRaw = (string) $product->get_price();
        if ($priceRaw === '') {
            return null;
        }

        $price = (float) wc_format_decimal($priceRaw);
        if ($price <= 0) {
            return $price;
        }

        if (get_option('woocommerce_prices_include_tax', 'no') === 'yes') {
            return $this->get_product_price_storage_amount($product, $price, 1);
        }

        return $price;
    }

    private function get_subscription_item_storage_unit_price(array $item): float {
        $unitPrice = (float) ($item['unit_price'] ?? 0.0);
        if ($unitPrice <= 0) {
            return 0.0;
        }

        if (empty($item['price_includes_tax'])) {
            return (float) wc_format_decimal((string) $unitPrice);
        }

        $productId = (int) ($item['base_variation_id'] ?? 0);
        if ($productId <= 0) {
            $productId = (int) ($item['base_product_id'] ?? 0);
        }

        $product = $productId > 0 ? wc_get_product($productId) : false;

        return $this->get_product_price_storage_amount($product, $unitPrice, 1);
    }

    private function should_subscription_item_price_be_treated_as_including_tax(int $productId, float $unitPrice, int $qty, array $taxes): bool {
        if ($productId <= 0 || $unitPrice <= 0 || $qty <= 0) {
            return true;
        }

        if (!function_exists('wc_tax_enabled') || !wc_tax_enabled()) {
            return false;
        }

        $storedTaxTotal = (float) array_sum($this->normalize_subscription_tax_amounts($taxes['total'] ?? $taxes));
        if ($storedTaxTotal <= 0) {
            return true;
        }

        $combinedRate = 0.0;
        foreach (array_keys((array) ($taxes['total'] ?? $taxes)) as $rateId) {
            $rateId = absint((string) $rateId);
            if ($rateId <= 0 || !class_exists('WC_Tax') || !method_exists('WC_Tax', 'get_rate_percent')) {
                continue;
            }

            $percent = (string) \WC_Tax::get_rate_percent($rateId);
            $percent = (float) str_replace(',', '.', rtrim($percent, "% \t\n\r\0\x0B"));
            if ($percent > 0) {
                $combinedRate += ($percent / 100);
            }
        }

        if ($combinedRate <= 0) {
            return true;
        }

        $lineAmount = $unitPrice * $qty;
        $expectedTaxWhenExcluding = (float) wc_format_decimal((string) ($lineAmount * $combinedRate));
        $expectedTaxWhenIncluding = (float) wc_format_decimal((string) ($lineAmount - ($lineAmount / (1 + $combinedRate))));

        return abs($storedTaxTotal - $expectedTaxWhenIncluding) <= abs($storedTaxTotal - $expectedTaxWhenExcluding);
    }

    private function get_subscription_related_orders(int $subId, int $userId = 0): array {
        $rows = [];
        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        if ($parentOrderId > 0) {
            $parent = wc_get_order($parentOrderId);
            if ($parent && is_object($parent) && $this->order_belongs_to_user($parent, $userId)) {
                $rows[] = ['type' => __('Start', 'hb-ucs'), 'order' => $parent];
            }
        }

        $orders = function_exists('wc_get_orders') ? wc_get_orders([
            'limit' => 20,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::ORDER_META_SUBSCRIPTION_ID,
            'meta_value' => (string) $subId,
        ]) : [];

        if (is_array($orders)) {
            foreach ($orders as $order) {
                if (!$order || !is_object($order) || !$this->order_belongs_to_user($order, $userId)) {
                    continue;
                }
                $rows[] = ['type' => __('Renewal', 'hb-ucs'), 'order' => $order];
            }
        }

        return $rows;
    }

    private function order_belongs_to_user($order, int $userId): bool {
        if ($userId <= 0 || !$order || !is_object($order)) {
            return false;
        }

        $customerId = method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0;
        if ($customerId > 0) {
            return $customerId === $userId;
        }

        $orderId = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        $fallbackCustomerId = $orderId > 0 ? (int) get_post_meta($orderId, '_customer_user', true) : 0;
        if ($fallbackCustomerId > 0) {
            return $fallbackCustomerId === $userId;
        }

        $user = get_user_by('id', $userId);
        $userEmail = $user && is_object($user) ? strtolower(trim((string) $user->user_email)) : '';
        if ($userEmail === '') {
            return false;
        }

        $billingEmail = method_exists($order, 'get_billing_email') ? strtolower(trim((string) $order->get_billing_email())) : '';
        return $billingEmail !== '' && $billingEmail === $userEmail;
    }

    private function subscription_has_locked_orders(int $subId): bool {
        if ($subId <= 0 || !function_exists('wc_get_orders')) {
            return false;
        }

        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        if ($parentOrderId > 0) {
            $parentOrder = wc_get_order($parentOrderId);
            if ($parentOrder && is_object($parentOrder) && in_array((string) $parentOrder->get_status(), ['on-hold', 'processing'], true)) {
                return true;
            }
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'status' => ['on-hold', 'processing'],
            'meta_key' => self::ORDER_META_SUBSCRIPTION_ID,
            'meta_value' => (string) $subId,
        ]);

        return !empty($orders);
    }

    private function build_user_contact_snapshot(int $userId, string $type): array {
        $prefix = $type === 'shipping' ? 'shipping_' : 'billing_';
        $snapshot = [
            'first_name' => (string) get_user_meta($userId, $prefix . 'first_name', true),
            'last_name' => (string) get_user_meta($userId, $prefix . 'last_name', true),
            'company' => (string) get_user_meta($userId, $prefix . 'company', true),
            'address_1' => (string) get_user_meta($userId, $prefix . 'address_1', true),
            'address_2' => (string) get_user_meta($userId, $prefix . 'address_2', true),
            'postcode' => (string) get_user_meta($userId, $prefix . 'postcode', true),
            'city' => (string) get_user_meta($userId, $prefix . 'city', true),
            'state' => (string) get_user_meta($userId, $prefix . 'state', true),
            'country' => (string) get_user_meta($userId, $prefix . 'country', true),
        ];

        if ($type === 'billing') {
            $user = get_user_by('id', $userId);
            $snapshot['email'] = $user && is_object($user) ? (string) $user->user_email : '';
            $snapshot['phone'] = (string) get_user_meta($userId, 'billing_phone', true);
        }

        return $snapshot;
    }

    private function sync_user_subscriptions_contact_snapshots(int $userId): void {
        if ($userId <= 0) {
            return;
        }

        $billing = $this->build_user_contact_snapshot($userId, 'billing');
        $shipping = $this->build_user_contact_snapshot($userId, 'shipping');

        foreach ($this->get_user_subscription_ids($userId) as $subId) {
            $this->refresh_subscription_contact_snapshots_and_shipping($subId, $billing, $shipping);
        }
    }

    public function sync_subscriptions_from_account_details(int $userId): void {
        $this->sync_user_subscriptions_contact_snapshots((int) $userId);
    }

    public function sync_subscriptions_from_customer_address(int $userId, string $addressType): void {
        $this->sync_user_subscriptions_contact_snapshots((int) $userId);
    }

    private function get_subscription_address_snapshot(int $subId, string $type, int $userId = 0, $order = null): array {
        $metaKey = $type === 'shipping' ? self::SUB_META_SHIPPING : self::SUB_META_BILLING;
        $stored = get_post_meta($subId, $metaKey, true);
        if (is_array($stored) && !empty($stored)) {
            return $stored;
        }

        if ($order && is_object($order) && method_exists($order, 'get_address')) {
            $address = (array) $order->get_address($type);
            if (!empty($address)) {
                if ($type === 'billing') {
                    $address['email'] = method_exists($order, 'get_billing_email') ? (string) $order->get_billing_email() : '';
                    $address['phone'] = method_exists($order, 'get_billing_phone') ? (string) $order->get_billing_phone() : '';
                }
                return $address;
            }
        }

        return $userId > 0 ? $this->build_user_contact_snapshot($userId, $type) : [];
    }

    private function apply_subscription_snapshot_to_order($order, int $subId, int $userId = 0, $fallbackOrder = null): void {
        if (!$order || !is_object($order) || !method_exists($order, 'set_address')) {
            return;
        }

        $billing = $this->get_subscription_address_snapshot($subId, 'billing', $userId, $fallbackOrder);
        $shipping = $this->get_subscription_address_snapshot($subId, 'shipping', $userId, $fallbackOrder);

        if (!empty($billing)) {
            $order->set_address($billing, 'billing');
        }
        if (!empty($shipping)) {
            $order->set_address($shipping, 'shipping');
        }
    }

    private function hydrate_subscription_order_customer_data(int $subId, int $userId = 0, $fallbackOrder = null): void {
        if ($subId <= 0 || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($subId);
        if (!$order || !is_object($order)) {
            return;
        }

        if ($userId > 0 && method_exists($order, 'set_customer_id')) {
            $order->set_customer_id($userId);
        }

        $this->apply_subscription_snapshot_to_order($order, $subId, $userId, $fallbackOrder);

        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    private function get_current_mollie_payment_mode(): string {
        $testMode = get_option('mollie-payments-for-woocommerce_test_mode_enabled');
        $isTest = ((string) $testMode) === 'yes' || ((string) $testMode) === '1';

        return $isTest ? 'test' : 'live';
    }

    private function extract_mollie_context_from_order($order, bool $resolveMandate = true): array {
        $context = [
            'customerId' => '',
            'mandateId' => '',
            'paymentId' => '',
            'paymentMode' => '',
        ];

        if (!$order || !is_object($order) || !method_exists($order, 'get_meta')) {
            return $context;
        }

        $context['customerId'] = (string) $order->get_meta('_mollie_customer_id', true);
        $context['mandateId'] = (string) $order->get_meta('_mollie_mandate_id', true);
        $context['paymentId'] = (string) $order->get_meta('_mollie_payment_id', true);
        $context['paymentMode'] = (string) $order->get_meta('_mollie_payment_mode', true);

        if ($resolveMandate && ($context['customerId'] === '' || $context['mandateId'] === '') && $context['paymentId'] !== '') {
            $cm = $this->mollie_get_customer_and_mandate($context['paymentId']);
            if ($context['customerId'] === '' && $cm['customerId'] !== '') {
                $context['customerId'] = $cm['customerId'];
            }
            if ($context['mandateId'] === '' && $cm['mandateId'] !== '') {
                $context['mandateId'] = $cm['mandateId'];
            }
        }

        if ($context['paymentMode'] === '' && $context['paymentId'] !== '') {
            $context['paymentMode'] = $this->get_current_mollie_payment_mode();
        }

        return $context;
    }

    private function hydrate_subscription_order_payment_data(int $subId, string $paymentMethod = '', string $paymentMethodTitle = '', array $mollie = [], $fallbackOrder = null): bool {
        if ($subId <= 0 || !function_exists('wc_get_order')) {
            return false;
        }

        $order = wc_get_order($subId);
        if (!$order || !is_object($order)) {
            return false;
        }

        if (($paymentMethod === '' || $paymentMethodTitle === '') && $fallbackOrder && is_object($fallbackOrder)) {
            $payment = $this->get_order_payment_method_data($fallbackOrder);
            if ($paymentMethod === '') {
                $paymentMethod = (string) ($payment['method'] ?? '');
            }
            if ($paymentMethodTitle === '') {
                $paymentMethodTitle = (string) ($payment['title'] ?? '');
            }
        }

        $mollieContext = [
            'customerId' => (string) ($mollie['customerId'] ?? ''),
            'mandateId' => (string) ($mollie['mandateId'] ?? ''),
            'paymentId' => (string) ($mollie['paymentId'] ?? ''),
            'paymentMode' => (string) ($mollie['paymentMode'] ?? ''),
        ];

        if ($fallbackOrder && is_object($fallbackOrder)) {
            $fallbackContext = $this->extract_mollie_context_from_order($fallbackOrder);
            foreach ($fallbackContext as $key => $value) {
                if ($mollieContext[$key] === '' && $value !== '') {
                    $mollieContext[$key] = $value;
                }
            }
        }

        $changed = false;

        if ($paymentMethod !== '' && method_exists($order, 'set_payment_method')) {
            $currentPaymentMethod = method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '';
            if ($currentPaymentMethod !== $paymentMethod) {
                $changed = true;
            }
            $order->set_payment_method($paymentMethod);
        }
        if ($paymentMethodTitle !== '' && method_exists($order, 'set_payment_method_title')) {
            $currentPaymentMethodTitle = method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : '';
            if ($currentPaymentMethodTitle !== $paymentMethodTitle) {
                $changed = true;
            }
            $order->set_payment_method_title($paymentMethodTitle);
        }

        $metaMap = [
            '_mollie_customer_id' => $mollieContext['customerId'],
            '_mollie_mandate_id' => $mollieContext['mandateId'],
            '_mollie_payment_id' => $mollieContext['paymentId'],
            '_mollie_payment_mode' => $mollieContext['paymentMode'],
            self::ORDER_META_MOLLIE_PAYMENT_ID => $mollieContext['paymentId'],
        ];

        foreach ($metaMap as $metaKey => $metaValue) {
            if ($metaValue !== '') {
                $currentMetaValue = method_exists($order, 'get_meta') ? $order->get_meta($metaKey, true) : get_post_meta($subId, $metaKey, true);
                if (!$this->subscription_meta_values_equal($currentMetaValue, $metaValue)) {
                    $changed = true;
                }
                $order->update_meta_data($metaKey, $metaValue);
            } else {
                $hasMeta = method_exists($order, 'meta_exists')
                    ? $order->meta_exists($metaKey)
                    : metadata_exists('post', $subId, $metaKey);
                if ($hasMeta) {
                    $changed = true;
                }
                $order->delete_meta_data($metaKey);
            }
        }

        if (!$changed) {
            return false;
        }

        if (method_exists($order, 'save')) {
            $order->save();
        }

        return true;
    }

    private function apply_subscription_fee_lines_to_order($order, int $subId, $fallbackOrder = null): void {
        if (!$order || !is_object($order) || !method_exists($order, 'add_item')) {
            return;
        }

        foreach ($this->get_effective_subscription_fee_lines($subId, $fallbackOrder, false) as $feeLine) {
            if (!is_array($feeLine)) {
                continue;
            }

            $newFee = new \WC_Order_Item_Fee();
            $newFee->set_name((string) ($feeLine['name'] ?? __('Kosten', 'hb-ucs')));
            $newFee->set_total((float) ($feeLine['total'] ?? 0.0));
            if (!empty($feeLine['taxes']) && is_array($feeLine['taxes'])) {
                $newFee->set_taxes((array) $feeLine['taxes']);
            }
            $order->add_item($newFee);
        }
    }

    private function apply_subscription_shipping_lines_to_order($order, int $subId, $fallbackOrder = null): void {
        if (!$order || !is_object($order) || !method_exists($order, 'add_item')) {
            return;
        }

        $shippingLines = $this->get_effective_subscription_shipping_lines($subId, $fallbackOrder, false);

        foreach ($shippingLines as $shippingLine) {
            if (!is_array($shippingLine)) {
                continue;
            }

            $newShip = new \WC_Order_Item_Shipping();
            $newShip->set_method_title((string) ($shippingLine['method_title'] ?? 'Shipping'));
            $newShip->set_method_id((string) ($shippingLine['method_id'] ?? ''));
            $newShip->set_instance_id((int) ($shippingLine['instance_id'] ?? 0));
            $newShip->set_total((float) ($shippingLine['total'] ?? 0.0));
            if (!empty($shippingLine['taxes']) && is_array($shippingLine['taxes'])) {
                $newShip->set_taxes((array) $shippingLine['taxes']);
            }
            $order->add_item($newShip);
        }
    }

    public function render_subscription_items_metabox($post): void {
        $subId = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        if ($subId <= 0) {
            echo '<p>' . esc_html__('Onbekend abonnement.', 'hb-ucs') . '</p>';
            return;
        }

        $items = $this->get_subscription_items($subId);
        if (empty($items)) {
            $items[] = [
                'base_product_id' => 0,
                'base_variation_id' => 0,
                'qty' => 1,
                'unit_price' => 0.0,
                'selected_attributes' => [],
            ];
        }

        $scheme = (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        $feeLines = $this->get_effective_subscription_fee_lines($subId, $parentOrder);
        $shippingLines = $this->get_effective_subscription_shipping_lines($subId, $parentOrder);
        $taxColumns = $this->get_subscription_admin_tax_columns($subId, $items, $shippingLines, $feeLines, $parentOrder);
        $customer = $this->get_subscription_tax_customer($subId, $parentOrder);
        $totals = $this->get_subscription_admin_totals($items, $shippingLines, $feeLines, $subId, $parentOrder);
        $availableTaxRates = $this->get_available_subscription_admin_tax_rates();

        ob_start();
        $this->render_admin_subscription_item_row(999999, [
            'base_product_id' => 0,
            'base_variation_id' => 0,
            'qty' => 1,
            'unit_price' => 0.0,
            'selected_attributes' => [],
        ], $scheme, $taxColumns, $customer, true);
        $rowTemplate = ob_get_clean();
        echo '<script type="text/template" id="tmpl-hb-ucs-subscription-item-row">' . str_replace(['999999', '&lt;\/script&gt;'], ['{{index}}', '<\/script>'], $rowTemplate) . '</script>';
        ob_start();
        $this->render_admin_subscription_fee_row(999999, ['name' => __('Kosten', 'hb-ucs'), 'total' => 0.0, 'taxes' => []], $taxColumns, true);
        $feeTemplate = ob_get_clean();
        echo '<script type="text/template" id="tmpl-hb-ucs-subscription-fee-row">' . str_replace(['999999', '&lt;\/script&gt;'], ['{{index}}', '<\/script>'], $feeTemplate) . '</script>';
        ob_start();
        $this->render_admin_subscription_shipping_row(999999, ['method_title' => __('Verzending', 'hb-ucs'), 'method_id' => '', 'instance_id' => 0, 'total' => 0.0, 'taxes' => []], $taxColumns, true, []);
        $shippingTemplate = ob_get_clean();

        $this->render_admin_template('subscription-order-items.php', [
            'availableTaxRates' => $availableTaxRates,
            'customer' => $customer,
            'feeLines' => $feeLines,
            'feeTemplate' => str_replace(['999999', '&lt;\/script&gt;'], ['{{index}}', '<\/script>'], $feeTemplate),
            'items' => $items,
            'rowTemplate' => str_replace(['999999', '&lt;\/script&gt;'], ['{{index}}', '<\/script>'], $rowTemplate),
            'scheme' => $scheme,
            'shippingLines' => $shippingLines,
            'shippingTemplate' => str_replace(['999999', '&lt;\/script&gt;'], ['{{index}}', '<\/script>'], $shippingTemplate),
            'subId' => $subId,
            'taxColumns' => $taxColumns,
            'totals' => $totals,
        ]);
    }

    private function render_subscription_items_metabox_html(int $subId): string {
        $post = get_post($subId);
        if (!$post || !is_object($post) || (string) ($post->post_type ?? '') !== $this->get_subscription_order_type()->get_type()) {
            return '';
        }

        ob_start();
        $this->render_subscription_items_metabox($post);

        return (string) ob_get_clean();
    }

    private function handle_subscription_tax_rate_ajax_request(string $action): void {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Onvoldoende rechten.', 'hb-ucs')], 403);
        }

        check_ajax_referer('hb_ucs_subscription_admin', 'nonce');

        $subId = isset($_POST['sub_id']) ? (int) absint((string) wp_unslash($_POST['sub_id'])) : 0;
        $rateId = isset($_POST['rate_id']) ? (int) absint((string) wp_unslash($_POST['rate_id'])) : 0;
        $postType = $subId > 0 ? (string) get_post_type($subId) : '';

        if ($subId <= 0 || $rateId <= 0 || $postType !== $this->get_subscription_order_type()->get_type()) {
            wp_send_json_error(['message' => __('Ongeldige belastingregel.', 'hb-ucs')], 400);
        }

        $updated = false;
        if ($action === 'add') {
            $updated = $this->add_subscription_manual_tax_rate($subId, $rateId);
            if (!$updated && in_array($rateId, $this->get_subscription_manual_tax_rates($subId), true)) {
                wp_send_json_error(['message' => __('Deze belastingregel is al toegevoegd.', 'hb-ucs')], 400);
            }
        } elseif ($action === 'remove') {
            $updated = $this->remove_subscription_manual_tax_rate($subId, $rateId);
        }

        if (!$updated && $action === 'add') {
            wp_send_json_error(['message' => __('Belastingregel kon niet worden toegevoegd.', 'hb-ucs')], 400);
        }

        wp_send_json_success([
            'html' => $this->render_subscription_items_metabox_html($subId),
        ]);
    }

    public function handle_subscription_add_tax_rate_ajax(): void {
        $this->handle_subscription_tax_rate_ajax_request('add');
    }

    public function handle_subscription_remove_tax_rate_ajax(): void {
        $this->handle_subscription_tax_rate_ajax_request('remove');
    }

    public function handle_subscription_customer_details_ajax(): void {
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Onvoldoende rechten.', 'hb-ucs')], 403);
        }

        check_ajax_referer('hb_ucs_subscription_admin', 'nonce');

        $userId = isset($_POST['user_id']) ? (int) absint((string) wp_unslash($_POST['user_id'])) : 0;
        if ($userId <= 0) {
            wp_send_json_error(['message' => __('Geen klant geselecteerd.', 'hb-ucs')], 400);
        }

        wp_send_json_success([
            'billing' => $this->build_user_contact_snapshot($userId, 'billing'),
            'shipping' => $this->build_user_contact_snapshot($userId, 'shipping'),
        ]);
    }

    public function filter_subscription_columns(array $columns): array {
        $new = [];
        if (isset($columns['cb'])) {
            $new['cb'] = $columns['cb'];
        }
        $new['hb_ucs_status'] = __('Status', 'hb-ucs');
        $new['title'] = __('Abonnement', 'hb-ucs');
        $new['hb_ucs_items'] = __('Artikelen', 'hb-ucs');
        $new['hb_ucs_total'] = __('Totaal', 'hb-ucs');
        $new['hb_ucs_start_date'] = __('Startdatum', 'hb-ucs');
        $new['hb_ucs_trial_end'] = __('Trial eindigt', 'hb-ucs');
        $new['hb_ucs_next_payment'] = __('Volgende betaling', 'hb-ucs');
        $new['hb_ucs_last_order_date'] = __('Laatste bestelling', 'hb-ucs');
        $new['hb_ucs_end_date'] = __('Einddatum', 'hb-ucs');
        $new['hb_ucs_orders'] = __('Bestellingen', 'hb-ucs');
        return $new;
    }

    public function render_subscription_column(string $column, int $postId): void {
        if ($postId <= 0) {
            return;
        }
        if ($column === 'hb_ucs_status') {
            $status = (string) get_post_meta($postId, self::SUB_META_STATUS, true);
            echo $this->get_subscription_status_badge_html($status);
            return;
        }

        if ($column === 'hb_ucs_items') {
            $items = $this->get_subscription_items($postId);
            if (empty($items)) {
                echo '—';
                return;
            }

            $labels = [];
            foreach ($items as $item) {
                $label = $this->get_subscription_item_label($item);
                $qty = (int) ($item['qty'] ?? 1);
                $labels[] = $qty > 1 ? ($label . ' × ' . $qty) : $label;
            }

            echo esc_html(implode(', ', $labels));
            $count = count($items);
            if ($count > 1) {
                echo '<br/><small>' . esc_html(sprintf(_n('%d artikel', '%d artikelen', $count, 'hb-ucs'), $count)) . '</small>';
            }
            return;
        }

        if ($column === 'hb_ucs_total') {
            $scheme = (string) get_post_meta($postId, self::SUB_META_SCHEME, true);
            $interval = (int) get_post_meta($postId, self::SUB_META_INTERVAL, true);
            if ($interval <= 0) {
                $interval = 1;
            }
            $amount = $this->get_subscription_total_amount($postId, true);
            $price = function_exists('wc_price') ? wc_price($amount) : number_format($amount, 2, '.', '');

            $every = $this->get_subscription_schedule_label($scheme, $interval);

            echo wp_kses_post($price);
            echo '<br/><small>' . esc_html($every) . '</small>';
            echo '<br/><small>' . esc_html__('Via', 'hb-ucs') . ' ' . esc_html($this->get_subscription_payment_method_label($postId)) . '</small>';
            return;
        }

        if ($column === 'hb_ucs_start_date') {
            $ts = $this->get_subscription_start_timestamp($postId);
            echo esc_html($ts ? $this->format_admin_relative_date($ts) : '—');
            return;
        }

        if ($column === 'hb_ucs_trial_end') {
            $ts = (int) get_post_meta($postId, self::SUB_META_TRIAL_END, true);
            echo esc_html($ts > 0 ? $this->format_wp_date($ts) : '—');
            return;
        }

        if ($column === 'hb_ucs_next_payment') {
            $nextPayment = (int) get_post_meta($postId, self::SUB_META_NEXT_PAYMENT, true);
            if ($nextPayment > 0) {
                echo esc_html($this->format_wp_date($nextPayment));
                echo '<br/><small>' . esc_html($this->format_admin_relative_date($nextPayment)) . '</small>';
            } else {
                echo '—';
            }
            return;
        }

        if ($column === 'hb_ucs_last_order_date') {
            $lastId = (int) get_post_meta($postId, self::SUB_META_LAST_ORDER_ID, true);
            $lastTs = (int) get_post_meta($postId, self::SUB_META_LAST_ORDER_DATE, true);
            if ($lastId > 0) {
                $link = admin_url('post.php?post=' . $lastId . '&action=edit');
                $label = $lastTs > 0 ? $this->format_admin_relative_date($lastTs) : ('#' . $lastId);
                echo '<a href="' . esc_url($link) . '">' . esc_html($label) . '</a>';
            } else {
                echo esc_html($lastTs > 0 ? $this->format_admin_relative_date($lastTs) : '—');
            }
            return;
        }

        if ($column === 'hb_ucs_end_date') {
            $ts = (int) get_post_meta($postId, self::SUB_META_END_DATE, true);
            echo esc_html($ts > 0 ? $this->format_wp_date($ts) : '—');
            return;
        }

        if ($column === 'hb_ucs_orders') {
            $count = $this->count_orders_for_subscription($postId);
            echo esc_html($count > 0 ? (string) $count : '0');
            return;
        }
    }

    private function count_orders_for_subscription(int $subId): int {
        if ($subId <= 0) {
            return 0;
        }

        $total = 0;

        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        if ($parentOrderId > 0) {
            $total++;
        }

        if (!function_exists('wc_get_orders')) {
            return $total;
        }

        $orders = wc_get_orders([
            'limit' => 1,
            'paginate' => true,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => self::ORDER_META_SUBSCRIPTION_ID,
            'meta_value' => (string) $subId,
            'status' => array_keys(wc_get_order_statuses()),
        ]);

        if (is_object($orders) && isset($orders->total)) {
            $total += (int) $orders->total;
        } elseif (is_array($orders)) {
            $total += count($orders);
        }

        return $total;
    }

    private function is_renewal_order($order): bool {
        if (is_numeric($order)) {
            $order = wc_get_order((int) $order);
        }

        return $order && is_object($order) && (string) $order->get_meta(self::ORDER_META_RENEWAL, true) === '1';
    }

    private function is_subscription_order_type($order): bool {
        if (is_numeric($order)) {
            $order = wc_get_order((int) $order);
        }

        return $order
            && is_object($order)
            && method_exists($order, 'get_type')
            && (string) $order->get_type() === \HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType::TYPE;
    }

    private function should_disable_default_order_email($order): bool {
        return $this->is_renewal_order($order) || $this->is_subscription_order_type($order);
    }

    public function maybe_disable_default_order_email_for_subscription_context(bool $enabled, $order): bool {
        if (!$enabled) {
            return false;
        }

        return $this->should_disable_default_order_email($order) ? false : $enabled;
    }

    public function register_renewal_email_classes(array $emails): array {
        if (class_exists('HB\\UCS\\Modules\\Subscriptions\\Emails\\CustomerRenewalInvoiceEmail')) {
            $emails['HB_UCS_Customer_Renewal_Invoice_Email'] = new \HB\UCS\Modules\Subscriptions\Emails\CustomerRenewalInvoiceEmail();
        }
        if (class_exists('HB\\UCS\\Modules\\Subscriptions\\Emails\\CustomerOnHoldRenewalOrderEmail')) {
            $emails['HB_UCS_Customer_On_Hold_Renewal_Order_Email'] = new \HB\UCS\Modules\Subscriptions\Emails\CustomerOnHoldRenewalOrderEmail();
        }
        if (class_exists('HB\\UCS\\Modules\\Subscriptions\\Emails\\CustomerProcessingRenewalOrderEmail')) {
            $emails['HB_UCS_Customer_Processing_Renewal_Order_Email'] = new \HB\UCS\Modules\Subscriptions\Emails\CustomerProcessingRenewalOrderEmail();
        }
        if (class_exists('HB\\UCS\\Modules\\Subscriptions\\Emails\\CustomerCompletedRenewalOrderEmail')) {
            $emails['HB_UCS_Customer_Completed_Renewal_Order_Email'] = new \HB\UCS\Modules\Subscriptions\Emails\CustomerCompletedRenewalOrderEmail();
        }
        return $emails;
    }

    private function trigger_renewal_customer_email(string $emailId, int $orderId, int $subscriptionId = 0): void {
        if ($emailId === '' || $orderId <= 0 || !function_exists('WC')) {
            return;
        }

        $mailer = WC()->mailer();
        if (!$mailer || !is_object($mailer) || !method_exists($mailer, 'get_emails')) {
            return;
        }

        foreach ((array) $mailer->get_emails() as $email) {
            if (!$email || !is_object($email) || !method_exists($email, 'trigger')) {
                continue;
            }

            if (!isset($email->id) || (string) $email->id !== $emailId) {
                continue;
            }

            $email->trigger($orderId, $subscriptionId);
            return;
        }
    }

    public function maybe_trigger_on_hold_renewal_email(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!$this->is_renewal_order($order)) {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_META_RENEWAL_MODE, true) !== 'mandate') {
            return;
        }

        $this->trigger_renewal_customer_email('customer_on_hold_renewal_order', $orderId, (int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true));
    }

    public function maybe_trigger_processing_renewal_email(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!$this->is_renewal_order($order)) {
            return;
        }

        $this->trigger_renewal_customer_email('customer_processing_renewal_order', $orderId, (int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true));
    }

    public function maybe_trigger_completed_renewal_email(int $orderId): void {
        $order = wc_get_order($orderId);
        if (!$this->is_renewal_order($order)) {
            return;
        }

        $this->trigger_renewal_customer_email('customer_completed_renewal_order', $orderId, (int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true));
    }

    public function handle_run_renewals_now(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        check_admin_referer('hb_ucs_subs_run_now', 'hb_ucs_subs_run_now_nonce');

        // Best-effort: run the renewal processor once.
        $this->process_due_renewals();

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=hb-ucs-subscriptions');
        }
        $redirect = add_query_arg('hb_ucs_subs_ran', '1', $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_create_demo_subscription(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        check_admin_referer('hb_ucs_subs_create_demo', 'hb_ucs_subs_create_demo_nonce');

        if (!function_exists('wc_get_orders')) {
            wp_die(esc_html__('WooCommerce is vereist.', 'hb-ucs'));
        }

        $orders = wc_get_orders([
            'limit' => 25,
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => array_keys(wc_get_order_statuses()),
        ]);

        $sourceOrder = null;
        $sourceItem = null;
        foreach ($orders as $order) {
            if (!$order || !is_object($order) || !method_exists($order, 'get_items')) {
                continue;
            }
            foreach ($order->get_items('line_item') as $item) {
                if (!is_object($item) || !method_exists($item, 'get_meta')) {
                    continue;
                }
                $scheme = (string) $item->get_meta('_hb_ucs_subscription_scheme', true);
                if ($scheme !== '' && $scheme !== '0') {
                    $sourceOrder = $order;
                    $sourceItem = $item;
                    break 2;
                }
            }
        }

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('admin.php?page=hb-ucs-subscriptions');
        }

        if (!$sourceOrder || !$sourceItem) {
            $redirect = add_query_arg('hb_ucs_subs_demo', 'not_found', $redirect);
            wp_safe_redirect($redirect);
            exit;
        }

        $orderId = (int) $sourceOrder->get_id();
        $userId = (int) $sourceOrder->get_user_id();
        if ($userId <= 0) {
            // Best-effort fallback to current admin.
            $userId = (int) get_current_user_id();
        }

        $schedule = $this->resolve_subscription_schedule((string) $sourceItem->get_meta('_hb_ucs_subscription_scheme', true));
        $scheme = (string) $schedule['scheme'];
        $interval = (int) $schedule['interval'];
        $period = (string) $schedule['period'];

        $productId = 0;
        $variationId = 0;
        if (method_exists($sourceItem, 'get_variation_id')) {
            $variationId = (int) $sourceItem->get_variation_id();
        }
        if (method_exists($sourceItem, 'get_product_id')) {
            $productId = (int) $sourceItem->get_product_id();
        }

        $qty = method_exists($sourceItem, 'get_quantity') ? (int) $sourceItem->get_quantity() : 1;
        if ($qty <= 0) {
            $qty = 1;
        }
        $lineTotal = method_exists($sourceItem, 'get_total') ? (float) $sourceItem->get_total() : 0.0;
        $unitPrice = $qty > 0 ? ($lineTotal / $qty) : $lineTotal;

        // Try to re-use Mollie customer/mandate from the parent order if available.
        $mCustomer = (string) $sourceOrder->get_meta('_mollie_customer_id', true);
        $mMandate = (string) $sourceOrder->get_meta('_mollie_mandate_id', true);
        if ($mCustomer === '' || $mMandate === '') {
            $molliePaymentId = (string) $sourceOrder->get_meta('_mollie_payment_id', true);
            if ($molliePaymentId !== '') {
                $cm = $this->mollie_get_customer_and_mandate($molliePaymentId);
                if ($mCustomer === '' && $cm['customerId'] !== '') {
                    $mCustomer = $cm['customerId'];
                }
                if ($mMandate === '' && $cm['mandateId'] !== '') {
                    $mMandate = $cm['mandateId'];
                }
            }
        }

        $subId = wp_insert_post([
            'post_type' => $this->get_subscription_order_type()->get_type(),
            'post_status' => 'wc-pending',
            'post_title' => sprintf(__('Abonnement (demo) – order #%d', 'hb-ucs'), $orderId),
        ], true);

        if (is_wp_error($subId) || (int) $subId <= 0) {
            $redirect = add_query_arg('hb_ucs_subs_demo', 'failed', $redirect);
            wp_safe_redirect($redirect);
            exit;
        }

        $subId = (int) $subId;
        update_post_meta($subId, self::SUB_META_USER_ID, (string) $userId);
        update_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, (string) $orderId);
        update_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID, (string) $productId);
        if ($variationId > 0) {
            update_post_meta($subId, self::SUB_META_BASE_VARIATION_ID, (string) $variationId);
        }
        update_post_meta($subId, self::SUB_META_SCHEME, $scheme);
        update_post_meta($subId, self::SUB_META_INTERVAL, (string) $interval);
        update_post_meta($subId, self::SUB_META_PERIOD, (string) $period);
        update_post_meta($subId, self::SUB_META_QTY, (string) $qty);
        update_post_meta($subId, self::SUB_META_UNIT_PRICE, (string) wc_format_decimal($unitPrice, wc_get_price_decimals()));
        update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) (time() + ($interval * WEEK_IN_SECONDS)));
        update_post_meta($subId, self::SUB_META_STATUS, ($mCustomer !== '' && $mMandate !== '') ? 'active' : 'pending_mandate');
        update_post_meta($subId, '_hb_ucs_subscription_scheme', $scheme);
        update_post_meta($subId, '_hb_ucs_subscription_interval', (string) $interval);
        update_post_meta($subId, '_hb_ucs_subscription_period', (string) $period);
        update_post_meta($subId, '_hb_ucs_subscription_next_payment', (string) (time() + ($interval * WEEK_IN_SECONDS)));
        update_post_meta($subId, self::SUB_META_BILLING, $this->get_subscription_address_snapshot($subId, 'billing', $userId, $sourceOrder));
        update_post_meta($subId, self::SUB_META_SHIPPING, $this->get_subscription_address_snapshot($subId, 'shipping', $userId, $sourceOrder));
        if ($mCustomer !== '') {
            update_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $mCustomer);
        }
        if ($mMandate !== '') {
            update_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, $mMandate);
        }

        $this->hydrate_subscription_order_customer_data($subId, $userId, $sourceOrder);
        $this->sync_subscription_order_type_record($subId);

        $redirect = add_query_arg([
            'hb_ucs_subs_demo' => 'created',
            'sub_id' => (string) $subId,
        ], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    private function is_subscription_admin_overview_screen($screen = null): bool {
        $screen = $screen ?: (function_exists('get_current_screen') ? get_current_screen() : null);
        if (!$screen instanceof \WP_Screen) {
            return false;
        }

        $screenId = (string) $screen->id;

        return in_array($screenId, [
            'woocommerce_page_wc-orders--shop_subscription_hb',
            'admin_page_wc-orders--shop_subscription_hb',
        ], true);
    }


    private function get_subscription_admin_overview_url(): string {
        return admin_url('admin.php?page=wc-orders--shop_subscription_hb');
    }

    private function recurring_enabled(): bool {
        $settings = $this->get_settings();
        return !empty($settings['recurring_enabled']);
    }

    private function get_webhook_token(): string {
        $settings = $this->get_settings();
        $token = isset($settings['recurring_webhook_token']) ? (string) $settings['recurring_webhook_token'] : '';
        return trim($token);
    }

    public function register_cron_schedules(array $schedules): array {
        $schedules[self::RENEWAL_CRON_RECURRENCE] = [
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Elke minuut (HB UCS abonnementen)', 'hb-ucs'),
        ];

        return $schedules;
    }

    public function ensure_cron_scheduled(): void {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        $next = wp_next_scheduled('hb_ucs_subs_process_renewals');
        if (!$this->recurring_enabled()) {
            // Clean up if previously scheduled.
            if ($next) {
                wp_unschedule_event($next, 'hb_ucs_subs_process_renewals');
            }
            return;
        }

        $needsReschedule = false;
        if (function_exists('wp_get_scheduled_event')) {
            $event = wp_get_scheduled_event('hb_ucs_subs_process_renewals');
            if ($event && isset($event->schedule) && (string) $event->schedule !== self::RENEWAL_CRON_RECURRENCE) {
                $needsReschedule = true;
            }
        }

        if ($needsReschedule && $next) {
            wp_unschedule_event($next, 'hb_ucs_subs_process_renewals');
            $next = false;
        }

        if (!$next) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, self::RENEWAL_CRON_RECURRENCE, 'hb_ucs_subs_process_renewals');
        }
    }

    public function register_account_endpoint(): void {
        if (!function_exists('add_rewrite_endpoint')) {
            return;
        }

        add_rewrite_endpoint(self::ACCOUNT_ENDPOINT, EP_ROOT | EP_PAGES);
    }

    public function register_product_picker_menu_location(): void {
        if (!function_exists('register_nav_menu')) {
            return;
        }

        register_nav_menu(
            self::PRODUCT_PICKER_MENU_LOCATION,
            __('HB UCS abonnementen productfilters', 'hb-ucs')
        );
    }

    public function register_query_vars(array $vars): array {
        $vars[] = 'hb_ucs_mollie_webhook';
        $vars[] = 'hb_ucs_run_renewals';
        $vars[] = 'token';
        $vars[] = self::ACCOUNT_ENDPOINT;
        return $vars;
    }

    public function maybe_handle_renewals_cron_request(): void {
        if (!function_exists('get_query_var')) {
            return;
        }

        $flag = get_query_var('hb_ucs_run_renewals');
        if ((string) $flag !== '1') {
            return;
        }

        $token = (string) get_query_var('token');
        $this->process_renewals_request($token);
    }

    public function handle_renewals_admin_post(): void {
        $token = isset($_REQUEST['token']) ? sanitize_text_field((string) wp_unslash($_REQUEST['token'])) : '';
        $this->process_renewals_request($token);
    }

    private function process_renewals_request(string $token): void {
        $token = trim($token);
        if ($token === '' || !hash_equals($this->get_webhook_token(), $token)) {
            status_header(403);
            echo 'Forbidden';
            exit;
        }

        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            status_header(200);
            echo 'Renewals disabled';
            exit;
        }

        $this->process_due_renewals();

        status_header(200);
        echo 'OK';
        exit;
    }

    private function get_mollie_api_key(): string {
        // Prefer Mollie plugin settings.
        $testMode = get_option('mollie-payments-for-woocommerce_test_mode_enabled');
        $isTest = ((string) $testMode) === 'yes' || ((string) $testMode) === '1';
        $key = $isTest ? get_option('mollie-payments-for-woocommerce_test_api_key') : get_option('mollie-payments-for-woocommerce_live_api_key');
        $key = is_string($key) ? trim($key) : '';
        if ($key === '') {
            return '';
        }
        // Basic validation.
        if (strpos($key, 'test_') !== 0 && strpos($key, 'live_') !== 0) {
            return '';
        }
        return $key;
    }

    private function mollie_request(string $method, string $path, ?array $body = null) {
        $apiKey = $this->get_mollie_api_key();
        if ($apiKey === '') {
            return new \WP_Error('hb_ucs_no_mollie_key', __('Mollie API key ontbreekt.', 'hb-ucs'));
        }

        $url = 'https://api.mollie.com/v2/' . ltrim($path, '/');
        $args = [
            'method' => strtoupper($method),
            'headers' => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => 20,
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) {
            return $resp;
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $raw = (string) wp_remote_retrieve_body($resp);
        $json = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            return new \WP_Error('hb_ucs_mollie_http_' . $code, $raw !== '' ? $raw : ('HTTP ' . $code));
        }
        return is_array($json) ? $json : [];
    }

    /**
     * Extract Mollie customerId/mandateId from a Mollie payment id (tr_ / pay_) or order id (ord_).
     * @return array{customerId:string,mandateId:string}
     */
    private function mollie_get_customer_and_mandate(string $transactionId): array {
        $transactionId = trim($transactionId);
        if ($transactionId === '') {
            return ['customerId' => '', 'mandateId' => ''];
        }

        $endpoint = (strpos($transactionId, 'ord_') === 0) ? 'orders/' : 'payments/';
        $obj = $this->mollie_request('GET', $endpoint . rawurlencode($transactionId));
        if (is_wp_error($obj) || !is_array($obj)) {
            return ['customerId' => '', 'mandateId' => ''];
        }

        $customerId = !empty($obj['customerId']) ? (string) $obj['customerId'] : '';
        $mandateId = !empty($obj['mandateId']) ? (string) $obj['mandateId'] : '';

        // Orders API typically stores mandateId on the embedded payment.
        if ($mandateId === '' && isset($obj['_embedded']) && is_array($obj['_embedded']) && isset($obj['_embedded']['payments']) && is_array($obj['_embedded']['payments'])) {
            $payments = (array) $obj['_embedded']['payments'];
            $first = isset($payments[0]) && is_array($payments[0]) ? $payments[0] : [];
            if ($customerId === '' && !empty($first['customerId'])) {
                $customerId = (string) $first['customerId'];
            }
            if ($mandateId === '' && !empty($first['mandateId'])) {
                $mandateId = (string) $first['mandateId'];
            }
        }

        if ($mandateId === '' && isset($obj['payments']) && is_array($obj['payments'])) {
            $payments = (array) $obj['payments'];
            $first = isset($payments[0]) && is_array($payments[0]) ? $payments[0] : [];
            if ($customerId === '' && !empty($first['customerId'])) {
                $customerId = (string) $first['customerId'];
            }
            if ($mandateId === '' && !empty($first['mandateId'])) {
                $mandateId = (string) $first['mandateId'];
            }
        }

        return [
            'customerId' => $customerId,
            'mandateId' => $mandateId,
        ];
    }

    private function get_allowed_first_gateways(): array {
        $allowed = [
            'mollie_wc_gateway_ideal',
        ];
        $allowed = apply_filters('hb_ucs_subs_allowed_first_gateways', $allowed);
        return array_values(array_filter(array_map('strval', is_array($allowed) ? $allowed : [])));
    }

    private function is_mollie_gateway(string $paymentMethod): bool {
        return strpos(trim($paymentMethod), 'mollie_wc_gateway_') === 0;
    }

    private function get_available_checkout_gateway_ids(): array {
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) {
            return [];
        }

        $gateways = WC()->payment_gateways()->get_available_payment_gateways();
        if (!is_array($gateways)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', array_keys($gateways))));
    }

    private function cart_contains_subscription(): bool {
        if (!function_exists('WC') || !WC()) {
            return false;
        }

        if (!did_action('wp_loaded')) {
            return false;
        }

        if (!WC()->cart || !method_exists(WC()->cart, 'get_cart')) {
            return false;
        }

        foreach (WC()->cart->get_cart() as $cartItem) {
            if (!is_array($cartItem)) {
                continue;
            }

            $data = isset($cartItem[self::CART_KEY]) && is_array($cartItem[self::CART_KEY]) ? $cartItem[self::CART_KEY] : null;
            if (!$data) {
                continue;
            }

            $scheme = (string) ($data['scheme'] ?? '');
            if ($scheme !== '' && $scheme !== '0') {
                return true;
            }
        }

        return false;
    }

    public function filter_first_subscription_payment_gateways($gateways) {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return $gateways;
        }
        if (!$this->cart_contains_subscription()) {
            return $gateways;
        }
        if (!is_array($gateways) || empty($gateways)) {
            return $gateways;
        }

        $allowed = $this->get_allowed_first_gateways();
        if (empty($allowed)) {
            foreach (array_keys($gateways) as $gatewayId) {
                if ($this->is_mollie_gateway((string) $gatewayId)) {
                    unset($gateways[$gatewayId]);
                }
            }

            return $gateways;
        }

        foreach (array_keys($gateways) as $gatewayId) {
            $gatewayId = (string) $gatewayId;
            if ($this->is_mollie_gateway($gatewayId) && !in_array($gatewayId, $allowed, true)) {
                unset($gateways[$gatewayId]);
            }
        }

        return $gateways;
    }

    private function payment_method_requires_mandate(string $paymentMethod): bool {
        $paymentMethod = trim($paymentMethod);
        if ($paymentMethod === '') {
            return false;
        }

        $requiresMandate = strpos($paymentMethod, 'mollie_wc_gateway_') === 0;
        return (bool) apply_filters('hb_ucs_subs_payment_method_requires_mandate', $requiresMandate, $paymentMethod);
    }

    private function resolve_subscription_payment_method_for_renewal(int $subId, string $paymentMethod, string $paymentMethodTitle, $parentOrder = null): array {
        $resolvedMethod = trim($paymentMethod);
        $resolvedTitle = trim($paymentMethodTitle);
        $storedRequiresMandate = $this->payment_method_requires_mandate($resolvedMethod);

        if ($parentOrder && is_object($parentOrder)) {
            $parentPayment = $this->get_order_payment_method_data($parentOrder);
            $parentMethod = trim((string) ($parentPayment['method'] ?? ''));
            $parentTitle = trim((string) ($parentPayment['title'] ?? ''));
            $parentRequiresMandate = $this->payment_method_requires_mandate($parentMethod);

            if ($resolvedMethod === '' && $parentMethod !== '') {
                $resolvedMethod = $parentMethod;
                $resolvedTitle = $parentTitle !== '' ? $parentTitle : $resolvedTitle;
            } elseif ($storedRequiresMandate && $parentMethod !== '' && !$parentRequiresMandate) {
                $resolvedMethod = $parentMethod;
                $resolvedTitle = $parentTitle !== '' ? $parentTitle : $resolvedTitle;
            } elseif ($resolvedTitle === '' && $parentTitle !== '') {
                $resolvedTitle = $parentTitle;
            }
        }

        return [
            'method' => $resolvedMethod,
            'title' => $resolvedTitle,
        ];
    }

    private function get_order_payment_method_data($order): array {
        $paymentMethod = '';
        $paymentMethodTitle = '';

        if ($order && is_object($order)) {
            if (method_exists($order, 'get_payment_method')) {
                $paymentMethod = (string) $order->get_payment_method();
            }
            if (method_exists($order, 'get_payment_method_title')) {
                $paymentMethodTitle = (string) $order->get_payment_method_title();
            }
        }

        return [
            'method' => $paymentMethod,
            'title' => $paymentMethodTitle,
        ];
    }

    private function get_gateway_title_from_object($gateway, string $fallbackId = ''): string {
        if ($gateway && is_object($gateway)) {
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

        return $fallbackId !== '' ? $fallbackId : __('Onbekend', 'hb-ucs');
    }

    private function get_available_payment_gateway_choices_for_user(int $userId): array {
        if (!function_exists('WC') || !WC() || !WC()->payment_gateways()) {
            return [];
        }

        $allGateways = WC()->payment_gateways()->payment_gateways();
        if (!is_array($allGateways)) {
            $allGateways = [];
        }

        $availableGateways = [];
        if (method_exists(WC()->payment_gateways(), 'get_available_payment_gateways')) {
            try {
                $availableGateways = WC()->payment_gateways()->get_available_payment_gateways();
            } catch (\Throwable $e) {
                $availableGateways = [];
            }
        }

        if (!is_array($availableGateways) || empty($availableGateways)) {
            $availableGateways = $allGateways;
        }

        if (
            class_exists('HB\\UCS\\Modules\\B2B\\Storage\\SettingsStore')
            && class_exists('HB\\UCS\\Modules\\B2B\\Checkout\\MethodVisibility')
            && class_exists('HB\\UCS\\Modules\\B2B\\Support\\Context')
        ) {
            try {
                \HB\UCS\Modules\B2B\Support\Context::set_forced_user_id(max(0, $userId));
                $visibility = new \HB\UCS\Modules\B2B\Checkout\MethodVisibility(new \HB\UCS\Modules\B2B\Storage\SettingsStore());
                $availableGateways = $visibility->filter_payment_gateways($availableGateways);
            } catch (\Throwable $e) {
            }
            \HB\UCS\Modules\B2B\Support\Context::set_forced_user_id(0);
        }

        $choices = [];
        foreach ($availableGateways as $gatewayId => $gateway) {
            $gatewayId = (string) $gatewayId;
            if ($gatewayId === '') {
                continue;
            }
            $choices[$gatewayId] = $this->get_gateway_title_from_object($gateway, $gatewayId);
        }

        return $choices;
    }

    private function build_subscription_title_from_order($order, int $fallbackId = 0): string {
        $fallbackId = $fallbackId > 0 ? $fallbackId : (is_object($order) && method_exists($order, 'get_id') ? (int) $order->get_id() : 0);
        $title = sprintf(__('Abonnement #%d', 'hb-ucs'), $fallbackId);

        if (!$order || !is_object($order)) {
            return $title;
        }

        $name = '';
        if (method_exists($order, 'get_formatted_billing_full_name')) {
            $name = trim(wp_strip_all_tags((string) $order->get_formatted_billing_full_name(), true));
        }

        if ($name === '') {
            $firstName = method_exists($order, 'get_billing_first_name') ? (string) $order->get_billing_first_name() : '';
            $lastName = method_exists($order, 'get_billing_last_name') ? (string) $order->get_billing_last_name() : '';
            $name = trim($firstName . ' ' . $lastName);
        }

        return $name !== '' ? $title . ' — ' . $name : $title;
    }

    public function validate_first_payment_method(array $data, $errors): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }
        if (!is_object($errors) || !method_exists($errors, 'add')) {
            return;
        }

        if (!$this->cart_contains_subscription()) {
            return;
        }

        // Mollie recurring requires a Mollie customerId -> enforce login.
        if (!is_user_logged_in()) {
            $errors->add('hb_ucs_subs_login_required', __('Voor automatische verlengingen moet je ingelogd zijn (klantaccount vereist).', 'hb-ucs'));
            return;
        }

        $paymentMethod = isset($data['payment_method']) ? (string) $data['payment_method'] : '';
        $available = $this->get_available_checkout_gateway_ids();
        if ($paymentMethod !== '' && !empty($available) && !in_array($paymentMethod, $available, true)) {
            $errors->add('hb_ucs_subs_first_payment_method', __('De gekozen betaalmethode is niet beschikbaar voor dit abonnement.', 'hb-ucs'));
            return;
        }

        if ($paymentMethod !== '' && $this->is_mollie_gateway($paymentMethod) && !$this->mollie_customer_storage_enabled()) {
            $errors->add('hb_ucs_subs_mollie_customer_storage_required', __('Automatische verlengingen via Mollie vereisen dat klantopslag in de Mollie plugin is ingeschakeld.', 'hb-ucs'));
            return;
        }

        if ($paymentMethod !== '' && $this->is_mollie_gateway($paymentMethod) && !in_array($paymentMethod, $this->get_allowed_first_gateways(), true)) {
            $errors->add('hb_ucs_subs_first_payment_method', __('De gekozen online betaalmethode is niet beschikbaar voor dit abonnement.', 'hb-ucs'));
        }
    }

    public function mark_order_subscription_meta($order, array $data): void {
        if (!$order || !is_object($order) || !method_exists($order, 'update_meta_data')) {
            return;
        }

        if ($this->is_checkout_draft_order($order)) {
            return;
        }

        if (!$this->cart_contains_subscription()) {
            return;
        }

        $order->update_meta_data(self::ORDER_META_CONTAINS_SUBSCRIPTION, 'yes');
    }

    public function maybe_create_subscriptions_from_manual_order(int $orderId, $postedData = null, $order = null): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }

        $order = ($order instanceof \WC_Order) ? $order : wc_get_order($orderId);
        if (!$order || !is_object($order)) {
            return;
        }

        if ($this->is_checkout_draft_order($order)) {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_META_RENEWAL, true) === '1') {
            return;
        }

        $payment = $this->get_order_payment_method_data($order);
        if ($this->payment_method_requires_mandate((string) ($payment['method'] ?? ''))) {
            return;
        }

        $this->maybe_create_subscriptions_from_order($orderId);
    }

    public function maybe_handle_store_api_checkout_order_processed($order): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }

        if ($this->is_checkout_draft_order($order)) {
            return;
        }

        if (!$this->order_contains_subscription($order)) {
            return;
        }

        if (method_exists($order, 'get_meta') && method_exists($order, 'update_meta_data')) {
            if ((string) $order->get_meta(self::ORDER_META_CONTAINS_SUBSCRIPTION, true) !== 'yes') {
                $order->update_meta_data(self::ORDER_META_CONTAINS_SUBSCRIPTION, 'yes');
                if (method_exists($order, 'save')) {
                    $order->save();
                }
            }
        }

        $this->maybe_create_subscriptions_from_manual_order((int) $order->get_id(), null, $order);

        if (method_exists($order, 'get_status') && (string) $order->get_status() === 'on-hold') {
            $this->maybe_promote_manual_subscription_order_to_processing((int) $order->get_id());
        }
    }

    public function maybe_promote_manual_subscription_order_to_processing(int $orderId): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order || !is_object($order)) {
            return;
        }

        if ($this->is_checkout_draft_order($order)) {
            return;
        }

        if (!$this->order_contains_subscription($order)) {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_META_RENEWAL, true) === '1') {
            return;
        }

        if ((string) $order->get_status() !== 'on-hold') {
            return;
        }

        $payment = $this->get_order_payment_method_data($order);
        if ($this->payment_method_requires_mandate((string) ($payment['method'] ?? ''))) {
            return;
        }

        $order->update_status('processing', __('HB UCS: abonnement met handmatige/offline betaalmethode direct op verwerken gezet.', 'hb-ucs'));
    }

    public function maybe_handle_mollie_webhook(): void {
        if (is_admin()) {
            return;
        }
        $flag = get_query_var('hb_ucs_mollie_webhook');
        if ((string) $flag !== '1') {
            return;
        }

        $token = (string) get_query_var('token');
        $token = trim($token);
        if ($token === '' || !hash_equals($this->get_webhook_token(), $token)) {
            status_header(403);
            echo 'Forbidden';
            exit;
        }

        // Mollie sends payment id as POST param 'id'.
        $paymentId = isset($_POST['id']) ? (string) wp_unslash($_POST['id']) : '';
        $paymentId = trim($paymentId);
        if ($paymentId === '') {
            status_header(400);
            echo 'Missing id';
            exit;
        }

        $payment = $this->mollie_request('GET', 'payments/' . rawurlencode($paymentId));
        if (is_wp_error($payment)) {
            status_header(500);
            echo 'Error';
            exit;
        }

        $metadata = isset($payment['metadata']) && is_array($payment['metadata']) ? $payment['metadata'] : [];
        $orderId = isset($metadata['order_id']) ? (int) $metadata['order_id'] : 0;
        if ($orderId <= 0) {
            status_header(200);
            echo 'OK';
            exit;
        }

        $order = wc_get_order($orderId);
        if (!$order || !is_object($order)) {
            status_header(200);
            echo 'OK';
            exit;
        }

        $storedPaymentId = (string) $order->get_meta(self::ORDER_META_MOLLIE_PAYMENT_ID, true);
        if ($storedPaymentId !== '' && $storedPaymentId !== $paymentId) {
            status_header(200);
            echo 'OK';
            exit;
        }

        $status = isset($payment['status']) ? (string) $payment['status'] : '';
        $subId = (int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true);

        if ($status === 'paid') {
            if (method_exists($order, 'payment_complete')) {
                $order->payment_complete();
            }
            if (method_exists($order, 'add_order_note')) {
                $order->add_order_note(sprintf(__('HB UCS: Mollie recurring betaling %s is betaald.', 'hb-ucs'), $paymentId));
            }
            if ($subId > 0) {
                $paid = method_exists($order, 'get_date_paid') ? $order->get_date_paid() : null;
                $paidTs = $paid ? (int) $paid->getTimestamp() : time();
                $this->persist_subscription_last_order_state($subId, $orderId, $paidTs);
                $this->persist_subscription_runtime_state($subId, 'active', $this->get_recorded_renewal_next_payment($order, $subId));
                $this->sync_subscription_order_type_record($subId);
            }
        } elseif ($status === 'failed' || $status === 'canceled' || $status === 'expired') {
            if (method_exists($order, 'update_status')) {
                $order->update_status('failed', sprintf(__('HB UCS: Mollie recurring betaling %s is mislukt (%s).', 'hb-ucs'), $paymentId, $status));
            }
            if ($subId > 0) {
                $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
                $createdTs = $created ? (int) $created->getTimestamp() : time();
                $this->persist_subscription_last_order_state($subId, $orderId, $createdTs);
                $this->persist_subscription_runtime_state($subId, 'on-hold');
                $this->sync_subscription_order_type_record($subId);
            }
        }

        status_header(200);
        echo 'OK';
        exit;
    }

    private function calculate_next_payment_timestamp(int $subId): int {
        $step = $this->get_subscription_schedule_step_seconds($subId);
        if ($step <= 0) {
            $step = WEEK_IN_SECONDS;
        }

        $now = time();
        $currentNext = $this->normalize_subscription_next_payment(
            $subId,
            (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true)
        );

        if ($currentNext > 0) {
            $next = $currentNext;
        } else {
            $lastOrderDate = (int) get_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, true);
            $next = $lastOrderDate > 0 ? $lastOrderDate : $now;
        }

        while ($next <= $now) {
            $next += $step;
        }

        return $this->normalize_subscription_next_payment($subId, $next);
    }

    private function get_recorded_renewal_next_payment($order, int $subId): int {
        $referenceTimestamp = $this->get_order_reference_timestamp($order);
        $recordedNextPayment = 0;

        if ($order && is_object($order) && method_exists($order, 'get_meta')) {
            $recordedNextPayment = (int) $order->get_meta(self::ORDER_META_RENEWAL_NEXT_PAYMENT, true);
        }

        $recordedNextPayment = $this->normalize_subscription_next_payment($subId, $recordedNextPayment, $referenceTimestamp);

        if ($recordedNextPayment > time()) {
            return $recordedNextPayment;
        }

        $currentNextPayment = $this->normalize_subscription_next_payment(
            $subId,
            (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true),
            $referenceTimestamp
        );

        if ($currentNextPayment > time()) {
            return $currentNextPayment;
        }

        return $this->normalize_subscription_next_payment(
            $subId,
            $this->calculate_next_payment_timestamp($subId),
            $referenceTimestamp
        );
    }

    private function mark_subscription_paid_and_advance(int $subId): void {
        $currentNext = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
        $threshold = (int) apply_filters('hb_ucs_subscription_activation_next_payment_date_threshold', 2 * HOUR_IN_SECONDS, $currentNext, $subId);

        if ($currentNext > (time() + max(0, $threshold))) {
            $next = $currentNext;
        } else {
            $next = $this->calculate_next_payment_timestamp($subId);
        }

        $this->persist_subscription_runtime_state($subId, 'active', $next);
        $this->sync_subscription_order_type_record($subId);
    }

    private function get_engine(): string {
        $settings = $this->get_settings();
        $engine = isset($settings['engine']) ? sanitize_key((string) $settings['engine']) : 'manual';
        if ($engine !== 'manual') {
            $engine = 'manual';
        }
        return $engine;
    }

    public function enqueue_admin_assets(string $hook): void {
        if (!is_admin()) return;
        if ($hook !== 'post.php' && $hook !== 'post-new.php' && $hook !== 'edit.php' && $hook !== 'woocommerce_page_wc-orders') return;
        if (!function_exists('get_current_screen')) return;
        $screen = get_current_screen();
        if (!$screen) return;

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 4) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/assets/', $pluginFile));
        $ver = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';

        // Product edit screen JS (existing pricing UI).
        if ($screen->post_type === 'product' && ($hook === 'post.php' || $hook === 'post-new.php')) {
            wp_enqueue_script('hb-ucs-subscriptions-admin', $base . 'admin-hb-ucs-subscriptions.js', ['jquery'], $ver, true);
            wp_localize_script('hb-ucs-subscriptions-admin', 'hbUcsSubsAdmin', [
                'symbol' => function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€',
                'decimals' => function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2,
            ]);
            return;
        }

        // Subscription screens: use WooCommerce admin styles + enhanced selects for filters/product search.
        if ($screen->id === 'woocommerce_page_wc-orders--' . $this->get_subscription_order_type()->get_type() || $screen->id === 'admin_page_wc-orders--' . $this->get_subscription_order_type()->get_type()) {
            if (wp_style_is('woocommerce_admin_styles', 'registered') || wp_style_is('woocommerce_admin_styles', 'enqueued')) {
                wp_enqueue_style('woocommerce_admin_styles');
            }
            if (wp_script_is('wc-enhanced-select', 'registered') || wp_script_is('wc-enhanced-select', 'enqueued')) {
                wp_enqueue_script('wc-enhanced-select');
                wp_add_inline_script('wc-enhanced-select', "jQuery(function($){ try { $(document.body).trigger('wc-enhanced-select-init'); } catch(e) {} });");
                $subscriptionAdminScriptTemplate = <<<'JS'
jQuery(function($){
    var cfg = {
        ajaxUrl: __AJAX_URL__,
        nonce: __NONCE__,
        decimals: __DECIMALS__,
        currencySymbol: __CURRENCY_SYMBOL__,
        selectTaxMessage: __I18N_SELECT_TAX__,
        duplicateTaxMessage: __I18N_DUPLICATE_TAX__
    };

    function setMetaboxLoading(isLoading) {
        var box = $('#woocommerce-order-items');
        if (!box.length || typeof box.block !== 'function' || typeof box.unblock !== 'function') {
            return;
        }

        if (isLoading) {
            box.block({ message: null, overlayCSS: { background: '#fff', opacity: 0.6 } });
        } else {
            box.unblock();
        }
    }

    function replaceItemsMetabox(html) {
        var next = $(html);
        if (!next.length) {
            return;
        }

        $('#woocommerce-order-items').replaceWith(next);
        try { $(document.body).trigger('wc-enhanced-select-init'); } catch(e) {}
        updateAddressDisplay('billing');
        updateAddressDisplay('shipping');
    }

    function closeTaxModal() {
        $('.hb-ucs-tax-modal-wrapper').remove();
    }

    function openTaxModal() {
        var template = $('#tmpl-hb-ucs-subscription-add-tax-modal').html();
        closeTaxModal();
        if (!template) {
            return;
        }
        $('body').append('<div class="hb-ucs-tax-modal-wrapper">' + template + '</div>');
    }

    function updateTaxRate(action, rateId) {
        var subId = parseInt($('#hb_ucs_sub_id_current').val() || '0', 10),
            ajaxAction = action === 'remove' ? 'hb_ucs_subscription_remove_tax_rate' : 'hb_ucs_subscription_add_tax_rate';

        if (!subId || !rateId) {
            return;
        }

        setMetaboxLoading(true);
        $.post(cfg.ajaxUrl, {
            action: ajaxAction,
            nonce: cfg.nonce,
            sub_id: subId,
            rate_id: rateId
        }).done(function(response) {
            if (!response || !response.success || !response.data || !response.data.html) {
                window.alert(response && response.data && response.data.message ? response.data.message : cfg.selectTaxMessage);
                return;
            }

            replaceItemsMetabox(response.data.html);
            closeTaxModal();
        }).fail(function(xhr) {
            var message = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : '';
            window.alert(message || cfg.selectTaxMessage);
        }).always(function() {
            setMetaboxLoading(false);
        });
    }

    function formatMoney(amount) {
        amount = parseFloat(amount || 0) || 0;
        return cfg.currencySymbol + amount.toFixed(cfg.decimals).replace('.', ',');
    }

    function getRowIndex(row) {
        return parseInt(row.attr('data-row-index') || '0', 10) || 0;
    }

    function getRowSelectedAttributes(row) {
        var selected = {};
        row.find('.hb-ucs-item-attr-select').each(function() {
            var field = $(this),
                name = field.attr('name') || '',
                match = name.match(/\[selected_attributes\]\[([^\]]+)\]/),
                value = field.val() || '';

            if (match && match[1]) {
                selected[match[1]] = value;
            }
        });
        return selected;
    }

    function syncProductSelection(row, productId, label) {
        var field = row.find('.hb-ucs-item-product');
        if (!field.length || !productId) {
            return;
        }

        if (String(field.val() || '') === String(productId)) {
            return;
        }

        row.data('hbUcsSyncingProduct', true);
        field.find('option').remove();
        field.append(new Option(label || productId, productId, true, true));
        field.val(String(productId));
        field.trigger('change.select2');
        row.data('hbUcsSyncingProduct', false);
    }

    function renderAttributeFields(row, config, selectedAttributes) {
        var wrapper = row.find('.hb-ucs-item-variation-fields'),
            rowIndex = getRowIndex(row),
            html = '';

        if (!wrapper.length) {
            return;
        }

        if (!config || !config.length) {
            wrapper.html('');
            return;
        }

        $.each(config, function(index, attribute) {
            var key = attribute && attribute.key ? attribute.key : '',
                label = attribute && attribute.label ? attribute.label : key,
                options = attribute && attribute.options ? attribute.options : [];

            if (!key || !options.length) {
                return;
            }

            html += '<p class="form-field form-field-wide hb-ucs-item-attribute-field">';
            html += '<label for="hb_ucs_sub_items_' + rowIndex + '_' + key + '">' + $('<div/>').text(label).html() + '</label>';
            html += '<select id="hb_ucs_sub_items_' + rowIndex + '_' + key + '" class="hb-ucs-item-attr-select" name="hb_ucs_items[' + rowIndex + '][selected_attributes][' + key + ']">';
            html += '<option value="">Kies een optie…</option>';
            $.each(options, function(optionIndex, option) {
                var value = option && option.value ? option.value : '',
                    optionLabel = option && option.label ? option.label : value,
                    selected = String((selectedAttributes || {})[key] || '') === String(value) ? ' selected="selected"' : '';
                html += '<option value="' + $('<div/>').text(value).html() + '"' + selected + '>' + $('<div/>').text(optionLabel).html() + '</option>';
            });
            html += '</select>';
            html += '</p>';
        });

        wrapper.html(html);
    }

    function focusRowProductPicker(row) {
        var field = row.find('.hb-ucs-item-product');
        if (!field.length) {
            return;
        }

        setTimeout(function() {
            try {
                if (field.data('select2')) {
                    field.select2('open');
                } else {
                    field.trigger('focus');
                }
            } catch (e) {
                field.trigger('focus');
            }
        }, 60);
    }

    function setRowEditing(row, editing, focusPicker) {
        if (!row.length) {
            return;
        }

        row.toggleClass('editing', !!editing);
        row.find('.view').toggle(!editing);
        row.find('.edit').toggle(!!editing);

        if (editing && focusPicker) {
            focusRowProductPicker(row);
        }
    }

    function updateTotalsFromRows() {
        var itemSubtotal = 0,
            itemTax = 0,
            itemTotal = 0,
            feeSubtotal = 0,
            feeTax = 0,
            feeTotal = 0,
            shippingSubtotal = 0,
            shippingTax = 0,
            shippingTotal = 0,
            taxBreakdown = {};

        function collectTaxCells(row) {
            row.find('.hb-ucs-tax-cell').each(function() {
                var cell = $(this),
                    rateKey = String(cell.attr('data-tax-rate') || ''),
                    amount = parseFloat(cell.attr('data-tax-amount') || '0') || 0;

                if (!rateKey) {
                    return;
                }

                taxBreakdown[rateKey] = (taxBreakdown[rateKey] || 0) + amount;
            });
        }

        $('#order_line_items tr.hb-ucs-sub-item-row').each(function() {
            var row = $(this);
            itemSubtotal += parseFloat(row.attr('data-line-subtotal') || '0') || 0;
            itemTax += parseFloat(row.attr('data-line-tax') || '0') || 0;
            itemTotal += parseFloat(row.attr('data-line-total') || '0') || 0;
            collectTaxCells(row);
        });

        $('#order_fee_line_items tr.hb-ucs-sub-fee-row').each(function() {
            var row = $(this);
            feeSubtotal += parseFloat(row.attr('data-line-subtotal') || '0') || 0;
            feeTax += parseFloat(row.attr('data-line-tax') || '0') || 0;
            feeTotal += parseFloat(row.attr('data-line-total') || '0') || 0;
            collectTaxCells(row);
        });

        $('#order_shipping_line_items tr.hb-ucs-sub-shipping-row').each(function() {
            var row = $(this);
            shippingSubtotal += parseFloat(row.attr('data-line-subtotal') || '0') || 0;
            shippingTax += parseFloat(row.attr('data-line-tax') || '0') || 0;
            shippingTotal += parseFloat(row.attr('data-line-total') || '0') || 0;
            collectTaxCells(row);
        });

        $('#hb-ucs-sub-items-subtotal').html(formatMoney(itemSubtotal));
        $('#hb-ucs-sub-fee-total').html(formatMoney(feeSubtotal));
        $('#hb-ucs-sub-shipping-total').html(formatMoney(shippingSubtotal));
        $('.hb-ucs-tax-total-row').each(function() {
            var row = $(this),
                rateKey = String(row.attr('data-tax-rate') || ''),
                safeKey = rateKey.replace(/[^A-Za-z0-9_-]/g, '-');

            $('#hb-ucs-sub-tax-rate-' + safeKey).html(formatMoney(taxBreakdown[rateKey] || 0));
        });
        $('#hb-ucs-sub-total-tax').html(formatMoney(itemTax + feeTax + shippingTax));
        $('#hb-ucs-sub-order-total').html(formatMoney(itemTotal + feeTotal + shippingTotal));
    }

    function syncManualChargeRow(row, prefix) {
        var subtotalField = row.find('.hb-ucs-' + prefix + '-total'),
            subtotal = parseFloat(subtotalField.val() || '0') || 0,
            tax = 0,
            total = subtotal + tax;

        row.find('.hb-ucs-tax-input').each(function() {
            var field = $(this),
                cell = field.closest('.hb-ucs-tax-cell'),
                amount = parseFloat(field.val() || '0') || 0;

            tax += amount;
            cell.attr('data-tax-amount', amount.toFixed(cfg.decimals));
            cell.find('.view').html(formatMoney(amount));
        });

        total = subtotal + tax;

        row.attr('data-line-subtotal', subtotal.toFixed(cfg.decimals));
        row.attr('data-line-tax', tax.toFixed(cfg.decimals));
        row.attr('data-line-total', total.toFixed(cfg.decimals));
        row.find('.hb-ucs-' + prefix + '-subtotal-view').html(formatMoney(subtotal));
        row.find('.hb-ucs-' + prefix + '-tax-view').html(formatMoney(tax));
        row.find('.hb-ucs-' + prefix + '-total-view').html(formatMoney(total));
        row.find('.hb-ucs-' + prefix + '-line-total').val(total.toFixed(cfg.decimals));

        if (prefix === 'fee') {
            row.find('.view').first().text(row.find('.hb-ucs-fee-name').val() || 'Kosten');
        }
        if (prefix === 'shipping') {
            row.find('.view').first().text(row.find('.hb-ucs-shipping-title').val() || 'Verzending');
        }

        updateTotalsFromRows();
    }

    function applyPreviewToRow(row, data, syncPriceField) {
        var skuWrap = row.find('.hb-ucs-item-sku-wrap');
        var variationWrap = row.find('.hb-ucs-item-variation-summary-wrap');

        if (!row.length || !data) {
            return;
        }

        renderAttributeFields(row, data.attribute_config || [], data.selected_attributes || {});
        syncProductSelection(row, data.editor_product_id || 0, data.editor_product_label || '');

        row.attr('data-line-subtotal', data.line_subtotal || '0');
        row.attr('data-line-tax', data.line_tax || '0');
        row.attr('data-line-total', data.line_total || '0');
        row.find('.hb-ucs-tax-cell').each(function() {
            var cell = $(this),
                rateKey = String(cell.attr('data-tax-rate') || ''),
                amount = data.tax_breakdown && typeof data.tax_breakdown[rateKey] !== 'undefined' ? parseFloat(data.tax_breakdown[rateKey] || '0') || 0 : 0;

            cell.attr('data-tax-amount', amount.toFixed(cfg.decimals));
            cell.find('.view').html(formatMoney(amount));
            cell.find('.hb-ucs-tax-input').val(amount.toFixed(cfg.decimals));
        });
        row.find('.hb-ucs-item-label-view').text(data.label || '—');
        row.find('.hb-ucs-item-sku').text(data.sku || '');
        row.find('.hb-ucs-item-unit-view').html(data.unit_price_html || '');
        row.find('.hb-ucs-item-line-subtotal').html(data.line_subtotal_html || '');
        row.find('.hb-ucs-item-line-total').html(data.line_total_html || '');
        row.find('.hb-ucs-item-line-subtotal-input').val(data.line_subtotal || '0');
        row.find('.hb-ucs-item-line-total-input').val(data.line_total || '0');
        row.find('.hb-ucs-item-qty-view').text(row.find('.hb-ucs-item-qty').val() || '1');

        if (syncPriceField !== false && typeof data.unit_price !== 'undefined') {
            row.find('.hb-ucs-item-price').val(data.unit_price || '0');
        }

        if (data.sku) {
            skuWrap.removeClass('is-empty').show();
        } else {
            skuWrap.addClass('is-empty').hide();
        }

        if (data.variation_summary) {
            variationWrap.removeClass('is-empty').show().find('.hb-ucs-item-variation-summary').text(data.variation_summary);
        } else if (variationWrap.length) {
            variationWrap.addClass('is-empty').hide().find('.hb-ucs-item-variation-summary').text('');
        }

        updateTotalsFromRows();
    }

    function requestRowPreview(row, syncPriceField) {
        var productId = parseInt(row.find('.hb-ucs-item-product').val() || '0', 10),
            scheme = $('#hb_ucs_sub_scheme').val() || $('#hb_ucs_sub_scheme_current').val() || '',
            subId = parseInt($('#hb_ucs_sub_id_current').val() || '0', 10),
            qty = parseInt(row.find('.hb-ucs-item-qty').val() || '1', 10),
            unitPrice = row.find('.hb-ucs-item-price').val() || '';

        if (!productId || !scheme) {
            return;
        }

        $.post(cfg.ajaxUrl, {
            action: 'hb_ucs_subscription_product_data',
            nonce: cfg.nonce,
            product_id: productId,
            scheme: scheme,
            sub_id: subId,
            qty: qty,
            unit_price: unitPrice,
            sync_price: syncPriceField ? '1' : '0',
            selected_attributes: getRowSelectedAttributes(row)
        }).done(function(response) {
            if (!response || !response.success || !response.data) {
                return;
            }

            applyPreviewToRow(row, response.data, syncPriceField);
        });
    }

    function refreshAllRows(syncPriceField) {
        $('#order_line_items tr.hb-ucs-sub-item-row').each(function() {
            requestRowPreview($(this), syncPriceField);
        });
    }

    function addItemRow() {
        var template = $('#tmpl-hb-ucs-subscription-item-row').html(),
            index = $('#order_line_items tr.hb-ucs-sub-item-row').length,
            row;

        if (!template) {
            return;
        }

        template = template.replace(/\{\{index\}\}/g, index);
        $('#order_line_items').append(template);
        try { $(document.body).trigger('wc-enhanced-select-init'); } catch(e) {}
        row = $('#order_line_items tr.hb-ucs-sub-item-row').last();
        setRowEditing(row, true, true);
        $('html, body').animate({ scrollTop: Math.max(row.offset().top - 120, 0) }, 150);
        updateTotalsFromRows();
    }

    function addManualRow(templateId, targetSelector, rowSelector, prefix) {
        var template = $(templateId).html(),
            index = $(targetSelector).find(rowSelector).length,
            row;

        if (!template) {
            return;
        }

        template = template.replace(/\{\{index\}\}/g, index);
        $(targetSelector).append(template);
        row = $(targetSelector).find(rowSelector).last();
        setRowEditing(row, true, false);
        syncManualChargeRow(row, prefix);
        $('html, body').animate({ scrollTop: Math.max(row.offset().top - 120, 0) }, 150);
    }

    function showAddItemActions() {
        $('div.wc-order-add-item').slideDown();
    }

    function hideAddItemActions(reloadPage) {
        $('div.wc-order-add-item').slideUp();
        if (reloadPage) {
            window.location.reload();
        }
    }

    function updateAddressDisplay(type) {
        var prefix = '#hb_ucs_sub_' + type + '_',
            wrapper = $('#order_data .hb-ucs-subscription-' + type + '-column .hb-ucs-address-preview'),
            lines = [],
            firstName = $(prefix + 'first_name').val() || '',
            lastName = $(prefix + 'last_name').val() || '',
            company = $(prefix + 'company').val() || '',
            address1 = $(prefix + 'address_1').val() || '',
            address2 = $(prefix + 'address_2').val() || '',
            postcode = $(prefix + 'postcode').val() || '',
            city = $(prefix + 'city').val() || '',
            country = $(prefix + 'country').val() || '',
            email = type === 'billing' ? ($(prefix + 'email').val() || '') : '',
            phone = type === 'billing' ? ($(prefix + 'phone').val() || '') : '',
            linesEl,
            emailEl,
            phoneEl;

        if (!wrapper.length) {
            return;
        }

        linesEl = wrapper.find('.hb-ucs-address-preview__lines');
        emailEl = wrapper.find('.hb-ucs-address-preview__email');
        phoneEl = wrapper.find('.hb-ucs-address-preview__phone');

        if ($.trim(firstName + ' ' + lastName)) {
            lines.push($('<div/>').text($.trim(firstName + ' ' + lastName)).html());
        }
        if (company) {
            lines.push($('<div/>').text(company).html());
        }
        if (address1) {
            lines.push($('<div/>').text(address1).html());
        }
        if (address2) {
            lines.push($('<div/>').text(address2).html());
        }
        if ($.trim(postcode + ' ' + city)) {
            lines.push($('<div/>').text($.trim(postcode + ' ' + city)).html());
        }
        if (country) {
            lines.push($('<div/>').text(country).html());
        }

        if (lines.length) {
            linesEl.removeClass('none_set').html(lines.join('<br>'));
        } else {
            linesEl.addClass('none_set').html('<strong>Adres:</strong> <span class="hb-ucs-address-preview__empty">Geen adres ingesteld.</span>');
        }

        if (type === 'billing') {
            if (email) {
                emailEl.html('<strong>E-mailadres:</strong> <a href="mailto:' + $('<div/>').text(email).html() + '">' + $('<div/>').text(email).html() + '</a>').show();
            } else {
                emailEl.hide().empty();
            }

            if (phone) {
                phoneEl.html('<strong>Telefoon:</strong> ' + $('<div/>').text(phone).html()).show();
            } else {
                phoneEl.hide().empty();
            }
        }
    }

    function loadCustomerAddress(type) {
        var userId = parseInt($('#hb_ucs_sub_user_id').val() || '0', 10);
        if (!userId) {
            return;
        }

        $.post(cfg.ajaxUrl, {
            action: 'hb_ucs_subscription_customer_details',
            nonce: cfg.nonce,
            user_id: userId
        }).done(function(response) {
            var data, prefix;
            if (!response || !response.success || !response.data) {
                return;
            }

            data = type === 'shipping' ? response.data.shipping : response.data.billing;
            prefix = '#hb_ucs_sub_' + type + '_';
            $.each(data || {}, function(key, value) {
                $(prefix + key).val(value || '').trigger('change');
            });
            updateAddressDisplay(type);
        });
    }

    function toggleAddressEdit(link) {
        var wrapper = link.closest('.order_data_column'),
            editing = !wrapper.find('div.edit_address').is(':visible');

        wrapper.find('div.address').toggle(!editing);
        wrapper.find('div.edit_address').toggle(editing);
        wrapper.find('a.load_customer_billing, a.load_customer_shipping, a.billing-same-as-shipping').toggle(editing);

        if (!editing) {
            if (wrapper.find('#hb_ucs_sub_billing_first_name').length) {
                updateAddressDisplay('billing');
            }
            if (wrapper.find('#hb_ucs_sub_shipping_first_name').length) {
                updateAddressDisplay('shipping');
            }
        }
    }

    function copyBillingToShipping() {
        var pairs = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'postcode', 'city', 'state', 'country'];
        $.each(pairs, function(index, key) {
            $('#hb_ucs_sub_shipping_' + key).val($('#hb_ucs_sub_billing_' + key).val()).trigger('change');
        });
        updateAddressDisplay('shipping');
    }

    $(document.body).on('click', '#order_data a.edit_address', function(event) {
        event.preventDefault();
        toggleAddressEdit($(this));
    });
    $(document.body).on('click', '#order_data a.billing-same-as-shipping', function(event) {
        event.preventDefault();
        copyBillingToShipping();
    });
    $(document.body).on('click', '#order_data a.load_customer_billing', function(event) {
        event.preventDefault();
        loadCustomerAddress('billing');
    });
    $(document.body).on('click', '#order_data a.load_customer_shipping', function(event) {
        event.preventDefault();
        loadCustomerAddress('shipping');
    });
    $(document.body).on('click', '.edit-order-item', function(event) {
        event.preventDefault();
        var row = $(this).closest('tr.item, tr.fee, tr.shipping');
        setRowEditing(row, !row.hasClass('editing'), !row.hasClass('editing'));
    });
    $(document.body).on('click', '.delete-order-item', function(event) {
        event.preventDefault();
        var row = $(this).closest('tr.item, tr.fee, tr.shipping');
        row.find('.hb-ucs-item-remove').val('1');
        row.find('.hb-ucs-fee-remove').val('1');
        row.find('.hb-ucs-shipping-remove').val('1');
        row.remove();
        updateTotalsFromRows();
    });
    $(document.body).on('click', '#hb-ucs-show-add-item-actions', function(event) {
        event.preventDefault();
        showAddItemActions();
    });
    $(document.body).on('click', '#hb-ucs-add-order-item', function(event) {
        event.preventDefault();
        addItemRow();
    });
    $(document.body).on('click', '#hb-ucs-add-order-fee', function(event) {
        event.preventDefault();
        addManualRow('#tmpl-hb-ucs-subscription-fee-row', '#order_fee_line_items', 'tr.hb-ucs-sub-fee-row', 'fee');
    });
    $(document.body).on('click', '#hb-ucs-add-order-shipping', function(event) {
        event.preventDefault();
        addManualRow('#tmpl-hb-ucs-subscription-shipping-row', '#order_shipping_line_items', 'tr.hb-ucs-sub-shipping-row', 'shipping');
    });
    $(document.body).on('click', '#hb-ucs-add-order-tax', function(event) {
        event.preventDefault();
        openTaxModal();
    });
    $(document.body).on('click', '.hb-ucs-tax-modal-wrapper .modal-close', function(event) {
        event.preventDefault();
        closeTaxModal();
    });
    $(document.body).on('click', '.hb-ucs-tax-modal-wrapper #btn-ok', function(event) {
        var rateId = parseInt($('.hb-ucs-tax-modal-wrapper input[name="hb_ucs_add_tax_rate"]:checked').val() || $('.hb-ucs-tax-modal-wrapper #manual_tax_rate_id').val() || '0', 10),
            existingRates = $('.order-tax-id').map(function() { return String($(this).val() || ''); }).get();

        event.preventDefault();
        if (!rateId) {
            window.alert(cfg.selectTaxMessage);
            return;
        }
        if ($.inArray(String(rateId), existingRates) !== -1) {
            window.alert(cfg.duplicateTaxMessage);
            return;
        }
        updateTaxRate('add', rateId);
    });
    $(document.body).on('click', 'a.delete-order-tax', function(event) {
        var rateId = parseInt($(this).attr('data-rate_id') || '0', 10);
        event.preventDefault();
        if (!rateId) {
            return;
        }
        updateTaxRate('remove', rateId);
    });
    $(document.body).on('click', '#hb-ucs-cancel-order-actions', function(event) {
        event.preventDefault();
        hideAddItemActions(true);
    });
    $(document.body).on('click', '#hb-ucs-save-order-actions', function(event) {
        event.preventDefault();
        $('#post').trigger('submit');
    });
    $(document.body).on('click', '#hb-ucs-recalc-order-items', function(event) {
        event.preventDefault();
        refreshAllRows(false);
        $('#order_fee_line_items tr.hb-ucs-sub-fee-row').each(function() {
            syncManualChargeRow($(this), 'fee');
        });
        $('#order_shipping_line_items tr.hb-ucs-sub-shipping-row').each(function() {
            syncManualChargeRow($(this), 'shipping');
        });
    });
    $(document.body).on('change', '#hb_ucs_sub_scheme', function() {
        refreshAllRows(true);
    });
    $(document.body).on('change', '.hb-ucs-item-product', function() {
        var row = $(this).closest('tr.hb-ucs-sub-item-row');
        if (row.data('hbUcsSyncingProduct')) {
            return;
        }
        requestRowPreview(row, true);
    });
    $(document.body).on('change', '.hb-ucs-item-attr-select', function() {
        requestRowPreview($(this).closest('tr.hb-ucs-sub-item-row'), true);
    });
    $(document.body).on('input change', '.hb-ucs-item-qty, .hb-ucs-item-price', function() {
        requestRowPreview($(this).closest('tr.hb-ucs-sub-item-row'), false);
    });
    $(document.body).on('input change', '.hb-ucs-fee-name, .hb-ucs-fee-total, .hb-ucs-fee-tax-input, #order_fee_line_items .hb-ucs-tax-input', function() {
        syncManualChargeRow($(this).closest('tr.hb-ucs-sub-fee-row'), 'fee');
    });
    $(document.body).on('input change', '.hb-ucs-shipping-title, .hb-ucs-shipping-total, .hb-ucs-shipping-tax-input, #order_shipping_line_items .hb-ucs-tax-input', function() {
        syncManualChargeRow($(this).closest('tr.hb-ucs-sub-shipping-row'), 'shipping');
    });
    $(document.body).on('input change', '#order_data .edit_address :input', function() {
        var field = $(this),
            id = field.attr('id') || '',
            isTaxField = /_(country|state|postcode|city)$/.test(id);

        if (id.indexOf('hb_ucs_sub_billing_') === 0) {
            updateAddressDisplay('billing');
        }
        if (id.indexOf('hb_ucs_sub_shipping_') === 0) {
            updateAddressDisplay('shipping');
        }
        if (isTaxField) {
            refreshAllRows(false);
        }
    });

    updateTotalsFromRows();
    updateAddressDisplay('billing');
    updateAddressDisplay('shipping');
});
JS;
                $subscriptionAdminScript = str_replace(
                    ['__AJAX_URL__', '__NONCE__', '__DECIMALS__', '__CURRENCY_SYMBOL__', '__I18N_SELECT_TAX__', '__I18N_DUPLICATE_TAX__'],
                    [wp_json_encode(admin_url('admin-ajax.php')), wp_json_encode(wp_create_nonce('hb_ucs_subscription_admin')), (string) ((int) wc_get_price_decimals()), wp_json_encode(function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol() : '€'), wp_json_encode(__('Selecteer een belastingregel om toe te voegen.', 'hb-ucs')), wp_json_encode(__('Deze belastingregel is al toegevoegd.', 'hb-ucs'))],
                    $subscriptionAdminScriptTemplate
                );
                wp_add_inline_script('wc-enhanced-select', $subscriptionAdminScript);
            }
            wp_register_style('hb-ucs-subscriptions-admin-inline', false, [], $ver);
            wp_enqueue_style('hb-ucs-subscriptions-admin-inline');
            wp_add_inline_style('hb-ucs-subscriptions-admin-inline', '.post-type-hb_ucs_subscription #titlediv{display:none}.post-type-hb_ucs_subscription .wrap>h1.wp-heading-inline{margin-bottom:12px}.post-type-hb_ucs_subscription #hb_ucs_subscription_data .inside{margin:0;padding:0}.post-type-hb_ucs_subscription #hb_ucs_subscription_data .postbox-header{display:none}.post-type-hb_ucs_subscription .woocommerce-order-data .order_data_column .form-field>label{display:block;margin-bottom:4px}.post-type-hb_ucs_subscription .woocommerce-order-data .order_data_column .form-field>span{display:block}.post-type-hb_ucs_subscription .hb-ucs-subscription-order-data-summary{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}.post-type-hb_ucs_subscription .hb-ucs-subscription-order-data-summary__item{display:inline-flex;align-items:center;min-height:32px;padding:0 12px;border:1px solid #dcdcde;border-radius:2px;background:#fff;font-weight:600}.post-type-hb_ucs_subscription .woocommerce_order_items .quantity,.post-type-hb_ucs_subscription .woocommerce_order_items .line_cost,.post-type-hb_ucs_subscription .woocommerce_order_items .item_cost{width:10%}.post-type-hb_ucs_subscription .column-hb_ucs_status{width:120px}.post-type-hb_ucs_subscription .column-hb_ucs_total{width:170px}.post-type-hb_ucs_subscription .column-hb_ucs_next_payment,.post-type-hb_ucs_subscription .column-hb_ucs_last_order_date,.post-type-hb_ucs_subscription .column-hb_ucs_start_date{width:140px}.post-type-hb_ucs_subscription .wp-list-table .column-title .row-title{font-weight:600}.post-type-hb_ucs_subscription .wc-order-bulk-actions{display:flex;gap:8px;align-items:center;padding-top:12px}.post-type-hb_ucs_subscription tr.item .edit{display:none}.post-type-hb_ucs_subscription tr.item.editing .edit{display:block}.post-type-hb_ucs_subscription tr.item.editing .view{display:none}.post-type-hb_ucs_subscription #poststuff #hb_ucs_subscription_actions .inside,.post-type-hb_ucs_subscription #poststuff #hb_ucs_subscription_notes .inside{margin:0;padding:0}.post-type-hb_ucs_subscription #hb_ucs_subscription_actions ul.order_actions li{padding:6px 10px;box-sizing:border-box}.post-type-hb_ucs_subscription #hb_ucs_subscription_notes ul.order_notes{margin:0}.post-type-hb_ucs_subscription #hb_ucs_subscription_notes ul.order_notes li{padding:0 10px;margin:0}.post-type-hb_ucs_subscription #hb_ucs_subscription_notes .add_note{padding:12px 10px 10px;border-top:1px solid #dcdcde}.post-type-hb_ucs_subscription #hb_ucs_subscription_notes .add_note textarea{width:100%}.post-type-hb_ucs_subscription #hb_ucs_subscription_notes .add_note select{max-width:100%;margin-right:6px}@media (max-width:1280px){.post-type-hb_ucs_subscription .hb-ucs-subscription-order-data-summary{justify-content:flex-start}}');
            wp_add_inline_style('hb-ucs-subscriptions-admin-inline', '.post-type-hb_ucs_subscription #woocommerce-order-items.hb-ucs-order-items-metabox{margin:0}.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items .item_cost,.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items .quantity,.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items .line_cost,.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items .line_tax,.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items .line_total{width:1%;vertical-align:top}.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items td.name{width:auto;min-width:280px}.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items .wc-order-item-name{font-weight:600}.post-type-hb_ucs_subscription #woocommerce-order-items .woocommerce_order_items .wc-order-item-thumbnail img{width:38px;height:38px;object-fit:cover}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-sku-wrap.is-empty,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-variation-summary-wrap.is-empty{display:none}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-variation-fields{margin-top:12px;border-top:1px solid #dcdcde;padding-top:12px;display:grid;gap:10px}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-attribute-field label{display:block;margin-bottom:4px;font-weight:600}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-attribute-field select,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-price,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-product{width:100%}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-line-subtotal,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-line-tax,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-line-total,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-item-unit-view{white-space:nowrap}.post-type-hb_ucs_subscription #woocommerce-order-items .wc-order-totals .total{text-align:right}.post-type-hb_ucs_subscription #woocommerce-order-items .wc-order-totals .label{font-weight:500}.post-type-hb_ucs_subscription #woocommerce-order-items .wc-order-totals tr:last-child .label,.post-type-hb_ucs_subscription #woocommerce-order-items .wc-order-totals tr:last-child .total{font-weight:700}.post-type-hb_ucs_subscription .hb-ucs-address-preview p{margin:0 0 8px}.post-type-hb_ucs_subscription .order_data_column .address,.post-type-hb_ucs_subscription .order_data_column .edit_address{min-height:180px}');
            wp_add_inline_style('hb-ucs-subscriptions-admin-inline', '.post-type-hb_ucs_subscription .hb-ucs-subscription-address-column .address a{font-weight:500}.post-type-hb_ucs_subscription tr.fee .edit,.post-type-hb_ucs_subscription tr.shipping .edit{display:none}.post-type-hb_ucs_subscription tr.fee.editing .edit,.post-type-hb_ucs_subscription tr.shipping.editing .edit{display:block}.post-type-hb_ucs_subscription tr.fee.editing .view,.post-type-hb_ucs_subscription tr.shipping.editing .view{display:none}');
            wp_add_inline_style('hb-ucs-subscriptions-admin-inline', '.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-sub-shipping-row .wc-order-item-thumbnail,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-sub-fee-row .wc-order-item-thumbnail{display:flex;align-items:center;justify-content:center;min-height:38px;color:#646970}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-sub-shipping-row .dashicons,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-sub-fee-row .dashicons{font-size:18px;width:18px;height:18px}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-shipping-meta{margin-top:4px;color:#646970}.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-sub-fee-row .edit input,.post-type-hb_ucs_subscription #woocommerce-order-items .hb-ucs-sub-shipping-row .edit input{width:100%}.post-type-hb_ucs_subscription #woocommerce-order-items .delete-order-tax{display:inline-block;width:16px;height:16px;margin-left:6px;vertical-align:middle;text-decoration:none}.post-type-hb_ucs_subscription #woocommerce-order-items .delete-order-tax:before{content:"\00d7";font-size:16px;line-height:16px;color:#b32d2e}.hb-ucs-tax-modal-wrapper{position:relative;z-index:100000}.hb-ucs-tax-modal-wrapper .wc-backbone-modal-content{max-width:800px}.hb-ucs-tax-modal-wrapper .widefat td:first-child,.hb-ucs-tax-modal-wrapper .widefat th:first-child{width:32px}');
            return;
        }

        $isOrderListScreen = ($hook === 'edit.php' && $screen->post_type === 'shop_order') || $hook === 'woocommerce_page_wc-orders' || (isset($screen->id) && (string) $screen->id === 'woocommerce_page_wc-orders');
        if ($isOrderListScreen) {
            wp_register_style('hb-ucs-subscriptions-admin-inline', false, [], $ver);
            wp_enqueue_style('hb-ucs-subscriptions-admin-inline');
            wp_add_inline_style('hb-ucs-subscriptions-admin-inline', '.column-hb_ucs_subscription,.manage-column.column-hb_ucs_subscription{width:56px;text-align:center}.manage-column.column-hb_ucs_subscription{padding-inline:8px}.hb-ucs-order-subscription-header-icon{display:block;width:18px;height:18px;margin:0 auto;font-size:18px;line-height:18px}.hb-ucs-order-subscription-indicator{display:inline-flex;align-items:center;justify-content:center;min-width:24px;min-height:24px;color:#2271b1;text-decoration:none}.hb-ucs-order-subscription-indicator .dashicons{width:18px;height:18px;font-size:18px}.hb-ucs-order-subscription-indicator--renewal{color:#7f54b3}.hb-ucs-order-subscription-indicator--parent{color:#3858e9}.hb-ucs-order-subscription-indicator--unlinked{color:#646970}.hb-ucs-order-subscription-indicator:focus{box-shadow:none;outline:1px solid currentColor;outline-offset:2px}');
        }
    }

    public function filter_shop_order_subscription_columns(array $columns): array {
        if (isset($columns['hb_ucs_subscription'])) {
            return $columns;
        }

        $newColumns = [];
        $inserted = false;

        foreach ($columns as $key => $label) {
            $newColumns[$key] = $label;

            if (in_array((string) $key, ['order_status', 'status', 'order_number'], true)) {
                $newColumns['hb_ucs_subscription'] = '<span class="hb-ucs-order-subscription-header-icon dashicons dashicons-update" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Abonnement', 'hb-ucs') . '</span>';
                $inserted = true;
            }
        }

        if (!$inserted) {
            $newColumns['hb_ucs_subscription'] = '<span class="hb-ucs-order-subscription-header-icon dashicons dashicons-update" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__('Abonnement', 'hb-ucs') . '</span>';
        }

        return $newColumns;
    }

    public function render_shop_order_subscription_filters_legacy(string $postType): void {
        if ($postType !== 'shop_order') {
            return;
        }

        $this->render_shop_order_subscription_filter_dropdown();
    }

    public function render_shop_order_subscription_filters_hpos(string $orderType, string $which): void {
        if ($which !== 'top' || $orderType !== 'shop_order') {
            return;
        }

        $this->render_shop_order_subscription_filter_dropdown();
    }

    public function filter_shop_order_subscription_query_legacy($query): void {
        if (!($query instanceof \WP_Query) || !is_admin() || !$query->is_main_query()) {
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

        foreach ($this->get_shop_order_subscription_query_meta_clauses() as $metaClause) {
            $metaQuery[] = $metaClause;
        }

        if (!empty($metaQuery)) {
            $query->set('meta_query', $metaQuery);
        }
    }

    /**
     * @param array<string,mixed> $queryArgs
     * @return array<string,mixed>
     */
    public function filter_shop_order_subscription_query_hpos(array $queryArgs): array {
        if (!$this->is_hpos_shop_order_list_screen()) {
            return $queryArgs;
        }

        $metaQuery = isset($queryArgs['meta_query']) && is_array($queryArgs['meta_query']) ? $queryArgs['meta_query'] : [];
        foreach ($this->get_shop_order_subscription_query_meta_clauses() as $metaClause) {
            $metaQuery[] = $metaClause;
        }

        if (!empty($metaQuery)) {
            $queryArgs['meta_query'] = $metaQuery;
        }

        return $queryArgs;
    }

    public function render_shop_order_subscription_column_legacy(string $column, int $postId): void {
        if ($column !== 'hb_ucs_subscription') {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($postId) : null;
        $this->render_shop_order_subscription_indicator($order);
    }

    public function render_shop_order_subscription_column_hpos(string $column, $order): void {
        if ($column !== 'hb_ucs_subscription') {
            return;
        }

        $this->render_shop_order_subscription_indicator($order);
    }

    private function render_shop_order_subscription_indicator($order): void {
        $context = $this->get_shop_order_subscription_context($order);
        if (!$context['is_subscription_order']) {
            echo '&mdash;';
            return;
        }

        $type = (string) $context['type'];
        $subId = (int) $context['subscription_id'];
        $labelMap = [
            'parent' => __('Startbestelling van abonnement', 'hb-ucs'),
            'renewal' => __('Vervolgorder van abonnement', 'hb-ucs'),
            'subscription' => __('Abonnementsbestelling', 'hb-ucs'),
        ];
        $label = $labelMap[$type] ?? __('Abonnementsbestelling', 'hb-ucs');
        $classes = ['hb-ucs-order-subscription-indicator', 'hb-ucs-order-subscription-indicator--' . sanitize_html_class($type !== '' ? $type : 'subscription')];
        $icon = '<span class="dashicons dashicons-update" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html($label) . '</span>';

        if ($subId > 0 && current_user_can('edit_post', $subId)) {
            $link = $this->get_subscription_service()->get_preferred_editor_url($subId);
            if ($link === '') {
                $link = admin_url('post.php?post=' . $subId . '&action=edit');
            }
            echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($link) . '" title="' . esc_attr($label) . '">' . $icon . '</a>';
            return;
        }

        $classes[] = 'hb-ucs-order-subscription-indicator--unlinked';
        echo '<span class="' . esc_attr(implode(' ', $classes)) . '" title="' . esc_attr($label) . '">' . $icon . '</span>';
    }

    private function render_shop_order_subscription_filter_dropdown(): void {
        $current = $this->get_active_shop_order_subscription_filter();

        echo '<select name="' . esc_attr(self::SHOP_ORDER_SUBSCRIPTION_FILTER_PARAM) . '">';
        echo '<option value="">' . esc_html__('Alle bestellingen', 'hb-ucs') . '</option>';
        echo '<option value="regular" ' . selected($current, 'regular', false) . '>' . esc_html__('Alleen gewone bestellingen', 'hb-ucs') . '</option>';
        echo '<option value="subscription_related" ' . selected($current, 'subscription_related', false) . '>' . esc_html__('Alleen abonnement-gerelateerd', 'hb-ucs') . '</option>';
        echo '<option value="parent" ' . selected($current, 'parent', false) . '>' . esc_html__('Alleen startbestellingen', 'hb-ucs') . '</option>';
        echo '<option value="renewal" ' . selected($current, 'renewal', false) . '>' . esc_html__('Alleen renewal-orders', 'hb-ucs') . '</option>';
        echo '</select>';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function get_shop_order_subscription_query_meta_clauses(): array {
        $metaQuery = [];

        $subscriptionId = $this->get_requested_shop_order_subscription_id();
        if ($subscriptionId > 0) {
            $metaQuery[] = [
                'key' => self::ORDER_META_SUBSCRIPTION_ID,
                'value' => (string) $subscriptionId,
                'compare' => '=',
            ];
        }

        $filter = $this->get_active_shop_order_subscription_filter();
        if ($filter === '') {
            return $metaQuery;
        }

        if ($filter === 'regular') {
            $metaQuery[] = [
                'relation' => 'AND',
                [
                    'key' => self::ORDER_META_SUBSCRIPTION_ID,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'relation' => 'OR',
                    [
                        'key' => self::ORDER_META_CONTAINS_SUBSCRIPTION,
                        'compare' => 'NOT EXISTS',
                    ],
                    [
                        'key' => self::ORDER_META_CONTAINS_SUBSCRIPTION,
                        'value' => 'yes',
                        'compare' => '!=',
                    ],
                ],
            ];

            return $metaQuery;
        }

        if ($filter === 'subscription_related') {
            $metaQuery[] = [
                'relation' => 'OR',
                [
                    'key' => self::ORDER_META_SUBSCRIPTION_ID,
                    'compare' => 'EXISTS',
                ],
                [
                    'key' => self::ORDER_META_CONTAINS_SUBSCRIPTION,
                    'value' => 'yes',
                    'compare' => '=',
                ],
            ];

            return $metaQuery;
        }

        if ($filter === 'parent') {
            $metaQuery[] = [
                'key' => self::ORDER_META_CONTAINS_SUBSCRIPTION,
                'value' => 'yes',
                'compare' => '=',
            ];
            $metaQuery[] = [
                'relation' => 'OR',
                [
                    'key' => self::ORDER_META_RENEWAL,
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => self::ORDER_META_RENEWAL,
                    'value' => '1',
                    'compare' => '!=',
                ],
            ];

            return $metaQuery;
        }

        if ($filter === 'renewal') {
            $metaQuery[] = [
                'key' => self::ORDER_META_RENEWAL,
                'value' => '1',
                'compare' => '=',
            ];
        }

        return $metaQuery;
    }

    private function get_active_shop_order_subscription_filter(): string {
        $filter = isset($_GET[self::SHOP_ORDER_SUBSCRIPTION_FILTER_PARAM])
            ? sanitize_key(wp_unslash((string) $_GET[self::SHOP_ORDER_SUBSCRIPTION_FILTER_PARAM]))
            : '';

        return in_array($filter, ['regular', 'subscription_related', 'parent', 'renewal'], true) ? $filter : '';
    }

    private function get_requested_shop_order_subscription_id(): int {
        return isset($_GET['hb_ucs_subscription_id']) ? absint(wp_unslash((string) $_GET['hb_ucs_subscription_id'])) : 0;
    }

    private function is_hpos_shop_order_list_screen(): bool {
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

    private function get_shop_order_subscription_context($order): array {
        $default = [
            'is_subscription_order' => false,
            'subscription_id' => 0,
            'type' => '',
        ];

        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return $default;
        }

        $orderId = (int) $order->get_id();
        if ($orderId <= 0) {
            return $default;
        }

        if (isset($this->shopOrderSubscriptionContextCache[$orderId])) {
            return $this->shopOrderSubscriptionContextCache[$orderId];
        }

        $cacheKey = 'shop_order_subscription_context_' . $orderId;
        $cached = wp_cache_get($cacheKey, 'hb_ucs');
        if (is_array($cached)) {
            $this->shopOrderSubscriptionContextCache[$orderId] = array_merge($default, $cached);
            return $this->shopOrderSubscriptionContextCache[$orderId];
        }

        $subId = method_exists($order, 'get_meta') ? (int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true) : 0;
        $isRenewal = method_exists($order, 'get_meta') && (string) $order->get_meta(self::ORDER_META_RENEWAL, true) === '1';
        $containsSubscription = method_exists($order, 'get_meta') && (string) $order->get_meta(self::ORDER_META_CONTAINS_SUBSCRIPTION, true) === 'yes';
        if ($subId > 0) {
            $context = [
                'is_subscription_order' => true,
                'subscription_id' => $subId,
                'type' => $isRenewal ? 'renewal' : ($containsSubscription ? 'parent' : 'subscription'),
            ];
            $this->shopOrderSubscriptionContextCache[$orderId] = $context;
            wp_cache_set($cacheKey, $context, 'hb_ucs', MINUTE_IN_SECONDS * 10);

            return $context;
        }

        if ($containsSubscription) {
            $context = [
                'is_subscription_order' => true,
                'subscription_id' => 0,
                'type' => 'parent',
            ];
            $this->shopOrderSubscriptionContextCache[$orderId] = $context;
            wp_cache_set($cacheKey, $context, 'hb_ucs', MINUTE_IN_SECONDS * 10);

            return $context;
        }

        $this->shopOrderSubscriptionContextCache[$orderId] = $default;
        wp_cache_set($cacheKey, $default, 'hb_ucs', MINUTE_IN_SECONDS * 5);

        return $default;
    }

    public function enqueue_frontend_assets(): void {
        $isProductPage = function_exists('is_singular') && is_singular('product');
        $isSubscriptionsEndpoint = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(self::ACCOUNT_ENDPOINT);
        if (!$isSubscriptionsEndpoint && function_exists('get_query_var')) {
            $endpointValue = get_query_var(self::ACCOUNT_ENDPOINT, null);
            $isSubscriptionsEndpoint = $endpointValue !== null;
        }
        $isAccountSubscriptionsPage = $isSubscriptionsEndpoint || $this->current_page_has_subscriptions_shortcode();
        if (!$isProductPage && !$isAccountSubscriptionsPage) {
            return;
        }

        $productId = 0;
        if ($isProductPage) {
            $productId = function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0;
            if ($productId <= 0 || get_post_type($productId) !== 'product') {
                return;
            }
            if (get_post_meta($productId, self::META_ENABLED, true) !== 'yes') {
                return;
            }
        }

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 4) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/assets/', $pluginFile));
        $assetVersion = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';

        $deps = ['jquery'];
        if ($isProductPage && (wp_script_is('wc-add-to-cart-variation', 'registered') || wp_script_is('wc-add-to-cart-variation', 'enqueued'))) {
            $deps[] = 'wc-add-to-cart-variation';
        }
        if ($isAccountSubscriptionsPage && (wp_script_is('selectWoo', 'registered') || wp_script_is('selectWoo', 'enqueued'))) {
            $deps[] = 'selectWoo';
            if (wp_style_is('select2', 'registered') || wp_style_is('select2', 'enqueued')) {
                wp_enqueue_style('select2');
            }
        }

        wp_enqueue_style('hb-ucs-subscriptions-frontend-style', $base . 'frontend-hb-ucs-subscriptions.css', [], $assetVersion);
        wp_enqueue_script('hb-ucs-subscriptions-frontend', $base . 'frontend-hb-ucs-subscriptions.js', $deps, $assetVersion, true);
        wp_localize_script('hb-ucs-subscriptions-frontend', 'hbUcsSubscriptionsFrontend', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('hb_ucs_subscription_frontend'),
            'searchAction' => 'hb_ucs_subscription_product_picker_search',
            'searchDebounceMs' => 220,
        ]);
    }

    private function current_page_has_subscriptions_shortcode(): bool {
        if (!function_exists('is_singular') || !is_singular()) {
            return false;
        }

        $post = get_post();
        if (!$post || !isset($post->post_content) || !is_string($post->post_content)) {
            return false;
        }

        return function_exists('has_shortcode') && has_shortcode($post->post_content, 'hb_ucs_subscriptions_account');
    }

    private function get_discount_settings(int $entityId, string $scheme, int $fallbackEntityId = 0): array {
        $scheme = sanitize_key($scheme);
        $enabledKey = self::META_DISC_ENABLED_PREFIX . $scheme;
        $typeKey = self::META_DISC_TYPE_PREFIX . $scheme;
        $valueKey = self::META_DISC_VALUE_PREFIX . $scheme;

        $enabledRaw = get_post_meta($entityId, $enabledKey, true);
        $typeRaw = (string) get_post_meta($entityId, $typeKey, true);
        $valueRaw = (string) get_post_meta($entityId, $valueKey, true);

        if ($enabledRaw === '' && $typeRaw === '' && $valueRaw === '' && $fallbackEntityId > 0) {
            $enabledRaw = get_post_meta($fallbackEntityId, $enabledKey, true);
            $typeRaw = (string) get_post_meta($fallbackEntityId, $typeKey, true);
            $valueRaw = (string) get_post_meta($fallbackEntityId, $valueKey, true);
        }

        $enabled = ((string) $enabledRaw) === '1';
        $type = $typeRaw !== '' ? sanitize_key($typeRaw) : 'percent';
        if ($type !== 'percent' && $type !== 'fixed') {
            $type = 'percent';
        }

        $value = 0.0;
        if ($valueRaw !== '') {
            $value = (float) wc_format_decimal($valueRaw);
        }

        return [
            'enabled' => $enabled,
            'type' => $type,
            'value' => $value,
        ];
    }

    private function calculate_discounted_price(float $basePrice, array $discount): float {
        $price = $basePrice;
        if (!empty($discount['enabled'])) {
            $type = (string) ($discount['type'] ?? 'percent');
            $value = (float) ($discount['value'] ?? 0);

            if ($type === 'percent') {
                $price = $basePrice - ($basePrice * max(0.0, $value) / 100.0);
            } else {
                $price = $basePrice - max(0.0, $value);
            }
        }

        if ($price < 0) {
            $price = 0.0;
        }

        return (float) wc_format_decimal($price);
    }

    private function get_subscription_pricing(int $entityId, float $basePrice, string $scheme, int $fallbackEntityId = 0): array {
        $scheme = sanitize_key($scheme);

        // Backward compatibility: explicit price override wins.
        $fixedRaw = (string) get_post_meta($entityId, self::META_PRICE_PREFIX . $scheme, true);
        if ($fixedRaw === '' && $fallbackEntityId > 0) {
            $fixedRaw = (string) get_post_meta($fallbackEntityId, self::META_PRICE_PREFIX . $scheme, true);
        }

        $fixed = null;
        if ($fixedRaw !== '') {
            $fixedDisplay = (float) wc_format_decimal($fixedRaw);
            $fixedProductId = $entityId > 0 ? $entityId : $fallbackEntityId;
            $fixedProduct = $fixedProductId > 0 ? wc_get_product($fixedProductId) : false;
            $fixed = $this->get_product_price_storage_amount($fixedProduct, $fixedDisplay, 1);
        }

        $discount = $this->get_discount_settings($entityId, $scheme, $fallbackEntityId);

        $final = $fixed !== null ? $fixed : $this->calculate_discounted_price($basePrice, $discount);
        $final = (float) wc_format_decimal($final);

        $hasDiscount = ($basePrice > 0.0 && $final >= 0.0 && $final < $basePrice);
        $badge = '';
        if ($hasDiscount) {
            if ($fixed !== null) {
                $pct = (int) round((1 - ($final / $basePrice)) * 100);
                if ($pct > 0) {
                    $badge = sprintf(__('-%d%%', 'hb-ucs'), $pct);
                }
            } else {
                if (!empty($discount['enabled'])) {
                    if ($discount['type'] === 'percent') {
                        $badge = sprintf(__('-%s%%', 'hb-ucs'), rtrim(rtrim(wc_format_decimal($discount['value']), '0'), '.'));
                    } else {
                        $val = (float) $discount['value'];
                        $badge = '-' . get_woocommerce_currency_symbol() . wc_format_decimal($val);
                    }
                }
            }
        }

        return [
            'base' => $basePrice,
            'final' => $final,
            'has_discount' => $hasDiscount,
            'badge' => $badge,
            'discount' => $discount,
            'fixed' => $fixed,
        ];
    }

    private function get_current_product_display_price($product): ?float {
        if (!$product || !is_object($product) || !method_exists($product, 'get_price')) {
            return null;
        }

        $priceRaw = (string) $product->get_price();
        if ($priceRaw === '') {
            return null;
        }

        $price = (float) wc_format_decimal($priceRaw);
        if ($price <= 0) {
            return $price;
        }

        $includeTax = $this->should_display_subscription_prices_including_tax();
        if (get_option('woocommerce_prices_include_tax', 'no') === 'yes' && $includeTax) {
            return $price;
        }

        $storagePrice = $this->get_product_current_storage_price($product);
        if ($storagePrice === null) {
            return null;
        }

        return $this->get_product_price_display_amount($product, $storagePrice, 1, $includeTax);
    }

    private function get_product_page_subscription_pricing(int $entityId, $product, string $scheme, int $fallbackEntityId = 0): ?array {
        if (!$product || !is_object($product)) {
            return null;
        }

        $displayBasePrice = $this->get_current_product_display_price($product);
        if ($displayBasePrice === null) {
            return null;
        }

        $includeTax = $this->should_display_subscription_prices_including_tax();
        $pricesIncludeTax = get_option('woocommerce_prices_include_tax', 'no') === 'yes';

        if (!$pricesIncludeTax || !$includeTax) {
            $storageBasePrice = $this->get_product_current_storage_price($product);
            if ($storageBasePrice === null) {
                return null;
            }

            $pricing = $this->get_subscription_pricing($entityId, $storageBasePrice, $scheme, $fallbackEntityId);
            $pricing['base'] = $this->get_product_price_display_amount($product, (float) $pricing['base'], 1, $includeTax);
            $pricing['final'] = $this->get_product_price_display_amount($product, (float) $pricing['final'], 1, $includeTax);

            return $pricing;
        }

        $scheme = sanitize_key($scheme);
        $fixedRaw = (string) get_post_meta($entityId, self::META_PRICE_PREFIX . $scheme, true);
        if ($fixedRaw === '' && $fallbackEntityId > 0) {
            $fixedRaw = (string) get_post_meta($fallbackEntityId, self::META_PRICE_PREFIX . $scheme, true);
        }

        $fixed = $fixedRaw !== '' ? (float) wc_format_decimal($fixedRaw) : null;
        $discount = $this->get_discount_settings($entityId, $scheme, $fallbackEntityId);

        $final = $fixed !== null ? $fixed : $this->calculate_discounted_price($displayBasePrice, $discount);
        $final = (float) wc_format_decimal($final);

        $hasDiscount = ($displayBasePrice > 0.0 && $final >= 0.0 && $final < $displayBasePrice);
        $badge = '';
        if ($hasDiscount) {
            if ($fixed !== null) {
                $pct = (int) round((1 - ($final / $displayBasePrice)) * 100);
                if ($pct > 0) {
                    $badge = sprintf(__('-%d%%', 'hb-ucs'), $pct);
                }
            } elseif (!empty($discount['enabled'])) {
                if ($discount['type'] === 'percent') {
                    $badge = sprintf(__('-%s%%', 'hb-ucs'), rtrim(rtrim(wc_format_decimal($discount['value']), '0'), '.'));
                } else {
                    $val = (float) $discount['value'];
                    $badge = '-' . get_woocommerce_currency_symbol() . wc_format_decimal($val);
                }
            }
        }

        return [
            'base' => $displayBasePrice,
            'final' => $final,
            'has_discount' => $hasDiscount,
            'badge' => $badge,
            'discount' => $discount,
            'fixed' => $fixed,
        ];
    }

    private function get_manual_cart_subscription_pricing(int $entityId, $product, string $scheme, int $fallbackEntityId = 0): ?array {
        if (!$product || !is_object($product) || !method_exists($product, 'get_price')) {
            return null;
        }

        $pricesIncludeTax = get_option('woocommerce_prices_include_tax', 'no') === 'yes';
        if (!$pricesIncludeTax) {
            $storageBasePrice = $this->get_product_current_storage_price($product);
            if ($storageBasePrice === null) {
                return null;
            }

            return $this->get_subscription_pricing($entityId, $storageBasePrice, $scheme, $fallbackEntityId);
        }

        $priceRaw = (string) $product->get_price();
        if ($priceRaw === '') {
            return null;
        }

        $basePrice = (float) wc_format_decimal($priceRaw);
        $scheme = sanitize_key($scheme);

        $fixedRaw = (string) get_post_meta($entityId, self::META_PRICE_PREFIX . $scheme, true);
        if ($fixedRaw === '' && $fallbackEntityId > 0) {
            $fixedRaw = (string) get_post_meta($fallbackEntityId, self::META_PRICE_PREFIX . $scheme, true);
        }

        $fixed = $fixedRaw !== '' ? (float) wc_format_decimal($fixedRaw) : null;
        $discount = $this->get_discount_settings($entityId, $scheme, $fallbackEntityId);
        $final = $fixed !== null ? $fixed : $this->calculate_discounted_price($basePrice, $discount);

        return [
            'base' => (float) wc_format_decimal((string) $basePrice),
            'final' => (float) wc_format_decimal((string) $final),
            'discount' => $discount,
            'fixed' => $fixed,
        ];
    }

    private function get_settings(): array {
        if (is_array($this->settingsCache)) {
            return $this->settingsCache;
        }

        $opt = get_option(Settings::OPT_SUBSCRIPTIONS, []);
        if (!is_array($opt)) {
            $opt = [];
        }

        $engine = 'manual';

        $recurringEnabled = empty($opt['recurring_enabled']) ? 0 : 1;
        $webhookToken = isset($opt['recurring_webhook_token']) ? (string) $opt['recurring_webhook_token'] : '';
        $webhookToken = trim($webhookToken);

        $freqsRaw = isset($opt['frequencies']) && is_array($opt['frequencies']) ? $opt['frequencies'] : [];
        $freqs = [];
        foreach (['1w' => 1, '2w' => 2, '3w' => 3, '4w' => 4, '5w' => 5, '6w' => 6, '7w' => 7, '8w' => 8] as $key => $interval) {
            $row = isset($freqsRaw[$key]) && is_array($freqsRaw[$key]) ? $freqsRaw[$key] : [];
            $freqs[$key] = [
                'enabled' => empty($row['enabled']) ? 0 : 1,
                'label' => isset($row['label']) ? (string) $row['label'] : $key,
                'interval' => (int) $interval,
                'period' => 'week',
            ];
        }
        $this->settingsCache = [
            'engine' => $engine,
            'recurring_enabled' => $recurringEnabled,
            'recurring_webhook_token' => $webhookToken,
            'debug_logging_enabled' => empty($opt['debug_logging_enabled']) ? 0 : 1,
            'product_picker_loop_template_id' => isset($opt['product_picker_loop_template_id']) ? max(0, (int) $opt['product_picker_loop_template_id']) : 0,
            'delete_data_on_uninstall' => empty($opt['delete_data_on_uninstall']) ? 0 : 1,
            'frequencies' => $freqs,
        ];

        return $this->settingsCache;
    }

    public function maybe_create_subscriptions_from_order(int $orderId): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order || !is_object($order)) {
            return;
        }

        if ($this->is_checkout_draft_order($order)) {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_META_RECURRING_CREATED, true) === '1') {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_META_RENEWAL, true) === '1') {
            return;
        }

        $existingSubscriptionIds = $this->get_existing_subscription_ids_for_parent_order($orderId);
        if (!empty($existingSubscriptionIds)) {
            $order->update_meta_data(self::ORDER_META_RECURRING_CREATED, '1');
            $order->update_meta_data(self::ORDER_META_CONTAINS_SUBSCRIPTION, 'yes');
            if ((int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true) <= 0) {
                $order->update_meta_data(self::ORDER_META_SUBSCRIPTION_ID, (string) (int) $existingSubscriptionIds[0]);
            }
            $order->save();
            return;
        }

        $payment = $this->get_order_payment_method_data($order);
        $paymentMethod = (string) ($payment['method'] ?? '');
        $paymentMethodTitle = (string) ($payment['title'] ?? '');
        $requiresMandate = $this->payment_method_requires_mandate($paymentMethod);

        $mollieContext = $this->extract_mollie_context_from_order($order, $requiresMandate);
        $mCustomer = (string) ($mollieContext['customerId'] ?? '');
        $mMandate = (string) ($mollieContext['mandateId'] ?? '');

        $createdAny = false;
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!is_object($item) || !method_exists($item, 'get_meta')) {
                continue;
            }
            $scheme = (string) $item->get_meta('_hb_ucs_subscription_scheme', true);
            if ($scheme === '' || $scheme === '0') {
                continue;
            }

            $baseProductId = (int) $item->get_meta('_hb_ucs_subscription_base_product_id', true);
            $baseVariationId = (int) $item->get_meta('_hb_ucs_subscription_base_variation_id', true);
            if ($baseProductId <= 0) {
                continue;
            }

            $qty = (int) (method_exists($item, 'get_quantity') ? $item->get_quantity() : 1);
            if ($qty <= 0) {
                $qty = 1;
            }
            $lineTotal = (float) (method_exists($item, 'get_total') ? $item->get_total() : 0.0);
            $unit = $qty > 0 ? ($lineTotal / $qty) : $lineTotal;

            $schedule = $this->resolve_subscription_schedule((string) $scheme);
            $scheme = (string) $schedule['scheme'];
            $interval = (int) $schedule['interval'];
            $period = (string) $schedule['period'];

            $paidDate = method_exists($order, 'get_date_paid') ? $order->get_date_paid() : null;
            $startTs = $paidDate ? (int) $paidDate->getTimestamp() : time();
            $nextTs = $startTs + ($interval * WEEK_IN_SECONDS);

            $subPostId = wp_insert_post([
                'post_type' => $this->get_subscription_order_type()->get_type(),
                'post_status' => 'wc-pending',
                'post_title' => $this->build_subscription_title_from_order($order, (int) $orderId),
            ], true);

            if (is_wp_error($subPostId) || (int) $subPostId <= 0) {
                continue;
            }
            $subId = (int) $subPostId;

            $status = 'active';
            if ($requiresMandate && ($mCustomer === '' || $mMandate === '')) {
                $status = 'pending_mandate';
            }

            update_post_meta($subId, self::SUB_META_STATUS, $status);
            update_post_meta($subId, self::SUB_META_USER_ID, (string) (int) $order->get_user_id());
            update_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, (string) $orderId);
            update_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID, (string) $baseProductId);
            update_post_meta($subId, self::SUB_META_BASE_VARIATION_ID, (string) $baseVariationId);
            update_post_meta($subId, self::SUB_META_SCHEME, (string) $scheme);
            update_post_meta($subId, self::SUB_META_INTERVAL, (string) $interval);
            update_post_meta($subId, self::SUB_META_PERIOD, (string) $period);
            update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) $nextTs);
            update_post_meta($subId, '_hb_ucs_subscription_status', $status);
            update_post_meta($subId, '_hb_ucs_subscription_scheme', (string) $scheme);
            update_post_meta($subId, '_hb_ucs_subscription_interval', (string) $interval);
            update_post_meta($subId, '_hb_ucs_subscription_period', (string) $period);
            update_post_meta($subId, '_hb_ucs_subscription_next_payment', (string) $nextTs);
            // Start order is also the initial 'last order'.
            update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) $orderId);
            update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $startTs);
            update_post_meta($subId, self::SUB_META_UNIT_PRICE, (string) wc_format_decimal($unit));
            update_post_meta($subId, self::SUB_META_QTY, (string) $qty);
            $selectedAttributes = $this->get_selected_attributes_from_order_item($item, $baseProductId, $baseVariationId);
            $displayMeta = $this->get_display_meta_rows_from_order_item($item, $baseProductId, $selectedAttributes);

            $this->persist_subscription_items($subId, [[
                'base_product_id' => $baseProductId,
                'base_variation_id' => $baseVariationId,
                'source_order_item_id' => method_exists($item, 'get_id') ? (int) $item->get_id() : 0,
                'qty' => $qty,
                'unit_price' => $unit,
                'price_includes_tax' => 0,
                'taxes' => method_exists($item, 'get_taxes') ? (array) $item->get_taxes() : [],
                'selected_attributes' => $selectedAttributes,
                'display_meta' => $displayMeta,
                'line_subtotal' => method_exists($item, 'get_subtotal') ? (float) $item->get_subtotal() : (float) $item->get_total(),
                'line_tax' => method_exists($item, 'get_total_tax') ? (float) $item->get_total_tax() : 0.0,
                'line_total' => (float) (method_exists($item, 'get_total') ? $item->get_total() : 0.0)
                    + (float) (method_exists($item, 'get_total_tax') ? $item->get_total_tax() : 0.0),
            ]]);
            $this->persist_subscription_fee_lines($subId, $this->extract_subscription_fee_lines($order));
            $this->persist_subscription_shipping_lines($subId, $this->extract_subscription_shipping_lines($order));
            update_post_meta($subId, self::SUB_META_PAYMENT_METHOD, $paymentMethod);
            update_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, $paymentMethodTitle);
            update_post_meta($subId, '_payment_method', $paymentMethod);
            update_post_meta($subId, '_payment_method_title', $paymentMethodTitle);
            update_post_meta($subId, self::SUB_META_BILLING, $this->get_subscription_address_snapshot($subId, 'billing', (int) $order->get_user_id(), $order));
            update_post_meta($subId, self::SUB_META_SHIPPING, $this->get_subscription_address_snapshot($subId, 'shipping', (int) $order->get_user_id(), $order));
            if ($mCustomer !== '') {
                update_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $mCustomer);
            }
            if ($mMandate !== '') {
                update_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, $mMandate);
            }
            if (!empty($mollieContext['paymentId'])) {
                update_post_meta($subId, self::SUB_META_LAST_PAYMENT_ID, (string) $mollieContext['paymentId']);
            }

            $this->hydrate_subscription_order_customer_data($subId, (int) $order->get_user_id(), $order);
            $this->sync_subscription_order_type_record($subId);
            $this->hydrate_subscription_order_payment_data($subId, $paymentMethod, $paymentMethodTitle, $mollieContext, $order);

            if ((int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true) <= 0) {
                $order->update_meta_data(self::ORDER_META_SUBSCRIPTION_ID, (string) $subId);
            }
            $order->update_meta_data(self::ORDER_META_CONTAINS_SUBSCRIPTION, 'yes');

            $createdAny = true;
        }

        if ($createdAny) {
            $order->update_meta_data(self::ORDER_META_RECURRING_CREATED, '1');
            $order->save();
            $order->add_order_note(__('HB UCS: abonnement record(s) aangemaakt voor automatische verlenging.', 'hb-ucs'));
        }
    }

    private function get_existing_subscription_ids_for_parent_order(int $orderId): array {
        if ($orderId <= 0 || !function_exists('wc_get_orders')) {
            return [];
        }

        $subscriptionIds = wc_get_orders([
            'type' => $this->get_subscription_order_type()->get_type(),
            'limit' => 20,
            'return' => 'ids',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_key' => self::SUB_META_PARENT_ORDER_ID,
            'meta_value' => (string) $orderId,
        ]);

        if (!is_array($subscriptionIds)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', $subscriptionIds)));
    }

    public function process_due_renewals(): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }

        $now = time();
        $batchSize = max(1, (int) apply_filters('hb_ucs_subscription_renewal_batch_size', 25));
        $maxPerRun = max($batchSize, (int) apply_filters('hb_ucs_subscription_renewal_max_per_run', 250));
        $processedPendingIds = [];

        // First: try to activate subscriptions that are waiting for a Mollie mandate.
        while (count($processedPendingIds) < $maxPerRun) {
            $pending = $this->get_pending_mandate_subscription_batch(
                min($batchSize, $maxPerRun - count($processedPendingIds)),
                array_keys($processedPendingIds)
            );

            if (empty($pending)) {
                break;
            }

            foreach ($pending as $subId) {
                $subId = (int) $subId;
                if ($subId <= 0) {
                    continue;
                }

                $processedPendingIds[$subId] = true;

                $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
                $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
                if (!$parentOrder) {
                    continue;
                }

                $mCustomer = (string) $parentOrder->get_meta('_mollie_customer_id', true);
                $mMandate = (string) $parentOrder->get_meta('_mollie_mandate_id', true);
                if ($mCustomer === '' || $mMandate === '') {
                    $molliePaymentId = (string) $parentOrder->get_meta('_mollie_payment_id', true);
                    if ($molliePaymentId !== '') {
                        $cm = $this->mollie_get_customer_and_mandate($molliePaymentId);
                        if ($mCustomer === '' && $cm['customerId'] !== '') {
                            $mCustomer = $cm['customerId'];
                        }
                        if ($mMandate === '' && $cm['mandateId'] !== '') {
                            $mMandate = $cm['mandateId'];
                        }
                    }
                }
                if ($mCustomer !== '' && $mMandate !== '') {
                    $mollieMetaChanged = false;
                    $mollieMetaChanged = $this->update_subscription_post_meta_if_changed($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $mCustomer) || $mollieMetaChanged;
                    $mollieMetaChanged = $this->update_subscription_post_meta_if_changed($subId, self::SUB_META_MOLLIE_MANDATE_ID, $mMandate) || $mollieMetaChanged;

                    $blockingStatus = $this->get_subscription_runtime_blocking_status($subId);
                    $runtimeStateChanged = false;
                    if ($blockingStatus !== '') {
                        $runtimeStateChanged = $this->persist_subscription_runtime_state($subId, $blockingStatus);
                    } else {
                        $runtimeStateChanged = $this->persist_subscription_runtime_state($subId, 'active');
                    }

                    $paymentDataChanged = $this->hydrate_subscription_order_payment_data($subId, '', '', [
                        'customerId' => $mCustomer,
                        'mandateId' => $mMandate,
                    ], $parentOrder);

                    if ($mollieMetaChanged || $runtimeStateChanged || $paymentDataChanged) {
                        $this->sync_subscription_order_type_record($subId);
                    }
                }
            }
        }

        $processedRecoveryIds = [];
        while (count($processedRecoveryIds) < $maxPerRun) {
            $recoverable = $this->get_recoverable_due_subscription_batch(
                $now,
                min($batchSize, $maxPerRun - count($processedRecoveryIds)),
                array_keys($processedRecoveryIds)
            );

            if (empty($recoverable)) {
                break;
            }

            foreach ($recoverable as $subId) {
                $subId = (int) $subId;
                if ($subId <= 0) {
                    continue;
                }

                $processedRecoveryIds[$subId] = true;
                $this->maybe_recover_due_subscription_state($subId);
            }
        }

        $processedDueIds = [];
        while (count($processedDueIds) < $maxPerRun) {
            $q = $this->get_due_renewal_subscription_batch(
                $now,
                min($batchSize, $maxPerRun - count($processedDueIds)),
                array_keys($processedDueIds)
            );

            if (empty($q)) {
                break;
            }

            foreach ($q as $subId) {
                $subId = (int) $subId;
                if ($subId <= 0) {
                    continue;
                }

                $processedDueIds[$subId] = true;

                if (!$this->subscription_is_eligible_for_auto_renewal($subId)) {
                    continue;
                }

                $result = $this->create_renewal_order_and_payment($subId, false);
                if (is_wp_error($result)) {
                    $this->persist_subscription_runtime_state($subId, 'on-hold');
                    $this->sync_subscription_order_type_record($subId);
                }
            }
        }
    }

    private function get_pending_mandate_subscription_batch(int $limit, array $excludeIds = []): array {
        if ($limit <= 0 || !function_exists('wc_get_orders')) {
            return [];
        }

        $queryArgs = [
            'type' => $this->get_subscription_order_type()->get_type(),
            'limit' => $limit,
            'return' => 'ids',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_query' => [
                [
                    'key' => self::SUB_META_STATUS,
                    'value' => 'pending_mandate',
                    'compare' => '=',
                ],
            ],
        ];

        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds)));
        if (!empty($excludeIds)) {
            $queryArgs['exclude'] = $excludeIds;
        }

        return array_values(array_filter(array_map('intval', (array) wc_get_orders($queryArgs))));
    }

    private function get_due_renewal_subscription_batch(int $now, int $limit, array $excludeIds = []): array {
        if ($limit <= 0 || !function_exists('wc_get_orders')) {
            return [];
        }

        $queryArgs = [
            'type' => $this->get_subscription_order_type()->get_type(),
            'limit' => $limit,
            'return' => 'ids',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_key' => self::SUB_META_NEXT_PAYMENT,
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => self::SUB_META_STATUS,
                    'value' => ['active'],
                    'compare' => 'IN',
                ],
                [
                    'key' => self::SUB_META_NEXT_PAYMENT,
                    'value' => (string) $now,
                    'type' => 'NUMERIC',
                    'compare' => '<=',
                ],
            ],
        ];

        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds)));
        if (!empty($excludeIds)) {
            $queryArgs['exclude'] = $excludeIds;
        }

        return array_values(array_filter(array_map('intval', (array) wc_get_orders($queryArgs))));
    }

    private function get_recoverable_due_subscription_batch(int $now, int $limit, array $excludeIds = []): array {
        if ($limit <= 0 || !function_exists('wc_get_orders')) {
            return [];
        }

        $queryArgs = [
            'type' => $this->get_subscription_order_type()->get_type(),
            'limit' => $limit,
            'return' => 'ids',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_key' => self::SUB_META_NEXT_PAYMENT,
            'orderby' => 'meta_value_num',
            'order' => 'ASC',
            'meta_query' => [
                [
                    'key' => self::SUB_META_STATUS,
                    'value' => ['payment_pending', 'pending_mandate'],
                    'compare' => 'IN',
                ],
                [
                    'key' => self::SUB_META_NEXT_PAYMENT,
                    'value' => (string) $now,
                    'type' => 'NUMERIC',
                    'compare' => '<=',
                ],
            ],
        ];

        $excludeIds = array_values(array_filter(array_map('intval', $excludeIds)));
        if (!empty($excludeIds)) {
            $queryArgs['exclude'] = $excludeIds;
        }

        return array_values(array_filter(array_map('intval', (array) wc_get_orders($queryArgs))));
    }

    private function maybe_recover_due_subscription_state(int $subId): void {
        if ($subId <= 0) {
            return;
        }

        $statuses = $this->get_subscription_runtime_statuses($subId);
        if (empty($statuses)) {
            return;
        }

        $hasPendingMandate = in_array('pending_mandate', $statuses, true);
        $hasPaymentPending = in_array('payment_pending', $statuses, true);
        if (!$hasPendingMandate && !$hasPaymentPending) {
            return;
        }

        if ($this->get_open_renewal_order_id_for_subscription($subId) > 0) {
            return;
        }

        $blockingStatus = $this->get_subscription_runtime_blocking_status($subId);
        if ($blockingStatus !== '') {
            return;
        }

        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        $paymentMethod = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD, true);
        $paymentMethodTitle = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
        $resolvedPayment = $this->resolve_subscription_payment_method_for_renewal($subId, $paymentMethod, $paymentMethodTitle, $parentOrder);
        $paymentMethod = (string) ($resolvedPayment['method'] ?? $paymentMethod);
        $requiresMandate = $this->payment_method_requires_mandate($paymentMethod);
        $customerId = (string) get_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, true);
        $mandateId = (string) get_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, true);

        if ($hasPendingMandate) {
            if (!$requiresMandate || ($customerId !== '' && $mandateId !== '')) {
                $this->persist_subscription_runtime_state($subId, 'active');
                $this->sync_subscription_order_type_record($subId);
            }

            return;
        }

        $latestRenewalOrderId = $this->get_latest_renewal_order_id_for_subscription($subId);
        if ($latestRenewalOrderId > 0) {
            $latestRenewalOrder = wc_get_order($latestRenewalOrderId);
            $latestRenewalStatus = $latestRenewalOrder && is_object($latestRenewalOrder) && method_exists($latestRenewalOrder, 'get_status')
                ? sanitize_key((string) $latestRenewalOrder->get_status())
                : '';

            if (in_array($latestRenewalStatus, ['failed', 'cancelled', 'refunded'], true)) {
                $this->persist_subscription_runtime_state($subId, 'on-hold');
                $this->sync_subscription_order_type_record($subId);
                return;
            }

            if (in_array($latestRenewalStatus, ['processing', 'completed'], true)) {
                $this->persist_subscription_runtime_state($subId, 'active', $this->get_recorded_renewal_next_payment($latestRenewalOrder, $subId));
                $this->sync_subscription_order_type_record($subId);
                return;
            }
        }

        $this->persist_subscription_runtime_state($subId, 'active');
        $this->sync_subscription_order_type_record($subId);
    }

    private function log_renewal_debug(string $event, int $subId, array $context = []): void {
        $payload = array_merge([
            'subscription_id' => $subId,
        ], $context);

        if (preg_match('/(error|exception|missing|failed)/', $event)) {
            SubscriptionSyncLogger::warning($event, $payload);
            return;
        }

        SubscriptionSyncLogger::info($event, $payload);
    }

    private function build_renewal_order_debug_snapshot($order): array {
        if (!$order || !is_object($order)) {
            return [];
        }

        $billing = method_exists($order, 'get_address') ? (array) $order->get_address('billing') : [];
        $shipping = method_exists($order, 'get_address') ? (array) $order->get_address('shipping') : [];
        $lineItems = method_exists($order, 'get_items') ? (array) $order->get_items('line_item') : [];
        $fees = method_exists($order, 'get_items') ? (array) $order->get_items('fee') : [];
        $shippingItems = method_exists($order, 'get_items') ? (array) $order->get_items('shipping') : [];

        return [
            'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
            'status' => method_exists($order, 'get_status') ? (string) $order->get_status() : '',
            'total' => method_exists($order, 'get_total') ? (float) $order->get_total() : null,
            'payment_method' => method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '',
            'payment_method_title' => method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : '',
            'line_item_count' => count($lineItems),
            'fee_count' => count($fees),
            'shipping_count' => count($shippingItems),
            'has_billing_name' => ((string) ($billing['first_name'] ?? '') !== '' || (string) ($billing['last_name'] ?? '') !== ''),
            'has_billing_address' => ((string) ($billing['address_1'] ?? '') !== '' || (string) ($billing['postcode'] ?? '') !== '' || (string) ($billing['city'] ?? '') !== ''),
            'billing_email' => (string) ($billing['email'] ?? ''),
            'has_shipping_name' => ((string) ($shipping['first_name'] ?? '') !== '' || (string) ($shipping['last_name'] ?? '') !== ''),
            'has_shipping_address' => ((string) ($shipping['address_1'] ?? '') !== '' || (string) ($shipping['postcode'] ?? '') !== '' || (string) ($shipping['city'] ?? '') !== ''),
            'mollie_payment_id' => method_exists($order, 'get_meta') ? (string) $order->get_meta(self::ORDER_META_MOLLIE_PAYMENT_ID, true) : '',
        ];
    }

    private function cleanup_partial_renewal_order($order, int $subId, string $reason): void {
        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }

        $orderId = (int) $order->get_id();
        if ($orderId <= 0) {
            return;
        }

        $snapshot = $this->build_renewal_order_debug_snapshot($order);
        $lineItemCount = (int) ($snapshot['line_item_count'] ?? 0);
        $feeCount = (int) ($snapshot['fee_count'] ?? 0);
        $shippingCount = (int) ($snapshot['shipping_count'] ?? 0);
        $paymentId = (string) ($snapshot['mollie_payment_id'] ?? '');

        if ($lineItemCount > 0 || $feeCount > 0 || $shippingCount > 0 || $paymentId !== '') {
            $this->log_renewal_debug('renewal.partial_order_kept', $subId, [
                'reason' => $reason,
                'order' => $snapshot,
            ]);
            return;
        }

        $this->log_renewal_debug('renewal.partial_order_deleted', $subId, [
            'reason' => $reason,
            'order' => $snapshot,
        ]);

        if (function_exists('wp_delete_post')) {
            wp_delete_post($orderId, true);
        }
    }

    private function create_renewal_order_and_payment(int $subId, bool $force = false) {
        if (!$force && !$this->subscription_is_eligible_for_auto_renewal($subId)) {
            return 0;
        }

        $storedNextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
        $nextPayment = $this->normalize_subscription_next_payment($subId, $storedNextPayment);

        if ($nextPayment > 0 && $nextPayment !== $storedNextPayment) {
            $this->persist_subscription_runtime_state($subId, '', $nextPayment);
            $this->sync_subscription_order_type_record($subId);
        }

        if (!$force && $nextPayment > time()) {
            return 0;
        }

        if (!$force) {
            $openRenewalOrderId = $this->get_open_renewal_order_id_for_subscription($subId);
            if ($openRenewalOrderId > 0) {
                return $openRenewalOrderId;
            }
        }

        $latestRenewalOrderId = $this->get_latest_renewal_order_id_for_subscription($subId);
        if ($latestRenewalOrderId > 0 && $nextPayment > time()) {
            return $latestRenewalOrderId;
        }

        $userId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $scheme = (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
        $paymentMethod = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD, true);
        $paymentMethodTitle = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
        $items = $this->get_subscription_items($subId, false, true);
        if (empty($items)) {
            return new \WP_Error('hb_ucs_missing_items', __('Dit abonnement bevat geen geldige artikelen.', 'hb-ucs'));
        }

        $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        $resolvedPayment = $this->resolve_subscription_payment_method_for_renewal($subId, $paymentMethod, $paymentMethodTitle, $parentOrder);
        $paymentMethod = (string) ($resolvedPayment['method'] ?? '');
        $paymentMethodTitle = (string) ($resolvedPayment['title'] ?? '');

        $this->log_renewal_debug('renewal.create.start', $subId, [
            'force' => $force,
            'next_payment' => $nextPayment,
            'parent_order_id' => $parentOrderId,
            'payment_method' => $paymentMethod,
            'payment_method_title' => $paymentMethodTitle,
        ]);

        $requiresMandate = $this->payment_method_requires_mandate($paymentMethod);
        $token = '';
        $customerId = '';
        $mandateId = '';

        if ($requiresMandate) {
            $token = $this->get_webhook_token();
            if ($token === '') {
                return new \WP_Error('hb_ucs_missing_webhook_token', __('Webhook token ontbreekt. Sla HB UCS → Abonnementen instellingen op om een token te genereren.', 'hb-ucs'));
            }

            $customerId = (string) get_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, true);
            $mandateId = (string) get_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, true);
            if ($customerId === '' || $mandateId === '') {
                return new \WP_Error('hb_ucs_missing_mandate', __('Geen Mollie customer/mandate voor deze subscription.', 'hb-ucs'));
            }
        }

        $customer = $this->get_subscription_tax_customer($subId, $parentOrder);
        $preparedOrderItems = [];

        foreach ($items as $subscriptionItem) {
            $baseProductId = (int) ($subscriptionItem['base_product_id'] ?? 0);
            $baseVariationId = (int) ($subscriptionItem['base_variation_id'] ?? 0);
            $qty = (int) ($subscriptionItem['qty'] ?? 1);
            if ($qty <= 0) {
                $qty = 1;
            }

            $productToAdd = $baseVariationId > 0 ? wc_get_product($baseVariationId) : wc_get_product($baseProductId);
            if (!$productToAdd) {
                return new \WP_Error('hb_ucs_missing_product', __('Product voor renewal niet gevonden.', 'hb-ucs'));
            }

            $orderTotals = $this->get_subscription_item_order_totals($subscriptionItem, $customer);
            $itemTaxes = $this->get_subscription_item_taxes($subscriptionItem, $customer);
            $lineTax = $this->round_subscription_order_item_amount((float) array_sum($this->normalize_subscription_tax_amounts($itemTaxes['total'] ?? [])));
            $subtotalTax = $this->round_subscription_order_item_amount((float) array_sum($this->normalize_subscription_tax_amounts($itemTaxes['subtotal'] ?? [])));
            $lineSubtotal = $this->round_subscription_order_item_amount((float) ($orderTotals['line_subtotal'] ?? 0.0));
            $lineTotalExcludingTax = $this->round_subscription_order_item_amount(max(0.0, ((float) ($orderTotals['line_total'] ?? 0.0)) - (float) $lineTax));
            $preparedOrderItems[] = [
                'product' => $productToAdd,
                'base_product_id' => $baseProductId,
                'base_variation_id' => $baseVariationId,
                'qty' => $qty,
                'line_subtotal' => $lineSubtotal,
                'line_subtotal_tax' => $subtotalTax,
                'line_tax' => $lineTax,
                'line_total_ex_tax' => $lineTotalExcludingTax,
                'taxes' => $itemTaxes,
                'source_order_item_id' => (int) ($subscriptionItem['source_order_item_id'] ?? 0),
                'catalog_unit_price' => $this->get_subscription_item_catalog_unit_price($subscriptionItem),
                'selected_attributes' => $this->get_subscription_item_selected_attributes($subscriptionItem),
                'attribute_snapshot' => $this->get_subscription_item_attribute_snapshot($subscriptionItem),
                'display_meta' => $this->get_subscription_item_effective_display_meta($subId, $subscriptionItem),
            ];
        }

        $billingSnapshot = $this->get_subscription_address_snapshot($subId, 'billing', $userId, $parentOrder);
        $shippingSnapshot = $this->get_subscription_address_snapshot($subId, 'shipping', $userId, $parentOrder);
        $this->log_renewal_debug('renewal.create.prepared', $subId, [
            'requires_mandate' => $requiresMandate,
            'prepared_item_count' => count($preparedOrderItems),
            'has_billing_name' => ((string) ($billingSnapshot['first_name'] ?? '') !== '' || (string) ($billingSnapshot['last_name'] ?? '') !== ''),
            'has_billing_address' => ((string) ($billingSnapshot['address_1'] ?? '') !== '' || (string) ($billingSnapshot['postcode'] ?? '') !== '' || (string) ($billingSnapshot['city'] ?? '') !== ''),
            'billing_email' => (string) ($billingSnapshot['email'] ?? ''),
            'has_shipping_name' => ((string) ($shippingSnapshot['first_name'] ?? '') !== '' || (string) ($shippingSnapshot['last_name'] ?? '') !== ''),
            'has_shipping_address' => ((string) ($shippingSnapshot['address_1'] ?? '') !== '' || (string) ($shippingSnapshot['postcode'] ?? '') !== '' || (string) ($shippingSnapshot['city'] ?? '') !== ''),
            'customer_id' => $userId,
            'customer_has_tax_context' => $customer ? true : false,
        ]);

        $order = wc_create_order([
            'customer_id' => $userId,
        ]);
        if (!is_object($order)) {
            return new \WP_Error('hb_ucs_order_create_failed', __('Kon geen renewal order aanmaken.', 'hb-ucs'));
        }

        try {
            $this->log_renewal_debug('renewal.create.order_created', $subId, [
                'order' => $this->build_renewal_order_debug_snapshot($order),
            ]);

            $this->apply_subscription_snapshot_to_order($order, $subId, $userId, $parentOrder);
            $this->log_renewal_debug('renewal.create.after_snapshot', $subId, [
                'order' => $this->build_renewal_order_debug_snapshot($order),
            ]);

            foreach ($preparedOrderItems as $preparedItem) {
                $item = new \WC_Order_Item_Product();
                $item->set_product($preparedItem['product']);
                $item->set_quantity((int) $preparedItem['qty']);
                $item->set_subtotal((float) $preparedItem['line_subtotal']);
                $item->set_total((float) $preparedItem['line_total_ex_tax']);
                if (method_exists($item, 'set_subtotal_tax')) {
                    $item->set_subtotal_tax((float) $preparedItem['line_subtotal_tax']);
                }
                if (method_exists($item, 'set_total_tax')) {
                    $item->set_total_tax((float) $preparedItem['line_tax']);
                }
                if (!empty($preparedItem['taxes']['total']) && method_exists($item, 'set_taxes')) {
                    $item->set_taxes((array) $preparedItem['taxes']);
                }
                $item->add_meta_data('_hb_ucs_subscription_base_product_id', (int) $preparedItem['base_product_id'], true);
                if ((int) $preparedItem['base_variation_id'] > 0) {
                    $item->add_meta_data('_hb_ucs_subscription_base_variation_id', (int) $preparedItem['base_variation_id'], true);
                }
                if ((int) $preparedItem['source_order_item_id'] > 0) {
                    $item->add_meta_data(self::ORDER_ITEM_META_SOURCE_ORDER_ITEM_ID, (int) $preparedItem['source_order_item_id'], true);
                }
                $item->add_meta_data(self::ORDER_ITEM_META_CATALOG_UNIT_PRICE, (float) $preparedItem['catalog_unit_price'], true);
                if (!empty($preparedItem['selected_attributes']) && is_array($preparedItem['selected_attributes'])) {
                    $item->add_meta_data(self::ORDER_ITEM_META_SELECTED_ATTRIBUTES, wp_json_encode($preparedItem['selected_attributes']), true);
                }
                if (!empty($preparedItem['attribute_snapshot']) && is_array($preparedItem['attribute_snapshot'])) {
                    $item->add_meta_data(self::ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT, wp_json_encode($preparedItem['attribute_snapshot']), true);
                }
                foreach ((array) $preparedItem['display_meta'] as $displayMetaRow) {
                    $label = (string) ($displayMetaRow['label'] ?? '');
                    $value = (string) ($displayMetaRow['value'] ?? '');
                    if ($label === '' || $value === '') {
                        continue;
                    }

                    $item->add_meta_data($label, $value, true);
                }
                $item->add_meta_data('_hb_ucs_subscription_scheme', $scheme, true);
                $order->add_item($item);
            }

            $this->apply_subscription_fee_lines_to_order($order, $subId, $parentOrder);
            $this->apply_subscription_shipping_lines_to_order($order, $subId, $parentOrder);
            $this->log_renewal_debug('renewal.create.after_items', $subId, [
                'order' => $this->build_renewal_order_debug_snapshot($order),
            ]);

            $order->update_meta_data(self::ORDER_META_SUBSCRIPTION_ID, (string) $subId);
            $order->update_meta_data(self::ORDER_META_RENEWAL, '1');

            if ($requiresMandate) {
                $order->update_meta_data(self::ORDER_META_RENEWAL_MODE, 'mandate');
                $order->set_payment_method('mollie_wc_gateway_directdebit');
                $order->set_payment_method_title('SEPA Direct Debit');
            } else {
                $order->update_meta_data(self::ORDER_META_RENEWAL_MODE, 'manual');
                $order->update_meta_data(self::ORDER_META_RENEWAL_PROCESSED, '1');
                if ($paymentMethod !== '') {
                    $order->set_payment_method($paymentMethod);
                }
                if ($paymentMethodTitle !== '') {
                    $order->set_payment_method_title($paymentMethodTitle);
                }
            }

            if (function_exists('wc_tax_enabled') && wc_tax_enabled()) {
                foreach ($order->get_items('fee') as $index => $feeItem) {
                    $feeLines = array_values($this->get_effective_subscription_fee_lines($subId, $parentOrder));
                    if (isset($feeLines[$index]['taxes']) && !empty($feeLines[$index]['taxes']) && method_exists($feeItem, 'set_taxes')) {
                        $feeItem->set_taxes((array) $feeLines[$index]['taxes']);
                    }
                }
                foreach ($order->get_items('shipping') as $index => $shippingItem) {
                    $shippingLines = array_values($this->get_effective_subscription_shipping_lines($subId, $parentOrder));
                    if (isset($shippingLines[$index]['taxes']) && !empty($shippingLines[$index]['taxes']) && method_exists($shippingItem, 'set_taxes')) {
                        $shippingItem->set_taxes((array) $shippingLines[$index]['taxes']);
                    }
                }
                if (method_exists($order, 'update_taxes')) {
                    $order->update_taxes();
                }
            }
            $order->calculate_totals(false);

            $nextPayment = $this->calculate_next_payment_timestamp($subId);
            $runtimeStatus = $requiresMandate ? 'payment_pending' : 'active';
            $order->update_meta_data(self::ORDER_META_RENEWAL_NEXT_PAYMENT, (string) $nextPayment);

            // Persist renewal markers before status hooks fire so renewal orders are
            // never re-processed as source orders for fresh subscriptions.
            $order->save();
            $this->log_renewal_debug('renewal.create.after_save', $subId, [
                'runtime_status' => $runtimeStatus,
                'next_payment' => $nextPayment,
                'order' => $this->build_renewal_order_debug_snapshot(wc_get_order((int) $order->get_id())),
            ]);

            $this->persist_subscription_runtime_state($subId, $runtimeStatus, $nextPayment);
            $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
            $createdTs = $created ? (int) $created->getTimestamp() : time();
            $this->persist_subscription_last_order_state($subId, (int) $order->get_id(), $createdTs);
            $this->sync_subscription_order_type_record($subId);

            if ($requiresMandate) {
                $statusForced = $this->force_order_status($order, 'on-hold', __('HB UCS: renewal aangemaakt, wacht op SEPA incasso.', 'hb-ucs'));
                $this->log_renewal_debug('renewal.create.after_initial_on_hold', $subId, [
                    'status_forced' => $statusForced,
                    'order' => $this->build_renewal_order_debug_snapshot(wc_get_order((int) $order->get_id())),
                ]);
            } else {
                $order->update_status('processing', __('HB UCS: renewal aangemaakt en direct in verwerking gezet voor handmatige/offline betaalmethode.', 'hb-ucs'));
                $order->add_order_note(__('HB UCS: deze renewal gebruikt een handmatige/offline betaalmethode, vereist geen Mollie mandaat en staat direct op verwerken.', 'hb-ucs'));
            }

            if (!$requiresMandate) {
                return (int) $order->get_id();
            }

            // Create Mollie recurring payment.
            $webhookUrl = add_query_arg([
                'hb_ucs_mollie_webhook' => '1',
                'token' => $token,
            ], home_url('/'));

            $amountValue = number_format((float) $order->get_total(), 2, '.', '');
            $payload = [
                'amount' => [
                    'currency' => (string) get_woocommerce_currency(),
                    'value' => $amountValue,
                ],
                'description' => $this->get_mollie_payment_description($order),
                'customerId' => $customerId,
                'mandateId' => $mandateId,
                'sequenceType' => 'recurring',
                'method' => 'directdebit',
                'webhookUrl' => $webhookUrl,
                'metadata' => [
                    'order_id' => (int) $order->get_id(),
                    'subscription_id' => (int) $subId,
                    'source' => 'hb_ucs',
                ],
            ];
            $this->log_renewal_debug('renewal.create.before_mollie_request', $subId, [
                'order' => $this->build_renewal_order_debug_snapshot(wc_get_order((int) $order->get_id())),
                'payload' => [
                    'amount' => $payload['amount'],
                    'customerId' => $customerId,
                    'mandateId' => $mandateId,
                    'method' => 'directdebit',
                ],
            ]);

            $payment = $this->mollie_request('POST', 'payments', $payload);
            if (is_wp_error($payment)) {
                $this->log_renewal_debug('renewal.create.mollie_error', $subId, [
                    'order' => $this->build_renewal_order_debug_snapshot(wc_get_order((int) $order->get_id())),
                    'error_code' => $payment->get_error_code(),
                    'error_message' => $payment->get_error_message(),
                ]);
                return $payment;
            }
            $paymentId = isset($payment['id']) ? (string) $payment['id'] : '';
            if ($paymentId === '') {
                $this->log_renewal_debug('renewal.create.mollie_missing_payment_id', $subId, [
                    'order' => $this->build_renewal_order_debug_snapshot(wc_get_order((int) $order->get_id())),
                    'response' => $payment,
                ]);
                return new \WP_Error('hb_ucs_mollie_no_payment_id', __('Mollie gaf geen payment id terug.', 'hb-ucs'));
            }

            $freshOrder = wc_get_order((int) $order->get_id());
            if ($freshOrder && is_object($freshOrder)) {
                $freshOrder->update_meta_data(self::ORDER_META_MOLLIE_PAYMENT_ID, $paymentId);
                $freshOrder->add_order_note(sprintf(__('HB UCS: Mollie recurring betaling gestart (%s).', 'hb-ucs'), $paymentId));
                $freshOrder->save();
                $statusForced = $this->force_order_status($freshOrder, 'on-hold', __('HB UCS: renewal wacht op SEPA incasso.', 'hb-ucs'));
                $this->log_renewal_debug('renewal.create.after_mollie_payment_id', $subId, [
                    'status_forced' => $statusForced,
                    'payment_id' => $paymentId,
                    'order' => $this->build_renewal_order_debug_snapshot(wc_get_order((int) $order->get_id())),
                ]);
            }

            $this->add_subscription_admin_note($subId, __('Abonnement blijft actief totdat betaling mislukt, omdat een SEPA incasso betaling enige tijd nodig heeft om te verwerken.', 'hb-ucs'));
            update_post_meta($subId, self::SUB_META_LAST_PAYMENT_ID, $paymentId);

            return (int) $order->get_id();
        } catch (\Throwable $e) {
            $this->log_renewal_debug('renewal.create.exception', $subId, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'order' => $this->build_renewal_order_debug_snapshot(wc_get_order((int) $order->get_id())),
            ]);
            $this->cleanup_partial_renewal_order($order, $subId, $e->getMessage());

            return new \WP_Error('hb_ucs_renewal_exception', $e->getMessage());
        }
    }

    private function get_latest_renewal_order_id_for_subscription(int $subId): int {
        if ($subId <= 0 || !function_exists('wc_get_orders')) {
            return 0;
        }

        $relatedSubscriptionIds = $this->get_related_subscription_ids($subId);
        if (empty($relatedSubscriptionIds)) {
            return 0;
        }

        $orderIds = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => array_keys(wc_get_order_statuses()),
            'meta_query' => [
                [
                    'key' => self::ORDER_META_SUBSCRIPTION_ID,
                    'value' => array_map('strval', $relatedSubscriptionIds),
                    'compare' => 'IN',
                ],
                [
                    'key' => self::ORDER_META_RENEWAL,
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        if (!is_array($orderIds) || empty($orderIds[0])) {
            return 0;
        }

        return (int) $orderIds[0];
    }

    private function get_open_renewal_order_id_for_subscription(int $subId): int {
        if ($subId <= 0 || !function_exists('wc_get_order')) {
            return 0;
        }

        $relatedSubscriptionIds = $this->get_related_subscription_ids($subId);
        if (empty($relatedSubscriptionIds)) {
            return 0;
        }

        $lastOrderId = (int) get_post_meta($subId, self::SUB_META_LAST_ORDER_ID, true);
        if ($lastOrderId > 0) {
            $lastOrder = wc_get_order($lastOrderId);
            if ($this->is_open_renewal_order_for_subscription($lastOrder, $subId)) {
                return $lastOrderId;
            }
        }

        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        $openStatuses = function_exists('wc_get_order_statuses')
            ? array_values(array_intersect(array_keys(wc_get_order_statuses()), ['wc-pending', 'wc-on-hold', 'wc-checkout-draft']))
            : ['wc-pending', 'wc-on-hold', 'wc-checkout-draft'];

        $orderIds = wc_get_orders([
            'limit' => 1,
            'return' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'status' => $openStatuses,
            'meta_query' => [
                [
                    'key' => self::ORDER_META_SUBSCRIPTION_ID,
                    'value' => array_map('strval', $relatedSubscriptionIds),
                    'compare' => 'IN',
                ],
                [
                    'key' => self::ORDER_META_RENEWAL,
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);

        if (!is_array($orderIds) || empty($orderIds[0])) {
            return 0;
        }

        $renewalOrderId = (int) $orderIds[0];
        $renewalOrder = wc_get_order($renewalOrderId);

        return $this->is_open_renewal_order_for_subscription($renewalOrder, $subId) ? $renewalOrderId : 0;
    }

    private function is_open_renewal_order_for_subscription($order, int $subId): bool {
        if (!$order || !is_object($order) || !method_exists($order, 'get_status') || !method_exists($order, 'get_meta')) {
            return false;
        }

        if (!in_array((int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true), $this->get_related_subscription_ids($subId), true)) {
            return false;
        }

        if ((string) $order->get_meta(self::ORDER_META_RENEWAL, true) !== '1') {
            return false;
        }

        $status = sanitize_key((string) $order->get_status());

        return in_array($status, ['pending', 'on-hold', 'checkout-draft'], true);
    }

    private function force_order_status($order, string $status, string $note = ''): bool {
        if (!$order || !is_object($order) || !method_exists($order, 'get_id')) {
            return false;
        }

        $orderId = (int) $order->get_id();
        if ($orderId <= 0) {
            return false;
        }

        $targetStatus = sanitize_key($status);
        if ($targetStatus === '') {
            return false;
        }

        $freshOrder = function_exists('wc_get_order') ? wc_get_order($orderId) : $order;
        if ($freshOrder && is_object($freshOrder) && method_exists($freshOrder, 'set_status') && method_exists($freshOrder, 'save')) {
            $freshOrder->set_status($targetStatus, $note, true);
            $freshOrder->save();
        } elseif ($freshOrder && is_object($freshOrder) && method_exists($freshOrder, 'update_status')) {
            $freshOrder->update_status($targetStatus, $note);
        }

        $verifiedOrder = function_exists('wc_get_order') ? wc_get_order($orderId) : $freshOrder;
        if ($verifiedOrder && is_object($verifiedOrder) && method_exists($verifiedOrder, 'get_status') && sanitize_key((string) $verifiedOrder->get_status()) === $targetStatus) {
            return true;
        }

        if (function_exists('wp_update_post')) {
            wp_update_post([
                'ID' => $orderId,
                'post_status' => 'wc-' . $targetStatus,
            ]);
        }

        $verifiedOrder = function_exists('wc_get_order') ? wc_get_order($orderId) : $verifiedOrder;

        return $verifiedOrder && is_object($verifiedOrder) && method_exists($verifiedOrder, 'get_status')
            ? sanitize_key((string) $verifiedOrder->get_status()) === $targetStatus
            : false;
    }

    public function maybe_mark_manual_renewal_paid(int $orderId): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order || !is_object($order)) {
            return;
        }

        $subId = (int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true);
        $isRenewal = (string) $order->get_meta(self::ORDER_META_RENEWAL, true) === '1';
        if ($subId <= 0 || !$isRenewal) {
            return;
        }

        $mode = (string) $order->get_meta(self::ORDER_META_RENEWAL_MODE, true);
        if ($mode === '') {
            $mode = $this->payment_method_requires_mandate((string) $order->get_payment_method()) ? 'mandate' : 'manual';
        }
        if ($mode !== 'manual') {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_META_RENEWAL_PROCESSED, true) === '1') {
            return;
        }

        $order->update_meta_data(self::ORDER_META_RENEWAL_PROCESSED, '1');
        $order->save();

        $paid = method_exists($order, 'get_date_paid') ? $order->get_date_paid() : null;
        $paidTs = $paid ? (int) $paid->getTimestamp() : time();
        $this->persist_subscription_last_order_state($subId, $orderId, $paidTs);
        $this->persist_subscription_runtime_state($subId, 'active', $this->get_recorded_renewal_next_payment($order, $subId));
        $this->sync_subscription_order_type_record($subId);

        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(__('HB UCS: handmatige renewal betaald; volgende abonnementsdatum is bijgewerkt.', 'hb-ucs'));
        }
    }

    private function get_configured_frequencies(bool $enabledOnly = true): array {
        $settings = $this->get_settings();
        $freqs = (array) ($settings['frequencies'] ?? []);

        $out = [];
        foreach (['1w', '2w', '3w', '4w', '5w', '6w', '7w', '8w'] as $key) {
            $row = isset($freqs[$key]) && is_array($freqs[$key]) ? $freqs[$key] : [];
            if ($enabledOnly && empty($row['enabled'])) {
                continue;
            }
            $label = (string) ($row['label'] ?? $key);
            $interval = (int) ($row['interval'] ?? 0);
            $period = (string) ($row['period'] ?? 'week');
            if ($interval <= 0) {
                continue;
            }
            $out[$key] = [
                'label' => $label,
                'interval' => $interval,
                'period' => $period,
            ];
        }
        return $out;
    }

    private function get_enabled_frequencies(): array {
        return $this->get_configured_frequencies(true);
    }

    private function is_checkout_draft_order($order): bool {
        if (!$order || !is_object($order)) {
            return false;
        }

        $status = method_exists($order, 'get_status') ? (string) $order->get_status() : '';
        if ($status !== '') {
            $normalizedStatus = strpos($status, 'wc-') === 0 ? substr($status, 3) : $status;
            return in_array($normalizedStatus, ['checkout-draft', 'draft', 'auto-draft'], true);
        }

        $postStatus = method_exists($order, 'get_id') ? (string) get_post_status((int) $order->get_id()) : '';
        $normalizedPostStatus = strpos($postStatus, 'wc-') === 0 ? substr($postStatus, 3) : $postStatus;

        return in_array($normalizedPostStatus, ['checkout-draft', 'draft', 'auto-draft'], true);
    }

    public function render_product_fields(): void {
        global $post;
        if (!$post || $post->post_type !== 'product') {
            return;
        }

        $product = wc_get_product($post->ID);
        if (!$product || (! $product->is_type('simple') && ! $product->is_type('variable'))) {
            return;
        }

        $productId = (int) $post->ID;
        $enabled = get_post_meta($productId, self::META_ENABLED, true) === 'yes' ? 'yes' : 'no';

        echo '<div class="options_group">';

        woocommerce_wp_checkbox([
            'id' => self::META_ENABLED,
            'label' => __('Abonnement optie', 'hb-ucs'),
            'description' => __('Sta klanten toe om dit product als abonnement te kopen (naast eenmalige aankoop).', 'hb-ucs'),
            'value' => $enabled,
        ]);

        $freqs = $this->get_enabled_frequencies();
        if (empty($freqs)) {
            echo '<p class="form-field"><span class="description">' . esc_html__('Er zijn geen globale frequenties ingeschakeld in HB UCS → Abonnementen.', 'hb-ucs') . '</span></p>';
            echo '</div>';
            return;
        }

        if ($product->is_type('simple')) {
            $basePrice = (string) $product->get_price();
            $basePriceFloat = $basePrice === '' ? 0.0 : (float) wc_format_decimal($basePrice);

            echo '<p class="form-field"><strong>' . esc_html__('Abonnement prijzen', 'hb-ucs') . '</strong><br/><span class="description">' . esc_html__('Standaard is de abonnementsprijs gelijk aan de productprijs. Gebruik de snelle korting-toggle (% of vast bedrag) of vul een vaste abonnementsprijs in als override.', 'hb-ucs') . '</span></p>';

            foreach ($freqs as $scheme => $row) {
                $scheme = (string) $scheme;
                $label = (string) ($row['label'] ?? $scheme);

                $disc = $this->get_discount_settings($productId, $scheme);
                $pricing = $this->get_subscription_pricing($productId, $basePriceFloat, $scheme);
                $fixedRaw = (string) get_post_meta($productId, self::META_PRICE_PREFIX . $scheme, true);

                echo '<div class="form-field hb-ucs-subs-scheme" data-base-price="' . esc_attr((string) $basePriceFloat) . '" data-scheme="' . esc_attr($scheme) . '" style="padding:10px 0;border-top:1px solid #f0f0f1;">';
                echo '<strong>' . esc_html($label) . '</strong><br/>';
                echo '<span class="description">' . sprintf(esc_html__('Basisprijs incl. btw: %s', 'hb-ucs'), wp_kses_post(wc_price($this->get_product_price_display_amount(wc_get_product($productId), $basePriceFloat, 1, true)))) . '</span>';

                echo '<p style="margin:8px 0 0;">';
                echo '<label style="display:inline-block;margin-right:12px;">';
                echo '<input type="checkbox" name="hb_ucs_subs_disc_enabled[' . esc_attr($scheme) . ']" value="1" ' . checked(!empty($disc['enabled']), true, false) . ' /> ';
                echo esc_html__('Korting', 'hb-ucs');
                echo '</label>';

                echo '<select name="hb_ucs_subs_disc_type[' . esc_attr($scheme) . ']" style="margin-right:8px;">';
                echo '<option value="percent" ' . selected((string) ($disc['type'] ?? 'percent'), 'percent', false) . '>' . esc_html__('% korting', 'hb-ucs') . '</option>';
                echo '<option value="fixed" ' . selected((string) ($disc['type'] ?? 'percent'), 'fixed', false) . '>' . esc_html__('€ korting', 'hb-ucs') . '</option>';
                echo '</select>';

                echo '<input type="text" class="short" name="hb_ucs_subs_disc_value[' . esc_attr($scheme) . ']" value="' . esc_attr((string) ($disc['value'] ?? '')) . '" placeholder="0" />';
                echo '<span class="description" style="margin-left:10px;">' . esc_html__('Nieuwe prijs incl. btw:', 'hb-ucs') . ' <strong class="hb-ucs-subs-new-price">' . wp_kses_post(wc_price($this->get_product_price_display_amount(wc_get_product($productId), (float) ($pricing['final'] ?? $basePriceFloat), 1, true))) . '</strong></span>';
                echo '</p>';

                echo '<details style="margin-top:8px;"><summary>' . esc_html__('Geavanceerd: vaste abonnementsprijs (override)', 'hb-ucs') . '</summary>';
                echo '<p style="margin:8px 0 0;">';
                echo '<label>' . esc_html__('Vaste prijs', 'hb-ucs') . ': ';
                echo '<input type="text" class="short" name="hb_ucs_subs_fixed_price[' . esc_attr($scheme) . ']" value="' . esc_attr($fixedRaw) . '" placeholder="' . esc_attr((string) $basePriceFloat) . '" />';
                echo '</label> ';
                echo '<span class="description">' . esc_html__('Leeg = gebruik basisprijs/korting.', 'hb-ucs') . '</span>';
                echo '</p>';
                echo '</details>';

                echo '</div>';
            }
        } else {
            echo '<p class="form-field"><span class="description">' . esc_html__('Voor variabele producten stel je vaste abonnementsprijzen per variatie in bij Variaties. Hieronder kun je optioneel een standaard korting instellen die geldt als default voor alle variaties (tenzij overschreven bij een variatie).', 'hb-ucs') . '</span></p>';

            foreach ($freqs as $scheme => $row) {
                $scheme = (string) $scheme;
                $label = (string) ($row['label'] ?? $scheme);
                $disc = $this->get_discount_settings($productId, $scheme);

                echo '<div class="form-field hb-ucs-subs-scheme" data-base-price="0" data-scheme="' . esc_attr($scheme) . '" style="padding:10px 0;border-top:1px solid #f0f0f1;">';
                echo '<strong>' . esc_html(sprintf(__('Standaard korting (%s)', 'hb-ucs'), $label)) . '</strong>';
                echo '<p style="margin:8px 0 0;">';
                echo '<label style="display:inline-block;margin-right:12px;">';
                echo '<input type="checkbox" name="hb_ucs_subs_disc_enabled[' . esc_attr($scheme) . ']" value="1" ' . checked(!empty($disc['enabled']), true, false) . ' /> ';
                echo esc_html__('Korting', 'hb-ucs');
                echo '</label>';

                echo '<select name="hb_ucs_subs_disc_type[' . esc_attr($scheme) . ']" style="margin-right:8px;">';
                echo '<option value="percent" ' . selected((string) ($disc['type'] ?? 'percent'), 'percent', false) . '>' . esc_html__('% korting', 'hb-ucs') . '</option>';
                echo '<option value="fixed" ' . selected((string) ($disc['type'] ?? 'percent'), 'fixed', false) . '>' . esc_html__('€ korting', 'hb-ucs') . '</option>';
                echo '</select>';

                echo '<input type="text" class="short" name="hb_ucs_subs_disc_value[' . esc_attr($scheme) . ']" value="' . esc_attr((string) ($disc['value'] ?? '')) . '" placeholder="0" />';
                echo '<span class="description" style="margin-left:10px;">' . esc_html__('Wordt toegepast op variatieprijs.', 'hb-ucs') . '</span>';
                echo '</p>';
                echo '</div>';
            }
        }

        echo '</div>';
    }

    public function save_product_fields($product): void {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }

        $productId = (int) $product->get_id();
        if ($productId <= 0) {
            return;
        }

        // Only handle simple/variable products.
        if (method_exists($product, 'is_type') && !$product->is_type('simple') && !$product->is_type('variable')) {
            return;
        }

        $enabled = isset($_POST[self::META_ENABLED]) ? 'yes' : 'no';
        update_post_meta($productId, self::META_ENABLED, $enabled);

        // Save discount settings for simple + variable products.
        if ($product->is_type('simple') || $product->is_type('variable')) {
            $freqs = $this->get_enabled_frequencies();

            $discEnabled = isset($_POST['hb_ucs_subs_disc_enabled']) && is_array($_POST['hb_ucs_subs_disc_enabled']) ? (array) $_POST['hb_ucs_subs_disc_enabled'] : [];
            $discType = isset($_POST['hb_ucs_subs_disc_type']) && is_array($_POST['hb_ucs_subs_disc_type']) ? (array) $_POST['hb_ucs_subs_disc_type'] : [];
            $discValue = isset($_POST['hb_ucs_subs_disc_value']) && is_array($_POST['hb_ucs_subs_disc_value']) ? (array) $_POST['hb_ucs_subs_disc_value'] : [];

            // Fixed override only for simple products.
            $fixed = ($product->is_type('simple') && isset($_POST['hb_ucs_subs_fixed_price']) && is_array($_POST['hb_ucs_subs_fixed_price'])) ? (array) $_POST['hb_ucs_subs_fixed_price'] : [];

            foreach ($freqs as $scheme => $row) {
                $scheme = (string) $scheme;

                if ($product->is_type('simple')) {
                    $fixedRaw = isset($fixed[$scheme]) ? (string) wp_unslash($fixed[$scheme]) : '';
                    $fixedRaw = trim($fixedRaw);
                    $fixedKey = self::META_PRICE_PREFIX . $scheme;
                    if ($fixedRaw === '') {
                        delete_post_meta($productId, $fixedKey);
                    } else {
                        $fixedDisplay = (float) wc_format_decimal($fixedRaw);
                        $fixedStorage = $this->get_product_price_storage_amount($product, $fixedDisplay, 1);
                        update_post_meta($productId, $fixedKey, wc_format_decimal((string) $fixedStorage));
                    }
                }

                $enabled = !empty($discEnabled[$scheme]);
                $type = isset($discType[$scheme]) ? sanitize_key((string) wp_unslash($discType[$scheme])) : 'percent';
                if ($type !== 'percent' && $type !== 'fixed') {
                    $type = 'percent';
                }
                $valueRaw = isset($discValue[$scheme]) ? (string) wp_unslash($discValue[$scheme]) : '';
                $valueRaw = trim($valueRaw);

                $enabledKey = self::META_DISC_ENABLED_PREFIX . $scheme;
                $typeKey = self::META_DISC_TYPE_PREFIX . $scheme;
                $valueKey = self::META_DISC_VALUE_PREFIX . $scheme;

                if (!$enabled) {
                    delete_post_meta($productId, $enabledKey);
                    delete_post_meta($productId, $typeKey);
                    delete_post_meta($productId, $valueKey);
                } else {
                    update_post_meta($productId, $enabledKey, '1');
                    update_post_meta($productId, $typeKey, $type);
                    $value = $valueRaw === '' ? '0' : wc_format_decimal($valueRaw);
                    update_post_meta($productId, $valueKey, $value);
                }
            }
        }

        $this->sync_existing_subscription_prices_for_product($productId, 0, $product);

    }

    public function render_purchase_options(): void {
        if (!is_product()) {
            return;
        }

        global $product;
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return;
        }
        if (!$product->is_type('simple') && !$product->is_type('variable')) {
            return;
        }

        $productId = (int) $product->get_id();
        if (get_post_meta($productId, self::META_ENABLED, true) !== 'yes') {
            return;
        }

        $freqs = $this->get_enabled_frequencies();
        if (empty($freqs)) {
            return;
        }

        // Current selection (if user returned due to validation errors).
        $selected = $this->get_requested_scheme();
        if ($selected === '') {
            $selected = '0';
        }

        echo '<div class="hb-ucs-subscriptions hb-ucs-subscriptions--product">';
        echo '<p class="hb-ucs-subscriptions__title"><strong>' . esc_html__('Kies aankooptype', 'hb-ucs') . '</strong></p>';

        echo '<label class="hb-ucs-subscriptions__option">';
        echo '<input type="radio" name="hb_ucs_subs_scheme" value="0" ' . checked($selected, '0', false) . ' /> ';
        echo esc_html__('Eenmalige aankoop', 'hb-ucs');
        echo '</label>';

        foreach ($freqs as $scheme => $row) {
            $label = (string) $row['label'];

            echo '<label class="hb-ucs-subscriptions__option">';
            echo '<input type="radio" name="hb_ucs_subs_scheme" value="' . esc_attr($scheme) . '" ' . checked($selected, (string) $scheme, false) . ' /> ';
            echo esc_html($label);
            if ($product->is_type('simple')) {
                $pricing = $this->get_product_page_subscription_pricing($productId, $product, (string) $scheme);
                $priceHtml = $pricing ? $this->format_subscription_price_html((float) $pricing['base'], (float) $pricing['final'], (string) ($pricing['badge'] ?? '')) : '';
                echo ' — <span class="price">' . wp_kses_post($priceHtml) . '</span>';
            } else {
                echo ' — <span class="price hb-ucs-subs-price" data-scheme="' . esc_attr((string) $scheme) . '"></span>';
            }
            echo '</label>';
        }

        if ($product->is_type('variable')) {
            echo '<p class="description hb-ucs-subscriptions__description">' . esc_html__('Kies eerst een variatie; de abonnementsprijs kan per variatie verschillen.', 'hb-ucs') . '</p>';
        }

        echo '</div>';
    }

    private function get_requested_scheme(): string {
        $raw = isset($_REQUEST['hb_ucs_subs_scheme']) ? (string) wp_unslash($_REQUEST['hb_ucs_subs_scheme']) : '';
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if ($raw === '0') {
            return '0';
        }
        return sanitize_key($raw);
    }

    public function validate_add_to_cart(bool $passed, int $productId, int $quantity, int $variationId = 0): bool {
        if (!$passed) {
            return false;
        }

        $scheme = $this->get_requested_scheme();
        if ($scheme === '' || $scheme === '0') {
            return true;
        }

        // Only act on products that have subscriptions enabled (stored on parent product).
        if (get_post_meta($productId, self::META_ENABLED, true) !== 'yes') {
            return true;
        }

        $prod = wc_get_product($productId);
        if ($prod && $prod->is_type('variable')) {
            if ((int) $variationId <= 0) {
                wc_add_notice(__('Kies eerst een variatie voor je abonnement.', 'hb-ucs'), 'error');
                return false;
            }
        }

        $freqs = $this->get_enabled_frequencies();
        if (!isset($freqs[$scheme])) {
            wc_add_notice(__('Deze abonnement frequentie is niet beschikbaar.', 'hb-ucs'), 'error');
            return false;
        }

        return true;
    }

    public function maybe_swap_product_id(int $productId): int {
        return $productId;
    }

    public function maybe_swap_variation_id(int $variationId): int {
        return $variationId;
    }

    public function add_cart_item_data(array $cartItemData, int $productId, int $variationId): array {
        $scheme = $this->get_requested_scheme();
        if ($scheme === '' || $scheme === '0') {
            return $cartItemData;
        }

        // Only act on products that have subscriptions enabled (stored on parent product).
        if (get_post_meta($productId, self::META_ENABLED, true) !== 'yes') {
            return $cartItemData;
        }

        $freqs = $this->get_enabled_frequencies();
        if (!isset($freqs[$scheme])) {
            return $cartItemData;
        }

        $entityId = $variationId > 0 ? (int) $variationId : (int) $productId;
        $fallbackId = $variationId > 0 ? (int) $productId : 0;
        $p = wc_get_product($entityId);
        if (!$p) {
            return $cartItemData;
        }

        $pricing = $this->get_manual_cart_subscription_pricing($entityId, $p, (string) $scheme, $fallbackId);
        if (!$pricing) {
            return $cartItemData;
        }

        $base = (float) ($pricing['base'] ?? 0.0);
        $final = isset($pricing['final']) ? (float) $pricing['final'] : $base;

        // Ensure unique cart item so one-time and different schemes don't merge.
        $cartItemData['hb_ucs_subs_key'] = $productId . ':' . $variationId . ':' . $scheme . ':' . wp_generate_uuid4();
        $cartItemData[self::CART_KEY] = [
            'base_product_id' => (int) $productId,
            'base_variation_id' => (int) $variationId,
            'scheme' => (string) $scheme,
            'engine' => 'manual',
            'base_price' => (float) $base,
            'final_price' => (float) $final,
            'selected_attributes' => isset($cartItemData['variation']) && is_array($cartItemData['variation'])
                ? $this->normalize_selected_attributes_from_variation_payload((array) $cartItemData['variation'], (int) $productId)
                : [],
        ];

        return $cartItemData;
    }

    public function maybe_apply_manual_subscription_pricing($cart): void {
        if ($this->get_engine() !== 'manual') {
            return;
        }
        if (!is_object($cart) || !method_exists($cart, 'get_cart')) {
            return;
        }
        if (is_admin() && !defined('DOING_AJAX')) {
            return;
        }

        foreach ($cart->get_cart() as $cartItemKey => $cartItem) {
            if (!is_array($cartItem)) {
                continue;
            }
            $data = isset($cartItem[self::CART_KEY]) && is_array($cartItem[self::CART_KEY]) ? $cartItem[self::CART_KEY] : null;
            if (!$data) {
                continue;
            }
            if (($data['engine'] ?? '') !== 'manual') {
                continue;
            }
            $scheme = (string) ($data['scheme'] ?? '');
            if ($scheme === '' || $scheme === '0') {
                continue;
            }

            $base = isset($data['base_price']) ? (float) $data['base_price'] : null;
            $final = isset($data['final_price']) ? (float) $data['final_price'] : null;
            if ($final === null || $base === null) {
                continue;
            }

            if (!isset($cartItem['data']) || !is_object($cartItem['data']) || !method_exists($cartItem['data'], 'set_price')) {
                continue;
            }

            if (method_exists($cartItem['data'], 'set_regular_price')) {
                $cartItem['data']->set_regular_price(wc_format_decimal((string) max(0.0, $base)));
            }

            if (method_exists($cartItem['data'], 'set_sale_price')) {
                $salePrice = ($final >= 0.0 && $final < $base) ? wc_format_decimal((string) $final) : '';
                $cartItem['data']->set_sale_price($salePrice);
            }

            $cartItem['data']->set_price($final);
        }
    }

    public function display_cart_item_data(array $itemData, array $cartItem): array {
        $data = isset($cartItem[self::CART_KEY]) && is_array($cartItem[self::CART_KEY]) ? $cartItem[self::CART_KEY] : null;
        if (!$data) {
            return $itemData;
        }

        $scheme = (string) ($data['scheme'] ?? '');
        if ($scheme === '') {
            return $itemData;
        }

        $freqs = $this->get_enabled_frequencies();
        $label = isset($freqs[$scheme]['label']) ? (string) $freqs[$scheme]['label'] : $scheme;

        $itemData[] = [
            'key' => __('Abonnement', 'hb-ucs'),
            'value' => $label,
            'display' => $label,
        ];

        return $itemData;
    }

    public function add_order_item_meta($item, $cartItemKey, $values, $order): void {
        if (!is_object($item) || !method_exists($item, 'add_meta_data')) {
            return;
        }
        if (!is_array($values)) {
            return;
        }
        $data = isset($values[self::CART_KEY]) && is_array($values[self::CART_KEY]) ? $values[self::CART_KEY] : null;
        if (!$data) {
            return;
        }

        $baseId = (int) ($data['base_product_id'] ?? 0);
        $baseVariationId = (int) ($data['base_variation_id'] ?? 0);
        $scheme = (string) ($data['scheme'] ?? '');
        if ($baseId <= 0 || $scheme === '') {
            return;
        }

        $selectedAttributes = [];
        $attributeSnapshot = [];
        if (isset($values['variation']) && is_array($values['variation'])) {
            $selectedAttributes = $this->normalize_selected_attributes_from_variation_payload((array) $values['variation'], $baseId);
            $attributeSnapshot = $selectedAttributes;
        }
        if (isset($data['selected_attributes']) && is_array($data['selected_attributes'])) {
            $attributeSnapshot = $this->normalize_selected_attributes_from_variation_payload((array) $data['selected_attributes'], $baseId);
        }

        $item->add_meta_data('_hb_ucs_subscription_base_product_id', $baseId, true);
        if ($baseVariationId > 0) {
            $item->add_meta_data('_hb_ucs_subscription_base_variation_id', $baseVariationId, true);
        }
        $item->add_meta_data('_hb_ucs_subscription_scheme', $scheme, true);
        if (!empty($selectedAttributes)) {
            $item->add_meta_data(self::ORDER_ITEM_META_SELECTED_ATTRIBUTES, wp_json_encode($selectedAttributes), true);
        }
        if (!empty($attributeSnapshot)) {
            $item->add_meta_data(self::ORDER_ITEM_META_ATTRIBUTE_SNAPSHOT, wp_json_encode($attributeSnapshot), true);
        }
    }

    public function maybe_reduce_base_stock($order): void {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }
        $orderId = (int) $order->get_id();
        if ($orderId <= 0) {
            return;
        }

        if (get_post_meta($orderId, '_hb_ucs_subs_base_stock_reduced', true) === 'yes') {
            return;
        }

        $changed = false;
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!is_object($item) || !method_exists($item, 'get_meta')) {
                continue;
            }

            $baseId = (int) $item->get_meta('_hb_ucs_subscription_base_product_id', true);
            $baseVariationId = (int) $item->get_meta('_hb_ucs_subscription_base_variation_id', true);
            $scheme = (string) $item->get_meta('_hb_ucs_subscription_scheme', true);
            if ($baseId <= 0 || $scheme === '') {
                continue;
            }

            $qty = (int) (method_exists($item, 'get_quantity') ? $item->get_quantity() : 0);
            if ($qty <= 0) {
                continue;
            }

            $targetId = $baseVariationId > 0 ? $baseVariationId : $baseId;
            $baseProduct = wc_get_product($targetId);
            if (!$baseProduct || !$baseProduct->managing_stock()) {
                continue;
            }

            wc_update_product_stock($baseProduct, $qty, 'decrease');
            $changed = true;
        }

        if ($changed) {
            update_post_meta($orderId, '_hb_ucs_subs_base_stock_reduced', 'yes');
        }

    }

    public function filter_hidden_order_itemmeta(array $hiddenMeta): array {
        $hiddenMeta = array_merge($hiddenMeta, $this->get_subscription_order_item_hidden_meta_keys());

        return array_values(array_unique($hiddenMeta));
    }

    public function filter_order_item_formatted_meta_data(array $formattedMeta, $item): array {
        $isAdminContext = is_admin();
        $isSubscriptionItem = $this->is_subscription_order_item($item);
        [$baseProductId] = $isSubscriptionItem ? $this->get_subscription_order_item_base_ids($item, true) : [0, 0];
        $attributeDisplayLabels = $isSubscriptionItem ? $this->get_subscription_item_attribute_display_meta_labels($baseProductId) : [];
        $seen = [];
        foreach ($formattedMeta as $metaId => $meta) {
            if (!is_object($meta) || !isset($meta->key)) {
                continue;
            }

            if ($isSubscriptionItem && $this->is_subscription_selected_attribute_meta_key((string) $meta->key)) {
                unset($formattedMeta[$metaId]);
                continue;
            }

            if (in_array((string) $meta->key, $this->get_subscription_order_item_hidden_meta_keys(), true)) {
                unset($formattedMeta[$metaId]);
                continue;
            }

            $label = isset($meta->display_key) ? trim(wp_strip_all_tags((string) $meta->display_key, true)) : '';
            $value = isset($meta->display_value) ? trim(html_entity_decode(wp_strip_all_tags((string) $meta->display_value, true), ENT_QUOTES, 'UTF-8')) : '';
            $normalizedLabel = ltrim(sanitize_key($this->normalize_subscription_item_display_label($label)), '_');
            if ($isSubscriptionItem && $normalizedLabel !== '' && in_array($normalizedLabel, $attributeDisplayLabels, true)) {
                unset($formattedMeta[$metaId]);
                continue;
            }

            if (!$isAdminContext || $label === '' || $value === '') {
                continue;
            }

            $seen[$this->get_subscription_item_display_row_hash($label, $value)] = true;
        }

        static $isInjectingDisplayMeta = false;

        if ($this->is_subscription_order_item($item) && !$isInjectingDisplayMeta) {
            $isInjectingDisplayMeta = true;

            try {
                foreach ($this->get_backend_order_item_display_meta_rows($item) as $displayMetaRow) {
                    $label = isset($displayMetaRow['label']) && is_scalar($displayMetaRow['label']) ? trim((string) $displayMetaRow['label']) : '';
                    $value = isset($displayMetaRow['value']) && is_scalar($displayMetaRow['value']) ? trim((string) $displayMetaRow['value']) : '';
                    if ($label === '' || $value === '') {
                        continue;
                    }

                    $hash = $this->get_subscription_item_display_row_hash($label, $value);
                    if (isset($seen[$hash])) {
                        continue;
                    }

                    $seen[$hash] = true;
                    $formattedMeta['hb_ucs_' . $hash] = (object) [
                        'key' => sanitize_key($label) !== '' ? sanitize_key($label) : $label,
                        'value' => $value,
                        'display_key' => $label,
                        'display_value' => $value,
                    ];
                }
            } finally {
                $isInjectingDisplayMeta = false;
            }
        }

        return $formattedMeta;
    }

    private function get_backend_order_item_display_meta_rows($item): array {
        if (!is_object($item) || !method_exists($item, 'get_meta')) {
            return [];
        }

        [$baseProductId, $baseVariationId] = $this->get_subscription_order_item_base_ids($item, true);

        if ($baseProductId <= 0 && $baseVariationId <= 0) {
            return [];
        }

        $attributeSnapshot = $this->get_attribute_snapshot_from_order_item($item, $baseProductId, $baseVariationId);
        $rows = $this->get_selected_attribute_display_rows($baseProductId, $attributeSnapshot);
        $seen = [];
        foreach ($rows as $row) {
            $label = (string) ($row['label'] ?? '');
            $value = (string) ($row['value'] ?? '');
            if ($label === '' || $value === '') {
                continue;
            }

            $seen[$this->get_subscription_item_display_row_hash($label, $value)] = true;
        }

        $displayMeta = $this->get_display_meta_rows_from_order_item($item, $baseProductId, $attributeSnapshot, false);
        foreach ($displayMeta as $displayMetaRow) {
            $label = (string) ($displayMetaRow['label'] ?? '');
            $value = (string) ($displayMetaRow['value'] ?? '');
            if ($label === '' || $value === '') {
                continue;
            }

            $hash = $this->get_subscription_item_display_row_hash($label, $value);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $rows[] = [
                'label' => $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    public function maybe_restore_base_stock($order): void {
        if (!is_object($order) || !method_exists($order, 'get_id')) {
            return;
        }
        $orderId = (int) $order->get_id();
        if ($orderId <= 0) {
            return;
        }

        if (get_post_meta($orderId, '_hb_ucs_subs_base_stock_reduced', true) !== 'yes') {
            return;
        }

        $changed = false;
        foreach ($order->get_items('line_item') as $itemId => $item) {
            if (!is_object($item) || !method_exists($item, 'get_meta')) {
                continue;
            }

            $baseId = (int) $item->get_meta('_hb_ucs_subscription_base_product_id', true);
            $baseVariationId = (int) $item->get_meta('_hb_ucs_subscription_base_variation_id', true);
            $scheme = (string) $item->get_meta('_hb_ucs_subscription_scheme', true);
            if ($baseId <= 0 || $scheme === '') {
                continue;
            }

            $qty = (int) (method_exists($item, 'get_quantity') ? $item->get_quantity() : 0);
            if ($qty <= 0) {
                continue;
            }

            $targetId = $baseVariationId > 0 ? $baseVariationId : $baseId;
            $baseProduct = wc_get_product($targetId);
            if (!$baseProduct || !$baseProduct->managing_stock()) {
                continue;
            }

            wc_update_product_stock($baseProduct, $qty, 'increase');
            $changed = true;
        }

        if ($changed) {
            delete_post_meta($orderId, '_hb_ucs_subs_base_stock_reduced');
        }
    }

    private function get_base_subscription_price(int $baseProductId, string $scheme): ?float {
        $base = wc_get_product($baseProductId);
        if (!$base) {
            return null;
        }

        $basePrice = $this->get_product_current_storage_price($base);
        if ($basePrice === null) {
            return null;
        }
        $pricing = $this->get_subscription_pricing($baseProductId, $basePrice, $scheme);
        return (float) ($pricing['final'] ?? $basePrice);
    }

    private function get_variation_subscription_price(int $variationId, string $scheme): ?float {
        $variation = wc_get_product($variationId);
        if (!$variation) {
            return null;
        }

        $basePrice = $this->get_product_current_storage_price($variation);
        if ($basePrice === null) {
            return null;
        }
        $parentId = method_exists($variation, 'get_parent_id') ? (int) $variation->get_parent_id() : (int) wp_get_post_parent_id($variationId);
        $pricing = $this->get_subscription_pricing($variationId, $basePrice, $scheme, $parentId);
        return (float) ($pricing['final'] ?? $basePrice);
    }

    private function format_subscription_price_html(float $base, float $final, string $badge = ''): string {
        if ($base > 0 && $final >= 0 && $final < $base) {
            $html = '<del>' . wc_price($base) . '</del> <ins>' . wc_price($final) . '</ins>';
            if ($badge !== '') {
                $html .= ' <small class="hb-ucs-subs-badge">' . esc_html($badge) . '</small>';
            }
            return $html;
        }
        return wc_price($final);
    }

    public function add_variation_subscription_data(array $variationData, $product, $variation): array {
        if (!is_object($product) || !method_exists($product, 'get_id')) {
            return $variationData;
        }
        $parentId = (int) $product->get_id();
        if ($parentId <= 0) {
            return $variationData;
        }
        if (get_post_meta($parentId, self::META_ENABLED, true) !== 'yes') {
            return $variationData;
        }

        if (!is_object($variation) || !method_exists($variation, 'get_id')) {
            return $variationData;
        }
        $variationId = (int) $variation->get_id();
        if ($variationId <= 0) {
            return $variationData;
        }

        $freqs = $this->get_enabled_frequencies();
        if (empty($freqs)) {
            return $variationData;
        }

        $base = $this->get_product_current_storage_price($variation);
        if ($base === null) {
            $base = 0.0;
        }

        $out = [];
        foreach ($freqs as $scheme => $row) {
            $pricing = $this->get_product_page_subscription_pricing($variationId, $variation, (string) $scheme, $parentId);
            if ($pricing === null) {
                $pricing = $this->get_subscription_pricing($variationId, (float) $base, (string) $scheme, $parentId);
                $pricing['base'] = $this->get_product_price_display_amount($variation, (float) $pricing['base'], 1, $this->should_display_subscription_prices_including_tax());
                $pricing['final'] = $this->get_product_price_display_amount($variation, (float) $pricing['final'], 1, $this->should_display_subscription_prices_including_tax());
            }

            $out[(string) $scheme] = [
                'base' => (float) $pricing['base'],
                'final' => (float) $pricing['final'],
                'badge' => (string) ($pricing['badge'] ?? ''),
                'price_html' => $this->format_subscription_price_html((float) $pricing['base'], (float) $pricing['final'], (string) ($pricing['badge'] ?? '')),
            ];
        }

        $variationData['hb_ucs_subs'] = $out;
        return $variationData;
    }

    public function render_variation_fields(int $loop, array $variationData, $variation): void {
        if (!is_object($variation) || !method_exists($variation, 'get_id')) {
            return;
        }
        $variationId = (int) $variation->get_id();
        if ($variationId <= 0) {
            return;
        }

        // Only show when parent has subscriptions enabled.
        $parentId = method_exists($variation, 'get_parent_id') ? (int) $variation->get_parent_id() : 0;
        if ($parentId > 0 && get_post_meta($parentId, self::META_ENABLED, true) !== 'yes') {
            return;
        }

        $freqs = $this->get_enabled_frequencies();
        if (empty($freqs)) {
            return;
        }

        $variationObj = wc_get_product($variationId);
        $base = $variationObj ? (string) $variationObj->get_price() : '';
        $baseFloat = $base === '' ? 0.0 : (float) wc_format_decimal($base);

        echo '<div class="hb-ucs-variation-subscriptions" data-base-price="' . esc_attr((string) $baseFloat) . '" style="padding:8px 0;">';
        echo '<p><strong>' . esc_html__('Abonnement prijzen', 'hb-ucs') . '</strong><br/><span class="description">' . sprintf(esc_html__('Basisprijs incl. btw: %s', 'hb-ucs'), wp_kses_post(wc_price($this->get_product_price_display_amount($variationObj, $baseFloat, 1, true)))) . '</span></p>';

        foreach ($freqs as $scheme => $row) {
            $scheme = (string) $scheme;
            $label = (string) ($row['label'] ?? $scheme);

            $disc = $this->get_discount_settings($variationId, $scheme, $parentId);
            $pricing = $this->get_subscription_pricing($variationId, $baseFloat, $scheme, $parentId);

            $fixedRaw = (string) get_post_meta($variationId, self::META_PRICE_PREFIX . $scheme, true);

            echo '<div class="form-row form-row-full hb-ucs-subs-scheme" data-base-price="' . esc_attr((string) $baseFloat) . '" data-scheme="' . esc_attr($scheme) . '">';
            echo '<p style="margin:6px 0 0;"><strong>' . esc_html($label) . '</strong></p>';

            echo '<label style="display:inline-block;margin-right:12px;">';
            echo '<input type="checkbox" name="hb_ucs_subs_disc_enabled[' . esc_attr($scheme) . '][' . esc_attr((string) $variationId) . ']" value="1" ' . checked(!empty($disc['enabled']), true, false) . ' /> ';
            echo esc_html__('Korting', 'hb-ucs');
            echo '</label>';

            echo '<select name="hb_ucs_subs_disc_type[' . esc_attr($scheme) . '][' . esc_attr((string) $variationId) . ']" style="margin-right:8px;">';
            echo '<option value="percent" ' . selected((string) ($disc['type'] ?? 'percent'), 'percent', false) . '>' . esc_html__('% korting', 'hb-ucs') . '</option>';
            echo '<option value="fixed" ' . selected((string) ($disc['type'] ?? 'percent'), 'fixed', false) . '>' . esc_html__('€ korting', 'hb-ucs') . '</option>';
            echo '</select>';

            echo '<input type="text" class="short" name="hb_ucs_subs_disc_value[' . esc_attr($scheme) . '][' . esc_attr((string) $variationId) . ']" value="' . esc_attr((string) ($disc['value'] ?? '')) . '" placeholder="0" />';
            echo '<span class="description" style="margin-left:10px;">' . esc_html__('Nieuwe prijs incl. btw:', 'hb-ucs') . ' <strong class="hb-ucs-subs-new-price">' . wp_kses_post(wc_price($this->get_product_price_display_amount($variationObj, (float) ($pricing['final'] ?? $baseFloat), 1, true))) . '</strong></span>';

            echo '<details style="margin-top:6px;"><summary>' . esc_html__('Vaste abonnementsprijs (override)', 'hb-ucs') . '</summary>';
            echo '<p style="margin:6px 0 0;">';
            echo '<label>' . esc_html__('Vaste prijs', 'hb-ucs') . ': ';
            echo '<input type="text" class="short" name="hb_ucs_subs_fixed_price[' . esc_attr($scheme) . '][' . esc_attr((string) $variationId) . ']" value="' . esc_attr($fixedRaw) . '" placeholder="' . esc_attr((string) $baseFloat) . '" />';
            echo '</label> ';
            echo '<span class="description">' . esc_html__('Leeg = gebruik basisprijs/korting.', 'hb-ucs') . '</span>';
            echo '</p>';
            echo '</details>';

            echo '</div>';
        }
        echo '</div>';
    }

    public function save_variation_fields(int $variationId, int $i): void {
        $variationId = (int) $variationId;
        if ($variationId <= 0) {
            return;
        }

        $freqs = $this->get_enabled_frequencies();
        if (empty($freqs)) {
            return;
        }

        $discEnabled = isset($_POST['hb_ucs_subs_disc_enabled']) && is_array($_POST['hb_ucs_subs_disc_enabled']) ? (array) $_POST['hb_ucs_subs_disc_enabled'] : [];
        $discType = isset($_POST['hb_ucs_subs_disc_type']) && is_array($_POST['hb_ucs_subs_disc_type']) ? (array) $_POST['hb_ucs_subs_disc_type'] : [];
        $discValue = isset($_POST['hb_ucs_subs_disc_value']) && is_array($_POST['hb_ucs_subs_disc_value']) ? (array) $_POST['hb_ucs_subs_disc_value'] : [];
        $fixed = isset($_POST['hb_ucs_subs_fixed_price']) && is_array($_POST['hb_ucs_subs_fixed_price']) ? (array) $_POST['hb_ucs_subs_fixed_price'] : [];

        foreach ($freqs as $scheme => $row) {
            $scheme = (string) $scheme;

            // Fixed price override.
            $fixedRaw = '';
            if (isset($fixed[$scheme]) && is_array($fixed[$scheme]) && isset($fixed[$scheme][$variationId])) {
                $fixedRaw = (string) wp_unslash($fixed[$scheme][$variationId]);
            }
            $fixedRaw = trim($fixedRaw);
            $fixedKey = self::META_PRICE_PREFIX . $scheme;
            if ($fixedRaw === '') {
                delete_post_meta($variationId, $fixedKey);
            } else {
                $variationProduct = wc_get_product($variationId);
                $fixedDisplay = (float) wc_format_decimal($fixedRaw);
                $fixedStorage = $this->get_product_price_storage_amount($variationProduct, $fixedDisplay, 1);
                update_post_meta($variationId, $fixedKey, wc_format_decimal((string) $fixedStorage));
            }

            // Discount.
            $enabled = isset($discEnabled[$scheme]) && is_array($discEnabled[$scheme]) && !empty($discEnabled[$scheme][$variationId]);
            $type = 'percent';
            if (isset($discType[$scheme]) && is_array($discType[$scheme]) && isset($discType[$scheme][$variationId])) {
                $type = sanitize_key((string) wp_unslash($discType[$scheme][$variationId]));
            }
            if ($type !== 'percent' && $type !== 'fixed') {
                $type = 'percent';
            }

            $valueRaw = '';
            if (isset($discValue[$scheme]) && is_array($discValue[$scheme]) && isset($discValue[$scheme][$variationId])) {
                $valueRaw = (string) wp_unslash($discValue[$scheme][$variationId]);
            }
            $valueRaw = trim($valueRaw);

            $enabledKey = self::META_DISC_ENABLED_PREFIX . $scheme;
            $typeKey = self::META_DISC_TYPE_PREFIX . $scheme;
            $valueKey = self::META_DISC_VALUE_PREFIX . $scheme;

            if (!$enabled) {
                delete_post_meta($variationId, $enabledKey);
                delete_post_meta($variationId, $typeKey);
                delete_post_meta($variationId, $valueKey);
            } else {
                update_post_meta($variationId, $enabledKey, '1');
                update_post_meta($variationId, $typeKey, $type);
                $value = $valueRaw === '' ? '0' : wc_format_decimal($valueRaw);
                update_post_meta($variationId, $valueKey, $value);
            }
        }

        $parentId = (int) wp_get_post_parent_id($variationId);
        $this->sync_existing_subscription_prices_for_product($parentId > 0 ? $parentId : $variationId, $variationId);
    }
}
