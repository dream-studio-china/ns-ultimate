# Trade (core: entity/service/workflow/repo/command/listener) test audit (2026-08-09)

Read-only audit of `tests/Trade/Entity`, `tests/Trade/Service`, `tests/Trade/Service/Pricing`,
`tests/Trade/Pricing`, `tests/Trade/Workflow`, `tests/Trade/Repository`,
`tests/Trade/Command`, and `tests/Trade/EventListener`. Cross-read against
`src/Trade/` and the integration suite (`tests/Trade/Integration/OrderWorkflowApiTest.php`,
`TradeRepoFullTest.php`, `TradeRepositoryIntegrationTest.php`).

All findings assume the files flagged for deletion are removed AFTER confirming zero
coverage delta on `src/Trade/` (see Verification steps). Skipped tests that document known
bugs (`docs/issues/coverage-2026-08-09/README.md`) are KEEP.

## Summary

| File | Tests | Verdict |
|---|---|---|
| tests/Trade/Entity/OrderTest.php | 18 | KEEP core (constructor defaults, money/currency, collections, lifecycle); DELETE 8 trivial accessor/constant tests |
| tests/Trade/Entity/OrderItemTest.php | 12 | KEEP snapshot/price/quantity logic; DELETE ~4 trivial setter/fluency tests + 1 duplicate |
| tests/Trade/Entity/ProductTest.php | 11 | KEEP status validation + collection/lifecycle; DELETE ~5 trivial accessor tests |
| tests/Trade/Entity/SpecificationTest.php | 11 | KEEP price/status validation; DELETE ~6 trivial accessor tests |
| tests/Trade/Entity/TradeOutboxMessageTest.php | 5 | DELETE candidates: 4/5 trivial accessors; keep availableAt==occurredAt invariant |
| tests/Trade/Service/OrderServiceTest.php | 13 | KEEP (core pricing pipeline + pay/refund/fulfill behavior) |
| tests/Trade/Service/OrderServicePaymentsTest.php | 28 | KEEP payment/refund/createOrder orchestration + store-submit; DELETE ~6 defensive-guard/impl tests |
| tests/Trade/Service/Pricing/PriceCalculationResultTest.php | 5 | MERGE candidate (trivial DTO, duplicated by PricingTest) |
| tests/Trade/Pricing/PricingTest.php | 17 | KEEP calculator/pipeline logic; DELETE ~5 trivial context/result tests |
| tests/Trade/Workflow/OrderWorkflowStateMachineTest.php | 26 | KEEP transition matrix + guard-absence probes; DELETE ~13 duplicate chain/timestamp tests |
| tests/Trade/Repository/ProductRepositoryTest.php | 2 | DELETE file (trivial `find()` delegation; real repo logic covered in integration) |
| tests/Trade/Repository/SpecificationRepositoryTest.php | 4 | KEEP `findByIdForUpdate` (lock SQL); DELETE `findById` trivial pair |
| tests/Trade/Repository/TradeOutboxMessageRepositoryTest.php | 13 | KEEP (real-DB outbox semantics + command; 2 skipped bug-doc tests) |
| tests/Trade/Command/PublishOutboxCommandTest.php | 8 | MERGE/DUPLICATE candidates vs TradeOutboxMessageRepositoryTest |
| tests/Trade/EventListener/OrderWorkflowListenerTest.php | 15 | KEEP outbox-recording branch tests; timestamp tests duplicate state-machine file |
| tests/Trade/EventListener/OrderInvoiceListenerTest.php | 21 | KEEP (core invoice→order propagation; skipped payer bug test) |

## KEEP

- **Pricing & snapshot behavior** — `OrderServiceTest::testPricingCalculations` (5-case DP),
  `testCalculatePricesDelegatesToPipeline/WithCustomCurrency/WithEmptyItems`;
  `OrderItemTest::testPrePersist*` (snapshot population + price calc);
  `PricingTest` calculator tests (`BasePriceCalculator` not-found/deleted/inactive/
  product-unavailable throws, default quantity, `QuantityCalculator`, `TotalAggregator`,
  `PipelineExecutionOrder`, `PipelineHandlesMultipleItemsWithDifferentPrices`).
- **Money movement** — `OrderServiceTest::testPayTransfersFromUserWalletAndMarksPayment`,
  `testRefundTransfersToUserWalletAndMarksRefund`, status guards
  (`testPayRejectsNonConfirmedOrder`, `testRefundRejectsNonCompletedOrder`,
  `testFulfillRejectsNonPaidOrder`), `testFulfillStoresShippingData`.
- **Payment orchestration** — `OrderServicePaymentsTest`: `createOrder` store-submit/outbox
  path, `createPayment` (reuse/new-invoice), `refundPayment` (remaining amount),
  `cancel` variants, and `testCreatePaymentReusesInvoiceRegardlessOfItsStatus`
  (passes today, pins known Bug #1). `testCreatePaymentCreatesFreshInvoiceWhenExistingInvoiceIsNotPayable` (skipped) = KEEP.
- **Workflow matrix + guard findings** — `OrderWorkflowStateMachineTest`:
  `testEnabledTransitionsMatchWorkflowConfig` (all 11 states),
  `testApplyingValidTransitionMovesToTargetState` (15), `testApplyingInvalidTransitionThrowsAndLeavesStateUnchanged`
  (~40), unknown-transition/`can()` probes, `testNoTransitionInWorkflowConfigDeclaresAGuard`
  and the three `testWorkflowLayerAllows*` probes (document Bug #68: no guards in workflow.yaml),
  `testOrderEntityExposesMarkingViaGetStatusSetStatus`.
- **Outbox repository (real DB)** — `TradeOutboxMessageRepositoryTest`: `findUnpublished`
  (filtering/order/limit), `claim` (double-claim, published, future `availableAt`, unknown id),
  `defer` (attempts/lastError/availableAt, accumulate, already-published), the two
  `testPublishOutboxCommand*AgainstRealDatabase` command tests, and the two skipped
  `markTestSkipped` bug-doc tests (Bug #1 no max-attempts, Bug #2 metadata not cleared).
- **Listeners** — `OrderInvoiceListenerTest` (all 21, incl. `testPaidIgnoresInvoicePaidByADifferentPayer`
  skipped Bug #22, amount/currency mismatch guards, refunded/cancelled/failed propagation);
  `OrderWorkflowListenerTest::testCancelTransitionRecordsOutboxMessageWhenStoreMetadataPresent`
  and the three store-metadata skip variants + `testNonCancelTransitionDoesNotRecordOutboxEvenWithStoreMetadata`
  (unique outbox-recording branches).
- **Entity domain rules** — `OrderTest::testCurrencyIsUpperCase` (strtoupper),
  `testOrderItemRelationships`/`testAddOrderItemDoesNotDuplicate`,
  `testPrePersist`/`testTouch`; `ProductTest::testStatusValidation`,
  `testSpecificationRelationships`/`testAddSpecificationDoesNotDuplicate`,
  `testPrePersistInitializesCreatedAtWhenConstructorWasSkipped`/`KeepsCreatedAtWhenAlreadySet`;
  `SpecificationTest::testPriceValidation`/`testStatusValidation`;
  `OrderItemTest::testQuantityValidation`, `testCostAndProfit`.
- **Repository locking** — `SpecificationRepositoryTest::testFindByIdForUpdateReturnsSpecification`
  and `ReturnsNullForUnknownId` (pessimistic-write SQL is real, DB-backed behavior).

## DELETE CANDIDATES

Reason codes: 1=COVERAGE-CHASING, 2=DUPLICATE, 3=IMPLEMENTATION-DETAIL, 4=REDUNDANT-REGRESSION, 5=NEAR-EMPTY.

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| TradeOutboxMessageTest::testGetIdReturnsInjectedId | 1 (reflection on trivial `getId()`) | High | — |
| TradeOutboxMessageTest::testMarkPublishedCanBeCalledTwice | 1 (idempotency of a one-line setter, no logic) | High | TradeOutboxMessageTest::testMarkPublishedSetsPublishedAt |
| TradeOutboxMessageTest::testConstructorInitializesFields | 1 (pure constructor/accessor round-trip) | Medium | TradeOutboxMessageTest::testConstructorMakesAvailableAtEqualToOccurredAt |
| TradeOutboxMessageTest::testMarkPublishedSetsPublishedAt | 2 | Medium | TradeOutboxMessageRepositoryTest::testPublishOutboxCommandRunsAgainstRealDatabase (asserts `getPublishedAt()` non-null) |
| OrderTest::testStatusConstants | 1 (tautological `CONST === 'draft'` etc., no behavior) | High | — |
| OrderTest::testNotes / testMetadata / testPaymentMethod / testTrackingNumber / testShippingAddress / testRefundReason | 1 (pure setter/getter round-trips) | Medium | OrderTest::testConstructorInitializesCoreFields (null defaults) |
| OrderTest::testTimestamps / testNewTimestamps | 1 (trivial DateTime set/reset round-trips) | Medium | — |
| OrderTest::testToString | 1 (formatting only, no branch) | Low | — |
| OrderItemTest::testQuantityCannotBeNegative | 2 (same guard as sibling) | High | OrderItemTest::testQuantityValidation (both assert `InvalidArgumentException` on `setQuantity` < 1) |
| OrderItemTest::testSetProfitDirectly / testMetadata | 1 (trivial accessors) | Medium | — |
| OrderItemTest::testSettersAreFluent | 3 (fluent-chain implementation detail) | Medium | — |
| OrderItemTest::testToString | 1 | Low | — |
| ProductTest::testSettersAreFluentAndTouchTimestamp | 3 (fluency + incidental touch) | Medium | ProductTest::testConstructorInitializesCoreFields |
| ProductTest::testDescriptionCanBeNull / testIsDeleted / testMetadata | 1 (trivial accessors) | Medium | — |
| ProductTest::testToString | 1 | Low | — |
| SpecificationTest::testSort / testIsDeleted / testProductAssociation / testTouch | 1 (trivial accessors; `touch` covered by Order/Product tests) | Medium | — |
| SpecificationTest::testSettersAreFluent | 3 | Medium | — |
| SpecificationTest::testToString / testToStringWithNullProduct | 1 | Low | — |
| PriceCalculationResultTest (whole file: constructor, meta default, 3× fromContext) | 2 | Medium | PricingTest::testResultConstructor, PricingTest::testResultFromContext; DTO has no logic (src/Trade/Service/Pricing/PriceCalculationResult.php is a value object) |
| PricingTest::testContextInitializesCorrectly / testContextDefaultCurrency / testContextMalleableState | 1 (plain public-property DTO init) | Medium | — |
| PricingTest::testResultFromContext / testResultConstructor | 2 | Medium | PriceCalculationResultTest::testFromContextCopiesMeta + testConstructorInitializesFields |
| OrderServicePaymentsTest::testPayRejectsUserWithoutPersistedId / testPayRejectsWalletWithoutPersistedId / testRefundRejectsUserWithoutPersistedId / testRefundRejectsWalletWithoutPersistedId | 1 (defensive "no persisted ID" guards, unreachable via normal create→persist flow) | Medium | — |
| OrderServicePaymentsTest::testCalculatePricesAcceptsTraversableCalculators | 3 (`ArrayIterator` vs array is an impl detail of `getSortedCalculators()`) | Medium | OrderServiceTest::testCalculatePricesWithEmptyItems |
| OrderServicePaymentsTest::testCreateOrderWithStoreContextThrowsWhenWorkflowMissing / WhenOutboxMissing | 1 (defensive wiring guards) | Low | — |
| OrderWorkflowStateMachineTest::testHappyPathChainDraftToRefunded | 2 | Medium | OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions (same chain, asserts statuses + timestamps E2E) |
| OrderWorkflowStateMachineTest::testStoreBranchChainDraftToCancelledViaReject | 2 | Medium | OrderWorkflowApiTest::testStoreBranchRejectThenCancelViaDoTransitions |
| OrderWorkflowStateMachineTest::testStoreBranchChainAwaitingAcceptToConfirmed | 2 | Medium | OrderWorkflowApiTest::testStoreBranchAcceptThenConfirmViaDoTransitions |
| OrderWorkflowStateMachineTest::testDuplicateSubmitTransitionIsRejected | 2 | Medium | OrderWorkflowApiTest::testDuplicateSubmitViaDoIsRejectedAndStateUnchanged |
| OrderWorkflowStateMachineTest::testCancelIsRejectedFromPaid | 2 | Medium | OrderWorkflowApiTest::testCancelIsRejectedAfterPaid |
| OrderWorkflowStateMachineTest::testApplyingUnknownTransitionThrowsUndefinedTransitionException | 2 | Low | OrderWorkflowApiTest::testUnknownTransitionViaDoIsRejected |
| OrderWorkflowStateMachineTest::testPayTransitionSetsPaidAtWhenNull | 2 | High | OrderWorkflowListenerTest::testPayTransitionSetsPaidAt |
| OrderWorkflowStateMachineTest::testPayTransitionPreservesExistingPaidAt | 2 (identical name/assertion) | High | OrderWorkflowListenerTest::testPayTransitionPreservesExistingPaidAt |
| OrderWorkflowStateMachineTest::testFulfillTransitionSetsFulfilledAtWhenNull | 2 | High | OrderWorkflowListenerTest::testFulfillTransitionSetsFulfilledAt |
| OrderWorkflowStateMachineTest::testCompleteTransitionSetsCompletedAt | 2 | High | OrderWorkflowListenerTest::testCompleteTransitionSetsCompletedAt |
| OrderWorkflowStateMachineTest::testCancelTransitionSetsCancelledAt | 2 | High | OrderWorkflowListenerTest::testCancelTransitionSetsCancelledAt |
| OrderWorkflowStateMachineTest::testRefundTransitionSetsRefundedAtWhenNull | 2 | High | OrderWorkflowListenerTest::testRefundTransitionSetsRefundedAt |
| OrderWorkflowStateMachineTest::testSubmitConfirmAndStoreTransitionsDoNotSetTimestamps | 2 | Medium | OrderWorkflowListenerTest::testSubmitTransitionDoesNotThrow + testNonCancelTransitionDoesNotRecordOutboxEvenWithStoreMetadata |
| OrderWorkflowStateMachineTest::testStoreRejectDoesNotSetTimestamp | 2 | Low | OrderWorkflowListenerTest::testSubmitTransitionDoesNotThrow (same no-op branch) |
| ProductRepositoryTest (whole file) | 1+3 (`findById` is one-line `find()` delegation; null case trivial) | Medium | TradeRepoFullTest::testProductRepoFindNotDeletedAndFindActive, TradeRepositoryIntegrationTest::testProductRepositoryFindNotDeleted/FindDeleted — the repo's actual logic (`findNotDeleted`/`findActive`) is already covered there |
| SpecificationRepositoryTest::testFindByIdReturnsSpecification / testFindByIdReturnsNullForUnknownId | 1 (trivial `find()` delegation) | Medium | TradeRepoFullTest::testSpecRepoFindByProductAndFindActiveByProduct |
| PublishOutboxCommandTest::testExecuteDefersWhenDispatchThrows | 2 | High | TradeOutboxMessageRepositoryTest::testPublishOutboxCommandDefersOnDispatchFailureAgainstRealDatabase (same failure→defer, asserted on persisted row) |
| PublishOutboxCommandTest::testExecuteDefersUnsupportedTopic | 2 | Medium | TradeOutboxMessageRepositoryTest::testPublishOutboxCommandRunsAgainstRealDatabase (unsupported topic → defer + lastError, persisted) |
| PublishOutboxCommandTest::testExecutePublishesCancelledMessage | 2 | Medium | TradeOutboxMessageRepositoryTest::testPublishOutboxCommandRunsAgainstRealDatabase (cancelled dispatch + publishedAt) |
| PublishOutboxCommandTest::testExecuteHandlesMixedMessagesInSingleRun | 2 | Medium | TradeOutboxMessageRepositoryTest::testPublishOutboxCommandRunsAgainstRealDatabase (created+cancelled+unsupported in one run) |
| PublishOutboxCommandTest::testExecutePublishesNothingWhenNoMessages | 1 (mock-only empty path) | Medium | — |
| PublishOutboxCommandTest::testExecuteSkipsMessageWhenClaimFails / testExecuteSkipsMessageWhenIdIsNull | 1 (defensive branches) | Medium | TradeOutboxMessageRepositoryTest::testClaim* (claim semantics already covered on real DB) |

Note on `OrderWorkflowStateMachineTest` timestamp tests (rows 27–36): either file may be kept,
but the timestamp behavior is asserted identically in `OrderWorkflowListenerTest`. Recommendation:
keep `OrderWorkflowStateMachineTest`'s transition matrix/guard probes and delete its Section-5
timestamp duplicates; wiring is still verified by the happy-path chain. Keep the outbox-recording
tests in `OrderWorkflowListenerTest` (unique). Do NOT delete both copies.

Note on `PublishOutboxCommandTest::testExecutePublishesCreatedMessageWithFullEnvelope`: it asserts
the full envelope (eventId/type/version/occurredAt/correlationId) which the integration test only
partially checks; before deleting, fold those envelope-field assertions into
`TradeOutboxMessageRepositoryTest::testPublishOutboxCommandRunsAgainstRealDatabase`.

## MERGE SUGGESTIONS

1. **`OrderServicePaymentsTest.php` → `OrderServiceTest.php`** — both test `src/Trade/Service/OrderService.php`
   with the same reflection-based construction helper. The split was campaign-artificial (the file
   header even says "branches not exercised by OrderServiceTest"). Consolidate into one file.
2. **`PriceCalculationResultTest.php` → `tests/Trade/Pricing/PricingTest.php`** — the result DTO
   is a value object (`PriceCalculationResult::fromContext` is a plain copy); keep the meta-copying
   assertions, drop the duplicated constructor assertions.
3. **`PublishOutboxCommandTest.php` → `TradeOutboxMessageRepositoryTest.php`** — the mock-only command
   tests duplicate the real-DB command tests; fold the envelope-field assertions into the integration
   test and delete the mock file.
4. **`OrderWorkflowStateMachineTest` Section-5 timestamp tests ↔ `OrderWorkflowListenerTest`** —
   keep one layer for timestamp side effects (see note above).

## Verification steps

Run from the repo root. Read-only for `src/` and `tests/`; only the report file is written.

```bash
PHP=/opt/homebrew/opt/php@8.5/bin/php
# 1. Baseline: full suite + Trade coverage BEFORE any deletion
XDEBUG_MODE=coverage "$PHP" ./vendor/bin/phpunit --coverage-html var/coverage-before-trade-audit
# 2. Record Trade-only baseline numbers
"$PHP" ./vendor/bin/phpunit tests/Trade > /tmp/trade-suite-before.txt

# 3. AFTER deleting the flagged methods/files, re-run the same two commands.
#    Gate: tests/Trade/ 100% green; trade assertion count drops; no failure appears.
#    Gate: src/Trade coverage is ZERO-delta vs baseline (the flagged tests must not be
#    the only coverers of any src/Trade line):
XDEBUG_MODE=coverage "$PHP" ./vendor/bin/phpunit --coverage-filter src/Trade \
  --coverage-html var/coverage-after-trade-audit

# 4. Per-file sanity for the duplicates cited as HIGH confidence:
"$PHP" ./vendor/bin/phpunit tests/Trade/Workflow tests/Trade/EventListener \
  tests/Trade/Command tests/Trade/Repository tests/Trade/Entity tests/Trade/Service tests/Trade/Pricing
# 5. Full serial suite on the CI database flavour (PostgreSQL) to prove no ordering leak:
"$PHP" ./vendor/bin/phpunit
```

Compare `var/coverage-before-trade-audit` vs `var/coverage-after-trade-audit` for `src/Trade`
(expect identical line/method numbers). If a `src/Trade` line loses coverage, that test is NOT
redundant — restore it.
