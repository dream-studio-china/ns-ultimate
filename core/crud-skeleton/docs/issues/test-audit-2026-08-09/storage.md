# Storage module test audit (2026-08-09)

Read-only audit of `tests/Storage/` (`Service/`, `Command/`). No `src/` or
`tests/` file was modified. Skipped tests that document known `src/` bugs are
KEEP (QiniuStorageTest::testDeleteOfPathOutsideConfiguredDomainIsIgnored).

Reference: `docs/issues/coverage-2026-08-09/storage.md` (bugs #1, #2),
`TEST_STRATEGY.md`, `TEST_MATRIX.md`, `BUSINESS_INVARIANTS.md`.

## Summary — table: File | Tests | Verdict

| File | Tests | Verdict |
|---|---|---|
| `tests/Storage/Service/StorageServiceTest.php` | 8 | KEEP 8 (no deletions) |
| `tests/Storage/Service/QiniuStorageTest.php` | 6 | KEEP 2, DELETE 3, KEEP 1 (skipped bug doc) |
| `tests/Storage/Service/LocalStorageCoverageTest.php` | 2 | KEEP 1, DELETE 1 |
| `tests/Storage/Command/InitQiniuSettingsCommandTest.php` | 3 | KEEP 3 |
| `tests/Storage/Command/InitQiniuSettingsCommandValuesTest.php` | 1 | KEEP 1 (merge into main command test) |
| **Total** | **20** | **DELETE 4, MERGE 1 (one extra file folded)** |

## KEEP

| Test | Why |
|---|---|
| `StorageServiceTest::testLocalStorageStoresAndDeletesFile` | Primary happy-path contract for `LocalStorage::store()/delete()`; only place asserting the `/uploads/YYYYMM/name` URL shape plus real file persistence/removal. |
| `StorageServiceTest::testLocalStorageDeleteIgnoresForeignAndUnsafePaths` | Security-relevant behavior: `delete()` refuses foreign prefixes and `../` traversal (`LocalStorage.php:51,60`). Mirrors `LocalStorage::delete()` domain-scoping that `QiniuStorage::delete()` is missing (bug #1). |
| `StorageServiceTest::testLocalStorageFailsWhenBasePathCannotBeCreated` | `store()` error contract (mkdir failure → `RuntimeException 'Unable to create upload directory'`, `LocalStorage.php:34-35`). |
| `StorageServiceTest::testRegistryResolvesDriversAndReportsUnknownName` | Sole coverage of `MediaStorageRegistry::get()/names()` + unknown-driver error (`MediaStorageRegistry.php`). No other test touches the registry. |
| `StorageServiceTest::testQiniuRequiresConfiguration` | Kept as the canonical "not configured" test (see duplicate analysis below). Also carries the only `QiniuStorage::getName()` assertion. |
| `StorageServiceTest::testQiniuRequiresSdkWhenConfigured` | Kept as the canonical missing-SDK test (see duplicate analysis below). |
| `StorageServiceTest::testQiniuStoresAndDeletesWithSdkStubs` | Happy-path `store()` URL construction, `delete()` success, and the bare-domain `$key === ''` guard (`QiniuStorage.php:47-49`) — the last one covered nowhere else. |
| `StorageServiceTest::testQiniuStoreAndDeleteErrorsAreReported` | Sole coverage of upload/delete error propagation (`QiniuStorage.php:32-34,58-60`). |
| `QiniuStorageTest::testStoreFallsBackToNameWhenSdkResultHasNoKey` | Unique branch: SDK result without `key` → `$name` fallback (`QiniuStorage.php:36`). |
| `QiniuStorageTest::testDeleteOfPathOutsideConfiguredDomainIsIgnored` | **SKIPPED**, documents bug #1 (`QiniuStorage::delete()` does not verify the domain). Keep per audit rules. |
| `LocalStorageCoverageTest::testDeleteThrowsWhenFileCannotBeUnlinked` | Sole coverage of the `!unlink()` failure branch (`LocalStorage.php:64-66`) and the regression evidence for bug #2 (unsuppressed `E_WARNING`). |
| `InitQiniuSettingsCommandTest::testExecuteCreatesMissingQiniuSettingsWithProvidedValues` | Value trimming, group/label metadata, output contract. |
| `InitQiniuSettingsCommandTest::testExecuteSkipsExistingSettingsAndCreatesMissingOnly` | Idempotency branch: existing setting preserved, only missing ones created. |
| `InitQiniuSettingsCommandTest::testExecuteDoesNotFlushWhenAllSettingsExist` | No-flush branch + "All Qiniu storage settings already exist." output. |
| `InitQiniuSettingsCommandValuesTest::testExecuteCreatesSettingsWithNullValuesForEmptyOptions` | Unique branch `$value === '' ? null : $value` (`InitQiniuSettingsCommand.php:93`). KEEP behavior; move into the main command test file (merge). |

## DELETE CANDIDATES — table: File::method | Reason | Confidence | Covered by

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `QiniuStorageTest::testStoreThrowsWhenSdkIsNotInstalled` | 2 DUPLICATE — `assertSdkInstalled()` throw (`QiniuStorage.php:87-89`), exercised via `store()`; identical branch and message as the pre-existing `delete()`-based test | HIGH (same source line, same exception) | `StorageServiceTest::testQiniuRequiresSdkWhenConfigured` |
| `QiniuStorageTest::testDeleteThrowsWhenStorageIsNotConfigured` | 2 DUPLICATE — `config()` empty-config throw (`QiniuStorage.php:73-75`); `config()` is invoked at the top of both `store()` and `delete()` before any method-specific code, so `delete()` vs `store()` is irrelevant | HIGH (same source line, same exception) | `StorageServiceTest::testQiniuRequiresConfiguration` |
| `QiniuStorageTest::testStoreThrowsWhenConfigurationIsIncomplete` | 2 DUPLICATE — same `config()` throw with 1-of-4 settings; `in_array('', $config, true)` treats "partial" identically to "empty" | HIGH (same source line, same exception) | `StorageServiceTest::testQiniuRequiresConfiguration` |
| `QiniuStorageTest::testStoreUsesKeyReturnedBySdk` | 2 DUPLICATE — `$result['key']` true-branch + URL construction (`QiniuStorage.php:36,38`); the distinct `uploaded-key.png` value proves only what the fallback test already distinguishes, and the branch itself is covered by the main-process happy path | MEDIUM (same branch, marginally stronger assertion value) | `StorageServiceTest::testQiniuStoresAndDeletesWithSdkStubs` |
| `LocalStorageCoverageTest::testStoreThrowsWhenDirectoryIsStillMissingAfterMkdir` | 1 COVERAGE-CHASING — drives a defensive TOCTOU-only branch (`LocalStorage.php:39-41`) that `src` itself documents as reachable only via an `mkdir()`/`is_dir()` race; requires an elaborate custom stream wrapper and asserts the **same observable contract** (same `RuntimeException`, same message) as the existing mkdir-failure test | HIGH (identical observable behavior) | `StorageServiceTest::testLocalStorageFailsWhenBasePathCannotBeCreated` |

Notes on the subprocess / stream-wrapper machinery (task question):

- The `#[RunInSeparateProcess]` isolation in `QiniuStorageTest` is **justified, not overkill**, for the remaining stub tests. `StorageServiceTest::testQiniuRequiresSdkWhenConfigured` relies on the real Qiniu classes not being defined in the main process (its vendor-file skip is a proxy; leaked `eval()` stubs would silently defeat `assertSdkInstalled()`). Deleting the three duplicate `QiniuStorageTest` non-stub tests above does not remove that dependency — the two remaining stub tests (fallback + skipped bug doc) still need separate processes. The isolation story would only simplify further if the missing-SDK assertion itself were moved to a separate process and the stubs consolidated, which is out of scope here.
- The **stream wrapper in `LocalStorageCoverageTest::testStoreThrowsWhenDirectoryIsStillMissingAfterMkdir` is overkill**: it exists purely to reach a defensive branch with no distinct observable output.

## MERGE SUGGESTIONS

| Suggestion | Rationale |
|---|---|
| Fold `InitQiniuSettingsCommandValuesTest` (1 test) into `InitQiniuSettingsCommandTest` as a table-driven case | Same command, same fixtures (`EntityManagerInterface`/`SettingRepository` mocks, `CommandTester`), same 4-setting creation shape. `TEST_STRATEGY.md` explicitly prefers table-driven tests for "a rule with many equivalent inputs". Keeps the null-value branch while removing a one-test file. |
| (Optional, lower value) Once `QiniuStorageTest::testStoreUsesKeyReturnedBySdk` is deleted, keep `testStoreFallsBackToNameWhenSdkResultHasNoKey` as the single SDK-result-shape test | The two SDK-result tests were the two arms of the same ternary; the with-key arm remains covered by `StorageServiceTest`. |

## Verification steps

1. Baseline: `/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Storage` → expect 20 tests, 75 assertions, 1 skipped, exit 0.
2. After applying the 4 deletions + 1 merge: re-run `phpunit tests/Storage` → expect 15 tests (fallback, skipped bug doc, unconfigured/sdk tests removed), still exit 0.
3. Coverage sanity: `XDEBUG_MODE=coverage .../phpunit tests/Storage --coverage-html var/coverage` and confirm `src/Storage/` remains at ~100% lines (the deleted tests share their covered lines with retained tests).
4. Confirm the retained missing-SDK / unconfigured tests still execute on CI (SDK not installed): `StorageServiceTest::testQiniuRequiresSdkWhenConfigured` and `testQiniuRequiresConfiguration` must not be skipped by class leakage — run the full suite serially, not just `tests/Storage`.
5. Fragility note (not a redundancy): `LocalStorageCoverageTest::testDeleteThrowsWhenFileCannotBeUnlinked` depends on `chmod 0555` blocking `unlink()`; it will fail when CI runs as root. Keep an eye on it, but it is the only coverage of the delete-failure branch.
