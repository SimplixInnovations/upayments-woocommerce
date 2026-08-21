## Residual Correction #21 — Honest Reclassification, Unconditional PASS Removal, Blocks Hardening

**Parent**: a6b1854d1b6607599b903b7fdcf3a2179c826679
**Head**: 2ca148a
**Branch**: feat/canonical-customer-token-identity

### Changes

1. **Assertion taxonomy corrected**: Reclassified PE/OW/WL/MM sections from `helper_unit_runtime` to `semantic_runtime` — these exercise real `process_payment()` and observe actual production workflow outcomes (result, provider counters, mutation counts).

2. **Unconditional PASSes removed**: Removed 9 `static_assert(true === true, ...)` placeholders across XSI/XREG/XSEC/XPROV/XHIST/XCLK/XDB/XLIM/XHAZ. These were documentation-only items that incremented PASS without testing anything. Retained as comments only.

3. **Blocks provider-card token validation fixed**: Changed `is_scalar($card['token'])` to `is_string($card['token'])` and removed `(string)` cast for security identifiers. Number/brand display fields retain `is_scalar()` normalization.

4. **PR-numbered method renamed**: `clear_stale_pr16_attempt_metadata()` → `clear_stale_attempt_metadata()` — no PR numbers in runtime API names.

5. **normalize_store_api_route docblock fixed**: `@return string|null` → `@return string` to match actual behavior (returns empty string for invalid input).

6. **Assertion guard cleaned**: Removed contradictory XREG exclusion pattern. Documentation-only prefixes (XART/XHAZ/XDB/XLIM/XCFG/XMETA/XEND) are now cleanly blocked from semantic_runtime.

7. **Blocks independence test fixed**: Removed monkey-patched `renderComponent` seam in B-INDEP3 — now uses props to identify test instances.

8. **Vacuous assertion removed**: H-ST-27b removed (two equal primitive values cannot demonstrate distinct storage cells).

9. **CHANGELOG updated**: Added truthful #21 entry with final counts.

### Final Counts

| Category | PASS | FAIL |
|----------|------|------|
| semantic_runtime | 597 | 0 |
| helper_unit_runtime | 640 | 0 |
| static_source | 46 | 0 |
| harness_self_test | 134 | 0 |
| lint_tooling | 10 | 0 |
| **Total PHP** | **1427** | **0** |

| Category | PASS | FAIL |
|----------|------|------|
| runtime | 80 | 0 |
| static | 15 | 0 |
| harness | 41 | 0 |
| **Total Blocks** | **136** | **0** |

### Semantic Runtime Gate

- Mandatory floor: 560
- Current: 597
- Status: **PASS** ✓
- Target: ≥600 (not yet attained)

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

### Artifact Package

| File | Bytes | SHA256 |
|------|-------|--------|
| tests/harness/phase-9g-h12-php-harness.php | (see git) | (see git) |
| tests/harness/phase-9g-h12-blocks-harness.js | (see git) | (see git) |
| tests/harness/store_api_child.php | (see git) | (see git) |
| tests/harness/_bootstrap.php | (see git) | (see git) |

### Frozen Subscription Files

- Scheduler.php: 5251866d4df2d1326e7c09f0c8ec1d146c0bb325
- CycleClaim.php: c34d83e2d77cc65024fe663e4c378cecb2b17347

### STOP. DO NOT MERGE.

Awaiting reviewer verification.
