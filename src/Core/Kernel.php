<?php
// =============================
// src/Core/Kernel.php
// =============================
namespace HB\UCS\Core;

if (!defined('ABSPATH')) exit;

class Kernel {
    public function boot(): void {
        // Init settings/admin UI
        (new Settings())->init();

        // Modules toggles
        $settings = get_option('hb_ucs_settings', []);
        $mods = $settings['modules'] ?? [];

        if (!empty($mods['invoice_email']) && class_exists('HB\\UCS\\Modules\\Customers\\InvoiceEmailModule')) {
            (new \HB\UCS\Modules\Customers\InvoiceEmailModule())->init();
        }
        if (!empty($mods['qls']) && class_exists('HB\\UCS\\Modules\\QLS\\QLSModule')) {
            (new \HB\UCS\Modules\QLS\QLSModule())->init();
        }
        if (!empty($mods['b2b']) && class_exists('HB\\UCS\\Modules\\B2B\\B2BModule')) {
            (new \HB\UCS\Modules\B2B\B2BModule())->init();
        }
        if (!empty($mods['roles']) && class_exists('HB\\UCS\\Modules\\Roles\\RolesModule')) {
            (new \HB\UCS\Modules\Roles\RolesModule())->init();
        }

        if (!empty($mods['subscriptions']) && class_exists('HB\\UCS\\Modules\\Subscriptions\\SubscriptionsModule')) {
            (new \HB\UCS\Modules\Subscriptions\SubscriptionsModule())->init();
        }

        if (!empty($mods['order_overview_status']) && class_exists('HB\\UCS\\Modules\\OrderOverviewStatus\\OrderOverviewStatusModule')) {
            (new \HB\UCS\Modules\OrderOverviewStatus\OrderOverviewStatusModule())->init();
        }

        if (!empty($mods['customer_order_note']) && class_exists('HB\\UCS\\Modules\\CustomerOrderNote\\CustomerOrderNoteModule')) {
            (new \HB\UCS\Modules\CustomerOrderNote\CustomerOrderNoteModule())->init();
        }
    }
}
