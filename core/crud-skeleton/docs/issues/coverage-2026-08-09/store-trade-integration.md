# Store ↔ Trade ↔ Inventory — Cross-Module E2E Integration Coverage & Bug Report

- Date: 2026-08-09
- Scope: the full cross-module message paths **Trade → Store → Trade** and **Trade → Store → Inventory → Store → Trade** (inbox/outbox/Messenger), driven through the real HTTP API + real outbox publish commands.
- Constraint honored: **nothing under `src/` was modified** — only new test files under `tests/` plus this report.
- Runner: `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit <files> --no-coverage`.

## Test files added (3 files, 11 tests: 10 passing + 1 documented skip)

All under `tests/Integration/`, namespace `App\Tests\Integration`, `declare(strict_types=1);`.

| File | Kind | Covers |
|---|---|---|
| `tests/Integration/StoreTradeFlowTestCase.php` | Abstract base (extends `IntegrationWebTestCase`, uses `DatabaseBootstrapTrait`) | Shared table cleanup, the **synchronous MessageBus** helper that routes every Store/Trade/Inventory integration message to the real registered handler, and the real `app:trade:outbox:publish` / `app:store:outbox:publish` / `app:inventory:outbox:publish` runners built on `CommandTester` against the real SQLite DB. |
| `tests/Integration/StoreTradeFlowTest.php` | `StoreTradeFlowTest` (inventory disabled, the default) — 8 tests | Full Trade→Store acceptance (`202 → awaiting_store_acceptance → StoreOrder accepted → store_accepted → confirmed`), Store rejection via the real Staff HTTP endpoint, store-becomes-unavailable race, unknown store code 400, inbox dedup, cross-eventId idempotency, cancellation tombstone, out-of-order tombstone flow. |
| `tests/Integration/StoreTradeInventoryEnabledFlowTest.php` | `StoreTradeInventoryEnabledFlowTest` (INVENTORY_ENABLED=1) — 2 tests + 1 skipped | Full reservation accept → release-on-cancel chain (request → confirmed → accepted → cancelled → release request → released → stock restored) and reservation-rejection propagation to `store_rejected`. Plus a skipped correct-behaviour test for the release-before-reserve gap. |

## INVENTORY_ENABLED toggling (documented per the task)

`StoreTradeInventoryEnabledFlowTest::setUpBeforeClass()` sets **before any kernel boot**:

```php
$_ENV['INVENTORY_ENABLED'] = '1';
$_SERVER['INVENTORY_ENABLED'] = '1';
putenv('INVENTORY_ENABLED=1');
```

`src/Store/MessageHandler/TradeOrderCreatedHandler.php:28` reads the toggle via
`#[Autowire('%env(bool:INVENTORY_ENABLED)%')]`, which Symfony resolves at runtime
from `$_ENV` / `$_SERVER` / `getenv()`. Because `IntegrationWebTestCase::createKernel()`
boots with `APP_DEBUG=true` (no compiled-container caching) and each test calls
`ensureKernelShutdown()`, every kernel boot in the class sees `INVENTORY_ENABLED=1`
and the handler requests a reservation instead of auto-accepting. The toggling
**persists for the whole PHPUnit process**, so this file must be run in isolation
(`phpunit tests/Integration/StoreTradeFlowTest.php tests/Integration/StoreTradeInventoryEnabledFlowTest.php`)
and is documented as such on the class. The two files run in one invocation so the
shared `var/test.db` is bootstrapped exactly once (`DatabaseBootstrapTrait` static flag).

## What the E2E harness does

The production relay is exercised for real:

1. HTTP `POST /api/v1/app/orders` with a trusted `X-Store-Code` header → `202`, Trade order in `awaiting_store_acceptance`, `trade.order.created.v1` in the Trade outbox.
2. `app:trade:outbox:publish` runs against the real DB; its `MessageBusInterface` is a synchronous `MessageBus([HandleMessageMiddleware(HandlersLocator([...]))])` mapping each message class to the **real** registered handler. So the claim/publish/`markPublished`/flush code path AND the handler both execute.
3. `app:store:outbox:publish` / `app:inventory:outbox:publish` likewise, in the order the real relay would use.
4. Store/Trade statuses are asserted at every step.

The sync bus maps: `TradeOrderCreatedMessage|TradeOrderCancelledMessage → Store\MessageHandler\*`, `StoreOrderAcceptedMessage|StoreOrderRejectedMessage → Trade\MessageHandler\*`, `InventoryReservationRequestedMessage|InventoryReservationReleaseRequestedMessage → Inventory\MessageHandler\*`, `InventoryReservationConfirmedMessage|InventoryReservationRejectedMessage|InventoryReservationReleasedMessage → Store\MessageHandler\*`.

## Coverage results (Xdebug, this suite only, `--coverage-filter src/Store|src/Trade|src/Inventory`)

| File | Methods | Lines |
|---|---|---|
| `Store/MessageHandler/TradeOrderCreatedHandler.php` | 25.0% (1/4) | **90.91% (70/77)** |
| `Store/MessageHandler/TradeOrderCancelledHandler.php` | 50.0% (1/2) | **84.31% (43/51)** |
| `Store/MessageHandler/InventoryReservationConfirmedHandler.php` | 50.0% | **83.87% (26/31)** |
| `Store/MessageHandler/InventoryReservationRejectedHandler.php` | 50.0% | **83.87% (26/31)** |
| `Store/MessageHandler/InventoryReservationReleasedHandler.php` | 50.0% | **86.36% (19/22)** |
| `Store/Service/StoreOrderService.php` | 25.0% (2/8) | **81.90% (86/105)** |
| `Store/Service/StoreContextResolver.php` | 50.0% | **92.86% (13/14)** |
| `Store/Command/PublishOutboxCommand.php` | 50.0% | **80.65% (25/31)** |
| `Trade/MessageHandler/StoreOrderAcceptedHandler.php` | 50.0% | **84.62% (11/13)** |
| `Trade/MessageHandler/StoreOrderRejectedHandler.php` | 50.0% | **84.62% (11/13)** |
| `Trade/Command/PublishOutboxCommand.php` | 50.0% | **83.87% (26/31)** |
| `Trade/Service/OrderService.php` | 20.0% (2/10) | 36.41% (67/184) (create-order + pricing path) |
| `Trade/EventListener/OrderWorkflowListener.php` | 33.3% | 64.71% (33/51) |
| `Trade/Controller/App/OrderController.php` | 33.3% (4/12) | 52.43% (54/103) |
| `Inventory/MessageHandler/InventoryReservationRequestedHandler.php` | 33.3% | **81.25% (91/112)** |
| `Inventory/MessageHandler/InventoryReservationReleaseRequestedHandler.php` | 40.0% | **78.79% (52/66)** |
| `Inventory/Service/InventoryService.php` | 25.0% (3/12) | **73.05% (187/256)** |
| `Inventory/Command/PublishOutboxCommand.php` | — | exercised (confirmed/rejected/released publish) |
| `Trade/Service/TradeOutboxService.php`, `Store/Service/StoreOutboxService.php`, `Inventory/Service/InventoryOutboxService.php` | 100% | **100%** |

These percentages are from the new files in isolation; the whole-suite figures are
higher because the pre-existing suites already covered many of these classes.

## What each test exercises (happy + bad paths)

**`StoreTradeFlowTest` (inventory disabled)**

1. `testFullTradeToStoreAcceptanceThroughHttpAndOutboxCommands` — `202` → `awaiting_store_acceptance` → `trade.order.created.v1` outbox → `app:trade:outbox:publish` (sync bus, output "Published 1") → StoreOrder `accepted` + `store.order.accepted.v1` outbox → `app:store:outbox:publish` → Trade `store_accepted` → idempotent second publish ("Published 0") → HTTP `confirm` → `confirmed`.
2. `testStoreRejectionLeavesTradeOrderInStoreRejectedUntilExplicitCancel` — real Staff `POST /store/{uuid}/orders/{uuid}/reject` (with membership grant) → `store.order.rejected.v1` outbox → publish → Trade order **`store_rejected`** (see Bug 1) → explicit HTTP `cancel` → `cancelled`.
3. `testStoreBecomingUnavailableAfterPlacementRejectsTheOrder` — order placed while store active, store suspended before the Trade outbox is relayed → Store handler emits `store.order.rejected.v1` (`STORE_UNAVAILABLE`) → publish → Trade `store_rejected`.
4. `testUnknownStoreCodeReturnsBadRequestAndCreatesNoOrder` — unknown `X-Store-Code` → HTTP `400`, no Trade outbox, no Store order.
5. `testDuplicateEventDeliveryIsDeduplicatedByInbox` — same event delivered twice to `TradeOrderCreatedHandler` → one StoreOrder, one `store.order.accepted.v1` outbox, one `store_consumed_event` row.
6. `testStoreOrderAlreadyProcessedIsIdempotentAcrossEventIds` — identical payload under two eventIds → `createFromTradeOrderSnapshot` idempotency holds → one StoreOrder, one accepted outbox.
7. `testCancellationForUnknownStoreOrderPersistsTombstoneAndIsIdempotent` — cancellation for a never-created Store order → `store_trade_order_cancellation` tombstone persisted, duplicate delivery ignored, no Store order.
8. `testOutOfOrderCancellationTombstoneIsHonoredWhenOrderCreatedLater` — HTTP cancel before the Trade created-event reaches Store; cancelled message delivered first (out-of-order) → tombstone; then `app:trade:outbox:publish` → StoreOrder created as `cancelled`, no reservation, no outbox.

**`StoreTradeInventoryEnabledFlowTest` (INVENTORY_ENABLED=1)**

1. `testInventoryReservationAcceptAndReleaseOnTradeCancellation` — full chain: `202` → trade publish → StoreOrder `awaiting_inventory` + `inventory.reservation.requested.v1` → store publish → reservation `confirmed`, stock `10 → 8 available`, `inventory.reservation.confirmed.v1` → inventory publish → StoreOrder `accepted` + `store.order.accepted.v1` → store publish → Trade `store_accepted` → HTTP cancel → `cancelled` + `trade.order.cancelled.v1` → trade publish → StoreOrder `cancelled` + `inventory.reservation.release.requested.v1` → store publish → reservation `released`, stock restored to `10 available`, `inventory.reservation.released.v1` → inventory publish.
2. `testReservationRejectionPropagatesToTradeStoreRejected` — no recipe/material for the spec → reservation rejected `SPECIFICATION_NOT_STOCKABLE` → StoreOrder `rejected` → `store.order.rejected.v1` → Trade `store_rejected`.

## Bugs found (documented only — no source changed)

### Bug 1 (MEDIUM) — Store rejection does NOT cancel the Trade order; it stalls in `store_rejected`

- **File/line:** `src/Trade/MessageHandler/StoreOrderRejectedHandler.php:37-41` (applies only `store_reject`); state machine `config/packages/workflow.yaml` (`store_rejected` is a non-terminal place, `cancel` from it is a user action).
- **Description:** after the Store rejects, `StoreOrderRejectedHandler` applies `store_reject` and stops. The Trade order becomes `store_rejected`, which is **not** `cancelled`. The task's expected end-state "store rejects → trade order cancelled" therefore does not hold: the order sits in `store_rejected` until a user explicitly calls `POST /app/orders/{id}/cancel`. Nothing auto-cancels, no timeout, no compensation.
- **Impact:** a rejected order stays "open" in reporting/status lists and can still be mutated via the generic `/do/{transition}` (the workflow permits `cancel`), and `store_rejected` orders never reach the terminal `cancelled` state on their own. Cross-module compensation is asymmetric: Store-side rejection is terminal, Trade-side is not.
- **Reproduction:** `StoreTradeFlowTest::testStoreRejectionLeavesTradeOrderInStoreRejectedUntilExplicitCancel` — asserts `store_rejected` after the rejection is relayed, then shows an explicit cancel is required to reach `cancelled`.
- **Proposed fix:** have `StoreOrderRejectedHandler` apply `store_reject` **and** `cancel` (or make the workflow's `store_reject` transition go straight to `cancelled`) so a Store rejection lands the Trade order in a terminal state, or clearly document `store_rejected` as the intended terminal state.

### Bug 2 (MEDIUM, documented TODO) — release-before-reserve is a poison message

- **File/line:** `src/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandler.php:39-42` (`findOneByReservationId` → `null` → throws `InvalidArgumentException('Reservation was not found.')`).
- **Description:** if a Trade cancellation is relayed while the `inventory.reservation.requested` is still in flight, the Store `TradeOrderCancelledHandler` records `inventory.reservation.release.requested.v1` for a reservation that does not exist yet. The Inventory release handler throws, so the message is retried 3× then lands in the `failed` transport (it would only self-heal if a later retry happens after the reservation request is processed — with the default retry strategy there is no guarantee).
- **Impact:** the cancellation path can produce a failed message and, more importantly, the release never completes for the reservation once it is created later, leaving stock reserved indefinitely.
- **Reproduction:** `StoreTradeInventoryEnabledFlowTest::testReleaseBeforeReserveIsHandledGracefully` — **skipped** because it asserts the correct behaviour (dispatch the release request ahead of the reservation request; expect no throw and no reservation created). Fails against current src. `docs/ai/context.md` §22.1 already lists "release-before-reserve handling" as not-yet-implemented.
- **Proposed fix:** treat a missing reservation as an out-of-order event (no-op + consumed-event record, or a tombstone) rather than throwing, so the release is naturally idempotent once the reservation arrives.

### Bug 3 (LOW, previously documented) — StoreOrder snapshot idempotency is key-order sensitive

- **File/line:** `src/Store/Service/StoreOrderService.php:180` (`matchesSnapshot` uses `===` on the `order_snapshot` JSON array).
- **Description:** the E2E cross-eventId idempotency test (`testStoreOrderAlreadyProcessedIsIdempotentAcrossEventIds`) passes because the relayed payload is byte-identical. But a redelivered `trade.order.created` whose JSON key order differs (e.g. a different producer serialization) is treated as a snapshot conflict → `LogicException` → poison message. Already documented in `docs/issues/coverage-2026-08-09/store-command-repo-entity.md` and skipped in `tests/Store/Service/StoreOrderServiceTest.php::testCreateFromSnapshotIsIdempotentDespiteSnapshotKeyOrder`.
- **Proposed fix:** compare snapshots with a canonical, key-order-insensitive normalization (e.g. `ksort` recursively) or hash.

## Skipped tests

- `App\Tests\Integration\StoreTradeInventoryEnabledFlowTest::testReleaseBeforeReserveIsHandledGracefully` — asserts the correct behaviour (a release request that arrives before its reservation must not throw). Fails against current src (Bug 2). Skipped to keep the suite green; the gap is a documented TODO (`context.md` §22.1).

## Observations (not bugs)

- **`InventoryOutboxMessage` API asymmetry:** unlike `StoreOutboxMessage` / `TradeOutboxMessage`, the inventory outbox entity exposes only `getId()`/`getEventId()`/`isPublished()`/`markPublished()`/`recordAttempt()` — no `getTopic()`/`getPayload()`. Tests had to use the array-returning `findUnpublishedForPublishing()`. Cosmetic; nothing in `src/` reads those getters today.
- The real Store/Trade publish commands drive the handlers to completion **only** when the bus is synchronous. In production the messages are routed to the `async` (Doctrine) transport by `config/packages/messenger.yaml`, so the command marks them published while a separate `worker` consumes them — the E2E sync-bus harness is the test-time equivalent and exercises the exact same command + handler code.

## Final test run

```
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
    tests/Integration/StoreTradeFlowTest.php \
    tests/Integration/StoreTradeInventoryEnabledFlowTest.php --no-coverage

OK, but some tests were skipped!
Tests: 11, Assertions: 112, Skipped: 1.
```

(The shared `var/test.db` occasionally shows transient `no such table` / `database is locked` errors when concurrent runners drop/recreate the schema — wait 10–15 s and re-run, per the project convention. This suite bootstraps the schema on first run and is green in isolation.)
