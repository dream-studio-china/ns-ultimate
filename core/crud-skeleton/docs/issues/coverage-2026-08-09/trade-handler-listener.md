# Trade module — MessageHandlers, EventListeners & OrderService coverage to ~100% & bug hunt (2026-08-09)

Scope: `src/Trade/MessageHandler/StoreOrderAcceptedHandler.php`, `StoreOrderRejectedHandler.php`, `src/Trade/EventListener/OrderInvoiceListener.php`, `OrderWorkflowListener.php`, `src/Trade/Service/OrderService.php`.
Goal: raise line coverage to ~100% and document bugs. **No code under `src/` was modified.**

## Test files added

| File | Tests | Purpose |
| --- | --- | --- |
| `tests/Trade/MessageHandler/StoreOrderAcceptedHandlerTest.php` | 8 (new) | Invalid-envelope throw, order-not-found, store-UUID mismatch, no-store-metadata, `can()==false`, and the happy `store_accept` apply. |
| `tests/Trade/MessageHandler/StoreOrderRejectedHandlerTest.php` | +6 (extended existing file) | Invalid-envelope throw, order-not-found, store-UUID mismatch, no-store-metadata, `can()==false`. Existing happy-path test kept untouched. |
| `tests/Trade/EventListener/OrderInvoiceListenerTest.php` | 17 (new, 1 skipped) | `getSubscribedEvents`, every event handler's not-found branch, already-paid skip, amount & currency mismatch guard (logger critical + return), `can()==false` skip, happy paths, partial-refund path, plus 1 skipped correct-behavior test documenting the missing payer guard. |
| `tests/Trade/EventListener/OrderWorkflowListenerTest.php` | +5 (extended existing file) | Cancel-transition outbox record with `_store` metadata (real `TradeOutboxService` + stubbed EM), and negative branches (missing/not-array/non-string store metadata, non-cancel transition). |
| `tests/Trade/Service/OrderServicePaymentsTest.php` | 28 (new, 1 skipped) | `createOrder` store-orchestration (missing workflow/outbox, `can()==false`, happy `store_submit` + outbox payload, user-reference path, user-instance path, item snapshot data), `pay()`/`refund()` remaining guard failures, `createPayment` (module missing, status guard, invoice reuse, invoice creation, non-payable-invoice reuse), `refundPayment` (module missing, no linked invoice, remaining-amount refund), `cancel()` branches, traversable calculator pipeline, plus 1 skipped correct-behavior test documenting Bug #1. |

Existing `tests/Trade/Service/OrderServiceTest.php` was **not** modified. Total added: 64 tests (62 passing + 2 documented skips).

## Coverage results

Measured by running `phpunit tests/Trade --coverage-clover` with PHP 8.5 + Xdebug (`XDEBUG_MODE=coverage`), full Trade suite green.

| File | Before | After |
| --- | --- | --- |
| `Trade/MessageHandler/StoreOrderAcceptedHandler.php` | 84.62% — lines **30, 34** | **100%** (13/13 stmts) |
| `Trade/MessageHandler/StoreOrderRejectedHandler.php` | 84.62% — lines **30, 34** | **100%** (13/13 stmts) |
| `Trade/EventListener/OrderInvoiceListener.php` | 80% — lines **30-35, 46, 49, 50, 53** | **100%** (50/50 stmts) |
| `Trade/EventListener/OrderWorkflowListener.php` | 90.2% — lines **110-114** | **100%** (51/51 stmts) |
| `Trade/Service/OrderService.php` | 90.76% — lines **113, 116, 167, 180, 206, 216, 221-225, 229, 273, 276, 281, 311, 318** | **100%** (184/184 stmts) |

Newly covered behaviour, by line:

- **StoreOrderAcceptedHandler/RejectedHandler**: line 30 `InvalidArgumentException` on malformed envelope (missing `payload`, non-array payload, missing `orderUuid`/`storeUuid`); line 34 silent-return on order-not-found, store-UUID mismatch and missing `_store` metadata.
- **OrderInvoiceListener**: `getSubscribedEvents()` mappings; `findOrder()` early-return for non-`trade_order` sources and missing orders; already-paid early return; amount/currency mismatch → `logger->critical()` + return; `workflow->can('pay')` guard; full pay-apply + `update`; refunded partial vs full transitions (`partial_refunded` only updates payment status; `refunded` + `can('refund')` applies transition + sets `refundedAt`); cancelled/failed payment-status updates.
- **OrderWorkflowListener**: cancel-transition outbox `trade.order.cancelled.v1` record with `_store.uuid` metadata (payload orderUuid/storeUuid/cancelledAt), and the negative branches for missing/`_store`-not-array/non-string uuid and non-cancel transitions.
- **OrderService**: `createOrder` store path — `Store order orchestration is not configured` (workflow or outbox null), `Order cannot be submitted for store acceptance`, happy `store_submit` + full outbox payload (items/lineId/catalogReference/delivery/customerUserUuid), `getReference` user-array path, `User`-instance path, item snapshot persistence; `pay()`/`refund()` unpersisted-user, missing-wallet, unpersisted-wallet guards; `createPayment`/`refundPayment` module-missing, status guards, invoice reuse and creation, no-linked-invoice; `cancel()` no-invoice-service/no-invoice/with-invoice branches; traversable `price_calculator` pipeline.

Full Trade suite: 343 tests, 1773 assertions, 6 skipped (4 pre-existing + 2 documented below), exit 0.

## Bugs found

### Bug #1 — `OrderService::createPayment()` reuses a non-payable invoice, breaking payment retries

- **File/line:** `src/Trade/Service/OrderService.php:280-283` (reuse branch; forwarded to `pay()` at line 302).
- **Description:** When the order already has `invoiceId`, `createPayment()` fetches that invoice and — if it is an `Invoice` — passes it straight to `InvoiceService::pay()` regardless of its status. `InvoiceService::pay()` (`src/Payment/Service/InvoiceService.php:68`) requires `workflow->can($invoice, 'start_pay')`, and `start_pay` is only allowed `from: pending` (config/packages/workflow.yaml). An invoice left over from a previous attempt in `failed` or `cancelled` status therefore makes every retry throw `InvoiceInvalidTransitionException`, surfacing as HTTP 400.
- **Impact:** After a failed or cancelled payment attempt the customer can never retry payment for that order through the standard flow; an operator must intervene manually (e.g. clear `order.invoice_id`). This is reachable today: the manage `POST /orders/{id}/payment` creates an invoice in `paying`, `markFailed`/`cancel` moves it to `failed`/`cancelled` while the order keeps `invoice_id` (see `tests/Trade/Integration/TradeOrderCancelWithInvoiceIntegrationTest.php`), and a second `/payment` call reuses it.
- **Reproduction (unit):** `OrderServicePaymentsTest::testCreatePaymentReusesInvoiceRegardlessOfItsStatus` — order confirmed, `invoice_id` = failed invoice, `invoiceService->get()` returns the failed invoice: `createInvoice` is never invoked and the failed invoice is forwarded to `pay()`.
- **Proposed fix:** only reuse the linked invoice when it is still payable, otherwise create a fresh one and re-link:
  ```php
  $invoice = null;
  if ($order->getInvoiceId() !== null) {
      $candidate = $this->invoiceService->get(['uuid' => $order->getInvoiceId()]);
      if ($candidate instanceof Invoice
          && in_array($candidate->getStatus(), [Invoice::STATUS_PENDING, Invoice::STATUS_PAYING], true)) {
          $invoice = $candidate;
      }
  }
  if (!$invoice instanceof Invoice) {
      $invoice = $this->invoiceService->createInvoice(/* ... as today ... */);
      $order->setInvoiceId($invoice->getUuid());
      // ...
  }
  ```
  (Also consider calling `invoiceService->cancel()`/`markFailed` cleanup for the discarded invoice.)

### Bug #2 (missing guard, low) — `OrderInvoiceListener::onInvoicePaid()` never verifies the payer

- **File/line:** `src/Trade/EventListener/OrderInvoiceListener.php:38-63` (only the amount/currency check at line 48 exists).
- **Description:** `onInvoicePaid()` guards invoice amount and currency against the order, but has no check that the invoice payer matches the order owner. A paid `trade_order` invoice referencing the order that belongs to a *different* user still flips the order to `paid` and fires the `pay` workflow transition. The task's expectation of a "wrong-payer guard" matches this gap: the guard does not exist.
- **Impact:** Low today — invoice creation is server-side only (`OrderService::createPayment` sets `payer` = order user) and the app-facing pay endpoint (`src/Payment/Controller/App/InvoiceController.php:42`) verifies payer ownership. However, any future flow that creates a `trade_order` invoice with a different payer (admin/agent-assisted payments, re-attribution, guest→member migration) would let an unrelated payment mark the order paid.
- **Reproduction (unit, would fail):** `OrderInvoiceListenerTest::testPaidIgnoresInvoicePaidByADifferentPayer` (skipped) — order owned by user #1, invoice paid with payer user #2, amounts match, `can('pay')==true`: current code proceeds to apply `pay` + `update`.
- **Proposed fix:** mirror the amount/currency guard:
  ```php
  $orderUser = $order->getUser();
  if ($invoice->getPayer() !== null && $orderUser !== null
      && $invoice->getPayer()->getId() !== $orderUser->getId()) {
      $this->logger->critical('Invoice/order payer mismatch', ['invoice' => $invoice->getOutTradeNo(), 'order' => $order->getUuid()]);
      return;
  }
  ```

## Observations (not bugs)

- `StoreOrderAcceptedHandler` evaluates `workflow->can()` outside `wrapInTransaction` while `StoreOrderRejectedHandler` evaluates it inside. Cosmetic inconsistency; no functional impact.
- `OrderService::pay()` / `refund()` transfer funds and set fields but neither applies the workflow transition nor flushes. This is intentional: the manage controller wraps them with `workflow->apply()` + `update()` in one transaction (`src/Trade/Controller/Manage/OrderController.php:174-178, 268-272`).
- `OrderService::fulfill()` (`src/Trade/Service/OrderService.php:264`) sets `fulfilledAt` unconditionally, unlike `OrderWorkflowListener` which only sets it when null. Double-fulfill is currently blocked by the controller's `workflow->can('fulfill')` guard, so the timestamp is safe in practice; a direct repeated service call would overwrite it.
- `createOrder()` with `user` = `['id' => N]` for a non-existent user (store path) resolves a lazy Doctrine reference; the outbox `customerUserUuid` access would surface a proxy `EntityNotFoundException` inside the transaction. Edge case; the API controllers always pass a real `User`.
- The 34 PHPUnit notices and 4 skips in the Trade suite are pre-existing (mock-without-expectation notices from older tests; environment/DB skips); this task's new tests add none.

## Skipped tests

- `OrderServicePaymentsTest::testCreatePaymentCreatesFreshInvoiceWhenExistingInvoiceIsNotPayable` — correct-behavior test for **Bug #1**: a fresh invoice should be created when the linked invoice is `failed`/`cancelled`. Fails against the current implementation (the failed invoice is forwarded to `pay()`), so it is `markTestSkipped` to keep the suite green and document the expected fix.
- `OrderInvoiceListenerTest::testPaidIgnoresInvoicePaidByADifferentPayer` — correct-behavior test for **Bug #2**: a paid invoice from a different payer should not mark the order paid. Fails against the current implementation (no payer check), so it is `markTestSkipped` to keep the suite green and document the expected guard.
