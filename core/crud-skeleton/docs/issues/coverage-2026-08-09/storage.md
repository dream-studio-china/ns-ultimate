# Storage module — coverage to ~100% & bug hunt (2026-08-09)

Scope: `src/Storage/` drivers + registry + qiniu settings command.
Goal: raise line coverage to ~100% and document bugs. **No code under `src/` was modified.**

## Test files added

| File | Tests | Purpose |
| --- | --- | --- |
| `tests/Storage/Service/LocalStorageCoverageTest.php` | 2 | Cover the two remaining uncovered branches of `LocalStorage` (`var/uncovered-map.txt`: lines 40 and 65). |
| `tests/Storage/Service/QiniuStorageTest.php` | 6 | Missing-SDK `store()` path, unconfigured/partial-config `delete()`/`store()`, SDK key fallback branch, and a skipped correct-behavior test documenting a bug. Stub-based scenarios run in a separate PHP process so they never leak into `StorageServiceTest`. |
| `tests/Storage/Command/InitQiniuSettingsCommandValuesTest.php` | 1 | Empty/whitespace option values collapsing to `null` settings. |

Total added: 9 tests (8 passing + 1 documented skip). Existing `tests/Storage/**` untouched.

## Coverage results

Measured by running `phpunit tests/Storage --coverage-html` with PHP 8.5 + Xdebug.

| File | Before | After |
| --- | --- | --- |
| `Storage/Service/LocalStorage.php` | 92% (23/25) — lines **40, 65** uncovered | **100%** |
| `Storage/Service/QiniuStorage.php` | 100% | 100% |
| `Storage/Service/MediaStorageRegistry.php` | 100% | 100% |
| `Storage/Service/MediaStorageInterface.php` | n/a (interface) | n/a |
| `Storage/Command/InitQiniuSettingsCommand.php` | 100% | 100% |

New coverage:
- `LocalStorage::store()` second `is_dir()` guard → `throw` at line 40. In production this branch is only reachable through a `mkdir()`/`is_dir()` TOCTOU race; the test drives it deterministically via a registered stream wrapper whose `is_dir()` always reports `false` while `mkdir()` reports success.
- `LocalStorage::delete()` `!unlink($realPath)` → `throw` at line 65, exercised by removing write permission from the parent directory (non-root user).
- `QiniuStorage::store()` missing-SDK error path (only `delete()` was previously tested for the missing SDK).
- `QiniuStorage::delete()` / `store()` with empty and partial configuration.
- `QiniuStorage::store()` line 36 fallback (`$name` used when the SDK result carries no `key`).
- Command: empty-string options produce `Setting` entities with `null` values.

Full suite green: `tests/Storage` = 20 tests, 75 assertions, 1 skipped, exit 0.

## Bugs found

### Bug #1 — `QiniuStorage::delete()` does not verify the path belongs to the configured domain

- **File/line:** `src/Storage/Service/QiniuStorage.php:41-49` (unguarded key extraction at line 46).
- **Description:** Unlike `LocalStorage::delete()` (which returns early for foreign paths at `LocalStorage.php:51`), `QiniuStorage::delete()` forwards **any** path to the Qiniu API. The key is computed as `str_replace(domain.'/', '', $path)` — if the path does not contain the domain, the whole (foreign) URL becomes the object key.
- **Impact:** Deleting a URL that is not under the configured CDN domain triggers an unnecessary remote Qiniu API call and would attempt to delete an object whose key is the entire foreign URL string; if such keys exist in the bucket, unrelated objects can be removed. The bare domain path (`https://cdn.example.com` without trailing slash) is also turned into a delete key.
- **Reproduction (verified with SDK stubs, domain `https://cdn.example.com`):**
  - `delete('https://other.example.com/logo.png')` → `BucketManager::delete('bucket', 'https://other.example.com/logo.png')` (API called).
  - `delete('https://cdn.example.com')` → `BucketManager::delete('bucket', 'https://cdn.example.com')` (API called).
  - `delete('https://cdn.example.com/')` → key `''` → returns (only guarded case).
- **Proposed fix:** mirror `LocalStorage::delete()` — require the path to start with the domain before extracting the key:
  ```php
  public function delete(string $path): void
  {
      $config = $this->config();
      $this->assertSdkInstalled();

      $domain = rtrim($config['domain'], '/');
      if (!str_starts_with($path, $domain . '/')) {
          return;
      }

      $key = ltrim(substr($path, strlen($domain)), '/');
      if ($key === '') {
          return;
      }
      // ... existing auth + bucketManager->delete(...) logic
  }
  ```

### Bug #2 (minor) — `LocalStorage::delete()` emits an unsuppressed E_WARNING on unlink failure

- **File/line:** `src/Storage/Service/LocalStorage.php:64` (`if (is_file($realPath) && !unlink($realPath))`).
- **Description:** `unlink()` is called without `@` or an error handler. When deletion fails (e.g., permission denied), PHP emits `unlink(...): Permission denied` before the intended `RuntimeException` is thrown at line 65.
- **Impact:** The failure path leaks a raw PHP warning to output/logs; it also trips strict test configurations (`failOnWarning="true"` in `phpunit.dist.xml`).
- **Reproduction:** `chmod 0555` on the month directory, then `delete()` an existing file inside it → warning + `RuntimeException`.
- **Proposed fix:** suppress the warning, e.g. `if (is_file($realPath) && !@unlink($realPath)) { throw new \RuntimeException(...); }`.

## Observations (not bugs)

- `LocalStorage::store()` path-traversal attempt via a crafted `$name` (e.g. `../escape.txt`) is neutralized by Symfony's `File::getName()` basename stripping — the file is stored inside the target directory. Verified empirically; not a bug.
- `LocalStorage.php:39-41` is defensive-only: it can only be reached via a `mkdir()`/`is_dir()` race, which is why it was uncovered in production paths.
- `MediaStorageRegistry` keys drivers by `getName()`; a silent name collision (two drivers returning the same name) silently overrides the previous driver. Low severity, arguably by design.
- `QiniuStorage::delete()` also uses `str_replace(..., $path)` which strips the domain from anywhere in the string (not only a prefix); fixing Bug #1 replaces this with a prefix check and removes the issue.

## Skipped tests

- `QiniuStorageTest::testDeleteOfPathOutsideConfiguredDomainIsIgnored` — correct-behavior test asserting that `delete()` of a foreign URL makes **no** API call. It fails against the current implementation (Bug #1), so it is `markTestSkipped` to keep the suite green and documents the expected behaviour/fix.
- `QiniuStorageTest` missing-SDK tests are skipped automatically when the Qiniu SDK/stub classes are already defined in the process (e.g. when run after `StorageServiceTest` in the same PHPUnit process); with the current suite ordering they run normally.
