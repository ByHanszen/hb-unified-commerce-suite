<?php

namespace HB\UCS\Modules\Subscriptions\Cli;

use HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository;
use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;

if (!defined('ABSPATH')) exit;

class SubscriptionMetaBackfillCommand {
    private const META_MAP = [
        SubscriptionRepository::LEGACY_STATUS_META => '_hb_ucs_subscription_status',
        SubscriptionRepository::LEGACY_SCHEME_META => '_hb_ucs_subscription_scheme',
        SubscriptionRepository::LEGACY_NEXT_PAYMENT_META => '_hb_ucs_subscription_next_payment',
        SubscriptionRepository::LEGACY_TRIAL_END_META => '_hb_ucs_subscription_trial_end',
        SubscriptionRepository::LEGACY_END_DATE_META => '_hb_ucs_subscription_end_date',
    ];

    /**
     * Backfill canonical subscription order meta from legacy-named meta keys on the same order record.
     *
     * ## OPTIONS
     *
     * [--execute]
     * : Actually write the changes. Without this flag the command runs as a dry run.
     *
     * [--ids=<ids>]
     * : Comma-separated subscription order ids to process.
     *
     * [--limit=<number>]
     * : Maximum number of subscription orders to inspect when --ids is not used.
     *
     * ## EXAMPLES
     *
     *     wp hb-ucs subscriptions backfill-order-meta
     *     wp hb-ucs subscriptions backfill-order-meta --execute
     *     wp hb-ucs subscriptions backfill-order-meta --ids=123,456 --execute
     *
     * @param array<int, string> $args
     * @param array<string, mixed> $assocArgs
     */
    public function __invoke(array $args, array $assocArgs): void {
        if (!class_exists('WooCommerce') || !function_exists('wc_get_orders') || !function_exists('wc_get_order')) {
            \WP_CLI::error('WooCommerce is not available.');
        }

        $execute = !empty($assocArgs['execute']);
        $limit = isset($assocArgs['limit']) ? max(1, (int) $assocArgs['limit']) : 0;
        $orderIds = $this->resolve_order_ids(isset($assocArgs['ids']) ? (string) $assocArgs['ids'] : '', $limit);

        if (empty($orderIds)) {
            \WP_CLI::success('No subscription orders found to inspect.');
            return;
        }

        $inspected = 0;
        $changedOrders = 0;
        $updatedMeta = 0;
        $rows = [];

        foreach ($orderIds as $orderId) {
            $order = wc_get_order($orderId);
            if (!$order || !is_object($order) || !method_exists($order, 'get_type') || (string) $order->get_type() !== SubscriptionOrderType::TYPE) {
                continue;
            }

            $inspected++;
            $changes = $this->collect_meta_changes($order);
            if (empty($changes)) {
                continue;
            }

            $changedOrders++;
            $updatedMeta += count($changes);

            $rows[] = [
                'order_id' => $orderId,
                'changes' => implode(', ', array_keys($changes)),
                'mode' => $execute ? 'execute' : 'dry-run',
            ];

            if (!$execute) {
                continue;
            }

            foreach ($changes as $metaKey => $metaValue) {
                $order->update_meta_data($metaKey, $metaValue);
            }

            $storageVersion = (string) $order->get_meta(SubscriptionOrderType::STORAGE_VERSION_META, true);
            if ($storageVersion !== SubscriptionOrderType::PHASE2_STORAGE_VERSION) {
                $order->update_meta_data(SubscriptionOrderType::STORAGE_VERSION_META, SubscriptionOrderType::PHASE2_STORAGE_VERSION);
            }

            $order->save();
        }

        if (!empty($rows)) {
            \WP_CLI\Utils\format_items('table', $rows, ['order_id', 'changes', 'mode']);
        }

        $summary = sprintf(
            'Inspected %1$d subscription orders, found %2$d orders with %3$d missing canonical meta values.%4$s',
            $inspected,
            $changedOrders,
            $updatedMeta,
            $execute ? ' Changes have been saved.' : ' Dry run only; no changes saved.'
        );

        \WP_CLI::success($summary);
    }

    /**
     * @return int[]
     */
    private function resolve_order_ids(string $idsRaw, int $limit): array {
        if ($idsRaw !== '') {
            return array_values(array_unique(array_filter(array_map('intval', array_map('trim', explode(',', $idsRaw))))));
        }

        $queryArgs = [
            'type' => SubscriptionOrderType::TYPE,
            'return' => 'ids',
            'limit' => $limit > 0 ? $limit : -1,
        ];

        $orderIds = wc_get_orders($queryArgs);
        if (!is_array($orderIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $orderIds))));
    }

    /**
     * @param object $order
     * @return array<string, mixed>
     */
    private function collect_meta_changes($order): array {
        $changes = [];

        foreach (self::META_MAP as $legacyKey => $canonicalKey) {
            $canonicalValue = $order->get_meta($canonicalKey, true);
            if ($this->has_meaningful_value($canonicalValue)) {
                continue;
            }

            $legacyValue = $order->get_meta($legacyKey, true);
            if (!$this->has_meaningful_value($legacyValue)) {
                continue;
            }

            $changes[$canonicalKey] = $legacyValue;
        }

        return $changes;
    }

    /**
     * @param mixed $value
     */
    private function has_meaningful_value($value): bool {
        if (is_array($value)) {
            return !empty($value);
        }

        if (is_numeric($value)) {
            return (string) $value !== '0';
        }

        return $value !== null && $value !== '';
    }
}