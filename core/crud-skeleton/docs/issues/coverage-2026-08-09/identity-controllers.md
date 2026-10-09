# Identity — controllers / OTP service / create-user command coverage to ~100% & bug report

- Date: 2026-08-09
- Scope: `src/Identity/Controller/AuthController.php`, `src/Identity/Controller/OtpController.php`, `src/Identity/Service/OtpService.php`, `src/Identity/Command/CreateUserCommand.php`, `src/Identity/Service/ProfileService.php`
- Task: raise line coverage to ~100% and hunt for bugs.
- Constraint honored: **nothing under `src/` was modified** — only new test files added under `tests/` plus this report.
- Runner: `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit <file> --no-coverage` (PHP 8.5.1, PHPUnit 12.5.30).

## Coverage before → after

Baseline from `var/uncovered-map.txt` / `var/coverage2/` HTML (generated 2026-08-09, full suite).
After = measured with Xdebug + Clover against the combined existing + new tests for these classes
(see "Measurement command" below).

| File | Before | After | Remaining uncovered lines |
|---|---|---|---|
| `Identity/Controller/OtpController.php` | 86.05% (lines 42, 53, 65, 69, 73, 79) | **100%** (43/43) | — |
| `Identity/Controller/AuthController.php` | 93.46% (lines 280, 294, 295, 296, 297, 300, 387) | **100%** (107/107) | — |
| `Identity/Service/OtpService.php` | 93.75% (lines 111, 112, 115) | **100%** (48/48) | — |
| `Identity/Command/CreateUserCommand.php` | 94.37% (lines 83, 85, 137, 143) | **100%** (71/71) | — |
| `Identity/Service/ProfileService.php` | already 100% (0 uncovered) | **100%** (8/8) | — |

The `refresh()` success body (AuthController lines 341–345) is covered by the
existing `AuthIntegrationTest::testFullAuthFlow` in the full suite; a unit test
was added anyway so the class is 100% under unit tests too.

### Measurement command

```
XDEBUG_MODE=coverage php -d memory_limit=1G vendor/bin/phpunit \
  tests/Identity/Controller/AuthControllerTest.php \
  tests/Identity/Controller/OtpControllerTest.php \
  tests/Identity/Service/OtpServiceTest.php \
  tests/Identity/Command/CreateUserCommandTest.php \
  tests/Identity/Service/ProfileServiceTest.php \
  tests/Identity/Controller/AuthControllerCoverageTest.php \
  tests/Identity/Controller/OtpControllerCoverageTest.php \
  tests/Identity/Service/OtpServiceCoverageTest.php \
  tests/Identity/Command/CreateUserCommandCoverageTest.php \
  --coverage-filter src/Identity/Controller/AuthController.php \
  --coverage-filter src/Identity/Controller/OtpController.php \
  --coverage-filter src/Identity/Service/OtpService.php \
  --coverage-filter src/Identity/Command/CreateUserCommand.php \
  --coverage-filter src/Identity/Service/ProfileService.php \
  --coverage-clover /tmp/cov-run/clover.xml
```

## Test files added (26 tests: 22 passing + 4 skipped)

| File | Tests | Purpose |
| --- | --- | --- |
| `tests/Identity/Controller/OtpControllerCoverageTest.php` | 8 (+1 skipped) | requestOtp invalid purpose (42), requestOtp 204 (53), verifyOtp missing phone/otp (65), verifyOtp invalid purpose (69), verifyOtp invalid OTP (73), verifyOtp login unknown user / unverified phone (79), verify_phone unknown-user branch; skipped Bug A repro. |
| `tests/Identity/Controller/AuthControllerCoverageTest.php` | 8 (+2 skipped) | verifyOtp login unverified/unknown (280), verifyOtp verify_phone flag+flush (294–297, 300), verifyOtp verify_phone unknown user (300), logout with non-array JSON body (387), login with verified phone → tokens, refresh success → rotated tokens (341–345); skipped Bug A + Bug D repros. |
| `tests/Identity/Service/OtpServiceCoverageTest.php` | 3 | private `maskPhone()` (111, 112, 115) exercised through public `generateAndSend()`/`verify()` with a real PSR logger, capturing the masked `phone` in the log context (long phone → `+86****5678`, 4-char phone → `***`, max-attempts log path). |
| `tests/Identity/Command/CreateUserCommandCoverageTest.php` | 4 (+1 skipped) | username-already-exists failure (83, 85); normalizeRoles skips non-string/empty role values (137); comma-separated roles with empty segments (143); no-phone/no-role creation; skipped Bug E repro. |

Existing `tests/Identity/**` untouched; no new DB/integration tests were needed —
all new coverage is pure unit tests with mocked collaborators.

## Bugs found

### Bug A — unguarded `json_decode(..., JSON_THROW_ON_ERROR)` → HTTP 500 on empty/malformed bodies

- **Files/lines:** `AuthController.php` 73 (login), 134 (register), 331 (refresh), 385 (logout); `OtpController.php` 33 (requestOtp), 59 (verifyOtp).
- **Description:** every JSON endpoint decodes the raw body with `JSON_THROW_ON_ERROR` and no guard for a missing/empty/malformed body. `json_decode('', …)` throws `JsonException` (verified: `JsonException: Syntax error`). Only `logout()` guards the *empty* body (`$content === '' ? [] : …`, line 385), but a **non-empty malformed** body still throws.
- **Impact:** a client that sends no body or malformed JSON gets an uncaught `JsonException` (HTTP 500 / error page) instead of a controlled `400`; potentially leaks exception details in non-prod. All six OTP/login/register/refresh/logout entry points are affected.
- **Reproduction:** `POST /api/auth/otp/request` with empty body, or `POST /api/auth/logout` with body `{oops` → `JsonException` bubbles out of the controller.
- **Proposed fix:** wrap the decode in a `try/catch (\JsonException)` (or validate `json_last_error()` after a non-throwing decode) and return `$this->error(..., 400)`; for `logout()` extend the guard to non-empty malformed bodies as well.
- **Tests:** `OtpControllerCoverageTest::testRequestOtpWithMalformedBodyShouldReturnBadRequest` and `AuthControllerCoverageTest::testLogoutWithMalformedJsonShouldNotThrow` are **skipped** (they assert the fixed 400/204 behavior; the current src throws). Kept in the suite to guard the future fix.

### Bug B — `verify_phone` reports `phone_verified=true` for phone numbers with no user account

- **Files/lines:** `AuthController.php` 294–300; `OtpController.php` 93–99.
- **Description:** after a successful OTP verification with purpose `verify_phone`, the controller looks up the user and only persists the flag when the user exists, **but always** returns `['phone_verified' => true]` — even when `findByPhone()` returned `null`. The `login` purpose is inconsistent: it returns `401` for a missing/unverified user.
- **Impact:** the client is told the phone was verified when nothing was persisted; the user will still be blocked at OTP login (or any phone-verified gate) afterwards. The lie is only detectable by the client (and repeated requests re-issue OTPs). Minor, but an incorrect success contract.
- **Reproduction:** (1) request a `verify_phone` OTP for any phone with no account (requestOtp doesn't check existence), (2) verify it → HTTP 200 `{"phone_verified": true}` even though no row was updated.
- **Proposed fix:** either return a `404`/`401` when `findByPhone()` yields no user for `verify_phone`, or document that the response is intentionally opaque (anti-user-enumeration) and return a neutral message for unknown phones while persisting only for known ones. Note the current behavior is *observable* (it never reveals whether the user exists), so if that's the intent it should at least be consistent with the `login` purpose.
- **Tests:** `OtpControllerCoverageTest::testVerifyOtpVerifyPhoneReportsSuccessForUnknownUser` and `AuthControllerCoverageTest::testVerifyOtpVerifyPhoneReportsSuccessForUnknownUser` **characterize** the current behavior (they pass) and pin it down for the report.

### Bug C — `OtpService::maskPhone()` leaks short phone numbers in logs

- **File/line:** `OtpService.php` 109–116 (`maskPhone`).
- **Description:** `maskPhone()` returns `mb_substr($phone, 0, 3) . '****' . mb_substr($phone, -4)` for any phone longer than 4 characters. For a phone of length 5–7 the two substrings **cover every digit** (e.g. `'12345'` → `'123****2345'`), so the "masked" value is the full number with an asterisk inserted.
- **Impact:** for short phone numbers (5–7 digits — e.g. legacy/short country numbers) the OTP log line `[OTP] Sent … phone=…` exposes the complete number to anyone with log access. For the typical E.164 11–14-digit numbers the current masking is fine (`+86****5678`).
- **Reproduction:** call `generateAndSend('12345', …)` with a logger; the log context `phone` is `'123****2345'` (all five digits present).
- **Proposed fix:** mask by first N and last N with a size guard, e.g. hide all but the first/last 2 when `strlen < 8`, or reuse a constant mask like `substr($phone,0,2).'****'.substr($phone,-2)` and additionally ensure at least one masked middle character.
- **Tests:** none skipped — the current output is documented by `OtpServiceCoverageTest` (`+86****5678` for a 14-digit number, `***` for a 4-digit number).

### Bug D — users with purely numeric usernames can never log in with their username

- **File/line:** `AuthController.php` 82–89 (login); interacts with `UserRepository::findByIdentifier()` (40–51) which *does* handle phone identifiers.
- **Description:** `login()` routes every identifier matching `/^\+?[0-9]{7,20}$/` through `findByPhone()` and never falls back to `findByIdentifier()`. A user whose **username** consists only of digits (register permits any non-empty username string, e.g. `13800138000`) can therefore never authenticate with that username — `findByPhone()` returns `null` and the code returns `401 Invalid credentials` without ever consulting the username index.
- **Impact:** accounts with numeric usernames are un-loginable via username; the only working identifier is email (or their phone, if set+verified). Silent auth failure for a legitimate credential.
- **Reproduction:** register `num@example.com` / username `13800138000`; `POST /api/auth/login` with `identifier: '13800138000'`, correct password → `401` (verified: unit test asserts `200` fails with actual `401`).
- **Proposed fix:** when `findByPhone()` returns `null`, fall through to `findByIdentifier($identifier)` (or simply always call `findByIdentifier()` and map the unverified-phone case to 403 separately). `findByIdentifier()` already applies the `phoneVerified` guard for phone identifiers, so the phone semantics are preserved.
- **Tests:** `AuthControllerCoverageTest::testLoginWithNumericUsernameShouldSucceed` is **skipped** (asserts the fixed `200`; current src returns `401`).

### Bug E — `CreateUserCommand` accepts empty email/username and creates broken accounts

- **File/lines:** `CreateUserCommand.php` 70–86 (only the password is validated).
- **Description:** the command validates that the password is non-empty (70–74) but never validates the email/username arguments. With `email: ''` the command proceeds: `findByEmail('')` → `null`, then persists a `User` with `email = ''` and returns `SUCCESS` (verified: `persist()` is called and the command exits 0). `getUserIdentifier()` then falls back to `'anonymous'`.
- **Impact:** junk accounts with empty email/username; a second run with the same empty email hits the DB unique constraint and crashes with an uncaught `UniqueConstraintViolationException` instead of a friendly failure message.
- **Reproduction:** `php bin/console app:identity:user:create '' emptymail 'Password123!'` → creates an account with `email=""` and exits 0.
- **Proposed fix:** add guards mirroring the password check, e.g. `if ($email === '') { $io->error('Email cannot be empty.'); return Command::FAILURE; }` (and the same for username) before the repository lookups.
- **Tests:** `CreateUserCommandCoverageTest::testExecuteWithEmptyEmailShouldFail` is **skipped** (asserts `Command::FAILURE` and no `persist`; current src persists and returns `SUCCESS`).

### Bug F (minor / robustness) — `CreateUserCommand` drops a non-array `--role` value

- **File/line:** `CreateUserCommand.php` 94–96 (`$roleInputs = is_array($rolesOption) ? $rolesOption : []`).
- **Description:** when `getOption('role')` is not an array the roles are silently discarded. The real CLI (`ArgvInput`) always normalizes a single `--role=X` into `['X']` for `VALUE_IS_ARRAY` options (verified), so normal usage is fine — but `CommandTester`/`ArrayInput` and any programmatic invocation that passes a plain string lose every role silently.
- **Impact:** low; roles are silently ignored for callers that pass a string. Confusing when writing tests or embedding the command.
- **Reproduction:** `new CommandTester($cmd)->execute([... '--role' => 'ROLE_EDITOR'])` → created user has only `ROLE_USER`.
- **Proposed fix:** normalize defensively, e.g. `$roleInputs = is_array($rolesOption) ? $rolesOption : [$rolesOption];` and filter non-strings (line 136 already skips them).
- **Tests:** `CreateUserCommandCoverageTest::testExecuteSkipsNonStringAndEmptyRoleOptionValues` covers the skipping; the discrepancy is documented here, no test asserts the fixed behavior.

## Skipped tests (all keep the suite green)

| Test | Bug | Asserted (fixed) behavior |
|---|---|---|
| `OtpControllerCoverageTest::testRequestOtpWithMalformedBodyShouldReturnBadRequest` | A | malformed body → 400 |
| `AuthControllerCoverageTest::testLogoutWithMalformedJsonShouldNotThrow` | A | malformed body → 204, no throw |
| `AuthControllerCoverageTest::testLoginWithNumericUsernameShouldSucceed` | D | numeric-username login → 200 |
| `CreateUserCommandCoverageTest::testExecuteWithEmptyEmailShouldFail` | E | empty email → `Command::FAILURE`, no persist |

Verified individually (un-skipped) that each of these fails against the current `src/`, then re-skipped.

## Result

- 4 new test files, **26 tests, 71 assertions, 4 documented skips, exit 0** (no deprecations/notices/warnings).
- All five target files now at **100% line coverage** (measured with Xdebug + Clover on the combined existing + new tests).
- 6 findings documented (Bugs A–F), none fixed (per constraint — no `src/` changes).
