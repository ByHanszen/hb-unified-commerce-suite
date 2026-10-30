<?php
namespace HB\UCS\Core;

if (!defined('ABSPATH')) exit;

/** Connection-scoped locks serialize a check-and-create without expiring mid-request. */
final class DatabaseLock {
    public static function run(string $resource, callable $callback) {
        global $wpdb;
        $key = 'hb-ucs-' . sha1($wpdb->prefix . ':' . $resource);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $key)) !== 1) {
            return new \WP_Error('hb_ucs_busy', __('Er loopt al een verwerking. Probeer het later opnieuw.', 'hb-ucs'));
        }
        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $key));
        }
    }
}
