# Inventory module test audit (2026-08-09)

Scope: `tests/Inventory/` (19 files, 109 tests). Read-only audit; no `src/` or `tests/` file was modified.
Pre-campaign files (2026-07-26, `e9a3594`) vs coverage-campaign files (2026-08-09, `4c62b5f`) were
distinguished via `git log --diff-filter=A`. Context: `TEST_STRATEGY.md`, `TEST_MATRIX.md`,
`BUSINESS_INVARIANTS.md`, `docs/issues/coverage-2026-08-09/README.md` and the two Inventory module
reports (`inventory-repos-handlers.md`, `inventory-controllers-service.md`).

No skipped tests exist in this module (all 38 suite-wide skips live elsewhere), so the
"skipped tests documenting bugs = KEEP" rule does not apply here.

## Summary

| File | Tests | Verdict |
|---|---|---|
| `tests/Inventory/Command/PublishOutboxCommandTest.php` | 6 | KEEP — merge 3 per-topic tests (C) |
| `tests/Inventory/Controller/Manage/RecipeControllerTest.php` | 7 | KEEP — 1 delete candidate (B1) |
| `tests/Inventory/Controller/Manage/StockControllerTest.php` | 8 | KEEP |
| `tests/Inventory/Entity/InventoryDomainEntityTest.php` | 4 | KEEP |
| `tests/Inventory/Entity/InventoryEntityCoverageTest.php` | 3 | DELETE file — merge 2 survivors (C) |
| `tests/Inventory/Entity/MaterialTest.php` | 1 | KEEP |
| `tests/Inventory/Integration/InventoryMessagingAndApiTest.php` | 11 | KEEP (critical messaging/idempotency) |
| `tests/Inventory/Integration/InventoryServiceCoverageTest.php` | 13 | KEEP — fold into `InventoryServiceTest` (C) |
| `tests/Inventory/Integration/InventoryServiceTest.php` | 9 | KEEP (core stock/ledger/reservation) |
| `tests/Inventory/Message/InventoryMessageTest.php` | 1 | 1 delete candidate (B1) |
| `tests/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandlerIntegrationTest.php` | 3 | KEEP — 1 delete candidate (B2) |
| `tests/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandlerTest.php` | 8 | KEEP |
| `tests/Inventory/MessageHandler/InventoryReservationRequestedHandlerIntegrationTest.php` | 3 | KEEP — 2 delete candidates (B2) |
| `tests/Inventory/MessageHandler/InventoryReservationRequestedHandlerTest.php` | 12 | KEEP — merge timestamp tests (C) |
| `tests/Inventory/Repository/RecipeLineRepositoryTest.php` | 5 | DELETE file — trivial repo (B1/B2) |
| `tests/Inventory/Repository/ReservationLineRepositoryTest.php` | 5 | DELETE file — mirror of above (B2) |
| `tests/Inventory/Service/QuantityCoverageTest.php` | 4 | MERGE into `QuantityTest` (C) |
| `tests/Inventory/Service/QuantityTest.php` | 4 | KEEP |
| `tests/Inventory/Service/SpecificationRecipeServiceTest.php` | 2 | KEEP |

## KEEP

Critical behavior that must not be deleted (per `TEST_MATRIX.md` Inventory row and
`BUSINESS_INVARIANTS.md` "Inventory" invariant: ledger-backed non-negative reserved quantities,
idempotent release):

- `InventoryServiceTest` (all 9): virtual-zero stock, recipe-demand aggregation, expired/unstockable
  rejection, reservation conflict, out-of-stock, unknown-reservation/stock failures, store-scoped
  policies + adjustment idempotency, store-UUID boundary, expired-reservation release command.
- `InventoryMessagingAndApiTest` (all 11): management API auth/validation, requested/released
  message idempotency, outbox publisher success/unsupported/transport-failure/mapping, malformed
  envelope rejection, release correlation integrity, inbox event-id-payload integrity.
- `InventoryReservationRequestedHandlerTest` and `InventoryReservationReleaseRequestedHandlerTest`
  (all validation + idempotent-skip tests): the envelope contract and at-least-once idempotency are
  the module's core messaging guarantees.
- `InventoryReservationReleaseRequestedHandlerIntegrationTest::testReleaseMessageIsIdempotentForSameEventId`
  and `testReleaseForAlreadyReleasedReservationIsSilentlyIgnored` (double delivery / double release
  no-op — the documented "release is idempotent" invariant).
- `InventoryReservationRequestedHandlerIntegrationTest::testServiceExceptionPropagatesAndConsumedEventIsRolledBack`
  (consumed-event rollback on service failure — regression for `inventory-repos-handlers` Bug #3 EM-close finding).
- `InventoryServiceCoverageTest` failure/idempotency paths that are NOT duplicated elsewhere:
  `testAdjustStockRejectsZeroQuantityOrEmptyReason`, `testAdjustStockWithLedgerButMissingStockBalanceFails`,
  `testAdjustStockAppliesNegativePolicyToExistingStock`, `testAdjustStockBelowConfirmedReservationsIsRejected`,
  `testSetStockAllowNegativeRejectsMissingMaterialInBothBranches`, `testSetStockAllowNegativeUpdatesExistingStock`,
  `testReserveRejectsStoreOrderAlreadyReserved`, `testReleaseRejectsReservationWithMissingMaterial`,
  `testReleaseRejectsReservationWithMissingStock` (documents `inventory-controllers-service` Bug #4),
  `testReserveRejectsEmptyItemList`, `testReserveRejectsDuplicateLineIds`.
- `StockControllerTest` (all 8) and `RecipeControllerTest` (6 of 7): real HTTP-status/payload-validation
  contracts with exact error messages; the HTTP layer in `InventoryMessagingAndApiTest` only asserts status
  codes, so the unit assertions are not redundant.
- `SpecificationRecipeServiceTest` (both): duplicate-specification and missing/inactive-material guards
  against the real DB.
- `InventoryDomainEntityTest`, `MaterialTest`, `QuantityTest`: original entity/quantity unit behavior.

## DELETE CANDIDATES

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `Entity/InventoryEntityCoverageTest::testReservationExposesNullDatabaseIdBeforePersist` | B1 COVERAGE-CHASING — pure getter passthrough + `getId()===null` on an object never persisted; tautological | HIGH | `Entity/InventoryDomainEntityTest::testReservationAndRecipeStateTransitions` already asserts `getUuid()/getStoreUuid()/getTradeOrderUuid()/getStoreOrderUuid()/getRequestHash()/getLines()` with identical values |
| `Controller/Manage/RecipeControllerTest::testCreateHooksUsedByCreateModeReturnPassThroughValues` | B1 COVERAGE-CHASING — reflection-invokes three trivial pass-through hooks (`defaultCreateValues`, `processCreateContent`, `afterCreated`) that are unreachable in this controller's own flow; created solely to cover lines 35/44/49 | HIGH | none (behavior is a no-op passthrough; `inventory-controllers-service.md` admits reflection is the only way to exercise it) |
| `Repository/RecipeLineRepositoryTest::testRepositoryIsInstantiatedByTheContainer` | B1 COVERAGE-CHASING — asserts container instantiation + `getClassName()` of a `ServiceEntityRepository` subclass whose only code is `parent::__construct()` (line 16) | HIGH | the `find*` assertions in the rest of the file / integration tests already instantiate it; the constructor line is infrastructure, not behavior |
| `Repository/ReservationLineRepositoryTest::testRepositoryIsInstantiatedByTheContainer` | B1 COVERAGE-CHASING — identical situation for the mirror repository | HIGH | same as above |
| `Repository/RecipeLineRepositoryTest::testFindAllReturnsPersistedLines`, `::testFindByIdReturnsLineAndUnknownIdReturnsNull`, `::testRemovePersistedLine` | B1 COVERAGE-CHASING — stock inherited Doctrine CRUD (`findAll`/`find`/`remove`) on a repository with no custom query methods; nothing project-specific is asserted | MEDIUM | RecipeLine mapping (quantity/sort/recipe) is already exercised by `InventoryServiceTest::testRecipeReservationAggregatesMaterialDemandAndReleaseIsIdempotent`, `InventoryServiceCoverageTest::testReserveRejectsInactiveRecipeMaterial`, `SpecificationRecipeServiceTest`, and the handler integration tests |
| `Repository/ReservationLineRepositoryTest::testFindAllAndFindByMaterialReturnPersistedLines`, `::testFindByIdReturnsLineAndUnknownIdReturnsNull`, `::testRemovePersistedLine` | B2 DUPLICATE — near-identical mirror of `RecipeLineRepositoryTest` (task-flagged pair), same inherited-Doctrine coverage; plus B1 | MEDIUM | ReservationLine mapping asserted via `InventoryServiceTest`/`InventoryServiceCoverageTest`/`InventoryReservationRequestedHandlerIntegrationTest` flows |
| `Integration/InventoryServiceCoverageTest::testAdjustStockIsIdempotentForRepeatedReference` | B2 DUPLICATE — same reference-id idempotency through `adjustStock` | MEDIUM | `InventoryServiceTest::testStockPoliciesAndAdjustmentIdempotencyAreScopedToStore` (adjust with `'same-reference'` returns unchanged balance) |
| `Integration/InventoryServiceCoverageTest::testReserveRejectsInactiveRecipeMaterial` | B2 DUPLICATE — identical persisted outcome (`status='rejected'`, `rejectionCode='MATERIAL_INACTIVE'`) | MEDIUM | `MessageHandler/InventoryReservationRequestedHandlerIntegrationTest::testRecipeResolutionFailureRejectsReservation` (same two asserts through the handler) — keep ONE layer (see note) |
| `MessageHandler/InventoryReservationRequestedHandlerIntegrationTest::testRecipeResolutionFailureRejectsReservation`, `::testMissingSpecificationRejectsReservation` | B2 DUPLICATE — handler-layer re-test of service outcomes | MEDIUM | `Integration/InventoryServiceCoverageTest::testReserveRejectsInactiveRecipeMaterial` (MATERIAL_INACTIVE) and `Integration/InventoryServiceTest::testReservationRejectsExpiredAndUnstockableRequests` (SPECIFICATION_NOT_STOCKABLE) assert the identical `getStatus()`/`getRejectionCode()` |
| `MessageHandler/InventoryReservationReleaseRequestedHandlerIntegrationTest::testReleaseForUnknownReservationThrows` | B2 DUPLICATE — unknown-reservation release failure | MEDIUM | `Integration/InventoryServiceTest::testUnknownReservationAndStockViewsFailExplicitly` (release of unknown id throws `InvalidArgumentException`); handler wiring for release already covered by the other two tests in the file |
| `Service/QuantityCoverageTest::testRejectsLeadingZeroVariantsThroughNormalize` | B1 COVERAGE-CHASING — 2 of 3 asserts are identity assertions (`'-0.500000'→'-0.500000'`, `'0.000000'→'0.000000'`); only the `'+00000012.34'` leading-zero case adds value | MEDIUM | `Service/QuantityTest::testNormalizesAndAddsSignedQuantities` already asserts `normalize('00012.34')==='12.340000'` (same padding/trim branch) |
| `Message/InventoryMessageTest::testMessagesExposeTheirEnvelope` | B1 COVERAGE-CHASING — one test asserting three message classes store-and-return the constructor arg (tautological) | MEDIUM | message classes are already constructed with real envelopes in `Command/PublishOutboxCommandTest` and the handler unit/integration tests |

Note on the two MATERIAL_INACTIVE tests (`InventoryServiceCoverageTest` vs request-handler integration):
they assert byte-identical outcomes, so only one layer is needed. Prefer keeping the **service-level** test
(`testReserveRejectsInactiveRecipeMaterial`, more direct) and deleting the handler-layer duplicates, since
`InventoryMessagingAndApiTest::testRequestedAndReleasedMessagesAreIdempotent` already proves the handler
delivers request → reservation persistence.

## MERGE SUGGESTIONS

| Merge | Rationale |
|---|---|
| `Entity/InventoryEntityCoverageTest` → into `Entity/InventoryDomainEntityTest` + `Entity/MaterialTest` | Delete the file; `testStockRejectsDisablingNegativePolicyWhileBalanceNegative` (entity-level negative-policy guard) belongs in `InventoryDomainEntityTest` next to its stock-lifecycle test; `testMaterialSettersRejectBlankRequiredFields` (blank code/name/unit validation) belongs in `MaterialTest`. |
| `Service/QuantityCoverageTest` → into `Service/QuantityTest` | Same target class; keep `testRejectsMalformedQuantityStrings`, `testRejectsNonPositiveQuantityWhenPositiveRequired`, `testSmallMultiplicationsKeepExactScale` inside `QuantityTest` (table-driven, per `TEST_STRATEGY.md` maintenance rule) and drop the standalone coverage file. |
| `Integration/InventoryServiceCoverageTest` → fold into `Integration/InventoryServiceTest` | Twin files for the same service (task-flagged pair). Consolidate to a single owning integration test; keep every non-duplicated failure/idempotency test. |
| `Repository/RecipeLineRepositoryTest` + `Repository/ReservationLineRepositoryTest` → one merged `LineRepositoryTest` (or delete both) | Both repositories contain zero custom query logic (only a constructor). If kept, merge into a single file and keep only the mapping-relevant assertions (`findOneByMaterial`/`findByRecipe` for RecipeLine; `findByReservation` for ReservationLine); drop findAll/find/remove noise. |
| `Command/PublishOutboxCommandTest` → merge `testPublishesConfirmedMessageAndMarksPublished`, `testPublishesRejectedMessageAndMarksPublished`, `testPublishesReleasedMessageAndMarksPublished` into one table-driven test | Three near-identical per-topic tests for the `match()` mapping; the published-vs-deferred outcome is already asserted at integration level by `InventoryMessagingAndApiTest::testOutboxPublisherMarksKnownTopicPublished` / `::testOutboxPublisherMapsEveryInventoryOutcome`. |
| `MessageHandler/InventoryReservationRequestedHandlerTest` → merge `testRejectsTimestampThatDoesNotMatchIso8601`, `testRejectsImpossibleCalendarDate`, `testRejectsShortFractionalTimestampWithImpossibleDate`, `testRejectsExpiryBeforeOrEqualToRequestTime` into one table-driven timestamp test | Same guard (`parseDate` + expiry order) with four near-identical setups; `TEST_STRATEGY.md` prefers table-driven tests for a rule with many equivalent inputs. |

## Verification steps

If the DELETE CANDIDATES are removed (or merged per the suggestions):

1. Re-run the Inventory suite and confirm the count drops from 109 by the number of deleted tests and that
   the suite stays green:
   ```bash
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Inventory
   ```
   (Run integration files individually or in small groups if transient SQLite `database is locked` /
   `no such table` flakes appear — documented in the coverage reports.)
2. Confirm the deleted behavior still passes through the surviving covering test listed in the
   "Covered by" column (run that test file specifically).
3. For the repository/controller deletions, confirm no new uncovered lines are introduced in
   `src/Inventory/` beyond the known trivial constructor/pass-through lines that the audit deems
   infrastructure rather than behavior:
   ```bash
   XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --coverage-html var/coverage tests/Inventory
   ```
4. Confirm `composer phpstan` and `composer rector:types:check` still pass (test deletions should not
   affect them).
5. Do not delete any reservation/stock/ledger behavior or handler idempotency test; those are KEEP per
   `BUSINESS_INVARIANTS.md`.
