<?php
namespace HB\UCS\Modules\Customers;

if (!defined('ABSPATH')) {
    exit;
}

class InvoiceEmailModule {
    public const META_KEY = '_hb_invoice_email';
    private const OPT_SETTINGS = 'hb_ucs_invoice_email_settings';

    /** @var array|null */
    private $settings = null;

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        add_action('woocommerce_edit_account_form', [$this, 'render_account_field']);
        add_action('woocommerce_save_account_details', [$this, 'save_account_field'], 10, 1);

        add_action('show_user_profile', [$this, 'render_admin_profile_field']);
        add_action('edit_user_profile', [$this, 'render_admin_profile_field']);
        add_action('personal_options_update', [$this, 'save_admin_profile_field']);
        add_action('edit_user_profile_update', [$this, 'save_admin_profile_field']);

        add_action('woocommerce_order_status_completed', [$this, 'mail_invoice_to_alt_email'], 20, 1);

        // PIPFS: blokkeer bijlagen in standaard klantmails ALS er een factuuradres is (hoge prioriteit)
        add_filter('wpo_wcpdf_document_types_for_email', [$this, 'maybe_block_all_pipfs_docs_for_customer_mails'], 1000, 3);
        add_filter('wpo_wcpdf_custom_attachment_condition', [$this, 'maybe_block_pipfs_attachment_condition'], 1000, 4);
        add_filter('woocommerce_email_attachments', [$this, 'strip_pipfs_attachments_from_customer_mails'], 1000, 4);
    }

    protected function get_settings(): array {
        if (is_array($this->settings)) {
            return $this->settings;
        }

        $opt = get_option(self::OPT_SETTINGS, []);
        if (!is_array($opt)) {
            $opt = [];
        }

        $this->settings = [
            'allowed_roles' => array_values(array_filter(array_map('sanitize_key', (array) ($opt['allowed_roles'] ?? [])))),
            'allowed_b2b_profiles' => array_values(array_filter(array_map('strval', (array) ($opt['allowed_b2b_profiles'] ?? [])))),
        ];

        return $this->settings;
    }

    protected function is_enabled_for_user_id(int $user_id): bool {
        if ($user_id <= 0) {
            return false;
        }

        static $cache = [];
        if (array_key_exists($user_id, $cache)) {
            return (bool) $cache[$user_id];
        }

        $settings = $this->get_settings();
        $allowed_roles = (array) ($settings['allowed_roles'] ?? []);
        $allowed_profiles = (array) ($settings['allowed_b2b_profiles'] ?? []);

        // No restrictions means enabled for everyone.
        if (empty($allowed_roles) && empty($allowed_profiles)) {
            $cache[$user_id] = true;
            return true;
        }

        $user = get_userdata($user_id);
        if (!$user) {
            $cache[$user_id] = false;
            return false;
        }

        $user_roles = is_array($user->roles) ? array_map('sanitize_key', $user->roles) : [];

        $role_ok = false;
        if (!empty($allowed_roles) && !empty($user_roles)) {
            $role_ok = !empty(array_intersect($allowed_roles, $user_roles));
        }

        $profile_ok = false;
        if (!empty($allowed_profiles)) {
            $profile_ok = $this->user_matches_b2b_profiles($user_id, $user_roles, $allowed_profiles);
        }

        // If both are set: user may match either roles OR profiles.
        $enabled = ($role_ok || $profile_ok);
        $cache[$user_id] = $enabled;
        return $enabled;
    }

    protected function user_matches_b2b_profiles(int $user_id, array $user_roles, array $allowed_profile_ids): bool {
        if ($user_id <= 0 || empty($allowed_profile_ids)) {
            return false;
        }

        if (!class_exists('HB\\UCS\\Modules\\B2B\\Storage\\ProfilesStore')) {
            return false;
        }

        static $all_profiles = null;
        if ($all_profiles === null) {
            $opt = get_option('hb_ucs_b2b_profiles', []);
            $all_profiles = is_array($opt) ? $opt : [];
        }

        $resolve_id = static function (string $profile_id, array $all): string {
            if ($profile_id === '' || $profile_id === 'new') return $profile_id;
            if (isset($all[$profile_id])) return $profile_id;
            $lower = strtolower($profile_id);
            if (isset($all[$lower])) return $lower;
            foreach ($all as $key => $_) {
                $key = (string) $key;
                if (strtolower($key) === $lower) return $key;
            }
            return $profile_id;
        };

        foreach ($allowed_profile_ids as $pid_raw) {
            $pid_raw = (string) $pid_raw;
            if ($pid_raw === '') continue;

            $pid = $resolve_id($pid_raw, $all_profiles);
            $p = $all_profiles[$pid] ?? null;
            if (!is_array($p)) {
                continue;
            }

            $linked_users = array_map('intval', (array) ($p['linked_users'] ?? []));
            if (!empty($linked_users) && in_array($user_id, $linked_users, true)) {
                return true;
            }

            $linked_roles = array_values(array_filter(array_map('sanitize_key', (array) ($p['linked_roles'] ?? []))));
            if (!empty($linked_roles) && !empty($user_roles) && !empty(array_intersect($linked_roles, $user_roles))) {
                return true;
            }
        }

        return false;
    }

    /* -----------------------------
     * Frontend / Admin velden
     * ----------------------------- */
    public function render_account_field(): void {
        $user_id = get_current_user_id();
        if (!$user_id) return;
        if (!$this->is_enabled_for_user_id((int) $user_id)) return;
        $val = get_user_meta($user_id, self::META_KEY, true);
        echo '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">';
        echo '<label for="hb_invoice_email">' . esc_html__('Factuur e-mailadres (optioneel)', 'hb-ucs') . '</label>';
        echo '<input type="email" class="woocommerce-Input woocommerce-Input--email input-text" name="hb_invoice_email" id="hb_invoice_email" value="' . esc_attr($val) . '" placeholder="facturen@voorbeeld.nl">';
        echo '<span class="description">' . esc_html__('We sturen de PDF-factuur (en indien beschikbaar de UBL) na afronding naar dit adres. Laat leeg om je reguliere e-mailadres te gebruiken.', 'hb-ucs') . '</span>';
        echo '</p>';
    }

    public function save_account_field(int $user_id): void {
        if (!$user_id) return;
        if (!$this->is_enabled_for_user_id((int) $user_id)) return;
        $email = isset($_POST['hb_invoice_email']) ? sanitize_email(wp_unslash($_POST['hb_invoice_email'])) : '';
        if ($email && !is_email($email)) {
            wc_add_notice(__('Ongeldig factuur e-mailadres.', 'hb-ucs'), 'error');
            return;
        }
        if ($email) update_user_meta($user_id, self::META_KEY, $email);
        else delete_user_meta($user_id, self::META_KEY);
    }

    public function render_admin_profile_field(\WP_User $user): void {
        if (!$this->is_enabled_for_user_id((int) $user->ID)) {
            return;
        }
        $val = get_user_meta($user->ID, self::META_KEY, true);
        echo '<h2>' . esc_html__('HB Commerce – Factuur e-mailadres', 'hb-ucs') . '</h2>';
        echo '<table class="form-table"><tr>';
        echo '<th><label for="hb_invoice_email">' . esc_html__('Factuur e-mailadres', 'hb-ucs') . '</label></th>';
        echo '<td><input type="email" name="hb_invoice_email" id="hb_invoice_email" value="' . esc_attr($val) . '" class="regular-text" placeholder="facturen@voorbeeld.nl">';
        echo '<p class="description">' . esc_html__('Alleen de factuur gaat naar dit adres; overige mails blijven naar het reguliere e-mailadres gaan.', 'hb-ucs') . '</p>';
        echo '</td></tr></table>';
    }

    public function save_admin_profile_field(int $user_id): void {
        if (!current_user_can('edit_user', $user_id)) return;
        if (!$this->is_enabled_for_user_id((int) $user_id)) return;
        $email = isset($_POST['hb_invoice_email']) ? sanitize_email(wp_unslash($_POST['hb_invoice_email'])) : '';
        if ($email && !is_email($email)) return;
        if ($email) update_user_meta($user_id, self::META_KEY, $email);
        else delete_user_meta($user_id, self::META_KEY);
    }

    /* -----------------------------
     * Helpers
     * ----------------------------- */
    protected function get_invoice_email_for_order(\WC_Order $order): string {
        $user_id = $order->get_user_id();
        if ($user_id && $this->is_enabled_for_user_id((int) $user_id)) {
            $custom = (string) get_user_meta($user_id, self::META_KEY, true);
            if ($custom && is_email($custom)) return $custom;
        }
        return '';
    }

    protected function build_file_base($document, \WC_Order $order): array {
        // Gebruik GEFORMATTEERD nummer (prefix HB…)
        $invoice_number = '';
        if (is_object($document) && method_exists($document, 'get_number')) {
            $numObj = $document->get_number();
            if ($numObj) {
                if (method_exists($numObj, 'get_formatted')) {
                    $invoice_number = (string) $numObj->get_formatted();
                } elseif (method_exists($numObj, 'get_plain')) {
                    $invoice_number = (string) $numObj->get_plain();
                }
            }
        }
        if ($invoice_number === '') {
            $invoice_number = (string) $order->get_order_number();
        }

        $upload = wp_upload_dir();
        $dir = trailingslashit($upload['basedir']).'hb-ucs-invoices';
        if (!file_exists($dir)) wp_mkdir_p($dir);

        $base = 'factuur-'.sanitize_file_name($invoice_number);

        // Respecteer evt. PIPFS bestandsnaamfilter
        if (has_filter('wpo_wcpdf_filename')) {
            $maybe = apply_filters('wpo_wcpdf_filename', $base, 'invoice', $document);
            if (is_string($maybe) && $maybe !== '') {
                $base = $maybe;
            }
        }
        return [$dir, $base];
    }

    protected function get_ubl_path_for_order(\WC_Order $order, $base_name = ''): string {
        $uploads  = wp_upload_dir();
        $roots    = [
            trailingslashit($uploads['basedir']).'wpo_wcpdf/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/attachments/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/invoices/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/ubl/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/UBL/',
            trailingslashit($uploads['basedir']).'hb-ucs-invoices/',
        ];
        $order_no = preg_replace('~[^0-9A-Za-z_-]+~', '', (string) $order->get_order_number());
        $candidates = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) continue;
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            foreach ($it as $fileInfo) {
                if (!$fileInfo->isFile()) continue;
                $basename = $fileInfo->getBasename();
                $ext = strtolower(pathinfo($basename, PATHINFO_EXTENSION));
                if (!in_array($ext, ['xml','zip'], true)) continue;
                $lc = strtolower($basename);
                if (
                    ($base_name && strpos($lc, strtolower($base_name)) === 0) ||
                    (strpos($lc, strtolower($order_no)) !== false) ||
                    (strpos($lc, 'ubl') !== false)
                ) {
                    $candidates[] = $fileInfo->getPathname();
                }
            }
        }
        usort($candidates, fn($a,$b)=>filemtime($b)<=>filemtime($a));
        return $candidates[0] ?? '';
    }

    /* -----------------------------
     * PIPFS bijlagelogica – standaard mails
     * ----------------------------- */

    // Blokkeer ALLE PIPFS documenttypes voor klantmails als er een factuuradres is
    public function maybe_block_all_pipfs_docs_for_customer_mails($document_types, $email_id, $order) {
        if (!($order instanceof \WC_Order)) return $document_types;
        if (!$this->get_invoice_email_for_order($order)) return $document_types;
        // Alleen voor klantmails ingrijpen
        $customer_email_ids = [
            'customer_completed_order',
            'customer_processing_order',
            'customer_on_hold_order',
            'customer_refunded_order',
            'customer_note',
            'customer_reset_password',
            'customer_new_account',
            'customer_invoice',
            'customer_completed_renewal_order',
            'customer_processing_renewal_order',
        ];
        if (in_array($email_id, $customer_email_ids, true)) {
            return []; // niets meesturen
        }
        return $document_types;
    }

    // Extra guard per document type
    public function maybe_block_pipfs_attachment_condition($allowed, $order, $email_id, $document_type) {
        if (!($order instanceof \WC_Order)) return $allowed;
        if (!$this->get_invoice_email_for_order($order)) return $allowed;
        return false; // blokkeer
    }

    // Laatste vangnet: verwijder paden naar wpo_wcpdf uit attachments
    public function strip_pipfs_attachments_from_customer_mails($attachments, $email_id, $order, $sent_to_admin){
        if ($sent_to_admin) return $attachments;
        if (!($order instanceof \WC_Order)) return $attachments;
        if (!$this->get_invoice_email_for_order($order)) return $attachments;

        $customer_email_ids = [
            'customer_completed_order','customer_processing_order','customer_on_hold_order',
            'customer_refunded_order','customer_note','customer_reset_password','customer_new_account',
            'customer_invoice','customer_completed_renewal_order','customer_processing_renewal_order'
        ];
        if (!in_array($email_id, $customer_email_ids, true)) return $attachments;

        $uploads = wp_upload_dir();
        $uploads_base = str_replace('\\','/',trailingslashit($uploads['basedir']));
        $filtered = array_filter((array)$attachments, function($path) use($uploads_base){
            if (!is_string($path)) return true;
            $norm = str_replace('\\','/',$path);
            $in_wcpdf = strpos($norm, $uploads_base.'wpo_wcpdf/') !== false;
            $ext = strtolower(pathinfo($norm, PATHINFO_EXTENSION));
            $is_doc = in_array($ext, ['pdf','xml','zip'], true);
            return !($in_wcpdf && $is_doc);
        });
        return array_values($filtered);
    }

    /* -----------------------------
     * Custom factuurmail naar alternatief e-mailadres
     * ----------------------------- */
    public function mail_invoice_to_alt_email($order_id): void {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $to = $this->get_invoice_email_for_order($order);
        if (!$to) return; // geen alt adres => niets doen, standaard gedrag blijft intact

        if (!function_exists('wcpdf_get_document')) return;

        // 1) Haal factuurdocument op en genereer PDF
        $document = wcpdf_get_document('invoice', $order, true);
        if (!$document) return;
        $pdf_data = $document->get_pdf();
        if (empty($pdf_data)) return;

        // 2) Bepaal bestandsbasis & directory
        [$dir, $base_name] = $this->build_file_base($document, $order);

        // Sla PDF op
        $pdf_file = trailingslashit($dir) . $base_name . '.pdf';
        file_put_contents($pdf_file, $pdf_data);
        $attachments = [$pdf_file];

        // 3) UBL toevoegen
    // 3) UBL toevoegen
    $ubl_path = '';

    //
    // (a) UBL helper van add-on (als die bestaat)
    //
    try {
        if (function_exists('wpo_wcpdf_get_ubl')) {
            $ubl_doc = wpo_wcpdf_get_ubl($order);
            if ($ubl_doc) {
                if (method_exists($ubl_doc, 'get_file')) {
                    $ubl_path = (string) $ubl_doc->get_file();
                } elseif (method_exists($ubl_doc, 'get_path')) {
                    $ubl_path = (string) $ubl_doc->get_path();
                }
            }
        }
    } catch (\Throwable $e) { /* ignore */ }

    //
    // (b) Document API direct (sommige setups geven XML-string terug)
    //
    if (!$ubl_path && method_exists($document, 'get_ubl')) {
        try {
            $ubl_xml = $document->get_ubl();
            if (is_string($ubl_xml) && strlen($ubl_xml) > 20) {
                $dest = trailingslashit($dir) . $base_name . '.xml'; // bv. factuur-HB2025081318.xml
                file_put_contents($dest, $ubl_xml);
                $ubl_path = $dest;
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    //
    // (c) Forceer PIPFS/UBL om attachments te genereren en pak 'm uit de tmp-map
    //     -> sommige sites schrijven pas NA 'after_attachment_creation'
    //
    if (!$ubl_path && function_exists('WPO_WCPDF')) {
        try {
            // Zorg dat generator draait
            do_action('wpo_wcpdf_before_attachment_creation', $order, 'customer_completed_order', 'invoice');

            // Extra: sommige UBL-addons vullen pas in 'after'
            if (has_action('wpo_wcpdf_after_attachment_creation')) {
                do_action('wpo_wcpdf_after_attachment_creation', $order, 'customer_completed_order', 'invoice');
            }

            // Geef het FS-event een fractie tijd om te schrijven
            usleep(200000); // 0.2s

            // Scan zowel tmp-root als 'attachments' submap
            $tmp_base = WPO_WCPDF()->main->get_tmp_path();                // bv. wp-content/uploads/wpo_wcpdf/tmp/
            $tmp_att  = WPO_WCPDF()->main->get_tmp_path('attachments');   // bv. .../tmp/attachments/
            $scan_dirs = array_values(array_filter([$tmp_att, $tmp_base]));

            $order_no = preg_replace('~[^0-9A-Za-z_-]+~', '', (string)$order->get_order_number());
            $candidates = [];

            foreach ($scan_dirs as $scan) {
                if (!is_dir($scan)) continue;
                foreach (glob(trailingslashit($scan).'*.{xml,XML,zip,ZIP}', GLOB_BRACE) ?: [] as $f) {
                    $name = basename($f);
                    $lc   = strtolower($name);

                    // 1) exact jouw gewenste naam eerst
                    if ($lc === strtolower($base_name . '.xml') || $lc === strtolower($base_name . '.zip')) {
                        $candidates[] = $f;
                        continue;
                    }
                    // 2) anders: match op base_name / ordernummer / 'ubl'
                    if (
                        strpos($lc, strtolower($base_name)) !== false ||
                        strpos($lc, strtolower($order_no)) !== false ||
                        strpos($lc, 'ubl') !== false
                    ) {
                        $candidates[] = $f;
                    }
                }
            }

            // meest recente eerst
            usort($candidates, fn($a,$b) => filemtime($b) <=> filemtime($a));

            if (!empty($candidates)) {
                $src = $candidates[0];
                $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
                // hernoem naar exact jouw bestandsnaam
                $dest = trailingslashit($dir) . $base_name . '.' . $ext;
                // kopiëren i.p.v. verplaatsen (tmp kan ondertussen opgeschoond worden)
                @copy($src, $dest);
                if (file_exists($dest)) {
                    $ubl_path = $dest;
                }
            }
        } catch (\Throwable $e) { /* ignore */ }
    }

    //
    // (d) Laatste redmiddel: recursief zoeken in uploads (wpo_wcpdf/** & hb-ucs-invoices/)
    //
    if (!$ubl_path) {
        $ubl_path = $this->get_ubl_path_for_order($order, $base_name);
    }

    if ($ubl_path && file_exists($ubl_path)) {
        $attachments[] = $ubl_path;
    }


        // 4) Woo‑stijl mail met header/footer, zonder bestelregels
        $mailer  = WC()->mailer();
        $heading = __('Uw factuur', 'hb-ucs');

        $order_date = wc_format_datetime($order->get_date_created());
        $body  = '<p>' . esc_html__('Beste klant,', 'hb-ucs') . '</p>';
        $body .= '<p>' . esc_html__('In de bijlage vindt u de factuur van uw recente bestelling.', 'hb-ucs') . '</p>';
        $body .= '<p><strong>' . esc_html__('Ordernummer:', 'hb-ucs') . '</strong> ' . esc_html($order->get_order_number()) . '<br>';
        $body .= '<strong>' . esc_html__('Orderdatum:', 'hb-ucs') . '</strong> ' . esc_html($order_date) . '</p>';
        if (count($attachments) > 1) {
            $body .= '<p>' . esc_html__('We hebben tevens een UBL-bestand meegestuurd voor uw administratie.', 'hb-ucs') . '</p>';
        }
        $body .= '<p>' . esc_html__('Met vriendelijke groet,', 'hb-ucs') . '<br>' . esc_html(get_bloginfo('name')) . '</p>';

        $message = method_exists($mailer, 'wrap_message') ? $mailer->wrap_message($heading, $body) : ('<h2>'.esc_html($heading).'</h2>'.$body);
        $subject = sprintf(__('Uw factuur voor bestelling %s', 'hb-ucs'), $order->get_order_number());
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        wp_mail($to, $subject, $message, $headers, $attachments);
    }
}
