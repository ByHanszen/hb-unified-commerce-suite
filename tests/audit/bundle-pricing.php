<?php
/** Actual cart and order totals for bundle VAT contexts and residual cents. */
if (PHP_SAPI !== 'cli') exit;
require_once dirname(__DIR__, 5) . '/wp-load.php';
require_once dirname(__DIR__, 2) . '/src/Modules/Bundles/Products/WC_Product_Woosb.php';

use HB\UCS\Modules\Bundles\Cart\BundleCart;
use HB\UCS\Modules\Bundles\Orders\BundleOrders;
use HB\UCS\Modules\Bundles\Support\BundleData;
use HB\UCS\Modules\B2B\Engine\PriceEngine;
use HB\UCS\Modules\B2B\Storage\SettingsStore;
use HB\UCS\Modules\B2B\Storage\CustomerRulesStore;
use HB\UCS\Modules\B2B\Support\Context;
use HB\UCS\Modules\B2B\Support\WpcProductBundlesSupport;

$assert = static function ($actual, $expected, $message) {
    if (is_numeric($actual) && is_numeric($expected) ? abs((float) $actual - (float) $expected) > .001 : $actual !== $expected) throw new RuntimeException($message . ': ' . var_export($actual, true) . ' expected ' . var_export($expected, true));
};
global $wpdb;
if (!WC()->cart) wc_load_cart();
$oldContents = WC()->cart->cart_contents;
$oldCustomer = WC()->customer;
$fixtureUserId = 0;
$replacedPricingHooks = [];
$fixturePricingHooks = [];
$wpdb->query('START TRANSACTION');
try {
    update_option('woocommerce_calc_taxes', 'yes');
    update_option('woocommerce_tax_based_on', 'shipping');
    WC()->customer = new WC_Customer();
    WC()->customer->set_shipping_country('ZZ');
    WC()->customer->set_billing_country('ZZ');
    WC()->customer->set_is_vat_exempt(false);
    WC_Tax::create_tax_class('Audit reduced', 'audit-reduced');
    foreach (['' => 21, 'audit-reduced' => 9] as $class => $percent) {
        WC_Tax::_insert_tax_rate(['tax_rate_country' => 'ZZ', 'tax_rate_state' => '', 'tax_rate' => (string) $percent, 'tax_rate_name' => 'Audit', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 0, 'tax_rate_class' => $class]);
    }
    $makeProduct = static function ($class) {
        $product = new WC_Product_Simple();
        $product->set_name('Bundle audit fixture');
        $product->set_regular_price(5);
        $product->set_tax_class($class);
        $product->set_virtual(true);
        $product->save();
        return $product;
    };
    $standard = $makeProduct('');
    $reduced = $makeProduct('audit-reduced');
    $bundles = new BundleCart();
    $orders = new BundleOrders();
    foreach (['no', 'yes'] as $enteredWithTax) {
        update_option('woocommerce_prices_include_tax', $enteredWithTax);
        foreach (['incl', 'excl'] as $display) {
            update_option('woocommerce_tax_display_cart', $display);
            WC()->cart->cart_contents = [
                'parent' => ['key' => 'parent', 'product_id' => $standard->get_id(), 'quantity' => 1, 'data' => clone $standard, 'woosb_ids' => 'fixture', 'woosb_fixed_price' => false, 'woosb_keys' => ['a', 'b'], BundleData::CART_INSTANCE => 'audit-bundle'],
                'a' => ['key' => 'a', 'product_id' => $standard->get_id(), 'quantity' => 1, 'data' => clone $standard, 'woosb_parent_id' => $standard->get_id(), 'woosb_qty' => 1, BundleData::CART_INSTANCE => 'audit-bundle'],
                'b' => ['key' => 'b', 'product_id' => $reduced->get_id(), 'quantity' => 1, 'data' => clone $reduced, 'woosb_parent_id' => $standard->get_id(), 'woosb_qty' => 1, BundleData::CART_INSTANCE => 'audit-bundle'],
            ];
            $bundles->calculate_prices(WC()->cart);
            WC()->cart->calculate_totals();
            $parent = WC()->cart->get_cart_item('parent');
            $sum = 0.0;
            foreach (['a', 'b'] as $key) {
                $child = WC()->cart->get_cart_item($key);
                $sum += $child['line_subtotal'] + ($display === 'incl' ? $child['line_subtotal_tax'] : 0);
            }
            $assert($bundles->display_parent_subtotal('', $parent), wc_price($sum), "A11 actual cart ($enteredWithTax/$display)");
            if ($enteredWithTax === 'no' && $display === 'incl') $assert($sum, 11.5, 'A11 mixed 9/21 VAT');

            $order = new WC_Order();
            $order->set_currency('USD');
            foreach (WC()->cart->get_cart() as $key => $values) {
                $line = new WC_Order_Item_Product();
                $line->set_product($values['data']);
                $line->set_quantity($values['quantity']);
                $line->set_subtotal($values['line_subtotal']);
                $line->set_total($values['line_total']);
                $line->set_taxes($values['line_tax_data']);
                $orders->create_order_line_item($line, $key, $values, $order);
                $order->add_item($line);
                if ($key === 'parent') $parentLine = $line;
            }
            $order->save();
            $assert($orders->formatted_line_subtotal('', $parentLine, $order), wc_price($sum, ['currency' => 'USD']), "A11 saved order VAT/currency ($enteredWithTax/$display)");
        }
    }
    update_option('woocommerce_prices_include_tax', 'no');
    update_option('woocommerce_calc_taxes', 'no');
    WC()->cart->cart_contents = [
        'parent' => ['key' => 'parent', 'product_id' => $standard->get_id(), 'quantity' => 1, 'data' => clone $standard, 'woosb_ids' => 'fixture', 'woosb_fixed_price' => false, 'woosb_discount_amount' => 5, 'woosb_keys' => ['a'], BundleData::CART_INSTANCE => 'audit-rounding'],
        'a' => ['key' => 'a', 'product_id' => $standard->get_id(), 'quantity' => 3, 'data' => clone $standard, 'woosb_parent_id' => $standard->get_id(), 'woosb_qty' => 3, BundleData::CART_INSTANCE => 'audit-rounding'],
    ];
    for ($i = 0; $i < 3; $i++) {
        $bundles->calculate_prices(WC()->cart);
        WC()->cart->calculate_totals();
        $assert(WC()->cart->get_cart_item('a')['line_total'], 10, 'A12 three units sum to target 10');
        $assert(WC()->cart->get_cart_contents_total(), 10, 'A12 actual cart total');
    }
    // B2B must replace the subscription price, including a later bundle calculation.
    // Use one engine for both mutation and price getters, just as B2BModule does.
    foreach (['woocommerce_product_get_price', 'woocommerce_product_variation_get_price', 'woocommerce_before_calculate_totals'] as $hook) {
        foreach (($GLOBALS['wp_filter'][$hook]->callbacks ?? []) as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $fn = $callback['function'];
                if (is_array($fn) && $fn[0] instanceof PriceEngine) {
                    $replacedPricingHooks[] = [$hook, $fn, $priority, $callback['accepted_args']];
                    remove_filter($hook, $fn, $priority);
                }
            }
        }
    }
    $fixtureLogin = 'hb-bundle-b2b-' . wp_generate_uuid4();
    $wpdb->insert($wpdb->users, ['user_login' => $fixtureLogin, 'user_pass' => 'disabled', 'user_email' => $fixtureLogin . '@example.invalid']);
    $fixtureUserId = (int) $wpdb->insert_id;
    $wpdb->insert($wpdb->usermeta, ['user_id' => $fixtureUserId, 'meta_key' => $wpdb->prefix . 'capabilities', 'meta_value' => maybe_serialize(['customer' => true])]);
    $settings = new SettingsStore();
    $settings->update(['price_base' => 'current']);
    $rules = new CustomerRulesStore();
    $rules->save($fixtureUserId, ['price_display_mode' => 'both']);
    Context::set_forced_user_id($fixtureUserId);
    foreach (['fixed', 'dynamic', 'component'] as $scenario) {
        $bundle = new WC_Product_Woosb();
        $bundle->set_name('B2B subscription fixture');
        $bundle->set_regular_price(10);
        $bundle->set_price(10);
        $bundle->set_virtual(true);
        $bundle->update_meta_data(BundleData::META_FIXED_PRICE, $scenario === 'fixed' ? 'on' : 'off');
        $bundle->update_meta_data(BundleData::META_ITEMS, ['part' => ['id' => $standard->get_id(), 'qty' => 2]]);
        $bundle->build_items();
        $bundle->save();
        $ruleProductId = $scenario === 'component' ? $standard->get_id() : $bundle->get_id();
        $rules->save($fixtureUserId, ['price_display_mode' => 'both', 'pricing_products' => ['id' => [$ruleProductId], 'type' => ['percent'], 'value' => [10]]]);
        $engine = new PriceEngine($settings, new WpcProductBundlesSupport());
        $fixturePricingHooks = [
            ['woocommerce_product_get_price', [$engine, 'filter_product_price'], 1000, 2],
            ['woocommerce_product_variation_get_price', [$engine, 'filter_product_price'], 1000, 2],
            ['woocommerce_before_calculate_totals', [$engine, 'reprice_cart_items'], 1000, 1],
        ];
        foreach ($fixturePricingHooks as $hook) add_filter(...$hook);
        $assert(Context::get_effective_user_id(), $fixtureUserId, 'B2B fixture user context');
        if ($scenario === 'fixed') {
            $loadedBundle = wc_get_product($bundle->get_id());
            $assert($engine->get_adjusted_price_raw($loadedBundle, $fixtureUserId), 9, 'B2B fixed catalog rule: ' . json_encode([$loadedBundle->get_type(), $loadedBundle->get_price('edit'), $loadedBundle->get_regular_price('edit')]));
        }
        WC()->cart->cart_contents = [
            'parent' => ['key' => 'parent', 'product_id' => $bundle->get_id(), 'quantity' => 1, 'data' => clone $bundle, 'woosb_ids' => 'fixture', 'woosb_fixed_price' => $scenario === 'fixed', 'woosb_keys' => ['a'], 'hb_ucs_subs' => ['engine' => 'manual', 'scheme' => '1w', 'base_price' => 10, 'final_price' => 8], BundleData::CART_INSTANCE => 'b2b-policy'],
            'a' => ['key' => 'a', 'product_id' => $standard->get_id(), 'quantity' => 2, 'data' => clone $standard, 'woosb_parent_id' => $bundle->get_id(), 'woosb_qty' => 2, BundleData::CART_INSTANCE => 'b2b-policy'],
        ];
        for ($i = 0; $i < 3; $i++) {
            WC()->cart->cart_contents['parent']['data']->set_price(8);
            $engine->reprice_cart_items(WC()->cart);
            $matchedKey = $scenario === 'component' ? 'a' : 'parent';
            $assert(WC()->cart->cart_contents[$matchedKey]['hb_ucs_b2b_unit_price'] ?? 'missing', $scenario === 'component' ? 4.5 : 9, 'B2B rule applied before bundle: ' . $scenario . ' / ' . json_encode($rules->get($fixtureUserId)));
            $bundles->calculate_prices(WC()->cart);
            WC()->cart->calculate_totals();
            $assert(WC()->cart->get_cart_contents_total(), 9, 'B2B bundle price without subscription stacking: ' . $scenario);
        }
        $restored = $bundles->clear_session_pricing_marker(['hb_ucs_b2b_unit_price' => 9]);
        $assert(isset($restored['hb_ucs_b2b_unit_price']), false, 'B2B pricing marker is recalculated after session restore');
        foreach ($fixturePricingHooks as $hook) remove_filter($hook[0], $hook[1], $hook[2]);
        $fixturePricingHooks = [];
    }
    echo "PASS A11/A12: actual carts/orders, mixed VAT, currency, rounding; B2B subscription bundle precedence\n";
} finally {
    WC()->cart->cart_contents = $oldContents;
    WC()->customer = $oldCustomer;
    $wpdb->query('ROLLBACK');
    if ($fixtureUserId > 0) clean_user_cache($fixtureUserId);
    Context::set_forced_user_id(0);
    foreach ($fixturePricingHooks as $hook) remove_filter($hook[0], $hook[1], $hook[2]);
    foreach ($replacedPricingHooks as $hook) add_filter(...$hook);
}
