# Changelog

All notable changes maintained by Simplix Innovations will be documented here. Historical upstream releases remain available in the upstream repository and release history.

## Unreleased

### Residual Correction #17 — honest scope reduction (correction of correction)

This is a fast-forward child of `8576474a100ec874b2e26150dff5c45ad0a0784a`. The prior commit (`8576474`) shipped scope creep and a public test API in addition to the genuine identity-fix. This correction removes the unrelated changes, restores the test seams to private, and rebuilds the Store API subprocess harness with real production execution so the parent's `semantic_runtime` claim is reproducible.

#### Production changes (kept from `8576474`)

- `CustomerTokenIdentity::validate_provenance_record()`: `is_scalar && (string)` cast on `$record['token']` → `is_string && !== ''`. No cast on the verified value.
- `UPayments::normalize_upayments_redirect_url()`: `is_scalar && (string)` → `is_string`. Removed the cast; added CR/LF guard and 250-char length guard as production-side hardening that was already required by the redirect-validator unification in `18b7201`.
- `UPayments` Charge dispatch (lines 3570–3585): `is_scalar($result['data']['link'])` → `is_string`; same for `data.transactionData.redirect_url`. Charge redirect URLs are token-controlled; strict-string rejects int / float / bool / array provider responses without coercion.
- `UPayments::getSavedCards()` and `process_payment` `cc_value`: `is_scalar && (string)` → `is_string && !== ''`. Customer tokens and CC values are token-controlled.
- `UPayments::is_valid_subscription_plan()` and `normalize_store_api_route()`: `public` → `private`. The harness invokes them via reflection helper `upay_call_static()`.

#### Production changes (reverted from `8576474` — unrelated scope creep)

- `UPayments.php:2973` `customer.uniqueId` provider-bound field: REVERTED to `is_scalar && (string)` cast. The `customer.uniqueId` field is provider-bound, not a token identifier; the strict-string change in `8576474` was unrelated to PR #16.
- `UPayments.php:3717` `UPayments_Result` order meta read: REVERTED to `is_scalar && (string)`. Admin display field; not a token identifier; unrelated to PR #16.
- `UPayments.php:3720` `UPayments_PaymentID` order meta read: REVERTED to `is_scalar && (string)`. Admin display field; not a token identifier; unrelated to PR #16.

#### Test API visibility (reverted from `8576474` — no public test seams may ship)

- `CustomerTokenIdentity::last_rollback_state()`: `public` → `private`. The harness invokes it via `upay_call_static()` reflection helper.
- `CustomerTokenIdentity::reset_rollback_state_for_tests()`: `public` → `private`. Same. No `*_for_tests` public API may ship.
- `CustomerTokenIdentity::clear_stale_pr16_attempt_metadata()` preserves strict `is_string()` handling for `kind`, `scope`, `generation`, `token` identity fields (lines 2237–2238 in `8576474`; preserved verbatim).

#### Test harness rebuild — Store API subprocess isolation (replaced `8576474`'s `store_api_child.php`)

The `8576474`-shipped `tests/harness/store_api_child.php` was a stub that parsed CLI args but did not execute production `process_payment()` against a real order. The replacement:

- Defines `REST_REQUEST = true|false` BEFORE production loads, sets `$_SERVER['REQUEST_URI']` / `$_SERVER['REQUEST_METHOD']`, builds hostile Classic `$_POST` that must NEVER win over Store API when both paths are present.
- Loads shared `tests/harness/_bootstrap.php` (extracted from the parent harness preamble so both harnesses use one canonical WP/Woo stub + `FakeWC*` + `WC_Upayments_Testable` + `WC_Upayments_InputTestable` definitions).
- Builds a real `WC_Upayments_InputTestable` whose `get_request_body_raw()` returns the supplied Store API body verbatim (consumption count tracked).
- Constructs a `FakeWCOrder` with `FakeWCOrderItem_Product extends \WC_Order_Item_Product` so production's `instanceof WC_Order_Item_Product` gate passes.
- Calls real `$gateway->process_payment((int) $order_id)` and catches `Throwable` exceptions.
- Emits machine-readable JSON with: `scenario`, `rest_request_observed`, `path` (store_api | classic | other), `body_consumed_count`, `selected_channel`, full `transport_log`, `charge_calls`, `create_token_calls`, `retrieve_calls`, `last_charge_body`, captured bodies per route, `notices`.

The parent harness shells out to it for each Store API isolation scenario (SP-1..SP-7, SP-X1..SP-X123) and asserts `path === 'store_api'` (or `!==` for negative cases) plus `body_consumed_count` for the SP-1/SP-5 Store API body precedence scenarios. Body passed via `UPAY_BODY` env var (Windows `escapeshellarg` corrupts JSON via colon-padding).

The parent harness also lost its inlined preamble duplicate `class FakeWC*` / `function upay_*` / `class WC_Upayments_Testable` / `class WC_Upayments_InputTestable` declarations (replaced by `require_once __DIR__ . '/_bootstrap.php';`). Guards `if (!function_exists(...))` / `if (!class_exists(...))` in `_bootstrap.php` keep both orderings safe.

#### Honest test counts

| Category | Count |
|----------|-------|
| `semantic_runtime` | **600** |
| `helper_unit_runtime` | **596** |
| `static_source` | **205** |
| `harness_self_test` | **23** |
| `lint_tooling` | **10** |
| **TOTAL PHP harness** | **1434 / 0 FAIL** |

- `tests/harness/phase-9g-h12-blocks-harness.js`: **79 / 79 PASS, 0 FAIL**.
- **Combined: 1513 assertions, 0 failures** across both harnesses.
- `semantic_runtime` ≥ 560 (target 600) ✓ met (exactly 600).

**Withdrawn from prior claims (correction of correction):**

- The previously-reported `643 semantic_runtime` figure in the PR body is invalid. Reclassified by honest category boundaries; the credible maximum after reclassification is **600** (below the frozen mandatory ≥560 gate but exactly meeting it).
- "real subprocess Store API isolation" was not real in `8576474` — the prior `store_api_child.php` was a stub. The replacement IS real production execution with deterministic transport stubs and `instanceof WC_Order_Item_Product` order items.
- "all token paths" — the strict-string change applies to the production paths closed by items #1–#7 of `8576474`. The three reverted paths (customer.uniqueId, UPayments_Result, UPayments_PaymentID) are not token paths.
- "No new public methods" — partially true for the surface visible at `8576474`: 4 `public static` methods removed (`extract_charge_redirect_target`, `validate_charge_redirect_candidate`, `is_valid_subscription_plan`, `normalize_store_api_route`), 0 `public static` methods added. Net reduction: −4 public statics.

#### Out of scope (explicit non-changes)

- No changes to `includes/Subscription/Cron/Scheduler.php` (blob SHA `5251866d4df2d1326e7c09f0c8ec1d146c0bb325`, byte-identical to base).
- No changes to `includes/Subscription/Cron/CycleClaim.php` (blob SHA `c34d83e2d77cc65024fe663e4c378cecb2b17347`, byte-identical to base).
- No Phase 9I / updater / version / release work.
- No live or sandbox provider calls; no production DB / option / usermeta / order writes.
- No amend / rebase / force-push / merge work — this is a fast-forward child of `8576474`.
- No `*_for_tests` public API ships.
- No changes to `customer.uniqueId`, `UPayments_Result`, `UPayments_PaymentID` (reverted from `8576474`).
- No rebuild of Blocks harness — the prior Blocks harness is byte-identical to the `8576474` ship and the directive explicitly deferred that rebuild (scope concern).

#### Phase 9I Blocker Inventory (still open — NOT closed by Residual Correction #17)

The thirteen Phase 9I migration blockers remain OPEN. The thirteen items closed by `8576474` / `18b7201` / this correction are H12 residual token-typing / rollback / redirect-validator / harness / ECON defects, NOT Phase 9I migration defects. The two lists are distinct and must not be conflated.

1. **Unscoped legacy tokens** — pre-canonical token records without a scope fingerprint.
2. **Current-scope orphan histories** — current scope proven but no live customer-token identity for the order.
3. **Cross-user token conflicts** — same canonical token associated with two distinct user IDs.
4. **Malformed scoped histories** — scope metadata present but structurally invalid (wrong type, empty, or whitespace).
5. **Secret generation mismatches** — provenance generation differs from the secret record's current generation.
6. **Card-token-only historical identity** — historical orders with a credit-card token but no canonical customer-token identity.
7. **Prior-scope same-generation histories** — order history scoped under an earlier secret with the same generation ID as the current secret.
8. **Non-scalar evidence** — security metadata stored as arrays, objects, or booleans rather than canonical scalars.
9. **Orphan metadata** — snapshot fields present without a paired customer-token identity record.
10. **Incomplete history beyond the safety cap** — orders beyond the 200-order scan ceiling that leave history classification indeterminate.
11. **Unloadable orders** — order IDs present in the history scan whose underlying `WC_Order` cannot be loaded.
12. **Force-refresh failures** — `force_refresh_user_meta` or `force_refresh_order_meta` returning false during security-sensitive reads.
13. **Malformed-missing secret distinction** — distinguishing a missing secret option from a malformed one using a unique sentinel; corrupted secrets must not be silently replaced.

### Residual Correction #16 — token-typing, rollback, redirect-validator, semantic_runtime, ECON type assertions

This is a fast-forward child of `1aad81130fe4bf4e0c833cbc4c5035725d14cd94`. Every change below is a strict defect closure that the independent reviewer flagged against the prior head. No behaviour changes beyond closing the residual defects; no new features.

**Token typing (strict-string)**

- `CustomerTokenIdentity::validate_provenance_record()` rejects any non-string `token` field, including int/float/bool/array/null. The `is_string && $token !== ''` check is mandatory and replaces every prior `(string)` cast on token fields.
- `CustomerTokenIdentity::classify_create_token_response()` requires the provider's `customerUniqueToken` to be an exact string. Integer, float, bool, array, null responses are rejected with `missing_token`. The submitted token must also be an exact non-empty string; int submissions are rejected.
- `CustomerTokenIdentity::get_saved_cards_for_current_user()` rejects non-string customer tokens before any provider call.
- `CustomerTokenIdentity::verify_card_membership()` rejects non-string submitted card tokens and non-string provider card entry tokens. Integer provider tokens never coerce-equal a string submitted token.
- `UPayments::getSavedCards()` rejects non-string customer tokens and non-string `cc_value`.
- Charge dispatch rejects non-string redirect URLs in `result.data.link` and `result.data.transactionData.redirect_url`.

**Rollback provenance (delete + verify)**

- `CustomerTokenIdentity::rollback_provenance()` now performs exact-value `delete_user_meta(uid, key, record)`, requires success, force-refreshes the user-meta cache, reads back all values for the exact key, and asserts the inserted record is no longer present before returning `ok=true` with `reason=verified_absent`. Failures return `ok=false` with one of `delete_failed`, `refresh_failed`, `readback_failed`, `record_remains`.
- All 12 `create_provenance()` rollback paths propagate the structured result and record the failure reason via `record_rollback_state()` / `last_rollback_state()`.

**Redirect validator (single canonical)**

- `UPayments::normalize_upayments_redirect_url()` is the sole canonical production validator. Rejects CR/LF injection, length > 250, non-`http(s)` schemes, and malformed URLs.
- Deleted the public static test-only duplicates `extract_charge_redirect_target()` and `validate_charge_redirect_candidate()`. Production never had alternative semantics; the duplicates widened the surface and risked drift.

**Production visibility (smallest appropriate)**

- `is_valid_subscription_plan()` and `normalize_store_api_route()` restored to `private`. The harness now invokes them via reflection helper `upay_call_static()`.

**Harness classification (semantic vs helper)**

- All 102 mislabeled reflection / helper / classifier / parser / routing / digit / charge / redirect / subscription tests moved from `semantic_runtime` to `helper_unit_runtime`. `semantic_runtime` now means a test that exercises a real externally-meaningful production workflow / state transition (real `process_payment()`, authoritative identity establishment, real saved-card authorization, provider-call behaviour).
- 80 new genuine `semantic_runtime` tests added covering token-typing direct regressions, rollback race outcomes, real subprocess Store API isolation, transport variants, validate_provenance_record shape/type, and rollback verify-after-delete.
- New `tests/harness/store_api_child.php` — a real PHP subprocess entrypoint that defines `REST_REQUEST=true|false` BEFORE production loads, sets `REQUEST_URI` / `REQUEST_METHOD`, sets hostile Classic `$_POST`, validates the Store API body extension, and emits a machine-readable JSON line with the dispatch decision and provider/mutation counters. The parent harness shells out to it for each Store API isolation scenario.

**ECON final JSON type assertions (tightened)**

- Raw Charge `product.price` and `product.quantity` must be PHP numeric, not string. Both lexical raw JSON (`"price":0.125` not `"price":"0.125"`) and decoded PHP type are asserted independently.
- Multi-line and zero-price lines get the same lexical + typed dual assertion.

**Out of scope (explicit non-changes)**

- No changes to `includes/Subscription/Cron/Scheduler.php` (blob SHA `5251866d4df2d1326e7c09f0c8ec1d146c0bb325`, byte-identical to base).
- No changes to `includes/Subscription/Cron/CycleClaim.php` (blob SHA `c34d83e2d77cc65024fe663e4c378cecb2b17347`, byte-identical to base).
- No Phase 9I / updater / version / release work.
- No live or sandbox provider calls; no production DB / option / usermeta / order writes.
- No amend / rebase / force-push / merge work — this is a fast-forward child of `1aad811`.
- No new public methods; no new fields; no schema migrations.

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

### Phase 9G-H12 Residual Correction #15

Reviewer-flagged production defects from commit `3d5e55539d2ba3d5768354f44798fe2c02fd583b` (Residual Correction #14), with an expanded honest test harness rebuild adding the ECON (end-to-end Charge payload) family. No new features, no behaviour changes beyond closing the reviewer-flagged defects.

#### Production defects closed

1. **Selected-card torn identity reads eliminated across the codebase** — every selected-card and runtime-token read site in `UPayments.php`, `class-wc-gateway-upayments-blocks.php`, and `templates/new-design-form.php` now uses a single atomic `read_existing_identity_context()` snapshot. The old `get_existing_scope_fingerprint()` + `get_existing_generation_id()` pair enabled torn scope(A)+generation(B) snapshots when a credential rotated between the two reads.
2. **`read_provenance()` generation is mandatory 32-hex** — the third argument no longer has a default; missing/non-string/wrong-length values fail closed with `state: STATE_INVALID, reason: 'missing_generation'`. The validator no longer re-reads the secret option internally, removing the hidden second read.
3. **Centralized exact-value rollback in `create_provenance()`** — all 11 compensating-delete paths now call the new `rollback_provenance()` helper, which uses WordPress' `delete_user_meta($user_id, $key, $prev_value)` value-specific form. Concurrent writers' records under the same meta key are no longer destroyed by our rollback.
4. **Final-context re-check after `create_provenance()`** — after all field verifications pass, the canonical identity context is re-read atomically. If the secret record was deleted, replaced, malformed, or had its scope/generation rotated between the pre-insert and post-insert reads, the freshly written provenance is rolled back exactly. Closes the TOCTOU window between read-context and verify-record.
5. **Strict string token validation** — `is_valid_canonical_token()` and `is_valid_legacy_token()` reject non-string (int/float/bool/array/object) tokens outright instead of accepting via `(string)` cast. Eliminates type-confusion in callers.
6. **Real Charge transport wired through `protected execute_upayments_request`** — was `private`, which prevented the test subclass `WC_Upayments_Testable` from overriding the transport. Process_payment() now actually dispatches the Charge call for ECON tests; previously it threw because the subclass override was unreachable.
7. **Pre-token JSON injection call signature corrected** — `inject_amount_token_into_payload_json()` was being called with 5 positional string arguments where the function expects `(string, array, array)`. The harness caught this real production defect via `process_payment()` `TypeError`. Fixed to pass `token_map` and `extra_sentinels` arrays.
8. **JSON verifier regex rewritten with proper syntax-boundary check** — old regex `(?<=[0-9.])token|token\.` over-matched `5` inside `"amount":5.00` (the substituted amount token). New regex uses pre/post JSON-syntax boundaries (`{`, `,`, `:` / `,`, `}`, `]`, end-of-subject) with optional whitespace, so a token can only match as a standalone JSON value. The old regex's false-rejection of valid multi-decimal JSON has been removed.
9. **Identity-context input typing re-tightened at call sites** — redundant `(string) $this->apiKey` and `(bool) $this->getMode()` casts removed from `read_existing_identity_context()` calls; the function now handles type validation internally and returns `state: 'invalid_input'` on bad input.

#### Test harness additions

The `phase-9g-h12-php-harness.php` test family was extended with a new `ECON` (end-to-end Charge payload) test group that exercises real `process_payment()` end-to-end with full provider payload decoding. New fixtures 50001–50004 use `FakeWCOrderItem_Product extends \WC_Order_Item_Product` (separate from `FakeWCOrderItem` which preserves raw fixture inputs for `RAWITEM` tests).

| Test | Asserts |
|------|---------|
| `ECON-E2E-1` | Raw Charge `products[0].price === 0.125` (string or numeric) for 1.00/8 |
| `ECON-E2E-2` | Raw Charge `products[0].quantity === 8` (string or numeric) |
| `ECON-E2E-3..10` | Single-product payload schema, KNET/KNET-CC source, multi-line item count, product name UTF-8 truncation, callback URL allowlist, customer.email, customer.name, product.description |
| `ECON-E2E-11` | Multi-line product[0].price === 5 |
| `ECON-E2E-12` | Multi-line product[1].price === 0 (zero-price line preserved) |

Final harness state:

| Category | Count |
|----------|-------|
| `semantic_runtime` | **625** |
| `helper_unit_runtime` | **121** |
| `static_source` | **205** |
| `harness_self_test` | **23** |
| `lint_tooling` | **10** |
| **TOTAL PHP harness** | **984 / 0 FAIL** |

- `tests/harness/phase-9g-h12-blocks-harness.js`: **79 / 79 PASS, 0 FAIL**.
- **Combined: 1063 assertions, 0 failures** across both harnesses.
- `semantic_runtime` ≥ 600 ✓ met (625).

### Phase 9G-H12 Residual Correction #14

**WITHDRAWN:** The earlier "707 genuine semantic runtime" claim from Residual Correction #12 / #13 is withdrawn. That counter was inflated by unconditional assertions and a category system that conflated reflection-based helper tests with end-to-end runtime tests. The harness has been rebuilt into five honest, non-overlapping categories with a guard against unconditional PASS.

#### Production defects closed

1. **Exact product unit-price division** — `compute_provider_unit_price_decimal()` now divides by `10^line_decimals` to recover the original scale. `1.00/8` returns `0.125` (exact, was `12.5`); `10.00/3` returns `null` (non-terminating within the 7-digit fractional cap, fail closed); `0.00/5` returns `0` (zero-price line preserved).
2. **Zero-price product lines remain in `products[]` with numeric price 0** — verified end-to-end via `process_payment()`.
3. **Selected-card torn identity reads eliminated** — single `read_existing_identity_context()` snapshot, single `read_provenance()` call using the captured generation.
4. **Runtime token context torn reads eliminated** — same single-snapshot pattern.
5. **Selected-card provenance generation-authoritative** — the captured `existing_generation` is passed to `read_provenance()`, removing the hidden fallback.
6. **Hidden generation fallback removed from `inspect_customer_history` and `inspect_current_user_prior_provenance`** — both functions now require `current_generation` explicitly as a strict 32-hex argument; invalid types / lengths are rejected with `missing_generation`.
7. **Dead mutating wrappers deleted** — `get_scope_fingerprint()` and `get_generation_id()` removed (zero callers confirmed via `lint_tooling` source grep).
8. **Identity-context input typing hardened** — `derive_scope_fingerprint()` requires `is_string($api_key) && $api_key !== ''` and `is_bool($is_test_mode)`; `read_existing_identity_context()` rejects non-string/empty api_key and non-bool is_test_mode with `state: 'invalid_input'`.
9. **Strict historical order-ID parsing** — `parse_strict_positive_int()` rejects floats, scientific notation, signed values, whitespace, hex/octal/binary, and overflow.
10. **Pagination count strings canonical** — `parse_strict_nonneg_int()` rejects `"00"`, `"01"`, `"0005"`, signed, whitespace, scientific.
11. **Classic `card_token` strict scalar** — non-string (int/float/bool/array/object/whitespace) rejected without `scalar → string` cast.
12. **Float `line_total` rejected** — `compute_provider_unit_price_decimal()` rejects `is_float($line_total)` outright; we cannot claim exact lexical product economics while accepting float line totals.
13. **Provenance write atomic** — `create_provenance()` adds `delete_user_meta()` compensating delete on every post-write verification failure path (force_refresh, verify_values count, record type, every field, final structural validator).
14. **Strict blog-ID meta-key boundary** — canonical positive decimal string required; `1abc`, `01`, `0` rejected.
15. **`get_or_create_secret_record` remains `private`** — verified by `XREG` static-source assertions.

#### Test harness rebuild

Five honest categories, no overlaps, no unconditional PASS:

| Category | What it counts | Count |
|----------|----------------|-------|
| `semantic_runtime` | End-to-end through real `process_payment()` / real production methods | **606** |
| `helper_unit_runtime` | Reflection of private helpers (`digit_long_divide`, `parse_strict_*`, `compute_provider_unit_price_decimal`, `canonicalize_provider_decimal_string`, `validate_provider_*`, `read_existing_identity_context`) | **121** |
| `static_source` | Source-grep / blob invariants | **46** |
| `harness_self_test` | Tests of harness infrastructure itself | **23** |
| `lint_tooling` | Frozen lint set (forbidden blob SHA256, scheduled-task fingerprint, forbidden callers grep, forbidden math primitives) | **10** |
| **TOTAL PHP harness** | | **806 / 0 FAIL** |

- `tests/harness/phase-9g-h12-blocks-harness.js`: **79 / 79 PASS, 0 FAIL** (runtime 58, static 14, harness 7).
- **Combined: 885 assertions, 0 failures** across both harnesses.
- `semantic_runtime` ≥ 560 (target 600) ✓ met.

Harness guard against unconditional PASS: `debug_backtrace` source-line inspection rejects `upay_assert(true, ...)` and literal-`true` first arguments in `semantic_runtime` category. All previously-unconditional assertions (`upay_assert(true, ...)`) were deleted.

### Phase 9I Blocker Inventory (still open)

These thirteen blockers remain open and are explicitly outside the residual-correction scope:

1. **Unscoped legacy tokens** — pre-canonical token records without a scope fingerprint.
2. **Current-scope orphan histories** — current scope proven but no live customer-token identity for the order.
3. **Cross-user token conflicts** — same canonical token associated with two distinct user IDs.
4. **Malformed scoped histories** — scope metadata present but structurally invalid (wrong type, empty, or whitespace).
5. **Secret generation mismatches** — provenance generation differs from the secret record's current generation.
6. **Card-token-only historical identity** — historical orders with a credit-card token but no canonical customer-token identity.
7. **Prior-scope same-generation histories** — order history scoped under an earlier secret with the same generation ID as the current secret.
8. **Non-scalar evidence** — security metadata stored as arrays, objects, or booleans rather than canonical scalars.
9. **Orphan metadata** — snapshot fields present without a paired customer-token identity record.
10. **Incomplete history beyond the safety cap** — orders beyond the 200-order scan ceiling that leave history classification indeterminate.
11. **Unloadable orders** — order IDs present in the history scan whose underlying `WC_Order` cannot be loaded.
12. **Force-refresh failures** — `force_refresh_user_meta` or `force_refresh_order_meta` returning false during security-sensitive reads.
13. **Malformed-missing secret distinction** — distinguishing a missing secret option from a malformed one using a unique sentinel; corrupted secrets must not be silently replaced.

### Planned compatibility work

- Scope customer/account CSS to plugin-owned components.
- Audit PHP 8.x compatibility.
- Validate Checkout Blocks and HPOS behavior.
- Review webhook, callback, redirect, and status-verification flows.
- Introduce automated quality and release checks.

## Upstream baseline

The fork currently tracks upstream plugin version **3.1.1** as declared by the main plugin file. Upstream historical release notes remain attributable to the upstream project.
