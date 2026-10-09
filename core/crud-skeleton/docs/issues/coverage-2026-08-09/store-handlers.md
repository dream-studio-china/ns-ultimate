# Store MessageHandlers — coverage to ~100% and bug report

Date: 2026-08-09
Scope: `src/Store/MessageHandler/*` (5 handlers)
Runner: `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit <file> --no-coverage`
Rule: **no changes under `src/`** — only tests added under `tests/` plus this report.

## Coverage before → after

Verified with Xdebug coverage (`XDEBUG_MODE=coverage ... --coverage-clover`) against the
new `tests/Store/MessageHandler/*` suite combined with the pre-existing
`tests/Store/Integration/TradeOrderCreatedHandlerTest.php` and
`tests/Store/Integration/InventoryReservationOutcomeHandlerTest.php` (54 tests, 132
assertions, all green, no deprecations/notices/warnings).

| File | Before | After |
|---|---|---|
| `TradeOrderCancelledHandler.php` | 64.71% (33/51) | **100%** (51/51) |
| `InventoryReservationConfirmedHandler.php` | 87.10% (27/31) | **100%** (31/31) |
| `InventoryReservationRejectedHandler.php` | 83.87% (26/31) | **100%** (31/31) |
| `InventoryReservationReleasedHandler.php` | 90.91% (20/22) | **100%** (22/22) |
| `TradeOrderCreatedHandler.php` | 90.91% (70/77) | **100%** (77/77) |

Every line listed in `var/uncovered-map.txt` for these handlers is now executed
(41,49,54,67,68,70,79–91; 35,39,48,63; 35,39,43,48,63; 32,40; 47,52,65,80,107,127,137).

## Test files added (6 files, 43 tests: 43 passing + 0 skipped)

All in `tests/Store/MessageHandler/`, namespace `App\Tests\Store\MessageHandler`,
`declare(strict_types=1);`. DB-backed classes use `DatabaseBootstrapTrait` +
`IntegrationWebTestCase`; concurrency-only branches use mock unit tests (the in-transaction
re-dedup lines can't be reached sequentially, only via two concurrent checks).

- `TradeOrderCancelledHandlerTest.php` (10 tests) — the biggest win. Invalid envelope
  (wrong type / version / non-string eventId / non-array payload / missing fields → line 41),
  duplicate eventId ignored (49), tombstone idempotency for an unknown order (70), conflicting
  tombstone store → `LogicException` (67–68), cancel of a `pending_validation` order with no
  reservation (79–82), cancel of an `awaiting_inventory` order publishing
  `inventory.reservation.release.requested.v1` with full correlation payload (79–91).
- `TradeOrderCancelledHandlerConcurrencyTest.php` (1 test) — second `findOneBy` inside the
  transaction returns a consumed event → early return (54).
- `InventoryReservationOutcomeCoverageTest.php` (19 tests) — Confirmed: invalid envelope (35),
  missing `confirmedAt` (39), ignored for unknown order / store mismatch / tradeOrderUuid
  mismatch / reservationId mismatch / non-`awaiting_inventory` status (63). Rejected: invalid
  envelope (35), missing/type-mismatched required fields (39), duplicate event ignored (43),
  ignored for unknown order / store mismatch / terminal status (63). Released: invalid
  envelope / missing `releasedAt` / missing `reservationId` (32), non-UUID reservation id
  consumed.
- `InventoryReservationOutcomeConcurrencyTest.php` (3 tests) — in-transaction re-dedup early
  return for Confirmed (48), Rejected (48), Released (40).
- `TradeOrderCreatedHandlerCoverageTest.php` (9 tests) — missing store snapshot / snapshot
  without `uuid` (47), conflicting cancellation tombstone → `LogicException` (65), duplicate
  created event with a **different** eventId leaves the existing order untouched (80),
  unavailable store + missing `orderUuid` → `InvalidArgumentException` (107), and with an
  inventory-enabled handler: empty items (127), zero-quantity item (137), item missing
  `catalogReference` (137), non-integer quantity (137).
- `TradeOrderCreatedHandlerConcurrencyTest.php` (1 test) — in-transaction re-dedup early
  return (52).

Notes: final `StoreTradeOrderCancellationRepository` cannot be doubled by PHPUnit, so the
concurrency unit tests construct a real instance over a mocked `ManagerRegistry` (its query
methods are never reached on the early-return path). The unit tests carry
`#[AllowMockObjectsWithoutExpectations]` (the existing suite's convention for mock-only tests)
so no PHPUnit notices are emitted under `failOnNotice="true"`.

## Bugs found (reported only — no source changed)

No correct-behavior test failed, so nothing needed to be marked skipped; the findings below
are robustness/correctness gaps surfaced by reading each handler end-to-end against its
producer (Trade) and consumer (Inventory).

### Bug 1 (MEDIUM) — Store publishes inventory reservation requests Inventory can never accept

- **File / line:** `src/Store/MessageHandler/TradeOrderCreatedHandler.php:132-137`
  (`inventoryItems()`) — requires only `is_string($item['catalogReference'] ?? null)`.
- **Description:** Trade serialises `catalogReference` as
  `$item->getSpecification()?->getUuid() ?? ''` (`src/Trade/Service/OrderService.php:128`), so
  a specification-less order item produces `catalogReference = ''`. The Store handler accepts
  that (empty string is a string), builds the `inventory.reservation.requested.v1` outbox
  payload, but the Inventory consumer (`src/Inventory/MessageHandler/InventoryReservationRequestedHandler.php:119-120`)
  requires `catalogReference` to match a UUID and throws `InvalidArgumentException`.
- **Impact:** the reservation request is a poison message (3 retries, then the `failed`
  transport), and the Store order is permanently stuck in `awaiting_inventory` — the
  confirmation can never arrive. A trade order with any specification-less line can never be
  accepted by the Store projection while `INVENTORY_ENABLED=1`.
- **Reproduction:** create a Trade order whose item has no specification (specification deleted
  / null); observe `trade.order.created` → Store order `awaiting_inventory`, outbox request with
  `items[0].catalogReference = ''`; the Inventory `InventoryReservationRequestedHandler` throws
  `Invalid inventory reservation request item.` on every delivery.
- **Proposed fix:** validate `catalogReference` (and `lineId`) against the UUID format (and
  non-empty) in `inventoryItems()`, failing deterministically at the Store boundary; and/or make
  Trade emit a stable UUID reference for specification-less items instead of `''`.

### Bug 2 (MEDIUM) — deterministically-invalid `trade.order.created` payloads become poison messages

- **File / line:** `src/Store/MessageHandler/TradeOrderCreatedHandler.php:37-38` (shallow
  envelope validation), `:56-61` (`StoreConsumedEvent` persisted inside the transaction),
  `:106-107` (`recordRejected()` orderUuid throw), `:126-137` (`inventoryItems()` throws),
  plus `src/Store/Service/StoreOrderService.php:84-86,110-111` (`LogicException` on snapshot
  conflict).
- **Description:** only `is_string($eventId) && is_array($payload)` is validated up front. Any
  deeper failure (missing `orderUuid` in `recordRejected()`, empty/invalid items, conflicting
  snapshot) throws **inside** `wrapInTransaction`, which rolls back the already-persisted
  `StoreConsumedEvent`. The event is therefore never marked consumed and is retried (3×) with no
  chance of success before landing in the `failed` transport. Contrast the cancellation handler,
  which validates the full envelope (type/version/eventId/payload/orderUuid/storeUuid/cancelledAt/
  timestamp) before any persistence.
- **Impact:** a single malformed-but-structurally-valid `trade.order.created` event blocks
  order acceptance for that trade order, requires manual `failed`-transport intervention, and
  re-runs the whole transaction (re-creating and rolling back a Store order) on every retry.
- **Reproduction:** dispatch `trade.order.created` with a payload containing a valid `store.uuid`
  but no `orderUuid` and a non-existent store → `InvalidArgumentException: Trade order event does
  not include an order UUID.` on every redelivery; `store_consumed_event` never contains the
  eventId.
- **Proposed fix:** move deterministic structural validation (orderUuid present, items well-formed
  when inventory is enabled, store snapshot shape) ahead of the transaction, or consume-and-record
  a terminal `StoreConsumedEvent` for invalid envelopes instead of throwing.

### Bug 3 (LOW/MEDIUM) — Store-side dedup has no payload-hash integrity check

- **File / line:** `src/Store/MessageHandler/TradeOrderCancelledHandler.php:48,53`,
  `TradeOrderCreatedHandler.php:40,51`, `InventoryReservationConfirmedHandler.php:42,47`,
  `InventoryReservationRejectedHandler.php:42,47`, `InventoryReservationReleasedHandler.php:34,39`.
- **Description:** all five handlers dedup by `eventId` alone (`findOneBy(['eventId' => ...])`)
  and silently return on a hit. The Inventory handlers (`src/Inventory/MessageHandler/InventoryReservationRequestedHandler.php:181-192`,
  `InventoryReservationReleaseRequestedHandler.php:122-133`) additionally compare the payload
  hash and raise `InventoryMessageIntegrityException` when an eventId is reused with a different
  payload.
- **Impact:** if a producer reuses an eventId with a modified payload (a redelivery, or a
  tampered/corrupted message), the Store silently ignores the new payload instead of flagging
  the integrity violation; the two sides behave differently for the same fault.
- **Reproduction:** deliver `inventory.reservation.confirmed` with eventId `E`, then deliver it
  again with eventId `E` and a different `storeOrderUuid`. Store: silently consumed (order never
  accepted). Inventory (same scenario on its own handlers): `InventoryMessageIntegrityException`.
- **Proposed fix:** store `hash('sha256', json_encode($envelope))` (already computed for the
  `StoreConsumedEvent`) and `hash_equals()` it on dedup, throwing an integrity exception on
  mismatch, mirroring the Inventory side.

### Bug 4 (LOW) — `confirmedAt`/`rejectedAt`/`releasedAt` are required but never used

- **File / line:** `InventoryReservationConfirmedHandler.php:37-40`,
  `InventoryReservationRejectedHandler.php:37-40`, `InventoryReservationReleasedHandler.php:30-31`;
  `src/Store/Entity/StoreOrder.php:129-130`.
- **Description:** the three outcome handlers require an ISO-8601 timestamp field to be present
  (a non-string payload throws) but never parse it, validate its format, or persist it.
  `StoreOrder::accept()/reject()` stamp `acceptedAt`/`rejectedAt` with processing time
  (`new \DateTimeImmutable()`), not the event timestamp. A confirmation with
  `confirmedAt: 'not-a-date'` is accepted and the order is accepted anyway.
- **Impact:** audit timestamps on the Store order reflect when the Store processed the outcome,
  not when Inventory confirmed/rejected (can be hours off for delayed messages), and the event's
  timestamp is discarded. Invalid timestamps are accepted silently.
- **Reproduction:** `InventoryReservationConfirmedHandler` accepts
  `['reservationId' => ..., ..., 'confirmedAt' => 'garbage']` and marks the order accepted; the
  resulting `store.order.accepted.v1` payload carries `acceptedAt = <now>` instead of the
  confirmed time.
- **Proposed fix:** validate the timestamp format and/or surface it — e.g. `StoreOrder::accept()`
  gains an optional `$acceptedAt`, the handlers pass the parsed event timestamp, and the accepted/
  rejected outbox payloads use it.

### Bug 5 (LOW) — release acknowledgements are consumed blindly

- **File / line:** `src/Store/MessageHandler/InventoryReservationReleasedHandler.php:34-47`.
- **Description:** the released handler only persists a `StoreConsumedEvent`; it never looks up
  the Store order, never verifies the `reservationId` matches one (unlike the Confirmed/Rejected
  handlers' correlation checks), and never publishes any acknowledgement back to Trade (the
  Store order was already marked cancelled by `TradeOrderCancelledHandler`).
- **Impact:** a release event for an unknown or never-cancelled reservation is silently marked
  consumed, and the cancellation flow produces no terminal outbox message from the Store side,
  so Trade has no Store-side acknowledgement that inventory release completed.
- **Reproduction:** deliver `inventory.reservation.released` with a random `reservationId`; the
  event is consumed (`store_consumed_event` row) with no Store-order validation and no outbox
  message.
- **Proposed fix:** correlate the `reservationId` against a Store order (as the Confirmed/
  Rejected handlers do), and decide whether the Store should emit a `store.order.cancelled.v1`
  (or similar) acknowledgement after release completes.

## Notes

- The pre-existing `tests/Store/Integration/TradeOrderCreatedHandlerTest.php` and
  `tests/Store/Integration/InventoryReservationOutcomeHandlerTest.php` already covered the
  happy paths and several branches; the new tests deliberately add only the missing branches
  (no duplication).
- Bugs are reported only — **no source files were modified**.
- The two skipped-test scenarios contemplated by the task did not arise: no correct-behavior
  test fails, so the suite is fully green (54 tests, 132 assertions) with 0 skips.
