# VAT configuration and regression checks

## Configure untaxed orders

In **Depoto > Pairing > Taxes**, select **0% DEPOTO VAT for untaxed items (required)** and save. This selector is always available, including stores with no WooCommerce tax rates. Its choices come from the account's actual `Vat.percent = 0` records; labels include the name, percentage and ID. If no choices load, check the connection or create a 0% record in DEPOTO, then reload.

Untaxed products, shipping and payment lines send this selected record ID without changing their prices. Existing WooCommerce zero-rate mappings remain a fallback. Taxable lines require their own mappings and never inherit the untaxed default or another rate. Incomplete mappings stop exports before remote address/order writes, with an actionable order note.

DEPOTO support confirmed that VAT is required even for non-taxpayer sales. Although GraphQL declares a nullable input, live requests omitting VAT fail database persistence (`vat_id cannot be null`, SQLSTATE 23000 / 1048). Read-only schema introspection verified the numeric `Vat.percent` field and paginated `vats` response. VAT lists are fetched once per request, across all pages, and shared by the settings selectors.

## Diagnostics

All current diagnostics use **WooCommerce > Status > Logs**, source **depoto-diagnostics**. In Logs > Settings, enable logging and choose threshold **Info** (or None) for normal lifecycle events. **Debug** additionally records webhook processing steps.

- Exports: scheduler ID/failure, sanitized line summaries, successful DEPOTO ID, already-exported skips, missing orders and failure stage.
- Manual retry batches: started/finished, with exported/failed/skipped counts. The existing button covers orders created within the last week.
- Payments and cancellations: success, failure and skipped operations without a linked DEPOTO order.
- Webhooks: scheduling, completion, permanent skips, retry reason/attempt count, exhausted retries and scheduling failures. Untrusted event types are reported as unsupported.
- Configuration/API: VAT mapping changes, rejected nonzero defaults, VAT-list failures, connection checks and failed lookups/import operations.

Logs retain only diagnostic IDs, counters, safe failure metadata and numeric line values. Customer names, addresses, emails, product names/SKUs, order references, raw webhook payloads and OAuth credentials/tokens are excluded. Unknown exception messages are withheld because upstream messages can echo sensitive values; existing order notes still receive original exceptions. New writes to the old `depoto.log` and opt-in webhook `includes/log.txt` files are replaced by WooCommerce logging; historical files are not modified. WooCommerce controls retention, and logger failures do not interrupt processing.

## Run checks

From the plugin root:

```sh
php tests/vat-regression.php
php tests/settings-regression.php
php tests/vat-records-regression.php
php tests/logging-regression.php
php tests/lifecycle-regression.php
```

The settings check starts a temporary localhost PHP server to exercise real POST/filter_input handling. The logging check uses the real vendored GraphQL/OAuth client with simulated HTTP responses. Lifecycle checks exercise real export, webhook scheduling and retry paths with local fixtures. No network traffic or real orders are generated; fixture IDs and credentials are invented.

Coverage includes no-rate settings, only-zero choices, save/clear/redisplay, invalid/default rejection, unchanged untaxed totals, separate taxable mappings, pagination/cache behavior, null versus zero percentages, serialization, successful and failed exports, duplicate prevention, retry summaries, payment/cancellation outcomes, webhook retries, secret exclusion and logging failure isolation.

The vendored dependencies emit existing deprecation notices on PHP 8.5. No authenticated store/DEPOTO integration test was performed; live acceptance and accounting totals still need verification after selecting the account's correct zero record.
