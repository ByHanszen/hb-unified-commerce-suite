# ProductSearch tests

Run the isolated routing/query smoke suite without WordPress or database writes:

```bash
php tests/product-search/runtime-smoke.php
php tests/product-search/wordpress-integration.php
```

The isolated suite covers clean-route recognition, the module query var, product-only scoping, normal-search isolation, Elementor legacy redirects, AJAX/admin/loop guards, UTF-8/space/percent round-trips and empty terms. The read-only WordPress integration test additionally boots the local WordPress/WooCommerce stack and confirms that real `WP_Query::is_search()` remains true for all required terms. Neither test writes to the database.

The browser, Elementor template, customer-role, price and full HTTP-flow scenarios in `docs/PRODUCT-SEARCH.md` remain staging acceptance tests. They deliberately are not automated against production.
