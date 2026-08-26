# ProductSearch

## Scope and architecture

ProductSearch adds a clean product-search route without replacing the Elementor Search-widget or its Search Results-template. The module adds no frontend CSS/JavaScript, dependencies, tables or normal-request database writes.

- `ProductSearchModule` composes the module and owns defaults/lifecycle handling.
- `SearchRewrite` registers the clean route, the public marker query var and lossless extraction of the URL-encoded search term.
- `SearchQuery` scopes only the marked main search query to published WooCommerce products and preserves the existing query clauses.
- `SearchRedirect` converts a valid legacy Elementor form submission to one temporary 302 redirect.
- `SearchSeo` keeps the clean route out of indexing and blocks canonical redirects back to query-string URLs.

## URL format

Default route:

```text
/zoeken/{URL-gecodeerde zoekterm}/
```

Example mappings:

```text
/?s=topping&e_search_props=<widget>-<template>  -> 302 -> /zoeken/topping/
/zoeken/house%20blend/                         -> internal search: house blend
/zoeken/decaf%C3%A9/                           -> internal search: decafé
/zoeken/100%25%20arabica/                      -> internal search: 100% arabica
```

The rewrite first sets `hb_product_search=1`. The `request` filter then reads the raw URL path, decodes the term exactly once and supplies the real WordPress `s` query var before `WP_Query` is parsed. This avoids `sanitize_title()` and preserves spaces, case, accents and percent characters. Because `s` is a real query var, `is_search()` remains true.

Pretty pagination is also recognized as `/zoeken/{term}/page/{number}/`.

## Elementor integration and `e_search_props`

Elementor Pro's Search-widget renders a normal GET form with `s` plus a hidden `e_search_props` value in the form `<widget-id>-<template-post-id>`. On a legacy page load, Elementor resolves that saved widget and applies its `search_query` controls to the main query. The identifier is generated content data and is not a stable API.

Read-only production audit on 2026-08-26 showed:

- the legacy request with the production widget props rendered the product Search Results-template and its existing product Loop Grid;
- the same `?s=topping` request without props rendered the general Search Results-template and did not render that product grid;
- the behavior required from `e_search_props` is the widget's `post_type=product` query scope, which also drives Elementor's product-search template condition; Elementor additionally forces `posts_per_page=-1` for this main query;
- ProductSearch reproduces that durable behavior with `pre_get_posts`, not with a stored widget/template ID;
- the existing form, Enter/button submit triggers and responsive Elementor markup remain untouched;
- Elementor live results use a separate REST endpoint with the widget/template identifiers in its request. REST/AJAX/background requests are explicitly excluded from redirects, so enabling live results remains compatible;
- the audited production widget currently had live-result rendering disabled; no suggestion request was emitted when typing. ProductSearch does not change that widget setting.

On the clean route, ProductSearch sets `post_type=product`, `post_status=publish` and Elementor's existing `posts_per_page=-1` behavior on the marked main search query. It does not replace Elementor templates, loops or queries and does not use a generated Elementor ID as a dependency. Elementor Loop Grid pagination/load-more remains owned by the existing template.

## WooCommerce and B2B compatibility

`SearchQuery` runs at `pre_get_posts` priority 20, after the normal Elementor/WooCommerce query setup. It retains existing `tax_query` and `meta_query` clauses. The WooCommerce `exclude-from-search` product-visibility term is added only when WooCommerce has not already added it, so products with catalog-only/hidden search visibility do not appear.

No B2B hook, rule, price or product object is modified. Existing HB UCS price filters continue to run when Elementor renders product cards.

## Hooks

- `query_vars`: registers `hb_product_search`.
- `request`: restores the exact `s` term from the clean path.
- `init`: registers the two rewrite patterns.
- `pre_get_posts` (20): marked main search becomes published products only.
- `template_redirect` (1): eligible legacy Elementor GET/HEAD gets one 302.
- `redirect_canonical`: prevents WordPress from canonicalizing clean searches back to `?s=`.
- `wp_robots`: merges `noindex, follow` into WordPress's single robots output.
- `wpseo_canonical`, `rank_math/frontend/canonical`, `aioseo_canonical_url`: suppress a search canonical if those plugins are present.
- `update_option_hb_ucs_settings`: schedules a rewrite flush only when the module toggle changes.
- `update_option_hb_ucs_product_search_settings`: schedules a flush only when `search_base` changes.

## Settings

Option `hb_ucs_product_search_settings`:

- `search_base`: `zoeken`;
- `redirect_legacy_elementor_urls`: `1`;
- `noindex`: `1`;
- `delete_data_on_uninstall`: `0`.

The `product_search` module toggle is disabled by default.

## Rewrite lifecycle

Rewrite rules are never flushed on a normal search request. A single non-hard flush occurs when:

- an already-enabled module is included during plugin activation;
- the `product_search` toggle changes (on the next request, with the new state loaded);
- `search_base` changes while the module is enabled;
- the plugin is deactivated, after its in-memory ProductSearch rules are removed.

## SEO

WordPress already marks normal searches `noindex, follow`. The production audit confirmed Yoast SEO 28.3 is active and found one robots tag with exactly that value and no canonical on both tested search variants. All 15 sitemap documents referenced by the production Yoast sitemap index were also checked and contained no `?s=`, `e_search_props` or `/zoeken/` URL. The module merges directives through `wp_robots`, so it does not print a second meta tag. Search URLs are not post/taxonomy objects and are not added to WordPress or SEO-plugin XML sitemaps.

ProductSearch suppresses canonical output for the marked route, preventing a canonical to `?s=...&e_search_props=...` and avoiding duplicate canonicals.

## Rollback

1. Disable **Product zoeken** under **HB UCS -> Modules**.
2. Allow the next WordPress request to perform the scheduled rewrite flush.
3. Clear page/object cache only if staging shows cached redirects or markup; do not change LiteSpeed or Cloudflare configuration as part of this module.
4. Legacy Elementor submissions then return to their original query-string behavior.
5. If code rollback is also needed, revert the feature commit/branch. Do not delete `hb_ucs_product_search_settings` unless its uninstall cleanup toggle was intentionally enabled.

## Staging acceptance checklist

Run before any production merge/deploy, with browser cache disabled where appropriate:

- module disabled: existing Elementor search unchanged;
- enable module, verify rewrite flush occurs once;
- desktop header/template: live suggestions, Enter and search button;
- mobile template: live suggestions, Enter and search button;
- direct `/zoeken/topping/`, plus `house blend`, `decafé`, `Etna`, `100% arabica`, an empty term, and a no-results term;
- existing Elementor product Search Results-template and Loop Grid render on every clean route;
- pagination/load-more, if present;
- logged-out visitor, logged-in B2C customer and logged-in B2B customer;
- product visibility, stock behavior, prices, B2B prices and product links remain correct;
- hidden/catalog-only products remain absent;
- an unrelated `?s=` WordPress search remains general;
- robots output occurs once as `noindex, follow`, no canonical is emitted, and search URLs are absent from XML sitemaps;
- `/?s=topping&e_search_props=<current-value>` -> at most one 302 -> `/zoeken/topping/` -> 200, with no redirect chain;
- AJAX, REST, cron and wp-admin requests never redirect;
- change `search_base`, verify the new route works and the old rule disappears after one flush;
- disable the module and verify the clean rewrite disappears after one flush.

No staging acceptance item is considered passed by the isolated PHP smoke suite alone.
