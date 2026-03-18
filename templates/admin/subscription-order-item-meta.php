<?php
/**
 * Subscription order item meta rows.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;

if (empty($rows) || !is_array($rows)) {
    return;
}

if (!$editing) :
    ?>
    <table cellspacing="0" class="display_meta">
        <tbody>
            <?php foreach ($rows as $row) : ?>
                <?php
                $label = (string) ($row['label'] ?? '');
                $value = (string) ($row['value'] ?? '');
                if ($label === '' || $value === '') {
                    continue;
                }
                ?>
                <tr>
                    <th><?php echo esc_html($label); ?>:</th>
                    <td><p><?php echo esc_html($value); ?></p></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
    return;
endif;
?>
<table class="meta" cellspacing="0">
    <tbody class="meta_items">
        <?php foreach ($rows as $index => $row) : ?>
            <?php
            $label = (string) ($row['label'] ?? '');
            $value = (string) ($row['value'] ?? '');
            if ($label === '' || $value === '') {
                continue;
            }
            ?>
            <tr data-meta_id="<?php echo esc_attr((string) $index); ?>">
                <td>
                    <input type="text" maxlength="255" placeholder="<?php echo esc_attr__('Naam (vereist)', 'hb-ucs'); ?>" value="<?php echo esc_attr($label); ?>" readonly="readonly" />
                    <textarea placeholder="<?php echo esc_attr__('Waarde (vereist)', 'hb-ucs'); ?>" readonly="readonly"><?php echo esc_textarea($value); ?></textarea>
                    <?php if ($namePrefix !== '') : ?>
                        <input type="hidden" name="<?php echo esc_attr($namePrefix . '[' . $index . '][label]'); ?>" value="<?php echo esc_attr($label); ?>" />
                        <input type="hidden" name="<?php echo esc_attr($namePrefix . '[' . $index . '][value]'); ?>" value="<?php echo esc_attr($value); ?>" />
                    <?php endif; ?>
                </td>
                <td width="1%"><button type="button" class="remove_order_item_meta button" disabled="disabled">×</button></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="4"><button type="button" class="add_order_item_meta button" disabled="disabled"><?php esc_html_e('Meta toevoegen', 'hb-ucs'); ?></button></td>
        </tr>
    </tfoot>
</table>
