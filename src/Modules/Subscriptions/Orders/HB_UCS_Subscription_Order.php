<?php

namespace HB\UCS\Modules\Subscriptions\Orders;

use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;

if (!defined('ABSPATH')) exit;

class HB_UCS_Subscription_Order extends \WC_Order {
    protected $data_store_name = SubscriptionOrderType::DATA_STORE;

    public function get_type(): string {
        return SubscriptionOrderType::TYPE;
    }

    public function is_subscription(): bool {
        return true;
    }

    public function get_legacy_post_id(string $context = 'view'): int {
        return (int) $this->get_meta(SubscriptionOrderType::LEGACY_POST_ID_META, true, $context);
    }

    public function set_legacy_post_id(int $legacyPostId): void {
        $this->update_meta_data(SubscriptionOrderType::LEGACY_POST_ID_META, $legacyPostId > 0 ? $legacyPostId : '');
    }

    public function get_storage_version(string $context = 'view'): string {
        return (string) $this->get_meta(SubscriptionOrderType::STORAGE_VERSION_META, true, $context);
    }

    public function set_storage_version(string $version): void {
        $this->update_meta_data(SubscriptionOrderType::STORAGE_VERSION_META, sanitize_key($version));
    }

    public function get_subscription_status(string $context = 'view'): string {
        return (string) $this->get_meta('_hb_ucs_subscription_status', true, $context);
    }

    public function set_subscription_status(string $status): void {
        $this->update_meta_data('_hb_ucs_subscription_status', sanitize_key($status));
    }

    public function get_subscription_scheme(string $context = 'view'): string {
        return (string) $this->get_meta('_hb_ucs_subscription_scheme', true, $context);
    }

    public function set_subscription_scheme(string $scheme): void {
        $this->update_meta_data('_hb_ucs_subscription_scheme', sanitize_key($scheme));
    }

    public function get_next_payment_timestamp(string $context = 'view'): int {
        return (int) $this->get_meta('_hb_ucs_subscription_next_payment', true, $context);
    }

    public function set_next_payment_timestamp(int $timestamp): void {
        $this->update_meta_data('_hb_ucs_subscription_next_payment', max(0, $timestamp));
    }
}
