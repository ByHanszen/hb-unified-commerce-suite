# Invoice email regressions

Run `php tests/invoice-email/runtime-smoke.php`. The isolated harness sends no real email and makes no network calls. GitHub Actions runs it on PHP 8.2 and 8.4.

The tests exercise automatic completion, the `woocommerce_mail_callback` route for manual `customer_invoice`, alternative recipients and custom content, unchanged other mailtypes/guests, lock contention, missing PDFs, provider failures and time-outs, success callbacks from WordPress and WP Mail SMTP, queue isolation, deferred callbacks, stale cleanup, old callback isolation, redacted error logging, and the order status display.

## Staging verification before deployment

With the actual WordPress/WooCommerce, WPO PDF and WP Mail SMTP versions, verify both CPT and HPOS:

1. Use a test customer with an alternative invoice address you control. Complete a test order: normal notifications keep their usual recipient; the separate HB invoice goes only to the alternative address with the PDF (and matching UBL when available).
2. Use WooCommerce's existing order action to send customer invoice/order details. Verify another **HB invoice**, not the standard order-details message, arrives at the alternative address. Repeat with an account without an alternative address: default behavior must remain unchanged.
3. Check the order's **HB factuurmail** status, private order notes, `WooCommerce > Status > Logs > hb-ucs-invoice-email`, and the provider's mail log. `accepted` means transport/mailserver acceptance, not proof of delivery to the inbox.
4. Simulate a provider rejection and a 15-second timeout. The former is `failed`, the latter `uncertain`. Verify error codes and a redacted error message are present. Check provider logs before deliberately resending a timed-out message: it may already have been accepted remotely.
5. Enable WP Mail SMTP queueing. Only the marked HB invoice must bypass its second queue; other emails keep their queue settings. Verify the PDF is readable while the actual transport runs and removed after the send completes.
6. For another mail plugin that intercepts `wp_mail` without a transport callback, verify `pending` rather than `accepted`, no duplicate send while pending, and retained private attachments. A delayed success/failure callback with the correlation header resolves the matching attempt. After seven days without a callback, the scheduled cleanup records `uncertain` and removes the private files. Keep WP-Cron/server scheduling operational.
7. Two simultaneous resend/completion requests must not produce two pending attempts. Repeated automatic completion hooks do not retry existing attempts; deliberate resend after failure/uncertainty/acceptance remains possible through the same WooCommerce action.

No production mail should be sent just to test the change. The production Microsoft 365 connectivity/time-out issue still requires a separate server/provider investigation; this change records its outcome and supports a controlled manual resend.
