<?php
// =============================
// src/Modules/B2B/Support/Context.php
// =============================
namespace HB\UCS\Modules\B2B\Support;

if (!defined('ABSPATH')) exit;

class Context {
    private static int $forced_user_id = 0;

    public static function set_forced_user_id(int $user_id): void {
        self::$forced_user_id = max(0, $user_id);
    }

    public static function get_effective_user_id(): int {
        if (self::$forced_user_id > 0) {
            return self::$forced_user_id;
        }
        $uid = get_current_user_id();
        return $uid ? (int) $uid : 0;
    }

    public static function is_admin_safe_context(): bool {
        // We skip pricing/visibility modifications in admin, except when forced via AJAX order context.
        if (!is_admin()) return true;
        return self::$forced_user_id > 0;
    }
}
