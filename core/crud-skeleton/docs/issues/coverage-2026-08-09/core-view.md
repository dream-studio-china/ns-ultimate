# Core/View Mixin Traits — Coverage & Bug Report

- Date: 2026-08-09
- Scope: `src/Core/View/*` mixin traits (read/transform/view concerns)
- Task: raise line coverage of the Core/View mixin traits to ~100% and find bugs.
- Constraint honored: **nothing under `src/` was modified**. Only new test files under `tests/` and this report were added.

## Test files added

All under `tests/Core/View/`, namespace `App\Tests\Core\View`, `declare(strict_types=1);`:

| File | Trait(s) covered | Focus lines |
|---|---|---|
| `WorkflowApiViewMixinTest.php` | `WorkflowApiViewMixin` | 31–42, 54–60, 73–94, 107–110 |
| `ApiViewMessagesTest.php` | `ApiViewMessages` | 23 (+ constants, 18) |
| `CreateApiViewMixinTest.php` | `CreateApiViewMixin` | 133, 134, 165, 178, 179 |
| `UpdateApiViewMixinTest.php` | `UpdateApiViewMixin` (+ `CreateApiViewMixin` for create-mode hooks) | 87–91, 112, 207, 210, 217, 265, 270, 271, 277 |
| `DeleteApiViewMixinTest.php` | `DeleteApiViewMixin` | 34 |
| `DetailApiViewMixinTest.php` | `DetailApiViewMixin` | 49 |
| `ApiViewTest.php` | `ApiView` | 10 (+ `mixToCommonFilter`/`mixIdToCommonFilter`) |
| `SingleCreateAndUpdateApiViewMixinCoverageTest.php` | `SingleCreateAndUpdateApiViewMixin` | 60, 63, 64 |

The existing `ScopedApiViewMixinTest`, `SingleCreateAndUpdateApiViewMixinTest`, and `TransformContentTest` already existed and were not duplicated; the new files extend coverage of the remaining uncovered lines only.

## Coverage results

Baseline uncovered lines (from `var/uncovered-map.txt`) vs. status after the new tests (verified with `phpunit --coverage-xml` on the affected files):

| Trait | Baseline uncovered lines | After | Notes |
|---|---|---|---|
| `WorkflowApiViewMixin` | 31,32,33,34,37,38,39,40,42,54,55,57,58,60,73,74,75,77,78,81,83,84,85,87,88,90,91,94,107,108,110 | **100%** | all lines covered |
| `ApiViewMessages` | 23 | **100%** | |
| `SingleCreateAndUpdateApiViewMixin` | 60,63,64 | **100%** | |
| `CreateApiViewMixin` | 93,133,134,149,165,178,179 | 133,134,165,178,179 covered; **93, 149 unreachable (dead code)** | see Bugs 4 |
| `UpdateApiViewMixin` | 87,88,89,91,112,207,210,217,242,265,270,271,277 | 87,88,89,91,112,207,210,217,265,270,271,277 covered; **242 unreachable (dead code)** | see Bug 5 |
| `DeleteApiViewMixin` | 34 | **100%** | |
| `DetailApiViewMixin` | 49 | **100%** | |
| `ApiView` | 10 | **100%** (line 10) | |

Full new test run: **43 tests, 125 assertions, 0 failures/errors/notices/warnings/deprecations, 1 skipped** (the skipped test documents Bug 1).

## Bugs found

### Bug 1 — `resetMarkingAction` route placeholder does not match the action argument (HIGH)

- **File/line:** `src/Core/View/WorkflowApiViewMixin.php:104-105`
  ```php
  #[Route('/{id}/status-reset', name: 'reset-status', methods: ['PUT'])]
  public function resetMarkingAction($entity)
  ```
- **Description:** The route declares placeholder `{id}` but the action parameter is named `$entity`. Symfony's `ControllerArgumentResolver` resolves route placeholders to arguments **by name**, so `{id}` can never be injected into `$entity`.
- **Impact:** Every request to `PUT /{id}/status-reset` fails with an unresolvable-controller-argument `RuntimeException` (500). The endpoint is entirely broken. Additionally, even if the argument were resolvable, the code calls `$entity->setStatus([])` directly on whatever is injected — i.e. the raw `id` (string/int), not an entity, and it **never loads the entity from the service**. A second failure path.
- **Reproduction:**
  1. Build a controller composing `WorkflowApiViewMixin` with `#[Route('/{id}/status-reset')]`.
  2. `PUT /1/status-reset`.
  3. Observed: `RuntimeException: Controller "...::resetMarkingAction()" requires that you provide a value for the "$entity" argument.`
- **Correct-behaviour test:** `WorkflowApiViewMixinTest::testResetMarkingRoutePlaceholderMatchesActionArgument` asserts that every route placeholder maps to a controller argument. It **fails** against the current code and is therefore **skipped** (see Skipped items).
- **Proposed fix:**
  ```php
  public function resetMarkingAction(int|string $id): Response
  {
      $entity = $this->service->get(['id' => $id]);
      if (!$entity) {
          return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
      }
      $entity->setStatus([]);
      $this->container->get('doctrine')->getManager()->flush();
      return $this->success();
  }
  ```
- **Note:** the trait is currently **unused** by any controller in `src/` (only `OrderController` re-implements the same endpoints inline), so there is no live production breakage today — but any controller adopting the trait inherits this bug.

### Bug 2 — Workflow actions have no entity-not-found guard (MEDIUM)

- **File/line:** `src/Core/View/WorkflowApiViewMixin.php:52-58` (`availableTransitionsAction`), `69-94` (`doTransitionAction`).
- **Description:** Neither action checks `$entity` after `$service->get(['id' => $id])` (lines 55, 74). A missing entity yields `null`, which is handed straight to `WorkflowInterface` methods typed as `object $subject`.
- **Impact:**
  - `availableTransitionsAction`: `getEnabledTransitions(null)` raises an uncaught `TypeError` → **500** instead of 404.
  - `doTransitionAction`: the `TypeError` is swallowed by `catch (\Throwable)` → a **200 warning** containing a confusing "must be of type object" message instead of 404.
- **Reproduction:** GET/POST a workflow route with an unknown id.
- **Evidence:** `WorkflowApiViewMixinTest::testAvailableTransitionsActionMissingEntityThrowsTypeError` and `testDoTransitionActionMissingEntityWarnsAboutTypeError` lock in the current (wrong) behaviour.
- **Proposed fix:** mirror `Trade/Controller/Manage/OrderController::transitionsAction()`/`doTransitionAction()`:
  ```php
  if (!$entity) {
      return $this->warning(ApiViewMessages::ENTITY_NOT_FOUND, 404, '', 404);
  }
  ```

### Bug 3 — `todoAction` does not reindex the filtered array (LOW / cosmetic)

- **File/line:** `src/Core/View/WorkflowApiViewMixin.php:37-42`.
- **Description:** `array_filter()` preserves original array keys. When leading (or non-consecutive) entities have no enabled transitions, the surviving array has gaps, and the JSON serializer emits an **object** (`{"1": {...}}`) instead of an **array** (`[{...}]`) in the `data` payload.
- **Impact:** inconsistent/incorrect client-side shape for the todo list.
- **Reproduction:** two entities where the first has no enabled transitions — `data` becomes `{"1": {...}}`.
- **Proposed fix:** `return $this->success(array_values($entities));` (the equivalent `OrderController::todoAction()` at `src/Trade/Controller/Manage/OrderController.php:291` already does this).

### Bug 4 — Dead branches in `CreateApiViewMixin::createAction` (LOW)

- **File/line:** `src/Core/View/CreateApiViewMixin.php:92-94` (`$contents = [];`) and `:148-150` (`throw new ValidatorException();`).
- **Description:**
  - Line 67 already bails out whenever `FixJSON::getJSONType($request->getContent()) === false`. `getJSONType()` returns only `'object'`, `'array'`, or `false` (`src/Core/Utils/FixJSON.php:23-38`), so the `else` at line 92 (and the `$inputType` re-computation at line 81) can never run with a value other than `'object'`/`'array'`.
  - Line 149 sits inside `$processItem`, which only ever runs when `$contents` is non-empty — which only happens for object/array input. The `else` throwing `new ValidatorException()` is therefore unreachable too.
- **Impact:** none at runtime; the branches are unreachable and cannot be covered by any test (line 93/149 remain red in coverage no matter what).
- **Proposed fix:** drop the `else { $contents = []; }` branch and the `else { throw new ValidatorException(); }` branch, or restructure so `$inputType` is validated once.

### Bug 5 — `batchUpdateAction`'s `BATCH_UPDATE_ERROR` is unreachable (LOW)

- **File/line:** `src/Core/View/UpdateApiViewMixin.php:241-243`.
- **Description:** `batchUpdateAction()` calls `updateRecords($request)` **without an id**. `updateRecords()` either returns the single-update result (an entity or `false`, never `null`), returns the batch `$response` array, or throws at line 217 for non-array content. It can therefore never return `null`, and the `if ($response === null) { throw new ValidatorException(ApiViewMessages::BATCH_UPDATE_ERROR); }` guard is dead code (line 242 can never be covered).
- **Impact:** the `BATCH_UPDATE_ERROR` message is never emitted; any genuinely "null" response would instead flow into `success(null)`.
- **Proposed fix:** remove the null guard (and confirm the batch path always returns an array), or make `updateRecords()` return `null` on a real failure signal.

### Verified NON-bug — `todoAction`'s `list()` call

- **File/line:** `src/Core/View/WorkflowApiViewMixin.php:33` — `$service->list(null, null, false)`.
- **Finding:** The task suggested this may throw `ArgumentCountError` if `BaseService::list()` accepts one argument. **It does not.** `BaseServiceReadListTrait::list(mixed $object = null, mixed $order = null, bool $disableRequest = true)` (`src/Core/Service/Concern/BaseServiceReadListTrait.php:57-61`) and `BaseServiceInterface::list(...)` both accept 3 arguments, so the call is valid. No bug. (Covered by `testTodoActionReturnsOnlyEntitiesWithEnabledTransitions`, which asserts `list` was invoked with `(null, null, false)`.)

## Skipped items

- `WorkflowApiViewMixinTest::testResetMarkingRoutePlaceholderMatchesActionArgument` — **skipped** because it asserts the correct behaviour (every route placeholder must map to a controller argument) and would **fail** against the current code (Bug 1). Skipping keeps the suite green while documenting the defect.

## Command reference

```bash
# run only the new View tests
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Core/View/WorkflowApiViewMixinTest.php \
  tests/Core/View/ApiViewMessagesTest.php \
  tests/Core/View/CreateApiViewMixinTest.php \
  tests/Core/View/UpdateApiViewMixinTest.php \
  tests/Core/View/DeleteApiViewMixinTest.php \
  tests/Core/View/DetailApiViewMixinTest.php \
  tests/Core/View/ApiViewTest.php \
  tests/Core/View/SingleCreateAndUpdateApiViewMixinCoverageTest.php --no-coverage

# verify coverage for a single file
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Core/View/UpdateApiViewMixinTest.php --coverage-xml var/coverage-check --no-progress
```
