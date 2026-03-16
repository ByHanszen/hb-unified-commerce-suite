<?php
if (!defined('ABSPATH')) {
    exit;
}

do_action('woocommerce_email_header', $email_heading, $email);
?>
<p>
	<?php echo wp_kses_post(wpautop(wptexturize((string) $additional_content))); ?>
</p>
<?php
if ($order instanceof WC_Order) {
    do_action('woocommerce_email_order_details', $order, false, false, $email);
    do_action('woocommerce_email_order_meta', $order, false, false, $email);
    do_action('woocommerce_email_customer_details', $order, false, false, $email);
}

do_action('woocommerce_email_footer', $email);
