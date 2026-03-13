<?php
// =============================
// src/Modules/B2B/Admin/B2BAdminPage.php
// =============================
namespace HB\UCS\Modules\B2B\Admin;

use HB\UCS\Core\Settings as CoreSettings;
use HB\UCS\Modules\B2B\Storage\CustomerRulesStore;
use HB\UCS\Modules\B2B\Storage\ProfilesStore;
use HB\UCS\Modules\B2B\Storage\RoleRulesStore;
use HB\UCS\Modules\B2B\Storage\SettingsStore;
use HB\UCS\Modules\B2B\Support\Validator;

if (!defined('ABSPATH')) exit;

class B2BAdminPage {
    private SettingsStore $settings;
    private RoleRulesStore $roleRules;
    private CustomerRulesStore $customerRules;
    private ProfilesStore $profiles;

    public function __construct() {
        $this->settings = new SettingsStore();
        $this->roleRules = new RoleRulesStore();
        $this->customerRules = new CustomerRulesStore();
        $this->profiles = new ProfilesStore();
    }

    public function register_handlers(): void {
        // Enqueue assets
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Form handlers
        add_action('admin_post_hb_ucs_b2b_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_hb_ucs_b2b_save_role', [$this, 'handle_save_role']);
        add_action('admin_post_hb_ucs_b2b_save_customer', [$this, 'handle_save_customer']);
        add_action('admin_post_hb_ucs_b2b_save_profile', [$this, 'handle_save_profile']);
        add_action('admin_post_hb_ucs_b2b_delete_profile', [$this, 'handle_delete_profile']);
    }

    public function enqueue_assets(string $hook): void {
        if (!isset($_GET['page']) || (string)$_GET['page'] !== 'hb-ucs-b2b') return;

        if (function_exists('WC')) {
            wp_enqueue_script('selectWoo');
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
        }

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 5) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/Modules/B2B/assets/', $pluginFile));
        $ver = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';
        wp_enqueue_style('hb-ucs-b2b-admin', $base . 'admin-b2b.css', [], $ver);
        $deps = ['jquery', 'jquery-ui-sortable'];
        if (function_exists('WC')) {
            $deps[] = 'selectWoo';
            $deps[] = 'wc-enhanced-select';
        }
        wp_enqueue_script('hb-ucs-b2b-admin', $base . 'admin-b2b.js', $deps, $ver, true);

        wp_localize_script('hb-ucs-b2b-admin', 'HB_UCS_B2B_ADMIN', [
            'page' => 'hb-ucs-b2b',
            'categoriesOptionsHtml' => $this->categories_options_html(),
            'priceTypes' => [
                ['id' => 'percent', 'label' => __('% korting (ex btw)', 'hb-ucs')],
                ['id' => 'fixed_price', 'label' => __('Vaste prijs (ex btw)', 'hb-ucs')],
                ['id' => 'fixed_discount', 'label' => __('Vaste korting bedrag (ex btw)', 'hb-ucs')],
            ],
        ]);
    }

    public function render(): void {
        if (!Validator::can_manage()) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap"><h1>'.esc_html__('B2B klanten', 'hb-ucs').'</h1>';
            echo '<div class="notice notice-error"><p>'.esc_html__('WooCommerce is vereist voor deze module.', 'hb-ucs').'</p></div></div>';
            return;
        }

        $tab = isset($_GET['tab']) ? (string) sanitize_key($_GET['tab']) : 'settings';
        if (!in_array($tab, ['settings', 'roles', 'customers', 'profiles'], true)) $tab = 'settings';

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('B2B klanten', 'hb-ucs') . '</h1>';
        echo '<p class="description">' . esc_html__('Let op: B2B prijsregels beïnvloeden productprijzen, checkout en orderberekening. Regels worden altijd berekend op de ex btw prijs.', 'hb-ucs') . '</p>';

        echo '<h2 class="nav-tab-wrapper">';
        $this->tab_link('settings', __('Instellingen', 'hb-ucs'), $tab);
        $this->tab_link('roles', __('Regels per rol', 'hb-ucs'), $tab);
        $this->tab_link('customers', __('Regels per klant', 'hb-ucs'), $tab);
        $this->tab_link('profiles', __('B2B profielen', 'hb-ucs'), $tab);
        echo '</h2>';

        if ($tab === 'settings') $this->render_settings();
        if ($tab === 'roles') $this->render_roles();
        if ($tab === 'customers') $this->render_customers();
        if ($tab === 'profiles') $this->render_profiles();

        echo '</div>';
    }

    private function maybe_notice_no_shipping_instances(array $shippingChoices): void {
        $hasInstance = false;
        foreach (array_keys($shippingChoices) as $key) {
            $key = (string) $key;
            if (strpos($key, ':') !== false) {
                $hasInstance = true;
                break;
            }
        }

        if ($hasInstance) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Geen verzendmethodes (zone-instances) gevonden in WooCommerce verzendzones. Voeg eerst verzendmethodes toe via WooCommerce → Instellingen → Verzenden, daarna verschijnen ze hier als bijv. flat_rate:3. Je kunt wel alvast op type-niveau (bijv. flat_rate) configureren.', 'hb-ucs');
        echo '</p></div>';
    }

    private function tab_link(string $tab, string $label, string $active): void {
        $url = add_query_arg(['page' => 'hb-ucs-b2b', 'tab' => $tab], admin_url('admin.php'));
        $class = 'nav-tab' . ($tab === $active ? ' nav-tab-active' : '');
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private function render_settings(): void {
        $main = get_option(CoreSettings::OPT, []);
        $enabled = !empty(($main['modules'] ?? [])['b2b']);
        $opt = $this->settings->get();

        $shippingChoices = \HB\UCS\Modules\B2B\Support\Validator::shipping_choices();
        $paymentChoices = \HB\UCS\Modules\B2B\Support\Validator::payment_gateway_choices();

        $this->maybe_notice_no_shipping_instances($shippingChoices);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:14px;">';
        echo '<input type="hidden" name="action" value="hb_ucs_b2b_save_settings" />';
        wp_nonce_field('hb_ucs_b2b_save_settings', 'hb_ucs_b2b_nonce');

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">' . esc_html__('Module', 'hb-ucs') . '</th><td>';
        echo '<label><input type="checkbox" name="hb_ucs_modules[b2b]" value="1" ' . checked($enabled, true, false) . ' /> ' . esc_html__('B2B module actief', 'hb-ucs') . '</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Default gedrag (B2C / geen match)', 'hb-ucs') . '</th><td>';
        echo '<label><input type="radio" name="hb_ucs_b2b[default_behavior]" value="woocommerce_all" ' . checked($opt['default_behavior'], 'woocommerce_all', false) . ' /> ' . esc_html__('Alles tonen (standaard WooCommerce)', 'hb-ucs') . '</label><br>';
        echo '<label><input type="radio" name="hb_ucs_b2b[default_behavior]" value="whitelist" ' . checked($opt['default_behavior'], 'whitelist', false) . ' /> ' . esc_html__('Alleen standaardmethodes (whitelist)', 'hb-ucs') . '</label>';
        echo '<p class="description">' . esc_html__('Deze default geldt voor ingelogde B2C gebruikers (zonder match) en kan ook gelden voor gasten, afhankelijk van de gast-instelling hieronder.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Gast checkout (niet ingelogd)', 'hb-ucs') . '</th><td>';
        echo '<label><input type="radio" name="hb_ucs_b2b[guest_behavior]" value="inherit_default" ' . checked($opt['guest_behavior'] ?? 'inherit_default', 'inherit_default', false) . ' /> ' . esc_html__('Gebruik default gedrag hierboven', 'hb-ucs') . '</label><br>';
        echo '<label><input type="radio" name="hb_ucs_b2b[guest_behavior]" value="woocommerce_all" ' . checked($opt['guest_behavior'] ?? 'inherit_default', 'woocommerce_all', false) . ' /> ' . esc_html__('Altijd alles tonen (WooCommerce)', 'hb-ucs') . '</label><br>';
        echo '<label><input type="radio" name="hb_ucs_b2b[guest_behavior]" value="whitelist" ' . checked($opt['guest_behavior'] ?? 'inherit_default', 'whitelist', false) . ' /> ' . esc_html__('Alleen onderstaande gast-methodes (whitelist)', 'hb-ucs') . '</label>';
        echo '<p class="description">' . esc_html__('Handig als je gasten wilt beperken (bijv. alleen iDEAL) maar ingelogde klanten meer opties mogen zien.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Gast verzendmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('hb_ucs_b2b[guest_allowed_shipping][]', (array)($opt['guest_allowed_shipping'] ?? []), $shippingChoices);
        echo '<p class="description">' . esc_html__('Wordt alleen gebruikt als "Gast checkout" op whitelist staat.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Gast betaalmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('hb_ucs_b2b[guest_allowed_payments][]', (array)($opt['guest_allowed_payments'] ?? []), $paymentChoices);
        echo '<p class="description">' . esc_html__('Wordt alleen gebruikt als "Gast checkout" op whitelist staat.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Standaard verzendmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('hb_ucs_b2b[default_allowed_shipping][]', (array)$opt['default_allowed_shipping'], $shippingChoices);
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Standaard betaalmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('hb_ucs_b2b[default_allowed_payments][]', (array)$opt['default_allowed_payments'], $paymentChoices);
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Merge regels (meerdere rollen)', 'hb-ucs') . '</th><td>';
        echo '<select name="hb_ucs_b2b[roles_merge]">';
        echo '<option value="union" ' . selected($opt['roles_merge'], 'union', false) . '>' . esc_html__('Union (alles wat 1 rol toestaat)', 'hb-ucs') . '</option>';
        echo '<option value="intersection" ' . selected($opt['roles_merge'], 'intersection', false) . '>' . esc_html__('Intersection (alleen wat alle rollen toestaan)', 'hb-ucs') . '</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Klant-overrule', 'hb-ucs') . '</th><td>';
        echo '<select name="hb_ucs_b2b[customer_overrule_mode]">';
        echo '<option value="override" ' . selected($opt['customer_overrule_mode'], 'override', false) . '>' . esc_html__('Override (klant gaat vóór rollen)', 'hb-ucs') . '</option>';
        echo '<option value="merge" ' . selected($opt['customer_overrule_mode'], 'merge', false) . '>' . esc_html__('Merge (rol + klant samenvoegen)', 'hb-ucs') . '</option>';
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Prijsberekening', 'hb-ucs') . '</th><td>';
        echo '<p><strong>' . esc_html__('Berekenen op ex btw:', 'hb-ucs') . '</strong> ' . esc_html__('verplicht (altijd).', 'hb-ucs') . '</p>';
        echo '<label>' . esc_html__('Basis voor korting:', 'hb-ucs') . ' ';
        echo '<select name="hb_ucs_b2b[price_base]">';
        echo '<option value="regular" ' . selected($opt['price_base'], 'regular', false) . '>' . esc_html__('Regular price (standaard)', 'hb-ucs') . '</option>';
        echo '<option value="current" ' . selected($opt['price_base'], 'current', false) . '>' . esc_html__('Actuele prijs (incl. sale)', 'hb-ucs') . '</option>';
        echo '</select></label>';
        echo '<br><label>' . esc_html__('Rounding (decimals):', 'hb-ucs') . ' <input type="number" min="0" max="6" name="hb_ucs_b2b[rounding_decimals]" value="' . esc_attr((int)$opt['rounding_decimals']) . '" class="small-text" /></label>';
        echo '<p><label><input type="checkbox" name="hb_ucs_b2b[priority_product_over_category]" value="1" ' . checked(!empty($opt['priority_product_over_category']), true, false) . ' /> ' . esc_html__('Productregels hebben voorrang op categorierregels', 'hb-ucs') . '</label></p>';
        echo '<p><label><input type="checkbox" name="hb_ucs_b2b[fixed_price_wins]" value="1" ' . checked(!empty($opt['fixed_price_wins']), true, false) . ' /> ' . esc_html__('Vaste prijs wint boven korting', 'hb-ucs') . '</label></p>';
        echo '<p><label>' . esc_html__('Als meerdere rollen regels hebben:', 'hb-ucs') . ' ';
        echo '<select name="hb_ucs_b2b[multi_role_price_strategy]">';
        echo '<option value="lowest_price" ' . selected($opt['multi_role_price_strategy'], 'lowest_price', false) . '>' . esc_html__('Kies laagste prijs (meest voordelig)', 'hb-ucs') . '</option>';
        echo '<option value="highest_discount" ' . selected($opt['multi_role_price_strategy'], 'highest_discount', false) . '>' . esc_html__('Kies hoogste korting', 'hb-ucs') . '</option>';
        echo '</select></label></p>';
        echo '<p><label>' . esc_html__('Graceful fallback bij lege selectie:', 'hb-ucs') . ' ';
        echo '<select name="hb_ucs_b2b[graceful_fallback_methods]">';
        echo '<option value="all" ' . selected($opt['graceful_fallback_methods'], 'all', false) . '>' . esc_html__('Toon alles (aanbevolen)', 'hb-ucs') . '</option>';
        echo '<option value="default" ' . selected($opt['graceful_fallback_methods'], 'default', false) . '>' . esc_html__('Val terug op default methodes', 'hb-ucs') . '</option>';
        echo '<option value="none" ' . selected($opt['graceful_fallback_methods'], 'none', false) . '>' . esc_html__('Toon niets', 'hb-ucs') . '</option>';
        echo '</select></label></p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Backend orders', 'hb-ucs') . '</th><td>';
        echo '<label><input type="checkbox" name="hb_ucs_b2b[admin_auto_recalc_on_customer_change]" value="1" ' . checked(!empty($opt['admin_auto_recalc_on_customer_change']), true, false) . ' /> ' . esc_html__('Automatisch herberekenen als klant wijzigt (optioneel)', 'hb-ucs') . '</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Uninstall cleanup', 'hb-ucs') . '</th><td>';
        echo '<label><input type="checkbox" name="hb_ucs_b2b[delete_data_on_uninstall]" value="1" ' . checked(!empty($opt['delete_data_on_uninstall']), true, false) . ' /> ' . esc_html__('Bij verwijderen ook alle B2B moduledata verwijderen', 'hb-ucs') . '</label>';
        echo '</td></tr>';

        echo '</table>';

        submit_button(__('Instellingen opslaan', 'hb-ucs'));
        echo '</form>';
    }

    private function render_roles(): void {
        $role = isset($_GET['role']) ? (string) sanitize_key($_GET['role']) : '';

        $wp_roles = wp_roles();
        $roles = $wp_roles && is_array($wp_roles->roles) ? $wp_roles->roles : [];

        echo '<form method="get" action="' . esc_url(admin_url('admin.php')) . '" style="margin:14px 0;">';
        echo '<input type="hidden" name="page" value="hb-ucs-b2b" />';
        echo '<input type="hidden" name="tab" value="roles" />';
        echo '<select name="role">';
        echo '<option value="">' . esc_html__('— Kies rol —', 'hb-ucs') . '</option>';
        foreach ($roles as $key => $r) {
            $key = (string) $key;
            $name = is_array($r) && isset($r['name']) ? (string)$r['name'] : $key;
            echo '<option value="' . esc_attr($key) . '" ' . selected($role, $key, false) . '>' . esc_html($name . ' (' . $key . ')') . '</option>';
        }
        echo '</select> ';
        submit_button(__('Selecteer', 'hb-ucs'), 'secondary', 'submit', false);
        echo '</form>';

        if ($role === '' || !isset($roles[$role])) {
            echo '<p class="description">' . esc_html__('Selecteer een rol om regels te beheren.', 'hb-ucs') . '</p>';
            return;
        }

        $data = $this->roleRules->get($role);
        $shippingChoices = \HB\UCS\Modules\B2B\Support\Validator::shipping_choices();
        $paymentChoices = \HB\UCS\Modules\B2B\Support\Validator::payment_gateway_choices();

        $this->maybe_notice_no_shipping_instances($shippingChoices);

        $selectedProfiles = $this->profiles_linked_to_role($role);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_b2b_save_role" />';
        echo '<input type="hidden" name="role" value="' . esc_attr($role) . '" />';
        wp_nonce_field('hb_ucs_b2b_save_role', 'hb_ucs_b2b_nonce');

        echo '<h3>' . esc_html__('Toegestane methodes', 'hb-ucs') . '</h3>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Verzendmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('allowed_shipping[]', (array)($data['allowed_shipping'] ?? []), $shippingChoices);
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Betaalmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('allowed_payments[]', (array)($data['allowed_payments'] ?? []), $paymentChoices);
        echo '</td></tr>';
        echo '</table>';

        echo '<h3>' . esc_html__('B2B profielen voor deze rol', 'hb-ucs') . '</h3>';
        $this->render_profiles_multiselect('linked_profiles[]', $selectedProfiles);

        echo '<h3>' . esc_html__('Prijsregels (ex btw)', 'hb-ucs') . '</h3>';
        $this->render_pricing_rules_editor($data['pricing'] ?? []);

        echo '<h3>' . esc_html__('Prijsweergave', 'hb-ucs') . '</h3>';
        $this->render_price_display_fields('role', $data);

        submit_button(__('Opslaan', 'hb-ucs'));
        echo '</form>';
    }

    private function render_customers(): void {
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : 0;

        echo '<p>' . esc_html__('Zoek klant:', 'hb-ucs') . '</p>';
        echo '<select class="wc-customer-search" style="width: 320px;" data-placeholder="' . esc_attr__('Zoek klant op naam/e-mail…', 'hb-ucs') . '" data-allow_clear="true"></select>';
        echo '<p class="description">' . esc_html__('Na selectie wordt de pagina herladen met de klantregels.', 'hb-ucs') . '</p>';

        if ($user_id <= 0) {
            echo '<p class="description">' . esc_html__('Selecteer een klant om regels te beheren.', 'hb-ucs') . '</p>';
            return;
        }

        $user = get_user_by('id', $user_id);
        if (!$user) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Klant niet gevonden.', 'hb-ucs') . '</p></div>';
            return;
        }

        $data = $this->customerRules->get($user_id);
        $shippingChoices = \HB\UCS\Modules\B2B\Support\Validator::shipping_choices();
        $paymentChoices = \HB\UCS\Modules\B2B\Support\Validator::payment_gateway_choices();

        $this->maybe_notice_no_shipping_instances($shippingChoices);
        $selectedProfiles = $this->profiles_linked_to_user($user_id);

        echo '<hr />';
        echo '<h3>' . esc_html(sprintf(__('Klant: %s', 'hb-ucs'), $user->display_name . ' <' . $user->user_email . '>')) . '</h3>';
        echo '<p><strong>' . esc_html__('Rollen:', 'hb-ucs') . '</strong> ' . esc_html(implode(', ', (array)$user->roles)) . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_b2b_save_customer" />';
        echo '<input type="hidden" name="user_id" value="' . (int)$user_id . '" />';
        wp_nonce_field('hb_ucs_b2b_save_customer', 'hb_ucs_b2b_nonce');

        echo '<h4>' . esc_html__('Toegestane methodes', 'hb-ucs') . '</h4>';
        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Verzendmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('allowed_shipping[]', (array)($data['allowed_shipping'] ?? []), $shippingChoices);
        echo '</td></tr>';
        echo '<tr><th scope="row">' . esc_html__('Betaalmethodes', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('allowed_payments[]', (array)($data['allowed_payments'] ?? []), $paymentChoices);
        echo '</td></tr>';
        echo '</table>';

        echo '<h4>' . esc_html__('B2B profielen voor deze klant', 'hb-ucs') . '</h4>';
        $this->render_profiles_multiselect('linked_profiles[]', $selectedProfiles);

        echo '<h4>' . esc_html__('Prijsregels (ex btw)', 'hb-ucs') . '</h4>';
        $this->render_pricing_rules_editor($data['pricing'] ?? []);

        echo '<h4>' . esc_html__('Prijsweergave', 'hb-ucs') . '</h4>';
        $this->render_price_display_fields('customer', $data);

        submit_button(__('Opslaan', 'hb-ucs'));
        echo '</form>';
    }

    private function render_profiles(): void {
        $action = isset($_GET['action2']) ? (string) sanitize_key($_GET['action2']) : '';
        $profile_id = isset($_GET['profile_id']) ? (string) Validator::sanitize_profile_id((string) $_GET['profile_id']) : '';

        if ($action === 'edit' && $profile_id !== '') {
            $this->render_profile_edit($profile_id);
            return;
        }
        if ($action === 'new') {
            $this->render_profile_edit('new');
            return;
        }

        $all = $this->profiles->all();

        echo '<p><a class="button button-primary" href="' . esc_url(add_query_arg(['page'=>'hb-ucs-b2b','tab'=>'profiles','action2'=>'new'], admin_url('admin.php'))) . '">' . esc_html__('Nieuw profiel', 'hb-ucs') . '</a></p>';

        if (empty($all)) {
            echo '<p class="description">' . esc_html__('Nog geen profielen.', 'hb-ucs') . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__('Naam', 'hb-ucs') . '</th><th>' . esc_html__('ID', 'hb-ucs') . '</th><th>' . esc_html__('Gekoppelde rollen', 'hb-ucs') . '</th><th>' . esc_html__('Gekoppelde klanten', 'hb-ucs') . '</th><th>' . esc_html__('Acties', 'hb-ucs') . '</th></tr></thead><tbody>';

        foreach ($all as $id => $p) {
            if (!is_array($p)) continue;
            $name = (string)($p['name'] ?? $id);
            $roles = (array)($p['linked_roles'] ?? []);
            $users = (array)($p['linked_users'] ?? []);

            $editUrl = add_query_arg(['page'=>'hb-ucs-b2b','tab'=>'profiles','action2'=>'edit','profile_id'=>$id], admin_url('admin.php'));
            $delUrl = wp_nonce_url(
                add_query_arg(['action' => 'hb_ucs_b2b_delete_profile', 'profile_id' => $id], admin_url('admin-post.php')),
                'hb_ucs_b2b_delete_profile',
                'hb_ucs_b2b_nonce'
            );

            echo '<tr>';
            echo '<td>' . esc_html($name) . '</td>';
            echo '<td><code>' . esc_html($id) . '</code></td>';
            echo '<td>' . esc_html(implode(', ', $roles)) . '</td>';
            echo '<td>' . esc_html(count($users)) . '</td>';
            echo '<td><a class="button button-small" href="' . esc_url($editUrl) . '">' . esc_html__('Bewerken', 'hb-ucs') . '</a> ';
            echo '<a class="button button-small" href="' . esc_url($delUrl) . '" onclick="return confirm(\'' . esc_js(__('Profiel verwijderen?', 'hb-ucs')) . '\');">' . esc_html__('Verwijderen', 'hb-ucs') . '</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_profile_edit(string $profile_id): void {
        $p = $profile_id === 'new' ? [] : $this->profiles->get($profile_id);

        $shippingChoices = \HB\UCS\Modules\B2B\Support\Validator::shipping_choices();
        $paymentChoices = \HB\UCS\Modules\B2B\Support\Validator::payment_gateway_choices();

        $this->maybe_notice_no_shipping_instances($shippingChoices);

        $name = (string)($p['name'] ?? '');
        $allowedShipping = (array)($p['allowed_shipping'] ?? []);
        $allowedPayments = (array)($p['allowed_payments'] ?? []);
        $pricing = (array)($p['pricing'] ?? []);
        $linkedRoles = (array)($p['linked_roles'] ?? []);
        $linkedUsers = array_map('intval', (array)($p['linked_users'] ?? []));

        $backUrl = add_query_arg(['page'=>'hb-ucs-b2b','tab'=>'profiles'], admin_url('admin.php'));

        echo '<p><a href="' . esc_url($backUrl) . '">← ' . esc_html__('Terug naar profielen', 'hb-ucs') . '</a></p>';
        echo '<h3>' . esc_html($profile_id === 'new' ? __('Nieuw profiel', 'hb-ucs') : __('Profiel bewerken', 'hb-ucs')) . '</h3>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_b2b_save_profile" />';
        echo '<input type="hidden" name="profile_id" value="' . esc_attr($profile_id) . '" />';
        wp_nonce_field('hb_ucs_b2b_save_profile', 'hb_ucs_b2b_nonce');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Naam', 'hb-ucs') . '</th><td>';
        echo '<input type="text" name="profile[name]" value="' . esc_attr($name) . '" class="regular-text" required />';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Allowed shipping', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('profile[allowed_shipping][]', $allowedShipping, $shippingChoices);
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Allowed payments', 'hb-ucs') . '</th><td>';
        $this->render_multiselect('profile[allowed_payments][]', $allowedPayments, $paymentChoices);
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Koppel aan rollen', 'hb-ucs') . '</th><td>';
        $wp_roles = wp_roles();
        $roles = $wp_roles && is_array($wp_roles->roles) ? $wp_roles->roles : [];
        echo '<select name="profile[linked_roles][]" multiple class="wc-enhanced-select" style="min-width:320px;">';
        foreach ($roles as $key => $r) {
            $key = (string)$key;
            $nameR = is_array($r) && isset($r['name']) ? (string)$r['name'] : $key;
            echo '<option value="' . esc_attr($key) . '" ' . selected(in_array($key, $linkedRoles, true), true, false) . '>' . esc_html($nameR . ' (' . $key . ')') . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Koppel aan klanten', 'hb-ucs') . '</th><td>';
        echo '<div class="hb-ucs-b2b-linked-users" data-field-name="profile[linked_users][]">';
        foreach ($linkedUsers as $uid) {
            $u = get_user_by('id', $uid);
            if (!$u) continue;
            echo '<div class="hb-ucs-b2b-linked-user">';
            echo '<input type="hidden" name="profile[linked_users][]" value="' . (int)$uid . '">';
            echo '<span>' . esc_html($u->display_name . ' <' . $u->user_email . '>') . '</span> ';
            echo '<button type="button" class="button-link-delete hb-ucs-b2b-remove-linked-user">' . esc_html__('Verwijderen', 'hb-ucs') . '</button>';
            echo '</div>';
        }
        echo '</div>';
        echo '<p><select class="wc-customer-search" style="width:320px;" data-placeholder="' . esc_attr__('Zoek klant om toe te voegen…', 'hb-ucs') . '" data-allow_clear="true"></select> ';
        echo '<button type="button" class="button" id="hb-ucs-b2b-add-linked-user">' . esc_html__('Toevoegen', 'hb-ucs') . '</button></p>';
        echo '</td></tr>';

        echo '</table>';

        echo '<h3>' . esc_html__('Prijsregels (ex btw)', 'hb-ucs') . '</h3>';
        $this->render_pricing_rules_editor($pricing);

        echo '<h3>' . esc_html__('Prijsweergave', 'hb-ucs') . '</h3>';
        $this->render_price_display_fields('profile', $p);

        submit_button(__('Opslaan', 'hb-ucs'));
        echo '</form>';
    }

    private function render_multiselect(string $name, array $selected, array $choices): void {
        $selected = array_values(array_unique(array_map('strval', $selected)));
        $selectedSet = array_fill_keys($selected, true);

        // Mark these selects as sortable; JS will allow drag/drop reordering.
        echo '<select name="' . esc_attr($name) . '" multiple class="wc-enhanced-select hb-ucs-sortable-select" style="min-width:360px;">';

        // Render selected options first, in their stored order.
        foreach ($selected as $sid) {
            $sid = (string) $sid;
            if ($sid === '') continue;
            $label = isset($choices[$sid]) ? (string) $choices[$sid] : $sid;
            echo '<option value="' . esc_attr($sid) . '" selected>' . esc_html($label) . '</option>';
        }

        // Render remaining options.
        foreach ($choices as $id => $label) {
            $id = (string) $id;
            if ($id === '' || isset($selectedSet[$id])) continue;
            echo '<option value="' . esc_attr($id) . '">' . esc_html((string) $label) . '</option>';
        }

        echo '</select>';
        echo '<p class="description" style="margin-top:6px;">' . esc_html__('Tip: sleep de geselecteerde methodes om de volgorde te bepalen.', 'hb-ucs') . '</p>';
    }

    private function render_profiles_multiselect(string $name, array $selectedProfileIds): void {
        $all = $this->profiles->all();
        echo '<select name="' . esc_attr($name) . '" multiple class="wc-enhanced-select" style="min-width:360px;">';
        foreach ($all as $id => $p) {
            if (!is_array($p)) continue;
            $label = (string)($p['name'] ?? $id);
            echo '<option value="' . esc_attr((string)$id) . '" ' . selected(in_array((string)$id, $selectedProfileIds, true), true, false) . '>' . esc_html($label . ' (' . $id . ')') . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Profielen worden samengevoegd met de regels van de rol/klant.', 'hb-ucs') . '</p>';
    }

    private function render_price_display_fields(string $context, array $data): void {
        $mode = isset($data['price_display_mode']) ? (string) $data['price_display_mode'] : '';
        $label = isset($data['price_display_label']) ? (string) $data['price_display_label'] : '';

        // For backwards compatibility, default to showing the adjusted price only.
        if ($mode === '') {
            $mode = 'adjusted';
        }

        $modeField = 'price_display_mode';
        $labelField = 'price_display_label';

        // Profiles are posted inside profile[...] array.
        if ($context === 'profile') {
            $modeField = 'profile[price_display_mode]';
            $labelField = 'profile[price_display_label]';
        }

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">' . esc_html__('Welke prijs tonen?', 'hb-ucs') . '</th><td>';
        echo '<select name="' . esc_attr($modeField) . '">';
        echo '<option value="adjusted" ' . selected($mode, 'adjusted', false) . '>' . esc_html__('Prijs na korting (B2B prijs)', 'hb-ucs') . '</option>';
        echo '<option value="both" ' . selected($mode, 'both', false) . '>' . esc_html__('Beide prijzen (origineel + B2B)', 'hb-ucs') . '</option>';
        echo '<option value="original" ' . selected($mode, 'original', false) . '>' . esc_html__('Alleen originele prijs (geen B2B prijs toepassen)', 'hb-ucs') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Let op: “Alleen originele prijs” schakelt B2B prijsaanpassingen uit voor deze bron (rol/profiel/klant) zodra dit de effectieve instelling is.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Label bij B2B prijs', 'hb-ucs') . '</th><td>';
        echo '<input type="text" class="regular-text" name="' . esc_attr($labelField) . '" value="' . esc_attr($label) . '" placeholder="' . esc_attr__('Bijv. Wederverkopersprijs', 'hb-ucs') . '" />';
        echo '<p class="description">' . esc_html__('Wordt getoond vóór de prijs na korting (bij “B2B prijs” of “Beide prijzen”). Laat leeg om geen label te tonen.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '</table>';
    }

    private function profiles_linked_to_role(string $role): array {
        $out = [];
        foreach ($this->profiles->all() as $id => $p) {
            if (!is_array($p)) continue;
            if (in_array($role, (array)($p['linked_roles'] ?? []), true)) {
                $out[] = (string) $id;
            }
        }
        return $out;
    }

    private function profiles_linked_to_user(int $user_id): array {
        $out = [];
        foreach ($this->profiles->all() as $id => $p) {
            if (!is_array($p)) continue;
            $users = array_map('intval', (array)($p['linked_users'] ?? []));
            if (in_array($user_id, $users, true)) {
                $out[] = (string) $id;
            }
        }
        return $out;
    }

    private function render_pricing_rules_editor(array $pricing): void {
        $cats = (array)($pricing['categories'] ?? []);
        $prods = (array)($pricing['products'] ?? []);

        echo '<div class="hb-ucs-b2b-pricing">';

        echo '<h4>' . esc_html__('Categorie regels', 'hb-ucs') . '</h4>';
        echo '<table class="widefat striped hb-ucs-b2b-rules-table" data-kind="category">';
        echo '<thead><tr><th>' . esc_html__('Categorie', 'hb-ucs') . '</th><th>' . esc_html__('Type', 'hb-ucs') . '</th><th>' . esc_html__('Waarde (ex btw)', 'hb-ucs') . '</th><th></th></tr></thead><tbody>';
        if (!empty($cats)) {
            foreach ($cats as $term_id => $rule) {
                $this->render_category_rule_row((int)$term_id, (array)$rule);
            }
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button hb-ucs-b2b-add-row" data-kind="category">' . esc_html__('Categorie regel toevoegen', 'hb-ucs') . '</button></p>';

        echo '<h4>' . esc_html__('Product regels', 'hb-ucs') . '</h4>';
        echo '<table class="widefat striped hb-ucs-b2b-rules-table" data-kind="product">';
        echo '<thead><tr><th>' . esc_html__('Product', 'hb-ucs') . '</th><th>' . esc_html__('Type', 'hb-ucs') . '</th><th>' . esc_html__('Waarde (ex btw)', 'hb-ucs') . '</th><th></th></tr></thead><tbody>';
        if (!empty($prods)) {
            foreach ($prods as $pid => $rule) {
                $this->render_product_rule_row((int)$pid, (array)$rule);
            }
        }
        echo '</tbody></table>';
        echo '<p><button type="button" class="button hb-ucs-b2b-add-row" data-kind="product">' . esc_html__('Product regel toevoegen', 'hb-ucs') . '</button></p>';

        echo '</div>';
    }

    private function render_category_rule_row(int $term_id, array $rule): void {
        $type = (string)($rule['type'] ?? 'percent');
        $value = (string)($rule['value'] ?? '');

        echo '<tr>';
        echo '<td><select name="pricing_categories[id][]" class="hb-ucs-b2b-category-select">' . $this->categories_options_html($term_id) . '</select></td>';
        echo '<td>' . $this->price_type_select('pricing_categories[type][]', $type) . '</td>';
        echo '<td><input type="number" step="0.01" min="0" name="pricing_categories[value][]" value="' . esc_attr($value) . '" /></td>';
        echo '<td><button type="button" class="button-link-delete hb-ucs-b2b-remove-row">' . esc_html__('Verwijderen', 'hb-ucs') . '</button></td>';
        echo '</tr>';
    }

    private function render_product_rule_row(int $product_id, array $rule): void {
        $type = (string)($rule['type'] ?? 'percent');
        $value = (string)($rule['value'] ?? '');

        $label = '';
        $p = function_exists('wc_get_product') ? wc_get_product($product_id) : null;
        if ($p) {
            $label = $p->get_formatted_name();
        }

        echo '<tr>';
        echo '<td>';
        echo '<select class="wc-product-search" style="width: 320px;" name="pricing_products[id][]" data-placeholder="' . esc_attr__('Zoek product…', 'hb-ucs') . '" data-allow_clear="true" data-action="woocommerce_json_search_products_and_variations">';
        if ($product_id > 0 && $label) {
            echo '<option value="' . (int)$product_id . '" selected="selected">' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '</td>';
        echo '<td>' . $this->price_type_select('pricing_products[type][]', $type) . '</td>';
        echo '<td><input type="number" step="0.01" min="0" name="pricing_products[value][]" value="' . esc_attr($value) . '" /></td>';
        echo '<td><button type="button" class="button-link-delete hb-ucs-b2b-remove-row">' . esc_html__('Verwijderen', 'hb-ucs') . '</button></td>';
        echo '</tr>';
    }

    private function price_type_select(string $name, string $selected): string {
        $opts = [
            'percent' => __('% korting', 'hb-ucs'),
            'fixed_price' => __('Vaste prijs', 'hb-ucs'),
            'fixed_discount' => __('Vaste korting', 'hb-ucs'),
        ];
        $html = '<select name="' . esc_attr($name) . '">';
        foreach ($opts as $id => $label) {
            $html .= '<option value="' . esc_attr($id) . '" ' . selected($selected, $id, false) . '>' . esc_html($label) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function categories_options_html(int $selected_id = 0): string {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (!is_array($terms)) $terms = [];

        $html = '<option value="0">' . esc_html__('— Kies categorie —', 'hb-ucs') . '</option>';
        foreach ($terms as $t) {
            if (!is_object($t)) continue;
            $id = (int) $t->term_id;
            $name = (string) $t->name;
            $html .= '<option value="' . (int)$id . '" ' . selected($selected_id, $id, false) . '>' . esc_html($name . ' (#' . $id . ')') . '</option>';
        }
        return $html;
    }

    // ========== Handlers ==========

    public function handle_save_settings(): void {
        if (!Validator::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_b2b_save_settings', 'hb_ucs_b2b_nonce');

        $b2bRaw = isset($_POST['hb_ucs_b2b']) ? (array) $_POST['hb_ucs_b2b'] : [];
        $this->settings->update($b2bRaw);

        // Module toggle in main settings
        $modulesRaw = isset($_POST['hb_ucs_modules']) ? (array) $_POST['hb_ucs_modules'] : [];
        $main = get_option(CoreSettings::OPT, []);
        if (!is_array($main)) $main = [];
        $main['modules'] = $main['modules'] ?? [];
        $main['modules']['b2b'] = empty($modulesRaw['b2b']) ? 0 : 1;
        update_option(CoreSettings::OPT, $main, false);

        $redirect = add_query_arg(['page' => 'hb-ucs-b2b', 'tab' => 'settings', 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_save_role(): void {
        if (!Validator::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_b2b_save_role', 'hb_ucs_b2b_nonce');

        $role = isset($_POST['role']) ? (string) sanitize_key($_POST['role']) : '';
        if ($role === '') wp_die('missing role');

        $raw = [
            'allowed_shipping' => $_POST['allowed_shipping'] ?? [],
            'allowed_payments' => $_POST['allowed_payments'] ?? [],
            'price_display_mode' => $_POST['price_display_mode'] ?? '',
            'price_display_label' => $_POST['price_display_label'] ?? '',
            'pricing_categories' => $_POST['pricing_categories'] ?? [],
            'pricing_products' => $_POST['pricing_products'] ?? [],
        ];
        $this->roleRules->save($role, $raw);

        // Profile linking updates
        $selectedProfiles = array_values(array_filter(array_map([Validator::class, 'sanitize_profile_id'], (array)($_POST['linked_profiles'] ?? []))));
        $this->sync_profiles_for_role($role, $selectedProfiles);

        $redirect = add_query_arg(['page' => 'hb-ucs-b2b', 'tab' => 'roles', 'role' => $role, 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_save_customer(): void {
        if (!Validator::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_b2b_save_customer', 'hb_ucs_b2b_nonce');

        $user_id = isset($_POST['user_id']) ? (int) $_POST['user_id'] : 0;
        if ($user_id <= 0) wp_die('missing user');

        $raw = [
            'allowed_shipping' => $_POST['allowed_shipping'] ?? [],
            'allowed_payments' => $_POST['allowed_payments'] ?? [],
            'price_display_mode' => $_POST['price_display_mode'] ?? '',
            'price_display_label' => $_POST['price_display_label'] ?? '',
            'pricing_categories' => $_POST['pricing_categories'] ?? [],
            'pricing_products' => $_POST['pricing_products'] ?? [],
        ];
        $this->customerRules->save($user_id, $raw);

        $selectedProfiles = array_values(array_filter(array_map([Validator::class, 'sanitize_profile_id'], (array)($_POST['linked_profiles'] ?? []))));
        $this->sync_profiles_for_user($user_id, $selectedProfiles);

        $redirect = add_query_arg(['page' => 'hb-ucs-b2b', 'tab' => 'customers', 'user_id' => $user_id, 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_save_profile(): void {
        if (!Validator::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_b2b_save_profile', 'hb_ucs_b2b_nonce');

        $profile_id = isset($_POST['profile_id']) ? (string) Validator::sanitize_profile_id((string) $_POST['profile_id']) : 'new';
        if ($profile_id === '') $profile_id = 'new';
        $profile = isset($_POST['profile']) ? (array) $_POST['profile'] : [];

        // pricing arrays are posted at root level from editor.
        $profile['pricing_categories'] = $_POST['pricing_categories'] ?? [];
        $profile['pricing_products'] = $_POST['pricing_products'] ?? [];

        $saved_id = $this->profiles->save($profile_id, $profile);

        $redirect = add_query_arg(['page' => 'hb-ucs-b2b', 'tab' => 'profiles', 'action2' => 'edit', 'profile_id' => $saved_id, 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_delete_profile(): void {
        if (!Validator::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_b2b_delete_profile', 'hb_ucs_b2b_nonce');

        $profile_id = isset($_GET['profile_id']) ? (string) Validator::sanitize_profile_id((string) $_GET['profile_id']) : '';
        if ($profile_id === '') wp_die('missing profile');
        $this->profiles->delete($profile_id);

        $redirect = add_query_arg(['page' => 'hb-ucs-b2b', 'tab' => 'profiles', 'deleted' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    private function sync_profiles_for_role(string $role, array $selectedProfileIds): void {
        $all = $this->profiles->all();
        $changed = false;

        foreach ($all as $id => $p) {
            if (!is_array($p)) continue;
            $roles = array_values(array_filter(array_map('strval', (array)($p['linked_roles'] ?? []))));
            $has = in_array($role, $roles, true);
            $should = in_array((string)$id, $selectedProfileIds, true);
            if ($should && !$has) {
                $roles[] = $role;
                $p['linked_roles'] = array_values(array_unique($roles));
                $all[$id] = $p;
                $changed = true;
            }
            if (!$should && $has) {
                $p['linked_roles'] = array_values(array_diff($roles, [$role]));
                $all[$id] = $p;
                $changed = true;
            }
        }

        if ($changed) {
            update_option(ProfilesStore::OPT, $all, false);
        }
    }

    private function sync_profiles_for_user(int $user_id, array $selectedProfileIds): void {
        $all = $this->profiles->all();
        $changed = false;

        foreach ($all as $id => $p) {
            if (!is_array($p)) continue;
            $users = array_values(array_filter(array_map('intval', (array)($p['linked_users'] ?? []))));
            $has = in_array($user_id, $users, true);
            $should = in_array((string)$id, $selectedProfileIds, true);

            if ($should && !$has) {
                $users[] = $user_id;
                $p['linked_users'] = array_values(array_unique($users));
                $all[$id] = $p;
                $changed = true;
            }
            if (!$should && $has) {
                $p['linked_users'] = array_values(array_diff($users, [$user_id]));
                $all[$id] = $p;
                $changed = true;
            }
        }

        if ($changed) {
            update_option(ProfilesStore::OPT, $all, false);
        }
    }
}
