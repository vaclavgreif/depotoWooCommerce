# VAT regression checks

Run from the plugin root:

```sh
php tests/vat-regression.php
php tests/settings-regression.php
```

These checks exercise the plugin classes directly with small WooCommerce/WordPress fixtures. The second check starts and stops its own localhost PHP server to exercise the actual `filter_input(INPUT_POST, ...)` settings-save path. No real orders are sent and the numeric fixture IDs are invented test values.

The payload scenarios cover missing/blank mappings, selected taxable and zero-percent record IDs, mixed selected/unselected rates, untaxed shipping/fees, no payment fee, and invalid stored IDs. An untaxed fixture checks that product/shipping/fee prices still total 115; this does not establish live DEPOTO accounting behavior or verify every pricing/rounding scenario.

## API contract verified

On 2026-10-26, a read-only query to `https://server1.depoto.cz/graphql` confirmed that `createOrderItem_.vat` is nullable `Int`, with no declared schema default. The schema can be explored at https://server1.depoto.cz/graphql/explorer using:

```graphql
{ __type(name: "createOrderItem_") { inputFields { name defaultValue type { kind name } } } }
```

When No VAT is selected, the plugin omits `vat`, rather than sending 0, an empty string, or an invented VAT record ID. A real mapped zero-percent VAT ID remains a valid explicit selection. Untaxed lines only use that zero-percent mapping and no longer inherit the highest configured tax rate. DEPOTO may still apply business defaults when a field is omitted; actual order acceptance and final totals require a subsequent account-level transfer check.

No authenticated DEPOTO account or WooCommerce test site was available during this fix. Only schema introspection and local tests were performed.
