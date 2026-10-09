# Store module test audit (2026-08-09)

Auditor scope: `tests/Store/` (26 files, ~153 tests) cross-read against `src/Store/`,
`docs/testing/crud-skeleton-production/*`, and `docs/issues/coverage-2026-08-09/README.md`.
Read-only audit; only this report is written.

Classification scheme applied:
A. KEEP — core behavioral value.
B. DELETE CANDIDATE with reason code: 1 COVERAGE-CHASING, 2 DUPLICATE, 3 IMPLEMENTATION-DETAIL, 4 REDUNDANT-REGRESSION, 5 NEAR-EMPTY.
C. MERGE SUGGESTION.

Conservative by design: HIGH-confidence DELETE candidates are limited to exact
duplicate/tautological citations. Store integration flows (accept/reject/cancel/
tombstone, outbox→inbox idempotency) and every concurrency test are KEEP. Skipped
tests that document known Store bugs (`StoreOrderServiceTest::testCreateFromSnapshotIsIdempotentDespiteSnapshotKeyOrder`,
per coverage README bug 28) are KEEP.

## Summary

| File | Tests | Verdict |
|---|---|---|
| `tests/Store/Entity/StoreTest.php` | 5 | KEEP |
| `tests/Store/Entity/StoreMembershipTest.php` | 2 | KEEP |
| `tests/Store/Entity/StoreOrderTest.php` | 1 | KEEP |
| `tests/Store/Entity/StoreOutboxMessageTest.php` | 1 | KEEP |
| `tests/Store/Entity/StoreOutboxMessageLifecycleTest.php` | 3 | 2 KEEP → merge into `StoreOutboxMessageTest`; 1 DELETE |
| `tests/Store/Entity/StoreConsumedEventTest.php` | 1 | KEEP |
| `tests/Store/Entity/StoreConsumedEventLifecycleTest.php` | 2 | 1 KEEP → merge into `StoreConsumedEventTest`; 1 DELETE |
| `tests/Store/Entity/StoreTradeOrderCancellationTest.php` | 1 | KEEP (low value; merge suggestion) |
| `tests/Store/Service/StoreServiceTest.php` | 4 | KEEP |
| `tests/Store/Service/StoreMembershipServiceTest.php` | 7 | KEEP |
| `tests/Store/Service/StoreContextResolverTest.php` | 4 | KEEP |
| `tests/Store/Service/StoreOrderServiceTest.php` | 17 (1 skipped = bug doc) | KEEP; 1 mirror-test merge |
| `tests/Store/Command/PublishOutboxCommandTest.php` | 6 | KEEP |
| `tests/Store/Repository/StoreOutboxMessageRepositoryTest.php` | 11 | KEEP |
| `tests/Store/Controller/Staff/StoreOrderControllerTest.php` | 17 | KEEP |
| `tests/Store/Controller/Manage/StoreControllerTest.php` | 15 | KEEP (13); 2 DELETE candidates |
| `tests/Store/Integration/StoreScopedOrderFlowTest.php` | 1 | KEEP |
| `tests/Store/Integration/StoreControllerViewIntegrationTest.php` | 1 | KEEP |
| `tests/Store/Integration/TradeOrderCreatedHandlerTest.php` | 7 | KEEP |
| `tests/Store/Integration/InventoryReservationOutcomeHandlerTest.php` | 4 | KEEP |
| `tests/Store/MessageHandler/TradeOrderCreatedHandlerCoverageTest.php` | 9 | KEEP |
| `tests/Store/MessageHandler/InventoryReservationOutcomeCoverageTest.php` | 19 | KEEP |
| `tests/Store/MessageHandler/TradeOrderCancelledHandlerTest.php` | 10 | KEEP |
| `tests/Store/MessageHandler/TradeOrderCreatedHandlerConcurrencyTest.php` | 1 | KEEP (concurrency) |
| `tests/Store/MessageHandler/TradeOrderCancelledHandlerConcurrencyTest.php` | 1 | KEEP (concurrency) |
| `tests/Store/MessageHandler/InventoryReservationOutcomeConcurrencyTest.php` | 3 | KEEP (concurrency) |

## KEEP

- **Integration flows (critical path).** `TradeOrderCreatedHandlerTest`, `InventoryReservationOutcomeHandlerTest`,
  `TradeOrderCancelledHandlerTest`, `StoreScopedOrderFlowTest`, `StoreControllerViewIntegrationTest` protect the
  store acceptance/rejection, cancellation-before-create tombstone, outbox→inbox dedup, and store-boundary
  authorization invariants from `BUSINESS_INVARIANTS.md` / matrix critical path #6. Do not delete.
- **Concurrency tests.** `TradeOrderCreatedHandlerConcurrencyTest`, `TradeOrderCancelledHandlerConcurrencyTest`,
  `InventoryReservationOutcomeConcurrencyTest` exercise the double-check `findOneBy` inside `wrapInTransaction`
  (the "event consumed between check and transaction" race). Per audit brief, concurrency tests are KEEP.
- **Skipped bug-documenting tests.** `StoreOrderServiceTest::testCreateFromSnapshotIsIdempotentDespiteSnapshotKeyOrder`
  (skipped; documents `matchesSnapshot` order-sensitive `===`, coverage README bug 28). KEEP.
- **Repository.** `StoreOutboxMessageRepositoryTest` is the only persistence-bound coverage of `findUnpublished`,
  `claim`, and `defer` SQL (including `defer()` claim-ownership guard gap, coverage README bug 15). KEEP all 11.
- **Command.** `PublishOutboxCommandTest` covers the full publish/claim/defer/dispatch contract (bugs 16/52/53/54).
  The four `claim` false-branch tests are distinct guards, not duplicates. KEEP.
- **Service unit tests.** `StoreServiceTest`, `StoreMembershipServiceTest`, `StoreContextResolverTest`,
  `StoreOrderServiceTest` are the canonical unit layer for validation/idempotency/authorization decisions.
  `StoreOrderServiceTest::testCreateFromSnapshotRethrowsUniqueConstraintWhenExistingDisappears` /
  `...RethrowsConflictWithUniqueConstraintCause` / `...ReturnsExistingAfterUniqueConstraint` cover three distinct
  race paths in `createFromTradeOrderSnapshot` (pre-check vs unique-constraint-catch) — complementary, not duplicates.
- **Handler "coverage" files.** Despite the campaign name, `TradeOrderCreatedHandlerCoverageTest` and
  `InventoryReservationOutcomeCoverageTest` cover distinct guard branches:
  - The created-handler idempotency tests use **different** mechanisms: `TradeOrderCreatedHandlerTest` dedups on the
    **same** `eventId` (StoreConsumedEvent lookup) while `TradeOrderCreatedHandlerCoverageTest::testDuplicateCreatedEventWithDifferentEventIdIsIgnored`
    dedups on the **same** tradeOrderUuid with **different** eventIds (StoreOrderService snapshot idempotency). These
    are different layers of the at-least-once contract, so not an exact duplicate pair.
  - The confirm/reject `IsIgnoredWhen…` tests (unknown order, store mismatch, trade-order mismatch, reservation
    mismatch, not-awaiting) each cover a distinct conjunct of the handler guard clause in
    `InventoryReservationConfirmedHandler` / `InventoryReservationRejectedHandler`.
  - The envelope-validation tests cover the poison-message contract (coverage README bugs 2, 3, 18).
- **Controller unit tests.** `Staff/StoreOrderControllerTest` and `Manage/StoreControllerTest` assert the public
  response envelope + status-code contract at the unit layer; the 404-vs-400 branches are distinct guards. The
  reflection-invoked `scopedListFilter`/`scopedDetailFilter` tests protect the store-boundary authorization invariant.
- **Entity tests.** `StoreTest`, `StoreMembershipTest`, `StoreOrderTest`, `StoreOutboxMessageTest`,
  `StoreConsumedEventTest` assert real entity behavior (state transitions, `touch()` on setters, outbox retry
  metadata, role validation). They are the strategy's "entity methods" unit layer and are not duplicated elsewhere.

## DELETE CANDIDATES

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `tests/Store/Entity/StoreOutboxMessageLifecycleTest::testIdBecomesAvailableAfterPersistenceIdAssignment` | 1 COVERAGE-CHASING — reflection-injects the private `id` property and asserts the `getId()` getter reads it back; a tautological getter readback that cannot fail. The `id === null` default is already asserted by the sibling `testNewMessageHasNoId…`. | HIGH | Same file `testNewMessageHasNoIdAndOccurredAtDefaultsToAvailableAt` (asserts the null default on construction); no behavior beyond a field getter. |
| `tests/Store/Entity/StoreConsumedEventLifecycleTest::testIdBecomesAvailableAfterPersistenceIdAssignment` | 1 COVERAGE-CHASING — identical reflection-inject + getter-readback tautology on `StoreConsumedEvent::id`. | HIGH | `StoreConsumedEventTest::testStoresInboundEventAuditFields` already constructs the entity; `testNewEventHasNoIdUntilPersisted` in the same file asserts the null default. |
| `tests/Store/Entity/StoreConsumedEventLifecycleTest::testNewEventHasNoIdUntilPersisted` | 1 COVERAGE-CHASING / 4 REDUNDANT-REGRESSION — asserts only the trivially-true `getId() === null` default that the entity constructor guarantees; adds nothing beyond `StoreConsumedEventTest` construction. | MEDIUM | `StoreConsumedEventTest::testStoresInboundEventAuditFields` (constructs the same entity and asserts its fields). |
| `tests/Store/Controller/Manage/StoreControllerTest::testProcessCreateContentReturnsValidContent` | 1 COVERAGE-CHASING — reflection-invokes `processCreateContent()` with already-valid content and asserts `assertSame($content, $result)`, a passthrough identity tautology. Valid-input acceptance is proven by the sibling reject tests (`testCreateActionRejects*`) and end-to-end by `StoreControllerViewIntegrationTest` (POST `/manage/stores` → 201). | MEDIUM | `StoreControllerViewIntegrationTest::testStoreViewsManageViewsAndStaffViews` (HTTP 201 create with the same fields); sibling `testCreateActionRejectsEmptyCode` etc. |
| `tests/Store/Controller/Manage/StoreControllerTest::testProcessUpdateContentReturnsValidContent` | 1 COVERAGE-CHASING — same passthrough-identity tautology for `processUpdateContent()`. Valid-input update acceptance is proven by `StoreControllerViewIntegrationTest` (PUT `/manage/stores/{id}` → 200). | MEDIUM | `StoreControllerViewIntegrationTest::testStoreViewsManageViewsAndStaffViews` (HTTP 200 PUT update); sibling `testUpdateActionRejectsNonArraySettings`. |

No HIGH-confidence **DUPLICATE** (reason 2) was found in the explicitly flagged pairs: the entity tests are
complementary (not mutually duplicative), `StoreOrderServiceTest` covers service-layer paths the handler
integration tests reach only incidentally, and `InventoryReservationOutcomeCoverageTest` /
`TradeOrderCreatedHandlerCoverageTest` cover distinct guard branches as explained in KEEP. The closest reason-2
case is listed under MERGE below (`testRejectWithoutOutboxThrows`), held at MEDIUM because the accept/reject
guards are separate source lines despite identical behavior.

## MERGE SUGGESTIONS

1. **`StoreOutboxMessageLifecycleTest` → `StoreOutboxMessageTest`.** `testNewMessageHasNoIdAndOccurredAtDefaultsToAvailableAt`
   and `testConstructorAcceptsExplicitOccurredAt` assert real constructor behavior for the **same entity** as
   `StoreOutboxMessageTest`; the campaign split them into a second file purely for coverage accounting. Fold the two
   kept tests into `StoreOutboxMessageTest` (and drop the tautological id-reflection test per DELETE CANDIDATES).
2. **`StoreConsumedEventLifecycleTest::testNewEventHasNoIdUntilPersisted` → `StoreConsumedEventTest`.** Single
   assertion, same entity; fold into the base file (delete the id-reflection sibling).
3. **`StoreOrderServiceTest::testRejectWithoutOutboxThrows` → fold into `testAcceptRequiresAnOutboxService`**
   (reason 2, MEDIUM). Both assert the identical `RuntimeException('Store outbox is not configured.')` thrown by the
   same `outboxService === null` guard pattern (`src/Store/Service/StoreOrderService.php:30` and `:48`). A
   table-driven `accept`/`reject` pair or a single retained guard test suffices.
4. **`StoreTradeOrderCancellationTest` → note only.** A single getter-only test on a 3-field entity; the tombstone
   behavior it mirrors is thoroughly exercised by `TradeOrderCancelledHandlerTest::testTombstoneForUnknownOrderIsIdempotent`
   and `TradeOrderCreatedHandlerTest::testCancellationBeforeCreationCreatesCancelledOrderWithoutReservation`. Keep as-is
   (harmless) or fold its three assertions into a cancellation-handler integration test.
5. **Envelope-validation test density.** `InventoryReservationOutcomeCoverageTest` (19 tests) plus the envelope
   tests in `TradeOrderCancelledHandlerTest` / `TradeOrderCreatedHandlerCoverageTest` follow an identical
   throw-`InvalidArgumentException`-with-message pattern per handler. If trimmed, prefer a table-driven
   data-provider per handler (keeps the poison-message contract) rather than dropping branches.

## Verification steps

Before acting on any DELETE candidate:

1. Run the full suite to establish the green baseline:
   `/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit`
2. For each candidate, temporarily remove the test method and re-run `tests/Store/` plus the sibling file that
   "covered by" points to, confirming no assertion count/behavior change in the surviving tests.
3. For the two HIGH-confidence entity candidates, confirm the deleted `id`-assignment lines remain covered via the
   retained construction tests (line coverage is not a delete gate; behavioral value is).
4. For the two `Manage/StoreControllerTest` candidates, confirm `StoreControllerViewIntegrationTest` still returns
   201/200 for create/update, proving the happy path is not lost.
5. Run `composer phpstan` and `composer rector:types:check` after any deletion; no `src/` change is permitted by
   this audit.
