<?php
/**
 * Subscription fee row admin template.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;
?>
<tr class="fee hb-ucs-sub-fee-row item<?php echo $editing ? ' editing' : ''; ?>" data-row-index="<?php echo esc_attr((string) $rowIndex); ?>" data-line-subtotal="<?php echo esc_attr((string) $totals['line_subtotal']); ?>" data-line-tax="<?php echo esc_attr((string) $totals['line_tax']); ?>" data-line-total="<?php echo esc_attr((string) $totals['line_total']); ?>">
    <td class="thumb"><div class="wc-order-item-thumbnail"><span class="dashicons dashicons-money-alt" aria-hidden="true"></span></div></td>
    <td class="name">
        <div class="view"><?php echo esc_html($name !== '' ? $name : __('Kosten', 'hb-ucs')); ?></div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <input type="text" class="hb-ucs-fee-name" name="hb_ucs_fees[<?php echo esc_attr((string) $rowIndex); ?>][name]" value="<?php echo esc_attr($name); ?>" placeholder="<?php echo esc_attr__('Naam kostenregel', 'hb-ucs'); ?>" />
            <input type="hidden" class="hb-ucs-fee-remove" name="hb_ucs_fees[<?php echo esc_attr((string) $rowIndex); ?>][remove]" value="0" />
        </div>
    </td>
    <td class="item_cost" width="1%">&ndash;</td>
    <td class="quantity" width="1%">&ndash;</td>
    <td class="line_cost" width="1%">
        <div class="view hb-ucs-fee-subtotal-view"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['line_subtotal']) : number_format($totals['line_subtotal'], 2, '.', '')); ?></div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <input type="text" class="line_total wc_input_price hb-ucs-fee-total" name="hb_ucs_fees[<?php echo esc_attr((string) $rowIndex); ?>][total]" value="<?php echo esc_attr((string) wc_format_decimal((string) $totals['line_subtotal'], wc_get_price_decimals())); ?>" />
        </div>
    </td>
    <?php $this->render_subscription_admin_tax_cells($taxColumns, (array) ($totals['tax_breakdown'] ?? []), $editing, 'hb-ucs-fee', 'hb-ucs-fee-tax-input', 'hb_ucs_fees[' . $rowIndex . '][taxes][total]', false); ?>
    <td class="line_total" width="1%">
        <div class="view hb-ucs-fee-total-view"><?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['line_total']) : number_format($totals['line_total'], 2, '.', '')); ?></div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <input type="text" class="wc_input_price hb-ucs-fee-line-total" value="<?php echo esc_attr((string) wc_format_decimal((string) $totals['line_total'], wc_get_price_decimals())); ?>" readonly="readonly" />
        </div>
    </td>
    <td class="wc-order-edit-line-item" width="1%">
        <div class="wc-order-edit-line-item-actions">
            <a class="edit-order-item tips" href="#" data-tip="<?php echo esc_attr__('Kosten bewerken', 'hb-ucs'); ?>" aria-label="<?php echo esc_attr__('Kosten bewerken', 'hb-ucs'); ?>"></a><a class="delete-order-item tips" href="#" data-tip="<?php echo esc_attr__('Kosten verwijderen', 'hb-ucs'); ?>" aria-label="<?php echo esc_attr__('Kosten verwijderen', 'hb-ucs'); ?>"></a>
        </div>
    </td>
</tr>
