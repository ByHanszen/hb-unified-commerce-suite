<?php
// =============================
// src/Modules/B2B/Storage/CustomerRulesStore.php
// =============================
namespace HB\UCS\Modules\B2B\Storage;

use HB\UCS\Modules\B2B\Support\Validator;

if (!defined('ABSPATH')) exit;

class CustomerRulesStore {
    public const META = 'hb_ucs_b2b_customer_rules';
    private static array $cache = [];

    public function get(int $user_id): array {
        if ($user_id <= 0) return [];

        if (isset(self::$cache[$user_id])) {
            return self::$cache[$user_id];
        }

        $val = get_user_meta($user_id, self::META, true);
        self::$cache[$user_id] = is_array($val) ? $val : [];
        return self::$cache[$user_id];
    }

    public function save(int $user_id, array $raw): void {
        if ($user_id <= 0) return;
        if (!get_user_by('id', $user_id)) return;

        $clean = [
            'allowed_shipping' => Validator::sanitize_allowed_shipping($raw['allowed_shipping'] ?? []),
            'allowed_payments' => Validator::sanitize_allowed_payments($raw['allowed_payments'] ?? []),
            'price_display_mode' => Validator::sanitize_price_display_mode($raw['price_display_mode'] ?? ''),
            'price_display_label' => Validator::sanitize_price_display_label($raw['price_display_label'] ?? ''),
            'pricing' => [
                'categories' => Validator::parse_price_rules((array)($raw['pricing_categories'] ?? []), 'category'),
                'products' => Validator::parse_price_rules((array)($raw['pricing_products'] ?? []), 'product'),
            ],
        ];

        update_user_meta($user_id, self::META, $clean);
        self::$cache[$user_id] = $clean;
    }

    public function delete(int $user_id): void {
        if ($user_id <= 0) return;
        delete_user_meta($user_id, self::META);
        unset(self::$cache[$user_id]);
    }

    public function has_any_config(int $user_id): bool {
        $r = $this->get($user_id);
        if (empty($r)) return false;
        if (!empty($r['allowed_shipping']) || !empty($r['allowed_payments'])) return true;
        $pricing = (array)($r['pricing'] ?? []);
        if (!empty($pricing['categories']) || !empty($pricing['products'])) return true;
        return false;
    }
}
