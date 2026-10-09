# Common module test audit (2026-08-09)

Scope: `tests/Common/` (Entity, Controller, Integration) — 21 files, 131 test methods.
Read-only audit; no test or `src/` file was modified. Cross-referenced against the
pre-existing suite (`tests/Integration/AppApiRegressionTest.php`,
`tests/Integration/CommonModulesApiRegressionTest.php`, `tests/Integration/CommonModulesIntegrationTest.php`,
`tests/Integration/ContentRepositoryIntegrationTest.php`, `tests/Core/View/*ApiViewMixinTest.php`)
and the `docs/issues/coverage-2026-08-09/README.md` campaign summary.

## Summary

| File | Tests | Verdict |
|---|---|---|
| tests/Common/Entity/CategoryTest.php | 9 | KEEP |
| tests/Common/Entity/CategoryEntityCoverageTest.php | 3 | DELETE (all 3) |
| tests/Common/Entity/CommentTest.php | 8 | KEEP |
| tests/Common/Entity/ContentEntityCoverageTest.php | 5 | 3 DELETE, 2 KEEP (skipped, document Bug 1) |
| tests/Common/Entity/ContentTest.php | 8 | KEEP (note: blind spot on `setTitle` bug) |
| tests/Common/Entity/MediaTest.php | 6 | KEEP |
| tests/Common/Entity/PageTest.php | 7 | KEEP |
| tests/Common/Entity/PictureTest.php | 8 | KEEP |
| tests/Common/Entity/SettingTest.php | 6 | KEEP |
| tests/Common/Entity/TagTest.php | 6 | KEEP |
| tests/Common/Controller/CommentControllerTest.php | 2 | DELETE (both) |
| tests/Common/Controller/CommentParentScopeIntegrationTest.php | 1 | KEEP (skipped, documents Bug 2) |
| tests/Common/Controller/ContentControllerTest.php | 4 | DELETE (Medium) / borderline |
| tests/Common/Controller/MediaControllerUploadTest.php | 2 | DELETE (both) |
| tests/Common/Integration/CommentApiExtraTest.php | 1 | DELETE |
| tests/Common/Integration/CommonBatchUpdateTest.php | 7 | DELETE (Medium) / MERGE |
| tests/Common/Integration/CommonRepoFullTest.php | 14 | KEEP (2 partial dup/merge) |
| tests/Common/Integration/CommonRepoTest.php | 7 | DELETE (Medium) / MERGE |
| tests/Common/Integration/MediaUploadIntegrationTest.php | 19 | KEEP (1 test dup of MediaTest) |
| tests/Common/Integration/PictureControllerTest.php | 6 | KEEP |
| tests/Common/Integration/SettingApiIntegrationTest.php | 2 | DELETE (both) |

Verdict tally: 25 delete candidates (11 HIGH, 14 MEDIUM) out of 131 tests.

## KEEP

Base entity tests (`CategoryTest`, `CommentTest`, `ContentTest`, `MediaTest`, `PageTest`,
`PictureTest`, `SettingTest`, `TagTest`) — plain constructor/fluent-setter/touch/prePersist
assertions. These are the conventional base suite; they are boilerplate but are the
established pattern across every module, so they are retained by this audit.

Skipped tests that document real bugs (per campaign instructions these are KEEP):
- `ContentEntityCoverageTest::testSetTitleTouchesUpdatedAt`, `testAddTagTouchesUpdatedAt`
  (document `Content::setTitle()/addTag()` not touching `updatedAt` — campaign Bug 1).
- `CommentParentScopeIntegrationTest::testReplyParentMustBelongToSameEntityScope`
  (documents cross-entity parent acceptance — campaign Bug 2).

Behavioral value (not duplicated anywhere in the suite):
- `PictureControllerTest` (full HTTP CRUD, validation, owner-scope isolation, picture repo
  custom methods). No other file touches `/api/v1/{app,manage}/pictures`.
- `MediaUploadIntegrationTest` core upload journey: manage upload stores + deletes the file,
  PDF upload without dimensions, owner-only app delete, public readonly scope, missing-file /
  unknown-storage / unknown-category 400s, `MediaService` validation branches, and storage
  failure handling. These are unique.
- `CommonRepoFullTest` custom repository methods (`findBySlug`, `findRootCategories`,
  `findEnabled`, `findByEntity`, `findPending`, `findImages`, `findPublished`, `findByGroup`,
  `findByNameLike`) — only place these queries are asserted.

## DELETE CANDIDATES

Reason codes: 1 = COVERAGE-CHASING, 2 = DUPLICATE, 3 = IMPLEMENTATION-DETAIL,
4 = REDUNDANT-REGRESSION, 5 = NEAR-EMPTY.

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| CategoryEntityCoverageTest::testGetChildrenReinitializesWhenPropertyIsNull | 1/3 | HIGH | CategoryTest::testConstructorInitializesFields, testParentChildRelationships |
| CategoryEntityCoverageTest::testAddChildReinitializesNullCollection | 1/3 | HIGH | CategoryTest::testParentChildRelationships, testAddChildDoesNotDuplicate |
| CategoryEntityCoverageTest::testChildrenFromReflectionWithoutConstructorAreLazilyInitialized | 1/3 | HIGH | CategoryTest::testConstructorInitializesFields |
| ContentEntityCoverageTest::testGetTagsReinitializesWhenPropertyIsNull | 1/3 | HIGH | ContentTest::testConstructorInitializesCoreFields |
| ContentEntityCoverageTest::testAddTagReinitializesNullCollection | 1/3 | HIGH | ContentTest::testTagRelationships, testAddTagDoesNotDuplicate |
| ContentEntityCoverageTest::testTagsFromReflectionWithoutConstructorAreLazilyInitialized | 1/3 | HIGH | ContentTest::testConstructorInitializesCoreFields |
| CommentApiExtraTest::testCommentCreateAndList | 2/5 | HIGH | AppApiRegressionTest::testAppCommentCreateDefaultsToPending, testAppCommentListOnlyReturnsApproved |
| CommentControllerTest::testCreateWithAuthenticatedUserRecordsAuthorFields | 2 | HIGH | AppApiRegressionTest::testAppCommentCreateDefaultsToPending |
| MediaControllerUploadTest::testAppUploadReturns500ForUnexpectedError | 2 | HIGH | MediaUploadIntegrationTest::testUploadHandlesUnexpectedStorageFailure |
| MediaUploadIntegrationTest::testMediaEntityAccessorsAndPrePersistDefaults | 2 | HIGH | MediaTest::testSettersAreFluent, MediaTest::testPrePersistWhenCreatedFromReflection |
| SettingApiIntegrationTest::testCreateAndReadSettingViaManageApi | 2 | HIGH | CommonModulesApiRegressionTest::testSettingCrudRegression |
| CommentControllerTest::testCreateWithoutUserFallsBackToPendingStatus | 1 | MEDIUM | (null-user branch unreachable behind auth; see note) |
| MediaControllerUploadTest::testManageUploadReturns500ForUnexpectedError | 2 | MEDIUM | MediaControllerUploadTest::testAppUploadReturns500ForUnexpectedError, MediaUploadIntegrationTest::testUploadHandlesUnexpectedStorageFailure |
| ContentControllerTest::testListReturnsAllContents | 2/1 | MEDIUM | UpdateApiViewMixinTest / DetailApiViewMixinTest (mixin unit coverage) |
| ContentControllerTest::testDetailReturnsContent | 2/1 | MEDIUM | DetailApiViewMixinTest::testDetailReturnsEntity |
| ContentControllerTest::testDetailMissingReturns404 | 2/1 | MEDIUM | DetailApiViewMixinTest::testDetailMissingEntityReturnsNotFound |
| ContentControllerTest::testListRequiresAuthentication | 2 | MEDIUM | AppApiRegressionTest::testAppEndpointsRequireAuthentication (intent; `/app/contents` not in list) |
| CommonBatchUpdateTest (all 7: Content/Category/Tag/Page/Comment/Setting/Media) | 2/5 | MEDIUM | UpdateApiViewMixinTest::testBatchUpdate* (unit), TradeApiIntegrationTest (HTTP batch-update, real result asserts) |
| CommonRepoTest (all 7: Content/Category/Comment/Media/Page/Setting/Tag findById+findAll) | 4 | MEDIUM | ContentRepositoryIntegrationTest::testFindByIdReturnsEntityOrNull; CommonModulesIntegrationTest CRUD flows; CommonRepoFullTest custom queries |
| SettingApiIntegrationTest::testCreateSettingViaEntityManager | 2/4 | MEDIUM | CommonRepoFullTest::testSettingFindByKey, CommonRepoTest::testSettingRepositoryFindByIdAndFindAll, CommonModulesIntegrationTest::testSettingCrudFlow |
| CommonRepoFullTest::testContentFindLatest | 2 | MEDIUM | ContentRepositoryIntegrationTest::testFindLatestReturnsNewestFirstAndRespectsLimit |
| CommonRepoFullTest::testContentFindLatestWithDefaultLimit | 2/5 | MEDIUM | ContentRepositoryIntegrationTest::testFindLatestReturnsNewestFirstAndRespectsLimit (default-limit number never asserted) |

### HIGH-confidence reasoning (exact citations)

- **CategoryEntityCoverageTest / ContentEntityCoverageTest (active tests).** Each forces a
  `null` collection via `ReflectionProperty`/`newInstanceWithoutConstructor` to execute the
  defensive lazy-init guard in `Category::getChildren()` (`src/Common/Entity/Category.php:120-122`)
  / `Content::getTags()` (`src/Common/Entity/Content.php:102-104`). The state is unreachable
  through the public constructor, which always initializes the `ArrayCollection`
  (`Category.php:53`, `Content.php:46`). The asserted behavior (collection membership,
  dedup, parent linking) is already asserted in the base tests listed. The docblocks state
  the purpose outright: "Covers the last uncovered branch". These pin an implementation
  detail purely for line coverage.
- **CommentApiExtraTest::testCommentCreateAndList.** POST `/api/v1/app/comments` → 201 and
  GET `/api/v1/app/comments` → 200/`code=0` is a strict subset of
  `AppApiRegressionTest::testAppCommentCreateDefaultsToPending` (201 + `code=0` + fields) and
  `testAppCommentListOnlyReturnsApproved` / `testAppCommentPendingNotVisibleInAppList` (GET
  list). It asserts only status codes and `code=0`.
- **CommentControllerTest::testCreateWithAuthenticatedUserRecordsAuthorFields.** Asserts via a
  `FakeCommentService` that `defaultCreateValues()` records `authorName`/`authorEmail`/`status
  = pending` from the current user — exactly what `AppApiRegressionTest::testAppCommentCreateDefaultsToPending`
  asserts over the real HTTP stack (`authorName = 'testauth'`, `authorEmail = 'testauth@example.com'`,
  `status = 'pending'`).
- **MediaControllerUploadTest::testAppUploadReturns500ForUnexpectedError.** Identical to
  `MediaUploadIntegrationTest::testUploadHandlesUnexpectedStorageFailure`: same inherited
  `App\Common\Controller\App\MediaController::uploadAction()` `catch (\Throwable) → 500 +
  message`, same fake `MediaServiceInterface` throwing, same `uploadOwner() → null`. The
  integration variant is strictly stronger (real container serializer/translator/request
  stack).
- **MediaControllerUploadTest::testManageUploadReturns500ForUnexpectedError.** `Manage\MediaController`
  extends `App\MediaController` and inherits `uploadAction()`; with `uploadOwner() → null` the
  exercised `catch (\Throwable) → 500` path is the same code as the App test, differing only
  in the thrown message. Two tests for one code path.
- **MediaUploadIntegrationTest::testMediaEntityAccessorsAndPrePersistDefaults.** Reproduces
  `MediaTest::testSettersAreFluent` (same six setters, same assertions on
  filename/originalFilename/mimeType/size/path/storage + `getUpdatedAt()`) and
  `MediaTest::testPrePersistWhenCreatedFromReflection` (reflection `newInstanceWithoutConstructor`
  + `prePersist()` + `createdAt`). Pure entity unit testing embedded in an integration file.
- **SettingApiIntegrationTest::testCreateAndReadSettingViaManageApi.** POST `/api/v1/manage/settings`
  (201, key/value asserted) + GET `/api/v1/manage/settings/{id}` (key asserted) is a strict
  subset of `CommonModulesApiRegressionTest::testSettingCrudRegression` (same POST assertions,
  plus GET detail value, PUT, DELETE).

### MEDIUM-confidence reasoning

- **ContentControllerTest.** `App\ContentController` is an empty subclass of generic
  `ListApiViewMixin`/`DetailApiViewMixin` mixins (no custom logic). The list/detail/404
  behaviors are covered at unit level by `tests/Core/View/DetailApiViewMixinTest` and the
  batch/list mixin tests; 404-on-missing is also covered HTTP-level for every other entity.
  What the file uniquely adds is route wiring + auth smoke for `/api/v1/app/contents`
  (`/app/contents` is absent from `AppApiRegressionTest::testAppEndpointsRequireAuthentication`).
  Borderline — flag only if the team wants one representative "app list/detail + auth" test
  per module; the mixin-level coverage makes this file redundant.
- **CommonBatchUpdateTest.** Each of the 7 tests is structurally identical (create two rows,
  POST `batch-update`, assert HTTP 200). Asserting only 200 means the tests pass even if the
  update were a no-op (weak/no persistence verification). The `batchUpdateAction` behavior is
  covered by `UpdateApiViewMixinTest` (unit) and `TradeApiIntegrationTest` (HTTP, with real
  result assertions). Merge into one parameterized test that verifies persisted values, or
  drop in favour of the generic coverage.
- **CommonRepoTest.** All 7 tests are the same pattern `persist → flush → findById → same
  entity → findAll non-empty`, exercising only generic Doctrine `EntityRepository` methods
  (no custom repository logic). `findById` round-trips are already proven by
  `ContentRepositoryIntegrationTest::testFindByIdReturnsEntityOrNull` and every
  `CommonModulesIntegrationTest` CRUD flow. Merge into one parameterized mapping test.
- **CommonRepoFullTest::testContentFindLatest***. `findLatest()` ordering+limit is already
  asserted by `ContentRepositoryIntegrationTest::testFindLatestReturnsNewestFirstAndRespectsLimit`;
  the new tests add a limit-count assertion without ordering and `findLatest(1) → count 1`.
  Fold the default-limit variant into the pre-existing test instead of duplicating.
- **CommentControllerTest::testCreateWithoutUserFallsBackToPendingStatus.** App comment create
  is auth-protected (`AppApiRegressionTest::testAppCommentCreateUnauthenticated`), so the
  null-user branch of `defaultCreateValues()` is unreachable in production; the test mocks
  `getUser() → null` purely to cover that defensive branch (docblock: "Covers the remaining
  uncovered line (50)"). Coverage-chasing of an unreachable branch.

## MERGE SUGGESTIONS

1. `CommonBatchUpdateTest` (7 tests) → one table-driven test asserting persisted field values,
   not just HTTP 200.
2. `CommonRepoTest` (7 tests) → one parameterized `persist → findById → findAll` mapping test
   (or delete in favour of `CommonModulesIntegrationTest` CRUD coverage).
3. `CommonRepoFullTest::testContentFindLatest*` → fold into `ContentRepositoryIntegrationTest`
   (already asserts ordering/limit).
4. `MediaUploadIntegrationTest::testMediaEntityAccessorsAndPrePersistDefaults` → fold into
   `MediaTest` (or delete; `MediaTest` already covers it).
5. `CommentControllerTest` (if kept) → fold the two unit tests into the HTTP-level coverage in
   `AppApiRegressionTest` rather than maintaining a mock-heavy second copy.

## Notes / non-blocking observations

- `ContentTest::testSettersAreFluentAndTouchUpdatesTimestamp` passes only because `setBody()`
  touches `updatedAt`; it does NOT catch the `setTitle()` missing-`touch()` bug. This is a
  coverage blind spot, not a redundant test — the skipped tests in
  `ContentEntityCoverageTest` document the bug instead. Do not treat this test as proof the
  bug is fixed.
- No skipped test in this module was flagged; all skips document campaign bugs and are KEEP.

## Verification steps

```bash
cd /Volumes/Nayuki/Development/PHP/crud-skeleton
/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Common/   # 131 tests, 0 failures (baseline)
# After deleting HIGH-confidence candidates only:
/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter "CategoryEntityCoverageTest|ContentEntityCoverageTest|CommentApiExtraTest|CommentControllerTest|MediaControllerUploadTest|SettingApiIntegrationTest" --testsuite unit 2>/dev/null || true
# Confirm remaining coverage: run with Xdebug and diff var/coverage for src/Common
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --coverage-text --filter Common
```

Deleting the HIGH-confidence set (11 tests) must not lower `src/Common` line coverage below the
90% gate; the `Category::getChildren()`/`Content::getTags()` lazy-init branches are the only
lines they exercise, and those are defensive guards reachable only via reflection.
