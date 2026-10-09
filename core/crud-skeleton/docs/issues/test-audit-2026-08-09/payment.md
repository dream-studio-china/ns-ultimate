# Payment module test audit (2026-08-09)

Read-only audit of `tests/Payment/` (14 files, 76 tests) to identify unnecessary /
redundant tests as candidates for later deletion. Reference documents:
`TEST_STRATEGY.md` ("One behaviour should normally have one primary layer",
"Do not add controller tests merely to raise line coverage"),
`TEST_MATRIX.md`, `BUSINESS_INVARIANTS.md` (Payments invariant), and the
`coverage-2026-08-09` campaign summary (99.46% line coverage was chased across
the suite).

Bias note: payment gateway / adjustment / invoice-lifecycle behavior is
money-critical (BUSINESS_INVARIANTS "Payments" row) and is KEEP unless an exact
duplicate exists at the same or higher layer. No skipped tests exist in this
module, so there are no bug-documenting tests to protect.

## Summary

| File | Tests | Verdict |
|---|---|---|
| `tests/Payment/Entity/InvoiceTest.php` | 1 | KEEP |
| `tests/Payment/Entity/InvoiceCoverageTest.php` | 1 | DELETE CANDIDATE |
| `tests/Payment/Event/InvoiceEventTest.php` | 1 | DELETE CANDIDATE |
| `tests/Payment/Service/InvoiceServiceTest.php` | 9 | PARTIAL (4 keep / 5 candidates) |
| `tests/Payment/Service/MockGatewayTest.php` | 2 | DELETE CANDIDATE |
| `tests/Payment/Service/PaymentAdjustmentRegistryTest.php` | 1 | KEEP |
| `tests/Payment/Service/PaymentGatewayRegistryTest.php` | 2 | DELETE CANDIDATE |
| `tests/Payment/Controller/App/InvoiceControllerTest.php` | 3 | DELETE CANDIDATE |
| `tests/Payment/Controller/Manage/InvoiceControllerTest.php` | 14 | DELETE CANDIDATE |
| `tests/Payment/Controller/Webhook/PaymentNotifyControllerTest.php` | 4 | DELETE CANDIDATE |
| `tests/Payment/Integration/InvoiceServiceIntegrationTest.php` | 10 | KEEP |
| `tests/Payment/Integration/PaymentAdjustmentIntegrationTest.php` | 5 | PARTIAL (1 keep / 4 candidates) |
| `tests/Payment/Integration/PaymentAdjustmentMultiGatewayIntegrationTest.php` | 19 | KEEP (2 internal dup notes) |
| `tests/Payment/Integration/PaymentApiIntegrationTest.php` | 4 | KEEP |

Trend: every DELETE CANDIDATE below was added by the coverage campaign
(`4c62b5f test: add 900+ tests ... push line coverage to 99.46%`) or duplicates
behavior that the integration/HTTP layer already owns. The pre-campaign
integration files (`InvoiceServiceIntegrationTest.php`,
`PaymentAdjustmentIntegrationTest.php`, `PaymentApiIntegrationTest.php`) remain
the authoritative layer.

## KEEP

- `tests/Payment/Integration/InvoiceServiceIntegrationTest.php` (10) — the
  primary invoice-lifecycle test: create→pay→notify→refund, findBySource,
  repository lookups, redaction, idempotent duplicate notify, cancel/fail,
  refund validation branches. Money-critical, authoritative.
- `tests/Payment/Integration/PaymentApiIntegrationTest.php` (4) — the
  Payment-module-owned HTTP layer for manage/app invoice routes + notify
  webhook. This is the layer that the controller unit tests duplicate; keep
  these, delete those.
- `tests/Payment/Entity/InvoiceTest.php` (1) — entity defaults/accessors +
  `__toString` + `prePersist`. Core entity behavior.
- `tests/Payment/Service/PaymentAdjustmentRegistryTest.php` (1) — the only test
  exercising the registry (applicable/apply/sumApplied/hasApplied/release/refund)
  with a controlled fake provider in isolation. Reasonable primary layer for the
  registry class itself.
- `tests/Payment/Integration/PaymentAdjustmentMultiGatewayIntegrationTest.php`
  (17 of 19) — the comprehensive deduction+gateway matrix (mock/wallet/wechat,
  autoPaid, notify, partial/full refund, release on fail/cancel, sequential
  multi-invoice). Money-critical; keep except the two internal dups noted below.
- `tests/Payment/Integration/PaymentAdjustmentIntegrationTest.php` →
  `testDeductedInvoiceRejectsPartialRefund` — the only test of the
  "adjusted invoices only support full refund" guard
  (`InvoiceService.php:241-243`). Unique.
- `tests/Payment/Service/InvoiceServiceTest.php` →
  `testCreateInvoiceRejectsNegativeAmount`, `testPayThrowsWhenDeductionExceedsInvoiceAmount`,
  `testPayAppliesGatewayAndTradeTypeOptions`, `testMarkFailedThrowsWhenWorkflowCannotFail`
  — these branches (negative amount, deduction > invoice, gateway/tradeType
  option application, fail-not-allowed guard) are not asserted by any
  integration/HTTP test. Unique coverage.

## DELETE CANDIDATES

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `Entity/InvoiceCoverageTest.php::testPrePersistReinitializesCreatedAtWhenMissing` | B1 (COVERAGE-CHASING) + B3 (IMPLEMENTATION-DETAIL): reflection-only exercise of a defensive `prePersist` guard unreachable through normal construction | Medium | `Entity/InvoiceTest.php::testDefaultsAndAccessors` asserts `prePersist()` behavior; branch is dead in normal use |
| `Event/InvoiceEventTest.php::testEventPayloads` | B1 (COVERAGE-CHASING): tautological getter assertions on dumb event value objects | Medium | events are constructed/dispatched by `InvoiceServiceIntegrationTest.php`; getters add no contract |
| `Service/InvoiceServiceTest.php::testPayThrowsWhenStartPayTransitionNotAllowed` | B2 (DUPLICATE): pay on non-startable invoice → rejection | Medium | `tests/Integration/PaymentTradeIntegrationTest.php::testBadPathGuards` (re-pay → 400) and `::testDuplicateNotifyAndDeductionAttemptAreIdempotent` (second pay → 400) via real workflow |
| `Service/InvoiceServiceTest.php::testHandleNotifyResultThrowsWhenInvoiceNotFound` | B4 (REDUNDANT-REGRESSION): "Invoice X not found" asserted in unit + webhook + HTTP | Medium | `Controller/Webhook/PaymentNotifyControllerTest.php::testNotifyActionReturnsFailWhenServiceThrows`; `PaymentTradeIntegrationTest::testBadPathGuards` (unknown outTradeNo → 400 FAIL) |
| `Service/InvoiceServiceTest.php::testMarkPaidThrowsWhenWorkflowCannotMarkPaid` | B2 (DUPLICATE): mark_paid not allowed → `InvoiceInvalidTransitionException` | Medium | `Integration/InvoiceServiceIntegrationTest.php::testMarkPaidOnCancelledInvoiceThrows` (same method, same exception) |
| `Service/InvoiceServiceTest.php::testCancelThrowsWhenWorkflowCannotCancel` | B2 (DUPLICATE): cancel not allowed → 400 | Medium | `PaymentTradeIntegrationTest::testBadPathGuards` (cancel paid invoice → 400) |
| `Service/InvoiceServiceTest.php::testRefundThrowsWhenWorkflowCannotApplyTransition` | B2 (DUPLICATE): refund on non-refundable invoice → `InvoiceInvalidTransitionException` | HIGH | `Integration/InvoiceServiceIntegrationTest.php::testRefundRejectsInvalidInvoiceStatus` (identical exception for refund on pending invoice) |
| `Service/MockGatewayTest.php::testPayNotifyRefundAndResponse` + `::testNotifyRejectsInvalidSecret` | B2 (DUPLICATE): MockGateway is a test double whose full surface is exercised by every integration pay/notify/refund and by the webhook tests | Medium | `PaymentApiIntegrationTest::testManageAndAppInvoiceApiFlow` + `::testWebhookInvalidSecretReturnsFailure`; `PaymentTradeIntegrationTest::testOrderPaidViaMockGatewayAndNotifyWebhook`; `PaymentNotifyControllerTest` |
| `Service/PaymentGatewayRegistryTest.php::testRegistryResolvesGatewayNames` + `::testUnknownGatewayThrows` | B2 (DUPLICATE): registry presence/names + unknown-gateway error asserted at integration/HTTP | Medium | `Integration/InvoiceServiceIntegrationTest.php::testGatewayRegistryFromContainer`; `PaymentAdjustmentMultiGatewayIntegrationTest::testAllThreeGatewayNamesAreKnown` + `::testDeductedInvoiceWithMockReleasesOnGatewayFailure`; `PaymentTradeIntegrationTest::testBadPathGuards` (unknown gateway → 400) |
| `Controller/App/InvoiceControllerTest.php::testPayActionReturnsSuccessWhenPayerMatches` | B2 (DUPLICATE): app pay success over HTTP | HIGH | `PaymentApiIntegrationTest::testManageAndAppInvoiceApiFlow` (`POST /api/v1/app/invoices/{id}/pay/mock` → 200) |
| `Controller/App/InvoiceControllerTest.php::testPayActionReturnsNotFoundWhenPayerMismatch` | B2 (DUPLICATE): mismatched payer → 404 | HIGH | `PaymentTradeIntegrationTest::testBadPathGuards` (userB pays userA's invoice → 404) |
| `Controller/App/InvoiceControllerTest.php::testPayActionReturnsWarningWhenServiceThrows` | B1/B2 (COVERAGE-CHASING): generic exception→400 warning envelope, mock-only | Medium | envelope is standard RestController behavior; no service-exception scenario in HTTP tests |
| `Controller/Manage/InvoiceControllerTest.php::testCreateActionReturnsSuccessWithPayerAndParsesAmount` | B2 (DUPLICATE): create + parseAmount `'12.34'→1234` over HTTP | HIGH | `PaymentApiIntegrationTest::testManageAndAppInvoiceApiFlow` (asserts `1234`) |
| `Controller/Manage/InvoiceControllerTest.php::testCreateActionReturnsWarningWhenRequiredFieldMissing` | B2 (DUPLICATE): missing `sourceId` → 400 | HIGH | `PaymentApiIntegrationTest::testManageValidationWarnings` (`['sourceType'=>'manual']` → 400) |
| `Controller/Manage/InvoiceControllerTest.php::testCancelActionReturnsSuccess` | B2 (DUPLICATE): cancel → 200/CANCELLED over HTTP | HIGH | `PaymentApiIntegrationTest::testManageCancelTransitionsAndAppNotFoundBranches` |
| `Controller/Manage/InvoiceControllerTest.php::testRefundActionReturnsSuccess` | B2 (DUPLICATE): refund → 200/REFUNDED over HTTP | HIGH | `PaymentApiIntegrationTest::testManageAndAppInvoiceApiFlow` (refund → 200, status `refunded`) |
| `Controller/Manage/InvoiceControllerTest.php::testTransitionsActionReturnsSuccess` | B2 (DUPLICATE): transitions → 200 over HTTP | HIGH | `PaymentApiIntegrationTest::testManageCancelTransitionsAndAppNotFoundBranches` (GET transitions → 200 notEmpty) |
| `Controller/Manage/InvoiceControllerTest.php` (remaining 9: pay success, cancel/refund/transitions not-found, refund amount/reason missing, 4× service-throws) | B1/B2 (COVERAGE-CHASING / DUPLICATE): same routes owned by `PaymentApiIntegrationTest` at the HTTP layer; remaining branches are generic warning-envelope passthrough with mocked services | Medium | `PaymentApiIntegrationTest::testManageAndAppInvoiceApiFlow`, `::testManageValidationWarnings`, `::testManageCancelTransitionsAndAppNotFoundBranches` |
| `Controller/Webhook/PaymentNotifyControllerTest.php::testNotifyActionReturnsGatewaySuccessResponse` | B2 (DUPLICATE): notify → SUCCESS over HTTP | HIGH | `PaymentApiIntegrationTest::testManageAndAppInvoiceApiFlow` (notify → SUCCESS) |
| `Controller/Webhook/PaymentNotifyControllerTest.php::testNotifyActionReturnsFailWithMessageForVerificationException` | B2 (DUPLICATE): invalid secret → `FAIL: ...` 400 | HIGH | `PaymentApiIntegrationTest::testWebhookInvalidSecretReturnsFailure`; `PaymentTradeIntegrationTest::testNotifyVerificationFailuresReturn400AndDoNotAdvanceOrder` |
| `Controller/Webhook/PaymentNotifyControllerTest.php::testNotifyActionReturnsFailWhenServiceThrows` | B2 (DUPLICATE): service failure → `FAIL` 400 | HIGH | `PaymentTradeIntegrationTest::testBadPathGuards` (unknown outTradeNo notify → 400 FAIL) |
| `Controller/Webhook/PaymentNotifyControllerTest.php::testNotifyActionReturnsFailForUnknownGateway` | B1 (COVERAGE-CHASING): unknown-gateway branch not exercised at HTTP; trivial mapping | Low/Medium | — |
| `Integration/PaymentAdjustmentIntegrationTest.php::testWalletDeductionPlusMockPaymentAndFullRefund` | B2 (DUPLICATE): deduction(300)+mock(700)+full-refund restores 2000/0 — near-identical scenario | HIGH | `PaymentAdjustmentMultiGatewayIntegrationTest::testMockGatewayNotifyConfirmsDeductedInvoice` (identical 300/700/1000/2000 numbers) and `::testMockGatewayWithWalletDeductionAndAutoPaid` |
| `Integration/PaymentAdjustmentIntegrationTest.php::testNotifyMustMatchRemainingGatewayAmount` | B2 (DUPLICATE): notify of gross amount after deduction → mismatch exception | HIGH | `PaymentAdjustmentMultiGatewayIntegrationTest::testMockGatewayNotifyAmountMismatchWithDeductionRejected` (identical scenario) |
| `Integration/PaymentAdjustmentIntegrationTest.php::testFullWalletDeductionSetsPaymentWallet` | B2 (DUPLICATE): walletAmount == amount → payment=wallet, gatewayAmount=0 | HIGH | `PaymentAdjustmentMultiGatewayIntegrationTest::testWalletGatewayWithWalletDeductionMeansFullWalletPayment` and `::testSequentialMultipleInvoicesWithDifferentGateways` (inv4) |
| `Integration/PaymentAdjustmentIntegrationTest.php::testGatewayFailureReleasesDeduction` | B2 (DUPLICATE): missing gateway → deduction released, balances restored | HIGH | `PaymentAdjustmentMultiGatewayIntegrationTest::testDeductedInvoiceWithMockReleasesOnGatewayFailure` (identical scenario, 200 vs 400 wallet amount only) |
| `Integration/PaymentAdjustmentMultiGatewayIntegrationTest.php::testAllThreeGatewayNamesAreKnown` | B2 (DUPLICATE): registry names asserted three times across the suite | HIGH | `::testWechatGatewayIsRegistered` in the same file; `InvoiceServiceIntegrationTest::testGatewayRegistryFromContainer` |
| `Integration/PaymentAdjustmentMultiGatewayIntegrationTest.php::testDeductionIsIdempotentOnReapply` | B2 (DUPLICATE): duplicate notify does not double-deduct | Medium | `PaymentTradeIntegrationTest::testDuplicateNotifyAndDeductionAttemptAreIdempotent` (same deduction + duplicate notify + balance assertion) |

Net DELETE CANDIDATE count: ~30 tests (2 high-confidence duplicates each for the
PaymentAdjustment file and the controller files), mostly mock-based unit tests
added by the coverage campaign that re-assert behavior the integration/HTTP
layer already protects.

## MERGE SUGGESTIONS

- **PaymentAdjustmentIntegrationTest → PaymentAdjustmentMultiGatewayIntegrationTest**: the 4 flagged tests are scenario-level duplicates of the later, more
  comprehensive multi-gateway file. Rather than delete outright, merge any
  scenario nuance (the 200-vs-400 walletAmount in the gateway-failure test) into
  the MultiGateway file and drop the older file's duplicates. Keep
  `testDeductedInvoiceRejectsPartialRefund` (only full-refund-only guard).
- **InvoiceServiceTest → InvoiceServiceIntegrationTest**: this coverage-campaign
  file re-asserts workflow-guard exceptions that the integration file proves with
  the real state machine. The 4 unique unit branches (negative amount,
  deduction-exceeds, gateway/tradeType options, fail-not-allowed) are better
  expressed as integration scenarios in `InvoiceServiceIntegrationTest.php`;
  the remaining 5 guard tests are then deleted.
- **Controller unit files (App/Manage/Webhook) → PaymentApiIntegrationTest +
  PaymentTradeIntegrationTest**: the HTTP layer already owns these routes
  (strategy: "Do not add controller tests merely to raise line coverage"). Any
  genuinely uncovered branch (e.g. manage cancel-404, refund amount/reason
  missing) should be added to `PaymentApiIntegrationTest` instead of kept as a
  mocked-service unit test.

## Verification steps

1. Confirm the audit made no changes under `src/` or `tests/` (only this report
   was written under `docs/issues/test-audit-2026-08-09/`).
2. Before deleting any test, temporarily `--filter` the covering test to prove
   it still fails when the candidate is removed from the suite (i.e. the
   "covered by" test is the real safety net):
   ```
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter PaymentApiIntegrationTest
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter PaymentAdjustmentMultiGatewayIntegrationTest
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter PaymentTradeIntegrationTest
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter InvoiceServiceIntegrationTest
   ```
3. Deletion batches should be atomic per file and re-run the full suite on the
   CI environment (PostgreSQL) plus `composer phpstan` and
   `composer rector:types:check` per the strategy's mandatory commands.
4. Keep at least one of each duplicated pair until the coverage-campaign line
   target is consciously re-negotiated; deleting a duplicate lowers measured
   coverage even when behavioral protection is unchanged.
5. Do not delete any skipped/bug-documenting test if the module gains skipped
   tests in the future; none exist today.
