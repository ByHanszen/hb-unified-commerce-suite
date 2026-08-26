<?php
/**
 * Isolated smoke tests for ProductSearch routing, query scoping and redirects.
 *
 * Run: php tests/product-search/runtime-smoke.php
 */

if (PHP_SAPI !== 'cli') {
    exit;
}

define('ABSPATH', __DIR__ . '/');

$GLOBALS['hb_test_rewrite_rules'] = [];
$GLOBALS['hb_test_query_vars'] = [];
$GLOBALS['hb_test_is_admin'] = false;
$GLOBALS['hb_test_doing_ajax'] = false;
$GLOBALS['hb_test_doing_cron'] = false;
$GLOBALS['hb_test_is_search'] = false;

function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1) {}
function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1) {}
function add_rewrite_rule($regex, $query, $position = 'bottom') {
    $GLOBALS['hb_test_rewrite_rules'][$regex] = $query;
}
function sanitize_title($value) {
    $value = strtolower(trim((string) $value));
    $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
    return trim((string) $value, '-');
}
function sanitize_text_field($value) {
    return preg_replace('/[\r\n\t]+/u', ' ', strip_tags((string) $value));
}
function esc_url_raw($value) { return (string) $value; }
function home_url($path = '') { return 'https://example.test' . (string) $path; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_unslash($value) { return $value; }
function get_query_var($key, $default = '') {
    return $GLOBALS['hb_test_query_vars'][$key] ?? $default;
}
function is_admin() { return $GLOBALS['hb_test_is_admin']; }
function wp_doing_ajax() { return $GLOBALS['hb_test_doing_ajax']; }
function wp_doing_cron() { return $GLOBALS['hb_test_doing_cron']; }
function is_search() { return $GLOBALS['hb_test_is_search']; }
function wc_get_product_visibility_term_ids() { return ['exclude-from-search' => 42]; }

class WP_Query {
    /** @var array<string,mixed> */
    public $vars;
    /** @var bool */
    private $main;
    /** @var bool */
    private $search;

    public function __construct(array $vars = [], bool $main = true, bool $search = true) {
        $this->vars = $vars;
        $this->main = $main;
        $this->search = $search;
    }

    public function is_main_query(): bool { return $this->main; }
    public function is_search(): bool { return $this->search; }
    public function get($key) { return $this->vars[$key] ?? ''; }
    public function set($key, $value): void { $this->vars[$key] = $value; }
}

require_once dirname(__DIR__, 2) . '/src/Modules/ProductSearch/ProductSearchModule.php';
require_once dirname(__DIR__, 2) . '/src/Modules/ProductSearch/SearchRewrite.php';
require_once dirname(__DIR__, 2) . '/src/Modules/ProductSearch/SearchQuery.php';
require_once dirname(__DIR__, 2) . '/src/Modules/ProductSearch/SearchRedirect.php';
require_once dirname(__DIR__, 2) . '/src/Modules/ProductSearch/SearchSeo.php';

use HB\UCS\Modules\ProductSearch\ProductSearchModule;
use HB\UCS\Modules\ProductSearch\SearchQuery;
use HB\UCS\Modules\ProductSearch\SearchRedirect;
use HB\UCS\Modules\ProductSearch\SearchRewrite;
use HB\UCS\Modules\ProductSearch\SearchSeo;

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$settings = ProductSearchModule::defaults();
$rewrite = new SearchRewrite($settings);
$rewrite->register_rewrite();

// 1. The clean route is recognized, including an optional pretty page number.
$mainRule = null;
$pageRule = null;
foreach ($GLOBALS['hb_test_rewrite_rules'] as $regex => $query) {
    if (preg_match('#' . $regex . '#', 'zoeken/topping/')) {
        $mainRule = $query;
    }
    if (preg_match('#' . $regex . '#', 'zoeken/topping/page/2/')) {
        $pageRule = $query;
    }
}
$assert(is_string($mainRule) && strpos($mainRule, ProductSearchModule::QUERY_VAR . '=1') !== false, 'Clean search route was not registered.');
$assert(is_string($pageRule) && strpos($pageRule, 'paged=$matches[2]') !== false, 'Pretty pagination route was not registered.');

// 2 and 3. The request gets a real `s` value and keeps the module query var.
$_SERVER['REQUEST_URI'] = '/zoeken/topping/';
$requestVars = $rewrite->add_search_term_to_request([ProductSearchModule::QUERY_VAR => '1']);
$assert(($requestVars['s'] ?? '') === 'topping', 'Clean route did not preserve the search term.');
$assert(($requestVars[ProductSearchModule::QUERY_VAR] ?? '') === '1', 'Product search query var was lost.');
$query = new WP_Query($requestVars, true, isset($requestVars['s']));
(new SearchQuery())->limit_to_products($query);
$assert($query->is_search(), 'is_search() did not remain true.');

// 4. Only published products are queried, with search-hidden products excluded.
$assert(($query->vars['post_type'] ?? '') === 'product', 'Product search was not limited to products.');
$assert(($query->vars['post_status'] ?? '') === 'publish', 'Product search was not limited to published posts.');
$assert(($query->vars['posts_per_page'] ?? 0) === -1, 'Elementor result size behavior was not preserved.');
$visibility = $query->vars['tax_query'][0] ?? [];
$assert(($visibility['taxonomy'] ?? '') === 'product_visibility' && ($visibility['operator'] ?? '') === 'NOT IN' && in_array(42, (array) ($visibility['terms'] ?? []), true), 'WooCommerce search visibility was not preserved.');

// 5. A normal WordPress search remains untouched.
$normalQuery = new WP_Query(['s' => 'topping'], true, true);
(new SearchQuery())->limit_to_products($normalQuery);
$assert(!isset($normalQuery->vars['post_type'], $normalQuery->vars['post_status']), 'Normal WordPress search was modified.');

$redirect = new SearchRedirect($settings, $rewrite);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = ['s' => 'topping', 'e_search_props' => 'c09f63d-21880'];

// 6. A valid Elementor legacy URL redirects to the clean route.
$assert($redirect->get_redirect_url() === 'https://example.test/zoeken/topping/', 'Elementor legacy redirect URL is invalid.');

// 7. AJAX requests do not redirect.
$GLOBALS['hb_test_doing_ajax'] = true;
$assert($redirect->get_redirect_url() === '', 'AJAX request was redirected.');
$GLOBALS['hb_test_doing_ajax'] = false;

// 8. Admin requests do not redirect.
$GLOBALS['hb_test_is_admin'] = true;
$assert($redirect->get_redirect_url() === '', 'Admin request was redirected.');
$GLOBALS['hb_test_is_admin'] = false;

// 9. The clean route never redirects again.
$GLOBALS['hb_test_query_vars'][ProductSearchModule::QUERY_VAR] = '1';
$assert($redirect->get_redirect_url() === '', 'Clean route caused a redirect loop.');
$GLOBALS['hb_test_query_vars'] = [];

// 10. Spaces, UTF-8, case and percent characters round-trip without slugification.
foreach (['topping', 'house blend', 'decafé', 'Etna', '100% arabica'] as $term) {
    $url = $rewrite->get_search_url($term);
    $path = (string) parse_url($url, PHP_URL_PATH);
    $_SERVER['REQUEST_URI'] = $path;
    $vars = $rewrite->add_search_term_to_request([ProductSearchModule::QUERY_VAR => '1']);
    $assert(($vars['s'] ?? '') === $term, 'Search term did not round-trip: ' . $term);
}
$assert(strpos($rewrite->get_search_url('house blend'), 'house%20blend') !== false, 'Space was not URL encoded.');
$assert(strpos($rewrite->get_search_url('decafé'), 'decaf%C3%A9') !== false, 'UTF-8 was not URL encoded.');
$assert(strpos($rewrite->get_search_url('100% arabica'), '100%25%20arabica') !== false, 'Percent character was not URL encoded.');

// 11. An empty search term does not produce a broken URL or redirect.
$assert($rewrite->get_search_url('   ') === '', 'Empty term produced a clean URL.');
$_GET = ['s' => '   ', 'e_search_props' => 'c09f63d-21880'];
$assert($redirect->get_redirect_url() === '', 'Empty term caused a redirect.');

// SEO filters merge into one robots array and suppress a legacy canonical only for the clean search.
$GLOBALS['hb_test_is_search'] = true;
$GLOBALS['hb_test_query_vars'][ProductSearchModule::QUERY_VAR] = '1';
$seo = new SearchSeo($settings);
$robots = $seo->add_noindex_follow(['max-image-preview' => 'large']);
$assert(!empty($robots['noindex']) && !empty($robots['follow']), 'Product search robots are not noindex, follow.');
$assert($seo->disable_seo_plugin_canonical('https://example.test/?s=topping') === false, 'Legacy canonical was not suppressed.');

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "ProductSearch smoke tests: OK\n";
