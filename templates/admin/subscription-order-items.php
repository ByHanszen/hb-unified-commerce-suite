<?php
/**
 * Subscription order items admin template.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;
?>
<div id="woocommerce-order-items" class="hb-ucs-order-items-metabox">
    <div class="woocommerce_order_items_wrapper wc-order-items-editable">
        <table cellpadding="0" cellspacing="0" class="woocommerce_order_items">
            <thead>
                <tr>
                    <th class="item sortable" colspan="2" data-sort="string-ins"><?php esc_html_e('Item', 'hb-ucs'); ?></th>
                    <th class="item_cost sortable" data-sort="float"><?php esc_html_e('Prijs', 'hb-ucs'); ?></th>
                    <th class="quantity sortable" data-sort="int"><?php esc_html_e('Aantal', 'hb-ucs'); ?></th>
                    <th class="line_cost sortable" data-sort="float"><?php esc_html_e('Subtotaal', 'hb-ucs'); ?></th>
                    <?php foreach ($taxColumns as $column) : ?>
                        <?php
                        $rateKey = (string) ($column['key'] ?? '');
                        $canDelete = ($column['source'] ?? 'computed') === 'manual';
                        ?>
                        <th class="line_tax">
                            <?php echo esc_html((string) ($column['label'] ?? __('BTW', 'hb-ucs'))); ?>
                            <?php if ($rateKey !== '') : ?>
                                <input type="hidden" class="order-tax-id" value="<?php echo esc_attr($rateKey); ?>" />
                                <?php if ($canDelete) : ?>
                                    <input type="hidden" name="hb_ucs_manual_tax_rates[]" value="<?php echo esc_attr($rateKey); ?>" />
                                    <a class="delete-order-tax" href="#" data-rate_id="<?php echo esc_attr($rateKey); ?>" aria-label="<?php echo esc_attr__('Belasting verwijderen', 'hb-ucs'); ?>"></a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </th>
                    <?php endforeach; ?>
                    <th class="line_total sortable" data-sort="float"><?php esc_html_e('Totaal', 'hb-ucs'); ?></th>
                    <th class="wc-order-edit-line-item" width="1%">&nbsp;</th>
                </tr>
            </thead>
            <tbody id="order_line_items">
                <?php foreach (array_values($items) as $index => $item) : ?>
                    <?php $this->render_admin_subscription_item_row($index, $item, $scheme, $taxColumns, $customer, $index === 0); ?>
                <?php endforeach; ?>
            </tbody>
            <tbody id="order_fee_line_items">
                <?php foreach (array_values($feeLines) as $index => $feeLine) : ?>
                    <?php $this->render_admin_subscription_fee_row($index, $feeLine, $taxColumns, false); ?>
                <?php endforeach; ?>
            </tbody>
            <tbody id="order_shipping_line_items">
                <?php foreach (array_values($shippingLines) as $index => $shippingLine) : ?>
                    <?php $this->render_admin_subscription_shipping_row($index, $shippingLine, $taxColumns, false, $items); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <input type="hidden" name="hb_ucs_items_present" value="1" />
    <input type="hidden" name="hb_ucs_fees_present" value="1" />
    <input type="hidden" name="hb_ucs_shipping_lines_present" value="1" />
    <input type="hidden" name="hb_ucs_manual_tax_rates_present" value="1" />

    <div class="wc-order-data-row wc-order-totals-items wc-order-items-editable">
        <table class="wc-order-totals">
            <tr>
                <td class="label"><?php esc_html_e('Artikelen subtotaal:', 'hb-ucs'); ?></td>
                <td width="1%"></td>
                <td class="total" id="hb-ucs-sub-items-subtotal"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['item_subtotal']) : number_format($totals['item_subtotal'], 2, '.', '')); ?></td>
            </tr>
            <tr>
                <td class="label"><?php esc_html_e('Kosten:', 'hb-ucs'); ?></td>
                <td width="1%"></td>
                <td class="total" id="hb-ucs-sub-fee-total"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['fee_subtotal']) : number_format($totals['fee_subtotal'], 2, '.', '')); ?></td>
            </tr>
            <tr>
                <td class="label"><?php esc_html_e('Verzending:', 'hb-ucs'); ?></td>
                <td width="1%"></td>
                <td class="total" id="hb-ucs-sub-shipping-total"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['shipping_subtotal']) : number_format($totals['shipping_subtotal'], 2, '.', '')); ?></td>
            </tr>
            <?php foreach ($taxColumns as $column) : ?>
                <?php
                $rateKey = (string) ($column['key'] ?? '');
                $safeKey = sanitize_html_class($rateKey !== '' ? $rateKey : 'tax');
                $rateAmount = (float) (($totals['tax_breakdown'][$rateKey] ?? 0.0));
                ?>
                <tr class="hb-ucs-tax-total-row" data-tax-rate="<?php echo esc_attr($rateKey); ?>">
                    <td class="label"><?php echo esc_html((string) ($column['label'] ?? __('BTW', 'hb-ucs'))); ?>:</td>
                    <td width="1%"></td>
                    <td class="total" id="hb-ucs-sub-tax-rate-<?php echo esc_attr($safeKey); ?>"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($rateAmount) : number_format($rateAmount, 2, '.', '')); ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td class="label"><?php esc_html_e('BTW:', 'hb-ucs'); ?></td>
                <td width="1%"></td>
                <td class="total" id="hb-ucs-sub-total-tax"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['tax']) : number_format($totals['tax'], 2, '.', '')); ?></td>
            </tr>
            <tr>
                <td class="label"><?php esc_html_e('Abonnement totaal:', 'hb-ucs'); ?></td>
                <td width="1%"></td>
                <td class="total" id="hb-ucs-sub-order-total"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['total']) : number_format($totals['total'], 2, '.', '')); ?></td>
            </tr>
        </table>

        <div class="clear"></div>

        <div class="wc-order-bulk-actions add-items">
            <button type="button" class="button add-line-item" id="hb-ucs-show-add-item-actions"><?php esc_html_e('Item(s) toevoegen', 'hb-ucs'); ?></button>
            <button type="button" class="button button-primary calculate-action" id="hb-ucs-recalc-order-items"><?php esc_html_e('Herberekenen', 'hb-ucs'); ?></button>
        </div>

        <div class="wc-order-data-row wc-order-add-item wc-order-data-row-toggle" style="display:none;">
            <button type="button" class="button add-order-item" id="hb-ucs-add-order-item"><?php esc_html_e('Product(en) toevoegen', 'hb-ucs'); ?></button>
            <button type="button" class="button add-order-fee" id="hb-ucs-add-order-fee"><?php esc_html_e('Kosten toevoegen', 'hb-ucs'); ?></button>
            <button type="button" class="button add-order-shipping" id="hb-ucs-add-order-shipping"><?php esc_html_e('Voeg verzending toe', 'hb-ucs'); ?></button>
            <button type="button" class="button add-order-tax" id="hb-ucs-add-order-tax"><?php esc_html_e('Belastingen toevoegen', 'hb-ucs'); ?></button>
            <button type="button" class="button cancel-action" id="hb-ucs-cancel-order-actions"><?php esc_html_e('Annuleren', 'hb-ucs'); ?></button>
            <button type="button" class="button button-primary save-action" id="hb-ucs-save-order-actions"><?php esc_html_e('Opslaan', 'hb-ucs'); ?></button>
        </div>

        <script type="text/template" id="tmpl-hb-ucs-subscription-item-row"><?php echo str_replace('</script>', '<\/script>', $rowTemplate); ?></script>
        <script type="text/template" id="tmpl-hb-ucs-subscription-fee-row"><?php echo str_replace('</script>', '<\/script>', $feeTemplate); ?></script>
        <script type="text/template" id="tmpl-hb-ucs-subscription-shipping-row"><?php echo str_replace('</script>', '<\/script>', $shippingTemplate); ?></script>
        <script type="text/template" id="tmpl-hb-ucs-subscription-add-tax-modal">
            <div class="wc-backbone-modal">
                <div class="wc-backbone-modal-content">
                    <section class="wc-backbone-modal-main" role="main">
                        <header class="wc-backbone-modal-header">
                            <h1 id="hb-ucs-add-tax-title"><?php esc_html_e('Belasting toevoegen', 'hb-ucs'); ?></h1>
                            <button class="modal-close modal-close-link dashicons dashicons-no-alt"><span class="screen-reader-text"><?php esc_html_e('Sluit modaal venster', 'hb-ucs'); ?></span></button>
                        </header>
                        <article>
                            <form action="" method="post">
                                <table class="widefat">
                                    <thead>
                                        <tr>
                                            <th>&nbsp;</th>
                                            <th><?php esc_html_e('Naam tarief', 'hb-ucs'); ?></th>
                                            <th><?php esc_html_e('Belastingklasse', 'hb-ucs'); ?></th>
                                            <th><?php esc_html_e('Code', 'hb-ucs'); ?></th>
                                            <th><?php esc_html_e('Percentage', 'hb-ucs'); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($availableTaxRates as $rate) : ?>
                                            <?php $rateId = (int) ($rate['id'] ?? 0); ?>
                                            <?php if ($rateId <= 0) {
                                                continue;
                                            } ?>
                                            <tr>
                                                <td><input type="radio" id="hb_ucs_add_order_tax_<?php echo esc_attr((string) $rateId); ?>" name="hb_ucs_add_tax_rate" value="<?php echo esc_attr((string) $rateId); ?>" /></td>
                                                <td><label for="hb_ucs_add_order_tax_<?php echo esc_attr((string) $rateId); ?>"><?php echo esc_html((string) ($rate['label'] ?? '')); ?></label></td>
                                                <td><?php echo esc_html((string) ($rate['class'] ?? '—')); ?></td>
                                                <td><?php echo esc_html((string) ($rate['code'] ?? '')); ?></td>
                                                <td><?php echo esc_html((string) ($rate['percent'] ?? '')); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <p>
                                    <label for="manual_tax_rate_id"><?php esc_html_e('Of vul handmatig een tax-rate ID in:', 'hb-ucs'); ?></label><br />
                                    <input type="number" name="manual_tax_rate_id" id="manual_tax_rate_id" min="1" step="1" placeholder="<?php echo esc_attr__('Optioneel', 'hb-ucs'); ?>" />
                                </p>
                            </form>
                        </article>
                        <footer><div class="inner"><button id="btn-ok" type="button" class="button button-primary button-large"><?php esc_html_e('Toevoegen', 'hb-ucs'); ?></button></div></footer>
                    </section>
                </div>
            </div>
            <div class="wc-backbone-modal-backdrop modal-close"></div>
        </script>

        <input type="hidden" id="hb_ucs_sub_id_current" value="<?php echo esc_attr((string) $subId); ?>" />
        <input type="hidden" id="hb_ucs_sub_scheme_current" value="<?php echo esc_attr($scheme); ?>" />
    </div>
</div>
