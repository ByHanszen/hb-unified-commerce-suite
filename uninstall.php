<?php
// uninstall.php — HB Unified Commerce Suite
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Only remove data if user opted in (via QLS settings)
$opts = get_option('hb_ucs_qls');
$b2b = get_option('hb_ucs_b2b_settings');
$roles = get_option('hb_ucs_roles_settings');
$invoice = get_option('hb_ucs_invoice_email_settings');
$cust_note = get_option('hb_ucs_customer_order_note_settings');
$subs = get_option('hb_ucs_subscriptions_settings');

$delete_qls = !empty($opts['delete_data_on_uninstall']);
$delete_b2b = is_array($b2b) && !empty($b2b['delete_data_on_uninstall']);
$delete_roles = is_array($roles) && !empty($roles['delete_data_on_uninstall']);
$delete_invoice = is_array($invoice) && !empty($invoice['delete_data_on_uninstall']);
$delete_cust_note = is_array($cust_note) && !empty($cust_note['delete_data_on_uninstall']);
$delete_subs = is_array($subs) && !empty($subs['delete_data_on_uninstall']);

$delete_any = $delete_qls || $delete_b2b || $delete_roles || $delete_invoice || $delete_cust_note || $delete_subs;

if ($delete_any) {
    // Global plugin settings (module toggles).
    delete_option('hb_ucs_settings');
}

if ($delete_qls) {
    // Remove options
    delete_option('hb_ucs_qls');
    delete_option('hb_ucs_qls_migrated');
}

if ($delete_b2b) {
    delete_option('hb_ucs_b2b_settings');
    delete_option('hb_ucs_b2b_role_rules');
    delete_option('hb_ucs_b2b_profiles');

    // Remove all customer overrides
    delete_metadata('user', 0, 'hb_ucs_b2b_customer_rules', '', true);
}

if ($delete_roles) {
    // Remove module settings
    delete_option('hb_ucs_roles_settings');

    // Remove roles created/managed by this module only
    $created = get_option('hb_ucs_roles_created', []);
    if (is_array($created)) {
        foreach ($created as $role) {
            $role = sanitize_key((string)$role);
            if ($role && get_role($role)) {
                remove_role($role);
            }
        }
    }
    delete_option('hb_ucs_roles_created');
}

if ($delete_invoice) {
    delete_option('hb_ucs_invoice_email_settings');

    // Remove all stored invoice email addresses.
    delete_metadata('user', 0, '_hb_invoice_email', '', true);
}

if ($delete_cust_note) {
    delete_option('hb_ucs_customer_order_note_settings');

    // Remove customer note on user level.
    delete_metadata('user', 0, 'customer_order_note_internal', '', true);

    // Remove snapshot on order level.
    delete_metadata('post', 0, '_customer_order_note_internal', '', true);
}

if ($delete_subs) {
    delete_option('hb_ucs_subscriptions_settings');

    // Best-effort cleanup of generated subscription products.
    $generated = get_posts([
        'post_type' => 'product',
        'post_status' => ['publish', 'private', 'draft'],
        'numberposts' => -1,
        'fields' => 'ids',
        'meta_query' => [
            [
                'key' => '_hb_ucs_subs_generated',
                'value' => '1',
                'compare' => '=',
            ],
        ],
    ]);
    if (is_array($generated)) {
        foreach ($generated as $pid) {
            $pid = (int) $pid;
            if ($pid > 0) {
                wp_delete_post($pid, true);
            }
        }
    }

    // Remove product-level meta on base products.
    delete_metadata('post', 0, '_hb_ucs_subs_enabled', '', true);
    foreach (['1w', '2w', '3w', '4w'] as $k) {
        delete_metadata('post', 0, '_hb_ucs_subs_price_' . $k, '', true);
        delete_metadata('post', 0, '_hb_ucs_subs_child_' . $k, '', true);

        delete_metadata('post', 0, '_hb_ucs_subs_disc_enabled_' . $k, '', true);
        delete_metadata('post', 0, '_hb_ucs_subs_disc_type_' . $k, '', true);
        delete_metadata('post', 0, '_hb_ucs_subs_disc_value_' . $k, '', true);
    }
}

// Drop custom tables if you add them later (examples):
// global $wpdb;
// $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}hb_ucs_logs");
// $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}hb_ucs_relations");
