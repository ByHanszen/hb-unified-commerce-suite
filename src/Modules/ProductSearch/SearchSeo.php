<?php

namespace HB\UCS\Modules\ProductSearch;

if (!defined('ABSPATH')) exit;

class SearchSeo {
    /** @var array<string,mixed> */
    private $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        add_filter('redirect_canonical', [$this, 'prevent_query_string_canonical_redirect'], 10, 2);

        if (!empty($this->settings['noindex'])) {
            add_filter('wp_robots', [$this, 'add_noindex_follow'], 20);
        }

        // Search pages are noindex and should not advertise an alternate legacy canonical.
        add_filter('wpseo_canonical', [$this, 'disable_seo_plugin_canonical']);
        add_filter('rank_math/frontend/canonical', [$this, 'disable_seo_plugin_canonical']);
        add_filter('aioseo_canonical_url', [$this, 'disable_seo_plugin_canonical']);
    }

    public function add_noindex_follow(array $robots): array {
        if (!$this->is_product_search()) {
            return $robots;
        }

        unset($robots['index'], $robots['nofollow']);
        $robots['noindex'] = true;
        $robots['follow'] = true;

        return $robots;
    }

    public function prevent_query_string_canonical_redirect($redirectUrl, $requestedUrl) {
        return $this->is_product_search() ? false : $redirectUrl;
    }

    public function disable_seo_plugin_canonical($canonical) {
        return $this->is_product_search() ? false : $canonical;
    }

    private function is_product_search(): bool {
        return is_search() && (string) get_query_var(ProductSearchModule::QUERY_VAR, '') === '1';
    }
}
