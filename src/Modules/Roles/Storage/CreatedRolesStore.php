<?php
// =============================
// src/Modules/Roles/Storage/CreatedRolesStore.php
// =============================
namespace HB\UCS\Modules\Roles\Storage;

if (!defined('ABSPATH')) exit;

class CreatedRolesStore {
    public const OPT = 'hb_ucs_roles_created';

    public function all(): array {
        $opt = get_option(self::OPT, []);
        if (!is_array($opt)) return [];
        return array_values(array_filter(array_map('sanitize_key', $opt)));
    }

    public function add(string $role): void {
        $role = sanitize_key($role);
        if ($role === '') return;
        $all = $this->all();
        if (!in_array($role, $all, true)) {
            $all[] = $role;
            update_option(self::OPT, $all, false);
        }
    }

    public function remove(string $role): void {
        $role = sanitize_key($role);
        $all = $this->all();
        $all = array_values(array_diff($all, [$role]));
        update_option(self::OPT, $all, false);
    }

    public function delete_all(): void {
        delete_option(self::OPT);
    }

    public function is_managed(string $role): bool {
        return in_array(sanitize_key($role), $this->all(), true);
    }
}
