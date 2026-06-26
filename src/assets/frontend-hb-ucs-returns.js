(function ($) {
  'use strict';

  function getMessages() {
    return (window.hbUcsReturns && window.hbUcsReturns.messages) || {};
  }

  function getAjaxConfig() {
    return window.hbUcsReturns || {};
  }

  function setFeedback($root, message, type) {
    var $feedback = $root.find('.hb-ucs-returns__feedback');
    if (!message) {
      $feedback.attr('hidden', true).removeClass('is-error is-success is-info').empty();
      return;
    }

    $feedback.removeAttr('hidden').removeClass('is-error is-success is-info').addClass('is-' + type).text(message);
  }

  function setStep($root, step) {
    var index = step === 'lookup' ? 0 : (step === 'items' ? 1 : 2);
    $root.find('.hb-ucs-returns__steps li').removeClass('is-active is-done').each(function (i) {
      if (i < index) {
        $(this).addClass('is-done');
      }
      if (i === index) {
        $(this).addClass('is-active');
      }
    });

    $root.find('[data-step="lookup"]').attr('hidden', step !== 'lookup');
    $root.find('[data-step="items"]').attr('hidden', step !== 'items');
    $root.find('[data-step="success"]').attr('hidden', step !== 'success');
  }

  function renderOrderSummary(order, messages) {
    return [
      '<div class="hb-ucs-returns__summary-grid">',
      '<div><span>' + messages.orderSummaryLabel + '</span><strong>#' + order.order_number + '</strong></div>',
      '<div><span>' + messages.placedOnLabel + '</span><strong>' + (order.placed_on || '-') + '</strong></div>',
      '<div><span>' + messages.completedOnLabel + '</span><strong>' + (order.completed_on || '-') + '</strong></div>',
      '<div><span>' + messages.deliveryLabel + '</span><strong>' + (order.estimated_delivery || '-') + '</strong></div>',
      '<div><span>' + messages.deadlineLabel + '</span><strong>' + (order.return_deadline || '-') + '</strong></div>',
      '</div>'
    ].join('');
  }

  function renderItems(items, messages) {
    var html = '<div class="hb-ucs-returns__items-list">';
    $.each(items, function (_, item) {
      var image = '<div class="hb-ucs-returns__item-image">';
      if (item.image) {
        image += '<img src="' + item.image + '" alt="" />';
      } else {
        image += '<span class="hb-ucs-returns__item-image-placeholder" aria-hidden="true"></span>';
      }
      image += '</div>';
      var meta = item.meta ? '<p class="hb-ucs-returns__item-meta">' + item.meta + '</p>' : '';
      html += '<article class="hb-ucs-returns__item" data-item-id="' + item.item_id + '">';
      html += image;
      html += '<div class="hb-ucs-returns__item-content">';
      html += '<label class="hb-ucs-returns__item-check"><input type="checkbox" name="selected_items[]" value="' + item.item_id + '" /> <span>' + item.name + '</span></label>';
      html += meta;
      html += '<div class="hb-ucs-returns__item-meta-row"><span>' + messages.availableLabel + ': <strong>' + item.available_quantity + '</strong></span><span>' + item.total + '</span></div>';
      html += '</div>';
      html += '<div class="hb-ucs-returns__item-qty"><label>' + messages.quantityLabel + '</label><input type="number" min="1" max="' + item.available_quantity + '" value="1" data-item-qty="' + item.item_id + '" /></div>';
      html += '</article>';
    });
    html += '</div>';
    return html;
  }

  function renderSuccess(data, messages) {
    var itemList = '<ul class="hb-ucs-returns__success-items">';
    $.each(data.items || [], function (_, item) {
      var label = item.name + (item.meta ? ' (' + item.meta + ')' : '');
      itemList += '<li>' + item.quantity + 'x ' + label + '</li>';
    });
    itemList += '</ul>';

    return [
      '<div class="hb-ucs-returns__success-badge">', messages.successHeading, '</div>',
      '<h3>', messages.successHeading, '</h3>',
      '<p>', messages.successText, '</p>',
      '<div class="hb-ucs-returns__summary-grid">',
      '<div><span>' + messages.requestNumberLabel + '</span><strong>' + data.request_number + '</strong></div>',
      '<div><span>' + messages.orderSummaryLabel + '</span><strong>#' + data.order_number + '</strong></div>',
      '<div><span>' + messages.customerMailLabel + '</span><strong>' + data.customer_email + '</strong></div>',
      '</div>',
      itemList
    ].join('');
  }

  function collectSelectedItems($root) {
    var items = {};
    $root.find('.hb-ucs-returns__item').each(function () {
      var $item = $(this);
      var itemId = String($item.data('item-id'));
      var isChecked = $item.find('input[type="checkbox"]').is(':checked');
      var quantity = parseInt($item.find('[data-item-qty]').val(), 10) || 0;
      if (isChecked && quantity > 0) {
        items[itemId] = quantity;
      }
    });
    return items;
  }

  function initRoot($root) {
    if ($root.data('hbUcsReturnsReady')) {
      return;
    }
    $root.data('hbUcsReturnsReady', true);

    var messages = getMessages();
    var config = getAjaxConfig();
    var state = {
      orderNumber: '',
      postcode: '',
      lookup: null
    };

    $root.on('submit', '.hb-ucs-returns__lookup-form', function (event) {
      event.preventDefault();

      state.orderNumber = $.trim($root.find('input[name="order_number"]').val() || '');
      state.postcode = $.trim($root.find('input[name="postcode"]').val() || '');
      setFeedback($root, messages.loading || '', 'info');

      $.post(config.ajaxUrl, {
        action: 'hb_ucs_returns_lookup',
        nonce: config.nonce,
        order_number: state.orderNumber,
        postcode: state.postcode
      }).done(function (response) {
        if (!response || !response.success) {
          setFeedback($root, (response && response.data && response.data.message) || messages.lookupError, 'error');
          return;
        }

        state.lookup = response.data;
        $root.find('.hb-ucs-returns__summary').html(renderOrderSummary(response.data.order, messages));
        $root.find('.hb-ucs-returns__items').html(renderItems(response.data.items || [], messages));
        setFeedback($root, '', 'info');
        setStep($root, 'items');
      }).fail(function (xhr) {
        var message = messages.lookupError;
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        }
        setFeedback($root, message, 'error');
      });
    });

    $root.on('click', '[data-hb-ucs-returns-back="1"]', function () {
      setFeedback($root, '', 'info');
      setStep($root, 'lookup');
    });

    $root.on('submit', '.hb-ucs-returns__items-form', function (event) {
      event.preventDefault();

      var items = collectSelectedItems($root);
      if (!Object.keys(items).length) {
        setFeedback($root, messages.itemsRequired, 'error');
        return;
      }

      setFeedback($root, messages.submitLoading || '', 'info');

      $.post(config.ajaxUrl, {
        action: 'hb_ucs_returns_submit',
        nonce: config.nonce,
        order_number: state.orderNumber,
        postcode: state.postcode,
        reason: $.trim($root.find('textarea[name="reason"]').val() || ''),
        items: items
      }).done(function (response) {
        if (!response || !response.success) {
          setFeedback($root, (response && response.data && response.data.message) || messages.submitError, 'error');
          return;
        }

        $root.find('.hb-ucs-returns__success').html(renderSuccess(response.data, messages));
        setFeedback($root, '', 'info');
        setStep($root, 'success');
      }).fail(function (xhr) {
        var message = messages.submitError;
        if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
          message = xhr.responseJSON.data.message;
        }
        setFeedback($root, message, 'error');
      });
    });
  }

  $(function () {
    $('[data-hb-ucs-returns="1"]').each(function () {
      initRoot($(this));
    });
  });
})(jQuery);