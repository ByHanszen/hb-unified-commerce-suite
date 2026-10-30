<?php
/** Audit regressions against WordPress/WooCommerce; fixture writes are rolled back. */
if (PHP_SAPI !== 'cli') exit;
$wpLoad = getenv('HB_UCS_TEST_WP_LOAD') ?: dirname(__DIR__, 5) . '/wp-load.php';
$storageMode = getenv('HB_UCS_TEST_HPOS');
if ($storageMode === 'yes' || $storageMode === 'no') {
    $GLOBALS['wp_filter']['pre_option_woocommerce_custom_orders_table_enabled'][10][] = ['function' => static function () use ($storageMode) { return $storageMode; }, 'accepted_args' => 0];
    $GLOBALS['wp_filter']['pre_option_woocommerce_custom_orders_table_data_sync_enabled'][10][] = ['function' => static function () { return 'no'; }, 'accepted_args' => 0];
}
require_once $wpLoad;
require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
require_once WC_ABSPATH . 'includes/emails/class-wc-email.php';

use HB\UCS\Core\DatabaseLock;
use HB\UCS\Core\Settings;
use HB\UCS\Modules\B2B\Engine\PriceEngine;
use HB\UCS\Modules\B2B\Storage\SettingsStore as B2BSettings;
use HB\UCS\Modules\B2B\Storage\CustomerRulesStore;
use HB\UCS\Modules\B2B\Support\Context;
use HB\UCS\Modules\B2B\Support\WpcProductBundlesSupport;
use HB\UCS\Modules\Bundles\Support\BundleData;
use HB\UCS\Modules\Bundles\Cart\BundleCart;
use HB\UCS\Modules\Bundles\Orders\BundleOrders;
use HB\UCS\Modules\Customers\InvoiceEmailModule;
use HB\UCS\Modules\OrderOverviewStatus\Storage\SettingsStore as StatusSettings;
use HB\UCS\Modules\QLS\QLSModule;
use HB\UCS\Modules\Returns\ReturnsModule;
use HB\UCS\Modules\Roles\Support\RolesGuard;
use HB\UCS\Modules\Roles\Admin\RolesAdminPage;
use HB\UCS\Modules\Subscriptions\Payments\RenewalPaymentService;

function audit_check($condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function audit_equal($actual, $expected, string $message): void {
    audit_check(is_numeric($actual) && is_numeric($expected) ? abs((float) $actual - (float) $expected) < 0.0001 : $actual === $expected, $message . ': ' . var_export($actual, true));
}
function audit_call($object, string $method, ...$args) {
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invoke($object, ...$args);
}

global $wpdb;
$oldUser = wp_get_current_user();
$oldRoles = $GLOBALS['wp_roles'];
$GLOBALS['wp_roles'] = clone $oldRoles;
$GLOBALS['wp_roles']->use_db = false;
$userIds = [];
$files = [];
$testDir = trailingslashit(get_temp_dir()) . 'hb-audit-' . wp_generate_uuid4();
wp_mkdir_p($testDir);
$blockMail = static function () { return true; };
$blockNetwork = static function () { return new WP_Error('audit_network_blocked', 'Unexpected HTTP request in regression test'); };
add_filter('pre_wp_mail', $blockMail);
add_filter('pre_http_request', $blockNetwork, 1);
$wpdb->query('START TRANSACTION');
try {
    $makeUser = static function (array $roles) use ($wpdb, &$userIds) {
        $login = 'hb-audit-' . wp_generate_uuid4();
        $wpdb->insert($wpdb->users, ['user_login' => $login, 'user_pass' => 'disabled', 'user_nicename' => $login, 'user_email' => $login . '@example.invalid', 'display_name' => 'Audit fixture', 'user_registered' => gmdate('Y-m-d H:i:s')]);
        $id = (int) $wpdb->insert_id;
        audit_check($id > 0, 'Fixture user insert');
        $wpdb->insert($wpdb->usermeta, ['user_id' => $id, 'meta_key' => $wpdb->prefix . 'capabilities', 'meta_value' => maybe_serialize(array_fill_keys($roles, true))]);
        $userIds[] = $id;
        return $id;
    };
    $adminId = $makeUser(['administrator']);
    $managerId = $makeUser(['shop_manager']);
    wp_set_current_user($managerId);
    audit_check(!RolesGuard::can_manage(), 'A01 shop manager must be denied');
    wp_set_current_user($adminId);
    audit_check(RolesGuard::can_manage(), 'A01 administrator allowed');
    audit_check(!RolesGuard::can_edit_role('administrator'), 'A01 administrator role protected');
    audit_check(!RolesGuard::can_edit_role('shop_manager'), 'A01 shop manager role protected');
    audit_check(!RolesGuard::can_assign_caps(['hb_unknown_privilege' => true]), 'A01 cannot grant unknown privilege');
    add_role('hb_audit_source', 'Audit source', ['read' => true]);
    add_role('hb_audit_target', 'Audit target', ['read' => true]);
    audit_check(RolesGuard::can_edit_role('hb_audit_target'), 'A01 ordinary customer role editable');
    $rolesPage = new RolesAdminPage();
    audit_check(!audit_call($rolesPage, 'reassign_users', 'hb_audit_source', 'hb_audit_source'), 'A08 identical roles rejected');
    foreach ([201, 450] as $count) {
        $batch = [];
        for ($i = 0; $i < $count; $i++) $batch[] = $makeUser(['hb_audit_source', 'subscriber']);
        wp_cache_set_users_last_changed();
        $beforeUsers = new WP_User_Query(['role' => 'hb_audit_source', 'fields' => 'ID', 'number' => 200]);
        audit_check(count($beforeUsers->get_results()) > 0, 'A08 fixture selection: ' . $beforeUsers->request . ' / ' . $wpdb->last_error);
        audit_check(audit_call($rolesPage, 'reassign_users', 'hb_audit_source', 'hb_audit_target'), 'A08 reassignment succeeds');
        foreach ($batch as $id) {
            $roles = get_user_by('id', $id)->roles;
            audit_check(in_array('hb_audit_target', $roles, true) && in_array('subscriber', $roles, true) && !in_array('hb_audit_source', $roles, true), 'A08 all users and other roles preserved: ' . json_encode($roles));
        }
    }
    echo "PASS A01/A08: handler authorization and 201/450 users\n";

    $statuses = new StatusSettings();
    $rows = [['id' => 'a', 'label' => 'A'], ['id' => 'b', 'label' => 'B'], ['id' => 'c', 'label' => 'C']];
    $statuses->update(['statuses' => $rows]);
    $statuses->update(['statuses' => [$rows[0]]]);
    audit_equal(count($statuses->get_statuses()), 1, 'A09 deletion persists');
    $statuses->update(['statuses' => []]);
    audit_equal($statuses->get_statuses(), [], 'A09 empty list persists');
    echo "PASS A09: exact status-list replacement\n";

    $makeProduct = static function ($price, $taxClass = '') {
        $product = new WC_Product_Simple();
        $product->set_name('Audit fixture');
        $product->set_regular_price((string) $price);
        $product->set_price((string) $price);
        $product->set_tax_class($taxClass);
        $product->save();
        return $product;
    };
    $product = $makeProduct(100);
    $b2bSettings = new B2BSettings();
    $b2bSettings->update(['price_base' => 'current']);
    update_user_meta($managerId, CustomerRulesStore::META, ['pricing' => ['products' => [$product->get_id() => ['type' => 'percent', 'value' => 10]]]]);
    Context::set_forced_user_id($managerId);
    $pricing = new PriceEngine($b2bSettings, new WpcProductBundlesSupport());
    $cart = new class($product) {
        public $data;
        public function __construct($product) { $this->data = ['data' => clone $product]; }
        public function get_cart() { return ['test' => $this->data]; }
    };
    for ($i = 0; $i < 3; $i++) {
        $pricing->reprice_cart_items($cart);
        audit_equal($cart->data['data']->get_price('edit'), 90, 'A05 current price must not compound');
    }
    $cart->data['hb_ucs_subs'] = ['engine' => 'manual', 'scheme' => '1w', 'base_price' => 100, 'final_price' => 80];
    $subscriptionPricing = new PriceEngine($b2bSettings, new WpcProductBundlesSupport());
    for ($i = 0; $i < 3; $i++) {
        $cart->data['data']->set_price(80); // Subscription hook runs before B2B every time.
        $subscriptionPricing->reprice_cart_items($cart);
        audit_equal($cart->data['data']->get_price('edit'), 90, 'B2B overrides subscription without stacking');
        audit_equal($subscriptionPricing->filter_product_price('90', $cart->data['data']), 90, 'B2B product getter agrees with cart');
    }
    Context::set_forced_user_id($adminId); // No matching B2B rule for this user.
    $subscriptionPricing->reprice_cart_items($cart);
    audit_equal($cart->data['data']->get_price('edit'), 80, 'Subscription price remains without a B2B rule');
    Context::set_forced_user_id($managerId);
    unset($cart->data['hb_ucs_subs']);
    Context::set_forced_user_id(0);
    wp_set_current_user(0);
    $pricing->reprice_cart_items($cart);
    audit_equal($cart->data['data']->get_price('edit'), 100, 'A05 guest restores catalog base');
    wp_set_current_user($adminId);
    Context::set_forced_user_id($managerId);
    foreach (['current' => 72, 'regular' => 90] as $baseMode => $expected) {
        $b2bSettings->update(['price_base' => $baseMode]);
        $salePricing = new PriceEngine($b2bSettings, new WpcProductBundlesSupport());
        $cart->data['data'] = clone $product;
        $cart->data['data']->set_sale_price(80);
        $cart->data['data']->set_price(80);
        for ($i = 0; $i < 3; $i++) {
            $salePricing->reprice_cart_items($cart);
            audit_equal($cart->data['data']->get_price('edit'), $expected, 'A05 sale price basis ' . $baseMode);
        }
    }
    Context::set_forced_user_id(0);
    echo "PASS A05: repeated pricing, B2B precedence without stacking, subscription fallback, guest\n";

    $invoice = new InvoiceEmailModule();
    $order = new WC_Order();
    $order->set_customer_id($managerId);
    $order->save();
    update_user_meta($managerId, InvoiceEmailModule::META_KEY, 'audit-invoice@example.invalid');
    update_option('hb_ucs_invoice_email_settings', []);
    audit_equal($invoice->maybe_block_pipfs_attachment_condition(true, $order, 'new_order', 'invoice'), true, 'Administrator invoice attachment preserved');
    audit_equal($invoice->maybe_block_pipfs_attachment_condition(true, $order, 'customer_completed_order', 'invoice'), false, 'Customer invoice attachment redirected');
    $xml = static function ($id) { return '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2"><cbc:ID>invoice</cbc:ID><cac:OrderReference><cbc:ID>' . $id . '</cbc:ID></cac:OrderReference></Invoice>'; };
    $correct = $testDir . '/ubl-correct.xml';
    $wrong = $testDir . '/ubl-newer.xml';
    file_put_contents($correct, $xml($order->get_order_number()));
    file_put_contents($wrong, $xml('another-order'));
    $files = [$correct, $wrong];
    touch($wrong, time() + 10);
    audit_check(audit_call($invoice, 'ubl_file_matches_order', $correct, $order), 'A02 correct XML accepted');
    audit_check(!audit_call($invoice, 'ubl_file_matches_order', $wrong, $order), 'A02 wrong XML rejected despite newer timestamp');
    audit_check(!audit_call($invoice, 'ubl_xml_matches_order', '<!DOCTYPE Invoice [<!ENTITY x SYSTEM "file:///etc/passwd">]><Invoice>&x;</Invoice>', $order), 'A02 external entity rejected');
    if (class_exists('ZipArchive')) {
        foreach (['valid', 'mixed'] as $kind) {
            $archive = $testDir . '/' . $kind . '.zip';
            $files[] = $archive;
            $zip = new ZipArchive();
            $zip->open($archive, ZipArchive::CREATE);
            $zip->addFromString('invoice.xml', $xml($order->get_order_number()));
            if ($kind === 'mixed') $zip->addFromString('other.xml', $xml('another-order'));
            $zip->close();
            audit_equal(audit_call($invoice, 'ubl_file_matches_order', $archive, $order), $kind === 'valid', 'A02 every ZIP entry must match');
        }
    }
    $uploadFilter = static function ($uploads) use ($testDir) { $uploads['basedir'] = $testDir; return $uploads; };
    add_filter('upload_dir', $uploadFilter);
    wp_mkdir_p($testDir . '/wpo_wcpdf');
    copy($correct, $testDir . '/wpo_wcpdf/ubl-correct.xml');
    copy($wrong, $testDir . '/wpo_wcpdf/ubl-newer.xml');
    $files[] = $testDir . '/wpo_wcpdf/ubl-correct.xml';
    $files[] = $testDir . '/wpo_wcpdf/ubl-newer.xml';
    $customerEmail = new class extends WC_Email { public function is_customer_email() { return true; } };
    $adminEmail = new class extends WC_Email { public function is_customer_email() { return false; } };
    $attachments = [$testDir . '/wpo_wcpdf/invoice.pdf'];
    audit_equal($invoice->strip_pipfs_attachments_from_customer_mails($attachments, 'customer_completed_order', $order, $customerEmail), [], 'Customer PDF guard accepts WC_Email object');
    audit_equal($invoice->strip_pipfs_attachments_from_customer_mails($attachments, 'new_order', $order, $adminEmail), $attachments, 'Administrator PDF guard preserves attachments');
    audit_equal(wp_normalize_path(audit_call($invoice, 'get_ubl_path_for_order', $order)), wp_normalize_path($testDir . '/wpo_wcpdf/ubl-correct.xml'), 'A02 exact identity wins');
    unlink($testDir . '/wpo_wcpdf/ubl-correct.xml');
    audit_equal(audit_call($invoice, 'get_ubl_path_for_order', $order), '', 'A02 no matching UBL omitted');
    remove_filter('upload_dir', $uploadFilter);
    echo "PASS A02: exact UBL identity, no match, external entities\n";

    $payload = ['amount' => ['currency' => 'EUR', 'value' => '10.00'], 'customerId' => 'cst_audit', 'mandateId' => 'mdt_audit', 'metadata' => ['order_id' => $order->get_id(), 'subscription_id' => 123]];
    $calls = [];
    $paymentService = new RenewalPaymentService(static function ($method, $path, $body, $key) use (&$calls) {
        $calls[] = [$method, $path, $body, $key];
        return count($calls) === 1 ? new WP_Error('http_request_failed', 'Simulated timeout') : ['id' => 'tr_audit', 'status' => 'pending'];
    });
    audit_check(is_wp_error($paymentService->ensure_payment($order, $payload, 'credential')), 'A04 first request fails');
    $changedPayload = $payload;
    $changedPayload['amount']['value'] = '999.00';
    audit_equal($paymentService->ensure_payment($order, $changedPayload, 'credential'), 'tr_audit', 'A04 retry recovers payment');
    audit_equal($calls[0][2], $calls[1][2], 'A04 retry freezes payload');
    audit_equal($calls[0][3], $calls[1][3], 'A04 retry retains idempotency key');
    $paymentService->ensure_payment($order, $payload, 'credential');
    audit_equal(count($calls), 2, 'A04 existing payment prevents duplicate POST');
    $uncertain = new WC_Order();
    $uncertain->save();
    $payload['metadata']['order_id'] = $uncertain->get_id();
    $uncertain->update_meta_data(RenewalPaymentService::REQUEST, $payload);
    $uncertain->update_meta_data(RenewalPaymentService::KEY, 'expired-key');
    $uncertain->update_meta_data(RenewalPaymentService::STARTED, time() - 2 * HOUR_IN_SECONDS);
    $uncertain->update_meta_data(RenewalPaymentService::CREDENTIAL, 'credential');
    $uncertain->save();
    $reconcileCalls = [];
    $reconcile = new RenewalPaymentService(static function ($method, $path) use (&$reconcileCalls, $payload) {
        $reconcileCalls[] = $method;
        return ['_embedded' => ['payments' => [array_merge($payload, ['id' => 'tr_reconciled'])]]];
    });
    audit_equal($reconcile->ensure_payment($uncertain, $payload, 'credential'), 'tr_reconciled', 'A04 expired window reconciles');
    audit_equal($reconcileCalls, ['GET'], 'A04 no POST after expired window');
    $unresolved = new WC_Order();
    $unresolved->save();
    $unknownPayload = $payload;
    $unknownPayload['metadata']['order_id'] = $unresolved->get_id();
    $unresolved->update_meta_data(RenewalPaymentService::REQUEST, $unknownPayload);
    $unresolved->update_meta_data(RenewalPaymentService::KEY, 'expired-unknown');
    $unresolved->update_meta_data(RenewalPaymentService::STARTED, time() - 2 * HOUR_IN_SECONDS);
    $unresolved->update_meta_data(RenewalPaymentService::CREDENTIAL, 'credential');
    $unresolved->save();
    $unresolvedService = new RenewalPaymentService(static function ($method) {
        audit_equal($method, 'GET', 'A04 unresolved old attempt must not POST');
        return ['_embedded' => ['payments' => []]];
    });
    $unresolvedResult = $unresolvedService->ensure_payment($unresolved, $unknownPayload, 'credential');
    audit_check(is_wp_error($unresolvedResult) && $unresolvedResult->get_error_code() === 'hb_ucs_payment_reconciliation_required', 'A04 uncertain outcome requires reconciliation');
    echo "PASS A04: timeout, frozen retries, no duplicate, reconciliation\n";

    $qls = new QLSModule();
    $qls->init();
    update_option(Settings::OPT_QLS, ['api_user_id' => $adminId]);
    $fixtureOrders = [];
    for ($i = 0; $i < 11; $i++) {
        $fixture = new WC_Order();
        $fixture->update_meta_data(QLSModule::META_ORDER, $i < 10 ? '1' : '0');
        $fixture->save();
        $fixtureOrders[] = $fixture->get_id();
    }
    audit_check(!$qls->should_send_order(true, $fixtureOrders[0]), 'A06 CRUD exclusion honored');
    audit_check($qls->should_send_order(true, $fixtureOrders[10]), 'A06 included order honored');
    $_POST = ['hb_qls_order_meta_nonce' => wp_create_nonce('hb_qls_order_meta'), 'hb_qls_exclude_order' => '1'];
    $qls->save_order_meta($fixtureOrders[10], wc_get_order($fixtureOrders[10]));
    audit_equal(wc_get_order($fixtureOrders[10])->get_meta(QLSModule::META_ORDER, true), '1', 'A06 metabox saves to active store');
    $_POST = ['hb_qls_order_meta_nonce' => wp_create_nonce('hb_qls_order_meta')];
    $qls->save_order_meta($fixtureOrders[10], wc_get_order($fixtureOrders[10]));
    $_POST = [];
    $request = new WP_REST_Request('GET', '/wc/v3/orders');
    $request->set_param('include', $fixtureOrders);
    $request->set_param('per_page', 10);
    $prepared = $qls->rest_filter_order_query(['post_type' => 'shop_order', 'post_status' => 'any', 'post__in' => $fixtureOrders], $request);
    audit_equal(count($prepared['post__not_in']), 10, 'A07 excluded IDs selected before REST');
    $response = rest_do_request($request);
    audit_equal($response->get_status(), 200, 'A07 REST request succeeds');
    audit_equal($response->get_headers()['X-WP-Total'], 1, 'A07 total counts only permitted orders');
    audit_equal(count($response->get_data()), 1, 'A07 first page contains permitted order');
    audit_equal($response->get_data()[0]['id'], $fixtureOrders[10], 'A07 eleventh order not missed');
    echo "PASS A06/A07: CRUD exclusion and filtering before pagination\n";

    $allocated = BundleData::allocate_component_prices(['a' => ['unit' => 5, 'component_qty' => 3]], 10);
    audit_equal(round($allocated['a'] * 3, 2), 10, 'A12 three units total 10');
    $rows = ['a' => ['unit' => 5, 'component_qty' => 3], 'b' => ['unit' => 7, 'component_qty' => 2]];
    $allocated = BundleData::allocate_component_prices($rows, 10, 2);
    $sum = 0;
    foreach ($rows as $key => $row) $sum += round($allocated[$key] * $row['component_qty'] * 2, 2);
    audit_equal($sum, 20, 'A12 residual cents allocated per line');
    if (!WC()->cart) wc_load_cart();
    $originalContents = WC()->cart->cart_contents;
    $bundleParent = ['data' => clone $product, 'quantity' => 1, 'woosb_ids' => 'fixture', 'woosb_fixed_price' => false, 'woosb_price' => 10, 'woosb_keys' => ['a', 'b'], BundleData::CART_INSTANCE => 'audit'];
    WC()->cart->cart_contents = [
        'parent' => $bundleParent,
        'a' => ['data' => clone $product, 'quantity' => 1, 'woosb_parent_id' => $product->get_id(), BundleData::CART_INSTANCE => 'audit', 'line_subtotal' => 5, 'line_subtotal_tax' => 1.05],
        'b' => ['data' => clone $product, 'quantity' => 1, 'woosb_parent_id' => $product->get_id(), BundleData::CART_INSTANCE => 'audit', 'line_subtotal' => 5, 'line_subtotal_tax' => .45],
    ];
    $bundles = new BundleCart();
    update_option('woocommerce_tax_display_cart', 'incl');
    audit_equal($bundles->display_parent_subtotal('', $bundleParent), wc_price(11.5), 'A11 mixed VAT cart display');
    update_option('woocommerce_tax_display_cart', 'excl');
    audit_equal($bundles->display_parent_subtotal('', $bundleParent), wc_price(10), 'A11 ex VAT cart display');
    WC()->cart->cart_contents = $originalContents;
    echo "PASS A11/A12: mixed VAT context and component rounding\n";

    $returns = new ReturnsModule();
    $returns->register_post_type();
    $returnOrder = new WC_Order();
    $line = new WC_Order_Item_Product();
    $line->set_product($product);
    $line->set_quantity(3);
    $line->set_subtotal(300);
    $line->set_total(300);
    $returnOrder->add_item($line);
    $returnOrder->calculate_totals(false);
    $returnOrder->save();
    $refund = wc_create_refund(['order_id' => $returnOrder->get_id(), 'amount' => 200, 'line_items' => [$line->get_id() => ['qty' => 2, 'refund_total' => 200]], 'refund_payment' => false, 'restock_items' => false]);
    audit_check($refund instanceof WC_Order_Refund, 'A13 refund fixture: ' . (is_wp_error($refund) ? $refund->get_error_message() : 'invalid result'));
    $available = audit_call($returns, 'get_returnable_items', wc_get_order($returnOrder->get_id()));
    audit_equal($available[$line->get_id()]['available_quantity'], 1, 'A13 external quantity refund excluded');
    $requestId = wp_insert_post(['post_type' => ReturnsModule::POST_TYPE, 'post_status' => 'private', 'post_title' => 'Audit fixture']);
    update_post_meta($requestId, ReturnsModule::META_ORDER_ID, $returnOrder->get_id());
    update_post_meta($requestId, ReturnsModule::META_STATUS, 'completed');
    update_post_meta($requestId, ReturnsModule::META_ITEMS, [['item_id' => $line->get_id(), 'quantity' => 2]]);
    audit_check($returns->set_linked_refunds($requestId, [$refund->get_id()]), 'A13 matching refund linked');
    $available = audit_call($returns, 'get_returnable_items', wc_get_order($returnOrder->get_id()));
    audit_equal($available[$line->get_id()]['available_quantity'], 1, 'A13 linked refund not double counted');
    $moneyOnly = wc_create_refund(['order_id' => $returnOrder->get_id(), 'amount' => 1, 'refund_payment' => false]);
    $available = audit_call($returns, 'get_returnable_items', wc_get_order($returnOrder->get_id()));
    audit_equal($available[$line->get_id()]['available_quantity'], 1, 'A13 monetary adjustment leaves quantities unchanged');
    audit_check(!$returns->set_linked_refunds($requestId, [$order->get_id()]), 'A13 unrelated refund cannot be linked');
    $submitted = audit_call($returns, 'submit_return_request', wc_get_order($returnOrder->get_id()), [$line->get_id() => 1], 'Fixture');
    audit_check(is_int($submitted) && $submitted > 0, 'Return submission consumes remaining quantity');
    $duplicate = audit_call($returns, 'submit_return_request', wc_get_order($returnOrder->get_id()), [$line->get_id() => 1], 'Fixture duplicate');
    audit_check(is_wp_error($duplicate), 'Duplicate return cannot consume already reserved quantity');
    echo "PASS A13: refund quantities, linked overlap and monetary corrections\n";

    audit_equal(DatabaseLock::run('audit-lock', static function () { return 42; }), 42, 'Database lock executes callback');
    $otherConnection = new wpdb(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST);
    $lockKey = 'hb-ucs-' . sha1($wpdb->prefix . ':audit-contention');
    try {
        audit_equal($otherConnection->get_var($otherConnection->prepare('SELECT GET_LOCK(%s, 0)', $lockKey)), 1, 'Second connection acquires lock');
        $busy = DatabaseLock::run('audit-contention', static function () { throw new RuntimeException('Contended callback must not execute'); });
        audit_check(is_wp_error($busy) && $busy->get_error_code() === 'hb_ucs_busy', 'Competing connection cannot create duplicate');
    } finally {
        $otherConnection->get_var($otherConnection->prepare('SELECT RELEASE_LOCK(%s)', $lockKey));
        $otherConnection->close();
    }
    echo 'PASS: audit regression suite; HPOS=' . (\Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ? 'yes' : 'no') . "\n";
} finally {
    $wpdb->query('ROLLBACK');
    foreach ($userIds as $id) clean_user_cache($id);
    $GLOBALS['wp_roles'] = $oldRoles;
    $GLOBALS['current_user'] = $oldUser;
    Context::set_forced_user_id(0);
    remove_filter('pre_wp_mail', $blockMail);
    remove_filter('pre_http_request', $blockNetwork, 1);
    foreach ($files as $file) if (is_file($file)) unlink($file);
    if (is_dir($testDir . '/wpo_wcpdf')) rmdir($testDir . '/wpo_wcpdf');
    rmdir($testDir);
}
