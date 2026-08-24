(function () {
    'use strict';

    if (!window.wc || !window.wc.blocksCheckout || typeof window.wc.blocksCheckout.registerCheckoutFilters !== 'function') {
        return;
    }

    function cartItem(args) {
        return args && args.cartItem ? args.cartItem : {};
    }

    function createEditLink(url, label) {
        var link = document.createElement('a');
        link.className = 'hb-ucs-bundle-block-edit';
        link.href = String(url || '');
        link.textContent = String(label || 'Samenstelling wijzigen');
        return link.outerHTML;
    }

    function escapeHtml(value) {
        var span = document.createElement('span');
        span.textContent = String(value || '');
        return span.innerHTML;
    }

    window.wc.blocksCheckout.registerCheckoutFilters('hb-ucs-bundles', {
        cartItemClass: function (defaultValue, extensions, args) {
            var item = cartItem(args);
            var classes = [defaultValue];
            if (item.hb_ucs_bundle_parent) classes.push('hb-ucs-bundle-block-parent');
            if (item.hb_ucs_bundle_child) classes.push('hb-ucs-bundle-block-child');
            if (item.hb_ucs_bundle_dynamic) classes.push('is-dynamic');
            if (item.hb_ucs_bundle_fixed) classes.push('is-fixed');
            if (item.hb_ucs_bundle_hidden) classes.push('is-bundle-hidden');
            return classes.join(' ').trim();
        },
        showRemoveItemLink: function (defaultValue, extensions, args) {
            return cartItem(args).hb_ucs_bundle_child ? false : defaultValue;
        },
        itemName: function (defaultValue, extensions, args) {
            var item = cartItem(args);
            if (item.hb_ucs_bundle_child && item.hb_ucs_bundle_parent_name) {
                return escapeHtml(item.hb_ucs_bundle_parent_name) + ' → ' + defaultValue;
            }
            if (item.hb_ucs_bundle_parent && item.hb_ucs_bundle_edit_url) {
                var data = extensions && extensions['hb-ucs-bundles'] ? extensions['hb-ucs-bundles'] : {};
                return defaultValue + ' ' + createEditLink(item.hb_ucs_bundle_edit_url, data.editLabel);
            }
            return defaultValue;
        }
    });
})();
