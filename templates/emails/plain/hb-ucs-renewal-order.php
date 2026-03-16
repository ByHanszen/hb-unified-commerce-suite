<?php
if (!defined('ABSPATH')) {
    exit;
}

echo '= ' . wp_strip_all_tags($email_heading) . " =\n\n";
if (!empty($additional_content)) {
    echo wp_strip_all_tags((string) $additional_content) . "\n\n";
}
if ($order instanceof WC_Order) {
    echo sprintf("%s: #%s\n", __('Bestelling', 'hb-ucs'), $order->get_order_number());
    if ($order->get_date_created()) {
        echo sprintf("%s: %s\n", __('Datum', 'hb-ucs'), wc_format_datetime($order->get_date_created()));
    }
    echo sprintf("%s: %s\n", __('Totaal', 'hb-ucs'), wp_strip_all_tags($order->get_formatted_order_total()));
    echo "\n";
    do_action('woocommerce_email_order_details', $order, false, true, $email);
    do_action('woocommerce_email_order_meta', $order, false, true, $email);
    do_action('woocommerce_email_customer_details', $order, false, true, $email);
}
