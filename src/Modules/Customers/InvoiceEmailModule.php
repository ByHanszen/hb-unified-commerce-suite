<?php
namespace HB\UCS\Modules\Customers;

if (!defined('ABSPATH')) {
    exit;
}

class InvoiceEmailModule {
    public const META_KEY = '_hb_invoice_email';
    private const OPT_SETTINGS = 'hb_ucs_invoice_email_settings';
    private const LAST_ATTEMPT = '_hb_invoice_email_last_attempt';
    private const ATTEMPT_PREFIX = '_hb_invoice_email_attempt_';
    private const CLEANUP_HOOK = 'hb_ucs_invoice_email_cleanup';

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
        add_filter('woocommerce_mail_callback', [$this, 'route_manual_invoice'], 1000, 2);
        add_action('wp_mail_succeeded', [$this, 'mail_succeeded']);
        add_action('wp_mail_failed', [$this, 'mail_failed']);
        add_action('wp_mail_smtp_mailcatcher_send_after', [$this, 'smtp_mail_finished'], 10, 2);
        add_action('wp_mail_smtp_mailcatcher_send_failed', [$this, 'smtp_mail_failed'], 10, 2);
        add_action(self::CLEANUP_HOOK, [$this, 'cleanup_attempt'], 10, 2);
        add_action('woocommerce_admin_order_data_after_order_details', [$this, 'render_delivery_status']);

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

        // Keep invoices outside the web root and restrict filesystem access.
        $temporary_root = '';
        foreach (array_unique([get_temp_dir(), sys_get_temp_dir()]) as $candidate) {
            $candidate = realpath($candidate);
            if (!$candidate || !is_writable($candidate)) continue;
            $public = false;
            foreach ([ABSPATH, defined('WP_CONTENT_DIR') ? WP_CONTENT_DIR : ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $web_root) {
                if ($web_root === '') continue;
                $web_root = realpath($web_root) ?: $web_root;
                if (strpos(trailingslashit($candidate), trailingslashit($web_root)) === 0) $public = true;
            }
            if (!$public) { $temporary_root = $candidate; break; }
        }
        if ($temporary_root === '') throw new \RuntimeException('Geen beschrijfbare tijdelijke map buiten de webroot beschikbaar.');
        $dir = trailingslashit($temporary_root) . 'hb-ucs-invoices-' . wp_generate_password(24, false, false);
        if (!wp_mkdir_p($dir)) throw new \RuntimeException('Tijdelijke factuurmap kon niet worden aangemaakt.');
        @chmod($dir, 0700);

        $base = 'factuur-'.sanitize_file_name($invoice_number);

        // Respecteer evt. PIPFS bestandsnaamfilter
        if (has_filter('wpo_wcpdf_filename')) {
            $maybe = apply_filters('wpo_wcpdf_filename', $base, 'invoice', $document);
            if (is_string($maybe) && $maybe !== '') {
                $base = $maybe;
            }
        }
        return [$dir, sanitize_file_name(basename($base))];
    }

    protected function ubl_xml_matches_order(string $xml, \WC_Order $order, $document = null): bool {
        if ($xml === '' || strlen($xml) > 10 * MB_IN_BYTES || stripos($xml, '<!DOCTYPE') !== false || !class_exists('DOMDocument')) return false;
        $previous = libxml_use_internal_errors(true);
        try {
            $dom = new \DOMDocument();
            if (!$dom->loadXML($xml, LIBXML_NONET) || !$dom->documentElement || !in_array($dom->documentElement->localName, ['Invoice', 'CreditNote'], true)) return false;
            $xpath = new \DOMXPath($dom);
            $references = $xpath->query('/*/*[local-name()="OrderReference"]/*[local-name()="ID"]');
            $orderIds = [(string) $order->get_id(), (string) $order->get_order_number()];
            if ($references->length > 0) {
                foreach ($references as $reference) {
                    if (!in_array(trim($reference->textContent), $orderIds, true)) return false;
                }
                return true;
            }
            // Some UBL exports omit OrderReference but carry the exact invoice ID.
            $numbers = [];
            if ($document && method_exists($document, 'get_number')) {
                $number = $document->get_number();
                foreach (['get_formatted', 'get_plain'] as $method) {
                    if (is_object($number) && method_exists($number, $method)) $numbers[] = (string) $number->$method();
                }
            }
            $invoiceId = trim((string) $xpath->evaluate('string(/*/*[local-name()="ID"][1])'));
            return $invoiceId !== '' && in_array($invoiceId, $numbers, true);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    protected function ubl_file_matches_order(string $file, \WC_Order $order, $document = null): bool {
        if (!is_file($file) || !is_readable($file) || filesize($file) > 10 * MB_IN_BYTES) return false;
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($extension === 'xml') return $this->ubl_xml_matches_order((string) file_get_contents($file), $order, $document);
        if ($extension !== 'zip' || !class_exists('ZipArchive')) return false;
        $zip = new \ZipArchive();
        if ($zip->open($file) !== true) return false;
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > 50) return false;
            $count = 0;
            $size = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $entry = $zip->statIndex($index);
                if (!$entry) return false;
                if (substr($entry['name'], -1) === '/') continue;
                // Every attachment inside the archive must belong to this order.
                $size += (int) $entry['size'];
                if ($size > 10 * MB_IN_BYTES || strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)) !== 'xml') return false;
                $xml = $zip->getFromIndex($index);
                if (!is_string($xml) || !$this->ubl_xml_matches_order($xml, $order, $document)) return false;
                $count++;
            }
            return $count > 0;
        } finally {
            $zip->close();
        }
    }

    protected function get_ubl_path_for_order(\WC_Order $order, $base_name = '', $document = null): string {
        $uploads  = wp_upload_dir();
        $roots    = [
            trailingslashit($uploads['basedir']).'wpo_wcpdf/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/attachments/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/invoices/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/ubl/',
            trailingslashit($uploads['basedir']).'wpo_wcpdf/UBL/',
            trailingslashit($uploads['basedir']).'hb-ucs-invoices/',
        ];
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
                if ($this->ubl_file_matches_order($fileInfo->getPathname(), $order, $document)) {
                    $candidates[] = $fileInfo->getPathname();
                }
            }
        }
        $candidates = array_values(array_unique($candidates));
        usort($candidates, static function ($a, $b) use ($base_name) {
            $exactA = pathinfo($a, PATHINFO_FILENAME) === $base_name;
            $exactB = pathinfo($b, PATHINFO_FILENAME) === $base_name;
            return ($exactB <=> $exactA) ?: (filemtime($b) <=> filemtime($a));
        });
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
            'customer_renewal_invoice',
            'customer_on_hold_renewal_order',
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
        $types = $this->maybe_block_all_pipfs_docs_for_customer_mails(['invoice'], $email_id, $order);
        return empty($types) ? false : $allowed;
    }

    // Laatste vangnet: verwijder paden naar wpo_wcpdf uit attachments
    public function strip_pipfs_attachments_from_customer_mails($attachments, $email_id, $order, $email){
        if ($email === true || ($email instanceof \WC_Email && !$email->is_customer_email())) return $attachments;
        if (!($order instanceof \WC_Order)) return $attachments;
        if (!$this->get_invoice_email_for_order($order)) return $attachments;

        $customer_email_ids = [
            'customer_completed_order','customer_processing_order','customer_on_hold_order',
            'customer_refunded_order','customer_note','customer_reset_password','customer_new_account',
            'customer_invoice','customer_renewal_invoice','customer_on_hold_renewal_order','customer_completed_renewal_order','customer_processing_renewal_order'
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
    /** Replace only WooCommerce's manual customer invoice, never order notifications. */
    public function route_manual_invoice($callback, $email) {
        if (!($email instanceof \WC_Email) || $email->id !== 'customer_invoice'
            || !($email->object instanceof \WC_Order)
            || !$this->get_invoice_email_for_order($email->object)) {
            return $callback;
        }
        $order_id = $email->object->get_id();
        return function (...$unused) use ($order_id): bool {
            return $this->send_invoice($order_id, true);
        };
    }

    public function mail_invoice_to_alt_email($order_id): void {
        $this->send_invoice((int) $order_id, false);
    }

    protected function send_invoice(int $order_id, bool $manual): bool {
        // A connection-scoped database lock prevents simultaneous status changes
        // or two admin requests from sending the same pending invoice twice.
        $result = \HB\UCS\Core\DatabaseLock::run('invoice-email-' . $order_id, function () use ($order_id, $manual) {
            $order = wc_get_order($order_id);
            if (!$order) return false;
            $to = $this->get_invoice_email_for_order($order);
            if (!$to) return false;
            $last = (string) $order->get_meta(self::LAST_ATTEMPT, true);
            $previous = $this->get_attempt($order_id, $last);
            $status = $previous['status'] ?? '';
            // A pending transport may still deliver: do not generate a second mail.
            if (in_array($status, ['sending', 'pending'], true)) return false;
            if (!$manual && $status !== '') return false;

            $attempt = wp_generate_uuid4();
            $order->update_meta_data(self::LAST_ATTEMPT, $attempt);
            $order->update_meta_data(self::ATTEMPT_PREFIX . $attempt, [
                'status' => 'sending', 'recipient' => $to,
                'created_at' => time(), 'updated_at' => time(),
                'origin' => $manual ? 'woocommerce_manual' : 'completed',
            ]);
            $order->save();
            // Also recovers interrupted PHP requests. Files are never kept forever
            // after a third-party queue short-circuits wp_mail().
            if (!wp_schedule_single_event(time() + 7 * DAY_IN_SECONDS, self::CLEANUP_HOOK, [$order_id, $attempt])) {
                $this->finish_attempt($order_id, $attempt, 'failed', 'cleanup_schedule_failed', 'Opruimtaak kon niet worden gepland; verzending afgebroken.');
                return false;
            }
            return $this->deliver_invoice($order, $to, $attempt);
        });
        return $result === true;
    }

    protected function get_attempt(int $order_id, string $attempt): array {
        if ($attempt === '') return [];
        $order = wc_get_order($order_id);
        $state = $order ? $order->get_meta(self::ATTEMPT_PREFIX . $attempt, true) : [];
        return is_array($state) ? $state : [];
    }

    protected function update_attempt(int $order_id, string $attempt, array $changes): void {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $state = $order->get_meta(self::ATTEMPT_PREFIX . $attempt, true);
        if (!is_array($state) || !$state) return;
        $order->update_meta_data(self::ATTEMPT_PREFIX . $attempt, array_merge($state, $changes, ['updated_at' => time()]));
        $order->save();
    }

    protected function safe_error(string $message): string {
        $message = wp_strip_all_tags($message);
        // Provider exceptions can contain request URLs, tokens or email addresses.
        $message = preg_replace('~https?://\S+~i', '[URL]', $message);
        $message = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[e-mailadres]', $message);
        $message = preg_replace('/Bearer\s+[^\s,;]+/i', 'Bearer [verwijderd]', $message);
        $message = preg_replace('/["\']?(?:access_token|refresh_token|client_secret|authorization|password|api[_-]?key)["\']?\s*[:=]\s*(?:"[^"]*"|\'[^\']*\'|[^\s,;]+)/i', '[geheim verwijderd]', $message);
        return substr(sanitize_text_field($message), 0, 1000);
    }

    protected function finish_attempt(int $order_id, string $attempt, string $status, string $code = '', string $message = ''): void {
        $state = $this->get_attempt($order_id, $attempt);
        if (!$state || ($state['status'] ?? '') === $status) return;
        // Duplicate transport hooks must not overwrite a confirmed successful send.
        if (($state['status'] ?? '') === 'accepted') return;
        $code = sanitize_key($code);
        $message = $this->safe_error($message);
        $this->update_attempt($order_id, $attempt, ['status' => $status, 'error_code' => $code, 'error_message' => $message]);
        $labels = $this->status_labels();
        $note = 'HB factuurmail: ' . ($labels[$status] ?? $status) . ' (' . $attempt . ').';
        if ($code !== '') $note .= ' ' . $code . ': ' . $message;
        $order = wc_get_order($order_id);
        if ($order) $order->add_order_note($note);
        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log(in_array($status, ['failed', 'uncertain'], true) ? 'error' : 'info', $note, [
                'source' => 'hb-ucs-invoice-email', 'order_id' => $order_id, 'attempt_id' => $attempt,
            ]);
        }
    }

    protected function status_labels(): array {
        return [
            'sending' => __('Verzending gestart', 'hb-ucs'),
            'pending' => __('In wachtrij; mailverwerking nog niet bevestigd', 'hb-ucs'),
            'accepted' => __('Door mailserver geaccepteerd; aflevering niet bevestigd', 'hb-ucs'),
            'failed' => __('Verzending mislukt', 'hb-ucs'),
            'uncertain' => __('Uitkomst onzeker; controleer het maillog vóór opnieuw verzenden', 'hb-ucs'),
        ];
    }

    /** Correlate callbacks across requests; never match by recipient or subject. */
    protected function mail_identity($headers): array {
        if (is_string($headers)) $headers = preg_split('/\r?\n/', $headers);
        foreach ((array) $headers as $name => $header) {
            // PHPMailer getCustomHeaders() returns [name, value] tuples.
            if (is_array($header)) $header = implode(': ', $header);
            elseif (is_string($name) && is_string($header)) $header = $name . ': ' . $header;
            if (is_string($header) && preg_match('/^X-HB-Invoice-Attempt:\s*(\d+):([a-f0-9-]{36})\s*$/i', $header, $match)) {
                return [(int) $match[1], strtolower($match[2])];
            }
        }
        return [];
    }

    public function mail_succeeded($data): void {
        $identity = $this->mail_identity($data['headers'] ?? []);
        if ($identity) $this->finish_attempt($identity[0], $identity[1], 'accepted');
    }

    public function mail_failed($error): void {
        if (!is_wp_error($error)) return;
        $data = $error->get_error_data();
        $identity = $this->mail_identity(is_array($data) ? ($data['headers'] ?? []) : []);
        if ($identity) $this->record_transport_error($identity, (string) $error->get_error_code(), $error->get_error_message());
    }

    public function smtp_mail_finished($mailer, $catcher): void {
        if (!is_object($catcher) || !method_exists($catcher, 'getCustomHeaders')) return;
        $identity = $this->mail_identity($catcher->getCustomHeaders());
        if ($identity && is_object($mailer) && method_exists($mailer, 'is_email_sent') && $mailer->is_email_sent() === true) {
            $this->finish_attempt($identity[0], $identity[1], 'accepted');
        }
    }

    public function smtp_mail_failed($message, $catcher): void {
        if (!is_object($catcher) || !method_exists($catcher, 'getCustomHeaders')) return;
        $identity = $this->mail_identity($catcher->getCustomHeaders());
        if ($identity) $this->record_transport_error($identity, 'mailer_error', (string) $message);
    }

    protected function record_transport_error(array $identity, string $code, string $message): void {
        // A timeout does not prove that the provider rejected the message. An
        // automatic retry could deliver duplicates, so leave that choice to admin.
        $uncertain = preg_match('/timeout|timed out|curl error 28|\b50[234]\b/i', $message);
        $this->finish_attempt($identity[0], $identity[1], $uncertain ? 'uncertain' : 'failed', $code, $message);
    }

    public function cleanup_attempt($order_id, $attempt): void {
        $order_id = (int) $order_id;
        $attempt = (string) $attempt;
        $state = $this->get_attempt($order_id, $attempt);
        if (!$state) return;
        if (in_array($state['status'] ?? '', ['sending', 'pending'], true)) {
            if (time() - (int) $state['created_at'] < 7 * DAY_IN_SECONDS) return;
            $this->finish_attempt($order_id, $attempt, 'uncertain', 'transport_unconfirmed', 'Binnen zeven dagen geen definitieve mailbevestiging ontvangen. Controleer het maillog.');
        }
        $dir = $state['directory'] ?? '';
        // Never remove arbitrary directories, symlinks or third-party UBL sources.
        if (is_string($dir) && preg_match('/^hb-ucs-invoices-[A-Za-z0-9]{24}$/', basename($dir)) && is_dir($dir) && !is_link($dir)) {
            foreach (glob(trailingslashit($dir) . '*') ?: [] as $file) {
                if (is_file($file) || is_link($file)) wp_delete_file($file);
            }
            @rmdir($dir);
        }
        $this->update_attempt($order_id, $attempt, ['directory' => '']);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK, [$order_id, $attempt]);
    }

    public function render_delivery_status($order): void {
        if (!($order instanceof \WC_Order)) return;
        $attempt = (string) $order->get_meta(self::LAST_ATTEMPT, true);
        $state = $this->get_attempt($order->get_id(), $attempt);
        if (!$state) return;
        $labels = $this->status_labels();
        echo '<p class="form-field form-field-wide"><strong>' . esc_html__('HB factuurmail', 'hb-ucs') . '</strong><br>';
        echo esc_html($labels[$state['status']] ?? $state['status']);
        echo '<br>' . esc_html($state['recipient'] ?? '');
        if (!empty($state['error_message'])) echo '<br>' . esc_html(($state['error_code'] ?? '') . ': ' . $state['error_message']);
        echo '<br><small>' . esc_html__('Opnieuw verzenden: gebruik de normale WooCommerce-actie voor factuur/bestelgegevens naar de klant.', 'hb-ucs') . '</small></p>';
    }

    protected function deliver_invoice(\WC_Order $order, string $to, string $attempt): bool {
        $dir = '';
        try {
        if (!function_exists('wcpdf_get_document')) {
            throw new \RuntimeException('PDF-factuurplugin is niet beschikbaar.');
        }

        // 1) Haal factuurdocument op en genereer PDF
        $document = wcpdf_get_document('invoice', $order, true);
        if (!$document) throw new \RuntimeException('Factuurdocument kon niet worden aangemaakt.');
        $pdf_data = $document->get_pdf();
        if (empty($pdf_data)) throw new \RuntimeException('De PDF-factuur is leeg.');

        // 2) Bepaal bestandsbasis & directory
        [$dir, $base_name] = $this->build_file_base($document, $order);

        $this->update_attempt($order->get_id(), $attempt, ['directory' => $dir]);
        // Sla PDF op
        $pdf_file = trailingslashit($dir) . $base_name . '.pdf';
        if (file_put_contents($pdf_file, $pdf_data) !== strlen($pdf_data)) {
            throw new \RuntimeException('PDF-factuur kon niet volledig worden opgeslagen.');
        }
        @chmod($pdf_file, 0600);
        $this->update_attempt($order->get_id(), $attempt, ['directory' => $dir]);
        $attachments = [$pdf_file];

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

            $candidates = [];

            foreach ($scan_dirs as $scan) {
                if (!is_dir($scan)) continue;
                foreach (glob(trailingslashit($scan).'*.{xml,XML,zip,ZIP}', GLOB_BRACE) ?: [] as $f) {
                    if (!$this->ubl_file_matches_order($f, $order, $document)) continue;
                    $candidates[] = $f;
                }
            }

            // meest recente eerst
            usort($candidates, static function ($a, $b) use ($base_name) {
                $exactA = pathinfo($a, PATHINFO_FILENAME) === $base_name;
                $exactB = pathinfo($b, PATHINFO_FILENAME) === $base_name;
                return ($exactB <=> $exactA) ?: (filemtime($b) <=> filemtime($a));
            });

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
        $ubl_path = $this->get_ubl_path_for_order($order, $base_name, $document);
    }

    if ($ubl_path && $this->ubl_file_matches_order($ubl_path, $order, $document)) {
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
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'X-HB-Invoice-Attempt: ' . $order->get_id() . ':' . $attempt,
        ];

        // Only this invoice bypasses WP Mail SMTP's secondary queue. The files
        // stay present until the actual transport finishes, not merely enqueueing.
        $immediate = function ($enqueue, $args) use ($order, $attempt) {
            $identity = $this->mail_identity($args['headers'] ?? []);
            return $identity === [$order->get_id(), $attempt] ? false : $enqueue;
        };
        add_filter('wp_mail_smtp_mail_catcher_send_enqueue_email', $immediate, PHP_INT_MAX, 2);
        try {
            $result = wp_mail($to, $subject, $message, $headers, $attachments);
        } finally {
            remove_filter('wp_mail_smtp_mail_catcher_send_enqueue_email', $immediate, PHP_INT_MAX);
        }
        $state = $this->get_attempt($order->get_id(), $attempt);
        if (($state['status'] ?? '') === 'sending') {
            // A true return can also mean a third-party queue accepted the job.
            // Only a transport callback is allowed to mark the attempt accepted.
            $this->finish_attempt($order->get_id(), $attempt, $result ? 'pending' : 'failed',
                $result ? 'awaiting_transport' : 'wp_mail_false',
                $result ? 'Wacht op bevestiging van de mailverwerking.' : 'wp_mail() gaf false terug zonder verdere foutinformatie.');
        }
        return ($this->get_attempt($order->get_id(), $attempt)['status'] ?? '') === 'accepted';
        } catch (\Throwable $e) {
            $this->finish_attempt($order->get_id(), $attempt, 'failed', 'invoice_exception', $e->getMessage());
            return false;
        } finally {
            $state = $this->get_attempt($order->get_id(), $attempt);
            if (in_array($state['status'] ?? '', ['accepted', 'failed', 'uncertain'], true)) {
                $this->cleanup_attempt($order->get_id(), $attempt);
            }
        }
    }
}
