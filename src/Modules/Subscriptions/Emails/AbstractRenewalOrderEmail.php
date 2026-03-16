<?php
namespace HB\UCS\Modules\Subscriptions\Emails;

if (!defined('ABSPATH')) {
    exit;
}

abstract class AbstractRenewalOrderEmail extends \WC_Email {
    protected string $trigger_action = '';
    protected string $default_subject_text = '';
    protected string $default_heading_text = '';
    protected string $intro_text = '';

    public function __construct() {
        $this->customer_email = true;
        $this->template_html = 'emails/hb-ucs-renewal-order.php';
        $this->template_plain = 'emails/plain/hb-ucs-renewal-order.php';
        $this->template_base = trailingslashit(dirname(__FILE__, 5)) . 'templates/';

        parent::__construct();

        if ($this->trigger_action !== '') {
            add_action($this->trigger_action, [$this, 'trigger'], 10, 2);
        }
    }

    public function trigger($orderId, $subscriptionId = 0): void {
        if (!$orderId) {
            return;
        }

        $this->setup_locale();

        $order = wc_get_order((int) $orderId);
        if (!$order || (string) $order->get_meta('_hb_ucs_subscription_renewal', true) !== '1') {
            $this->restore_locale();
            return;
        }

        $this->object = $order;
        $this->recipient = $order->get_billing_email();
        $this->placeholders['{order_date}'] = $order->get_date_created() ? wc_format_datetime($order->get_date_created()) : '';
        $this->placeholders['{order_number}'] = $order->get_order_number();

        if (!$this->is_enabled() || !$this->get_recipient()) {
            $this->restore_locale();
            return;
        }

        $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        $this->restore_locale();
    }

    public function get_default_subject(): string {
        return $this->default_subject_text;
    }

    public function get_default_heading(): string {
        return $this->default_heading_text;
    }

    public function get_default_additional_content(): string {
        return $this->intro_text;
    }

    public function get_content_html(): string {
        return wc_get_template_html($this->template_html, [
            'order' => $this->object,
            'email_heading' => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
            'plain_text' => false,
            'email' => $this,
        ], '', $this->template_base);
    }

    public function get_content_plain(): string {
        return wc_get_template_html($this->template_plain, [
            'order' => $this->object,
            'email_heading' => $this->get_heading(),
            'additional_content' => $this->get_additional_content(),
            'sent_to_admin' => false,
            'plain_text' => true,
            'email' => $this,
        ], '', $this->template_base);
    }
}
