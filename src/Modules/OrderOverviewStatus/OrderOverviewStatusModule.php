<?php
// =============================
// src/Modules/OrderOverviewStatus/OrderOverviewStatusModule.php
// =============================
namespace HB\UCS\Modules\OrderOverviewStatus;

use HB\UCS\Modules\OrderOverviewStatus\Storage\SettingsStore;

if (!defined('ABSPATH')) exit;

class OrderOverviewStatusModule {
    public function init(): void {
        if (!is_admin()) {
            return;
        }

        if (!class_exists('WooCommerce')) {
            return;
        }

        $settings = new SettingsStore();
        $settings->ensure_defaults();

        $listClass = '\\HB\\UCS\\Modules\\OrderOverviewStatus\\Admin\\OrderOverviewStatusList';
        if (class_exists($listClass)) {
            (new $listClass($settings))->init();
        }
    }
}
