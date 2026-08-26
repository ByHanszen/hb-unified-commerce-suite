(function ($) {
    'use strict';

    function money(value) {
        var cfg = window.hbUcsBundles || {};
        try {
            return new Intl.NumberFormat(cfg.locale || undefined, {
                style: 'currency',
                currency: cfg.currency || 'EUR',
                minimumFractionDigits: Number(cfg.priceDecimals || 2),
                maximumFractionDigits: Number(cfg.priceDecimals || 2)
            }).format(Number(value || 0));
        } catch (e) {
            return Number(value || 0).toFixed(Number(cfg.priceDecimals || 2));
        }
    }

    function quantity(value) {
        var number = Number(value || 0);
        return number % 1 === 0 ? String(number) : String(Math.round(number * 100) / 100);
    }

    function parseJson(value, fallback) {
        try { return JSON.parse(value); } catch (e) { return fallback; }
    }

    function sameAttributes(selected, candidate) {
        var keys = Object.keys(candidate || {});
        for (var i = 0; i < keys.length; i++) {
            var expected = String(candidate[keys[i]] || '');
            if (expected !== '' && String(selected[keys[i]] || '') !== expected) return false;
        }
        return true;
    }

    function selectVariation($item, component) {
        if (!component.variable) return component;
        var selected = {};
        var complete = true;
        $item.find('.hb-ucs-bundle__attribute').each(function () {
            var value = String($(this).val() || '');
            selected[String($(this).data('attribute'))] = value;
            if (!value) complete = false;
        });
        var variations = parseJson($item.find('.hb-ucs-bundle__variations').text(), []);
        var match = null;
        if (complete) {
            for (var i = 0; i < variations.length; i++) {
                if (sameAttributes(selected, variations[i].attributes || {})) {
                    match = variations[i];
                    break;
                }
            }
        }
        component.attributes = selected;
        component.selectedId = match ? Number(match.id) : 0;
        component.price = match ? Number(match.price || 0) : 0;
        component.regularPrice = match ? Number(match.regularPrice || component.price) : 0;
        component.purchasable = !!(match && match.purchasable);
        component.stock = match ? Number(match.stock == null ? -1 : match.stock) : 0;
        if (match && match.priceHtml) $item.find('.hb-ucs-bundle__price').html(match.priceHtml);
        if (match && match.image) $item.find('.hb-ucs-bundle__image img').attr('src', match.image).removeAttr('srcset');
        return component;
    }

    function updateItemState($item, component, qty, itemError) {
        var cfg = window.hbUcsBundles || {};
        var selected = qty > 0 && !!component.selectedId && !!component.purchasable && !itemError;
        var state = cfg.chooseLabel || 'Maak een keuze';

        if (selected) {
            state = cfg.selectedLabel || 'Geselecteerd';
        } else if (qty > 0 && !component.purchasable && (!component.variable || component.selectedId)) {
            state = cfg.unavailableLabel || 'Niet beschikbaar';
        } else if (component.optional && qty <= 0) {
            state = cfg.optionalEmptyLabel || 'Niet geselecteerd';
        }

        $item.toggleClass('is-selected', selected);
        $item.toggleClass('has-error', !!itemError);
        $item.find('.hb-ucs-bundle__item-status').contents().filter(function () {
            return this.nodeType === 3;
        }).remove();
        $item.find('.hb-ucs-bundle__item-status').append(document.createTextNode(state));
        $item.find('.hb-ucs-bundle__qty, .hb-ucs-bundle__attribute').attr('aria-invalid', itemError ? 'true' : null);
    }

    function update($bundle) {
        var cfg = window.hbUcsBundles || {};
        var config = parseJson($bundle.attr('data-config'), {});
        var selection = [];
        var summary = [];
        var count = 0;
        var regularTotal = 0;
        var total = 0;
        var error = '';
        var $form = $bundle.closest('form.cart');
        var bundleQty = Math.max(1, Number($form.find('.quantity .qty').not('.hb-ucs-bundle__qty').first().val() || 1));

        $bundle.find('.hb-ucs-bundle__item').each(function () {
            var $item = $(this);
            var component = parseJson($item.attr('data-component'), {});
            component = selectVariation($item, component);
            var qty = component.optional ? Number($item.find('.hb-ucs-bundle__qty').val() || 0) : Number(component.qty || 0);
            var min = Number(component.min || 0);
            var max = Number(component.max || 0);
            var itemError = '';

            if (qty < min || (max > 0 && qty > max)) itemError = cfg.quantityError || 'Controleer de aantallen.';
            if (!component.optional && qty <= 0) itemError = itemError || cfg.requiredError || 'Maak alle keuzes.';
            if (component.variable && qty > 0 && !component.selectedId) itemError = itemError || cfg.requiredError || 'Maak alle keuzes.';
            if (qty > 0 && !component.purchasable) itemError = itemError || cfg.stockError || 'Niet op voorraad.';
            if (qty > 0 && Number(component.stock) >= 0 && (qty * bundleQty) > Number(component.stock)) itemError = itemError || cfg.stockError || 'Niet op voorraad.';

            if (itemError) error = error || itemError;
            $item.attr('data-component', JSON.stringify(component));
            updateItemState($item, component, qty, itemError);

            if (qty > 0 && component.selectedId) {
                var attrs = component.attributes || {};
                selection.push(Number(component.selectedId) + '/' + encodeURIComponent(String(component.key)) + '/' + qty + '/' + encodeURIComponent(JSON.stringify(attrs)));
                var title = $.trim($item.find('.hb-ucs-bundle__title-row h4').first().text());
                var choices = [];
                $item.find('.hb-ucs-bundle__attribute option:selected').each(function () {
                    if ($(this).val()) choices.push($(this).text());
                });
                summary.push({qty: qty, title: title, choices: choices.join(', ')});
                count += qty;
                regularTotal += Number(component.regularPrice || component.price || 0) * qty;
                total += Number(component.price || 0) * qty;
            }
        });

        if (!selection.length) error = error || (cfg.emptyError || 'Kies minimaal één onderdeel.');
        if (Number(config.minCount || 0) > 0 && count < Number(config.minCount)) error = error || (cfg.quantityError || 'Controleer de aantallen.');
        if (Number(config.maxCount || 0) > 0 && count > Number(config.maxCount)) error = error || (cfg.quantityError || 'Controleer de aantallen.');

        if (config.fixedPrice) {
            total = Number(config.fixedTotal || 0);
            regularTotal = total;
        } else if (Number(config.discountAmount || 0) > 0) {
            total = Math.max(0, total - Number(config.discountAmount));
        } else if (Number(config.discount || 0) > 0) {
            total = Math.max(0, total * (100 - Number(config.discount)) / 100);
        }
        if (config.useTotalLimits && Number(config.minTotal || 0) > 0 && total < Number(config.minTotal)) error = error || (cfg.totalError || 'Ongeldig totaal.');
        if (config.useTotalLimits && Number(config.maxTotal || 0) > 0 && total > Number(config.maxTotal)) error = error || (cfg.totalError || 'Ongeldig totaal.');

        var $summary = $bundle.find('.hb-ucs-bundle__summary');
        var $list = $bundle.find('.hb-ucs-bundle__summary-list').empty();
        $.each(summary, function (_, row) {
            var $li = $('<li>');
            var $main = $('<span class="hb-ucs-bundle__summary-main">');
            $('<strong>').text(quantity(row.qty) + ' × ' + row.title).appendTo($main);
            if (row.choices) $('<small>').text(row.choices).appendTo($main);
            $li.append($main);
            $list.append($li);
        });
        $summary.toggleClass('is-empty', summary.length === 0);
        $summary.find('.hb-ucs-bundle__summary-count strong').text(quantity(count));
        $summary.find('.hb-ucs-bundle__summary-count span').text(count === 1 ? (cfg.componentSingular || 'onderdeel') : (cfg.componentPlural || 'onderdelen'));
        $bundle.find('.hb-ucs-bundle__total-value').text(money(total));

        var savings = Math.max(0, regularTotal - total);
        var $savings = $bundle.find('.hb-ucs-bundle__savings');
        if (savings > 0.005) {
            $savings.find('strong').text(money(savings));
            $savings.prop('hidden', false);
        } else {
            $savings.find('strong').text('');
            $savings.prop('hidden', true);
        }

        $form.find('.hb-ucs-bundle-selection').val(selection.join(','));
        var $notice = $bundle.find('.hb-ucs-bundle__notice');
        if (error) {
            $notice.text(error).prop('hidden', false);
            $form.find('.hb-ucs-bundle__submit').prop('disabled', true).addClass('disabled');
        } else {
            $notice.text('').prop('hidden', true);
            $form.find('.hb-ucs-bundle__submit').prop('disabled', false).removeClass('disabled');
        }
        $bundle.trigger('hb_ucs_bundle_updated', [{selection: selection, total: total, regularTotal: regularTotal, valid: !error}]);
    }

    $(function () {
        $('.hb-ucs-bundle').each(function () {
            var $bundle = $(this);
            $bundle.on('change input', '.hb-ucs-bundle__qty, .hb-ucs-bundle__attribute', function () {
                update($bundle);
            });
            $bundle.closest('form.cart').on('change input', '.quantity .qty', function () {
                if (!$(this).hasClass('hb-ucs-bundle__qty')) {
                    update($bundle);
                }
            });
            update($bundle);
        });
    });
})(jQuery);
