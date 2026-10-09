# Core Integration (HTTP Kernel) — Coverage & Bug Report

- **Date:** 2026-08-09
- **Scope:** Core framework behaviors exercised end-to-end through the HTTP kernel: dynamic query system, System introspection endpoints, locale detection, ExceptionInterceptor, OpenApiEnricherListener, AccessLogListener, RestController pagination.
- **Constraint:** no changes under `src/`; only test files added under `tests/` + this report.
- **Runner:** `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit <files> --no-coverage`

## 1. Summary

Six new WebTestCase integration test files were added under `tests/Integration/` (namespace `App\Tests\Integration`, `declare(strict_types=1)`, using `DatabaseBootstrapTrait`):

| File | Tests | Pass | Skipped | Covers |
|---|---|---|---|---|
| `tests/Integration/CoreDynamicQueryApiTest.php` | 23 | 18 | 5 | Task 1: `@filter/@dql/@order/@sort/@select/@groupBy/@expands/@display/@transform` + malformed params |
| `tests/Integration/CoreSystemEndpointsApiTest.php` | 10 | 9 | 1 | Task 2: `/system/entities`, `/system/entities/{name}`, `/system/router`, translated field names, not-found entity |
| `tests/Integration/CoreLocaleDetectionApiTest.php` | 9 | 9 | 0 | Task 3: `?_locale`, Accept-Language mapping, message translation per locale |
| `tests/Integration/CoreExceptionInterceptorApiTest.php` | 7 | 7 | 0 | Task 4: uncaught exception / 404 route / 403 JSON envelopes; success & warning envelope shapes |
| `tests/Integration/CoreOpenApiEnricherApiTest.php` | 9 | 8 | 1 | Task 5: `/api/doc.json` module tags, no operation-type tags left |
| `tests/Integration/CoreAccessLogAndPaginationApiTest.php` | 7 | 7 | 0 | Tasks 6 + 7: real access-log lines; pagination edge cases |
| **Total** | **65** | **58** | **7** | |

Run (single invocation, one shared schema bootstrap):

```
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Integration/CoreDynamicQueryApiTest.php \
  tests/Integration/CoreSystemEndpointsApiTest.php \
  tests/Integration/CoreLocaleDetectionApiTest.php \
  tests/Integration/CoreExceptionInterceptorApiTest.php \
  tests/Integration/CoreOpenApiEnricherApiTest.php \
  tests/Integration/CoreAccessLogAndPaginationApiTest.php --no-coverage
```

Result: **65 tests, 478 assertions, 0 failures, 0 errors, 0 deprecations, 7 skipped**.

## 2. Coverage hit (integration tests only, measured with the listed `--coverage-filter`s)

| File | Lines hit via HTTP-kernel tests |
|---|---|
| `src/Core/Controller/System/EntityController.php` | **100%** (44/44) — full real-metadata path, both actions, translation |
| `src/Core/Controller/System/RouterController.php` | **100%** (3/3) |
| `src/Core/Controller/RestController.php` | 70.66% (118/167) — `success()/warning()/pagination()/requestProcess()` incl. `@display`, `@expands`, `@transform` paths |
| `src/Core/EventListener/AccessLogListener.php` | 83.33% (35/42) — real `monolog.logger.access` stream, real token storage |
| `src/Core/EventListener/ExceptionInterceptor.php` | 95.65% (22/23) — real `kernel.exception` for 500/404/403 API routes |
| `src/Core/EventListener/LocaleListener.php` | 91.18% (31/34) — real `kernel.request`, both `?_locale` and Accept-Language |
| `src/Core/EventListener/OpenApiEnricherListener.php` | 91.41% (117/128) — real `/api/doc.json` enrichment |
| `src/Core/EventListener/ControllerListener.php` | 94.44% (17/18) — write-method logging |
| `src/Core/Parser/ExpressionDqlParser.php` | 52.02% (116/223) — real `@filter` → DQL pipeline (compiler + validation) |
| `src/Core/Parser/ExpressionQueryBuilderAssembler.php` | 46.15% (30/65) — real fragment assembly |
| `src/Core/Serializer/Normalizer/FlatNormalizer.php` | 54.55% (36/66) — real response normalization |
| `src/Core/View/{ListApiViewMixin,DetailApiViewMixin,CreateApiViewMixin,TransformContent}.php` | 100% / 93.33% / 74.60% / 44.78% — real controller-trait flows |

The System controllers (`EntityController`, `RouterController`) were only unit-tested before; the HTTP-kernel tests now exercise them with a real EntityManager/router (100%).

## 3. Verified correct behaviors (positive tests)

- `@filter` compiles to DQL and filters correctly (`entity.getSortOrder() > 0` → subset; `==`; `matches "slug-1"` → LIKE; single-attribute truthy `entity.getName()` → IS NOT NULL). 200 + correct `paginator.total`.
- `@order=entity.sortOrder|DESC` → correct ordering.
- `@select=entity.id,entity.name` → projection only (no `slug`/`description`).
- `@groupBy` + `@select` → grouped rows.
- `@display=reduce` and `@display=["entity.name"]` field projection.
- `@transform` on create (`Math.ceil(3.7)` → `"4"`).
- `limit=abc` / `page=abc` → **400** (`BadRequestHttpException`, an exception-interceptor JSON envelope) — the one malformed-param case that correctly 4xxs.
- `/system/entities` list, `/system/entities/{name}` metadata (types/columnName/nullable + ManyToOne targetEntity), `/system/router` route dump — all 200 with `{data,code:0,message:"SUCCESS"}`.
- Field-name translation via `?_locale=zh|zh_Hant|ja` and `Accept-Language: en-US`.
- Message translation per locale: `Entity is not found` → zh `实体未找到。`, zh_Hant `實體未找到。`, ja `エンティティが見つかりません。`, en `Entity is not found`.
- `/api/doc.json`: module tags present (`Auth,System,Wechat,Products,Orders,Categories,…,Payment,Wallet`), no `List/Detail/Create/Update/Delete/Workflow` left in `tags` or on any operation; key endpoints map to the correct module.
- AccessLogListener writes real lines (`@testauth#1 POST /api/v1/manage/categories | 201 | REQ: … | RES: …`) and does not log GETs.
- Pagination: page beyond range → empty items + correct `has_next=false`; negative page → coerced to 1; `limit=0` → coerced to 1; full `{total,page,limit,pages,has_previous,has_next}` shape.
- Envelope shapes: success list `{data,code,message,paginator}`; success detail `{data,code,message}` (no paginator); warning `{code,message,raw_data}` (no `data`); exception `{code,message,class}`.

## 4. Bugs found

### BUG-1 — BaseService::$user is always null for JWT-authenticated HTTP requests → all admin-gated query params 403 — **HIGH**

- **Location:** `src/Core/Service/BaseService.php:77` (`$this->user = $token ? $token->getUser() : null;`), consumed by `src/Core/Service/Concern/BaseServiceReadListTrait.php:119-120` (filter fallback admin gate), `:270-271` (`assertPrivilegedQueryParameters`), `:291-295` (`hasAdminRole`).
- **Description:** `BaseService::$user` is resolved **once at construction** from `security.token_storage`. The service classes are container singletons and are instantiated outside the per-request token context, so `$user` is permanently `null` for HTTP requests. Root cause proven empirically: during the request the token storage *does* contain the authenticated user (roles `ROLE_ADMIN,ROLE_USER`), but the container-cached service instance has `user=null`, while `new CategoryService($container)` built at the same moment has `user=ROLE_ADMIN,ROLE_USER`.
- **Impact:** `@dql`, `@sort`, `@hints` always throw `AccessDeniedHttpException` (403) even for ROLE_ADMIN users; `@filter` expressions that cannot compile to DQL (needing the in-memory `LegacyEvaluator` fallback) also always 403 instead of falling back. The documented dynamic-query privilege system is unusable over HTTP. Secondary impact: `src/Trade/Service/OrderService.php:55` reads the same `$this->user` for the price-pipeline context.
- **Reproduction:** with a ROLE_ADMIN JWT, `GET /api/v1/manage/categories?@dql=SELECT c.id FROM App\Common\Entity\Category c` → `403 {code:403, message:"@dql is restricted to administrators.", class:"…AccessDeniedHttpException"}`.
- **Proposed fix:** resolve the user from the token storage at request time (e.g. inject `TokenStorageInterface` and read the user inside `list()`/`hasAdminRole()`), or mark BaseService subclasses request-scoped; do not cache the principal in a long-lived singleton.
- **Tests:** `testDqlRestrictedForEveryoneCurrently`, `testSortRestrictedForEveryoneCurrently`, `testHintsRestrictedForEveryoneCurrently`, `testInvalidFilterFallsToAdminGateCurrently` (regression, pass); `testDqlAllowedForAdminReturnsFilteredResults`, `testSortAllowedForAdminSortsInMemory` (correct-behavior, **skipped**).

### BUG-2 — `@expands` creates a dynamic `__metadata` property → PHP 8.5 deprecation → breaks `failOnDeprecation` — **HIGH**

- **Location:** `src/Core/Controller/RestController.php:198` (`$node->__metadata = $node;`).
- **Description:** Expansion attaches a dynamic property to entities. On PHP 8.2+ this is deprecated (`Creation of dynamic property …::__metadata is deprecated`). The repo’s `phpunit.dist.xml` sets `failOnDeprecation="true"`, so **any** request using `@expands` turns the suite red.
- **Impact:** `@expands` is effectively untestable/unsafe in CI and should be considered broken. (Observed additionally: the serialized expanded relation came back `null` in this setup, so expansion had no visible payload effect.)
- **Reproduction:** `GET /api/v1/manage/categories?@expands=["parent"]` → deprecation from `RestController.php:198`.
- **Proposed fix:** declare a typed `public mixed $__metadata = null;` on the entities that may be expanded (or use a `#[AllowDynamicProperties]`/external map), and make `expandObjects`/`expandObjectToMetadata` only attach the metadata the serializer needs.
- **Tests:** `testExpandsReturnsMetadataWithoutDeprecation` (**skipped**, references this bug).

### BUG-3 — malformed `@select` (array) returns HTTP 500 instead of 400 — **MEDIUM**

- **Location:** `src/Core/Service/Concern/BaseServiceReadListTrait.php` (`if (!is_string($select)) throw new ValidatorException('@select must be a string.');`), surfaced uncaught through `src/Core/View/ListApiViewMixin.php:listAction()` (no try/catch) into the `ExceptionInterceptor`.
- **Description:** `@select[]=a&@select[]=b` makes `$select` an array; the `ValidatorException` is not an `HttpException`, has code 0, so `ExceptionInterceptor` maps it to **500**, not 400.
- **Impact:** clients get a 500 (server error) for a request that is simply malformed.
- **Reproduction:** `GET /api/v1/manage/categories?@select[]=entity.id&@select[]=entity.name` → `500 {"message":"@select must be a string.","class":"…ValidatorException"}`.
- **Proposed fix:** catch `ValidatorException` in `ListApiViewMixin::listAction()` and return `warning(…, 400, …, 400)`, or make the read-list trait throw a `BadRequestHttpException`.
- **Tests:** `testArraySelectReturns500Currently` (regression, pass); `testArraySelectReturns400` (correct-behavior, **skipped**).

### BUG-4 — `matches` with a regex literal crashes on SQLite (no REGEXP function) — **MEDIUM (environment)**

- **Location:** `src/Core/Parser/ExpressionDqlParser.php` (`matches` → `REGEXP(%s, %s) = TRUE`).
- **Description:** `/pattern/flags` compiles to a `REGEXP()` call; SQLite has no `REGEXP` function, so the query fails at execution with `no such function: REGEXP`. Plain LIKE-style `matches "slug-1"` works fine.
- **Impact:** regex filtering works on MySQL but 500s in the SQLite test environment (and on any SQLite deployment).
- **Reproduction:** `GET /api/v1/manage/categories?@filter=entity.getSlug() matches "/^slug-1$/i"` → `500 {"message":"…no such function: REGEXP","class":"…DriverException"}`.
- **Proposed fix:** guard REGEXP by DB platform (e.g. register an SQLite REGEXP user-function in tests / use `LIKE` fallback), or document that regex matching is MySQL-only.
- **Tests:** `testRegexMatchesWorksOnCurrentDb` (**skipped**).

### BUG-5 — System endpoints return 500 HTML (not 404 JSON) for unknown entities — **MEDIUM**

- **Location:** `src/Core/EventListener/ExceptionInterceptor.php:21` (`EFFECTIVE_PATTERN = '/^\/(api)\/.*$/'`); `src/Core/Controller/System/EntityController.php:66-67` (`getClassMetadata` throws for unknown FQCN).
- **Description:** the interceptor only handles `/api/*` paths, so a `MappingException` (unknown entity) on `/system/entities/{name}` bypasses the JSON error envelope and renders Symfony’s HTML error page (500). The docs in `docs/ai/context.md` §12 imply these are API-ish system endpoints; consumers get HTML.
- **Impact:** no JSON error contract for the System endpoints; a bad entity name is a 500 (server error) rather than a 404.
- **Reproduction:** `GET /system/entities/App/Does/Not/Exist` → `500 text/html` with `Doctrine\Persistence\Mapping\MappingException: Class 'App\Does\Not\Exist' does not exist`.
- **Proposed fix:** extend the interceptor pattern to `/system` (or catch `MappingException` in `EntityController::retrieveAction()` and `warning(…, 404, …, 404)`).
- **Tests:** `testMissingEntityReturns500HtmlCurrently` (regression, pass); `testMissingEntityShouldReturn404Json` (correct-behavior, **skipped**).

### BUG-6 — `default_locale: zh` does not become the active locale — effective default is English — **LOW**

- **Location:** `config/packages/translation.yaml:2` (`default_locale: zh`); framework `Symfony\Component\HttpKernel\EventListener\LocaleListener` sets only the *default* locale at priority 100 and never activates it without an explicit `_locale`; the app’s custom `App\Core\EventListener\LocaleListener` (priority 20) only overrides on a matched `?_locale`/Accept-Language.
- **Description:** with no locale hint the active request locale stays `en` (PHP `Request` default) — verified `reqLocale=en`/`translatorLocale=en` while `reqDefaultLocale=zh`. `default_locale: zh` therefore only feeds the translator fallback for missing keys (which can produce a weird en-with-zh-fallback mix). The LocaleListener docblock and `docs/ai/context.md` both claim fallback `en`, which matches the *effective* behavior but contradicts the config.
- **Impact:** misconfiguration — the app defaults to English responses even though `default_locale` says Chinese; docs/config mismatch.
- **Reproduction:** `GET /api/v1/manage/categories/999999` (no hints) → `message: "Entity is not found"` (en); `fr-FR` header → also en.
- **Proposed fix:** either set `default_locale: en` to match reality, or make the custom LocaleListener apply the configured default when no hint matches.
- **Tests:** `testNoLocaleHintUsesEffectiveEnglishDefault`, `testUnsupportedLocaleLeavesEffectiveEnglishFallback` (assert actual behavior, pass).

### BUG-7 — OpenApiEnricherListener::detectTag() prefix checks never match method-prefixed operationIds — Store endpoints left untagged — **MEDIUM**

- **Location:** `src/Core/EventListener/OpenApiEnricherListener.php:259-262` (`str_starts_with($opId, 'system-')`, `'wechat-'`, `'store-'`).
- **Description:** Nelmio prefixes operationIds with the HTTP method (`get_store-orders-list`, `post_store-orders-accept`), so the anchored `str_starts_with` checks never fire. The unanchored `manage|app|public` regex and the `str_contains(…, 'sys-auth')` check still work, which is why `system-*`/`wechat-*` endpoints with explicit OA `tags` survive. Store endpoints (no explicit OA tags, no META entry) end up with **no tag at all**.
- **Impact:** `/api/v1/store/{scopeId}/orders…` (list/detail/accept/reject/fulfill) have empty `tags` in the enriched spec → missing module tag in the docs UI.
- **Reproduction:** `GET /api/doc.json` → `paths['/api/v1/store/{scopeId}/orders/{orderUuid}/accept']['post']['tags'] === []`.
- **Proposed fix:** strip the method prefix before detection (e.g. `preg_replace('/^(get|post|put|delete|patch)_/', '', $opId)`), or use `str_contains` for the prefix checks.
- **Tests:** `testStoreOrderEndpointsAreLeftUntaggedCurrently` (regression, pass); `testStoreOrderEndpointsShouldBeTaggedStore` (correct-behavior, **skipped**).

### Additional observations (not blocking)

- `@order=entity.sortOrder|UP` (invalid direction) and `@order=entity.nope|ASC` (unknown field) surface as **500** `QueryException` instead of 400 — no validation of `@order` tokens.
- `TransformContent` (`src/Core/View/TransformContent.php:156-157`) silently swallows `@transform` evaluation failures — a malformed transform expression no-ops instead of erroring.
- Docs (`docs/ai/context.md` §5) show `Math.mul(value, 100)`, but `src/Core/Utils/Math.php` has no `mul` method.
- `translations/messages.ja.yaml:293` maps `"SUCCESS"` to the Chinese string `操作成功。` (should likely be a Japanese equivalent).

## 5. Skipped tests (all reference the bugs above; suite stays green)

| Test | Reason |
|---|---|
| `testDqlAllowedForAdminReturnsFilteredResults` | BUG-1 |
| `testSortAllowedForAdminSortsInMemory` | BUG-1 |
| `testExpandsReturnsMetadataWithoutDeprecation` | BUG-2 |
| `testArraySelectReturns400` | BUG-3 |
| `testRegexMatchesWorksOnCurrentDb` | BUG-4 |
| `testMissingEntityShouldReturn404Json` | BUG-5 |
| `testStoreOrderEndpointsShouldBeTaggedStore` | BUG-7 |

## 6. Suggested follow-ups

1. Fix BUG-1 (request-time user resolution) — unlocks `@dql/@sort/@hints` + the `@filter` in-memory fallback, which are the highest-value dynamic-query features.
2. Fix BUG-2 (typed `__metadata` / no dynamic property) so `@expands` stops failing `failOnDeprecation`.
3. Make `ListApiViewMixin` map `ValidatorException` → 400 (BUG-3) and validate `@order`.
4. Add the `/system` prefix to the ExceptionInterceptor (BUG-5) and catch `MappingException` for 404 JSON.
5. Normalize operationIds before tag detection (BUG-7) and align `default_locale` config with actual behavior (BUG-6).
