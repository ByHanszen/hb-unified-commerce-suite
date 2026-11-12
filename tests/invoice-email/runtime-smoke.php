<?php
/** No WordPress, network, or real email required: php tests/invoice-email/runtime-smoke.php */
if (PHP_SAPI !== 'cli') exit;
define('ABSPATH', dirname(__DIR__, 2) . '/');
define('DAY_IN_SECONDS', 86400);
$GLOBALS['hooks'] = $GLOBALS['orders'] = $GLOBALS['events'] = $GLOBALS['mails'] = $GLOBALS['logs'] = [];
$GLOBALS['transport'] = 'success';
$GLOBALS['doc_mode'] = 'pdf';
$GLOBALS['sequence'] = 0;
function add_filter($name, $callback, $priority = 10, $args = 1) { $GLOBALS['hooks'][$name][$priority][] = [$callback, $args]; }
function add_action(...$args) { add_filter(...$args); }
function remove_filter($name, $callback, $priority = 10) {
    foreach ($GLOBALS['hooks'][$name][$priority] ?? [] as $i => $entry) {
        if ($entry[0] === $callback) unset($GLOBALS['hooks'][$name][$priority][$i]);
    }
}
function apply_filters($name, $value, ...$args) {
    $hooks = $GLOBALS['hooks'][$name] ?? []; ksort($hooks);
    foreach ($hooks as $entries) foreach ($entries as [$fn, $count]) $value = $fn(...array_slice([$value, ...$args], 0, $count));
    return $value;
}
function do_action($name, ...$args) {
    $hooks = $GLOBALS['hooks'][$name] ?? []; ksort($hooks);
    foreach ($hooks as $entries) foreach ($entries as [$fn, $count]) $fn(...array_slice($args, 0, $count));
}
function __($s, $domain = '') { return $s; }
function esc_html__($s, $domain = '') { return htmlspecialchars($s); }
function esc_html($s) { return htmlspecialchars((string) $s); }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($s)); }
function sanitize_text_field($s) { return trim(preg_replace('/[\r\n\t]+/', ' ', strip_tags($s))); }
function wp_strip_all_tags($s) { return strip_tags($s); }
function sanitize_file_name($s) { return preg_replace('/[^a-zA-Z0-9.\-]/', '', $s); }
function get_option($k, $default = []) { return $default; }
function get_user_meta($id, $key, $single) { return $GLOBALS['recipients'][$id] ?? ''; }
function is_email($s) { return filter_var($s, FILTER_VALIDATE_EMAIL); }
function trailingslashit($s) { return rtrim($s, '/') . '/'; }
function get_temp_dir() { return sys_get_temp_dir(); }
function wp_generate_password($len, ...$args) { return substr(str_repeat('a', 22) . sprintf('%02d', ++$GLOBALS['sequence']), -$len); }
function wp_generate_uuid4() { return sprintf('00000000-0000-4000-8000-%012d', ++$GLOBALS['sequence']); }
function wp_mkdir_p($p) { return is_dir($p) || mkdir($p, 0700, true); }
function wp_delete_file($p) { unlink($p); }
function wp_upload_dir() { return ['basedir' => '/no-invoice-fixtures-here']; }
function has_filter($hook) { return false; }
function wc_format_datetime($date) { return '2026-11-12'; }
function get_bloginfo($name) { return 'Test shop'; }
function wc_get_order($id) { return $GLOBALS['orders'][(int) $id] ?? false; }
function is_wp_error($e) { return $e instanceof WP_Error; }
function wp_schedule_single_event($at, $hook, $args) { $GLOBALS['events'][json_encode($args)] = [$at, $hook, $args]; return true; }
function wp_clear_scheduled_hook($hook, $args) { unset($GLOBALS['events'][json_encode($args)]); }
function wc_get_logger() { return new class { public function log($level, $message, $context) { $GLOBALS['logs'][] = [$level, $message, $context]; } }; }
class WooCommerce {}
class WC_Email { public $id; public $object; public function is_customer_email() { return true; } }
class WC_Order {
    public $meta = []; public $notes = []; public $id; public $user;
    public function __construct($id, $user) { $this->id = $id; $this->user = $user; $GLOBALS['orders'][$id] = $this; }
    public function get_id() { return $this->id; }
    public function get_user_id() { return $this->user; }
    public function get_order_number() { return (string) $this->id; }
    public function get_date_created() { return new DateTimeImmutable(); }
    public function get_meta($k, $single = true) { return $this->meta[$k] ?? ''; }
    public function update_meta_data($k, $v) { $this->meta[$k] = $v; }
    public function save() {}
    public function add_order_note($n) { $this->notes[] = $n; }
}
class WP_Error {
    private $code; private $msg; private $data;
    public function __construct($code, $msg, $data = []) { $this->code=$code; $this->msg=$msg; $this->data=$data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->msg; }
    public function get_error_data() { return $this->data; }
}
// Exercise the production DatabaseLock, with a controllable competing connection.
$wpdb = new class {
    public $prefix = 'wp_'; public $busy = false;
    public function prepare($query, $key) { return $query; }
    public function get_var($q) { return strpos($q, 'GET_LOCK') !== false && $this->busy ? 0 : 1; }
};
function WC() { return new class { public function mailer() { return new class { public function wrap_message($heading, $body) { return '<h2>'.$heading.'</h2>'.$body; } }; } }; }
function wcpdf_get_document($type, $order, $init) {
    if ($GLOBALS['doc_mode'] === 'missing') return false;
    return new class {
        public function get_pdf() { return $GLOBALS['doc_mode'] === 'empty' ? '' : '%PDF-test'; }
        public function get_number() { return false; }
    };
}
function wp_mail($to, $subject, $message, $headers, $attachments) {
    $args = compact('to','subject','message','headers','attachments');
    foreach ($attachments as $path) check(is_readable($path) && filesize($path) > 0, 'Attachment readable during actual transport');
    check(apply_filters('wp_mail_smtp_mail_catcher_send_enqueue_email', true, $args) === false, 'Only HB invoice bypasses WP Mail SMTP queue');
    check(apply_filters('wp_mail_smtp_mail_catcher_send_enqueue_email', true, ['headers'=>[]]) === true, 'Other mail queue settings preserved');
    $GLOBALS['mails'][] = $args;
    $mode = $GLOBALS['transport'];
    if ($mode === 'throw') throw new RuntimeException('Transport exception');
    if ($mode === 'queued') return true; // a different queue short-circuits before transport
    if ($mode === 'false') return false;
    if ($mode === 'failed' || $mode === 'timeout') {
        do_action('wp_mail_failed', new WP_Error('http_request_failed', $mode === 'timeout' ? 'cURL error 28: Operation timed out after 15002 milliseconds' : 'Provider rejected: password="secret" client_secret=hidden at https://example.test/?token=hidden user@example.test', $args));
        return false;
    }
    if ($mode === 'smtp') {
        $catcher = new class($headers) {
            private $headers;
            public function __construct($headers) { $this->headers=$headers; }
            public function getCustomHeaders() { return array_map(function ($h) { return explode(': ', $h, 2); }, $this->headers); }
        };
        do_action('wp_mail_smtp_mailcatcher_send_after', new class { public function is_email_sent() { return true; } }, $catcher);
    } else do_action('wp_mail_succeeded', array_merge($args, ['headers' => array_column(array_map(function ($h) { return explode(': ', $h, 2); }, $headers), 1, 0)]));
    return true;
}
function check($condition, $message) { if (!$condition) throw new RuntimeException($message); $GLOBALS['checks'] = ($GLOBALS['checks'] ?? 0) + 1; }
function state($o) { return $o->get_meta('_hb_invoice_email_attempt_'.$o->get_meta('_hb_invoice_email_last_attempt')); }
function manual($module, $o) {
    $email = new WC_Email(); $email->id='customer_invoice'; $email->object=$o;
    return apply_filters('woocommerce_mail_callback', 'wp_mail', $email);
}
require dirname(__DIR__, 2) . '/src/Core/DatabaseLock.php';
require dirname(__DIR__, 2) . '/src/Modules/Customers/InvoiceEmailModule.php';
$module = new HB\UCS\Modules\Customers\InvoiceEmailModule(); $module->init();
$GLOBALS['recipients'][7] = 'invoices@example.test';
$order = new WC_Order(12, 7);
do_action('woocommerce_order_status_completed', 12);
check(state($order)['status'] === 'accepted', 'Completion records mailserver acceptance');
check($GLOBALS['mails'][0]['to'] === 'invoices@example.test', 'Completion uses alternative recipient');
check(strpos($GLOBALS['mails'][0]['subject'], 'Uw factuur') !== false, 'Custom invoice subject used');
check(!file_exists($GLOBALS['mails'][0]['attachments'][0]), 'Synchronous success cleans private PDF');
do_action('woocommerce_order_status_completed', 12);
check(count($GLOBALS['mails']) === 1, 'Repeated completion does not duplicate invoice');
$callback = manual($module, $order);
check(is_callable($callback) && $callback !== 'wp_mail', 'Standard WooCommerce manual invoice is replaced');
$callback('billing@example.test', 'standard invoice', 'standard body', [], []);
check(count($GLOBALS['mails']) === 2, 'Explicit resend is allowed after success');
check($GLOBALS['mails'][1]['to'] === 'invoices@example.test' && strpos($GLOBALS['mails'][1]['message'], 'standard body') === false, 'Resend uses custom body and alternative address');
check(state($order)['origin'] === 'woocommerce_manual', 'Manual resend origin recorded');
foreach (['customer_completed_order','customer_processing_order','new_order','customer_renewal_invoice'] as $id) {
    $email = new WC_Email(); $email->id=$id; $email->object=$order;
    check(apply_filters('woocommerce_mail_callback', 'wp_mail', $email) === 'wp_mail', 'Other WooCommerce email remains unchanged: '.$id);
}
$guest = new WC_Order(13, 0);
check(manual($module, $guest) === 'wp_mail', 'Guest without alternative address keeps default invoice');
$normal = new WC_Order(14, 8);
check(manual($module, $normal) === 'wp_mail', 'Account without alternative address keeps default invoice');
$wpdb->busy = true;
$n = count($GLOBALS['mails']); manual($module, $order)();
check(count($GLOBALS['mails']) === $n, 'Competing request cannot send duplicate'); $wpdb->busy=false;
foreach (['failed'=>'failed','timeout'=>'uncertain','false'=>'failed','throw'=>'failed','smtp'=>'accepted'] as $mode=>$expected) {
    $GLOBALS['transport']=$mode; manual($module, $order)();
    check(state($order)['status'] === $expected, 'Transport outcome: '.$mode);
    check(!file_exists(end($GLOBALS['mails'])['attachments'][0]), 'Terminal outcome cleans attachment: '.$mode);
}
$GLOBALS['transport']='failed'; manual($module, $order)();
$error = state($order)['error_message'];
check(strpos($error,'secret') === false && strpos($error,'hidden') === false && strpos($error,'user@example') === false, 'Credentials and private addresses are redacted from stored errors');
check(count($GLOBALS['logs']) > 0 && count($order->notes) > 0, 'Order notes and structured WooCommerce logs recorded');
$GLOBALS['transport']='queued'; manual($module, $order)();
check(state($order)['status'] === 'pending', 'Queue acceptance is not transport success');
$pendingId=$order->get_meta('_hb_invoice_email_last_attempt');
$pendingMail=end($GLOBALS['mails']);
check(is_file($pendingMail['attachments'][0]), 'Queued transport retains attachment');
$n=count($GLOBALS['mails']); manual($module, $order)();
check(count($GLOBALS['mails']) === $n, 'Pending queue prevents duplicate manual resend');
// Simulate a later request's completion hook: header identity finds saved attempt.
$module->mail_succeeded($pendingMail);
check(state($order)['status'] === 'accepted', 'Delayed transport callback updates status');
$module->cleanup_attempt(12,$pendingId);
check(!is_file($pendingMail['attachments'][0]), 'Delayed completion can clean attachment');
// An older callback cannot change the latest attempt's status.
$GLOBALS['transport']='failed'; manual($module,$order)();
$module->mail_succeeded($pendingMail);
check(state($order)['status']==='failed', 'Old callback does not overwrite new attempt');
$GLOBALS['transport']='queued'; manual($module,$order)();
$id=$order->get_meta('_hb_invoice_email_last_attempt'); $pendingMail=end($GLOBALS['mails']);
$s=state($order); $s['created_at']=time()-8*DAY_IN_SECONDS;
$order->update_meta_data('_hb_invoice_email_attempt_'.$id,$s);
$module->cleanup_attempt(12,$id);
check(state($order)['status']==='uncertain' && !is_file($pendingMail['attachments'][0]), 'Stale queue expires as uncertain and cleans files');
foreach (['missing','empty'] as $mode) {
    $GLOBALS['doc_mode']=$mode; $n=count($GLOBALS['mails']); manual($module,$order)();
    check(state($order)['status']==='failed' && count($GLOBALS['mails'])===$n, 'Missing or empty PDF fails explicitly without default email: '.$mode);
}
ob_start(); $module->render_delivery_status($order); $html=ob_get_clean();
check(strpos($html,'HB factuurmail') !== false && strpos($html,'Verzending mislukt') !== false, 'Order editor renders actionable status');
check(apply_filters('wp_mail_smtp_mail_catcher_send_enqueue_email',true,['headers'=>[]])===true,'Temporary queue filter removed even on exceptions');
check(!$GLOBALS['events'], 'All completed/stale attempts clear cleanup events');
echo 'Invoice email smoke tests: OK ('.$GLOBALS['checks']." checks)\n";
