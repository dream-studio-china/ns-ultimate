# Trade (controllers/integration/handlers) test audit (2026-08-09)

Read-only audit of `tests/Trade/Controller/`, `tests/Trade/Integration/`, `tests/Trade/MessageHandler/`
(13 test files + 1 support fake, 191 test methods). Goal: identify UNNECESSARY / REDUNDANT tests
that are candidates for later deletion. Nothing under `src/` or `tests/` was modified.

Context used: `TEST_STRATEGY.md` ("one behaviour should normally have one primary layer",
"Do not add controller tests merely to raise line coverage"), `BUSINESS_INVARIANTS.md`
regression rule (assert public or persisted outcome), and the 2026-08-09 coverage campaign
reports (skipped tests that document bugs are KEEP, but the same bug asserted in several
places is redundant).

## Summary — File | Tests | Verdict

| File | Tests | Verdict |
|---|---|---|
| `tests/Trade/Integration/TradeApiIntegrationTest.php` | 76 | **REDUCE** — 26 redundant workflow/cancel/pay/fulfill/refund/transitions/todo tests duplicate `OrderWorkflowApiTest` (and itself); keep only unique endpoint coverage |
| `tests/Trade/Integration/OrderWorkflowApiTest.php` | 33 | **KEEP** — canonical workflow HTTP coverage (canonical full flow, data-driven cancel/transitions/todo); 1 redundant skipped tamper twin |
| `tests/Trade/Integration/TradePaymentIntegrationTest.php` | 4 | **REDUCE (1)** — `testAppOrderTransitionFailures` fully duplicated; app submit/confirm re-asserted (merge) |
| `tests/Trade/Integration/TradeOrderCancelWithInvoiceIntegrationTest.php` | 4 | **KEEP** — order↔invoice cancel propagation is unique; 2 marginal listener-path tests (low/med candidates) |
| `tests/Trade/Integration/TradeRepoFullTest.php` | 4 | **KEEP** — surviving repository-coverage file (strongest assertions) |
| `tests/Trade/Integration/TradeRepositoryIntegrationTest.php` | 4 | **DELETE FILE** — every method duplicated by `TradeRepoFullTest` with stronger assertions |
| `tests/Trade/Integration/TradeStoreOrderEventTest.php` | 1 | **KEEP** — unique outbox lineId invariant |
| `tests/Trade/Controller/App/OrderControllerTest.php` | 16 | **KEEP** — fast unit branches integration cannot reach; skipped bug test (Bug 1) kept |
| `tests/Trade/Controller/Manage/OrderControllerTest.php` | 26 | **REDUCE** — 9× near-identical 404 unit tests (merge), 5 guard-message duplicates of integration, 1 redundant skipped tamper twin |
| `tests/Trade/Controller/Manage/OrderItemControllerTest.php` | 4 | **REDUCE (1)** — `testRepositoryFindByOrder` is a repository test duplicated by `TradeRepoFullTest` |
| `tests/Trade/Controller/Manage/SpecificationAllControllerTest.php` | 4 | **KEEP** — only coverage of the standalone `/manage/specifications` resource |
| `tests/Trade/MessageHandler/StoreOrderAcceptedHandlerTest.php` | 8 | **KEEP** — cheap unit tests for two distinct handlers |
| `tests/Trade/MessageHandler/StoreOrderRejectedHandlerTest.php` | 7 | **KEEP** — incl. Bug-14 behavior pin |

## KEEP

Everything not listed in the DELETE candidates table, including:

- **All skipped tests documenting known bugs** (do not delete): `OrderWorkflowApiTest::testDoTransitionMustNotForwardArbitraryBodyFieldsToUpdate`,
  `App/OrderControllerTest::testSubmitActionReturnsWarningWhenTransitionFails`,
  `Manage/OrderControllerTest::testDoTransitionDoesNotForwardArbitraryFieldsToUpdate`
  (keep exactly ONE of the two tamper twins — see DELETE table) plus out-of-scope skips.
- **`OrderWorkflowApiTest`** — the authoritative HTTP layer for the order state machine:
  data-driven cancel-from-every-state, per-state `/transitions` listing, `/todo` semantics,
  wallet-transfer pay/refund success, guard rejections with exact messages. This is the
  NAME-covering survivor for the Trade API workflow tests.
- **`TradeRepoFullTest`** — survivor for repository queries (`findNotDeleted`/`findActive`,
  `findByProduct`/`findActiveByProduct`, `findById`, `findByUser`, `findByOrder`) with
  precise inclusion/exclusion assertions.
- **`TradeStoreOrderEventTest::testDuplicateSpecificationItemsUseDistinctOrderLineIds`**
  — unique invariant (distinct `lineId` for duplicate lines in one outbox event).
- **`TradePaymentIntegrationTest::testOrderPaymentAndRefundThroughInvoiceEvents`**
  — the only full lifecycle through a REAL invoice + `OrderInvoiceListener` (mock
  autoPaid → refund via invoice events); also pins the documented "refundAction skips the
  workflow transition is not a bug" decision.
- **`TradeOrderCancelWithInvoiceIntegrationTest::testManageCancelOrderCancelsLinkedInvoiceAndReleasesDeduction`**
  — unique: order cancel → linked invoice cancelled + wallet deduction released.
- **`TradeApiIntegrationTest`** unique endpoints (surviving after the redundant workflow
  tests are removed): product/specification CRUD (nested `/manage/products/{id}/specifications`),
  batch-update modes, pagination, app product inactive/deleted filters, order-items
  subresource (manage + app ownership 404), `testManageOrderCreateWithUserId`,
  `testOrderItemSnapshotsAreCreated`, app order metadata/receiver round-trip.
- **`App/OrderControllerTest`** and the non-listed `Manage/OrderControllerTest` methods —
  fast unit tests covering controller branches (price-engine throw, transaction-closure
  throws, cross-user ownership, payment-method forwarding) that integration cannot reach.

## DELETE CANDIDATES — File::method | Reason | Confidence | Covered by

Reason codes: 1=COVERAGE-CHASING, 2=DUPLICATE, 3=IMPLEMENTATION-DETAIL, 4=REDUNDANT-REGRESSION, 5=NEAR-EMPTY.

### `tests/Trade/Integration/TradeApiIntegrationTest.php` (26)

All duplicate tests run the full kernel+DB bootstrap (≈6.4 s file) to re-assert behaviour
that `OrderWorkflowApiTest` (surviving) asserts more precisely (exact status codes +
messages + state unchanged).

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `TradeApiIntegrationTest::testOrderWorkflowFullFlow` | 2 | HIGH | `OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions` |
| `TradeApiIntegrationTest::testOrderWorkflowCompleteFullFlow` | 2 | HIGH | `OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions` (asserts `completedAt`) |
| `TradeApiIntegrationTest::testWorkflowCompleteFullFlowTimestamps` | 2 | HIGH | `OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions` (asserts `paidAt`/`fulfilledAt`/`completedAt`/`refundedAt`) |
| `TradeApiIntegrationTest::testOrderRefundFromCompleted` | 2 | HIGH | `OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions` (chain includes `refund` → `refunded`) |
| `TradeApiIntegrationTest::testWorkflowRefundSetsRefundedAt` | 2 | HIGH | `OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions` |
| `TradeApiIntegrationTest::testWorkflowCancelSetsCancelledAt` | 2 | HIGH | `OrderWorkflowApiTest::testCancelFromEveryCancellableState` (asserts `cancelledAt` for every state) |
| `TradeApiIntegrationTest::testOrderCancelFromDraft` | 2 | HIGH | `OrderWorkflowApiTest::testCancelFromEveryCancellableState` (draft case) |
| `TradeApiIntegrationTest::testOrderCancelFromPending` | 2 | HIGH | `OrderWorkflowApiTest::testCancelFromEveryCancellableState` (pending case) |
| `TradeApiIntegrationTest::testOrderCancelFromConfirmed` | 2 | HIGH | `OrderWorkflowApiTest::testCancelFromEveryCancellableState` (confirmed case) |
| `TradeApiIntegrationTest::testOrderCannotCancelAfterPaid` | 2 | HIGH | `OrderWorkflowApiTest::testCancelIsRejectedAfterPaid` (same 200 + non-zero code + state stays `paid`) |
| `TradeApiIntegrationTest::testOrderTransitionsEndpoint` | 2 | HIGH | `OrderWorkflowApiTest::testTransitionsEndpointListsEnabledTransitionsPerState` (data-driven, all 9 states) |
| `TradeApiIntegrationTest::testOrderTodoList` | 1/5 | HIGH | `OrderWorkflowApiTest::testTodoEndpointReturnsOnlyOrdersWithEnabledTransitions` (asserts precise membership; this test only asserts HTTP 200 + code 0) |
| `TradeApiIntegrationTest::testManageOrderFulfill` | 2 | HIGH | `OrderWorkflowApiTest::testFulfillEndpointSuccessStoresTrackingAndMarksFulfilled` |
| `TradeApiIntegrationTest::testManageOrderFulfillWithoutOptionalData` | 2 | MEDIUM | same-file twin `testManageOrderFulfill` + `OrderWorkflowApiTest::testFulfillEndpointSuccessStoresTrackingAndMarksFulfilled`; only delta = omitted optional fields |
| `TradeApiIntegrationTest::testManageOrderFulfillWrongStatus` | 2 | HIGH | `OrderWorkflowApiTest::testFulfillEndpointRejectsNonPaidOrder` (same 400) |
| `TradeApiIntegrationTest::testManageOrderPayRequiresSystemWallet` | 2 | HIGH | `OrderWorkflowApiTest::testPayEndpointRejectsOrderWithoutWallet` (both send a wallet id on an order with no user → same 400 abort) |
| `TradeApiIntegrationTest::testManageOrderPayMissingSystemWallet` | 2 | HIGH | `OrderWorkflowApiTest::testPayEndpointRequiresSystemWalletId` (same 400) |
| `TradeApiIntegrationTest::testManageOrderPayWrongStatus` | 2 | HIGH | `OrderWorkflowApiTest::testPayEndpointRejectsDraftOrder` (same 400) |
| `TradeApiIntegrationTest::testManageOrderRefundRequiresSystemWallet` | 2 | MEDIUM | `Manage/OrderControllerTest::testRefundActionReturnsErrorWhenSystemWalletIdMissing` (unit, same message) |
| `TradeApiIntegrationTest::testManageOrderRefundMissingReason` | 2 | HIGH | `OrderWorkflowApiTest::testRefundEndpointRequiresReason` (same `reason is required.` 400) |
| `TradeApiIntegrationTest::testManageOrderRefundWrongStatus` | 2 | HIGH | `OrderWorkflowApiTest::testRefundEndpointRejectsNonCompletedOrder` (same 400) |
| `TradeApiIntegrationTest::testAppOrderCancelWrongUser` | 2 | HIGH | `OrderWorkflowApiTest::testAppEndpointsHideOtherUsersOrders` (same 404) |
| `TradeApiIntegrationTest::testAppOrderCancelAfterPaidNotAllowed` | 2 | HIGH | `OrderWorkflowApiTest::testAppCancelAfterPaidIsRejected` (same 400 + message) |
| `TradeApiIntegrationTest::testNonDraftOrderCannotBeUpdated` | 2/4 | HIGH | same-file twin `testManageOrderCannotBeUpdatedAfterSubmit` (L830, identical scenario) |
| `TradeApiIntegrationTest::testOrderCannotRefundFromDraft` | 1 | MEDIUM | workflow layer: `OrderWorkflowStateMachineTest::testApplyingInvalidTransitionThrowsAndLeavesStateUnchanged` (`draft->refund` in `invalidTransitionProvider`); HTTP assert is weak (200 + `code != 0` only) |

> Note: `testAppOrderCancelFromDraft` is NOT listed — the app `/cancel` endpoint from draft
> is only exercised here (OrderWorkflowApi cancels app orders after confirm), so it stays.

### `tests/Trade/Integration/TradeRepositoryIntegrationTest.php` (4 — whole file)

Every method is covered by `TradeRepoFullTest`, which uses the same seed data style but
asserts inclusion/exclusion precisely instead of `assertNotEmpty()`/`assertIsArray()`.

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `TradeRepositoryIntegrationTest::testProductRepositoryFindNotDeleted` | 1/2 | HIGH | `TradeRepoFullTest::testProductRepoFindNotDeletedAndFindActive` |
| `TradeRepositoryIntegrationTest::testProductRepositoryFindDeleted` | 2 | HIGH | `TradeRepoFullTest::testProductRepoFindNotDeletedAndFindActive` (asserts deleted excluded) |
| `TradeRepositoryIntegrationTest::testSpecificationRepositoryFindByProduct` | 1/2 | HIGH | `TradeRepoFullTest::testSpecRepoFindByProductAndFindActiveByProduct` |
| `TradeRepositoryIntegrationTest::testOrderRepositoryFindByUser` | 1/2 | HIGH | `TradeRepoFullTest::testOrderRepoFindByUser` |

### `tests/Trade/Integration/TradePaymentIntegrationTest.php` (1)

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `TradePaymentIntegrationTest::testAppOrderTransitionFailures` | 2 | HIGH | `OrderWorkflowApiTest::testAppSubmitOnNotFoundOrderReturns404` + `testAppSubmitOnAlreadySubmittedOrderIsRejected` + `testAppEndpointsHideOtherUsersOrders` (each of the 4 scenarios maps 1:1) |

### `tests/Trade/Integration/TradeOrderCancelWithInvoiceIntegrationTest.php` (2)

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `TradeOrderCancelWithInvoiceIntegrationTest::testInvoiceCancelledEventUpdatesOrderPaymentStatus` | 2 | MEDIUM | unit `OrderInvoiceListenerTest::testCancelledUpdatesOrderPaymentStatus` + `tests/Integration/PaymentTradeIntegrationTest::testInvoiceCancellationForPendingInvoiceKeepsOrderConfirmed` |
| `TradeOrderCancelWithInvoiceIntegrationTest::testInvoiceMarkedFailedEventUpdatesOrderPaymentStatus` | 2 | MEDIUM | unit `OrderInvoiceListenerTest::testFailedUpdatesOrderPaymentStatus` + `tests/Integration/PaymentTradeIntegrationTest::testPaymentCannotBeRetriedAfterFailedNotify` |
| `TradeOrderCancelWithInvoiceIntegrationTest::testManageCancelOrderWithoutInvoiceStillWorks` | 1 | MEDIUM | `OrderWorkflowApiTest::testCancelFromEveryCancellableState`; only delta is asserting `invoiceId` stays null on a never-paid order |

### `tests/Trade/Controller/Manage/OrderControllerTest.php` (6)

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `OrderControllerTest::testDoTransitionDoesNotForwardArbitraryFieldsToUpdate` (skipped) | 4 | HIGH | `OrderWorkflowApiTest::testDoTransitionMustNotForwardArbitraryBodyFieldsToUpdate` (identical tamper regression, verbatim twins; keep the integration one per `BUSINESS_INVARIANTS` regression rule "assert the public or persisted outcome") |
| `OrderControllerTest::testPayActionReturnsErrorWhenCannotPay` | 2 | MEDIUM | `OrderWorkflowApiTest::testPayEndpointRejectsDraftOrder` (same `Order cannot be paid in current status.`) |
| `OrderControllerTest::testPaymentActionReturnsErrorWhenCannotPay` | 2 | MEDIUM | `OrderWorkflowApiTest::testPayEndpointRejectsDraftOrder` (same message via `/payment`) |
| `OrderControllerTest::testRefundActionReturnsErrorWhenReasonMissing` | 2 | MEDIUM | `OrderWorkflowApiTest::testRefundEndpointRequiresReason` (same `reason is required.`) |
| `OrderControllerTest::testUpdateActionReturnsErrorWhenNotDraft` | 2 | MEDIUM | `TradeApiIntegrationTest::testManageOrderCannotBeUpdatedAfterSubmit` (integration 400) |
| `OrderControllerTest::testDeleteActionReturnsErrorWhenNotDraft` | 2 | MEDIUM | `TradeApiIntegrationTest::testNonDraftOrderCannotBeDeleted` (integration 400) |

### `tests/Trade/Controller/Manage/OrderItemControllerTest.php` (1)

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `OrderItemControllerTest::testRepositoryFindByOrder` | 2/3 | HIGH | `TradeRepoFullTest::testOrderItemRepoFindByOrder` (identical `findByOrder`/`findById` assertions); also misplaced — a repository test inside a controller test class |

## MERGE SUGGESTIONS (note: merging reduces runtime)

1. **Consolidate the Trade API full-flow tests into ONE table-driven test.**
   `TradeApiIntegrationTest` re-runs create→submit→confirm→pay→fulfill→complete→refund up to 5×
   (and cancel 3×) with per-test kernel+DB bootstrap (~6.4 s file). Keep a single data-driven
   full-flow in `OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions` (already
   the strongest) and delete the 26 flagged `TradeApiIntegrationTest` tests — the file keeps
   only its unique product/spec/batch/app/items coverage. Expected runtime saving: roughly half
   of TradeApiIntegrationTest's ≈6.4 s, plus reduced DB contention on the shared `var/test.db`.

2. **Delete `TradeRepositoryIntegrationTest.php` and let `TradeRepoFullTest` remain the sole
   repository-coverage file.** Same seed style, stronger assertions. Saves a full
   `bootTestDatabase()` per class.

3. **Collapse the 9 near-identical not-found unit tests in `Manage/OrderControllerTest`
   (`testDeleteActionReturns404WhenOrderNotFound`, `testPayActionReturns404WhenOrderNotFound`,
   `testPaymentActionReturns404WhenOrderNotFound`, `testFulfillActionReturns404WhenOrderNotFound`,
   `testRefundActionReturns404WhenOrderNotFound`, `testTransitionsActionReturns404WhenOrderNotFound`,
   `testDoTransitionActionReturns404WhenOrderNotFound`, `testItemsActionReturns404WhenOrderNotFound`,
   `testUpdateActionReturns404WhenOrderNotFound`) into ONE data-driven test** keyed by
   (action, HTTP method, uri). These are ~9× boilerplate asserting the same
   `get() → null → 404 'Order not found.'` contract. (Runtime impact small — unit — but it
   removes ~90 duplicated lines.)

4. **`TradePaymentIntegrationTest::testAppUserCanSubmitConfirmAndPayOwnOrder`** re-asserts the
   app submit/confirm steps already covered by `OrderWorkflowApiTest::testAppUserSubmitConfirmAndCancelOwnOrder`;
   keep only the invoice-based pay step (autoPaid mock) as the unique delta, or fold it into the
   surviving app-flow test.

## Verification steps

Follow the strategy's mandatory commands and the 2026-08-09 report conventions:

1. Baseline: run the two biggest files and capture timings before edits:
   ```bash
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Trade/Integration/TradeApiIntegrationTest.php tests/Trade/Integration/OrderWorkflowApiTest.php --no-coverage
   ```
2. Apply deletions, then re-run the survivors in isolation first:
   ```bash
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Trade/Integration/OrderWorkflowApiTest.php tests/Trade/Integration/TradeRepoFullTest.php --no-coverage
   ```
3. Prove no `src/Trade` coverage regression: capture clover before/after on the affected areas
   and diff the `count="0"` statements (the campaign's own technique):
   ```bash
   XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter 'Trade|OrderWorkflow|OrderItem|Specification' --coverage-clover /tmp/trade-before.xml
   XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter 'Trade|OrderWorkflow|OrderItem|Specification' --coverage-clover /tmp/trade-after.xml
   ```
   Expected result: `OrderWorkflowApiTest` keeps the state-machine lines covered; `TradeRepoFullTest`
   keeps the repository lines covered; the deleted `TradeRepositoryIntegrationTest` adds no unique lines.
4. Full suite on CI PostgreSQL must stay green (2686 → fewer tests, same line coverage ≥ 90%):
   `composer phpstan`, `composer rector:types:check`, full `phpunit` run.
5. Confirm exactly ONE skipped doTransition-tamper regression remains (the integration twin in
   `OrderWorkflowApiTest`), and update `docs/issues/coverage-2026-08-09/trade-controllers.md` /
   `trade-workflow-badpaths.md` if the referenced unit test is removed.
6. Confirm the skipped Bug-1 test (`App/OrderControllerTest::testSubmitActionReturnsWarningWhenTransitionFails`)
   and all other bug-documenting skips are untouched.
