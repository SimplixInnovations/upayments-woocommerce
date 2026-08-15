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

- Prevent a WPML String Translation fatal error by ensuring the UPayments gettext text domain is always a valid string during gateway construction.
- Correct the plugin `Text Domain` header from a URL to the stable `upayments` domain.
- Prevent UPayments frontend CSS from overriding WooCommerce My Account navigation/content layout and generic responsive table styling.
- Harden subscription account actions with consistent nonce verification, action allowlisting, and sanitized request handling.
- Restore TLS certificate verification for authenticated UPayments API requests.

### Planned compatibility work

- Scope customer/account CSS to plugin-owned components.
- Audit PHP 8.x compatibility.
- Validate Checkout Blocks and HPOS behavior.
- Review webhook, callback, redirect, and status-verification flows.
- Introduce automated quality and release checks.

## Upstream baseline

The fork currently tracks upstream plugin version **3.1.1** as declared by the main plugin file. Upstream historical release notes remain attributable to the upstream project.
