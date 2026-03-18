<?php

namespace HB\UCS\Modules\Subscriptions\Domain;

use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;

if (!defined('ABSPATH')) exit;

class SubscriptionService {
    /** @var SubscriptionRepository */
    private $repository;

    public function __construct(?SubscriptionRepository $repository = null) {
        $this->repository = $repository ?: new SubscriptionRepository();
    }

    public function get_repository(): SubscriptionRepository {
        return $this->repository;
    }

    public function is_subscription(int $subscriptionId): bool {
        return $this->repository->exists($subscriptionId);
    }

    public function get_admin_edit_url(int $subscriptionId): string {
        return $this->repository->get_admin_edit_url($subscriptionId);
    }

    public function get_migration_seed(int $subscriptionId): array {
        $seed = $this->repository->get_migration_seed($subscriptionId);
        if (empty($seed)) {
            return [];
        }

        $seed['target_order_type'] = SubscriptionOrderType::TYPE;
        $seed['can_use_woocommerce_screen'] = class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil');
        $seed['recommended_storage_version'] = SubscriptionOrderType::PHASE2_STORAGE_VERSION;

        return $seed;
    }

    public function get_existing_order_type_record(int $legacySubscriptionId): ?array {
        return $this->repository->find_by_legacy_post_id($legacySubscriptionId);
    }

    public function ensure_order_type_record(int $legacySubscriptionId): ?array {
        return $this->repository->ensure_order_type_record($legacySubscriptionId, true);
    }

    public function get_preferred_editor_url(int $subscriptionId): string {
        $record = $this->repository->find($subscriptionId);
        if (!$record) {
            return '';
        }

        if (($record['storage'] ?? '') === 'order_type') {
            return (string) ($record['edit_url'] ?? '');
        }

        $newRecord = $this->get_existing_order_type_record($subscriptionId);
        if (is_array($newRecord) && !empty($newRecord['edit_url'])) {
            return (string) $newRecord['edit_url'];
        }

        return (string) ($record['edit_url'] ?? '');
    }
}
