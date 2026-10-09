# Core Parser / Serializer / EventListener — Coverage & Bug Report

- **Date:** 2026-08-09
- **Scope:** `src/Core/Parser/*`, `src/Core/Serializer/Normalizer/FlatNormalizer.php`, `src/Core/EventListener/{OpenApiEnricherListener,LocaleListener}.php`
- **Constraint:** no changes under `src/`; tests only + this report.
- **Baseline:** `var/uncovered-map.txt` / `var/coverage2/` HTML reports.

## 1. Summary

| File | Before | After | Remaining uncovered lines |
|---|---|---|---|
| `src/Core/Parser/ExpressionDqlParser.php` | 92.38% | **98.21%** (219/223) | 317, 394, 488, 541 (unreachable dead code, see §3.4) |
| `src/Core/Parser/ExpressionQueryBuilderAssembler.php` | 90.77% | **100%** (65/65) | — |
| `src/Core/Serializer/Normalizer/FlatNormalizer.php` | 80.30% | **100%** (66/66) | — |
| `src/Core/EventListener/OpenApiEnricherListener.php` | 98.44% | **100%** (128/128) | — |
| `src/Core/EventListener/LocaleListener.php` | 97.06% | **100%** (34/34) | — |

Measured with the full test suite:

```
XDEBUG_MODE=coverage php -d memory_limit=1G vendor/bin/phpunit \
  --coverage-filter src/Core/Parser/ExpressionDqlParser.php \
  --coverage-filter src/Core/Parser/ExpressionQueryBuilderAssembler.php \
  --coverage-filter src/Core/Serializer/Normalizer/FlatNormalizer.php \
  --coverage-filter src/Core/EventListener/OpenApiEnricherListener.php \
  --coverage-filter src/Core/EventListener/LocaleListener.php
```

## 2. Test files added (29 tests, all pure unit tests, green)

| File | Tests | Lines covered |
|---|---|---|
| `tests/Core/Parser/ExpressionDqlParserCoverageTest.php` | 10 | 117, 146–147, 154–155, 172, 277, 307, 351–352, 437, 501, 529 |
| `tests/Core/Parser/ExpressionQueryBuilderAssemblerCoverageTest.php` | 4 | 79–80, 111–112, 118, 123 |
| `tests/Core/Serializer/Normalizer/FlatNormalizerCoverageTest.php` | 10 | 42, 50, 58, 61–62, 64, 97, 99, 127, 147, 169–170, 180 |
| `tests/Core/EventListener/OpenApiEnricherListenerCoverageTest.php` | 4 | 111, 118 |
| `tests/Core/EventListener/LocaleListenerCoverageTest.php` | 1 | 57 |

Notable techniques used (no `setAccessible()`, no Reflection deprecations):

- `ExpressionDqlParser` is unit-tested against a mocked `Symfony\Component\ExpressionLanguage\ExpressionLanguage` to exercise the `parse()` / `getNodes()` exception wraps (lines 146–147, 154–155) that a real parser cannot produce.
- Unreachable-by-public-API branches of `validateFragments()` are exercised by writing private state through `ReflectionProperty::setValue()` (allowed since PHP 8.1, no `setAccessible()`), e.g. a crafted `where` string with an unknown alias (line 501) and an empty join path (line 529).
- Operator coverage: `in` (unsupported binary op → 277), unary `-` (`entity.getId() == -5` → 307), `[1, 2]` array literal (generic traversal branch 351–352).
- `FlatNormalizer`: Doctrine-ORM object with `__toString()` (uses `Doctrine\ORM\Mapping\ClassMetadata`, line 50); decorated-normalizer failure paths (58/61–64); relation `__metadata()` vs `__metadata` property (97/99); JSON-string attribute decode (127); `denormalize()` LogicException guard (147); `setSerializer()`/`setNormalizer()` forwarding stubs implementing `NormalizerAwareInterface`/`SerializerInterface`+`NormalizerInterface` (169–170, 180).
- `OpenApiEnricherListener` and `LocaleListener` exercised with real `Request`/`Response`/`*Event` objects.

Run:

```
XDEBUG_MODE=off php vendor/bin/phpunit \
  tests/Core/Parser/ExpressionDqlParserCoverageTest.php \
  tests/Core/Parser/ExpressionQueryBuilderAssemblerCoverageTest.php \
  tests/Core/Serializer/Normalizer/FlatNormalizerCoverageTest.php \
  tests/Core/EventListener/OpenApiEnricherListenerCoverageTest.php \
  tests/Core/EventListener/LocaleListenerCoverageTest.php --no-coverage
```

Result: 29 tests, 49 assertions, 0 failures, 0 errors, 0 deprecations. (The "PHPUnit Notices" are the repo-wide "no expectations configured for mock" notices already emitted by existing tests, e.g. `ExpressionQueryBuilderAssemblerFullTest`.)

## 3. Bugs found

### 3.1 FlatNormalizer corrupts string attributes that look like JSON — HIGH

- **Location:** `src/Core/Serializer/Normalizer/FlatNormalizer.php:124-130`
- **Description:** Every non-numeric string attribute read via the property accessor is passed through `json_decode()`. If the string happens to be valid JSON, the attribute value is silently replaced with the decoded PHP value — regardless of whether the attribute is a JSON column or an ordinary text field.
- **Impact:** Type corruption in normalized API output: a plain-text note `"true"` becomes boolean `true`, `"null"` becomes `null`, `"{}"` / `"[]"` become empty arrays, `"123.0"`… (numeric strings are excluded, but `"1e2"` → `100` is not). Consumers of the API receive wrong types and lose data fidelity. There is no way to opt a column out.
- **Reproduction:**
  ```php
  $obj = new class { public string $note = 'true'; public string $empty = '{}'; };
  (new FlatNormalizer(new ObjectNormalizer(), PropertyAccess::createPropertyAccessor()))
      ->normalize($obj, 'json');
  // ['note' => true, 'empty' => []]   ← strings replaced by non-strings
  ```
- **Proposed fix:** Only JSON-decode when the attribute is known to be a JSON column (e.g. driven by a configured column allow-list or a `json` Doctrine mapping hint passed via context), or keep the string when the decoded value is not an array/object; do not blanket-decode every string field.

### 3.2 FlatNormalizer discards all relation fields except id/__toString/__metadata — MEDIUM

- **Location:** `src/Core/Serializer/Normalizer/FlatNormalizer.php:106-108` (+ `reduceTransform` at 88-103)
- **Description:** When a raw attribute value is an object with `getId()` (a relation), the decorated normalizer's baseline for that attribute (which may contain `name`, `slug`, `price`, etc.) is **overwritten wholesale** by `reduceTransform($raw)`, which emits only `id`, `__toString`, `__metadata`.
- **Impact:** Related-entity payloads are reduced to an id (and possibly a label), silently dropping every other field the decorated normalizer produced. If the API is expected to return those fields (e.g. category `name` on a product), the response is incomplete.
- **Reproduction:** decorated normalizer returns `['related' => ['id' => 9, 'name' => 'Pizza', 'price' => 9.99]]`; the related object has `getId()` → output relation becomes `['id' => 9, '__toString' => 'Pizza']` (`name`/`price` lost).
- **Proposed fix:** Merge the reduced representation with the baseline (`array_merge` the `id`/`__toString`/`__metadata` keys onto the decorated output, or only fill missing keys), so existing relation fields survive.

### 3.3 LocaleListener does not parse valid Accept-Language q-value forms with optional whitespace / case — LOW

- **Location:** `src/Core/EventListener/LocaleListener.php:78-82`
- **Description:** `parseAcceptLanguage()` splits on the exact token `;q=`. RFC 7230/7231 permits optional whitespace around the `;`/`=` separators and case-insensitive parameter names. A header like `zh-CN; q=1.0, en; q=0.5` (space after `;`, emitted by some clients) is not split, so `lang` becomes `"zh-CN; q=1.0"`, fails `resolveLocale()`, and the request silently falls back to the default locale `en`. Same for `fr;Q=1.0`.
- **Impact:** Well-formed browsers/proxies can be served the wrong language. (Confirmed: `zh-CN; q=1.0, en; q=0.5` yields locale `en` instead of `zh`.)
- **Reproduction:**
  ```php
  $request->headers->set('Accept-Language', 'zh-CN; q=1.0, en; q=0.5');
  $listener->onKernelRequest(new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST));
  // $request->getLocale() === 'en'  (expected 'zh')
  ```
- **Proposed fix:** Normalize each item first (collapse whitespace / lowercase `q`), e.g. `preg_split('/\s*;\s*q\s*=\s*/i', $item)` instead of a literal `explode(';q=', ...)`, and also trim the language tag.

### 3.4 ExpressionDqlParser — unreachable defensive branches (dead code) — LOW

The four lines that cannot be raised to 100% are unreachable by any public input. Each is a defensive branch whose guard can never fail:

| Line | Guard | Why unreachable |
|---|---|---|
| 317 | `if (empty($matches[1])) throw 'Invalid attribute access'` | The guard regex at 312 (`/^entity(\.get[A-Z]\w+\(\))+$/`) already guarantees ≥1 capture, so `$matches[1]` is never empty. |
| 394 | `throw 'matches pattern parameter was not compiled'` | `compileMatchOperand()` calls `recursiveCompile()` on the right operand first, which always inserts the parameter into `$this->parameters` before the lookup loop runs; the loop always finds it. |
| 488 | `if (count($segments) === 0) throw 'Empty path'` | `explode('.', $path)` returns at least one element for every possible string. |
| 541 | `if (strpos($token, ':') === 0) continue` | The where-token regex at 537 (`[a-zA-Z_][\w]*(?:\.[a-zA-Z_][\w]*)+`) can never produce a match starting with `:`, so the colon-prefix skip never triggers. |

**Note on 541:** this is also a latent correctness smell — because the regex starts at a letter, `:filter_parameter_1` is *not* excluded by this guard, it simply never matches (no dot). More importantly the regex **does** match dotted paths inside string literals (e.g. a JSON-ish where clause), which would make `validateFragments()` throw on unrelated text. Worth a follow-up regex hardening (e.g. negative lookbehind for `:` / quote handling).

### 3.5 ExpressionQueryBuilderAssembler swallows join failures silently — LOW/MEDIUM

- **Location:** `src/Core/Parser/ExpressionQueryBuilderAssembler.php:116-126`
- **Description:** `leftJoin()` failures are caught and ignored (`catch (\Exception $e) { /* ignore join errors */ }`), and duplicate join aliases are silently skipped (117-119). If a join path is invalid, assembly "succeeds" while the WHERE clause still references the missing join alias.
- **Impact:** Errors surface later, as an obscure DQL "alias is not joined" failure at query execution, or the query silently returns different results than intended when an alias is reused for a different path.
- **Proposed fix:** At minimum log the swallowed exception; consider rethrowing a `ValidatorException` for invalid join paths (consistent with the class's other validation), and error (or rename) on alias collision instead of skipping.

### 3.6 OpenApiEnricherListener — minor robustness issues — LOW

- `src/Core/EventListener/OpenApiEnricherListener.php:110-112` — `$content === false` is unreachable (only reachable when `getContent(true)` is used, which it never is); empty-string guard is the real path.
- `:115` — an HTML `/api/doc` response whose body starts with `{` (leading template text) would be misclassified as raw JSON and `json_decode`d; harmless today but fragile.
- `:196` — `$t['name']` is dereferenced without checking the key exists; a spec whose `tags` entries lack `name` (e.g. custom tooling) would raise an undefined-key warning/notice.
- `:134-138` — the `if (is_string($newJson))` block is mis-indented (functionally correct, but confusing).

No test was marked skipped: all "correct behavior" tests I wrote pass against the current source. The 4 dead branches in §3.4 were intentionally left uncovered (they are unreachable); attempting to force them would require reflection-injected fake AST nodes that cannot occur in practice.

## 4. Suite status

- The 5 new test files: **green** (29 tests / 49 assertions, no failures/errors/deprecations).
- Full suite with the new tests and coverage: **2019 tests, 0 failures, 7 skipped** (pre-existing skips, none in these files). Coverage measurements above come from this run.
- A *separate* full run without coverage in this sandbox additionally shows pre-existing DB integration failures (`Doctrine\DBAL\Exception\TableNotFoundException` — the SQLite schema is not loaded in this environment, e.g. `common_content`, `users`). These are environment/setup errors unrelated to this task; they occur in `tests/**/Integration/*` and `tests/**/Controller/*Repository*` tests and predate these changes. The targeted unit tests do not touch the DB.

## 5. Suggested follow-ups

1. Fix §3.1 (JSON string corruption) and §3.2 (relation field loss) in `FlatNormalizer` — both change API response payloads.
2. Harden §3.3 (`Accept-Language` parsing) with a proper tokenizer.
3. Clean up the §3.4 dead branches or add a `@codeCoverageIgnore`/comment so coverage audits stop flagging them.
4. Make `ExpressionQueryBuilderAssembler` join failures visible (§3.5).
