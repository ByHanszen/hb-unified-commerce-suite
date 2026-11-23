<?php
/** Full renewal/retry regression with a mocked Mollie transport and rollback. */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__, 5) . '/wp-load.php';

use HB\UCS\Modules\Subscriptions\SubscriptionsModule;
use HB\UCS\Modules\Subscriptions\Domain\SubscriptionRepository;
use HB\UCS\Modules\Subscriptions\OrderTypes\SubscriptionOrderType;
use HB\UCS\Modules\Subscriptions\Orders\HB_UCS_Subscription_Order;
use HB\UCS\Core\Settings;

$assert = static function ($condition, $message) { if (!$condition) throw new RuntimeException($message); };
global $wpdb;
$wpdb->query('START TRANSACTION');
$requests = [];
$mock = static function ($preempt, $args, $url) use (&$requests) {
    if ($url !== 'https://api.mollie.com/v2/payments' || $args['method'] !== 'POST') return new WP_Error('audit_unexpected_http', 'Unexpected HTTP request blocked');
    $requests[] = $args;
    if (count($requests) === 1) return new WP_Error('http_request_failed', 'Simulated network timeout');
    return ['headers' => [], 'response' => ['code' => 201, 'message' => 'Created'], 'body' => json_encode(['id' => 'tr_audit_renewal', 'status' => 'pending'])];
};
$noMail = static function () { return true; };
add_filter('pre_http_request', $mock, PHP_INT_MAX, 3);
add_filter('pre_wp_mail', $noMail);
try {
    update_option(Settings::OPT_SUBSCRIPTIONS, ['recurring_enabled' => 1, 'recurring_webhook_token' => 'audit-webhook-token', 'frequencies' => ['1w' => ['enabled' => 1]]]);
    update_option('mollie-payments-for-woocommerce_test_mode_enabled', 'yes');
    update_option('mollie-payments-for-woocommerce_test_api_key', 'test_audit_not_a_real_key');
    $repository = new SubscriptionRepository();
    $type = new SubscriptionOrderType($repository);
    $type->init();
    $type->register();
    $product = new WC_Product_Simple();
    $product->set_name('Renewal test');
    $product->set_regular_price(10);
    $product->save();
    $subscription = new HB_UCS_Subscription_Order();
    $subscription->set_subscription_status('active');
    $subscription->set_subscription_scheme('1w');
    $subscription->set_payment_method('mollie_wc_gateway_directdebit');
    $due = time() - HOUR_IN_SECONDS;
    $subscription->set_next_payment_timestamp($due);
    $subscription->update_meta_data('_hb_ucs_sub_payment_method', 'mollie_wc_gateway_directdebit');
    $subscription->update_meta_data('_hb_ucs_sub_mollie_customer_id', 'cst_audit');
    $subscription->update_meta_data('_hb_ucs_sub_mollie_mandate_id', 'mdt_audit');
    $subscription->update_meta_data('_hb_ucs_sub_scheme', '1w');
    $line = new WC_Order_Item_Product();
    $line->set_product($product);
    $line->set_quantity(2);
    $line->set_subtotal(20);
    $line->set_total(16);
    $subscription->add_item($line);
    $subscription->calculate_totals(false);
    $subscription->save();
    $subId = $subscription->get_id();
    $repository->sync_order_type_self($subId, false);
    $module = new SubscriptionsModule();
    $dueBatch = new ReflectionMethod($module, 'get_due_renewal_subscription_batch');
    $recoverableBatch = new ReflectionMethod($module, 'get_recoverable_due_subscription_batch');
    $mandateBatch = new ReflectionMethod($module, 'get_pending_mandate_subscription_batch');
    $assert(in_array($subId, $dueBatch->invoke($module, time(), 100), true), 'Due active subscription is selected');
    $assert(!in_array($subId, $recoverableBatch->invoke($module, time(), 100), true), 'Active subscription is excluded from recovery');
    $assert(!in_array($subId, $mandateBatch->invoke($module, 100), true), 'Active subscription is excluded from mandate activation');
    $renew = new ReflectionMethod($module, 'create_renewal_order_and_payment');
    $renew->setAccessible(true);
    $result = $renew->invoke($module, $subId, true);
    $assert(is_wp_error($result), 'First attempt should report the simulated error: ' . var_export($result, true));
    $assert(count($requests) === 1, 'First attempt reaches mocked Mollie');
    $firstOrderId = (int) get_post_meta($subId, '_hb_ucs_sub_last_order_id', true);
    $assert($firstOrderId > 0, 'Failed attempt retains one renewal order');
    $storedRequest = wc_get_order($firstOrderId)->get_meta(\HB\UCS\Modules\Subscriptions\Payments\RenewalPaymentService::REQUEST, true);
    $assert(strpos(wp_json_encode($storedRequest), 'audit-webhook-token') === false, 'Webhook secret must not be stored in readable order metadata');
    $assert((int) get_post_meta($subId, '_hb_ucs_sub_next_payment', true) === $due, 'Failed attempt must not advance next payment');
    $assert((string) get_post_meta($subId, '_hb_ucs_sub_status', true) === 'payment_pending', 'Creation failure remains recoverable: ' . get_post_meta($subId, '_hb_ucs_sub_status', true));
    $assert(in_array($subId, $recoverableBatch->invoke($module, time(), 100), true), 'Due payment creation failure is selected for recovery');
    $result = $renew->invoke($module, $subId, true);
    $assert($result === $firstOrderId, 'Retry uses the same renewal order: ' . (is_wp_error($result) ? $result->get_error_message() : var_export($result, true)));
    $assert(count($requests) === 2, 'Exactly one retry');
    $assert($requests[0]['headers']['Idempotency-Key'] === $requests[1]['headers']['Idempotency-Key'], 'Retry uses same idempotency key');
    $assert($requests[0]['body'] === $requests[1]['body'], 'Retry uses identical payload');
    $assert(wc_get_order($firstOrderId)->get_meta('_hb_ucs_mollie_payment_id', true) === 'tr_audit_renewal', 'Payment stored on same order');
    $assert((int) get_post_meta($subId, '_hb_ucs_sub_next_payment', true) > time(), 'Accepted payment advances subscription period');
    $result = $renew->invoke($module, $subId, true);
    $assert($result === $firstOrderId && count($requests) === 2, 'Further retries do not create orders or payments');
    $orders = wc_get_orders(['limit' => -1, 'meta_query' => [['key' => '_hb_ucs_subscription_id', 'value' => (string) $subId], ['key' => '_hb_ucs_subscription_renewal', 'value' => '1']]]);
    $assert(count($orders) === 1, 'One renewal order across failure and recovery');
    $nextPayment = (int) get_post_meta($subId, '_hb_ucs_sub_next_payment', true);
    $assert(!in_array($subId, $recoverableBatch->invoke($module, time(), 100), true), 'A pending payment with a future date is excluded from recovery');
    $assert(!in_array($subId, $mandateBatch->invoke($module, 100), true), 'Pending payment is not a pending mandate');

    $notesMatching = static function (int $orderId, string $text): int {
        $notes = wc_get_order_notes(['order_id' => $orderId]);
        return count(array_filter($notes, static function ($note) use ($text) {
            return strpos($note->content, $text) !== false;
        }));
    };
    for ($i = 0; $i < 3; $i++) {
        $module->process_due_renewals();
        $renew->invoke($module, $subId, true);
    }
    $assert(count($requests) === 2, 'Repeated cron/manual requests do not create another payment');
    $assert($notesMatching($firstOrderId, 'Mollie recurring betaling gekoppeld') === 1, 'Payment linkage is logged exactly once');
    $assert((string) get_post_meta($subId, '_hb_ucs_sub_status', true) === 'payment_pending', 'Cron keeps the subscription pending while Mollie is pending');
    $assert((int) get_post_meta($subId, '_hb_ucs_sub_next_payment', true) === $nextPayment, 'Repeated requests keep the next payment date');

    $applyStatus = new ReflectionMethod($module, 'apply_mollie_renewal_payment_status');
    $applyStatus->invoke($module, $firstOrderId, 'tr_audit_other_payment', 'paid');
    $assert(wc_get_order($firstOrderId)->has_status('on-hold'), 'A mismatched payment cannot complete the renewal');
    $applyStatus->invoke($module, $firstOrderId, 'tr_audit_renewal', 'pending');
    $assert(wc_get_order($firstOrderId)->has_status('on-hold'), 'Pending Mollie status leaves the order on hold');
    $applyStatus->invoke($module, $firstOrderId, 'tr_audit_renewal', 'paid');
    $applyStatus->invoke($module, $firstOrderId, 'tr_audit_renewal', 'paid');
    $applyStatus->invoke($module, $firstOrderId, 'tr_audit_renewal', 'failed');
    $assert(wc_get_order($firstOrderId)->is_paid(), 'Mollie success completes the order and a stale failure does not undo it');
    $assert($notesMatching($firstOrderId, 'Mollie recurring betaling tr_audit_renewal is betaald') === 1, 'Duplicate webhooks log payment completion exactly once');
    $assert((string) get_post_meta($subId, '_hb_ucs_sub_status', true) === 'active', 'Paid renewal reactivates the subscription');
    $assert((int) get_post_meta($subId, '_hb_ucs_sub_next_payment', true) === $nextPayment, 'Duplicate webhooks do not advance the date again');

    echo "PASS A04: full renewal failure/recovery, same order/payment, period advancement\n";
} finally {
    $wpdb->query('ROLLBACK');
    remove_filter('pre_http_request', $mock, PHP_INT_MAX);
    remove_filter('pre_wp_mail', $noMail);
}
