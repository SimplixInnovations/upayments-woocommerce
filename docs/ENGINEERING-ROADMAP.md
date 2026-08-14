# Engineering Roadmap

The maintenance program is organized to reduce payment risk while improving the codebase incrementally.

## Phase 1 — Repository foundation

**Status: in progress**

- professional repository identity;
- support and security policies;
- upstream relationship and attribution;
- structured issue/PR workflows;
- compatibility methodology;
- repository hygiene.

## Phase 2 — Critical compatibility fixes

- initialize and normalize the plugin text domain;
- remove WPML fatal conditions;
- scope frontend/account CSS to UPayments-owned UI;
- eliminate production debug noise that is not gated by debug mode;
- correct stale plugin/readme compatibility metadata after validation.

## Phase 3 — Payment lifecycle audit

- charge creation and request validation;
- return/cancel handling;
- webhook processing;
- payment-status verification;
- order matching and idempotency;
- retry/failure behavior;
- refund and transaction metadata review.

## Phase 4 — Modern WooCommerce interoperability

- Checkout Blocks payment-method integration;
- HPOS;
- current WordPress/WooCommerce compatibility;
- PHP 8.x;
- multilingual/WPML;
- theme-safe assets;
- saved cards, subscriptions, booking products, and multi-merchant flows.

## Phase 5 — Architecture and quality

- reduce the monolithic gateway/bootstrap class;
- isolate API, payment, admin, frontend, subscription, and integration responsibilities;
- introduce consistent sanitization, escaping, validation, and exception boundaries;
- add static analysis and coding-standard checks;
- add automated tests around payment-critical behavior.

## Phase 6 — Release engineering

- reproducible plugin packages;
- CI quality gates;
- compatibility matrix updates;
- release checklist;
- semantic versioning policy for the Simplix maintenance track;
- documented upgrade and rollback procedures.
