<?php
// =============================
// src/Modules/OrderOverviewStatus/Support/Permissions.php
// =============================
namespace HB\UCS\Modules\OrderOverviewStatus\Support;

if (!defined('ABSPATH')) exit;

class Permissions {
    public static function can_manage_module(): bool {
        return current_user_can('manage_options') || current_user_can('manage_woocommerce');
    }

    public static function can_edit_orders(): bool {
        return current_user_can('edit_shop_orders')
            || current_user_can('edit_others_shop_orders')
            || current_user_can('manage_woocommerce')
            || current_user_can('manage_options');
    }
}
