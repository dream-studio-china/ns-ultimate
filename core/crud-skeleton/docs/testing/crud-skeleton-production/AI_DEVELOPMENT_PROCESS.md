# AI Development Process

AI-assisted changes follow the same engineering gates as human-authored changes.
AI output is untrusted until reviewed and verified in the repository.

## Change Flow

1. Identify the affected module, public contract, transaction boundary, and
   invariants before editing.
2. Implement the smallest change that preserves module boundaries and existing
   contracts.
3. Add or update tests using the selection rules in `TEST_STRATEGY.md`.
4. Run the smallest relevant test set during development, then the required
   full quality gates before merge.
5. Review the final diff for security, data integrity, migration safety, and
   documentation drift.
6. Record new critical paths in the matrix/invariants and escaped defects as
   focused regression tests.

## Review Gate

| Area | Reviewer must establish |
|---|---|
| Architecture | dependencies follow module boundaries; no unrelated framework coupling or hidden global state |
| Public contract | API responses, HTTP status, authorization scope, i18n, and OpenAPI impact are deliberate |
| Business logic | each affected invariant has a testable outcome; money uses integer cents; workflow guards remain intact |
| Reliability | transactions, idempotency keys, outbox/inbox semantics, ordering, retries, and compensation are addressed |
| Database | Doctrine mapping and migration are portable to the intended deployment database; destructive changes have a rollout plan |
| Tests | tests assert outcomes at the right layer, include denial/failure cases, remain deterministic, and do not only inflate coverage |
| Operations | configuration, worker/scheduler, external provider, logging, metrics, and rollback implications are covered |

## Human Responsibility

- Own architecture, business decisions, risk acceptance, credentials, and the
  final approval.
- Do not approve a change solely because an AI says tests are sufficient or a
  coverage number increased.
- Require a production-like concurrency and operational review before enabling
  Inventory or introducing a new payment/provider integration.
