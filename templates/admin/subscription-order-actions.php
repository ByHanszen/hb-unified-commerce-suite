<?php
/**
 * Subscription admin actions meta box.
 *
 * @package HB_UCS
 */

defined('ABSPATH') || exit;

$actions = isset($actions) && is_array($actions) ? $actions : [];
?>
<ul class="order_actions submitbox">
    <li class="wide" id="actions">
        <select name="hb_ucs_subscription_admin_action">
            <option value=""><?php esc_html_e('Kies een actie...', 'hb-ucs'); ?></option>
            <?php foreach ($actions as $actionKey => $actionLabel) : ?>
                <option value="<?php echo esc_attr($actionKey); ?>"><?php echo esc_html($actionLabel); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button wc-reload" name="hb_ucs_apply_subscription_action" value="1">
            <span><?php esc_html_e('Toepassen', 'hb-ucs'); ?></span>
        </button>
    </li>
    <li class="wide">
        <p style="margin:0; color:#646970;">
            <?php esc_html_e('Gebruik deze acties om direct een renewal te maken, adressen te synchroniseren of de abonnementsstatus te wijzigen.', 'hb-ucs'); ?>
        </p>
    </li>
</ul>
