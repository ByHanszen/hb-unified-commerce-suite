<?php
/**
 * Plugin Name: HB Unified Commerce Suite
 * Description: Overkoepelende plugin met modulaire features.
 * Version: 0.5.6
 * Author: Hoeksche Branders
 * Text Domain: hb-ucs
 */
if (!defined('ABSPATH')) exit;

if (!defined('HB_UCS_PLUGIN_FILE')) {
    define('HB_UCS_PLUGIN_FILE', __FILE__);
}
if (!defined('HB_UCS_VERSION')) {
    define('HB_UCS_VERSION', '0.5.6');
}

add_action('before_woocommerce_init', function () {
    if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', HB_UCS_PLUGIN_FILE, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', HB_UCS_PLUGIN_FILE, true);
    }
});

spl_autoload_register(function ($class) {
    $prefix = 'HB\\UCS\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));                 // e.g. "Core\Kernel"
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (file_exists($path)) require_once $path;
});

function hb_ucs_is_subscription_order_items_ajax(): bool {
    $action = isset($_REQUEST['action']) ? sanitize_key((string) wp_unslash($_REQUEST['action'])) : '';
    if ($action !== 'woocommerce_save_order_items') {
        return false;
    }

    $orderId = isset($_REQUEST['order_id']) ? absint((string) wp_unslash($_REQUEST['order_id'])) : 0;
    $subscriptionType = 'shop_subscription_hb';
    $isSubscriptionOrder = $orderId > 0 && get_post_type($orderId) === $subscriptionType;
    $referer = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
    $postedItems = [];

    if (isset($_REQUEST['items']) && is_string($_REQUEST['items'])) {
        parse_str(wp_unslash($_REQUEST['items']), $postedItems);
    }

    $isSubscriptionEditorRequest = isset($postedItems['hb_ucs_shipping_lines_present'])
        || isset($postedItems['hb_ucs_items_present'])
        || isset($postedItems['hb_ucs_fees_present']);

    if (!$isSubscriptionOrder && !$isSubscriptionEditorRequest && strpos($referer, 'page=wc-orders--' . $subscriptionType) !== false) {
        $isSubscriptionOrder = true;
    }

    return $isSubscriptionOrder || $isSubscriptionEditorRequest;
}

function hb_ucs_handle_subscription_order_items_ajax(): void {
    if (!current_user_can('edit_shop_orders')) {
        wp_die(-1);
    }

    check_ajax_referer('order-item', 'security');
    wp_send_json_success(['html' => '', 'notes_html' => '']);
}

add_action('init', function () {
    if (!wp_doing_ajax() || !hb_ucs_is_subscription_order_items_ajax()) {
        return;
    }

    remove_all_actions('wp_ajax_woocommerce_save_order_items');
    add_action('wp_ajax_woocommerce_save_order_items', 'hb_ucs_handle_subscription_order_items_ajax', 1);
}, 0);

register_activation_hook(__FILE__, function () {
    // Defaults voor hoofdopties
    $defaults = [
        'modules' => [
            'invoice_email' => false,
            'qls'           => true,
            'b2b'           => false,
            'roles'         => false,
            'customer_order_note' => false,
            'subscriptions' => false,
            'bundles'       => false,
            'order_overview_status' => false,
            'returns'       => false,
            'product_pages' => false,
            'product_search' => false,
        ],
    ];
    $opt = get_option('hb_ucs_settings', []);
    update_option('hb_ucs_settings', array_replace_recursive($defaults, is_array($opt)?$opt:[]));

    if (class_exists('HB\\UCS\\Core\\Settings')) {
        (new \HB\UCS\Core\Settings())->seed_default_options();
    }

    // Store current plugin version for update tracking.
    if (defined('HB_UCS_VERSION')) {
        update_option('hb_ucs_version', HB_UCS_VERSION);
    }

    if (class_exists('HB\\UCS\\Modules\\ProductSearch\\ProductSearchModule')) {
        \HB\UCS\Modules\ProductSearch\ProductSearchModule::activate();
    }
});

register_deactivation_hook(__FILE__, function () {
    if (class_exists('HB\\UCS\\Modules\\ProductSearch\\ProductSearchModule')) {
        \HB\UCS\Modules\ProductSearch\ProductSearchModule::deactivate();
    }
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('hb-ucs', false, basename(__DIR__) . '/languages');

    // Track updates and show a one-time admin notice linking to release notes.
    if (is_admin() && defined('HB_UCS_VERSION')) {
        $stored = (string) get_option('hb_ucs_version', '');
        if ($stored !== HB_UCS_VERSION) {
            update_option('hb_ucs_version', HB_UCS_VERSION);
            set_transient('hb_ucs_version_updated', [
                'from' => $stored,
                'to' => HB_UCS_VERSION,
            ], DAY_IN_SECONDS);
        }

        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) return;
            $data = get_transient('hb_ucs_version_updated');
            if (!is_array($data) || empty($data['to'])) return;
            delete_transient('hb_ucs_version_updated');

            $to = (string) ($data['to'] ?? '');
            $from = (string) ($data['from'] ?? '');
            $url = admin_url('admin.php?page=hb-ucs-release-notes');

            echo '<div class="notice notice-success is-dismissible"><p>';
            if ($from !== '') {
                echo sprintf(
                    esc_html__('HB Unified Commerce Suite is bijgewerkt van %1$s naar %2$s. Bekijk de release notes voor details.', 'hb-ucs'),
                    esc_html($from),
                    esc_html($to)
                );
            } else {
                echo sprintf(
                    esc_html__('HB Unified Commerce Suite is bijgewerkt naar %s. Bekijk de release notes voor details.', 'hb-ucs'),
                    esc_html($to)
                );
            }
            echo ' <a href="' . esc_url($url) . '">' . esc_html__('Release notes', 'hb-ucs') . '</a>';
            echo '</p></div>';
        });
    }

    // Kernel start (roept Settings->init() aan)
    if (class_exists('HB\\UCS\\Core\\Kernel')) {
        (new \HB\UCS\Core\Kernel())->boot();
    }

    if (defined('WP_CLI') && WP_CLI && class_exists('HB\\UCS\\Modules\\Subscriptions\\Cli\\SubscriptionMetaBackfillCommand')) {
        \WP_CLI::add_command('hb-ucs subscriptions backfill-order-meta', 'HB\\UCS\\Modules\\Subscriptions\\Cli\\SubscriptionMetaBackfillCommand');
    }
});
