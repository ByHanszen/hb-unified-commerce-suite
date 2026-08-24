<?php
namespace HB\UCS\Modules\Bundles;

use HB\UCS\Modules\Bundles\Admin\BundleProductAdmin;
use HB\UCS\Modules\Bundles\Admin\BundleSettings;
use HB\UCS\Modules\Bundles\Blocks\BundleBlocks;
use HB\UCS\Modules\Bundles\Cart\BundleCart;
use HB\UCS\Modules\Bundles\Frontend\BundleFrontend;
use HB\UCS\Modules\Bundles\Orders\BundleOrders;

if (!defined('ABSPATH')) exit;

final class BundlesModule {
    public function init(): void {
        if (!class_exists('WooCommerce') || !class_exists('WC_Product')) {
            return;
        }

        (new BundleSettings())->init();

        // Both engines register the same `woosb` product type and cart hooks.
        // Keep data readable, but never run both implementations simultaneously.
        if (defined('WOOSB_FILE') || defined('WOOSB_LITE') || defined('WOOSB_PREMIUM')) {
            add_action('admin_notices', [$this, 'render_wpc_conflict_notice']);
            return;
        }

        require_once __DIR__ . '/Products/WC_Product_Woosb.php';
        if (!class_exists('WC_Product_Woosb')) {
            add_action('admin_notices', [$this, 'render_product_class_notice']);
            return;
        }

        add_filter('woocommerce_product_class', [$this, 'resolve_product_class'], 20, 4);
        (new BundleProductAdmin())->init();
        (new BundleFrontend())->init();
        (new BundleCart())->init();
        (new BundleOrders())->init();
        (new BundleBlocks())->init();
    }

    public function resolve_product_class(string $className, string $productType, string $context, int $productId): string {
        return $productType === 'woosb' ? 'WC_Product_Woosb' : $className;
    }

    public function render_wpc_conflict_notice(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html__('HB UCS Productbundels is gepauzeerd.', 'hb-ucs') . '</strong> ';
        echo esc_html__('WPC Product Bundles is nog actief. Deactiveer WPC Product Bundles om de HB-module veilig te gebruiken; bestaande bundeldata blijft behouden.', 'hb-ucs');
        echo '</p></div>';
    }

    public function render_product_class_notice(): void {
        if (current_user_can('manage_woocommerce')) {
            echo '<div class="notice notice-error"><p>' . esc_html__('De productklasse voor HB UCS Productbundels kon niet worden geladen.', 'hb-ucs') . '</p></div>';
        }
    }
}
