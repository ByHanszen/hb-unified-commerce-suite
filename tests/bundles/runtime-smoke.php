<?php
/**
 * Read-only CLI smoke test for the HB UCS bundle data contract.
 * Run: php tests/bundles/runtime-smoke.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

$wpLoad = dirname(__DIR__, 5) . '/wp-load.php';
if (!is_readable($wpLoad)) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(1);
}

require_once $wpLoad;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$class = 'HB\\UCS\\Modules\\Bundles\\Support\\BundleData';
$assert(class_exists($class), 'BundleData class is not autoloadable.');

$blocksClass = 'HB\\UCS\\Modules\\Bundles\\Blocks\\BundleBlocks';
$blocksIntegrationClass = 'HB\\UCS\\Modules\\Bundles\\Blocks\\BundleBlocksIntegration';
$assert(class_exists($blocksClass), 'BundleBlocks class is not autoloadable.');
if (interface_exists('Automattic\\WooCommerce\\Blocks\\Integrations\\IntegrationInterface')) {
    $assert(class_exists($blocksIntegrationClass), 'WooCommerce Blocks integration is not autoloadable.');
}

if (class_exists($blocksIntegrationClass)) {
    $integration = new $blocksIntegrationClass();
    $assert($integration->get_name() === 'hb-ucs-bundles', 'WooCommerce Blocks integration name is invalid.');
    $assert(in_array('hb-ucs-bundles-blocks', $integration->get_script_handles(), true), 'WooCommerce Blocks script handle is missing.');
}

if (class_exists($blocksClass) && function_exists('wc_get_products') && class_exists('WP_REST_Response') && class_exists('WP_REST_Request')) {
    $sampleProducts = wc_get_products(['status' => 'publish', 'limit' => 1, 'return' => 'objects']);
    $sampleProduct = is_array($sampleProducts) ? reset($sampleProducts) : false;
    if ($sampleProduct instanceof WC_Product) {
        if ((!function_exists('WC') || !WC()->cart) && function_exists('wc_load_cart')) {
            wc_load_cart();
        }
        if (function_exists('WC') && WC()->cart) {
            $originalCartContents = WC()->cart->cart_contents;
            try {
                $instance = 'runtime-smoke-bundle';
                WC()->cart->cart_contents = [
                    'runtime-parent' => [
                        'key' => 'runtime-parent',
                        'product_id' => $sampleProduct->get_id(),
                        'quantity' => 1,
                        'data' => clone $sampleProduct,
                        'woosb_ids' => $sampleProduct->get_id() . '/sample/1/',
                        'woosb_fixed_price' => false,
                        'woosb_keys' => ['runtime-child'],
                        'hb_ucs_bundle_instance_id' => $instance,
                    ],
                    'runtime-child' => [
                        'key' => 'runtime-child',
                        'product_id' => $sampleProduct->get_id(),
                        'quantity' => 1,
                        'data' => clone $sampleProduct,
                        'woosb_parent_id' => $sampleProduct->get_id(),
                        'woosb_parent_key' => 'runtime-parent',
                        'woosb_qty' => 1,
                        'hb_ucs_bundle_instance_id' => $instance,
                    ],
                ];
                $totals = static function (int $subtotal, int $tax): object {
                    return (object) [
                        'line_subtotal' => (string) $subtotal,
                        'line_subtotal_tax' => (string) $tax,
                        'line_total' => (string) $subtotal,
                        'line_total_tax' => (string) $tax,
                    ];
                };
                $response = new WP_REST_Response(['items' => [
                    ['key' => 'runtime-parent', 'quantity_limits' => (object) ['editable' => true], 'totals' => $totals(0, 0)],
                    ['key' => 'runtime-child', 'quantity_limits' => (object) ['editable' => true], 'totals' => $totals(1250, 263)],
                ]]);
                $request = new WP_REST_Request('GET', '/wc/store/v1/cart');
                $blocks = new $blocksClass();
                $result = $blocks->enrich_cart_response($response, null, $request)->get_data();
                $assert(!empty($result['items'][0]['hb_ucs_bundle_parent']), 'Store API parent marker is missing.');
                $assert(($result['items'][0]['totals']->line_total ?? '') === '1250', 'Store API child totals were not presented on the parent.');
                $assert(!empty($result['items'][1]['hb_ucs_bundle_child']), 'Store API child marker is missing.');
                $assert(($result['items'][1]['quantity_limits']->editable ?? true) === false, 'Store API child quantity is still editable.');
            } finally {
                WC()->cart->cart_contents = $originalCartContents;
            }
        }
    }
}

if (class_exists($class)) {
    $source = [
        'coffee' => [
            'id' => 123,
            'sku' => 'COFFEE-123',
            'qty' => 2,
            'optional' => 1,
            'min' => 0,
            'max' => 4,
            'attrs' => ['attribute_pa_size' => 'large'],
            'terms' => ['pa_size' => ['large', 'medium']],
            'customer_title' => 'Koffie naar keuze',
            'customer_description' => '<strong>Kies je formaat</strong>',
            'badge' => 'Favoriet',
            'group' => 'Dranken',
        ],
        'intro' => [
            'id' => 0,
            'type' => 'h2',
            'text' => 'Kies je pakket',
        ],
    ];

    $normalized = $class::normalize_items($source);
    $assert(isset($normalized['coffee']), 'WPC product row was not normalized.');
    $assert(($normalized['coffee']['terms']['pa_size'] ?? []) === ['large', 'medium'], 'Variation restrictions were not preserved.');
    $assert(($normalized['intro']['type'] ?? '') === 'h2', 'WPC content row was not preserved.');

    $compact = $class::selection_to_string(['coffee' => $source['coffee']]);
    $parsed = $class::parse_selection($compact);
    $assert((int) ($parsed['coffee']['id'] ?? 0) === 123, 'Compact product ID round-trip failed.');
    $assert((float) ($parsed['coffee']['qty'] ?? 0) === 2.0, 'Compact quantity round-trip failed.');
    $assert(($parsed['coffee']['attrs']['attribute_pa_size'] ?? '') === 'large', 'Compact variation round-trip failed.');

    $legacyProduct = new class {
        public function get_meta($key) {
            $values = [
                'woosb_limit_each_min_default' => 'off',
                'woosb_limit_each_min' => '2',
                'woosb_limit_each_max' => '5',
            ];
            return $values[$key] ?? '';
        }
    };
    $legacyDefinition = $class::normalize_product_items($legacyProduct, [
        'legacy-option' => ['id' => 123, 'qty' => 3, 'optional' => 1],
    ]);
    $assert((float) ($legacyDefinition['legacy-option']['min'] ?? 0) === 2.0, 'Historical WPC minimum fallback failed.');
    $assert((float) ($legacyDefinition['legacy-option']['max'] ?? 0) === 5.0, 'Historical WPC maximum fallback failed.');

    $legacy = array_values($class::parse_selection('123/2'));
    $assert((int) ($legacy[0]['id'] ?? 0) === 123, 'Legacy id/quantity product ID parsing failed.');
    $assert((float) ($legacy[0]['qty'] ?? 0) === 2.0, 'Legacy id/quantity amount parsing failed.');
}

echo 'HB UCS: ' . (defined('HB_UCS_VERSION') ? HB_UCS_VERSION : 'inactive') . PHP_EOL;
echo 'WooCommerce: ' . (defined('WC_VERSION') ? WC_VERSION : 'inactive') . PHP_EOL;
echo 'WPC Product Bundles: ' . (defined('WOOSB_VERSION') ? WOOSB_VERSION : 'inactive') . PHP_EOL;
$mainSettings = (array) get_option('hb_ucs_settings', []);
echo 'HB Productbundels module: ' . (!empty($mainSettings['modules']['bundles']) ? 'enabled' : 'disabled') . PHP_EOL;

if (function_exists('wc_get_page_id')) {
    foreach (['cart' => 'Cart page', 'checkout' => 'Checkout page'] as $pageKey => $label) {
        $pageId = (int) wc_get_page_id($pageKey);
        $page = $pageId > 0 ? get_post($pageId) : null;
        $blockName = $pageKey === 'cart' ? 'woocommerce/cart' : 'woocommerce/checkout';
        if (!$page) {
            $mode = 'missing';
        } else {
            $mode = has_block($blockName, $page) ? 'block' : 'classic/other';
        }
        echo $label . ': ' . $mode . ' (#' . $pageId . ')' . PHP_EOL;
    }
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "Bundle data smoke test: OK\n";

