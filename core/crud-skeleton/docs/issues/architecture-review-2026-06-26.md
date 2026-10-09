# Architecture Review Issues - 2026-06-26

This document records architecture and production-readiness issues found during a review of `docs/ai/context.md` and the current codebase.

## Summary

The overall modular architecture is reasonable for a Symfony API skeleton. Module boundaries are clear, `Core` provides reusable CRUD/API infrastructure, business modules are separated by domain, and extension points such as payment gateways, pricing calculators, workflow, and domain events are well aligned with the project's goals.

The main risks are not structural failure, but overly broad generic capabilities being exposed by default, production security boundaries that need tightening, and transaction boundaries around payment flows.

## High Priority

### 1. WeChat payment gateway may not be registered

**Impact:** `payment=wechat` may fail with `PaymentGatewayNotFoundException` even though `WechatPayGateway` implements `PaymentGatewayInterface`.

**Evidence:**

- `PaymentGatewayRegistry` only collects services tagged with `payment.gateway`: `src/Payment/Service/PaymentGatewayRegistry.php`.
- `config/services.yaml` excludes `src/Wechat/Service/Gateway/WechatPayGateway.php` from the global `App\` service resource.
- `src/Wechat/Resources/config/services_wechat.yaml` explicitly defines `WechatPayGateway`, but does not add `payment.gateway` tag.

**Recommendation:**

Add an explicit tag in `services_wechat.yaml`:

```yaml
App\Wechat\Service\Gateway\WechatPayGateway:
    arguments:
        $notifyUrl: '%env(WECHAT_PAY_NOTIFY_URL)%'
    tags: ['payment.gateway']
```

Alternatively, add an `_instanceof` rule inside the WeChat service config for `PaymentGatewayInterface`.

### 2. `/system/*` introspection endpoints are effectively public

**Impact:** Entity metadata and route structure can be exposed in production.

**Evidence:**

- System controllers are routed under `/system`, for example `src/Core/Controller/System/EntityController.php`.
- The configured API firewall only matches `^/api` in `config/packages/security.yaml`.
- `access_control` rules only restrict `/api` paths and do not cover `/system`.

**Recommendation:**

Choose one of these approaches:

1. Move system routes under `/api/system` so the API firewall applies.
2. Add explicit `access_control` rules for `^/system` requiring `ROLE_ADMIN`.
3. Disable system routes in production and keep them dev/test-only.

### 3. Dynamic query features are too powerful for default public API use

**Impact:** Clients can submit raw or near-raw query controls, which increases data exposure and performance risk.

**Evidence:**

- `@dql` is read from the request and passed to Doctrine query creation in `BaseServiceReadListTrait`.
- `@select`, `@groupBy`, `@hints`, and `@showDQL` are also supported by request parameters.
- `ListApiViewMixin` documents these parameters for all generic list endpoints.

**Recommendation:**

Introduce capability levels for query parameters:

- Public/App endpoints: allow only `page`, `limit`, constrained `@order`, and safe `@filter`.
- Manage endpoints: optionally allow richer query features for admins.
- Debug/dev only: allow `@dql`, `@hints`, and `@showDQL`.

Also consider removing these dangerous parameters from generated public OpenAPI docs.

### 4. Payment and wallet transaction boundaries need tightening

**Impact:** External payment calls may be executed while a database transaction is open. Nested wallet transactions may not behave as expected without explicit savepoint support.

**Evidence:**

- `InvoiceService::pay()` opens a transaction and then calls `$gateway->pay()` inside it.
- `WechatPayGateway::pay()` performs outbound WeChat API calls.
- `TransferService` manually starts transactions with `beginTransaction()`.
- Comments in `Trade\Controller\Manage\OrderController` assume nested savepoint behavior, but no explicit Doctrine savepoint configuration was found.

**Recommendation:**

Separate local and external payment flows:

- Wallet gateway: keep local balance movement and invoice state update atomic.
- External gateways such as WeChat: persist invoice as `paying`, commit, call provider, then finalize through callback/notify.
- If nested transactions are required, explicitly enable and test savepoint behavior.

## Medium Priority

### 5. App order lifecycle may be incomplete

**Impact:** App users can create orders in `draft`, but payment requires the order to be able to transition from `confirmed` to `paid`.

**Evidence:**

- `Order` initial status is `draft`.
- Workflow requires `draft -> pending -> confirmed -> paid`.
- App payment action checks `workflow->can($order, 'pay')`.
- No App endpoint was found for submit/confirm.

**Recommendation:**

Clarify intended business flow:

- If admin confirmation is required, document it clearly.
- If users should pay immediately, create orders directly as `confirmed`, or add an App submit flow that moves the order to a payable state.

### 6. `BaseService` mixes service logic with request-level concerns

**Impact:** Service classes become harder to reuse outside HTTP and harder to reason about in tests or CLI contexts.

**Evidence:**

- `BaseService` captures current user from token storage.
- `BaseServiceReadListTrait::list()` reads query parameters from the current request.
- Query syntax, request filtering, Doctrine, serialization, and validation are concentrated in the base service layer.

**Recommendation:**

Move request parsing toward controllers or dedicated Query DTO/query builder objects over time. Keep service methods focused on explicit inputs and domain operations.

### 7. `BaseService::new()` can bypass entity constructors

**Impact:** Entities with required constructor invariants may be created in invalid states.

**Evidence:**

- `BaseServiceMutationTrait::new()` uses `newInstanceWithoutConstructor()` when the entity constructor has required parameters.
- `Wallet` requires a `User` and currency in its constructor.

**Recommendation:**

Use dedicated factory/create methods for entities with required invariants. Avoid exposing generic create endpoints for those entities unless the controller constructs them safely.

### 8. Pagination has no maximum limit

**Impact:** A client can request a very large `limit`, causing heavy DB queries or large serialized responses.

**Evidence:**

- `RestController::pagination()` uses `max(1, request limit)` with default `100`, but does not cap upper bound.

**Recommendation:**

Add a global max, for example `min(200, max(1, $request->query->getInt('limit', 100)))`, or make the maximum configurable.

### 9. In-memory fallback filtering can load too much data

**Impact:** If DQL compilation fails, the service loads the full result set and filters in PHP, which can be expensive on large tables.

**Evidence:**

- `BaseServiceReadListTrait::list()` falls back to `$query->getResult()` and then `array_filter()` when filter compilation fails or `@sort` is used.

**Recommendation:**

For production API endpoints, fail fast with `400 Bad Request` when filter compilation fails. Keep in-memory fallback only for dev/debug or explicitly small collections.

## Low Priority / Cleanup

### 10. Generic OpenAPI docs expose implementation controls

**Impact:** Public API docs advertise internal query controls like `@dql`, `@hints`, and `@showDQL`.

**Recommendation:**

Split OpenAPI parameter documentation by endpoint type or remove dangerous controls from the generic mixin docs.

### 11. Service wiring has duplicated gateway tagging conventions

**Impact:** Payment gateways under `src/Payment/Service/Gateway/` are tagged by both the root `_instanceof` rule and `services_payment.yaml`, while WeChat gateway is defined separately and currently not tagged.

**Recommendation:**

Centralize gateway tagging in one consistent convention, preferably `_instanceof PaymentGatewayInterface` for all imported module service configs.
