# Daily plugin compatibility checks

The workflow checks HB Unified Commerce Suite against the latest stable WordPress and WooCommerce releases.

It performs:

- PHP syntax checks on PHP 8.1, 8.2, 8.3 and 8.4;
- WordPress bootstrap and plugin activation tests;
- an individual test for every plugin in `public-plugins.txt`;
- a combined activation test for all public plugins;
- optional tests for commercial or private plugin ZIP files;
- automatic creation or updating of a GitHub issue when a run fails;
- a daily SMTP email report to `stefan@hoekschebranders.nl`, including successful results.

The workflow runs daily at 06:15 UTC, after relevant pushes to `main`, for relevant pull requests, and through manual dispatch. Email is sent only for scheduled and manually started runs, not for every push or pull request.

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

## SMTP email configuration

The workflow sends email directly through an SMTP server. No Gmail, Outlook or ChatGPT mailbox connection is required.

Create these repository Actions secrets:

- `SMTP_HOST` — SMTP server hostname, for example `smtp.example.nl`;
- `SMTP_PORT` — usually `465` for SSL or `587` for STARTTLS;
- `SMTP_USERNAME` — SMTP account username;
- `SMTP_PASSWORD` — SMTP password or provider-specific app password;
- `SMTP_SECURITY` — `ssl`, `starttls` or `none`;
- `SMTP_FROM` — optional sender address; when omitted, `SMTP_USERNAME` is used.

The recipient is configured in the workflow as `stefan@hoekschebranders.nl`. The message is sent after every scheduled or manually started compatibility check, including successful checks.

For a Google Workspace or Gmail sender, use an app password rather than the normal account password. This does not give ChatGPT access to the mailbox; the credential is stored only as a masked GitHub Actions secret.

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
