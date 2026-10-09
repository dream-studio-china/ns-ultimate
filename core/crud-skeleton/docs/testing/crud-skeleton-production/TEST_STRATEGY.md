# Test Strategy

## Objective And Scope

The objective is safe iteration of a modular Symfony API that holds identity,
money, orders, inventory, and asynchronous state. Tests protect observable
contracts and domain invariants, rather than implementation details or a target
test count.

This strategy covers code under `src/`, Doctrine schema behaviour, HTTP APIs,
Messenger handlers, and release smoke checks. Real external providers (SMS,
WeChat, Qiniu, payment acquirers) are represented by deterministic adapters in
automated tests; their credentials and provider-side behaviour require a
separate staging certification.

## Test Environments

| Environment | Database | Purpose | Constraint |
|---|---|---|---|
| Local PHPUnit | SQLite (`var/test.db`) | fast development feedback | do not treat SQLite locking or SQL dialect behaviour as production proof |
| Parallel local PHPUnit | per-process SQLite | fast isolated suite runs | external shared databases are unsupported for parallel schema setup |
| CI PHPUnit | PostgreSQL 16 | merge gate and database portability | required for every pull request |
| Staging/release | deployed services | configuration, real HTTP, workers, and operational checks | never use production credentials or customer data |

MySQL is the deployment database declared by Docker Compose. Any migration or
query using database-specific SQL must be checked on MySQL-compatible staging
before release; PostgreSQL CI does not prove MySQL compatibility.

## Test Layers

| Layer | Purpose | Examples | Rule |
|---|---|---|---|
| Unit | deterministic domain decisions and error cases | DSL, pricing, token, workflow, entity methods | no kernel or real database unless the component itself is persistence-bound |
| Integration | Doctrine mapping, transactions, repositories, service wiring, event/handler effects | invoice adjustments, wallet transfer, outbox/inbox | assert persisted state and rollback/idempotency effects |
| HTTP/API | routing, authentication, authorization, serialization, validation, and status codes | app/manage boundaries, locale, OpenAPI | assert the public response envelope and data isolation |
| System smoke | deployed service wiring and critical journeys | auth, catalog, wallet, order/payment, Store/Inventory flow | run against a disposable environment, not PHPUnit mocks |
| Manual provider certification | third-party contract and callback verification | WeChat Pay, SMS, Qiniu | document provider sandbox result and retain no secrets in tests |

One behaviour should normally have one primary layer. Add a higher-layer test
when an integration boundary, permission boundary, or transport contract is at
risk. Do not add controller tests merely to raise line coverage.

## Required Change Rules

| Change | Minimum evidence required |
|---|---|
| Pure domain rule or bug fix | focused unit test, including the prior failing case |
| Service, repository, transaction, or Doctrine mapping | integration test on persisted state; rollback test when multiple writes occur |
| API route, payload, authorization, locale, or serializer | HTTP/API success and denial/validation test |
| State transition or money movement | valid and invalid transition tests; invariant and duplicate-request coverage |
| Message, outbox, inbox, retry, or consumer | first delivery, duplicate delivery, and out-of-order/failure handling test |
| Migration or database-specific query | fresh-schema migration validation and target-database staging validation |
| External provider adapter | deterministic contract test plus staging/sandbox certification before release |

When a change touches more than one row, aggregate, or module, test the whole
business result rather than only individual method calls.

## Mandatory Commands

Use the repository PHP 8.4+ runtime. On local machines where `php` is 7.4,
the project context currently documents `/opt/homebrew/opt/php@8.5/bin/php`.

```bash
/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit
composer phpstan
composer rector:types:check
```

`phpunit.dist.xml` sets the test-process memory limit to 512M because complete
OpenAPI generation is exercised by the integration suite.

For local coverage inspection:

```bash
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --coverage-html var/coverage
```

For the release smoke checks, start the disposable Docker environment, apply
migrations, create the required test users/data, then run only the scripts that
match the enabled modules:

```bash
scripts/tests/api-smoke.sh
scripts/tests/store-smoke.sh
scripts/tests/inventory-smoke.sh
```

The commands may create test data and must not target a shared or production
environment.

## Quality Gates

Merge gate:

- PHPUnit succeeds on CI PostgreSQL. New skipped tests, warnings, notices, or
  deprecations require an explicit reason and follow-up instead of silently
  expanding the baseline.
- `composer phpstan` and `composer rector:types:check` succeed.
- Line coverage for `src/` is at least 90%; an increase in excluded code or a
  lowered threshold requires explicit maintainer approval.
- The changed risk is represented in the test matrix and its required evidence
  is present.

Release gate:

- The merge gate is green for the release commit.
- Relevant smoke scripts and migration checks succeed on a disposable
  deployment-like environment.
- Worker, scheduler, callback URLs, secrets, and observability are checked as
  described in `PRODUCTION_VALIDATION.md`.
- Critical changes have a rollback or compensating-operation plan.

## Maintenance Rules

- Keep tests deterministic: inject clocks, UUIDs, gateways, and provider
  responses where variability would hide a failure.
- Each test owns its data; do not depend on execution order or a previous test.
- Use public API assertions for public contracts and persistence assertions for
  transactional contracts. Avoid assertions on private implementation details.
- Prefer table-driven tests for a rule with many equivalent inputs; name tests
  after the protected outcome.
- Update this strategy, the matrix, and invariants whenever a module gains a
  new critical path or changes an existing contract.
