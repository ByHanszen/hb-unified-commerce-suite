<?php
namespace HB\UCS\Modules\Returns;

if (!defined('ABSPATH')) {
    exit;
}

class ReturnsModule {
    public const OPT_SETTINGS = 'hb_ucs_returns_settings';
    public const SHORTCODE = 'hb_ucs_returns_form';
    public const POST_TYPE = 'hb_ucs_return_req';
    public const LEGACY_POST_TYPE = 'hb_ucs_return_request';
    public const AJAX_LOOKUP = 'hb_ucs_returns_lookup';
    public const AJAX_SUBMIT = 'hb_ucs_returns_submit';
    public const NONCE_ACTION = 'hb_ucs_returns_public';

    public const META_STATUS = '_hb_ucs_return_status';
    public const META_ORDER_ID = '_hb_ucs_return_order_id';
    public const META_ORDER_NUMBER = '_hb_ucs_return_order_number';
    public const META_ITEMS = '_hb_ucs_return_items';
    public const META_REASON = '_hb_ucs_return_reason';
    public const META_BILLING_EMAIL = '_hb_ucs_return_billing_email';
    public const META_BILLING_NAME = '_hb_ucs_return_billing_name';
    public const META_BILLING_POSTCODE = '_hb_ucs_return_billing_postcode';
    public const META_SUBMITTED_AT = '_hb_ucs_return_submitted_at';
    public const META_ESTIMATED_DELIVERY = '_hb_ucs_return_estimated_delivery';
    public const META_RETURN_DEADLINE = '_hb_ucs_return_deadline';

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('init', [$this, 'register_post_type']);
        add_action('wp_enqueue_scripts', [$this, 'register_frontend_assets']);
        add_shortcode(self::SHORTCODE, [$this, 'render_shortcode']);
        add_action('wp_ajax_' . self::AJAX_LOOKUP, [$this, 'handle_lookup_ajax']);
        add_action('wp_ajax_nopriv_' . self::AJAX_LOOKUP, [$this, 'handle_lookup_ajax']);
        add_action('wp_ajax_' . self::AJAX_SUBMIT, [$this, 'handle_submit_ajax']);
        add_action('wp_ajax_nopriv_' . self::AJAX_SUBMIT, [$this, 'handle_submit_ajax']);
        add_action('elementor/widgets/register', [$this, 'register_elementor_widget']);
    }

    public function register_post_type(): void {
        if (post_type_exists(self::POST_TYPE)) {
            return;
        }

        register_post_type(self::POST_TYPE, [
            'labels' => [
                'name' => __('Retourmeldingen', 'hb-ucs'),
                'singular_name' => __('Retourmelding', 'hb-ucs'),
            ],
            'public' => false,
            'show_ui' => false,
            'show_in_menu' => false,
            'supports' => ['title'],
            'capability_type' => 'post',
            'map_meta_cap' => true,
            'exclude_from_search' => true,
            'publicly_queryable' => false,
        ]);
    }

    protected static function get_request_post_types(): array {
        return [self::POST_TYPE, self::LEGACY_POST_TYPE];
    }

    public static function default_settings(): array {
        return array_merge([
            'return_window_days' => 14,
            'delivery_offset_days' => 2,
            'excluded_product_ids' => [],
            'excluded_category_ids' => [],
            'customer_email_intro' => __('Bedankt voor je retourmelding. Hieronder vind je een overzicht van je aanvraag. Bewaar deze e-mail goed. We nemen contact op zodra we je retour verder verwerken.', 'hb-ucs'),
            'admin_email' => '',
            'style_accent_color' => '#245c4f',
            'style_accent_text_color' => '#ffffff',
            'style_background_color' => '#f6f1e8',
            'style_panel_color' => '#ffffff',
            'style_border_color' => '#d9cfbf',
            'style_text_color' => '#2a251f',
            'style_muted_text_color' => '#6b6459',
            'style_success_color' => '#e6f2ea',
            'style_error_color' => '#f6e5e1',
            'style_button_primary_hover_color' => '#1d4b41',
            'style_button_primary_hover_text_color' => '#ffffff',
            'style_button_primary_hover_border_color' => '#1d4b41',
            'style_button_secondary_hover_color' => '#edf3f1',
            'style_button_secondary_hover_text_color' => '#245c4f',
            'style_button_secondary_hover_border_color' => '#245c4f',
            'style_radius' => 18,
            'delete_data_on_uninstall' => 0,
            'add_order_note' => 1,
        ], self::get_widget_text_defaults());
    }

    private static function get_widget_text_defaults(): array {
        return [
            'widget_eyebrow' => __('Herroepingsrecht', 'hb-ucs'),
            'widget_title' => __('Retour aanmelden', 'hb-ucs'),
            'widget_intro' => __('Vul je ordernummer en factuurpostcode in. Daarna tonen we alleen de artikelen die nog voor retour in aanmerking komen.', 'hb-ucs'),
            'widget_help_text' => __('Retouren kunnen standaard tot {days} dagen na de geschatte afleverdatum worden aangemeld. De geschatte afleverdatum is de completed-datum plus {offset} dag(en).', 'hb-ucs'),
            'widget_step_lookup' => __('1. Bestelling zoeken', 'hb-ucs'),
            'widget_step_items' => __('2. Artikelen kiezen', 'hb-ucs'),
            'widget_step_success' => __('3. Bevestiging', 'hb-ucs'),
            'widget_order_number_label' => __('Ordernummer', 'hb-ucs'),
            'widget_postcode_label' => __('Factuurpostcode', 'hb-ucs'),
            'widget_lookup_button_text' => __('Zoek bestelling', 'hb-ucs'),
            'widget_reason_label' => __('Retourreden (optioneel)', 'hb-ucs'),
            'widget_reason_placeholder' => __('Licht hier eventueel je retour toe.', 'hb-ucs'),
            'widget_back_button_text' => __('Vorige stap', 'hb-ucs'),
            'widget_submit_button_text' => __('Retourmelding bevestigen', 'hb-ucs'),
            'widget_success_heading' => __('Retourmelding ontvangen', 'hb-ucs'),
            'widget_success_text' => __('We hebben je retourmelding opgeslagen en direct een bevestiging gestuurd naar het e-mailadres van de bestelling.', 'hb-ucs'),
            'widget_message_loading' => __('We zoeken je bestelling op…', 'hb-ucs'),
            'widget_message_submit_loading' => __('Je retourmelding wordt opgeslagen…', 'hb-ucs'),
            'widget_message_lookup_error' => __('We konden de bestelling niet ophalen.', 'hb-ucs'),
            'widget_message_submit_error' => __('De retourmelding kon niet worden opgeslagen.', 'hb-ucs'),
            'widget_message_items_required' => __('Kies minimaal één artikel en aantal om door te gaan.', 'hb-ucs'),
            'widget_label_order_summary' => __('Bestelling', 'hb-ucs'),
            'widget_label_placed_on' => __('Geplaatst op', 'hb-ucs'),
            'widget_label_completed_on' => __('Afgerond op', 'hb-ucs'),
            'widget_label_delivery' => __('Verwachte afleverdatum', 'hb-ucs'),
            'widget_label_deadline' => __('Retour mogelijk tot', 'hb-ucs'),
            'widget_label_available_quantity' => __('Nog retour mogelijk', 'hb-ucs'),
            'widget_label_quantity' => __('Aantal', 'hb-ucs'),
            'widget_label_request_number' => __('Retournummer', 'hb-ucs'),
            'widget_label_customer_mail' => __('Bevestiging verzonden naar', 'hb-ucs'),
        ];
    }

    public static function get_settings(): array {
        $saved = get_option(self::OPT_SETTINGS, []);
        $saved = is_array($saved) ? $saved : [];

        return array_replace_recursive(self::default_settings(), $saved);
    }

    public static function status_labels(): array {
        return [
            'submitted' => __('Nieuw', 'hb-ucs'),
            'received' => __('Ontvangen', 'hb-ucs'),
            'approved' => __('Goedgekeurd', 'hb-ucs'),
            'rejected' => __('Afgewezen', 'hb-ucs'),
            'completed' => __('Afgerond', 'hb-ucs'),
        ];
    }

    public static function get_status_labels(): array {
        return self::status_labels();
    }

    public function sanitize_settings(array $raw): array {
        $defaults = self::default_settings();
        $clean = $defaults;

        $clean['return_window_days'] = isset($raw['return_window_days']) ? max(1, min(60, (int) $raw['return_window_days'])) : (int) $defaults['return_window_days'];
        $clean['delivery_offset_days'] = isset($raw['delivery_offset_days']) ? max(0, min(30, (int) $raw['delivery_offset_days'])) : (int) $defaults['delivery_offset_days'];

        $productIds = $raw['excluded_product_ids'] ?? [];
        if (is_string($productIds)) {
            $productIds = preg_split('/\s*,\s*/', trim($productIds), -1, PREG_SPLIT_NO_EMPTY);
        }
        $clean['excluded_product_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $productIds))));

        $categoryIds = $raw['excluded_category_ids'] ?? [];
        $clean['excluded_category_ids'] = array_values(array_unique(array_filter(array_map('intval', (array) $categoryIds))));

        $customerIntro = isset($raw['customer_email_intro']) ? wp_kses_post((string) wp_unslash($raw['customer_email_intro'])) : '';
        $clean['customer_email_intro'] = trim($customerIntro) !== '' ? $customerIntro : $defaults['customer_email_intro'];

        $adminEmail = isset($raw['admin_email']) ? sanitize_email((string) wp_unslash($raw['admin_email'])) : '';
        $clean['admin_email'] = is_email($adminEmail) ? $adminEmail : '';

        $widgetTextareaKeys = [
            'widget_intro',
            'widget_help_text',
            'widget_success_text',
            'widget_message_lookup_error',
            'widget_message_submit_error',
        ];

        foreach (self::get_widget_text_defaults() as $key => $defaultValue) {
            $rawValue = isset($raw[$key]) ? (string) wp_unslash($raw[$key]) : '';
            $sanitized = in_array($key, $widgetTextareaKeys, true)
                ? sanitize_textarea_field($rawValue)
                : sanitize_text_field($rawValue);
            $clean[$key] = trim($sanitized) !== '' ? $sanitized : (string) $defaultValue;
        }

        foreach (['style_accent_color', 'style_accent_text_color', 'style_background_color', 'style_panel_color', 'style_border_color', 'style_text_color', 'style_muted_text_color', 'style_success_color', 'style_error_color', 'style_button_primary_hover_color', 'style_button_primary_hover_text_color', 'style_button_primary_hover_border_color', 'style_button_secondary_hover_color', 'style_button_secondary_hover_text_color', 'style_button_secondary_hover_border_color'] as $colorKey) {
            $value = isset($raw[$colorKey]) ? sanitize_hex_color((string) wp_unslash($raw[$colorKey])) : '';
            $clean[$colorKey] = $value ?: $defaults[$colorKey];
        }

        $clean['style_radius'] = isset($raw['style_radius']) ? max(0, min(48, (int) $raw['style_radius'])) : (int) $defaults['style_radius'];
        $clean['delete_data_on_uninstall'] = empty($raw['delete_data_on_uninstall']) ? 0 : 1;
        $clean['add_order_note'] = empty($raw['add_order_note']) ? 0 : 1;

        return $clean;
    }

    public function register_frontend_assets(): void {
        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 4) . '/hb-unified-commerce-suite.php');
        $base = trailingslashit(plugins_url('src/assets/', $pluginFile));
        $scriptPath = dirname(__FILE__, 3) . '/assets/frontend-hb-ucs-returns.js';
        $stylePath = dirname(__FILE__, 3) . '/assets/frontend-hb-ucs-returns.css';
        $version = defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0';

        wp_register_style(
            'hb-ucs-returns',
            $base . 'frontend-hb-ucs-returns.css',
            [],
            file_exists($stylePath) ? (string) filemtime($stylePath) : $version
        );

        wp_register_script(
            'hb-ucs-returns',
            $base . 'frontend-hb-ucs-returns.js',
            ['jquery'],
            file_exists($scriptPath) ? (string) filemtime($scriptPath) : $version,
            true
        );
    }

    public function render_shortcode($atts = []): string {
        return $this->render_form_shell('shortcode');
    }

    public function render_public_form(string $context = 'shortcode'): string {
        return $this->render_form_shell($context);
    }

    public function register_elementor_widget($widgetsManager): void {
        if (!class_exists('HB\\UCS\\Modules\\Returns\\Elementor\\ReturnsFormWidget')) {
            return;
        }

        $widget = new \HB\UCS\Modules\Returns\Elementor\ReturnsFormWidget();

        if (is_object($widgetsManager) && method_exists($widgetsManager, 'register')) {
            $widgetsManager->register($widget);
            return;
        }

        if (is_object($widgetsManager) && method_exists($widgetsManager, 'register_widget_type')) {
            $widgetsManager->register_widget_type($widget);
        }
    }

    public function render_form_shell(string $context = 'shortcode'): string {
        $this->register_frontend_assets();
        wp_enqueue_style('hb-ucs-returns');
        wp_enqueue_script('hb-ucs-returns');

        $settings = self::get_settings();
        $widgetTexts = $this->get_widget_texts($settings);
        wp_localize_script('hb-ucs-returns', 'hbUcsReturns', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'messages' => [
                'loading' => $widgetTexts['message_loading'],
                'submitLoading' => $widgetTexts['message_submit_loading'],
                'lookupError' => $widgetTexts['message_lookup_error'],
                'submitError' => $widgetTexts['message_submit_error'],
                'itemsRequired' => $widgetTexts['message_items_required'],
                'orderSummaryLabel' => $widgetTexts['label_order_summary'],
                'placedOnLabel' => $widgetTexts['label_placed_on'],
                'completedOnLabel' => $widgetTexts['label_completed_on'],
                'deliveryLabel' => $widgetTexts['label_delivery'],
                'deadlineLabel' => $widgetTexts['label_deadline'],
                'availableLabel' => $widgetTexts['label_available_quantity'],
                'quantityLabel' => $widgetTexts['label_quantity'],
                'successHeading' => $widgetTexts['success_heading'],
                'successText' => $widgetTexts['success_text'],
                'requestNumberLabel' => $widgetTexts['label_request_number'],
                'customerMailLabel' => $widgetTexts['label_customer_mail'],
            ],
        ]);

        $style = $this->build_inline_style_vars($settings);

        ob_start();
        ?>
        <div class="hb-ucs-returns" data-hb-ucs-returns="1" data-hb-ucs-context="<?php echo esc_attr($context); ?>" style="<?php echo esc_attr($style); ?>">
            <div class="hb-ucs-returns__shell">
                <div class="hb-ucs-returns__hero">
                    <div>
                        <p class="hb-ucs-returns__eyebrow"><?php echo esc_html($widgetTexts['eyebrow']); ?></p>
                        <h2 class="hb-ucs-returns__title"><?php echo esc_html($widgetTexts['title']); ?></h2>
                        <p class="hb-ucs-returns__intro"><?php echo esc_html($widgetTexts['intro']); ?></p>
                        <p class="hb-ucs-returns__help"><?php echo esc_html($widgetTexts['help_text']); ?></p>
                    </div>
                    <ol class="hb-ucs-returns__steps" aria-label="<?php echo esc_attr__('Retourstappen', 'hb-ucs'); ?>">
                        <li class="is-active"><?php echo esc_html($widgetTexts['step_lookup']); ?></li>
                        <li><?php echo esc_html($widgetTexts['step_items']); ?></li>
                        <li><?php echo esc_html($widgetTexts['step_success']); ?></li>
                    </ol>
                </div>

                <div class="hb-ucs-returns__feedback" hidden></div>

                <form class="hb-ucs-returns__card hb-ucs-returns__lookup-form" data-step="lookup">
                    <div class="hb-ucs-returns__grid">
                        <p class="hb-ucs-returns__field">
                            <label><?php echo esc_html($widgetTexts['order_number_label']); ?></label>
                            <input type="text" name="order_number" autocomplete="off" required />
                        </p>
                        <p class="hb-ucs-returns__field">
                            <label><?php echo esc_html($widgetTexts['postcode_label']); ?></label>
                            <input type="text" name="postcode" autocomplete="postal-code" required />
                        </p>
                    </div>
                    <div class="hb-ucs-returns__actions">
                        <button type="submit" class="hb-ucs-returns__button hb-ucs-returns__button--primary"><?php echo esc_html($widgetTexts['lookup_button_text']); ?></button>
                    </div>
                </form>

                <form class="hb-ucs-returns__card hb-ucs-returns__items-form" data-step="items" hidden>
                    <div class="hb-ucs-returns__summary"></div>
                    <div class="hb-ucs-returns__items"></div>
                    <p class="hb-ucs-returns__field hb-ucs-returns__field--full">
                        <label><?php echo esc_html($widgetTexts['reason_label']); ?></label>
                        <textarea name="reason" rows="4" placeholder="<?php echo esc_attr($widgetTexts['reason_placeholder']); ?>"></textarea>
                    </p>
                    <div class="hb-ucs-returns__actions">
                        <button type="button" class="hb-ucs-returns__button hb-ucs-returns__button--secondary" data-hb-ucs-returns-back="1"><?php echo esc_html($widgetTexts['back_button_text']); ?></button>
                        <button type="submit" class="hb-ucs-returns__button hb-ucs-returns__button--primary"><?php echo esc_html($widgetTexts['submit_button_text']); ?></button>
                    </div>
                </form>

                <section class="hb-ucs-returns__card hb-ucs-returns__success" data-step="success" hidden></section>
            </div>
        </div>
        <?php

        return (string) ob_get_clean();
    }

    public function handle_lookup_ajax(): void {
        if (!$this->validate_public_nonce()) {
            wp_send_json_error(['message' => __('Ongeldige sessie. Vernieuw de pagina en probeer het opnieuw.', 'hb-ucs')], 403);
        }

        $orderNumber = isset($_POST['order_number']) ? sanitize_text_field((string) wp_unslash($_POST['order_number'])) : '';
        $postcode = isset($_POST['postcode']) ? sanitize_text_field((string) wp_unslash($_POST['postcode'])) : '';
        $order = $this->find_eligible_order_by_lookup($orderNumber, $postcode);

        if (!$order) {
            wp_send_json_error(['message' => __('We konden geen afgeronde bestelling vinden die bij deze gegevens past of nog binnen de retourtermijn valt.', 'hb-ucs')], 404);
        }

        $items = $this->get_returnable_items($order);
        if (empty($items)) {
            wp_send_json_error(['message' => __('Voor deze bestelling zijn momenteel geen artikelen meer beschikbaar voor een retourmelding.', 'hb-ucs')], 409);
        }

        wp_send_json_success([
            'order' => $this->build_order_payload($order),
            'items' => $items,
        ]);
    }

    public function handle_submit_ajax(): void {
        if (!$this->validate_public_nonce()) {
            wp_send_json_error(['message' => __('Ongeldige sessie. Vernieuw de pagina en probeer het opnieuw.', 'hb-ucs')], 403);
        }

        $orderNumber = isset($_POST['order_number']) ? sanitize_text_field((string) wp_unslash($_POST['order_number'])) : '';
        $postcode = isset($_POST['postcode']) ? sanitize_text_field((string) wp_unslash($_POST['postcode'])) : '';
        $reason = isset($_POST['reason']) ? sanitize_textarea_field((string) wp_unslash($_POST['reason'])) : '';
        $rawItems = isset($_POST['items']) ? (array) $_POST['items'] : [];
        $order = $this->find_eligible_order_by_lookup($orderNumber, $postcode);

        if (!$order) {
            wp_send_json_error(['message' => __('De bestelling is niet meer geldig voor een retourmelding. Start opnieuw en controleer de gegevens.', 'hb-ucs')], 409);
        }

        $availableItems = $this->get_returnable_items($order);
        $selectedItems = $this->normalize_selected_items($rawItems, $availableItems);
        if (empty($selectedItems)) {
            wp_send_json_error(['message' => __('Selecteer minimaal één artikel en kies een geldig aantal.', 'hb-ucs')], 422);
        }

        $requestId = $this->create_return_request($order, $selectedItems, $reason);
        if ($requestId <= 0) {
            wp_send_json_error(['message' => __('De retourmelding kon niet worden opgeslagen.', 'hb-ucs')], 500);
        }

        $this->send_customer_email($requestId);
        $this->send_admin_email($requestId);

        wp_send_json_success([
            'request_number' => $this->get_request_number($requestId),
            'order_number' => (string) $order->get_order_number(),
            'submitted_at' => $this->format_datetime_for_display((string) get_post_meta($requestId, self::META_SUBMITTED_AT, true)),
            'customer_email' => (string) get_post_meta($requestId, self::META_BILLING_EMAIL, true),
            'items' => array_values(array_map(function (array $item): array {
                return [
                    'name' => (string) ($item['name'] ?? ''),
                    'meta' => (string) ($item['meta_text'] ?? ''),
                    'quantity' => (int) ($item['quantity'] ?? 0),
                ];
            }, $selectedItems)),
        ]);
    }

    public function get_return_requests($filters = '', int $limit = 50): array {
        if (is_string($filters)) {
            $filters = ['status' => $filters];
        }

        $result = $this->query_return_requests(is_array($filters) ? $filters : [], [
            'posts_per_page' => max(1, $limit),
            'paged' => 1,
        ]);

        return (array) ($result['posts'] ?? []);
    }

    public function query_return_requests(array $filters = [], array $options = []): array {
        $postsPerPage = max(1, (int) ($options['posts_per_page'] ?? 20));
        $paged = max(1, (int) ($options['paged'] ?? 1));
        $status = sanitize_key((string) ($filters['status'] ?? ''));
        $search = trim((string) ($filters['search'] ?? ''));
        $dateFrom = $this->normalize_admin_date_string((string) ($filters['date_from'] ?? ''));
        $dateTo = $this->normalize_admin_date_string((string) ($filters['date_to'] ?? ''));
        $orderby = sanitize_key((string) ($filters['orderby'] ?? 'submitted_at'));
        $order = strtoupper((string) ($filters['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        $metaQuery = [];

        if ($status !== '' && isset(self::status_labels()[$status])) {
            $metaQuery[] = [
                'key' => self::META_STATUS,
                'value' => $status,
                'compare' => '=',
            ];
        }

        if ($dateFrom !== '') {
            $metaQuery[] = [
                'key' => self::META_SUBMITTED_AT,
                'value' => gmdate('c', strtotime($dateFrom . ' 00:00:00 UTC')),
                'compare' => '>=',
                'type' => 'CHAR',
            ];
        }

        if ($dateTo !== '') {
            $metaQuery[] = [
                'key' => self::META_SUBMITTED_AT,
                'value' => gmdate('c', strtotime($dateTo . ' 23:59:59 UTC')),
                'compare' => '<=',
                'type' => 'CHAR',
            ];
        }

        $requestIdSearch = $this->extract_request_id_from_search($search);
        $matchedPostIds = [];
        if ($search !== '') {
            $searchQuery = [
                'relation' => 'OR',
                [
                    'key' => self::META_ORDER_NUMBER,
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => self::META_BILLING_NAME,
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
                [
                    'key' => self::META_BILLING_EMAIL,
                    'value' => $search,
                    'compare' => 'LIKE',
                ],
            ];

            $searchMetaQuery = $metaQuery;
            $searchMetaQuery[] = $searchQuery;
            if (count($searchMetaQuery) > 1) {
                $searchMetaQuery = array_merge(['relation' => 'AND'], $searchMetaQuery);
            }

            $matchedPostIds = get_posts([
                'post_type' => self::get_request_post_types(),
                'post_status' => 'private',
                'fields' => 'ids',
                'numberposts' => -1,
                'meta_query' => $searchMetaQuery,
            ]);

            if ($requestIdSearch > 0) {
                $requestPost = get_post($requestIdSearch);
                if ($requestPost instanceof \WP_Post && in_array((string) $requestPost->post_type, self::get_request_post_types(), true)) {
                    $matchedPostIds[] = $requestIdSearch;
                }
            }

            $matchedPostIds = array_values(array_unique(array_map('intval', (array) $matchedPostIds)));
        }

        if (count($metaQuery) > 1) {
            $metaQuery = array_merge(['relation' => 'AND'], $metaQuery);
        }

        $queryArgs = [
            'post_type' => self::get_request_post_types(),
            'post_status' => 'private',
            'posts_per_page' => $postsPerPage,
            'paged' => $paged,
            'no_found_rows' => false,
            'ignore_sticky_posts' => true,
        ];

        if (!empty($metaQuery)) {
            $queryArgs['meta_query'] = $metaQuery;
        }

        if ($search !== '') {
            $queryArgs['post__in'] = !empty($matchedPostIds) ? $matchedPostIds : [0];
        }

        switch ($orderby) {
            case 'request_number':
                $queryArgs['orderby'] = 'ID';
                $queryArgs['order'] = $order;
                break;
            case 'order_number':
                $queryArgs['meta_key'] = self::META_ORDER_NUMBER;
                $queryArgs['orderby'] = 'meta_value';
                $queryArgs['order'] = $order;
                break;
            case 'customer':
                $queryArgs['meta_key'] = self::META_BILLING_NAME;
                $queryArgs['orderby'] = 'meta_value';
                $queryArgs['order'] = $order;
                break;
            case 'status':
                $queryArgs['meta_key'] = self::META_STATUS;
                $queryArgs['orderby'] = 'meta_value';
                $queryArgs['order'] = $order;
                break;
            case 'submitted_at':
            default:
                $queryArgs['meta_key'] = self::META_SUBMITTED_AT;
                $queryArgs['orderby'] = 'meta_value';
                $queryArgs['order'] = $order;
                break;
        }

        $query = new \WP_Query($queryArgs);

        return [
            'posts' => $query->posts,
            'total_items' => (int) $query->found_posts,
            'total_pages' => max(1, (int) $query->max_num_pages),
            'paged' => $paged,
            'posts_per_page' => $postsPerPage,
        ];
    }

    public function update_return_request_status(int $requestId, string $status): bool {
        $status = sanitize_key($status);
        if ($requestId <= 0 || !isset(self::status_labels()[$status])) {
            return false;
        }

        update_post_meta($requestId, self::META_STATUS, $status);

        return true;
    }

    private function normalize_admin_date_string(string $date): string {
        $date = trim($date);
        if ($date === '') {
            return '';
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
    }

    private function extract_request_id_from_search(string $search): int {
        $search = strtoupper(trim($search));
        if ($search === '') {
            return 0;
        }

        if (strpos($search, 'RET-') === 0) {
            $search = substr($search, 4);
        }

        return ctype_digit($search) ? (int) ltrim($search, '0') : 0;
    }

    public function get_selected_product_map(array $productIds): array {
        $map = [];
        foreach (array_filter(array_map('intval', $productIds)) as $productId) {
            $product = function_exists('wc_get_product') ? wc_get_product($productId) : null;
            if (!$product) {
                continue;
            }

            $map[(string) $productId] = sprintf('%s (#%d)', wp_strip_all_tags((string) $product->get_name()), $productId);
        }

        return $map;
    }

    public function format_request_items_list(array $items): string {
        if (empty($items)) {
            return '<p>' . esc_html__('Geen artikelen opgeslagen.', 'hb-ucs') . '</p>';
        }

        $html = '<ul class="hb-ucs-returns-admin-items">';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = (string) ($item['name'] ?? '');
            $qty = (int) ($item['quantity'] ?? 0);
            $meta = trim((string) ($item['meta_text'] ?? ''));
            $html .= '<li><strong>' . esc_html($name) . '</strong> × ' . esc_html((string) $qty);
            if ($meta !== '') {
                $html .= '<br/><span>' . esc_html($meta) . '</span>';
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    protected function validate_public_nonce(): bool {
        $nonce = isset($_POST['nonce']) ? (string) wp_unslash($_POST['nonce']) : '';

        return $nonce !== '' && wp_verify_nonce($nonce, self::NONCE_ACTION);
    }

    protected function build_inline_style_vars(array $settings): string {
        $vars = [
            '--hb-ucs-returns-accent' => (string) $settings['style_accent_color'],
            '--hb-ucs-returns-accent-text' => (string) $settings['style_accent_text_color'],
            '--hb-ucs-returns-bg' => (string) $settings['style_background_color'],
            '--hb-ucs-returns-panel' => (string) $settings['style_panel_color'],
            '--hb-ucs-returns-border' => (string) $settings['style_border_color'],
            '--hb-ucs-returns-text' => (string) $settings['style_text_color'],
            '--hb-ucs-returns-muted' => (string) $settings['style_muted_text_color'],
            '--hb-ucs-returns-success' => (string) $settings['style_success_color'],
            '--hb-ucs-returns-error' => (string) $settings['style_error_color'],
            '--hb-ucs-returns-button-primary-hover-bg' => (string) $settings['style_button_primary_hover_color'],
            '--hb-ucs-returns-button-primary-hover-text' => (string) $settings['style_button_primary_hover_text_color'],
            '--hb-ucs-returns-button-primary-hover-border' => (string) $settings['style_button_primary_hover_border_color'],
            '--hb-ucs-returns-button-secondary-hover-bg' => (string) $settings['style_button_secondary_hover_color'],
            '--hb-ucs-returns-button-secondary-hover-text' => (string) $settings['style_button_secondary_hover_text_color'],
            '--hb-ucs-returns-button-secondary-hover-border' => (string) $settings['style_button_secondary_hover_border_color'],
            '--hb-ucs-returns-radius' => (int) $settings['style_radius'] . 'px',
        ];

        $parts = [];
        foreach ($vars as $key => $value) {
            $parts[] = $key . ':' . $value;
        }

        return implode(';', $parts);
    }

    private function get_widget_texts(array $settings): array {
        return [
            'eyebrow' => (string) $settings['widget_eyebrow'],
            'title' => (string) $settings['widget_title'],
            'intro' => (string) $settings['widget_intro'],
            'help_text' => $this->replace_widget_text_tokens((string) $settings['widget_help_text'], $settings),
            'step_lookup' => (string) $settings['widget_step_lookup'],
            'step_items' => (string) $settings['widget_step_items'],
            'step_success' => (string) $settings['widget_step_success'],
            'order_number_label' => (string) $settings['widget_order_number_label'],
            'postcode_label' => (string) $settings['widget_postcode_label'],
            'lookup_button_text' => (string) $settings['widget_lookup_button_text'],
            'reason_label' => (string) $settings['widget_reason_label'],
            'reason_placeholder' => (string) $settings['widget_reason_placeholder'],
            'back_button_text' => (string) $settings['widget_back_button_text'],
            'submit_button_text' => (string) $settings['widget_submit_button_text'],
            'success_heading' => (string) $settings['widget_success_heading'],
            'success_text' => (string) $settings['widget_success_text'],
            'message_loading' => (string) $settings['widget_message_loading'],
            'message_submit_loading' => (string) $settings['widget_message_submit_loading'],
            'message_lookup_error' => (string) $settings['widget_message_lookup_error'],
            'message_submit_error' => (string) $settings['widget_message_submit_error'],
            'message_items_required' => (string) $settings['widget_message_items_required'],
            'label_order_summary' => (string) $settings['widget_label_order_summary'],
            'label_placed_on' => (string) $settings['widget_label_placed_on'],
            'label_completed_on' => (string) $settings['widget_label_completed_on'],
            'label_delivery' => (string) $settings['widget_label_delivery'],
            'label_deadline' => (string) $settings['widget_label_deadline'],
            'label_available_quantity' => (string) $settings['widget_label_available_quantity'],
            'label_quantity' => (string) $settings['widget_label_quantity'],
            'label_request_number' => (string) $settings['widget_label_request_number'],
            'label_customer_mail' => (string) $settings['widget_label_customer_mail'],
        ];
    }

    private function replace_widget_text_tokens(string $text, array $settings): string {
        return strtr($text, [
            '{days}' => (string) ((int) ($settings['return_window_days'] ?? 0)),
            '{offset}' => (string) ((int) ($settings['delivery_offset_days'] ?? 0)),
        ]);
    }

    protected function build_order_payload($order): array {
        $eligibility = $this->get_order_eligibility_dates($order);

        return [
            'order_id' => (int) $order->get_id(),
            'order_number' => (string) $order->get_order_number(),
            'placed_on' => $this->format_datetime($order->get_date_created()),
            'completed_on' => $this->format_datetime($order->get_date_completed()),
            'estimated_delivery' => $eligibility['delivery_display'],
            'return_deadline' => $eligibility['deadline_display'],
            'customer_name' => trim((string) $order->get_formatted_billing_full_name()),
        ];
    }

    protected function find_eligible_order_by_lookup(string $orderNumber, string $postcode) {
        $orderNumber = $this->normalize_order_number($orderNumber);
        $postcode = $this->normalize_postcode($postcode);
        if ($orderNumber === '' || $postcode === '') {
            return null;
        }

        foreach ($this->find_candidate_orders_by_number($orderNumber) as $order) {
            if (!$order || !is_object($order)) {
                continue;
            }

            if (!$this->is_allowed_lookup_order($order)) {
                continue;
            }

            if ($this->normalize_postcode((string) $order->get_billing_postcode()) !== $postcode) {
                continue;
            }

            if (!$this->is_order_within_return_window($order)) {
                continue;
            }

            return $order;
        }

        return null;
    }

    protected function find_candidate_orders_by_number(string $orderNumber): array {
        $candidates = [];
        $seen = [];

        $normalized = $this->normalize_order_number($orderNumber);
        if ($normalized === '') {
            return [];
        }

        if (ctype_digit($normalized)) {
            $direct = wc_get_order((int) $normalized);
            if ($direct) {
                $seen[(int) $direct->get_id()] = true;
                $candidates[] = $direct;
            }
        }

        $statusFilter = ['wc-completed'];
        $querySets = [
            [
                'limit' => 20,
                'status' => $statusFilter,
                'orderby' => 'date',
                'order' => 'DESC',
                'search' => $orderNumber,
            ],
            [
                'limit' => 20,
                'status' => $statusFilter,
                'orderby' => 'date',
                'order' => 'DESC',
                'meta_query' => [
                    'relation' => 'OR',
                    ['key' => '_order_number', 'value' => $orderNumber, 'compare' => '='],
                    ['key' => '_order_number_formatted', 'value' => $orderNumber, 'compare' => '='],
                    ['key' => '_custom_order_number', 'value' => $orderNumber, 'compare' => '='],
                    ['key' => '_alg_wc_custom_order_number', 'value' => $orderNumber, 'compare' => '='],
                ],
            ],
        ];

        foreach ($querySets as $queryArgs) {
            $orders = wc_get_orders($queryArgs);
            foreach ((array) $orders as $order) {
                if (!$order || !is_object($order)) {
                    continue;
                }

                $orderId = (int) $order->get_id();
                if (isset($seen[$orderId])) {
                    continue;
                }

                $seen[$orderId] = true;
                $candidates[] = $order;
            }
        }

        $recentOrders = wc_get_orders([
            'limit' => 250,
            'status' => $statusFilter,
            'orderby' => 'date',
            'order' => 'DESC',
            'date_completed' => '>' . gmdate('Y-m-d H:i:s', current_time('timestamp', true) - (730 * DAY_IN_SECONDS)),
        ]);

        foreach ((array) $recentOrders as $order) {
            if (!$order || !is_object($order)) {
                continue;
            }

            $orderId = (int) $order->get_id();
            if (isset($seen[$orderId])) {
                continue;
            }

            if ($this->normalize_order_number((string) $order->get_order_number()) !== $normalized) {
                continue;
            }

            $seen[$orderId] = true;
            $candidates[] = $order;
        }

        return $candidates;
    }

    protected function is_allowed_lookup_order($order): bool {
        if (!$order || !is_object($order) || !method_exists($order, 'get_status')) {
            return false;
        }

        return (string) $order->get_status() === 'completed';
    }

    protected function is_order_within_return_window($order): bool {
        $dates = $this->get_order_eligibility_dates($order);
        if (empty($dates['deadline_ts'])) {
            return false;
        }

        return current_time('timestamp', true) <= (int) $dates['deadline_ts'];
    }

    protected function get_order_eligibility_dates($order): array {
        $settings = self::get_settings();
        $completed = $order && method_exists($order, 'get_date_completed') ? $order->get_date_completed() : null;
        $completedTs = $completed ? (int) $completed->getTimestamp() : 0;
        $deliveryTs = $completedTs > 0 ? $completedTs + ((int) $settings['delivery_offset_days'] * DAY_IN_SECONDS) : 0;
        $deadlineTs = $deliveryTs > 0 ? $deliveryTs + ((int) $settings['return_window_days'] * DAY_IN_SECONDS) : 0;

        return [
            'completed_ts' => $completedTs,
            'delivery_ts' => $deliveryTs,
            'deadline_ts' => $deadlineTs,
            'completed_display' => $this->format_timestamp_for_display($completedTs),
            'delivery_display' => $this->format_timestamp_for_display($deliveryTs),
            'deadline_display' => $this->format_timestamp_for_display($deadlineTs),
        ];
    }

    protected function get_returnable_items($order): array {
        $settings = self::get_settings();
        $consumed = $this->get_consumed_quantities_for_order((int) $order->get_id());
        $items = [];

        foreach ((array) $order->get_items('line_item') as $itemId => $item) {
            if (!$item || !is_object($item)) {
                continue;
            }

            $orderedQty = max(0, (int) $item->get_quantity());
            $remainingQty = max(0, $orderedQty - (int) ($consumed[(int) $itemId] ?? 0));
            if ($remainingQty <= 0) {
                continue;
            }

            $product = $item->get_product();
            if (!$this->is_item_returnable($product, $settings)) {
                continue;
            }

            $metaHtml = wc_display_item_meta($item, [
                'echo' => false,
                'separator' => '<br/>',
                'autop' => false,
            ]);
            $metaText = trim(wp_strip_all_tags(str_replace(['<br />', '<br/>', '<br>'], ', ', (string) $metaHtml)));
            $imageUrl = '';

            if ($product && method_exists($product, 'get_image_id')) {
                $imageId = (int) $product->get_image_id();
                if ($imageId > 0) {
                    $imageUrl = (string) wp_get_attachment_image_url($imageId, 'thumbnail');
                }
            }

            if ($imageUrl === '' && function_exists('wc_placeholder_img_src')) {
                $imageUrl = (string) wc_placeholder_img_src('thumbnail');
            }

            $items[(int) $itemId] = [
                'item_id' => (int) $itemId,
                'product_id' => $product ? (int) $product->get_id() : 0,
                'variation_id' => method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0,
                'name' => wp_strip_all_tags((string) $item->get_name()),
                'quantity_ordered' => $orderedQty,
                'available_quantity' => $remainingQty,
                'meta' => $metaText,
                'meta_text' => $metaText,
                'image' => $imageUrl,
                'image_url' => $imageUrl,
                'total' => wp_kses_post((string) $order->get_formatted_line_subtotal($item)),
                'line_total_html' => wp_kses_post((string) $order->get_formatted_line_subtotal($item)),
            ];
        }

        return $items;
    }

    protected function is_item_returnable($product, array $settings): bool {
        if (!$product || !is_object($product) || !method_exists($product, 'get_id')) {
            return false;
        }

        $productId = (int) $product->get_id();
        $parentId = method_exists($product, 'get_parent_id') ? (int) $product->get_parent_id() : 0;
        $excludedProducts = array_map('intval', (array) ($settings['excluded_product_ids'] ?? []));
        if (in_array($productId, $excludedProducts, true) || ($parentId > 0 && in_array($parentId, $excludedProducts, true))) {
            return false;
        }

        $excludedCategories = array_map('intval', (array) ($settings['excluded_category_ids'] ?? []));
        if (!empty($excludedCategories)) {
            $checkId = $parentId > 0 ? $parentId : $productId;
            $terms = get_the_terms($checkId, 'product_cat');
            if (is_array($terms)) {
                foreach ($terms as $term) {
                    if ($term && in_array((int) $term->term_id, $excludedCategories, true)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    protected function get_consumed_quantities_for_order(int $orderId): array {
        if ($orderId <= 0) {
            return [];
        }

        $requestIds = get_posts([
            'post_type' => self::get_request_post_types(),
            'post_status' => 'private',
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => self::META_ORDER_ID,
            'meta_value' => $orderId,
        ]);

        $quantities = [];
        foreach ((array) $requestIds as $requestId) {
            $status = (string) get_post_meta((int) $requestId, self::META_STATUS, true);
            if (!in_array($status, self::get_countable_statuses(), true)) {
                continue;
            }

            $items = get_post_meta((int) $requestId, self::META_ITEMS, true);
            foreach ((array) $items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $itemId = (int) ($item['item_id'] ?? 0);
                $qty = (int) ($item['quantity'] ?? 0);
                if ($itemId <= 0 || $qty <= 0) {
                    continue;
                }

                $quantities[$itemId] = (int) ($quantities[$itemId] ?? 0) + $qty;
            }
        }

        return $quantities;
    }

    protected function normalize_selected_items(array $rawItems, array $availableItems): array {
        $selected = [];
        foreach ($rawItems as $itemId => $qtyRaw) {
            $itemId = (int) $itemId;
            $qty = max(0, (int) $qtyRaw);
            if ($itemId <= 0 || $qty <= 0 || !isset($availableItems[$itemId])) {
                continue;
            }

            $available = $availableItems[$itemId];
            $qty = min($qty, (int) ($available['available_quantity'] ?? 0));
            if ($qty <= 0) {
                continue;
            }

            $selected[$itemId] = [
                'item_id' => $itemId,
                'product_id' => (int) ($available['product_id'] ?? 0),
                'variation_id' => (int) ($available['variation_id'] ?? 0),
                'name' => (string) ($available['name'] ?? ''),
                'quantity' => $qty,
                'quantity_available_at_submission' => (int) ($available['available_quantity'] ?? 0),
                'meta_text' => (string) ($available['meta_text'] ?? ''),
                'meta' => (string) ($available['meta_text'] ?? ''),
            ];
        }

        return $selected;
    }

    protected function create_return_request($order, array $selectedItems, string $reason): int {
        $this->register_post_type();

        $orderNumber = method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : (string) $order->get_id();
        $postId = wp_insert_post([
            'post_type' => self::POST_TYPE,
            'post_status' => 'private',
            'post_title' => sprintf(__('Retourmelding %s voor bestelling %s', 'hb-ucs'), gmdate('Y-m-d H:i'), $orderNumber),
        ], true);

        if (is_wp_error($postId)) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error(
                    'Retourmelding kon niet worden opgeslagen: ' . $postId->get_error_message(),
                    ['source' => 'hb-ucs-returns']
                );
            }

            return 0;
        }

        if (!$postId) {
            return 0;
        }

        $requestId = (int) $postId;
        $dates = $this->get_order_eligibility_dates($order);
        $submittedAt = gmdate('c');

        update_post_meta($requestId, self::META_STATUS, 'submitted');
        update_post_meta($requestId, self::META_ORDER_ID, (int) $order->get_id());
        update_post_meta($requestId, self::META_ORDER_NUMBER, $orderNumber);
        update_post_meta($requestId, self::META_BILLING_EMAIL, (string) $order->get_billing_email());
        update_post_meta($requestId, self::META_BILLING_NAME, trim((string) $order->get_formatted_billing_full_name()));
        update_post_meta($requestId, self::META_BILLING_POSTCODE, (string) $order->get_billing_postcode());
        update_post_meta($requestId, self::META_REASON, $reason);
        update_post_meta($requestId, self::META_ITEMS, array_values($selectedItems));
        update_post_meta($requestId, self::META_SUBMITTED_AT, $submittedAt);
        update_post_meta($requestId, self::META_RETURN_DEADLINE, gmdate('c', (int) ($dates['deadline_ts'] ?? 0)));
        update_post_meta($requestId, self::META_ESTIMATED_DELIVERY, gmdate('c', (int) ($dates['delivery_ts'] ?? 0)));

        $settings = self::get_settings();
        if (!empty($settings['add_order_note']) && method_exists($order, 'add_order_note')) {
            $lines = [];
            foreach ($selectedItems as $item) {
                $line = sprintf('%s × %d', (string) ($item['name'] ?? ''), (int) ($item['quantity'] ?? 0));
                if (!empty($item['meta_text'])) {
                    $line .= ' (' . (string) $item['meta_text'] . ')';
                }
                $lines[] = $line;
            }

            $note = sprintf(
                "%s\n%s",
                sprintf(__('Nieuwe retourmelding %s ontvangen.', 'hb-ucs'), $this->get_request_number($requestId)),
                implode("\n", $lines)
            );

            if (trim($reason) !== '') {
                $note .= "\n" . sprintf(__('Reden: %s', 'hb-ucs'), $reason);
            }

            $order->add_order_note($note, false, true);
        }

        return $requestId;
    }

    protected function send_customer_email(int $requestId): void {
        $request = $this->get_request_context($requestId);
        if (empty($request['billing_email']) || !is_email((string) $request['billing_email'])) {
            return;
        }

        $settings = self::get_settings();
        $subject = sprintf(__('Bevestiging retourmelding voor bestelling %s', 'hb-ucs'), (string) $request['order_number']);
        $heading = __('Bevestiging van je retourmelding', 'hb-ucs');
        $body = '';

        $intro = trim((string) ($settings['customer_email_intro'] ?? ''));
        if ($intro !== '') {
            $body .= wpautop(wp_kses_post($intro));
        }

        $body .= '<p><strong>' . esc_html__('Retournummer:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['request_number']) . '<br/>';
        $body .= '<strong>' . esc_html__('Ordernummer:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['order_number']) . '<br/>';
        $body .= '<strong>' . esc_html__('Ingediend op:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['submitted_at_display']) . '</p>';
        $body .= '<h3>' . esc_html__('Aangemelde artikelen', 'hb-ucs') . '</h3>';
        $body .= $this->build_email_items_html((array) $request['items']);

        if ((string) $request['reason'] !== '') {
            $body .= '<p><strong>' . esc_html__('Retourreden:', 'hb-ucs') . '</strong><br/>' . nl2br(esc_html((string) $request['reason'])) . '</p>';
        }

        $this->send_wrapped_email((string) $request['billing_email'], $subject, $heading, $body);
    }

    protected function send_admin_email(int $requestId): void {
        $request = $this->get_request_context($requestId);
        $settings = self::get_settings();
        $adminEmail = (string) ($settings['admin_email'] ?: get_option('admin_email', ''));
        if ($adminEmail === '' || !is_email($adminEmail)) {
            return;
        }

        $subject = sprintf(__('Nieuwe retourmelding ontvangen voor bestelling %s', 'hb-ucs'), (string) $request['order_number']);
        $heading = __('Nieuwe retourmelding', 'hb-ucs');
        $body = '<p><strong>' . esc_html__('Retournummer:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['request_number']) . '<br/>';
        $body .= '<strong>' . esc_html__('Ordernummer:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['order_number']) . '<br/>';
        $body .= '<strong>' . esc_html__('Klant:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['billing_name']) . ' &lt;' . esc_html((string) $request['billing_email']) . '&gt;<br/>';
        $body .= '<strong>' . esc_html__('Ingediend op:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['submitted_at_display']) . '</p>';
        $body .= '<h3>' . esc_html__('Aangemelde artikelen', 'hb-ucs') . '</h3>';
        $body .= $this->build_email_items_html((array) $request['items']);

        if ((string) $request['reason'] !== '') {
            $body .= '<p><strong>' . esc_html__('Retourreden:', 'hb-ucs') . '</strong><br/>' . nl2br(esc_html((string) $request['reason'])) . '</p>';
        }

        $this->send_wrapped_email($adminEmail, $subject, $heading, $body);
    }

    public function send_return_label_email(int $requestId, string $message, array $attachments = []): bool {
        $request = $this->get_request_context($requestId);
        if (empty($request['billing_email']) || !is_email((string) $request['billing_email'])) {
            return false;
        }

        $subject = sprintf(__('Retourlabel voor bestelling %s', 'hb-ucs'), (string) $request['order_number']);
        $heading = __('Je retourlabel', 'hb-ucs');
        $body = wpautop(esc_html($message));
        $body .= '<p><strong>' . esc_html__('Retournummer:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['request_number']) . '<br/>';
        $body .= '<strong>' . esc_html__('Ordernummer:', 'hb-ucs') . '</strong> ' . esc_html((string) $request['order_number']) . '</p>';
        $body .= '<h3>' . esc_html__('Aangemelde artikelen', 'hb-ucs') . '</h3>';
        $body .= $this->build_email_items_html((array) $request['items']);

        return $this->send_wrapped_email((string) $request['billing_email'], $subject, $heading, $body, $attachments);
    }

    public function get_request_context(int $requestId): array {
        $items = get_post_meta($requestId, self::META_ITEMS, true);
        $submittedAt = (string) get_post_meta($requestId, self::META_SUBMITTED_AT, true);
        $estimatedDelivery = (string) get_post_meta($requestId, self::META_ESTIMATED_DELIVERY, true);
        $returnDeadline = (string) get_post_meta($requestId, self::META_RETURN_DEADLINE, true);

        return [
            'request_id' => $requestId,
            'request_number' => $this->get_request_number($requestId),
            'order_id' => (int) get_post_meta($requestId, self::META_ORDER_ID, true),
            'order_number' => (string) get_post_meta($requestId, self::META_ORDER_NUMBER, true),
            'status' => (string) get_post_meta($requestId, self::META_STATUS, true),
            'billing_email' => (string) get_post_meta($requestId, self::META_BILLING_EMAIL, true),
            'billing_name' => (string) get_post_meta($requestId, self::META_BILLING_NAME, true),
            'billing_postcode' => (string) get_post_meta($requestId, self::META_BILLING_POSTCODE, true),
            'reason' => (string) get_post_meta($requestId, self::META_REASON, true),
            'items' => is_array($items) ? $items : [],
            'submitted_at' => $submittedAt,
            'submitted_at_display' => $this->format_datetime_for_display($submittedAt),
            'estimated_delivery' => $estimatedDelivery,
            'estimated_delivery_display' => $this->format_datetime_for_display($estimatedDelivery),
            'return_deadline' => $returnDeadline,
            'return_deadline_display' => $this->format_datetime_for_display($returnDeadline),
        ];
    }

    protected function build_email_items_html(array $items): string {
        if (empty($items)) {
            return '<p>' . esc_html__('Geen artikelen opgeslagen.', 'hb-ucs') . '</p>';
        }

        $html = '<ul>';
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $html .= '<li><strong>' . esc_html((string) ($item['name'] ?? '')) . '</strong> × ' . esc_html((string) ((int) ($item['quantity'] ?? 0)));
            if (!empty($item['meta_text'])) {
                $html .= '<br/>' . esc_html((string) $item['meta_text']);
            }
            $html .= '</li>';
        }
        $html .= '</ul>';

        return $html;
    }

    protected function send_wrapped_email(string $to, string $subject, string $heading, string $body, array $attachments = []): bool {
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        $message = $body;

        if (function_exists('WC')) {
            $mailer = WC()->mailer();
            if ($mailer && is_object($mailer) && method_exists($mailer, 'wrap_message')) {
                $message = $mailer->wrap_message($heading, $body);
            }
        }

        return (bool) wp_mail($to, $subject, $message, $headers, $attachments);
    }

    protected function get_request_number(int $requestId): string {
        return 'RET-' . str_pad((string) $requestId, 6, '0', STR_PAD_LEFT);
    }

    protected function normalize_order_number(string $value): string {
        $value = trim($value);
        $value = ltrim($value, '#');

        return strtoupper(preg_replace('/\s+/', '', $value) ?? '');
    }

    protected function normalize_postcode(string $value): string {
        $value = strtoupper(trim($value));

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    protected static function get_countable_statuses(): array {
        return ['submitted', 'received', 'approved', 'completed'];
    }

    protected function format_timestamp_for_display(int $timestamp): string {
        if ($timestamp <= 0) {
            return __('Onbekend', 'hb-ucs');
        }

        return wp_date(get_option('date_format'), $timestamp);
    }

    protected function format_datetime_for_display(string $iso): string {
        if ($iso === '') {
            return __('Onbekend', 'hb-ucs');
        }

        $timestamp = strtotime($iso);
        if (!$timestamp) {
            return __('Onbekend', 'hb-ucs');
        }

        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $timestamp);
    }

    protected function format_datetime($date): string {
        if (!$date) {
            return '';
        }

        if ($date instanceof \WC_DateTime || $date instanceof \DateTimeInterface) {
            return wc_format_datetime($date);
        }

        try {
            return wc_format_datetime(new \WC_DateTime((string) $date));
        } catch (\Throwable $exception) {
            return '';
        }
    }
}