<?php
// =============================
// src/Modules/Roles/Storage/RolesSettingsStore.php
// =============================
namespace HB\UCS\Modules\Roles\Storage;

if (!defined('ABSPATH')) exit;

class RolesSettingsStore {
    public const OPT = 'hb_ucs_roles_settings';

    public function defaults(): array {
        return [
            'delete_data_on_uninstall' => 0,
            'reassign_users_on_delete' => 1,
            'reassign_target_role' => 'customer',
        ];
    }

    public function ensure_defaults(): void {
        add_option(self::OPT, $this->defaults());
    }

    public function get(): array {
        $opt = get_option(self::OPT, []);
        if (!is_array($opt)) $opt = [];
        return array_replace_recursive($this->defaults(), $opt);
    }

    public function update(array $raw): array {
        $clean = $this->sanitize($raw);
        update_option(self::OPT, $clean, false);
        return $clean;
    }

    public function sanitize(array $raw): array {
        $d = $this->defaults();

        $target = isset($raw['reassign_target_role']) ? sanitize_key((string)$raw['reassign_target_role']) : $d['reassign_target_role'];
        $roles = wp_roles();
        $known = $roles && is_array($roles->roles) ? array_keys($roles->roles) : [];
        if (!in_array($target, $known, true)) {
            $target = $d['reassign_target_role'];
        }

        return [
            'delete_data_on_uninstall' => empty($raw['delete_data_on_uninstall']) ? 0 : 1,
            'reassign_users_on_delete' => empty($raw['reassign_users_on_delete']) ? 0 : 1,
            'reassign_target_role' => $target,
        ];
    }
}
