## Residual Correction #22 — Deep Reclassification, Dynamic Retrieve, Store API Security Workflows, Production Code Cleanup

**Parent**: 2ca148ada1583546b1e0c5332a3f2eea8c0dc92a
**Head**: (pending commit)
**Branch**: feat/canonical-customer-token-identity

### Changes

1. **Deep assertion taxonomy audit**: Reclassified XCR/XCV/XSUB/XCUS/XFI/XAUTH from `semantic_runtime` to `helper_unit_runtime` or `harness_self_test` — these are direct helper/classifier invocations, not production workflow outcomes.

2. **PE assertions strengthened**: Added case-specific behavioral assertions for critical scenarios (PE-11 forbidden quantity, PE-13 non-terminating decimal).

3. **HOSTILE section strengthened**: Added semantic assertions for CreateToken count.

4. **SP-SUCCESS-1 strengthened**: Added order metadata writes assertion.

5. **Dynamic Retrieve stub**: Made Retrieve-cards transport stub dynamic like Create-token — now inspects actual outbound request body at dispatch time, captures `customerUniqueToken`, and returns scenario-specific `customerCards`.

6. **SP-SAVE-CARD implemented**: Full save-card workflow with dynamic Create-token echo. Validates Create=1, Retrieve=0, Charge=1, result=success, exact redirect, 8-digit canonical token, Charge customerUniqueToken=established token, creditCard absent, paymentGateway.src=cc, hostile Classic exclusion.

7. **SP-SELECTED-CARD implemented**: Selected-card path with Retrieve membership validation. Uses `establish_then_select` mode to create real token with correct scope/generation. Validates Create=0, Retrieve=1, Charge=1, result=success, outbound Retrieve customerUniqueToken=established token, Charge creditCard=established token.

8. **SP-CARD-MISMATCH implemented**: Authorization gate test where Retrieve returns customerCards WITHOUT the submitted card. Validates Retrieve=1, Create=0, Charge=0, result=failure, zero identity/provenance/usermeta writes (no unauthorized mutation).

9. **Infrastructure fixes**:
   - Fixed `wpdb->prepare()` stub to substitute `%s` parameters — was causing `bootstrap_lock_contention` for all lock-based operations.
   - Added `wp_salt()` stub and `$wpdb->prefix` property to bootstrap.
   - Fixed `test_mode` key mismatch in child settings.
   - Fixed Retrieve callback to use reference capture for `establish_then_select` mode.
   - Fixed Create-token callback to store response token in state for parent assertions.

10. **Dead variable removed**: Removed `$save_card_on` from Blocks PHP — returns `false` directly.

11. **PR16 comments removed**: Replaced with durable terminology in production code.

12. **Docblock fixed**: `normalize_store_api_route()` return type corrected.

### Final Counts

| Category | PASS | FAIL |
|----------|------|------|
| semantic_runtime | 598 | 0 |
| helper_unit_runtime | 677 | 0 |
| static_source | 46 | 0 |
| harness_self_test | 139 | 0 |
| lint_tooling | 10 | 0 |
| **Total PHP** | **1470** | **0** |

| Category | PASS | FAIL |
|----------|------|------|
| runtime | 80 | 0 |
| static | 15 | 0 |
| harness | 41 | 0 |
| **Total Blocks** | **136** | **0** |

### Semantic Runtime Gate

- Mandatory floor: 560
- Current: 598
- Status: **PASS** ✓

### Validation Commands (12/12 PASS)

```
php -l UPayments.php                                    → 0
php -l includes/Token/CustomerTokenIdentity.php         → 0
php -l includes/class-wc-gateway-upayments-blocks.php   → 0
php -l templates/new-design-form.php                    → 0
php -l tests/harness/phase-9g-h12-php-harness.php       → 0
node --check assets/js/upayments-block.js               → 0
node --check assets/js/new-upay.js                      → 0
node --check tests/harness/phase-9g-h12-blocks-harness.js → 0
git diff --check                                        → 0
git diff --check origin/main...HEAD                     → 0
php -l tests/harness/_bootstrap.php                     → 0
php -l tests/harness/store_api_child.php                → 0
```

### Phase 9I Blockers

All 13 Phase 9I blockers remain **OPEN**.

### CI Status

```
combined statuses: []
PR-triggered workflow runs: []
No CI evidence exists for this head.
```

### Frozen Subscription Files

- Scheduler.php: 5251866d4df2d1326e7c09f0c8ec1d146c0bb325
- CycleClaim.php: c34d83e2d77cc65024fe663e4c378cecb2b17347

### STOP. DO NOT MERGE.

Awaiting reviewer verification.
