<?php
// =============================
// src/Modules/QLS/QLSModule.php
// =============================
namespace HB\UCS\Modules\QLS;

use HB\UCS\Core\Settings;

if (!defined('ABSPATH')) exit;

class QLSModule {
    // Metas behouden voor backward compatibility
    const META_PRODUCT = '_hb_qls_exclude_product'; // 1/0 (manual per product)
    const META_ORDER   = '_hb_qls_exclude_order';   // 1/0 (manual per order)
    const META_ORDER_LEGACY = '_qls_exclude';       // 1/0 (oude plugin/meta-compat)

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Product metabox
        add_action('add_meta_boxes', [$this, 'add_product_metabox']);
        add_action('save_post_product', [$this, 'save_product_meta'], 10, 3);

        // Order metabox
        add_action('add_meta_boxes', [$this, 'add_order_metabox']);
        add_action('save_post_shop_order', [$this, 'save_order_meta'], 10, 3);

        // Centrale filter voor connector (herbruikbaar in rest-filter en batch tools)
        add_filter('hb_ucs_qls_should_send_order', [$this, 'should_send_order'], 10, 2);

        // REST API: filter orders voor geselecteerde API user
        add_filter('rest_post_dispatch', [$this, 'rest_filter_orders_for_api_user'], 10, 3);
    }

    /* -----------------------------
     * Product metabox
     * ----------------------------- */
    public function add_product_metabox(): void {
        add_meta_box(
            'hb_qls_product_exclude',
            __('QLS: Order meenemen?', 'hb-ucs'),
            [$this, 'render_product_metabox'],
            'product',
            'side',
            'default'
        );
    }

    public function render_product_metabox(\WP_Post $post): void {
        $val = get_post_meta($post->ID, self::META_PRODUCT, true) === '1' ? '1' : '0';
        wp_nonce_field('hb_qls_product_meta', 'hb_qls_product_meta_nonce');
        echo '<p>';
        echo '<label>';
        echo '<input type="checkbox" name="hb_qls_exclude_product" value="1" ' . checked($val, '1', false) . ' /> ';
        esc_html_e('Product uitsluiten van QLS.', 'hb-ucs');
        echo '</label>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('Orders met dit product worden niet doorgezet naar QLS.', 'hb-ucs') . '</p>';
    }

    public function save_product_meta(int $post_id, $post, bool $update): void {
        // Autosave/bulk/quick skip
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (defined('DOING_AJAX') && DOING_AJAX) {
            // alleen bewaren bij expliciete save in editor, laat ajax (quick edit) met rust
            return;
        }
        if (!isset($_POST['hb_qls_product_meta_nonce']) || !wp_verify_nonce($_POST['hb_qls_product_meta_nonce'], 'hb_qls_product_meta')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $val = isset($_POST['hb_qls_exclude_product']) ? '1' : '0';
        update_post_meta($post_id, self::META_PRODUCT, $val);
    }

    /* -----------------------------
     * Order metabox
     * ----------------------------- */
    public function add_order_metabox(): void {
        add_meta_box(
            'hb_qls_order_exclude',
            __('QLS: Order meenemen?', 'hb-ucs'),
            [$this, 'render_order_metabox'],
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_order_metabox(\WP_Post $post): void {
        $val = get_post_meta($post->ID, self::META_ORDER, true) === '1' ? '1' : '0';
        wp_nonce_field('hb_qls_order_meta', 'hb_qls_order_meta_nonce');
        echo '<p>';
        echo '<label>';
        echo '<input type="checkbox" name="hb_qls_exclude_order" value="1" ' . checked($val, '1', false) . ' /> ';
        esc_html_e('Deze order uitsluiten van QLS.', 'hb-ucs');
        echo '</label>';
        echo '</p>';

        // Toon ook legacy meta als debug-info
        $legacy = get_post_meta($post->ID, self::META_ORDER_LEGACY, true);
        if ($legacy !== '') {
            echo '<p class="description">' . esc_html(sprintf(__('Legacy meta (%s): %s', 'hb-ucs'), self::META_ORDER_LEGACY, $legacy)) . '</p>';
        }
    }

    public function save_order_meta(int $post_id, $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['hb_qls_order_meta_nonce']) || !wp_verify_nonce($_POST['hb_qls_order_meta_nonce'], 'hb_qls_order_meta')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $val = isset($_POST['hb_qls_exclude_order']) ? '1' : '0';
        update_post_meta($post_id, self::META_ORDER, $val);
    }

    /* -----------------------------
     * Beslislogica
     * ----------------------------- */
    public function should_send_order(bool $allow, int $order_id): bool {
        $order = wc_get_order($order_id);
        if (!$order) return false;

        $opt = get_option(Settings::OPT_QLS, []);

        // 0) Legacy/handmatige overrides
        $legacy = get_post_meta($order_id, self::META_ORDER_LEGACY, true);
        if ($legacy === '1') return false; // oude plugin exclude
        // legacy '0' = expliciet toestaan -> ga door

        $manual = get_post_meta($order_id, self::META_ORDER, true);
        if ($manual === '1') return false; // handmatig uitgesloten
        // manual '0' = expliciet toelaten -> ga door

        // 1) Shipping instance_id uitsluitingen
        $excluded_instances = array_map('intval', (array)($opt['excluded_shipping_instance_ids'] ?? []));
        if (!empty($excluded_instances)) {
            foreach ($order->get_shipping_methods() as $ship_item) {
                $iid = (int) $ship_item->get_instance_id();
                if ($iid && in_array($iid, $excluded_instances, true)) {
                    return false;
                }
            }
        }

        // 2) Shipping method_id (type) uitsluitingen
        $excluded_method_ids = array_map('strval', (array)($opt['excluded_shipping_method_ids'] ?? []));
        if (!empty($excluded_method_ids)) {
            foreach ($order->get_shipping_methods() as $ship_item) {
                $mid = (string) $ship_item->get_method_id();
                if (in_array($mid, $excluded_method_ids, true)) {
                    return false;
                }
            }
        }

        // 3) Product niveau exclusions (handmatig per product)
        foreach ($order->get_items('line_item') as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $pid = (int) $item->get_product_id();
            if ($pid && get_post_meta($pid, self::META_PRODUCT, true) === '1') {
                return false;
            }
        }

        return true;
    }

    /* -----------------------------
     * REST API filtering
     * ----------------------------- */
    public function rest_filter_orders_for_api_user($response, \WP_REST_Server $server, \WP_REST_Request $request) {
        // Alleen voor wc/v3 orders endpoints
        $route = $request->get_route();
        if (strpos($route, '/wc/v3/orders') !== 0 && strpos($route, '/wc/v2/orders') !== 0) {
            return $response;
        }

        $opt = get_option(Settings::OPT_QLS, []);
        $api_user_id = (int) ($opt['api_user_id'] ?? 0);
        if ($api_user_id <= 0) return $response;

        // Alleen toepassen voor de ingestelde gebruiker
        $current = get_current_user_id();
        if ((int) $current !== $api_user_id) return $response;

        if (!($response instanceof \WP_REST_Response)) return $response;

        $data = $response->get_data();
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            // Collection
            $filtered = [];
            foreach ($data as $row) {
                if (!isset($row['id'])) continue;
                $oid = (int) $row['id'];
                if ($oid > 0 && apply_filters('hb_ucs_qls_should_send_order', true, $oid)) {
                    $filtered[] = $row;
                }
            }
            $response->set_data($filtered);
            return $response;
        }

        // Single (detail)
        if (is_object($data) && isset($data->id)) {
            $oid = (int) $data->id;
            if ($oid > 0 && !apply_filters('hb_ucs_qls_should_send_order', true, $oid)) {
                return new \WP_Error('hb_ucs_qls_excluded', __('Order is uitgesloten voor deze API gebruiker.', 'hb-ucs'), ['status'=>404]);
            }
            return $response;
        }

        if (is_array($data) && isset($data['id'])) {
            // Sommige responses komen als assoc array terug
            $oid = (int) $data['id'];
            if ($oid > 0 && !apply_filters('hb_ucs_qls_should_send_order', true, $oid)) {
                return new \WP_Error('hb_ucs_qls_excluded', __('Order is uitgesloten voor deze API gebruiker.', 'hb-ucs'), ['status'=>404]);
            }
            return $response;
        }

        return $response;
    }
}
