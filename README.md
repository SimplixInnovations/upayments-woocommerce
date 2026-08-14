<p align="center">
  <img src="assets/images/logo.png" alt="UPayments" width="120">
</p>

<h1 align="center">UPayments for WooCommerce</h1>

<p align="center">
  <strong>Enterprise-maintained WooCommerce payment gateway compatibility fork</strong><br>
  maintained by <a href="https://simplixi.com">Simplix Innovations</a>
</p>

<p align="center">
  <a href="https://simplixi.com"><img alt="Maintained by Simplix Innovations" src="https://img.shields.io/badge/Maintained%20by-Simplix%20Innovations-111111?style=flat-square"></a>
  <a href="https://woocommerce.com/development-services/simplix-innovations-woocommerce-full-service-agency/232995338/"><img alt="WooCommerce — Woo Agency Partner" src="https://img.shields.io/badge/WooCommerce-Woo%20Agency%20Partner-7f54b3?style=flat-square"></a>
  <a href="https://github.com/upaymentskwt/woocommerce"><img alt="Upstream UPayments WooCommerce" src="https://img.shields.io/badge/Upstream-UPayments-4b5563?style=flat-square"></a>
  <img alt="Maintenance status" src="https://img.shields.io/badge/Status-Engineering%20hardening-f59e0b?style=flat-square">
</p>

<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/simplix-innovations-logo-white.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/simplix-innovations-logo-black.svg">
    <img src=".github/assets/simplix-innovations-logo-black.svg" alt="Simplix Innovations" width="280">
  </picture>
</p>

> [!IMPORTANT]
> This repository is an independently maintained fork of the UPayments WooCommerce integration. It is not presented as the official UPayments distribution and does not imply endorsement by UPayments. UPayments and related trademarks remain the property of their respective owners.

## Overview

**UPayments for WooCommerce** is the Simplix Innovations maintenance track for the UPayments payment gateway integration. The project is being hardened for production WooCommerce environments with an emphasis on payment integrity, compatibility, maintainability, multilingual commerce, and controlled releases.

The fork exists because payment extensions are business-critical infrastructure. A gateway should not merely load at checkout: it must remain reliable across WooCommerce releases, PHP upgrades, multilingual stacks, modern Checkout Blocks, order-storage changes, themes, callbacks, webhooks, saved-card flows, and production failure conditions.

## Maintenance objectives

The Simplix Innovations engineering track is focused on:

- WordPress and WooCommerce compatibility
- Classic Checkout and Cart/Checkout Blocks interoperability
- High-Performance Order Storage (HPOS) compatibility
- PHP 8.x compatibility and forward-maintenance
- WPML and multilingual WooCommerce compatibility
- theme-safe, component-scoped frontend assets
- webhook, callback, redirect, and payment-status reliability
- saved-card/tokenization and multi-merchant flow review
- security, validation, escaping, and sensitive-data handling
- predictable logging and merchant-safe diagnostics
- regression testing, release discipline, and documented compatibility
- reducing global side effects and improving code maintainability

## Upstream capability baseline

UPayments' current developer documentation describes the WooCommerce integration as supporting KNET, cards, Apple Pay, Google Pay, and Samsung Pay. The upstream plugin also documents test mode, saved-card/tokenization functionality, multi-merchant configuration, and support for standard-product Checkout Blocks.

UPayments additionally documents `notificationUrl` webhooks as mandatory for charge requests and recommends server-to-server payment notifications for reliable payment-state handling. Transaction status can be verified later using UPayments' payment-status API.

These are **upstream capability claims**, not blanket Simplix compatibility certifications. Simplix Innovations publishes verified compatibility only after a feature has passed the project's validation process.

- UPayments WooCommerce documentation: https://developers.upayments.com/reference/woocommerce
- UPayments Block Checkout documentation: https://developers.upayments.com/reference/woocommerce-core-block-checkout-support
- UPayments webhook documentation: https://developers.upayments.com/reference/webhook
- UPayments payment-status documentation: https://developers.upayments.com/reference/checkpaymentstatus

## Verified compatibility policy

This fork does not use untested compatibility badges. Each major integration area is classified as one of:

- **Verified** — tested by Simplix Innovations against a documented environment
- **Known issue** — a reproducible defect exists and is tracked for remediation
- **Upstream claim** — documented by UPayments but not yet independently validated in this fork
- **Pending validation** — audit or regression testing is still required

See **[Compatibility Matrix](docs/COMPATIBILITY.md)** for the current status.

WooCommerce recommends that extension developers test and explicitly declare compatibility with current WordPress/WooCommerce versions and relevant features such as Cart/Checkout Blocks and HPOS. This fork follows that model rather than assuming compatibility from the absence of errors.

## Known upstream defects under remediation

The maintenance track began with production failures that were reproduced and traced to upstream implementation details, including:

1. **WPML String Translation fatal error** caused by the gateway passing a `null` gettext text domain.
2. **WooCommerce My Account layout conflicts** caused by generic plugin CSS overriding theme/account structural widths.
3. **Stale compatibility metadata** that does not reflect the current upstream plugin version or modern WooCommerce requirements.
4. **Large bootstrap/gateway class surface area** that increases coupling and makes compatibility changes riskier than necessary.

Fixes are developed as focused changes and validated before release.

## Payment integrity principles

For payment-critical code, this project follows several non-negotiable rules:

- do not trust browser redirects as the sole source of payment truth;
- prefer server-to-server webhook data and explicit status verification;
- never expose API keys, bearer tokens, cardholder data, or customer PII in public reports;
- make order-state transitions deterministic and idempotent wherever possible;
- avoid unrelated global checkout/account mutations;
- load assets only where the integration requires them;
- treat compatibility declarations as claims that require evidence.

## Support boundaries

### Plugin engineering and WooCommerce integration

For implementation work, compatibility remediation, production debugging, custom WooCommerce integrations, multilingual commerce, performance, or long-term maintenance, contact **Simplix Innovations**:

- Website: https://simplixi.com
- Email: info@simplixi.com
- GitHub: https://github.com/SimplixInnovations

### UPayments merchant services

Merchant onboarding, account approval, settlement, acquiring, commercial terms, production API access, and UPayments platform/account matters remain the responsibility of UPayments. Use the official UPayments website and developer portal for those services.

## Professional WooCommerce engineering

Simplix Innovations is publicly listed by WooCommerce as a **Woo Agency Partner / Full Service Agency**, serving clients worldwide from the United Arab Emirates. See the official partner profile:

- WooCommerce partner profile — https://woocommerce.com/development-services/simplix-innovations-woocommerce-full-service-agency/232995338/
- Simplix Innovations website — https://simplixi.com

The agency provides **professional WooCommerce engineering** across the capabilities documented on that partner profile, including:

- WooCommerce development and platform engineering
- payment gateway integrations
- multilingual and multi-currency commerce (including WPML)
- third-party and bespoke integrations
- performance optimization and reliability engineering
- ongoing support and long-term maintenance

This repository also serves as a public engineering record of how Simplix Innovations approaches business-critical WooCommerce integrations: reproducible diagnosis, narrow fixes, compatibility evidence, documented risk, and maintainable releases.

> Listing by WooCommerce as a Woo Agency Partner reflects the agency's broader WooCommerce practice. It is not an endorsement of any specific fork, gateway, or commercial arrangement, and is independent of UPayments.

## Project documentation

- [Compatibility Matrix](docs/COMPATIBILITY.md)
- [Engineering Roadmap](docs/ENGINEERING-ROADMAP.md)
- [Upstream Relationship](UPSTREAM.md)
- [Support Policy](SUPPORT.md)
- [Security Policy](SECURITY.md)
- [Contribution Policy](CONTRIBUTING.md)
- [Maintainers](MAINTAINERS.md)
- [Changelog](CHANGELOG.md)

## Development status

The repository is currently in the **engineering hardening** phase. Production behavior is being audited before broad compatibility claims are made.

The first workstreams are:

1. repository foundation and governance;
2. WPML/i18n fatal remediation;
3. frontend asset scoping and My Account compatibility;
4. PHP and WooCommerce compatibility audit;
5. Checkout Blocks and HPOS verification;
6. payment lifecycle and webhook review;
7. automated quality gates and release packaging.

## Reporting bugs

Public issue tracking will be enabled after the issue templates and support policy are merged into `main`. When Issues are enabled, reports must not contain production secrets or sensitive customer/payment data.

For security-sensitive findings, follow [SECURITY.md](SECURITY.md) instead of opening a public issue.

## License

The upstream project declares the plugin under the MIT License. Simplix Innovations modifications in this fork are also provided under the MIT License. See [LICENSE](LICENSE) and [UPSTREAM.md](UPSTREAM.md).

---

<p align="center">
  <strong>Maintained by Simplix Innovations</strong><br>
  WooCommerce engineering · Payment integrations · Multilingual commerce · Production reliability
</p>
