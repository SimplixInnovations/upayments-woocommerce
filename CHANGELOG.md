# Changelog

All notable changes maintained by Simplix Innovations will be documented here. Historical upstream releases remain available in the upstream repository and release history.

## Unreleased

### Repository foundation

- Establish Simplix Innovations maintenance identity and governance.
- Add security, support, contribution, upstream, and maintainer policies.
- Add an evidence-based compatibility matrix and engineering roadmap.
- Add structured GitHub issue and pull-request templates.
- Add optimized Simplix Innovations repository branding assets.
- Remove the committed runtime `debug.log` and ignore runtime log output.

### Fixed

- Replace phone-derived customer token identity with canonical non-predictable 8-digit tokens for saved-card flows, eliminating phone-change card orphaning and provider-compliance gaps.
- Implement strict fail-closed Create Token establishment with 201-only success validation, protecting against transport failures, provider errors, and malformed responses.
- Enforce current-scope provenance for saved-card operations with immutable CREATE-ONLY identity establishment and multisite-recoverable meta keys.
- Add server-bound saved-card token validation requiring exact membership match against provider-returned card list before Charge dispatch.
- Protect legacy historical token identities from silent canonical overwrite pending dedicated Phase 9I migration, with customer-scoped safety fallback.
- Restrict saved-card retrieval to users with valid canonical or legacy-compatible provenance, eliminating unauthenticated Retrieve Cards calls during checkout rendering.
- Require login for guest Save Card requests with server-side rejection and generic customer notice.
- Separate provider-facing customer.mobile from saved-card/customer-token identity; billing phone is WooCommerce customer data, provider mobile is a separate validated field (PR15), and customerUniqueToken is canonical/legacy-compatible identity not recalculated from phone.
- Fix subscription new-card vs saved-card semantics: existing saved-card subscription no longer requires explicit Save Card opt-in toggle, only new-card subscription requires it.
- Fix Blocks Save Card consent to use reactive `useSelect` subscription instead of imperative `select()`, ensuring checkbox state always agrees with submitted extension data.
- Add strict Retrieve Cards request input validation requiring 8-18 digit numeric customer token before provider call.
- Add structured secret record v2 with generation ID and full-record verifier (binding domain, version, generation_id) for detecting secret corruption and credential/mode rotation.
- Add full prior-provenance validation with scope and generation binding to prevent cross-credential token reuse.
- Split structural provenance validation from current-generation binding so that prior-generation records are classified as generation_mismatch rather than generic invalid.
- Make history inspector query results trustworthy with pagination validation, duplicate-order-ID detection, page-size enforcement, and unloadable-order detection.
- Add card-token-only history protection: historical orders with credit card token but no customer token identity block canonical establishment pending Phase 9I migration.
- Add runtime token context validation before snapshot write and Charge dispatch, preventing stale or cross-scope token tuples from reaching the provider.
- Fix secret-existence query to use `$wpdb->query()` row-count semantics instead of `$wpdb->get_var()` null interpretation, preventing clean installations from being blocked.
- Distinguish missing secret option from malformed existing option using a unique sentinel, preventing silent replacement of corrupted secrets.
- Enforce exactly-one provenance record per user/scope pair, rejecting duplicate meta values as INVALID.
- Fix process_payment variable order: `$user_id` and `$has_selected_card` established before use.
- Enforce server-side Save Card request contract: guest Save Card rejected, save_card=1 + selected card rejected, non-CC save_card=1 rejected.
- Fix Classic template to render Save Card toggle only for logged-in users with save_card enabled.
- Fix Classic `toggleSaveCard()` to safely handle missing checkbox element.
- Fix Blocks `handleSubscriptionChange` to not restore stale card_token/save_card state.
- Stop UPayments from requiring billing phone when saving unrelated WooCommerce Account Details, preventing blocked profile saves. Billing phone is WooCommerce customer/order data according to merchant settings; provider customer.mobile is a separate PR15 validated international representation; customer.uniqueId is a legacy compatibility field; tokens.customerUniqueToken is canonical/legacy-compatible identity NOT derived from phone and NOT requiring phone to establish.
- Stop persistently overriding WooCommerce phone-field configuration on every request.
- Stop globally forcing billing phone required through UPayments Classic field filters, allowing other gateways to respect merchant phone settings.
- Escape UPayments payment metadata at render time in admin order details and order-details template.
- Harden Classic saved-card rendering against malformed or provider-controlled values with structural guards and context-specific escaping.
- Contextually escape template text, attributes, and URLs in Classic checkout templates.
- Safely encode Classic inline notice strings for JavaScript context using wp_json_encode() with HEX flags.
- Add shared cross-request payment-method availability cache with credential-scoped fingerprint to prevent stale results after API key changes.
- Add durable 65-second refresh cooldown for the payment-methods endpoint to respect UPayments' documented one-request-per-minute limit.
- Add concurrency-safe refresh gate using MySQL named advisory locks (GET_LOCK) to prevent multiple PHP workers from simultaneously calling the payment-methods endpoint.
- Implement fail-closed behavior when refresh coordination is unavailable (lock contention, unsupported database, or gate-persistence failure).
- Harden checkout request validation: strict payment-source allowlist, consistent Classic/Blocks subscription plan and interval validation, deterministic save-card input handling, fail-closed charge-response structure and redirect URL validation, generic customer-facing provider failure messages, subscription-context enforcement, guest-subscription rejection, and fail-closed payment-method availability state.
- Verify UPayments callback payment state against the authenticated Get Payment Status API before allowing WooCommerce paid-order transitions.
- Neutralize legacy `getCustomerUniqueToken()` to safe empty-string stub; no provider calls, no phone token derivation. Retained temporarily for third-party compatibility.
- Fix history completeness: safety cap (200 orders) no longer treated as scan completion; `expected_total > 200` with clean first-200 returns INDETERMINATE, not NONE/PRIOR_SCOPE_ONLY.
- Add historical order meta cardinality enforcement: duplicate metadata for token/kind/scope/generation/card keys classified as MALFORMED_SCOPED via reusable WC_Order helper.
- Add current-order residual migration evidence check: token-dependent operations fail closed if preserved legacy/corrupt identity evidence exists on the order.
- Use unique meta semantics (`delete_meta_data` + `add_meta_data($key, $value, true)`) for all identity snapshot writes.
- Verify snapshot persistence via fresh `wc_get_order()` reload before Charge dispatch; any missing/duplicate/wrong-value snapshot blocks Charge.
- Ordinary non-token payments no longer initialize token identity secret, scope, or generation.
- Fix Blocks `getExtensionData()` to read full extension map then access NAMESPACE, not pass namespace selector argument.
- Fix Blocks Save Card toggle to require `is_logged_in` in addition to `save_card_enabled` and CC source.
- Add page-consistency guards: scanned count cannot exceed expected_total, page number cannot continue beyond max_pages.
- Add read-only secret record access for cleanup paths; cleanup never creates a new identity secret.
- Force-refresh order metadata from storage before security-sensitive decisions; stale cached metadata cannot influence token classification.
- Use raw/edit context for security metadata reads, preventing frontend filters from transforming security evidence.
- Add card-token cardinality check in stale cleanup; duplicate/malformed card-token metadata preserves all identity evidence.
- Stage UPayments_Checkout_Selected after cleanup/refresh/residual-evidence gate to prevent forced refresh from erasing payment-source metadata.
- Strict Charge HTTP 201 enforcement; non-201 responses fail closed without success redirect processing.
- Strict payment-button availability response: require HTTP 201, status === true (boolean), normalized isWhiteLabel/payButtons.
- Version availability cache (`upay_pm_v2_`) to prevent stale pre-H9 cached data from bypassing strict parser.
- getPaymentIcons consumes only normalized availability data with strict boolean/integer checks.
- Blocks saved-card server sanitization: each card entry sanitized to token/number/brand scalars before passing to JS.
- Blocks defensive JS: card.token/number/brand require non-null object guard before access.
- Blocks mount no longer unconditionally resets subscription plan/interval state.
- HTTP 422 fails closed as http_422; no duplicate-token inference, provider-message matching, or automatic retry.
- Prevent a WPML String Translation fatal error by ensuring the UPayments gettext text domain is always a valid string during gateway construction.
- Correct the plugin `Text Domain` header from a URL to the stable `upayments` domain.
- Prevent UPayments frontend CSS from overriding WooCommerce My Account navigation/content layout and generic responsive table styling.
- Harden subscription account actions with consistent nonce verification, action allowlisting, and sanitized request handling.
- Restore TLS certificate verification for authenticated UPayments API requests.
- Prevent UPayments diagnostic logging from exposing merchant credentials, customer/payment payloads, and raw callback data, and route opt-in debug output through WooCommerce logging.
- Prevent repeated automatic subscription charge dispatches for locally tracked billing cycles by introducing per-billing-cycle attempt claims, isolating cron processing per subscription, and hardening auto-deduction transport and response handling.
- Prevent paused subscriptions from being auto-billed by the subscription cron; only `active` subscriptions are eligible for automatic auto-deduct dispatch.
- Remove the duplicate legacy `upay_hourly_cron_job` schedule so `Scheduler::init()` is the sole canonical owner of the subscription cron.
- Harden remaining authenticated UPayments API requests (charge, create-customer-unique-token, check-payment-button-status, retrieve-customer-cards) with explicit TLS verification, redirects disabled, finite network timeouts, and structured transport failure handling that does not expose raw cURL transport errors to customers.
- Harden response-structure validation for the UPayments payment-methods, payment-icons, and saved-cards flows so that malformed JSON, missing fields, and unexpected scalar/non-array values no longer produce undefined-index warnings or downstream type errors on the checkout and My Account pages.

### Planned compatibility work

- Scope customer/account CSS to plugin-owned components.
- Audit PHP 8.x compatibility.
- Validate Checkout Blocks and HPOS behavior.
- Review webhook, callback, redirect, and status-verification flows.
- Introduce automated quality and release checks.

## Upstream baseline

The fork currently tracks upstream plugin version **3.1.1** as declared by the main plugin file. Upstream historical release notes remain attributable to the upstream project.
