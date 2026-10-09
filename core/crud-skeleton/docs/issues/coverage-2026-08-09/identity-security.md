# Identity — security/token/OTP-storage/User coverage to ~100% & bug hunt (2026-08-09)

Scope: `src/Identity/Security/TokenManager.php`, `src/Identity/Security/JwtAuthenticator.php`, `src/Identity/Service/RedisOtpStorage.php`, `src/Identity/Entity/User.php`.
Goal: raise line coverage to ~100% and document bugs. **No code under `src/` was modified.**

## Test files added

| File | Tests | Purpose |
| --- | --- | --- |
| `tests/Identity/Security/TokenManagerAdditionalTest.php` | 14 | Constructor error paths, malformed-but-signed JWT payloads, refresh-token rotation edge cases (unknown token, transaction rollback x2), `revokeAllForUser()` delegation, passphrase-fallback behaviour. |
| `tests/Identity/Security/JwtAuthenticatorAdditionalTest.php` | 3 | `onAuthenticationSuccess()`, translated `onAuthenticationFailure()` message-key path, non-numeric `sub` coercion. |
| `tests/Identity/Entity/UserAdditionalTest.php` | 5 | `setProfile()` back-reference synchronization (both branches), replace-profile and idempotent cases. |
| `tests/Identity/Service/RedisOtpStorageTest.php` | 6 (5 pass + 1 skipped) | Full OTP lifecycle against a fake RESP server; error-reply coercions; skipped correct-behavior test for Bug #1. |
| `tests/Identity/Service/Resources/fake_redis_server.php` | fixture | Minimal in-memory RESP server (SELECT/EXISTS/GET/SETEX/DEL/TTL + `-ERR` for `err_*` keys) started as a subprocess on a random free port. |

Total added: **28 tests, 91 assertions, 1 skipped**. Existing `tests/Identity/**` untouched.

## Coverage results

Measured with PHP 8.5 + Xdebug (`XDEBUG_MODE=coverage phpunit --coverage-html`), combining the pre-existing tests for these classes with the new ones.

| File | Before | After | Remaining uncovered |
| --- | --- | --- | --- |
| `Identity/Security/TokenManager.php` | 88.08% (133/151) | **96.69%** (146/151) | 50, 100, 101, 121, 332 — all provably unreachable (see below) |
| `Identity/Security/JwtAuthenticator.php` | 100% | **100%** | — |
| `Identity/Entity/User.php` | 87.10% (27/31) | **100%** (31/31) | — |
| `Identity/Service/RedisOtpStorage.php` | 0% (0/9) | **100%** (9/9) | — |

Full run of the new files: **28 tests, 91 assertions, 1 skipped, exit 0**.

## Testability note — RedisOtpStorage (no redis-server available)

- `RedisAdapter::createConnection($dsn)` resolves to **Predis** (no `redis`/`relay` extension loaded; `php -m` shows only Xdebug). Predis is lazy, so the `RedisOtpStorage` **constructor never throws** — but every operation throws `Predis\Connection\Resource\Exception\StreamInitException` ("Connection refused") because no redis-server binary/daemon exists in this environment.
- Workaround used: a tiny RESP server (`tests/Identity/Service/Resources/fake_redis_server.php`) is launched as a subprocess on a free localhost port, and `RedisOtpStorage` is constructed with `redis://127.0.0.1:<port>/0`. The fake server implements exactly the commands used (SELECT/EXISTS/GET/SETEX/DEL/TTL) and answers `-ERR` for `err_*` keys so the defensive `is_string`/`is_int` coercion branches are exercised. This yields real (integration-flavoured) coverage without installing any extension.
- Long-term proposal: inject the Redis-like client via an interface/constructor argument instead of a DSN (interface-based DI), which would make the class unit-testable without a server and would also let the caller reuse a shared connection. Until then `LocalCacheOtpStorage` remains the tested fallback path.

## Bugs found

### Bug #1 — `RedisOtpStorage::exists()` reports `true` when the store answers with an error

- **File/line:** `src/Identity/Service/RedisOtpStorage.php:23` — `return (bool) $this->redis->exists($key);`.
- **Description:** With `exceptions=false` (set by `RedisAdapter::createConnection`), a RESP error reply is surfaced as a `Predis\Response\Error` object, not thrown. `(bool)` of that object is `true`, so a storage failure makes `exists()` claim the key **is present**. Every other method coerces errors to the safe "absent" default (`get()` → `false`, `del()`/`ttl()` → `0`), so `exists()` is the odd one out.
- **Impact:** If the OTP store fails mid-flow, `exists()` looks like the code exists — an OTP can be treated as present/consumed and a valid login blocked, masking a storage outage. Low severity (requires the store itself to fail), but inconsistent and misleading.
- **Reproduction (verified):** point the storage at the fake RESP server and call `exists('err_exists')` (the server replies `-ERR fake failure for EXISTS`) → returns `true`.
- **Proposed fix:** coerce errors to a safe default, e.g.
  ```php
  $result = $this->redis->exists($key);
  return $result === 1 || $result === true;
  ```
  (and, for symmetry, treat a `Predis\Response\Error` as "not present").
- **Skipped test:** `RedisOtpStorageTest::testExistsReturnsFalseWhenServerErrors` asserts the correct behaviour and is `markTestSkipped` to keep the suite green until fixed.

### Bug #2 (dead code) — `TokenManager::decodeAccessToken()` repeats the blacklist check it already performed

- **File/line:** `src/Identity/Security/TokenManager.php:119-122`.
- **Description:** `decodeAccessToken()` calls `decodeAccessTokenWithoutBlacklist()`, which **already** checks `isAccessTokenRevoked($jti)` at lines 183-186 and returns `null` for a revoked token. The re-check at lines 120-121 is therefore unreachable (the blacklist is deterministic within a request) — line 121 is never executed.
- **Impact:** none at runtime (harmless redundancy), but the extra branch is dead code that implies the private method skips the check, which it does not. It also quietly added 1 uncovered line to coverage.
- **Proposed fix:** drop lines 119-122 (`decodeAccessToken()` just returns the private method's result).

### Bug #3 (dead code) — `revokeAccessToken()` unreachable `$jti === null` guard

- **File/line:** `src/Identity/Security/TokenManager.php:330-333`.
- **Description:** `decodeAccessTokenWithoutBlacklist()` requires `jti` to be a string (line 169) or it returns `null` (handled at line 325-328). Therefore `$payload['jti']` can never be `null` here and line 332 is unreachable.
- **Impact:** none (defensive-only); dead branch.
- **Proposed fix:** remove the guard.

### Bug #4 (dead code) — private-key "dev fallback" passphrase branch can never fire

- **File/line:** `src/Identity/Security/TokenManager.php:48-51`.
- **Description:** For an **unencrypted** key, PHP 8.5's `openssl_pkey_get_private($pem, $passphrase)` ignores the passphrase and returns the key (verified empirically), so `$privateKey === false` is false and the fallback at line 50 is skipped. For a genuinely **encrypted** key with a wrong passphrase, both the passphrase attempt and the fallback (no passphrase) fail, reaching line 54. There is no key state for which the first call fails but the no-passphrase retry succeeds.
- **Impact:** none — the intended "allow unencrypted key even when passphrase is configured" behaviour already works (the passphrase is simply ignored), but the fallback branch itself is unreachable/misleading.
- **Proposed fix:** remove lines 48-51, or document that the passphrase is only meaningful for genuinely encrypted keys.

### Unreachable defensive branch — `openssl_sign()` failure path

- **File/line:** `src/Identity/Security/TokenManager.php:100-101`.
- **Description:** The constructor guarantees a valid private key; `openssl_sign()` with SHA-256 succeeded for RSA, EC and DSA keys in this environment. The `$signed !== true` branch could not be triggered without invalidating the constructor contract.
- **Recommendation:** leave as defence-in-depth; not testable in a unit test. Documented rather than force-tested.

## Observations (not bugs)

- `User::setProfile()` (line 153-160) does not detach the previously assigned profile. After `setProfile($a)` then `setProfile($b)`, `$a->getUser()` still points at `$user`. If both profiles were flushed, the OneToOne `user_id` unique constraint could be violated. Normal flows only set a profile once, so severity is low; a `setProfile(null)`/detach step would make replacement safe. (Not covered by a failing test — documented for the fixer.)
- `JwtAuthenticator::supports()` (line 33) is case-sensitive (`'Bearer '`); `Authorization: bearer xyz` is not recognised. Many JWT stacks accept case-insensitive scheme names; minor.
- `TokenManager::rotateRefreshToken()` reuse detection (lines 249-253) intentionally only treats *rotation-replaced* tokens as theft; a token revoked manually (e.g. logout) with no replacement yields "invalid or expired" rather than "reuse detected" — by design.
- `RedisOtpStorage` constructor performs no I/O (Predis lazy client); a misconfigured DSN fails only on the first command, not at construction time.

## Skipped tests

- `RedisOtpStorageTest::testExistsReturnsFalseWhenServerErrors` — correct-behavior test for **Bug #1** (error replies must not make `exists()` return `true`). Fails against the current implementation, so it is skipped and documents the expected behaviour + fix.
