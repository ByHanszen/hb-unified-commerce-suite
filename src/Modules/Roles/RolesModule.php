<?php
// =============================
// src/Modules/Roles/RolesModule.php
// =============================
namespace HB\UCS\Modules\Roles;

if (!defined('ABSPATH')) exit;

class RolesModule {
    public function init(): void {
        // No frontend hooks; admin-only module.
        if (!is_admin()) return;

        // Ensure defaults exist.
        (new \HB\UCS\Modules\Roles\Storage\RolesSettingsStore())->ensure_defaults();
    }
}
