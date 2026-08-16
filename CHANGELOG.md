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

- Harden checkout request validation: strict payment-source allowlist, consistent Classic/Blocks subscription plan and interval validation, deterministic save-card input handling, fail-closed charge-response structure and redirect URL validation, generic customer-facing provider failure messages, subscription-context enforcement, guest-subscription rejection, and fail-closed payment-method availability state.
- Verify UPayments callback payment state against the authenticated Get Payment Status API before allowing WooCommerce paid-order transitions.
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
