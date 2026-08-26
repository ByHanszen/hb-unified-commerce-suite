<?php

namespace HB\UCS\Modules\ProductSearch;

if (!defined('ABSPATH')) exit;

class SearchRewrite {
    /** @var array<string,mixed> */
    private $settings;

    public function __construct(array $settings) {
        $this->settings = $settings;
    }

    public function init(): void {
        add_filter('query_vars', [$this, 'register_query_var']);
        add_filter('request', [$this, 'add_search_term_to_request']);
        add_action('init', [$this, 'register_rewrite'], 10);
    }

    public function register_query_var(array $queryVars): array {
        if (!in_array(ProductSearchModule::QUERY_VAR, $queryVars, true)) {
            $queryVars[] = ProductSearchModule::QUERY_VAR;
        }

        return $queryVars;
    }

    public function register_rewrite(): void {
        $base = preg_quote($this->get_base(), '#');

        add_rewrite_rule(
            '^' . $base . '/([^/]+)/?$',
            'index.php?' . ProductSearchModule::QUERY_VAR . '=1',
            'top'
        );
        add_rewrite_rule(
            '^' . $base . '/([^/]+)/page/([0-9]+)/?$',
            'index.php?' . ProductSearchModule::QUERY_VAR . '=1&paged=$matches[2]',
            'top'
        );
    }

    public function add_search_term_to_request(array $queryVars): array {
        if (empty($queryVars[ProductSearchModule::QUERY_VAR])) {
            return $queryVars;
        }

        $term = $this->extract_search_term_from_request();
        if ($term !== '') {
            $queryVars['s'] = $term;
        }

        return $queryVars;
    }

    public function get_search_url(string $term): string {
        $term = self::normalize_search_term($term);
        if ($term === '') {
            return '';
        }

        $path = '/' . $this->get_base() . '/' . rawurlencode($term) . '/';

        return esc_url_raw(home_url($path));
    }

    public function get_base(): string {
        return self::normalize_base((string) ($this->settings['search_base'] ?? 'zoeken'));
    }

    public static function normalize_base(string $base): string {
        $base = sanitize_title(trim($base));

        return $base !== '' ? $base : 'zoeken';
    }

    public static function normalize_search_term(string $term): string {
        $term = sanitize_text_field($term);

        return trim($term);
    }

    public static function remove_registered_rules(): void {
        global $wp_rewrite;

        if (!is_object($wp_rewrite)) {
            return;
        }

        foreach (['extra_rules_top', 'extra_rules'] as $property) {
            if (!isset($wp_rewrite->{$property}) || !is_array($wp_rewrite->{$property})) {
                continue;
            }

            foreach ($wp_rewrite->{$property} as $pattern => $query) {
                if (strpos((string) $query, ProductSearchModule::QUERY_VAR . '=1') !== false) {
                    unset($wp_rewrite->{$property}[$pattern]);
                }
            }
        }
    }

    private function extract_search_term_from_request(): string {
        if (empty($_SERVER['REQUEST_URI'])) {
            return '';
        }

        $requestPath = wp_parse_url((string) wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH);
        $homePath = wp_parse_url(home_url('/'), PHP_URL_PATH);
        if (!is_string($requestPath)) {
            return '';
        }

        $relativePath = ltrim($requestPath, '/');
        $homePath = is_string($homePath) ? trim($homePath, '/') : '';
        if ($homePath !== '' && strpos($relativePath, $homePath . '/') === 0) {
            $relativePath = substr($relativePath, strlen($homePath) + 1);
        }

        $pattern = '#^' . preg_quote($this->get_base(), '#') . '/([^/]+)(?:/page/[0-9]+)?/?$#u';
        if (!preg_match($pattern, $relativePath, $matches)) {
            return '';
        }

        return self::normalize_search_term(rawurldecode((string) $matches[1]));
    }
}
