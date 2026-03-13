<?php
// =============================
// src/Modules/B2B/Storage/ProfilesStore.php
// =============================
namespace HB\UCS\Modules\B2B\Storage;

use HB\UCS\Modules\B2B\Support\Validator;

if (!defined('ABSPATH')) exit;

class ProfilesStore {
    public const OPT = 'hb_ucs_b2b_profiles';

    private static ?array $cache_all = null;
    private static array $cache_for_role = [];
    private static array $cache_for_user = [];

    private function resolve_id(string $profile_id, array $all): string {
        if ($profile_id === '' || $profile_id === 'new') return $profile_id;
        if (isset($all[$profile_id])) return $profile_id;

        $lower = strtolower($profile_id);
        if (isset($all[$lower])) return $lower;

        // Last resort: find matching key case-insensitively.
        foreach ($all as $key => $_) {
            $key = (string) $key;
            if (strtolower($key) === $lower) return $key;
        }
        return $profile_id;
    }

    public function all(): array {
        if (self::$cache_all !== null) {
            return self::$cache_all;
        }
        $opt = get_option(self::OPT, []);
        self::$cache_all = is_array($opt) ? $opt : [];
        return self::$cache_all;
    }

    public function get(string $profile_id): array {
        $all = $this->all();
        $profile_id = $this->resolve_id($profile_id, $all);
        $p = $all[$profile_id] ?? [];
        return is_array($p) ? $p : [];
    }

    public function save(string $profile_id, array $raw): string {
        $all = $this->all();

        // If this is an existing profile, ensure we keep its original key casing.
        if ($profile_id !== '' && $profile_id !== 'new') {
            $profile_id = $this->resolve_id($profile_id, $all);
        }

        if ($profile_id === '' || $profile_id === 'new') {
            do {
                $profile_id = 'p_' . strtolower(wp_generate_password(10, false, false));
            } while (isset($all[$profile_id]));
        }

        $name = Validator::sanitize_profile_name((string)($raw['name'] ?? ''));
        if ($name === '') {
            $name = 'B2B profiel';
        }

        $linked_roles = array_values(array_filter(array_map('strval', (array)($raw['linked_roles'] ?? []))));
        $linked_users = array_values(array_filter(array_map('intval', (array)($raw['linked_users'] ?? []))));

        // Validate roles.
        $roles = [];
        $wp_roles = wp_roles();
        if ($wp_roles && is_array($wp_roles->roles)) {
            $roles = array_keys($wp_roles->roles);
        }
        $linked_roles = array_values(array_intersect($linked_roles, $roles));

        // Validate users.
        $linked_users = array_values(array_filter($linked_users, function ($uid) {
            return $uid > 0 && get_user_by('id', $uid);
        }));

        $profile = [
            'id' => $profile_id,
            'name' => $name,
            'allowed_shipping' => Validator::sanitize_allowed_shipping($raw['allowed_shipping'] ?? []),
            'allowed_payments' => Validator::sanitize_allowed_payments($raw['allowed_payments'] ?? []),
            'price_display_mode' => Validator::sanitize_price_display_mode($raw['price_display_mode'] ?? ''),
            'price_display_label' => Validator::sanitize_price_display_label($raw['price_display_label'] ?? ''),
            'pricing' => [
                'categories' => Validator::parse_price_rules((array)($raw['pricing_categories'] ?? []), 'category'),
                'products' => Validator::parse_price_rules((array)($raw['pricing_products'] ?? []), 'product'),
            ],
            'linked_roles' => $linked_roles,
            'linked_users' => $linked_users,
        ];

        $all[$profile_id] = $profile;
        update_option(self::OPT, $all, false);

        self::$cache_all = null;
        self::$cache_for_role = [];
        self::$cache_for_user = [];
        return $profile_id;
    }

    public function delete(string $profile_id): void {
        $all = $this->all();
        $profile_id = $this->resolve_id($profile_id, $all);
        if (!isset($all[$profile_id])) return;
        unset($all[$profile_id]);
        update_option(self::OPT, $all, false);

        self::$cache_all = null;
        self::$cache_for_role = [];
        self::$cache_for_user = [];
    }

    public function profiles_for_role(string $role): array {
        $role = (string) $role;
        if ($role !== '' && isset(self::$cache_for_role[$role])) {
            return self::$cache_for_role[$role];
        }

        $out = [];
        foreach ($this->all() as $id => $p) {
            if (!is_array($p)) continue;
            $roles = (array)($p['linked_roles'] ?? []);
            if (in_array($role, $roles, true)) {
                $out[$id] = $p;
            }
        }
        if ($role !== '') {
            self::$cache_for_role[$role] = $out;
        }
        return $out;
    }

    public function profiles_for_user(int $user_id): array {
        if ($user_id > 0 && isset(self::$cache_for_user[$user_id])) {
            return self::$cache_for_user[$user_id];
        }

        $out = [];
        foreach ($this->all() as $id => $p) {
            if (!is_array($p)) continue;
            $users = array_map('intval', (array)($p['linked_users'] ?? []));
            if (in_array($user_id, $users, true)) {
                $out[$id] = $p;
            }
        }
        if ($user_id > 0) {
            self::$cache_for_user[$user_id] = $out;
        }
        return $out;
    }
}
