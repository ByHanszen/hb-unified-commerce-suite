<?php
/**
 * Plugin Name: HB Unified Commerce Suite
 * Description: Overkoepelende plugin met modulaire features.
 * Version: 0.3.97
 * Author: Hoeksche Branders
 * Text Domain: hb-ucs
 */
if (!defined('ABSPATH')) exit;

if (!defined('HB_UCS_PLUGIN_FILE')) {
    define('HB_UCS_PLUGIN_FILE', __FILE__);
}
if (!defined('HB_UCS_VERSION')) {
    define('HB_UCS_VERSION', '0.3.97');
}

spl_autoload_register(function ($class) {
    $prefix = 'HB\\UCS\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
    $rel = substr($class, strlen($prefix));                 // e.g. "Core\Kernel"
    $path = __DIR__ . '/src/' . str_replace('\\', '/', $rel) . '.php';
    if (file_exists($path)) require_once $path;
});

register_activation_hook(__FILE__, function () {
    // Defaults voor hoofdopties
    $defaults = [
        'modules' => [
            'invoice_email' => false,
            'qls'           => true,
            'b2b'           => false,
            'roles'         => false,
            'subscriptions' => false,
        ],
    ];
    $opt = get_option('hb_ucs_settings', []);
    update_option('hb_ucs_settings', array_replace_recursive($defaults, is_array($opt)?$opt:[]));

    if (class_exists('HB\\UCS\\Core\\Settings')) {
        (new \HB\UCS\Core\Settings())->seed_default_options();
    }

    // Store current plugin version for update tracking.
    if (defined('HB_UCS_VERSION')) {
        update_option('hb_ucs_version', HB_UCS_VERSION);
    }
});

add_action('plugins_loaded', function () {
    load_plugin_textdomain('hb-ucs', false, basename(__DIR__) . '/languages');

    // Track updates and show a one-time admin notice linking to release notes.
    if (is_admin() && defined('HB_UCS_VERSION')) {
        $stored = (string) get_option('hb_ucs_version', '');
        if ($stored !== HB_UCS_VERSION) {
            update_option('hb_ucs_version', HB_UCS_VERSION);
            set_transient('hb_ucs_version_updated', [
                'from' => $stored,
                'to' => HB_UCS_VERSION,
            ], DAY_IN_SECONDS);
        }

        add_action('admin_notices', function () {
            if (!current_user_can('manage_options')) return;
            $data = get_transient('hb_ucs_version_updated');
            if (!is_array($data) || empty($data['to'])) return;
            delete_transient('hb_ucs_version_updated');

            $to = (string) ($data['to'] ?? '');
            $from = (string) ($data['from'] ?? '');
            $url = admin_url('admin.php?page=hb-ucs-release-notes');

            echo '<div class="notice notice-success is-dismissible"><p>';
            if ($from !== '') {
                echo sprintf(
                    esc_html__('HB Unified Commerce Suite is bijgewerkt van %1$s naar %2$s. Bekijk de release notes voor details.', 'hb-ucs'),
                    esc_html($from),
                    esc_html($to)
                );
            } else {
                echo sprintf(
                    esc_html__('HB Unified Commerce Suite is bijgewerkt naar %s. Bekijk de release notes voor details.', 'hb-ucs'),
                    esc_html($to)
                );
            }
            echo ' <a href="' . esc_url($url) . '">' . esc_html__('Release notes', 'hb-ucs') . '</a>';
            echo '</p></div>';
        });
    }

    // Kernel start (roept Settings->init() aan)
    if (class_exists('HB\\UCS\\Core\\Kernel')) {
        (new \HB\UCS\Core\Kernel())->boot();
    }
});
