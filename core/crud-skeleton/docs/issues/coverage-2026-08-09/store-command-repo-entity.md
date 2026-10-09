# Store Command / Repository / Entity — Test Coverage & Bug Report

Date: 2026-08-09
Scope: `app:store:outbox:publish` command, `StoreOutboxMessageRepository`, and the Store outbox/consumed/cancellation entities.
Rule followed: **no files under `src/` were modified** — only test files were added under `tests/`, plus this report.

## Files added

| File | Type | What it covers |
|---|---|---|
| `tests/Store/Command/PublishOutboxCommandTest.php` | unit (mocked repo/EM/bus, `CommandTester`) | All 6 branches of `execute()`: no messages, null id skip, claim-failure skip, unsupported-topic defer, successful dispatch+markPublished for all 4 topics (envelope/type/version/aggregateId/payload asserted), dispatch-throws defer. |
| `tests/Store/Repository/StoreOutboxMessageRepositoryTest.php` | integration (`KernelTestCase` + `DatabaseBootstrapTrait`) | `findUnpublished` filtering/order/limit, `claim` true/false paths (already-claimed, published, not-yet-available, missing id), `defer` attempts/lastError/availableAt accumulation, defer-noop for published, re-availability after `recordAttempt`. |
| `tests/Store/Entity/StoreOutboxMessageLifecycleTest.php` | unit | Previously uncovered `getId()` (line 65) and `getOccurredAt()` (line 72), default `availableAt == occurredAt`, explicit `occurredAt` constructor arg. |
| `tests/Store/Entity/StoreConsumedEventLifecycleTest.php` | unit | Previously uncovered `getId()` (line 44). |
| `tests/Store/Entity/StoreTradeOrderCancellationTest.php` | unit | `getTradeOrderUuid()` (36), `getStoreUuid()` (37), `getCancelledAt()` (38). |

No existing test file was modified; no `src/` file was touched.

## Coverage results

Measured with `tests/Store` suite + the added files (Xdebug, `phpunit.dist.xml` config).

| File | Before | After |
|---|---|---|
| `src/Store/Command/PublishOutboxCommand.php` | 0% (0/31) | **100% (31/31)** |
| `src/Store/Repository/StoreOutboxMessageRepository.php` | 28.12% (9/32) | **100% (32/32)** |
| `src/Store/Entity/StoreOutboxMessage.php` | 90.48% (19/21) | **100% (21/21)** |
| `src/Store/Entity/StoreConsumedEvent.php` | 90.91% (10/11) | **100% (11/11)** |
| `src/Store/Entity/StoreTradeOrderCancellation.php` | 66.67% (4/6) | **100% (6/6)** |

All five target files reach **~100%** line coverage.

## How to run

```bash
cd /Volumes/Nayuki/Development/PHP/crud-skeleton
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Store/Command/PublishOutboxCommandTest.php \
  tests/Store/Repository/StoreOutboxMessageRepositoryTest.php \
  tests/Store/Entity/StoreOutboxMessageLifecycleTest.php \
  tests/Store/Entity/StoreConsumedEventLifecycleTest.php \
  tests/Store/Entity/StoreTradeOrderCancellationTest.php \
  --no-coverage
# => OK (23 tests, 129 assertions)
```

Note: the repository/integration tests share `var/test.db` with the rest of the suite and the CI/staging process. If a run reports `database is locked`, `no such table`, or `Premature end`, wait 10–15 s and rerun (this is the documented shared-DB behavior, not a test defect). While this report was being written, a concurrent coverage run in the same workspace intermittently dropped/recreated the shared schema, producing transient errors in *other* Store test files; the five files above are green in isolation.

## Bugs found

All `src/` bugs below are **documented only** — no fix was applied.

### Bug 1 — `defer()` has no claim-ownership/availability guard (medium, concurrency)
- **File/line:** `src/Store/Repository/StoreOutboxMessageRepository.php:47-61`
- **Description:** the DQL `WHERE` only matches `id` and `publishedAt IS NULL`. It does **not** require `availableAt <= :now`, so it does not verify that the caller still owns the claim window set by `claim()`.
- **Impact:** in a multi-worker deployment, worker A claims message M (`availableAt = now+1m`), its dispatch is slow (>1 min) and later throws; worker B has meanwhile re-claimed M. A's late `defer(M)` still matches (M is unpublished), so it increments `attempts` and overwrites `availableAt`, clobbering B's claim state. Retry metadata becomes corrupted and a message can be deferred/retried out of order relative to its real owner.
- **Reproduction:** claim M; sleep 61 s; worker B claims M (returns true); worker A's `dispatch` throws; A calls `defer(M)` — succeeds and overwrites B's `availableAt`.
- **Proposed fix:** add `andWhere('message.availableAt <= :now')` to `defer()` (mirroring `claim()`), or require the caller to pass the claimed `until` and match on it.

### Bug 2 — unbounded retry / no dead-letter or max-attempts (medium, operational)
- **File/line:** `src/Store/Repository/StoreOutboxMessageRepository.php:51` (`message.attempts + 1`), `src/Store/Command/PublishOutboxCommand.php:53,61`
- **Description:** `defer()` increments `attempts` indefinitely; `findUnpublished()` never filters on `attempts`, and there is no dead-letter path.
- **Impact:** a permanently failing message (bus permanently down, unsupported topic) is retried forever every 5 minutes and accumulates rows in `store_outbox_message` forever, growing the table and churning retries with no visibility.
- **Reproduction:** insert a message with an unsupported topic and run `app:store:outbox:publish` repeatedly — `attempts` grows without bound.
- **Proposed fix:** cap retries (e.g. stop `findUnpublished` at a max `attempts`, or move rows to a dead-letter table after N attempts) and surface the error.

### Bug 3 — brittle envelope `type`/`version` derivation (low, robustness)
- **File/line:** `src/Store/Command/PublishOutboxCommand.php:40-41`
- **Description:** `'type' => str_replace('.v1', '', $message->getTopic())` strips `.v1` from **anywhere** in the topic (not just the trailing version), and `'version' => 1` is hardcoded.
- **Impact:** correct for the current four `*.v1` topics, but any future `.v2` topic (or a topic containing `.v1` in the domain name) yields `type` still suffixed (e.g. `...v2`) while `version` stays `1`. Consumers such as `InventoryReservationRequestedHandler` (validates `type === 'inventory.reservation.requested'` and `version === 1`) would reject such an envelope, deferring the message forever.
- **Reproduction:** add a topic `inventory.reservation.requested.v2`; the generated envelope is `type: 'inventory.reservation.requested.v2'`, `version: 1` → consumer throws `Invalid ... envelope type or version`.
- **Proposed fix:** strip only a trailing version suffix (e.g. `preg_replace('/\.v\d+$/', '', $topic)`) and derive `version` from the stripped suffix instead of hardcoding `1`.

### Bug 4 — publish persistence relies on a single trailing `flush()` outside `finally` (low–medium, durability)
- **File/line:** `src/Store/Command/PublishOutboxCommand.php:56-64`
- **Description:** `markPublished()` mutates the managed entities, but the only `flush()` runs after the loop (line 64) and is not guarded by `finally` or a transaction. `claim()`/`defer()` are DQL bulk updates that bypass the Unit of Work, leaving the loaded entities with stale in-memory `availableAt`. Any unexpected exception in the loop — e.g. `findUnpublished()`, `claim()`, or `defer()` throwing a DB error (none of these are inside the per-message `try/catch`) — aborts `execute()` before `flush()`, so earlier `markPublished()` mutations are silently lost.
- **Impact:** successfully-dispatched messages are re-dispatched on the next run (duplicate delivery). Consumers are idempotent via consumed-event dedup, so duplicates are tolerated, but the design risks duplicate delivery and inconsistent partial batches (some messages marked published in DB, others not).
- **Reproduction:** mock the repository so `claim()` throws on the second message after the first dispatched successfully; `flush()` never runs and the first message is not persisted as published.
- **Proposed fix:** wrap each dispatch in its own transaction (or flush per message), and/or put the flush in a `finally`; optionally use `Query::HINT_READ_ONLY` on `findUnpublished` so the bulk updates and the UoW cannot diverge.

### Bug 5 — `$id === null` silently drops a message (negligible, dead-in-practice)
- **File/line:** `src/Store/Command/PublishOutboxCommand.php:35`
- **Description:** if `getId()` returns `null`, the message is skipped (`continue`) without defer or retry; it stays unpublished forever.
- **Impact:** unreachable in practice (entities returned by `findUnpublished()` always have DB ids), but the branch hides a programming error instead of surfacing it.
- **Proposed fix:** log or throw when a message has no id rather than silently skipping.

## Skipped tests

**None.** Every correct-behavior test written for the five files passes against the current `src/` code; no `src` bug forced a skip. The functional single-worker semantics of `claim()`/`defer()`/`findUnpublished()` and the command's happy-path/error-path branching are all validated and green.

## Testing note (not a src bug)

While mocking `MessageBusInterface` it surfaced that `dispatch()` has a non-nullable `Envelope` return type: a mock configured with `with()` but no `willReturn*` returns `null`, which PHP's engine turns into a `TypeError` *inside* the mocked call — the command's `catch (\Throwable)` then treats it as a dispatch failure and defers. This is standard PHPUnit/default-value behavior, not a defect in the command; tests must return a real `Envelope` from the dispatch stub (the added command test does).
