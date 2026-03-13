<?php
// =============================
// src/Modules/CustomerOrderNote/CustomerOrderNoteModule.php
// =============================
namespace HB\UCS\Modules\CustomerOrderNote;

if (!defined('ABSPATH')) exit;

class CustomerOrderNoteModule {
    public const USER_META_KEY = 'customer_order_note_internal';
    public const ORDER_META_KEY = '_customer_order_note_internal';
    private const NONCE_ACTION = 'hb_ucs_customer_order_note_internal';
    private const NONCE_NAME = 'hb_ucs_customer_order_note_internal_nonce';

    public function init(): void
    {
        // User admin field (WordPress user edit screens).
        add_action('show_user_profile', [$this, 'render_user_field']);
        add_action('edit_user_profile', [$this, 'render_user_field']);

        add_action('personal_options_update', [$this, 'save_user_field']);
        add_action('edit_user_profile_update', [$this, 'save_user_field']);

        // WooCommerce integrations must be optional.
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Copy snapshot to new orders.
        add_action('woocommerce_new_order', [$this, 'maybe_snapshot_to_new_order'], 10, 2);

        // Admin fallback: when an order is created in admin first and the customer is assigned later,
        // the woocommerce_new_order hook may run before customer_id is set. This hook runs on save.
        add_action('woocommerce_process_shop_order_meta', [$this, 'maybe_snapshot_on_admin_save'], 10, 2);

        // Show note on admin order screen.
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_admin_order_block']);
    }

    public function render_user_field($user): void
    {
        if (!($user instanceof \WP_User)) {
            return;
        }

        // Only allow privileged staff (admin/shop manager).
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }

        $value = (string) get_user_meta($user->ID, self::USER_META_KEY, true);

        echo '<h2>' . esc_html__('Klantnotitie', 'hb-ucs') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th><label for="hb_ucs_customer_order_note_internal">' . esc_html__('Klantnotitie voor orders en paklijst', 'hb-ucs') . '</label></th>';
        echo '<td>';
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        echo '<textarea name="hb_ucs_customer_order_note_internal" id="hb_ucs_customer_order_note_internal" rows="5" class="large-text">' . esc_textarea($value) . '</textarea>';
        echo '<p class="description">' . esc_html__('Deze notitie wordt automatisch overgenomen naar nieuwe bestellingen van deze klant en moet zichtbaar kunnen worden op de paklijst.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';
    }

    public function save_user_field(int $user_id): void
    {
        if ($user_id <= 0) {
            return;
        }

        // Only allow privileged staff (admin/shop manager).
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!current_user_can('edit_user', $user_id)) {
            return;
        }

        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce((string) $_POST[self::NONCE_NAME], self::NONCE_ACTION)) {
            return;
        }

        $raw = isset($_POST['hb_ucs_customer_order_note_internal']) ? (string) wp_unslash($_POST['hb_ucs_customer_order_note_internal']) : '';
        $note = sanitize_textarea_field($raw);

        if ($note === '') {
            delete_user_meta($user_id, self::USER_META_KEY);
            return;
        }

        update_user_meta($user_id, self::USER_META_KEY, $note);
    }

    public function maybe_snapshot_to_new_order(int $order_id, $order_data = null): void
    {
        if ($order_id <= 0) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Only copy once: do not overwrite existing snapshots.
        $existing = (string) $order->get_meta(self::ORDER_META_KEY, true);
        if ($existing !== '') {
            return;
        }

        $customer_id = (int) $order->get_customer_id();
        if ($customer_id <= 0) {
            return;
        }

        $note = (string) get_user_meta($customer_id, self::USER_META_KEY, true);
        $note = sanitize_textarea_field($note);
        if ($note === '') {
            return;
        }

        $order->update_meta_data(self::ORDER_META_KEY, $note);
        $order->save();
    }

    public function maybe_snapshot_on_admin_save(int $order_id, $post = null): void
    {
        if (!is_admin()) {
            return;
        }

        // Reuse the same logic: it is safe because it never overwrites an existing snapshot.
        $this->maybe_snapshot_to_new_order($order_id, null);
    }

    public function render_admin_order_block($order): void
    {
        if (!($order instanceof \WC_Order)) {
            return;
        }

        if (!is_admin()) {
            return;
        }

        if (!current_user_can('edit_shop_orders')) {
            return;
        }

        $note = (string) $order->get_meta(self::ORDER_META_KEY, true);
        $note = trim($note);
        if ($note === '') {
            return; // do not show empty blocks.
        }

        echo '<div class="order_data_column">';
        echo '<h3>' . esc_html__('Klantnotitie', 'hb-ucs') . '</h3>';
        echo '<p class="form-field form-field-wide">';
        echo '<textarea class="large-text" rows="5" readonly="readonly">' . esc_textarea($note) . '</textarea>';
        echo '</p>';
        echo '</div>';
    }

    public static function get_note_for_order($order): string
    {
        if (!class_exists('WooCommerce')) {
            return '';
        }

        $o = $order;
        if (is_numeric($order)) {
            $o = wc_get_order((int) $order);
        }
        if (!($o instanceof \WC_Order)) {
            return '';
        }

        $note = (string) $o->get_meta(self::ORDER_META_KEY, true);
        $note = trim($note);

        $note = (string) apply_filters('hb_ucs_customer_order_note_internal', $note, $o);

        return $note;
    }
}
