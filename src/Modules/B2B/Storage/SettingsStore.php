<?php
// =============================
// src/Modules/B2B/Storage/SettingsStore.php
// =============================
namespace HB\UCS\Modules\B2B\Storage;

use HB\UCS\Modules\B2B\Support\Validator;

if (!defined('ABSPATH')) exit;

class SettingsStore {
    public const OPT = 'hb_ucs_b2b_settings';
    private static ?array $cache = null;

    public function defaults(): array {
        return [
            'default_behavior' => 'woocommerce_all', // woocommerce_all | whitelist
            'default_allowed_shipping' => [],
            'default_allowed_payments' => [],

            // Guests (not logged in)
            // inherit_default | woocommerce_all | whitelist
            'guest_behavior' => 'inherit_default',
            'guest_allowed_shipping' => [],
            'guest_allowed_payments' => [],

            'roles_merge' => 'union', // union | intersection
            'customer_overrule_mode' => 'override', // override | merge (methods)
            'customer_pricing_mode' => 'merge', // merge | override (pricing)

            'price_base' => 'regular', // regular | current
            'rounding_decimals' => (function_exists('wc_get_price_decimals') ? (int) wc_get_price_decimals() : 2),
            'priority_product_over_category' => 1,

            'multi_role_price_strategy' => 'lowest_price', // lowest_price | highest_discount
            'fixed_price_wins' => 1,

            'graceful_fallback_methods' => 'all', // all | default | none

            'delete_data_on_uninstall' => 0,
            'admin_auto_recalc_on_customer_change' => 0,
        ];
    }

    public function get(): array {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $opt = get_option(self::OPT, []);
        if (!is_array($opt)) $opt = [];
        self::$cache = array_replace_recursive($this->defaults(), $opt);
        return self::$cache;
    }

    public function update(array $raw): array {
        $clean = $this->sanitize($raw);
        update_option(self::OPT, $clean, false);
        self::$cache = null;
        return $clean;
    }

    public function sanitize(array $raw): array {
        $d = $this->defaults();

        $default_behavior = isset($raw['default_behavior']) ? (string) $raw['default_behavior'] : $d['default_behavior'];
        if (!in_array($default_behavior, ['woocommerce_all', 'whitelist'], true)) {
            $default_behavior = $d['default_behavior'];
        }

        $roles_merge = isset($raw['roles_merge']) ? (string) $raw['roles_merge'] : $d['roles_merge'];
        if (!in_array($roles_merge, ['union', 'intersection'], true)) $roles_merge = $d['roles_merge'];

        $customer_overrule_mode = isset($raw['customer_overrule_mode']) ? (string) $raw['customer_overrule_mode'] : $d['customer_overrule_mode'];
        if (!in_array($customer_overrule_mode, ['override', 'merge'], true)) $customer_overrule_mode = $d['customer_overrule_mode'];

        $customer_pricing_mode = isset($raw['customer_pricing_mode']) ? (string) $raw['customer_pricing_mode'] : $d['customer_pricing_mode'];
        if (!in_array($customer_pricing_mode, ['override', 'merge'], true)) $customer_pricing_mode = $d['customer_pricing_mode'];

        $price_base = isset($raw['price_base']) ? (string) $raw['price_base'] : $d['price_base'];
        if (!in_array($price_base, ['regular', 'current'], true)) $price_base = $d['price_base'];

        $rounding = isset($raw['rounding_decimals']) ? (int) $raw['rounding_decimals'] : (int) $d['rounding_decimals'];
        if ($rounding < 0) $rounding = 0;
        if ($rounding > 6) $rounding = 6;

        $multi_role_price_strategy = isset($raw['multi_role_price_strategy']) ? (string) $raw['multi_role_price_strategy'] : $d['multi_role_price_strategy'];
        if (!in_array($multi_role_price_strategy, ['lowest_price', 'highest_discount'], true)) {
            $multi_role_price_strategy = $d['multi_role_price_strategy'];
        }

        $graceful = isset($raw['graceful_fallback_methods']) ? (string) $raw['graceful_fallback_methods'] : $d['graceful_fallback_methods'];
        if (!in_array($graceful, ['all', 'default', 'none'], true)) $graceful = $d['graceful_fallback_methods'];

        $guest_behavior = isset($raw['guest_behavior']) ? (string) $raw['guest_behavior'] : $d['guest_behavior'];
        if (!in_array($guest_behavior, ['inherit_default', 'woocommerce_all', 'whitelist'], true)) {
            $guest_behavior = $d['guest_behavior'];
        }

        return [
            'default_behavior' => $default_behavior,
            'default_allowed_shipping' => Validator::sanitize_allowed_shipping($raw['default_allowed_shipping'] ?? []),
            'default_allowed_payments' => Validator::sanitize_allowed_payments($raw['default_allowed_payments'] ?? []),

            'guest_behavior' => $guest_behavior,
            'guest_allowed_shipping' => Validator::sanitize_allowed_shipping($raw['guest_allowed_shipping'] ?? []),
            'guest_allowed_payments' => Validator::sanitize_allowed_payments($raw['guest_allowed_payments'] ?? []),

            'roles_merge' => $roles_merge,
            'customer_overrule_mode' => $customer_overrule_mode,
            'customer_pricing_mode' => $customer_pricing_mode,

            'price_base' => $price_base,
            'rounding_decimals' => $rounding,
            'priority_product_over_category' => empty($raw['priority_product_over_category']) ? 0 : 1,

            'multi_role_price_strategy' => $multi_role_price_strategy,
            'fixed_price_wins' => empty($raw['fixed_price_wins']) ? 0 : 1,

            'graceful_fallback_methods' => $graceful,

            'delete_data_on_uninstall' => empty($raw['delete_data_on_uninstall']) ? 0 : 1,
            'admin_auto_recalc_on_customer_change' => empty($raw['admin_auto_recalc_on_customer_change']) ? 0 : 1,
        ];
    }
}
