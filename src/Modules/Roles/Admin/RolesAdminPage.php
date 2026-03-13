<?php
// =============================
// src/Modules/Roles/Admin/RolesAdminPage.php
// =============================
namespace HB\UCS\Modules\Roles\Admin;

use HB\UCS\Core\Settings as CoreSettings;
use HB\UCS\Modules\Roles\Storage\CreatedRolesStore;

if (!defined('ABSPATH')) exit;

class RolesAdminPage {
    /** @var \HB\UCS\Modules\Roles\Storage\RolesSettingsStore */
    private $settings;
    /** @var CreatedRolesStore */
    private $created;

    public function __construct() {
        $this->settings = new \HB\UCS\Modules\Roles\Storage\RolesSettingsStore();
        $this->created = new CreatedRolesStore();
    }

    public function register_handlers(): void {
        add_action('admin_post_hb_ucs_roles_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_hb_ucs_roles_create', [$this, 'handle_create_role']);
        add_action('admin_post_hb_ucs_roles_update', [$this, 'handle_update_role']);
        add_action('admin_post_hb_ucs_roles_delete', [$this, 'handle_delete_role']);
    }

    public function render(): void {
        if (!\HB\UCS\Modules\Roles\Support\RolesGuard::can_manage()) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        $main = get_option(CoreSettings::OPT, []);
        $enabled = !empty(($main['modules'] ?? [])['roles']);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Rollenbeheer', 'hb-ucs') . '</h1>';

        if (!$enabled) {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Deze module is uitgeschakeld. Schakel hem in via HB UCS → Modules.', 'hb-ucs') . '</p></div>';
        }

        $this->render_notices();

        $tab = isset($_GET['tab']) ? sanitize_key((string)$_GET['tab']) : 'overview';
        if (!in_array($tab, ['overview', 'new', 'edit', 'settings'], true)) $tab = 'overview';

        echo '<h2 class="nav-tab-wrapper">';
        $this->tab('overview', __('Overzicht', 'hb-ucs'), $tab);
        $this->tab('new', __('Nieuwe rol', 'hb-ucs'), $tab);
        $this->tab('settings', __('Instellingen', 'hb-ucs'), $tab);
        echo '</h2>';

        if ($tab === 'overview') $this->render_overview();
        if ($tab === 'new') $this->render_new();
        if ($tab === 'edit') $this->render_edit();
        if ($tab === 'settings') $this->render_settings();

        echo '</div>';
    }

    private function notice(string $type, string $message): void {
        $type = in_array($type, ['success', 'error', 'warning', 'info'], true) ? $type : 'info';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function render_notices(): void {
        $notice = isset($_GET['hb_ucs_roles_notice']) ? sanitize_key((string) $_GET['hb_ucs_roles_notice']) : '';
        $code = isset($_GET['hb_ucs_roles_code']) ? sanitize_key((string) $_GET['hb_ucs_roles_code']) : '';
        $role = isset($_GET['hb_ucs_roles_role']) ? sanitize_key((string) $_GET['hb_ucs_roles_role']) : '';

        // Backwards compatibility with older redirect args.
        if ($notice === '' && isset($_GET['created'])) {
            $notice = 'created';
            $role = sanitize_key((string) $_GET['created']);
        }
        if ($notice === '' && isset($_GET['deleted'])) {
            $notice = 'deleted';
            $role = sanitize_key((string) $_GET['deleted']);
        }
        if ($notice === '' && !empty($_GET['updated'])) {
            $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : '';
            $notice = ($tab === 'settings') ? 'settings_saved' : 'updated';
        }

        if ($notice === '') return;

        if ($notice === 'created') {
            $this->notice('success', $role ? sprintf(__('Rol aangemaakt: %s', 'hb-ucs'), $role) : __('Rol aangemaakt.', 'hb-ucs'));
            return;
        }
        if ($notice === 'updated') {
            $this->notice('success', $role ? sprintf(__('Rol opgeslagen: %s', 'hb-ucs'), $role) : __('Rol opgeslagen.', 'hb-ucs'));
            return;
        }
        if ($notice === 'deleted') {
            $this->notice('success', $role ? sprintf(__('Rol verwijderd: %s', 'hb-ucs'), $role) : __('Rol verwijderd.', 'hb-ucs'));
            return;
        }
        if ($notice === 'settings_saved') {
            $this->notice('success', __('Instellingen opgeslagen.', 'hb-ucs'));
            return;
        }

        if ($notice === 'error') {
            $message = __('Actie mislukt.', 'hb-ucs');
            if ($code === 'missing') $message = __('Vul alle verplichte velden in.', 'hb-ucs');
            if ($code === 'invalid_slug') $message = __('Ongeldige rol slug. Gebruik alleen lowercase, cijfers en underscores.', 'hb-ucs');
            if ($code === 'exists') $message = __('Rol bestaat al.', 'hb-ucs');
            if ($code === 'base_missing') $message = __('Basisrol niet gevonden.', 'hb-ucs');
            if ($code === 'not_found') $message = __('Rol niet gevonden.', 'hb-ucs');
            if ($code === 'protected') $message = __('Deze rol kan niet verwijderd worden.', 'hb-ucs');
            if ($code === 'create_failed') $message = __('Rol aanmaken is mislukt.', 'hb-ucs');
            $this->notice('error', $message);
            return;
        }
    }

    private function redirect_admin(string $tab, array $args = []): void {
        $base = ['page' => 'hb-ucs-roles', 'tab' => $tab];
        $redirect = add_query_arg(array_merge($base, $args), admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    private function tab(string $tab, string $label, string $active): void {
        $url = add_query_arg(['page' => 'hb-ucs-roles', 'tab' => $tab], admin_url('admin.php'));
        $class = 'nav-tab' . ($tab === $active ? ' nav-tab-active' : '');
        echo '<a class="' . esc_attr($class) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }

    private function render_overview(): void {
        $roles = wp_roles();
        $all = $roles && is_array($roles->roles) ? $roles->roles : [];
        $managed = $this->created->all();

        echo '<p class="description">' . esc_html__('Tip: maak B2B-rollen aan (bijv. b2b_basic) en koppel B2B-profielen daaraan.', 'hb-ucs') . '</p>';

        echo '<table class="widefat striped">';
        echo '<thead><tr><th>' . esc_html__('Rol', 'hb-ucs') . '</th><th>' . esc_html__('Slug', 'hb-ucs') . '</th><th>' . esc_html__('Users', 'hb-ucs') . '</th><th>' . esc_html__('Acties', 'hb-ucs') . '</th></tr></thead><tbody>';

        foreach ($all as $slug => $data) {
            $slug = (string) $slug;
            $label = is_array($data) && isset($data['name']) ? (string)$data['name'] : $slug;
            $count = $this->count_users_with_role($slug);

            $editUrl = add_query_arg(['page'=>'hb-ucs-roles','tab'=>'edit','role'=>$slug], admin_url('admin.php'));

            $deleteUrl = wp_nonce_url(
                add_query_arg(['action' => 'hb_ucs_roles_delete', 'role' => $slug], admin_url('admin-post.php')),
                'hb_ucs_roles_delete',
                'hb_ucs_roles_nonce'
            );

            echo '<tr>';
            echo '<td>' . esc_html($label) . (in_array($slug, $managed, true) ? ' <span class="description">(' . esc_html__('managed', 'hb-ucs') . ')</span>' : '') . '</td>';
            echo '<td><code>' . esc_html($slug) . '</code></td>';
            echo '<td>' . esc_html((string)$count) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . esc_url($editUrl) . '">' . esc_html__('Bewerken', 'hb-ucs') . '</a> ';
            if (\HB\UCS\Modules\Roles\Support\RolesGuard::can_delete_role($slug)) {
                echo '<a class="button button-small" href="' . esc_url($deleteUrl) . '" onclick="return confirm(\'' . esc_js(__('Rol verwijderen? Users met deze rol worden (optioneel) omgezet.', 'hb-ucs')) . '\');">' . esc_html__('Verwijderen', 'hb-ucs') . '</a>';
            } else {
                echo '<span class="description">' . esc_html__('Beschermd', 'hb-ucs') . '</span>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_new(): void {
        $roles = wp_roles();
        $all = $roles && is_array($roles->roles) ? $roles->roles : [];

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_roles_create" />';
        wp_nonce_field('hb_ucs_roles_create', 'hb_ucs_roles_nonce');

        echo '<table class="form-table" role="presentation">';
        echo '<tr><th scope="row">' . esc_html__('Rol slug', 'hb-ucs') . '</th><td>';
        echo '<input type="text" name="role_slug" class="regular-text" placeholder="b2b_basic" required />';
        echo '<p class="description">' . esc_html__('Alleen lowercase, cijfers en underscores. Wordt intern gebruikt.', 'hb-ucs') . '</p>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Weergavenaam', 'hb-ucs') . '</th><td>';
        echo '<input type="text" name="role_label" class="regular-text" placeholder="B2B Basis" required />';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Kopieer capabilities van', 'hb-ucs') . '</th><td>';
        echo '<select name="base_role">';
        foreach ($all as $slug => $data) {
            $slug = (string)$slug;
            $label = is_array($data) && isset($data['name']) ? (string)$data['name'] : $slug;
            echo '<option value="' . esc_attr($slug) . '" ' . selected($slug, 'customer', false) . '>' . esc_html($label . ' (' . $slug . ')') . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '</table>';

        submit_button(__('Rol aanmaken', 'hb-ucs'));
        echo '</form>';
    }

    private function render_edit(): void {
        $role = isset($_GET['role']) ? sanitize_key((string)$_GET['role']) : '';
        $r = $role ? get_role($role) : null;
        if (!$role || !$r) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Rol niet gevonden.', 'hb-ucs') . '</p></div>';
            return;
        }

        $label = \HB\UCS\Modules\Roles\Support\RolesGuard::role_label($role);
        $knownCaps = \HB\UCS\Modules\Roles\Support\RolesGuard::all_known_caps();
        $activeCaps = is_array($r->capabilities) ? array_keys(array_filter($r->capabilities)) : [];

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_roles_update" />';
        echo '<input type="hidden" name="role" value="' . esc_attr($role) . '" />';
        wp_nonce_field('hb_ucs_roles_update', 'hb_ucs_roles_nonce');

        echo '<h3>' . esc_html__('Rol bewerken', 'hb-ucs') . '</h3>';
        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">' . esc_html__('Slug', 'hb-ucs') . '</th><td><code>' . esc_html($role) . '</code></td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Weergavenaam', 'hb-ucs') . '</th><td>';
        echo '<input type="text" name="role_label" class="regular-text" value="' . esc_attr($label) . '" required />';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Capabilities', 'hb-ucs') . '</th><td>';
        echo '<p class="description">' . esc_html__('Selecteer capabilities die deze rol mag hebben. (Advanced)', 'hb-ucs') . '</p>';
        echo '<div style="max-height:320px; overflow:auto; background:#fff; border:1px solid #ccd0d4; padding:10px;">';
        foreach ($knownCaps as $cap) {
            $checked = in_array($cap, $activeCaps, true);
            echo '<label style="display:block; margin:2px 0;">';
            echo '<input type="checkbox" name="caps[]" value="' . esc_attr($cap) . '" ' . checked($checked, true, false) . ' /> ';
            echo esc_html($cap);
            echo '</label>';
        }
        echo '</div>';
        echo '</td></tr>';

        echo '</table>';

        submit_button(__('Opslaan', 'hb-ucs'));
        echo '</form>';
    }

    private function render_settings(): void {
        $opt = $this->settings->get();
        $roles = wp_roles();
        $all = $roles && is_array($roles->roles) ? $roles->roles : [];

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_roles_save_settings" />';
        wp_nonce_field('hb_ucs_roles_save_settings', 'hb_ucs_roles_nonce');

        echo '<table class="form-table" role="presentation">';

        echo '<tr><th scope="row">' . esc_html__('Uninstall cleanup', 'hb-ucs') . '</th><td>';
        echo '<label><input type="checkbox" name="roles_settings[delete_data_on_uninstall]" value="1" ' . checked(!empty($opt['delete_data_on_uninstall']), true, false) . ' /> ' . esc_html__('Bij verwijderen ook moduledata en aangemaakte rollen verwijderen', 'hb-ucs') . '</label>';
        echo '</td></tr>';

        echo '<tr><th scope="row">' . esc_html__('Bij rol verwijderen: users omzetten', 'hb-ucs') . '</th><td>';
        echo '<label><input type="checkbox" name="roles_settings[reassign_users_on_delete]" value="1" ' . checked(!empty($opt['reassign_users_on_delete']), true, false) . ' /> ' . esc_html__('Users met de verwijderde rol overzetten naar:', 'hb-ucs') . '</label> ';
        echo '<select name="roles_settings[reassign_target_role]">';
        foreach ($all as $slug => $data) {
            $slug = (string)$slug;
            $label = is_array($data) && isset($data['name']) ? (string)$data['name'] : $slug;
            echo '<option value="' . esc_attr($slug) . '" ' . selected($opt['reassign_target_role'], $slug, false) . '>' . esc_html($label . ' (' . $slug . ')') . '</option>';
        }
        echo '</select>';
        echo '</td></tr>';

        echo '</table>';

        submit_button(__('Opslaan', 'hb-ucs'));
        echo '</form>';
    }

    // ========== Handlers ==========

    public function handle_save_settings(): void {
        if (!\HB\UCS\Modules\Roles\Support\RolesGuard::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_roles_save_settings', 'hb_ucs_roles_nonce');

        $raw = isset($_POST['roles_settings']) ? (array) $_POST['roles_settings'] : [];
        $this->settings->update($raw);

        $this->redirect_admin('settings', ['hb_ucs_roles_notice' => 'settings_saved']);
    }

    public function handle_create_role(): void {
        if (!\HB\UCS\Modules\Roles\Support\RolesGuard::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_roles_create', 'hb_ucs_roles_nonce');

        $slugRaw = isset($_POST['role_slug']) ? (string) $_POST['role_slug'] : '';
        $slugRaw = trim($slugRaw);
        $slug = $slugRaw !== '' ? sanitize_key($slugRaw) : '';
        $label = isset($_POST['role_label']) ? trim(wp_strip_all_tags((string)$_POST['role_label'])) : '';
        $base = isset($_POST['base_role']) ? sanitize_key((string)$_POST['base_role']) : 'customer';

        if ($slug === '' || $label === '') {
            $this->redirect_admin('new', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'missing']);
        }
        if ($slug !== $slugRaw || !preg_match('/^[a-z0-9_]+$/', $slugRaw)) {
            $this->redirect_admin('new', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'invalid_slug']);
        }
        if (get_role($slug)) {
            $this->redirect_admin('new', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'exists']);
        }
        if (!get_role($base)) {
            $this->redirect_admin('new', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'base_missing']);
        }

        $caps = \HB\UCS\Modules\Roles\Support\RolesGuard::caps_for_role($base);
        $created = add_role($slug, $label, $caps);
        if (!$created) {
            $this->redirect_admin('new', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'create_failed']);
        }

        $this->created->add($slug);

        $this->redirect_admin('overview', ['hb_ucs_roles_notice' => 'created', 'hb_ucs_roles_role' => $slug]);
    }

    public function handle_update_role(): void {
        if (!\HB\UCS\Modules\Roles\Support\RolesGuard::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_roles_update', 'hb_ucs_roles_nonce');

        $role = isset($_POST['role']) ? sanitize_key((string)$_POST['role']) : '';
        $label = isset($_POST['role_label']) ? trim(wp_strip_all_tags((string)$_POST['role_label'])) : '';
        $capsRaw = $_POST['caps'] ?? [];

        $r = $role ? get_role($role) : null;
        if (!$role || !$r) {
            $this->redirect_admin('overview', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'not_found']);
        }
        if ($label === '') {
            $this->redirect_admin('edit', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'missing', 'role' => $role]);
        }

        \HB\UCS\Modules\Roles\Support\RolesGuard::update_role_label($role, $label);

        $desired = \HB\UCS\Modules\Roles\Support\RolesGuard::sanitize_caps($capsRaw);
        $current = is_array($r->capabilities) ? $r->capabilities : [];

        // Remove caps not desired.
        foreach ($current as $cap => $on) {
            $cap = (string) $cap;
            if ($on && !isset($desired[$cap])) {
                $r->remove_cap($cap);
            }
        }
        // Add desired caps.
        foreach ($desired as $cap => $_) {
            if (empty($current[$cap])) {
                $r->add_cap($cap, true);
            }
        }

        $this->redirect_admin('edit', ['hb_ucs_roles_notice' => 'updated', 'hb_ucs_roles_role' => $role, 'role' => $role]);
    }

    public function handle_delete_role(): void {
        if (!\HB\UCS\Modules\Roles\Support\RolesGuard::can_manage()) wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        check_admin_referer('hb_ucs_roles_delete', 'hb_ucs_roles_nonce');

        $role = isset($_GET['role']) ? sanitize_key((string)$_GET['role']) : '';
        if ($role === '') {
            $this->redirect_admin('overview', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'missing']);
        }
        if (!\HB\UCS\Modules\Roles\Support\RolesGuard::can_delete_role($role)) {
            $this->redirect_admin('overview', ['hb_ucs_roles_notice' => 'error', 'hb_ucs_roles_code' => 'protected']);
        }

        $opt = $this->settings->get();
        if (!empty($opt['reassign_users_on_delete'])) {
            $target = sanitize_key((string)($opt['reassign_target_role'] ?? 'customer'));
            $this->reassign_users($role, $target);
        }

        remove_role($role);
        $this->created->remove($role);

        $this->redirect_admin('overview', ['hb_ucs_roles_notice' => 'deleted', 'hb_ucs_roles_role' => $role]);
    }

    private function count_users_with_role(string $role): int {
        $q = new \WP_User_Query([
            'role' => $role,
            'fields' => 'ID',
            'number' => 1,
            'count_total' => true,
        ]);
        return (int) $q->get_total();
    }

    private function reassign_users(string $from_role, string $to_role): void {
        $from_role = sanitize_key($from_role);
        $to_role = sanitize_key($to_role);
        if (!$from_role || !$to_role) return;
        if (!get_role($to_role)) return;

        $q = new \WP_User_Query([
            'role' => $from_role,
            'fields' => 'ID',
            'number' => 200,
            'paged' => 1,
        ]);

        $paged = 1;
        do {
            $q = new \WP_User_Query([
                'role' => $from_role,
                'fields' => 'ID',
                'number' => 200,
                'paged' => $paged,
            ]);
            $ids = (array) $q->get_results();
            foreach ($ids as $uid) {
                $uid = (int) $uid;
                $u = get_user_by('id', $uid);
                if (!$u) continue;
                $u->remove_role($from_role);
                if (!$u->has_cap($to_role)) {
                    $u->add_role($to_role);
                }
            }
            $paged++;
        } while (!empty($ids));
    }
}
