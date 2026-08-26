(function ($) {
    'use strict';

    function key() {
        if (window.crypto && window.crypto.getRandomValues) {
            var values = new Uint32Array(2);
            window.crypto.getRandomValues(values);
            return 'item_' + values[0].toString(36) + values[1].toString(36);
        }
        return 'item_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 8);
    }

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    function label(name, fallback) {
        var labels = window.hbUcsBundlesAdmin || {};
        return labels[name] || fallback;
    }

    function fieldName(itemKey, field) {
        return 'hb_ucs_bundle_items[' + itemKey + '][' + field + ']';
    }

    function itemHeader(itemKey, title, state, content) {
        var thumb = content
            ? '<span class="hb-ucs-bundle-item__thumb hb-ucs-bundle-item__thumb--content"><span class="dashicons dashicons-editor-textcolor" aria-hidden="true"></span></span>'
            : '<span class="hb-ucs-bundle-item__thumb hb-ucs-bundle-item__thumb--placeholder"><span class="dashicons dashicons-products" aria-hidden="true"></span></span>';

        return '<header class="hb-ucs-bundle-item__header">' +
            '<span class="hb-ucs-bundle-handle dashicons dashicons-move" role="button" tabindex="0" aria-label="Onderdeel verslepen"></span>' +
            '<span class="hb-ucs-bundle-item__index" aria-hidden="true"></span>' +
            thumb +
            '<span class="hb-ucs-bundle-item__heading"><strong>' + esc(title) + '</strong><span class="hb-ucs-bundle-item__meta">' +
            esc(content ? 'Verduidelijkt de samenstelling voor de klant' : 'Nieuw onderdeel — sla het product op voor alle productgegevens') +
            '</span></span>' +
            '<span class="hb-ucs-bundle-item__state">' + esc(state) + '</span>' +
            '<button type="button" class="button-link hb-ucs-bundle-toggle" aria-expanded="true" aria-label="' + esc(label('collapse', 'Onderdeel inklappen')) + '"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>' +
            '<button type="button" class="button-link-delete hb-ucs-bundle-remove"><span class="dashicons dashicons-trash" aria-hidden="true"></span><span class="screen-reader-text">' + esc(label('remove', 'Verwijderen')) + '</span></button>' +
            '</header>';
    }

    function itemHtml(itemKey, id, title) {
        return '<article class="hb-ucs-bundle-item" data-key="' + esc(itemKey) + '">' +
            itemHeader(itemKey, title, label('included', 'Vast inbegrepen'), false) +
            '<input type="hidden" name="' + esc(fieldName(itemKey, 'id')) + '" value="' + esc(id) + '">' +
            '<input type="hidden" name="' + esc(fieldName(itemKey, 'sku')) + '" value="">' +
            '<div class="hb-ucs-bundle-item__body"><div class="hb-ucs-bundle-item-grid">' +
            '<label><span>' + esc(label('defaultQuantity', 'Standaardaantal')) + '</span><input type="number" min="0" step="any" name="' + esc(fieldName(itemKey, 'qty')) + '" value="1"></label>' +
            '<label class="hb-ucs-bundle-optional"><span>' + esc(label('type', 'Type')) + '</span><select name="' + esc(fieldName(itemKey, 'optional')) + '"><option value="0">' + esc(label('included', 'Vast inbegrepen')) + '</option><option value="1">' + esc(label('optional', 'Keuzeonderdeel')) + '</option></select></label>' +
            '<label><span>' + esc(label('minimum', 'Minimum')) + '</span><input type="number" min="0" step="any" name="' + esc(fieldName(itemKey, 'min')) + '" value="1"></label>' +
            '<label><span>' + esc(label('maximum', 'Maximum')) + '</span><input type="number" min="0" step="any" name="' + esc(fieldName(itemKey, 'max')) + '" value="1"></label>' +
            '<label><span>' + esc(label('customerTitle', 'Klanttitel')) + '</span><input type="text" name="' + esc(fieldName(itemKey, 'customer_title')) + '" placeholder="' + esc(label('customerTitlePlaceholder', 'Leeg gebruikt de productnaam')) + '"></label>' +
            '<label><span>' + esc(label('badge', 'Label')) + '</span><input type="text" name="' + esc(fieldName(itemKey, 'badge')) + '" placeholder="' + esc(label('badgePlaceholder', 'Bijv. Meest gekozen')) + '"></label>' +
            '<label><span>' + esc(label('group', 'Groep')) + '</span><input type="text" name="' + esc(fieldName(itemKey, 'group')) + '" placeholder="' + esc(label('groupPlaceholder', 'Bijv. Basis of Extra’s')) + '"></label>' +
            '<label class="hb-ucs-bundle-wide"><span>' + esc(label('customerDescription', 'Uitleg voor klant')) + '</span><textarea rows="3" name="' + esc(fieldName(itemKey, 'customer_description')) + '"></textarea><small>Houd deze uitleg kort; de klant ziet hem direct onder de productnaam.</small></label>' +
            '</div></div></article>';
    }

    function contentHtml(itemKey) {
        return '<article class="hb-ucs-bundle-item hb-ucs-bundle-content-item" data-key="' + esc(itemKey) + '">' +
            itemHeader(itemKey, label('contentRow', 'Tekst of tussenkop'), label('content', 'Inhoud'), true) +
            '<input type="hidden" name="' + esc(fieldName(itemKey, 'id')) + '" value="0">' +
            '<div class="hb-ucs-bundle-item__body"><div class="hb-ucs-bundle-item-grid"><label><span>' + esc(label('format', 'Opmaak')) + '</span><select name="' + esc(fieldName(itemKey, 'type')) + '"><option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option><option value="h4">H4</option><option value="h5">H5</option><option value="h6">H6</option><option value="p" selected>' + esc(label('paragraph', 'Alinea')) + '</option><option value="span">' + esc(label('shortText', 'Korte tekst')) + '</option><option value="none">' + esc(label('noMarkup', 'Geen extra opmaak')) + '</option></select></label>' +
            '<label class="hb-ucs-bundle-wide"><span>' + esc(label('text', 'Tekst')) + '</span><textarea rows="3" name="' + esc(fieldName(itemKey, 'text')) + '"></textarea></label></div></div></article>';
    }

    function announce(message) {
        $('.hb-ucs-bundle-status').text('').text(message);
    }

    function setItemState($item) {
        if ($item.hasClass('hb-ucs-bundle-content-item')) return;
        var optional = $item.find('.hb-ucs-bundle-optional select').val() === '1';
        $item.toggleClass('is-optional', optional);
        $item.find('.hb-ucs-bundle-item__state').text(optional ? label('optional', 'Keuzeonderdeel') : label('included', 'Vast inbegrepen'));
    }

    function refreshUi($items) {
        var $all = $items.children('.hb-ucs-bundle-item');
        var count = $all.length;
        $items.toggleClass('is-empty', count === 0);
        $all.each(function (index) {
            $(this).find('.hb-ucs-bundle-item__index').first().text(index + 1);
            setItemState($(this));
        });
        var $count = $('.hb-ucs-bundle-builder__count');
        $count.find('strong').text(count);
        var $label = $count.find('span');
        $label.text(count === 1 ? ($label.data('singular') || label('itemSingular', 'onderdeel')) : ($label.data('plural') || label('itemPlural', 'onderdelen')));
    }

    $(function () {
        var $items = $('.hb-ucs-bundle-items');
        if (!$items.length) return;

        $items.sortable({
            handle: '.hb-ucs-bundle-handle',
            items: '> .hb-ucs-bundle-item',
            placeholder: 'hb-ucs-bundle-sort-placeholder',
            forcePlaceholderSize: true,
            update: function () {
                refreshUi($items);
            }
        });
        refreshUi($items);

        $(document).on('change', '.hb-ucs-bundle-search', function () {
            $('.hb-ucs-bundle-add').prop('disabled', !$(this).find('option:selected').length);
        });

        $(document).on('click', '.hb-ucs-bundle-add', function () {
            var $search = $('.hb-ucs-bundle-search');
            var added = 0;
            $search.find('option:selected').each(function () {
                var id = parseInt($(this).val(), 10);
                if (!id) return;
                $items.append(itemHtml(key(), id, $(this).text()));
                added++;
            });
            $search.val(null).trigger('change');
            refreshUi($items);
            if (added) {
                announce(label('added', 'Onderdeel toegevoegd.'));
                $items.children('.hb-ucs-bundle-item').last().find('input, select, textarea').first().trigger('focus');
            }
        });

        $(document).on('click', '.hb-ucs-bundle-add-content', function () {
            $items.append(contentHtml(key()));
            refreshUi($items);
            announce(label('added', 'Onderdeel toegevoegd.'));
            $items.children('.hb-ucs-bundle-item').last().find('select').first().trigger('focus');
        });

        $(document).on('click', '.hb-ucs-bundle-remove', function () {
            $(this).closest('.hb-ucs-bundle-item').remove();
            refreshUi($items);
            announce(label('removed', 'Onderdeel verwijderd.'));
        });

        $(document).on('click', '.hb-ucs-bundle-toggle', function () {
            var $button = $(this);
            var $item = $button.closest('.hb-ucs-bundle-item');
            var collapsed = !$item.hasClass('is-collapsed');
            $item.toggleClass('is-collapsed', collapsed);
            $button.attr('aria-expanded', collapsed ? 'false' : 'true');
            $button.attr('aria-label', collapsed ? label('expand', 'Onderdeel uitklappen') : label('collapse', 'Onderdeel inklappen'));
            $button.find('.dashicons').toggleClass('dashicons-arrow-down-alt2', collapsed).toggleClass('dashicons-arrow-up-alt2', !collapsed);
        });

        $(document).on('change', '.hb-ucs-bundle-optional select', function () {
            var $item = $(this).closest('.hb-ucs-bundle-item');
            var optional = $(this).val() === '1';
            var qty = $item.find('[name$="[qty]"]').val() || 1;
            if (optional) {
                $item.find('[name$="[min]"]').val(0);
                if (!$item.find('[name$="[max]"]').val()) {
                    $item.find('[name$="[max]"]').val(qty);
                }
            } else {
                $item.find('[name$="[min]"]').val(qty);
                $item.find('[name$="[max]"]').val(qty);
            }
            setItemState($item);
        });

        $('#product-type').on('change', function () {
            if ($(this).val() === 'woosb') {
                $('.show_if_simple, .show_if_woosb').show();
                $('.show_if_external').hide();
            }
        }).trigger('change');
    });
})(jQuery);
