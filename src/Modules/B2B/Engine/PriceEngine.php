<?php
// =============================
// src/Modules/B2B/Engine/PriceEngine.php
// =============================
namespace HB\UCS\Modules\B2B\Engine;

use HB\UCS\Modules\B2B\Storage\CustomerRulesStore;
use HB\UCS\Modules\B2B\Storage\ProfilesStore;
use HB\UCS\Modules\B2B\Storage\RoleRulesStore;
use HB\UCS\Modules\B2B\Storage\SettingsStore;
use HB\UCS\Modules\B2B\Support\Context;

if (!defined('ABSPATH')) exit;

class PriceEngine {
    private SettingsStore $settings;
    private RoleRulesStore $roleRules;
    private CustomerRulesStore $customerRules;
    private ProfilesStore $profiles;

    private static bool $guard = false;
    private static bool $html_guard = false;

    private static array $cache_category_terms = [];

    public function __construct(SettingsStore $settings) {
        $this->settings = $settings;
        $this->roleRules = new RoleRulesStore();
        $this->customerRules = new CustomerRulesStore();
        $this->profiles = new ProfilesStore();
    }

    private function round_wc(float $value, int $precision): float {
        if (function_exists('wc_get_tax_rounding_mode') && class_exists('\\Automattic\\WooCommerce\\Utilities\\NumberUtil')) {
            return (float) \Automattic\WooCommerce\Utilities\NumberUtil::round($value, $precision, (int) wc_get_tax_rounding_mode());
        }
        return (float) round($value, $precision);
    }

    public function filter_product_price($price, $product) {
        if (self::$guard) return $price;
        if (!Context::is_admin_safe_context()) return $price;
        if (!is_object($product) || !method_exists($product, 'get_id')) return $price;

        $user_id = Context::get_effective_user_id();
        if ($user_id <= 0) return $price;

        // If effective display mode is "original", do not apply any B2B pricing.
        $display = $this->resolve_display_settings($user_id);
        if (($display['mode'] ?? '') === 'original') {
            return $price;
        }

        $adjusted = $this->get_adjusted_price_raw($product, $user_id);
        if ($adjusted === null) return $price;

        return $adjusted;
    }

    public function reprice_cart_items($cart): void {
        if (!Context::is_admin_safe_context()) return;
        if (!is_object($cart) || !method_exists($cart, 'get_cart')) return;

        $user_id = Context::get_effective_user_id();
        if ($user_id <= 0) return;

        $display = $this->resolve_display_settings($user_id);
        if (($display['mode'] ?? '') === 'original') {
            return;
        }

        self::$guard = true;
        try {
            foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
                if (empty($cart_item['data']) || !is_object($cart_item['data'])) continue;
                $product = $cart_item['data'];

                $adjusted = $this->get_adjusted_price_raw($product, $user_id);
                if ($adjusted === null) continue;

                if (method_exists($product, 'set_price')) {
                    $product->set_price($adjusted);
                }
            }
        } finally {
            self::$guard = false;
        }
    }

    /**
     * Returns adjusted price in the store's "raw" format (incl/ex tax depending on settings),
     * or null when no B2B rule matches.
     */
    public function get_adjusted_price_raw($product, int $user_id): ?float {
        $display = $this->resolve_display_settings($user_id);
        if (($display['mode'] ?? '') === 'original') {
            return null;
        }

        if (!function_exists('wc_get_price_excluding_tax') || !function_exists('wc_get_price_including_tax')) {
            return null;
        }

        $s = $this->settings->get();
        $baseMode = (string)($s['price_base'] ?? 'regular');

        $base_raw = $this->get_base_raw_price($product, $baseMode);
        if ($base_raw === null) return null;

        $base_ex = (float) wc_get_price_excluding_tax($product, ['price' => $base_raw, 'qty' => 1]);

        $decision = $this->resolve_price_ex($product, $user_id, $base_ex);
        if ($decision === null) return null;

        $decimals = (int)($s['rounding_decimals'] ?? 2);
        $result_ex = $this->round_wc(max(0.0, (float) $decision), $decimals);

        if (wc_prices_include_tax()) {
            // IMPORTANT: wc_get_price_including_tax() will *not* add taxes when the store setting
            // "Prices entered with tax" is enabled. Since $result_ex is explicitly EX tax, we need
            // to convert it back to the store's raw inclusive format.
            //
            // We derive the effective multiplier from the base raw/ex values to stay consistent
            // with Woo settings (including non-base location adjustments).
            $base_raw_f = (float) $base_raw;
            $multiplier = ($base_ex > 0.0) ? ($base_raw_f / $base_ex) : 1.0;
            $raw = (float) ($result_ex * $multiplier);

            return (float) $this->round_wc(max(0.0, $raw), $decimals);
        }

        return $result_ex;
    }

    /** Returns unit price excl tax after applying rules, or null when no rule matches. */
    public function resolve_price_ex($product, int $user_id, float $base_ex): ?float {
        $display = $this->resolve_display_settings($user_id);
        if (($display['mode'] ?? '') === 'original') {
            return null;
        }

        $s = $this->settings->get();

        $customer = $this->customerRules->get($user_id);
        $customerProfiles = $this->profiles->profiles_for_user($user_id);

        $customerHasAnyConfig = !empty($customer) || $this->profiles_have_any_pricing($customerProfiles);
        $customerPricingMode = (string)($s['customer_pricing_mode'] ?? 'merge');

        // Customer layer
        $customerRule = $this->match_best_rule_for_product($product, $base_ex, $user_id, $customer, $customerProfiles, 'customer');
        if ($customerRule !== null) {
            return $customerRule;
        }

        // If customer pricing is override and customer has any config, stop here.
        if ($customerPricingMode === 'override' && $customerHasAnyConfig) {
            return null;
        }

        // Role layer
        $roleRule = $this->match_best_rule_for_product($product, $base_ex, $user_id, [], $this->profiles_for_user_roles($user_id), 'role');
        return $roleRule;
    }

    public function filter_price_html(string $price_html, $product): string {
        if (self::$html_guard) return $price_html;
        if (!Context::is_admin_safe_context()) return $price_html;
        if (!is_object($product)) return $price_html;

        $user_id = Context::get_effective_user_id();
        if ($user_id <= 0) return $price_html;

        $display = $this->resolve_display_settings($user_id);
        $mode = (string) ($display['mode'] ?? 'adjusted');
        $label = (string) ($display['label'] ?? '');

        if ($mode === '') {
            return $price_html;
        }

        // If we only want the adjusted price but with a label, prefix the existing HTML.
        if ($mode === 'adjusted') {
            if ($label === '') {
                return $price_html;
            }
            return '<span class="hb-ucs-b2b-price-label">' . esc_html($label) . '</span> ' . $price_html;
        }

        self::$html_guard = true;
        try {
            $s = $this->settings->get();
            $baseMode = (string)($s['price_base'] ?? 'regular');

            // Variable products: show a range based on visible children (bounded for performance).
            if (is_a($product, 'WC_Product_Variable') && method_exists($product, 'get_visible_children')) {
                $children = (array) $product->get_visible_children();
                if (count($children) > 60) {
                    // Too many variations: keep default HTML to avoid heavy computation.
                    return $price_html;
                }

                $orig_prices = [];
                $adj_prices = [];
                foreach ($children as $vid) {
                    $vid = (int) $vid;
                    if ($vid <= 0) continue;
                    $v = wc_get_product($vid);
                    if (!$v) continue;

                    $base_raw = $this->get_base_raw_price($v, $baseMode);
                    if ($base_raw === null) continue;

                    $adj_raw = $this->get_adjusted_price_raw($v, $user_id);
                    if ($adj_raw === null) {
                        $adj_raw = $base_raw;
                    }

                    $orig_prices[] = (float) wc_get_price_to_display($v, ['price' => $base_raw, 'qty' => 1]);
                    $adj_prices[] = (float) wc_get_price_to_display($v, ['price' => $adj_raw, 'qty' => 1]);
                }

                if (empty($orig_prices) || empty($adj_prices)) {
                    return $price_html;
                }

                $orig_min = min($orig_prices);
                $orig_max = max($orig_prices);
                $adj_min = min($adj_prices);
                $adj_max = max($adj_prices);

                // If adjusted equals original across the range, do not alter output.
                if (abs($orig_min - $adj_min) < 0.00001 && abs($orig_max - $adj_max) < 0.00001) {
                    return $price_html;
                }

                if ($mode === 'original') {
                    $from = wc_price($orig_min);
                    $to = wc_price($orig_max);
                    return $orig_min === $orig_max ? $from : wc_format_price_range($from, $to);
                }

                // both
                $orig_from = wc_price($orig_min);
                $orig_to = wc_price($orig_max);
                $adj_from = wc_price($adj_min);
                $adj_to = wc_price($adj_max);

                $orig_range = ($orig_min === $orig_max) ? $orig_from : wc_format_price_range($orig_from, $orig_to);
                $adj_range = ($adj_min === $adj_max) ? $adj_from : wc_format_price_range($adj_from, $adj_to);

                $label_html = $label !== '' ? '<span class="hb-ucs-b2b-price-label">' . esc_html($label) . '</span> ' : '';
                return '<del>' . $orig_range . '</del> <ins>' . $label_html . $adj_range . '</ins>';
            }

            // Simple/variation/etc.
            $base_raw = $this->get_base_raw_price($product, $baseMode);
            if ($base_raw === null) {
                return $price_html;
            }

            $adj_raw = $this->get_adjusted_price_raw($product, $user_id);
            if ($adj_raw === null) {
                // No rule match or mode original.
                if ($mode === 'original') {
                    $orig_display = (float) wc_get_price_to_display($product, ['price' => $base_raw, 'qty' => 1]);
                    return wc_price($orig_display);
                }
                return $price_html;
            }

            $orig_display = (float) wc_get_price_to_display($product, ['price' => $base_raw, 'qty' => 1]);
            $adj_display = (float) wc_get_price_to_display($product, ['price' => $adj_raw, 'qty' => 1]);

            if (abs($orig_display - $adj_display) < 0.00001) {
                return $price_html;
            }

            if ($mode === 'original') {
                return wc_price($orig_display);
            }

            $label_html = $label !== '' ? '<span class="hb-ucs-b2b-price-label">' . esc_html($label) . '</span> ' : '';
            return '<del>' . wc_price($orig_display) . '</del> <ins>' . $label_html . wc_price($adj_display) . '</ins>';
        } finally {
            self::$html_guard = false;
        }
    }

    public function filter_cart_item_price(string $price_html, array $cart_item, string $cart_item_key): string {
        if (self::$html_guard) return $price_html;
        if (!Context::is_admin_safe_context()) return $price_html;

        $user_id = Context::get_effective_user_id();
        if ($user_id <= 0) return $price_html;

        $display = $this->resolve_display_settings($user_id);
        $mode = (string) ($display['mode'] ?? 'adjusted');
        $label = (string) ($display['label'] ?? '');
        if ($mode === 'adjusted') {
            if ($label === '') return $price_html;
            return '<span class="hb-ucs-b2b-price-label">' . esc_html($label) . '</span> ' . $price_html;
        }
        if ($mode !== 'both') return $price_html;

        $product = $cart_item['data'] ?? null;
        if (!is_object($product)) return $price_html;

        $s = $this->settings->get();
        $baseMode = (string)($s['price_base'] ?? 'regular');
        $base_raw = $this->get_base_raw_price($product, $baseMode);
        if ($base_raw === null) return $price_html;

        $adj_raw = $this->get_adjusted_price_raw($product, $user_id);
        if ($adj_raw === null) return $price_html;

        $orig_display = (float) wc_get_price_to_display($product, ['price' => $base_raw, 'qty' => 1, 'display_context' => 'cart']);
        $adj_display = (float) wc_get_price_to_display($product, ['price' => $adj_raw, 'qty' => 1, 'display_context' => 'cart']);
        if (abs($orig_display - $adj_display) < 0.00001) return $price_html;

        $label_html = $label !== '' ? '<span class="hb-ucs-b2b-price-label">' . esc_html($label) . '</span> ' : '';
        return '<del>' . wc_price($orig_display) . '</del> <ins>' . $label_html . wc_price($adj_display) . '</ins>';
    }

    public function filter_cart_item_subtotal(string $subtotal_html, array $cart_item, string $cart_item_key): string {
        if (self::$html_guard) return $subtotal_html;
        if (!Context::is_admin_safe_context()) return $subtotal_html;

        $user_id = Context::get_effective_user_id();
        if ($user_id <= 0) return $subtotal_html;

        $display = $this->resolve_display_settings($user_id);
        $mode = (string) ($display['mode'] ?? 'adjusted');
        $label = (string) ($display['label'] ?? '');
        if ($mode === 'adjusted') {
            if ($label === '') return $subtotal_html;
            return '<span class="hb-ucs-b2b-price-label">' . esc_html($label) . '</span> ' . $subtotal_html;
        }
        if ($mode !== 'both') return $subtotal_html;

        $product = $cart_item['data'] ?? null;
        if (!is_object($product)) return $subtotal_html;

        $qty = isset($cart_item['quantity']) ? max(1, (int) $cart_item['quantity']) : 1;

        $s = $this->settings->get();
        $baseMode = (string)($s['price_base'] ?? 'regular');
        $base_raw = $this->get_base_raw_price($product, $baseMode);
        if ($base_raw === null) return $subtotal_html;

        $adj_raw = $this->get_adjusted_price_raw($product, $user_id);
        if ($adj_raw === null) return $subtotal_html;

        $orig_display = (float) wc_get_price_to_display($product, ['price' => $base_raw, 'qty' => $qty, 'display_context' => 'cart']);
        $adj_display = (float) wc_get_price_to_display($product, ['price' => $adj_raw, 'qty' => $qty, 'display_context' => 'cart']);
        if (abs($orig_display - $adj_display) < 0.00001) return $subtotal_html;

        $label_html = $label !== '' ? '<span class="hb-ucs-b2b-price-label">' . esc_html($label) . '</span> ' : '';
        return '<del>' . wc_price($orig_display) . '</del> <ins>' . $label_html . wc_price($adj_display) . '</ins>';
    }

    private function resolve_display_settings(int $user_id): array {
        static $cache = [];
        if ($user_id <= 0) {
            return ['mode' => 'adjusted', 'label' => ''];
        }
        if (isset($cache[$user_id])) {
            return $cache[$user_id];
        }

        $mode = '';
        $label = '';

        // 1) Customer explicit settings.
        $customer = $this->customerRules->get($user_id);
        $c_mode = is_array($customer) ? (string) ($customer['price_display_mode'] ?? '') : '';
        $c_label = is_array($customer) ? (string) ($customer['price_display_label'] ?? '') : '';
        if ($c_mode !== '') {
            $mode = $c_mode;
        }
        if ($c_label !== '') {
            $label = $c_label;
        }

        // 2) Profiles explicitly linked to the user.
        if ($mode === '' || $label === '') {
            $profiles = $this->profiles->profiles_for_user($user_id);
            $picked = $this->pick_display_from_sources($profiles);
            if ($mode === '' && ($picked['mode'] ?? '') !== '') {
                $mode = (string) $picked['mode'];
            }
            if ($label === '' && ($picked['label'] ?? '') !== '') {
                $label = (string) $picked['label'];
            }
        }

        // 3) Roles (direct role rules + profiles linked to roles).
        if ($mode === '' || $label === '') {
            $user = get_user_by('id', $user_id);
            $roles = $user ? (array) $user->roles : [];

            $role_sources = [];
            foreach ($roles as $role) {
                $role = (string) $role;
                $r = $this->roleRules->get($role);
                if (is_array($r) && !empty($r)) {
                    $role_sources[] = $r;
                }
                foreach ($this->profiles->profiles_for_role($role) as $p) {
                    if (is_array($p)) {
                        $role_sources[] = $p;
                    }
                }
            }

            $picked = $this->pick_display_from_sources($role_sources);
            if ($mode === '' && ($picked['mode'] ?? '') !== '') {
                $mode = (string) $picked['mode'];
            }
            if ($label === '' && ($picked['label'] ?? '') !== '') {
                $label = (string) $picked['label'];
            }
        }

        // Default
        if ($mode === '') {
            $mode = 'adjusted';
        }

        $cache[$user_id] = ['mode' => $mode, 'label' => $label];
        return $cache[$user_id];
    }

    private function pick_display_from_sources(array $sources): array {
        $modes = [];
        $label = '';
        foreach ($sources as $src) {
            if (!is_array($src)) continue;
            $m = (string) ($src['price_display_mode'] ?? '');
            $l = (string) ($src['price_display_label'] ?? '');
            if ($m !== '') {
                $modes[] = $m;
            }
            if ($label === '' && $l !== '') {
                $label = $l;
            }
        }

        $mode = '';
        // Priority: original (most restrictive) > both > adjusted.
        if (in_array('original', $modes, true)) {
            $mode = 'original';
        } elseif (in_array('both', $modes, true)) {
            $mode = 'both';
        } elseif (in_array('adjusted', $modes, true)) {
            $mode = 'adjusted';
        }

        return ['mode' => $mode, 'label' => $label];
    }

    private function get_base_raw_price($product, string $baseMode): ?float {
        // Use unfiltered edit-context getters.
        $regular = method_exists($product, 'get_regular_price') ? (string) $product->get_regular_price('edit') : '';
        $current = method_exists($product, 'get_price') ? (string) $product->get_price('edit') : '';

        $reg = is_numeric($regular) ? (float) $regular : null;
        $cur = is_numeric($current) ? (float) $current : null;

        if ($baseMode === 'current') {
            return $cur ?? $reg;
        }
        // regular
        return $reg ?? $cur;
    }

    private function profiles_have_any_pricing(array $profiles): bool {
        foreach ($profiles as $p) {
            if (!is_array($p)) continue;
            $pricing = (array)($p['pricing'] ?? []);
            if (!empty($pricing['categories']) || !empty($pricing['products'])) {
                return true;
            }
        }
        return false;
    }

    private function profiles_for_user_roles(int $user_id): array {
        $user = get_user_by('id', $user_id);
        if (!$user) return [];

        $out = [];
        foreach ((array)$user->roles as $role) {
            $role = (string) $role;
            foreach ($this->profiles->profiles_for_role($role) as $id => $p) {
                $out[$id] = $p;
            }
        }
        return $out;
    }

    /**
     * Match best rule among direct rules (array with 'pricing') plus profile pricing.
     * $layer indicates which storage to use for role rules.
     */
    private function match_best_rule_for_product($product, float $base_ex, int $user_id, array $directRules, array $profiles, string $layer): ?float {
        $s = $this->settings->get();
        $productFirst = !empty($s['priority_product_over_category']);

        $candidates = [];

        if ($layer === 'role') {
            // Collect direct role rules for each role.
            $user = get_user_by('id', $user_id);
            $roles = $user ? (array) $user->roles : [];
            foreach ($roles as $role) {
                $r = $this->roleRules->get((string)$role);
                if (is_array($r)) {
                    $candidates[] = $r;
                }
            }
        } else {
            if (!empty($directRules)) {
                $candidates[] = $directRules;
            }
        }

        // Convert to a combined structure with pricing maps.
        $pricingSources = [];
        foreach ($candidates as $r) {
            $pricingSources[] = [
                'products' => (array)(($r['pricing']['products'] ?? []) ?: ($r['pricing_products'] ?? [])),
                'categories' => (array)(($r['pricing']['categories'] ?? []) ?: ($r['pricing_categories'] ?? [])),
            ];
        }
        foreach ($profiles as $p) {
            if (!is_array($p)) continue;
            $pricing = (array)($p['pricing'] ?? []);
            $pricingSources[] = [
                'products' => (array)($pricing['products'] ?? []),
                'categories' => (array)($pricing['categories'] ?? []),
            ];
        }

        // Resolve in priority order: product vs category.
        if ($productFirst) {
            $rule = $this->resolve_product_rule($product, $base_ex, $pricingSources);
            if ($rule !== null) return $rule;
            return $this->resolve_category_rule($product, $base_ex, $pricingSources);
        }

        $rule = $this->resolve_category_rule($product, $base_ex, $pricingSources);
        if ($rule !== null) return $rule;
        return $this->resolve_product_rule($product, $base_ex, $pricingSources);
    }

    private function resolve_product_rule($product, float $base_ex, array $sources): ?float {
        $pid = method_exists($product, 'get_id') ? (int) $product->get_id() : 0;
        $parent = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;

        $rules = [];
        foreach ($sources as $src) {
            $products = (array)($src['products'] ?? []);
            if ($pid && isset($products[$pid])) {
                $rules[] = $products[$pid];
            } elseif ($parent && isset($products[$parent])) {
                $rules[] = $products[$parent];
            }
        }

        return $this->pick_best_price_from_rules($base_ex, $rules);
    }

    private function resolve_category_rule($product, float $base_ex, array $sources): ?float {
        $baseProductId = method_exists($product, 'get_parent_id') && $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
        if ($baseProductId <= 0) return null;

        if (isset(self::$cache_category_terms[$baseProductId])) {
            $all_terms = self::$cache_category_terms[$baseProductId];
        } else {
            $term_ids = wp_get_post_terms($baseProductId, 'product_cat', ['fields' => 'ids']);
            $term_ids = is_array($term_ids) ? array_map('intval', $term_ids) : [];

            // Include ancestors.
            $all_terms = [];
            foreach ($term_ids as $tid) {
                $all_terms[] = $tid;
                $anc = get_ancestors($tid, 'product_cat');
                if (is_array($anc)) {
                    foreach ($anc as $a) $all_terms[] = (int) $a;
                }
            }
            $all_terms = array_values(array_unique(array_filter($all_terms)));
            self::$cache_category_terms[$baseProductId] = $all_terms;
        }
        if (empty($all_terms)) return null;

        $rules = [];
        foreach ($sources as $src) {
            $cats = (array)($src['categories'] ?? []);
            foreach ($all_terms as $tid) {
                if (isset($cats[$tid])) {
                    $rules[] = $cats[$tid];
                }
            }
        }

        return $this->pick_best_price_from_rules($base_ex, $rules);
    }

    private function pick_best_price_from_rules(float $base_ex, array $rules): ?float {
        if (empty($rules)) return null;

        $s = $this->settings->get();
        $strategy = (string)($s['multi_role_price_strategy'] ?? 'lowest_price');
        $fixedWins = !empty($s['fixed_price_wins']);

        $candidates = [];
        foreach ($rules as $rule) {
            if (!is_array($rule) || empty($rule['type'])) continue;
            $type = (string) $rule['type'];
            $value = isset($rule['value']) ? (float) $rule['value'] : 0.0;
            $new = $this->apply_rule($base_ex, $type, $value);
            if ($new === null) continue;
            $candidates[] = [
                'type' => $type,
                'value' => $value,
                'price_ex' => $new,
                'discount_ex' => max(0.0, $base_ex - $new),
            ];
        }

        if (empty($candidates)) return null;

        if ($fixedWins) {
            $fixed = array_values(array_filter($candidates, fn($c) => ($c['type'] ?? '') === 'fixed_price'));
            if (!empty($fixed)) {
                $candidates = $fixed;
            }
        }

        usort($candidates, function ($a, $b) use ($strategy) {
            if ($strategy === 'highest_discount') {
                $d = ($b['discount_ex'] ?? 0) <=> ($a['discount_ex'] ?? 0);
                if ($d !== 0) return $d;
            }
            return ($a['price_ex'] ?? 0) <=> ($b['price_ex'] ?? 0);
        });

        return (float) $candidates[0]['price_ex'];
    }

    private function apply_rule(float $base_ex, string $type, float $value): ?float {
        $base_ex = max(0.0, $base_ex);
        $value = max(0.0, $value);

        if ($type === 'percent') {
            return max(0.0, $base_ex * (1 - ($value / 100.0)));
        }
        if ($type === 'fixed_price') {
            return max(0.0, $value);
        }
        if ($type === 'fixed_discount') {
            return max(0.0, $base_ex - $value);
        }
        return null;
    }
}
