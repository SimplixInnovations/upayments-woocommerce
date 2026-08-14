# Compatibility Matrix

This document separates **upstream capability claims** from **Simplix Innovations verification**. A feature is not marked Verified until it has passed a documented test environment.

| Area | Upstream position | Simplix status | Notes |
|---|---|---|---|
| Classic WooCommerce Checkout | Supported | Pending regression validation | Core upstream integration path. |
| Cart/Checkout Blocks — standard products | UPayments documents native support | Pending independent validation | WooCommerce requires payment gateways to register a frontend payment-method integration as well as declare compatibility. |
| Checkout Blocks — subscription/tokenization flows | UPayments recommends Classic Checkout for the most reliable subscription experience | Pending validation | Must be tested separately from standard-product Blocks support. |
| High-Performance Order Storage (HPOS) | No verified declaration recorded in this maintenance track yet | Audit required | Direct order/post access must be reviewed before declaring compatibility. |
| WPML / String Translation | Known upstream defect reproduced | Fix implemented — validation pending | Upstream gateway can pass a `null` gettext text domain, causing current WPML String Translation to throw a fatal `TypeError`. The `WC_Upayments::$domain` property is now initialized to `upayments` and the plugin `Text Domain` header is the stable `upayments` string instead of a URL. Runtime validation is still required before this row can be marked `Verified`. |
| My Account / theme interoperability | Generic UPayments account CSS previously forced WooCommerce My Account navigation/content dimensions and navigation presentation | Fix implemented — validation pending | Core WooCommerce selectors (`.woocommerce-MyAccount-content`, `.woocommerce-MyAccount-navigation`, and its descendants) and the generic `.woocommerce-page table.shop_table_responsive` rule have been removed from `assets/css/customer.css` so layout remains under the active theme/WooCommerce. Runtime cross-theme validation is pending. |
| PHP 8.4 / 8.5 | Not independently certified by this fork | Audit required | Compatibility will be based on runtime testing and static analysis, not header claims alone. |
| Saved cards / tokenization | Upstream feature | Pending regression validation | UPayments documents customer-token/card APIs. |
| Multi-merchant | Upstream feature | Pending regression validation | UPayments documents `ExtraMerchantsData`-based multi-merchant processing. |
| Webhook payment updates | UPayments documents `notificationUrl` as mandatory | Audit required | Plugin handling must be reviewed for validation, idempotency, order matching, and failure behavior. |
| Payment status verification | UPayments provides status lookup | Audit required | UPayments documents lookup by `track_id` and other identifiers; retry/rate-limit behavior must be respected. |

## Evidence sources

### UPayments

- WooCommerce integration: https://developers.upayments.com/reference/woocommerce
- Checkout Blocks support: https://developers.upayments.com/reference/woocommerce-core-block-checkout-support
- Webhooks: https://developers.upayments.com/reference/webhook
- Payment status: https://developers.upayments.com/reference/checkpaymentstatus
- Saved cards: https://developers.upayments.com/reference/retrievecustomercards
- Multi-merchant: https://developers.upayments.com/reference/multi-vendor-api

### WooCommerce

- Extension compatibility: https://developer.woocommerce.com/docs/extensions/best-practices-extensions/compatibility
- Cart/Checkout extensibility: https://developer.woocommerce.com/docs/block-development/extensible-blocks/cart-and-checkout-blocks/
- HPOS extension guidance: https://developer.woocommerce.com/docs/features/orders/high-performance-order-storage/recipe-book/

## Verification rule

A Simplix Verified entry should identify the tested WordPress, WooCommerce, PHP, checkout mode, HPOS state, multilingual state, and relevant payment feature. Validation evidence should be reproducible.
