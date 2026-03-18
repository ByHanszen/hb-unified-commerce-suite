<?php

namespace HB\UCS\Modules\Subscriptions\DataStores;

use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore;
use Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta;
use Automattic\WooCommerce\Internal\Utilities\DatabaseUtil;
use Automattic\WooCommerce\Proxies\LegacyProxy;
use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;

if (!defined('ABSPATH')) exit;

class HybridOrderDataStore extends OrdersTableDataStore {
    /** @var SubscriptionOrderDataStoreCPT|null */
    private $subscriptionCptStore = null;

    public function __construct() {
        if (!function_exists('wc_get_container')) {
            return;
        }

        $container = wc_get_container();

        $this->init(
            $container->get(OrdersTableDataStoreMeta::class),
            $container->get(DatabaseUtil::class),
            $container->get(LegacyProxy::class)
        );
    }

    public function get_order_type($order_id) {
        $orderType = parent::get_order_type($order_id);
        if ($orderType !== '') {
            return $orderType;
        }

        $postType = get_post_type($order_id);
        return $postType === SubscriptionOrderType::TYPE ? $postType : '';
    }

    public function get_orders_type($order_ids) {
        $orderTypes = parent::get_orders_type($order_ids);

        foreach ((array) $order_ids as $orderId) {
            $orderId = (int) $orderId;
            if ($orderId <= 0 || isset($orderTypes[$orderId])) {
                continue;
            }

            $postType = get_post_type($orderId);
            if ($postType === SubscriptionOrderType::TYPE) {
                $orderTypes[$orderId] = $postType;
            }
        }

        return $orderTypes;
    }

    public function query($query_vars) {
        $type = $query_vars['type'] ?? '';

        if ($this->is_subscription_type_query($type)) {
            return $this->get_subscription_cpt_store()->query($query_vars);
        }

        return parent::query($query_vars);
    }

    private function is_subscription_type_query($type): bool {
        if (is_array($type)) {
            $types = array_values(array_filter(array_map('strval', $type)));
            return count($types) === 1 && $types[0] === SubscriptionOrderType::TYPE;
        }

        return (string) $type === SubscriptionOrderType::TYPE;
    }

    private function get_subscription_cpt_store(): SubscriptionOrderDataStoreCPT {
        if ($this->subscriptionCptStore === null) {
            $this->subscriptionCptStore = new SubscriptionOrderDataStoreCPT();
        }

        return $this->subscriptionCptStore;
    }
}