<?php

namespace HB\UCS\Modules\Subscriptions\Domain;

use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;
use HB\UCS\Modules\Subscriptions\Support\SubscriptionSyncLogger;

if (!defined('ABSPATH')) exit;

class SubscriptionRepository {
    public const LEGACY_POST_TYPE = 'hb_ucs_subscription';
    public const LEGACY_STATUS_META = '_hb_ucs_sub_status';
    public const LEGACY_USER_ID_META = '_hb_ucs_sub_user_id';
    public const LEGACY_PARENT_ORDER_ID_META = '_hb_ucs_sub_parent_order_id';
    public const LEGACY_SCHEME_META = '_hb_ucs_sub_scheme';
    public const LEGACY_INTERVAL_META = '_hb_ucs_sub_interval';
    public const LEGACY_PERIOD_META = '_hb_ucs_sub_period';
    public const LEGACY_NEXT_PAYMENT_META = '_hb_ucs_sub_next_payment';
    public const LEGACY_PAYMENT_METHOD_META = '_hb_ucs_sub_payment_method';
    public const LEGACY_PAYMENT_METHOD_TITLE_META = '_hb_ucs_sub_payment_method_title';
    public const LEGACY_BILLING_META = '_hb_ucs_sub_billing';
    public const LEGACY_SHIPPING_META = '_hb_ucs_sub_shipping';
    public const LEGACY_ITEMS_META = '_hb_ucs_sub_items';
    public const LEGACY_FEE_LINES_META = '_hb_ucs_sub_fee_lines';
    public const LEGACY_SHIPPING_LINES_META = '_hb_ucs_sub_shipping_lines';
    public const LEGACY_LAST_ORDER_ID_META = '_hb_ucs_sub_last_order_id';
    public const LEGACY_LAST_ORDER_DATE_META = '_hb_ucs_sub_last_order_date';
    public const LEGACY_TRIAL_END_META = '_hb_ucs_sub_trial_end';
    public const LEGACY_END_DATE_META = '_hb_ucs_sub_end_date';
    public const LEGACY_MOLLIE_CUSTOMER_ID_META = '_hb_ucs_sub_mollie_customer_id';
    public const LEGACY_MOLLIE_MANDATE_ID_META = '_hb_ucs_sub_mollie_mandate_id';
    public const LEGACY_LAST_PAYMENT_ID_META = '_hb_ucs_sub_last_payment_id';
    private const ORDER_ITEM_META_CATALOG_UNIT_PRICE = '_hb_ucs_subscription_catalog_unit_price';

    /** @var bool */
    private static $creatingLegacyFromOrder = false;

    /** @var bool */
    private static $resolvingOrderTypeData = false;

    public static function is_creating_legacy_from_order(): bool {
        return self::$creatingLegacyFromOrder;
    }

    public function get_legacy_subscription_data(int $legacyPostId): array {
        if ($legacyPostId <= 0) {
            return [];
        }

        $post = get_post($legacyPostId);
        if (!$post instanceof \WP_Post || (string) $post->post_type !== self::LEGACY_POST_TYPE) {
            return [];
        }

        $billing = get_post_meta($legacyPostId, self::LEGACY_BILLING_META, true);
        $shipping = get_post_meta($legacyPostId, self::LEGACY_SHIPPING_META, true);
        $items = get_post_meta($legacyPostId, self::LEGACY_ITEMS_META, true);
        $feeLines = get_post_meta($legacyPostId, self::LEGACY_FEE_LINES_META, true);
        $shippingLines = get_post_meta($legacyPostId, self::LEGACY_SHIPPING_LINES_META, true);
        $parentOrderId = (int) get_post_meta($legacyPostId, self::LEGACY_PARENT_ORDER_ID_META, true);
        $parentOrderCurrency = $this->get_parent_order_currency($parentOrderId);
        $items = $this->hydrate_legacy_items_from_parent_order(is_array($items) ? $items : [], $parentOrderId);
        $items = $this->hydrate_legacy_items_with_computed_taxes($items, is_array($billing) ? $billing : [], is_array($shipping) ? $shipping : []);
        $pricesIncludeTax = false;

        if (is_array($items)) {
            foreach ($items as $item) {
                if (!empty($item['price_includes_tax'])) {
                    $pricesIncludeTax = true;
                    break;
                }
            }
        }

        return [
            'id' => $legacyPostId,
            'post' => $post,
            'date_created_gmt' => (string) $post->post_date_gmt,
            'date_modified_gmt' => (string) $post->post_modified_gmt,
            'customer_note' => (string) $post->post_excerpt,
            'customer_id' => (int) get_post_meta($legacyPostId, self::LEGACY_USER_ID_META, true),
            'status' => (string) get_post_meta($legacyPostId, self::LEGACY_STATUS_META, true),
            'parent_order_id' => $parentOrderId,
            'scheme' => (string) get_post_meta($legacyPostId, self::LEGACY_SCHEME_META, true),
            'interval' => (int) get_post_meta($legacyPostId, self::LEGACY_INTERVAL_META, true),
            'period' => (string) get_post_meta($legacyPostId, self::LEGACY_PERIOD_META, true),
            'next_payment' => (int) get_post_meta($legacyPostId, self::LEGACY_NEXT_PAYMENT_META, true),
            'payment_method' => (string) get_post_meta($legacyPostId, self::LEGACY_PAYMENT_METHOD_META, true),
            'payment_method_title' => (string) get_post_meta($legacyPostId, self::LEGACY_PAYMENT_METHOD_TITLE_META, true),
            'billing' => is_array($billing) ? $billing : [],
            'shipping' => is_array($shipping) ? $shipping : [],
            'items' => is_array($items) ? $items : [],
            'fee_lines' => is_array($feeLines) ? $feeLines : [],
            'shipping_lines' => is_array($shippingLines) ? $shippingLines : [],
            'trial_end' => (int) get_post_meta($legacyPostId, self::LEGACY_TRIAL_END_META, true),
            'end_date' => (int) get_post_meta($legacyPostId, self::LEGACY_END_DATE_META, true),
            'last_order_id' => (int) get_post_meta($legacyPostId, self::LEGACY_LAST_ORDER_ID_META, true),
            'last_order_date' => (int) get_post_meta($legacyPostId, self::LEGACY_LAST_ORDER_DATE_META, true),
            'mollie_customer_id' => (string) get_post_meta($legacyPostId, self::LEGACY_MOLLIE_CUSTOMER_ID_META, true),
            'mollie_mandate_id' => (string) get_post_meta($legacyPostId, self::LEGACY_MOLLIE_MANDATE_ID_META, true),
            'last_payment_id' => (string) get_post_meta($legacyPostId, self::LEGACY_LAST_PAYMENT_ID_META, true),
            'currency' => $parentOrderCurrency !== ''
                ? $parentOrderCurrency
                : get_woocommerce_currency(),
            'prices_include_tax' => $pricesIncludeTax,
            'totals' => $this->calculate_legacy_totals(is_array($items) ? $items : [], is_array($feeLines) ? $feeLines : [], is_array($shippingLines) ? $shippingLines : []),
        ];
    }

    private function hydrate_legacy_items_from_parent_order(array $items, int $parentOrderId): array {
        if ($parentOrderId <= 0 || empty($items) || !function_exists('wc_get_order')) {
            return $items;
        }

        $parentOrder = wc_get_order($parentOrderId);
        if (!$parentOrder || !is_object($parentOrder) || !method_exists($parentOrder, 'get_items')) {
            return $items;
        }

        $orderItems = array_values((array) $parentOrder->get_items('line_item'));

        foreach ($items as $index => $item) {
            if (!is_array($item) || !empty(($item['taxes']['total'] ?? []))) {
                continue;
            }

            $sourceItem = $orderItems[$index] ?? null;
            if (!$sourceItem || !is_object($sourceItem)) {
                foreach ($orderItems as $candidate) {
                    if (!$candidate || !is_object($candidate)) {
                        continue;
                    }

                    $candidateProductId = method_exists($candidate, 'get_product_id') ? (int) $candidate->get_product_id() : 0;
                    $candidateVariationId = method_exists($candidate, 'get_variation_id') ? (int) $candidate->get_variation_id() : 0;
                    if ($candidateProductId === (int) ($item['base_product_id'] ?? 0) && $candidateVariationId === (int) ($item['base_variation_id'] ?? 0)) {
                        $sourceItem = $candidate;
                        break;
                    }
                }
            }

            if (!$sourceItem || !is_object($sourceItem) || !method_exists($sourceItem, 'get_taxes')) {
                continue;
            }

            $item['taxes'] = $this->normalize_item_taxes((array) $sourceItem->get_taxes());
            $items[$index] = $item;
        }

        return $items;
    }

    private function hydrate_legacy_items_with_computed_taxes(array $items, array $billing, array $shipping): array {
        if (empty($items) || !function_exists('wc_tax_enabled') || !wc_tax_enabled() || !class_exists('WC_Tax')) {
            return $items;
        }

        $customer = $this->build_tax_customer($billing, $shipping);

        foreach ($items as $index => $item) {
            if (!is_array($item) || !empty(($item['taxes']['total'] ?? []))) {
                continue;
            }

            $taxes = $this->calculate_legacy_item_taxes($item, $customer);
            if (empty($taxes['total'])) {
                continue;
            }

            $item['taxes'] = $taxes;
            $items[$index] = $item;
        }

        return $items;
    }

    private function get_parent_order_currency(int $parentOrderId): string {
        if ($parentOrderId <= 0) {
            return '';
        }

        $storedCurrency = (string) get_post_meta($parentOrderId, '_order_currency', true);
        if ($storedCurrency !== '') {
            return $storedCurrency;
        }

        if (!function_exists('wc_get_order')) {
            return '';
        }

        try {
            $parentOrder = wc_get_order($parentOrderId);
            if ($parentOrder && is_object($parentOrder) && method_exists($parentOrder, 'get_currency')) {
                return (string) $parentOrder->get_currency();
            }
        } catch (\Throwable $e) {
            return '';
        }

        return '';
    }

    public function get_legacy_post_type(): string {
        return self::LEGACY_POST_TYPE;
    }

    public function get_order_type(): string {
        return SubscriptionOrderType::TYPE;
    }

    public function get_linked_legacy_post_id($order): int {
        if (is_numeric($order)) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order) : null;
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== $this->get_order_type()) {
            return 0;
        }

        $legacyPostId = method_exists($order, 'get_meta') ? (int) $order->get_meta(SubscriptionOrderType::LEGACY_POST_ID_META, true) : 0;
        if ($legacyPostId <= 0) {
            return 0;
        }

        return get_post_type($legacyPostId) === self::LEGACY_POST_TYPE ? $legacyPostId : 0;
    }

    public function ensure_legacy_record_for_order($order): int {
        if (is_numeric($order)) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order) : null;
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== $this->get_order_type()) {
            return 0;
        }

        $legacyPostId = $this->get_linked_legacy_post_id($order);
        if ($legacyPostId > 0 && get_post_type($legacyPostId) === self::LEGACY_POST_TYPE) {
            return $legacyPostId;
        }

        $dateCreated = method_exists($order, 'get_date_created') ? $order->get_date_created() : null;
        $dateString = $dateCreated && is_object($dateCreated) && method_exists($dateCreated, 'date') ? $dateCreated->date('Y-m-d H:i:s') : current_time('mysql');
        $dateGmtString = $dateCreated && is_object($dateCreated) && method_exists($dateCreated, 'date') ? $dateCreated->date('Y-m-d H:i:s', new \DateTimeZone('UTC')) : current_time('mysql', 1);

        self::$creatingLegacyFromOrder = true;

        try {
            $legacyPostId = wp_insert_post([
                'post_type' => self::LEGACY_POST_TYPE,
                'post_status' => 'publish',
                'post_title' => $this->build_legacy_post_title_from_order($order),
                'post_excerpt' => method_exists($order, 'get_customer_note') ? (string) $order->get_customer_note() : '',
                'post_date' => $dateString,
                'post_date_gmt' => $dateGmtString,
            ], true);
        } finally {
            self::$creatingLegacyFromOrder = false;
        }

        if (is_wp_error($legacyPostId) || $legacyPostId <= 0) {
            return 0;
        }

        $legacyPostId = (int) $legacyPostId;
        update_post_meta($order->get_id(), SubscriptionOrderType::LEGACY_POST_ID_META, $legacyPostId);
        update_post_meta($order->get_id(), SubscriptionOrderType::STORAGE_VERSION_META, SubscriptionOrderType::PHASE2_STORAGE_VERSION);

        return $legacyPostId;
    }

    public function sync_legacy_from_order($order, bool $createIfMissing = false): ?array {
        if (is_numeric($order)) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order) : null;
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== $this->get_order_type()) {
            return null;
        }

        $legacyPostId = $this->get_linked_legacy_post_id($order);
        if ($legacyPostId <= 0 && $createIfMissing) {
            $legacyPostId = $this->ensure_legacy_record_for_order($order);
        }

        if ($legacyPostId <= 0) {
            $this->log_sync_debug('repository.sync_legacy_from_order.skipped_missing_legacy', [
                'order' => $this->build_order_debug_snapshot($order),
                'create_if_missing' => $createIfMissing,
            ]);
            return null;
        }

        $this->log_sync_debug('repository.sync_legacy_from_order.start', [
            'order' => $this->build_order_debug_snapshot($order),
            'legacy_before' => $this->build_legacy_debug_snapshot($legacyPostId),
        ]);

        $legacyData = $this->build_legacy_data_from_order($order, $legacyPostId);

        wp_update_post([
            'ID' => $legacyPostId,
            'post_title' => $this->build_legacy_post_title_from_order($order),
            'post_excerpt' => (string) ($legacyData['customer_note'] ?? ''),
        ]);

        $metaMap = [
            self::LEGACY_STATUS_META => (string) ($legacyData['status'] ?? ''),
            self::LEGACY_USER_ID_META => (int) ($legacyData['customer_id'] ?? 0),
            self::LEGACY_PARENT_ORDER_ID_META => (int) ($legacyData['parent_order_id'] ?? 0),
            self::LEGACY_SCHEME_META => (string) ($legacyData['scheme'] ?? ''),
            self::LEGACY_INTERVAL_META => (int) ($legacyData['interval'] ?? 0),
            self::LEGACY_PERIOD_META => (string) ($legacyData['period'] ?? ''),
            self::LEGACY_NEXT_PAYMENT_META => (int) ($legacyData['next_payment'] ?? 0),
            self::LEGACY_PAYMENT_METHOD_META => (string) ($legacyData['payment_method'] ?? ''),
            self::LEGACY_PAYMENT_METHOD_TITLE_META => (string) ($legacyData['payment_method_title'] ?? ''),
            self::LEGACY_BILLING_META => (array) ($legacyData['billing'] ?? []),
            self::LEGACY_SHIPPING_META => (array) ($legacyData['shipping'] ?? []),
            self::LEGACY_ITEMS_META => (array) ($legacyData['items'] ?? []),
            self::LEGACY_FEE_LINES_META => (array) ($legacyData['fee_lines'] ?? []),
            self::LEGACY_SHIPPING_LINES_META => (array) ($legacyData['shipping_lines'] ?? []),
            self::LEGACY_TRIAL_END_META => (int) ($legacyData['trial_end'] ?? 0),
            self::LEGACY_END_DATE_META => (int) ($legacyData['end_date'] ?? 0),
            self::LEGACY_LAST_ORDER_ID_META => (int) ($legacyData['last_order_id'] ?? 0),
            self::LEGACY_LAST_ORDER_DATE_META => (int) ($legacyData['last_order_date'] ?? 0),
            self::LEGACY_MOLLIE_CUSTOMER_ID_META => (string) ($legacyData['mollie_customer_id'] ?? ''),
            self::LEGACY_MOLLIE_MANDATE_ID_META => (string) ($legacyData['mollie_mandate_id'] ?? ''),
            self::LEGACY_LAST_PAYMENT_ID_META => (string) ($legacyData['last_payment_id'] ?? ''),
        ];

        foreach ($metaMap as $metaKey => $metaValue) {
            if ($metaValue === '' || $metaValue === 0 || $metaValue === [] || $metaValue === null) {
                if (in_array($metaKey, [self::LEGACY_BILLING_META, self::LEGACY_SHIPPING_META, self::LEGACY_ITEMS_META, self::LEGACY_FEE_LINES_META, self::LEGACY_SHIPPING_LINES_META], true)) {
                    update_post_meta($legacyPostId, $metaKey, $metaValue);
                } else {
                    delete_post_meta($legacyPostId, $metaKey);
                }
                continue;
            }

            update_post_meta($legacyPostId, $metaKey, $metaValue);
        }

        update_post_meta($order->get_id(), SubscriptionOrderType::LEGACY_POST_ID_META, $legacyPostId);
        update_post_meta($order->get_id(), SubscriptionOrderType::STORAGE_VERSION_META, SubscriptionOrderType::PHASE2_STORAGE_VERSION);

        $result = $this->sync_order_type_record($legacyPostId, (int) $order->get_id());

        $this->log_sync_debug('repository.sync_legacy_from_order.end', [
            'order' => $this->build_order_debug_snapshot($order),
            'legacy_after' => $this->build_legacy_debug_snapshot($legacyPostId),
            'result' => is_array($result) ? $result : [],
        ]);

        return $result;
    }

    public function ensure_order_type_record(int $legacyPostId, bool $sync = true): ?array {
        $legacy = $this->get_legacy_subscription_data($legacyPostId);
        if (empty($legacy) || !post_type_exists($this->get_order_type())) {
            return null;
        }

        $existing = $this->find_by_legacy_post_id($legacyPostId);
        $orderId = is_array($existing) ? (int) ($existing['id'] ?? 0) : 0;

        if ($orderId <= 0) {
            $orderId = wp_insert_post($this->build_shadow_order_postarr($legacy), true);
            if (is_wp_error($orderId) || $orderId <= 0) {
                return null;
            }
            $orderId = (int) $orderId;
        }

        if ($sync) {
            return $this->sync_order_type_record($legacyPostId, $orderId, $legacy);
        }

        return $this->find($orderId);
    }

    public function sync_order_type_record(int $legacyPostId, int $orderId = 0, array $legacy = []): ?array {
        $legacy = !empty($legacy) ? $legacy : $this->get_legacy_subscription_data($legacyPostId);
        if (empty($legacy)) {
            $this->log_sync_debug('repository.sync_order_type_record.empty_legacy', [
                'legacy_post_id' => $legacyPostId,
                'order_id' => $orderId,
            ]);
            return null;
        }

        if ($orderId <= 0) {
            $existing = $this->find_by_legacy_post_id($legacyPostId);
            $orderId = is_array($existing) ? (int) ($existing['id'] ?? 0) : 0;
        }

        if ($orderId <= 0) {
            return $this->ensure_order_type_record($legacyPostId, true);
        }

        $this->log_sync_debug('repository.sync_order_type_record.start', [
            'legacy_post_id' => $legacyPostId,
            'order_id' => $orderId,
            'legacy' => $this->build_legacy_debug_snapshot($legacyPostId, $legacy),
            'order_before' => $this->build_order_debug_snapshot($orderId),
        ]);

        wp_update_post($this->build_shadow_order_postarr($legacy, $orderId));
        $this->sync_shadow_order_meta($orderId, $legacy);
        $this->sync_shadow_order_items($orderId, $legacy);

        $result = $this->find($orderId);

        $this->log_sync_debug('repository.sync_order_type_record.end', [
            'legacy_post_id' => $legacyPostId,
            'order_id' => $orderId,
            'order_after' => $this->build_order_debug_snapshot($orderId),
            'result' => is_array($result) ? $result : [],
        ]);

        return $result;
    }

    public function sync_order_type_self(int $orderId, bool $createMissingLegacy = false): ?array {
        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            return null;
        }

        $order = wc_get_order($orderId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== $this->get_order_type()) {
            return null;
        }

        $data = $this->get_order_type_subscription_data($order);
        if (empty($data)) {
            $this->log_sync_debug('repository.sync_order_type_self.empty_data', [
                'order' => $this->build_order_debug_snapshot($order),
            ]);
            return null;
        }

        $data['items'] = $this->extract_legacy_items_from_order($order);
        $data['fee_lines'] = $this->extract_legacy_fee_lines_from_order($order);
        $data['shipping_lines'] = $this->extract_legacy_shipping_lines_from_order($order);
        $data['totals'] = $this->calculate_legacy_totals(
            $data['items'],
            $data['fee_lines'],
            $data['shipping_lines']
        );

        $this->log_sync_debug('repository.sync_order_type_self.start', [
            'order' => $this->build_order_debug_snapshot($order),
            'linked_legacy_before' => $this->build_legacy_debug_snapshot((int) ($data['legacy_post_id'] ?? 0)),
            'data' => $data,
        ]);

        wp_update_post($this->build_shadow_order_postarr($data, $orderId));
        $this->sync_shadow_order_meta($orderId, $data);
        $this->sync_shadow_order_items($orderId, $data);

        $synced = $this->sync_legacy_from_order($order, $createMissingLegacy);
        if (is_array($synced)) {
            $this->log_sync_debug('repository.sync_order_type_self.end', [
                'order' => $this->build_order_debug_snapshot($order),
                'linked_legacy_after' => $this->build_legacy_debug_snapshot($this->get_linked_legacy_post_id($order)),
                'result' => $synced,
            ]);
            return $synced;
        }

        $result = $this->find($orderId);

        $this->log_sync_debug('repository.sync_order_type_self.end_without_sync_result', [
            'order' => $this->build_order_debug_snapshot($order),
            'result' => is_array($result) ? $result : [],
        ]);

        return $result;
    }

    public function exists(int $subscriptionId): bool {
        return $this->find($subscriptionId) !== null;
    }

    public function find(int $subscriptionId): ?array {
        if ($subscriptionId <= 0) {
            return null;
        }

        if (function_exists('wc_get_order')) {
            $order = wc_get_order($subscriptionId);
            if ($order && is_object($order) && method_exists($order, 'get_type') && (string) $order->get_type() === $this->get_order_type()) {
                return $this->normalize_order_record($order);
            }
        }

        $post = get_post($subscriptionId);
        if ($post instanceof \WP_Post && (string) $post->post_type === self::LEGACY_POST_TYPE) {
            return $this->normalize_legacy_record($post);
        }

        return null;
    }

    public function find_by_legacy_post_id(int $legacyPostId): ?array {
        if ($legacyPostId <= 0 || !function_exists('wc_get_orders')) {
            return null;
        }

        $orders = wc_get_orders([
            'type' => $this->get_order_type(),
            'limit' => 1,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_key' => SubscriptionOrderType::LEGACY_POST_ID_META,
            'meta_value' => (string) $legacyPostId,
        ]);

        if (!is_array($orders) || empty($orders[0]) || !is_object($orders[0])) {
            return null;
        }

        return $this->normalize_order_record($orders[0]);
    }

    public function get_order_type_subscription_data($order): array {
        if (is_numeric($order)) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order) : null;
        }

        if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== $this->get_order_type()) {
            return [];
        }

        $orderId = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        if ($orderId <= 0) {
            return [];
        }

        $billing = $this->extract_order_address($order, 'billing');
        $shipping = $this->extract_order_address($order, 'shipping');
        $items = get_post_meta($orderId, self::LEGACY_ITEMS_META, true);
        $feeLines = get_post_meta($orderId, self::LEGACY_FEE_LINES_META, true);
        $shippingLines = get_post_meta($orderId, self::LEGACY_SHIPPING_LINES_META, true);
        $status = method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_status', true) : '';
        if ($status === '') {
            $status = (string) get_post_meta($orderId, self::LEGACY_STATUS_META, true);
        }
        if ($status === '') {
            $status = $this->map_order_post_status_to_legacy_status(method_exists($order, 'get_status') ? (string) $order->get_status() : 'pending');
        }
        $items = is_array($items) ? $items : [];
        $feeLines = is_array($feeLines) ? $feeLines : [];
        $shippingLines = is_array($shippingLines) ? $shippingLines : [];

        if (!self::$resolvingOrderTypeData && method_exists($order, 'get_items')) {
            self::$resolvingOrderTypeData = true;

            try {
                if (empty($items)) {
                    $items = $this->extract_legacy_items_from_order($order);
                }

                if (empty($feeLines)) {
                    $feeLines = $this->extract_legacy_fee_lines_from_order($order);
                }

                if (empty($shippingLines)) {
                    $shippingLines = $this->extract_legacy_shipping_lines_from_order($order);
                }
            } finally {
                self::$resolvingOrderTypeData = false;
            }
        }

        $totals = $this->calculate_legacy_totals($items, $feeLines, $shippingLines);
        $nextPayment = (int) get_post_meta($orderId, '_hb_ucs_subscription_next_payment', true);
        if ($nextPayment <= 0) {
            $nextPayment = (int) get_post_meta($orderId, self::LEGACY_NEXT_PAYMENT_META, true);
        }

        $trialEnd = (int) get_post_meta($orderId, '_hb_ucs_subscription_trial_end', true);
        if ($trialEnd <= 0) {
            $trialEnd = (int) get_post_meta($orderId, self::LEGACY_TRIAL_END_META, true);
        }

        $endDate = (int) get_post_meta($orderId, '_hb_ucs_subscription_end_date', true);
        if ($endDate <= 0) {
            $endDate = (int) get_post_meta($orderId, self::LEGACY_END_DATE_META, true);
        }

        $post = get_post($orderId);

        return [
            'id' => $orderId,
            'post' => $post instanceof \WP_Post ? $post : null,
            'date_created_gmt' => $post instanceof \WP_Post ? (string) $post->post_date_gmt : '',
            'date_modified_gmt' => $post instanceof \WP_Post ? (string) $post->post_modified_gmt : '',
            'customer_note' => method_exists($order, 'get_customer_note') ? (string) $order->get_customer_note() : '',
            'customer_id' => method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : (int) get_post_meta($orderId, '_customer_user', true),
            'status' => $status,
            'parent_order_id' => (int) get_post_meta($orderId, self::LEGACY_PARENT_ORDER_ID_META, true),
            'scheme' => (string) get_post_meta($orderId, '_hb_ucs_subscription_scheme', true),
            'interval' => (int) get_post_meta($orderId, '_hb_ucs_subscription_interval', true),
            'period' => (string) get_post_meta($orderId, '_hb_ucs_subscription_period', true),
            'next_payment' => $nextPayment,
            'payment_method' => method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '',
            'payment_method_title' => method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : '',
            'billing' => $billing,
            'shipping' => $shipping,
            'items' => $items,
            'fee_lines' => $feeLines,
            'shipping_lines' => $shippingLines,
            'trial_end' => $trialEnd,
            'end_date' => $endDate,
            'last_order_id' => (int) get_post_meta($orderId, self::LEGACY_LAST_ORDER_ID_META, true),
            'last_order_date' => (int) get_post_meta($orderId, self::LEGACY_LAST_ORDER_DATE_META, true),
            'mollie_customer_id' => (string) get_post_meta($orderId, self::LEGACY_MOLLIE_CUSTOMER_ID_META, true),
            'mollie_mandate_id' => (string) get_post_meta($orderId, self::LEGACY_MOLLIE_MANDATE_ID_META, true),
            'last_payment_id' => (string) get_post_meta($orderId, self::LEGACY_LAST_PAYMENT_ID_META, true),
            'currency' => method_exists($order, 'get_currency') ? (string) $order->get_currency() : (string) get_post_meta($orderId, '_order_currency', true),
            'prices_include_tax' => method_exists($order, 'get_prices_include_tax') ? (bool) $order->get_prices_include_tax() : ((string) get_post_meta($orderId, '_prices_include_tax', true) === 'yes'),
            'totals' => $totals,
        ];
    }

    public function get_admin_edit_url(int $subscriptionId): string {
        $record = $this->find($subscriptionId);
        if (!$record) {
            return '';
        }

        if (($record['storage'] ?? '') === 'order_type') {
            return $this->build_order_type_edit_url((int) $record['id']);
        }

        return admin_url('post.php?post=' . (int) $record['id'] . '&action=edit');
    }

    public function get_migration_seed(int $subscriptionId): array {
        $record = $this->find($subscriptionId);
        if (!$record) {
            return [];
        }

        if (($record['storage'] ?? '') === 'order_type') {
            $order = $record['object'];
            $nextPayment = method_exists($order, 'get_meta') ? (int) $order->get_meta('_hb_ucs_subscription_next_payment', true) : 0;
            if ($nextPayment <= 0) {
                $nextPayment = method_exists($order, 'get_meta') ? (int) $order->get_meta(self::LEGACY_NEXT_PAYMENT_META, true) : 0;
            }

            return [
                'id' => (int) $record['id'],
                'storage' => 'order_type',
                'legacy_post_id' => method_exists($order, 'get_meta') ? (int) $order->get_meta(SubscriptionOrderType::LEGACY_POST_ID_META, true) : 0,
                'customer_id' => method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0,
                'status' => method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_status', true) : '',
                'scheme' => method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_scheme', true) : '',
                'next_payment' => $nextPayment,
                'edit_url' => $this->get_admin_edit_url((int) $record['id']),
            ];
        }

        $postId = (int) $record['id'];
        $legacy = $this->get_legacy_subscription_data($postId);

        return [
            'id' => $postId,
            'storage' => 'legacy_post',
            'legacy_post_id' => $postId,
            'customer_id' => (int) ($legacy['customer_id'] ?? 0),
            'status' => (string) ($legacy['status'] ?? ''),
            'parent_order_id' => (int) ($legacy['parent_order_id'] ?? 0),
            'scheme' => (string) ($legacy['scheme'] ?? ''),
            'interval' => (int) ($legacy['interval'] ?? 0),
            'period' => (string) ($legacy['period'] ?? ''),
            'next_payment' => (int) ($legacy['next_payment'] ?? 0),
            'payment_method' => (string) ($legacy['payment_method'] ?? ''),
            'payment_method_title' => (string) ($legacy['payment_method_title'] ?? ''),
            'billing' => (array) ($legacy['billing'] ?? []),
            'shipping' => (array) ($legacy['shipping'] ?? []),
            'items' => (array) ($legacy['items'] ?? []),
            'fee_lines' => (array) ($legacy['fee_lines'] ?? []),
            'shipping_lines' => (array) ($legacy['shipping_lines'] ?? []),
            'trial_end' => (int) ($legacy['trial_end'] ?? 0),
            'end_date' => (int) ($legacy['end_date'] ?? 0),
            'last_order_id' => (int) ($legacy['last_order_id'] ?? 0),
            'mollie_customer_id' => (string) ($legacy['mollie_customer_id'] ?? ''),
            'mollie_mandate_id' => (string) ($legacy['mollie_mandate_id'] ?? ''),
            'last_payment_id' => (string) ($legacy['last_payment_id'] ?? ''),
            'totals' => (array) ($legacy['totals'] ?? []),
            'edit_url' => $this->get_admin_edit_url($postId),
        ];
    }

    private function normalize_legacy_record(\WP_Post $post): array {
        return [
            'id' => (int) $post->ID,
            'storage' => 'legacy_post',
            'type' => self::LEGACY_POST_TYPE,
            'object' => $post,
            'edit_url' => admin_url('post.php?post=' . (int) $post->ID . '&action=edit'),
        ];
    }

    private function normalize_order_record($order): array {
        $orderId = method_exists($order, 'get_id') ? (int) $order->get_id() : 0;
        return [
            'id' => $orderId,
            'storage' => 'order_type',
            'type' => $this->get_order_type(),
            'object' => $order,
            'edit_url' => $this->build_order_type_edit_url($orderId),
        ];
    }

    private function build_order_type_edit_url(int $orderId): string {
        if ($orderId <= 0) {
            return '';
        }

        if (class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            return \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url($orderId);
        }

        return admin_url('admin.php?page=wc-orders--' . $this->get_order_type() . '&action=edit&id=' . $orderId);
    }

    private function build_shadow_order_postarr(array $legacy, int $orderId = 0): array {
        $post = isset($legacy['post']) && $legacy['post'] instanceof \WP_Post ? $legacy['post'] : null;
        $customerId = (int) ($legacy['customer_id'] ?? 0);
        $billing = isset($legacy['billing']) && is_array($legacy['billing']) ? $legacy['billing'] : [];
        $name = trim(((string) ($billing['first_name'] ?? '')) . ' ' . ((string) ($billing['last_name'] ?? '')));
        $title = sprintf(__('Abonnement #%1$d', 'hb-ucs'), (int) ($legacy['id'] ?? 0));

        if ($name !== '') {
            $title .= ' — ' . $name;
        } elseif ($customerId > 0) {
            $title .= ' — ' . sprintf(__('Klant #%d', 'hb-ucs'), $customerId);
        } elseif ($post && $post->post_title !== '') {
            $title = (string) $post->post_title;
        }

        $postarr = [
            'post_type' => $this->get_order_type(),
            'post_status' => $this->map_legacy_status_to_order_post_status((string) ($legacy['status'] ?? 'pending_mandate')),
            'post_parent' => (int) ($legacy['parent_order_id'] ?? 0),
            'post_title' => $title,
            'post_excerpt' => (string) ($legacy['customer_note'] ?? ''),
            'post_date' => $post ? (string) $post->post_date : current_time('mysql'),
            'post_date_gmt' => $post ? (string) $post->post_date_gmt : current_time('mysql', 1),
        ];

        if ($orderId > 0) {
            $postarr['ID'] = $orderId;
        }

        return $postarr;
    }

    private function build_legacy_data_from_order($order, int $legacyPostId): array {
        $existing = $this->get_legacy_subscription_data($legacyPostId);
        $scheme = method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_scheme', true) : '';
        $scheme = $scheme !== '' ? $scheme : (string) ($existing['scheme'] ?? '1w');
        $interval = $this->extract_interval_from_scheme($scheme, (int) ($existing['interval'] ?? 0));
        $status = method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_status', true) : '';
        if ($status === '') {
            $status = method_exists($order, 'get_meta') ? (string) $order->get_meta(self::LEGACY_STATUS_META, true) : '';
        }
        $status = $status !== '' ? $status : $this->map_order_post_status_to_legacy_status(method_exists($order, 'get_status') ? (string) $order->get_status() : 'pending');

        $nextPayment = method_exists($order, 'get_meta') ? (int) $order->get_meta('_hb_ucs_subscription_next_payment', true) : 0;
        if ($nextPayment <= 0) {
            $nextPayment = method_exists($order, 'get_meta') ? (int) $order->get_meta(self::LEGACY_NEXT_PAYMENT_META, true) : 0;
        }
        if ($nextPayment <= 0) {
            $nextPayment = (int) ($existing['next_payment'] ?? 0);
        }

        $trialEnd = method_exists($order, 'get_meta') ? (int) $order->get_meta('_hb_ucs_subscription_trial_end', true) : 0;
        if ($trialEnd <= 0) {
            $trialEnd = method_exists($order, 'get_meta') ? (int) $order->get_meta(self::LEGACY_TRIAL_END_META, true) : 0;
        }
        if ($trialEnd <= 0) {
            $trialEnd = (int) ($existing['trial_end'] ?? 0);
        }

        $endDate = method_exists($order, 'get_meta') ? (int) $order->get_meta('_hb_ucs_subscription_end_date', true) : 0;
        if ($endDate <= 0) {
            $endDate = method_exists($order, 'get_meta') ? (int) $order->get_meta(self::LEGACY_END_DATE_META, true) : 0;
        }
        if ($endDate <= 0) {
            $endDate = (int) ($existing['end_date'] ?? 0);
        }

        return [
            'customer_note' => method_exists($order, 'get_customer_note') ? (string) $order->get_customer_note() : (string) ($existing['customer_note'] ?? ''),
            'customer_id' => method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : (int) ($existing['customer_id'] ?? 0),
            'status' => $status,
            'parent_order_id' => method_exists($order, 'get_parent_id') ? (int) $order->get_parent_id() : (int) ($existing['parent_order_id'] ?? 0),
            'scheme' => $scheme,
            'interval' => $interval,
            'period' => 'week',
            'next_payment' => $nextPayment,
            'payment_method' => method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : (string) ($existing['payment_method'] ?? ''),
            'payment_method_title' => method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : (string) ($existing['payment_method_title'] ?? ''),
            'billing' => $this->extract_order_address($order, 'billing'),
            'shipping' => $this->extract_order_address($order, 'shipping'),
            'items' => $this->extract_legacy_items_from_order($order),
            'fee_lines' => $this->extract_legacy_fee_lines_from_order($order),
            'shipping_lines' => $this->extract_legacy_shipping_lines_from_order($order),
            'trial_end' => $trialEnd,
            'end_date' => $endDate,
            'last_order_id' => (int) ($existing['last_order_id'] ?? 0),
            'last_order_date' => (int) ($existing['last_order_date'] ?? 0),
            'mollie_customer_id' => method_exists($order, 'get_meta') ? (string) $order->get_meta(self::LEGACY_MOLLIE_CUSTOMER_ID_META, true) : (string) ($existing['mollie_customer_id'] ?? ''),
            'mollie_mandate_id' => method_exists($order, 'get_meta') ? (string) $order->get_meta(self::LEGACY_MOLLIE_MANDATE_ID_META, true) : (string) ($existing['mollie_mandate_id'] ?? ''),
            'last_payment_id' => method_exists($order, 'get_meta') ? (string) $order->get_meta(self::LEGACY_LAST_PAYMENT_ID_META, true) : (string) ($existing['last_payment_id'] ?? ''),
        ];
    }

    private function build_legacy_post_title_from_order($order): string {
        $billingName = '';
        if (method_exists($order, 'get_formatted_billing_full_name')) {
            $billingName = trim(wp_strip_all_tags((string) $order->get_formatted_billing_full_name(), true));
        }

        if ($billingName === '') {
            $firstName = method_exists($order, 'get_billing_first_name') ? (string) $order->get_billing_first_name() : '';
            $lastName = method_exists($order, 'get_billing_last_name') ? (string) $order->get_billing_last_name() : '';
            $billingName = trim($firstName . ' ' . $lastName);
        }

        $title = sprintf(__('Abonnement #%d', 'hb-ucs'), (int) $order->get_id());
        return $billingName !== '' ? $title . ' — ' . $billingName : $title;
    }

    private function extract_order_address($order, string $type): array {
        $fields = ['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'];
        $address = [];

        foreach ($fields as $field) {
            $getter = 'get_' . $type . '_' . $field;
            if (!method_exists($order, $getter)) {
                continue;
            }

            $address[$field] = (string) $order->{$getter}();
        }

        return array_filter($address, static function ($value) {
            return $value !== '';
        });
    }

    private function get_display_meta_row_hash(string $label, string $value): string {
        return strtolower(remove_accents(trim($label) . '|' . trim($value)));
    }

    private function get_selected_attribute_display_row_hashes(int $baseProductId, array $selectedAttributes): array {
        $hashes = [];
        $selectedAttributes = is_array($selectedAttributes) ? $selectedAttributes : [];
        if (empty($selectedAttributes)) {
            return $hashes;
        }

        $configByKey = [];
        if ($baseProductId > 0 && function_exists('wc_get_product')) {
            $product = wc_get_product($baseProductId);
            if ($product && is_object($product) && method_exists($product, 'get_attributes')) {
                foreach ((array) $product->get_attributes() as $attributeKey => $attribute) {
                    if (!is_object($attribute) || !method_exists($attribute, 'get_name')) {
                        continue;
                    }

                    $name = (string) $attribute->get_name();
                    $key = strpos($name, 'pa_') === 0 ? 'attribute_' . sanitize_key($name) : 'attribute_' . sanitize_key($name);
                    $label = function_exists('wc_attribute_label') ? (string) wc_attribute_label($name, $product) : ucwords(str_replace(['pa_', '_', '-'], ['', ' ', ' '], $name));
                    $options = [];

                    if (taxonomy_exists($name)) {
                        foreach ((array) wc_get_product_terms($baseProductId, $name, ['fields' => 'all']) as $term) {
                            if (!is_object($term) || !isset($term->slug, $term->name)) {
                                continue;
                            }
                            $options[(string) $term->slug] = (string) $term->name;
                        }
                    } elseif (method_exists($attribute, 'get_options')) {
                        foreach ((array) $attribute->get_options() as $option) {
                            $option = (string) $option;
                            if ($option === '') {
                                continue;
                            }
                            $options[sanitize_title($option)] = $option;
                        }
                    }

                    $configByKey[$key] = [
                        'label' => $label !== '' ? $label : $key,
                        'options' => $options,
                    ];
                }
            }
        }

        foreach ($selectedAttributes as $key => $rawValue) {
            $key = sanitize_key((string) $key);
            $rawValue = (string) $rawValue;
            if ($key === '' || $rawValue === '') {
                continue;
            }

            $label = isset($configByKey[$key]['label']) ? (string) $configByKey[$key]['label'] : trim(wp_strip_all_tags(ucwords(str_replace(['attribute_', '_', '-'], ['', ' ', ' '], $key)), true));
            $value = $rawValue;
            if (isset($configByKey[$key]['options'][sanitize_title($rawValue)])) {
                $value = (string) $configByKey[$key]['options'][sanitize_title($rawValue)];
            } else {
                $value = trim(wp_strip_all_tags(ucwords(str_replace(['_', '-'], [' ', ' '], $rawValue)), true));
            }

            if ($label === '' || $value === '') {
                continue;
            }

            $hashes[$this->get_display_meta_row_hash($label, $value)] = true;
        }

        return $hashes;
    }

    private function sanitize_display_meta_rows(array $rows): array {
        $clean = [];
        $seen = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = isset($row['label']) && is_scalar($row['label']) ? trim(wp_strip_all_tags((string) $row['label'], true)) : '';
            $value = isset($row['value']) && is_scalar($row['value']) ? trim(html_entity_decode(wp_strip_all_tags((string) $row['value'], true), ENT_QUOTES, 'UTF-8')) : '';
            if ($label === '' || $value === '') {
                continue;
            }

            $hash = $this->get_display_meta_row_hash($label, $value);
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

    private function should_skip_display_meta_key(string $metaKey): bool {
        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return true;
        }

        $normalizedKey = ltrim(sanitize_key($metaKey), '_');
        if ($normalizedKey === '' || strpos($normalizedKey, 'attribute_') === 0) {
            return true;
        }

        if (in_array($normalizedKey, $this->get_display_meta_excluded_keys(), true)) {
            return true;
        }

        return strpos($metaKey, '_') === 0;
    }

    private function should_skip_formatted_display_meta(string $metaKey, string $label): bool {
        $label = trim($label);
        if ($label === '') {
            return true;
        }

        $metaKey = trim($metaKey);
        if ($metaKey === '') {
            return false;
        }

        $normalizedKey = ltrim(sanitize_key($metaKey), '_');
        if ($normalizedKey === '' || strpos($normalizedKey, 'attribute_') === 0) {
            return true;
        }

        return in_array($normalizedKey, $this->get_display_meta_excluded_keys(), true);
    }

    private function get_display_meta_excluded_keys(): array {
        return [
            'hb_ucs_subscription_selected_attributes',
            'hb_ucs_subscription_source_order_item_id',
            'hb_ucs_subscription_base_product_id',
            'hb_ucs_subscription_base_variation_id',
            'hb_ucs_subscription_scheme',
            'sku',
        ];
    }

    private function resolve_source_order_item_id($item, bool $isSubscriptionOrder): int {
        $sourceOrderItemId = 0;

        if (method_exists($item, 'get_meta')) {
            $sourceOrderItemId = (int) $item->get_meta('_hb_ucs_subscription_source_order_item_id', true);
        }

        if ($sourceOrderItemId <= 0 && !$isSubscriptionOrder && method_exists($item, 'get_id')) {
            $sourceOrderItemId = (int) $item->get_id();
        }

        return $sourceOrderItemId;
    }

    private function extract_selected_attributes_from_order_item($item): array {
        $attributes = [];

        if (!method_exists($item, 'get_meta_data')) {
            return $attributes;
        }

        foreach ((array) $item->get_meta_data() as $meta) {
            if (!is_object($meta) || !isset($meta->key)) {
                continue;
            }

            $metaKey = sanitize_key((string) $meta->key);
            if (strpos($metaKey, 'attribute_') !== 0) {
                continue;
            }

            $attributes[$metaKey] = sanitize_title((string) $meta->value);
        }

        return $attributes;
    }

    private function extract_display_meta_rows_from_order_item($item): array {
        if (!$item || !is_object($item)) {
            return [];
        }

        $rows = [];
        $seen = [];

        if (method_exists($item, 'get_all_formatted_meta_data') || method_exists($item, 'get_formatted_meta_data')) {
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
                if ($label === '' || $value === '' || $this->should_skip_formatted_display_meta($metaKey, $label)) {
                    continue;
                }

                $hash = $this->get_display_meta_row_hash($label, $value);
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
                if (!is_object($meta) || !isset($meta->key) || !isset($meta->value)) {
                    continue;
                }

                $metaKey = is_scalar($meta->key) ? (string) $meta->key : '';
                $metaValue = is_scalar($meta->value) ? trim((string) $meta->value) : '';
                if ($metaValue === '' || $this->should_skip_display_meta_key($metaKey)) {
                    continue;
                }

                $label = trim(wp_strip_all_tags(ucwords(str_replace(['-', '_'], ' ', (string) preg_replace('/^_+/', '', $metaKey))), true));
                if ($label === '') {
                    continue;
                }

                $hash = $this->get_display_meta_row_hash($label, $metaValue);
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

        return $this->sanitize_display_meta_rows($rows);
    }

    private function extract_legacy_items_from_order($order): array {
        $items = [];
        $isSubscriptionOrder = is_object($order) && method_exists($order, 'get_type') && strpos((string) $order->get_type(), 'hb_ucs_subscription') !== false;
        $scheme = method_exists($order, 'get_meta') ? sanitize_key((string) $order->get_meta('_hb_ucs_subscription_scheme', true)) : '';
        foreach ((array) $order->get_items('line_item') as $item) {
            if (!$item || !is_object($item)) {
                continue;
            }

            $qty = max(1, method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1);
            $productId = method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0;
            $variationId = method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0;
            $lineTotal = method_exists($item, 'get_total') ? (float) $item->get_total() : 0.0;
            $attributes = $this->extract_selected_attributes_from_order_item($item);
            $displayMetaRows = $this->extract_display_meta_rows_from_order_item($item);
            $sourceOrderItemId = $this->resolve_source_order_item_id($item, $isSubscriptionOrder);

            $items[] = [
                'base_product_id' => $productId,
                'base_variation_id' => $variationId,
                'source_order_item_id' => $sourceOrderItemId,
                'qty' => $qty,
                'unit_price' => $qty > 0 ? $this->normalize_decimal($lineTotal / $qty) : 0.0,
                'catalog_unit_price' => method_exists($item, 'get_meta') ? $item->get_meta(self::ORDER_ITEM_META_CATALOG_UNIT_PRICE, true) : '',
                'scheme' => $scheme,
                'price_includes_tax' => 0,
                'taxes' => $this->normalize_item_taxes(method_exists($item, 'get_taxes') ? (array) $item->get_taxes() : []),
                'selected_attributes' => $attributes,
                'display_meta' => $displayMetaRows,
                'source_item_snapshot' => [
                    'selected_attributes' => $attributes,
                    'display_meta' => $displayMetaRows,
                ],
            ];
        }

        return $items;
    }

    private function extract_legacy_fee_lines_from_order($order): array {
        $lines = [];
        foreach ((array) $order->get_items('fee') as $item) {
            if (!$item || !is_object($item)) {
                continue;
            }

            $lines[] = [
                'name' => method_exists($item, 'get_name') ? (string) $item->get_name() : __('Toeslag', 'hb-ucs'),
                'total' => method_exists($item, 'get_total') ? $this->normalize_decimal($item->get_total()) : 0.0,
                'taxes' => method_exists($item, 'get_taxes') ? (array) $item->get_taxes() : ['total' => []],
            ];
        }

        return $lines;
    }

    private function extract_legacy_shipping_lines_from_order($order): array {
        $lines = [];
        foreach ((array) $order->get_items('shipping') as $item) {
            if (!$item || !is_object($item)) {
                continue;
            }

            $lines[] = [
                'method_title' => method_exists($item, 'get_method_title') ? (string) $item->get_method_title() : __('Verzending', 'hb-ucs'),
                'method_id' => method_exists($item, 'get_method_id') ? (string) $item->get_method_id() : '',
                'instance_id' => method_exists($item, 'get_instance_id') ? (int) $item->get_instance_id() : 0,
                'total' => method_exists($item, 'get_total') ? $this->normalize_decimal($item->get_total()) : 0.0,
                'taxes' => method_exists($item, 'get_taxes') ? (array) $item->get_taxes() : ['total' => []],
            ];
        }

        return $lines;
    }

    private function sync_shadow_order_meta(int $orderId, array $legacy): void {
        $billing = isset($legacy['billing']) && is_array($legacy['billing']) ? $legacy['billing'] : [];
        $shipping = isset($legacy['shipping']) && is_array($legacy['shipping']) ? $legacy['shipping'] : [];
        $items = isset($legacy['items']) && is_array($legacy['items']) ? $legacy['items'] : [];
        $feeLines = isset($legacy['fee_lines']) && is_array($legacy['fee_lines']) ? $legacy['fee_lines'] : [];
        $shippingLines = isset($legacy['shipping_lines']) && is_array($legacy['shipping_lines']) ? $legacy['shipping_lines'] : [];
        $totals = isset($legacy['totals']) && is_array($legacy['totals']) ? $legacy['totals'] : [];
        $orderKey = (string) get_post_meta($orderId, '_order_key', true);
        $structuredMetaKeys = [
            self::LEGACY_BILLING_META,
            self::LEGACY_SHIPPING_META,
            self::LEGACY_ITEMS_META,
            self::LEGACY_FEE_LINES_META,
            self::LEGACY_SHIPPING_LINES_META,
        ];

        if ($orderKey === '' && function_exists('wc_generate_order_key')) {
            $orderKey = wc_generate_order_key();
        }

        $metaMap = [
            SubscriptionOrderType::LEGACY_POST_ID_META => (int) ($legacy['legacy_post_id'] ?? ($legacy['id'] ?? 0)),
            SubscriptionOrderType::STORAGE_VERSION_META => SubscriptionOrderType::PHASE2_STORAGE_VERSION,
            '_order_key' => $orderKey,
            '_customer_user' => (int) ($legacy['customer_id'] ?? 0),
            '_payment_method' => (string) ($legacy['payment_method'] ?? ''),
            '_payment_method_title' => (string) ($legacy['payment_method_title'] ?? ''),
            self::LEGACY_BILLING_META => $billing,
            self::LEGACY_SHIPPING_META => $shipping,
            self::LEGACY_ITEMS_META => $items,
            self::LEGACY_FEE_LINES_META => $feeLines,
            self::LEGACY_SHIPPING_LINES_META => $shippingLines,
            '_created_via' => 'hb-ucs-legacy-subscription',
            '_order_currency' => (string) ($legacy['currency'] ?? get_woocommerce_currency()),
            '_prices_include_tax' => !empty($legacy['prices_include_tax']) ? 'yes' : 'no',
            '_order_shipping' => $this->format_decimal($totals['shipping_subtotal'] ?? 0.0),
            '_order_shipping_tax' => $this->format_decimal($totals['shipping_tax'] ?? 0.0),
            '_order_tax' => $this->format_decimal($totals['tax'] ?? 0.0),
            '_order_total' => $this->format_decimal($totals['total'] ?? 0.0),
            self::LEGACY_STATUS_META => (string) ($legacy['status'] ?? ''),
            self::LEGACY_SCHEME_META => (string) ($legacy['scheme'] ?? ''),
            self::LEGACY_INTERVAL_META => (int) ($legacy['interval'] ?? 0),
            self::LEGACY_PERIOD_META => (string) ($legacy['period'] ?? ''),
            self::LEGACY_NEXT_PAYMENT_META => (int) ($legacy['next_payment'] ?? 0),
            self::LEGACY_TRIAL_END_META => (int) ($legacy['trial_end'] ?? 0),
            self::LEGACY_END_DATE_META => (int) ($legacy['end_date'] ?? 0),
            '_hb_ucs_subscription_status' => (string) ($legacy['status'] ?? ''),
            '_hb_ucs_subscription_scheme' => (string) ($legacy['scheme'] ?? ''),
            '_hb_ucs_subscription_interval' => (int) ($legacy['interval'] ?? 0),
            '_hb_ucs_subscription_period' => (string) ($legacy['period'] ?? ''),
            '_hb_ucs_subscription_next_payment' => (int) ($legacy['next_payment'] ?? 0),
            '_hb_ucs_subscription_trial_end' => (int) ($legacy['trial_end'] ?? 0),
            '_hb_ucs_subscription_end_date' => (int) ($legacy['end_date'] ?? 0),
            self::LEGACY_LAST_ORDER_ID_META => (int) ($legacy['last_order_id'] ?? 0),
            self::LEGACY_LAST_ORDER_DATE_META => (int) ($legacy['last_order_date'] ?? 0),
            self::LEGACY_MOLLIE_CUSTOMER_ID_META => (string) ($legacy['mollie_customer_id'] ?? ''),
            self::LEGACY_MOLLIE_MANDATE_ID_META => (string) ($legacy['mollie_mandate_id'] ?? ''),
            self::LEGACY_LAST_PAYMENT_ID_META => (string) ($legacy['last_payment_id'] ?? ''),
        ];

        foreach ($metaMap as $metaKey => $metaValue) {
            if ($metaValue === '' || $metaValue === [] || $metaValue === null) {
                if (in_array($metaKey, $structuredMetaKeys, true)) {
                    update_post_meta($orderId, $metaKey, $metaValue);
                } else {
                    delete_post_meta($orderId, $metaKey);
                }
                continue;
            }

            update_post_meta($orderId, $metaKey, $metaValue);
        }

        $this->log_sync_debug('repository.sync_shadow_order_meta', [
            'order_id' => $orderId,
            'legacy_post_id' => (int) ($legacy['legacy_post_id'] ?? ($legacy['id'] ?? 0)),
            'status' => (string) ($legacy['status'] ?? ''),
            'scheme' => (string) ($legacy['scheme'] ?? ''),
            'next_payment' => (int) ($legacy['next_payment'] ?? 0),
            'trial_end' => (int) ($legacy['trial_end'] ?? 0),
            'end_date' => (int) ($legacy['end_date'] ?? 0),
        ]);

        foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
            $billingValue = (string) ($billing[$field] ?? '');
            $shippingValue = (string) ($shipping[$field] ?? '');

            if ($billingValue !== '') {
                update_post_meta($orderId, '_billing_' . $field, $billingValue);
            } else {
                delete_post_meta($orderId, '_billing_' . $field);
            }

            if ($shippingValue !== '') {
                update_post_meta($orderId, '_shipping_' . $field, $shippingValue);
            } else {
                delete_post_meta($orderId, '_shipping_' . $field);
            }
        }
    }

    private function sync_shadow_order_items(int $orderId, array $legacy): void {
        if ($orderId <= 0 || !function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($orderId);
        if (!$order || !is_object($order) || !method_exists($order, 'get_items') || !method_exists($order, 'add_item')) {
            return;
        }

        foreach (['line_item', 'fee', 'shipping', 'tax'] as $itemType) {
            foreach ((array) $order->get_items($itemType) as $itemId => $item) {
                if (method_exists($order, 'remove_item')) {
                    $order->remove_item($itemId);
                }
            }
        }

        foreach ((array) ($legacy['items'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $productId = (int) ($row['base_product_id'] ?? 0);
            $variationId = (int) ($row['base_variation_id'] ?? 0);
            $targetId = $variationId > 0 ? $variationId : $productId;
            $product = $targetId > 0 ? wc_get_product($targetId) : false;
            $qty = max(1, (int) ($row['qty'] ?? 1));
            $unitPrice = $this->get_legacy_item_storage_unit_price($row, $product);
            $lineSubtotal = $this->normalize_decimal($unitPrice * $qty);
            $taxes = $this->normalize_item_taxes($row['taxes'] ?? []);
            $subtotalTax = array_sum($this->normalize_tax_group($taxes['subtotal'] ?? []));
            $lineTax = array_sum($this->normalize_tax_group($taxes['total'] ?? []));

            $item = new \WC_Order_Item_Product();
            if ($product && is_object($product) && method_exists($item, 'set_product')) {
                $item->set_product($product);
            } else {
                $item->set_product_id($productId);
                $item->set_variation_id($variationId);
            }
            $item->set_quantity($qty);
            $item->set_subtotal($lineSubtotal);
            $item->set_total($lineSubtotal);
            if (method_exists($item, 'set_subtotal_tax')) {
                $item->set_subtotal_tax($this->normalize_decimal($subtotalTax));
            }
            if (method_exists($item, 'set_total_tax')) {
                $item->set_total_tax($this->normalize_decimal($lineTax));
            }
            if (method_exists($item, 'set_taxes')) {
                $item->set_taxes($taxes);
            }

            $item->add_meta_data('_hb_ucs_subscription_base_product_id', $productId, true);
            if ($variationId > 0) {
                $item->add_meta_data('_hb_ucs_subscription_base_variation_id', $variationId, true);
            }
            if ((int) ($row['source_order_item_id'] ?? 0) > 0) {
                $item->add_meta_data('_hb_ucs_subscription_source_order_item_id', (int) $row['source_order_item_id'], true);
            }
            if (array_key_exists('catalog_unit_price', $row) && $row['catalog_unit_price'] !== '' && $row['catalog_unit_price'] !== null) {
                $item->add_meta_data(self::ORDER_ITEM_META_CATALOG_UNIT_PRICE, $this->normalize_decimal($row['catalog_unit_price']), true);
            }
            if (array_key_exists('catalog_unit_price', $row) && $row['catalog_unit_price'] !== '' && $row['catalog_unit_price'] !== null) {
                $item->add_meta_data(self::ORDER_ITEM_META_CATALOG_UNIT_PRICE, $this->normalize_decimal($row['catalog_unit_price']), true);
            }
            if (!empty($legacy['scheme'])) {
                $item->add_meta_data('_hb_ucs_subscription_scheme', (string) $legacy['scheme'], true);
            }

            $selectedAttributes = isset($row['selected_attributes']) && is_array($row['selected_attributes']) ? $row['selected_attributes'] : [];
            if (!empty($selectedAttributes)) {
                $item->add_meta_data('_hb_ucs_subscription_selected_attributes', wp_json_encode($selectedAttributes), true);
            }

            if ($variationId <= 0) {
                foreach ($selectedAttributes as $attributeKey => $attributeValue) {
                    $attributeKey = sanitize_key((string) $attributeKey);
                    if ($attributeKey === '') {
                        continue;
                    }

                    $item->add_meta_data($attributeKey, sanitize_text_field((string) $attributeValue), true);
                }
            }

            $displayMetaRows = [];
            if (!empty($row['display_meta']) && is_array($row['display_meta'])) {
                $displayMetaRows = (array) $row['display_meta'];
            } elseif (!empty($row['source_item_snapshot']['display_meta']) && is_array($row['source_item_snapshot']['display_meta'])) {
                $displayMetaRows = (array) $row['source_item_snapshot']['display_meta'];
            }

            $selectedAttributeHashes = $this->get_selected_attribute_display_row_hashes($productId, $selectedAttributes);

            foreach ($displayMetaRows as $displayMetaRow) {
                if (!is_array($displayMetaRow)) {
                    continue;
                }

                $label = isset($displayMetaRow['label']) && is_scalar($displayMetaRow['label']) ? trim((string) $displayMetaRow['label']) : '';
                $value = isset($displayMetaRow['value']) && is_scalar($displayMetaRow['value']) ? trim((string) $displayMetaRow['value']) : '';
                if ($label === '' || $value === '') {
                    continue;
                }

                if (isset($selectedAttributeHashes[$this->get_display_meta_row_hash($label, $value)])) {
                    continue;
                }

                $item->add_meta_data($label, sanitize_text_field($value), true);
            }

            $order->add_item($item);
        }

        foreach ((array) ($legacy['fee_lines'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = new \WC_Order_Item_Fee();
            $item->set_name((string) ($row['name'] ?? __('Toeslag', 'hb-ucs')));
            $item->set_total($this->normalize_decimal($row['total'] ?? 0.0));
            if (method_exists($item, 'set_taxes')) {
                $item->set_taxes($this->normalize_item_taxes($row['taxes'] ?? []));
            }
            $order->add_item($item);
        }

        foreach ((array) ($legacy['shipping_lines'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $item = new \WC_Order_Item_Shipping();
            if (method_exists($item, 'set_method_title')) {
                $item->set_method_title((string) ($row['method_title'] ?? __('Verzending', 'hb-ucs')));
            }
            if (method_exists($item, 'set_method_id')) {
                $item->set_method_id((string) ($row['method_id'] ?? ''));
            }
            if (method_exists($item, 'set_instance_id')) {
                $item->set_instance_id((int) ($row['instance_id'] ?? 0));
            }
            $item->set_total($this->normalize_decimal($row['total'] ?? 0.0));
            if (method_exists($item, 'set_taxes')) {
                $item->set_taxes($this->normalize_item_taxes($row['taxes'] ?? []));
            }
            $order->add_item($item);
        }

        if (method_exists($order, 'update_taxes')) {
            $order->update_taxes();
        }
        if (method_exists($order, 'calculate_totals')) {
            $order->calculate_totals(false);
        }
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    private function calculate_legacy_totals(array $items, array $feeLines, array $shippingLines): array {
        $itemSubtotal = 0.0;
        $itemTax = 0.0;
        $feeSubtotal = 0.0;
        $feeTax = 0.0;
        $shippingSubtotal = 0.0;
        $shippingTax = 0.0;
        $taxBreakdown = [];

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $qty = max(1, (int) ($item['qty'] ?? 1));
            $productId = (int) (($item['base_variation_id'] ?? 0) ?: ($item['base_product_id'] ?? 0));
            $product = $productId > 0 ? wc_get_product($productId) : false;
            $lineSubtotal = $this->normalize_decimal($this->get_legacy_item_storage_unit_price($item, $product) * $qty);
            $itemSubtotal += $lineSubtotal;

            $itemTaxes = $this->normalize_item_taxes($item['taxes'] ?? []);
            foreach ($this->normalize_tax_group($itemTaxes['total'] ?? []) as $rateKey => $taxAmount) {
                $itemTax += $taxAmount;
                $taxBreakdown[$rateKey] = (float) ($taxBreakdown[$rateKey] ?? 0.0) + $taxAmount;
            }
        }

        foreach ($feeLines as $feeLine) {
            if (!is_array($feeLine)) {
                continue;
            }

            $feeSubtotal += $this->normalize_decimal($feeLine['total'] ?? 0.0);
            foreach ($this->normalize_tax_group($feeLine['taxes']['total'] ?? []) as $rateKey => $taxAmount) {
                $feeTax += $taxAmount;
                $taxBreakdown[$rateKey] = (float) ($taxBreakdown[$rateKey] ?? 0.0) + $taxAmount;
            }
        }

        foreach ($shippingLines as $shippingLine) {
            if (!is_array($shippingLine)) {
                continue;
            }

            $shippingSubtotal += $this->normalize_decimal($shippingLine['total'] ?? 0.0);
            foreach ($this->normalize_tax_group($shippingLine['taxes']['total'] ?? []) as $rateKey => $taxAmount) {
                $shippingTax += $taxAmount;
                $taxBreakdown[$rateKey] = (float) ($taxBreakdown[$rateKey] ?? 0.0) + $taxAmount;
            }
        }

        $subtotal = $itemSubtotal + $feeSubtotal + $shippingSubtotal;
        $tax = $itemTax + $feeTax + $shippingTax;

        return [
            'item_subtotal' => $this->normalize_decimal($itemSubtotal),
            'item_tax' => $this->normalize_decimal($itemTax),
            'item_total' => $this->normalize_decimal($itemSubtotal + $itemTax),
            'fee_subtotal' => $this->normalize_decimal($feeSubtotal),
            'fee_tax' => $this->normalize_decimal($feeTax),
            'fee_total' => $this->normalize_decimal($feeSubtotal + $feeTax),
            'shipping_subtotal' => $this->normalize_decimal($shippingSubtotal),
            'shipping_tax' => $this->normalize_decimal($shippingTax),
            'shipping_total' => $this->normalize_decimal($shippingSubtotal + $shippingTax),
            'subtotal' => $this->normalize_decimal($subtotal),
            'tax' => $this->normalize_decimal($tax),
            'total' => $this->normalize_decimal($subtotal + $tax),
            'tax_breakdown' => array_map([$this, 'normalize_decimal'], $taxBreakdown),
        ];
    }

    private function normalize_tax_group($taxes): array {
        $normalized = [];
        if (!is_array($taxes)) {
            return $normalized;
        }

        foreach ($taxes as $rateKey => $taxAmount) {
            $normalizedKey = is_numeric($rateKey) ? (string) absint((string) $rateKey) : sanitize_key((string) $rateKey);
            if ($normalizedKey === '') {
                $normalizedKey = 'manual';
            }
            $normalized[$normalizedKey] = $this->normalize_decimal($taxAmount);
        }

        return $normalized;
    }

    private function normalize_item_taxes($taxes): array {
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

                $normalized[$group][$normalizedKey] = $this->normalize_decimal($taxAmount);
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

    private function build_tax_customer(array $billing, array $shipping) {
        if (!class_exists('WC_Customer')) {
            return null;
        }

        try {
            $customer = new \WC_Customer(0, true);
        } catch (\Throwable $e) {
            return null;
        }

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

    private function calculate_legacy_item_taxes(array $item, $customer): array {
        $normalized = ['subtotal' => [], 'total' => []];
        $productId = (int) (($item['base_variation_id'] ?? 0) ?: ($item['base_product_id'] ?? 0));
        $product = $productId > 0 ? wc_get_product($productId) : false;
        if (!$product || !is_object($product) || !method_exists($product, 'get_tax_status') || $product->get_tax_status() !== 'taxable') {
            return $normalized;
        }

        $taxClass = method_exists($product, 'get_tax_class') ? (string) $product->get_tax_class() : '';
        try {
            $rates = (array) \WC_Tax::get_rates($taxClass, $customer);
        } catch (\Throwable $e) {
            return $normalized;
        }

        $qty = max(1, (int) ($item['qty'] ?? 1));
        $lineSubtotal = $this->normalize_decimal($this->get_legacy_item_storage_unit_price($item, $product) * $qty);
        if ($lineSubtotal <= 0) {
            return $normalized;
        }

        foreach ($rates as $rateId => $rateData) {
            $amounts = \WC_Tax::calc_tax($lineSubtotal, [(string) $rateId => $rateData], false);
            $rateKey = (string) absint((string) $rateId);
            if ($rateKey === '0') {
                continue;
            }

            $normalized['subtotal'][$rateKey] = isset($amounts[$rateId]) ? $this->normalize_decimal($amounts[$rateId]) : 0.0;
            $normalized['total'][$rateKey] = $normalized['subtotal'][$rateKey];
        }

        return $normalized;
    }

    private function get_legacy_item_storage_unit_price(array $item, $product): float {
        $unitPrice = function_exists('wc_format_decimal')
            ? (float) wc_format_decimal((string) ($item['unit_price'] ?? 0.0))
            : (float) ($item['unit_price'] ?? 0.0);
        if ($unitPrice <= 0) {
            return 0.0;
        }

        if (empty($item['price_includes_tax']) || !function_exists('wc_get_price_excluding_tax') || !$product || !is_object($product)) {
            return $unitPrice;
        }

        return function_exists('wc_format_decimal')
            ? (float) wc_format_decimal((string) wc_get_price_excluding_tax($product, [
                'qty' => 1,
                'price' => $unitPrice,
            ]))
            : (float) wc_get_price_excluding_tax($product, [
            'qty' => 1,
            'price' => $unitPrice,
        ]);
    }

    private function extract_interval_from_scheme(string $scheme, int $fallback = 0): int {
        if (preg_match('/^(\d+)w$/', $scheme, $matches)) {
            return max(1, (int) $matches[1]);
        }

        return $fallback > 0 ? $fallback : 1;
    }

    private function map_order_post_status_to_legacy_status(string $orderStatus): string {
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

    private function map_legacy_status_to_order_post_status(string $legacyStatus): string {
        switch ($legacyStatus) {
            case 'active':
                return 'wc-processing';
            case 'on-hold':
            case 'paused':
                return 'wc-on-hold';
            case 'cancelled':
            case 'expired':
                return 'wc-cancelled';
            case 'payment_pending':
            case 'pending_mandate':
            default:
                return 'wc-pending';
        }
    }

    private function format_decimal($value): string {
        return function_exists('wc_format_decimal')
            ? wc_format_decimal((string) $value, wc_get_price_decimals())
            : number_format((float) $value, 2, '.', '');
    }

    private function normalize_decimal($value): float {
        return function_exists('wc_format_decimal')
            ? (float) wc_format_decimal((string) $value, wc_get_price_decimals())
            : round((float) $value, 2);
    }

    private function log_sync_debug(string $event, array $context = []): void {
        SubscriptionSyncLogger::debug($event, $context);
    }

    private function build_order_debug_snapshot($order): array {
        if (is_numeric($order)) {
            $order = function_exists('wc_get_order') ? wc_get_order((int) $order) : null;
        }

        if (!$order || !is_object($order)) {
            return [];
        }

        return [
            'order_id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
            'order_type' => method_exists($order, 'get_type') ? (string) $order->get_type() : '',
            'post_status' => method_exists($order, 'get_status') ? (string) $order->get_status() : '',
            'legacy_post_id' => $this->get_linked_legacy_post_id($order),
            'subscription_status' => method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_status', true) : '',
            'legacy_status_meta_on_order' => method_exists($order, 'get_meta') ? (string) $order->get_meta(self::LEGACY_STATUS_META, true) : '',
            'scheme' => method_exists($order, 'get_meta') ? (string) $order->get_meta('_hb_ucs_subscription_scheme', true) : '',
            'legacy_scheme_meta_on_order' => method_exists($order, 'get_meta') ? (string) $order->get_meta(self::LEGACY_SCHEME_META, true) : '',
            'next_payment' => method_exists($order, 'get_meta') ? (int) $order->get_meta('_hb_ucs_subscription_next_payment', true) : 0,
            'legacy_next_payment_meta_on_order' => method_exists($order, 'get_meta') ? (int) $order->get_meta(self::LEGACY_NEXT_PAYMENT_META, true) : 0,
        ];
    }

    private function build_legacy_debug_snapshot(int $legacyPostId, array $legacy = []): array {
        if ($legacyPostId <= 0 && empty($legacy)) {
            return [];
        }

        $legacy = !empty($legacy) ? $legacy : $this->get_legacy_subscription_data($legacyPostId);
        if (empty($legacy)) {
            return [
                'legacy_post_id' => $legacyPostId,
                'exists' => false,
            ];
        }

        return [
            'legacy_post_id' => (int) ($legacy['legacy_post_id'] ?? ($legacy['id'] ?? $legacyPostId)),
            'status' => (string) ($legacy['status'] ?? ''),
            'scheme' => (string) ($legacy['scheme'] ?? ''),
            'next_payment' => (int) ($legacy['next_payment'] ?? 0),
            'trial_end' => (int) ($legacy['trial_end'] ?? 0),
            'end_date' => (int) ($legacy['end_date'] ?? 0),
            'customer_id' => (int) ($legacy['customer_id'] ?? 0),
        ];
    }
}
