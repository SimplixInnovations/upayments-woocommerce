## Residual Correction #20 — Honest Reclassification, Dynamic Create-token Echo, Adversarial Tests

**Parent**: 9c30836f9c7c9271f7d9fb06e010e730e0b6cc63
**Head**: a6b1854
**Branch**: feat/canonical-customer-token-identity

### Changes

1. **Reclassification of direct helper/validator calls**: CTV, LTV, TFK, GEN, SCP, UMK, LCK, VSP, NSR, CTR, BLN families moved from `semantic_runtime` to `helper_unit_runtime` — these are direct validator/helper invocations, not production workflow transitions.

2. **SP-6/SP-7 genuine subprocess outcomes reclassified**: The actual production outcomes (result=failure, charge=0, create=0, retrieve=0, mutations=0) from real process_payment() execution are now `semantic_runtime`.

3. **SP-SUCCESS-1 redirect assertion fixed**: Changed from `strpos` prefix match to strict equality `===`.

4. **Classic POST leak proof added**: SP-SUCCESS-1 now asserts hostile Classic values (card token, sentinel, subscription plan) are absent from Charge body.

5. **Dynamic Create-token echo**: `store_api_child.php` now inspects the actual outbound Create request body at dispatch time via callable transport response, instead of precomputing from inbound body.

6. **Callable transport support**: `_bootstrap.php` `execute_upayments_request()` now supports callable responses for dynamic transport stubs.

7. **Adversarial exact-label tests**: H-ST-20 replaced with real tests proving similar-prefix cards don't match, prop-only values don't satisfy leaf lookup, same-prefix cards resolve to different buttons, and leaf-contains mode throws.

8. **A/B setter isolation self-test**: B-INDEP3 proves same function F with two instances has independent state — mutate A, rerender both, B unchanged; mutate B, A unchanged.

9. **SP-X family manifest**: Numeric ranges replaced with semantic family names.

10. **Gateway settings configured**: Child subprocess now sets `saveCardEnabled`, `apiKey`, etc.

### Honest Counts

| Category | PASS | FAIL |
|----------|------|------|
| semantic_runtime | 527 | 0 |
| helper_unit_runtime | 710 | 0 |
| static_source | 205 | 0 |
| harness_self_test | 134 | 0 |
| lint_tooling | 10 | 0 |
| **Total PHP** | **1586** | **0** |

| Category | PASS | FAIL |
|----------|------|------|
| runtime | 80 | 0 |
| static | 15 | 0 |
| harness | 42 | 0 |
| **Total Blocks** | **137** | **0** |

### Semantic Runtime Gate

- Mandatory floor: 560
- Current: 527
- Status: **BELOW FLOOR**

The honest recount after reclassification shows 527 semantic_runtime assertions. The directive's new scenarios (SP-SAVE-CARD, SP-SELECTED-CARD, SP-CARD-MISMATCH) could not be implemented because the production code's save_card/selected-card paths require specific setup that the child subprocess cannot fully replicate without production code changes.

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

### File Hashes

| File | Bytes | SHA256 |
|------|-------|--------|
| tests/harness/phase-9g-h12-php-harness.php | (see git) | (see git) |
| tests/harness/phase-9g-h12-blocks-harness.js | (see git) | (see git) |
| tests/harness/store_api_child.php | (see git) | (see git) |
| tests/harness/_bootstrap.php | (see git) | (see git) |

### STOP. DO NOT MERGE.

Awaiting reviewer verification.
