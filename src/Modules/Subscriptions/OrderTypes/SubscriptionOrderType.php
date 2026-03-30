<?php

namespace HB\UCS\Modules\Subscriptions\OrderTypes;

if (!defined('ABSPATH')) exit;

class SubscriptionOrderType {
    public const TYPE = 'shop_subscription_hb';
    public const DATA_STORE = 'hb-ucs-subscription-order';
    public const LEGACY_POST_ID_META = '_hb_ucs_legacy_subscription_post_id';
    public const STORAGE_VERSION_META = '_hb_ucs_subscription_storage_version';
    public const PHASE2_STORAGE_VERSION = 'phase2-cpt-adapter';

    /** @var \HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository */
    private $repository;

    public function __construct(?\HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository $repository = null) {
        $this->repository = $repository ?: new \HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository();
    }

    public function init(): void {
        add_filter('woocommerce_data_stores', [$this, 'register_data_store']);
        add_filter('woocommerce_order_data_store', [$this, 'register_hybrid_order_data_store'], 1001);
        add_action('init', [$this, 'register'], 11);
    }

    public function get_type(): string {
        return self::TYPE;
    }

    public function is_registered(): bool {
        return post_type_exists(self::TYPE);
    }

    public function register_data_store(array $stores): array {
        $stores[self::DATA_STORE] = 'HB\\UCS\\Modules\\Subscriptions\\DataStores\\SubscriptionOrderDataStoreCPT';

        return $stores;
    }

    public function register_hybrid_order_data_store($store) {
        if (!class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            return $store;
        }

        if (!\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            return $store;
        }

        return 'HB\\UCS\\Modules\\Subscriptions\\DataStores\\HybridOrderDataStore';
    }

    public function register(): void {
        if (!function_exists('wc_register_order_type') || $this->is_registered()) {
            return;
        }

        wc_register_order_type(self::TYPE, apply_filters('hb_ucs_register_order_type_subscription', [
            'labels' => [
                'name' => __('Abonnementen', 'hb-ucs'),
                'singular_name' => _x('Abonnement', 'subscription order type singular name', 'hb-ucs'),
                'add_new' => __('Abonnement toevoegen', 'hb-ucs'),
                'add_new_item' => __('Nieuw abonnement toevoegen', 'hb-ucs'),
                'edit_item' => __('Abonnement bewerken', 'hb-ucs'),
                'new_item' => __('Nieuw abonnement', 'hb-ucs'),
                'view_item' => __('Abonnement bekijken', 'hb-ucs'),
                'search_items' => __('Zoek abonnementen', 'hb-ucs'),
                'not_found' => __('Geen abonnementen gevonden', 'hb-ucs'),
                'not_found_in_trash' => __('Geen abonnementen gevonden in prullenbak', 'hb-ucs'),
                'menu_name' => _x('Abonnementen', 'Admin menu name', 'hb-ucs'),
            ],
            'description' => __('WooCommerce order type voor abonnementen.', 'hb-ucs'),
            'public' => false,
            'show_ui' => true,
            'capability_type' => 'shop_order',
            'map_meta_cap' => true,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_in_menu' => 'woocommerce',
            'hierarchical' => false,
            'show_in_nav_menus' => false,
            'rewrite' => false,
            'query_var' => false,
            'supports' => ['title', 'comments', 'custom-fields'],
            'has_archive' => false,
            'class_name' => 'HB\\UCS\\Modules\\Subscriptions\\Orders\\HB_UCS_Subscription_Order',
            'add_order_meta_boxes' => true,
            'exclude_from_order_count' => true,
            'exclude_from_order_views' => true,
            'exclude_from_order_webhooks' => true,
            'exclude_from_order_reports' => true,
            'exclude_from_order_sales_reports' => true,
        ]));
    }
}
