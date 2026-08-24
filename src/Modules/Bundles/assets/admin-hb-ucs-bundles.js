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

    function fieldName(itemKey, field) {
        return 'hb_ucs_bundle_items[' + itemKey + '][' + field + ']';
    }

    function itemHtml(itemKey, id, title) {
        var labels = window.hbUcsBundlesAdmin || {};
        return '<article class="hb-ucs-bundle-item" data-key="' + esc(itemKey) + '">' +
            '<header><span class="hb-ucs-bundle-handle dashicons dashicons-move" aria-hidden="true"></span><strong>' + esc(title) + '</strong><button type="button" class="button-link-delete hb-ucs-bundle-remove">' + esc(labels.remove || 'Verwijderen') + '</button></header>' +
            '<input type="hidden" name="' + esc(fieldName(itemKey, 'id')) + '" value="' + esc(id) + '">' +
            '<input type="hidden" name="' + esc(fieldName(itemKey, 'sku')) + '" value="">' +
            '<div class="hb-ucs-bundle-item-grid">' +
            '<label><span>Standaardaantal</span><input type="number" min="0" step="any" name="' + esc(fieldName(itemKey, 'qty')) + '" value="1"></label>' +
            '<label class="hb-ucs-bundle-optional"><span>Type</span><select name="' + esc(fieldName(itemKey, 'optional')) + '"><option value="0">' + esc(labels.included || 'Inbegrepen') + '</option><option value="1">' + esc(labels.optional || 'Keuzeonderdeel') + '</option></select></label>' +
            '<label><span>Minimum</span><input type="number" min="0" step="any" name="' + esc(fieldName(itemKey, 'min')) + '" value="1"></label>' +
            '<label><span>Maximum</span><input type="number" min="0" step="any" name="' + esc(fieldName(itemKey, 'max')) + '" value="1"></label>' +
            '<label><span>Klanttitel</span><input type="text" name="' + esc(fieldName(itemKey, 'customer_title')) + '" placeholder="Leeg gebruikt de productnaam"></label>' +
            '<label><span>Label</span><input type="text" name="' + esc(fieldName(itemKey, 'badge')) + '" placeholder="Bijv. Meest gekozen"></label>' +
            '<label><span>Groep</span><input type="text" name="' + esc(fieldName(itemKey, 'group')) + '" placeholder="Bijv. Basis of Extra’s"></label>' +
            '<label class="hb-ucs-bundle-wide"><span>Uitleg voor klant</span><textarea rows="2" name="' + esc(fieldName(itemKey, 'customer_description')) + '"></textarea></label>' +
            '</div></article>';
    }

    function contentHtml(itemKey) {
        var labels = window.hbUcsBundlesAdmin || {};
        return '<article class="hb-ucs-bundle-item hb-ucs-bundle-content-item" data-key="' + esc(itemKey) + '">' +
            '<header><span class="hb-ucs-bundle-handle dashicons dashicons-move" aria-hidden="true"></span><strong>' + esc(labels.contentRow || 'Tekst of tussenkop') + '</strong><button type="button" class="button-link-delete hb-ucs-bundle-remove">' + esc(labels.remove || 'Verwijderen') + '</button></header>' +
            '<input type="hidden" name="' + esc(fieldName(itemKey, 'id')) + '" value="0">' +
            '<div class="hb-ucs-bundle-item-grid"><label><span>Opmaak</span><select name="' + esc(fieldName(itemKey, 'type')) + '"><option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option><option value="h4">H4</option><option value="h5">H5</option><option value="h6">H6</option><option value="p" selected>Alinea</option><option value="span">Korte tekst</option><option value="none">Geen extra opmaak</option></select></label>' +
            '<label class="hb-ucs-bundle-wide"><span>Tekst</span><textarea rows="2" name="' + esc(fieldName(itemKey, 'text')) + '"></textarea></label></div></article>';
    }

    function refreshEmpty($items) {
        $items.toggleClass('is-empty', !$items.children('.hb-ucs-bundle-item').length);
    }

    $(function () {
        var $items = $('.hb-ucs-bundle-items');
        if (!$items.length) return;

        $items.sortable({
            handle: '.hb-ucs-bundle-handle',
            items: '> .hb-ucs-bundle-item',
            placeholder: 'hb-ucs-bundle-sort-placeholder'
        });
        refreshEmpty($items);

        $(document).on('click', '.hb-ucs-bundle-add', function () {
            var $search = $('.hb-ucs-bundle-search');
            $search.find('option:selected').each(function () {
                var id = parseInt($(this).val(), 10);
                if (!id) return;
                $items.append(itemHtml(key(), id, $(this).text()));
            });
            $search.val(null).trigger('change');
            refreshEmpty($items);
        });

        $(document).on('click', '.hb-ucs-bundle-add-content', function () {
            $items.append(contentHtml(key()));
            refreshEmpty($items);
        });

        $(document).on('click', '.hb-ucs-bundle-remove', function () {
            $(this).closest('.hb-ucs-bundle-item').remove();
            refreshEmpty($items);
        });

        $(document).on('change', '.hb-ucs-bundle-optional select', function () {
            var $item = $(this).closest('.hb-ucs-bundle-item');
            var optional = $(this).val() === '1';
            if (!optional) {
                var qty = $item.find('[name$="[qty]"]').val() || 1;
                $item.find('[name$="[min]"]').val(qty);
                $item.find('[name$="[max]"]').val(qty);
            }
            $item.toggleClass('is-optional', optional);
        });

        $('#product-type').on('change', function () {
            if ($(this).val() === 'woosb') {
                $('.show_if_simple, .show_if_woosb').show();
                $('.show_if_external').hide();
            }
        }).trigger('change');
    });
})(jQuery);
