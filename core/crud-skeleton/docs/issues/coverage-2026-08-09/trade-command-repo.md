# Trade module — outbox command, repositories & entity: coverage to ~100% & bug hunt (2026-08-09)

Scope: `src/Trade/Command/PublishOutboxCommand.php`, `src/Trade/Repository/TradeOutboxMessageRepository.php`, `src/Trade/Repository/SpecificationRepository.php`, `src/Trade/Repository/ProductRepository.php`, `src/Trade/Entity/TradeOutboxMessage.php`.
Goal: raise line coverage to ~100% and document bugs. **No code under `src/` was modified.**

## Test files added

| File | Tests | Purpose |
| --- | --- | --- |
| `tests/Trade/Command/PublishOutboxCommandTest.php` | 8 | Unit tests (mocked `TradeOutboxMessageRepository` / `EntityManagerInterface` / `MessageBusInterface`) covering every branch of `app:trade:outbox:publish`: zero messages, created/cancelled topics with full envelope assertion, claim-failed, `getId() === null`, unsupported-topic defer, dispatch-exception defer, and a mixed multi-message run. |
| `tests/Trade/Entity/TradeOutboxMessageTest.php` | 5 | Pure-PHP entity tests: constructor fields, `availableAt == occurredAt`, `getId()` (injected via `ReflectionProperty`, no `setAccessible`), `getPublishedAt()`, `markPublished()` (incl. idempotence). |
| `tests/Trade/Repository/TradeOutboxMessageRepositoryTest.php` | 11 + 2 skipped | Real-DB integration: `findUnpublished` filtering/ordering/limit; `claim` success + double-claim guard + published/future/unknown-id; `defer` attempts increment + `lastError` + `availableAt` + published no-op; plus two real-DB runs of the actual `PublishOutboxCommand` (success path and dispatch-failure defer path) built with the real repository/EM and a stubbed bus. |
| `tests/Trade/Repository/SpecificationRepositoryTest.php` | 4 | Real-DB: `findById` (found/null) and `findByIdForUpdate` (found/null, inside a transaction). `findByProduct`/`findActiveByProduct` were already covered by `tests/Trade/Integration/TradeRepoFullTest.php`. |
| `tests/Trade/Repository/ProductRepositoryTest.php` | 2 | Real-DB: `findById` (found/null). `findNotDeleted`/`findActive` already covered by existing tests. |

Total added: 30 tests (28 passing + 2 documented skips). Existing `tests/Trade/**` untouched.

Note: `App\Trade\Message\TradeOrderCreatedMessage` and `App\Trade\Message\TradeOrderCancelledMessage` both exist as `final readonly class` taking a single `array $envelope`; the command's constructed envelopes are a match.

## Coverage results

Measured with PHP 8.5 + Xdebug (`phpunit <files> --coverage-html`).

| File | Before (`var/uncovered-map.txt`) | After |
| --- | --- | --- |
| `Trade/Command/PublishOutboxCommand.php` | 0% (25,30–59,62,63,65) | **100%** (31/31) |
| `Trade/Repository/TradeOutboxMessageRepository.php` | 28.12% (34–60) | **100%** (32/32) |
| `Trade/Repository/SpecificationRepository.php` | 58.82% (24,52–57) | **100%** (combined: new tests cover `findById`/`findByIdForUpdate`; existing `TradeRepoFullTest` covers `findByProduct`/`findActiveByProduct`) |
| `Trade/Repository/ProductRepository.php` | 75% (23) | **100%** (combined: new tests cover `findById`; existing tests cover `findNotDeleted`/`findActive`) |
| `Trade/Entity/TradeOutboxMessage.php` | 60% (65,67,68,71,72,73) | **100%** (15/15) |

New coverage notes:
- Command: unsupported-topic defer, dispatch-failure defer, claim-failed skip, null-id skip, both supported topics, output formatting and `Command::SUCCESS`.
- Repository: `claim` true/false semantics (double-claim, already-published, future `availableAt`, unknown id), `defer` attempts/`lastError`/`availableAt` and its no-op when published.
- Entity: all getters plus `markPublished()`.
- A test gotcha worth recording: `MessageBusInterface::dispatch()` returns `Envelope`, which has a **private constructor** — PHPUnit mocks cannot auto-generate a return value, so every `dispatch()` stub must return `Envelope::wrap($msg)` (otherwise the mock throws, which the command's `catch (\Throwable)` misinterprets as a dispatch failure).

Full suite for these files: 32 tests, 143 assertions, 2 skipped, exit 0. Integration files bootstrap the shared `var/test.db` schema; transient `no such table`/`database is locked` failures from concurrent agents were observed once and cleared on rerun (per the shared-DB convention).

## Bugs found

### Bug #1 — Poison messages are retried forever: no max-attempts cap, no dead-lettering

- **File/line:** `src/Trade/Command/PublishOutboxCommand.php:36-39` (unsupported topic) and `:58-60` (dispatch failure); `src/Trade/Repository/TradeOutboxMessageRepository.php:47-61` (`defer()`).
- **Description:** `defer()` only guards on `publishedAt IS NULL`. It always increments `attempts` and re-schedules `availableAt = +5 minutes`. There is no attempt ceiling and no dead-letter/quarantine state, so an unsupported topic or a permanently failing message is re-claimed and re-deferred every 5 minutes forever.
- **Impact:** `attempts` grows unboundedly on the row, the message never leaves the unpublished set, and every run performs a wasted claim+dispatch attempt. A single misconfigured/typo'd topic permanently pollutes the outbox table and the publish loop.
- **Reproduction (verified):** insert a row with `topic = 'trade.custom.topic.v1'`; run `app:trade:outbox:publish` repeatedly — each run increments `attempts` (`0 → 1 → 2 → …`), sets `last_error`, and the row is never published or removed. Same for any topic whose dispatch always throws.
- **Proposed fix:** cap attempts (e.g. only `defer()` while `attempts < N`, then move the row to a dead-letter/quarantine state or delete it), and/or add a `status`/`dead_lettered` column so poison messages are skipped by `findUnpublished()`.

### Bug #2 — Successful publish does not clear previous failure metadata

- **File/line:** `src/Trade/Command/PublishOutboxCommand.php:56-57` (`markPublished()` + `flush()`); `src/Trade/Repository/TradeOutboxMessageRepository.php:47-61` (`defer()`); `src/Trade/Entity/TradeOutboxMessage.php:73` (`markPublished()`).
- **Description:** `claim()`/`defer()` are DQL bulk updates that bypass the unit of work; `markPublished()` only sets `publishedAt` in memory. If a message fails once (defer → `attempts=1`, `lastError` set) and is later published successfully on a retry, the row is written with `published_at` set but **`attempts` and `last_error` remain from the earlier failure** — nothing ever resets them.
- **Impact:** successfully delivered events carry stale, misleading failure counters/errors; any consumer of `attempts`/`last_error` (monitoring, dashboards, reconciliation) reports false failures for delivered events.
- **Reproduction (verified reasoning, covered by skipped test):** create a message, `defer()` it (attempts=1, lastError='real bus down'), then run the command with a working bus — the published row still has `attempts=1` and `last_error='real bus down'`.
- **Proposed fix:** on successful publish, clear the failure state, e.g. a repository `markPublished()` that sets `published_at = now`, `attempts = 0`, `last_error = NULL` (or have the entity `markPublished()` reset them and keep the current flush-based flow).

### Bug #3 (latent) — Envelope `aggregateType` is hardcoded instead of read from the entity

- **File/line:** `src/Trade/Command/PublishOutboxCommand.php:45`; `src/Trade/Entity/TradeOutboxMessage.php` (stores `aggregate_type`, but exposes **no `getAggregateType()`** getter).
- **Description:** The command emits `'aggregateType' => 'trade_order'` literally rather than `$message->getAggregateType()`. The entity already stores the value (constructor arg `$aggregateType`).
- **Impact:** No current runtime impact — every Trade outbox row is recorded with `aggregate_type = 'trade_order'` (`OrderService::createOrder` and `OrderWorkflowListener`). It is a latent consistency gap: the moment any producer records a different aggregate type, the published envelope will still claim `trade_order`, corrupting downstream envelope semantics.
- **Reproduction:** record a row with any `aggregateType` other than `'trade_order'`; observe the dispatched `TradeOrderCreatedMessage`/`TradeOrderCancelledMessage` envelope still carrying `'aggregateType' => 'trade_order'`.
- **Proposed fix:** add `getAggregateType(): string` to `TradeOutboxMessage` and use it at `PublishOutboxCommand.php:45` (mirroring the richer `StoreOutboxMessage` API which already exposes `getAggregateType()` etc.).

## Observations (not bugs)

- After a successful publish, `available_at` on the row is left at `claimTime + 1 minute` (never reset to `published_at`). Harmless — `findUnpublished()` filters `published_at IS NULL` first — but slightly misleading if anyone reads `available_at` on published rows.
- `claim()` then `dispatch()` are deliberately non-atomic (two queries). A crash between them retries the message after 1 minute; a concurrent worker cannot double-publish because `claim()` also requires `available_at <= now`, which the first claim advances. Verified by `testClaimReturnsTrueThenPreventsDoubleClaim`.
- `TradeOutboxMessage` is missing several getters that its Store twin has (`getAggregateType`, `getAvailableAt`, `getAttempts`, `getLastError`, `isPublished`) — API asymmetry only; nothing in `src/Trade` calls them today.

## Skipped tests

- `TradeOutboxMessageRepositoryTest::testUnsupportedTopicIsEventuallyQuarantined` — correct-behavior test asserting an unsupported topic stops being retried / is dead-lettered. Fails against the current implementation (Bug #1: unbounded retry), so `markTestSkipped` to keep the suite green and document the expected behaviour/fix.
- `TradeOutboxMessageRepositoryTest::testSuccessfulPublishClearsPreviousFailureMetadata` — correct-behavior test asserting a row published after a prior failure has `attempts = 0` and `last_error = null`. Fails against the current implementation (Bug #2), so `markTestSkipped` to keep the suite green and document the expected behaviour/fix.
