## Summary

Describe the change and the user/merchant impact.

## Root cause / requirement

Explain the defect, compatibility requirement, or maintenance need.

## Scope

List the files/components changed and explicitly note what is intentionally out of scope.

## Payment-flow risk

- [ ] No payment-flow behavior changed
- [ ] Charge creation affected
- [ ] Redirect/return/cancel handling affected
- [ ] Webhook/callback handling affected
- [ ] Order status/metadata affected
- [ ] Saved-card/tokenization affected
- [ ] Subscription/auto-deduction affected
- [ ] Multi-merchant affected

Describe safeguards and rollback considerations for any checked payment-flow item.

## Compatibility validation

Record the tested environment where applicable:

- WordPress:
- WooCommerce:
- PHP:
- Checkout: Classic / Blocks
- HPOS: On / Off
- WPML/multilingual:
- Theme:

## Validation performed

Describe reproducible tests, logs, static checks, and regression checks.

## Security and privacy

- [ ] No secrets, API keys, tokens, card data, or customer PII are included
- [ ] Inputs are validated/sanitized where relevant
- [ ] Output is escaped where relevant
- [ ] Logging changes avoid sensitive data

## Documentation

- [ ] Changelog updated when required
- [ ] Compatibility matrix updated when required
- [ ] Merchant/developer documentation updated when required
