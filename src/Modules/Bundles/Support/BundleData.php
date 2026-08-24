<?php
namespace HB\UCS\Modules\Bundles\Support;

if (!defined('ABSPATH')) exit;

/**
 * Canonical bundle data contract.
 *
 * Product definitions deliberately use WPC Product Bundles' public `woosb_*`
 * keys so existing bundle products can be used without a data migration.
 */
final class BundleData {
    public const PRODUCT_TYPE = 'woosb';
    public const META_ITEMS = 'woosb_ids';
    public const META_FIXED_PRICE = 'woosb_disable_auto_price';
    public const META_DISCOUNT = 'woosb_discount';
    public const META_DISCOUNT_AMOUNT = 'woosb_discount_amount';
    public const META_SHIPPING = 'woosb_shipping_fee';
    public const META_MANAGE_STOCK = 'woosb_manage_stock';

    public const CART_INSTANCE = 'hb_ucs_bundle_instance_id';
    public const CART_SNAPSHOT = 'hb_ucs_bundle_snapshot';
    public const ORDER_INSTANCE = '_hb_ucs_bundle_instance_id';
    public const ORDER_SNAPSHOT = '_hb_ucs_bundle_snapshot';
    public const ORDER_ROLE = '_hb_ucs_bundle_role';

    /** @return array<string,array<string,mixed>> */
    public static function normalize_product_items($product, $raw = null): array {
        if ($raw === null && is_object($product) && method_exists($product, 'get_meta')) {
            $raw = $product->get_meta(self::META_ITEMS);
        }
        if (is_array($raw) && is_object($product) && method_exists($product, 'get_meta')) {
            $defaultMin = $product->get_meta('woosb_limit_each_min_default') === 'on'
                ? null : $product->get_meta('woosb_limit_each_min');
            $defaultMax = $product->get_meta('woosb_limit_each_max');
            foreach ($raw as &$item) {
                if (!is_array($item) || empty($item['id']) || isset($item['min'])) {
                    continue;
                }
                // This mirrors WPC 8.x's runtime upgrade path for pre-8.0 product arrays.
                $item['min'] = $defaultMin === null ? ($item['qty'] ?? 0) : $defaultMin;
                $item['max'] = $defaultMax;
            }
            unset($item);
        }
        return self::normalize_items($raw);
    }

    /** @return array<string,array<string,mixed>> */
    public static function normalize_items($raw): array {
        if (is_string($raw)) {
            return self::parse_selection($raw);
        }
        if (!is_array($raw)) {
            return [];
        }

        $normalized = [];
        foreach ($raw as $rawKey => $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }

            $key = self::normalize_key($rawKey, $normalized);
            $id = max(0, (int) ($rawItem['id'] ?? 0));
            if ($id <= 0) {
                $text = isset($rawItem['text']) ? trim((string) $rawItem['text']) : '';
                if ($text === '') {
                    continue;
                }
                $type = sanitize_key((string) ($rawItem['type'] ?? 'p'));
                if (!in_array($type, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'span', 'none'], true)) {
                    $type = 'p';
                }
                $normalized[$key] = [
                    'id' => 0,
                    'type' => $type,
                    'text' => wp_kses_post($text),
                ];
                continue;
            }

            $qty = self::decimal($rawItem['qty'] ?? 1, 1.0);
            $optional = !empty($rawItem['optional']) ? 1 : 0;
            $min = self::decimal($rawItem['min'] ?? ($optional ? 0 : $qty), $optional ? 0.0 : $qty);
            $maxRaw = $rawItem['max'] ?? '';
            $max = ($maxRaw === '' || $maxRaw === null) ? '' : self::decimal($maxRaw, $qty);
            if ($max !== '' && (float) $max < $min) {
                $max = $min;
            }
            if (!$optional) {
                $min = $qty;
                $max = $qty;
            }

            $normalized[$key] = [
                'id' => $id,
                'sku' => sanitize_text_field((string) ($rawItem['sku'] ?? '')),
                'qty' => max(0.0, $qty),
                'optional' => $optional,
                'min' => max(0.0, $min),
                'max' => $max,
                'attrs' => self::sanitize_attributes($rawItem['attrs'] ?? []),
                'terms' => self::sanitize_terms($rawItem['terms'] ?? []),
                'customer_title' => sanitize_text_field((string) ($rawItem['customer_title'] ?? '')),
                'customer_description' => wp_kses_post((string) ($rawItem['customer_description'] ?? '')),
                'badge' => sanitize_text_field((string) ($rawItem['badge'] ?? '')),
                'group' => sanitize_text_field((string) ($rawItem['group'] ?? '')),
            ];
        }

        return $normalized;
    }

    /**
     * Parse WPC's compact cart/order format: product-id/key/qty/urlencoded-json-attributes.
     *
     * @return array<string,array<string,mixed>>
     */
    public static function parse_selection(string $value): array {
        $selection = [];
        foreach (array_filter(explode(',', self::clean_compact_string($value))) as $part) {
            $fields = explode('/', $part, 4);
            $rawId = rawurldecode((string) ($fields[0] ?? '0'));
            $sku = '';
            if (is_numeric($rawId)) {
                $id = max(0, (int) $rawId);
            } else {
                $sku = preg_replace('/^sku-/', '', $rawId) ?: '';
                $id = function_exists('wc_get_product_id_by_sku') ? max(0, (int) wc_get_product_id_by_sku($sku)) : 0;
            }
            if ($id <= 0) {
                continue;
            }
            $second = rawurldecode((string) ($fields[1] ?? ''));
            $legacyIdQtyFormat = $second !== '' && is_numeric($second) && !isset($fields[2]);
            $key = self::normalize_key($legacyIdQtyFormat ? '' : $second, $selection);
            $qty = max(0.0, self::decimal($legacyIdQtyFormat ? $second : ($fields[2] ?? 1), 1.0));
            $attrs = [];
            if (!empty($fields[3])) {
                $decoded = json_decode(rawurldecode((string) $fields[3]), true);
                $attrs = is_array($decoded) ? self::sanitize_attributes($decoded) : [];
            }
            $selection[$key] = [
                'id' => $id,
                'sku' => $sku,
                'qty' => $qty,
                'attrs' => $attrs,
            ];
        }

        return $selection;
    }

    public static function selection_to_string(array $selection): string {
        $parts = [];
        foreach (self::normalize_items($selection) as $key => $item) {
            if (empty($item['id']) || (float) ($item['qty'] ?? 0) <= 0) {
                continue;
            }
            $attrs = !empty($item['attrs']) ? rawurlencode((string) wp_json_encode($item['attrs'])) : '';
            $parts[] = (int) $item['id'] . '/' . rawurlencode((string) $key) . '/' . self::format_decimal((float) $item['qty']) . '/' . $attrs;
        }
        return implode(',', $parts);
    }

    public static function clean_compact_string(string $value): string {
        $value = wp_unslash($value);
        return preg_replace('/[^0-9A-Za-z_,.\-\/%:{}\[\]"\\\\]+/', '', $value) ?: '';
    }

    /** @return array<string,string> */
    public static function sanitize_attributes($attributes): array {
        if (!is_array($attributes)) {
            return [];
        }
        $clean = [];
        foreach ($attributes as $name => $value) {
            $name = sanitize_key((string) $name);
            if ($name === '') {
                continue;
            }
            if (strpos($name, 'attribute_') !== 0) {
                $name = 'attribute_' . $name;
            }
            $clean[$name] = sanitize_title((string) $value);
        }
        ksort($clean);
        return $clean;
    }

    /** @return array<string,array<int,string>> */
    private static function sanitize_terms($terms): array {
        if (!is_array($terms)) {
            return [];
        }
        $clean = [];
        foreach ($terms as $name => $values) {
            $name = sanitize_key((string) $name);
            if ($name === '') {
                continue;
            }
            $values = is_array($values) ? $values : [$values];
            $clean[$name] = array_values(array_unique(array_filter(array_map('sanitize_title', array_map('strval', $values)))));
        }
        return $clean;
    }

    public static function is_bundle_product($product): bool {
        return is_object($product) && method_exists($product, 'get_type') && (string) $product->get_type() === self::PRODUCT_TYPE;
    }

    public static function generate_instance_id(): string {
        return function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('hb-bundle-', true);
    }

    /** @return array<string,mixed> */
    public static function build_snapshot($bundle, array $selection): array {
        $components = [];
        foreach ($selection as $key => $selected) {
            $product = function_exists('wc_get_product') ? wc_get_product((int) ($selected['id'] ?? 0)) : false;
            if (!$product) {
                continue;
            }
            $components[] = [
                'key' => sanitize_key((string) $key),
                'product_id' => (int) $product->get_id(),
                'parent_product_id' => method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0,
                'sku' => (string) $product->get_sku(),
                'name' => (string) $product->get_name(),
                'quantity' => (float) ($selected['qty'] ?? 0),
                'attributes' => self::sanitize_attributes($selected['attrs'] ?? []),
                'unit_price' => (float) $product->get_price(),
            ];
        }

        return [
            'schema_version' => 1,
            'bundle_product_id' => is_object($bundle) && method_exists($bundle, 'get_id') ? (int) $bundle->get_id() : 0,
            'bundle_name' => is_object($bundle) && method_exists($bundle, 'get_name') ? (string) $bundle->get_name() : '',
            'pricing_mode' => is_object($bundle) && method_exists($bundle, 'is_fixed_price') && $bundle->is_fixed_price() ? 'fixed' : 'components',
            'shipping_mode' => is_object($bundle) && method_exists($bundle, 'get_meta') ? (string) ($bundle->get_meta(self::META_SHIPPING) ?: 'whole') : 'whole',
            'components' => $components,
        ];
    }

    private static function normalize_key($rawKey, array $existing): string {
        $key = sanitize_key((string) $rawKey);
        if ($key === '' || is_numeric($key)) {
            $key = 'item_' . substr(md5((string) $rawKey . ':' . count($existing)), 0, 10);
        }
        $base = $key;
        $suffix = 2;
        while (isset($existing[$key])) {
            $key = $base . '_' . $suffix;
            $suffix++;
        }
        return $key;
    }

    private static function decimal($value, float $default): float {
        if ($value === '' || $value === null || !is_numeric($value)) {
            return $default;
        }
        return (float) wc_format_decimal((string) $value);
    }

    private static function format_decimal(float $value): string {
        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}
