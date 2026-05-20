<?php
// =============================
// src/Modules/Checkout/CheckoutModule.php
// =============================
namespace HB\UCS\Modules\Checkout;

if (!defined('ABSPATH')) exit;

/**
 * Checkout-module.
 *
 * Optie: verplichte keuze verzendmethode.
 * Wanneer ingeschakeld wordt er nooit standaard een verzendmethode voorselecteerd.
 * De klant moet bewust een keuze maken voordat de bestelling geplaatst kan worden.
 */
class CheckoutModule {

    private const SESSION_FLAG = 'hb_ucs_shipping_user_chosen';
    private const POST_FLAG = 'hb_ucs_shipping_user_chosen';

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $opt = get_option('hb_ucs_checkout_settings', []);

        if (!empty($opt['force_shipping_selection'])) {
            // Primary mechanism: clear chosen_shipping_methods from session after every
            // cart recalculation. WooCommerce's checkout template reads the session key
            // directly, so we must manipulate the session – not just the filter.
            // Priority 999 ensures we run after WooCommerce itself has finished writing.
            // is_checkout() returns true during AJAX checkout updates too (WC sets
            // WOOCOMMERCE_CHECKOUT constant), so this covers both cases.
            add_action('woocommerce_after_calculate_totals', [$this, 'clear_session_shipping'], 999);

            // Clear the session again at the last possible classic-checkout render point.
            // The checkout shipping template reads WC()->session->chosen_shipping_methods
            // directly, so clearing here guarantees the rendered radios see an empty value.
            add_action('woocommerce_review_order_before_shipping', [$this, 'clear_session_shipping'], 1);

            // Backup filter: also prevent WooCommerce from recording an auto-selected
            // method back into the session during the shipping calculation phase itself.
            add_filter('woocommerce_shipping_chosen_method', [$this, 'maybe_unset_default_shipping'], 10, 3);

            // Track when the customer explicitly picks a method via the AJAX update.
            add_action('woocommerce_checkout_update_order_review', [$this, 'track_explicit_shipping_choice'], 5);

            // Output a hidden field on the classic checkout form so we can validate
            // explicit user intent instead of trusting WooCommerce's chosen method state.
            add_action('woocommerce_review_order_before_submit', [$this, 'render_choice_tracking_field'], 1);

            // Server-side validation: block order placement when no method was chosen.
            add_action('woocommerce_checkout_process', [$this, 'validate_shipping_chosen']);

            // Clear the tracking flag whenever the cart is emptied.
            add_action('woocommerce_cart_emptied', [$this, 'clear_shipping_flag']);

            // Enqueue frontend JS (client-side safety net for AJAX-rendered fragments).
            add_action('wp_enqueue_scripts', [$this, 'enqueue_checkout_scripts']);
        }
    }

    /**
     * Clears the WooCommerce session key that stores the chosen shipping method.
     * Called after every cart recalculation. The checkout template reads this key
     * directly from session, so clearing it here prevents any pre-selection.
     * Skipped once the customer has made an explicit choice.
     *
     */
    public function clear_session_shipping($context = null): void {
        if (!is_checkout()) {
            return;
        }
        if (!WC()->session) {
            return;
        }
        if (WC()->session->get(self::SESSION_FLAG)) {
            // Customer has already chosen explicitly – keep their choice.
            return;
        }
        WC()->session->set('chosen_shipping_methods', []);
    }

    /**
     * Backup filter: prevents WooCommerce from storing an auto-selected method
     * in session during the calculation phase (called inside
     * wc_get_chosen_shipping_method_for_package()).
     *
     * @param string $default Method ID WooCommerce wants to pre-select.
     * @param array  $rates   Available shipping rates for this package.
     * @param string $chosen  Current session value (before this filter).
     * @return string
     */
    public function maybe_unset_default_shipping($default, $rates, $chosen) {
        if (WC()->session && WC()->session->get(self::SESSION_FLAG)) {
            return $default;
        }
        // Return empty string so WooCommerce's `if ($chosen_method)` guard
        // skips writing the auto-selected value to session.
        return '';
    }

    /**
     * Called on every checkout AJAX update. Detects whether the customer
     * submitted a shipping_method value (= clicked a radio button).
     *
     * @param string $post_data URL-encoded serialised checkout form.
     */
    public function track_explicit_shipping_choice(string $post_data): void {
        if (!WC()->session) {
            return;
        }

        $data = [];
        wp_parse_str($post_data, $data);

        $userHasChosen = !empty($data[self::POST_FLAG]) && (string) $data[self::POST_FLAG] === '1';

        if ($userHasChosen) {
            WC()->session->set(self::SESSION_FLAG, true);
            return;
        }

        WC()->session->__unset(self::SESSION_FLAG);
        WC()->session->set('chosen_shipping_methods', []);
    }

    public function render_choice_tracking_field(): void {
        echo '<input type="hidden" name="' . esc_attr(self::POST_FLAG) . '" value="0" data-hb-ucs-shipping-choice="1" />';
    }

    /**
     * Validates that an explicit shipping method was submitted on checkout.
     * Only triggered for packages that have multiple available methods
     * (a single method leaves the customer no real choice).
     */
    public function validate_shipping_chosen(): void {
        $packages = WC()->shipping()->get_packages();
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $explicitChoice = isset($_POST[self::POST_FLAG]) && (string) wp_unslash($_POST[self::POST_FLAG]) === '1';

        foreach ($packages as $i => $package) {
            if (empty($package['rates'])) {
                continue;
            }

            // With only one available method there is nothing to choose.
            if (count($package['rates']) <= 1) {
                continue;
            }

            if (!$explicitChoice) {
                if (WC()->session) {
                    WC()->session->__unset(self::SESSION_FLAG);
                    WC()->session->set('chosen_shipping_methods', []);
                }
                wc_add_notice(
                    __('Selecteer een verzendmethode om verder te gaan.', 'hb-ucs'),
                    'error'
                );
                return;
            }
        }
    }

    /**
     * Resets the explicit-choice session flag when the cart is emptied.
     */
    public function clear_shipping_flag(): void {
        if (WC()->session) {
            WC()->session->__unset(self::SESSION_FLAG);
        }
    }

    /**
     * Enqueues the frontend script on the checkout page.
     * The script deselects shipping radios as an additional client-side safeguard.
     */
    public function enqueue_checkout_scripts(): void {
        if (!is_checkout()) {
            return;
        }

        $pluginFile = defined('HB_UCS_PLUGIN_FILE') ? HB_UCS_PLUGIN_FILE : (dirname(__FILE__, 4) . '/hb-unified-commerce-suite.php');
        $base       = trailingslashit(plugins_url('src/assets/', $pluginFile));
        $scriptPath = dirname(__FILE__, 3) . '/assets/frontend-hb-ucs-checkout.js';
        $ver        = file_exists($scriptPath)
            ? (string) filemtime($scriptPath)
            : (defined('HB_UCS_VERSION') ? HB_UCS_VERSION : '0.0.0');

        wp_enqueue_script(
            'hb-ucs-checkout',
            $base . 'frontend-hb-ucs-checkout.js',
            ['jquery'],
            $ver,
            true
        );
    }
}
