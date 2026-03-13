jQuery(function ($) {
  try {
    // If WooCommerce enhanced select is present, initialize it on HB UCS pages.
    $(document.body).trigger('wc-enhanced-select-init');
  } catch (e) {}
});
