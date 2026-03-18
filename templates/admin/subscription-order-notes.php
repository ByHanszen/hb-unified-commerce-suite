<?php
/**
 * Subscription admin notes meta box.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;

$notes = isset($notes) && is_array($notes) ? $notes : [];
?>
<ul class="order_notes">
    <?php if (!empty($notes)) : ?>
        <?php foreach ($notes as $note) :
            $cssClasses = ['note'];
            if (!empty($note->customer_note)) {
                $cssClasses[] = 'customer-note';
            }
        ?>
            <li rel="<?php echo esc_attr((string) ($note->id ?? 0)); ?>" class="<?php echo esc_attr(implode(' ', $cssClasses)); ?>">
                <div class="note_content">
                    <?php echo wpautop(wp_kses_post((string) ($note->content ?? ''))); ?>
                </div>
                <p class="meta">
                    <?php if (!empty($note->date_created) && is_object($note->date_created) && method_exists($note->date_created, 'date_i18n')) : ?>
                        <abbr class="exact-date" title="<?php echo esc_attr($note->date_created->date('Y-m-d H:i:s')); ?>">
                            <?php
                            echo esc_html(sprintf(
                                __('%1$s om %2$s', 'hb-ucs'),
                                $note->date_created->date_i18n(wc_date_format()),
                                $note->date_created->date_i18n(wc_time_format())
                            ));
                            ?>
                        </abbr>
                    <?php endif; ?>
                    <?php if (!empty($note->added_by)) : ?>
                        <?php echo esc_html(sprintf(__(' door %s', 'hb-ucs'), (string) $note->added_by)); ?>
                    <?php endif; ?>
                </p>
            </li>
        <?php endforeach; ?>
    <?php else : ?>
        <li class="no-items"><?php esc_html_e('Er zijn nog geen notities.', 'hb-ucs'); ?></li>
    <?php endif; ?>
</ul>

<div class="add_note">
    <p>
        <label for="hb_ucs_subscription_note"><?php esc_html_e('Notitie toevoegen', 'hb-ucs'); ?></label>
        <textarea name="hb_ucs_subscription_note" id="hb_ucs_subscription_note" class="input-text" cols="20" rows="4"></textarea>
    </p>
    <p>
        <label for="hb_ucs_subscription_note_type" class="screen-reader-text"><?php esc_html_e('Type notitie', 'hb-ucs'); ?></label>
        <select name="hb_ucs_subscription_note_type" id="hb_ucs_subscription_note_type">
            <option value="private"><?php esc_html_e('Privé notitie', 'hb-ucs'); ?></option>
            <option value="customer"><?php esc_html_e('Klantnotitie', 'hb-ucs'); ?></option>
        </select>
        <button type="submit" class="button" name="hb_ucs_add_subscription_note" value="1"><?php esc_html_e('Toevoegen', 'hb-ucs'); ?></button>
    </p>
</div>
