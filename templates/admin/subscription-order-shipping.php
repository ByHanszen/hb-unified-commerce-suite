<?php
/**
 * Subscription shipping row admin template.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;
?>
<tr class="shipping hb-ucs-sub-shipping-row item<?php echo $editing ? ' editing' : ''; ?>" data-row-index="<?php echo esc_attr((string) $rowIndex); ?>" data-line-subtotal="<?php echo esc_attr((string) $totals['line_subtotal']); ?>" data-line-tax="<?php echo esc_attr((string) $totals['line_tax']); ?>" data-line-total="<?php echo esc_attr((string) $totals['line_total']); ?>">
    <td class="thumb"><div class="wc-order-item-thumbnail"><span class="dashicons dashicons-cart" aria-hidden="true"></span></div></td>
    <td class="name">
        <div class="view"><?php echo esc_html($methodTitle !== '' ? $methodTitle : __('Verzending', 'hb-ucs')); ?></div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <input type="text" class="shipping_method_name hb-ucs-shipping-title" name="hb_ucs_shipping_lines[<?php echo esc_attr((string) $rowIndex); ?>][method_title]" value="<?php echo esc_attr($methodTitle !== '' ? $methodTitle : __('Verzending', 'hb-ucs')); ?>" placeholder="<?php echo esc_attr__('Naam verzending', 'hb-ucs'); ?>" />
            <input type="hidden" class="hb-ucs-shipping-method-id" name="hb_ucs_shipping_lines[<?php echo esc_attr((string) $rowIndex); ?>][method_id]" value="<?php echo esc_attr($methodId); ?>" />
            <input type="hidden" class="hb-ucs-shipping-remove" name="hb_ucs_shipping_lines[<?php echo esc_attr((string) $rowIndex); ?>][remove]" value="0" />
        </div>
        <?php $this->render_admin_template('subscription-order-item-meta.php', ['editing' => false, 'namePrefix' => '', 'rows' => $displayMetaRows]); ?>
    </td>
    <td class="item_cost" width="1%">&ndash;</td>
    <td class="quantity" width="1%">&ndash;</td>
    <td class="line_cost" width="1%">
        <div class="view hb-ucs-item-line-subtotal"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['line_subtotal']) : number_format($totals['line_subtotal'], 2, '.', '')); ?></div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <input type="text" class="line_total wc_input_price hb-ucs-shipping-total" name="hb_ucs_shipping_lines[<?php echo esc_attr((string) $rowIndex); ?>][total]" value="<?php echo esc_attr((string) wc_format_decimal((string) $totals['line_subtotal'], wc_get_price_decimals())); ?>" />
        </div>
    </td>
    <?php $this->render_subscription_admin_tax_cells($taxColumns, (array) ($totals['tax_breakdown'] ?? []), $editing, 'hb-ucs-shipping', 'hb-ucs-shipping-tax-input', 'hb_ucs_shipping_lines[' . $rowIndex . '][taxes][total]', false); ?>
    <td class="line_total" width="1%">
        <div class="view hb-ucs-item-line-total"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['line_total']) : number_format($totals['line_total'], 2, '.', '')); ?></div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <input type="text" class="wc_input_price hb-ucs-shipping-line-total" value="<?php echo esc_attr((string) wc_format_decimal((string) $totals['line_total'], wc_get_price_decimals())); ?>" readonly="readonly" />
        </div>
    </td>
    <td class="wc-order-edit-line-item" width="1%">
        <div class="wc-order-edit-line-item-actions">
            <a class="edit-order-item tips" href="#" data-tip="<?php echo esc_attr__('Verzending bewerken', 'hb-ucs'); ?>" aria-label="<?php echo esc_attr__('Verzending bewerken', 'hb-ucs'); ?>"></a><a class="delete-order-item tips" href="#" data-tip="<?php echo esc_attr__('Verzending verwijderen', 'hb-ucs'); ?>" aria-label="<?php echo esc_attr__('Verzending verwijderen', 'hb-ucs'); ?>"></a>
        </div>
    </td>
</tr>
