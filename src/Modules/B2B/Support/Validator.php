<?php
// =============================
// src/Modules/B2B/Support/Validator.php
// =============================
namespace HB\UCS\Modules\B2B\Support;

if (!defined('ABSPATH')) exit;

class Validator {
    public const RULE_TYPES = ['percent', 'fixed_price', 'fixed_discount'];
    public const PRICE_DISPLAY_MODES = ['adjusted', 'original', 'both'];

    private static ?array $cache_shipping_choices = null;
    private static ?array $cache_payment_choices = null;

    public static function can_manage(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * Returns [shipping_id => label] with both type ids (e.g. flat_rate)
     * and instance ids (e.g. flat_rate:3).
     */
    public static function shipping_choices(): array {
        if (self::$cache_shipping_choices !== null) {
            return self::$cache_shipping_choices;
        }

        $choices = [];

        // Ensure shipping is initialized on admin pages before reading zones/method instances.
        $shipping = null;
        if (function_exists('WC')) {
            $wc = WC();
            if ($wc && method_exists($wc, 'shipping')) {
                $shipping = $wc->shipping();
                if ($shipping && method_exists($shipping, 'init')) {
                    $shipping->init();
                }
                if ($shipping && method_exists($shipping, 'load_shipping_methods')) {
                    $shipping->load_shipping_methods();
                }
            }
        }

        // Always include shipping METHOD TYPES, even when there are no zone instances.
        if (is_object($shipping) && method_exists($shipping, 'get_shipping_methods')) {
            foreach ((array) $shipping->get_shipping_methods() as $mid => $method) {
                $mid = (string) $mid;
                if ($mid === '') continue;

                $label = $mid;
                if (is_object($method)) {
                    if (method_exists($method, 'get_method_title')) {
                        $label = (string) $method->get_method_title();
                    } elseif (property_exists($method, 'method_title')) {
                        $label = (string) $method->method_title;
                    }
                }
                $label = trim($label);
                if ($label === '') {
                    $label = $mid;
                }

                if (!isset($choices[$mid])) {
                    $choices[$mid] = sprintf('%s (%s)', $label, $mid);
                }
            }
        }

        // If zones are not loaded/available, fall back to types only.
        if (!class_exists('WC_Shipping_Zones')) {
            asort($choices, SORT_NATURAL);
            return $choices;
        }

        // Zones via public API.
        $zones_raw = \WC_Shipping_Zones::get_zones();

        $iterate_zone_methods = static function ($methods, string $zone_name) use (&$choices): void {
            foreach ((array) $methods as $method) {
                if (!is_object($method)) continue;
                $mid = method_exists($method, 'get_method_id') ? (string) $method->get_method_id() : '';
                $iid = method_exists($method, 'get_instance_id') ? (int) $method->get_instance_id() : 0;
                $title = method_exists($method, 'get_title') ? (string) $method->get_title() : $mid;

                $enabled_suffix = '';
                if (is_object($method) && method_exists($method, 'is_enabled')) {
                    $enabled_suffix = $method->is_enabled() ? '' : ' — ' . __('uitgeschakeld', 'hb-ucs');
                } elseif (is_object($method) && property_exists($method, 'enabled')) {
                    $enabled_suffix = ((string) $method->enabled === 'yes') ? '' : ' — ' . __('uitgeschakeld', 'hb-ucs');
                }

                if ($mid === '' || $iid <= 0) {
                    continue;
                }

                $key = $mid . ':' . $iid;
                if (!isset($choices[$key])) {
                    // Prefer the instance title (as configured in WooCommerce) as primary label.
                    $choices[$key] = sprintf('%s — %s (%s)%s', $title, $zone_name, $key, $enabled_suffix);
                }
            }
        };

        // Prefer reading the already-materialized shipping methods from WC_Shipping_Zones::get_zones().
        if (is_array($zones_raw)) {
            foreach ($zones_raw as $zr) {
                if (!is_array($zr)) {
                    continue;
                }

                $zone_name = isset($zr['zone_name']) ? (string) $zr['zone_name'] : '';
                $methods = $zr['shipping_methods'] ?? null;
                if (is_array($methods)) {
                    $iterate_zone_methods($methods, $zone_name);
                    continue;
                }

                // Fallback: instantiate zone and fetch methods.
                $zid = isset($zr['id']) ? (int) $zr['id'] : (int) ($zr['zone_id'] ?? 0);
                if ($zid <= 0) continue;
                $zone = new \WC_Shipping_Zone($zid);
                if (is_object($zone) && method_exists($zone, 'get_shipping_methods')) {
                    $zn = method_exists($zone, 'get_zone_name') ? (string) $zone->get_zone_name() : $zone_name;
                    $iterate_zone_methods($zone->get_shipping_methods(false), $zn);
                }
            }
        }

        // Always include "Rest of the world" zone (zone_id = 0).
        $zone0 = null;
        if (method_exists('\\WC_Shipping_Zones', 'get_zone')) {
            $zone0 = \WC_Shipping_Zones::get_zone(0);
        }
        if (!$zone0) {
            $zone0 = new \WC_Shipping_Zone(0);
        }
        if (is_object($zone0) && method_exists($zone0, 'get_shipping_methods')) {
            $zone0_name = method_exists($zone0, 'get_zone_name') ? (string) $zone0->get_zone_name() : '';
            $iterate_zone_methods($zone0->get_shipping_methods(false), $zone0_name);
        }

        asort($choices, SORT_NATURAL);
        self::$cache_shipping_choices = $choices;
        return self::$cache_shipping_choices;
    }

    /** Returns [gateway_id => title] */
    public static function payment_gateway_choices(): array {
        if (self::$cache_payment_choices !== null) {
            return self::$cache_payment_choices;
        }

        if (!function_exists('WC')) return [];
        $wc = WC();
        if (!$wc || !method_exists($wc, 'payment_gateways')) return [];

        $pg = $wc->payment_gateways();
        if (!$pg || !method_exists($pg, 'payment_gateways')) return [];

        // Ensure gateways are loaded.
        if (method_exists($pg, 'init')) {
            $pg->init();
        }

        $choices = [];
        foreach ((array) $pg->payment_gateways() as $gateway) {
            if (!is_object($gateway) || empty($gateway->id)) continue;
            $id = (string) $gateway->id;
            $title = method_exists($gateway, 'get_title') ? (string) $gateway->get_title() : $id;
            $choices[$id] = $title;
        }
        ksort($choices);
        self::$cache_payment_choices = $choices;
        return self::$cache_payment_choices;
    }

    public static function sanitize_allowed_shipping($raw): array {
        $raw = is_array($raw) ? $raw : [];
        $out = [];

        foreach ($raw as $val) {
            $val = trim((string) $val);
            if ($val === '') continue;

            if (strpos($val, ':') !== false) {
                $parts = explode(':', $val, 2);
                $type = sanitize_key((string) ($parts[0] ?? ''));
                $iid = (int) ($parts[1] ?? 0);
                if ($type !== '' && $iid > 0) {
                    $out[] = $type . ':' . $iid;
                }
                continue;
            }

            $out[] = sanitize_key($val);
        }

        $out = array_values(array_unique(array_filter($out)));

        // Only whitelist-intersect when we have choices available.
        $whitelist = array_keys(self::shipping_choices());
        if (!empty($whitelist)) {
            $out = array_values(array_intersect($out, $whitelist));
        }

        return $out;
    }

    public static function sanitize_allowed_payments($raw): array {
        $raw = is_array($raw) ? $raw : [];

        $out = [];
        foreach ($raw as $val) {
            $val = trim((string) $val);
            if ($val === '') continue;
            $out[] = sanitize_key($val);
        }
        $out = array_values(array_unique(array_filter($out)));

        // Only whitelist-intersect when we have gateways available.
        $whitelist = array_keys(self::payment_gateway_choices());
        if (!empty($whitelist)) {
            $out = array_values(array_intersect($out, $whitelist));
        }

        return $out;
    }

    public static function sanitize_price_display_mode($raw): string {
        $mode = is_string($raw) ? $raw : (string) $raw;
        $mode = sanitize_key(trim($mode));
        if (!in_array($mode, self::PRICE_DISPLAY_MODES, true)) {
            return '';
        }
        return $mode;
    }

    public static function sanitize_price_display_label($raw): string {
        $label = is_string($raw) ? $raw : (string) $raw;
        $label = trim(wp_strip_all_tags($label));
        if ($label === '') return '';
        if (function_exists('mb_substr')) {
            $label = mb_substr($label, 0, 80);
        } else {
            $label = substr($label, 0, 80);
        }
        return $label;
    }

    /**
     * Parse repeatable price rules from POST arrays: ids[], types[], values[].
     * Returns map<int,string|int,array{type:string,value:float}>.
     */
    public static function parse_price_rules(array $raw, string $kind): array {
        $ids = $raw['id'] ?? [];
        $types = $raw['type'] ?? [];
        $values = $raw['value'] ?? [];

        $out = [];
        $count = max(count((array) $ids), count((array) $types), count((array) $values));

        for ($i = 0; $i < $count; $i++) {
            $id_raw = $ids[$i] ?? '';
            $type_raw = $types[$i] ?? '';
            $val_raw = $values[$i] ?? '';

            $id = (int) $id_raw;
            if ($id <= 0) continue;

            $type = (string) $type_raw;
            if (!in_array($type, self::RULE_TYPES, true)) continue;

            $value = is_numeric($val_raw) ? (float) $val_raw : 0.0;
            if ($value < 0) $value = 0.0;

            if ($kind === 'category') {
                $term = get_term($id, 'product_cat');
                if (!$term || is_wp_error($term)) continue;
            }
            if ($kind === 'product') {
                if (!function_exists('wc_get_product')) continue;
                $p = wc_get_product($id);
                if (!$p) continue;
            }

            $out[$id] = [
                'type' => $type,
                'value' => $value,
            ];
        }

        return $out;
    }

    public static function sanitize_profile_name(string $name): string {
        $name = trim(wp_strip_all_tags($name));
        return mb_substr($name, 0, 80);
    }

    /**
     * Profile IDs are stored as array keys in an option, so we must not use sanitize_key()
     * (it lowercases), otherwise IDs with uppercase characters cannot be retrieved/deleted.
     */
    public static function sanitize_profile_id(string $profile_id): string {
        $profile_id = trim($profile_id);
        if ($profile_id === '' || $profile_id === 'new') return $profile_id;

        // Keep case, only allow safe characters.
        $profile_id = (string) preg_replace('/[^A-Za-z0-9_]/', '', $profile_id);

        // Basic sanity: require our expected prefix.
        if ($profile_id === '' || strpos($profile_id, 'p_') !== 0) return '';
        return $profile_id;
    }
}
