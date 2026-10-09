# Core Utils / DependencyInjection / Serializer Normalizer — Coverage & Bug Report

- Date: 2026-08-09
- Scope: `src/Core/Utils/{RsaClient,Location,Math,Inflect}.php`, `src/Core/DependencyInjection/{Configuration,CoreExtension}.php`, `src/Core/Serializer/Normalizer/CircularReferenceHandler.php`
- Task: raise line coverage to ~100% and find bugs.
- Constraint honored: **nothing under `src/` was modified**. Only new test files under `tests/` and this report were added.

## Test files added

All use `declare(strict_types=1);` and `extends \PHPUnit\Framework\TestCase` (pure unit tests, no DB):

| File | Class under test | What it covers |
|---|---|---|
| `tests/Core/Utils/RsaClientTest.php` | `RsaClient` | Real RSA key pairs generated with `openssl_pkey_new()` and injected through the public key properties. Covers `rsaSign`/`rsaVerifySign`, `sign`/`verifySign`, `getSignContent` (ksort + empty-value skipping), `checkEmpty`, `getPrivateKey`/`getPublicKey` happy + bad paths (inline PEM, raw-base64 wrapping, file path, missing file → `false`), `getPrivateKenLen`/`getPublicKenLen`, and full encrypt/decrypt round trips plus non-string/missing-key error returns. |
| `tests/Core/Utils/LocationTest.php` | `Location` | Drives `getLocation`/`getDistance` (success + `\ErrorException` catch paths) through a **test-only stub of `Curl\Curl`** (see Testability note). |
| `tests/Core/Utils/MathCoverageTest.php` | `Math` | `acosh`, `asinh`, `atanh`, `getrandmax`, `mt_getrandmax`, `mt_srand`, `srand` (seed determinism). |
| `tests/Core/Utils/InflectCoverageTest.php` | `Inflect` | `singularize()` fall-through branch (word matching no rule); confirms `pluralize()` catch-all. |
| `tests/Core/DependencyInjection/ConfigurationTest.php` | `Configuration` | `getConfigTreeBuilder()` returns a `TreeBuilder('core')`, root named `core`, empty config processes to `[]`, unknown options rejected. |
| `tests/Core/DependencyInjection/CoreExtensionTest.php` | `CoreExtension` | `load([], new ContainerBuilder())` loads `services.yaml`, registers all 6 service definitions, carries correct class/tag/decorator metadata, idempotent across repeated `load()`. |
| `tests/Core/Serializer/Normalizer/CircularReferenceHandlerTest.php` | `CircularReferenceHandler` | scalar id → `['id'=>…]`, non-scalar id (object/array) → `null`, missing `getId` → `\Exception`. |

## Coverage results

Baseline from `var/uncovered-map.txt` vs. status after the new tests (verified with `phpunit --coverage-html` on the affected files):

| File | Baseline uncovered | After | Notes |
|---|---|---|---|
| `Utils/RsaClient` | 0% (~110 lines) | **90.13% (137/152)** | remaining 15 lines are deprecated or unreachable (see below) |
| `Utils/Location` | 0% (15,18–24,30,33–39,44–45,47,50–56) | **69.57% (16/23)** | remaining 7 lines all belong to broken `getAddress` (Bug L-2) |
| `Utils/Math` | 53,55,58,72,78,84,85,86,91,102 | **95.16% (59/62)** | remaining 78, 85, 91 are a deprecation + two bugs (Bugs M-1/M-2) |
| `Utils/Inflect` | 135, 158 | **95.65% (22/23)** | remaining 135 is dead code (Bug I-1) |
| `DependencyInjection/Configuration` | 0% (17, 19) | **100%** | |
| `DependencyInjection/CoreExtension` | 0% (19, 20) | **100%** | |
| `Serializer/Normalizer/CircularReferenceHandler` | 0% (12,13,14,17) | **100%** | |

Full new-test run: **63 tests, 93 assertions, 0 failures/errors/notices/warnings/deprecations, 4 skipped** (each skip documents a bug or an untestable line; see Skipped items).

## Bugs found

### Bug R-1 — RSA signatures use cryptographically broken MD5 (CRITICAL)

- **File/line:** `src/Core/Utils/RsaClient.php:51` (`openssl_sign(..., OPENSSL_ALGO_MD5)`) and `:99` (`openssl_verify(..., OPENSSL_ALGO_MD5)`).
- **Description:** all sign/verify operations use MD5 as the digest algorithm.
- **Impact:** MD5 is broken (collision/forgery attacks); signatures are not tamper-proof. FIPS 140-2/3 environments typically disable MD5, so `openssl_sign`/`openssl_verify` fail silently in those environments. The class is a payment/RSA-signing helper, making this a security defect.
- **Reproduction:** `(new RsaClient())` with a valid key — `openssl_get_md5_methods()` still lists `md5` on PHP 8.5 so it works, but only because the environment permits MD5.
- **Proposed fix:** use `OPENSSL_ALGO_SHA256` (or `SHA-256` with the `openssl` CLI for compatible peers).

### Bug R-2 — `sign()` base64-encodes an undefined variable when `openssl_sign()` fails (HIGH)

- **File/line:** `src/Core/Utils/RsaClient.php:51-59`.
- **Description:** `openssl_sign()`’s return value is ignored. When it fails (e.g. `getPrivateKey()` returned a truthy-but-invalid wrapped string, or the environment rejects MD5), `$sign` is never assigned and `base64_encode($sign)` at line 58 runs on an undefined variable.
- **Impact:** PHP warning + a bogus signature (`base64_encode(null)`), silently masking the failure; with `failOnWarning` CI this is a hard failure at the call site.
- **Reproduction:** `$c = new RsaClient(); $c->rsaPrivateKey = 'garbage'; echo $c->sign('data');` — `getPrivateKey()` wraps the garbage into a truthy PEM, `openssl_sign` returns `false`, `base64_encode($sign)` warns on undefined `$sign`.
- **Proposed fix:**
  ```php
  if (!openssl_sign((string) $data, $sign, $res, OPENSSL_ALGO_SHA256)) {
      return '';
  }
  ```

### Bug R-3 — `getSignContent()` string-casts arbitrary values (MEDIUM)

- **File/line:** `src/Core/Utils/RsaClient.php:75` and `:77` (`$stringToBeSigned .= "$k" . "=" . "$v";`).
- **Description:** interpolation casts every value to string. Arrays become the literal `Array` (plus a PHP warning); objects invoke `__toString()` or throw.
- **Impact:** wrong signature content and/or warnings when a param value is non-scalar; signed payload no longer round-trips with the caller’s plain data.
- **Reproduction:** `$c->getSignContent(['a' => ['nested']])` → `"a=Array"` + warning.
- **Proposed fix:** validate that `$k`/`$v` are scalar (or JSON-encode complex values) before concatenation; at minimum check `is_scalar($v)`.

### Bug R-4 — `openssl_free_key()` is deprecated since PHP 8.0 (LOW)

- **File/line:** `src/Core/Utils/RsaClient.php:55` and `:104`.
- **Description:** `openssl_free_key()` is deprecated (no-op) since PHP 8.0; `OpenSSLAsymmetricKey` objects are freed automatically.
- **Impact:** every `sign()`/`verifySign()` that loads a key from a **file path** emits a deprecation notice (breaks `failOnDeprecation` CI). Because of this, lines 54–56 and 103–105 cannot be covered without triggering a deprecation — they remain uncovered.
- **Reproduction:** `$c->rsaPrivateKeyFilePath = 'key.pem'; $c->sign('data');` → `Function openssl_free_key() is deprecated since 8.0`.
- **Proposed fix:** remove both `if ($res instanceof \OpenSSLAsymmetricKey) { openssl_free_key($res); }` blocks.

### Bug M-1 — `Math::mt_rand()` and `Math::rand()` always throw on PHP 8.5 (HIGH)

- **File/line:** `src/Core/Utils/Math.php:85` (`return mt_rand($x);`) and `:91` (`return rand($x);`).
- **Description:** the one-argument form of `mt_rand()`/`rand()` (single arg treated as max) was deprecated in PHP 8.3 and is now an error in PHP 8.5: both native functions **require exactly two arguments**.
- **Impact:** any caller of `Math::mt_rand($n)` / `Math::rand($n)` crashes with an uncaught `ArgumentCountError`.
- **Reproduction:**
  ```php
  Math::rand(10); // ArgumentCountError: rand() expects exactly 2 arguments, 1 given
  Math::mt_rand(10); // ArgumentCountError: mt_rand() expects exactly 2 arguments, 1 given
  ```
- **Proposed fix:** `return mt_rand(0, $x);` and `return rand(0, $x);` (matches the intended “max only” signature).

### Bug M-2 — `Math::lcg_value()` delegates to a function deprecated since PHP 8.4 (LOW)

- **File/line:** `src/Core/Utils/Math.php:78` (`return lcg_value();`).
- **Description:** `lcg_value()` is deprecated since PHP 8.4 in favour of `\Random\Randomizer::getFloat()`.
- **Impact:** calling `Math::lcg_value()` raises a deprecation (breaks `failOnDeprecation` CI); the line cannot be covered without a deprecation.
- **Reproduction:** `Math::lcg_value();` → `Function lcg_value() is deprecated since 8.4`.
- **Proposed fix:** reimplement as `Randomizer::getFloat()` or `Math::random(0, 1)`.

### Bug L-1 — `Location` depends on `php-curl-class/php-curl-class`, which is not installed (CRITICAL)

- **File/line:** `src/Core/Utils/Location.php:5` (`use Curl\Curl;`), `:18`, `:33`, `:50` (`new Curl();`).
- **Description:** the class instantiates `Curl\Curl` from the `php-curl-class/php-curl-class` package, but that package is **not declared in `composer.json` and not present in `vendor/`** (`class_exists('Curl\Curl')` → `false`).
- **Impact:** every call to `getLocation()`, `getAddress()` or `getDistance()` throws `Error: Class "Curl\Curl" not found`. Because the thrown `Error` is not an `\ErrorException`, the `catch (\ErrorException)` blocks do **not** catch it — the methods are completely broken in the real app.
- **Reproduction:** `Location::getLocation('x');` → `Error: Class "Curl\Curl" not found`.
- **Proposed fix:** either add `php-curl-class/php-curl-class` to `composer.json`, or refactor to an injectable HTTP client (see Testability note) so tests can stub it.

### Bug L-2 — `getAddress()` calls `getResponse()` on a string (HIGH)

- **File/line:** `src/Core/Utils/Location.php:35` (`$data = json_decode($data->getResponse());`).
- **Description:** the methods are inconsistent: `getLocation()` (line 20) and `getDistance()` (line 52) do `json_decode($data)` where `$data` is the **string body** returned by `Curl::get()` (confirmed in php-curl-class: `exec()` returns `$this->response`). `getAddress()` instead calls `$data->getResponse()` on that string.
- **Impact:** `$data->getResponse()` on a string raises `Error: Call to a member function getResponse() on string`, which is not an `\ErrorException`, so the `catch` at line 38 never fires and the method fatals.
- **Reproduction:** any `getAddress($lat, $lng)` call.
- **Proposed fix:** mirror the other two methods:
  ```php
  $data = $curl->get($api_url);
  $data = json_decode($data);
  return $data->result->address ?? null;
  ```
  (Note: even after this fix, `getAddress()` returns `$e->getMessage()` on error while the other two methods return `null` — Bug L-4.)

### Bug L-3 — JSON responses are dereferenced without checking `status`/validity (MEDIUM)

- **File/line:** `src/Core/Utils/Location.php:22`, `:37`, `:54`.
- **Description:** after `json_decode`, the code accesses `$data->result->location` (etc.) without checking `json_last_error()`, the API `status` field, or null result. A Tencent error body (`{"status":310,"message":"..."}`) yields `$data->result === null`, and `null->location` raises an “Attempt to read property on null” warning (PHP 8) and returns `null`.
- **Impact:** warnings + confusing `null`/`false` returns instead of a structured error; masks API failures.
- **Reproduction:** stub a response `{"status":310,"message":"QUERY_FAULT"}` (or any non-200 status) → warning + `null`.
- **Proposed fix:** `if (json_decode(...) === null || ($data->status ?? 0) !== 0) return null;` before property access.

### Bug L-4 — inconsistent error contract across the three methods (LOW)

- **File/line:** `src/Core/Utils/Location.php:24` (`return null;`), `:39` (`return $e->getMessage();`), `:56` (`return null;`).
- **Description:** `getLocation`/`getDistance` return `null` on failure but `getAddress` returns the exception message string.
- **Impact:** callers cannot uniformly detect failure; `getAddress` returns a truthy string on error.
- **Proposed fix:** make all three return `null` on failure.

### Bug I-1 — `Inflect::pluralize()` fall-through is dead code (LOW)

- **File/line:** `src/Core/Utils/Inflect.php:135` (`return $string;`).
- **Description:** the final plural rule `'/$' => "s"` (`:54`) matches every string, so `pluralize()` always returns a transformed value and the trailing `return $string;` at line 135 can never execute.
- **Impact:** none functionally; the line is permanently uncovered (cannot be tested).
- **Reproduction:** `Inflect::pluralize('apple')` → `'apples'` (never falls through).
- **Proposed fix:** remove the unreachable `return $string;` (or remove the catch-all rule if unintended).

## Testability finding — `Location` (BLOCKER for full coverage)

`Location` is a static class that hard-codes `new Curl()` (lines 18, 33, 50) and the Tencent URLs in `const` values. It is **not dependency-injectable**, so the real class cannot be exercised without either the network or the `Curl\Curl` class existing. Additionally, the package is **not installed**, so the real methods cannot run at all.

To keep the suite green and still prove the decode/JSON paths, `LocationTest` defines a **test-only double of `Curl\Curl`** in the test file (the class name is otherwise unresolvable) whose `get()` returns a configurable response body string — faithfully matching php-curl-class’s `exec()` contract. This yields coverage of `getLocation`/`getDistance` success + error paths. `getAddress` is intentionally **not** covered: a faithful stub makes it throw the `\Error` described in Bug L-2, and per task rules no test may assert broken behaviour.

Proposed refactor for full testability:
1. Inject the HTTP client (`Curl` or Symfony `HttpClientInterface`) via constructor/static setter instead of `new Curl()`.
2. Make the API base URL + key configurable (constructor or constants → parameters), so tests can point at a fake endpoint.
3. Return typed DTOs/`null` instead of raw JSON objects.

## Uncovered lines still remaining (all intentional)

- `RsaClient.php` 54, 55, 103, 104 — `openssl_free_key()` deprecated since PHP 8.0; covering emits a deprecation (Bug R-4).
- `RsaClient.php` 230, 272, 313, 355 — `$partLen < 1` guard: requires an RSA key < 88 bits (encrypt) / < 8 bits (decrypt); `openssl_pkey_new()` minimum is 512 bits → unreachable.
- `RsaClient.php` 237, 279, 320, 362 — `$key === false` guards after `get*KenLen()` already validated the same key → logically unreachable defensive code.
- `RsaClient.php` 247, 289, 330 — `openssl_*_encrypt/decrypt` failure branches; unreachable with a valid key of the computed size (chunks always fit the modulus).
- `Math.php` 78 — `lcg_value()` deprecated since 8.4 (Bug M-2).
- `Math.php` 85, 91 — `mt_rand()`/`rand()` single-argument calls always throw on PHP 8.5 (Bug M-1).
- `Inflect.php` 135 — dead code (Bug I-1).
- `Location.php` 30, 33, 34, 35, 37, 38, 39 — all inside the broken `getAddress()` (Bug L-2).

## Skipped items

- `MathCoverageTest::testMtRand` — **skipped** (`See report — bug M-1`): asserts the correct behaviour (an int in `[0, max]`), which cannot hold because `Math::mt_rand()` always throws `ArgumentCountError`.
- `MathCoverageTest::testRand` — **skipped** (`See report — bug M-1`): same for `Math::rand()`.
- `MathCoverageTest::testLcgValue` — **skipped** (`See report — bug M-2`): covering it emits a deprecation.
- `LocationTest::testGetAddressIsBrokenByStringMethodCall` — **skipped** (`See report — bug L-2`): getAddress is broken; no test asserts the wrong behaviour.

## Command reference

```bash
# run all new tests for this scope
XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Core/Utils/RsaClientTest.php \
  tests/Core/Utils/LocationTest.php \
  tests/Core/Utils/MathCoverageTest.php \
  tests/Core/Utils/InflectCoverageTest.php \
  tests/Core/DependencyInjection \
  tests/Core/Serializer/Normalizer/CircularReferenceHandlerTest.php \
  --no-coverage

# verify coverage of the touched files
XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit \
  tests/Core/Utils tests/Core/DependencyInjection \
  tests/Core/Serializer/Normalizer/CircularReferenceHandlerTest.php \
  --coverage-html var/coverage-check-utils-di --no-progress
```
