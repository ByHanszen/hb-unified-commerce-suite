<?php
/**
 * Subscription order data admin template.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;

$mollieMeta = $this->get_subscription_mollie_meta_context($subId);
$backendPaymentId = (string) ($mollieMeta['paymentId'] ?? '');
$backendPaymentMode = (string) ($mollieMeta['paymentMode'] ?? '');
$backendCustomerId = (string) ($mollieMeta['customerId'] ?? '');
$backendMandateId = (string) ($mollieMeta['mandateId'] ?? '');
$paymentMethodTitleValue = (string) ($paymentMethodLabel !== '' ? $paymentMethodLabel : '');
$isMandateMethod = strpos((string) $paymentMethod, 'mollie_wc_gateway_') === 0;
?>
<div class="panel-wrap woocommerce">
    <input name="post_title" type="hidden" value="<?php echo esc_attr(get_the_title($subId)); ?>" />
    <input name="post_status" type="hidden" value="<?php echo esc_attr((string) ($post->post_status ?? 'publish')); ?>" />

    <div id="order_data" class="panel woocommerce-order-data">
        <div class="order_data_header">
            <div class="order_data_header_column">
                <h2 class="woocommerce-order-data__heading">
                    <?php echo esc_html(sprintf(__('Abonnement #%d details', 'hb-ucs'), $subId)); ?>
                </h2>
                <p class="woocommerce-order-data__meta order_number">
                    <?php echo wp_kses_post(implode('. ', array_map('esc_html', $metaList))); ?>
                </p>
            </div>
            <div class="order_data_header_column">
                <div class="hb-ucs-subscription-order-data-summary">
                    <span class="hb-ucs-subscription-order-data-summary__item">
                        <?php echo wp_kses_post(function_exists('wc_price') ? wc_price($total) : number_format($total, 2, '.', '')); ?>
                    </span>
                    <span class="hb-ucs-subscription-order-data-summary__item">
                        <?php echo esc_html($scheduleLabel !== '' ? $scheduleLabel : '—'); ?>
                    </span>
                    <span class="hb-ucs-subscription-order-data-summary__item">
                        <?php echo esc_html(sprintf(_n('%d bestelling', '%d bestellingen', $ordersCount, 'hb-ucs'), $ordersCount)); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="order_data_column_container">
            <div class="order_data_column hb-ucs-subscription-general-column">
                <h3><?php esc_html_e('Algemeen', 'hb-ucs'); ?></h3>

                <p class="form-field form-field-wide">
                    <label for="hb_ucs_sub_status"><?php esc_html_e('Status:', 'hb-ucs'); ?></label>
                    <select name="hb_ucs_sub_status" id="hb_ucs_sub_status" class="wc-enhanced-select">
                        <?php foreach ($this->get_subscription_statuses() as $key => $label) : ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="form-field form-field-wide wc-customer-user">
                    <label for="hb_ucs_sub_user_id">
                        <?php esc_html_e('Klant:', 'hb-ucs'); ?>
                        <?php if ($otherSubscriptionsUrl !== '') : ?>
                            <a href="<?php echo esc_url($otherSubscriptionsUrl); ?>"><?php esc_html_e('Andere abonnementen weergeven →', 'hb-ucs'); ?></a>
                        <?php endif; ?>
                        <?php if ($userLink !== '') : ?>
                            <a href="<?php echo esc_url($userLink); ?>"><?php esc_html_e('Profiel →', 'hb-ucs'); ?></a>
                        <?php endif; ?>
                    </label>
                    <select id="hb_ucs_sub_user_id" name="hb_ucs_sub_user_id" class="wc-customer-search" data-placeholder="<?php echo esc_attr__('Gast', 'hb-ucs'); ?>" data-allow_clear="true" style="width:100%;">
                        <?php if ($userId > 0 && $customerLabel !== '') : ?>
                            <option value="<?php echo esc_attr((string) $userId); ?>" selected="selected"><?php echo esc_html($customerLabel); ?></option>
                        <?php endif; ?>
                    </select>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Aangemaakt:', 'hb-ucs'); ?></label>
                    <span><?php echo esc_html($createdDisplay !== '' ? $createdDisplay : '—'); ?></span>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Bovenliggende bestelling:', 'hb-ucs'); ?></label>
                    <?php if ($orderLink !== '') : ?>
                        <a href="<?php echo esc_url($orderLink); ?>">#<?php echo esc_html((string) $parentOrderId); ?></a>
                    <?php else : ?>
                        —
                    <?php endif; ?>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Planning:', 'hb-ucs'); ?></label>
                    <span><?php echo esc_html($scheduleLabel !== '' ? $scheduleLabel : '—'); ?></span>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Laatste bestelling:', 'hb-ucs'); ?></label>
                    <?php if ($lastOrderId > 0) : ?>
                        <a href="<?php echo esc_url(admin_url('post.php?post=' . $lastOrderId . '&action=edit')); ?>">#<?php echo esc_html((string) $lastOrderId); ?></a>
                    <?php else : ?>
                        —
                    <?php endif; ?>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Betaalmethode:', 'hb-ucs'); ?></label>
                    <span><?php echo esc_html($paymentMethodLabel !== '' ? $paymentMethodLabel : '—'); ?></span>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Mollie customerId:', 'hb-ucs'); ?></label>
                    <span><?php echo esc_html($backendCustomerId !== '' ? $backendCustomerId : '—'); ?></span>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Mandate ID:', 'hb-ucs'); ?></label>
                    <span><?php echo esc_html($backendMandateId !== '' ? $backendMandateId : '—'); ?></span>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Mollie payment mode:', 'hb-ucs'); ?></label>
                    <span><?php echo esc_html($backendPaymentMode !== '' ? $backendPaymentMode : '—'); ?></span>
                </p>

                <p class="form-field form-field-wide">
                    <label><?php esc_html_e('Last payment ID:', 'hb-ucs'); ?></label>
                    <span><?php echo esc_html($backendPaymentId !== '' ? $backendPaymentId : '—'); ?></span>
                </p>

                <p class="form-field form-field-wide description">
                    <?php echo esc_html($isMandateMethod
                        ? __('Voor automatische Mollie-incasso zijn minimaal een geldige betaalmethode, Mollie Customer ID en Mollie Mandate ID vereist.', 'hb-ucs')
                        : __('Voor handmatige/offline betaalmethoden zijn Mollie mandate-gegevens niet vereist.', 'hb-ucs')); ?>
                </p>
            </div>

            <div class="order_data_column hb-ucs-subscription-address-column hb-ucs-subscription-billing-column">
                <h3>
                    <?php esc_html_e('Facturering', 'hb-ucs'); ?>
                    <a href="#" class="edit_address"><?php esc_html_e('Bewerken', 'hb-ucs'); ?></a>
                    <span><a href="#" class="load_customer_billing" style="display:none;"><?php esc_html_e('Laad factuuradres', 'hb-ucs'); ?></a></span>
                </h3>

                <div class="address">
                    <?php $this->render_subscription_address_display('billing', $billingAddress); ?>
                    <p><strong><?php esc_html_e('Betaalmethode', 'hb-ucs'); ?>:</strong> <?php echo esc_html($paymentMethodLabel !== '' ? $paymentMethodLabel : '—'); ?></p>
                    <p><strong><?php esc_html_e('Mollie Payment ID', 'hb-ucs'); ?>:</strong> <?php echo esc_html($backendPaymentId !== '' ? $backendPaymentId : '—'); ?></p>
                    <p><strong><?php esc_html_e('Mollie Payment Mode', 'hb-ucs'); ?>:</strong> <?php echo esc_html($backendPaymentMode !== '' ? $backendPaymentMode : '—'); ?></p>
                    <p><strong><?php esc_html_e('Mollie Customer ID', 'hb-ucs'); ?>:</strong> <?php echo esc_html($backendCustomerId !== '' ? $backendCustomerId : '—'); ?></p>
                    <p><strong><?php esc_html_e('Mollie Mandate ID', 'hb-ucs'); ?>:</strong> <?php echo esc_html($backendMandateId !== '' ? $backendMandateId : '—'); ?></p>
                    <?php if ($userLink !== '') : ?>
                        <p><a href="<?php echo esc_url($userLink); ?>"><?php esc_html_e('Klantprofiel openen →', 'hb-ucs'); ?></a></p>
                    <?php endif; ?>
                </div>

                <div class="edit_address" style="display:none;">
                    <?php $this->render_subscription_address_fields('billing', $billingAddress); ?>
                    <p class="form-field form-field-wide">
                        <label for="hb_ucs_sub_payment_method"><strong><?php esc_html_e('Betaalmethode', 'hb-ucs'); ?></strong></label>
                        <select name="hb_ucs_sub_payment_method" id="hb_ucs_sub_payment_method" class="first" style="width:100%;">
                            <option value=""><?php esc_html_e('Kies een betaalmethode…', 'hb-ucs'); ?></option>
                            <?php foreach ($gatewayChoices as $gatewayId => $gatewayTitle) : ?>
                                <option value="<?php echo esc_attr((string) $gatewayId); ?>" <?php selected($paymentMethod, (string) $gatewayId); ?>><?php echo esc_html((string) $gatewayTitle); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="hb_ucs_sub_payment_method_title"><strong><?php esc_html_e('Titel betaalmethode', 'hb-ucs'); ?></strong></label>
                        <input type="text" name="hb_ucs_sub_payment_method_title" id="hb_ucs_sub_payment_method_title" value="<?php echo esc_attr($paymentMethodTitleValue); ?>" style="width:100%;" />
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="hb_ucs_sub_mollie_customer_id"><strong><?php esc_html_e('Mollie Customer ID', 'hb-ucs'); ?></strong></label>
                        <input type="text" name="hb_ucs_sub_mollie_customer_id" id="hb_ucs_sub_mollie_customer_id" value="<?php echo esc_attr($backendCustomerId); ?>" style="width:100%;" />
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="hb_ucs_sub_mollie_mandate_id"><strong><?php esc_html_e('Mollie Mandate ID', 'hb-ucs'); ?></strong></label>
                        <input type="text" name="hb_ucs_sub_mollie_mandate_id" id="hb_ucs_sub_mollie_mandate_id" value="<?php echo esc_attr($backendMandateId); ?>" style="width:100%;" />
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="hb_ucs_sub_last_payment_id"><strong><?php esc_html_e('Mollie Payment ID', 'hb-ucs'); ?></strong></label>
                        <input type="text" name="hb_ucs_sub_last_payment_id" id="hb_ucs_sub_last_payment_id" value="<?php echo esc_attr($backendPaymentId); ?>" style="width:100%;" />
                    </p>
                    <p class="form-field form-field-wide">
                        <label for="hb_ucs_sub_payment_mode"><strong><?php esc_html_e('Mollie Payment Mode', 'hb-ucs'); ?></strong></label>
                        <select name="hb_ucs_sub_payment_mode" id="hb_ucs_sub_payment_mode" style="width:100%;">
                            <option value=""><?php esc_html_e('Automatisch bepalen', 'hb-ucs'); ?></option>
                            <option value="test" <?php selected($backendPaymentMode, 'test'); ?>><?php esc_html_e('test', 'hb-ucs'); ?></option>
                            <option value="live" <?php selected($backendPaymentMode, 'live'); ?>><?php esc_html_e('live', 'hb-ucs'); ?></option>
                        </select>
                    </p>
                </div>
            </div>

            <div class="order_data_column hb-ucs-subscription-address-column hb-ucs-subscription-shipping-column">
                <h3>
                    <?php esc_html_e('Verzending', 'hb-ucs'); ?>
                    <a href="#" class="edit_address"><?php esc_html_e('Bewerken', 'hb-ucs'); ?></a>
                    <span>
                        <a href="#" class="load_customer_shipping" style="display:none;"><?php esc_html_e('Laad verzendadres', 'hb-ucs'); ?></a>
                        <a href="#" class="billing-same-as-shipping" style="display:none;"><?php esc_html_e('Factuuradres kopiëren', 'hb-ucs'); ?></a>
                    </span>
                </h3>

                <div class="address">
                    <?php $this->render_subscription_address_display('shipping', $shippingAddress); ?>
                    <?php if ($otherSubscriptionsUrl !== '') : ?>
                        <p><a href="<?php echo esc_url($otherSubscriptionsUrl); ?>"><?php esc_html_e('Andere abonnementen van deze klant →', 'hb-ucs'); ?></a></p>
                    <?php endif; ?>
                </div>

                <div class="edit_address" style="display:none;">
                    <?php $this->render_subscription_address_fields('shipping', $shippingAddress); ?>
                </div>
            </div>
        </div>
    </div>
</div>
