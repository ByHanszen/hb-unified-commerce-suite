<?php
// =============================
// src/Modules/B2B/Storage/RoleRulesStore.php
// =============================
namespace HB\UCS\Modules\B2B\Storage;

use HB\UCS\Modules\B2B\Support\Validator;

if (!defined('ABSPATH')) exit;

class RoleRulesStore {
    public const OPT = 'hb_ucs_b2b_role_rules';
    private static ?array $cache_all = null;

    public function all(): array {
        if (self::$cache_all !== null) {
            return self::$cache_all;
        }
        $opt = get_option(self::OPT, []);
        self::$cache_all = is_array($opt) ? $opt : [];
        return self::$cache_all;
    }

    public function get(string $role): array {
        $all = $this->all();
        $r = $all[$role] ?? [];
        return is_array($r) ? $r : [];
    }

    public function save(string $role, array $raw): void {
        $all = $this->all();

        $wp_roles = wp_roles();
        $roles = $wp_roles && is_array($wp_roles->roles) ? array_keys($wp_roles->roles) : [];
        if (!in_array($role, $roles, true)) return;

        $all[$role] = [
            'role' => $role,
            'allowed_shipping' => Validator::sanitize_allowed_shipping($raw['allowed_shipping'] ?? []),
            'allowed_payments' => Validator::sanitize_allowed_payments($raw['allowed_payments'] ?? []),
            'price_display_mode' => Validator::sanitize_price_display_mode($raw['price_display_mode'] ?? ''),
            'price_display_label' => Validator::sanitize_price_display_label($raw['price_display_label'] ?? ''),
            'pricing' => [
                'categories' => Validator::parse_price_rules((array)($raw['pricing_categories'] ?? []), 'category'),
                'products' => Validator::parse_price_rules((array)($raw['pricing_products'] ?? []), 'product'),
            ],
        ];

        update_option(self::OPT, $all, false);
        self::$cache_all = null;
    }

    public function delete(string $role): void {
        $all = $this->all();
        if (!isset($all[$role])) return;
        unset($all[$role]);
        update_option(self::OPT, $all, false);
        self::$cache_all = null;
    }
}
