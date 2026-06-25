# Daily plugin compatibility checks

The workflow checks HB Unified Commerce Suite against the latest stable WordPress and WooCommerce releases.

It performs:

- PHP syntax checks on PHP 8.1, 8.2, 8.3 and 8.4;
- WordPress bootstrap and plugin activation tests;
- an individual test for every plugin in `public-plugins.txt`;
- a combined activation test for all public plugins;
- optional tests for commercial or private plugin ZIP files;
- automatic creation or updating of a GitHub issue when a run fails.

The workflow runs daily at 06:15 UTC, after relevant pushes to `main`, for relevant pull requests, and through manual dispatch.

## Private plugin URLs

Add these repository Actions secrets when the corresponding ZIP packages are available:

- `ELEMENTOR_PRO_ZIP_URL`
- `LEAT_ZIP_URL`
- `QLS_SERVICEPOINT_ZIP_URL`
- `WOO_UPDATE_MANAGER_ZIP_URL`
- `WPC_PRODUCT_BUNDLES_PREMIUM_ZIP_URL`
- `PDF_INVOICES_PREMIUM_TEMPLATES_ZIP_URL`
- `WP_MAIL_SMTP_PRO_ZIP_URL`

An optional `PRIVATE_PLUGIN_DOWNLOAD_TOKEN` secret can be used when all private download URLs accept the same bearer credential.

Each URL must return a plugin ZIP directly. Missing URLs are reported as `SKIPPED`; invalid or incompatible configured packages fail the check.

## Plugin list format

Public plugins use:

```text
Display name|wordpress.org-slug|comma-separated-prerequisite-slugs
```

Private plugins use:

```text
Display name|ZIP_URL_ENVIRONMENT_VARIABLE|comma-separated-prerequisite-slugs
```

Detailed logs are uploaded as the `plugin-compatibility-logs` artifact and retained for 14 days.
