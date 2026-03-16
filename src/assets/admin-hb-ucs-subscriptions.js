/* global jQuery, hbUcsSubsAdmin */
(function ($) {
  'use strict';

  function toNumber(val) {
    if (typeof val !== 'string') return 0;
    val = val.replace(',', '.');
    var n = parseFloat(val);
    return isNaN(n) ? 0 : n;
  }

  function formatMoney(amount) {
    var decimals = (hbUcsSubsAdmin && hbUcsSubsAdmin.decimals != null) ? hbUcsSubsAdmin.decimals : 2;
    var symbol = (hbUcsSubsAdmin && hbUcsSubsAdmin.symbol) ? hbUcsSubsAdmin.symbol : '€';
    return symbol + amount.toFixed(decimals);
  }

  function recalc($wrap) {
    var base = toNumber(String($wrap.data('base-price') || '0'));

    var fixedRaw = $wrap.find('input[name^="hb_ucs_subs_fixed_price"]').first().val() || '';
    fixedRaw = String(fixedRaw).trim();

    var finalPrice;
    if (fixedRaw !== '') {
      finalPrice = toNumber(fixedRaw);
    } else {
      finalPrice = base;
      var enabled = $wrap.find('input[type="checkbox"][name^="hb_ucs_subs_disc_enabled"]').first().is(':checked');
      var type = $wrap.find('select[name^="hb_ucs_subs_disc_type"]').first().val() || 'percent';
      var value = toNumber(String($wrap.find('input[name^="hb_ucs_subs_disc_value"]').first().val() || '0'));

      if (enabled) {
        if (type === 'percent') {
          finalPrice = base - (base * Math.max(0, value) / 100);
        } else {
          finalPrice = base - Math.max(0, value);
        }
      }
    }

    if (finalPrice < 0) finalPrice = 0;

    $wrap.find('.hb-ucs-subs-new-price').text(formatMoney(finalPrice));
  }

  $(function () {
    $(document).on('change keyup', '.hb-ucs-subs-scheme input, .hb-ucs-subs-scheme select', function () {
      var $wrap = $(this).closest('.hb-ucs-subs-scheme');
      if ($wrap.length) recalc($wrap);
    });

    $('.hb-ucs-subs-scheme').each(function () {
      recalc($(this));
    });
  });
})(jQuery);
