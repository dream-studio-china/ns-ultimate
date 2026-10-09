# Trade Order Workflow — State Machine & Bad-Path Coverage Report

- Date: 2026-08-09
- Scope: `config/packages/workflow.yaml` (order state machine), `src/Trade/Entity/Order.php`, `src/Trade/Service/OrderService.php`, `src/Trade/EventListener/OrderWorkflowListener.php`, `src/Trade/Controller/App/OrderController.php`, `src/Trade/Controller/Manage/OrderController.php`, plus the HTTP endpoints they expose.
- Task: exhaustively exercise every order workflow transition (all states, guards, bad paths), find bugs.
- Constraint honored: **nothing under `src/` was modified**. Only new test files under `tests/` and this report were added.

## Test files added

All new files use `declare(strict_types=1);`.

| File | Namespace | Kind | Covers |
|---|---|---|---|
| `tests/Trade/Workflow/OrderWorkflowStateMachineTest.php` | `App\Tests\Trade\Workflow` | `KernelTestCase` (real container service `state_machine.order`, no DB) | The full order state machine: every transition from every state, invalid/duplicate/unknown transitions, enabled-transition listing, listener timestamp side-effects, guard absence probes |
| `tests/Trade/Integration/OrderWorkflowApiTest.php` | `App\Tests\Trade\Integration` | `WebTestCase` + `IntegrationWebTestCase` + `DatabaseBootstrapTrait` | End-to-end HTTP: manage `pay/fulfill/refund/todo/transitions/do/{transition}`, app `submit/confirm/cancel`, guard rejections, not-found, duplicate/unknown transitions, wallet transfers, status-reset route |

## Coverage results

Generated with `XDEBUG_MODE=coverage` over both files (clover merged). Incremental line coverage on `src/Trade/**` exercised by the two new files:

| File | Lines (covered/total) | Coverage |
|---|---|---|
| `src/Trade/EventListener/OrderWorkflowListener.php` | 86/102 | **84.3%** |
| `src/Trade/Entity/Order.php` | 108/184 | **58.7%** |
| `src/Trade/Controller/Manage/OrderController.php` | 95/302 | 31.5% |
| `src/Trade/Controller/App/OrderController.php` | 63/206 | 30.6% |
| `src/Trade/Service/OrderService.php` | 89/368 | 24.2% |
| `src/Trade/Event/*` (OrderPaid/Fulfilled/Completed/Cancelled/Refunded) | 10/10 | **100%** |
| `src/Trade/Service/Pricing/*` (calculator pipeline) | 50/118 | 42.4% |
| **Total `src/Trade/**` exercised** | 604/2004 | 30.1% |

Combined suite: **123 tests, 555 assertions, 1 intentional skip** — green (`OK, but some tests were skipped!`).

> Coverage notes: the two files target the workflow + its HTTP surface, so the low `Total src/Trade` figure is expected — commands, repositories, DTOs, message handlers and outbox (covered elsewhere) are not the subject. `OrderWorkflowListener` and the five domain events are now essentially fully covered.

## What each group covers

**Workflow state machine unit tests (no DB):**

1. **Every enabled transition from every state** (`enabledTransitionsProvider`, `validTransitionProvider`) — the exact arcs from `config/packages/workflow.yaml`:
   - `draft` → `submit`/`store_submit`/`cancel`
   - `pending` → `confirm`/`cancel`
   - `awaiting_store_acceptance` → `store_accept`/`store_reject`/`cancel`
   - `store_accepted` → `confirm`/`cancel`; `store_rejected` → `cancel`
   - `confirmed` → `pay`/`cancel`; `paid` → `fulfill`; `fulfilled` → `complete`; `completed` → `refund`
   - `cancelled`/`refunded` are terminal (no enabled transitions)
2. **Bad paths** (`invalidTransitionProvider`, ~39 cases) — applying a non-enabled transition throws `NotEnabledTransitionException` and leaves the status unchanged; unknown transition names throw `UndefinedTransitionException`; `can()` is false for them; duplicate `submit`/`pay` are rejected.
3. **Timestamp side-effects** of `OrderWorkflowListener` — `cancel→cancelledAt`, `pay→paidAt` (set only when null), `fulfill→fulfilledAt` (only when null), `complete→completedAt`, `refund→refundedAt` (only when null); `submit/confirm/store_*` set none.
4. **Guard probes** — proves `config/packages/workflow.yaml` declares NO guard rules: the workflow alone permits `pay` on an order with no amount/user/wallet, `complete` without `fulfilledAt`, and `refund` without a reason.

**HTTP integration tests (real SQLite schema):**

1. Happy path `draft→pending→confirmed→paid→fulfilled→completed→refunded` through `/do/{transition}` with timestamp assertions.
2. Store branch: `store_submit→store_accept→confirm→pay` and `store_submit→store_reject→cancel` via `/do`; `store_accept` from draft is rejected.
3. Cancel from all six cancellable states; `cancel` rejected after `paid`.
4. Duplicate `submit`/`refund` rejected with state unchanged; unknown transition rejected; `/do` on a missing order → 404.
5. `/transitions` endpoint lists the exact enabled set per state (data-driven across all states); 404 on missing order.
6. `/todo` returns orders with enabled transitions and excludes terminal (`cancelled`, `refunded`) orders.
7. `/pay` — guard rejections (draft → 400, missing `systemWalletId` → 400, missing order → 404, order without user/wallet → 400), wallet-transfer success (balances move 3000 user→system, `paidAt`+`paymentMethod` set, status `paid`), second payment rejected.
8. `/fulfill` — wrong status → 400, not found → 404, success stores `trackingNumber`/`shippingAddress` + `fulfilledAt`, status `fulfilled`.
9. `/refund` — wrong status → 400, missing `reason` → 400, not found → 404, wallet-refund success (balances restored, `refundedAt`+`refundReason` set, status `refunded`).
10. App endpoints — create/submit/confirm/cancel happy path, duplicate submit → 400, cancel after paid → 400, cross-user → 404, not found → 404.
11. Status-reset: `PUT /manage/orders/{id}/status-reset` returns **404** — the reset route is dead code for orders (see Finding 5).

## Bugs found

### Finding 1 — The order workflow has NO guard rules; `/do/{transition}` lets an admin run the whole paid lifecycle without payment (MEDIUM-HIGH)

- **File/line:** `config/packages/workflow.yaml` (order section) — no `guard:` on any transition; `src/Trade/Controller/Manage/OrderController.php:308-339` (`doTransitionAction`).
- **Description:** The workflow defines arcs only. The installed Symfony Workflow `Transition` value object no longer has a guard API (`vendor/symfony/workflow/Transition.php`), so guard expressions are impossible. The only enforcement is `OrderService` status checks (`pay` requires `confirmed`, `refund` requires `completed`, `fulfill` requires `paid`) plus controller `can()` calls. The generic `POST /manage/orders/{id}/do/{transition}` endpoint calls `can()` only — so a `ROLE_ADMIN` can move any order `draft→paid` (`do/submit`, `do/confirm`, `do/pay`), `→fulfilled`, `→completed`, `→refunded` with no wallet, no invoice, and no money movement.
- **Impact:** An order can be marked paid/fulfilled/completed/refunded without any actual payment; financial-state integrity depends entirely on every caller remembering to use the wallet endpoints rather than `/do`. This is the demo script's own happy path (`scripts/tests/demo-trade-workflow.php` step 1 uses `do/pay`), so it is intended for demos — but it is a real data-integrity hole for production.
- **Reproduction:** `OrderWorkflowApiTest::testHappyPathDraftToRefundedViaDoTransitions` (passes by design) and `OrderWorkflowStateMachineTest::testWorkflowLayerAllowsPayWithoutAmountWalletOrUser` (passes — proves no guard).
- **Proposed fix:** do not expose money transitions (`pay`/`refund`) through `/do/{transition}` — restrict `/do` to non-monetary transitions (`submit`, `store_*`, `confirm`, `fulfill`, `complete`) or require a payment reference for `pay`/`refund`. Alternatively re-add guard expressions as workflow event-listeners/blockers (guards as an API no longer exist in this Symfony version).

### Finding 2 — OrderService::pay/refund/fulfill do not apply the workflow transition (LOW / design split)

- **File/line:** `src/Trade/Service/OrderService.php:146-265` (`pay`, `refund`, `fulfill`).
- **Description:** These methods validate the current status, perform the wallet transfer / set business fields (`paidAt`, `refundedAt`, `fulfilledAt`, `trackingNumber`, `paymentMethod`, `refundReason`), but **never** call `$this->workflow->apply(...)`. The status change happens only in the controllers (`Manage/OrderController::payAction/fulfillAction/refundAction` call `workflow->apply()` inside `wrapInTransaction`). `OrderWorkflowListener` deliberately preserves a pre-set `paidAt`/`fulfilledAt`/`refundedAt` (only sets when null), which confirms the split design.
- **Impact:** Any caller that uses `OrderService::pay()` directly (a future command/worker/saga) will set `paidAt` while the order stays `confirmed` — an inconsistent state. The status checks in the methods are the only guard, and they don't protect against a direct caller skipping the transition.
- **Reproduction:** unit-level — `OrderService::pay()` on a confirmed order sets `paidAt` and leaves `status === 'confirmed'` (covered by existing `OrderServiceTest`).
- **Proposed fix:** either have `OrderService::pay/refund/fulfill` apply the matching workflow transition themselves, or document loudly that callers must pair each service call with the workflow transition.

### Finding 3 — `doTransitionAction` forwards arbitrary request-body fields to `update()`, enabling order-data tampering (MEDIUM — previously documented)

- **File/line:** `src/Trade/Controller/Manage/OrderController.php:329`.
- **Description:** `/do/{transition}` passes the whole decoded body to `OrderService::update()`, which applies any settable property via the serializer (no whitelist, unlike `updateAction()` which restricts to `notes`/`metadata`).
- **Impact:** `POST /manage/orders/{id}/do/submit {"totalAmount": 1}` rewrites the order total; the same holds for `currency`, `paidAt`, etc. Bypasses the ordinary update whitelist.
- **Reproduction:** `OrderWorkflowApiTest::testDoTransitionMustNotForwardArbitraryBodyFieldsToUpdate` — **skipped** because it asserts the correct behaviour (tampered `totalAmount` must NOT persist) and currently fails. Full detail in `docs/issues/coverage-2026-08-09/trade-controllers.md` (Bug 2).

### Finding 4 — App `submit`/`confirm` propagate workflow/DB failures as HTTP 500 (MEDIUM — previously documented)

- **File/line:** `src/Trade/Controller/App/OrderController.php:158-166` (`applyUserOrderTransition`), used by `submitAction`/`confirmAction`.
- **Description:** Unlike `cancelAction`/`paymentAction` (which `try/catch` and return 400), `applyUserOrderTransition()` performs `workflow->apply()` inside `wrapInTransaction` with no `catch`; a failure escapes as an unhandled 500 instead of the API's `400 {code,message}` contract.
- **Reproduction:** covered conceptually by `OrderWorkflowApiTest::testAppSubmitOnAlreadySubmittedOrderIsRejected` (guard path returns 400 cleanly) — the 500 path needs a mid-transaction throw and is pinned in `tests/Trade/Controller/App/OrderControllerTest.php` (skipped correct-behaviour test). Full detail in `trade-controllers.md` (Bug 1).

### Finding 5 — `status-reset` endpoint is dead code and would not work on orders (LOW)

- **File/line:** `src/Core/View/WorkflowApiViewMixin.php:104-109` (`resetMarkingAction`).
- **Description:** No controller uses `WorkflowApiViewMixin` (verified by grep — only the mixin file itself and its unit test reference it), so `PUT /manage/orders/{id}/status-reset` is not a route on the order controllers and returns **404** (`OrderWorkflowApiTest::testStatusResetRouteIsNotRegisteredOnOrderControllers`). Even if wired up, `resetMarkingAction()` calls `$entity->setStatus([])` — an array into `Order::setStatus(string)` → `TypeError` — and the route placeholder `{id}` does not match the `$entity` argument name. (The placeholder mismatch is already flagged in `docs/issues/coverage-2026-08-09/core-view.md`.)
- **Impact:** The documented "reset" workflow flow does not exist for orders; the mixin is latent broken code.
- **Proposed fix:** either delete the mixin or fix it (`$entity->setStatus(Order::STATUS_DRAFT)` + argument named `$id`), and add the route to the order controllers if reset is actually wanted.

### Finding 6 — Order entity has no STATUS_ constants for the store states (INFO)

- **File/line:** `src/Trade/Entity/Order.php:19-26`.
- **Description:** `Order::STATUS_*` covers only `draft/pending/confirmed/paid/fulfilled/completed/cancelled/refunded`. The store states `awaiting_store_acceptance`, `store_accepted`, `store_rejected` are bare string literals across `OrderService`, controllers and message handlers.
- **Impact:** typos/renames are not caught by static analysis; inconsistent with the constant-based statuses.
- **Proposed fix:** add `STATUS_AWAITING_STORE_ACCEPTANCE`, `STATUS_STORE_ACCEPTED`, `STATUS_STORE_REJECTED` and use them.

### Finding 7 — Raw exception messages returned to API clients (LOW — codebase-wide convention)

- **File/line:** every `catch (\Throwable $e)` in both order controllers returns `$e->getMessage()` verbatim (e.g. `Manage/OrderController.php:180,205,231,274,335`).
- **Impact:** internal wallet/gateway/DB messages leak to clients. Noted only — this is the established convention across the codebase (see `trade-controllers.md` Finding 3).

## Skipped items (correct-behaviour tests that fail against current src)

- `App\Tests\Trade\Integration\OrderWorkflowApiTest::testDoTransitionMustNotForwardArbitraryBodyFieldsToUpdate` — **skipped**; asserts `totalAmount` in a `/do/submit` body must NOT persist. Fails against current code (Finding 3). (Mirrors the already-skipped unit test in `tests/Trade/Controller/Manage/OrderControllerTest.php`.)
- (Reference only) `App\Tests\Trade\Controller\App\OrderControllerTest::testSubmitActionReturnsWarningWhenTransitionFails` and `...Manage\OrderControllerTest::testDoTransitionDoesNotForwardArbitraryFieldsToUpdate` — previously skipped in `trade-controllers.md`; not duplicated here.

## Verified non-bugs (investigated and cleared)

- **Workflow allows `pay` from `store_accepted`?** No — `confirm` must come first (`confirm: from: [pending, store_accepted]`); `pay` is only from `confirmed`. Covered by `invalidTransitionProvider` + integration happy path.
- **`pending → awaiting_store_acceptance`?** Not a transition — `store_submit` is from `draft` only (the docs `context.md` §7.1 diagram is a simplification). Tested per the actual `workflow.yaml`.
- **Refund from `paid`/`fulfilled` (task phrasing "paid/…→refunded")?** The config only allows `refund: from: completed`. `refundAction` correctly guards on `can('refund')`; direct `do/refund` from `paid` is rejected. Confirmed by `testRefundEndpointRejectsNonCompletedOrder` + `invalidTransitionProvider`.
- **Manage `refundAction` invoice branch skipping the workflow transition** — not a bug: `InvoiceService::refund()` dispatches `InvoiceRefundedEvent` and `OrderInvoiceListener::onInvoiceRefunded()` applies the `refund` transition (covered by existing `TradePaymentIntegrationTest`).
- **`/todo` pagination/query params** — `list(null, null, false)` intentionally evaluates the request; no filter params are sent, and `assertPrivilegedQueryParameters` allows plain admin GETs. Only a scalability TODO in the mixin, not a correctness bug.

## Final test run

```
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
    tests/Trade/Workflow/OrderWorkflowStateMachineTest.php \
    tests/Trade/Integration/OrderWorkflowApiTest.php --no-coverage

OK, but some tests were skipped!
Tests: 123, Assertions: 555, Skipped: 1.
```

(Individual runs are identical; the shared `var/test.db` occasionally shows transient `no such table`/`database is locked` failures when concurrent test runners in this environment drop/recreate the schema — wait 10-15 s and re-run, per the project convention.)
