# Trade Order Controllers — Coverage & Bug Report

- Date: 2026-08-09
- Scope: `src/Trade/Controller/App/OrderController.php`, `src/Trade/Controller/Manage/OrderController.php`
- Task: raise line coverage of both controllers to ~100% and find bugs.
- Constraint honored: **nothing under `src/` was modified**. Only new test files under `tests/` and this report were added.

## Test files added

All new files use `declare(strict_types=1);`.

| File | Namespace | Covers |
|---|---|---|
| `tests/Trade/Controller/App/OrderControllerTest.php` | `App\Tests\Trade\Controller\App` | `App\Trade\Controller\App\OrderController` |
| `tests/Trade/Controller/OrderControllerServiceFake.php` | `App\Tests\Trade\Controller` | support double (see note below) |
| `tests/Trade/Controller/Manage/OrderControllerTest.php` (extended) | `App\Tests\Trade\Controller\Manage` | `App\Trade\Controller\Manage\OrderController` |

### Why a fake service was needed

`OrderServiceInterface` declares `wrapInTransaction()` **only via a `@method` PHPDoc tag**, and PHPUnit 12 no longer generates mock methods for PHPDoc-declared methods (`createMock()` yields a mock without `wrapInTransaction` — verified). The controllers call `$this->service->wrapInTransaction(...)` in `payAction`/`fulfillAction`/`refundAction`/`cancelAction`/`doTransitionAction`, so those paths could not be exercised with a plain mock.

`OrderControllerServiceFake` implements `OrderServiceInterface` and delegates every real interface method to a backing PHPUnit mock (so `$service->method('get')->with(...)->willReturn(...)` keeps working exactly as in the existing tests) while adding a real `wrapInTransaction()` that either invokes the callback (`invokeTransaction = true`) or behaves like a no-op. The existing Manage `OrderControllerTest::setUp()` now builds the controller with `new OrderController(new OrderControllerServiceFake($this->service), ...)`; all 15 pre-existing tests still pass unchanged.

## Coverage results

Baseline uncovered lines (from `var/uncovered-map.txt`, generated 2026-08-09 07:39) vs. status after the new tests. Verified by running

```
XDEBUG_MODE=coverage php vendor/bin/phpunit \
    tests/Trade/Controller/App/OrderControllerTest.php \
    tests/Trade/Controller/Manage/OrderControllerTest.php \
    --coverage-clover /tmp/cov.xml
```

and diffing the `count="0"` statement lines against the baseline list. Every baseline-uncovered line is now executed by the new unit tests.

| Controller | Baseline uncovered lines | After new tests |
|---|---|---|
| `App\Trade\Controller\App\OrderController` (was 78.64%) | 41,80,81,88,90,91,92,95,98,99,100,101,102,112,182,200,201,211,216,220,228,229 | **all 22 lines covered** |
| `App\Trade\Controller\Manage\OrderController` (was 84.77%) | 66,67,74,76,77,78,81,84,85,86,87,88,176,177,183,204,205,230,231,264,270,271,277 | **all 23 lines covered** |

Coverage already provided by the existing integration suite (create success paths, ownership-pass, cancel/payment success, list/detail, transitions, todo, etc.) was not duplicated.

### What each group of tests covers

**App controller**

- `commonFilter()` line 41 (`['id' => -1]` for anonymous users) via `listAction()` with a `null` token (`testListActionWithoutUserUsesMinusOneIdFilter`).
- `createAction` 201 (no store), 202 (store context), and the `catch` path 80–81 (price engine throws).
- `quoteAction` — empty items (88,90,91,92), success including custom currency + meta (95,98,99,100), and failure (101,102).
- `itemsAction` not-found branch (112).
- `cancelAction` not-found (182) and transaction-failure branch (200,201) via `workflow->apply` throwing inside the fake transaction.
- `paymentAction` — not-found (211), cross-user ownership (216), workflow guard (220), gateway failure (228,229), and success forwarding for `mock`/`wallet`/`wechat` (data provider) asserting the method + options reach `createPayment`.

**Manage controller**

- `createAction` `catch` (66,67).
- `quoteAction` — empty items (74,76,77,78), success (81,84,85,86), failure (87,88).
- `payAction` success path through the transaction closure (176,177,183) — wallet pay + workflow apply + update + 200.
- `paymentAction` success (203) and failure (204,205).
- `fulfillAction` failure (230,231) with `service->fulfill` throwing inside the transaction.
- `refundAction` wallet paths — missing `systemWalletId` (264) and full wallet-refund success (270,271,277).

### How the security container is wired for unit tests

`AbstractController::getUser()` needs a container exposing `security.token_storage`. The App tests inject a mocked `ContainerInterface` whose `has()/get()` serve a mocked `TokenStorageInterface` + `TokenInterface`, so `getCurrentUser()` returns a `User` mock with a configurable id (or `null` for the anonymous case). The existing Manage tests never call `getUser()`, so they are unaffected.

## Bugs found

### Bug 1 — App `submitAction`/`confirmAction` let workflow/DB failures bubble up as HTTP 500 (HIGH)

- **File/line:** `src/Trade/Controller/App/OrderController.php:158-166` (`applyUserOrderTransition()`), used by `submitAction` (123-132) and `confirmAction` (134-143).
- **Description:** Unlike `cancelAction` (194-202) and `paymentAction` (226-230), which wrap `wrapInTransaction(...)`/`workflow->apply(...)` in `try/catch` and return a 400 warning, `applyUserOrderTransition()` performs the transition inside `wrapInTransaction()` with **no `try/catch`**. Any exception (workflow `NotEnabledTransitionException`, DB failure, listener error) propagates out of the action.
- **Impact:** A failed submit/confirm returns an unhandled exception → HTTP 500 (Symfony exception handling) instead of the consistent `400 {"code":400,"message":...}` JSON every other mutation on the same controller returns. API clients see an inconsistent contract; in dev the raw exception may be exposed.
- **Reproduction:** (verified with a probe test) — owner matched, `workflow->can($order,'submit') === true`, then `workflow->apply($order,'submit')` throws. Result: `submitAction()` **throws** `RuntimeException('submit boom')`; `cancelAction()`/`paymentAction()` in the same file return a 400 warning for the equivalent failure.
- **Correct-behaviour test:** `App\Tests\Trade\Controller\App\OrderControllerTest::testSubmitActionReturnsWarningWhenTransitionFails` asserts a 400 response; it **fails** against the current code and is therefore **skipped** (see Skipped items).
- **Proposed fix:**
  ```php
  try {
      $this->service->wrapInTransaction(function () use ($order, $transition) {
          $this->workflow->apply($order, $transition);
      });
  } catch (\Throwable $e) {
      return $this->warning($e->getMessage(), 400, '', 400);
  }
  return $this->success($order, $successMessage);
  ```

### Bug 2 — Manage `doTransitionAction` forwards arbitrary request fields to `update()`, allowing order-data tampering (MEDIUM)

- **File/line:** `src/Trade/Controller/Manage/OrderController.php:322-332` (specifically `:329` `$this->service->update($entity, $content);`).
- **Description:** The `/do/{transition}` endpoint passes the **entire decoded request body** to `OrderService::update()`. `BaseServiceMutationTrait::update()` (`src/Core/Service/Concern/BaseServiceMutationTrait.php:38+`) applies **any settable property** of the entity via the serializer (`object_to_populate`), not just a whitelist. By contrast, `updateAction()` (105-112) explicitly restricts mutations to `notes` and `metadata`.
- **Impact:** A `ROLE_ADMIN` caller can mutate protected order data — e.g. `totalAmount`, `currency`, `paidAt`, `trackingNumber` — simply by including it in the transition body: `POST /manage/orders/{id}/do/complete {..., "totalAmount": 1}` permanently rewrites the order total (the later `workflow->apply()` only rewrites `status`). This bypasses the field whitelist enforced by the ordinary update endpoint and lets a transition call change pricing data.
- **Reproduction:** `Order` exposes `totalAmount` + `setTotalAmount()`; after the reflection loop in `update()`, the remaining keys are deserialized onto the entity, so `totalAmount` is set. The controller's `can()` guard runs before the update and only checks the transition, not the payload fields.
- **Correct-behaviour test:** `App\Tests\Trade\Controller\Manage\OrderControllerTest::testDoTransitionDoesNotForwardArbitraryFieldsToUpdate` asserts the update payload excludes `totalAmount`; it **fails** against the current code and is therefore **skipped**.
- **Proposed fix:** whitelist the fields accepted by `doTransitionAction` (e.g. `notes`, `reason`, `trackingNumber`, `shippingAddress`) before calling `update()`, mirroring `updateAction()`. Additionally, run `workflow->apply()` first and then persist only the safe fields, so status/total can never be tampered with through the transition body.

### Bug 3 — Raw exception messages returned to API clients (LOW / consistent with codebase)

- **File/line:** every `catch (\Throwable $e)` block in both controllers returns `$e->getMessage()` verbatim: App `createAction:81`, `quoteAction:102`, `cancelAction:201`, `paymentAction:229`; Manage `createAction:67`, `quoteAction:88`, `payAction:180`, `paymentAction:205`, `fulfillAction:231`, `refundAction:274`, `doTransitionAction:335`.
- **Description:** Internal exception messages (DB constraints, wallet/gateway internals, serializer errors) are exposed to API clients in the `message` field.
- **Impact:** Information disclosure / confusing client errors. Note this is a codebase-wide convention (the same pattern exists in `src/Core/View/*`), so it is recorded here only as a note, not treated as a regression.
- **Proposed fix:** log the real exception and return a stable, user-safe message (e.g. `ApiViewMessages::UNKNOWN_ERROR` / the existing `RestController::UNKNOWN_ERROR`).

## Skipped items

- `App\Tests\Trade\Controller\App\OrderControllerTest::testSubmitActionReturnsWarningWhenTransitionFails` — **skipped** because it asserts the correct behaviour (submit/confirm failures must return a 400 JSON warning like cancel/payment) and would **fail** against the current code (Bug 1).
- `App\Tests\Trade\Controller\Manage\OrderControllerTest::testDoTransitionDoesNotForwardArbitraryFieldsToUpdate` — **skipped** because it asserts the correct behaviour (transition bodies must not mutate arbitrary order fields) and would **fail** against the current code (Bug 2).

## Verified NON-bugs (investigated and cleared)

- **Manage `refundAction` invoice branch (259-260)** — it returns after `refundPayment()` without applying the `refund` workflow transition in the controller. This is **not** a bug: `InvoiceService::refund()` dispatches `InvoiceRefundedEvent`, and `Trade\EventListener\OrderInvoiceListener::onInvoiceRefunded()` applies the `refund` transition and sets `refundedAt`. Confirmed by the existing integration test `TradePaymentIntegrationTest::testOrderPaymentAndRefundThroughInvoiceEvents` (order ends in `STATUS_REFUNDED`).
- **App `createAction` store-context 202 path** — order status is not left as a plain draft: `OrderService::createOrder()` applies the `store_submit` transition and records the `trade.order.created.v1` outbox message in the same transaction (`src/Trade/Service/OrderService.php:111-140`).
- **Invalid payment methods on `paymentAction`** — the controller forwards any string, but validation happens service-side: `InvoiceService::pay()` resolves the method through the gateway registry and throws on unknown methods (caught → 400). Covered by the App unit tests asserting `mock`/`wallet`/`wechat` are forwarded.
- **`doTransitionAction` "can() fails" path returning HTTP 200** — this is asserted by the existing integration tests (`testOrderCannotCancelAfterPaid` expects 200 + non-zero code), so it is intentional.
- **`wrapInTransaction` availability on `OrderServiceInterface`** — it is a `@method`-only declaration, so `createMock()` cannot generate it under PHPUnit 12; the fake service was introduced for this reason (this is a test-infrastructure note, not a product bug).

## Final test run

```
XDEBUG_MODE=off php vendor/bin/phpunit \
    tests/Trade/Controller/App/OrderControllerTest.php \
    tests/Trade/Controller/Manage/OrderControllerTest.php --no-coverage

OK, but some tests were skipped!
Tests: 44, Assertions: 165, Skipped: 2.
```

44 test cases (18 App cases incl. 3 payment-method data-provider cases, 26 Manage cases incl. the 15 pre-existing ones), 0 failures/errors/notices/warnings/deprecations, 2 skipped (Bug 1 and Bug 2 documentation).
