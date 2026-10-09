# Common Module Controllers & Entities — Coverage & Bug Report

- Date: 2026-08-09
- Scope: `src/Common/Controller/App/ContentController.php`, `src/Common/Controller/App/CommentController.php`, `src/Common/Entity/Content.php`, `src/Common/Entity/Category.php`
- Task: raise line coverage of the two App controllers to ~100% and close the remaining uncovered entity lines; find bugs.
- Constraint honored: **nothing under `src/` was modified**. Only new test files under `tests/` and this report were added.

## Test files added

All new files use `declare(strict_types=1);` and live in `App\Tests\Common\Controller` / `App\Tests\Common\Entity`.

| File | Type | Covers |
|---|---|---|
| `tests/Common/Controller/ContentControllerTest.php` | WebTestCase HTTP (JWT auth via `IntegrationWebTestCase::createAuthenticatedClient()` + `DatabaseBootstrapTrait`) | `App\Common\Controller\App\ContentController` (was 0%, constructor line 19) — list / detail / 404 / auth-required |
| `tests/Common/Controller/CommentControllerTest.php` | unit (`TestCase`) with an anonymous subclass overriding `getUser()` + a `FakeCommentService` test double | `App\Common\Controller\App\CommentController` line 50 (the non-`User` branch of `defaultCreateValues()`) |
| `tests/Common/Controller/CommentParentScopeIntegrationTest.php` | WebTestCase HTTP | documents Bug 2 (skipped correct-behaviour test) |
| `tests/Common/Entity/ContentEntityCoverageTest.php` | unit (`TestCase`) | `App\Common\Entity\Content` line 103 (`getTags()` lazy re-init) + Bug 1 skipped tests |
| `tests/Common/Entity/CategoryEntityCoverageTest.php` | unit (`TestCase`) | `App\Common\Entity\Category` line 121 (`getChildren()` lazy re-init) |

### Notes on technique

- **ContentController** has no controller logic of its own — it is a thin trait controller (`use ApiView, DetailApiViewMixin, ListApiViewMixin`). Its only own executable code is the constructor, so it is covered 100% simply by routing HTTP requests to it. Tests hit `/api/v1/app/contents` (list) and `/api/v1/app/contents/{id}` (detail), which in turn execute the `ListApiViewMixin::listAction` / `DetailApiViewMixin::detailAction` trait code. A `Content` row is persisted through the test `EntityManager` before each read.
- **CommentController line 50** is the anonymous-user fallback of `defaultCreateValues()`. It is not reachable over HTTP because `config/packages/security.yaml` blocks all `/api` routes behind `IS_AUTHENTICATED_FULLY`, so it must be unit-tested. The anonymous subclass overrides `AbstractController::getUser()` and a `FakeCommentService` (a real class implementing `CommentServiceInterface`, since PHPUnit 12 does not mock PHPDoc-declared `wrapInTransaction()`) runs the full `CreateApiViewMixin::createAction()` path. `setRequestStack()`/`setSerializer()`/`setTranslator()` are injected exactly like the existing `MediaControllerUploadTest`.
- **`Content::getTags()` (line 103) / `Category::getChildren()` (line 121)** are the lazy `if ($this->tags === null)` branches. They are exercised by nulling the property through `ReflectionProperty::setValue()` **without** `setAccessible()` (the PHP 8.5-deprecated call is deliberately avoided) and by `newInstanceWithoutConstructor()`.

## Coverage results

Baseline uncovered lines (from `var/uncovered-map.txt`, generated 2026-08-09 07:39) vs. status after the new tests. Verified by running

```
XDEBUG_MODE=coverage php vendor/bin/phpunit \
    tests/Common/Controller/ContentControllerTest.php \
    tests/Common/Controller/CommentControllerTest.php \
    tests/Common/Entity/ContentEntityCoverageTest.php \
    tests/Common/Entity/CategoryEntityCoverageTest.php \
    --coverage-clover /tmp/cov.xml
```

| File | Baseline uncovered | After new tests |
|---|---|---|
| `App\Common\Controller\App\ContentController` (was 0%) | 19 | **covered** (constructor 2/2 = 100%) |
| `App\Common\Controller\App\CommentController` (was 92.31%) | 50 | **covered** (line 50 hit by the unit test) |
| `App\Common\Entity\Content` (was 96.88%) | 103 | **covered** |
| `App\Common\Entity\Category` (was 97.92%) | 121 | **covered** |

Combined with the pre-existing suite (`tests/Common/Entity/*Test.php` covers the getter/setter/prePersist body; `tests/Integration/AppApiRegressionTest.php` covers `CommentController::commonFilter()` lines 29–33 and the `User` branch of `defaultCreateValues()`), the four files reach ~100% across the full suite. `var/uncovered-map.txt` lists no other `Common/` files with uncovered lines, so there is nothing else in scope.

## Bugs found

### Bug 1 — `Content::setTitle()`/`addTag()`/`removeTag()` do not refresh `updatedAt` (LOW)

- **File/line:** `src/Common/Entity/Content.php:65-70` (`setTitle`), `:108-120` (`addTag`/`removeTag`).
- **Description:** Every other setter in the file (`setBody()` line 77-83, `setCategory()` line 90-95) calls `$this->touch()` on mutation, and **every** setter in the sibling Common entities — `Category`, `Comment`, `Page`, `Setting`, `Tag`, `Media`, `Picture` — follows the same "touch on every mutation" convention. `Content::setTitle()` is the only setter in the module that mutates a field **without** touching, and the tag-collection mutators don't either.
- **Impact:** A title-only edit (e.g. `PUT /api/v1/manage/contents/{id}` with only `title`, or `$content->setTitle(...)` in code) leaves `updatedAt` unchanged, so API consumers/DBs that rely on `updatedAt` to detect changes miss the mutation. `updatedAt` is therefore not a reliable change indicator for `Content`, unlike every other entity in the module. (The existing `ContentTest::testSettersAreFluentAndTouchUpdatesTimestamp` masks this by chaining `setTitle(...)->setBody(...)`, so the touch is attributed to `setBody`.)
- **Reproduction:**
  ```php
  $content = new Content('before');
  $content->setTitle('after');
  var_dump($content->getUpdatedAt()); // null — bug: setBody() would set a DateTimeImmutable
  ```
- **Correct-behaviour test:** `App\Tests\Common\Entity\ContentEntityCoverageTest::testSetTitleTouchesUpdatedAt` and `::testAddTagTouchesUpdatedAt` assert `updatedAt` is set after `setTitle`/`addTag`; they **fail** against the current code and are therefore **skipped**.
- **Proposed fix:** add `$this->touch();` to `setTitle()`, `addTag()`, and `removeTag()`, mirroring `setBody()` and the other entities.

### Bug 2 — App comment create accepts a `parent` from a different entity scope (MEDIUM)

- **File/line:** `src/Common/Controller/App/CommentController.php:22` (`protected array $acceptedCreateProperties = ['parent'];`), combined with `CreateApiViewMixin::createAction()` (`src/Core/View/CreateApiViewMixin.php:118-124`) forwarding `parent` straight into `CommentService::update()`. There is no validation of the parent's `entityType`/`entityId` anywhere in the flow (`CommentService` is an empty `BaseService` subclass; `Comment` has no constraints on the parent relation).
- **Description:** `POST /api/v1/app/comments` accepts any integer `parent` id. `update()` (`src/Core/Service/Concern/BaseServiceMutationTrait.php:70-89`) only resolves the ManyToOne target by id; it never verifies that the parent comment shares the same `entityType`/`entityId` as the new comment.
- **Impact:** A client can build a "reply" whose `entityType`/`entityId` point at content A while its `parent` belongs to content B. `CommentRepository::findByEntity()` (which drives approved-thread listing per entity) will then render the reply under A's thread even though its parent lives under B — orphaned/cross-entity threads that cannot be traversed consistently, and the moderation state of one entity's thread leaks into another's.
- **Reproduction:** create a parent comment on `Page/1` (approved), then `POST /api/v1/app/comments` with `{"entityType":"Content","entityId":99,"parent":<parentId>}` → returns 201 instead of rejecting the mismatch.
- **Correct-behaviour test:** `App\Tests\Common\Controller\CommentParentScopeIntegrationTest::testReplyParentMustBelongToSameEntityScope` asserts a 400 for the cross-entity reply; it **fails** against the current code (201) and is therefore **skipped**.
- **Proposed fix:** in `CommentController::processCreateContent()` (or in the service) reject when `parent` is set and `parent.entityType !== entityType || parent.entityId !== entityId` (return 400), mirroring the entity-scope constraint already enforced by `CommentRepository::findByEntity()`.

## Verified NON-bugs (investigated and cleared)

- **`CommentController::defaultCreateValues()` line 50 (non-`User` branch) is unreachable over HTTP** — `security.yaml` gates every `/api` route behind `IS_AUTHENTICATED_FULLY`, so `getUser()` always returns a real `User` in the API flow. The branch is defensive dead code; it is now covered by the unit test but is not a bug.
- **`CommentController::commonFilter()` returning `['author' => $this->getUser()]`** — the App list/detail endpoints intentionally scope comments to the current user. Whether pending comments should be excluded from the App list is a product decision; the current behaviour (author-filter only) is consistent with the existing integration suite (`testAppCommentPendingNotVisibleInAppList` explicitly asserts pending comments DO appear), so the test named `testAppCommentListOnlyReturnsApproved` is simply mis-named, not buggy.
- **`Content`/`Category` lazy collection re-init and `prePersist`** — `getTags()`/`getChildren()` returning a fresh `ArrayCollection` when the property is null is consistent with how the repositories/list views consume the collections; no correctness issue found.

## Skipped items

- `App\Tests\Common\Entity\ContentEntityCoverageTest::testSetTitleTouchesUpdatedAt` — **skipped** (asserts correct behaviour; fails on Bug 1).
- `App\Tests\Common\Entity\ContentEntityCoverageTest::testAddTagTouchesUpdatedAt` — **skipped** (asserts correct behaviour; fails on Bug 1).
- `App\Tests\Common\Controller\CommentParentScopeIntegrationTest::testReplyParentMustBelongToSameEntityScope` — **skipped** (asserts correct behaviour; fails on Bug 2).

## Final test run

```
XDEBUG_MODE=off php vendor/bin/phpunit \
    tests/Common/Controller/ContentControllerTest.php \
    tests/Common/Controller/CommentControllerTest.php \
    tests/Common/Controller/CommentParentScopeIntegrationTest.php \
    tests/Common/Entity/ContentEntityCoverageTest.php \
    tests/Common/Entity/CategoryEntityCoverageTest.php --no-coverage

OK, but some tests were skipped!
Tests: 15, Assertions: 30, Skipped: 3.
```

15 test cases (5 ContentController HTTP, 2 CommentController unit, 1 CommentParentScope skipped integration, 3 + 2 skipped Content entity, 3 Category entity), 0 failures/errors/notices/warnings/deprecations, 3 skipped (Bug 1 ×2 and Bug 2 documentation). A full `tests/Common/Controller` + `tests/Common/Entity` run stays green (77 tests, 235 assertions, 3 skipped).
