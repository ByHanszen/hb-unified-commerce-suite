<?php

namespace HB\UCS\Modules\ProductSearch;

use HB\UCS\Core\Settings;

if (!defined('ABSPATH')) exit;

class ProductSearchModule {
    public const QUERY_VAR = 'hb_product_search';
    public const REWRITE_FLUSH_OPTION = 'hb_ucs_product_search_rewrite_flush_required';

    /** @var SearchRewrite|null */
    private $rewrite = null;

    public function init(): void {
        if (!class_exists('WooCommerce')) {
            return;
        }

        $settings = self::get_settings();

        $this->rewrite = new SearchRewrite($settings);
        $this->rewrite->init();

        (new SearchQuery())->init();
        (new SearchRedirect($settings, $this->rewrite))->init();
        (new SearchSeo($settings))->init();

        add_action('update_option_' . Settings::OPT_PRODUCT_SEARCH, [self::class, 'settings_updated'], 10, 2);
    }

    public static function defaults(): array {
        return [
            'search_base' => 'zoeken',
            'redirect_legacy_elementor_urls' => 1,
            'noindex' => 1,
            'delete_data_on_uninstall' => 0,
        ];
    }

    public static function get_settings(): array {
        $settings = get_option(Settings::OPT_PRODUCT_SEARCH, []);

        return array_replace(self::defaults(), is_array($settings) ? $settings : []);
    }

    public static function settings_updated($oldValue, $newValue): void {
        $old = array_replace(self::defaults(), is_array($oldValue) ? $oldValue : []);
        $new = array_replace(self::defaults(), is_array($newValue) ? $newValue : []);

        if (SearchRewrite::normalize_base((string) $old['search_base']) !== SearchRewrite::normalize_base((string) $new['search_base'])) {
            self::schedule_rewrite_flush();
        }
    }

    public static function schedule_rewrite_flush(): void {
        update_option(self::REWRITE_FLUSH_OPTION, 1, false);
    }

    public static function activate(): void {
        $main = get_option(Settings::OPT, []);
        if (empty($main['modules']['product_search'])) {
            return;
        }

        $rewrite = new SearchRewrite(self::get_settings());
        $rewrite->register_rewrite();
        flush_rewrite_rules(false);
        delete_option(self::REWRITE_FLUSH_OPTION);
    }

    public static function deactivate(): void {
        SearchRewrite::remove_registered_rules();
        flush_rewrite_rules(false);
        delete_option(self::REWRITE_FLUSH_OPTION);
    }
}
