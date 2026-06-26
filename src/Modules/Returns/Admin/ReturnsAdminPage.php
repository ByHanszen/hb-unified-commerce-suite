<?php
namespace HB\UCS\Modules\Returns\Admin;

use HB\UCS\Modules\Returns\ReturnsModule;

if (!defined('ABSPATH')) {
    exit;
}

class ReturnsAdminPage {
    private ReturnsModule $module;

    public function __construct() {
        $this->module = new ReturnsModule();
    }

    public function register_handlers(): void {
        add_action('admin_post_hb_ucs_returns_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_hb_ucs_returns_update_status', [$this, 'handle_update_status']);
        add_action('admin_post_hb_ucs_returns_send_label', [$this, 'handle_send_label']);
    }

    public function render(): void {
        $this->render_settings();
    }

    public function render_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap"><h1>' . esc_html__('Retourinstellingen', 'hb-ucs') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is vereist voor deze module.', 'hb-ucs') . '</p></div></div>';
            return;
        }

        $this->module->register_post_type();

        $settings = ReturnsModule::get_settings();
        $main = get_option('hb_ucs_settings', []);
        $mods = is_array($main) ? (array) ($main['modules'] ?? []) : [];
        $enabled = !empty($mods['returns']);
        $categories = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ]);
        $selectedProducts = $this->module->get_selected_product_map((array) ($settings['excluded_product_ids'] ?? []));
        $selectedProductValue = implode(',', array_map('intval', (array) ($settings['excluded_product_ids'] ?? [])));

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Retourinstellingen', 'hb-ucs') . '</h1>';

        if (!empty($_GET['updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Retourinstellingen opgeslagen.', 'hb-ucs') . '</p></div>';
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

        echo '<p>' . esc_html__('Beheer hier de retourflow, klantinstructies en globale widgetstijl van het retourformulier.', 'hb-ucs') . '</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="hb_ucs_returns_save_settings" />';
        wp_nonce_field('hb_ucs_returns_save_settings', 'hb_ucs_returns_save_settings_nonce');

        echo '<h2>' . esc_html__('Instellingen', 'hb-ucs') . '</h2>';
        echo '<table class="form-table" role="presentation"><tbody>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_returns_return_window_days">' . esc_html__('Retourtermijn (dagen)', 'hb-ucs') . '</label></th>';
        echo '<td><input type="number" min="1" max="60" class="small-text" id="hb_ucs_returns_return_window_days" name="hb_ucs_returns[return_window_days]" value="' . esc_attr((string) (int) $settings['return_window_days']) . '" />';
        echo '<p class="description">' . esc_html__('Aantal dagen dat een klant na de geschatte afleverdatum nog een retourmelding mag starten.', 'hb-ucs') . '</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_returns_delivery_offset_days">' . esc_html__('Afleveroffset na completed', 'hb-ucs') . '</label></th>';
        echo '<td><input type="number" min="0" max="30" class="small-text" id="hb_ucs_returns_delivery_offset_days" name="hb_ucs_returns[delivery_offset_days]" value="' . esc_attr((string) (int) $settings['delivery_offset_days']) . '" />';
        echo '<p class="description">' . esc_html__('De module gebruikt completed-datum plus dit aantal dagen als geschatte afleverdatum.', 'hb-ucs') . '</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_returns_admin_email">' . esc_html__('Admin e-mailadres', 'hb-ucs') . '</label></th>';
        echo '<td><input type="email" class="regular-text" id="hb_ucs_returns_admin_email" name="hb_ucs_returns[admin_email]" value="' . esc_attr((string) $settings['admin_email']) . '" />';
        echo '<p class="description">' . esc_html__('Laat leeg om het standaard webshop admin e-mailadres te gebruiken.', 'hb-ucs') . '</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_returns_excluded_category_ids">' . esc_html__('Uitgesloten categorieën', 'hb-ucs') . '</label></th>';
        echo '<td>';
        echo '<select id="hb_ucs_returns_excluded_category_ids" name="hb_ucs_returns[excluded_category_ids][]" multiple="multiple" class="wc-enhanced-select" data-placeholder="' . esc_attr__('Geen categorieën uitgesloten', 'hb-ucs') . '">';
        if (!is_wp_error($categories)) {
            foreach ((array) $categories as $category) {
                if (!$category || !isset($category->term_id, $category->name)) {
                    continue;
                }

                $selected = in_array((int) $category->term_id, array_map('intval', (array) ($settings['excluded_category_ids'] ?? [])), true) ? 'selected' : '';
                echo '<option value="' . esc_attr((string) (int) $category->term_id) . '" ' . $selected . '>' . esc_html((string) $category->name) . '</option>';
            }
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Artikelen uit deze productcategorieën worden niet getoond in het retourformulier.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_returns_excluded_product_ids">' . esc_html__('Uitgesloten producten', 'hb-ucs') . '</label></th>';
        echo '<td>';
        echo '<input type="hidden" id="hb_ucs_returns_excluded_product_ids" class="wc-product-search" style="width: 50%;" name="hb_ucs_returns[excluded_product_ids]" data-placeholder="' . esc_attr__('Zoek producten…', 'hb-ucs') . '" data-action="woocommerce_json_search_products_and_variations" data-multiple="true" data-selected="' . esc_attr(wp_json_encode($selectedProducts)) . '" value="' . esc_attr($selectedProductValue) . '" />';
        echo '<p class="description">' . esc_html__('Specifieke producten die nooit via het retourformulier mogen worden aangemeld.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="hb_ucs_returns_customer_email_intro">' . esc_html__('Klantmail: algemene instructie', 'hb-ucs') . '</label></th>';
        echo '<td>';
        echo '<textarea class="large-text" rows="6" id="hb_ucs_returns_customer_email_intro" name="hb_ucs_returns[customer_email_intro]">' . esc_textarea((string) $settings['customer_email_intro']) . '</textarea>';
        echo '<p class="description">' . esc_html__('Deze tekst komt bovenaan de bevestigingsmail die de klant direct na de retourmelding ontvangt.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Widgetteksten', 'hb-ucs') . '</th>';
        echo '<td><fieldset style="display:grid;gap:14px;max-width:900px;">';
        foreach ($this->get_widget_text_field_map() as $key => $config) {
            $label = (string) ($config['label'] ?? '');
            $type = (string) ($config['type'] ?? 'text');
            $rows = (int) ($config['rows'] ?? 3);
            $description = (string) ($config['description'] ?? '');
            $value = (string) ($settings[$key] ?? '');

            echo '<label style="display:block;">';
            echo '<strong>' . esc_html($label) . '</strong><br />';

            if ($type === 'textarea') {
                echo '<textarea class="large-text" rows="' . esc_attr((string) $rows) . '" name="hb_ucs_returns[' . esc_attr($key) . ']">' . esc_textarea($value) . '</textarea>';
            } else {
                echo '<input type="text" class="regular-text" name="hb_ucs_returns[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" />';
            }

            if ($description !== '') {
                echo '<span class="description" style="display:block;margin-top:4px;">' . esc_html($description) . '</span>';
            }

            echo '</label>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Gebruik in de helptekst {days} voor de retourtermijn en {offset} voor de afleveroffset. Deze waarden worden automatisch vervangen in de widget.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Globale styling', 'hb-ucs') . '</th>';
        echo '<td><fieldset style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;max-width:860px;">';
        foreach ($this->get_style_field_map() as $key => $label) {
            if ($key === 'style_radius') {
                echo '<label>' . esc_html($label) . '<br /><input type="number" min="0" max="48" class="small-text" name="hb_ucs_returns[' . esc_attr($key) . ']" value="' . esc_attr((string) (int) $settings[$key]) . '" /></label>';
                continue;
            }

            echo '<label>' . esc_html($label) . '<br /><input type="color" name="hb_ucs_returns[' . esc_attr($key) . ']" value="' . esc_attr((string) $settings[$key]) . '" /></label>';
        }
        echo '</fieldset>';
        echo '<p class="description">' . esc_html__('Deze waarden vormen de standaardstijl van de shortcode en Elementor-widget. Elementor kan ze per widget overschrijven.', 'hb-ucs') . '</p>';
        echo '</td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Extra gedrag', 'hb-ucs') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="hb_ucs_returns[add_order_note]" value="1" ' . checked(!empty($settings['add_order_note']), true, false) . ' /> ' . esc_html__('Voeg automatisch een ordernotitie toe bij een nieuwe retourmelding.', 'hb-ucs') . '</label><br/>';
        echo '<label><input type="checkbox" name="hb_ucs_returns[delete_data_on_uninstall]" value="1" ' . checked(!empty($settings['delete_data_on_uninstall']), true, false) . ' /> ' . esc_html__('Verwijder retourinstellingen en alle retourmeldingen bij uninstall.', 'hb-ucs') . '</label>';
        echo '</td>';
        echo '</tr>';

        echo '</tbody></table>';

        submit_button(__('Instellingen opslaan', 'hb-ucs'));
        echo '</form>';

        echo '</div>';
    }

    public function render_overview(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        if (!class_exists('WooCommerce')) {
            echo '<div class="wrap"><h1>' . esc_html__('Retourmeldingen', 'hb-ucs') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('WooCommerce is vereist voor deze module.', 'hb-ucs') . '</p></div></div>';
            return;
        }

        $this->module->register_post_type();

        $requestId = $this->get_requested_request_id();
        if ($requestId > 0) {
            $this->render_detail($requestId);
            return;
        }

        $statusLabels = ReturnsModule::status_labels();
        $filters = $this->get_overview_filters();
        $queryResult = $this->module->query_return_requests($filters, [
            'posts_per_page' => 20,
            'paged' => (int) $filters['paged'],
        ]);
        $requests = (array) ($queryResult['posts'] ?? []);
        $totalItems = (int) ($queryResult['total_items'] ?? 0);
        $totalPages = (int) ($queryResult['total_pages'] ?? 1);
        $currentPage = (int) ($queryResult['paged'] ?? 1);

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Retourmeldingen', 'hb-ucs') . '</h1>';

        if (!empty($_GET['status_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Retourstatus bijgewerkt.', 'hb-ucs') . '</p></div>';
        }

        echo '<p>' . esc_html__('Bekijk en verwerk hier de binnengekomen retourmeldingen.', 'hb-ucs') . '</p>';

        echo '<form method="get" style="margin:0 0 16px;display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">';
        echo '<input type="hidden" name="page" value="hb-ucs-return-requests" />';
        echo '<label for="hb_ucs_returns_search"><strong>' . esc_html__('Zoeken', 'hb-ucs') . '</strong><br />';
        echo '<input type="search" id="hb_ucs_returns_search" name="return_search" value="' . esc_attr((string) $filters['search']) . '" placeholder="' . esc_attr__('Retournummer, ordernummer, klant of e-mail', 'hb-ucs') . '" class="regular-text" /></label>';
        echo '<label for="hb_ucs_returns_status_filter"><strong>' . esc_html__('Status', 'hb-ucs') . '</strong><br />';
        echo '<select id="hb_ucs_returns_status_filter" name="return_status">';
        echo '<option value="">' . esc_html__('Alle statussen', 'hb-ucs') . '</option>';
        foreach ($statusLabels as $statusKey => $statusLabel) {
            echo '<option value="' . esc_attr($statusKey) . '" ' . selected((string) $filters['status'], $statusKey, false) . '>' . esc_html($statusLabel) . '</option>';
        }
        echo '</select></label>';
        echo '<label for="hb_ucs_returns_date_from"><strong>' . esc_html__('Van', 'hb-ucs') . '</strong><br />';
        echo '<input type="date" id="hb_ucs_returns_date_from" name="date_from" value="' . esc_attr((string) $filters['date_from']) . '" /></label>';
        echo '<label for="hb_ucs_returns_date_to"><strong>' . esc_html__('Tot en met', 'hb-ucs') . '</strong><br />';
        echo '<input type="date" id="hb_ucs_returns_date_to" name="date_to" value="' . esc_attr((string) $filters['date_to']) . '" /></label>';
        echo '<input type="hidden" name="orderby" value="' . esc_attr((string) $filters['orderby']) . '" />';
        echo '<input type="hidden" name="order" value="' . esc_attr((string) $filters['order']) . '" />';
        submit_button(__('Filter', 'hb-ucs'), 'secondary', '', false);
        $resetUrl = $this->get_overview_admin_url();
        echo '<a class="button" href="' . esc_url($resetUrl) . '">' . esc_html__('Reset', 'hb-ucs') . '</a>';
        echo '</form>';

        echo '<p class="description" style="margin:0 0 12px;">' . sprintf(esc_html(_n('%d retourmelding gevonden.', '%d retourmeldingen gevonden.', $totalItems, 'hb-ucs')), $totalItems) . '</p>';

        if (empty($requests)) {
            echo '<p>' . esc_html__('Er zijn nog geen retourmeldingen gevonden voor dit filter.', 'hb-ucs') . '</p>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . $this->render_sort_link(__('Retournummer', 'hb-ucs'), 'request_number', $filters) . '</th>';
        echo '<th>' . $this->render_sort_link(__('Ingediend op', 'hb-ucs'), 'submitted_at', $filters) . '</th>';
        echo '<th>' . $this->render_sort_link(__('Bestelling', 'hb-ucs'), 'order_number', $filters) . '</th>';
        echo '<th>' . $this->render_sort_link(__('Klant', 'hb-ucs'), 'customer', $filters) . '</th>';
        echo '<th>' . esc_html__('Artikelen', 'hb-ucs') . '</th>';
        echo '<th>' . esc_html__('Reden', 'hb-ucs') . '</th>';
        echo '<th>' . $this->render_sort_link(__('Status', 'hb-ucs'), 'status', $filters) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($requests as $requestPost) {
            $requestId = (int) $requestPost->ID;
            $orderId = (int) get_post_meta($requestId, ReturnsModule::META_ORDER_ID, true);
            $orderNumber = (string) get_post_meta($requestId, ReturnsModule::META_ORDER_NUMBER, true);
            $billingName = (string) get_post_meta($requestId, ReturnsModule::META_BILLING_NAME, true);
            $billingEmail = (string) get_post_meta($requestId, ReturnsModule::META_BILLING_EMAIL, true);
            $reason = (string) get_post_meta($requestId, ReturnsModule::META_REASON, true);
            $status = (string) get_post_meta($requestId, ReturnsModule::META_STATUS, true);
            $items = get_post_meta($requestId, ReturnsModule::META_ITEMS, true);
            $submittedAt = (string) get_post_meta($requestId, ReturnsModule::META_SUBMITTED_AT, true);
            $order = $orderId > 0 ? wc_get_order($orderId) : null;
            $orderEditUrl = ($order && method_exists($order, 'get_edit_order_url')) ? (string) $order->get_edit_order_url() : '';
            $detailUrl = $this->get_request_detail_url($requestId, $filters);

            echo '<tr>';
            echo '<td><strong><a href="' . esc_url($detailUrl) . '">' . esc_html('RET-' . str_pad((string) $requestId, 6, '0', STR_PAD_LEFT)) . '</a></strong></td>';

            echo '<td>' . esc_html($submittedAt !== '' ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($submittedAt)) : __('Onbekend', 'hb-ucs')) . '</td>';

            echo '<td>';
            if ($orderEditUrl !== '') {
                echo '<a href="' . esc_url($orderEditUrl) . '">#' . esc_html($orderNumber) . '</a>';
            } else {
                echo '#' . esc_html($orderNumber);
            }
            echo '<br/><span class="description">ID ' . esc_html((string) $orderId) . '</span>';
            echo '</td>';

            echo '<td><strong>' . esc_html($billingName) . '</strong><br/><a href="mailto:' . esc_attr($billingEmail) . '">' . esc_html($billingEmail) . '</a></td>';
            echo '<td>' . $this->module->format_request_items_list(is_array($items) ? $items : []) . '</td>';
            echo '<td>' . ($reason !== '' ? nl2br(esc_html($reason)) : '<span class="description">' . esc_html__('Geen reden opgegeven', 'hb-ucs') . '</span>') . '</td>';

            echo '<td>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="hb_ucs_returns_update_status" />';
            echo '<input type="hidden" name="request_id" value="' . esc_attr((string) $requestId) . '" />';
            foreach ($this->get_overview_state_fields($filters) as $fieldName => $fieldValue) {
                echo '<input type="hidden" name="' . esc_attr($fieldName) . '" value="' . esc_attr($fieldValue) . '" />';
            }
            wp_nonce_field('hb_ucs_returns_update_status_' . $requestId, 'hb_ucs_returns_update_status_nonce');
            echo '<select name="status">';
            foreach ($statusLabels as $statusKey => $statusLabel) {
                echo '<option value="' . esc_attr($statusKey) . '" ' . selected($status, $statusKey, false) . '>' . esc_html($statusLabel) . '</option>';
            }
            echo '</select> ';
            submit_button(__('Opslaan', 'hb-ucs'), 'secondary', '', false);
            echo '</form>';
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';

        if ($totalPages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages" style="margin:16px 0 0;">';
            echo wp_kses_post(paginate_links([
                'base' => add_query_arg('paged', '%#%', $this->get_overview_admin_url($filters, ['paged' => false])),
                'format' => '',
                'current' => $currentPage,
                'total' => $totalPages,
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'type' => 'plain',
            ]));
            echo '</div></div>';
        }

        echo '</div>';
    }

    private function render_detail(int $requestId): void {
        $requestPost = get_post($requestId);
        if (!$requestPost instanceof \WP_Post || !in_array((string) $requestPost->post_type, [ReturnsModule::POST_TYPE, ReturnsModule::LEGACY_POST_TYPE], true)) {
            echo '<div class="wrap"><h1>' . esc_html__('Retourmelding niet gevonden', 'hb-ucs') . '</h1>';
            echo '<div class="notice notice-error"><p>' . esc_html__('De gevraagde retourmelding bestaat niet of is niet meer beschikbaar.', 'hb-ucs') . '</p></div></div>';
            return;
        }

        $filters = $this->get_overview_filters();
        $request = $this->module->get_request_context($requestId);
        $statusLabels = ReturnsModule::status_labels();
        $order = !empty($request['order_id']) ? wc_get_order((int) $request['order_id']) : null;
        $backUrl = $this->get_overview_admin_url($filters);
        $orderEditUrl = ($order && method_exists($order, 'get_edit_order_url')) ? (string) $order->get_edit_order_url() : '';
        $orderStatus = ($order && method_exists($order, 'get_status')) ? wc_get_order_status_name((string) $order->get_status()) : '';

        echo '<div class="wrap woocommerce">';
        echo '<a href="' . esc_url($backUrl) . '" class="page-title-action">' . esc_html__('Terug naar overzicht', 'hb-ucs') . '</a>';
        echo '<h1 class="wp-heading-inline">' . esc_html(sprintf(__('Retourmelding %s', 'hb-ucs'), (string) $request['request_number'])) . '</h1>';
        if (!empty($_GET['status_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Retourstatus bijgewerkt.', 'hb-ucs') . '</p></div>';
        }
        $labelNotice = isset($_GET['label_notice']) ? sanitize_key((string) $_GET['label_notice']) : '';
        if ($labelNotice === 'sent') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Retourlabel met bericht is naar de klant verstuurd.', 'hb-ucs') . '</p></div>';
        } elseif ($labelNotice !== '') {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($this->get_label_notice_message($labelNotice)) . '</p></div>';
        }
        echo '<hr class="wp-header-end" />';

        echo '<div id="poststuff">';
        echo '<div id="post-body" class="metabox-holder columns-2">';
        echo '<div id="post-body-content">';

        $this->render_detail_box(__('Artikelen', 'hb-ucs'), $this->render_detail_items_table((array) $request['items'], $order));
        $reasonHtml = (string) $request['reason'] !== ''
            ? '<p>' . nl2br(esc_html((string) $request['reason'])) . '</p>'
            : '<p class="description">' . esc_html__('Er is geen retourreden opgegeven.', 'hb-ucs') . '</p>';
        $this->render_detail_box(__('Retourreden', 'hb-ucs'), $reasonHtml);

        echo '</div>';
        echo '<div id="postbox-container-1" class="postbox-container">';

        $statusForm = '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        $statusForm .= '<input type="hidden" name="action" value="hb_ucs_returns_update_status" />';
        $statusForm .= '<input type="hidden" name="request_id" value="' . esc_attr((string) $requestId) . '" />';
        $statusForm .= '<input type="hidden" name="return_view" value="detail" />';
        foreach ($this->get_overview_state_fields($filters) as $fieldName => $fieldValue) {
            $statusForm .= '<input type="hidden" name="' . esc_attr($fieldName) . '" value="' . esc_attr($fieldValue) . '" />';
        }
        ob_start();
        wp_nonce_field('hb_ucs_returns_update_status_' . $requestId, 'hb_ucs_returns_update_status_nonce');
        $statusForm .= (string) ob_get_clean();
        $statusForm .= '<p><strong>' . esc_html__('Retourstatus', 'hb-ucs') . '</strong></p>';
        $statusForm .= '<p><select name="status" style="width:100%;">';
        foreach ($statusLabels as $statusKey => $statusLabel) {
            $statusForm .= '<option value="' . esc_attr($statusKey) . '" ' . selected((string) $request['status'], $statusKey, false) . '>' . esc_html($statusLabel) . '</option>';
        }
        $statusForm .= '</select></p>';
        $statusForm .= '<p>';
        ob_start();
        submit_button(__('Status opslaan', 'hb-ucs'), 'primary', '', false);
        $statusForm .= (string) ob_get_clean();
        $statusForm .= '</p></form>';
        $this->render_detail_box(__('Status', 'hb-ucs'), $statusForm);

        $labelHtml = $this->render_label_mail_form($requestId, $request, $filters, $order);
        $this->render_detail_box(__('Retourlabel mailen', 'hb-ucs'), $labelHtml);

        $customerHtml = '<p><strong>' . esc_html((string) $request['billing_name']) . '</strong><br />';
        if (!empty($request['billing_email'])) {
            $customerHtml .= '<a href="mailto:' . esc_attr((string) $request['billing_email']) . '">' . esc_html((string) $request['billing_email']) . '</a><br />';
        }
        if ($order && method_exists($order, 'get_billing_phone') && (string) $order->get_billing_phone() !== '') {
            $customerHtml .= esc_html((string) $order->get_billing_phone()) . '<br />';
        }
        if (!empty($request['billing_postcode'])) {
            $customerHtml .= esc_html__('Postcode:', 'hb-ucs') . ' ' . esc_html((string) $request['billing_postcode']) . '<br />';
        }
        $customerHtml .= '</p>';
        if ($order && method_exists($order, 'get_formatted_billing_address')) {
            $billingAddress = (string) $order->get_formatted_billing_address();
            if ($billingAddress !== '') {
                $customerHtml .= '<p><strong>' . esc_html__('Factuuradres', 'hb-ucs') . '</strong><br />' . wp_kses_post(wpautop($billingAddress)) . '</p>';
            }
        }
        if ($order && method_exists($order, 'get_formatted_shipping_address')) {
            $shippingAddress = (string) $order->get_formatted_shipping_address();
            if ($shippingAddress !== '') {
                $customerHtml .= '<p><strong>' . esc_html__('Verzendadres', 'hb-ucs') . '</strong><br />' . wp_kses_post(wpautop($shippingAddress)) . '</p>';
            }
        }
        $this->render_detail_box(__('Klantgegevens', 'hb-ucs'), $customerHtml);

        $orderHtml = '<p><strong>' . esc_html__('Retournummer', 'hb-ucs') . '</strong><br />' . esc_html((string) $request['request_number']) . '</p>';
        $orderHtml .= '<p><strong>' . esc_html__('Ingediend op', 'hb-ucs') . '</strong><br />' . esc_html((string) ($request['submitted_at_display'] ?: __('Onbekend', 'hb-ucs'))) . '</p>';
        if ($order) {
            $orderHtml .= '<p><strong>' . esc_html__('Bestelling', 'hb-ucs') . '</strong><br />';
            if ($orderEditUrl !== '') {
                $orderHtml .= '<a href="' . esc_url($orderEditUrl) . '">#' . esc_html((string) $request['order_number']) . '</a>';
            } else {
                $orderHtml .= '#' . esc_html((string) $request['order_number']);
            }
            $orderHtml .= '</p>';
            if ($orderStatus !== '') {
                $orderHtml .= '<p><strong>' . esc_html__('Orderstatus', 'hb-ucs') . '</strong><br />' . esc_html($orderStatus) . '</p>';
            }
            if (method_exists($order, 'get_date_created') && $order->get_date_created()) {
                $orderHtml .= '<p><strong>' . esc_html__('Geplaatst op', 'hb-ucs') . '</strong><br />' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $order->get_date_created()->getTimestamp())) . '</p>';
            }
            if (method_exists($order, 'get_date_completed') && $order->get_date_completed()) {
                $orderHtml .= '<p><strong>' . esc_html__('Afgerond op', 'hb-ucs') . '</strong><br />' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), $order->get_date_completed()->getTimestamp())) . '</p>';
            }
            if (method_exists($order, 'get_formatted_order_total')) {
                $orderHtml .= '<p><strong>' . esc_html__('Ordertotaal', 'hb-ucs') . '</strong><br />' . wp_kses_post((string) $order->get_formatted_order_total()) . '</p>';
            }
        }
        if (!empty($request['estimated_delivery_display'])) {
            $orderHtml .= '<p><strong>' . esc_html__('Verwachte afleverdatum', 'hb-ucs') . '</strong><br />' . esc_html((string) $request['estimated_delivery_display']) . '</p>';
        }
        if (!empty($request['return_deadline_display'])) {
            $orderHtml .= '<p><strong>' . esc_html__('Retourdeadline', 'hb-ucs') . '</strong><br />' . esc_html((string) $request['return_deadline_display']) . '</p>';
        }
        $this->render_detail_box(__('Bestellingsgegevens', 'hb-ucs'), $orderHtml);

        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public function handle_save_settings(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        check_admin_referer('hb_ucs_returns_save_settings', 'hb_ucs_returns_save_settings_nonce');

        $raw = isset($_POST['hb_ucs_returns']) ? (array) wp_unslash($_POST['hb_ucs_returns']) : [];
        $clean = $this->module->sanitize_settings($raw);
        update_option(ReturnsModule::OPT_SETTINGS, $clean, false);

        $redirect = add_query_arg(['page' => 'hb-ucs-returns', 'updated' => 'true'], admin_url('admin.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_update_status(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        $requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
        check_admin_referer('hb_ucs_returns_update_status_' . $requestId, 'hb_ucs_returns_update_status_nonce');

        $status = isset($_POST['status']) ? sanitize_key((string) wp_unslash($_POST['status'])) : '';
        $this->module->update_return_request_status($requestId, $status);

        if (isset($_POST['return_view']) && (string) wp_unslash($_POST['return_view']) === 'detail') {
            $redirect = add_query_arg([
                'page' => 'hb-ucs-return-requests',
                'request_id' => $requestId,
                'status_updated' => 'true',
            ], admin_url('admin.php'));
        } else {
            $redirectArgs = array_merge($this->get_overview_filters($_POST), ['page' => 'hb-ucs-return-requests', 'status_updated' => 'true']);
            $redirect = add_query_arg($redirectArgs, admin_url('admin.php'));
        }
        wp_safe_redirect($redirect);
        exit;
    }

    public function handle_send_label(): void {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Onvoldoende rechten.', 'hb-ucs'));
        }

        $requestId = isset($_POST['request_id']) ? (int) $_POST['request_id'] : 0;
        check_admin_referer('hb_ucs_returns_send_label_' . $requestId, 'hb_ucs_returns_send_label_nonce');

        $filters = $this->get_overview_filters($_POST);
        $redirectBase = [
            'page' => 'hb-ucs-return-requests',
            'request_id' => $requestId,
        ];

        $request = $this->module->get_request_context($requestId);
        if ($requestId <= 0 || empty($request['request_number'])) {
            wp_safe_redirect(add_query_arg(array_merge($redirectBase, ['label_notice' => 'not_found']), admin_url('admin.php')));
            exit;
        }

        if (empty($request['billing_email']) || !is_email((string) $request['billing_email'])) {
            wp_safe_redirect(add_query_arg(array_merge($redirectBase, ['label_notice' => 'missing_email']), admin_url('admin.php')));
            exit;
        }

        $message = isset($_POST['label_message']) ? sanitize_textarea_field((string) wp_unslash($_POST['label_message'])) : '';
        if (trim($message) === '') {
            $message = $this->get_default_label_message($request);
        }

        $file = $_FILES['return_label_attachment'] ?? null;
        if (!is_array($file) || !isset($file['error']) || (int) $file['error'] === UPLOAD_ERR_NO_FILE) {
            wp_safe_redirect(add_query_arg(array_merge($redirectBase, $this->get_overview_redirect_args($filters), ['label_notice' => 'missing_file']), admin_url('admin.php')));
            exit;
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            wp_safe_redirect(add_query_arg(array_merge($redirectBase, $this->get_overview_redirect_args($filters), ['label_notice' => 'upload_error']), admin_url('admin.php')));
            exit;
        }

        if (!function_exists('wp_handle_upload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $upload = wp_handle_upload($file, [
            'test_form' => false,
            'mimes' => [
                'pdf' => 'application/pdf',
                'jpg|jpeg' => 'image/jpeg',
                'png' => 'image/png',
            ],
        ]);

        if (!is_array($upload) || !empty($upload['error']) || empty($upload['file'])) {
            wp_safe_redirect(add_query_arg(array_merge($redirectBase, $this->get_overview_redirect_args($filters), ['label_notice' => 'upload_error']), admin_url('admin.php')));
            exit;
        }

        $attachmentPath = (string) $upload['file'];
        $sent = $this->module->send_return_label_email($requestId, $message, [$attachmentPath]);

        if (is_file($attachmentPath)) {
            @unlink($attachmentPath);
        }

        if ($sent) {
            $order = !empty($request['order_id']) ? wc_get_order((int) $request['order_id']) : null;
            if ($order && method_exists($order, 'add_order_note')) {
                $order->add_order_note(
                    sprintf(__('Retourlabel per e-mail verstuurd naar %1$s voor retourmelding %2$s.', 'hb-ucs'), (string) $request['billing_email'], (string) $request['request_number']),
                    false,
                    true
                );
            }

            wp_safe_redirect(add_query_arg(array_merge($redirectBase, $this->get_overview_redirect_args($filters), ['label_notice' => 'sent']), admin_url('admin.php')));
            exit;
        }

        wp_safe_redirect(add_query_arg(array_merge($redirectBase, $this->get_overview_redirect_args($filters), ['label_notice' => 'send_failed']), admin_url('admin.php')));
        exit;
    }

    private function get_requested_request_id(): int {
        return isset($_GET['request_id']) ? max(0, (int) $_GET['request_id']) : 0;
    }

    private function get_request_detail_url(int $requestId, array $filters = []): string {
        return $this->get_overview_admin_url($filters, [
            'request_id' => $requestId,
        ]);
    }

    private function render_label_mail_form(int $requestId, array $request, array $filters, $order): string {
        if (empty($request['billing_email']) || !is_email((string) $request['billing_email'])) {
            return '<p class="description">' . esc_html__('Er is geen geldig klantmailadres beschikbaar om een retourlabel naartoe te sturen.', 'hb-ucs') . '</p>';
        }

        $subject = sprintf(__('Retourlabel voor bestelling %s', 'hb-ucs'), (string) $request['order_number']);
        $defaultMessage = $this->get_default_label_message($request);

        $html = '<p class="description">' . esc_html__('Upload hier een extern aangemaakt retourlabel en stuur direct een begeleidend bericht naar de klant.', 'hb-ucs') . '</p>';
        $html .= '<p><strong>' . esc_html__('Ontvanger', 'hb-ucs') . '</strong><br />' . esc_html((string) $request['billing_email']) . '</p>';
        $html .= '<p><strong>' . esc_html__('Onderwerp', 'hb-ucs') . '</strong><br />' . esc_html($subject) . '</p>';
        $html .= '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" enctype="multipart/form-data">';
        $html .= '<input type="hidden" name="action" value="hb_ucs_returns_send_label" />';
        $html .= '<input type="hidden" name="request_id" value="' . esc_attr((string) $requestId) . '" />';
        foreach ($this->get_overview_state_fields($filters) as $fieldName => $fieldValue) {
            $html .= '<input type="hidden" name="' . esc_attr($fieldName) . '" value="' . esc_attr($fieldValue) . '" />';
        }
        ob_start();
        wp_nonce_field('hb_ucs_returns_send_label_' . $requestId, 'hb_ucs_returns_send_label_nonce');
        $html .= (string) ob_get_clean();
        $html .= '<p><label for="hb_ucs_returns_label_message_' . esc_attr((string) $requestId) . '"><strong>' . esc_html__('Bericht aan klant', 'hb-ucs') . '</strong></label><br />';
        $html .= '<textarea id="hb_ucs_returns_label_message_' . esc_attr((string) $requestId) . '" name="label_message" rows="8" style="width:100%;">' . esc_textarea($defaultMessage) . '</textarea></p>';
        $html .= '<p><label for="hb_ucs_returns_label_attachment_' . esc_attr((string) $requestId) . '"><strong>' . esc_html__('Retourlabel bijlage', 'hb-ucs') . '</strong></label><br />';
        $html .= '<input id="hb_ucs_returns_label_attachment_' . esc_attr((string) $requestId) . '" type="file" name="return_label_attachment" accept=".pdf,.jpg,.jpeg,.png" />';
        $html .= '<br /><span class="description">' . esc_html__('Toegestaan: PDF, JPG en PNG.', 'hb-ucs') . '</span></p>';
        ob_start();
        submit_button(__('Retourlabel versturen', 'hb-ucs'), 'secondary', '', false);
        $html .= '<p>' . (string) ob_get_clean() . '</p>';
        $html .= '</form>';

        return $html;
    }

    private function get_default_label_message(array $request): string {
        $name = trim((string) ($request['billing_name'] ?? ''));
        $greetingName = $name !== '' ? $name : __('klant', 'hb-ucs');

        return sprintf(
            "Beste %s,\n\nIn de bijlage vind je het retourlabel voor retourmelding %s van bestelling %s.\n\nPrint het label uit, bevestig het duidelijk op het pakket en lever je retour vervolgens in bij het verzendpunt. Zodra wij je retourzending hebben ontvangen en verwerkt, nemen wij contact met je op.\n\nMet vriendelijke groet,\n%s",
            $greetingName,
            (string) ($request['request_number'] ?? ''),
            (string) ($request['order_number'] ?? ''),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );
    }

    private function get_label_notice_message(string $notice): string {
        switch ($notice) {
            case 'missing_file':
                return __('Upload eerst een retourlabel voordat je het bericht verstuurt.', 'hb-ucs');
            case 'missing_email':
                return __('Er is geen geldig klantmailadres beschikbaar voor deze retourmelding.', 'hb-ucs');
            case 'upload_error':
                return __('Het retourlabel kon niet worden geüpload. Controleer het bestand en probeer het opnieuw.', 'hb-ucs');
            case 'send_failed':
                return __('Het bericht met retourlabel kon niet worden verstuurd.', 'hb-ucs');
            case 'not_found':
                return __('De gekozen retourmelding kon niet worden teruggevonden.', 'hb-ucs');
            default:
                return __('Er is een onbekende fout opgetreden tijdens het versturen van het retourlabel.', 'hb-ucs');
        }
    }

    private function get_overview_redirect_args(array $filters): array {
        $args = [];
        foreach (['status' => 'return_status', 'search' => 'return_search', 'date_from' => 'date_from', 'date_to' => 'date_to', 'orderby' => 'orderby', 'order' => 'order', 'paged' => 'paged'] as $filterKey => $queryKey) {
            $value = $filters[$filterKey] ?? '';
            if ($value === '' || $value === null || ($filterKey === 'paged' && (int) $value <= 1)) {
                continue;
            }
            $args[$queryKey] = $value;
        }

        return $args;
    }

    private function get_overview_filters(?array $source = null): array {
        $source = is_array($source) ? $source : $_GET;

        $orderby = sanitize_key((string) ($source['orderby'] ?? 'submitted_at'));
        if (!in_array($orderby, ['request_number', 'submitted_at', 'order_number', 'customer', 'status'], true)) {
            $orderby = 'submitted_at';
        }

        $order = strtoupper((string) ($source['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        $dateFrom = $this->normalize_overview_date((string) ($source['date_from'] ?? ''));
        $dateTo = $this->normalize_overview_date((string) ($source['date_to'] ?? ''));

        return [
            'status' => sanitize_key((string) ($source['return_status'] ?? '')),
            'search' => sanitize_text_field((string) ($source['return_search'] ?? '')),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'orderby' => $orderby,
            'order' => $order,
            'paged' => max(1, (int) ($source['paged'] ?? 1)),
        ];
    }

    private function normalize_overview_date(string $date): string {
        $date = trim($date);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private function render_sort_link(string $label, string $orderby, array $filters): string {
        $nextOrder = 'ASC';
        $suffix = '';

        if ((string) $filters['orderby'] === $orderby) {
            if ((string) $filters['order'] === 'ASC') {
                $nextOrder = 'DESC';
                $suffix = ' ↑';
            } else {
                $nextOrder = 'ASC';
                $suffix = ' ↓';
            }
        }

        $url = $this->get_overview_admin_url($filters, [
            'orderby' => $orderby,
            'order' => $nextOrder,
            'paged' => 1,
        ]);

        return '<a href="' . esc_url($url) . '"><span>' . esc_html($label . $suffix) . '</span></a>';
    }

    private function get_overview_admin_url(array $filters = [], array $overrides = []): string {
        $args = [
            'page' => 'hb-ucs-return-requests',
        ];

        $state = array_merge($filters, $overrides);
        $map = [
            'status' => 'return_status',
            'search' => 'return_search',
            'date_from' => 'date_from',
            'date_to' => 'date_to',
            'orderby' => 'orderby',
            'order' => 'order',
            'paged' => 'paged',
            'request_id' => 'request_id',
        ];

        foreach ($map as $stateKey => $queryKey) {
            if (!array_key_exists($stateKey, $state)) {
                continue;
            }

            $value = $state[$stateKey];
            if ($value === false || $value === '' || $value === null || ($stateKey === 'paged' && (int) $value <= 1)) {
                continue;
            }

            $args[$queryKey] = $value;
        }

        return add_query_arg($args, admin_url('admin.php'));
    }

    private function get_overview_state_fields(array $filters): array {
        $fields = [];
        $map = [
            'status' => 'return_status',
            'search' => 'return_search',
            'date_from' => 'date_from',
            'date_to' => 'date_to',
            'orderby' => 'orderby',
            'order' => 'order',
            'paged' => 'paged',
        ];

        foreach ($map as $filterKey => $fieldName) {
            $value = $filters[$filterKey] ?? '';
            if ($value === '' || $value === null || ($filterKey === 'paged' && (int) $value <= 1)) {
                continue;
            }
            $fields[$fieldName] = (string) $value;
        }

        return $fields;
    }

    private function render_detail_box(string $title, string $content): void {
        echo '<div class="postbox">';
        echo '<div class="postbox-header"><h2 class="hndle">' . esc_html($title) . '</h2></div>';
        echo '<div class="inside">' . $content . '</div>';
        echo '</div>';
    }

    private function render_detail_items_table(array $items, $order): string {
        if (empty($items)) {
            return '<p>' . esc_html__('Geen artikelen opgeslagen.', 'hb-ucs') . '</p>';
        }

        $orderItems = [];
        if ($order && is_object($order) && method_exists($order, 'get_items')) {
            foreach ((array) $order->get_items('line_item') as $orderItemId => $orderItem) {
                $orderItems[(int) $orderItemId] = $orderItem;
            }
        }

        $html = '<table class="widefat striped wc-order-list-table"><thead><tr>';
        $html .= '<th>' . esc_html__('Product', 'hb-ucs') . '</th>';
        $html .= '<th>' . esc_html__('SKU', 'hb-ucs') . '</th>';
        $html .= '<th>' . esc_html__('Besteld', 'hb-ucs') . '</th>';
        $html .= '<th>' . esc_html__('Retour', 'hb-ucs') . '</th>';
        $html .= '<th>' . esc_html__('Totaal', 'hb-ucs') . '</th>';
        $html .= '</tr></thead><tbody>';

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $itemId = (int) ($item['item_id'] ?? 0);
            $orderItem = $orderItems[$itemId] ?? null;
            $product = $orderItem && method_exists($orderItem, 'get_product') ? $orderItem->get_product() : null;
            $sku = $product && method_exists($product, 'get_sku') ? (string) $product->get_sku() : '';
            $orderedQty = $orderItem && method_exists($orderItem, 'get_quantity') ? (int) $orderItem->get_quantity() : 0;
            $lineTotal = ($order && $orderItem && method_exists($order, 'get_formatted_line_subtotal')) ? (string) $order->get_formatted_line_subtotal($orderItem) : '—';
            $name = (string) ($item['name'] ?? '');
            $meta = trim((string) ($item['meta_text'] ?? $item['meta'] ?? ''));

            $html .= '<tr>';
            $html .= '<td><strong>' . esc_html($name) . '</strong>';
            if ($meta !== '') {
                $html .= '<br /><span class="description">' . esc_html($meta) . '</span>';
            }
            $html .= '</td>';
            $html .= '<td>' . ($sku !== '' ? esc_html($sku) : '—') . '</td>';
            $html .= '<td>' . esc_html($orderedQty > 0 ? (string) $orderedQty : '—') . '</td>';
            $html .= '<td>' . esc_html((string) ((int) ($item['quantity'] ?? 0))) . '</td>';
            $html .= '<td>' . (is_string($lineTotal) ? wp_kses_post($lineTotal) : '—') . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    private function get_style_field_map(): array {
        return [
            'style_accent_color' => __('Accent', 'hb-ucs'),
            'style_accent_text_color' => __('Accent tekst', 'hb-ucs'),
            'style_background_color' => __('Achtergrond', 'hb-ucs'),
            'style_panel_color' => __('Panelen', 'hb-ucs'),
            'style_border_color' => __('Randen', 'hb-ucs'),
            'style_text_color' => __('Tekst', 'hb-ucs'),
            'style_muted_text_color' => __('Secundaire tekst', 'hb-ucs'),
            'style_success_color' => __('Succesvlak', 'hb-ucs'),
            'style_error_color' => __('Foutvlak', 'hb-ucs'),
            'style_button_primary_hover_color' => __('Primary knop hover', 'hb-ucs'),
            'style_button_primary_hover_text_color' => __('Primary knop hover tekst', 'hb-ucs'),
            'style_button_primary_hover_border_color' => __('Primary knop hover rand', 'hb-ucs'),
            'style_button_secondary_hover_color' => __('Secondary knop hover', 'hb-ucs'),
            'style_button_secondary_hover_text_color' => __('Secondary knop hover tekst', 'hb-ucs'),
            'style_button_secondary_hover_border_color' => __('Secondary knop hover rand', 'hb-ucs'),
            'style_radius' => __('Radius', 'hb-ucs'),
        ];
    }

    private function get_widget_text_field_map(): array {
        return [
            'widget_eyebrow' => [
                'label' => __('Widget: eyebrow', 'hb-ucs'),
                'description' => __('Kleine bovenregel boven de widgettitel.', 'hb-ucs'),
            ],
            'widget_title' => [
                'label' => __('Widget: titel', 'hb-ucs'),
            ],
            'widget_intro' => [
                'label' => __('Widget: introtekst', 'hb-ucs'),
                'type' => 'textarea',
                'rows' => 3,
            ],
            'widget_help_text' => [
                'label' => __('Widget: helptekst', 'hb-ucs'),
                'type' => 'textarea',
                'rows' => 3,
                'description' => __('Ondersteunt {days} en {offset} als placeholders.', 'hb-ucs'),
            ],
            'widget_step_lookup' => [
                'label' => __('Widget: stap 1', 'hb-ucs'),
            ],
            'widget_step_items' => [
                'label' => __('Widget: stap 2', 'hb-ucs'),
            ],
            'widget_step_success' => [
                'label' => __('Widget: stap 3', 'hb-ucs'),
            ],
            'widget_order_number_label' => [
                'label' => __('Widget: label ordernummer', 'hb-ucs'),
            ],
            'widget_postcode_label' => [
                'label' => __('Widget: label postcode', 'hb-ucs'),
            ],
            'widget_lookup_button_text' => [
                'label' => __('Widget: knop bestelling zoeken', 'hb-ucs'),
            ],
            'widget_reason_label' => [
                'label' => __('Widget: label retourreden', 'hb-ucs'),
            ],
            'widget_reason_placeholder' => [
                'label' => __('Widget: placeholder retourreden', 'hb-ucs'),
            ],
            'widget_back_button_text' => [
                'label' => __('Widget: knop vorige stap', 'hb-ucs'),
            ],
            'widget_submit_button_text' => [
                'label' => __('Widget: knop bevestigen', 'hb-ucs'),
            ],
            'widget_success_heading' => [
                'label' => __('Widget: succeskop', 'hb-ucs'),
            ],
            'widget_success_text' => [
                'label' => __('Widget: succesbericht', 'hb-ucs'),
                'type' => 'textarea',
                'rows' => 3,
            ],
            'widget_message_loading' => [
                'label' => __('Widget: melding laden', 'hb-ucs'),
            ],
            'widget_message_submit_loading' => [
                'label' => __('Widget: melding opslaan', 'hb-ucs'),
            ],
            'widget_message_lookup_error' => [
                'label' => __('Widget: foutmelding bestelling', 'hb-ucs'),
                'type' => 'textarea',
                'rows' => 2,
            ],
            'widget_message_submit_error' => [
                'label' => __('Widget: foutmelding bevestigen', 'hb-ucs'),
                'type' => 'textarea',
                'rows' => 2,
            ],
            'widget_message_items_required' => [
                'label' => __('Widget: melding artikelkeuze verplicht', 'hb-ucs'),
            ],
            'widget_label_order_summary' => [
                'label' => __('Widget: label bestellingsoverzicht', 'hb-ucs'),
            ],
            'widget_label_placed_on' => [
                'label' => __('Widget: label geplaatst op', 'hb-ucs'),
            ],
            'widget_label_completed_on' => [
                'label' => __('Widget: label afgerond op', 'hb-ucs'),
            ],
            'widget_label_delivery' => [
                'label' => __('Widget: label afleverdatum', 'hb-ucs'),
            ],
            'widget_label_deadline' => [
                'label' => __('Widget: label retourdeadline', 'hb-ucs'),
            ],
            'widget_label_available_quantity' => [
                'label' => __('Widget: label beschikbaar aantal', 'hb-ucs'),
            ],
            'widget_label_quantity' => [
                'label' => __('Widget: label aantal', 'hb-ucs'),
            ],
            'widget_label_request_number' => [
                'label' => __('Widget: label retournummer', 'hb-ucs'),
            ],
            'widget_label_customer_mail' => [
                'label' => __('Widget: label klantmail', 'hb-ucs'),
            ],
        ];
    }
}