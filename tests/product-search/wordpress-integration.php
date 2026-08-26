<?php
/**
 * Read-only WordPress integration smoke test for ProductSearch.
 *
 * Run: php tests/product-search/wordpress-integration.php
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

use HB\UCS\Modules\ProductSearch\ProductSearchModule;
use HB\UCS\Modules\ProductSearch\SearchQuery;
use HB\UCS\Modules\ProductSearch\SearchRewrite;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(class_exists(ProductSearchModule::class), 'ProductSearchModule is not autoloadable.');
$assert(class_exists('WooCommerce'), 'WooCommerce is not active.');

$rewrite = new SearchRewrite(ProductSearchModule::defaults());
$rewrite->init();
$rewrite->register_rewrite();
$queryVars = $rewrite->register_query_var([]);
$assert(in_array(ProductSearchModule::QUERY_VAR, $queryVars, true), 'Custom query var is not registered.');

$generatedRules = $GLOBALS['wp_rewrite']->rewrite_rules();
$assert(is_array($generatedRules) && isset($generatedRules['^zoeken/([^/]+)/?$']), 'Clean route is absent from generated WordPress rewrite rules.');
$rulesFilter = static function () use ($generatedRules) {
    return $generatedRules;
};
add_filter('pre_option_rewrite_rules', $rulesFilter);

$originalRequestUri = $_SERVER['REQUEST_URI'] ?? null;
$originalPhpSelf = $_SERVER['PHP_SELF'] ?? null;
$homePath = (string) wp_parse_url(home_url('/'), PHP_URL_PATH);

foreach (['topping', 'house blend', 'decafé', 'Etna', '100% arabica'] as $term) {
    $url = $rewrite->get_search_url($term);
    $_SERVER['REQUEST_URI'] = (string) wp_parse_url($url, PHP_URL_PATH);
    $_SERVER['PHP_SELF'] = rtrim($homePath, '/') . '/index.php';

    $router = new WP();
    $router->parse_request();
    $vars = $router->query_vars;
    $assert(($vars[ProductSearchModule::QUERY_VAR] ?? '') === '1', 'WordPress rewrite marker missing for: ' . $term);

    $query = new WP_Query();
    $query->parse_query($vars);
    $assert($query->is_search(), 'WordPress did not recognize a real search for: ' . $term);
    $assert((string) $query->get('s') === $term, 'WordPress search term changed for: ' . $term);

    $previousMainQuery = $GLOBALS['wp_the_query'] ?? null;
    $GLOBALS['wp_the_query'] = $query;
    (new SearchQuery())->limit_to_products($query);
    $GLOBALS['wp_the_query'] = $previousMainQuery;
    $assert($query->get('post_type') === 'product', 'Integration query was not product-only for: ' . $term);
    $assert($query->get('post_status') === 'publish', 'Integration query was not publish-only for: ' . $term);
    $assert((int) $query->get('posts_per_page') === -1, 'Elementor result size behavior changed for: ' . $term);
}

remove_filter('pre_option_rewrite_rules', $rulesFilter);
if ($originalRequestUri === null) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $originalRequestUri;
}
if ($originalPhpSelf === null) {
    unset($_SERVER['PHP_SELF']);
} else {
    $_SERVER['PHP_SELF'] = $originalPhpSelf;
}

$normal = new WP_Query();
$normal->parse_query(['s' => 'topping']);
$previousMainQuery = $GLOBALS['wp_the_query'] ?? null;
$GLOBALS['wp_the_query'] = $normal;
(new SearchQuery())->limit_to_products($normal);
$GLOBALS['wp_the_query'] = $previousMainQuery;
$assert($normal->is_search(), 'Normal WordPress query is not a search.');
$assert($normal->get('post_type') !== 'product', 'Normal WordPress search was changed to product-only.');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "ProductSearch WordPress integration: OK\n";
