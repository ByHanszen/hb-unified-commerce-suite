<?php

namespace HB\UCS\Modules\ProductSearch;

if (!defined('ABSPATH')) exit;

class SearchRedirect {
    /** @var array<string,mixed> */
    private $settings;

    /** @var SearchRewrite */
    private $rewrite;

    public function __construct(array $settings, SearchRewrite $rewrite) {
        $this->settings = $settings;
        $this->rewrite = $rewrite;
    }

    public function init(): void {
        add_action('template_redirect', [$this, 'maybe_redirect'], 1);
    }

    public function maybe_redirect(): void {
        $url = $this->get_redirect_url();
        if ($url === '') {
            return;
        }

        wp_safe_redirect($url, 302, 'HB UCS Product Search');
        exit;
    }

    public function get_redirect_url(): string {
        if (empty($this->settings['redirect_legacy_elementor_urls']) || $this->is_background_request()) {
            return '';
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            return '';
        }

        if ((string) get_query_var(ProductSearchModule::QUERY_VAR, '') === '1') {
            return '';
        }

        if (!isset($_GET['s'], $_GET['e_search_props'])) {
            return '';
        }

        $elementorProps = sanitize_text_field((string) wp_unslash($_GET['e_search_props']));
        if (!preg_match('/^[A-Za-z0-9]+-[0-9]+$/', $elementorProps)) {
            return '';
        }

        $term = SearchRewrite::normalize_search_term((string) wp_unslash($_GET['s']));

        return $term !== '' ? $this->rewrite->get_search_url($term) : '';
    }

    private function is_background_request(): bool {
        if (is_admin()) {
            return true;
        }

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }

        if (function_exists('wp_doing_cron') && wp_doing_cron()) {
            return true;
        }

        return (defined('REST_REQUEST') && REST_REQUEST)
            || (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST)
            || (defined('WP_CLI') && WP_CLI);
    }
}
