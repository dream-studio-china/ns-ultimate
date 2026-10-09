# Core/Service + Core/Controller/RestController — Coverage to ~100%

**Date:** 2026-08-09
**Scope:** `Core/Service/*` + `Core/Controller/RestController`
**Constraint:** no changes under `src/`; only test files added under `tests/`.

## Result summary

| File | Before | After |
|---|---|---|
| `src/Core/Service/ExpressionService.php` | 62.5% | **100%** |
| `src/Core/Service/LegacyEvaluator.php` | 91.67% | **100%** |
| `src/Core/Service/DefaultServiceLocator.php` | 84.21% | **100%** |
| `src/Core/Service/Concern/BaseServiceInfrastructureTrait.php` | 80.23% | **100%** |
| `src/Core/Service/Concern/BaseServiceReadListTrait.php` | 94.08% | **99.34%** |
| `src/Core/Service/Concern/BaseServiceMutationTrait.php` | 90.98% | **97.54%** |
| `src/Core/Controller/RestController.php` | 94.01% | **100%** |

The only remaining uncovered lines (ReadList `133`, Mutation `59`, `158`, `179`) are
provably **unreachable dead code** (see Bugs 4 & 5) and cannot be exercised by any valid test.

## Test files added (all green, no deprecations/notices/warnings)

1. `tests/Core/Service/ExpressionServiceCoverageTest.php` (4 tests)
   - cache-hit rebuild path (`createQuery` + parameter wrappers), garbage cache entry fall-through,
     cache-store (`set` with name/value pairs), `ArrayCollection` → array parameter conversion.
2. `tests/Core/Service/LegacyEvaluatorCoverageTest.php` (1 test)
   - `$language === null` → `evaluate()`/`evaluateBool()` return `false`.
3. `tests/Core/Service/DefaultServiceLocatorCoverageTest.php` (4 tests)
   - `getRequestStack()` present/missing, `getSerializer()` legacy-service-name fallback and both-missing → `null`.
4. `tests/Core/Service/BaseServiceInfrastructureTraitCoverageTest.php` (17 tests)
   - `listResultToCollection()` QueryBuilder non-empty/empty results; container-backed lazy EM; EM-unavailable
     exception; repository default + alternate-class resolution; container logger / logger exception /
     missing-logger fallback; serializer cached from locator / locator exception fallback to container /
     container exception fallback to built-in serializer; `wrapInTransaction()` fallback (with and without
     `flush()`), commit path, rollback path, and inactive-transaction skip-rollback path.
5. `tests/Core/Service/BaseServiceMutationTraitCoverageTest.php` (14 tests, 1 skipped)
   - unknown data key `continue`; `ManyToOne`/`OneToMany` with `targetEntity: null` `continue`;
     to-one and to-many relation-not-found `NotFoundHttpException`; direct `DateTimeInterface` value
     assignment; `ReflectionException` from a throwing property attribute → `return false`;
     generic setter exception rethrow; `UniqueConstraintViolationException` → `ValidatorException('Duplication entries')`;
     generic flush exception rethrow; date-like mapping true/false via property named types; untyped property.
   - **1 skipped** documenting the immutable-datetime src bug (Bug 1).
6. `tests/Core/Service/BaseServiceReadListTraitCoverageTest.php` (5 tests)
   - `list()` with empty root aliases → `ValidatorException`; multi-segment `@select` join generation
     (`$joins` map + `leftJoin`); legacy in-memory filter failure → empty result; legacy sorter success
     (comparator `? 1 : -1` both branches); legacy sorter failure → `return 0` (order preserved).
7. `tests/Core/Controller/RestControllerCoverage2Test.php` (12 tests)
   - `resolveService()` throw (no container / service missing) + success; `getService()` throw + declared-service
     success; `getRequestStack()`/`getSerializer()`/`getTranslator()` unavailable throws; `@expands`
     `entity.`-prefixed chain shift; expands getter exception silently swallowed; `@display` traversing
     intermediate arrays; `@display` expression-object evaluation exception swallowed.
8. `tests/Core/Controller/RestControllerPaginationIntegrationTest.php` (1 test, integration)
   - `pagination()` against a real `QueryBuilder`/`DoctrinePaginator` (needs real EM + schema; see Caveats).

## Bugs found

### Bug 1 — `BaseServiceMutationTrait.php:137` — mutable DateTime assigned to immutable-typed setters
- **Description:** When updating a date-like field the trait always builds a mutable `new \DateTime((string) $val)`
  and passes it to the setter, regardless of the property/setter type.
- **Impact:** For entities with a `\DateTimeImmutable` typed property/setter (e.g. `#[ORM\Column(type: 'datetime_immutable')]`),
  `update()` throws a `TypeError: must be of type DateTimeImmutable, DateTime given`. Immutable datetime fields
  cannot be updated through the generic service.
- **Reproduction:** Entity with `private ?\DateTimeImmutable $created;` + `setCreated(\DateTimeImmutable)`; call
  `$service->update($entity, ['created' => '2026-02-02 08:00:00'])` → `TypeError` (confirmed while writing
  `BaseServiceMutationTraitCoverageTest`). Skipped test: `testUpdateWithImmutableDateTimeTypedPropertyIsBroken`.
- **Proposed fix:** Inspect the setter/property type (the trait already knows `isDateLikeMapping`) and construct
  `\DateTimeImmutable` when the target type is immutable, or normalize through `\DateTime::createFromInterface()` /
  the appropriate class.

### Bug 2 — `BaseServiceReadListTrait.php:254` — sorter comparator never returns `0`
- **Description:** `return $this->getLegacyEvaluator()->evaluateBool($sorter, ...) ? 1 : -1;` has no `0` case, so
  the comparator reports `x < y` for equal keys (both `(x,y)` and `(y,x)` return `-1`).
- **Impact:** Violates the `usort` comparator contract (must be antisymmetric). Ties produce unstable/undefined
  ordering; a malformed sorter silently degrades instead of preserving order.
- **Reproduction:** `@sort` comparing ids of two equal-id entities — comparator returns `-1` for both argument orders.
- **Proposed fix:** Compute a comparable scalar (e.g. `$a = evaluateBool(...)` / `$b = ...`) and return
  `$a === $b ? 0 : ($a ? 1 : -1)`, or return `0` when the boolean is identical.

### Bug 3 — `BaseServiceReadListTrait.php:242-243, 255-256` — dead `catch (\Exception)` around legacy evaluator
- **Description:** `LegacyEvaluator::evaluate()` already catches every `\Exception` internally and returns `false`,
  so the `catch (\Exception $e) { return false; }` / `return 0;` blocks never fire. Only `\Error`s (e.g. undefined
  method on the entity inside the expression) escape — and those are not caught by `catch (\Exception)`.
- **Impact:** The defensive catch gives false confidence; an `\Error` from an entity getter during in-memory
  filtering/sorting propagates as an unhandled 500 instead of being suppressed. Dead code.
- **Reproduction:** Inject a legacy evaluator that throws inside `evaluateBool()`; the `catch` is never reached
  because `evaluate()` already swallowed the exception. (Coverage tests exercise the blocks by injecting a
  subclass whose `evaluateBool()` throws directly.)
- **Proposed fix:** Either catch `\Throwable` (if in-memory evaluation must never fail) or remove the dead catch.

### Bug 4 — `BaseServiceReadListTrait.php:132-134` — unreachable `!is_string` guard in `$joiner`
- **Description:** `$joiner = function(?string &$expression, ...)` binds by reference. In strict mode, passing a
  non-string (e.g. an array `@groupBy[]` / `@order[]`) throws `TypeError` at the call site *before* the guard
  `if (!is_string($expression)) return;` runs. The guard is unreachable (verified in a sandbox).
- **Impact:** Malformed query params cause a 500 `TypeError` instead of a graceful validation error; the guard is
  misleading dead code.
- **Reproduction:** Request with `@groupBy` as an array → `TypeError` on `$joiner($groupBy, ...)`.
- **Proposed fix:** Validate `@select/@groupBy/@order` are strings before invoking `$joiner`, or widen the
  parameter to `mixed &$expression`.

### Bug 5 — `BaseServiceMutationTrait.php:58-59, 157-158, 178-179` — unreachable `!is_object($object)` checks
- **Description:** Three `if (!is_object($object)) throw new RuntimeException('Object became invalid during update')`
  blocks are unreachable: `update()` already throws `ValidatorException` when `$object` is not an object (lines
  40-48), and nothing reassigns `$object` to a non-object afterwards (the author even added
  `@phpstan-ignore function.alreadyNarrowedType`).
- **Impact:** Dead code; misleading signal that the object can change type mid-update. These 3 lines are the reason
  `BaseServiceMutationTrait` cannot reach 100% line coverage.
- **Reproduction:** None (unreachable).
- **Proposed fix:** Remove the redundant checks (lines 58-59, 157-159, 178-180).

### Minor / design notes
- `ExpressionService::buildFilter()` returns a `Doctrine\ORM\Query` on a cache hit but a `QueryBuilder` on a cache
  miss (documented `Query|QueryBuilder`). Callers only use `getDQL()`/parameters so it is harmless today, but the
  inconsistent return type is a latent contract issue. Cache-hit path also trusts `parameters` to be iterable
  (an invalid cached entry with a non-array `parameters` would `TypeError` in `array_map`).
- `RestController::expandObjectToMetadata()` depth guard `0 === $level` (line 192) never fires for the default
  `$level = -1` (decrementing `-1, -2, …` never equals `0`); recursion is bounded only by chain length.
- `BaseServiceMutationTrait::update()` relation processing calls `$object->$getter() ?? new ArrayCollection()`
  (line 103); a getter returning a plain array would fail on `->map()`. Doctrine collections are unaffected.
- `BaseServiceInfrastructureTrait::getLogger()`/`getEntityManager()` container-fallback branches are only
  reachable when a custom `ServiceLocatorInterface` returns `null` for the accessor; with `DefaultServiceLocator`
  the constructor always populates `$this->logger`/`$this->em` directly.

## Skipped tests
- `BaseServiceMutationTraitCoverageTest::testUpdateWithImmutableDateTimeTypedPropertyIsBroken` — skipped (src Bug 1).

## Caveats
- `RestControllerPaginationIntegrationTest` boots the Symfony kernel and, via `DatabaseBootstrapTrait`,
  **drops and recreates the shared sqlite schema at `var/test.db`**. Do not run it in parallel with other
  integration tests that bootstrap the same DB file; it is safe when run alone or sequentially.
- Coverage numbers above were measured with: existing `tests/Core/Service` + `tests/Core/Controller` +
  `tests/Integration/CoreServiceIntegrationTest.php` + `tests/Integration/BaseServiceIntegrationTest.php`
  plus the new test files, `XDEBUG_MODE=coverage`.
