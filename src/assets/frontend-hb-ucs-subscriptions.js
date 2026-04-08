/* global jQuery */
(function ($) {
  'use strict';

  var productPickerConfig = null;
  var frontendConfig = window.hbUcsSubscriptionsFrontend || {};
  var productPickerRequest = null;
  var productPickerSearchTimer = null;

  function getProductPickerConfig() {
    if (productPickerConfig !== null) {
      return productPickerConfig;
    }

    productPickerConfig = { variableConfigs: {}, variationLookup: {}, chooseOptionLabel: 'Kies een optie…' };
    var element = document.getElementById('hb-ucs-product-picker-config');
    if (!element) {
      return productPickerConfig;
    }

    try {
      productPickerConfig = JSON.parse(element.textContent || '{}') || productPickerConfig;
    } catch (e) {
      productPickerConfig = { variableConfigs: {}, variationLookup: {}, chooseOptionLabel: 'Kies een optie…' };
    }

    return productPickerConfig;
  }

  function updateSchemePrices($form, variation) {
    if (!variation || !variation.hb_ucs_subs) return;

    // Some setups may pass custom data as a JSON string.
    if (typeof variation.hb_ucs_subs === 'string') {
      try {
        variation.hb_ucs_subs = JSON.parse(variation.hb_ucs_subs);
      } catch (e) {
        return;
      }
    }

    var $scope = $form && $form.length ? $form.closest('form.cart') : $(document);
    var $wrap = $scope.find('.hb-ucs-subscriptions');
    if (!$wrap.length) {
      $wrap = $scope;
    }

    Object.keys(variation.hb_ucs_subs).forEach(function (scheme) {
      var data = variation.hb_ucs_subs[scheme] || {};
      var html = data.price_html || '';
      var $targets = $wrap.find('.hb-ucs-subs-price[data-scheme="' + scheme + '"]');
      if (!$targets.length) {
        $targets = $scope.find('.hb-ucs-subs-price[data-scheme="' + scheme + '"]');
      }
      if (!$targets.length) {
        $targets = $('.hb-ucs-subs-price[data-scheme="' + scheme + '"]');
      }
      $targets.html(html);
    });
  }

  function resetSchemePrices($form) {
    var $scope = $form && $form.length ? $form.closest('form.cart') : $(document);
    var $wrap = $scope.find('.hb-ucs-subscriptions');
    if (!$wrap.length) {
      $wrap = $scope;
    }
    $wrap.find('.hb-ucs-subs-price').html('');
  }

  function normalizeText(value) {
    var normalized = String(value || '').toLowerCase().trim();
    if (typeof normalized.normalize === 'function') {
      normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }

    return normalized;
  }

  function parseCategoryIds(value) {
    return String(value || '')
      .split(',')
      .map(function (part) {
        return String(part || '').trim();
      })
      .filter(function (part) {
        return part !== '';
      });
  }

  function getActiveCategoryFilterIds($modal) {
    var $activeButton = $modal.find('.hb-ucs-product-modal__menu-button.is-active').first();
    if (!$activeButton.length) {
      return [];
    }

    return parseCategoryIds($activeButton.attr('data-filter-categories'));
  }

  function setActiveCategoryFilter($modal, $button) {
    if (!$modal || !$modal.length) {
      return;
    }

    var $buttons = $modal.find('.hb-ucs-product-modal__menu-button');
    $buttons.removeClass('is-active').attr('aria-pressed', 'false');

    if ($button && $button.length) {
      $button.addClass('is-active').attr('aria-pressed', 'true');
    }
  }

  function filterProductModal($modal) {
    var term = normalizeText($modal.find('.hb-ucs-product-modal__search').val());
    var activeCategoryIds = getActiveCategoryFilterIds($modal);
    var visible = 0;

    $modal.find('.hb-ucs-product-modal__item').each(function () {
      var $item = $(this);
      var haystack = normalizeText($item.data('productSearch'));
      var categories = parseCategoryIds($item.attr('data-product-categories'));
      var matchesTerm = !term || haystack.indexOf(term) !== -1;
      var matchesCategory = !activeCategoryIds.length || activeCategoryIds.some(function (categoryId) {
        return categories.indexOf(categoryId) !== -1;
      });
      var show = matchesTerm && matchesCategory;

      $item.prop('hidden', !show);
      if (show) {
        visible += 1;
      }
    });

    $modal.find('.hb-ucs-product-modal__no-results').prop('hidden', visible > 0);
  }

  function renderProductModalResults($modal, html, count) {
    if (!$modal || !$modal.length) {
      return;
    }

    var config = getProductPickerConfig();
    var noResultsLabel = String((config && config.noResultsLabel) || 'Geen producten gevonden voor deze filters.');
    var markup = String(html || '');

    if (!markup) {
      markup = '<p class="hb-ucs-product-modal__empty">' + $('<div>').text(noResultsLabel).html() + '</p>';
    }

    markup += '<p class="hb-ucs-product-modal__no-results"' + ((count || 0) > 0 ? ' hidden' : '') + '>' + $('<div>').text(noResultsLabel).html() + '</p>';
    $modal.find('.hb-ucs-product-modal__results').html(markup);
    filterProductModal($modal);
  }

  function requestProductModalResults($modal) {
    if (!$modal || !$modal.length || !frontendConfig.ajaxUrl || !frontendConfig.searchAction) {
      filterProductModal($modal);
      return;
    }

    var config = getProductPickerConfig();
    var term = String($modal.find('.hb-ucs-product-modal__search').val() || '');
    var categoryIds = getActiveCategoryFilterIds($modal);
    var loadingLabel = String((config && config.loadingLabel) || 'Producten laden…');

    if (productPickerRequest && typeof productPickerRequest.abort === 'function') {
      productPickerRequest.abort();
    }

    $modal.find('.hb-ucs-product-modal__results').html('<p class="hb-ucs-product-modal__empty">' + $('<div>').text(loadingLabel).html() + '</p>');

    productPickerRequest = $.ajax({
      url: frontendConfig.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: frontendConfig.searchAction,
        nonce: frontendConfig.nonce || '',
        scheme: String((config && config.scheme) || ''),
        term: term,
        category_ids: categoryIds
      }
    }).done(function (response) {
      if (!response || response.success !== true || !response.data) {
        filterProductModal($modal);
        return;
      }

      renderProductModalResults($modal, String(response.data.html || ''), parseInt(String(response.data.count || '0'), 10) || 0);
    }).fail(function () {
      filterProductModal($modal);
    }).always(function () {
      productPickerRequest = null;
    });
  }

  function scheduleProductModalRequest($modal, immediate) {
    var delay = immediate ? 0 : parseInt(String(frontendConfig.searchDebounceMs || 220), 10);
    if (productPickerSearchTimer) {
      window.clearTimeout(productPickerSearchTimer);
    }

    productPickerSearchTimer = window.setTimeout(function () {
      requestProductModalResults($modal);
    }, Math.max(0, delay));
  }

  function closeProductModal($modal) {
    if (!$modal || !$modal.length) {
      return;
    }

    if (productPickerSearchTimer) {
      window.clearTimeout(productPickerSearchTimer);
      productPickerSearchTimer = null;
    }
    if (productPickerRequest && typeof productPickerRequest.abort === 'function') {
      productPickerRequest.abort();
      productPickerRequest = null;
    }

    $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true').removeData('activeInput');
    $('body').removeClass('hb-ucs-product-modal-open');
  }

  function closeSimpleModal($modal) {
    if (!$modal || !$modal.length) {
      return;
    }

    $modal.attr('hidden', 'hidden').attr('aria-hidden', 'true');
    $('body').removeClass('hb-ucs-product-modal-open');
  }

  function openSimpleModal($modal) {
    if (!$modal || !$modal.length) {
      return;
    }

    $modal.removeAttr('hidden').attr('aria-hidden', 'false');
    $('body').addClass('hb-ucs-product-modal-open');
    window.setTimeout(function () {
      var focusTarget = $modal.find('input, select, textarea, button').filter(':visible').first();
      if (focusTarget.length) {
        focusTarget.trigger('focus');
      }
    }, 10);
  }

  function openProductModal($modal, inputId, title) {
    if (!$modal || !$modal.length) {
      return;
    }

    var $defaultFilter = $modal.find('.hb-ucs-product-modal__menu-button[data-filter-default="1"]').first();

    $modal.data('activeInput', inputId || '');
    $modal.find('#hb-ucs-product-modal-title').text(title || 'Kies een product');
    $modal.find('.hb-ucs-product-modal__search').val('');
    setActiveCategoryFilter($modal, $defaultFilter);
    $modal.removeAttr('hidden').attr('aria-hidden', 'false');
    $('body').addClass('hb-ucs-product-modal-open');
    filterProductModal($modal);
    scheduleProductModalRequest($modal, true);
    window.setTimeout(function () {
      $modal.find('.hb-ucs-product-modal__search').trigger('focus');
    }, 10);
  }

  function getNextSubscriptionItemIndex($list) {
    var maxIndex = -1;
    $list.find('input[name]').each(function () {
      var name = String($(this).attr('name') || '');
      var match = name.match(/^items\[(\d+)\]\[product_id\]$/);
      if (!match) {
        return;
      }

      var index = parseInt(match[1], 10);
      if (!Number.isNaN(index)) {
        maxIndex = Math.max(maxIndex, index);
      }
    });

    return maxIndex + 1;
  }

  function createSubscriptionItemRow(templateId, listId) {
    var template = document.getElementById(String(templateId || ''));
    var $list = $('#' + String(listId || ''));
    if (!template || !$list.length) {
      return $();
    }

    var nextIndex = getNextSubscriptionItemIndex($list);
    var html = String(template.innerHTML || '').replace(/__HB_UCS_INDEX__/g, String(nextIndex));
    var $row = $(html);
    if (!$row.length) {
      return $();
    }

    $list.append($row);
    return $row;
  }

  function setSubscriptionRowPrice($row, price) {
    if (!$row || !$row.length) {
      return;
    }

    var $heading = $row.find('.hb-ucs-subscription-item-card__heading').first();
    var $price = $heading.find('.hb-ucs-product-card__price').first();
    if (!price) {
      if ($price.length) {
        $price.remove();
      }
      return;
    }

    if (!$price.length) {
      $price = $('<p class="hb-ucs-product-card__price"></p>').insertAfter($heading.find('h4').first());
    }
    $price.html(price + ' <span>per levering</span>');
  }

  function collectSelectedAttributeValues($field) {
    var values = {};
    if (!$field || !$field.length) {
      return values;
    }

    $field.find('.hb-ucs-product-picker-attributes select').each(function () {
      var match = String($(this).attr('name') || '').match(/\[([^\]]+)\]$/);
      if (!match) {
        return;
      }
      var key = String(match[1] || '');
      var value = String($(this).val() || '');
      if (key && value) {
        values[key] = value;
      }
    });

    return values;
  }

  function setSubscriptionRowEditing($row, expanded) {
    if (!$row || !$row.length) {
      return;
    }

    var $editor = $row.find('.hb-ucs-product-card__editor').first();
    if (!$editor.length) {
      return;
    }

    $row.toggleClass('is-editing', !!expanded);
    $editor.prop('hidden', !expanded).attr('aria-hidden', expanded ? 'false' : 'true');
  }

  function findMatchingVariationData(productId, selectedAttributes) {
    var config = getProductPickerConfig();
    var variationLookup = config && config.variationLookup ? config.variationLookup : {};
    var variableConfigs = config && config.variableConfigs ? config.variableConfigs : {};
    var variations = variationLookup[String(productId)] || variationLookup[productId] || [];
    var attributesConfig = variableConfigs[String(productId)] || variableConfigs[productId] || [];
    var selectedKeys = Object.keys(selectedAttributes || {});

    if (!variations.length || !selectedKeys.length) {
      return null;
    }

    for (var configIndex = 0; configIndex < attributesConfig.length; configIndex += 1) {
      var requiredKey = String(attributesConfig[configIndex] && attributesConfig[configIndex].key ? attributesConfig[configIndex].key : '');
      if (requiredKey && !String(selectedAttributes[requiredKey] || '')) {
        return null;
      }
    }

    if (!attributesConfig.length) {
      return null;
    }

    for (var i = 0; i < variations.length; i += 1) {
      var variation = variations[i] || {};
      var attributes = variation.attributes || {};
      var isMatch = true;

      for (var j = 0; j < selectedKeys.length; j += 1) {
        var key = selectedKeys[j];
        var selectedValue = String(selectedAttributes[key] || '');
        var currentValue = String(attributes[key] || '');
        if (!currentValue) {
          continue;
        }
        if (currentValue !== selectedValue) {
          isMatch = false;
          break;
        }
      }

      if (isMatch) {
        return variation;
      }
    }

    return null;
  }

  function buildSelectedAttributesSummary(productId, selectedAttributes) {
    var config = getProductPickerConfig();
    var variableConfigs = config && config.variableConfigs ? config.variableConfigs : {};
    var attributes = variableConfigs[String(productId)] || variableConfigs[productId] || [];
    var parts = [];

    attributes.forEach(function (attribute) {
      var key = String(attribute && attribute.key ? attribute.key : '');
      var label = String(attribute && attribute.label ? attribute.label : key);
      var value = key ? String(selectedAttributes[key] || '') : '';

      if (!key || !value) {
        return;
      }

      (attribute.options || []).forEach(function (option) {
        if (String(option && option.value ? option.value : '') === value) {
          value = String(option && option.label ? option.label : value);
        }
      });

      if (value) {
        parts.push(label + ': ' + value);
      }
    });

    return parts.join(' | ');
  }

  function updateProductPickerLabel($field, summary) {
    if (!$field || !$field.length) {
      return;
    }

    var $label = $field.find('.hb-ucs-product-picker-label').first();
    if (!$label.length) {
      return;
    }

    var emptyLabel = String($label.data('emptyLabel') || '');
    var baseLabel = String($label.attr('data-base-label') || $label.text() || emptyLabel);

    if (!baseLabel || baseLabel === emptyLabel) {
      $label.text(emptyLabel);
      return;
    }

    $label.text(summary ? (baseLabel + ' — ' + summary) : baseLabel);
  }

  function updateRowVariationPreview($field) {
    if (!$field || !$field.length) {
      return;
    }

    var $row = $field.closest('.hb-ucs-subscription-item-card');
    var productId = String($field.find('.hb-ucs-product-picker-value').val() || '');
    if (!productId) {
      return;
    }

    var selectedAttributes = collectSelectedAttributeValues($field);
    var selectedSummary = buildSelectedAttributesSummary(productId, selectedAttributes);
    var variation = findMatchingVariationData(productId, selectedAttributes);
    if (variation) {
      setSubscriptionRowPrice($row, String(variation.price_html || ''));
      if (variation.image_html) {
        $row.find('.hb-ucs-subscription-item-card__media').first().html(String(variation.image_html));
      }
      var variationSummary = String(variation.summary || selectedSummary || '');
      $row.find('.hb-ucs-product-card__variation-summary').first().text(variationSummary);
      updateProductPickerLabel($field, variationSummary);
      return;
    }

    if ($field.find('.hb-ucs-product-picker-attributes select').length) {
      setSubscriptionRowPrice($row, '');
      $row.find('.hb-ucs-product-card__variation-summary').first().text(selectedSummary);
      updateProductPickerLabel($field, selectedSummary);
    }
  }

  function updateSubscriptionRowPreview($row, $item) {
    if (!$row || !$row.length || !$item || !$item.length) {
      return;
    }

    var label = String($item.data('productLabel') || '');
    var summary = String($item.data('productSummary') || '');
    var price = String($item.data('productPrice') || '');
    var imageHtml = '';
    try {
      imageHtml = window.atob(String($item.attr('data-product-image') || ''));
    } catch (e) {
      imageHtml = '';
    }

    if (label) {
      $row.find('.hb-ucs-subscription-item-card__heading h4').first().text(label);
    }

    var $price = $row.find('.hb-ucs-product-card__price').first();
    if (price) {
      if ($price.length) {
        $price.html(price + ' <span>per levering</span>');
      }
    } else if ($price.length) {
      $price.remove();
    }

    if (imageHtml) {
      $row.find('.hb-ucs-subscription-item-card__media').first().html(imageHtml);
    }

    $row.find('.hb-ucs-product-card__variation-summary').first().text(summary);
  }

  function cleanupPendingSubscriptionRow($modal) {
    if (!$modal || !$modal.length) {
      return;
    }

    var $pendingRow = $modal.data('pendingNewRow');
    if ($pendingRow && $pendingRow.length) {
      var $input = $pendingRow.find('.hb-ucs-product-picker-value').first();
      if ($input.length && !$input.val()) {
        $pendingRow.remove();
      }
    }

    $modal.removeData('pendingNewRow');
  }

  function renderAttributeSelectors($field, productId, selectedAttributes) {
    var config = getProductPickerConfig();
    var variableConfigs = config && config.variableConfigs ? config.variableConfigs : {};
    var attributes = variableConfigs[String(productId)] || variableConfigs[productId] || [];
    var $container = $field.find('.hb-ucs-product-picker-attributes');
    var attributesName = String($container.data('attributesName') || '');

    if (!$container.length || !attributesName) {
      return;
    }

    if (!attributes.length) {
      $container.empty();
      return;
    }

    selectedAttributes = selectedAttributes || {};
    var chooseOptionLabel = String((config && config.chooseOptionLabel) || 'Kies een optie…');
    var html = '<div class="hb-ucs-product-picker-attribute-grid">';
    attributes.forEach(function (attribute) {
      var key = String(attribute.key || '');
      if (!key) {
        return;
      }
      var currentValue = String(selectedAttributes[key] || '');
      html += '<p class="form-row form-row-wide">';
      html += '<label>' + $('<div>').text(String(attribute.label || key)).html() + '</label>';
      html += '<select class="input-text" name="' + $('<div>').text(attributesName + '[' + key + ']').html() + '">';
      html += '<option value="">' + $('<div>').text(chooseOptionLabel).html() + '</option>';
      (attribute.options || []).forEach(function (option) {
        var value = String(option.value || '');
        var label = String(option.label || value);
        var selected = currentValue === value ? ' selected="selected"' : '';
        html += '<option value="' + $('<div>').text(value).html() + '"' + selected + '>' + $('<div>').text(label).html() + '</option>';
      });
      html += '</select>';
      html += '</p>';
    });
    html += '</div>';
    $container.html(html);
  }

  function initAccountProductPickers(context) {
    var $modal = $('#hb-ucs-product-modal');
    if (!$modal.length) {
      return;
    }

    $(document).off('click.hbUcsProductModalOpen', '.hb-ucs-open-product-modal');
    $(document).on('click.hbUcsProductModalOpen', '.hb-ucs-open-product-modal', function (event) {
      event.preventDefault();
      var $button = $(this);
      var inputId = String($button.data('pickerInput') || '');
      var templateId = String($button.data('hbUcsAddItemTemplate') || '');
      var listId = String($button.data('hbUcsAddItemList') || '');

      $modal.removeData('pendingNewRow');
      if (!inputId && templateId && listId) {
        var $newRow = createSubscriptionItemRow(templateId, listId);
        if (!$newRow.length) {
          return;
        }
        $modal.data('pendingNewRow', $newRow);
        inputId = String($newRow.find('.hb-ucs-product-picker-value').first().attr('id') || '');
      }

      openProductModal($modal, inputId, $button.data('pickerTitle'));
    });

    $modal.off('click.hbUcsProductModalClose', '[data-close-product-modal]');
    $modal.on('click.hbUcsProductModalClose', '[data-close-product-modal]', function (event) {
      event.preventDefault();
      cleanupPendingSubscriptionRow($modal);
      closeProductModal($modal);
    });

    $modal.off('input.hbUcsProductModalFilter', '.hb-ucs-product-modal__search');
    $modal.on('input.hbUcsProductModalFilter', '.hb-ucs-product-modal__search', function () {
      filterProductModal($modal);
      scheduleProductModalRequest($modal, false);
    });

    $modal.off('click.hbUcsProductModalCategory', '.hb-ucs-product-modal__menu-button');
    $modal.on('click.hbUcsProductModalCategory', '.hb-ucs-product-modal__menu-button', function (event) {
      event.preventDefault();
      setActiveCategoryFilter($modal, $(this));
      filterProductModal($modal);
      scheduleProductModalRequest($modal, true);
    });

    $modal.off('click.hbUcsProductModalPick', '.hb-ucs-product-modal__item');
    $modal.on('click.hbUcsProductModalPick', '.hb-ucs-product-modal__item', function (event) {
      event.preventDefault();
      var $item = $(this);
      if ($item.attr('aria-disabled') === 'true') {
        return;
      }
      var inputId = String($modal.data('activeInput') || '');
      var targetProductId = String($item.data('targetProductId') || $item.data('productId') || '');
      var selectedAttributes = {};
      try {
        selectedAttributes = JSON.parse(String($item.attr('data-selected-attributes') || '{}')) || {};
      } catch (e) {
        selectedAttributes = {};
      }

      var $field = $();
      var $input = $();
      var $pendingRow = $modal.data('pendingNewRow');
      if (inputId) {
        $input = $('#' + inputId);
        if ($input.length) {
          $field = $input.closest('.hb-ucs-product-picker-field');
        }
      }

      if (!$input.length || !$field.length) {
        closeProductModal($modal);
        return;
      }

      var $row = ($pendingRow && $pendingRow.length) ? $pendingRow : $field.closest('.hb-ucs-subscription-item-card');
      $input.val(targetProductId);
      $field.find('.hb-ucs-product-picker-label').attr('data-base-label', String($item.data('productLabel') || '')).text(String($item.data('productLabel') || ''));
      renderAttributeSelectors($field, targetProductId, selectedAttributes);
      updateSubscriptionRowPreview($row, $item);
      setSubscriptionRowEditing($row, $field.find('.hb-ucs-product-picker-attributes select').length > 0);
      updateRowVariationPreview($field);
      cleanupPendingSubscriptionRow($modal);
      closeProductModal($modal);
    });

    $modal.off('keydown.hbUcsProductModalPick', '.hb-ucs-product-modal__item');
    $modal.on('keydown.hbUcsProductModalPick', '.hb-ucs-product-modal__item', function (event) {
      if (event.key !== 'Enter' && event.key !== ' ') {
        return;
      }

      event.preventDefault();
      $(this).trigger('click');
    });

    $(document).off('change.hbUcsProductAttributes', '.hb-ucs-product-picker-attributes select');
    $(document).on('change.hbUcsProductAttributes', '.hb-ucs-product-picker-attributes select', function () {
      var $field = $(this).closest('.hb-ucs-product-picker-field');
      updateRowVariationPreview($field);
      setSubscriptionRowEditing($(this).closest('.hb-ucs-subscription-item-card'), true);
    });

    $(document).off('keydown.hbUcsProductModalEsc');
    $(document).on('keydown.hbUcsProductModalEsc', function (event) {
      if (event.key === 'Escape' && !$modal.is('[hidden]')) {
        cleanupPendingSubscriptionRow($modal);
        closeProductModal($modal);
      }
    });
  }

  function setToggleState($trigger, expanded) {
    if (!$trigger || !$trigger.length) {
      return;
    }

    $trigger.attr('aria-expanded', expanded ? 'true' : 'false');
    if ($trigger.hasClass('hb-ucs-toggle-button')) {
      var label = expanded ? String($trigger.data('hbUcsLabelExpanded') || 'Sluit') : String($trigger.data('hbUcsLabelCollapsed') || 'Open');
      $trigger.text(label);
      $trigger.toggleClass('is-active', expanded);
    }
    if ($trigger.hasClass('hb-ucs-icon-button')) {
      $trigger.toggleClass('is-active', expanded);
    }
    if ($trigger.hasClass('hb-ucs-section-nav__button')) {
      $trigger.attr('aria-pressed', expanded ? 'true' : 'false');
      $trigger.toggleClass('is-active', expanded);
    }
  }

  function initAccountCompactUi() {
    $(document).off('click.hbUcsSectionNav', '.hb-ucs-section-nav__button');
    $(document).on('click.hbUcsSectionNav', '.hb-ucs-section-nav__button', function (event) {
      event.preventDefault();

      var $button = $(this);
      var targetId = String($button.data('hbUcsPanelTarget') || '');
      if (!targetId) {
        return;
      }

      var $shell = $button.closest('.hb-ucs-account-shell--detail');
      var $panels = $shell.find('.hb-ucs-accordion-panel');
      var $target = $('#' + targetId);
      if (!$target.length) {
        return;
      }

      $panels.prop('hidden', true).removeClass('is-active');
      $shell.find('.hb-ucs-section-nav__button').each(function () {
        setToggleState($(this), false);
      });

      $target.prop('hidden', false).addClass('is-active');
      setToggleState($button, true);
    });

    $(document).off('click.hbUcsGenericToggle', '[data-hb-ucs-toggle]');
    $(document).on('click.hbUcsGenericToggle', '[data-hb-ucs-toggle]', function (event) {
      event.preventDefault();

      var $button = $(this);
      var targetId = String($button.data('hbUcsToggle') || '');
      if (!targetId) {
        return;
      }

      var $target = $('#' + targetId);
      if (!$target.length) {
        return;
      }

      var expanded = $target.prop('hidden');
      $target.prop('hidden', !expanded);
      setToggleState($button, expanded);
    });

    $(document).off('click.hbUcsScrollTarget', '[data-hb-ucs-scroll-target]');
    $(document).on('click.hbUcsScrollTarget', '[data-hb-ucs-scroll-target]', function (event) {
      event.preventDefault();

      var targetId = String($(this).data('hbUcsScrollTarget') || '');
      if (!targetId) {
        return;
      }

      var target = document.getElementById(targetId);
      if (!target) {
        return;
      }

      target.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(function () {
        var focusTarget = target.querySelector('input, select, textarea, button');
        if (focusTarget) {
          focusTarget.focus();
        }
      }, 220);
    });

    $(document).off('click.hbUcsOpenModal', '[data-hb-ucs-open-modal]');
    $(document).on('click.hbUcsOpenModal', '[data-hb-ucs-open-modal]', function (event) {
      event.preventDefault();

      var modalId = String($(this).data('hbUcsOpenModal') || '');
      if (!modalId) {
        return;
      }

      openSimpleModal($('#' + modalId));
    });

    $(document).off('click.hbUcsCloseModal', '[data-hb-ucs-close-modal]');
    $(document).on('click.hbUcsCloseModal', '[data-hb-ucs-close-modal]', function (event) {
      event.preventDefault();
      closeSimpleModal($(this).closest('.hb-ucs-schedule-modal'));
    });

    $(document).off('click.hbUcsQtyStep', '.hb-ucs-qty-stepper__button');
    $(document).on('click.hbUcsQtyStep', '.hb-ucs-qty-stepper__button', function (event) {
      event.preventDefault();

      var step = parseInt(String($(this).data('hbUcsQtyStep') || '0'), 10);
      var $input = $(this).siblings('input[type="number"]');
      if (!$input.length || !step) {
        return;
      }

      var min = parseInt(String($input.attr('min') || '1'), 10);
      var current = parseInt(String($input.val() || min), 10);
      if (Number.isNaN(current)) {
        current = min;
      }

      var nextValue = Math.max(min, current + step);
      $input.val(nextValue).trigger('change');
    });

    $(document).off('click.hbUcsRemoveToggle', '[data-hb-ucs-remove-toggle]');
    $(document).on('click.hbUcsRemoveToggle', '[data-hb-ucs-remove-toggle]', function (event) {
      event.preventDefault();

      var fieldName = String($(this).data('hbUcsRemoveToggle') || '');
      if (!fieldName) {
        return;
      }

      var $card = $(this).closest('.hb-ucs-subscription-item-card');
      var $input = $card.find('input.hb-ucs-remove-input[name="' + fieldName.replace(/"/g, '\\"') + '"]');
      if (!$input.length || $input.is(':disabled')) {
        return;
      }

      var isChecked = String($input.val() || '0') === '1' ? false : true;
      $input.val(isChecked ? '1' : '0');
      $(this).attr('aria-pressed', isChecked ? 'true' : 'false');
      $card.toggleClass('is-marked-remove', isChecked);
    });

    $(document).off('keydown.hbUcsSimpleModalEsc');
    $(document).on('keydown.hbUcsSimpleModalEsc', function (event) {
      if (event.key !== 'Escape') {
        return;
      }

      var $openModal = $('.hb-ucs-schedule-modal').filter(function () {
        return !$(this).is('[hidden]');
      }).first();

      if ($openModal.length) {
        closeSimpleModal($openModal);
      }
    });
  }

  $(function () {
    var $body = $(document.body);

    // Delegated handlers so dynamic builders (Elementor) also work.
    $body.on('found_variation', '.variations_form', function (e, variation) {
      updateSchemePrices($(this), variation);
    });

    $body.on('show_variation', '.variations_form', function (e, variation) {
      updateSchemePrices($(this), variation);
    });

    $body.on('woocommerce_variation_has_changed reset_data hide_variation', '.variations_form', function () {
      resetSchemePrices($(this));
    });

    initAccountProductPickers($body);
    initAccountCompactUi();
  });
})(jQuery);
