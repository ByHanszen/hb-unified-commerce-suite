<?php
// =============================
// src/Modules/B2B/Support/Validator.php
// =============================
namespace HB\UCS\Modules\B2B\Support;

if (!defined('ABSPATH')) exit;

class Validator {
    public const RULE_TYPES = ['percent', 'fixed_price', 'fixed_discount'];
    public const PRICE_DISPLAY_MODES = ['adjusted', 'original', 'both'];
    private const OPT_OBSERVED_SHIPPING_CHOICES = 'hb_ucs_b2b_observed_shipping_choices';
    private const MAX_OBSERVED_SHIPPING_CHOICES = 200;

    private static ?array $cache_shipping_choices = null;
    private static ?array $cache_payment_choices = null;

    public static function can_manage(): bool {
        return current_user_can('manage_woocommerce') || current_user_can('manage_options');
    }

    /**
     * Returns [shipping_id => label] with method types, zone instances,
     * and any runtime checkout rate variants observed earlier.
     */
    public static function shipping_choices(): array {
        if (self::$cache_shipping_choices !== null) {
            return self::$cache_shipping_choices;
        }

        $choices = [];
        $typeChoices = [];
        $instanceChoices = [];

        self::ensure_shipping_zone_classes_loaded();

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

        // Collect shipping METHOD TYPES first. These remain available as fallback selections.
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

                $typeChoices[$mid] = $label;
            }
        }

        // If zones are not loaded/available, fall back to types only.
        if (!class_exists('WC_Shipping_Zones')) {
            foreach ($typeChoices as $mid => $label) {
                $choices[$mid] = sprintf('%s (%s)', $label, $mid);
            }
            asort($choices, SORT_NATURAL);
            return $choices;
        }

        $iterate_zone_methods = static function ($methods, string $zone_name) use (&$instanceChoices): void {
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
                $instanceChoices[$key] = [
                    'title' => trim($title) !== '' ? trim($title) : $mid,
                    'zone_name' => $zone_name,
                    'type' => $mid,
                    'enabled_suffix' => $enabled_suffix,
                ];
            }
        };

        // Read configured shipping instances from real zone objects.
        $zones_raw = \WC_Shipping_Zones::get_zones();
        if (is_array($zones_raw)) {
            foreach ($zones_raw as $zr) {
                if (!is_array($zr)) {
                    continue;
                }

                $zid = isset($zr['id']) ? (int) $zr['id'] : (int) ($zr['zone_id'] ?? 0);
                if ($zid <= 0) {
                    continue;
                }

                $zone = new \WC_Shipping_Zone($zid);
                if (!is_object($zone) || !method_exists($zone, 'get_shipping_methods')) {
                    continue;
                }

                $zoneName = method_exists($zone, 'get_zone_name')
                    ? (string) $zone->get_zone_name()
                    : (string) ($zr['zone_name'] ?? '');

                $iterate_zone_methods($zone->get_shipping_methods(false), $zoneName);
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

        $titleCounts = [];
        foreach ($instanceChoices as $meta) {
            $title = isset($meta['title']) ? trim((string) $meta['title']) : '';
            if ($title === '') {
                continue;
            }

            $countKey = strtolower($title);
            $titleCounts[$countKey] = (int) ($titleCounts[$countKey] ?? 0) + 1;
        }

        $specificChoiceTypes = [];
        foreach ($instanceChoices as $key => $meta) {
            $title = isset($meta['title']) ? trim((string) $meta['title']) : $key;
            $zoneName = isset($meta['zone_name']) ? trim((string) $meta['zone_name']) : '';
            $enabledSuffix = (string) ($meta['enabled_suffix'] ?? '');
            $type = isset($meta['type']) ? (string) $meta['type'] : '';

            $display = $title !== '' ? $title : $key;
            $countKey = strtolower($display);
            if (($titleCounts[$countKey] ?? 0) > 1 && $zoneName !== '') {
                $display .= ' — ' . $zoneName;
            }
            $display .= $enabledSuffix;

            $choices[$key] = $display;
            if ($type !== '') {
                $specificChoiceTypes[$type] = true;
            }
        }

        foreach (self::observed_shipping_choices() as $key => $label) {
            $choices[$key] = $label;
            if (strpos((string) $key, ':') !== false) {
                $parts = explode(':', (string) $key, 2);
                $type = (string) ($parts[0] ?? '');
                if ($type !== '') {
                    $specificChoiceTypes[$type] = true;
                }
            }
        }

        foreach (self::manual_shipping_choices() as $key => $label) {
            $choices[$key] = $label;
            if (strpos((string) $key, ':') !== false) {
                $parts = explode(':', (string) $key, 2);
                $type = (string) ($parts[0] ?? '');
                if ($type !== '') {
                    $specificChoiceTypes[$type] = true;
                }
            }
        }

        foreach ($typeChoices as $mid => $label) {
            if (isset($specificChoiceTypes[$mid])) {
                $choices[$mid] = sprintf(__('Alle %s methodes (%s)', 'hb-ucs'), $label, $mid);
                continue;
            }

            $choices[$mid] = sprintf('%s (%s)', $label, $mid);
        }

        asort($choices, SORT_NATURAL);
        self::$cache_shipping_choices = $choices;
        return self::$cache_shipping_choices;
    }

    public static function remember_runtime_shipping_choices(array $rates): void {
        if (empty($rates)) {
            return;
        }

        $existing = self::observed_shipping_choices();
        $changed = false;

        foreach ($rates as $rate_key => $rate) {
            if (!is_object($rate)) {
                continue;
            }

            $key = method_exists($rate, 'get_id') ? (string) $rate->get_id() : (string) $rate_key;
            $key = self::sanitize_shipping_choice_key($key);
            if ($key === '') {
                continue;
            }

            $label = self::build_runtime_shipping_choice_label($rate, $key);

            if (isset($existing[$key]) && $existing[$key] === $label) {
                continue;
            }

            if (isset($existing[$key])) {
                unset($existing[$key]);
            }

            $existing[$key] = $label;
            $changed = true;
        }

        if (!$changed) {
            return;
        }

        if (count($existing) > self::MAX_OBSERVED_SHIPPING_CHOICES) {
            $existing = array_slice($existing, -self::MAX_OBSERVED_SHIPPING_CHOICES, null, true);
        }

        update_option(self::OPT_OBSERVED_SHIPPING_CHOICES, $existing, false);
        self::$cache_shipping_choices = null;
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
            $key = self::sanitize_shipping_choice_key((string) $val);
            if ($key === '') continue;
            $out[] = $key;
        }

        $out = array_values(array_unique(array_filter($out)));

        // Only whitelist-intersect when we have choices available.
        $whitelist = array_keys(self::shipping_choices());
        if (!empty($whitelist)) {
            $out = array_values(array_intersect($out, $whitelist));
        }

        return $out;
    }

    public static function sanitize_manual_shipping_choices($raw): array {
        $rows = is_array($raw) ? $raw : [];
        $choices = [];

        foreach ($rows as $key => $label) {
            if (is_int($key)) {
                $line = trim((string) $label);
                if ($line === '') {
                    continue;
                }

                $parts = explode('|', $line, 2);
                $key = (string) ($parts[0] ?? '');
                $label = (string) ($parts[1] ?? '');
            }

            $sanitizedKey = self::sanitize_shipping_choice_key((string) $key);
            if ($sanitizedKey === '') {
                continue;
            }

            $sanitizedLabel = trim(wp_strip_all_tags((string) $label));
            if ($sanitizedLabel === '') {
                $sanitizedLabel = $sanitizedKey;
            }

            if (function_exists('mb_substr')) {
                $sanitizedLabel = (string) mb_substr($sanitizedLabel, 0, 160);
            } else {
                $sanitizedLabel = (string) substr($sanitizedLabel, 0, 160);
            }

            $choices[$sanitizedKey] = $sanitizedLabel;
        }

        return $choices;
    }

    private static function observed_shipping_choices(): array {
        $stored = get_option(self::OPT_OBSERVED_SHIPPING_CHOICES, []);
        if (!is_array($stored)) {
            return [];
        }

        $choices = [];
        foreach ($stored as $key => $label) {
            $sanitizedKey = self::sanitize_shipping_choice_key((string) $key);
            if ($sanitizedKey === '') {
                continue;
            }

            $sanitizedLabel = trim(wp_strip_all_tags((string) $label));
            if ($sanitizedLabel === '') {
                $sanitizedLabel = $sanitizedKey;
            }

            $choices[$sanitizedKey] = $sanitizedLabel;
        }

        return $choices;
    }

    private static function manual_shipping_choices(): array {
        $settings = get_option('hb_ucs_b2b_settings', []);
        if (!is_array($settings)) {
            return [];
        }

        return self::sanitize_manual_shipping_choices($settings['custom_shipping_choices'] ?? []);
    }

    private static function ensure_shipping_zone_classes_loaded(): void {
        if (class_exists('WC_Shipping_Zones') && class_exists('WC_Shipping_Zone')) {
            return;
        }

        if (!defined('WC_ABSPATH')) {
            return;
        }

        $files = [
            WC_ABSPATH . 'includes/class-wc-shipping-zone.php',
            WC_ABSPATH . 'includes/class-wc-shipping-zones.php',
        ];

        foreach ($files as $file) {
            if (is_string($file) && $file !== '' && file_exists($file)) {
                require_once $file;
            }
        }
    }

    private static function sanitize_shipping_choice_key(string $raw): string {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (strpos($raw, ':') === false) {
            return sanitize_key($raw);
        }

        $segments = array_map('trim', explode(':', $raw));
        $segments = array_map('sanitize_key', $segments);
        $segments = array_values(array_filter($segments, static function ($segment) {
            return $segment !== '';
        }));

        if (count($segments) < 2) {
            return '';
        }

        return implode(':', $segments);
    }

    private static function build_runtime_shipping_choice_label(object $rate, string $key): string {
        $title = method_exists($rate, 'get_label') ? (string) $rate->get_label() : '';
        if ($title === '' && method_exists($rate, 'get_method_id')) {
            $title = (string) $rate->get_method_id();
        }
        if ($title === '') {
            $title = $key;
        }

        $methodId = method_exists($rate, 'get_method_id') ? (string) $rate->get_method_id() : '';
        $instanceId = method_exists($rate, 'get_instance_id') ? (int) $rate->get_instance_id() : 0;
        $instanceKey = ($methodId !== '' && $instanceId > 0) ? ($methodId . ':' . $instanceId) : '';

        if ($instanceKey !== '' && $instanceKey !== $key) {
            return sprintf('%s — checkout-variant (%s; basis %s)', $title, $key, $instanceKey);
        }

        if ($methodId !== '' && $methodId !== $key) {
            return sprintf('%s — checkout-variant (%s; methode %s)', $title, $key, $methodId);
        }

        return sprintf('%s (%s)', $title, $key);
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
