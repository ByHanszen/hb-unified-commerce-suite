<?php
// =============================
// src/Modules/Roles/Support/RolesGuard.php
// =============================
namespace HB\UCS\Modules\Roles\Support;

if (!defined('ABSPATH')) exit;

class RolesGuard {
    public static function can_manage(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    public static function protected_roles(): array {
        // Prevent foot-guns.
        return [
            'administrator',
            'editor',
            'author',
            'contributor',
            'subscriber',
            'customer',
            'shop_manager',
        ];
    }

    public static function can_delete_role(string $role): bool {
        $role = sanitize_key($role);
        if ($role === '') return false;
        if (in_array($role, self::protected_roles(), true)) return false;
        return (bool) get_role($role);
    }

    public static function role_label(string $role): string {
        $roles = wp_roles();
        if (!$roles || !isset($roles->roles[$role]['name'])) return $role;
        return (string) $roles->roles[$role]['name'];
    }

    /** Returns a map of capability => true from a role slug. */
    public static function caps_for_role(string $base_role): array {
        $base_role = sanitize_key($base_role);
        $r = get_role($base_role);
        if (!$r || !is_array($r->capabilities)) return [];
        $caps = [];
        foreach ($r->capabilities as $cap => $allowed) {
            if ($allowed) {
                $caps[(string)$cap] = true;
            }
        }
        ksort($caps);
        return $caps;
    }

    /** Union of all known caps across roles. */
    public static function all_known_caps(): array {
        $roles = wp_roles();
        if (!$roles || !is_array($roles->roles)) return [];
        $caps = [];
        foreach ($roles->roles as $roleKey => $roleData) {
            $roleCaps = (array)($roleData['capabilities'] ?? []);
            foreach ($roleCaps as $cap => $on) {
                $caps[(string)$cap] = true;
            }
        }
        ksort($caps);
        return array_keys($caps);
    }

    public static function sanitize_caps($raw): array {
        $raw = is_array($raw) ? $raw : [];
        $out = [];
        foreach ($raw as $cap) {
            $cap = sanitize_key((string)$cap);
            if ($cap === '') continue;
            $out[$cap] = true;
        }
        ksort($out);
        return $out;
    }

    public static function update_role_label(string $role, string $label): bool {
        $role = sanitize_key($role);
        $label = trim(wp_strip_all_tags($label));
        if ($role === '' || $label === '') return false;

        $wp_roles = wp_roles();
        if (!$wp_roles) return false;

        // Update in-memory
        if (isset($wp_roles->roles[$role])) {
            $wp_roles->roles[$role]['name'] = $label;
        }
        $wp_roles->role_names[$role] = $label;

        // Persist to DB
        $roleKey = $wp_roles->role_key;
        $db = get_option($roleKey);
        if (!is_array($db)) return false;
        if (!isset($db[$role])) return false;

        $db[$role]['name'] = $label;
        update_option($roleKey, $db);
        return true;
    }
}
