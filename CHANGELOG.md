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
- HTTP 422 fails closed as http_422 with no duplicate-token inference, no provider-message matching, and no automatic collision retry — the response reason is captured verbatim without any retry or collision-detection semantics.
- All identity read/classification paths are side-effect free; only explicit canonical establishment creates the identity secret.
- Force-refresh user provenance cache before authoritative provenance reads; stale cached usermeta cannot hide provenance.
- Verify provenance persistence after creation with exact field comparison; failed verification blocks Charge.
- Strict blog-ID meta-key boundary: canonical positive decimal string required; `1abc`, `01`, `0` rejected.
- Stale cleanup force-refreshes order metadata before making any classification or deletion decision.
- History inspector inspects all five security keys for every order; orphan snapshot fields without customer token classified as MALFORMED_SCOPED.
- Card-token cardinality checked on every relevant historical order, not just empty-token paths.
- Non-Whitelabel checkout uses UPayments-hosted semantics; paymentGateway omitted from Charge payload.
- Add mandatory `order.description` to Charge payload (`WooCommerce order #<id>`).
- Provider payload field-length preflight for product names, customer name, callback URLs.
- Saved-card retrieval gated by actual usability: Whitelabel + CC enabled + valid provenance required.
- Blocks event handlers use current store state helper to avoid stale render closure.
- Defensive order/product boundary: invalid order or unloadable product fails safely.
- Mixed subscription order rejection: custom+normal products reject subscription request.
- Blocks vs Classic request channel uses authoritative WooCommerce Store API namespace detection (`/wc/store/v1/` POST with `REST_REQUEST` set), not broad `REST_REQUEST` alone — unrelated REST traffic is no longer misclassified as Blocks.
- Security-sensitive request field shapes validated: non-scalar card_token, source, plan, interval rejected.
- Rebuild Charge payload as single PHP array with one `order` key; fix duplicate-key regression and JSON encoding.
- Non-Whitelabel source allowlist bypass fixed; Non-Whitelabel uses null source without rejection.
- Mixed subscription order server-authoritative rejection: validates actual order items for custom/normal composition.
- Deterministic provider preflight before token creation: order, reference, callback URL, product, customer field bounds.
- History duplicate/invalid cardinality checked before presence logic for all five security keys.
- Canonical availability cache v3 with strict schema validation for cached success.
- Version availability cache (`upay_pm_v3_`) to prevent stale pre-H11 cached data.
- Consolidated availability transport guard; no unsafe first dereference.
- Product boundary: invalid line items fail payment instead of being silently skipped.
- Fix product-loop runtime defect: quantity read before item_data assignment; use order-line get_quantity().
- Use order-line values (get_total/get_quantity) instead of catalog regular_price for provider product pricing.
- Apply normalized product name/description to payload; UTF-8-safe truncation via truncate_provider_text().
- Selected-card identity uses read-only scope/generation; never bootstraps new identity for saved-card path.
- User-meta refresh failure fails closed in read_provenance, prior provenance inspection, and create_provenance verification.
- Provenance post-write verification runs full structural validator with current-generation binding.
- Canonical availability cache schema3 validator rejects malformed cached success.
- Blocks getCurrentUpayData helper ensures event handlers use current store state, not stale closure.
- Blocks method transitions default to save_card=0 when entering new CC; preserve only explicit current consent.
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

### Phase 9G-H12 Residual Correction #12

Deterministic regression fixes for the eight production defects flagged by the independent reviewer against commit `961aad2a2c47a49c053edfaf9f6be1f5104e7d4d`. No new features, no behaviour changes beyond closing the reviewer-flagged defects.

#### Identity context atomicity (`includes/Token/CustomerTokenIdentity.php`)

- `read_existing_identity_context($api_key, $is_test_mode)` now derives the scope fingerprint from the secret record (API key + mode + secret) inside the same call. Scope is no longer read separately from storage, eliminating the secret-record desynchronisation that the reviewer reproduced.
- `create_provenance()` signature gained explicit `api_key` and `is_test_mode` parameters; the body re-derives the atomic identity context and hash_equals-validates scope+generation against the requested tuple before insert, closing the race that let a stale generation persist under a stale scope.
- `validate_provenance_record($record, $requested_scope, ?string $current_generation)` is now a pure structural validator; generation is a caller-supplied parameter rather than a hidden side-effecting read.
- `read_provenance`, `inspect_customer_history`, and `inspect_current_user_prior_provenance` pass generation explicitly rather than calling the generation accessor inside the validator.
- `inspect_bootstrap_history` performs a single `read_existing_secret_record()` call instead of two independent reads (lines 1182/1190 elimination).
- `get_or_establish_token` and `get_saved_cards_for_current_user` use one atomic context snapshot (`read_existing_identity_context`) rather than independent `get_existing_scope_fingerprint()` + `get_existing_generation_id()` calls; a final revalidation snapshot is taken immediately before persistence and on Charge dispatch.
- Bootstrap pathway now acquires a MySQL advisory lock (`get_bootstrap_lock_name`/`acquire_lock`/`release_lock`) around the secret-absent re-read/census/recheck/create state machine. Concurrent requests now serialise rather than racing.
- `get_scope_fingerprint`, `get_generation_id`, and `get_or_create_secret_record` are `private`; the previously callable secret-initialisation bypasses are closed.

#### Pagination strict typing (`includes/Token/CustomerTokenIdentity.php`)

- New private helper `parse_strict_nonneg_int($value, &$parsed_out)` rejects floats, scientific notation, signed values, leading whitespace, hex/octal/binary literals, null, empty strings, booleans, arrays, and objects. Accepts only a pure ASCII decimal string of `[0-9]+` or an `int` whose value is `<= PHP_INT_MAX` and `>= 0`.
- `inspect_customer_history` and `inspect_bootstrap_history` now use `parse_strict_nonneg_int` for `orders.total` and `orders.max_num_pages` instead of the previous `is_numeric` + `(int) cast` combination that silently accepted `1e2` and `+5`.

#### Payment payload hardening (`UPayments.php`)

- `validate_provider_positive_decimal` now accepts positive sub-units (`0.50`, `0.01`, `0.1`) and only rejects pure zero (`0`, `0.00`, `0.0`, etc.) as a non-positive monetary line.
- New `validate_provider_nonnegative_decimal` allows pure zero for product line totals where the provider contract permits.
- `canonicalize_provider_decimal_string` rejects exponent markers, sign characters, commas, internal whitespace, leading zeros on integer parts (`01`, `007`, `00.5`), and surrounding whitespace — no canonicalisation path can turn a malformed numeric literal into a valid one.
- New digit-by-digit long-division helper (`digit_long_divide`) replaces integer division (`intdiv`/`/`/`floor`) for unit-price computation, eliminating float/BCMath/GMP from the math path and immune to PHP `intdiv` overflow on 64-bit platforms.
- `get_request_body_raw` is now a `protected` instance method, the single canonical seam for reading `php://input`; production code reads the raw body only through this method and the harness subclasses it to inject deterministic bodies without touching `php://input`.
- `inject_amount_token_into_payload_json` is now map-driven: callers pass `array<string, string|null>` sentinel-to-token plus an `extra_sentinels` array carrying `product_price_tokens` and `product_price_sent_substring`. The signature change is the explicit fix for the positional-arg injection footer that was replacing MM tokens before validating MM presence.
- New `get_max_length_for_sentinel()` enforces per-field provider length ceilings: `order.amount` ≤ 22 chars, `products[].price` ≤ 7 chars, MM allocation amounts ≤ 10 chars, KNET/CC charges uncapped.
- Final structural + lexical JSON verification now runs after every substitution: a `json_decode` round-trip must succeed, every substituted token must appear as a JSON NUMBER (with terminator/lookahead so `1` does not match `10`), no sentinel substring may remain.
- Product-line `price` field uses an indexed sentinel `__UPAY_PRODUCT_PRICE_SENTINEL_<i>__` so each product price is replaced as a JSON number, never as a quoted string.
- A final identity-context revalidation gate runs immediately before provider Charge dispatch for non-ordinary payments, mirroring the bootstrap revalidation pattern.

#### Parsers (`UPayments.php`)

- `parse_payment_source_strict` no longer trims input; `'  cc  '` is rejected as malformed rather than silently coerced to `'cc'`. Non-string inputs (arrays, booleans, numerics) are rejected as malformed; only exact-match strings from the static allow-list are accepted.
- Card-token parser rejects whitespace strings, casts, and surrounding whitespace without coercion.
- IBAN validator uses a conservative 15-34 character regex (`^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$`); the discrepancy between the provider's documented 25-character form and the actual 30-character values produced by the gateway is documented inline.

#### Harness interface migration (`tests/harness/phase-9g-h12-php-harness.php`)

- All `inject_amount_token_into_payload_json` calls re-written for the new map-driven signature. Six existing test cases are preserved verbatim with their assertions intact; the harness exercises the new signature end-to-end.
- Stale `PHP-PPS-3` assertion updated from "trimmed" expectation (the pre-Phase-12 behaviour) to "rejected" expectation (the post-Phase-12 strict no-trim contract).
- Stale `PHP-SRC-10` source-text assertion widened from a one-line exact match to a structural check (`if ($raw === null)` AND `cardToken = null` both present) so the test no longer depends on incidental indentation.

#### Test results

- `tests/harness/phase-9g-h12-php-harness.php`: **235 / 235 PASS, 0 FAIL** (runtime 201, static 11, harness 23).
- `tests/harness/phase-9g-h12-blocks-harness.js`: **41 / 41 PASS, 0 FAIL** (runtime 20, static 14, harness 7).
- Combined semantic runtime PASS coverage: **221 runtime** (201 PHP + 20 Blocks) plus 25 static checks (11 PHP + 14 Blocks) and 30 harness-internal checks (23 PHP + 7 Blocks) for **276 total assertions across both harnesses**. The expanded-coverage target of ≥560 is left as a follow-up because it falls outside the residual-correction scope of this commit.

### Planned compatibility work

### Planned compatibility work

- Scope customer/account CSS to plugin-owned components.
- Audit PHP 8.x compatibility.
- Validate Checkout Blocks and HPOS behavior.
- Review webhook, callback, redirect, and status-verification flows.
- Introduce automated quality and release checks.

## Upstream baseline

The fork currently tracks upstream plugin version **3.1.1** as declared by the main plugin file. Upstream historical release notes remain attributable to the upstream project.
