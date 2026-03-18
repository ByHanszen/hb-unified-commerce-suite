<?php
/**
 * Subscription order item admin row.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;

$rowClass = $editing ? 'item editing hb-ucs-sub-item-row' : 'item hb-ucs-sub-item-row';
?>
<tr class="<?php echo esc_attr($rowClass); ?>" data-row-index="<?php echo esc_attr((string) $rowIndex); ?>" data-line-subtotal="<?php echo esc_attr((string) $totals['line_subtotal']); ?>" data-line-tax="<?php echo esc_attr((string) $totals['line_tax']); ?>" data-line-total="<?php echo esc_attr((string) $totals['line_total']); ?>">
    <td class="thumb">
        <div class="wc-order-item-thumbnail"><?php echo wp_kses_post($thumbnailHtml !== '' ? $thumbnailHtml : '&nbsp;'); ?></div>
    </td>
    <td class="name" data-sort-value="<?php echo esc_attr($currentLabel); ?>">
        <div class="view">
            <?php if ($productLink !== '' && $currentLabel !== '') : ?>
                <a href="<?php echo esc_url($productLink); ?>" class="wc-order-item-name hb-ucs-item-label-view"><?php echo esc_html($currentLabel); ?></a>
            <?php else : ?>
                <div class="wc-order-item-name hb-ucs-item-label-view"><?php echo esc_html($currentLabel !== '' ? $currentLabel : '—'); ?></div>
            <?php endif; ?>

            <div class="wc-order-item-sku hb-ucs-item-sku-wrap<?php echo $sku === '' ? ' is-empty' : ''; ?>">
                <strong><?php esc_html_e('Artikelnummer:', 'hb-ucs'); ?></strong>
                <span class="hb-ucs-item-sku"><?php echo esc_html($sku); ?></span>
            </div>

            <div class="wc-order-item-variation hb-ucs-item-variation-summary-wrap<?php echo $currentVariationId <= 0 ? ' is-empty' : ''; ?>"<?php echo $currentVariationId <= 0 ? ' style="display:none;"' : ''; ?>>
                <strong><?php esc_html_e('Variatie-ID:', 'hb-ucs'); ?></strong>
                <span class="hb-ucs-item-variation-summary"><?php echo esc_html($currentVariationId > 0 ? (string) $currentVariationId : ''); ?></span>
            </div>

            <?php $this->render_admin_template('subscription-order-item-meta.php', ['editing' => false, 'namePrefix' => '', 'rows' => $displayMetaRows]); ?>
        </div>

        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <label class="screen-reader-text" for="hb_ucs_sub_items_<?php echo esc_attr((string) $rowIndex); ?>_product_id"><?php esc_html_e('Product', 'hb-ucs'); ?></label>
            <select id="hb_ucs_sub_items_<?php echo esc_attr((string) $rowIndex); ?>_product_id" name="hb_ucs_items[<?php echo esc_attr((string) $rowIndex); ?>][product_id]" class="wc-product-search hb-ucs-sub-product-search hb-ucs-item-product" style="width:100%;" data-placeholder="<?php echo esc_attr__('Zoek een product…', 'hb-ucs'); ?>" data-action="woocommerce_json_search_products_and_variations" data-allow_clear="true">
                <?php if ($currentEditProductId > 0 && $currentEditProductLabel !== '') : ?>
                    <option value="<?php echo esc_attr((string) $currentEditProductId); ?>" selected="selected"><?php echo esc_html($currentEditProductLabel); ?></option>
                <?php endif; ?>
            </select>

            <?php $this->render_admin_subscription_item_attribute_fields($rowIndex, $currentEditProductId, $selectedAttributes); ?>
            <?php $this->render_admin_template('subscription-order-item-meta.php', ['editing' => true, 'namePrefix' => 'hb_ucs_items[' . $rowIndex . '][meta]', 'rows' => $displayMetaRows]); ?>
            <input type="hidden" class="hb-ucs-item-remove" name="hb_ucs_items[<?php echo esc_attr((string) $rowIndex); ?>][remove]" value="0" />
        </div>
    </td>

    <td class="item_cost" width="1%" data-sort-value="<?php echo esc_attr((string) $totals['unit_subtotal']); ?>">
        <div class="view hb-ucs-item-unit-view">
            <?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['unit_subtotal']) : number_format($totals['unit_subtotal'], 2, '.', '')); ?>
        </div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <div class="split-input">
                <div class="input">
                    <label><?php esc_html_e('Prijs per stuk', 'hb-ucs'); ?></label>
                    <input type="text" name="hb_ucs_items[<?php echo esc_attr((string) $rowIndex); ?>][unit_price]" value="<?php echo esc_attr((string) wc_format_decimal((string) $totals['unit_subtotal'], wc_get_price_decimals())); ?>" class="line_total wc_input_price hb-ucs-item-price" />
                </div>
            </div>
        </div>
    </td>

    <td class="quantity" width="1%">
        <div class="view">
            <small class="times">&times;</small> <span class="hb-ucs-item-qty-view"><?php echo esc_html((string) $currentQty); ?></span>
        </div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <input type="number" min="1" step="1" name="hb_ucs_items[<?php echo esc_attr((string) $rowIndex); ?>][qty]" value="<?php echo esc_attr((string) $currentQty); ?>" class="quantity hb-ucs-item-qty" />
        </div>
    </td>

    <td class="line_cost" width="1%" data-sort-value="<?php echo esc_attr((string) $totals['line_subtotal']); ?>">
        <div class="view hb-ucs-item-line-subtotal">
            <?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['line_subtotal']) : number_format($totals['line_subtotal'], 2, '.', '')); ?>
        </div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <div class="split-input">
                <div class="input">
                    <label><?php esc_html_e('Subtotaal', 'hb-ucs'); ?></label>
                    <input type="text" value="<?php echo esc_attr((string) wc_format_decimal((string) $totals['line_subtotal'], wc_get_price_decimals())); ?>" class="wc_input_price hb-ucs-item-line-subtotal-input" readonly="readonly" />
                </div>
            </div>
        </div>
    </td>

    <?php $this->render_subscription_admin_tax_cells($taxColumns, (array) ($totals['tax_breakdown'] ?? []), $editing, 'hb-ucs-item', 'hb-ucs-item-line-tax-input', '', true); ?>

    <td class="line_total" width="1%" data-sort-value="<?php echo esc_attr((string) $totals['line_total']); ?>">
        <div class="view hb-ucs-item-line-total">
            <?php echo wp_kses_post(function_exists('wc_price') ? wc_price($totals['line_total']) : number_format($totals['line_total'], 2, '.', '')); ?>
        </div>
        <div class="edit" style="display:<?php echo $editing ? 'block' : 'none'; ?>;">
            <div class="split-input">
                <div class="input">
                    <label><?php esc_html_e('Totaal', 'hb-ucs'); ?></label>
                    <input type="text" value="<?php echo esc_attr((string) wc_format_decimal((string) $totals['line_total'], wc_get_price_decimals())); ?>" class="wc_input_price hb-ucs-item-line-total-input" readonly="readonly" />
                </div>
            </div>
        </div>
    </td>

    <td class="wc-order-edit-line-item" width="1%">
        <div class="wc-order-edit-line-item-actions">
            <a class="edit-order-item tips" href="#" data-tip="<?php echo esc_attr__('Item bewerken', 'hb-ucs'); ?>" aria-label="<?php echo esc_attr__('Item bewerken', 'hb-ucs'); ?>"></a><a class="delete-order-item tips" href="#" data-tip="<?php echo esc_attr__('Item verwijderen', 'hb-ucs'); ?>" aria-label="<?php echo esc_attr__('Item verwijderen', 'hb-ucs'); ?>"></a>
        </div>
    </td>
</tr>
