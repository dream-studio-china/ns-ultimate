# HuifuFin Store payment and refund foundation

## Goal

Implement the approved HuifuFin plan: encrypted provider and merchant profiles,
shareable per-Store bindings, an online Huifu payment gateway, signed
notifications, refund confirmation, reconciliation, and POS receipt intake
without the legacy automatic wallet deposit.

## Implemented

- Added the auto-discovered `business/backend/HuifuFin` module with provider,
  merchant, Store grant, Store setting, payment attempt, refund, webhook, POS
  device, and POS receipt entities.
- Added `HUIFU_CONFIG_KEY` encryption with versioned credential history so an
  in-flight attempt keeps using the credentials recorded when it started.
- Added the `huifu` gateway, Store-scoped payment initiation, attempt-specific
  signed notifications, payment and refund queries, and a reconciliation command.
- Added Store-scoped refund requests that submit first and only apply accounting
  to Core after provider confirmation. Processing refunds return HTTP 202.
- Added POS notification verification, receipt deduplication, linked-order
  handling, and an unclaimed fallback with no wallet top-up.
- Added Admin UI collections, routes, translations, scoped authorization seeding,
  environment placeholders, and the module operating guide.
- Removed the misleading generic Huifu placeholder from the legacy payment
  channel schema.

## Validation

- Composed backend PHPUnit: 315 tests, 6,607 assertions, 1 skipped.
- Business PHPStan: no errors.
- Admin type check: passed.
- Admin composition and project workflow tests: 88 passed.
- Composed admin build: passed.
- Documentation link check: 61 documents, 0 broken local targets.
- `git diff --check`: passed.

The Huifu tests use an isolated SQLite schema and a fake provider client. They
cover encrypted configuration and rotation, multi-Store sharing and denial,
Store payment attempt creation, signed notification idempotency, confirmed and
pending refund behavior, POS deduplication, and unknown-terminal rejection.

## Review hardening

- Payment initiation serializes on the invoice, records a timeout as `unknown`
  outside the rolled-back Core transaction, and blocks a replacement attempt
  until reconciliation resolves it. Failures before a provider request are
  recorded as `failed` instead of blocking the invoice. Stale `created` attempts
  enter reconciliation after five minutes.
- A confirmed refund is applied to Core exactly once under row locks. Refund
  reservation is transactional, and confirmed refunds that were not applied are
  retried by reconciliation.
- Corrected notifications and reconciliation results lock and reload the attempt
  before applying state, so a confirmed payment cannot be downgraded by a
  concurrent query or notification.
- Store payment and refund workflows pass an internal HMAC proof to the gateway.
  The generic Core payment and refund routes cannot submit an attempt or consume
  a confirmed refund without it.
- POS notifications must match the notified merchant. Synchronous responses
  must be signed; unsigned responses are treated as unknown.
- Provider environment and identity fields are immutable after profile creation,
  and a confirmed payment cannot be downgraded by a later notification.

Additional validation for these changes:

- Composed backend PHPUnit: 318 tests, 6,653 assertions, 1 skipped.
- Business PHPStan: no errors.
- Documentation link check: 62 documents, 0 broken local targets.

## Risks and follow-up

- No live Huifu sandbox or production request was made. The signing
  serialization, response codes, refund/query endpoints, and notification
  protocol must be confirmed against the contracted product.
- The generic Core invoice pay and refund routes deliberately reject Huifu
  actions that lack a HuifuFin attempt or confirmed refund record. Clients must
  use the Store-scoped HuifuFin endpoints.
- POS-linked invoices can be paid but are not refundable through this release.
- Automatic close-order behavior, quick-pay refunds, account onboarding,
  transfers, and withdrawals remain future work.
- The reconciliation command needs an actual scheduler in the deployment.
