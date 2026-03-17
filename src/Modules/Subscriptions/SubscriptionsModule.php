<?php
// =============================
// src/Modules/Subscriptions/SubscriptionsModule.php
// =============================
namespace HB\UCS\Modules\Subscriptions;

use HB\UCS\Core\Settings;

if (!defined('ABSPATH')) exit;

class SubscriptionsModule {
    private const RENEWAL_CRON_RECURRENCE = 'hb_ucs_every_minute';
    private const ACCOUNT_ENDPOINT = 'abonnementen';
    private const ORDER_META_CONTAINS_SUBSCRIPTION = '_hb_ucs_contains_subscription';

    private const META_ENABLED = '_hb_ucs_subs_enabled';
    private const META_PRICE_PREFIX = '_hb_ucs_subs_price_'; // suffix: 1w|2w|3w|4w

    private const META_DISC_ENABLED_PREFIX = '_hb_ucs_subs_disc_enabled_';
    private const META_DISC_TYPE_PREFIX = '_hb_ucs_subs_disc_type_';      // percent|fixed
    private const META_DISC_VALUE_PREFIX = '_hb_ucs_subs_disc_value_';    // decimal

    private const META_CHILD_PREFIX = '_hb_ucs_subs_child_'; // stored on base product: subscription product id

    private const META_CHILD_BASE_PRODUCT_ID = '_hb_ucs_subs_base_product_id';
    private const META_CHILD_SCHEME = '_hb_ucs_subs_scheme';
    private const META_CHILD_GENERATED = '_hb_ucs_subs_generated';

    private const CART_KEY = 'hb_ucs_subs';

    private const SUB_CPT = 'hb_ucs_subscription';

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
    private const SUB_META_BILLING = '_hb_ucs_sub_billing';
    private const SUB_META_SHIPPING = '_hb_ucs_sub_shipping';

    private const SUB_META_TRIAL_END = '_hb_ucs_sub_trial_end'; // unix timestamp (optional)
    private const SUB_META_END_DATE = '_hb_ucs_sub_end_date'; // unix timestamp (optional)
    private const SUB_META_LAST_ORDER_ID = '_hb_ucs_sub_last_order_id';
    private const SUB_META_LAST_ORDER_DATE = '_hb_ucs_sub_last_order_date'; // unix timestamp
    private const SUB_META_WCS_SOURCE_ID = '_hb_ucs_sub_wcs_source_id';

    private const ORDER_META_RECURRING_CREATED = '_hb_ucs_subs_recurring_created';
    private const ORDER_META_SUBSCRIPTION_ID = '_hb_ucs_subscription_id';
    private const ORDER_META_RENEWAL = '_hb_ucs_subscription_renewal';
    private const ORDER_META_RENEWAL_MODE = '_hb_ucs_subscription_renewal_mode';
    private const ORDER_META_RENEWAL_PROCESSED = '_hb_ucs_subscription_renewal_processed';
    private const ORDER_META_MOLLIE_PAYMENT_ID = '_hb_ucs_mollie_payment_id';

    /** @var array{base_product_id:int,base_variation_id:int,child_product_id:int,scheme:string}|null */
    private static $pendingAddToCart = null;

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Storage for subscriptions.
        add_action('init', [$this, 'register_subscription_post_type']);
        add_action('init', [$this, 'register_account_endpoint']);

        // Admin: product fields.
        add_action('woocommerce_product_options_general_product_data', [$this, 'render_product_fields']);
        add_action('woocommerce_admin_process_product_object', [$this, 'save_product_fields']);

        // Admin: variation fields.
        add_action('woocommerce_product_after_variable_attributes', [$this, 'render_variation_fields'], 10, 3);
        add_action('woocommerce_save_product_variation', [$this, 'save_variation_fields'], 10, 2);

        // Admin notice if module is enabled but WCS missing.
        if (is_admin()) {
            add_action('admin_notices', [$this, 'maybe_notice_missing_wcs']);
            add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

            // Admin UX for internal subscription records.
            add_action('add_meta_boxes_' . self::SUB_CPT, [$this, 'add_subscription_metaboxes']);
            add_action('save_post_' . self::SUB_CPT, [$this, 'save_subscription_post'], 10, 3);
            add_filter('manage_edit-' . self::SUB_CPT . '_columns', [$this, 'filter_subscription_columns']);
            add_action('manage_' . self::SUB_CPT . '_posts_custom_column', [$this, 'render_subscription_column'], 10, 2);
            add_filter('the_title', [$this, 'filter_subscription_admin_title'], 10, 2);
            add_filter('manage_edit-' . self::SUB_CPT . '_sortable_columns', [$this, 'sortable_subscription_columns']);
            add_filter('views_edit-' . self::SUB_CPT, [$this, 'subscription_status_views']);
            add_action('pre_get_posts', [$this, 'filter_subscription_admin_query']);
            add_action('restrict_manage_posts', [$this, 'subscription_admin_filters']);
            add_filter('manage_edit-shop_order_columns', [$this, 'filter_shop_order_subscription_columns'], 20);
            add_action('manage_shop_order_posts_custom_column', [$this, 'render_shop_order_subscription_column_legacy'], 20, 2);
            add_filter('manage_woocommerce_page_wc-orders_columns', [$this, 'filter_shop_order_subscription_columns'], 20);
            add_action('manage_woocommerce_page_wc-orders_custom_column', [$this, 'render_shop_order_subscription_column_hpos'], 20, 2);

            // Manual test trigger for cron renewals.
            add_action('admin_post_hb_ucs_subs_run_now', [$this, 'handle_run_renewals_now']);

            // Demo data helper: create a subscription record from latest subscription order.
            add_action('admin_post_hb_ucs_subs_create_demo', [$this, 'handle_create_demo_subscription']);
            add_action('admin_post_hb_ucs_subs_migrate_wcs', [$this, 'handle_migrate_wcs_subscriptions']);
            add_action('admin_notices', [$this, 'maybe_render_wcs_migration_notice']);
        }

        // Frontend assets (late so WooCommerce has registered variation scripts).
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets'], 20);

        // Frontend: render subscription choices.
        add_action('woocommerce_before_add_to_cart_button', [$this, 'render_purchase_options'], 15);

        // Variable product: expose scheme prices in variation data for JS.
        add_filter('woocommerce_available_variation', [$this, 'add_variation_subscription_data'], 10, 3);

        // Add-to-cart: validate and swap product.
        add_filter('woocommerce_add_to_cart_validation', [$this, 'validate_add_to_cart'], 10, 4);
        add_filter('woocommerce_add_to_cart_product_id', [$this, 'maybe_swap_product_id'], 10, 1);
        add_filter('woocommerce_add_to_cart_variation_id', [$this, 'maybe_swap_variation_id'], 10, 1);
        add_filter('woocommerce_add_cart_item_data', [$this, 'add_cart_item_data'], 10, 3);

        // Manual engine: apply subscription price in cart (no WCS dependency).
        add_action('woocommerce_before_calculate_totals', [$this, 'maybe_apply_manual_subscription_pricing'], 20, 1);

        // Recurring engine (manual): validate first payment method at checkout.
        add_filter('woocommerce_available_payment_gateways', [$this, 'filter_first_subscription_payment_gateways'], 20);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_first_payment_method'], 10, 2);

        // Make initial Mollie payment a "first" payment so Mollie creates a mandate.
        add_filter('woocommerce_mollie_wc_gateway_ideal_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);
        add_filter('woocommerce_mollie_wc_gateway_idealpayment_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);
        add_filter('woocommerce_mollie_wc_gateway_creditcard_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);
        add_filter('woocommerce_mollie_wc_gateway_creditcardpayment_args', [$this, 'maybe_mark_mollie_first_payment'], 10, 2);

        // Create subscription records after the first successful payment.
        add_action('woocommerce_payment_complete', [$this, 'maybe_create_subscriptions_from_order'], 20, 1);
        add_action('woocommerce_checkout_order_processed', [$this, 'maybe_create_subscriptions_from_manual_order'], 20, 3);
        add_action('woocommerce_payment_complete', [$this, 'maybe_mark_manual_renewal_paid'], 30, 1);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_mark_manual_renewal_paid'], 30, 1);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_mark_manual_renewal_paid'], 30, 1);
        add_filter('woocommerce_email_classes', [$this, 'register_renewal_email_classes']);
        add_action('woocommerce_order_status_on-hold', [$this, 'maybe_trigger_on_hold_renewal_email'], 40, 1);
        add_action('woocommerce_order_status_processing', [$this, 'maybe_trigger_processing_renewal_email'], 40, 1);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_trigger_completed_renewal_email'], 40, 1);
        add_filter('woocommerce_email_enabled_customer_processing_order', [$this, 'maybe_disable_default_customer_order_email_for_renewals'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_completed_order', [$this, 'maybe_disable_default_customer_order_email_for_renewals'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_on_hold_order', [$this, 'maybe_disable_default_customer_order_email_for_renewals'], 10, 2);
        add_filter('woocommerce_email_enabled_customer_invoice', [$this, 'maybe_disable_default_customer_order_email_for_renewals'], 10, 2);

        // Cron renewals.
        add_filter('cron_schedules', [$this, 'register_cron_schedules']);
        add_action('init', [$this, 'ensure_cron_scheduled']);
        add_action('hb_ucs_subs_process_renewals', [$this, 'process_due_renewals']);

        // Mollie webhook endpoint.
        add_filter('query_vars', [$this, 'register_query_vars']);
        add_action('admin_post_nopriv_hb_ucs_run_renewals', [$this, 'handle_renewals_admin_post']);
        add_action('admin_post_hb_ucs_run_renewals', [$this, 'handle_renewals_admin_post']);
        add_action('template_redirect', [$this, 'maybe_handle_mollie_webhook']);
        add_action('template_redirect', [$this, 'maybe_handle_renewals_cron_request']);
        add_action('template_redirect', [$this, 'maybe_handle_account_subscription_action']);

        // Customer self-service in My Account.
        add_filter('woocommerce_account_menu_items', [$this, 'add_account_menu_item']);
        add_action('woocommerce_account_' . self::ACCOUNT_ENDPOINT . '_endpoint', [$this, 'render_account_endpoint']);
        add_shortcode('hb_ucs_subscriptions_account', [$this, 'render_account_shortcode']);
        add_action('woocommerce_save_account_details', [$this, 'sync_subscriptions_from_account_details'], 20, 1);
        add_action('woocommerce_customer_save_address', [$this, 'sync_subscriptions_from_customer_address'], 20, 2);

        if (did_action('elementor/loaded')) {
            add_action('elementor/element/woocommerce-my-account/section_menu_icon_content/before_section_end', [$this, 'extend_elementor_my_account_widget_tabs'], 10, 2);
        }

        // Cart display.
        add_filter('woocommerce_get_item_data', [$this, 'display_cart_item_data'], 10, 2);

        // Order item meta.
        add_action('woocommerce_checkout_create_order', [$this, 'mark_order_subscription_meta'], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'add_order_item_meta'], 10, 4);

        // Stock mapping: subscription child products do not manage stock; reduce base stock instead.
        add_action('woocommerce_reduce_order_stock', [$this, 'maybe_reduce_base_stock'], 10, 1);
        add_action('woocommerce_restore_order_stock', [$this, 'maybe_restore_base_stock'], 10, 1);
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

    public function register_subscription_post_type(): void {
        if (post_type_exists(self::SUB_CPT)) {
            return;
        }

        register_post_type(self::SUB_CPT, [
            'labels' => [
                'name' => __('Abonnementen', 'hb-ucs'),
                'singular_name' => __('Abonnement', 'hb-ucs'),
                'menu_name' => __('Abonnementen', 'hb-ucs'),
                'name_admin_bar' => __('Abonnement', 'hb-ucs'),
                'add_new' => __('Maak abonnement aan', 'hb-ucs'),
                'add_new_item' => __('Maak abonnement aan', 'hb-ucs'),
                'new_item' => __('Nieuw abonnement', 'hb-ucs'),
                'edit_item' => __('Abonnement bewerken', 'hb-ucs'),
                'view_item' => __('Abonnement bekijken', 'hb-ucs'),
                'all_items' => __('Abonnementen', 'hb-ucs'),
                'search_items' => __('Zoek abonnementen', 'hb-ucs'),
                'not_found' => __('Geen abonnementen gevonden.', 'hb-ucs'),
                'not_found_in_trash' => __('Geen abonnementen gevonden in prullenbak.', 'hb-ucs'),
            ],
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'woocommerce',
            'capability_type' => 'shop_order',
            'map_meta_cap' => true,
            'supports' => ['title'],
        ]);
    }

    public function add_subscription_metaboxes($post): void {
        if (!$post || !is_object($post) || (string) ($post->post_type ?? '') !== self::SUB_CPT) {
            return;
        }

        add_meta_box(
            'hb_ucs_subscription_data',
            __('Abonnement gegevens', 'hb-ucs'),
            [$this, 'render_subscription_data_metabox'],
            self::SUB_CPT,
            'normal',
            'high'
        );

        add_meta_box(
            'hb_ucs_subscription_schedule',
            __('Planning', 'hb-ucs'),
            [$this, 'render_subscription_schedule_metabox'],
            self::SUB_CPT,
            'side',
            'default'
        );

        add_meta_box(
            'hb_ucs_subscription_items',
            __('Abonnement items', 'hb-ucs'),
            [$this, 'render_subscription_items_metabox'],
            self::SUB_CPT,
            'normal',
            'default'
        );

        add_meta_box(
            'hb_ucs_subscription_related_orders',
            __('Gerelateerde bestellingen', 'hb-ucs'),
            [$this, 'render_subscription_related_orders_metabox'],
            self::SUB_CPT,
            'normal',
            'default'
        );
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
            $this->render_account_subscription_detail((int) $subscription->ID, $userId);
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
            echo '<div class="hb-ucs-subscription-card__meta-item"><span>' . esc_html__('Totaal', 'hb-ucs') . '</span><strong>' . wp_kses_post(function_exists('wc_price') ? wc_price($this->get_subscription_total_amount($subId, true)) : number_format($this->get_subscription_total_amount($subId, true), 2, '.', '')) . '</strong></div>';
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
        $nextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
        $locked = $this->subscription_has_locked_orders($subId);
        $items = $this->get_subscription_items($subId);
        $productOptions = $this->get_manageable_subscription_product_options($scheme);
        $contact = $this->get_subscription_contact_snapshot($userId, null, $subId);
        $relatedOrders = $this->get_subscription_related_orders($subId);
        $manageDisabled = in_array($status, ['cancelled', 'expired'], true);
        $totalAmount = $this->get_subscription_total_amount($subId, true);
        $scheduleModalId = 'hb-ucs-schedule-modal-' . $subId;
        $contactPanelId = 'hb-ucs-panel-contact-' . $subId;
        $ordersPanelId = 'hb-ucs-panel-orders-' . $subId;
        $summaryDate = $nextPayment > 0 ? $this->format_wp_date($nextPayment) : '—';

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
            echo '<div class="woocommerce-info hb-ucs-inline-notice" role="status">' . esc_html__('Er staat al een gekoppelde bestelling op on-hold of processing. Wijzigingen aan dit abonnement passen die open bestelling niet meer aan en annuleren die ook niet.', 'hb-ucs') . '</div>';
        }

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
            $this->render_account_subscription_item_editor_row((string) $index, $item, $manageDisabled, $productOptions);
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
        ], $manageDisabled, $productOptions);
        echo '<template id="' . esc_attr($itemTemplateId) . '">' . ob_get_clean() . '</template>';
        $this->render_manageable_product_picker_modal($productOptions, $manageDisabled);
        echo '<div class="hb-ucs-subscription-items-footer hb-ucs-subscription-items-footer--dashboard">';
        echo '<button type="submit" class="button hb-ucs-button hb-ucs-button--primary" ' . disabled($manageDisabled, true, false) . '>' . esc_html__('Wijzigingen opslaan', 'hb-ucs') . '</button>';
        echo '</div>';
        echo '</form>';
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
        $status = (string) get_post_meta($subId, self::SUB_META_STATUS, true);
        $manageDisabled = in_array($status, ['cancelled', 'expired'], true);

        switch ($action) {
            case 'pause':
                if ($manageDisabled) {
                    wc_add_notice(__('Dit abonnement kan nu niet gepauzeerd worden.', 'hb-ucs'), 'error');
                    break;
                }
                update_post_meta($subId, self::SUB_META_STATUS, 'paused');
                wc_add_notice(__('Het abonnement is gepauzeerd.', 'hb-ucs'));
                break;

            case 'resume':
                if ($manageDisabled) {
                    wc_add_notice(__('Dit abonnement kan nu niet hervat worden.', 'hb-ucs'), 'error');
                    break;
                }
                update_post_meta($subId, self::SUB_META_STATUS, 'active');
                $nextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
                if ($nextPayment <= time()) {
                    update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) $this->calculate_next_payment_timestamp($subId));
                }
                wc_add_notice(__('Het abonnement is hervat.', 'hb-ucs'));
                break;

            case 'cancel':
                if ($manageDisabled) {
                    wc_add_notice(__('Dit abonnement kan nu niet geannuleerd worden.', 'hb-ucs'), 'error');
                    break;
                }
                update_post_meta($subId, self::SUB_META_STATUS, 'cancelled');
                update_post_meta($subId, self::SUB_META_END_DATE, (string) time());
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
                update_post_meta($subId, self::SUB_META_SCHEME, $scheme);
                update_post_meta($subId, self::SUB_META_INTERVAL, (string) ((int) ($freqs[$scheme]['interval'] ?? 1)));
                update_post_meta($subId, self::SUB_META_PERIOD, (string) ($freqs[$scheme]['period'] ?? 'week'));
                update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) $nextPayment);
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
                    if (!empty($row['remove'])) {
                        continue;
                    }

                    $selectedId = isset($row['product_id']) ? (int) absint((string) $row['product_id']) : 0;
                    $selectedAttributes = isset($row['selected_attributes']) && is_array($row['selected_attributes']) ? $row['selected_attributes'] : [];
                    $qty = isset($row['qty']) ? (int) absint((string) $row['qty']) : 1;
                    $existingItem = isset($existingItems[$index]) && is_array($existingItems[$index]) ? $existingItems[$index] : null;
                    $existingSelectedId = $existingItem ? (int) (($existingItem['base_variation_id'] ?? 0) > 0 ? $existingItem['base_variation_id'] : ($existingItem['base_product_id'] ?? 0)) : 0;
                    $fallbackUnit = ($existingItem && $existingSelectedId === $selectedId) ? (float) ($existingItem['unit_price'] ?? 0.0) : null;
                    $item = $this->build_subscription_item_from_selection($selectedId, $scheduleScheme, $qty, $fallbackUnit, is_array($selectedAttributes) ? $selectedAttributes : []);
                    if (!$item) {
                        if ($selectedId > 0) {
                            $hasInvalidSelection = true;
                            break;
                        }
                        continue;
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

                $this->persist_subscription_items($subId, $newItems);
                wc_add_notice(__('De abonnementartikelen zijn bijgewerkt.', 'hb-ucs'));
                break;
        }

        wp_safe_redirect($this->get_account_subscription_url($subId));
        exit;
    }

    private function get_account_subscription_for_user(int $subId, int $userId) {
        if ($subId <= 0 || $userId <= 0) {
            return null;
        }

        $post = get_post($subId);
        if (!$post || (string) $post->post_type !== self::SUB_CPT) {
            return null;
        }

        $ownerId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
        if ($ownerId !== $userId) {
            return null;
        }

        return $post;
    }

    private function get_user_subscription_ids(int $userId): array {
        if ($userId <= 0) {
            return [];
        }

        $query = new \WP_Query([
            'post_type' => self::SUB_CPT,
            'post_status' => 'publish',
            'posts_per_page' => 100,
            'fields' => 'ids',
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => self::SUB_META_USER_ID,
                    'value' => (string) $userId,
                    'compare' => '=',
                ],
            ],
        ]);

        return $query->have_posts() ? array_map('intval', $query->posts) : [];
    }

    private function get_subscription_scheme_label(string $scheme): string {
        $freqs = $this->get_enabled_frequencies();
        if (isset($freqs[$scheme]) && !empty($freqs[$scheme]['label'])) {
            return (string) $freqs[$scheme]['label'];
        }
        return $scheme !== '' ? $scheme : __('Onbekend', 'hb-ucs');
    }

    private function get_manageable_subscription_product_options(string $scheme = ''): array {
        if (!function_exists('wc_get_products')) {
            return [
                'items' => [],
                'lookup' => [],
                'categories' => [],
                'variable_configs' => [],
                'variation_lookup' => [],
            ];
        }

        $options = [
            'items' => [],
            'lookup' => [],
            'categories' => [],
            'variable_configs' => [],
            'variation_lookup' => [],
        ];
        $products = wc_get_products([
            'status' => 'publish',
            'limit' => -1,
            'return' => 'objects',
            'type' => ['simple', 'variable'],
        ]);

        if (!is_array($products)) {
            $products = [];
        }

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
            $baseSubscriptionPrice = $scheme !== '' ? $this->get_base_subscription_price($productId, $scheme) : null;
            $priceHtml = $baseSubscriptionPrice !== null ? (string) html_entity_decode(wp_strip_all_tags(wc_price($baseSubscriptionPrice), true), ENT_QUOTES, 'UTF-8') : (method_exists($product, 'get_price_html') ? (string) wp_strip_all_tags($product->get_price_html(), true) : '');

            if (method_exists($product, 'is_type') && $product->is_type('variable') && method_exists($product, 'get_children')) {
                $variableConfig = $this->get_variable_product_attribute_config($product);
                if (!empty($variableConfig)) {
                    $options['variable_configs'][$productId] = $variableConfig;
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
                $options['items'][] = $entry;
                $options['lookup'][$productId] = $entry;

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
        usort($options['items'], static function (array $left, array $right): int {
            return strcasecmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        return $options;
    }

    private function render_manageable_product_select(string $name, int $selectedId, bool $disabled, array $productOptions, string $placeholder = '', string $selectedLabel = '', string $attributesName = '', array $selectedAttributes = []): void {
        $fieldId = 'hb_ucs_product_picker_' . trim((string) preg_replace('/[^a-zA-Z0-9_]+/', '_', $name), '_');
        $displayLabel = $selectedLabel !== '' ? $selectedLabel : ($placeholder !== '' ? $placeholder : __('Nog geen product gekozen', 'hb-ucs'));
        if ($selectedLabel === '' && $selectedId > 0) {
            $lookup = isset($productOptions['lookup']) && is_array($productOptions['lookup']) ? $productOptions['lookup'] : [];
            if (isset($lookup[$selectedId]['label'])) {
                $displayLabel = (string) $lookup[$selectedId]['label'];
            } else {
                $displayLabel = $this->get_subscription_item_label([
                    'base_product_id' => $selectedId,
                    'base_variation_id' => 0,
                ]);
            }
        }

        echo '<div class="hb-ucs-product-picker-field">';
        echo '<div class="hb-ucs-product-picker-field__header">';
        echo '<span class="hb-ucs-product-picker-field__label">' . esc_html__('Product', 'hb-ucs') . '</span>';
        echo '</div>';
        echo '<input type="hidden" name="' . esc_attr($name) . '" id="' . esc_attr($fieldId) . '" class="hb-ucs-product-picker-value" value="' . esc_attr((string) $selectedId) . '" />';
        echo '<div class="hb-ucs-product-picker-summary">';
        echo '<span class="hb-ucs-product-picker-label" data-empty-label="' . esc_attr($placeholder !== '' ? $placeholder : __('Nog geen product gekozen', 'hb-ucs')) . '">' . esc_html($displayLabel) . '</span>';
        echo '<button type="button" class="button hb-ucs-button hb-ucs-button--secondary hb-ucs-open-product-modal" data-picker-input="' . esc_attr($fieldId) . '" data-picker-title="' . esc_attr__('Kies een product', 'hb-ucs') . '" ' . disabled($disabled, true, false) . '><span aria-hidden="true">＋</span> ' . esc_html($selectedId > 0 ? __('Selectie wijzigen', 'hb-ucs') : __('Kies product', 'hb-ucs')) . '</button>';
        echo '</div>';
        if ($attributesName !== '') {
            echo '<div class="hb-ucs-product-picker-attributes" data-attributes-name="' . esc_attr($attributesName) . '">';
            echo '<div class="hb-ucs-product-picker-field__header">';
            echo '<span class="hb-ucs-product-picker-field__label">' . esc_html__('Variaties', 'hb-ucs') . '</span>';
            echo '</div>';
            $this->render_manageable_product_attribute_fields($selectedId, $selectedAttributes, $attributesName, $disabled, $productOptions);
            echo '</div>';
        }
        echo '</div>';
    }

    private function render_account_subscription_item_editor_row(string $rowKey, array $item, bool $manageDisabled, array $productOptions): void {
        $selectedId = (int) ($item['base_product_id'] ?? 0);
        $selectedAttributes = $this->get_subscription_item_selected_attributes($item);
        $variationSummary = (string) ($item['variation_summary'] ?? '');
        $unitPrice = $this->get_subscription_item_display_amount($item, 1, true);
        $unitPriceHtml = $unitPrice > 0 ? (function_exists('wc_price') ? wc_price($unitPrice) : number_format($unitPrice, 2, '.', '')) : '';
        $itemLabel = isset($item['display_label']) && (string) $item['display_label'] !== '' ? (string) $item['display_label'] : ($selectedId > 0 ? $this->get_subscription_item_label($item) : __('Nieuw product', 'hb-ucs'));

        echo '<article class="hb-ucs-subscription-item-card hb-ucs-subscription-item-card--compact hb-ucs-subscription-item-card--dashboard woocommerce-MyAccount-content-wrapper woocommerce-EditAccountForm" data-hb-ucs-item-row="1">';
        echo '<div class="hb-ucs-subscription-item-card__media">' . wp_kses_post($this->get_account_subscription_item_image_html($item)) . '</div>';
        echo '<div class="hb-ucs-subscription-item-card__content">';
        echo '<div class="hb-ucs-subscription-item-card__top hb-ucs-subscription-item-card__top--compact">';
        echo '<div class="hb-ucs-subscription-item-card__heading">';
        echo '<h4>' . esc_html($itemLabel) . '</h4>';
        if ($unitPriceHtml !== '') {
            echo '<p class="hb-ucs-product-card__price">' . wp_kses_post($unitPriceHtml) . ' <span>' . esc_html__('per levering', 'hb-ucs') . '</span></p>';
        }
        echo '<p class="hb-ucs-product-card__variation-summary">' . esc_html($variationSummary) . '</p>';
        echo '</div>';
        echo '<button type="button" class="hb-ucs-product-card__dismiss" data-hb-ucs-remove-toggle="items[' . esc_attr($rowKey) . '][remove]" aria-label="' . esc_attr__('Product verwijderen', 'hb-ucs') . '" ' . disabled($manageDisabled, true, false) . '>&times;</button>';
        echo '</div>';
        echo '<div class="hb-ucs-product-card__editor">';
        echo '<div class="hb-ucs-product-card__controls">';
        echo '<div class="hb-ucs-product-card__qty">';
        echo '<span class="hb-ucs-product-card__meta-label">' . esc_html__('Aantal', 'hb-ucs') . '</span>';
        echo '<div class="quantity hb-ucs-quantity-field hb-ucs-quantity-field--compact hb-ucs-quantity-field--stepper">';
        echo '<button type="button" class="hb-ucs-qty-stepper__button minus" data-hb-ucs-qty-step="-1" ' . disabled($manageDisabled, true, false) . '>&minus;</button>';
        echo '<input type="number" class="input-text qty text" min="1" step="1" inputmode="numeric" name="items[' . esc_attr($rowKey) . '][qty]" value="' . esc_attr((string) ((int) ($item['qty'] ?? 1))) . '" ' . disabled($manageDisabled, true, false) . ' />';
        echo '<button type="button" class="hb-ucs-qty-stepper__button plus" data-hb-ucs-qty-step="1" ' . disabled($manageDisabled, true, false) . '>+</button>';
        echo '</div>';
        echo '</div>';
        echo '<div class="hb-ucs-product-card__links">';
        echo '<label class="hb-ucs-remove-toggle hb-ucs-remove-toggle--inline"><input type="checkbox" name="items[' . esc_attr($rowKey) . '][remove]" value="1" ' . disabled($manageDisabled, true, false) . ' /> <span>' . esc_html__('Verwijder', 'hb-ucs') . '</span></label>';
        echo '<span class="hb-ucs-product-card__divider" aria-hidden="true">•</span>';
        echo '<span class="hb-ucs-product-card__help-link">' . esc_html__('Pas hieronder product en variatie aan', 'hb-ucs') . '</span>';
        echo '</div>';
        echo '</div>';
        echo '<div class="hb-ucs-subscription-item-card__body hb-ucs-subscription-item-card__body--visible">';
        echo '<div class="hb-ucs-subscription-item-card__picker hb-ucs-subscription-item-card__picker--editor">';
        $this->render_manageable_product_select(
            'items[' . $rowKey . '][product_id]',
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

        $categories = isset($productOptions['categories']) && is_array($productOptions['categories']) ? $productOptions['categories'] : [];
        $items = isset($productOptions['items']) && is_array($productOptions['items']) ? $productOptions['items'] : [];
        $variableConfigs = isset($productOptions['variable_configs']) && is_array($productOptions['variable_configs']) ? $productOptions['variable_configs'] : [];

        echo '<script type="application/json" id="hb-ucs-product-picker-config">' . wp_json_encode([
            'variableConfigs' => $variableConfigs,
            'variationLookup' => isset($productOptions['variation_lookup']) && is_array($productOptions['variation_lookup']) ? $productOptions['variation_lookup'] : [],
            'chooseOptionLabel' => __('Kies een optie…', 'hb-ucs'),
        ]) . '</script>';

        echo '<div class="hb-ucs-product-modal" id="hb-ucs-product-modal" hidden aria-hidden="true">';
        echo '<div class="hb-ucs-product-modal__backdrop" data-close-product-modal="1"></div>';
        echo '<div class="hb-ucs-product-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="hb-ucs-product-modal-title">';
        echo '<div class="hb-ucs-product-modal__header">';
        echo '<h3 id="hb-ucs-product-modal-title">' . esc_html__('Kies een product', 'hb-ucs') . '</h3>';
        echo '<button type="button" class="hb-ucs-product-modal__close" data-close-product-modal="1" aria-label="' . esc_attr__('Sluiten', 'hb-ucs') . '">×</button>';
        echo '</div>';
        echo '<div class="hb-ucs-product-modal__filters">';
        echo '<input type="search" class="hb-ucs-product-modal__search" placeholder="' . esc_attr__('Zoek op productnaam of variatie…', 'hb-ucs') . '" />';
        echo '<select class="hb-ucs-product-modal__category">';
        echo '<option value="">' . esc_html__('Alle categorieën', 'hb-ucs') . '</option>';
        foreach ($categories as $termId => $termName) {
            echo '<option value="' . esc_attr((string) $termId) . '">' . esc_html((string) $termName) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo '<div class="hb-ucs-product-modal__results">';
        if (empty($items)) {
            echo '<p class="hb-ucs-product-modal__empty">' . esc_html__('Er zijn geen geschikte producten beschikbaar.', 'hb-ucs') . '</p>';
        } else {
            foreach ($items as $item) {
                $itemId = (int) ($item['id'] ?? 0);
                $itemLabel = (string) ($item['label'] ?? '');
                $categoryIds = isset($item['categories']) && is_array($item['categories']) ? implode(',', array_map('intval', $item['categories'])) : '';
                $categoryNames = isset($item['category_names']) && is_array($item['category_names']) ? implode(', ', array_map('strval', $item['category_names'])) : '';
                $searchText = strtolower(trim($itemLabel . ' ' . $categoryNames));
                $targetId = (int) ($item['target_id'] ?? $itemId);
                $selectedAttributes = isset($item['selected_attributes']) && is_array($item['selected_attributes']) ? $item['selected_attributes'] : [];
                $imageHtml = isset($item['image_html']) ? (string) $item['image_html'] : '';
                $priceHtml = isset($item['price_html']) ? (string) $item['price_html'] : '';
                $summary = isset($item['summary']) ? (string) $item['summary'] : '';
                echo '<button type="button" class="hb-ucs-product-modal__item" data-product-id="' . esc_attr((string) $itemId) . '" data-target-product-id="' . esc_attr((string) $targetId) . '" data-product-label="' . esc_attr($itemLabel) . '" data-product-summary="' . esc_attr($summary) . '" data-product-price="' . esc_attr($priceHtml) . '" data-product-image="' . esc_attr(base64_encode($imageHtml)) . '" data-product-categories="' . esc_attr($categoryIds) . '" data-product-search="' . esc_attr($searchText) . '" data-selected-attributes="' . esc_attr(wp_json_encode($selectedAttributes)) . '" ' . disabled($disabled, true, false) . '>';
                echo '<strong>' . esc_html($itemLabel) . '</strong>';
                if ($priceHtml !== '') {
                    echo '<small>' . esc_html($priceHtml) . '</small>';
                }
                if ($summary !== '') {
                    echo '<small>' . esc_html($summary) . '</small>';
                }
                if ($categoryNames !== '') {
                    echo '<small>' . esc_html($categoryNames) . '</small>';
                }
                echo '</button>';
            }
        }
        echo '<p class="hb-ucs-product-modal__no-results" hidden>' . esc_html__('Geen producten gevonden voor deze filters.', 'hb-ucs') . '</p>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
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
                    $attributeKey = 'attribute_' . sanitize_title($attributeName);
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

    private function get_variable_product_attribute_config($product): array {
        if (!$product || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable') || !method_exists($product, 'get_attributes')) {
            return [];
        }

        $config = [];
        foreach ((array) $product->get_attributes() as $attributeObject) {
            if (!is_object($attributeObject) || !method_exists($attributeObject, 'get_variation') || !$attributeObject->get_variation() || !method_exists($attributeObject, 'get_name')) {
                continue;
            }

            $attributeName = (string) $attributeObject->get_name();
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
                'key' => 'attribute_' . sanitize_title($attributeName),
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

            $subscriptionPrice = $scheme !== '' ? $this->get_variation_subscription_price($variationId, $scheme) : null;
            $priceHtml = $subscriptionPrice !== null ? (string) html_entity_decode(wp_strip_all_tags(wc_price($subscriptionPrice), true), ENT_QUOTES, 'UTF-8') : (method_exists($variation, 'get_price_html') ? (string) wp_strip_all_tags($variation->get_price_html(), true) : '');

            $rows[] = [
                'id' => $variationId,
                'attributes' => $this->get_selected_attributes_from_variation($variation),
                'summary' => $this->get_variation_option_label($variation, $variationId),
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
            $value = is_scalar($value) ? (string) $value : '';
            if ($value === '') {
                continue;
            }
            $out[(string) $key] = $value;
        }
        return $out;
    }

    private function get_subscription_item_selected_attributes(array $item): array {
        $stored = isset($item['selected_attributes']) && is_array($item['selected_attributes']) ? $item['selected_attributes'] : [];
        if (!empty($stored)) {
            return $stored;
        }

        $variationId = (int) ($item['base_variation_id'] ?? 0);
        if ($variationId > 0) {
            $variation = wc_get_product($variationId);
            return $this->get_selected_attributes_from_variation($variation);
        }

        return [];
    }

    private function normalize_selected_attributes_for_product($product, array $selectedAttributes): array {
        if (!$product || !is_object($product) || !method_exists($product, 'is_type') || !$product->is_type('variable')) {
            return [];
        }

        $normalized = [];
        $config = $this->get_variable_product_attribute_config($product);
        foreach ($config as $attribute) {
            $key = (string) ($attribute['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $value = isset($selectedAttributes[$key]) ? sanitize_title((string) $selectedAttributes[$key]) : '';
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

        $normalized = $this->normalize_selected_attributes_for_product($product, $selectedAttributes);
        if (empty($normalized)) {
            return 0;
        }

        $config = $this->get_variable_product_attribute_config($product);
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

        if (method_exists($product, 'is_type') && $product->is_type('variation')) {
            $parentId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
            $parent = $parentId > 0 ? wc_get_product($parentId) : null;
            $prefix = $parent && is_object($parent) && method_exists($parent, 'get_name') ? $parent->get_name() . ' — ' : '';
            $variationText = $this->get_variation_option_label($product, $targetId);
            return wp_strip_all_tags($prefix . $variationText, true);
        }

        return wp_strip_all_tags((string) $product->get_name(), true);
    }

    private function normalize_subscription_item(array $item): ?array {
        $baseProductId = (int) ($item['base_product_id'] ?? 0);
        $baseVariationId = (int) ($item['base_variation_id'] ?? 0);
        $qty = (int) ($item['qty'] ?? 1);
        $unitPrice = isset($item['unit_price']) ? (float) wc_format_decimal((string) $item['unit_price']) : 0.0;
        $priceIncludesTax = !empty($item['price_includes_tax']);
        $selectedAttributes = isset($item['selected_attributes']) && is_array($item['selected_attributes']) ? $item['selected_attributes'] : [];

        if ($baseProductId <= 0) {
            return null;
        }
        if ($qty <= 0) {
            $qty = 1;
        }

        $normalizedAttributes = [];
        foreach ($selectedAttributes as $key => $value) {
            $key = sanitize_key((string) $key);
            $value = sanitize_title((string) $value);
            if ($key === '' || $value === '') {
                continue;
            }
            $normalizedAttributes[$key] = $value;
        }

        return [
            'base_product_id' => $baseProductId,
            'base_variation_id' => max(0, $baseVariationId),
            'qty' => $qty,
            'unit_price' => $unitPrice,
            'price_includes_tax' => $priceIncludesTax ? 1 : 0,
            'selected_attributes' => $normalizedAttributes,
        ];
    }

    private function get_subscription_items(int $subId): array {
        $stored = get_post_meta($subId, self::SUB_META_ITEMS, true);
        $items = [];

        if (is_array($stored)) {
            foreach ($stored as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $item = $this->normalize_subscription_item($row);
                if ($item) {
                    $items[] = $item;
                }
            }
        }

        if (!empty($items)) {
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

    private function persist_subscription_items(int $subId, array $items): void {
        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $row = $this->normalize_subscription_item($item);
            if ($row) {
                $normalized[] = $row;
            }
        }

        if (empty($normalized)) {
            return;
        }

        update_post_meta($subId, self::SUB_META_ITEMS, $normalized);

        $first = $normalized[0];
        update_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID, (string) ($first['base_product_id'] ?? 0));
        update_post_meta($subId, self::SUB_META_BASE_VARIATION_ID, (string) ($first['base_variation_id'] ?? 0));
        update_post_meta($subId, self::SUB_META_QTY, (string) ($first['qty'] ?? 1));
        update_post_meta($subId, self::SUB_META_UNIT_PRICE, (string) wc_format_decimal((string) ($first['unit_price'] ?? 0.0), wc_get_price_decimals()));
    }

    private function build_subscription_item_from_selection(int $selectedId, string $scheme, int $qty = 1, ?float $fallbackUnitPrice = null, array $selectedAttributes = []): ?array {
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
            $baseVariationId = $selectedId;
            $baseProductId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
            $selectedAttributes = $this->get_selected_attributes_from_variation($product);
        } elseif (method_exists($product, 'is_type') && $product->is_type('variable')) {
            $selectedAttributes = $this->normalize_selected_attributes_for_product($product, $selectedAttributes);
            $baseVariationId = $this->resolve_variation_id_from_attributes($product, $selectedAttributes);
            if ($baseVariationId <= 0) {
                return null;
            }
        }

        if ($baseProductId <= 0 || get_post_meta($baseProductId, self::META_ENABLED, true) !== 'yes') {
            return null;
        }

        $unitPrice = null;
        if ($baseVariationId > 0) {
            $unitPrice = $this->get_variation_subscription_price($baseVariationId, $scheme);
        } else {
            $unitPrice = $this->get_base_subscription_price($baseProductId, $scheme);
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
        ]);
    }

    private function get_subscription_total_amount(int $subId, bool $includeTax = false): float {
        $amount = 0.0;
        foreach ($this->get_subscription_items($subId) as $item) {
            if ($includeTax) {
                $amount += $this->get_subscription_item_display_amount($item, (int) ($item['qty'] ?? 1), true);
            } else {
                $amount += ($this->get_subscription_item_storage_unit_price($item) * (int) ($item['qty'] ?? 1));
            }
        }
        return (float) wc_format_decimal((string) $amount);
    }

    private function get_subscription_item_display_amount(array $item, int $qty = 1, bool $includeTax = true): float {
        $qty = max(1, $qty);
        $unitPrice = (float) ($item['unit_price'] ?? 0.0);
        if ($unitPrice <= 0) {
            return 0.0;
        }

        if ($includeTax && !empty($item['price_includes_tax'])) {
            return (float) wc_format_decimal((string) ($unitPrice * $qty));
        }

        $productId = (int) ($item['base_variation_id'] ?? 0);
        if ($productId <= 0) {
            $productId = (int) ($item['base_product_id'] ?? 0);
        }

        $product = $productId > 0 ? wc_get_product($productId) : false;
        return $this->get_product_price_display_amount($product, $unitPrice, $qty, $includeTax);
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

    private function get_subscription_related_orders(int $subId): array {
        $rows = [];
        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        if ($parentOrderId > 0) {
            $parent = wc_get_order($parentOrderId);
            if ($parent && is_object($parent)) {
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
                if (!$order || !is_object($order)) {
                    continue;
                }
                $rows[] = ['type' => __('Renewal', 'hb-ucs'), 'order' => $order];
            }
        }

        return $rows;
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
            update_post_meta($subId, self::SUB_META_BILLING, $billing);
            update_post_meta($subId, self::SUB_META_SHIPPING, $shipping);
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

    public function render_subscription_data_metabox($post): void {
        $subId = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        if ($subId <= 0) {
            echo '<p>' . esc_html__('Onbekend abonnement.', 'hb-ucs') . '</p>';
            return;
        }

        wp_nonce_field('hb_ucs_save_subscription', 'hb_ucs_save_subscription_nonce');

        $status = (string) get_post_meta($subId, self::SUB_META_STATUS, true);
        $userId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $customerId = (string) get_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, true);
        $mandateId = (string) get_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, true);
        $lastPaymentId = (string) get_post_meta($subId, self::SUB_META_LAST_PAYMENT_ID, true);
        $paymentMethodTitle = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
        $paymentMethod = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD, true);
        $customerLabel = $this->get_admin_customer_label($userId);
        $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        $contact = $this->get_subscription_contact_snapshot($userId, $parentOrder, $subId);
        $gatewayChoices = $this->get_available_payment_gateway_choices_for_user($userId);
        if ($paymentMethod !== '' && !isset($gatewayChoices[$paymentMethod])) {
            $gatewayChoices = [$paymentMethod => ($paymentMethodTitle !== '' ? $paymentMethodTitle : $paymentMethod)] + $gatewayChoices;
        }

        $orderLink = $parentOrderId > 0 ? admin_url('post.php?post=' . $parentOrderId . '&action=edit') : '';
        $userLink = $userId > 0 ? admin_url('user-edit.php?user_id=' . $userId) : '';

        echo '<div class="order_data_column_container">';
        echo '<div class="order_data_column">';

        echo '<h3>' . esc_html__('Algemeen', 'hb-ucs') . '</h3>';

        echo '<p class="form-field form-field-wide"><label for="hb_ucs_sub_status"><strong>' . esc_html__('Status', 'hb-ucs') . '</strong></label>';
        echo '<select name="hb_ucs_sub_status" id="hb_ucs_sub_status" style="width:100%;">';
        foreach ($this->get_subscription_statuses() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';

        echo '<p class="form-field form-field-wide"><label for="hb_ucs_sub_user_id"><strong>' . esc_html__('Klant', 'hb-ucs') . '</strong></label>';
        echo '<select id="hb_ucs_sub_user_id" name="hb_ucs_sub_user_id" class="wc-customer-search" data-placeholder="' . esc_attr__('Zoek klant…', 'hb-ucs') . '" data-allow_clear="true" style="width:100%;">';
        if ($userId > 0 && $customerLabel !== '') {
            echo '<option value="' . esc_attr((string) $userId) . '" selected="selected">' . esc_html($customerLabel) . '</option>';
        }
        echo '</select>';
        if ($userLink !== '') {
            echo '<br/><a href="' . esc_url($userLink) . '">' . esc_html__('Open klantprofiel', 'hb-ucs') . '</a>';
        }
        echo '</p>';

        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Start order', 'hb-ucs') . '</strong><br/>';
        if ($orderLink !== '') {
            echo '<a href="' . esc_url($orderLink) . '">#' . esc_html((string) $parentOrderId) . '</a>';
        } else {
            echo '—';
        }
        echo '</p>';

        echo '<p class="form-field form-field-wide"><label for="hb_ucs_sub_payment_method"><strong>' . esc_html__('Betaalmethode', 'hb-ucs') . '</strong></label>';
        echo '<select name="hb_ucs_sub_payment_method" id="hb_ucs_sub_payment_method" style="width:100%;">';
        echo '<option value="">' . esc_html__('Kies een betaalmethode…', 'hb-ucs') . '</option>';
        foreach ($gatewayChoices as $gatewayId => $gatewayTitle) {
            echo '<option value="' . esc_attr((string) $gatewayId) . '" ' . selected($paymentMethod, (string) $gatewayId, false) . '>' . esc_html((string) $gatewayTitle) . '</option>';
        }
        echo '</select>';
        echo '<span class="description" style="display:block;margin-top:6px;">' . esc_html__('Beschikbare methodes volgen de B2B-instellingen voor de gekozen klant. Sla op en herlaad na klantwissel om de lijst te verversen.', 'hb-ucs') . '</span>';
        echo '</p>';

        echo '</div>';
        echo '<div class="order_data_column">';

        echo '<h3>' . esc_html__('Klantgegevens', 'hb-ucs') . '</h3>';
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('E-mailadres', 'hb-ucs') . '</strong><br/>' . esc_html($contact['billing_email'] !== '' ? (string) $contact['billing_email'] : '—') . '</p>';
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Telefoon', 'hb-ucs') . '</strong><br/>' . esc_html($contact['billing_phone'] !== '' ? (string) $contact['billing_phone'] : '—') . '</p>';
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Factuuradres', 'hb-ucs') . '</strong><br/>' . wp_kses_post((string) $contact['billing_address']) . '</p>';
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('Verzendadres', 'hb-ucs') . '</strong><br/>' . wp_kses_post((string) $contact['shipping_address']) . '</p>';

        echo '</div>';
        echo '<div class="order_data_column">';

        echo '<h3>' . esc_html__('Mollie', 'hb-ucs') . '</h3>';
        echo '<p class="form-field form-field-wide"><strong>customerId</strong><br/>' . esc_html($customerId !== '' ? $customerId : '—') . '</p>';
        echo '<p class="form-field form-field-wide"><strong>mandateId</strong><br/>' . esc_html($mandateId !== '' ? $mandateId : '—') . '</p>';
        echo '<p class="form-field form-field-wide"><strong>lastPaymentId</strong><br/>' . esc_html($lastPaymentId !== '' ? $lastPaymentId : '—') . '</p>';

        echo '</div>';
        echo '</div>';
    }

    public function render_subscription_items_metabox($post): void {
        $subId = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        if ($subId <= 0) {
            echo '<p>' . esc_html__('Onbekend abonnement.', 'hb-ucs') . '</p>';
            return;
        }

        $items = $this->get_subscription_items($subId);
        $firstItem = isset($items[0]) && is_array($items[0]) ? $items[0] : [];
        $baseProductId = (int) ($firstItem['base_product_id'] ?? get_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID, true));
        $baseVariationId = (int) ($firstItem['base_variation_id'] ?? get_post_meta($subId, self::SUB_META_BASE_VARIATION_ID, true));
        $qty = (int) ($firstItem['qty'] ?? get_post_meta($subId, self::SUB_META_QTY, true));
        $unitPrice = isset($firstItem['unit_price']) ? (string) $firstItem['unit_price'] : (string) get_post_meta($subId, self::SUB_META_UNIT_PRICE, true);

        $selectedId = $baseVariationId > 0 ? $baseVariationId : $baseProductId;
        $selectedLabel = $this->get_admin_product_label($selectedId);

        if ($qty <= 0) {
            $qty = 1;
        }

        $displayItem = [
            'base_product_id' => $baseProductId,
            'base_variation_id' => $baseVariationId,
            'qty' => $qty,
            'unit_price' => (float) wc_format_decimal($unitPrice !== '' ? $unitPrice : '0'),
        ];
        $displayUnitPrice = $this->get_subscription_item_display_amount($displayItem, 1, true);

        echo '<div class="woocommerce_order_items_wrapper">';
        echo '<table class="woocommerce_order_items widefat" cellspacing="0">';
        echo '<thead><tr>';
        echo '<th class="item" colspan="2">' . esc_html__('Item', 'hb-ucs') . '</th>';
        echo '<th class="quantity">' . esc_html__('Aantal', 'hb-ucs') . '</th>';
        echo '<th class="line_cost">' . esc_html__('Prijs (per stuk, incl. btw)', 'hb-ucs') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        echo '<tr class="item">';
        echo '<td class="thumb">&nbsp;</td>';
        echo '<td class="name">';
        echo '<label for="hb_ucs_sub_product_id" class="screen-reader-text">' . esc_html__('Product', 'hb-ucs') . '</label>';
        echo '<select id="hb_ucs_sub_product_id" name="hb_ucs_sub_product_id" class="wc-product-search hb-ucs-sub-product-search" style="width:100%;" data-placeholder="' . esc_attr__('Zoek een product…', 'hb-ucs') . '" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">';
        if ($selectedId > 0 && $selectedLabel !== '') {
            echo '<option value="' . esc_attr((string) $selectedId) . '" selected="selected">' . esc_html($selectedLabel) . '</option>';
        }
        echo '</select>';
        echo '<p class="description" style="margin:8px 0 0;">' . esc_html__('Kies het primaire product of variatie. Extra regels uit frontend-beheer blijven behouden.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '<td class="quantity">';
        echo '<input type="number" min="1" step="1" name="hb_ucs_sub_qty" value="' . esc_attr((string) $qty) . '" style="width:80px;" />';
        echo '</td>';
        echo '<td class="line_cost">';
        echo '<input type="text" name="hb_ucs_sub_unit_price" value="' . esc_attr((string) wc_format_decimal((string) $displayUnitPrice, wc_get_price_decimals())) . '" style="width:110px;" />';
        echo '</td>';
        echo '</tr>';
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }

    public function render_subscription_schedule_metabox($post): void {
        $subId = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        if ($subId <= 0) {
            echo '<p>' . esc_html__('Onbekend abonnement.', 'hb-ucs') . '</p>';
            return;
        }

        $scheme = (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
        $trialEnd = (int) get_post_meta($subId, self::SUB_META_TRIAL_END, true);
        $nextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
        $endDate = (int) get_post_meta($subId, self::SUB_META_END_DATE, true);
        $settings = $this->get_settings();
        $freqs = isset($settings['frequencies']) && is_array($settings['frequencies']) ? $settings['frequencies'] : [];

        echo '<p><label for="hb_ucs_sub_scheme"><strong>' . esc_html__('Frequentie', 'hb-ucs') . '</strong></label><br/>';
        echo '<select name="hb_ucs_sub_scheme" id="hb_ucs_sub_scheme" style="width:100%;">';
        foreach (['1w' => 1, '2w' => 2, '3w' => 3, '4w' => 4] as $key => $interval) {
            $row = isset($freqs[$key]) && is_array($freqs[$key]) ? $freqs[$key] : [];
            $label = (string) ($row['label'] ?? $key);
            $enabled = !empty($row['enabled']);
            $suffix = $enabled ? '' : ' — ' . __('globaal uitgeschakeld', 'hb-ucs');
            echo '<option value="' . esc_attr($key) . '" ' . selected($scheme, $key, false) . '>' . esc_html($label . $suffix) . '</option>';
        }
        echo '</select></p>';

        echo '<p><label for="hb_ucs_sub_next_payment"><strong>' . esc_html__('Volgende betaling', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="datetime-local" name="hb_ucs_sub_next_payment" id="hb_ucs_sub_next_payment" value="' . esc_attr($this->format_datetime_local($nextPayment)) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_trial_end"><strong>' . esc_html__('Trial eindigt', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="datetime-local" name="hb_ucs_sub_trial_end" id="hb_ucs_sub_trial_end" value="' . esc_attr($this->format_datetime_local($trialEnd)) . '" style="width:100%;" />';
        echo '</p>';

        echo '<p><label for="hb_ucs_sub_end_date"><strong>' . esc_html__('Einddatum', 'hb-ucs') . '</strong></label><br/>';
        echo '<input type="datetime-local" name="hb_ucs_sub_end_date" id="hb_ucs_sub_end_date" value="' . esc_attr($this->format_datetime_local($endDate)) . '" style="width:100%;" />';
        echo '</p>';
        echo '<p><small>' . esc_html(sprintf(__('Datums en tijden gebruiken de WordPress tijdzone: %s.', 'hb-ucs'), $this->get_wp_timezone_label())) . '</small></p>';
    }

    public function render_subscription_related_orders_metabox($post): void {
        $subId = is_object($post) && isset($post->ID) ? (int) $post->ID : 0;
        if ($subId <= 0) {
            echo '<p>' . esc_html__('Onbekend abonnement.', 'hb-ucs') . '</p>';
            return;
        }

        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $orders = [];
        if (function_exists('wc_get_orders')) {
            $orders = wc_get_orders([
                'limit' => 50,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_key' => self::ORDER_META_SUBSCRIPTION_ID,
                'meta_value' => (string) $subId,
            ]);
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('#', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Type', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Status', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Datum', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Totaal', 'hb-ucs') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        // Parent order.
        if ($parentOrderId > 0 && function_exists('wc_get_order')) {
            $po = wc_get_order($parentOrderId);
            if ($po && is_object($po)) {
                $link = admin_url('post.php?post=' . $parentOrderId . '&action=edit');
                $date = $this->format_wc_datetime_for_site_settings(method_exists($po, 'get_date_created') ? $po->get_date_created() : null);
                $total = method_exists($po, 'get_formatted_order_total') ? $po->get_formatted_order_total() : (string) $po->get_total();
                echo '<tr>';
                echo '<td><a href="' . esc_url($link) . '">#' . esc_html((string) $parentOrderId) . '</a></td>';
                echo '<td>' . esc_html__('Start', 'hb-ucs') . '</td>';
                echo '<td>' . esc_html(method_exists($po, 'get_status') ? $po->get_status() : '') . '</td>';
                echo '<td>' . esc_html($date) . '</td>';
                echo '<td>' . wp_kses_post($total) . '</td>';
                echo '</tr>';
            }
        }

        // Renewal orders.
        if (is_array($orders)) {
            foreach ($orders as $o) {
                if (!$o || !is_object($o) || !method_exists($o, 'get_id')) {
                    continue;
                }
                $id = (int) $o->get_id();
                $link = admin_url('post.php?post=' . $id . '&action=edit');
                $date = $this->format_wc_datetime_for_site_settings(method_exists($o, 'get_date_created') ? $o->get_date_created() : null);
                $total = method_exists($o, 'get_formatted_order_total') ? $o->get_formatted_order_total() : (string) $o->get_total();
                echo '<tr>';
                echo '<td><a href="' . esc_url($link) . '">#' . esc_html((string) $id) . '</a></td>';
                echo '<td>' . esc_html__('Renewal', 'hb-ucs') . '</td>';
                echo '<td>' . esc_html(method_exists($o, 'get_status') ? $o->get_status() : '') . '</td>';
                echo '<td>' . esc_html($date) . '</td>';
                echo '<td>' . wp_kses_post($total) . '</td>';
                echo '</tr>';
            }
        }

        echo '</tbody>';
        echo '</table>';
    }

    public function save_subscription_post(int $postId, $post, bool $update): void {
        if (!is_admin()) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!$post || !is_object($post) || (string) ($post->post_type ?? '') !== self::SUB_CPT) {
            return;
        }
        if (!isset($_POST['hb_ucs_save_subscription_nonce']) || !wp_verify_nonce((string) $_POST['hb_ucs_save_subscription_nonce'], 'hb_ucs_save_subscription')) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        // Status.
        if (isset($_POST['hb_ucs_sub_status'])) {
            $st = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_status']));
            $allowed = array_keys($this->get_subscription_statuses());
            if (!in_array($st, $allowed, true)) {
                $st = (string) get_post_meta($postId, self::SUB_META_STATUS, true);
            }
            if ($st !== '') {
                update_post_meta($postId, self::SUB_META_STATUS, $st);
            }
        }

        // User.
        if (isset($_POST['hb_ucs_sub_user_id'])) {
            $uid = (int) absint((string) wp_unslash($_POST['hb_ucs_sub_user_id']));
            update_post_meta($postId, self::SUB_META_USER_ID, (string) $uid);
        }

        if (isset($_POST['hb_ucs_sub_payment_method'])) {
            $paymentMethod = sanitize_text_field((string) wp_unslash($_POST['hb_ucs_sub_payment_method']));
            $userId = isset($_POST['hb_ucs_sub_user_id']) ? (int) absint((string) wp_unslash($_POST['hb_ucs_sub_user_id'])) : (int) get_post_meta($postId, self::SUB_META_USER_ID, true);
            $gatewayChoices = $this->get_available_payment_gateway_choices_for_user($userId);

            if ($paymentMethod === '') {
                delete_post_meta($postId, self::SUB_META_PAYMENT_METHOD);
                delete_post_meta($postId, self::SUB_META_PAYMENT_METHOD_TITLE);
            } elseif (isset($gatewayChoices[$paymentMethod])) {
                update_post_meta($postId, self::SUB_META_PAYMENT_METHOD, $paymentMethod);
                update_post_meta($postId, self::SUB_META_PAYMENT_METHOD_TITLE, (string) $gatewayChoices[$paymentMethod]);
            }
        }

        $items = $this->get_subscription_items($postId);
        $firstItem = isset($items[0]) && is_array($items[0]) ? $items[0] : [
            'base_product_id' => (int) get_post_meta($postId, self::SUB_META_BASE_PRODUCT_ID, true),
            'base_variation_id' => (int) get_post_meta($postId, self::SUB_META_BASE_VARIATION_ID, true),
            'qty' => (int) get_post_meta($postId, self::SUB_META_QTY, true),
            'unit_price' => (float) wc_format_decimal((string) get_post_meta($postId, self::SUB_META_UNIT_PRICE, true)),
        ];

        if (isset($_POST['hb_ucs_sub_product_id']) && function_exists('wc_get_product')) {
            $chosenId = (int) absint((string) wp_unslash($_POST['hb_ucs_sub_product_id']));
            if ($chosenId > 0) {
                $updated = $this->build_subscription_item_from_selection($chosenId, (string) get_post_meta($postId, self::SUB_META_SCHEME, true), (int) ($firstItem['qty'] ?? 1), (float) ($firstItem['unit_price'] ?? 0.0));
                if ($updated) {
                    $firstItem = $updated;
                }
            }
        }

        if (isset($_POST['hb_ucs_sub_qty'])) {
            $qty = (int) absint((string) wp_unslash($_POST['hb_ucs_sub_qty']));
            $firstItem['qty'] = $qty > 0 ? $qty : 1;
        }

        if (isset($_POST['hb_ucs_sub_unit_price']) && function_exists('wc_format_decimal')) {
            $rawPrice = (string) wp_unslash($_POST['hb_ucs_sub_unit_price']);
            $displayPrice = (float) wc_format_decimal($rawPrice, wc_get_price_decimals());
            $selectedId = (int) ($firstItem['base_variation_id'] ?? 0);
            if ($selectedId <= 0) {
                $selectedId = (int) ($firstItem['base_product_id'] ?? 0);
            }
            $product = $selectedId > 0 && function_exists('wc_get_product') ? wc_get_product($selectedId) : false;
            $firstItem['unit_price'] = $this->get_product_price_storage_amount($product, $displayPrice, 1);
            $firstItem['price_includes_tax'] = 0;
        }

        $items[0] = $firstItem;
        $this->persist_subscription_items($postId, $items);

        // Schedule.
        if (isset($_POST['hb_ucs_sub_scheme'])) {
            $scheme = sanitize_key((string) wp_unslash($_POST['hb_ucs_sub_scheme']));
            if (!in_array($scheme, ['1w', '2w', '3w', '4w'], true)) {
                $scheme = (string) get_post_meta($postId, self::SUB_META_SCHEME, true);
            }
            if ($scheme !== '') {
                update_post_meta($postId, self::SUB_META_SCHEME, $scheme);
                $settings = $this->get_settings();
                $row = isset($settings['frequencies'][$scheme]) && is_array($settings['frequencies'][$scheme]) ? $settings['frequencies'][$scheme] : [];
                $interval = (int) ($row['interval'] ?? 0);
                if ($interval <= 0 && preg_match('/^(\d)w$/', $scheme, $m)) {
                    $interval = (int) $m[1];
                }
                if ($interval <= 0) {
                    $interval = 1;
                }
                update_post_meta($postId, self::SUB_META_INTERVAL, (string) $interval);
                update_post_meta($postId, self::SUB_META_PERIOD, 'week');
            }
        }
        if (isset($_POST['hb_ucs_sub_next_payment'])) {
            $raw = sanitize_text_field((string) wp_unslash($_POST['hb_ucs_sub_next_payment']));
            $ts = $this->parse_datetime_local($raw);
            if ($ts > 0) {
                update_post_meta($postId, self::SUB_META_NEXT_PAYMENT, (string) $ts);
            }
        }

        if (isset($_POST['hb_ucs_sub_trial_end'])) {
            $raw = sanitize_text_field((string) wp_unslash($_POST['hb_ucs_sub_trial_end']));
            $ts = $this->parse_datetime_local($raw);
            if ($ts > 0) {
                update_post_meta($postId, self::SUB_META_TRIAL_END, (string) $ts);
            } else {
                delete_post_meta($postId, self::SUB_META_TRIAL_END);
            }
        }

        if (isset($_POST['hb_ucs_sub_end_date'])) {
            $raw = sanitize_text_field((string) wp_unslash($_POST['hb_ucs_sub_end_date']));
            $ts = $this->parse_datetime_local($raw);
            if ($ts > 0) {
                update_post_meta($postId, self::SUB_META_END_DATE, (string) $ts);
            } else {
                delete_post_meta($postId, self::SUB_META_END_DATE);
            }
        }
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
            $cls = $classMap[$status] ?? ($status !== '' ? sanitize_html_class('status-' . $status) : '');
            echo '<mark class="order-status ' . esc_attr($cls) . '"><span>' . esc_html((string) $label) . '</span></mark>';
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

            $every = sprintf(
                /* translators: 1: interval number */
                __('elke %d week/weken', 'hb-ucs'),
                $interval
            );
            $settings = $this->get_settings();
            $freqs = isset($settings['frequencies']) && is_array($settings['frequencies']) ? $settings['frequencies'] : [];
            if ($scheme !== '' && isset($freqs[$scheme]) && is_array($freqs[$scheme]) && !empty($freqs[$scheme]['label'])) {
                $every = (string) $freqs[$scheme]['label'];
            }

            echo wp_kses_post($price);
            echo '<br/><small>' . esc_html($every) . '</small>';
            return;
        }

        if ($column === 'hb_ucs_start_date') {
            $post = get_post($postId);
            $ts = $post && isset($post->post_date_gmt) ? strtotime((string) $post->post_date_gmt . ' GMT') : 0;
            echo esc_html($ts ? $this->format_wp_date($ts) : '—');
            return;
        }

        if ($column === 'hb_ucs_trial_end') {
            $ts = (int) get_post_meta($postId, self::SUB_META_TRIAL_END, true);
            echo esc_html($ts > 0 ? $this->format_wp_date($ts) : '—');
            return;
        }

        if ($column === 'hb_ucs_next_payment') {
            $nextPayment = (int) get_post_meta($postId, self::SUB_META_NEXT_PAYMENT, true);
            echo esc_html($nextPayment > 0 ? $this->format_wp_datetime($nextPayment) : '—');
            return;
        }

        if ($column === 'hb_ucs_last_order_date') {
            $lastId = (int) get_post_meta($postId, self::SUB_META_LAST_ORDER_ID, true);
            $lastTs = (int) get_post_meta($postId, self::SUB_META_LAST_ORDER_DATE, true);
            if ($lastId > 0) {
                $link = admin_url('post.php?post=' . $lastId . '&action=edit');
                $label = $lastTs > 0 ? $this->format_wp_date($lastTs) : ('#' . $lastId);
                echo '<a href="' . esc_url($link) . '">' . esc_html($label) . '</a>';
            } else {
                echo esc_html($lastTs > 0 ? $this->format_wp_date($lastTs) : '—');
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

    public function filter_subscription_admin_title(string $title, int $postId): string {
        if (!is_admin()) {
            return $title;
        }
        if ($postId <= 0 || get_post_type($postId) !== self::SUB_CPT) {
            return $title;
        }
        if (!function_exists('get_current_screen')) {
            return $title;
        }
        $screen = get_current_screen();
        if (!$screen || (string) $screen->post_type !== self::SUB_CPT || (string) $screen->base !== 'edit') {
            return $title;
        }

        $userId = (int) get_post_meta($postId, self::SUB_META_USER_ID, true);
        $customer = '';
        if ($userId > 0) {
            $u = get_user_by('id', $userId);
            if ($u && is_object($u)) {
                $customer = (string) $u->display_name;
            }
        }

        if ($customer !== '') {
            return sprintf(__('#%1$d — %2$s', 'hb-ucs'), (int) $postId, $customer);
        }
        return sprintf(__('#%d', 'hb-ucs'), (int) $postId);
    }

    public function sortable_subscription_columns(array $columns): array {
        $columns['hb_ucs_next_payment'] = 'hb_ucs_next_payment';
        $columns['hb_ucs_trial_end'] = 'hb_ucs_trial_end';
        $columns['hb_ucs_end_date'] = 'hb_ucs_end_date';
        $columns['hb_ucs_last_order_date'] = 'hb_ucs_last_order_date';
        return $columns;
    }

    public function subscription_status_views(array $views): array {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return $views;
        }
        $screen = get_current_screen();
        if (!$screen || (string) $screen->post_type !== self::SUB_CPT || (string) $screen->base !== 'edit') {
            return $views;
        }

        $current = isset($_GET['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_status'])) : '';
        $baseUrl = remove_query_arg(['hb_ucs_sub_status', 'paged']);

        $allCount = $this->count_subscriptions_by_status('');
        $viewsOut = [];
        $viewsOut['all'] = '<a href="' . esc_url($baseUrl) . '"' . ($current === '' ? ' class="current"' : '') . '>' . esc_html__('Alle', 'hb-ucs') . ' <span class="count">(' . (int) $allCount . ')</span></a>';

        foreach ($this->get_subscription_statuses() as $statusKey => $label) {
            $cnt = $this->count_subscriptions_by_status($statusKey);
            if ($cnt <= 0) {
                continue;
            }
            $url = add_query_arg('hb_ucs_sub_status', $statusKey, $baseUrl);
            $viewsOut[$statusKey] = '<a href="' . esc_url($url) . '"' . ($current === $statusKey ? ' class="current"' : '') . '>' . esc_html((string) $label) . ' <span class="count">(' . (int) $cnt . ')</span></a>';
        }

        return $viewsOut;
    }

    public function subscription_admin_filters(): void {
        if (!is_admin() || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || (string) $screen->post_type !== self::SUB_CPT || (string) $screen->base !== 'edit') {
            return;
        }

        $customer = isset($_GET['hb_ucs_customer']) ? (int) absint((string) wp_unslash($_GET['hb_ucs_customer'])) : 0;
        $scheme = isset($_GET['hb_ucs_scheme']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_scheme'])) : '';

        // Customer search (WooCommerce enhanced select).
        echo '<select class="wc-customer-search" name="hb_ucs_customer" data-placeholder="' . esc_attr__('Filter op klant…', 'hb-ucs') . '" data-allow_clear="true" style="width: 240px;">';
        if ($customer > 0) {
            $u = get_user_by('id', $customer);
            if ($u && is_object($u)) {
                $label = $u->display_name;
                if (!empty($u->user_email)) {
                    $label .= ' (' . $u->user_email . ')';
                }
                echo '<option value="' . esc_attr((string) $customer) . '" selected="selected">' . esc_html($label) . '</option>';
            }
        }
        echo '</select>';

        // Scheme filter.
        $settings = $this->get_settings();
        $freqs = isset($settings['frequencies']) && is_array($settings['frequencies']) ? $settings['frequencies'] : [];
        echo '<select name="hb_ucs_scheme">';
        echo '<option value="">' . esc_html__('Alle frequenties', 'hb-ucs') . '</option>';
        foreach (['1w', '2w', '3w', '4w'] as $key) {
            $row = isset($freqs[$key]) && is_array($freqs[$key]) ? $freqs[$key] : [];
            $label = (string) ($row['label'] ?? $key);
            echo '<option value="' . esc_attr($key) . '" ' . selected($scheme, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function filter_subscription_admin_query($query): void {
        if (!is_admin() || !($query instanceof \WP_Query) || !$query->is_main_query()) {
            return;
        }
        $postType = $query->get('post_type');
        if ((is_array($postType) && !in_array(self::SUB_CPT, $postType, true)) || (!is_array($postType) && (string) $postType !== self::SUB_CPT)) {
            return;
        }

        $metaQuery = (array) $query->get('meta_query');

        $status = isset($_GET['hb_ucs_sub_status']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_sub_status'])) : '';
        if ($status !== '') {
            $metaQuery[] = [
                'key' => self::SUB_META_STATUS,
                'value' => $status,
                'compare' => '=',
            ];
        }

        $customer = isset($_GET['hb_ucs_customer']) ? (int) absint((string) wp_unslash($_GET['hb_ucs_customer'])) : 0;
        if ($customer > 0) {
            $metaQuery[] = [
                'key' => self::SUB_META_USER_ID,
                'value' => (string) $customer,
                'compare' => '=',
            ];
        }

        $scheme = isset($_GET['hb_ucs_scheme']) ? sanitize_key((string) wp_unslash($_GET['hb_ucs_scheme'])) : '';
        if ($scheme !== '') {
            $metaQuery[] = [
                'key' => self::SUB_META_SCHEME,
                'value' => $scheme,
                'compare' => '=',
            ];
        }

        if (!empty($metaQuery)) {
            $query->set('meta_query', $metaQuery);
        }

        // Sorting by our meta fields.
        $orderby = (string) $query->get('orderby');
        if ($orderby === 'hb_ucs_next_payment') {
            $query->set('meta_key', self::SUB_META_NEXT_PAYMENT);
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'hb_ucs_trial_end') {
            $query->set('meta_key', self::SUB_META_TRIAL_END);
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'hb_ucs_end_date') {
            $query->set('meta_key', self::SUB_META_END_DATE);
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'hb_ucs_last_order_date') {
            $query->set('meta_key', self::SUB_META_LAST_ORDER_DATE);
            $query->set('orderby', 'meta_value_num');
        }
    }

    private function count_subscriptions_by_status(string $status): int {
        $args = [
            'post_type' => self::SUB_CPT,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
        ];
        if ($status !== '') {
            $args['meta_query'] = [
                [
                    'key' => self::SUB_META_STATUS,
                    'value' => $status,
                    'compare' => '=',
                ],
            ];
        }
        $q = new \WP_Query($args);
        return (int) ($q->found_posts ?? 0);
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

    public function maybe_disable_default_customer_order_email_for_renewals(bool $enabled, $order): bool {
        if (!$enabled) {
            return false;
        }

        return $this->is_renewal_order($order) ? false : $enabled;
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

        $scheme = (string) $sourceItem->get_meta('_hb_ucs_subscription_scheme', true);
        $interval = 0;
        if (preg_match('/^(\d)w$/', $scheme, $m)) {
            $interval = (int) $m[1];
        }
        if ($interval <= 0) {
            $interval = 1;
        }

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
            'post_type' => self::SUB_CPT,
            'post_status' => 'publish',
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
        update_post_meta($subId, self::SUB_META_QTY, (string) $qty);
        update_post_meta($subId, self::SUB_META_UNIT_PRICE, (string) wc_format_decimal($unitPrice, wc_get_price_decimals()));
        update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) (time() + ($interval * WEEK_IN_SECONDS)));
        update_post_meta($subId, self::SUB_META_STATUS, ($mCustomer !== '' && $mMandate !== '') ? 'active' : 'pending_mandate');
        if ($mCustomer !== '') {
            update_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $mCustomer);
        }
        if ($mMandate !== '') {
            update_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, $mMandate);
        }

        $redirect = add_query_arg([
            'hb_ucs_subs_demo' => 'created',
            'sub_id' => (string) $subId,
        ], $redirect);
        wp_safe_redirect($redirect);
        exit;
    }

    public function maybe_render_wcs_migration_notice(): void {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || (string) $screen->id !== 'edit-' . self::SUB_CPT) {
            return;
        }

        $imported = isset($_GET['hb_ucs_wcs_imported']) ? (int) $_GET['hb_ucs_wcs_imported'] : 0;
        $refreshed = isset($_GET['hb_ucs_wcs_refreshed']) ? (int) $_GET['hb_ucs_wcs_refreshed'] : 0;
        $skippedExisting = isset($_GET['hb_ucs_wcs_skipped_existing']) ? (int) $_GET['hb_ucs_wcs_skipped_existing'] : 0;
        $skippedUnsupported = isset($_GET['hb_ucs_wcs_skipped_unsupported']) ? (int) $_GET['hb_ucs_wcs_skipped_unsupported'] : 0;
        $errors = isset($_GET['hb_ucs_wcs_errors']) ? (int) $_GET['hb_ucs_wcs_errors'] : 0;

        if (isset($_GET['hb_ucs_wcs_migrated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf(
                __('WCS migratie voltooid: %1$d geïmporteerd, %2$d bijgewerkt, %3$d al aanwezig, %4$d overgeslagen (niet ondersteund), %5$d fouten.', 'hb-ucs'),
                $imported,
                $refreshed,
                $skippedExisting,
                $skippedUnsupported,
                $errors
            ));
            echo '</p></div>';
        }

        if (!$this->wcs_available() || !function_exists('wcs_get_subscriptions')) {
            return;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=hb_ucs_subs_migrate_wcs'),
            'hb_ucs_subs_migrate_wcs',
            'hb_ucs_subs_migrate_wcs_nonce'
        );

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('WooCommerce Subscriptions is actief. Je kunt bestaande WCS abonnementen eenmalig importeren naar HB UCS voordat je volledig overschakelt.', 'hb-ucs');
        echo '</p><p><a class="button button-primary" href="' . esc_url($url) . '">' . esc_html__('Importeer bestaande WCS abonnementen', 'hb-ucs') . '</a></p></div>';
    }

    public function handle_migrate_wcs_subscriptions(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        check_admin_referer('hb_ucs_subs_migrate_wcs', 'hb_ucs_subs_migrate_wcs_nonce');

        $result = $this->migrate_wcs_subscriptions();

        $redirect = wp_get_referer();
        if (!$redirect) {
            $redirect = admin_url('edit.php?post_type=' . self::SUB_CPT);
        }

        $redirect = add_query_arg([
            'hb_ucs_wcs_migrated' => '1',
            'hb_ucs_wcs_imported' => (int) ($result['imported'] ?? 0),
            'hb_ucs_wcs_refreshed' => (int) ($result['refreshed'] ?? 0),
            'hb_ucs_wcs_skipped_existing' => (int) ($result['skipped_existing'] ?? 0),
            'hb_ucs_wcs_skipped_unsupported' => (int) ($result['skipped_unsupported'] ?? 0),
            'hb_ucs_wcs_errors' => (int) ($result['errors'] ?? 0),
        ], $redirect);

        wp_safe_redirect($redirect);
        exit;
    }

    private function migrate_wcs_subscriptions(): array {
        $result = [
            'imported' => 0,
            'refreshed' => 0,
            'skipped_existing' => 0,
            'skipped_unsupported' => 0,
            'errors' => 0,
        ];

        if (!$this->wcs_available() || !function_exists('wcs_get_subscriptions')) {
            return $result;
        }

        $statuses = function_exists('wcs_get_subscription_statuses') ? array_keys((array) wcs_get_subscription_statuses()) : ['active', 'on-hold', 'pending', 'pending-cancel', 'cancelled', 'expired'];
        $subscriptions = wcs_get_subscriptions([
            'subscriptions_per_page' => -1,
            'subscription_status' => $statuses,
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);

        foreach ((array) $subscriptions as $subscription) {
            $outcome = $this->migrate_single_wcs_subscription($subscription);
            if (isset($result[$outcome])) {
                $result[$outcome]++;
            } else {
                $result['errors']++;
            }
        }

        return $result;
    }

    private function migrate_single_wcs_subscription($subscription): string {
        if (!$subscription || !is_object($subscription) || !method_exists($subscription, 'get_id')) {
            return 'errors';
        }

        $sourceId = (int) $subscription->get_id();
        if ($sourceId <= 0) {
            return 'errors';
        }

        $existingSubId = $this->get_internal_subscription_id_by_wcs_source($sourceId);

        $scheme = $this->map_wcs_subscription_to_scheme($subscription);
        if ($scheme === '') {
            return 'skipped_unsupported';
        }

        $items = $this->get_subscription_items_from_wcs_subscription($subscription, $scheme);
        if (empty($items)) {
            return 'skipped_unsupported';
        }

        $freqs = $this->get_enabled_frequencies();
        $interval = isset($freqs[$scheme]['interval']) ? (int) $freqs[$scheme]['interval'] : 1;
        $period = isset($freqs[$scheme]['period']) ? (string) $freqs[$scheme]['period'] : 'week';

        $userId = method_exists($subscription, 'get_user_id') ? (int) $subscription->get_user_id() : 0;
        if ($userId <= 0 && method_exists($subscription, 'get_customer_id')) {
            $userId = (int) $subscription->get_customer_id();
        }

        $parentOrderId = method_exists($subscription, 'get_parent_id') ? (int) $subscription->get_parent_id() : 0;
        $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;

        $paymentMethod = method_exists($subscription, 'get_payment_method') ? (string) $subscription->get_payment_method() : '';
        $paymentMethodTitle = method_exists($subscription, 'get_payment_method_title') ? (string) $subscription->get_payment_method_title() : '';
        if ($paymentMethod === '' && $parentOrder) {
            $payment = $this->get_order_payment_method_data($parentOrder);
            $paymentMethod = (string) ($payment['method'] ?? '');
            $paymentMethodTitle = $paymentMethodTitle !== '' ? $paymentMethodTitle : (string) ($payment['title'] ?? '');
        }

        $mollie = $this->extract_mollie_customer_and_mandate_from_wcs_subscription($subscription, $parentOrder);
        $requiresMandate = $this->payment_method_requires_mandate($paymentMethod);
        $status = $this->map_wcs_subscription_status((string) (method_exists($subscription, 'get_status') ? $subscription->get_status() : 'active'));
        if ($status === 'active' && $requiresMandate && ($mollie['customerId'] === '' || $mollie['mandateId'] === '')) {
            $status = 'pending_mandate';
        }

        $nextPaymentTs = $this->get_wcs_subscription_timestamp($subscription, 'next_payment');
        $trialEndTs = $this->get_wcs_subscription_timestamp($subscription, 'trial_end');
        $endDateTs = $this->get_wcs_subscription_timestamp($subscription, 'end');
        $startTs = $this->get_wcs_subscription_timestamp($subscription, 'start');
        if ($startTs <= 0) {
            $startTs = $this->get_wcs_subscription_timestamp($subscription, 'date_created');
        }
        if ($startTs <= 0) {
            $startTs = time();
        }

        if ($existingSubId > 0) {
            return $this->store_migrated_wcs_subscription($existingSubId, $subscription, $items, $scheme, $sourceId, $userId, $parentOrderId, $interval, $period, $status, $nextPaymentTs, $trialEndTs, $endDateTs, $startTs, $paymentMethod, $paymentMethodTitle, $mollie, $requiresMandate, 'refreshed');
        }

        $subPostId = wp_insert_post([
            'post_type' => self::SUB_CPT,
            'post_status' => 'publish',
            'post_title' => sprintf(__('Abonnement (WCS #%d)', 'hb-ucs'), $sourceId),
        ], true);

        if (is_wp_error($subPostId) || (int) $subPostId <= 0) {
            return 'errors';
        }

        return $this->store_migrated_wcs_subscription((int) $subPostId, $subscription, $items, $scheme, $sourceId, $userId, $parentOrderId, $interval, $period, $status, $nextPaymentTs, $trialEndTs, $endDateTs, $startTs, $paymentMethod, $paymentMethodTitle, $mollie, $requiresMandate, 'imported');
    }

    private function store_migrated_wcs_subscription(int $subId, $subscription, array $items, string $scheme, int $sourceId, int $userId, int $parentOrderId, int $interval, string $period, string $status, int $nextPaymentTs, int $trialEndTs, int $endDateTs, int $startTs, string $paymentMethod, string $paymentMethodTitle, array $mollie, bool $requiresMandate, string $successResult): string {
        if ($subId <= 0 || empty($items)) {
            return 'errors';
        }

        $first = $items[0];

        update_post_meta($subId, self::SUB_META_WCS_SOURCE_ID, (string) $sourceId);
        update_post_meta($subId, self::SUB_META_STATUS, $status);
        update_post_meta($subId, self::SUB_META_USER_ID, (string) $userId);
        update_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, (string) $parentOrderId);
        update_post_meta($subId, self::SUB_META_BASE_PRODUCT_ID, (string) ($first['base_product_id'] ?? 0));
        update_post_meta($subId, self::SUB_META_BASE_VARIATION_ID, (string) ($first['base_variation_id'] ?? 0));
        update_post_meta($subId, self::SUB_META_SCHEME, $scheme);
        update_post_meta($subId, self::SUB_META_INTERVAL, (string) $interval);
        update_post_meta($subId, self::SUB_META_PERIOD, $period);
        update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) max(0, $nextPaymentTs));
        update_post_meta($subId, self::SUB_META_UNIT_PRICE, (string) wc_format_decimal((string) ($first['unit_price'] ?? 0.0), wc_get_price_decimals()));
        update_post_meta($subId, self::SUB_META_QTY, (string) ($first['qty'] ?? 1));
        update_post_meta($subId, self::SUB_META_PAYMENT_METHOD, $paymentMethod);
        update_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, $paymentMethodTitle);
        update_post_meta($subId, self::SUB_META_BILLING, $this->get_wcs_subscription_address_snapshot($subscription, 'billing'));
        update_post_meta($subId, self::SUB_META_SHIPPING, $this->get_wcs_subscription_address_snapshot($subscription, 'shipping'));
        if ($trialEndTs > 0) {
            update_post_meta($subId, self::SUB_META_TRIAL_END, (string) $trialEndTs);
        }
        if ($endDateTs > 0) {
            update_post_meta($subId, self::SUB_META_END_DATE, (string) $endDateTs);
        }
        if ($parentOrderId > 0) {
            update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) $parentOrderId);
            update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $startTs);
        }
        if ($requiresMandate && $mollie['customerId'] !== '') {
            update_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $mollie['customerId']);
        }
        if ($requiresMandate && $mollie['mandateId'] !== '') {
            update_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, $mollie['mandateId']);
        }

        $this->persist_subscription_items($subId, $items);

        return $successResult;
    }

    private function get_internal_subscription_id_by_wcs_source(int $sourceId): int {
        if ($sourceId <= 0) {
            return 0;
        }

        $posts = get_posts([
            'post_type' => self::SUB_CPT,
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_key' => self::SUB_META_WCS_SOURCE_ID,
            'meta_value' => (string) $sourceId,
        ]);

        return !empty($posts) ? (int) $posts[0] : 0;
    }

    private function map_wcs_subscription_to_scheme($subscription): string {
        $interval = method_exists($subscription, 'get_billing_interval') ? (int) $subscription->get_billing_interval() : 0;
        $period = method_exists($subscription, 'get_billing_period') ? sanitize_key((string) $subscription->get_billing_period()) : '';
        if ($interval <= 0 || $period === '') {
            return '';
        }

        foreach ($this->get_enabled_frequencies() as $scheme => $row) {
            if ((int) ($row['interval'] ?? 0) === $interval && (string) ($row['period'] ?? '') === $period) {
                return (string) $scheme;
            }
        }

        return '';
    }

    private function map_wcs_subscription_status(string $status): string {
        switch (sanitize_key($status)) {
            case 'active':
                return 'active';
            case 'on-hold':
                return 'on-hold';
            case 'pending':
                return 'payment_pending';
            case 'pending-cancel':
            case 'cancelled':
                return 'cancelled';
            case 'expired':
                return 'expired';
            default:
                return 'on-hold';
        }
    }

    private function get_wcs_subscription_timestamp($subscription, string $dateType): int {
        if (!$subscription || !is_object($subscription)) {
            return 0;
        }

        if (method_exists($subscription, 'get_time')) {
            try {
                return (int) $subscription->get_time($dateType);
            } catch (\Throwable $e) {
            }
        }

        if (method_exists($subscription, 'get_date')) {
            try {
                $value = $subscription->get_date($dateType);
                if ($value instanceof \WC_DateTime) {
                    return (int) $value->getTimestamp();
                }
                if (is_string($value) && $value !== '') {
                    $ts = strtotime($value);
                    return $ts ? (int) $ts : 0;
                }
            } catch (\Throwable $e) {
            }
        }

        return 0;
    }

    private function get_subscription_items_from_wcs_subscription($subscription, string $scheme): array {
        $items = [];
        if (!$subscription || !is_object($subscription) || !method_exists($subscription, 'get_items')) {
            return $items;
        }

        foreach ($subscription->get_items('line_item') as $item) {
            if (!$item || !is_object($item)) {
                continue;
            }

            $baseProductId = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $baseVariationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            if ($baseProductId <= 0) {
                continue;
            }

            $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1;
            if ($qty <= 0) {
                $qty = 1;
            }

            $lineTotal = method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
            $lineTax = method_exists($item, 'get_total_tax') ? (float) $item->get_total_tax() : 0.0;
            $lineGross = $lineTotal + $lineTax;
            $unitPrice = $qty > 0 ? ($lineGross / $qty) : $lineGross;

            $selectedAttributes = [];
            $product = $baseVariationId > 0 ? wc_get_product($baseVariationId) : wc_get_product($baseProductId);
            if ($baseVariationId > 0 && $product && is_object($product)) {
                $selectedAttributes = $this->get_selected_attributes_from_variation($product);
            }

            $normalized = $this->normalize_subscription_item([
                'base_product_id' => $baseProductId,
                'base_variation_id' => $baseVariationId,
                'qty' => $qty,
                'unit_price' => $unitPrice,
                'price_includes_tax' => 1,
                'selected_attributes' => $selectedAttributes,
            ]);

            if ($normalized) {
                $items[] = $normalized;
            }
        }

        return $items;
    }

    private function extract_mollie_customer_and_mandate_from_wcs_subscription($subscription, $parentOrder = null): array {
        $customerId = '';
        $mandateId = '';

        if ($subscription && is_object($subscription) && method_exists($subscription, 'get_meta')) {
            $customerId = (string) $subscription->get_meta('_mollie_customer_id', true);
            $mandateId = (string) $subscription->get_meta('_mollie_mandate_id', true);
        }

        if (($customerId === '' || $mandateId === '') && $parentOrder && is_object($parentOrder) && method_exists($parentOrder, 'get_meta')) {
            if ($customerId === '') {
                $customerId = (string) $parentOrder->get_meta('_mollie_customer_id', true);
            }
            if ($mandateId === '') {
                $mandateId = (string) $parentOrder->get_meta('_mollie_mandate_id', true);
            }
            if ($customerId === '' || $mandateId === '') {
                $paymentId = (string) $parentOrder->get_meta('_mollie_payment_id', true);
                if ($paymentId !== '') {
                    $cm = $this->mollie_get_customer_and_mandate($paymentId);
                    if ($customerId === '' && $cm['customerId'] !== '') {
                        $customerId = $cm['customerId'];
                    }
                    if ($mandateId === '' && $cm['mandateId'] !== '') {
                        $mandateId = $cm['mandateId'];
                    }
                }
            }
        }

        return [
            'customerId' => $customerId,
            'mandateId' => $mandateId,
        ];
    }

    private function get_wcs_subscription_address_snapshot($subscription, string $type): array {
        if (!$subscription || !is_object($subscription) || !method_exists($subscription, 'get_address')) {
            return [];
        }

        $address = (array) $subscription->get_address($type);
        if ($type === 'billing') {
            $address['email'] = method_exists($subscription, 'get_billing_email') ? (string) $subscription->get_billing_email() : '';
            $address['phone'] = method_exists($subscription, 'get_billing_phone') ? (string) $subscription->get_billing_phone() : '';
        }

        return $address;
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
        if (!function_exists('WC') || !WC() || !WC()->cart || !method_exists(WC()->cart, 'get_cart')) {
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

        $payment = $this->get_order_payment_method_data($order);
        if ($this->payment_method_requires_mandate((string) ($payment['method'] ?? ''))) {
            return;
        }

        $this->maybe_create_subscriptions_from_order($orderId);
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
                update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) $orderId);
                $paid = method_exists($order, 'get_date_paid') ? $order->get_date_paid() : null;
                $paidTs = $paid ? (int) $paid->getTimestamp() : time();
                update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $paidTs);
                $this->mark_subscription_paid_and_advance($subId);
            }
        } elseif ($status === 'failed' || $status === 'canceled' || $status === 'expired') {
            if (method_exists($order, 'update_status')) {
                $order->update_status('failed', sprintf(__('HB UCS: Mollie recurring betaling %s is mislukt (%s).', 'hb-ucs'), $paymentId, $status));
            }
            if ($subId > 0) {
                update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) $orderId);
                $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
                $createdTs = $created ? (int) $created->getTimestamp() : time();
                update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $createdTs);
                update_post_meta($subId, self::SUB_META_STATUS, 'on-hold');
            }
        }

        status_header(200);
        echo 'OK';
        exit;
    }

    private function calculate_next_payment_timestamp(int $subId): int {
        $interval = (int) get_post_meta($subId, self::SUB_META_INTERVAL, true);
        $period = (string) get_post_meta($subId, self::SUB_META_PERIOD, true);
        if ($interval <= 0) {
            $interval = 1;
        }
        if ($period === '') {
            $period = 'week';
        }

        $step = $interval * WEEK_IN_SECONDS;
        if ($step <= 0) {
            $step = WEEK_IN_SECONDS;
        }

        $currentNext = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
        $base = $currentNext > 0 ? $currentNext : time();
        $next = $base;

        if ($period === 'week' || $period === 'weeks') {
            $next = $base + $step;
        } else {
            $next = $base + $step;
        }

        $now = time();
        while ($next <= $now) {
            $next += $step;
        }

        return $next;
    }

    private function mark_subscription_paid_and_advance(int $subId): void {
        $next = $this->calculate_next_payment_timestamp($subId);
        update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) $next);
        update_post_meta($subId, self::SUB_META_STATUS, 'active');
    }

    private function get_engine(): string {
        $settings = $this->get_settings();
        $engine = isset($settings['engine']) ? sanitize_key((string) $settings['engine']) : 'manual';
        if ($engine !== 'manual' && $engine !== 'wcs') {
            $engine = 'manual';
        }
        if ($engine === 'wcs' && !$this->wcs_available()) {
            // Fall back silently.
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
        if ($screen->post_type === self::SUB_CPT) {
            if (wp_style_is('woocommerce_admin_styles', 'registered') || wp_style_is('woocommerce_admin_styles', 'enqueued')) {
                wp_enqueue_style('woocommerce_admin_styles');
            }
            if (wp_script_is('wc-enhanced-select', 'registered') || wp_script_is('wc-enhanced-select', 'enqueued')) {
                wp_enqueue_script('wc-enhanced-select');
                wp_add_inline_script('wc-enhanced-select', "jQuery(function($){ try { $(document.body).trigger('wc-enhanced-select-init'); } catch(e) {} });");
            }
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
            $link = admin_url('post.php?post=' . $subId . '&action=edit');
            echo '<a class="' . esc_attr(implode(' ', $classes)) . '" href="' . esc_url($link) . '" title="' . esc_attr($label) . '">' . $icon . '</a>';
            return;
        }

        $classes[] = 'hb-ucs-order-subscription-indicator--unlinked';
        echo '<span class="' . esc_attr(implode(' ', $classes)) . '" title="' . esc_attr($label) . '">' . $icon . '</span>';
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

        $subId = method_exists($order, 'get_meta') ? (int) $order->get_meta(self::ORDER_META_SUBSCRIPTION_ID, true) : 0;
        $isRenewal = method_exists($order, 'get_meta') && (string) $order->get_meta(self::ORDER_META_RENEWAL, true) === '1';
        if ($subId > 0) {
            return [
                'is_subscription_order' => true,
                'subscription_id' => $subId,
                'type' => $isRenewal ? 'renewal' : 'subscription',
            ];
        }

        $parentSubscriptionIds = get_posts([
            'post_type' => self::SUB_CPT,
            'post_status' => 'any',
            'fields' => 'ids',
            'numberposts' => 1,
            'meta_key' => self::SUB_META_PARENT_ORDER_ID,
            'meta_value' => (string) $orderId,
        ]);
        $parentSubId = !empty($parentSubscriptionIds) ? (int) $parentSubscriptionIds[0] : 0;
        if ($parentSubId > 0) {
            return [
                'is_subscription_order' => true,
                'subscription_id' => $parentSubId,
                'type' => 'parent',
            ];
        }

        if ($this->order_contains_subscription($order)) {
            return [
                'is_subscription_order' => true,
                'subscription_id' => 0,
                'type' => 'subscription',
            ];
        }

        return $default;
    }

    public function enqueue_frontend_assets(): void {
        $isProductPage = function_exists('is_singular') && is_singular('product');
        $isAccountPage = function_exists('is_account_page') && is_account_page();
        $isSubscriptionsEndpoint = function_exists('is_wc_endpoint_url') && is_wc_endpoint_url(self::ACCOUNT_ENDPOINT);
        if (!$isSubscriptionsEndpoint && function_exists('get_query_var')) {
            $endpointValue = get_query_var(self::ACCOUNT_ENDPOINT, null);
            $isSubscriptionsEndpoint = $endpointValue !== null;
        }
        $isAccountSubscriptionsPage = $isAccountPage || $isSubscriptionsEndpoint;
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
        $defaultVer = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';
        $assetDir = dirname(__FILE__, 2) . '/assets/';
        $jsVer = file_exists($assetDir . 'frontend-hb-ucs-subscriptions.js') ? (string) filemtime($assetDir . 'frontend-hb-ucs-subscriptions.js') : $defaultVer;
        $cssVer = file_exists($assetDir . 'frontend-hb-ucs-subscriptions.css') ? (string) filemtime($assetDir . 'frontend-hb-ucs-subscriptions.css') : $defaultVer;

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

        wp_enqueue_style('hb-ucs-subscriptions-frontend-style', $base . 'frontend-hb-ucs-subscriptions.css', [], $cssVer);
        wp_enqueue_script('hb-ucs-subscriptions-frontend', $base . 'frontend-hb-ucs-subscriptions.js', $deps, $jsVer, true);
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
            $fixed = (float) wc_format_decimal($fixedRaw);
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

    public function maybe_notice_missing_wcs(): void {
        if ($this->get_engine() !== 'wcs') {
            return;
        }
        if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
            return;
        }
        if (!function_exists('get_current_screen')) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen) return;
        if ($screen->id !== 'product' && $screen->id !== 'edit-product' && strpos((string) $screen->id, 'hb-ucs') === false) {
            return;
        }

        if ($this->wcs_available()) {
            return;
        }

        echo '<div class="notice notice-warning"><p>';
        echo esc_html__('HB UCS Abonnementen: WooCommerce Subscriptions is niet actief. Zet de engine op Handmatig of activeer WooCommerce Subscriptions.', 'hb-ucs');
        echo '</p></div>';
    }

    private function wcs_available(): bool {
        // We depend on WCS providing the subscription product type.
        return (bool) term_exists('subscription', 'product_type');
    }

    private function get_settings(): array {
        $opt = get_option(Settings::OPT_SUBSCRIPTIONS, []);
        if (!is_array($opt)) {
            $opt = [];
        }

        $engine = isset($opt['engine']) ? sanitize_key((string) $opt['engine']) : 'manual';
        if ($engine !== 'manual' && $engine !== 'wcs') {
            $engine = 'manual';
        }

        $recurringEnabled = empty($opt['recurring_enabled']) ? 0 : 1;
        $webhookToken = isset($opt['recurring_webhook_token']) ? (string) $opt['recurring_webhook_token'] : '';
        $webhookToken = trim($webhookToken);

        $freqsRaw = isset($opt['frequencies']) && is_array($opt['frequencies']) ? $opt['frequencies'] : [];
        $freqs = [];
        foreach (['1w' => 1, '2w' => 2, '3w' => 3, '4w' => 4] as $key => $interval) {
            $row = isset($freqsRaw[$key]) && is_array($freqsRaw[$key]) ? $freqsRaw[$key] : [];
            $freqs[$key] = [
                'enabled' => empty($row['enabled']) ? 0 : 1,
                'label' => isset($row['label']) ? (string) $row['label'] : $key,
                'interval' => (int) $interval,
                'period' => 'week',
            ];
        }
        return [
            'engine' => $engine,
            'recurring_enabled' => $recurringEnabled,
            'recurring_webhook_token' => $webhookToken,
            'delete_data_on_uninstall' => empty($opt['delete_data_on_uninstall']) ? 0 : 1,
            'frequencies' => $freqs,
        ];
    }

    public function maybe_create_subscriptions_from_order(int $orderId): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }
        $order = wc_get_order($orderId);
        if (!$order || !is_object($order)) {
            return;
        }

        if ((string) $order->get_meta(self::ORDER_META_RECURRING_CREATED, true) === '1') {
            return;
        }

        $payment = $this->get_order_payment_method_data($order);
        $paymentMethod = (string) ($payment['method'] ?? '');
        $paymentMethodTitle = (string) ($payment['title'] ?? '');
        $requiresMandate = $this->payment_method_requires_mandate($paymentMethod);

        $mCustomer = (string) $order->get_meta('_mollie_customer_id', true);
        $mMandate = (string) $order->get_meta('_mollie_mandate_id', true);
        if ($requiresMandate && ($mCustomer === '' || $mMandate === '')) {
            $molliePaymentId = (string) $order->get_meta('_mollie_payment_id', true);
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

            $freqs = $this->get_enabled_frequencies();
            $interval = isset($freqs[$scheme]['interval']) ? (int) $freqs[$scheme]['interval'] : 1;
            $period = isset($freqs[$scheme]['period']) ? (string) $freqs[$scheme]['period'] : 'week';
            if ($interval <= 0) {
                $interval = 1;
            }

            $paidDate = method_exists($order, 'get_date_paid') ? $order->get_date_paid() : null;
            $startTs = $paidDate ? (int) $paidDate->getTimestamp() : time();
            $nextTs = $startTs + ($interval * WEEK_IN_SECONDS);

            $subPostId = wp_insert_post([
                'post_type' => self::SUB_CPT,
                'post_status' => 'publish',
                'post_title' => sprintf(__('Abonnement #%d', 'hb-ucs'), (int) $orderId),
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
            // Start order is also the initial 'last order'.
            update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) $orderId);
            update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $startTs);
            update_post_meta($subId, self::SUB_META_UNIT_PRICE, (string) wc_format_decimal($unit));
            update_post_meta($subId, self::SUB_META_QTY, (string) $qty);
            $this->persist_subscription_items($subId, [[
                'base_product_id' => $baseProductId,
                'base_variation_id' => $baseVariationId,
                'qty' => $qty,
                'unit_price' => $unit,
            ]]);
            update_post_meta($subId, self::SUB_META_PAYMENT_METHOD, $paymentMethod);
            update_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, $paymentMethodTitle);
            update_post_meta($subId, self::SUB_META_BILLING, $this->get_subscription_address_snapshot($subId, 'billing', (int) $order->get_user_id(), $order));
            update_post_meta($subId, self::SUB_META_SHIPPING, $this->get_subscription_address_snapshot($subId, 'shipping', (int) $order->get_user_id(), $order));
            if ($requiresMandate && $mCustomer !== '') {
                update_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $mCustomer);
            }
            if ($requiresMandate && $mMandate !== '') {
                update_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, $mMandate);
            }

            $createdAny = true;
        }

        if ($createdAny) {
            $order->update_meta_data(self::ORDER_META_RECURRING_CREATED, '1');
            $order->save();
            $order->add_order_note(__('HB UCS: abonnement record(s) aangemaakt voor automatische verlenging.', 'hb-ucs'));
        }
    }

    public function process_due_renewals(): void {
        if (!$this->recurring_enabled() || $this->get_engine() !== 'manual') {
            return;
        }

        $now = time();

        // First: try to activate subscriptions that are waiting for a Mollie mandate.
        $pending = new \WP_Query([
            'post_type' => self::SUB_CPT,
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => self::SUB_META_STATUS,
                    'value' => 'pending_mandate',
                    'compare' => '=',
                ],
            ],
        ]);
        if ($pending->have_posts()) {
            foreach ($pending->posts as $subId) {
                $subId = (int) $subId;
                if ($subId <= 0) continue;
                $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
                $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
                if (!$parentOrder) continue;

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
                    update_post_meta($subId, self::SUB_META_MOLLIE_CUSTOMER_ID, $mCustomer);
                    update_post_meta($subId, self::SUB_META_MOLLIE_MANDATE_ID, $mMandate);
                    update_post_meta($subId, self::SUB_META_STATUS, 'active');
                }
            }
        }

        $q = new \WP_Query([
            'post_type' => self::SUB_CPT,
            'post_status' => 'publish',
            'posts_per_page' => 10,
            'fields' => 'ids',
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
        ]);

        if (!$q->have_posts()) {
            return;
        }

        foreach ($q->posts as $subId) {
            $subId = (int) $subId;
            if ($subId <= 0) {
                continue;
            }

            $result = $this->create_renewal_order_and_payment($subId);
            if (is_wp_error($result)) {
                update_post_meta($subId, self::SUB_META_STATUS, 'on-hold');
            }
        }
    }

    private function create_renewal_order_and_payment(int $subId) {
        $userId = (int) get_post_meta($subId, self::SUB_META_USER_ID, true);
        $parentOrderId = (int) get_post_meta($subId, self::SUB_META_PARENT_ORDER_ID, true);
        $scheme = (string) get_post_meta($subId, self::SUB_META_SCHEME, true);
        $paymentMethod = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD, true);
        $paymentMethodTitle = (string) get_post_meta($subId, self::SUB_META_PAYMENT_METHOD_TITLE, true);
        $items = $this->get_subscription_items($subId);
        if (empty($items)) {
            return new \WP_Error('hb_ucs_missing_items', __('Dit abonnement bevat geen geldige artikelen.', 'hb-ucs'));
        }

        $parentOrder = $parentOrderId > 0 ? wc_get_order($parentOrderId) : null;
        if ($paymentMethod === '' && $parentOrder && is_object($parentOrder)) {
            $payment = $this->get_order_payment_method_data($parentOrder);
            $paymentMethod = (string) ($payment['method'] ?? '');
            if ($paymentMethodTitle === '') {
                $paymentMethodTitle = (string) ($payment['title'] ?? '');
            }
        }

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

        $order = wc_create_order([
            'customer_id' => $userId,
        ]);
        if (!is_object($order)) {
            return new \WP_Error('hb_ucs_order_create_failed', __('Kon geen renewal order aanmaken.', 'hb-ucs'));
        }

        $this->apply_subscription_snapshot_to_order($order, $subId, $userId, $parentOrder);

        foreach ($items as $subscriptionItem) {
            $baseProductId = (int) ($subscriptionItem['base_product_id'] ?? 0);
            $baseVariationId = (int) ($subscriptionItem['base_variation_id'] ?? 0);
            $qty = (int) ($subscriptionItem['qty'] ?? 1);
            $unit = $this->get_subscription_item_storage_unit_price($subscriptionItem);
            if ($qty <= 0) {
                $qty = 1;
            }

            $productToAdd = $baseVariationId > 0 ? wc_get_product($baseVariationId) : wc_get_product($baseProductId);
            if (!$productToAdd) {
                return new \WP_Error('hb_ucs_missing_product', __('Product voor renewal niet gevonden.', 'hb-ucs'));
            }

            $item = new \WC_Order_Item_Product();
            $item->set_product($productToAdd);
            $item->set_quantity($qty);
            $item->set_subtotal($unit * $qty);
            $item->set_total($unit * $qty);
            $item->add_meta_data('_hb_ucs_subscription_base_product_id', $baseProductId, true);
            if ($baseVariationId > 0) {
                $item->add_meta_data('_hb_ucs_subscription_base_variation_id', $baseVariationId, true);
            }
            foreach ((array) ($subscriptionItem['selected_attributes'] ?? []) as $attributeKey => $attributeValue) {
                if ($attributeKey === '' || $attributeValue === '') {
                    continue;
                }
                $item->add_meta_data($attributeKey, $attributeValue, true);
            }
            $item->add_meta_data('_hb_ucs_subscription_scheme', $scheme, true);
            $order->add_item($item);
        }

        // Copy shipping lines from parent order (best-effort).
        if ($parentOrder) {
            foreach ($parentOrder->get_items('shipping') as $shipItem) {
                if (!$shipItem || !is_object($shipItem)) {
                    continue;
                }
                // Woo returns WC_Order_Item_Shipping here.
                if ($shipItem instanceof \WC_Order_Item_Shipping) {
                    $newShip = new \WC_Order_Item_Shipping();
                    $newShip->set_method_title($shipItem->get_method_title());
                    $newShip->set_method_id($shipItem->get_method_id());
                    $newShip->set_instance_id($shipItem->get_instance_id());
                    $newShip->set_total($shipItem->get_total());
                    $newShip->set_taxes($shipItem->get_taxes());
                    $order->add_item($newShip);
                    continue;
                }
                // Fallback for unexpected item classes.
                if (method_exists($shipItem, 'get_method_id') && method_exists($shipItem, 'get_total')) {
                    $newShip = new \WC_Order_Item_Shipping();
                    $newShip->set_method_title(method_exists($shipItem, 'get_method_title') ? $shipItem->get_method_title() : 'Shipping');
                    $newShip->set_method_id($shipItem->get_method_id());
                    if (method_exists($shipItem, 'get_instance_id')) {
                        $newShip->set_instance_id($shipItem->get_instance_id());
                    }
                    $newShip->set_total($shipItem->get_total());
                    if (method_exists($shipItem, 'get_taxes')) {
                        $newShip->set_taxes($shipItem->get_taxes());
                    }
                    $order->add_item($newShip);
                }
            }
        }

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
            $order->calculate_taxes();
        }
        $order->calculate_totals(false);
        if ($requiresMandate) {
            $order->update_status('on-hold', __('HB UCS: renewal aangemaakt, wacht op SEPA incasso.', 'hb-ucs'));
        } else {
            $order->update_status('processing', __('HB UCS: renewal aangemaakt en direct in verwerking gezet voor handmatige/offline betaalmethode.', 'hb-ucs'));
            $order->add_order_note(__('HB UCS: deze renewal gebruikt een handmatige/offline betaalmethode, vereist geen Mollie mandaat en staat direct op verwerken.', 'hb-ucs'));
        }
        $order->save();

        $this->trigger_renewal_customer_email('customer_renewal_invoice', (int) $order->get_id(), $subId);

        if (!$requiresMandate) {
            $nextPayment = $this->calculate_next_payment_timestamp($subId);
            update_post_meta($subId, self::SUB_META_STATUS, 'active');
            update_post_meta($subId, self::SUB_META_NEXT_PAYMENT, (string) $nextPayment);
            update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) (int) $order->get_id());
            $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
            $createdTs = $created ? (int) $created->getTimestamp() : time();
            update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $createdTs);
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

        $payment = $this->mollie_request('POST', 'payments', $payload);
        if (is_wp_error($payment)) {
            return $payment;
        }
        $paymentId = isset($payment['id']) ? (string) $payment['id'] : '';
        if ($paymentId === '') {
            return new \WP_Error('hb_ucs_mollie_no_payment_id', __('Mollie gaf geen payment id terug.', 'hb-ucs'));
        }

        $order->update_meta_data(self::ORDER_META_MOLLIE_PAYMENT_ID, $paymentId);
        $order->add_order_note(sprintf(__('HB UCS: Mollie recurring betaling gestart (%s).', 'hb-ucs'), $paymentId));
        $order->save();

        // Mark as pending so cron won't create duplicates.
        update_post_meta($subId, self::SUB_META_STATUS, 'payment_pending');
        update_post_meta($subId, self::SUB_META_LAST_PAYMENT_ID, $paymentId);

        // Store last order pointers for admin list.
        update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) (int) $order->get_id());
        $created = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
        $createdTs = $created ? (int) $created->getTimestamp() : time();
        update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $createdTs);

        return (int) $order->get_id();
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

        update_post_meta($subId, self::SUB_META_LAST_ORDER_ID, (string) $orderId);
        $paid = method_exists($order, 'get_date_paid') ? $order->get_date_paid() : null;
        $paidTs = $paid ? (int) $paid->getTimestamp() : time();
        update_post_meta($subId, self::SUB_META_LAST_ORDER_DATE, (string) $paidTs);
        $nextPayment = (int) get_post_meta($subId, self::SUB_META_NEXT_PAYMENT, true);
        if ($nextPayment > time()) {
            update_post_meta($subId, self::SUB_META_STATUS, 'active');
        } else {
            $this->mark_subscription_paid_and_advance($subId);
        }

        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(__('HB UCS: handmatige renewal betaald; volgende abonnementsdatum is bijgewerkt.', 'hb-ucs'));
        }
    }

    private function get_enabled_frequencies(): array {
        $settings = $this->get_settings();
        $freqs = (array) ($settings['frequencies'] ?? []);

        $out = [];
        foreach (['1w', '2w', '3w', '4w'] as $key) {
            $row = isset($freqs[$key]) && is_array($freqs[$key]) ? $freqs[$key] : [];
            if (empty($row['enabled'])) {
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
                        update_post_meta($productId, $fixedKey, wc_format_decimal($fixedRaw));
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

        // Keep generated child products in sync only for WCS engine (best-effort).
        if ($this->get_engine() === 'wcs') {
            $this->sync_child_products($productId);
        }
    }

    private function sync_child_products(int $baseProductId): void {
        if ($this->get_engine() !== 'wcs' || !$this->wcs_available()) {
            return;
        }
        $base = wc_get_product($baseProductId);
        if (!$base || (!$base->is_type('simple') && !$base->is_type('variable'))) {
            return;
        }

        $enabled = get_post_meta($baseProductId, self::META_ENABLED, true) === 'yes';
        if (!$enabled) {
            return;
        }

        $freqs = $this->get_enabled_frequencies();
        if (empty($freqs)) {
            return;
        }

        if ($base->is_type('simple')) {
            foreach ($freqs as $scheme => $row) {
                $childId = $this->get_or_create_child_product_id($baseProductId, $scheme);
                if ($childId <= 0) {
                    continue;
                }
                $this->update_child_product_from_base($childId, $baseProductId, $scheme);
            }
            return;
        }

        // Variable products: only sync child products that already exist (lazy creation on add-to-cart).
        if (method_exists($base, 'get_children')) {
            $children = (array) $base->get_children();
            foreach ($children as $variationId) {
                $variationId = (int) $variationId;
                if ($variationId <= 0) continue;

                foreach ($freqs as $scheme => $row) {
                    $stored = (int) get_post_meta($variationId, self::META_CHILD_PREFIX . $scheme, true);
                    if ($stored <= 0) {
                        continue;
                    }
                    if (get_post_type($stored) !== 'product') {
                        continue;
                    }
                    $this->update_child_product_from_variation($stored, $baseProductId, $variationId, $scheme);
                }
            }
        }
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
                $baseRaw = (string) $product->get_price();
                $base = $baseRaw === '' ? 0.0 : (float) wc_format_decimal($baseRaw);
                $pricing = $this->get_subscription_pricing($productId, $base, (string) $scheme);
                $priceHtml = $this->format_subscription_price_html((float) $pricing['base'], (float) $pricing['final'], (string) ($pricing['badge'] ?? ''));
                echo ' — <span class="price">' . wp_kses_post($priceHtml) . '</span>';
            } else {
                echo ' — <span class="price hb-ucs-subs-price" data-scheme="' . esc_attr((string) $scheme) . '"></span>';
            }
            echo '</label>';
        }

        if ($product->is_type('variable')) {
            echo '<p class="description hb-ucs-subscriptions__description">' . esc_html__('Kies eerst een variatie; de abonnementsprijs kan per variatie verschillen.', 'hb-ucs') . '</p>';
        }

        if ($this->get_engine() === 'wcs' && !$this->wcs_available()) {
            echo '<p class="description hb-ucs-subscriptions__description">' . esc_html__('Let op: WooCommerce Subscriptions is niet actief; zet de engine op Handmatig of activeer WooCommerce Subscriptions.', 'hb-ucs') . '</p>';
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

        if ($this->get_engine() === 'wcs' && !$this->wcs_available()) {
            wc_add_notice(__('Abonnementen vereisen WooCommerce Subscriptions (engine = WCS). Neem contact op met de beheerder.', 'hb-ucs'), 'error');
            return false;
        }

        return true;
    }

    public function maybe_swap_product_id(int $productId): int {
        if ($this->get_engine() !== 'wcs') {
            self::$pendingAddToCart = null;
            return $productId;
        }
        $scheme = $this->get_requested_scheme();
        if ($scheme === '' || $scheme === '0') {
            self::$pendingAddToCart = null;
            return $productId;
        }

        if (get_post_meta($productId, self::META_ENABLED, true) !== 'yes') {
            self::$pendingAddToCart = null;
            return $productId;
        }

        $freqs = $this->get_enabled_frequencies();
        if (!isset($freqs[$scheme])) {
            self::$pendingAddToCart = null;
            return $productId;
        }

        $prod = wc_get_product($productId);
        if ($prod && $prod->is_type('variable')) {
            $variationId = isset($_REQUEST['variation_id']) ? (int) $_REQUEST['variation_id'] : 0;
            if ($variationId <= 0) {
                self::$pendingAddToCart = null;
                return $productId;
            }

            $childId = $this->get_or_create_child_product_id_for_variation($productId, $variationId, $scheme);
            if ($childId <= 0) {
                self::$pendingAddToCart = null;
                return $productId;
            }

            self::$pendingAddToCart = [
                'base_product_id' => (int) $productId,
                'base_variation_id' => (int) $variationId,
                'child_product_id' => (int) $childId,
                'scheme' => (string) $scheme,
            ];

            return (int) $childId;
        }

        $childId = $this->get_or_create_child_product_id($productId, $scheme);
        if ($childId <= 0) {
            self::$pendingAddToCart = null;
            return $productId;
        }

        self::$pendingAddToCart = [
            'base_product_id' => (int) $productId,
            'base_variation_id' => 0,
            'child_product_id' => (int) $childId,
            'scheme' => (string) $scheme,
        ];

        return (int) $childId;
    }

    public function maybe_swap_variation_id(int $variationId): int {
        if ($this->get_engine() !== 'wcs') {
            return $variationId;
        }
        $scheme = $this->get_requested_scheme();
        if ($scheme === '' || $scheme === '0') {
            return $variationId;
        }

        // Only override when we actually swapped the product id.
        if (!is_array(self::$pendingAddToCart)) {
            return $variationId;
        }

        // We swap variable product -> simple subscription child product.
        return 0;
    }

    public function add_cart_item_data(array $cartItemData, int $productId, int $variationId): array {
        // WCS engine flow (child product swap).
        if ($this->get_engine() === 'wcs') {
            if (!is_array(self::$pendingAddToCart)) {
                return $cartItemData;
            }

        $baseId = (int) (self::$pendingAddToCart['base_product_id'] ?? 0);
        $baseVariationId = (int) (self::$pendingAddToCart['base_variation_id'] ?? 0);
            $childId = (int) (self::$pendingAddToCart['child_product_id'] ?? 0);
            $scheme = (string) (self::$pendingAddToCart['scheme'] ?? '');

            if ($baseId <= 0 || $scheme === '' || $childId <= 0) {
                return $cartItemData;
            }

        // Attach meta to this add-to-cart call (WooCommerce passes filtered product_id in most flows).
            if ($productId !== $childId && $productId !== $baseId) {
                return $cartItemData;
            }

        // Ensure unique cart item so different schemes don't merge.
            $cartItemData['hb_ucs_subs_key'] = $baseId . ':' . $scheme . ':' . wp_generate_uuid4();

            $cartItemData[self::CART_KEY] = [
                'base_product_id' => $baseId,
                'base_variation_id' => $baseVariationId,
                'scheme' => $scheme,
            ];

        // Reset pending state.
            self::$pendingAddToCart = null;

            return $cartItemData;
        }

        // Manual engine flow: store scheme + precomputed price, do NOT swap product.
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

        $baseRaw = (string) $p->get_price();
        $base = $baseRaw === '' ? 0.0 : (float) wc_format_decimal($baseRaw);
        $pricing = $this->get_subscription_pricing($entityId, $base, (string) $scheme, $fallbackId);
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

            $final = isset($data['final_price']) ? (float) $data['final_price'] : null;
            if ($final === null) {
                continue;
            }

            if (!isset($cartItem['data']) || !is_object($cartItem['data']) || !method_exists($cartItem['data'], 'set_price')) {
                continue;
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

        $item->add_meta_data('_hb_ucs_subscription_base_product_id', $baseId, true);
        if ($baseVariationId > 0) {
            $item->add_meta_data('_hb_ucs_subscription_base_variation_id', $baseVariationId, true);
        }
        $item->add_meta_data('_hb_ucs_subscription_scheme', $scheme, true);
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

        $priceRaw = (string) $base->get_price();
        if ($priceRaw === '') {
            return null;
        }
        $basePrice = (float) wc_format_decimal($priceRaw);
        $pricing = $this->get_subscription_pricing($baseProductId, $basePrice, $scheme);
        return (float) ($pricing['final'] ?? $basePrice);
    }

    private function get_variation_subscription_price(int $variationId, string $scheme): ?float {
        $variation = wc_get_product($variationId);
        if (!$variation) {
            return null;
        }

        $priceRaw = (string) $variation->get_price();
        if ($priceRaw === '') {
            return null;
        }
        $basePrice = (float) wc_format_decimal($priceRaw);
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

        $base = isset($variationData['display_price']) ? (float) $variationData['display_price'] : null;
        if ($base === null) {
            $raw = (string) $variation->get_price();
            $base = $raw === '' ? 0.0 : (float) wc_format_decimal($raw);
        }

        $out = [];
        foreach ($freqs as $scheme => $row) {
            $pricing = $this->get_subscription_pricing($variationId, (float) $base, (string) $scheme, $parentId);
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
                update_post_meta($variationId, $fixedKey, wc_format_decimal($fixedRaw));
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

        // Keep existing child products in sync.
        $parentId = (int) wp_get_post_parent_id($variationId);
        if ($parentId > 0) {
            $this->sync_child_products($parentId);
        }
    }

    private function get_or_create_child_product_id(int $baseProductId, string $scheme): int {
        $scheme = sanitize_key($scheme);
        if (!in_array($scheme, ['1w', '2w', '3w', '4w'], true)) {
            return 0;
        }

        $stored = (int) get_post_meta($baseProductId, self::META_CHILD_PREFIX . $scheme, true);
        if ($stored > 0 && get_post_type($stored) === 'product') {
            return $stored;
        }

        // Try to find an existing generated child.
        $existing = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'private', 'draft'],
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => self::META_CHILD_BASE_PRODUCT_ID,
                    'value' => $baseProductId,
                    'compare' => '=',
                ],
                [
                    'key' => self::META_CHILD_SCHEME,
                    'value' => $scheme,
                    'compare' => '=',
                ],
                [
                    'key' => self::META_CHILD_GENERATED,
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);
        if (!empty($existing) && is_array($existing)) {
            $childId = (int) $existing[0];
            if ($childId > 0) {
                update_post_meta($baseProductId, self::META_CHILD_PREFIX . $scheme, $childId);
                return $childId;
            }
        }

        // Create.
        $childId = $this->create_child_product($baseProductId, $scheme);
        if ($childId > 0) {
            update_post_meta($baseProductId, self::META_CHILD_PREFIX . $scheme, $childId);
        }
        return $childId;
    }

    private function get_or_create_child_product_id_for_variation(int $parentProductId, int $variationId, string $scheme): int {
        $scheme = sanitize_key($scheme);
        if (!in_array($scheme, ['1w', '2w', '3w', '4w'], true)) {
            return 0;
        }

        $variationId = (int) $variationId;
        if ($variationId <= 0 || get_post_type($variationId) !== 'product_variation') {
            return 0;
        }

        $stored = (int) get_post_meta($variationId, self::META_CHILD_PREFIX . $scheme, true);
        if ($stored > 0 && get_post_type($stored) === 'product') {
            return $stored;
        }

        // Try to find an existing generated child.
        $existing = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'private', 'draft'],
            'numberposts' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => self::META_CHILD_BASE_PRODUCT_ID,
                    'value' => $parentProductId,
                    'compare' => '=',
                ],
                [
                    'key' => '_hb_ucs_subs_base_variation_id',
                    'value' => $variationId,
                    'compare' => '=',
                ],
                [
                    'key' => self::META_CHILD_SCHEME,
                    'value' => $scheme,
                    'compare' => '=',
                ],
                [
                    'key' => self::META_CHILD_GENERATED,
                    'value' => '1',
                    'compare' => '=',
                ],
            ],
        ]);
        if (!empty($existing) && is_array($existing)) {
            $childId = (int) $existing[0];
            if ($childId > 0) {
                update_post_meta($variationId, self::META_CHILD_PREFIX . $scheme, $childId);
                return $childId;
            }
        }

        $childId = $this->create_child_product_from_variation($parentProductId, $variationId, $scheme);
        if ($childId > 0) {
            update_post_meta($variationId, self::META_CHILD_PREFIX . $scheme, $childId);
        }
        return $childId;
    }

    private function create_child_product_from_variation(int $parentProductId, int $variationId, string $scheme): int {
        if (!$this->wcs_available()) {
            return 0;
        }

        $parent = wc_get_product($parentProductId);
        $variation = wc_get_product($variationId);
        if (!$parent || !$variation) {
            return 0;
        }
        if (!$parent->is_type('variable')) {
            return 0;
        }

        $freqs = $this->get_enabled_frequencies();
        if (!isset($freqs[$scheme])) {
            return 0;
        }

        $label = (string) $freqs[$scheme]['label'];
        $name = method_exists($variation, 'get_name') ? (string) $variation->get_name() : (string) $parent->get_name();

        $postId = wp_insert_post([
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_title' => $name . ' — ' . $label,
            'post_content' => '',
            'post_excerpt' => '',
        ], true);

        if (is_wp_error($postId)) {
            return 0;
        }

        $childId = (int) $postId;
        wp_set_object_terms($childId, 'subscription', 'product_type');
        wp_set_object_terms($childId, ['exclude-from-catalog', 'exclude-from-search'], 'product_visibility', false);

        update_post_meta($childId, self::META_CHILD_GENERATED, '1');
        update_post_meta($childId, self::META_CHILD_BASE_PRODUCT_ID, (string) $parentProductId);
        update_post_meta($childId, '_hb_ucs_subs_base_variation_id', (string) $variationId);
        update_post_meta($childId, self::META_CHILD_SCHEME, (string) $scheme);

        $this->update_child_product_from_variation($childId, $parentProductId, $variationId, $scheme);

        return $childId;
    }

    private function update_child_product_from_variation(int $childId, int $parentProductId, int $variationId, string $scheme): void {
        $parent = wc_get_product($parentProductId);
        $variation = wc_get_product($variationId);
        if (!$parent || !$variation) {
            return;
        }

        $freqs = $this->get_enabled_frequencies();
        if (!isset($freqs[$scheme])) {
            return;
        }

        $interval = (int) $freqs[$scheme]['interval'];
        $period = (string) $freqs[$scheme]['period'];
        $label = (string) $freqs[$scheme]['label'];

        $price = $this->get_variation_subscription_price($variationId, $scheme);
        if ($price === null) {
            $price = 0.0;
        }

        update_post_meta($childId, '_regular_price', wc_format_decimal($price));
        update_post_meta($childId, '_price', wc_format_decimal($price));
        delete_post_meta($childId, '_sale_price');

        update_post_meta($childId, '_subscription_period', $period);
        update_post_meta($childId, '_subscription_period_interval', (string) $interval);
        update_post_meta($childId, '_subscription_length', '0');

        // Copy shipping/tax related props from variation (falls back to parent where applicable).
        update_post_meta($childId, '_weight', (string) $variation->get_weight());
        update_post_meta($childId, '_length', (string) $variation->get_length());
        update_post_meta($childId, '_width', (string) $variation->get_width());
        update_post_meta($childId, '_height', (string) $variation->get_height());
        update_post_meta($childId, '_tax_class', (string) $variation->get_tax_class());

        $shippingClassId = (int) $variation->get_shipping_class_id();
        if ($shippingClassId <= 0) {
            $shippingClassId = (int) $parent->get_shipping_class_id();
        }
        if ($shippingClassId > 0) {
            $term = get_term($shippingClassId, 'product_shipping_class');
            if ($term && !is_wp_error($term)) {
                wp_set_object_terms($childId, [(string) $term->slug], 'product_shipping_class', false);
            }
        }

        update_post_meta($childId, '_manage_stock', 'no');
        update_post_meta($childId, '_stock_status', 'instock');

        $imgId = (int) (method_exists($variation, 'get_image_id') ? $variation->get_image_id() : 0);
        if ($imgId <= 0) {
            $imgId = (int) get_post_thumbnail_id($parentProductId);
        }
        if ($imgId > 0) {
            set_post_thumbnail($childId, $imgId);
        }

        $name = method_exists($variation, 'get_name') ? (string) $variation->get_name() : (string) $parent->get_name();
        wp_update_post([
            'ID' => $childId,
            'post_title' => $name . ' — ' . $label,
        ]);
    }

    private function create_child_product(int $baseProductId, string $scheme): int {
        if (!$this->wcs_available()) {
            return 0;
        }

        $base = wc_get_product($baseProductId);
        if (!$base || !$base->is_type('simple')) {
            return 0;
        }

        $freqs = $this->get_enabled_frequencies();
        if (!isset($freqs[$scheme])) {
            return 0;
        }

        $row = $freqs[$scheme];
        $label = (string) $row['label'];

        $postId = wp_insert_post([
            'post_type' => 'product',
            'post_status' => 'publish',
            'post_title' => $base->get_name() . ' — ' . $label,
            'post_content' => '',
            'post_excerpt' => '',
        ], true);

        if (is_wp_error($postId)) {
            return 0;
        }

        $childId = (int) $postId;

        // Mark product type.
        wp_set_object_terms($childId, 'subscription', 'product_type');

        // Hide from catalog/search.
        wp_set_object_terms($childId, ['exclude-from-catalog', 'exclude-from-search'], 'product_visibility', false);

        // Core child meta.
        update_post_meta($childId, self::META_CHILD_GENERATED, '1');
        update_post_meta($childId, self::META_CHILD_BASE_PRODUCT_ID, (string) $baseProductId);
        update_post_meta($childId, self::META_CHILD_SCHEME, (string) $scheme);

        $this->update_child_product_from_base($childId, $baseProductId, $scheme);

        return $childId;
    }

    private function update_child_product_from_base(int $childId, int $baseProductId, string $scheme): void {
        $base = wc_get_product($baseProductId);
        if (!$base) {
            return;
        }

        $freqs = $this->get_enabled_frequencies();
        if (!isset($freqs[$scheme])) {
            return;
        }

        $row = $freqs[$scheme];
        $interval = (int) $row['interval'];
        $period = (string) $row['period'];

        $price = $this->get_base_subscription_price($baseProductId, $scheme);
        if ($price === null) {
            $price = 0.0;
        }

        // Pricing.
        update_post_meta($childId, '_regular_price', wc_format_decimal($price));
        update_post_meta($childId, '_price', wc_format_decimal($price));
        delete_post_meta($childId, '_sale_price');

        // Subscriptions meta (WCS reads these).
        update_post_meta($childId, '_subscription_period', $period);
        update_post_meta($childId, '_subscription_period_interval', (string) $interval);
        // 0 length = indefinite.
        update_post_meta($childId, '_subscription_length', '0');

        // Copy shipping/tax related props (best-effort).
        update_post_meta($childId, '_weight', (string) $base->get_weight());
        update_post_meta($childId, '_length', (string) $base->get_length());
        update_post_meta($childId, '_width', (string) $base->get_width());
        update_post_meta($childId, '_height', (string) $base->get_height());
        update_post_meta($childId, '_tax_class', (string) $base->get_tax_class());

        // Copy shipping class.
        $shippingClassId = (int) $base->get_shipping_class_id();
        if ($shippingClassId > 0) {
            $term = get_term($shippingClassId, 'product_shipping_class');
            if ($term && !is_wp_error($term)) {
                wp_set_object_terms($childId, [(string) $term->slug], 'product_shipping_class', false);
            }
        }

        // Make sure child does not manage its own stock.
        update_post_meta($childId, '_manage_stock', 'no');
        update_post_meta($childId, '_stock_status', 'instock');

        // Copy featured image.
        $thumbId = (int) get_post_thumbnail_id($baseProductId);
        if ($thumbId > 0) {
            set_post_thumbnail($childId, $thumbId);
        }

        // Keep title in sync.
        $label = (string) ($freqs[$scheme]['label'] ?? $scheme);
        wp_update_post([
            'ID' => $childId,
            'post_title' => $base->get_name() . ' — ' . $label,
        ]);
    }
}
