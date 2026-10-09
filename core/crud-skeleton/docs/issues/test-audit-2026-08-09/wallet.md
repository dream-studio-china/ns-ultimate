# Wallet module test audit (2026-08-09)

Read-only audit of `tests/Wallet/` (183 PHPUnit tests, 524 assertions, 3 skipped) to identify
unnecessary/redundant tests that are candidates for later deletion. No `src/` or `tests/` file was
modified. Verdicts follow the project strategy (`docs/testing/crud-skeleton-production/TEST_STRATEGY.md`
— one behaviour per primary layer; no controller tests purely for line coverage) and the coverage-campaign
context (`docs/issues/coverage-2026-08-09/README.md`, `wallet-manage.md`).

Suite state verified: `php ./vendor/bin/phpunit tests/Wallet` → `OK, but there were issues! Tests: 183,
Assertions: 524, Skipped: 3, Notices: 16` (the 16 notices are pre-existing).

## Summary — table

| File | Tests | Verdict |
|---|---|---|
| `tests/Wallet/Entity/WalletTest.php` | 13 | MIXED — keep constructor defaults, status markers, `__toString`, money-as-cents conversion; delete trivial accessor round-trips and the duplicate zero-boundary test |
| `tests/Wallet/Entity/WalletTransactionTest.php` | 17 | MIXED — keep type/status validation, `markCompleted`/`markFailed`, one-cent boundary; delete trivial accessors, the tautological UUID test, and the duplicate large-boundary test |
| `tests/Wallet/Entity/WalletPaymentDeductionTest.php` | 2 | KEEP — state-machine markers (`markApplied/Released/Refunded/Failed`) and the invoice-payer constructor guard |
| `tests/Wallet/Service/WalletServiceTest.php` | 11 | MIXED — keep `reconcile()` branch logic (idempotency, `skipped_negative`); consolidate `verifyBalance` trio and the defensive-guard pairs |
| `tests/Wallet/Service/WalletServiceCoverageTest.php` | 2 | DELETE — coverage-chasing of one defensive guard; the two tests duplicate each other |
| `tests/Wallet/Service/TransferServiceTest.php` | 20 | MIXED — keep all deposit tests (no integration deposit coverage), rollback, EM-closed recovery, lock-ordering; delete transfer guard/happy-path duplicates covered by the Integration suite |
| `tests/Wallet/Service/Payment/WalletGatewayTest.php` | 10 | MIXED — keep payer/wallet-required and partial-refund tests; delete notify/systemWalletId/full-refund duplicates covered by `WalletGatewayIntegrationTest` |
| `tests/Wallet/Service/Payment/WalletBalanceAdjustmentProviderTest.php` | 6 | KEEP — adapter contract (supports/apply/applied/release/refund) with distinct error branches |
| `tests/Wallet/Integration/TransferServiceTest.php` | 16 | KEEP — primary layer for transfer money movement (persisted-state assertions) |
| `tests/Wallet/Integration/WalletApiRegressionTest.php` | 17 (+9 data sets) | KEEP — primary HTTP layer (routing, auth, payload, owner scoping, idempotency, fuzz) |
| `tests/Wallet/Integration/WalletGatewayIntegrationTest.php` | 4 | KEEP — primary gateway integration (pay+refund moves funds) |
| `tests/Wallet/Integration/WalletPaymentDeductionServiceIntegrationTest.php` | 12 | KEEP — deduction lifecycle, idempotency, validation branches, failed-transfer marking |
| `tests/Wallet/Integration/WalletRepositoryTest.php` | 9 | KEEP — pessimistic lock + findBy* contract |
| `tests/Wallet/Integration/WalletTransactionRepositoryTest.php` | 13 | KEEP — repository query contract (minor `findPendingEmpty`/`findByWalletEmpty` overlaps noted below) |
| `tests/Wallet/Controller/Manage/TransferControllerTest.php` | 22 (3 skipped) | MIXED — most create/deposit branch tests duplicate the HTTP layer; keep the 3 skipped bug-documentation tests |

## KEEP

Critical invariants these tests protect — do not delete:

- **Integer cents / money conversion**: `WalletTest::testBalanceAsFloatWithCents`, `WalletTransactionTest::testAmountBoundaryOneCent` (and the large-boundary variant if kept), `WalletPaymentDeductionTest` amount/currency assertions.
- **Idempotency**: `Service/TransferServiceTest::testDepositIdempotent`, `Integration/TransferServiceTest::testIdempotencyByReferenceId`, `::testSameReferenceIdDifferentParamsReturnsExisting`, `WalletApiRegressionTest::testTransferApiIdempotency`, `WalletPaymentDeductionServiceIntegrationTest::testApplyReleaseAndRefundAreIdempotent`, `::testReleasedDeductionPreventsReapply`.
- **Optimistic/pessimistic locking & concurrency**: `WalletRepositoryTest::testFindByIdForUpdatePessimisticLock`, `Service/TransferServiceTest::testTransferDeadlockSafeOrder` (white-box but protects the no-deadlock invariant), `Integration/TransferServiceTest::testConcurrentTransfersDoNotDoubleSpend`.
- **Transactional rollback / no partial state**: `Service/TransferServiceTest::testDepositRollbackOnError`, `::testDepositEmClosedRecovery`, `::testTransferEmClosedRecovery`, `Integration/TransferServiceTest::testBalanceUnchangedAfterFailedTransfer`, `WalletPaymentDeductionServiceIntegrationTest::testApplyMarksDeductionFailedWhenTransferFails`.
- **Reconciliation**: `WalletServiceTest::testReconcileExcessBalanceByDeposit`, `::testReconcileNegativeDiffSkipped`, `::testReconcileIdempotent`, `::testReconcileMultipleWallets`; HTTP `UserApiIntegrationTest::testBalanceVerification` / `::testReconcileAfterDepositProducesZero`.
- **Skipped tests documenting known bugs (KEEP per campaign rules)**: `TransferControllerTest::testCreateActionZeroAmountShouldReportAmountNotPositive`, `::testDepositActionZeroAmountShouldReportAmountNotPositive` (BUG-3 `empty()`), `::testCreateActionIdempotentReplayEchoesStoredAmount` (BUG-4). `WalletApiRegressionTest::blindTransferProvider` `'float amount' => 201` also documents BUG-1 truncation.
- **Validation guards**: `WalletTransactionTest::testConstructorInvalidTypeThrowsException`/`testSetInvalidStatus`/`testAllValidTypes`/`testAllValidStatuses`, `WalletPaymentDeductionTest::testRequiresInvoicePayer`, all `WalletPaymentDeductionServiceIntegrationTest` validation tests.
- **Owner/HTTP scoping**: `WalletApiRegressionTest::testAppWalletsAndTransactionsAreScopedToCurrentUser`, `::testTransferRequiresAuth`.
- **Full gateway/HTTP money flow**: `WalletGatewayIntegrationTest::testWalletPaymentAndRefund` (asserts payer and system wallet balances after pay and refund), `UserApiIntegrationTest::testDepositFundsToWallet`, `::testTransferBetweenWallets`.
- **Unique unit branches without a higher-layer duplicate**: `WalletGatewayTest::testPayRequiresPayer`/`::testRefundRequiresPayer`/`::testPayRequiresWalletForPayer`/`::testRefundRequiresWalletForPayer`/`::testRefundReturnsPartialRefundedStatus`; `Service/TransferServiceTest::testTransferSourceNotFound` (Integration only covers target-not-found); all deposit tests in `Service/TransferServiceTest`.

## DELETE CANDIDATES — table

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `WalletServiceCoverageTest::testGetWalletRepositoryThrowsWhenRepositoryIsNotWalletRepository` | 1 COVERAGE-CHASING + 2 DUPLICATE | HIGH | Tests a defensive `instanceof` guard at `src/Wallet/Service/WalletService.php:148` that is unreachable in practice (Doctrine always resolves `WalletRepository`); the two tests in the file hit the same line |
| `WalletServiceCoverageTest::testGetWalletRepositoryThrowsForUnrelatedRepository` | 2 DUPLICATE | HIGH | Same guard, same `LogicException`, different entry point — exact duplicate of `testGetWalletRepositoryThrowsWhenRepositoryIsNotWalletRepository` |
| `Service/TransferServiceTest::testTransferAmountNotPositive` | 2 DUPLICATE | HIGH | `Integration/TransferServiceTest::testTransferZeroAmount`, `::testTransferNegativeAmount` (same `InvalidArgumentException`, message `positive`) |
| `Service/TransferServiceTest::testTransferSameWallet` | 2 DUPLICATE | HIGH | `Integration/TransferServiceTest::testTransferSameWalletThrows` (`SameWalletTransferException`) |
| `Service/TransferServiceTest::testTransferSourceFrozen` | 2 DUPLICATE | HIGH | `Integration/TransferServiceTest::testTransferFromFrozenWallet` (`WalletFrozenException`) |
| `Service/TransferServiceTest::testTransferTargetFrozen` | 2 DUPLICATE | HIGH | `Integration/TransferServiceTest::testTransferToFrozenWallet` (`WalletFrozenException`) |
| `Service/TransferServiceTest::testTransferCurrencyMismatch` | 2 DUPLICATE | HIGH | `Integration/TransferServiceTest::testTransferCurrencyMismatch` (same `RuntimeException` "Currency mismatch") |
| `Service/TransferServiceTest::testTransferInsufficientFunds` | 2 DUPLICATE | HIGH | `Integration/TransferServiceTest::testTransferInsufficientFunds` (+ HTTP `WalletApiRegressionTest::testTransferApiInsufficientFunds`) |
| `Service/TransferServiceTest::testTransferIdempotent` | 2 DUPLICATE | HIGH | `Integration/TransferServiceTest::testIdempotencyByReferenceId` (+ HTTP `WalletApiRegressionTest::testTransferApiIdempotency`) |
| `Service/TransferServiceTest::testTransferTargetNotFound` | 2 DUPLICATE | MEDIUM | `Integration/TransferServiceTest::testTransferNonexistentWallet` (target wallet missing) |
| `Service/TransferServiceTest::testTransferHappyPath` | 2 DUPLICATE | MEDIUM | `Integration/TransferServiceTest::testBasicTransfer` (same completed status, amount, from/to balances, persisted) |
| `Service/TransferServiceTest::testTransferRollbackOnError` | 2 DUPLICATE | MEDIUM | `Integration/TransferServiceTest::testBalanceUnchangedAfterFailedTransfer` (rollback effect asserted on persisted state, stronger than mock `rollback()` call) |
| `Service/Payment/WalletGatewayTest::testNotifyRejectsExternalCallbacks` | 2 DUPLICATE | HIGH | `Integration/WalletGatewayIntegrationTest::testWalletGatewayRejectsExternalNotify` (same empty `Request` → `PaymentVerificationException`) |
| `Service/Payment/WalletGatewayTest::testPayRequiresSystemWalletId` | 2 DUPLICATE | HIGH | `Integration/WalletGatewayIntegrationTest::testWalletGatewayRequiresSystemWallet` (pay without `systemWalletId` → `InvalidArgumentException`) |
| `Service/Payment/WalletGatewayTest::testGetNameAndGetNotifySuccessResponse` | 1 COVERAGE-CHASING + 2 DUPLICATE | MEDIUM | `getName()` returns a constant; `getNotifySuccessResponse` duplicate of `Integration/WalletGatewayIntegrationTest::testGetNotifySuccessResponseReturnsTextResponse` (same 200 / "OK") |
| `Service/Payment/WalletGatewayTest::testRefundReturnsRefundedStatus` | 2 DUPLICATE | MEDIUM | `Integration/WalletGatewayIntegrationTest::testWalletPaymentAndRefund` (full refund asserted `STATUS_REFUNDED` with persisted balances) |
| `Entity/WalletTest::testBalanceBoundaryZero` | 2 DUPLICATE | HIGH | Exact duplicate of `testConstructorInitializesDefaults` assertions (balance `0`, `getBalanceAsFloat()` `0.0`) |
| `Entity/WalletTest::testBalanceBoundaryLarge` | 2 DUPLICATE | MEDIUM | Same `/100` conversion as `testBalanceAsFloatWithCents` (one boundary test suffices for the money invariant) |
| `Entity/WalletTest::testConstructorCurrencyUppercase` | 2 DUPLICATE | MEDIUM | Same `strtoupper` logic as `testSetCurrency` (constructor vs setter) |
| `Entity/WalletTest::testPrePersistWhenCreatedFromReflection` + `testPrePersistKeepsExistingCreatedAt` | 1 COVERAGE-CHASING | MEDIUM | Both exercise the two branches of the same `prePersist()` `if (!isset($this->createdAt))` lifecycle guard; merge into one parameterized test |
| `Entity/WalletTest::testSetUser` | 1 COVERAGE-CHASING | MEDIUM | Trivial `setUser()`/`getUser()` round-trip, no logic |
| `Entity/WalletTest::testSetLabel` | 1 COVERAGE-CHASING | MEDIUM | Trivial `setLabel()`/`getLabel()` round-trip, no logic |
| `Entity/WalletTransactionTest::testUuidPersistenceAcrossOperations` | 1 COVERAGE-CHASING | HIGH | Tautological — `uuid` has no setter and cannot change |
| `Entity/WalletTransactionTest::testAmountBoundaryLarge` | 2 DUPLICATE | MEDIUM | Same `/100` conversion as `testAmountBoundaryOneCent` |
| `Entity/WalletTransactionTest::testPrePersist` + `testPrePersistKeepsExisting` | 1 COVERAGE-CHASING | MEDIUM | Two branches of the same `prePersist()` guard; merge into one |
| `Entity/WalletTransactionTest::testSetFromAndToWallet` | 1 COVERAGE-CHASING | MEDIUM | Trivial set/get round-trip |
| `Entity/WalletTransactionTest::testSetReferenceId` | 1 COVERAGE-CHASING | MEDIUM | Trivial set/get round-trip |
| `Entity/WalletTransactionTest::testSetDescription` | 1 COVERAGE-CHASING | MEDIUM | Trivial set/get round-trip |
| `Entity/WalletTransactionTest::testSetMetadata` | 1 COVERAGE-CHASING | MEDIUM | Trivial set/get round-trip |
| `WalletServiceTest::testVerifyBalanceZero` | 2 DUPLICATE | MEDIUM | Same `matches=true` branch as `testVerifyBalanceMatches` with only the magnitude changed (mock values forwarded into the result array) |
| `WalletServiceTest::testReconcileEmptyWallets` | 2 DUPLICATE | MEDIUM | Same "0 reconciled, 0 adjustments" outcome as `testReconcileBalancedWallet` (empty loop vs balanced wallet); table-drive together |
| `WalletServiceTest::testReconcileSkipsNonWalletEntities` | 2 DUPLICATE | MEDIUM | Same `continue` guard at `WalletService.php:87` as `testReconcileSkipsWalletWithoutId`; defensive guard coverage |
| `WalletServiceTest::testReconcileSkipsWalletWithoutId` | 2 DUPLICATE | MEDIUM | Same guard as above; duplicate of `testReconcileSkipsNonWalletEntities` |
| `Controller/Manage/TransferControllerTest::testCreateActionRejectsMissingFields` | 2 DUPLICATE | HIGH | `WalletApiRegressionTest::testTransferApiMissingFields` (400) + `UserApiIntegrationTest::testTransferMissingFields` (400) |
| `Controller/Manage/TransferControllerTest::testCreateActionRejectsInvalidJson` | 2 DUPLICATE | HIGH | `WalletApiRegressionTest::testTransferInvalidJson` (400) |
| `Controller/Manage/TransferControllerTest::testCreateActionInsufficientFunds` | 2 DUPLICATE | HIGH | `WalletApiRegressionTest::testTransferApiInsufficientFunds` (402) + `UserApiIntegrationTest::testTransferBetweenWallets` (402) |
| `Controller/Manage/TransferControllerTest::testCreateActionWalletFrozen` | 2 DUPLICATE | HIGH | `WalletApiRegressionTest::testTransferApiFrozenWallet` (403) |
| `Controller/Manage/TransferControllerTest::testCreateActionSameWallet` | 2 DUPLICATE | HIGH | `WalletApiRegressionTest::testTransferApiSameWallet` (400) + `UserApiIntegrationTest::testTransferSameWalletRejected` (400) |
| `Controller/Manage/TransferControllerTest::testCreateActionRuntimeNotFoundMapsTo404` | 2 DUPLICATE | HIGH | `WalletApiRegressionTest::testTransferNonexistentSourceWallet` (404) |
| `Controller/Manage/TransferControllerTest::testCreateActionSuccess` | 2 DUPLICATE | MEDIUM | `WalletApiRegressionTest::testTransferApiSuccess` (201 + code/status/amount/amountFloat/balances) + `UserApiIntegrationTest::testTransferBetweenWallets` |
| `Controller/Manage/TransferControllerTest::testCreateActionRejectsNegativeAmount` | 2 DUPLICATE | MEDIUM | `WalletApiRegressionTest::blindTransferProvider` `'negative amount'` → 400 + `UserApiIntegrationTest::testTransferNegativeAmountRejected` (400) |
| `Controller/Manage/TransferControllerTest::testCreateActionRejectsNonNumericAmount` | 2 DUPLICATE | MEDIUM | `WalletApiRegressionTest::blindTransferProvider` `'string amount'` / `'wrong types'` → 400 |
| `Controller/Manage/TransferControllerTest::testCreateActionRuntimeErrorMapsTo500` | 2 DUPLICATE | MEDIUM | `WalletApiRegressionTest::testTransferCurrencyMismatch` (500) exercises the generic-`RuntimeException` → 500 mapping |
| `Controller/Manage/TransferControllerTest::testCreateActionInvalidArgument` | 2 DUPLICATE | MEDIUM | `WalletApiRegressionTest::blindTransferProvider` `'wrong types'` → 400 exercises the `InvalidArgumentException` → 400 mapping |
| `Controller/Manage/TransferControllerTest::testDepositActionSuccess` | 2 DUPLICATE | MEDIUM | `UserApiIntegrationTest::testDepositFundsToWallet` (201, `type` deposit, `toWalletBalanceAfter`, `amountFloat`) |
| `Controller/Manage/TransferControllerTest::testDepositActionRejectsMissingFields` | 2 DUPLICATE | HIGH | `UserApiIntegrationTest::testDepositMissingFields` (400) |
| `Controller/Manage/TransferControllerTest::testDepositActionRejectsNonPositiveAmount` | 2 DUPLICATE | HIGH | `UserApiIntegrationTest::testDepositNegativeAmountRejected` (400) |
| `Controller/Manage/TransferControllerTest::testDepositActionRuntimeNotFoundMapsTo404` | 2 DUPLICATE | HIGH | `UserApiIntegrationTest::testDepositToNonexistentWallet` (404) |

Note on the controller rows: the HTTP tests assert status codes (and partly response bodies); the unit
controller tests additionally assert the response envelope. Deleting them is safe only if the HTTP layer
is the primary owner of the API contract (per TEST_STRATEGY). See merge suggestion 7.

Lower-priority (leave for a later pass, not recommended for immediate deletion):
`WalletTransactionRepositoryTest::testFindPendingEmpty` (overlaps `testFindPendingExcludesCompleted`),
`WalletRepositoryTest::testFindByUserEmpty` / `testFindByUserAndCurrencyNotFound` (trivial empty results),
`WalletApiRegressionTest::testTransferApiMissingFields` (overlaps `blindTransferProvider` missing-field cases).

## MERGE SUGGESTIONS

1. **`WalletServiceCoverageTest`** — collapse to a single test (or delete the whole file; it exists only for line 148 of `WalletService.php`).
2. **`WalletServiceTest::testVerifyBalanceMatches` / `testVerifyBalanceMismatch` / `testVerifyBalanceZero`** — table-drive into one test (matches/mismatch/zero rows).
3. **`WalletServiceTest::testReconcileEmptyWallets` + `testReconcileBalancedWallet`** and **`testReconcileSkipsNonWalletEntities` + `testReconcileSkipsWalletWithoutId`** — merge each pair into one table-driven test.
4. **`WalletTest::testPrePersistWhenCreatedFromReflection` + `testPrePersistKeepsExistingCreatedAt`** and **`WalletTransactionTest::testPrePersist` + `testPrePersistKeepsExisting`** — merge into one parameterized lifecycle test each.
5. **`WalletTest::testConstructorCurrencyUppercase` + `testSetCurrency`** — merge (single uppercasing case matrix).
6. **`Entity` boundary tests** — keep one cents/float boundary test per entity (one-cent boundary) and drop the large-value variants; both exercise the same `amount/100` line.
7. **`TransferControllerTest`** — if the HTTP suite is kept as primary owner, fold the response-envelope assertions (`code`/`message`/`data` shape) into `WalletApiRegressionTest`/`UserApiIntegrationTest` and delete the corresponding unit tests; retain only the 3 skipped bug-documentation tests.

## Verification steps

1. Confirm the current baseline before deleting anything:
   `cd /Volumes/Nayuki/Development/PHP/crud-skeleton && /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Wallet`
   Expected: `Tests: 183, Assertions: 524, Skipped: 3` (16 pre-existing PHPUnit notices are unrelated).
2. After any deletion, re-run the same command and confirm the count drops exactly by the number of removed
   non-skipped tests and that `Skipped: 3` is unchanged (the 3 bug-documentation skips must remain).
3. Because the duplicates are cross-layer, also run the full suite to ensure the higher-layer owners still
   pass and that no `src/Wallet` line becomes uncovered below the 90% gate:
   `/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit`
4. For the coverage-chasing entities (accessors, `prePersist`, `WalletServiceCoverageTest`), verify removal
   does not drop `src/Wallet` line coverage below the gate:
   `XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --coverage-html var/coverage`.
   The campaign reached 99.46% project-wide with large headroom, so the flagged trivial tests should be safe
   to drop; any surprising dip should be reviewed before committing.
5. No changes under `src/` or `tests/` were made by this audit; deletion is a separate follow-up task.
