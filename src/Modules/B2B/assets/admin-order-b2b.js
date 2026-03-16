/* global HB_UCS_B2B_ORDER */
jQuery(function ($) {
  function getOrderItemIdFromInputName(name) {
    if (!name || typeof name !== 'string') return 0;
    const m = name.match(/\[(\d+)\]/);
    if (!m || !m[1]) return 0;
    const id = parseInt(m[1], 10);
    return id > 0 ? id : 0;
  }

  function ensureManualPriceLockField(itemId, $context) {
    if (!itemId || itemId <= 0) return;
    const fieldName = 'hb_ucs_b2b_manual_price_lock[' + itemId + ']';
    const $root = ($context && $context.length) ? $context : $('#woocommerce-order-items');
    if (!$root.length) return;

    if ($root.find('input[type="hidden"][name="' + fieldName + '"]').length) return;

    $root.append('<input type="hidden" name="' + fieldName + '" value="1" />');
  }

  function showNotice(message, type) {
    type = type || 'warning';
    const $wrap = $('#woocommerce-order-data');
    if (!$wrap.length) {
      alert(message);
      return;
    }
    $wrap.find('.hb-ucs-b2b-order-notice').remove();
    const $n = $('<div class="notice notice-' + type + ' inline hb-ucs-b2b-order-notice"><p></p></div>');
    $n.find('p').text(message);
    $wrap.prepend($n);
  }

  function hasUnsavedOrderItems() {
    const $save = $('#save_order_items');
    // In WooCommerce order edit screen, this becomes enabled when there are pending item changes.
    if ($save.length && !$save.prop('disabled')) {
      return true;
    }
    return false;
  }

  function getOrderId($btn) {
    const fromBtn = $btn && $btn.length ? parseInt($btn.data('order-id'), 10) : 0;
    if (fromBtn && fromBtn > 0) return fromBtn;
    if (window.woocommerce_admin_meta_boxes && window.woocommerce_admin_meta_boxes.post_id) {
      const pid = parseInt(window.woocommerce_admin_meta_boxes.post_id, 10);
      if (pid > 0) return pid;
    }
    return 0;
  }

  function getCustomerId() {
    const v = $('#customer_user').val();
    const cid = parseInt(v || '0', 10);
    return cid > 0 ? cid : 0;
  }

  function saveLineItemsIfNeeded() {
    // Save current (edited) line items so recalculation never drops unsaved changes.
    if (!hasUnsavedOrderItems()) {
      return $.Deferred().resolve().promise();
    }

    if (!window.woocommerce_admin_meta_boxes || !window.woocommerce_admin_meta_boxes.ajax_url) {
      return $.Deferred().reject('missing_wc_ajax').promise();
    }

    const data = {
      order_id: window.woocommerce_admin_meta_boxes.post_id,
      items: $('table.woocommerce_order_items :input[name], .wc-order-totals-items :input[name], #woocommerce-order-items input[name^="hb_ucs_b2b_manual_price_lock["]').serialize(),
      action: 'woocommerce_save_order_items',
      security: window.woocommerce_admin_meta_boxes.order_item_nonce
    };

    const dfd = $.Deferred();

    if (window.wc_meta_boxes_order_items && window.wc_meta_boxes_order_items.block) {
      window.wc_meta_boxes_order_items.block();
    }

    $.ajax({
      url: window.woocommerce_admin_meta_boxes.ajax_url,
      data: data,
      type: 'POST'
    })
      .done(function (resp) {
        if (resp && resp.success && resp.data && resp.data.html) {
          $('#woocommerce-order-items').find('.inside').empty().append(resp.data.html);
          if (resp.data.notes_html) {
            $('ul.order_notes').empty().append($(resp.data.notes_html).find('li'));
          }
          if (window.wc_meta_boxes_order_items && window.wc_meta_boxes_order_items.reloaded_items) {
            window.wc_meta_boxes_order_items.reloaded_items();
          }
          dfd.resolve();
          return;
        }
        dfd.reject('save_failed');
      })
      .fail(function () {
        dfd.reject('save_failed');
      })
      .always(function () {
        if (window.wc_meta_boxes_order_items && window.wc_meta_boxes_order_items.unblock) {
          window.wc_meta_boxes_order_items.unblock();
        }
      });

    return dfd.promise();
  }

  function doRecalc($btn, opts) {
    opts = opts || {};
    const orderId = getOrderId($btn);
    const customerId = getCustomerId();

    if (!HB_UCS_B2B_ORDER || !HB_UCS_B2B_ORDER.ajaxUrl) return;

    if (!orderId || parseInt(orderId, 10) <= 0) {
      showNotice(HB_UCS_B2B_ORDER.i18n.missingOrder || 'Order is nog niet opgeslagen.', 'warning');
      return;
    }

    // Save line items first if needed, then recalc.
    if (HB_UCS_B2B_ORDER._recalcInFlight) return;
    HB_UCS_B2B_ORDER._recalcInFlight = true;

    const oldText = $btn.text();
    $btn.prop('disabled', true).text(HB_UCS_B2B_ORDER.i18n.working || 'Working…');

    saveLineItemsIfNeeded()
      .done(function () {
        // Then B2B recalc.
        $.post(HB_UCS_B2B_ORDER.ajaxUrl, {
          action: 'hb_ucs_b2b_recalc_order_prices',
          nonce: HB_UCS_B2B_ORDER.nonce,
          order_id: orderId,
          customer_id: customerId,
          force: opts.force ? 1 : 0
        })
          .done(function (resp) {
            if (resp && resp.success) {
              if (resp.data && resp.data.html) {
                $('#woocommerce-order-items').find('.inside').empty().append(resp.data.html);
                if (window.wc_meta_boxes_order_items && window.wc_meta_boxes_order_items.reloaded_items) {
                  window.wc_meta_boxes_order_items.reloaded_items();
                }
              }
              showNotice(HB_UCS_B2B_ORDER.i18n.done || 'Prijzen herberekend.', 'success');
              return;
            }
            showNotice((HB_UCS_B2B_ORDER.i18n.failed || 'Herberekenen mislukt.') + ' ' + (resp && resp.data && resp.data.message ? resp.data.message : ''), 'error');
          })
          .fail(function () {
            showNotice(HB_UCS_B2B_ORDER.i18n.failed || 'Herberekenen mislukt.', 'error');
          })
          .always(function () {
            HB_UCS_B2B_ORDER._recalcInFlight = false;
            $btn.prop('disabled', false).text(oldText);
          });
      })
      .fail(function () {
        showNotice(HB_UCS_B2B_ORDER.i18n.needsSaveItems || 'Sla eerst de orderregels op (knop “Order items opslaan”) en probeer daarna opnieuw.', 'error');
        HB_UCS_B2B_ORDER._recalcInFlight = false;
        $btn.prop('disabled', false).text(oldText);
      });
  }

  function shouldAutoRecalc() {
    return !!(HB_UCS_B2B_ORDER && HB_UCS_B2B_ORDER.autoRecalcOnCustomerChange);
  }

  let recalcTimer = null;
  function queueRecalc(reason) {
    if (!shouldAutoRecalc()) return;
    const $btn = $('#hb-ucs-b2b-recalc');
    if (!$btn.length) return;
    if (recalcTimer) clearTimeout(recalcTimer);
    recalcTimer = setTimeout(function () {
      doRecalc($btn, { reason: reason || '' });
    }, 250);
  }

  // Track manual edits to line item totals/subtotals and lock those items, so B2B recalc won't overwrite them.
  $(document).on('input change', '#woocommerce-order-items input.line_total, #woocommerce-order-items input.line_subtotal', function () {
    const name = $(this).attr('name') || '';
    const itemId = getOrderItemIdFromInputName(name);
    if (itemId > 0) {
      ensureManualPriceLockField(itemId, $('#woocommerce-order-items'));
    }
  });

  $(document).on('click', '#hb-ucs-b2b-recalc', function (e) {
    // Shift-click forces recalculation even for locked items (overwrites manual prices).
    doRecalc($(this), { force: !!(e && e.shiftKey) });
  });

  // Optional auto recalculation when customer changes.
  $(document).on('change', '#customer_user', function () {
    queueRecalc('customer_change');
  });

  // Auto recalc when items are added/removed/saved, so customer-first flows add correct prices.
  $(document).ajaxSuccess(function (event, xhr, settings) {
    if (!shouldAutoRecalc()) return;
    if (!settings || !settings.data) return;
    if (HB_UCS_B2B_ORDER && HB_UCS_B2B_ORDER._recalcInFlight) return;

    const dataStr = (typeof settings.data === 'string') ? settings.data : '';
    if (!dataStr) return;

    // Ignore our own recalc requests.
    if (dataStr.indexOf('action=hb_ucs_b2b_recalc_order_prices') !== -1) return;

    const triggers = [
      'action=woocommerce_add_order_item',
      'action=woocommerce_remove_order_item',
      'action=woocommerce_save_order_items'
    ];
    for (let i = 0; i < triggers.length; i++) {
      if (dataStr.indexOf(triggers[i]) !== -1) {
        // Only when a customer is selected (else recalcing is still ok, but noisy).
        queueRecalc('items_changed');
        break;
      }
    }
  });
});
