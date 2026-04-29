<?php
// =============================
// src/Modules/QLS/QLSModule.php
// =============================
namespace HB\UCS\Modules\QLS;

use HB\UCS\Core\Settings;

if (!defined('ABSPATH')) exit;

class QLSModule {
    // Metas behouden voor backward compatibility
    const META_PRODUCT = '_hb_qls_exclude_product'; // 1/0 (manual per product)
    const META_ORDER   = '_hb_qls_exclude_order';   // 1/0 (manual per order)
    const META_ORDER_LEGACY = '_qls_exclude';       // 1/0 (oude plugin/meta-compat)
    const SERVICEPOINT_PLUGIN_SLUG = 'qlsgroup-woocommerce-service-points';

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        // Product metabox
        add_action('add_meta_boxes', [$this, 'add_product_metabox']);
        add_action('save_post_product', [$this, 'save_product_meta'], 10, 3);

        // Order metabox
        add_action('add_meta_boxes', [$this, 'add_order_metabox']);
        add_action('save_post_shop_order', [$this, 'save_order_meta'], 10, 3);

        // Centrale filter voor connector (herbruikbaar in rest-filter en batch tools)
        add_filter('hb_ucs_qls_should_send_order', [$this, 'should_send_order'], 10, 2);

        // REST API: filter orders voor geselecteerde API user
        add_filter('rest_post_dispatch', [$this, 'rest_filter_orders_for_api_user'], 10, 3);

        if ($this->has_active_servicepoint_plugin()) {
            add_action('wp_footer', [$this, 'render_servicepoint_modal_fix'], 99);
        }
    }

    private function has_active_servicepoint_plugin(): bool {
        $active_plugins = (array) get_option('active_plugins', []);

        foreach ($active_plugins as $plugin_file) {
            if (is_string($plugin_file) && strpos($plugin_file, self::SERVICEPOINT_PLUGIN_SLUG) !== false) {
                return true;
            }
        }

        if (is_multisite()) {
            $network_plugins = (array) get_site_option('active_sitewide_plugins', []);

            foreach (array_keys($network_plugins) as $plugin_file) {
                if (is_string($plugin_file) && strpos($plugin_file, self::SERVICEPOINT_PLUGIN_SLUG) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    public function render_servicepoint_modal_fix(): void {
        if (!function_exists('is_checkout') || !is_checkout() || !$this->has_active_servicepoint_plugin()) {
            return;
        }
        ?>
        <style>
            /*
             * HB UCS - QLS servicepunt iframe/popup fix
             * Sluit aan op de externe QLS locator-plugin, die een fullscreen
             * wrapper-div met iframe#qls_servicepoint_locator toevoegt.
             */

            body.woocommerce-checkout iframe#qls_servicepoint_locator,
            body.woocommerce-checkout iframe[src*="pakketdienstqls.nl"],
            body.woocommerce-checkout iframe[src*="servicepoint"],
            body.woocommerce-checkout iframe[src*="service-point"],
            body.woocommerce-checkout iframe[id*="qls"],
            body.woocommerce-checkout iframe[class*="qls"] {
                position: relative !important;
                top: auto !important;
                left: auto !important;
                width: 80vw !important;
                height: 80vh !important;
                max-width: 80vw !important;
                max-height: 80vh !important;
                transform: none !important;
                z-index: 1 !important;
                border: 0 !important;
                border-radius: 12px !important;
                background: #ffffff !important;
                box-shadow: 0 20px 60px rgba(0, 0, 0, 0.35) !important;
                overflow: hidden !important;
                display: block !important;
            }

            body.woocommerce-checkout .qls-servicepoint-modal,
            body.woocommerce-checkout .qls-servicepoint-popup,
            body.woocommerce-checkout [class*="qls"][class*="modal"],
            body.woocommerce-checkout [class*="qls"][class*="popup"],
            body.woocommerce-checkout [id*="qls"][id*="modal"],
            body.woocommerce-checkout [id*="qls"][id*="popup"],
            body.woocommerce-checkout [class*="servicepoint"][class*="modal"],
            body.woocommerce-checkout [class*="servicepoint"][class*="popup"],
            body.woocommerce-checkout [id*="servicepoint"][id*="modal"],
            body.woocommerce-checkout [id*="servicepoint"][id*="popup"] {
                position: fixed !important;
                inset: 0 !important;
                width: 100vw !important;
                height: 100vh !important;
                max-width: none !important;
                max-height: none !important;
                transform: none !important;
                z-index: 19999999 !important;
                background: rgba(0, 0, 0, 0.45) !important;
                overflow: auto !important;
            }

            #hb-ucs-qls-servicepoint-close {
                position: absolute !important;
                top: 24px !important;
                right: 24px !important;
                left: auto !important;
                width: 44px !important;
                height: 44px !important;
                z-index: 1000000 !important;
                border: 0 !important;
                border-radius: 999px !important;
                background: #111111 !important;
                color: #ffffff !important;
                font-size: 28px !important;
                line-height: 44px !important;
                text-align: center !important;
                cursor: pointer !important;
                box-shadow: 0 8px 25px rgba(0, 0, 0, 0.35) !important;
                padding: 0 !important;
                font-family: Arial, sans-serif !important;
            }

            #hb-ucs-qls-servicepoint-close:hover,
            #hb-ucs-qls-servicepoint-close:focus {
                background: #333333 !important;
                outline: none !important;
            }

            body.hb-ucs-qls-servicepoint-open {
                overflow: hidden !important;
            }

            @media (max-width: 768px) {
                body.woocommerce-checkout iframe[src*="pakketdienstqls.nl"],
                body.woocommerce-checkout iframe[src*="servicepoint"],
                body.woocommerce-checkout iframe[src*="service-point"],
                body.woocommerce-checkout iframe[id*="qls"],
                body.woocommerce-checkout iframe[class*="qls"],
                body.woocommerce-checkout .qls-servicepoint-modal,
                body.woocommerce-checkout .qls-servicepoint-popup,
                body.woocommerce-checkout [class*="qls"][class*="modal"],
                body.woocommerce-checkout [class*="qls"][class*="popup"],
                body.woocommerce-checkout [id*="qls"][id*="modal"],
                body.woocommerce-checkout [id*="qls"][id*="popup"],
                body.woocommerce-checkout [class*="servicepoint"][class*="modal"],
                body.woocommerce-checkout [class*="servicepoint"][class*="popup"],
                body.woocommerce-checkout [id*="servicepoint"][id*="modal"],
                body.woocommerce-checkout [id*="servicepoint"][id*="popup"] {
                    width: 90vw !important;
                    height: 85vh !important;
                    max-width: 90vw !important;
                    max-height: 85vh !important;
                }

                #hb-ucs-qls-servicepoint-close {
                    top: 16px !important;
                    right: 16px !important;
                    width: 42px !important;
                    height: 42px !important;
                    line-height: 42px !important;
                }
            }
        </style>

        <script>
            (function () {
                'use strict';

                var closeButtonId = 'hb-ucs-qls-servicepoint-close';
                var bodyOpenClass = 'hb-ucs-qls-servicepoint-open';

                function findQlsIframe() {
                    return document.querySelector(
                        'iframe#qls_servicepoint_locator, ' +
                        'iframe[src*="pakketdienstqls.nl"], ' +
                        'iframe[src*="servicepoint"], ' +
                        'iframe[src*="service-point"], ' +
                        'iframe[id*="qls"], ' +
                        'iframe[class*="qls"]'
                    );
                }

                function elementIsVisible(element) {
                    if (!element) {
                        return false;
                    }

                    var style = window.getComputedStyle(element);

                    if (
                        style.display === 'none' ||
                        style.visibility === 'hidden' ||
                        style.opacity === '0'
                    ) {
                        return false;
                    }

                    return !!(
                        element.offsetWidth ||
                        element.offsetHeight ||
                        element.getClientRects().length
                    );
                }

                function findQlsWrapper(iframe) {
                    if (!iframe) {
                        return null;
                    }

                    if (iframe.id === 'qls_servicepoint_locator' && iframe.parentElement) {
                        return iframe.parentElement;
                    }

                    return iframe.closest(
                        '.qls-servicepoint-modal, ' +
                        '.qls-servicepoint-popup, ' +
                        '[class*="qls"][class*="modal"], ' +
                        '[class*="qls"][class*="popup"], ' +
                        '[id*="qls"][id*="modal"], ' +
                        '[id*="qls"][id*="popup"], ' +
                        '[class*="servicepoint"][class*="modal"], ' +
                        '[class*="servicepoint"][class*="popup"], ' +
                        '[id*="servicepoint"][id*="modal"], ' +
                        '[id*="servicepoint"][id*="popup"]'
                    ) || iframe.parentElement || iframe;
                }

                function removeCustomControls() {
                    var closeButton = document.getElementById(closeButtonId);

                    if (closeButton) {
                        closeButton.remove();
                    }

                    document.body.classList.remove(bodyOpenClass);
                }

                function isServicepointModalOpen() {
                    var iframe = findQlsIframe();

                    return !!(iframe && elementIsVisible(iframe));
                }

                function positionCloseButton(wrapper, iframe) {
                    var closeButton = document.getElementById(closeButtonId);
                    var boundsElement = iframe || (wrapper && wrapper !== iframe ? wrapper : null);

                    if (!closeButton || !boundsElement) {
                        return;
                    }

                    var rect = boundsElement.getBoundingClientRect();
                    var isMobile = window.innerWidth <= 768;
                    var topOffset = isMobile ? 14 : 18;
                    var rightOffset = isMobile ? 10 : 14;
                    var top = Math.max(rect.top - topOffset, 12);
                    var right = Math.max(window.innerWidth - rect.right - rightOffset, 12);

                    closeButton.style.top = top + 'px';
                    closeButton.style.right = right + 'px';
                    closeButton.style.left = 'auto';
                }

                function isInjectedLocatorWrapper(wrapper, iframe) {
                    return !!(
                        wrapper &&
                        iframe &&
                        wrapper !== iframe &&
                        iframe.id === 'qls_servicepoint_locator' &&
                        wrapper.contains(iframe)
                    );
                }

                function bindWrapperClose(wrapper, iframe) {
                    if (!isInjectedLocatorWrapper(wrapper, iframe) || wrapper.dataset.hbUcsQlsBound === '1') {
                        return;
                    }

                    wrapper.dataset.hbUcsQlsBound = '1';
                    wrapper.addEventListener('click', function (event) {
                        if (event.target === wrapper) {
                            closeQlsServicepointModal();
                        }
                    });
                }

                function closeQlsServicepointModal() {
                    var iframe = findQlsIframe();
                    var wrapper = findQlsWrapper(iframe);

                    if (isInjectedLocatorWrapper(wrapper, iframe)) {
                        removeCustomControls();
                        wrapper.remove();
                        return;
                    }

                    /*
                     * Eerst proberen we een native sluitknop van QLS te gebruiken.
                     * Daardoor blijft interne QLS-state zo veel mogelijk intact.
                     */
                    if (wrapper && wrapper !== iframe) {
                        var nativeCloseButton = wrapper.querySelector(
                            'button[class*="close"], ' +
                            'button[aria-label*="close"], ' +
                            'button[aria-label*="Close"], ' +
                            'button[aria-label*="sluit"], ' +
                            'button[aria-label*="Sluit"], ' +
                            '[class*="close"], ' +
                            '[class*="Close"]'
                        );

                        if (nativeCloseButton) {
                            nativeCloseButton.click();
                            removeCustomControls();
                            return;
                        }
                    }

                    /*
                     * Fallback: als QLS geen native sluitknop heeft,
                     * verwijderen of verbergen we de wrapper/iframe.
                     */
                    if (wrapper && wrapper !== iframe) {
                        wrapper.remove();
                    } else if (wrapper) {
                        wrapper.style.display = 'none';
                        wrapper.style.visibility = 'hidden';
                    }

                    if (iframe) {
                        iframe.style.display = 'none';
                        iframe.style.visibility = 'hidden';
                    }

                    removeCustomControls();
                }

                function createCloseButton(wrapper) {
                    var closeButton = document.getElementById(closeButtonId);

                    if (closeButton && wrapper && closeButton.parentNode !== wrapper) {
                        closeButton.remove();
                        closeButton = null;
                    }

                    if (closeButton) {
                        return closeButton;
                    }

                    closeButton = document.createElement('button');
                    closeButton.type = 'button';
                    closeButton.id = closeButtonId;
                    closeButton.setAttribute('aria-label', 'Sluit servicepunt keuze');
                    closeButton.innerHTML = '&times;';

                    closeButton.addEventListener('click', function (event) {
                        event.preventDefault();
                        event.stopPropagation();
                        closeQlsServicepointModal();
                    });

                    (wrapper || document.body).appendChild(closeButton);

                    return closeButton;
                }

                function applyQlsModalFix() {
                    var iframe = findQlsIframe();

                    if (!iframe || !elementIsVisible(iframe)) {
                        removeCustomControls();
                        return;
                    }

                    var wrapper = findQlsWrapper(iframe);
                    var isMobile = window.innerWidth <= 768;
                    var modalWidth = isMobile ? '90vw' : '80vw';
                    var modalHeight = isMobile ? '85vh' : '80vh';

                    if (isInjectedLocatorWrapper(wrapper, iframe)) {
                        wrapper.style.position = 'fixed';
                        wrapper.style.inset = '0';
                        wrapper.style.width = '100vw';
                        wrapper.style.height = '100vh';
                        wrapper.style.maxWidth = 'none';
                        wrapper.style.maxHeight = 'none';
                        wrapper.style.transform = 'none';
                        wrapper.style.zIndex = '19999999';
                        wrapper.style.background = 'rgba(0, 0, 0, 0.45)';
                        wrapper.style.display = 'flex';
                        wrapper.style.alignItems = 'center';
                        wrapper.style.justifyContent = 'center';
                        wrapper.style.padding = isMobile ? '16px' : '24px';
                        wrapper.style.boxSizing = 'border-box';
                        wrapper.style.overflow = 'auto';
                    } else if (wrapper && wrapper !== iframe) {
                        wrapper.style.position = 'fixed';
                        wrapper.style.top = '50%';
                        wrapper.style.left = '50%';
                        wrapper.style.width = modalWidth;
                        wrapper.style.height = modalHeight;
                        wrapper.style.maxWidth = modalWidth;
                        wrapper.style.maxHeight = modalHeight;
                        wrapper.style.transform = 'translate(-50%, -50%)';
                        wrapper.style.zIndex = '999998';
                        wrapper.style.background = '#ffffff';
                        wrapper.style.borderRadius = '12px';
                        wrapper.style.overflow = 'hidden';
                    }

                    iframe.style.position = isInjectedLocatorWrapper(wrapper, iframe) ? 'relative' : 'fixed';
                    iframe.style.top = isInjectedLocatorWrapper(wrapper, iframe) ? 'auto' : '50%';
                    iframe.style.left = isInjectedLocatorWrapper(wrapper, iframe) ? 'auto' : '50%';
                    iframe.style.width = modalWidth;
                    iframe.style.height = modalHeight;
                    iframe.style.maxWidth = modalWidth;
                    iframe.style.maxHeight = modalHeight;
                    iframe.style.transform = isInjectedLocatorWrapper(wrapper, iframe) ? 'none' : 'translate(-50%, -50%)';
                    iframe.style.zIndex = isInjectedLocatorWrapper(wrapper, iframe) ? '1' : '999999';
                    iframe.style.border = '0';
                    iframe.style.borderRadius = '12px';
                    iframe.style.background = '#ffffff';
                    iframe.style.boxShadow = '0 20px 60px rgba(0, 0, 0, 0.35)';
                    iframe.style.display = 'block';

                    bindWrapperClose(wrapper, iframe);
                    createCloseButton(isInjectedLocatorWrapper(wrapper, iframe) ? wrapper : document.body);
                    positionCloseButton(wrapper, iframe);

                    document.body.classList.add(bodyOpenClass);
                }

                var observer = new MutationObserver(function () {
                    window.setTimeout(applyQlsModalFix, 100);
                });

                observer.observe(document.body, {
                    childList: true,
                    subtree: true,
                    attributes: true,
                    attributeFilter: ['style', 'class', 'src']
                });

                document.addEventListener('click', function () {
                    window.setTimeout(applyQlsModalFix, 250);
                }, true);

                document.addEventListener('keydown', function (event) {
                    if (event.key === 'Escape') {
                        closeQlsServicepointModal();
                    }
                });

                window.addEventListener('resize', function () {
                    window.setTimeout(applyQlsModalFix, 100);
                });

                window.addEventListener('load', function () {
                    window.setTimeout(applyQlsModalFix, 300);
                });

                if (window.jQuery) {
                    window.jQuery(document.body).on('updated_checkout', function () {
                        window.setTimeout(applyQlsModalFix, 300);
                    });
                }
            })();
        </script>
        <?php
    }

    /* -----------------------------
     * Product metabox
     * ----------------------------- */
    public function add_product_metabox(): void {
        add_meta_box(
            'hb_qls_product_exclude',
            __('QLS: Order meenemen?', 'hb-ucs'),
            [$this, 'render_product_metabox'],
            'product',
            'side',
            'default'
        );
    }

    public function render_product_metabox(\WP_Post $post): void {
        $val = get_post_meta($post->ID, self::META_PRODUCT, true) === '1' ? '1' : '0';
        wp_nonce_field('hb_qls_product_meta', 'hb_qls_product_meta_nonce');
        echo '<p>';
        echo '<label>';
        echo '<input type="checkbox" name="hb_qls_exclude_product" value="1" ' . checked($val, '1', false) . ' /> ';
        esc_html_e('Product uitsluiten van QLS.', 'hb-ucs');
        echo '</label>';
        echo '</p>';
        echo '<p class="description">' . esc_html__('Orders met dit product worden niet doorgezet naar QLS.', 'hb-ucs') . '</p>';
    }

    public function save_product_meta(int $post_id, $post, bool $update): void {
        // Autosave/bulk/quick skip
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (defined('DOING_AJAX') && DOING_AJAX) {
            // alleen bewaren bij expliciete save in editor, laat ajax (quick edit) met rust
            return;
        }
        if (!isset($_POST['hb_qls_product_meta_nonce']) || !wp_verify_nonce($_POST['hb_qls_product_meta_nonce'], 'hb_qls_product_meta')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $val = isset($_POST['hb_qls_exclude_product']) ? '1' : '0';
        update_post_meta($post_id, self::META_PRODUCT, $val);
    }

    /* -----------------------------
     * Order metabox
     * ----------------------------- */
    public function add_order_metabox(): void {
        add_meta_box(
            'hb_qls_order_exclude',
            __('QLS: Order meenemen?', 'hb-ucs'),
            [$this, 'render_order_metabox'],
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_order_metabox(\WP_Post $post): void {
        $val = get_post_meta($post->ID, self::META_ORDER, true) === '1' ? '1' : '0';
        wp_nonce_field('hb_qls_order_meta', 'hb_qls_order_meta_nonce');
        echo '<p>';
        echo '<label>';
        echo '<input type="checkbox" name="hb_qls_exclude_order" value="1" ' . checked($val, '1', false) . ' /> ';
        esc_html_e('Deze order uitsluiten van QLS.', 'hb-ucs');
        echo '</label>';
        echo '</p>';

        // Toon ook legacy meta als debug-info
        $legacy = get_post_meta($post->ID, self::META_ORDER_LEGACY, true);
        if ($legacy !== '') {
            echo '<p class="description">' . esc_html(sprintf(__('Legacy meta (%s): %s', 'hb-ucs'), self::META_ORDER_LEGACY, $legacy)) . '</p>';
        }
    }

    public function save_order_meta(int $post_id, $post, bool $update): void {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        if (!isset($_POST['hb_qls_order_meta_nonce']) || !wp_verify_nonce($_POST['hb_qls_order_meta_nonce'], 'hb_qls_order_meta')) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $val = isset($_POST['hb_qls_exclude_order']) ? '1' : '0';
        update_post_meta($post_id, self::META_ORDER, $val);
    }

    /* -----------------------------
     * Beslislogica
     * ----------------------------- */
    public function should_send_order(bool $allow, int $order_id): bool {
        $order = wc_get_order($order_id);
        if (!$order) return false;

        $opt = get_option(Settings::OPT_QLS, []);

        // 0) Legacy/handmatige overrides
        $legacy = get_post_meta($order_id, self::META_ORDER_LEGACY, true);
        if ($legacy === '1') return false; // oude plugin exclude
        // legacy '0' = expliciet toestaan -> ga door

        $manual = get_post_meta($order_id, self::META_ORDER, true);
        if ($manual === '1') return false; // handmatig uitgesloten
        // manual '0' = expliciet toelaten -> ga door

        // 1) Shipping instance_id uitsluitingen
        $excluded_instances = array_map('intval', (array)($opt['excluded_shipping_instance_ids'] ?? []));
        if (!empty($excluded_instances)) {
            foreach ($order->get_shipping_methods() as $ship_item) {
                $iid = (int) $ship_item->get_instance_id();
                if ($iid && in_array($iid, $excluded_instances, true)) {
                    return false;
                }
            }
        }

        // 2) Shipping method_id (type) uitsluitingen
        $excluded_method_ids = array_map('strval', (array)($opt['excluded_shipping_method_ids'] ?? []));
        if (!empty($excluded_method_ids)) {
            foreach ($order->get_shipping_methods() as $ship_item) {
                $mid = (string) $ship_item->get_method_id();
                if (in_array($mid, $excluded_method_ids, true)) {
                    return false;
                }
            }
        }

        // 3) Product niveau exclusions (handmatig per product)
        foreach ($order->get_items('line_item') as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product_id')) {
                continue;
            }
            $pid = (int) $item->get_product_id();
            if ($pid && get_post_meta($pid, self::META_PRODUCT, true) === '1') {
                return false;
            }
        }

        return true;
    }

    /* -----------------------------
     * REST API filtering
     * ----------------------------- */
    public function rest_filter_orders_for_api_user($response, \WP_REST_Server $server, \WP_REST_Request $request) {
        // Alleen voor wc/v3 orders endpoints
        $route = $request->get_route();
        if (strpos($route, '/wc/v3/orders') !== 0 && strpos($route, '/wc/v2/orders') !== 0) {
            return $response;
        }

        $opt = get_option(Settings::OPT_QLS, []);
        $api_user_id = (int) ($opt['api_user_id'] ?? 0);
        if ($api_user_id <= 0) return $response;

        // Alleen toepassen voor de ingestelde gebruiker
        $current = get_current_user_id();
        if ((int) $current !== $api_user_id) return $response;

        if (!($response instanceof \WP_REST_Response)) return $response;

        $data = $response->get_data();
        if (is_array($data) && isset($data[0]) && is_array($data[0])) {
            // Collection
            $filtered = [];
            foreach ($data as $row) {
                if (!isset($row['id'])) continue;
                $oid = (int) $row['id'];
                if ($oid > 0 && apply_filters('hb_ucs_qls_should_send_order', true, $oid)) {
                    $filtered[] = $row;
                }
            }
            $response->set_data($filtered);
            return $response;
        }

        // Single (detail)
        if (is_object($data) && isset($data->id)) {
            $oid = (int) $data->id;
            if ($oid > 0 && !apply_filters('hb_ucs_qls_should_send_order', true, $oid)) {
                return new \WP_Error('hb_ucs_qls_excluded', __('Order is uitgesloten voor deze API gebruiker.', 'hb-ucs'), ['status'=>404]);
            }
            return $response;
        }

        if (is_array($data) && isset($data['id'])) {
            // Sommige responses komen als assoc array terug
            $oid = (int) $data['id'];
            if ($oid > 0 && !apply_filters('hb_ucs_qls_should_send_order', true, $oid)) {
                return new \WP_Error('hb_ucs_qls_excluded', __('Order is uitgesloten voor deze API gebruiker.', 'hb-ucs'), ['status'=>404]);
            }
            return $response;
        }

        return $response;
    }
}
