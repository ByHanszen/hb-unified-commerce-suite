<?php

namespace HB\UCS\Modules\ProductSearch;

if (!defined('ABSPATH')) exit;

class SearchQuery {
    public function init(): void {
        add_action('pre_get_posts', [$this, 'limit_to_products'], 20);
    }

    public function limit_to_products($query): void {
        if (!$query instanceof \WP_Query || !$query->is_main_query() || !$query->is_search()) {
            return;
        }

        if ((string) $query->get(ProductSearchModule::QUERY_VAR) !== '1') {
            return;
        }

        $query->set('post_type', 'product');
        $query->set('post_status', 'publish');
        $query->set('posts_per_page', -1);

        $this->preserve_catalog_search_visibility($query);
    }

    private function preserve_catalog_search_visibility(\WP_Query $query): void {
        if (!function_exists('wc_get_product_visibility_term_ids')) {
            return;
        }

        $visibilityTerms = wc_get_product_visibility_term_ids();
        $excludeTermId = isset($visibilityTerms['exclude-from-search'])
            ? (int) $visibilityTerms['exclude-from-search']
            : 0;

        if ($excludeTermId <= 0) {
            return;
        }

        $taxQuery = $query->get('tax_query');
        $taxQuery = is_array($taxQuery) ? $taxQuery : [];

        if ($this->contains_exclude_from_search_clause($taxQuery, $excludeTermId)) {
            return;
        }

        $taxQuery[] = [
            'taxonomy' => 'product_visibility',
            'field' => 'term_taxonomy_id',
            'terms' => [$excludeTermId],
            'operator' => 'NOT IN',
        ];
        $query->set('tax_query', $taxQuery);
    }

    private function contains_exclude_from_search_clause(array $clauses, int $excludeTermId): bool {
        foreach ($clauses as $clause) {
            if (!is_array($clause)) {
                continue;
            }

            if (isset($clause['taxonomy']) && $clause['taxonomy'] === 'product_visibility') {
                $operator = strtoupper((string) ($clause['operator'] ?? 'IN'));
                $terms = array_map('intval', (array) ($clause['terms'] ?? []));
                if ($operator === 'NOT IN' && in_array($excludeTermId, $terms, true)) {
                    return true;
                }
            }

            if ($this->contains_exclude_from_search_clause($clause, $excludeTermId)) {
                return true;
            }
        }

        return false;
    }
}
