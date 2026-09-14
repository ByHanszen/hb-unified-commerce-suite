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
        var groupStatuses = [];
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
            $item.toggleClass('has-quantity', qty > 0);
            $item.find('.hb-ucs-bundle__stepper output').text(quantity(qty));

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

        $bundle.find('.hb-ucs-bundle__choice-group').each(function () {
            var $group = $(this);
            var group = parseJson($group.attr('data-group'), {});
            var groupTotal = 0;
            var selectedRows = 0;
            $group.find('.hb-ucs-bundle__item').each(function () {
                var $item = $(this);
                var qty = Number($item.find('.hb-ucs-bundle__qty').val() || 0);
                groupTotal += qty;
                if (qty > 0) selectedRows++;
            });
            $group.find('.hb-ucs-bundle__item').each(function () {
                var $item = $(this);
                var component = parseJson($item.attr('data-component'), {});
                var qty = Number($item.find('.hb-ucs-bundle__qty').val() || 0);
                var itemMax = Number(component.max || group.max || 0);
                var stockBlocked = Number(component.stock) >= 0 && ((qty + 1) * bundleQty) > Number(component.stock);
                $item.find('.hb-ucs-bundle__plus').prop('disabled', stockBlocked || groupTotal >= Number(group.max || 0) || (itemMax > 0 && qty >= itemMax));
                $item.find('.hb-ucs-bundle__add-choice').prop('disabled', stockBlocked || groupTotal >= Number(group.max || 0) || (itemMax > 0 && qty >= itemMax));
            });
            var min = Number(group.min || 0);
            var max = Number(group.max || 0);
            var remaining = Math.max(0, min - groupTotal);
            var available = Math.max(0, max - groupTotal);
            var groupError = '';
            var message = '';
            if (group.type === 'single' && min > 0 && groupTotal < 1) {
                groupError = cfg.singleRequired || 'Kies één optie.';
                message = groupError;
            } else if (groupTotal < min) {
                groupError = (cfg.minimumRemaining || 'Kies nog minimaal %s.').replace('%s', quantity(remaining));
                message = groupError;
            } else if (groupTotal > max || (group.type === 'single' && selectedRows > 1)) {
                groupError = cfg.quantityError || 'Controleer de gekozen aantallen.';
                message = groupError;
            } else if (groupTotal >= max) {
                message = '✓ ' + (cfg.groupComplete || 'Deze stap is compleet.');
            } else {
                message = '✓ ' + (cfg.minimumReached || 'Minimum bereikt. Je kunt nog %s toevoegen.').replace('%s', quantity(available));
            }
            if (groupError) error = error || groupError;
            $group.toggleClass('has-error', !!groupError).toggleClass('is-complete', !groupError && groupTotal >= min);
            $group.find('.hb-ucs-bundle__progress strong').text(quantity(groupTotal) + ' van ' + quantity(max) + ' gekozen');
            $group.find('.hb-ucs-bundle__progress span').text(message);
            groupStatuses.push({title: String(group.title || group.group_id || ''), total: groupTotal, max: max, message: message, valid: !groupError});
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
        var $summaryGroups = $bundle.find('.hb-ucs-bundle__summary-groups').empty();
        $.each(groupStatuses, function (_, status) {
            $('<p>').toggleClass('is-complete', status.valid).text(status.title + ': ' + quantity(status.total) + ' van ' + quantity(status.max)).appendTo($summaryGroups);
        });

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
        $bundle.find('.hb-ucs-bundle__mobile-total, .hb-ucs-bundle__mobile-sheet-total strong').text(money(total));
        $bundle.find('.hb-ucs-bundle__mobile-progress').text(groupStatuses.length ? groupStatuses.map(function (status) { return quantity(status.total) + '/' + quantity(status.max); }).join(' · ') : quantity(count));
        var $mobileList = $bundle.find('.hb-ucs-bundle__mobile-list').empty();
        $.each(summary, function (_, row) { $('<li>').text(quantity(row.qty) + ' × ' + row.title + (row.choices ? ' — ' + row.choices : '')).appendTo($mobileList); });
        var $mobileGroups = $bundle.find('.hb-ucs-bundle__mobile-groups').empty();
        $.each(groupStatuses, function (_, status) { $('<p>').toggleClass('is-complete', status.valid).text(status.title + ': ' + status.message).appendTo($mobileGroups); });
        var $notice = $bundle.find('.hb-ucs-bundle__notice');
        if (error) {
            $notice.text(error).prop('hidden', false);
            $form.find('.hb-ucs-bundle__submit').prop('disabled', true).addClass('disabled');
            $bundle.find('.hb-ucs-bundle__mobile-submit, .hb-ucs-bundle__summary-submit').prop('disabled', true);
        } else {
            $notice.text('').prop('hidden', true);
            $form.find('.hb-ucs-bundle__submit').prop('disabled', false).removeClass('disabled');
            $bundle.find('.hb-ucs-bundle__mobile-submit, .hb-ucs-bundle__summary-submit').prop('disabled', false);
        }
        $bundle.trigger('hb_ucs_bundle_updated', [{selection: selection, total: total, regularTotal: regularTotal, valid: !error}]);
    }

    $(function () {
        $('.hb-ucs-bundle').each(function () {
            var $bundle = $(this);
            $bundle.on('change input', '.hb-ucs-bundle__qty, .hb-ucs-bundle__attribute', function () {
                update($bundle);
            });
            $bundle.on('click', '.hb-ucs-bundle__add-choice, .hb-ucs-bundle__plus, .hb-ucs-bundle__minus', function () {
                var $item = $(this).closest('.hb-ucs-bundle__item');
                var $qty = $item.find('.hb-ucs-bundle__qty');
                var component = parseJson($item.attr('data-component'), {});
                var value = Number($qty.val() || 0) + ($(this).hasClass('hb-ucs-bundle__minus') ? -1 : 1);
                var bundleQty = Math.max(1, Number($item.closest('form.cart').find('.quantity .qty').not('.hb-ucs-bundle__qty').first().val() || 1));
                var maximum = Number(component.max || 999999);
                if (Number(component.stock) >= 0) maximum = Math.min(maximum, Math.floor(Number(component.stock) / bundleQty));
                value = Math.max(0, Math.min(maximum, value));
                $qty.val(value);
                update($bundle);
            });
            $bundle.on('change', '.hb-ucs-bundle__single-control input', function () {
                var $group = $(this).closest('.hb-ucs-bundle__choice-group');
                $group.find('.hb-ucs-bundle__qty').val(0);
                $(this).closest('.hb-ucs-bundle__item').find('.hb-ucs-bundle__qty').val(this.checked ? 1 : 0);
                update($bundle);
            });
            $bundle.closest('form.cart').on('change input', '.quantity .qty', function () {
                if (!$(this).hasClass('hb-ucs-bundle__qty')) {
                    update($bundle);
                }
            });
            update($bundle);
        });
        $(document).on('click', '.hb-ucs-bundle__mobile-open', function () {
            var $bundle = $(this).closest('.hb-ucs-bundle');
            $bundle.find('.hb-ucs-bundle__mobile-sheet, .hb-ucs-bundle__mobile-backdrop').prop('hidden', false);
            $(this).attr('aria-expanded', 'true');
            $bundle.find('.hb-ucs-bundle__mobile-close').trigger('focus');
        });
        $(document).on('click', '.hb-ucs-bundle__mobile-close, .hb-ucs-bundle__mobile-backdrop', function () {
            var $bundle = $(this).closest('.hb-ucs-bundle');
            $bundle.find('.hb-ucs-bundle__mobile-sheet, .hb-ucs-bundle__mobile-backdrop').prop('hidden', true);
            $bundle.find('.hb-ucs-bundle__mobile-open').attr('aria-expanded', 'false').trigger('focus');
        });
        $(document).on('keydown', '.hb-ucs-bundle__mobile-sheet', function (event) {
            if (event.key === 'Escape') {
                $(this).find('.hb-ucs-bundle__mobile-close').trigger('click');
                return;
            }
            if (event.key === 'Tab') {
                var $focusable = $(this).find('button:not(:disabled), a[href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled)').filter(':visible');
                if (!$focusable.length) return;
                var first = $focusable.get(0), last = $focusable.get($focusable.length - 1);
                if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
            }
        });
        $(document).on('click', '.hb-ucs-bundle__mobile-submit', function () {
            $(this).closest('form.cart').find('.hb-ucs-bundle__submit').trigger('click');
        });
        $(document).on('click', '.hb-ucs-bundle__summary-submit', function () {
            $(this).closest('form.cart').find('.hb-ucs-bundle__submit').trigger('click');
        });
    });
})(jQuery);
