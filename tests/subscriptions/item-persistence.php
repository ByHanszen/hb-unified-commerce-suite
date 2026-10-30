<?php
/** Local integration regression test. Fixture writes are rolled back. Run with PHP CLI. */
if (PHP_SAPI !== 'cli') {
    exit;
}
require dirname(__DIR__, 5) . '/wp-load.php';
require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';

use HB\UCS\Modules\Subscriptions\Admin\SubscriptionAdmin;
use HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository;
use HB\UCS\Modules\Subscriptions\Domain\SubscriptionService;
use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;
use HB\UCS\Modules\Subscriptions\Orders\HB_UCS_Subscription_Order;

$assert = static function ($actual, $expected, $label) {
    if (abs((float) $actual - (float) $expected) > 0.001) {
        throw new RuntimeException("$label: expected $expected, got $actual");
    }
};
$repository = new SubscriptionRepository();
$type = new SubscriptionOrderType($repository);
$type->init();
$type->register();
$admin = new SubscriptionAdmin(new SubscriptionService($repository), $type);
// CLI does not initialize the admin hooks automatically.
add_action('woocommerce_before_save_order_items', [$admin, 'capture_subscription_item_prices'], 1, 2);
add_action('woocommerce_before_save_order_item', [$admin, 'preserve_subscription_item_prices'], 100);
add_action('woocommerce_saved_order_items', [$admin, 'recalculate_saved_subscription_items'], 100, 2);
add_filter('pre_option_woocommerce_calc_taxes', static function () { return 'yes'; });
add_filter('pre_option_woocommerce_tax_based_on', static function () { return 'shipping'; });

global $wpdb;
$wpdb->query('START TRANSACTION');
try {
    $rateId = WC_Tax::_insert_tax_rate([
        'tax_rate_country' => 'ZZ', 'tax_rate_state' => '', 'tax_rate' => '21.0000',
        'tax_rate_name' => 'HB test', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0,
        'tax_rate_shipping' => 1, 'tax_rate_order' => 0, 'tax_rate_class' => '',
    ]);
    $product = new WC_Product_Simple();
    $product->set_name('HB subscription persistence test');
    $product->set_regular_price('999'); // Catalog changes must not overwrite agreed prices.
    $product->set_tax_status('taxable');
    $product->save();
    $order = new HB_UCS_Subscription_Order();
    $order->set_address(['country' => 'ZZ'], 'shipping');
    $order->save();
    $item = new WC_Order_Item_Product();
    $item->set_product($product);
    $item->set_quantity(2);
    $item->set_subtotal(20);
    $item->set_total(16);
    $order->add_item($item);
    $order->calculate_totals(true);
    $order->save();
    $orderId = $order->get_id();
    $itemId = $item->get_id();

    // Standard AJAX and Update both use wc_save_order_items().
    $save = static function ($qty, $subtotal, $total) use ($orderId, $itemId) {
        wc_save_order_items($orderId, [
            'order_item_id' => [$itemId], 'order_item_qty' => [$itemId => (string) $qty],
            'line_subtotal' => [$itemId => (string) $subtotal], 'line_total' => [$itemId => (string) $total],
        ]);
        return wc_get_order($orderId);
    };
    $order = $save(3, 20, 16); // Quantity changed; unchanged/stale posted amounts.
    $line = $order->get_item($itemId);
    $assert($line->get_subtotal(), 30, 'Agreed subtotal');
    $assert($line->get_total(), 24, 'Discounted total');
    $assert($line->get_total_tax(), 5.04, 'Recalculated VAT');
    $assert($order->get_total(), 29.04, 'Subscription total');
    $data = $repository->get_order_type_subscription_data($order);
    $assert($data['items'][0]['qty'], 3, 'Renewal quantity');
    $assert($data['items'][0]['unit_price'], 8, 'Renewal agreed price');
    $assert($data['items'][0]['taxes']['total'][$rateId], 5.04, 'Renewal VAT');
    $stored = get_post_meta($orderId, SubscriptionRepository::LEGACY_ITEMS_META, true);
    $assert($stored[0]['qty'], 3, 'Synchronized renewal quantity');
    $assert($stored[0]['unit_price'], 8, 'Synchronized renewal price');
    $assert($stored[0]['taxes']['total'][$rateId], 5.04, 'Synchronized renewal VAT');

    $order = $save(4, 40, 32); // Core JS already scaled the amounts.
    $assert($order->get_total(), 38.72, 'No double scaling');
    $order = $save(4, 40, 32);
    $assert($order->get_total(), 38.72, 'Repeated save is stable');

    // Custom editor shares the same tax calculation and keeps the discount.
    $custom = new ReflectionMethod($admin, 'persist_posted_subscription_items');
    $custom->setAccessible(true);
    $_POST = ['hb_ucs_items' => [['product_id' => $product->get_id(), 'qty' => 5, 'unit_price' => 8]]];
    $custom->invoke($admin, $orderId, $order);
    $order->save();
    $admin->recalculate_saved_subscription_items($orderId, []);
    $order = wc_get_order($orderId);
    $line = current($order->get_items('line_item'));
    $assert($line->get_subtotal(), 50, 'Custom editor subtotal');
    $assert($line->get_total(), 40, 'Custom editor discount');
    $assert($line->get_total_tax(), 8.4, 'Custom editor VAT');
    $assert($order->get_total(), 48.4, 'Custom editor total');

    $order->update_meta_data('is_vat_exempt', 'yes');
    $order->save();
    $admin->recalculate_saved_subscription_items($orderId, []);
    $assert(wc_get_order($orderId)->get_total(), 40, 'VAT exemption');

    $ordinary = new WC_Order();
    $ordinary->save();
    $ordinaryItem = new WC_Order_Item_Product();
    $ordinaryItem->set_product($product);
    $ordinaryItem->set_quantity(2);
    $ordinaryItem->set_subtotal(20);
    $ordinaryItem->set_total(16);
    $ordinary->add_item($ordinaryItem);
    $ordinary->save();
    $ordinaryItemId = $ordinaryItem->get_id();
    wc_save_order_items($ordinary->get_id(), [
        'order_item_id' => [$ordinaryItemId], 'order_item_qty' => [$ordinaryItemId => '3'],
        'line_subtotal' => [$ordinaryItemId => '20'], 'line_total' => [$ordinaryItemId => '16'],
    ]);
    $assert(wc_get_order($ordinary->get_id())->get_item($ordinaryItemId)->get_total(), 16, 'Ordinary order unchanged');
    echo "PASS: subscription item persistence, discounts, VAT and renewal data\n";
} finally {
    $_POST = [];
    $wpdb->query('ROLLBACK');
}
