# CRUD Skeleton Production Validation

This directory is the test-quality contract for changes to CRUD Skeleton. It is
intended to make production confidence repeatable as the codebase and team grow;
it is not a description of test counts or a substitute for a release decision.

## Read First

- [Test strategy](TEST_STRATEGY.md): layers, environments, required checks, and change rules.
- [Business invariants](BUSINESS_INVARIANTS.md): behaviours that may not regress.
- [Test matrix](TEST_MATRIX.md): risks, owning tests, and required validation.
- [Architecture mapping](ARCHITECTURE_TEST_MAPPING.md): module-level test responsibilities.
- [Failure modes](FAILURE_MODES.md): production failure scenarios and prevention tests.
- [Production validation](PRODUCTION_VALIDATION.md): release and post-release checklist.
- [AI development process](AI_DEVELOPMENT_PROCESS.md): review gate for assisted changes.

## Status Of The Baseline

- CI runs the PHPUnit suite on PHP 8.4 and PostgreSQL 16, PHPStan, and Rector
  type-rule dry-run. New skipped tests, warnings, notices, and deprecations
  must be reviewed rather than silently accepted.
- CI enforces at least 90% line coverage for `src/`. Coverage is a regression
  signal, not evidence that a business path is correct.
- Local tests default to an isolated SQLite database. CI explicitly supplies a
  PostgreSQL URL, so PostgreSQL is the release database compatibility gate.
- HTTP smoke scripts exist under `scripts/tests/`, but are not currently run by
  CI. They remain a release check until automated in an environment with the
  required services.

Every changed observable behaviour needs an appropriate automated test in the
same change. Every escaped production defect needs a focused regression test
before its fix is considered complete.
