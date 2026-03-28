# GDPR / DSAR Manager

GDPR / DSAR Manager is a Zen Cart plugin that adds customer-facing privacy request handling for data exports and data erasure/anonymization.

## What It Does

- lets logged-in customers submit GDPR / DSAR requests
- supports personal-data export requests
- supports erasure/anonymization requests
- gives admins a queue to review, approve, reject, and process requests
- tracks privacy-policy versions and customer acceptance
- records audit events for request activity
- generates export ZIP files with time-limited availability

## Main Areas

- storefront DSAR request page for customers
- admin request queue for processing requests
- policy-version management for privacy compliance workflows
- consent and audit logging tables for traceability

## Notes

- export files expire after the configured retention window and are removed automatically during normal DSAR page activity
- the plugin currently uses the privacy policy flow directly, while `terms` support is retained for future checkout-conditions integration

## Documentation

Detailed technical documentation is available in [docs/how-it-works.md](docs/how-it-works.md).
