# Payment ↔ Trade + Wallet Integration — Coverage & Bug Report

**Date:** 2026-08-09
**Engineer:** PHP test engineer (automated)
**Scope:** Cross-module end-to-end tests (Payment + Trade + Wallet) + bug findings
**Constraint:** No changes under `src/` — tests + report only.

---

## 1. Summary

Added a new end-to-end integration test file covering the Payment ↔ Trade + Wallet
integration (invoice lifecycle, gateways, wallet balance adjustment providers, public
notify webhook). The suite runs against a real SQLite schema through the HTTP stack
(`WebTestCase` + `DatabaseBootstrapTrait`).

**Result:** `14 tests, 266 assertions — green` (verified across multiple runs; only
environmental shared-`var/test.db` flakiness, see §5).

Four orchestration findings were reproduced and are documented in §4 (one high-impact
bug: payments cannot be retried after a failed gateway notify).

---

## 2. Deliverables

| Artifact | Path |
|---|---|
| Test suite (new) | `tests/Integration/PaymentTradeIntegrationTest.php` |
| Report (this file) | `docs/issues/coverage-2026-08-09/payment-trade-integration.md` |

No `src/` files were modified.

---

## 3. Test coverage

### 3.1 New test file — `tests/Integration/PaymentTradeIntegrationTest.php`

Namespace `App\Tests\Integration`, `declare(strict_types=1)`, extends
`IntegrationWebTestCase`, uses `DatabaseBootstrapTrait`. All scenarios go through the
real HTTP API (`/api/v1/manage/*`, `/api/v1/app/*`, `/api/payment/notify/mock`) and are
verified against the database (`Order`, `Invoice`, `Wallet`, `WalletPaymentDeduction`).

| # | Test | Task area | Coverage hit |
|---|---|---|---|
| 1 | `testOrderPaidViaWalletGatewayMarksOrderPaidAndMovesFunds` | 1 | create order → create invoice → pay via `wallet` gateway → `InvoicePaidEvent` → trade order `paid`; `paidAt` set; wallet balance transfer verified (user −1500, system +1500) |
| 2 | `testOrderPaidViaMockGatewayAndNotifyWebhook` | 2 | mock pay (invoice `paying`) → `POST /api/payment/notify/mock` with the `MockGateway` payload → order transitions to `paid`; `transactionId` recorded |
| 3 | `testNotifyVerificationFailuresReturn400AndDoNotAdvanceOrder` | 2, 6 | wrong secret → `400 FAIL: Invalid mock payment secret.`; amount mismatch → `400 FAIL`; order/invoice unchanged |
| 4 | `testRefundFlowRefundsOrderAndReturnsMoney` | 3 | refund invoice (`wallet` gateway) → `InvoiceRefundedEvent` → trade order `refunded` + `refundedAt`; money returned (user back to 5000, system 0) |
| 5 | `testInvoiceCancellationForPendingInvoiceKeepsOrderConfirmed` | 4 | pending invoice cancelled → order stays `confirmed`, `paymentStatus=cancelled`; cancellation guard (`onInvoiceCancelled`) covered |
| 6 | `testWalletDeductionPartialReducesMockGatewayAmount` | 5 | wallet deduction 500 reduces gateway amount to 1000 (verified via `extraData.pay.amount` + `sumAppliedAmount`); notify with adjusted amount confirms; balances checked |
| 7 | `testWalletDeductionFullSkipsGatewayAndMarksPaidImmediately` | 5 | full deduction → `gatewayAmount=0`, invoice paid immediately with `payment=wallet`, no gateway call |
| 8 | `testWalletDeductionInsufficientBalanceFailsAndOrderStaysConfirmed` | 5, 6 | insufficient wallet balance → `400`, order stays `confirmed`, no money moved, no applied deduction remains |
| 9 | `testBadPathGuards` | 6 | pay non-pending (paid) invoice → 400; refund non-paid invoice → 400; cancel non-cancellable (paid) invoice → 400; unknown gateway → 400; mismatch payer (user B pays A's invoice via app endpoint) → 404; notify with unknown `outTradeNo` → `400 FAIL` |
| 10 | `testDuplicateNotifyAndDeductionAttemptAreIdempotent` | 7 | duplicate payment attempt rejected without double-deduction (single applied deduction, balances stable); duplicate notify → both `200 SUCCESS`, invoice/order stay `paid`, no money moved twice |
| 11 | `testPaymentCannotBeRetriedAfterFailedNotify` | bug repro (BUG-001) | see §4.1 |
| 12 | `testDirectInvoiceRefundOfPaidOrderLeavesOrderPaid` | bug repro (BUG-002) | see §4.2 |
| 13 | `testWalletRefundRequiresSystemWalletIdToBeResupplied` | bug repro (BUG-003) | see §4.3 |
| 14 | `testInvoicePaidWithMismatchedAmountDoesNotAdvanceOrder` | bug repro (BUG-004) | see §4.4 |

### 3.2 Cross-module paths exercised

- Payment `InvoiceService::pay/notify/cancel/refund` + `PaymentGatewayRegistry` (mock, wallet)
- Payment `PaymentAdjustmentRegistry` + Wallet `WalletBalanceAdjustmentProvider`/`WalletPaymentDeductionService`
- Trade `OrderService::createPayment/refundPayment` + `OrderInvoiceListener` (paid/refunded/cancelled/failed events)
- Wallet `WalletGateway` (pay + refund) + `TransferService`
- Public webhook `PaymentNotifyController` → `MockGateway::notify`

---

## 4. Bugs / findings

### 4.1 BUG-001 (High) — Payment cannot be retried after a failed gateway notify; order is permanently stuck

- **Location:** `src/Trade/Service/OrderService.php:280-302` (`createPayment` reuses the
  linked invoice unconditionally) + `src/Payment/Service/InvoiceService.php:67-70`
  (`pay()` requires the `start_pay` transition, which is only enabled from `pending`,
  see `config/packages/workflow.yaml:20-21`).
- **Description:** `createPayment()` reuses `$order->getInvoiceId()` whenever the order
  already has a linked invoice. If that invoice reached a terminal non-paid state
  (`failed` via a `status=failed` webhook, or `cancelled`), the retry calls
  `InvoiceService::pay()` which throws `InvoiceInvalidTransitionException` ("cannot apply
  transition `start_pay` from status `failed`"). No new invoice is created and no reset
  path exists.
- **Impact:** After a gateway reports failure through the notify webhook, the user can
  never pay the order again through the normal payment endpoints. The order stays
  `confirmed` indefinitely; only manual/DB intervention (or cancelling + creating a new
  order) recovers. This also makes the `failed` payment state a dead end for the Trade
  integration.
- **Reproduction:** `PaymentTradeIntegrationTest::testPaymentCannotBeRetriedAfterFailedNotify`
  — confirmed order → pay mock → notify `status=failed` → invoice `failed` → retry
  payment → `400`, invoice count stays 1, order stuck `confirmed`.
- **Proposed fix:** In `OrderService::createPayment`, when the linked invoice is in a
  terminal non-paid state (`failed`/`cancelled`), create a fresh invoice (and release/cancel
  the old one), or add a workflow reset (e.g., `failed → pending`) before re-attempting.
  Alternatively, surface an explicit "invoice requires reset" error instead of a generic
  invalid transition.

### 4.2 BUG-002 (Medium) — Direct invoice refund of a `paid` order returns money but leaves the order `paid`

- **Location:** `src/Trade/EventListener/OrderInvoiceListener.php:73-77` (`onInvoiceRefunded`
  only applies the order `refund` transition when `$this->workflow->can($order, 'refund')`,
  which is enabled only from `completed`, `config/packages/workflow.yaml:87-89`).
- **Description:** Refunding a paid invoice directly (e.g., `POST /api/v1/manage/invoices/{id}/refund`,
  which has no order-status guard — `src/Payment/Controller/Manage/InvoiceController.php:103-124`)
  moves the money back but the linked order remains in `paid` with `paymentStatus=refunded`.
- **Impact:** Financial/state inconsistency — money is returned while the order still
  displays as paid. Re-funding/fulfillment logic that keys on `Order::STATUS_PAID` can act
  on a refunded order.
- **Reproduction:** `testDirectInvoiceRefundOfPaidOrderLeavesOrderPaid` — order paid via
  wallet gateway → refund directly on the invoice → order still `paid`, `paymentStatus=refunded`,
  user wallet restored.
- **Proposed fix:** Block direct refunds of invoices linked to orders that cannot be
  refunded (validate order status in `refund()`/controller), or add a compensation
  transition `paid → refunded` (with a record that funds were returned), or at minimum
  surface a warning in the response.

### 4.3 BUG-003 (Medium, design gap) — Wallet refunds require `systemWalletId` to be re-supplied; the invoice does not record where it was paid to

- **Location:** `src/Wallet/Service/Payment/WalletGateway.php:80-83` (refund reads
  `systemWalletId` from `$options` or the injected default `payment.system_wallet_id`,
  which is `0` — `config/packages/payment.yaml:2`); `src/Payment/Service/InvoiceService.php:253`
  (refund derives the gateway from the invoice but the invoice stores no receiving wallet id).
- **Description:** The wallet gateway refund does not know which system wallet to pull the
  refund from unless the caller repeats `systemWalletId` in the refund request. With the
  default parameter at `0`, a wallet-paid order/invoice **cannot** be refunded unless the
  client supplies `systemWalletId` again.
- **Impact:** Refund calls fail with `400 systemWalletId is required for wallet refund.`
  when omitted; the manage order refund endpoint is dependent on the caller remembering
  the value from the original payment. (Note: `WalletPaymentDeduction` does persist its own
  `systemWalletId`, but only for deduction adjustments, not for `WalletGateway` payments.)
- **Reproduction:** `testWalletRefundRequiresSystemWalletIdToBeResupplied` — pay via wallet
  gateway, then refund via the order refund endpoint without `systemWalletId` → `400`.
- **Proposed fix:** Persist the receiving system wallet id on the invoice at payment time
  (reuse the `WalletPaymentDeduction.systemWalletId` pattern, or an invoice field), and have
  the wallet gateway fall back to it at refund time.

### 4.4 BUG-004 (Low/Medium) — `InvoicePaidEvent` amount/currency mismatch guard silently swallows the order payment

- **Location:** `src/Trade/EventListener/OrderInvoiceListener.php:48-51`.
- **Description:** If a paid invoice's `amount`/`currency` differ from the linked order's
  `totalAmount`/`currency` (e.g., a manually created invoice for the same `trade_order`
  source with a wrong amount), `onInvoicePaid` logs a `critical` line and returns. The
  invoice is `paid` but the order never advances; no error is surfaced to the caller.
- **Impact:** Silent divergence — funds captured, order not paid, only a log entry
  indicates the problem. Difficult to detect in production.
- **Reproduction:** `testInvoicePaidWithMismatchedAmountDoesNotAdvanceOrder` — order
  confirmed, manual invoice (source `trade_order`, amount 999 ≠ 1500) paid via mock →
  invoice `paid`, order stays `confirmed`, `paymentStatus` not `paid`.
- **Proposed fix:** Throw / return an explicit failure on the mismatch path (and/or refuse
  to mark the invoice paid when the linked order total doesn't match), rather than
  silently logging.

---

## 5. Environmental notes

- `var/test.db` is **shared and externally mutated** on this machine (the file's mtime
  changes every ~2–8 s while idle; drop/create runs collide). This produces intermittent
  `no such table: users`, `database is locked`, and `500` responses during otherwise
  correct test runs. The project instructions document retrying up to 3×; the new suite
  additionally contains a **resilient bootstrap** (`bootTestDatabase()` override with
  drop/create retry + schema verification) and a **self-healing `setUp`** that re-bootstraps
  if the schema vanished. The suite is green when the environment is stable
  (`14 tests, 266 assertions`).
- No tests were marked skipped — all 14 tests pass and assert current (correct or
  bug-revealing) behavior.

---

## 6. How to run

```bash
# from repo root, PHP 8.5
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Integration/PaymentTradeIntegrationTest.php --no-coverage
```
