# Contribution Policy

Thank you for helping improve the reliability of UPayments on WooCommerce.

## Current contribution model

This fork is maintained under the Simplix Innovations engineering program. Public bug reports, compatibility reports, reproduction cases, and technical evidence are welcome once GitHub Issues are enabled.

External code pull requests are **not accepted by default**. This keeps release authorship, payment-risk ownership, and long-term maintenance responsibility inside the Simplix Innovations maintenance process. A code contribution should only be opened when explicitly requested by a maintainer.

## Before reporting a defect

Prepare a minimal reproduction and record:

- WordPress version;
- WooCommerce version;
- PHP version;
- plugin version/commit;
- Classic or Block Checkout;
- HPOS status;
- WPML/multilingual status;
- active theme;
- relevant payment feature (standard payment, saved card, subscription, multi-merchant, etc.);
- expected behavior;
- actual behavior;
- sanitized logs or stack trace.

Never include secrets or customer/payment data.

## Engineering standards

Requested code changes must be narrow, reviewable, backward-conscious, and supported by a reproducible failure or clearly defined improvement. Payment-flow behavior should not be changed casually.

Every change should document:

1. the problem or requirement;
2. root cause;
3. implementation scope;
4. compatibility impact;
5. validation performed;
6. rollback considerations.
