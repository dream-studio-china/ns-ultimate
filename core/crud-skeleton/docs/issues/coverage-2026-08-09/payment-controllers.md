# Payment Controllers — Coverage & Bug Report

- Date: 2026-08-09
- Scope: `src/Payment/Controller/{Manage,App,Webhook}/InvoiceController|PaymentNotifyController`, `src/Payment/Service/InvoiceService.php`, `src/Payment/Entity/Invoice.php`
- Constraint honored: **no `src/` files modified** — only test files under `tests/` and this report were added.

## 1. Coverage results

Coverage measured by running the full `tests/Payment` suite with
`--coverage-filter src/Payment` (PHP 8.5.1, PHPUnit 12.5.30).

| File | Before | After | Lines added |
|---|---|---|---|
| `src/Payment/Controller/Manage/InvoiceController.php` | 74.55% | **100%** | 66, 67, 80, 81, 82, 83, 92, 98, 99, 108, 113, 121, 122, 131 |
| `src/Payment/Controller/Webhook/PaymentNotifyController.php` | 77.78% | **100%** | 33, 34 |
| `src/Payment/Controller/App/InvoiceController.php` | 81.82% | **100%** | 49, 50 |
| `src/Payment/Service/InvoiceService.php` | 94.57% | **100%** | 43, 69, 77, 83, 86, 155, 178, 200, 217, 279 |
| `src/Payment/Entity/Invoice.php` | 98.21% | **100%** | 194 |

Full suite result: `OK (76 tests, 341 assertions)`.

## 2. Test files added (all under `tests/`, all green)

| File | Coverage targeted | Notes |
|---|---|---|
| `tests/Payment/Controller/Manage/InvoiceControllerTest.php` | Manage controller guard/catch branches | 14 unit tests; mocks `InvoiceServiceInterface`, `EntityManagerInterface`, `WorkflowInterface` + `#[AllowMockObjectsWithoutExpectations]` + `setRequestStack/setSerializer/setTranslator` (OrderControllerTest pattern). Covers create/pay/cancel/refund/transitions success, not-found (404), and service-throws (400) branches, plus `parseAmount('12.34') == 1234` and payer resolution. |
| `tests/Payment/Controller/App/InvoiceControllerTest.php` | App controller catch branch (49, 50) | 3 unit tests; real `Container` with `security.token_storage` returning an authenticated `User` (PromotionControllerTest pattern). Covers success, service-throws, and payer-mismatch 404. |
| `tests/Payment/Controller/Webhook/PaymentNotifyControllerTest.php` | generic `\Throwable` catch (33, 34) | 4 unit tests; real `PaymentGatewayRegistry` + `MockGateway`. Covers success response, unknown-gateway `FAIL`, verification `FAIL: message`, and service-throws `FAIL`. |
| `tests/Payment/Service/InvoiceServiceTest.php` | service guard/edge branches | 9 unit tests; real `Container` + mocked EM/Workflow/Dispatcher, real `PaymentGatewayRegistry`(MockGateway)/`PaymentAdjustmentRegistry`, plus an inline `FakeAdjustmentProvider` fixture. Covers negative amount, `start_pay` guard, deduction-exceeds-amount, `gateway`/`tradeType` options, notify-invoice-not-found, `mark_paid`/`fail`/`cancel`/refund transition guards. |
| `tests/Payment/Entity/InvoiceCoverageTest.php` | `Invoice::prePersist()` re-init of `createdAt` (194) | Uses `ReflectionClass::newInstanceWithoutConstructor()` + `ReflectionProperty::isInitialized()`; deliberately avoids the deprecated `ReflectionProperty::setAccessible()` (typed non-nullable `createdAt` cannot be set to `null`). |

Skipped tests: **none** — every correct-behavior test passes against current `src/`.

## 3. Bugs found (no source changes made)

### BUG-1 — Exception messages leaked to API clients (High)

- Location: `src/Payment/Controller/Manage/InvoiceController.php:67,83,99,122` and `src/Payment/Controller/App/InvoiceController.php:50`
- Description: every `catch (\Throwable $e)` returns `$e->getMessage()` verbatim in the HTTP 400 body (`return $this->warning($e->getMessage(), 400, '', 400);`). Internal exceptions (Doctrine/SQL failures, gateway internals, unexpected runtime errors) are surfaced to end users instead of a generic message.
- Impact: information disclosure; leak of internals/file paths/SQL text; inconsistent with the Webhook controller, which deliberately masks non-verification errors as `FAIL`.
- Reproduction: post an invoice pay/cancel/refund that triggers any `\Throwable` (e.g. unregistered gateway) and observe the raw exception text in the 400 response body.
- Proposed fix: catch domain exceptions (e.g. `InvoiceInvalidTransitionException`, `InvoiceAmountMismatchException`, `InvalidArgumentException`) and map them to their messages, but wrap unexpected `\Throwable` in a generic `warning` message (optionally log the original).

### BUG-2 — `markPaid` does not verify the notifying gateway/payment matches the invoice (Medium)

- Location: `src/Payment/Service/InvoiceService.php:182` (`$invoice->setPayment($result->payment);`); lookup at `:153` is by `outTradeNo` only.
- Description: `markPaid` validates amount and currency but never checks that `$result->payment` equals the payment method recorded on the invoice (set during `pay()`). Any notify result with a matching amount+currency transitions the invoice to paid and silently overwrites the `payment` field.
- Impact: defense-in-depth gap — a notify routed to the wrong gateway, or a direct service call with a wrong `payment`, would be accepted and could rewrite the recorded payment method; downstream logic (refund routing, reporting) then uses the wrong gateway.
- Reproduction: `pay()` an invoice via `mock`, then call `handleNotifyResult()`/`markPaid()` with `payment: 'wechat'` and the correct amount/currency; the invoice becomes `paid` with `payment = wechat`.
- Proposed fix: in `markPaid`, require `$result->payment === $invoice->getPayment()` (or the intended gateway) before applying the transition; throw `InvoiceAmountMismatchException`/`InvoiceInvalidTransitionException` otherwise.

### BUG-3 — Refund forwards the raw request body as gateway `$options` (Medium/Low)

- Location: `src/Payment/Controller/Manage/InvoiceController.php:118` (`... $content` as options) → `src/Payment/Service/InvoiceService.php:264` (`$gateway->refund(..., $options)`).
- Description: the full request body is passed through as `$options` to the gateway refund call. A client can include provider options such as `refundId` in the body; `MockGateway` uses `$options['refundId']` directly as the idempotency key, so a caller can override the provider refund reference.
- Impact: untrusted input reaches gateway options; refund idempotency keys are attacker-controlled (low impact with `MockGateway`, latent with real gateways that honor option keys).
- Reproduction: `POST /api/v1/manage/invoices/{id}/refund` with `{"amount": 100, "reason": "x", "refundId": "spoofed-id"}` — the stored `extraData` and returned `refundId` become `spoofed-id`.
- Proposed fix: whitelist the options forwarded to the gateway in `InvoiceService::refund()` (and `pay()`), or strip body-only keys (`amount`, `reason`) before forwarding.

### BUG-4 — `parseAmount` misparses non-decimal string amounts (Low)

- Location: `src/Payment/Controller/Manage/InvoiceController.php:137-142`
- Description: `parseAmount` decides decimal vs integer by `str_contains($amount, '.')`. Scientific-notation strings such as `"1e3"` contain no `'.'` and are cast directly: `(int) "1e3"` → `1` (silently truncating 1000 to 1 cent). Strings like `"1E2"`, `"0.5e2"`, or localized separators behave unexpectedly.
- Impact: a client sending a valid JSON string `"1e3"` as `amount` creates an invoice for 1 cent instead of 1000; amounts are silently wrong.
- Reproduction: `POST /api/v1/manage/invoices` with `"amount": "1e3"` → invoice `amount = 1`.
- Proposed fix: only treat strings as decimals when the value is not already an integer string (`(string)(int)$v === $v`), or delegate parsing to a strict decimal parser (e.g. `MoneyParser` / `decimal` comparison via `is_numeric` + regex).

### BUG-5 — Webhook masks transient failures as permanent `FAIL` 400 (Low)

- Location: `src/Payment/Controller/Webhook/PaymentNotifyController.php:33-34`
- Description: the generic `\Throwable` catch returns HTTP 400 with body `FAIL`. This covers both transient conditions (invoice-not-found because the webhook arrived before the invoice was persisted — a retryable condition) and permanent ones (unknown gateway name).
- Impact: payment providers commonly stop retrying after a 4xx. A gateway webhook arriving out-of-order (before the create/pay transaction commits) would be dropped permanently, leaving the invoice `paying` forever.
- Reproduction: `POST /api/payment/notify/mock` with a valid secret and an `outTradeNo` that does not exist yet → 400 `FAIL` (invoice-not-found), indistinguishable from a permanent configuration error.
- Proposed fix: return a retry-friendly code (e.g. HTTP 200 with a `FAIL` body, or 5xx) for transient conditions such as invoice-not-found, and reserve 4xx for verification/permanent failures. Distinguish via exception type.

## 4. Notes

- The `database is locked` errors encountered during coverage runs are the documented shared `var/test.db` issue; retries after a 10-15 s wait produced a clean `OK (76 tests, 341 assertions)` run.
- The webhook/App/Manage controller tests are pure unit tests (no DB); the pre-existing `tests/Payment/Integration/*` DB tests were left untouched and continue to pass.
- No `src/` files were changed; no tests were skipped.
