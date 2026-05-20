/* global jQuery */
/**
 * HB UCS – Checkout: verplichte keuze verzendmethode
 *
 * Zorgt ervoor dat bij het laden van de checkoutpagina en na elke AJAX-update
 * geen verzendmethode voorgeselecteerd is, zolang de klant nog geen keuze
 * heeft gemaakt. Dit is een client-side veiligheidsmaatregel bovenop de
 * server-side PHP-logica.
 */
(function ($) {
    'use strict';

    var userHasChosen = false;
    var observer = null;

    function getTrackingInput() {
        return $('input[data-hb-ucs-shipping-choice="1"]');
    }

    function ensureTrackingInput() {
        var $input = getTrackingInput();
        var $form = $('form.checkout');

        if ($input.length || !$form.length) {
            return $input;
        }

        $form.append('<input type="hidden" name="hb_ucs_shipping_user_chosen" value="0" data-hb-ucs-shipping-choice="1" />');
        return getTrackingInput();
    }

    function setTrackingValue(value) {
        ensureTrackingInput().val(value ? '1' : '0');
    }

    function getShippingInputs() {
        return $(
            'input[name^="shipping_method"][type="radio"], ' +
            '.woocommerce-shipping-methods input[type="radio"], ' +
            '.wc-block-components-shipping-rates-control__package input[type="radio"], ' +
            '.wc-block-checkout__shipping-option input[type="radio"]'
        );
    }

    /**
     * Deselecteert alle shipping method radio buttons.
     */
    function deselectShippingMethods() {
        if (userHasChosen) {
            return;
        }

        setTrackingValue(false);

        getShippingInputs().each(function () {
            this.checked = false;
            $(this).prop('checked', false).removeAttr('checked');
        });
    }

    function scheduleDeselect() {
        if (userHasChosen) {
            return;
        }

        deselectShippingMethods();
        window.setTimeout(deselectShippingMethods, 0);
        window.setTimeout(deselectShippingMethods, 50);
        window.setTimeout(deselectShippingMethods, 200);
    }

    function bindObserver() {
        var target = document.querySelector('.woocommerce-checkout-review-order-table, .wc-block-checkout__shipping-fields, .wc-block-components-totals-wrapper');

        if (!target || observer) {
            return;
        }

        observer = new MutationObserver(function () {
            scheduleDeselect();
        });

        observer.observe(target, {
            childList: true,
            subtree: true,
            attributes: true,
            attributeFilter: ['checked']
        });
    }

    $(function () {
        // Deselect bij het eerste laden van de pagina en bij late init door thema/scripts.
        ensureTrackingInput();
        setTrackingValue(false);
        scheduleDeselect();
        bindObserver();

        // Markeer dat de klant bewust een keuze heeft gemaakt.
        $(document.body).on('change', 'input[name^="shipping_method"][type="radio"], .woocommerce-shipping-methods input[type="radio"], .wc-block-components-shipping-rates-control__package input[type="radio"], .wc-block-checkout__shipping-option input[type="radio"]', function () {
            userHasChosen = true;
            setTrackingValue(true);
        });

        // Na elke checkout AJAX-update: deselect als er nog geen keuze is gemaakt.
        $(document.body).on('updated_checkout updated_shipping_method wc_fragments_loaded wc_fragments_refreshed', function () {
            ensureTrackingInput();
            scheduleDeselect();
            bindObserver();
        });

        $(window).on('load', function () {
            ensureTrackingInput();
            scheduleDeselect();
            bindObserver();
        });
    });
}(jQuery));
