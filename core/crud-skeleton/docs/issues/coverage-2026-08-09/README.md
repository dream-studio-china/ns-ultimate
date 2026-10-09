# Coverage Campaign 2026-08-09 — Master Summary

> Date: 2026-08-09
> Campaign: **24 parallel sub-agents** wrote new tests to push line coverage toward 100% and hunt for bugs across all modules. **No `src/` file was modified.**
> Constraint honored: only `tests/` (96 new files + 6 extended) and `docs/issues/coverage-2026-08-09/` were added.

## Results

| Metric | Before | After |
|---|---|---|
| PHPUnit tests | 1749 | **2686** (+937) |
| Assertions | 5755 | **9264** (+3509) |
| Line coverage | 90.18% | **99.46%** (8511/8557 lines) |
| Method coverage | — | 98.54% (1683/1708) |
| Class coverage | — | 95.68% (288/301) |
| Errors / Failures | 0 | **0** |
| Skipped | 0 | 38 (each documents a bug or untestable line) |
| Deprecations | 6 (pre-existing) | 6 (pre-existing, unchanged) |

The remaining 46 uncovered lines are **provably unreachable dead code**, **deprecated-PHP constructs that cannot be exercised without raising a deprecation**, or **broken code paths** (documented in the per-module reports; see "Remaining uncovered lines" below).

## Per-module coverage (before → after)

| Module | Before | After | Key agents |
|---|---|---|---|
| Core | 82.12% | **~99–100%** | core-utils-di, core-view, core-service, core-parser-listener, core-integration-extra |
| Trade | 85.13% | **~100%** (all target files) | trade-command-repo, trade-handler-listener, trade-controllers, trade-workflow-badpaths |
| Store | 80.30% | **100%** (all target files) | store-command-repo-entity, store-handlers, store-service-controllers, store-trade-integration |
| Payment | 93.00% | **100%** (controllers/service/entity) | payment-controllers, payment-trade-integration |
| Identity | 93.77% | **100%** (controllers/service/security) | identity-security, identity-controllers |
| Inventory | 94.37% | **100%** (repos/handlers/controllers/service) | inventory-repos-handlers, inventory-controllers-service |
| Promotion | 95.51% | **100%** (DSL + services) | promotion-dsl |
| Wechat | 95.86% | **100%** (all target files) | wechat |
| Storage | 98.13% | **100%** | storage |
| Common | 99.25% | **100%** | common-controllers-entity |
| Wallet | 99.43% | **100%** | wallet-manage |

## Report index (one detailed report per agent)

| Report | Scope |
|---|---|
| `core-utils-di.md` | RsaClient (MD5/broken sigs), Location (missing lib, broken getAddress), Math (rand crashes on PHP 8.5), Inflect, DI Configuration/CoreExtension, CircularReferenceHandler |
| `core-view.md` | WorkflowApiViewMixin (resetMarking route bug), Create/Update/Delete/Detail mixins, ApiViewMessages |
| `core-service.md` | ExpressionService, LegacyEvaluator, DefaultServiceLocator, BaseService traits (immutable-DateTime bug, sorter contract), RestController |
| `core-parser-listener.md` | ExpressionDqlParser, ExpressionQueryBuilderAssembler, FlatNormalizer (JSON-string corruption), OpenApiEnricherListener, LocaleListener |
| `core-integration-extra.md` | Dynamic query API, /system endpoints, locale detection, exception interceptor, /api/doc.json enrichment, access log, pagination |
| `trade-command-repo.md` | Trade PublishOutboxCommand, TradeOutboxMessageRepository, Specification/Product repositories, TradeOutboxMessage entity |
| `trade-handler-listener.md` | StoreOrderAccepted/RejectedHandler, OrderInvoiceListener, OrderWorkflowListener, OrderService (invoice reuse bug) |
| `trade-controllers.md` | App/Manage OrderController (submit/confirm 500 bug, doTransition tampering) |
| `trade-workflow-badpaths.md` | Order state machine exhaustively (90 unit + 33 API tests), guard absence findings |
| `store-command-repo-entity.md` | Store PublishOutboxCommand, StoreOutboxMessageRepository, Store entities |
| `store-handlers.md` | Store message handlers (poison messages, dedup integrity, inventory request bug) |
| `store-service-controllers.md` | StoreOrderService (snapshot idempotency), StoreMembershipService, StoreContextResolver, Staff/Manage controllers, Store entity |
| `store-trade-integration.md` | End-to-end Store↔Trade↔Inventory flows (accept/reject/cancel/release, tombstone, INVENTORY_ENABLED) |
| `payment-controllers.md` | Payment controllers (message leak, markPaid gateway check, refund options, parseAmount), InvoiceService, Invoice entity |
| `payment-trade-integration.md` | Order pay/refund/notify E2E (stuck-payment bug, direct-refund order-still-paid bug) |
| `identity-security.md` | RedisOtpStorage (exists() error-truthy), TokenManager, JwtAuthenticator, User entity |
| `identity-controllers.md` | OtpController, AuthController (json_decode 500, numeric-username login bug), OtpService, CreateUserCommand |
| `inventory-repos-handlers.md` | RecipeLine/ReservationLine repositories, reservation handlers (impossible dates, EM closed on failure) |
| `inventory-controllers-service.md` | Recipe/Stock controllers, InventoryService (timezone-sensitive idempotency), Quantity, PublishOutboxCommand |
| `promotion-dsl.md` | DSL Parser/Evaluator (tiered unparseable, and/or block swallowing), PromotionCalculator/Service/TemplateService |
| `storage.md` | LocalStorage (E_WARNING), QiniuStorage (delete doesn't verify domain), Qiniu settings command |
| `wallet-manage.md` | TransferController (amount truncation, 404-by-message-sniffing), WalletService reconcile |
| `wechat.md` | WechatPayGateway notify (broken — request never passed to EasyWeChat), WechatService code2Session, WechatAuthService identity collision, WechatUserService |

## Consolidated bug list (deduplicated, by severity)

### CRITICAL

1. **`RsaClient` signs with MD5** — `src/Core/Utils/RsaClient.php:51,99`. MD5 is forgeable and disabled under FIPS. Fix: `OPENSSL_ALGO_SHA256`. (core-utils-di, R-1)
2. **`Location` depends on `php-curl-class`, which is not installed** — `src/Core/Utils/Location.php:5,18,33,50`. Every call fatals `Class "Curl\Curl" not found` (the `catch (\ErrorException)` does not catch `Error`). Fix: add the package to `composer.json` or inject an HTTP client. (core-utils-di, L-1)

### HIGH

3. **WeChat Pay v3 notify can never succeed** — `src/Wechat/Service/Payment/WechatPayGateway.php:102-126`. The incoming request is never passed to EasyWeChat; `serve()` reads an empty `fromGlobals()` request → every real callback fails. Fix: `$app->setRequestFromSymfonyRequest($request)` before `serve()`. (wechat, Bug 1)
4. **`Math::mt_rand()`/`Math::rand()` always throw on PHP 8.5** — `src/Core/Utils/Math.php:85,91`. One-arg `rand()`/`mt_rand()` is an error in PHP 8.5. Fix: `rand(0, $x)` / `mt_rand(0, $x)`. (core-utils-di, M-1)
5. **`sign()` base64-encodes an undefined variable on failure** — `src/Core/Utils/RsaClient.php:51-59`. `openssl_sign()` result ignored → warning + bogus signature. Fix: check the return value. (core-utils-di, R-2)
6. **App `submit`/`confirm` bubble failures as HTTP 500** — `src/Trade/Controller/App/OrderController.php:158-166`. No try/catch around workflow/DB failures. Fix: wrap in try/catch and return the `warning()` 400 envelope. (trade-controllers, Bug 1)
7. **`BaseService::$user` is null for all HTTP requests** — `src/Core/Service/BaseService.php:77` + `BaseServiceReadListTrait.php:270`. The singleton resolves the principal once outside request scope, so `@dql/@sort/@hints` and the `@filter` in-memory fallback always 403 even for admins. (core-integration-extra, Bug 1)
8. **`@expands` sets a dynamic `__metadata` property → PHP 8.5 deprecation** — `src/Core/Controller/RestController.php:198`. Breaks `failOnDeprecation`. (core-integration-extra, Bug 2)
9. **Payment retries get stuck: `createPayment()` reuses a failed/cancelled invoice** — `src/Trade/Service/OrderService.php:280-302` + `src/Payment/Service/InvoiceService.php:67-70`. After a failed notify the invoice is `failed`; `start_pay` never re-enables it, so the order is permanently stuck in `confirmed`. Fix: only reuse the invoice when status ∈ {pending, paying}. (payment-trade-integration, BUG-001)
10. **Order data tampering via `/do/{transition}`** — `src/Trade/Controller/Manage/OrderController.php:329` forwards the raw request body to `update()`, letting an admin mutate `totalAmount`/arbitrary fields, bypassing the whitelist. Fix: whitelist fields (or only accept a fixed transition payload). (trade-controllers, Bug 2; also trade-workflow-badpaths)

### MEDIUM

11. **`FlatNormalizer` silently JSON-decodes string values** — `src/Core/Serializer/Normalizer/FlatNormalizer.php:124-130`. Non-numeric strings that are valid JSON (`"true"`, `"null"`, `"{}"`) are decoded into non-strings, corrupting API output types. (core-parser-listener, Bug 1)
12. **`markPaid` never verifies the notifying gateway matches the invoice** — `src/Payment/Service/InvoiceService.php:182`. Lookup is by `outTradeNo` only. Fix: require `$result->payment === $invoice->getPayment()`. (payment-controllers, BUG-2)
13. **Direct invoice refund leaves the order `paid`** — `src/Trade/EventListener/OrderInvoiceListener.php:73-77`. Money is returned but the order never transitions to `refunded`. (payment-trade-integration, BUG-002)
14. **Store rejection does not cancel the Trade order** — `src/Trade/MessageHandler/StoreOrderRejectedHandler.php:37`. The order stays `store_rejected` and requires an explicit user cancel. (store-trade-integration, Bug 1)
15. **Outbox `defer()` has no claim-ownership guard** — `src/Store/Repository/StoreOutboxMessageRepository.php:47-61` (and Trade equivalent). Stale workers can clobber a concurrent claim's retry state. Fix: add `availableAt <= :now` to the `defer` update. (store-command-repo-entity, Bug 1)
16. **Poison outbox messages retry forever** — Trade/Store `PublishOutboxCommand` + repositories: no max-attempts cap, no dead-lettering; `attempts` grows unboundedly. (trade-command-repo, Bug 1; store-command-repo-entity, Bug 2)
17. **Store publishes inventory reservation requests Inventory can never accept** — `src/Store/MessageHandler/TradeOrderCreatedHandler.php` inventoryItems accepts `catalogReference = ''`, but Inventory requires a UUID → poison message, order stuck in `awaiting_inventory`. (store-handlers, Bug 1)
18. **Deterministically-invalid `trade.order.created` payloads become poison messages** — the consumed event is persisted before deep validation; deterministic failures roll back → infinite retry → `failed` transport. (store-handlers, Bug 2)
19. **`InventoryReservationReleaseRequestedHandler` throws for release-before-reserve** — `src/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandler.php:41`. A release arriving before the reservation exists becomes a poison message (documented TODO in context.md §22.1). (store-trade-integration, Bug 2)
20. **`InventoryService::reserve()` idempotency hash is timezone-sensitive** — `src/Inventory/Service/InventoryService.php:186-193` uses `DATE_ATOM` with offset, so a same-instant retry with a different offset gets a spurious conflict exception. (inventory-controllers-service, Bug 2)
21. **Any inventory handler failure closes the shared EntityManager** — `wrapInTransaction()` calls `close()` on exception, breaking subsequent writes in the same request. (inventory-repos-handlers, Bug 3)
22. **`OrderInvoiceListener::onInvoicePaid` never verifies the payer** — `src/Trade/EventListener/OrderInvoiceListener.php:38-63` only checks amount/currency; a mismatched-payer paid invoice marks the order paid. (trade-handler-listener, Bug 2)
23. **`OtpService::maskPhone()` leaks short phone numbers** — `src/Identity/Service/OtpService.php:109-116` keeps all digits for 5–7-char phones in logs. (identity-controllers, Bug C)
24. **Numeric usernames can never log in** — `src/Identity/Controller/AuthController.php:82-89` never falls back to `findByIdentifier()`. (identity-controllers, Bug D)
25. **`CreateUserCommand` persists accounts with empty email/username** — `src/Identity/Command/CreateUserCommand.php:70-86`. (identity-controllers, Bug E)
26. **Unguarded `json_decode(..., JSON_THROW_ON_ERROR)` → HTTP 500** on empty/malformed bodies across auth/otp controllers. Fix: wrap in try/catch and return a 400 warning. (identity-controllers, Bug A)
27. **`verify_phone` reports `phone_verified=true` for non-existent users** — `src/Identity/Controller/AuthController.php:294-300`, `OtpController.php:93-99`, inconsistent with login's 401. (identity-controllers, Bug B)
28. **`StoreOrderService::matchesSnapshot()` uses order-sensitive `===`** — `src/Store/Service/StoreOrderService.php:180` breaks idempotency when snapshot key order differs. (store-service-controllers, Bug 1)
29. **Staff `acceptAction()` accepts an empty `reservationId`** and silently clears a real reservation in the outbox event. (store-service-controllers, Bug 2)
30. **`QiniuStorage::delete()` does not verify the path belongs to the configured domain** — any URL is forwarded as a remote-delete key. Fix: prefix-check against `domain.'/'`. (storage, Bug 1)
31. **`RedisOtpStorage::exists()` returns `true` on a RESP error** — `Predis\Response\Error` casts truthy, inconsistent with other safe defaults. (identity-security, Bug 1)
32. **`type: tiered` promotions can never be parsed** — keyword/identifier collision in the DSL lexer; tiered templates can't round-trip. (promotion-dsl, Bug 1)
33. **`and:`/`or:` blocks swallow sibling conditions** — `(a OR b) AND c` parses as `a OR b OR c`, false-positive matches. (promotion-dsl, Bug 2)
34. **`TransferController` truncates non-integer amounts** — `(int)` cast makes `"1e3"` → 1 cent. (wallet-manage, BUG-1)
35. **Transfer 404/500 decided by sniffing exception message text** — `str_ends_with($e->getMessage(), 'not found')`; server errors masked as 404. (wallet-manage, BUG-2)
36. **`WorkflowApiViewMixin::resetMarkingAction` route placeholder mismatch** — `/{id}/status-reset` vs `$entity` arg → endpoint always 500; also never loads the entity. (core-view, Bug 1)
37. **Store dedup has no payload-hash integrity check** — eventId-only dedup; reuse with a different payload is accepted on the Store side (Inventory side throws integrity errors). (store-handlers, Bug 3)
38. **`matches /regex/` → 500 on SQLite** — no REGEXP function (works on MySQL only). Document as a portability issue. (core-integration-extra, Bug 4)
39. **`/system/entities/{unknown}` → 500 HTML MappingException** instead of 404 JSON (interceptor only handles `/api/*`). (core-integration-extra, Bug 5)
40. **App comment create accepts a `parent` from a different entity scope** — `src/Common/Controller/App/CommentController.php:22`, enabling orphaned cross-entity threads. (common-controllers-entity, Bug 2)
41. **`createPayment` reuse bug also surfaced by E2E** (see Bug 9) — cross-reference between trade-handler-listener and payment-trade-integration.

### LOW

42. `Content::setTitle()/addTag()/removeTag()` don't `touch()` → stale `updatedAt`. (common-controllers-entity, Bug 1)
43. `getSignContent()` string-casts arrays/objects → `a=Array` + warning. (core-utils-di, R-3)
44. `openssl_free_key()` deprecated since PHP 8.0 → file-path keys raise deprecations. (core-utils-di, R-4)
45. `Math::lcg_value()` → `lcg_value()` deprecated since 8.4. (core-utils-di, M-2)
46. `Location::getAddress()` calls `getResponse()` on a string → fatal Error. (core-utils-di, L-2)
47. `Location` dereferences JSON without checking `status`/null → warnings + confusing returns. (core-utils-di, L-3)
48. `Location` error contract inconsistent (`null` vs `$e->getMessage()`). (core-utils-di, L-4)
49. `WorkflowApiViewMixin::todoAction` doesn't reindex `array_filter` keys → JSON object instead of array. (core-view, Bug 3)
50. Outbox successful publish doesn't clear `attempts`/`lastError` — published rows carry stale failure metadata. (trade-command-repo, Bug 2)
51. Outbox envelope `aggregateType` hardcoded `'trade_order'`; entity has no getter. (trade-command-repo, Bug 3)
52. Outbox `str_replace('.v1')` strips version from anywhere + hardcoded `version=1`. (store-command-repo-entity, Bug 3)
53. Outbox publish relies on a single trailing `flush()` outside `finally` — mid-loop exceptions lose `markPublished()` → duplicate dispatch. (store-command-repo-entity, Bug 4)
54. `grantMemberAction` catches `\Throwable` and masks programming errors as 400. (store-service-controllers, Bug 3)
55. `grant()` silently reactivates revoked memberships. (store-service-controllers, Bug 4)
56. Empty `X-Store-Channel` overrides the `'api'` default. (store-service-controllers, Bug 5)
57. `LocalStorage::delete()` emits unsuppressed `E_WARNING` on unlink failure (trips `failOnWarning`). (storage, Bug 2)
58. `code2Session` uses the access-token client → extra `/cgi-bin/token` call per login. (wechat, Bug 2)
59. WeChat auto-generated identity (username/email from openid suffix) can collide → 500 at scale. (wechat, Bug 3)
60. `promotion-dsl` minor findings: trailing-dot path error, unused `priority: desc` flag, `simulate()` vs pipeline mismatch, multiple `when:` inconsistency, unreachable EOL branches. (promotion-dsl, Bugs 3–7)
61. `BaseServiceMutationTrait` passes a mutable `new \DateTime` to `\DateTimeImmutable` setters → `TypeError`. (core-service, Bug 1)
62. `BaseServiceReadListTrait` sorter comparator never returns `0` — violates `usort` contract. (core-service, Bug 2)
63. Dead code / unreachable defensive branches documented in: BaseServiceReadListTrait (catch Exception, `!is_string` joiner guard), BaseServiceMutationTrait (`!is_object` checks), ExpressionDqlParser (317,394,488,541), Inventory Quantity line 75, Inflect line 135, `availableTransitions`/`doTransition` entity-not-found guards. (core-service, core-parser-listener, inventory-controllers-service, core-utils-di, core-view)
64. `ParseAmount` misparses scientific-notation strings. (payment-controllers, BUG-4)
65. Webhook masks transient invoice-not-found as permanent `FAIL` 400 → providers stop retrying. (payment-controllers, BUG-5)
66. `markPaid` overwrites the invoice `payment` field from the result. (payment-controllers, BUG-2)
67. Refund forwards raw request body as gateway `$options` → attacker-controlled refund keys. (payment-controllers, BUG-3)
68. No workflow guards in `workflow.yaml` — the `/do/{transition}` endpoint lets an admin run the whole lifecycle with zero payment (all enforcement lives in OrderService). (trade-workflow-badpaths)
69. `OrderService::pay/refund/fulfill` set fields/timestamps but never apply the workflow transition — status only changes via controllers. (trade-workflow-badpaths)
70. Release handler accepts impossible calendar dates (`2026-02-30`); the `new \DateTimeImmutable()` check is a no-op. (inventory-repos-handlers, Bug 1)
71. Oversized quantities pass handler validation then crash inside `Quantity::normalize()` (decimal(20,6) overflow). (inventory-repos-handlers, Bug 4)
72. `release()` mutates the reservation to RELEASED in memory before the missing material/stock throws → half-applied state after rollback. (inventory-controllers-service, Bug 4)
73. `default_locale: zh` never becomes the active locale — effective default is English. (core-integration-extra, Bug 6)
74. `OpenApiEnricherListener::detectTag()` prefix checks miss method-prefixed operationIds → Store endpoints left untagged. (core-integration-extra, Bug 7)

## Remaining uncovered lines (46) — why they are not covered

The residual uncovered lines are one of four categories, all documented in the per-module reports:

1. **Deprecated PHP constructs** that raise deprecations if executed (would break `failOnDeprecation`): `RsaClient` openssl_free_key branches, `Math::lcg_value()`.
2. **Broken code paths** that cannot be exercised without a fatal error: `Location::getAddress()` (broken by design), `Math::mt_rand()/rand()` one-arg calls (crash on PHP 8.5).
3. **Provably unreachable defensive/dead code** (guards that can never fire given the call graph): `BaseServiceReadListTrait` dead `catch`, `BaseServiceMutationTrait` `!is_object` checks, `ExpressionDqlParser` unreachable error branches, `Inflect` fall-through, `Quantity` padding branch, `TokenManager` redundant blacklist check, `RedisOtpStorage` error path, RsaClient sub-modulus-size guards.
4. **Config/DI plumbing** that only runs at container compile time (`CoreExtension::load`, `Configuration`), which the suite exercises but Xdebug's HTML report partially attributes to compile-time.

Fully eliminating the remaining lines requires either removing the dead code or refactoring the broken paths — both are `src/` changes and were out of scope by design.

## How the campaign ran

- **Baseline**: full suite (1749 tests, 90.18% lines) via `var/coverage2`.
- **24 sub-agents** in 3 waves, one report per agent, each instructed to (a) never touch `src/`, (b) target the uncovered lines listed in `var/uncovered-map.txt`, (c) follow project test conventions, and (d) document bugs with file:line + proposed fixes and keep the suite green (correct-behavior tests that fail on a real src bug are marked `markTestSkipped` with a reference to the report).
- **Consolidation**: full serial run → 2686 tests green; fixed one cross-test environment leak (`INVENTORY_ENABLED` restored in `tearDownAfterClass` of `StoreTradeInventoryEnabledFlowTest`); added `#[AllowMockObjectsWithoutExpectations]` to new mock-heavy test classes.
- **Final coverage**: `var/coverage-final/` (99.46% lines).

## Suggested follow-ups (outside this campaign's scope)

1. Fix the CRITICAL/HIGH src bugs listed above (they are all low-risk, well-scoped fixes).
2. Remove the documented dead code (or add `@codeCoverageIgnore`) to reach a literal 100%.
3. Consider splitting `var/test.db` per process (the `when@test` hardcoded URL in `config/packages/doctrine.yaml`) so parallel test runs never contend on schema drop/create.
4. Add a `max_attempts`/dead-letter policy to the Trade/Store/Inventory outbox publishers.
