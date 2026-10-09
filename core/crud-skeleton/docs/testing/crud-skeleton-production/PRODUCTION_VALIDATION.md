# Production Validation Plan

## Pre Release

Run and record the result for the release commit:

- CI is green: PHPUnit on PostgreSQL, 90%+ `src/` line coverage, PHPStan, and
  Rector type-rule dry-run. Review any PHPUnit skips, warnings, notices, or
  deprecations reported by the run.
- Run relevant HTTP smoke scripts from `scripts/tests/` against a disposable
  environment. Include `store-smoke.sh` and `inventory-smoke.sh` when their
  modules are enabled or changed.
- Apply all migrations to a fresh target-database instance. For any migration
  that transforms or removes data, rehearse it with a representative sanitized
  data copy and verify backup plus restore.
- Review the diff against `BUSINESS_INVARIANTS.md` and `TEST_MATRIX.md`; make
  sure changed critical paths include success, failure, and authorization or
  idempotency evidence as applicable.
- Validate release configuration without exposing secrets: JWT keys, app
  secrets, database and Redis DSNs, mailer, storage driver, payment/WeChat
  callback endpoints, worker transport, scheduler interval, and
  `INVENTORY_ENABLED`.
- For an external-provider change, complete sandbox/staging certification using
  non-production credentials.


## Deployment

Before serving traffic:

- Confirm the intended image/commit and migration version are deployed.
- Confirm the application is healthy and can connect to the database, Redis,
  and configured message transport.
- Confirm worker and scheduler are running exactly as expected; inspect outbox
  backlog before and after rollout.
- Exercise an authenticated health-level API journey with a disposable account.
- Confirm callback routes are reachable only through their intended public
  boundary and signature validation remains enabled.
- Keep a rollback decision point before any irreversible migration or provider
  configuration activation. Prefer a forward-compatible schema rollout followed
  by a separately reversible application rollout.


## Post Deployment

Monitor the release window and compare it with the pre-release baseline:

- HTTP error rate, authentication failures, and p95/p99 latency.
- Worker failures, retry volume, dead-letter messages, and unpublished outbox
  age.
- Payment callback failures, duplicate-idempotency rejections, wallet balance
  audit discrepancies, and inventory reservation/ledger discrepancies when
  inventory is enabled.
- Database errors, migration warnings, storage/provider errors, and unexpected
  authorization denials.

Escalate immediately when money is duplicated/lost, authorization may be
broadened, an outbox backlog grows without draining, or a workflow enters an
impossible state. Contain traffic first, preserve relevant event/request IDs,
then use a documented compensating operation rather than editing business data
directly.
