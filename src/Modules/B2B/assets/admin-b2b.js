/* global HB_UCS_B2B_ADMIN, ajaxurl */
jQuery(function ($) {
  let syncingOrder = false;

  function reorderOptionsByValues($select, values) {
    if (!values || !values.length) return;
    // Reorder selected <option> elements in DOM so PHP receives values in this order.
    values.forEach((val) => {
      const v = String(val);
      const $opt = $select.find('option').filter(function () {
        return String($(this).val()) === v;
      });
      if ($opt.length) {
        $opt.detach().appendTo($select);
      }
    });
  }

  function initEnhanced() {
    try {
      // Trigger WooCommerce's standard init (handles wc-enhanced-select + searches).
      $(document.body).trigger('wc-enhanced-select-init');

      // Fallback: if Woo's init didn't run for some reason, enhance basic selects ourselves.
      if ($.fn.selectWoo) {
        $('select.wc-enhanced-select')
          .filter(':not(.enhanced)')
          .each(function () {
            const $el = $(this);
            $el.selectWoo({
              width: 'resolve',
              closeOnSelect: false,
              allowClear: true,
            }).addClass('enhanced');
          });
      }
    } catch (e) {}
  }

  function initSortableEnhancedSelects() {
    if (!$.fn.sortable) return;

    $('select.hb-ucs-sortable-select').each(function () {
      const $select = $(this);

      // Only when Select2/SelectWoo has enhanced the select.
      if (!$select.hasClass('select2-hidden-accessible')) return;

      const $container = $select.next('.select2');
      const $ul = $container.find('ul.select2-selection__rendered');
      if (!$ul.length) return;
      if ($ul.data('hbUcsSortable')) return;
      $ul.data('hbUcsSortable', 1);

      // Make selected choices sortable.
      $ul.sortable({
        items: 'li.select2-selection__choice',
        tolerance: 'pointer',
        stop: function () {
          if (syncingOrder) return;
          syncingOrder = true;
          try {
            const ids = [];
            $ul.find('li.select2-selection__choice').each(function () {
              const data = $(this).data('data');
              if (data && data.id != null) {
                ids.push(String(data.id));
              }
            });

            const current = $select.val();
            const fallback = Array.isArray(current) ? current.map(String) : [];
            const ordered = ids.length ? ids : fallback;

            reorderOptionsByValues($select, ordered);
            // Keep select value in sync (without infinite loop).
            $select.val(ordered).trigger('change.select2');
          } finally {
            syncingOrder = false;
          }
        },
      });
    });
  }

  initEnhanced();
  initSortableEnhancedSelects();

  // Keep DOM option order in sync with selection order.
  $(document).on('change', 'select.hb-ucs-sortable-select', function () {
    if (syncingOrder) return;
    const $select = $(this);
    const val = $select.val();
    const values = Array.isArray(val) ? val.map(String) : [];
    reorderOptionsByValues($select, values);
  });

  // Customer select on customers tab: redirect with user_id.
  $(document).on('select2:select', '.wc-customer-search', function (e) {
    const data = e.params && e.params.data ? e.params.data : null;
    if (!data || !data.id) return;

    // Only redirect on the customers tab selector (first one on page)
    const page = new URL(window.location.href);
    if (page.searchParams.get('page') !== 'hb-ucs-b2b') return;
    if (page.searchParams.get('tab') !== 'customers') return;

    page.searchParams.set('user_id', String(data.id));
    window.location.href = page.toString();
  });

  // Add linked user on profile edit.
  $('#hb-ucs-b2b-add-linked-user').on('click', function () {
    const $select = $(this).closest('p').find('.wc-customer-search');
    const val = $select.val();
    if (!val) return;

    const text = $select.find('option:selected').text() || ('User #' + val);
    const $wrap = $('.hb-ucs-b2b-linked-users');

    // avoid duplicates
    if ($wrap.find('input[value="' + val + '"]').length) return;

    const $row = $('<div class="hb-ucs-b2b-linked-user" />');
    $row.append('<input type="hidden" name="profile[linked_users][]" value="' + String(val).replace(/"/g, '') + '">');
    $row.append('<span />').find('span').text(text);
    $row.append(' ');
    $row.append('<button type="button" class="button-link-delete hb-ucs-b2b-remove-linked-user">Verwijderen</button>');

    $wrap.append($row);

    $select.val(null).trigger('change');
  });

  $(document).on('click', '.hb-ucs-b2b-remove-linked-user', function () {
    $(this).closest('.hb-ucs-b2b-linked-user').remove();
  });

  // Repeatable pricing rows.
  function buildTypeSelect(name) {
    const types = (HB_UCS_B2B_ADMIN && HB_UCS_B2B_ADMIN.priceTypes) ? HB_UCS_B2B_ADMIN.priceTypes : [];
    const $sel = $('<select />').attr('name', name);
    types.forEach(t => {
      $sel.append($('<option />').attr('value', t.id).text(t.label));
    });
    return $sel;
  }

  $(document).on('click', '.hb-ucs-b2b-add-row', function () {
    const kind = $(this).data('kind');
    const $table = $('.hb-ucs-b2b-rules-table[data-kind="' + kind + '"]');
    const $tbody = $table.find('tbody');

    const $tr = $('<tr />');

    if (kind === 'category') {
      const opts = (HB_UCS_B2B_ADMIN && HB_UCS_B2B_ADMIN.categoriesOptionsHtml) ? HB_UCS_B2B_ADMIN.categoriesOptionsHtml : '<option value="0">—</option>';
      const $cat = $('<select class="hb-ucs-b2b-category-select" name="pricing_categories[id][]" />').html(opts);
      $tr.append($('<td />').append($cat));
      $tr.append($('<td />').append(buildTypeSelect('pricing_categories[type][]')));
      $tr.append($('<td />').append('<input type="number" step="0.01" min="0" name="pricing_categories[value][]" value="">'));
    }

    if (kind === 'product') {
      const $sel = $('<select class="wc-product-search" style="width: 320px;" name="pricing_products[id][]" data-placeholder="Zoek product…" data-allow_clear="true" data-action="woocommerce_json_search_products_and_variations" />');
      $tr.append($('<td />').append($sel));
      $tr.append($('<td />').append(buildTypeSelect('pricing_products[type][]')));
      $tr.append($('<td />').append('<input type="number" step="0.01" min="0" name="pricing_products[value][]" value="">'));
    }

    $tr.append($('<td />').append('<button type="button" class="button-link-delete hb-ucs-b2b-remove-row">Verwijderen</button>'));

    $tbody.append($tr);
    initEnhanced();
    initSortableEnhancedSelects();
  });

  $(document).on('click', '.hb-ucs-b2b-remove-row', function () {
    $(this).closest('tr').remove();
  });
});
