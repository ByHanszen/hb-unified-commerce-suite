<?php
// =============================
// src/Core/Settings.php
// =============================
namespace HB\UCS\Core;

use WC_Shipping_Zones;

if (!defined('ABSPATH')) exit;

class Settings {
    const OPT = 'hb_ucs_settings';          // algemene module toggles
    const OPT_QLS = 'hb_ucs_qls';           // QLS specifieke instellingen
    const OPT_INVOICE_EMAIL = 'hb_ucs_invoice_email_settings'; // Invoice e-mail module instellingen
    const OPT_CUSTOMER_ORDER_NOTE = 'hb_ucs_customer_order_note_settings'; // Klantnotitie module instellingen
    const OPT_SUBSCRIPTIONS = 'hb_ucs_subscriptions_settings'; // Subscriptions module instellingen
    const LEGACY_QLS = 'qls_exclude_settings'; // oude plugin optie (migratie)

    public function init(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register']);
        add_action('admin_init', [$this, 'maybe_migrate_legacy_qls']);

        // Ensure WooCommerce enhanced selects look correct on our custom admin pages.
        // Run after WooCommerce registers its assets.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets'], 20);

        // B2B admin handlers (admin-post + AJAX) moeten altijd beschikbaar zijn
        add_action('admin_init', [$this, 'init_b2b_admin']);

        // Rollenbeheer handlers
        add_action('admin_init', [$this, 'init_roles_admin']);

        // Form handlers (fix voor “De link is verlopen”)
        add_action('admin_post_hb_ucs_save_qls', [$this, 'handle_save_qls']);
        add_action('admin_post_hb_ucs_qls_recalc', [$this, 'handle_recalc_qls']);
        add_action('admin_post_hb_ucs_save_invoice_email', [$this, 'handle_save_invoice_email']);
        add_action('admin_post_hb_ucs_save_customer_order_note', [$this, 'handle_save_customer_order_note']);
        add_action('admin_post_hb_ucs_save_subscriptions', [$this, 'handle_save_subscriptions']);

        // Zorg voor QLS defaults bij eerste run
        add_option(self::OPT_QLS, $this->defaults_qls());

        // Defaults voor Invoice e-mail settings
        add_option(self::OPT_INVOICE_EMAIL, $this->defaults_invoice_email());

        // Defaults voor Klantnotitie module settings
        add_option(self::OPT_CUSTOMER_ORDER_NOTE, $this->defaults_customer_order_note());

        // Defaults voor Subscriptions module settings
        add_option(self::OPT_SUBSCRIPTIONS, $this->defaults_subscriptions());
    }

    public function enqueue_admin_assets(string $hook): void {
        if (!is_admin()) return;
        if (!isset($_GET['page'])) return;

        $page = (string) $_GET['page'];
        if ($page === '' || strpos($page, 'hb-ucs') !== 0) return;

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 3) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/assets/', $pluginFile));
        $ver = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';
        wp_enqueue_style('hb-ucs-admin', $base . 'admin-hb-ucs.css', [], $ver);

        // Only when WooCommerce is present.
        if (!function_exists('WC')) return;

        // WooCommerce admin.css contains SelectWoo/select2 styles used by enhanced selects.
        wp_enqueue_style('woocommerce_admin_styles');

        // Make sure enhanced selects (customer/product searches etc.) have their scripts.
        wp_enqueue_script('selectWoo');
        wp_enqueue_script('wc-enhanced-select');

        // Trigger enhanced select init on our custom pages.
        wp_enqueue_script('hb-ucs-admin', $base . 'admin-hb-ucs.js', ['jquery', 'wc-enhanced-select'], $ver, true);
    }

    public function render_release_notes(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Je hebt geen rechten om deze pagina te bekijken.', 'hb-ucs'));
        }

        $path = dirname(__FILE__, 3) . '/CHANGELOG.md';
        $content = '';
        if (is_readable($path)) {
            $raw = file_get_contents($path);
            $content = is_string($raw) ? $raw : '';
        }

        $ver = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Release notes', 'hb-ucs') . '</h1>';
        if ($ver !== '') {
            echo '<p class="description">' . sprintf(esc_html__('Huidige plugin versie: %s', 'hb-ucs'), esc_html($ver)) . '</p>';
        }

        if ($content === '') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Geen CHANGELOG.md gevonden of niet leesbaar.', 'hb-ucs') . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:1100px;white-space:pre-wrap;">' . esc_html($content) . '</pre>';
        echo '</div>';
    }

    public function init_b2b_admin(): void {
        if (!class_exists('HB\\UCS\\Modules\\B2B\\Admin\\B2BAdminPage')) return;
        (new \HB\UCS\Modules\B2B\Admin\B2BAdminPage())->register_handlers();
    }

    public function init_roles_admin(): void {
        if (!class_exists('HB\\UCS\\Modules\\Roles\\Admin\\RolesAdminPage')) return;
        (new \HB\UCS\Modules\Roles\Admin\RolesAdminPage())->register_handlers();
    }

    private function defaults_main(): array {
        return [
            'modules' => [
                'invoice_email' => 0,
                'qls'           => 1,
                'b2b'           => 0,
                'roles'         => 0,
                'customer_order_note' => 0,
                'subscriptions' => 0,
            ],
        ];
    }

    private function defaults_qls(): array {
        return [
            'api_user_id'                    => 0,
            'excluded_shipping_method_ids'   => [], // bv ['flat_rate','local_pickup']
            'excluded_shipping_instance_ids' => [], // ints (instance_id)
            'delete_data_on_uninstall'       => 0,
            'recalc_days'                    => 90,
        ];
    }

    private function defaults_invoice_email(): array {
        return [
            'delete_data_on_uninstall' => 0,
            'allowed_roles' => [],
            'allowed_b2b_profiles' => [],
        ];
    }

    private function defaults_customer_order_note(): array {
        return [
            'delete_data_on_uninstall' => 0,
        ];
    }

    private function defaults_subscriptions(): array {
        return [
            // Engine:
            // - manual: geen dependency op WooCommerce Subscriptions; klant rekent elke periode opnieuw af.
            // - wcs: gebruikt WooCommerce Subscriptions (optioneel) voor automatische renewals.
            'engine' => 'manual',
            'recurring_enabled' => 0,
            'recurring_webhook_token' => '',
            'delete_data_on_uninstall' => 0,
            'frequencies' => [
                '1w' => [
                    'enabled' => 1,
                    'label'   => __('Elke week', 'hb-ucs'),
                    'interval'=> 1,
                    'period'  => 'week',
                ],
                '2w' => [
                    'enabled' => 1,
                    'label'   => __('Elke 2 weken', 'hb-ucs'),
                    'interval'=> 2,
                    'period'  => 'week',
                ],
                '3w' => [
                    'enabled' => 1,
                    'label'   => __('Elke 3 weken', 'hb-ucs'),
                    'interval'=> 3,
                    'period'  => 'week',
                ],
                '4w' => [
                    'enabled' => 1,
                    'label'   => __('Elke 4 weken', 'hb-ucs'),
                    'interval'=> 4,
                    'period'  => 'week',
                ],
            ],
        ];
    }

    public function maybe_migrate_legacy_qls(): void {
        $migrated = get_option(self::OPT_QLS.'_migrated');
        if ($migrated) return;

        $legacy = get_option(self::LEGACY_QLS);
        if (!is_array($legacy)) {
            update_option(self::OPT_QLS.'_migrated', 1);
            return;
        }
        $current = get_option(self::OPT_QLS, $this->defaults_qls());

        // Mogelijke legacy sleutels mappen
        if (isset($legacy['excluded_shipping_method_ids']) && is_array($legacy['excluded_shipping_method_ids'])) {
            $current['excluded_shipping_method_ids'] = array_values(array_filter(array_map('strval', $legacy['excluded_shipping_method_ids'])));
        }
        if (isset($legacy['excluded_shipping_instance_ids']) && is_array($legacy['excluded_shipping_instance_ids'])) {
            $current['excluded_shipping_instance_ids'] = array_values(array_map('intval', $legacy['excluded_shipping_instance_ids']));
        }
        if (isset($legacy['api_user_id'])) {
            $current['api_user_id'] = (int) $legacy['api_user_id'];
        }

        update_option(self::OPT_QLS, $current);
        update_option(self::OPT_QLS.'_migrated', 1);
    }

    public function menu(): void {
        add_menu_page(
            __('Unified Commerce Suite', 'hb-ucs'),
            __('HB UCS', 'hb-ucs'),
            'manage_options',
            'hb-ucs',
            [$this, 'render'],
            'dashicons-hammer',
            56
        );

        add_submenu_page(
            'hb-ucs',
            __('Modules', 'hb-ucs'),
            __('Modules', 'hb-ucs'),
            'manage_options',
            'hb-ucs',
            [$this, 'render']
        );

        add_submenu_page(
            'hb-ucs',
            __('Release notes', 'hb-ucs'),
            __('Release notes', 'hb-ucs'),
            'manage_options',
            'hb-ucs-release-notes',
            [$this, 'render_release_notes']
        );

        add_submenu_page(
            'hb-ucs',
            __('QLS – Orders uitsluiten', 'hb-ucs'),
            __('QLS', 'hb-ucs'),
            'manage_options',
            'hb-ucs-qls',
            [$this, 'render_qls']
        );

        add_submenu_page(
            'hb-ucs',
            __('Invoice e-mail', 'hb-ucs'),
            __('Invoice e-mail', 'hb-ucs'),
            'manage_options',
            'hb-ucs-invoice-email',
            [$this, 'render_invoice_email']
        );

        add_submenu_page(
            'hb-ucs',
            __('Klantnotitie', 'hb-ucs'),
            __('Klantnotitie', 'hb-ucs'),
            'manage_options',
            'hb-ucs-customer-order-note',
            [$this, 'render_customer_order_note']
        );

        add_submenu_page(
            'hb-ucs',
            __('Abonnementen', 'hb-ucs'),
            __('Abonnementen', 'hb-ucs'),
            'manage_options',
            'hb-ucs-subscriptions',
            [$this, 'render_subscriptions']
        );

        add_submenu_page(
            'hb-ucs',
            __('B2B klanten', 'hb-ucs'),
            __('B2B', 'hb-ucs'),
            'manage_options',
            'hb-ucs-b2b',
            function () {
                if (class_exists('HB\\UCS\\Modules\\B2B\\Admin\\B2BAdminPage')) {
                    (new \HB\UCS\Modules\B2B\Admin\B2BAdminPage())->render();
                    return;
                }
                echo '<div class="wrap"><h1>'.esc_html__('B2B klanten', 'hb-ucs').'</h1>';
                echo '<div class="notice notice-error"><p>'.esc_html__('B2B module is niet geladen.', 'hb-ucs').'</p></div></div>';
            }
        );

        add_submenu_page(
            'hb-ucs',
            __('Rollenbeheer', 'hb-ucs'),
            __('Rollen', 'hb-ucs'),
            'manage_options',
            'hb-ucs-roles',
            function () {
                if (class_exists('HB\\UCS\\Modules\\Roles\\Admin\\RolesAdminPage')) {
                    (new \HB\UCS\Modules\Roles\Admin\RolesAdminPage())->render();
                    return;
                }
                echo '<div class="wrap"><h1>'.esc_html__('Rollenbeheer', 'hb-ucs').'</h1>';
                echo '<div class="notice notice-error"><p>'.esc_html__('Rollen module is niet geladen.', 'hb-ucs').'</p></div></div>';
            }
        );
    }

    public function register(): void {
        // ===== Modules pagina =====
        register_setting(self::OPT, self::OPT);

        add_settings_section('hb_ucs_modules', __('Modules', 'hb-ucs'), function () {
            echo '<p>' . esc_html__('Schakel hieronder modules aan/uit.', 'hb-ucs') . '</p>';
        }, 'hb-ucs');

        add_settings_field('invoice_email', __('Invoice e‑mail', 'hb-ucs'), function () {
            $opt = get_option(self::OPT, $this->defaults_main());
            $mods = $opt['modules'] ?? [];
            $checked = !empty($mods['invoice_email']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[modules][invoice_email]" value="1" '.$checked.'/> '.esc_html__('Activeren', 'hb-ucs').'</label>';
        }, 'hb-ucs', 'hb_ucs_modules');

        add_settings_field('qls', __('QLS exclude', 'hb-ucs'), function () {
            $opt = get_option(self::OPT, $this->defaults_main());
            $mods = $opt['modules'] ?? [];
            $checked = !empty($mods['qls']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[modules][qls]" value="1" '.$checked.'/> '.esc_html__('Activeren', 'hb-ucs').'</label>';
        }, 'hb-ucs', 'hb_ucs_modules');

        add_settings_field('b2b', __('B2B klanten', 'hb-ucs'), function () {
            $opt = get_option(self::OPT, $this->defaults_main());
            $mods = $opt['modules'] ?? [];
            $checked = !empty($mods['b2b']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[modules][b2b]" value="1" '.$checked.'/> '.esc_html__('Activeren', 'hb-ucs').'</label>';
            echo '<p class="description">'.esc_html__('Beheer rollen/klanten/profielen, betaal- en verzendmethodes, en B2B prijsregels.', 'hb-ucs').'</p>';
        }, 'hb-ucs', 'hb_ucs_modules');

        add_settings_field('roles', __('Rollenbeheer', 'hb-ucs'), function () {
            $opt = get_option(self::OPT, $this->defaults_main());
            $mods = $opt['modules'] ?? [];
            $checked = !empty($mods['roles']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[modules][roles]" value="1" '.$checked.'/> '.esc_html__('Activeren', 'hb-ucs').'</label>';
            echo '<p class="description">'.esc_html__('Beheer WordPress rollen (toevoegen/bewerken/verwijderen) vanuit deze plugin.', 'hb-ucs').'</p>';
        }, 'hb-ucs', 'hb_ucs_modules');

        add_settings_field('customer_order_note', __('Klantnotitie', 'hb-ucs'), function () {
            $opt = get_option(self::OPT, $this->defaults_main());
            $mods = $opt['modules'] ?? [];
            $checked = !empty($mods['customer_order_note']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[modules][customer_order_note]" value="1" '.$checked.'/> '.esc_html__('Activeren', 'hb-ucs').'</label>';
            echo '<p class="description">'.esc_html__('Voegt een interne klantnotitie toe op klantniveau en kopieert deze als snapshot naar nieuwe orders voor paklijsten.', 'hb-ucs').'</p>';
        }, 'hb-ucs', 'hb_ucs_modules');

        add_settings_field('subscriptions', __('Abonnementen', 'hb-ucs'), function () {
            $opt = get_option(self::OPT, $this->defaults_main());
            $mods = $opt['modules'] ?? [];
            $checked = !empty($mods['subscriptions']) ? 'checked' : '';
            echo '<label><input type="checkbox" name="'.esc_attr(self::OPT).'[modules][subscriptions]" value="1" '.$checked.'/> '.esc_html__('Activeren', 'hb-ucs').'</label>';
            echo '<p class="description">'.esc_html__('Maak van reguliere producten optioneel een abonnement (1–4 weken).', 'hb-ucs').'</p>';
        }, 'hb-ucs', 'hb_ucs_modules');
    }

    public function render(): void {
        echo '<div class="wrap"><h1>'.esc_html__('HB Unified Commerce Suite', 'hb-ucs').'</h1>';
        echo '<form method="post" action="'.esc_url(admin_url('options.php')).'">';
        settings_fields(self::OPT);
        do_settings_sections('hb-ucs');
        submit_button(__('Opslaan', 'hb-ucs'));
        echo '</form></div>';
    }

    public function render_invoice_email(): void {
        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Invoice e-mail', 'hb-ucs') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is vereist voor deze module.', 'hb-ucs') . '</p></div>';
            echo '</div>';
            return;
        }

        $main = get_option(self::OPT, $this->defaults_main());
        $mods = $main['modules'] ?? [];
        $enabled = !empty($mods['invoice_email']);
        $opt = get_option(self::OPT_INVOICE_EMAIL, $this->defaults_invoice_email());

        $allowed_roles = array_values(array_filter(array_map('strval', (array) ($opt['allowed_roles'] ?? []))));
        $allowed_profiles = array_values(array_filter(array_map('strval', (array) ($opt['allowed_b2b_profiles'] ?? []))));

        // Build role list.
        $roles = [];
        $wp_roles = wp_roles();
        if ($wp_roles && is_array($wp_roles->roles)) {
            foreach ($wp_roles->roles as $role_key => $role_data) {
                $name = is_array($role_data) ? (string) ($role_data['name'] ?? $role_key) : (string) $role_key;
                $roles[(string) $role_key] = $name;
            }
        }
        if (!empty($roles)) {
            ksort($roles, SORT_NATURAL);
        }

        // Build B2B profile list (optional).
        $profiles = [];
        if (class_exists('HB\\UCS\\Modules\\B2B\\Storage\\ProfilesStore')) {
            $store = new \HB\UCS\Modules\B2B\Storage\ProfilesStore();
            foreach ($store->all() as $pid => $p) {
                if (!is_array($p)) continue;
                $pid = (string) ($p['id'] ?? $pid);
                $pname = (string) ($p['name'] ?? $pid);
                if ($pid !== '') {
                    $profiles[$pid] = $pname;
                }
            }
            if (!empty($profiles)) {
                asort($profiles, SORT_NATURAL);
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Invoice e-mail', 'hb-ucs') . '</h1>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Instellingen opgeslagen.', 'hb-ucs');
            echo '</p></div>';
        }

        if (!empty($_GET['hb_ucs_subs_ran'])) {
            echo '<div class="notice notice-info is-dismissible"><p>';
            echo esc_html__('HB UCS: renewal job handmatig uitgevoerd. Controleer WooCommerce logs/abonnementen voor resultaat.', 'hb-ucs');
            echo '</p></div>';
        }

        if (!empty($_GET['hb_ucs_subs_demo'])) {
            $flag = sanitize_key((string) $_GET['hb_ucs_subs_demo']);
            if ($flag === 'created' && !empty($_GET['sub_id']) && is_numeric($_GET['sub_id'])) {
                $subId = (int) $_GET['sub_id'];
                $editUrl = admin_url('post.php?post=' . $subId . '&action=edit');
                echo '<div class="notice notice-success is-dismissible"><p>';
                echo esc_html__('Demo abonnement aangemaakt.', 'hb-ucs') . ' ';
                echo '<a href="' . esc_url($editUrl) . '">' . esc_html__('Open abonnement', 'hb-ucs') . '</a>';
                echo '</p></div>';
            } elseif ($flag === 'not_found') {
                echo '<div class="notice notice-warning is-dismissible"><p>';
                echo esc_html__('Geen recente bestelling gevonden met een abonnement-regel. Plaats eerst een testbestelling met een abonnement-optie, en probeer opnieuw.', 'hb-ucs');
                echo '</p></div>';
            } elseif ($flag === 'failed') {
                echo '<div class="notice notice-error is-dismissible"><p>';
                echo esc_html__('Demo abonnement aanmaken is mislukt.', 'hb-ucs');
                echo '</p></div>';
            }
        }

        if ($enabled) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Module status: ingeschakeld.', 'hb-ucs') . '</p></div>';
        } else {
            $modulesUrl = add_query_arg(['page' => 'hb-ucs'], admin_url('admin.php'));
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Module status: uitgeschakeld.', 'hb-ucs') . ' ';
            echo '<a href="' . esc_url($modulesUrl) . '">' . esc_html__('Schakel in via Modules.', 'hb-ucs') . '</a>';
            echo '</p></div>';
        }

        echo '<p>' . esc_html__('Deze module voegt een optioneel factuur e-mailadres toe aan het account en gebruikersprofiel. Na afronding van een bestelling wordt de PDF-factuur (en indien beschikbaar UBL) naar dit adres gestuurd.', 'hb-ucs') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_save_invoice_email" />';
        wp_nonce_field('hb_ucs_save_invoice_email', 'hb_ucs_save_invoice_email_nonce');

        $del = !empty($opt['delete_data_on_uninstall']) ? 'checked' : '';
        echo '<h2>' . esc_html__('Instellingen', 'hb-ucs') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_invoice_email_allowed_roles">' . esc_html__('Toegestane rollen', 'hb-ucs') . '</label></th>';
        echo '<td>';
        echo '<select id="hb_ucs_invoice_email_allowed_roles" name="hb_ucs_invoice_email[allowed_roles][]" multiple="multiple" class="wc-enhanced-select" data-placeholder="' . esc_attr__('Alle rollen (geen selectie)', 'hb-ucs') . '">';
        foreach ($roles as $role_key => $role_name) {
            $sel = in_array((string) $role_key, $allowed_roles, true) ? 'selected' : '';
            echo '<option value="' . esc_attr((string) $role_key) . '" ' . $sel . '>' . esc_html((string) $role_name . ' (' . (string) $role_key . ')') . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Geen selectie = actief voor iedereen. Met selectie = alleen actief voor gebruikers met één van deze rollen.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_invoice_email_allowed_b2b_profiles">' . esc_html__('Toegestane B2B profielen', 'hb-ucs') . '</label></th>';
        echo '<td>';
        if (empty($profiles)) {
            echo '<p class="description">' . esc_html__('Geen B2B profielen gevonden (of B2B module niet beschikbaar).', 'hb-ucs') . '</p>';
        } else {
            echo '<select id="hb_ucs_invoice_email_allowed_b2b_profiles" name="hb_ucs_invoice_email[allowed_b2b_profiles][]" multiple="multiple" class="wc-enhanced-select" data-placeholder="' . esc_attr__('Alle profielen (geen selectie)', 'hb-ucs') . '">';
            foreach ($profiles as $pid => $pname) {
                $sel = in_array((string) $pid, $allowed_profiles, true) ? 'selected' : '';
                echo '<option value="' . esc_attr((string) $pid) . '" ' . $sel . '>' . esc_html((string) $pname) . '</option>';
            }
            echo '</select>';
            echo '<p class="description">' . esc_html__('Geen selectie = actief voor iedereen. Met selectie = actief voor gebruikers die gekoppeld zijn aan één van deze profielen (via rol of gekoppelde gebruiker).', 'hb-ucs') . '</p>';
        }
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Data verwijderen bij uninstall', 'hb-ucs') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="hb_ucs_invoice_email[delete_data_on_uninstall]" value="1" ' . $del . ' /> ';
        echo esc_html__('Verwijder alle opgeslagen factuur e-mailadressen uit gebruikersprofielen bij uninstall.', 'hb-ucs');
        echo '</label>';
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';

        submit_button(__('Instellingen opslaan', 'hb-ucs'));
        echo '</form>';

        echo '</div>';
    }

    public function render_customer_order_note(): void {
        $main = get_option(self::OPT, $this->defaults_main());
        $mods = $main['modules'] ?? [];
        $enabled = !empty($mods['customer_order_note']);
        $opt = get_option(self::OPT_CUSTOMER_ORDER_NOTE, $this->defaults_customer_order_note());

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Klantnotitie', 'hb-ucs') . '</h1>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Instellingen opgeslagen.', 'hb-ucs');
            echo '</p></div>';
        }

        if ($enabled) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Module status: ingeschakeld.', 'hb-ucs') . '</p></div>';
        } else {
            $modulesUrl = add_query_arg(['page' => 'hb-ucs'], admin_url('admin.php'));
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Module status: uitgeschakeld.', 'hb-ucs') . ' ';
            echo '<a href="' . esc_url($modulesUrl) . '">' . esc_html__('Schakel in via Modules.', 'hb-ucs') . '</a>';
            echo '</p></div>';
        }

        echo '<p>' . esc_html__('Deze module voegt een interne klantnotitie toe op klantniveau (gebruikersprofiel) en kopieert deze als snapshot naar nieuwe orders. De snapshot wordt nooit overschreven.', 'hb-ucs') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_save_customer_order_note" />';
        wp_nonce_field('hb_ucs_save_customer_order_note', 'hb_ucs_save_customer_order_note_nonce');

        $del = !empty($opt['delete_data_on_uninstall']) ? 'checked' : '';
        echo '<h2>' . esc_html__('Instellingen', 'hb-ucs') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Data verwijderen bij uninstall', 'hb-ucs') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="hb_ucs_customer_order_note[delete_data_on_uninstall]" value="1" ' . $del . ' /> ';
        echo esc_html__('Verwijder klantnotities uit gebruikers en orders bij uninstall.', 'hb-ucs');
        echo '</label>';
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';

        submit_button(__('Instellingen opslaan', 'hb-ucs'));
        echo '</form>';

        echo '</div>';
    }

    public function render_subscriptions(): void {
        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap">';
            echo '<h1>' . esc_html__('Abonnementen', 'hb-ucs') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is vereist voor deze module.', 'hb-ucs') . '</p></div>';
            echo '</div>';
            return;
        }

        $main = get_option(self::OPT, $this->defaults_main());
        $mods = $main['modules'] ?? [];
        $enabled = !empty($mods['subscriptions']);
        $opt = get_option(self::OPT_SUBSCRIPTIONS, $this->defaults_subscriptions());
        $freqs = is_array($opt['frequencies'] ?? null) ? (array) $opt['frequencies'] : [];
        $recurringEnabled = !empty($opt['recurring_enabled']);
        $webhookToken = isset($opt['recurring_webhook_token']) ? (string) $opt['recurring_webhook_token'] : '';
        if ($webhookToken === '') {
            // Only for display; actual generation happens on save.
            $webhookToken = '—';
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Abonnementen', 'hb-ucs') . '</h1>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Instellingen opgeslagen.', 'hb-ucs');
            echo '</p></div>';
        }

        if ($enabled) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Module status: ingeschakeld.', 'hb-ucs') . '</p></div>';
        } else {
            $modulesUrl = add_query_arg(['page' => 'hb-ucs'], admin_url('admin.php'));
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('Module status: uitgeschakeld.', 'hb-ucs') . ' ';
            echo '<a href="' . esc_url($modulesUrl) . '">' . esc_html__('Schakel in via Modules.', 'hb-ucs') . '</a>';
            echo '</p></div>';
        }

        $engine = isset($opt['engine']) ? sanitize_key((string) $opt['engine']) : 'manual';
        if ($engine !== 'manual' && $engine !== 'wcs') {
            $engine = 'manual';
        }
        if ($engine === 'wcs' && !term_exists('subscription', 'product_type')) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('WooCommerce Subscriptions lijkt niet actief. Kies "Handmatig" of installeer/activeer WooCommerce Subscriptions.', 'hb-ucs');
            echo '</p></div>';
        }

        // Mollie must be able to reach the webhook URL from the outside.
        // On localhost/private IP this will fail with a 422 (unreachable webhookUrl).
        if ($recurringEnabled) {
            $home = (string) home_url('/');
            $host = (string) (wp_parse_url($home, PHP_URL_HOST) ?? '');
            $host = strtolower(trim($host));

            $endsWithLocal = (strlen($host) >= 6 && substr($host, -6) === '.local');
            $isLocalHost = ($host === 'localhost' || $host === '127.0.0.1' || $host === '::1' || $endsWithLocal);

            $isPrivateIp = false;
            if (!$isLocalHost && filter_var($host, FILTER_VALIDATE_IP)) {
                // Detect RFC1918 private IPv4 ranges.
                if (strpos($host, '10.') === 0 || strpos($host, '192.168.') === 0) {
                    $isPrivateIp = true;
                } elseif (preg_match('/^172\.(1[6-9]|2\d|3[0-1])\./', $host)) {
                    $isPrivateIp = true;
                }
            }

            if ($isLocalHost || $isPrivateIp) {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Let op: Mollie webhook onbereikbaar', 'hb-ucs') . '</strong><br/>';
                echo esc_html__('Je site draait op een lokale/private URL. Mollie kan de webhookUrl niet bereiken en zal de eerste betaling weigeren (422). Test recurring op een publiek bereikbare staging/live URL (HTTPS) of gebruik een tunnel (ngrok/Cloudflare Tunnel).', 'hb-ucs');
                echo '</p></div>';
            }
        }

        echo '<p>' . esc_html__('Met deze module kun je op hetzelfde product een abonnement-keuze aanbieden (naast eenmalige aankoop).', 'hb-ucs') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_save_subscriptions" />';
        wp_nonce_field('hb_ucs_save_subscriptions', 'hb_ucs_save_subscriptions_nonce');

        $del = !empty($opt['delete_data_on_uninstall']) ? 'checked' : '';

        echo '<h2>' . esc_html__('Afrekenen & verlengen', 'hb-ucs') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_subscriptions_engine">' . esc_html__('Engine', 'hb-ucs') . '</label></th>';
        echo '<td>';
        echo '<select id="hb_ucs_subscriptions_engine" name="hb_ucs_subscriptions[engine]">';
        echo '<option value="manual" ' . selected($engine, 'manual', false) . '>' . esc_html__('Handmatig (geen dependency)', 'hb-ucs') . '</option>';
        $wcsDisabled = term_exists('subscription', 'product_type') ? '' : 'disabled';
        echo '<option value="wcs" ' . selected($engine, 'wcs', false) . ' ' . $wcsDisabled . '>' . esc_html__('WooCommerce Subscriptions (automatisch, vereist plugin)', 'hb-ucs') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Handmatig: geen dependency op WooCommerce Subscriptions. Je kunt optioneel automatische verlengingen via Mollie inschakelen (zie hieronder). WCS: gebruikt WooCommerce Subscriptions voor automatische renewals (indien geïnstalleerd).', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Automatische verlengingen (Mollie)', 'hb-ucs') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Inschakelen', 'hb-ucs') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="hb_ucs_subscriptions[recurring_enabled]" value="1" ' . checked($recurringEnabled, true, false) . ' /> ';
        echo esc_html__('Start automatische verlengingen via Mollie (SEPA incasso).', 'hb-ucs');
        echo '</label>';
        echo '<p class="description">' . esc_html__('Eerste betaling gebeurt via iDEAL/creditcard; daarna worden verlengingen via SEPA Direct Debit geprobeerd. Hiervoor moet Mollie een customer/mandate hebben aangemaakt op de eerste betaling.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Webhook URL', 'hb-ucs') . '</th>';
        echo '<td>';
        echo '<input type="text" class="large-text" readonly value="' . esc_attr(add_query_arg([
            'hb_ucs_mollie_webhook' => '1',
            'token' => $webhookToken,
        ], home_url('/'))) . '" />';
        echo '<p class="description">' . esc_html__('Deze URL wordt automatisch als webhookUrl meegegeven bij elke recurring betaling. Token wordt automatisch ingevuld na opslaan.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Test', 'hb-ucs') . '</th>';
        echo '<td>';
        $runUrl = wp_nonce_url(admin_url('admin-post.php?action=hb_ucs_subs_run_now'), 'hb_ucs_subs_run_now', 'hb_ucs_subs_run_now_nonce');
        echo '<a class="button" href="' . esc_url($runUrl) . '">' . esc_html__('Renewals nu uitvoeren', 'hb-ucs') . '</a>';
        echo '&nbsp;';
        $demoUrl = wp_nonce_url(admin_url('admin-post.php?action=hb_ucs_subs_create_demo'), 'hb_ucs_subs_create_demo', 'hb_ucs_subs_create_demo_nonce');
        echo '<a class="button" href="' . esc_url($demoUrl) . '">' . esc_html__('Maak demo abonnement', 'hb-ucs') . '</a>';
        echo '<p class="description">' . esc_html__('Handig om te testen zonder te wachten op WP-Cron. Dit maakt alleen renewal orders/betalingen aan voor abonnementen die “due” zijn.', 'hb-ucs') . '</p>';
        echo '<p class="description">' . esc_html__('“Maak demo abonnement” maakt een abonnement-record aan op basis van de meest recente bestelling met een abonnement-regel, zodat je de backend-weergave kunt bekijken.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';

        echo '<h2>' . esc_html__('Frequenties', 'hb-ucs') . '</h2>';
        echo '<p class="description">' . esc_html__('Beheer welke frequenties beschikbaar zijn op productpagina’s. Per product kun je daarna de prijs per frequentie instellen.', 'hb-ucs') . '</p>';
        echo '<table class="form-table" role="presentation"><tbody>';

        foreach (['1w' => 1, '2w' => 2, '3w' => 3, '4w' => 4] as $key => $interval) {
            $row = is_array($freqs[$key] ?? null) ? (array) $freqs[$key] : [];
            $rowEnabled = !empty($row['enabled']);
            $rowLabel = (string) ($row['label'] ?? '');
            if ($rowLabel === '') {
                $rowLabel = sprintf(__('Elke %d week/weken', 'hb-ucs'), (int) $interval);
            }

            echo '<tr>';
            echo '<th scope="row">' . esc_html(sprintf(__('Frequentie %s', 'hb-ucs'), $key)) . '</th>';
            echo '<td>';
            echo '<label style="display:inline-block;margin-right:16px;">';
            echo '<input type="checkbox" name="hb_ucs_subscriptions[frequencies][' . esc_attr($key) . '][enabled]" value="1" ' . checked($rowEnabled, true, false) . ' /> ';
            echo esc_html__('Ingeschakeld', 'hb-ucs');
            echo '</label>';
            echo '<label>';
            echo esc_html__('Label', 'hb-ucs') . ': ';
            echo '<input type="text" class="regular-text" name="hb_ucs_subscriptions[frequencies][' . esc_attr($key) . '][label]" value="' . esc_attr($rowLabel) . '" />';
            echo '</label>';
            echo '<p class="description">' . esc_html(sprintf(__('Interval: %d week/weken (vast).', 'hb-ucs'), (int) $interval)) . '</p>';
            echo '</td>';
            echo '</tr>';
        }

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Data verwijderen bij uninstall', 'hb-ucs') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="hb_ucs_subscriptions[delete_data_on_uninstall]" value="1" ' . $del . ' /> ';
        echo esc_html__('Verwijder module-instellingen en gegenereerde subscription-producten bij uninstall.', 'hb-ucs');
        echo '</label>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody></table>';

        submit_button(__('Instellingen opslaan', 'hb-ucs'));
        echo '</form>';

        echo '</div>';
    }

    public function render_qls(): void {
        if (!class_exists('WooCommerce')) {
            echo '<div class="notice notice-error"><p>'.esc_html__('WooCommerce is vereist voor deze module.', 'hb-ucs').'</p></div>';
            return;
        }

        $opt = get_option(self::OPT_QLS, $this->defaults_qls());

        // === Data opbouwen voor UI ===
        $users = get_users(['fields'=>['ID','display_name','user_login'],'orderby'=>'display_name','order'=>'ASC']);

        // Zones ophalen via publieke API
        $zones_raw = class_exists('WC_Shipping_Zones') ? \WC_Shipping_Zones::get_zones() : [];
        $zones = [];
        if (is_array($zones_raw)) {
            foreach ($zones_raw as $zr) {
                $zid = isset($zr['id']) ? (int)$zr['id'] : (int)($zr['zone_id'] ?? 0);
                $zobj = new \WC_Shipping_Zone($zid);
                $zones[] = [
                    'zone_id'         => $zid,
                    'zone_name'       => $zobj->get_zone_name(),
                    'shipping_methods'=> $zobj->get_shipping_methods(),
                ];
            }
        }
        // Voeg zone 0 toe (“Rest of the world”)
        $z0 = new \WC_Shipping_Zone(0);
        $zones[] = [
            'zone_id'         => 0,
            'zone_name'       => $z0->get_zone_name(),
            'shipping_methods'=> $z0->get_shipping_methods(),
        ];

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('QLS – Orders uitsluiten', 'hb-ucs').'</h1>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html__('Instellingen opgeslagen.', 'hb-ucs');
            echo '</p></div>';
        }
        if (isset($_GET['recalc']) && is_numeric($_GET['recalc'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo esc_html(sprintf(__('Herberekening uitgevoerd voor %d orders.', 'hb-ucs'), (int) $_GET['recalc']));
            echo '</p></div>';
        }

        // === Form: instellingen opslaan (admin-post) ===
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hb_ucs_save_qls" />';
        wp_nonce_field('hb_ucs_save_qls', 'hb_ucs_save_qls_nonce');

        // Data voor selects
        $excluded_types = array_map('strval', (array)($opt['excluded_shipping_method_ids'] ?? []));
        $excluded_instances = array_map('intval', (array)($opt['excluded_shipping_instance_ids'] ?? []));

        // Verzamel unieke method_id’s uit alle zones
        $method_ids = [];
        foreach ($zones as $z) {
            foreach (($z['shipping_methods'] ?? []) as $m) {
                $mid = method_exists($m, 'get_method_id') ? $m->get_method_id() : (property_exists($m, 'id') ? $m->id : '');
                if ($mid) {
                    $method_ids[(string) $mid] = true;
                }
            }
        }
        if (!empty($method_ids)) {
            ksort($method_ids, SORT_NATURAL);
        }

        echo '<h2>'.esc_html__('Instellingen', 'hb-ucs').'</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        // API user
        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_qls_api_user_id">'.esc_html__('API gebruiker (REST orders filter)', 'hb-ucs').'</label></th>';
        echo '<td>';
        echo '<select id="hb_ucs_qls_api_user_id" name="hb_ucs_qls[api_user_id]" class="wc-enhanced-select" data-placeholder="'.esc_attr__('— geen filter —', 'hb-ucs').'">';
        echo '<option value="0">'.esc_html__('— geen filter —', 'hb-ucs').'</option>';
        foreach ($users as $u) {
            $sel = selected((int)($opt['api_user_id'] ?? 0), (int)$u->ID, false);
            $label = $u->display_name.' ('.$u->user_login.')';
            echo '<option value="'.(int)$u->ID.'" '.$sel.'>'.esc_html($label).'</option>';
        }
        echo '</select>';
        echo '<p class="description">'.esc_html__('Alleen voor deze gebruiker worden orders gefilterd in de WooCommerce REST API (wc/v2, wc/v3).', 'hb-ucs').'</p>';
        echo '</td>';
        echo '</tr>';

        // Uitsluiten per methode-type
        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_qls_excluded_shipping_method_ids">'.esc_html__('Uitsluiten per methode-type', 'hb-ucs').'</label></th>';
        echo '<td>';
        if (empty($method_ids)) {
            echo '<p>'.esc_html__('Geen verzendmethoden gevonden in je verzendzones.', 'hb-ucs').'</p>';
        } else {
            echo '<select id="hb_ucs_qls_excluded_shipping_method_ids" name="hb_ucs_qls[excluded_shipping_method_ids][]" multiple="multiple" class="wc-enhanced-select" data-placeholder="'.esc_attr__('Kies methodetypes…', 'hb-ucs').'">';
            foreach (array_keys($method_ids) as $mid) {
                $sel = in_array((string) $mid, $excluded_types, true) ? 'selected' : '';
                echo '<option value="'.esc_attr((string) $mid).'" '.$sel.'>'.esc_html((string) $mid).'</option>';
            }
            echo '</select>';
            echo '<p class="description">'.esc_html__('Selecteer methodetypes (bijv. flat_rate, local_pickup) die je wilt uitsluiten.', 'hb-ucs').'</p>';
        }
        echo '</td>';
        echo '</tr>';

        // Uitsluiten per specifieke instance
        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_qls_excluded_shipping_instance_ids">'.esc_html__('Uitsluiten per instance', 'hb-ucs').'</label></th>';
        echo '<td>';
        if (empty($zones)) {
            echo '<p>'.esc_html__('Geen verzendzones/instances gevonden.', 'hb-ucs').'</p>';
        } else {
            echo '<select id="hb_ucs_qls_excluded_shipping_instance_ids" name="hb_ucs_qls[excluded_shipping_instance_ids][]" multiple="multiple" class="wc-enhanced-select" data-placeholder="'.esc_attr__('Kies verzendmethodes (instances)…', 'hb-ucs').'">';
            foreach ($zones as $z) {
                $zone_name = (string) ($z['zone_name'] ?? ('#' . (string) ($z['zone_id'] ?? '')));
                $methods = $z['shipping_methods'] ?? [];
                if (empty($methods)) {
                    continue;
                }
                echo '<optgroup label="'.esc_attr($zone_name).'">';
                foreach ($methods as $m) {
                    $mid = method_exists($m, 'get_method_id') ? $m->get_method_id() : (property_exists($m, 'id') ? $m->id : '');
                    $iid = method_exists($m, 'get_instance_id') ? (int) $m->get_instance_id() : (int) ($m->instance_id ?? 0);
                    if (!$mid || $iid <= 0) {
                        continue;
                    }
                    $title = method_exists($m, 'get_title') ? $m->get_title() : ($m->title ?? $mid);
                    $label = sprintf('%s (%s:%d)', (string) $title, (string) $mid, (int) $iid);
                    $sel = in_array((int) $iid, $excluded_instances, true) ? 'selected' : '';
                    echo '<option value="'.esc_attr((string) $iid).'" '.$sel.'>'.esc_html($label).'</option>';
                }
                echo '</optgroup>';
            }
            echo '</select>';
            echo '<p class="description">'.esc_html__('Selecteer specifieke instances (per verzendzone) die je wilt uitsluiten.', 'hb-ucs').'</p>';
        }
        echo '</td>';
        echo '</tr>';

        // Opschonen bij verwijderen
        $del = !empty($opt['delete_data_on_uninstall']) ? 'checked' : '';
        echo '<tr>';
        echo '<th scope="row">'.esc_html__('Data verwijderen bij uninstall', 'hb-ucs').'</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="hb_ucs_qls[delete_data_on_uninstall]" value="1" '.$del.'/> '.esc_html__('Alles opruimen bij uninstall (alleen plugin-data).', 'hb-ucs').'</label>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody></table>';

        submit_button(__('Instellingen opslaan', 'hb-ucs'));
        echo '</form>';

        // === Form: herberekenen (aparte admin-post) ===
        echo '<hr />';
        echo '<h2>'.esc_html__('Eenmalig herberekenen (bestaande orders)', 'hb-ucs').'</h2>';
        echo '<p>'.esc_html__('Zet of verwijder de _qls_exclude meta op bestaande orders op basis van de huidige instellingen.', 'hb-ucs').'</p>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';
        echo '<input type="hidden" name="action" value="hb_ucs_qls_recalc" />';
        wp_nonce_field('hb_ucs_qls_recalc', 'hb_ucs_qls_recalc_nonce');

        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_qls_recalc_days">'.esc_html__('Periode (dagen terug)', 'hb-ucs').'</label></th>';
        echo '<td>';
        echo '<input type="number" min="0" step="1" name="days" id="hb_ucs_qls_recalc_days" value="'.esc_attr((int)($opt['recalc_days'] ?? 90)).'" class="small-text" /> ';
        submit_button(__('Nu herberekenen', 'hb-ucs'), 'secondary', 'submit', false);
        echo '<p class="description">'.esc_html__('Wordt opgeslagen als “laatst gebruikte periode”.', 'hb-ucs').'</p>';
        echo '</td>';
        echo '</tr>';
        echo '</tbody></table>';
        echo '</form>';

        echo '</div>';
    }

    // ========= Handlers =========

    public function handle_save_qls(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        check_admin_referer('hb_ucs_save_qls', 'hb_ucs_save_qls_nonce');

        $current = get_option(self::OPT_QLS, $this->defaults_qls());
        $raw = isset($_POST['hb_ucs_qls']) ? (array) $_POST['hb_ucs_qls'] : [];
        $clean = [
            'api_user_id'                    => isset($raw['api_user_id']) ? (int)$raw['api_user_id'] : 0,
            'excluded_shipping_method_ids'   => array_values(array_filter(array_map('strval', $raw['excluded_shipping_method_ids'] ?? []))),
            'excluded_shipping_instance_ids' => array_values(array_filter(array_map('intval', $raw['excluded_shipping_instance_ids'] ?? []))),
            'delete_data_on_uninstall'       => empty($raw['delete_data_on_uninstall']) ? 0 : 1,
            // Preserve last-used value when this field isn't present in the settings form.
            'recalc_days'                    => isset($raw['recalc_days']) ? (int)$raw['recalc_days'] : (int) ($current['recalc_days'] ?? 90),
        ];
        update_option(self::OPT_QLS, $clean, false);

        $redirect = add_query_arg(['page' => 'hb-ucs-qls', 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_recalc_qls(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        check_admin_referer('hb_ucs_qls_recalc', 'hb_ucs_qls_recalc_nonce');

        $days = isset($_POST['days']) ? (int) $_POST['days'] : 90;
        $opt = get_option(self::OPT_QLS, $this->defaults_qls());
        $opt['recalc_days'] = $days;
        update_option(self::OPT_QLS, $opt, false);

        $count = $this->recalc_legacy_meta($days);
        $redirect = add_query_arg(['page' => 'hb-ucs-qls', 'recalc' => $count], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_save_invoice_email(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        check_admin_referer('hb_ucs_save_invoice_email', 'hb_ucs_save_invoice_email_nonce');

        $raw = isset($_POST['hb_ucs_invoice_email']) ? (array) $_POST['hb_ucs_invoice_email'] : [];

        // Validate roles against existing WP roles.
        $existing_roles = [];
        $wp_roles = wp_roles();
        if ($wp_roles && is_array($wp_roles->roles)) {
            $existing_roles = array_keys($wp_roles->roles);
        }
        $allowed_roles = array_values(array_filter(array_map('sanitize_key', (array) ($raw['allowed_roles'] ?? []))));
        if (!empty($existing_roles)) {
            $allowed_roles = array_values(array_intersect($allowed_roles, $existing_roles));
        }

        // Sanitize profile IDs (optional).
        $allowed_profiles_raw = array_values(array_filter(array_map('strval', (array) ($raw['allowed_b2b_profiles'] ?? []))));
        $allowed_profiles = [];
        foreach ($allowed_profiles_raw as $pid) {
            $pid = trim($pid);
            if ($pid === '') continue;

            if (class_exists('HB\\UCS\\Modules\\B2B\\Support\\Validator')) {
                $pid = (string) \HB\UCS\Modules\B2B\Support\Validator::sanitize_profile_id($pid);
            } else {
                $pid = (string) preg_replace('/[^A-Za-z0-9_]/', '', $pid);
                if ($pid === '' || strpos($pid, 'p_') !== 0) {
                    $pid = '';
                }
            }

            if ($pid !== '') {
                $allowed_profiles[] = $pid;
            }
        }
        $allowed_profiles = array_values(array_unique($allowed_profiles));

        $clean = [
            'delete_data_on_uninstall' => empty($raw['delete_data_on_uninstall']) ? 0 : 1,
            'allowed_roles' => $allowed_roles,
            'allowed_b2b_profiles' => $allowed_profiles,
        ];
        update_option(self::OPT_INVOICE_EMAIL, $clean, false);

        $redirect = add_query_arg(['page' => 'hb-ucs-invoice-email', 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_save_customer_order_note(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        check_admin_referer('hb_ucs_save_customer_order_note', 'hb_ucs_save_customer_order_note_nonce');

        $raw = isset($_POST['hb_ucs_customer_order_note']) ? (array) $_POST['hb_ucs_customer_order_note'] : [];

        $clean = [
            'delete_data_on_uninstall' => empty($raw['delete_data_on_uninstall']) ? 0 : 1,
        ];
        update_option(self::OPT_CUSTOMER_ORDER_NOTE, $clean, false);

        $redirect = add_query_arg(['page' => 'hb-ucs-customer-order-note', 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_save_subscriptions(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }
        check_admin_referer('hb_ucs_save_subscriptions', 'hb_ucs_save_subscriptions_nonce');

        $raw = isset($_POST['hb_ucs_subscriptions']) ? (array) $_POST['hb_ucs_subscriptions'] : [];

        $defaults = $this->defaults_subscriptions();
        $defaultFreqs = (array) ($defaults['frequencies'] ?? []);

        $cleanFreqs = [];
        foreach (['1w' => 1, '2w' => 2, '3w' => 3, '4w' => 4] as $key => $interval) {
            $incoming = isset($raw['frequencies'][$key]) && is_array($raw['frequencies'][$key]) ? (array) $raw['frequencies'][$key] : [];
            $label = isset($incoming['label']) ? (string) wp_unslash($incoming['label']) : (string) (($defaultFreqs[$key]['label'] ?? ''));
            $label = trim($label);
            if ($label === '') {
                $label = sprintf(__('Elke %d week/weken', 'hb-ucs'), (int) $interval);
            }

            $cleanFreqs[$key] = [
                'enabled'  => empty($incoming['enabled']) ? 0 : 1,
                'label'    => $label,
                'interval' => (int) $interval,
                'period'   => 'week',
            ];
        }

        $incomingToken = isset($raw['recurring_webhook_token']) ? (string) wp_unslash($raw['recurring_webhook_token']) : '';
        $incomingToken = trim($incomingToken);
        $existing = get_option(self::OPT_SUBSCRIPTIONS, $this->defaults_subscriptions());
        $existingToken = is_array($existing) && isset($existing['recurring_webhook_token']) ? (string) $existing['recurring_webhook_token'] : '';
        $token = $existingToken !== '' ? $existingToken : $incomingToken;
        if ($token === '') {
            $token = wp_generate_password(32, false, false);
        }

        $clean = [
            'engine' => (isset($raw['engine']) && sanitize_key((string) $raw['engine']) === 'wcs') ? 'wcs' : 'manual',
            'recurring_enabled' => empty($raw['recurring_enabled']) ? 0 : 1,
            'recurring_webhook_token' => $token,
            'delete_data_on_uninstall' => empty($raw['delete_data_on_uninstall']) ? 0 : 1,
            'frequencies' => $cleanFreqs,
        ];
        update_option(self::OPT_SUBSCRIPTIONS, $clean, false);

        $redirect = add_query_arg(['page' => 'hb-ucs-subscriptions', 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    // ====== Helper: recalc legacy meta ======
    private function recalc_legacy_meta(int $days): int {
        $opt = get_option(self::OPT_QLS, $this->defaults_qls());
        $date_after = gmdate('Y-m-d H:i:s', time() - max(0, $days) * DAY_IN_SECONDS);

        $query = new \WC_Order_Query([
            'limit'   => -1,
            'return'  => 'ids',
            'date_created' => '>' . $date_after,
            'type'    => wc_get_order_types('shop_order'),
            'status'  => array_keys(wc_get_order_statuses()),
        ]);
        $ids = $query->get_orders();
        $count = 0;
        foreach ($ids as $order_id) {
            $allow = apply_filters('hb_ucs_qls_should_send_order', true, (int)$order_id);
            // legacy meta key zoals oude plugin: _qls_exclude (1/0)
            update_post_meta($order_id, '_qls_exclude', $allow ? '0' : '1');
            $count++;
        }
        return $count;
    }
}
