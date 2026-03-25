jQuery(function ($) {
  function applySelectColor($select, config) {
    var palette = (config && config.palette) || {};
    var selected = $select.find('option:selected');
    var colorKey = selected.data('color-key') || '';
    var colorConfig = colorKey && palette[colorKey] ? palette[colorKey] : null;

    if (!colorConfig) {
      $select.attr('data-color-key', '');
      $select.css({
        color: '',
        '--hb-ucs-status-bg': '',
        '--hb-ucs-status-text': ''
      });
      return;
    }

    $select.attr('data-color-key', colorKey);
    $select.css({
      color: colorConfig.text || '',
      '--hb-ucs-status-bg': colorConfig.background || '',
      '--hb-ucs-status-text': colorConfig.text || ''
    });
  }

  function applySettingsPreview($row) {
    var $select = $row.find('.hb-ucs-order-overview-status-color-select');
    var $preview = $row.find('.hb-ucs-order-overview-status-color-preview');
    var $selected = $select.find('option:selected');

    if (!$select.length || !$preview.length || !$selected.length) {
      return;
    }

    $preview.css({
      '--hb-ucs-preview-bg': $selected.data('background') || '',
      '--hb-ucs-preview-text': $selected.data('text') || ''
    });
  }

  function initSettingsRepeater() {
    var $rows = $('#hb-ucs-order-overview-status-rows');
    var $template = $('#tmpl-hb-ucs-order-overview-status-row');
    var $addButton = $('#hb-ucs-add-order-overview-status');

    if (!$rows.length || !$template.length || !$addButton.length) {
      return;
    }

    function nextIndex() {
      return $rows.find('.hb-ucs-order-overview-status-row').length;
    }

    function addRow() {
      var markup = $template.html().replace(/__index__/g, String(nextIndex()));
      var $markup = $(markup);
      $rows.append($markup);
      applySettingsPreview($markup);
    }

    $addButton.on('click', function () {
      addRow();
    });

    $rows.on('click', '.hb-ucs-remove-order-overview-status', function () {
      var $row = $(this).closest('.hb-ucs-order-overview-status-row');
      var rowCount = $rows.find('.hb-ucs-order-overview-status-row').length;

      if (rowCount <= 1) {
        $row.find('input').val('');
        $row.find('.hb-ucs-order-overview-status-color-select').prop('selectedIndex', 0);
        applySettingsPreview($row);
        return;
      }

      $row.remove();
    });

    $rows.find('.hb-ucs-order-overview-status-row').each(function () {
      applySettingsPreview($(this));
    });

    $rows.on('change', '.hb-ucs-order-overview-status-color-select', function () {
      applySettingsPreview($(this).closest('.hb-ucs-order-overview-status-row'));
    });
  }

  function initOrderListSaver() {
    var config = window.HB_UCS_ORDER_OVERVIEW_STATUS_ADMIN || {};
    if (!config.ajaxUrl || !config.nonce) {
      return;
    }

    $('.hb-ucs-order-overview-status-select').each(function () {
      applySelectColor($(this), config);
    });

    $(document).on('mousedown mouseup click', '.hb-ucs-order-overview-status-cell, .hb-ucs-order-overview-status-select', function (event) {
      event.stopPropagation();
    });

    $(document).on('focus', '.hb-ucs-order-overview-status-select', function () {
      $(this).attr('data-previous', $(this).val());
    });

    $(document).on('change', '.hb-ucs-order-overview-status-select', function () {
      var $select = $(this);
      var $cell = $select.closest('.hb-ucs-order-overview-status-cell');
      var $spinner = $cell.find('.spinner');
      var previous = $select.attr('data-previous') || '';

      applySelectColor($select, config);

      $select.prop('disabled', true);
      $spinner.addClass('is-active');

      $.post(config.ajaxUrl, {
        action: 'hb_ucs_save_order_overview_status',
        nonce: config.nonce,
        order_id: $select.data('order-id'),
        status: $select.val()
      })
        .done(function (response) {
          if (!response || response.success !== true) {
            $select.val(previous);
            applySelectColor($select, config);
            return;
          }

          $select.attr('data-previous', $select.val());
          applySelectColor($select, config);
        })
        .fail(function () {
          $select.val(previous);
          applySelectColor($select, config);
        })
        .always(function () {
          $spinner.removeClass('is-active');
          $select.prop('disabled', false);
        });
    });
  }

  initSettingsRepeater();
  initOrderListSaver();
});
