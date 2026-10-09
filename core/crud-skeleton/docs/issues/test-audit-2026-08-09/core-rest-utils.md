# Core (Utils/Listener/Controller/Serializer) test audit (2026-08-09)

Read-only audit of `tests/Core/Utils`, `tests/Core/EventListener`, `tests/Core/Controller` (root + `System/`), `tests/Core/Serializer` (+ `Normalizer/`), `tests/Core/Exception`, `tests/Core/DependencyInjection`. Cross-referenced against `src/Core/*` and the integration suite (`tests/Integration/Core*ApiTest`).

Context: the 2026-08-09 coverage campaign (see `docs/issues/coverage-2026-08-09/README.md`) added many `*CoverageTest` / `*ExtendedTest` / `*Coverage2Test` files pushing line coverage to 99.46%. Several duplicate behavior already locked by pre-existing tests, or assert only that lines run. Skipped tests that document `src/` bugs (Location L-2, Math M-1/M-2) are **KEEP** by policy.

## Summary — table

| File | Tests | Verdict |
|---|---|---|
| `Utils/ArrayCollectionTest.php` | 11 | KEEP (some trivial init cases → trim) |
| `Utils/ArrayCommonTest.php` | 3 | MERGE into Extended |
| `Utils/ArrayCommonExtendedTest.php` | 11 | KEEP |
| `Utils/FilterDateTimeTest.php` | 1 | DELETE candidate (near-empty wrapper, dead util) |
| `Utils/FixJSONTest.php` | 11 | KEEP (base) |
| `Utils/FixJSONExtendedTest.php` | 10 | PARTIAL-DELETE (5 dup of base) |
| `Utils/InflectTest.php` | 2 | DELETE (fully subsumed by Extended) |
| `Utils/InflectExtendedTest.php` | 15 | KEEP |
| `Utils/InflectCoverageTest.php` | 3 | DELETE candidate (trivial, dup of round-trip) |
| `Utils/LocationTest.php` | 5 (1 skipped) | KEEP (documents broken class + L-2 bug) |
| `Utils/MathTest.php` | 13 | KEEP |
| `Utils/MathExtendedTest.php` | 25 | PARTIAL-DELETE (2 tautological / dup) |
| `Utils/MathCoverageTest.php` | 8 (3 skipped) | KEEP (skips document M-1/M-2) |
| `Utils/RsaClientTest.php` | 25 | KEEP (only real crypto coverage) |
| `Utils/StringCaseTest.php` | 1 | MERGE into Extended |
| `Utils/StringCaseExtendedTest.php` | 13 | KEEP |
| `Utils/UUIDTest.php` | 2 | DELETE (1 exact dup + 1 subsumed) |
| `Utils/UUIDExtendedTest.php` | 15 | KEEP |
| `EventListener/AccessLogListenerTest.php` | 18 | KEEP |
| `EventListener/ControllerListenerTest.php` | 1 | DELETE (dup of Extended) |
| `EventListener/ControllerListenerExtendedTest.php` | 5 | KEEP |
| `EventListener/ExceptionInterceptorTest.php` | 1 | DELETE (dup of Extended) |
| `EventListener/ExceptionInterceptorExtendedTest.php` | 11 | KEEP |
| `EventListener/LocaleListenerTest.php` | 13 | KEEP |
| `EventListener/LocaleListenerCoverageTest.php` | 1 | DELETE (dup of LocaleListenerTest) |
| `EventListener/OpenApiEnricherListenerCoverageTest.php` | 4 | KEEP (guards not covered elsewhere; 2 of the 4 are the same branch) |
| `Controller/RestControllerTest.php` | 11 | KEEP |
| `Controller/RestControllerExtendedTest.php` | 11 | KEEP |
| `Controller/RestControllerCoverageTest.php` | 12 | PARTIAL-DELETE (3 exact dups) |
| `Controller/RestControllerCoverage2Test.php` | 13 | KEEP (note 4 IMPLEMENTATION-DETAIL getter tests) |
| `Controller/RestControllerPaginationIntegrationTest.php` | 1 | KEEP (unique real-DB path) |
| `Controller/System/EntityControllerTest.php` | 5 | PARTIAL-DELETE (2 dups of Extended) |
| `Controller/System/EntityControllerExtendedTest.php` | 10 | KEEP |
| `Controller/System/RouterControllerTest.php` | 3 | KEEP |
| `Controller/System/RouterControllerExtendedTest.php` | 5 | PARTIAL-DELETE (4 tautological) |
| `Serializer/CircularReferenceHandlerTest.php` | 2 | KEEP (weak fallback assertion) |
| `Serializer/ObjectCallbackTest.php` | 2 | KEEP (tests unwired class) |
| `Serializer/SerializerContextFactoryTest.php` | 1 | DELETE (passes even if SUT is a no-op) |
| `Serializer/Normalizer/CircularReferenceHandlerTest.php` | 5 | KEEP (tests orphaned duplicate class) |
| `Serializer/Normalizer/FlatNormalizerExtendedTest.php` | 9 | PARTIAL-DELETE (2 NEAR-EMPTY `assertTrue(true)`) |
| `Serializer/Normalizer/FlatNormalizerCoverageTest.php` | 12 | KEEP (unique branches + JSON-string-corruption bug doc) |
| `Exception/MessageHttpExceptionTest.php` | 2 | DELETE (subsumed by Extended) |
| `Exception/MessageHttpExceptionExtendedTest.php` | 7 | KEEP |
| `DependencyInjection/ConfigurationTest.php` | 4 | KEEP |
| `DependencyInjection/CoreExtensionTest.php` | 3 | KEEP |

## KEEP — bullet list

**Utils**
- `ArrayCommonExtendedTest` — real ExpressionLanguage `filter/map/reduce` behavior; the only place these helpers are validated.
- `ArrayCommonTest` — small but includes `testMergeMatchesCurrentLegacyBehavior`, a genuine regression guard for the surprising `array_merge($arrays)` wrapping.
- `FixJSONTest` — base coverage of `getJSONType`/`fixJSON`, both used in `RestController` and `CreateApiViewMixin`.
- `InflectExtendedTest` — the substantive inflector rule table (irregular/uncountable/x-ch-sh/round-trip). `Inflect::singularize` is used in `BaseServiceMutationTrait`.
- `LocationTest` — the only harness that can run `Location` (its `php-curl-class` dependency is absent); the skipped `testGetAddressIsBrokenByStringMethodCall` documents bug L-2. KEEP, incl. the skip.
- `MathCoverageTest` — the three `markTestSkipped` cases document real bugs M-1 (`mt_rand()/rand()` one-arg crash on PHP 8.5) and M-2 (`lcg_value()` deprecation).
- `MathTest` — real boundary assertions for `random`, `abs`, `round`, `locationDistance`, exact constant equality.
- `RsaClientTest` — the only real RSA sign/verify/encrypt round-trip coverage; documents the MD5-signing and undefined-variable bugs (R-1/R-2). None skipped, all assert current behavior.
- `StringCaseExtendedTest` — full dash→camel behavior matrix.
- `UUIDExtendedTest` — v3/v4/v5 format bits, namespaces, determinism, whitespace rejection; `UUID` is used across every module.
- `UUIDTest::testV4ProducesValidUuid` behavior is subsumed, but harmless until deletion of the file (see DELETE).

**EventListener**
- `AccessLogListenerTest` — thorough and unique: method whitelist, auth-path body hiding, truncation, binary/empty bodies, anon/username resolution.
- `ControllerListenerExtendedTest`, `ExceptionInterceptorExtendedTest`, `LocaleListenerTest` — the substantive branch tables.
- `OpenApiEnricherListenerCoverageTest` — the four early-return guards are not exercised by `CoreOpenApiEnricherApiTest` (which only hits the happy path). Note: `testJsonContentWithoutPathsReturnsEarly` and `testJsonArrayContentWithoutPathsReturnsEarly` hit the *same* `!is_array($spec) || !isset($spec['paths'])` guard — collapse those two.

**Controller**
- `RestControllerTest` + `RestControllerExtendedTest` — envelope shape, `@display` (complex/reduce/expr), `@expands`, pagination slices, 204.
- `RestControllerCoverage2Test` — unique error/edge branches (`resolveService`, expand chain-shift, silent getter/expression swallow). The four dependency-getter exception tests (`getRequestStack`/`getSerializer`/`getTranslator`/`getService`) are thin IMPLEMENTATION-DETAIL — consider after merge.
- `RestControllerPaginationIntegrationTest` — only test that drives `pagination()` through a real QueryBuilder + DoctrinePaginator. KEEP (do not parallelize with DB-bootstrapping tests).
- `EntityControllerExtendedTest`, `RouterControllerTest` — retain after trimming dups.
- `CoreExtensionTest` / `ConfigurationTest` — verify the DI wiring in `services.yaml`/`Configuration`; genuinely useful contract tests.

**Serializer / Exception / DI**
- `FlatNormalizerCoverageTest` — unique branches (decorated-failure fallback, `__metadata` reduce, JSON-string decode — which documents the JSON-string-corruption bug, bug 11 in the coverage report), serializer/normalizer forwarding.
- `Normalizer/CircularReferenceHandlerTest` — KEEP only because it still documents the contract of a class that *may* be referenced by future wiring; note the class is orphaned today (nothing in `src/`/`config/` references `App\Core\Serializer\Normalizer\CircularReferenceHandler`).
- `MessageHttpExceptionExtendedTest` — keep as the canonical file.
- `Serializer/CircularReferenceHandlerTest` — keep (this is the handler actually wired in `config/packages/serializer.yaml`).

## DELETE CANDIDATES — table

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `Utils/UUIDTest::testV4cHasNoDashes` | 2 DUPLICATE — identical assertions (`assertSame(32, strlen(...))` + `assertStringNotContainsString('-', ...)`) | High | `Utils/UUIDExtendedTest::testV4cNoDashes` |
| `Utils/UUIDTest::testV4ProducesValidUuid` | 1 COVERAGE-CHASING — `v4()` then `is_valid()` is implied by format/version-bit tests | Medium | `Utils/UUIDExtendedTest::testV4FormatIsCorrect`, `testIsValidWithStandardUuid` |
| `Utils/InflectTest::testPluralizeAndSingularize` | 2 DUPLICATE — same words, same expected strings | High | `Utils/InflectExtendedTest::testPluralizeRegular` + `testSingularizeRegular` |
| `Utils/InflectTest::testPluralizeIf` | 2 DUPLICATE — same inputs/outputs | High | `Utils/InflectExtendedTest::testPluralizeIfSingular` + `testPluralizeIfPlural` |
| `Utils/InflectCoverageTest::testSingularizeReturnsInputWhenNoRuleMatches` | 1 COVERAGE-CHASING — only visits the fall-through `return $string` | Medium | behavior implied by `Utils/InflectExtendedTest::testPluralizeAndSingularizeRoundTrip` |
| `Utils/InflectCoverageTest::testPluralizeCatchesAllStringsWithFinalRule` | 1 COVERAGE-CHASING — documents the catch-all `'/$/' => 's'` rule already exercised by `pluralize('test')` | Medium | `Utils/InflectExtendedTest::testPluralizeRegular` |
| `Utils/FixJSONExtendedTest::testFixJsonWithEmptyString` | 2 DUPLICATE — both `assertSame('', FixJSON::fixJSON(''))` | High | `Utils/FixJSONTest::testFixJsonHandlesEmpty` |
| `Utils/FixJSONExtendedTest::testGetJsonTypeWithNullLiteral` | 2 DUPLICATE — both `assertFalse(FixJSON::getJSONType('null'))` | High | `Utils/FixJSONTest::testGetJsonTypeNull` |
| `Utils/FixJSONExtendedTest::testGetJsonTypeWithLeadingWhitespaceAndArray` | 2 DUPLICATE — same branch/assertion (`'array'` for `"  \n[..]"`) | High | `Utils/FixJSONTest::testGetJsonTypeWithLeadingWhitespaceArray` |
| `Utils/FixJSONExtendedTest::testFixJsonAlreadyValid` | 2 DUPLICATE — valid-JSON passthrough | High | `Utils/FixJSONTest::testFixJsonPreservesDoubleQuotes` |
| `Utils/FixJSONExtendedTest::testGetJsonTypeWithNestedObject` | 2 DUPLICATE — same `'object'` branch as base `testGetJsonTypeObject` | Medium | `Utils/FixJSONTest::testGetJsonTypeObject` |
| `Utils/MathExtendedTest::testRandomInRange` | 2 DUPLICATE — same function/branch as base `testRandomRange` (bounds 5–10 vs 10–20) | Medium-High | `Utils/MathTest::testRandomRange` |
| `Utils/MathExtendedTest::testAllConstants` | 1 COVERAGE-CHASING — `assertGreaterThan(>0/>1/>2)` thresholds are tautologies; passes with wrong constant values | High | — (weak) |
| `Utils/FilterDateTimeTest::testGetReturnsDateTimeInstance` | 5 NEAR-EMPTY / 1 COVERAGE-CHASING — `get()` is literally `new \DateTime($time, $timezone)` on an otherwise-unused util | Medium | — (dead util; see verification steps) |
| `EventListener/ControllerListenerTest::testLogsOnlyForWriteMethods` | 2 DUPLICATE — POST≡PUT in the same `/(PUT|POST)/i` branch | High | `ControllerListenerExtendedTest::testLogsForPutMethod` + `testSkipsLoggingForGetMethod` |
| `EventListener/ExceptionInterceptorTest::testProdEnvironmentSetsResponseForApiPath` | 2 DUPLICATE — superset assertions in Extended | Medium-High | `ExceptionInterceptorExtendedTest::testResponseJsonStructure` + `testUsesExceptionCodeAsHttpStatusWhenInRange` |
| `EventListener/LocaleListenerCoverageTest::testMissingAcceptLanguageLeavesPreSetLocaleIntact` | 2 DUPLICATE — same `$acceptLanguage === null` early-return branch; only initial locale differs | High | `LocaleListenerTest::testNoHeaderOrQueryKeepsDefaultLocale` |
| `Controller/RestControllerTest::testSuccessWith204ReturnsEmptyResponse` | 2 DUPLICATE — identical 204/empty-content assertions | High | `RestControllerExtendedTest::testSuccessWith204Status` |
| `Controller/RestControllerCoverageTest::testSuccessWithPostRequestNoPagination` | 2 DUPLICATE — same method name + identical assertions (POST → no `paginator`) | High | `RestControllerExtendedTest::testSuccessWithPostRequestNoPagination` |
| `Controller/RestControllerCoverageTest::testDisplayReduceOnArray` | 2 DUPLICATE — same `@display=reduce` → `{id,__toString}` branch | High | `RestControllerTest::testDisplayReduceProducesIdAndToString` |
| `Controller/RestControllerCoverageTest::testDisplayExpressionObject` | 2 DUPLICATE — same `@display={"val":"entity.getId()"}` expression branch | High | `RestControllerTest::testExpressionEvaluationInDisplay` |
| `Controller/RestControllerCoverageTest::testPaginationOnGetRequest` | 2 DUPLICATE — same page/limit slice assertions | Medium-High | `RestControllerTest::testPaginationUsesBuiltInPaginationOnGet`, `RestControllerExtendedTest::testSuccessWithArrayCollection` |
| `Controller/RestControllerCoverage2Test::testGetRequestStackThrowsWhenNotInjected` | 3 IMPLEMENTATION-DETAIL — asserts exception text of a protected getter | Medium | — |
| `Controller/RestControllerCoverage2Test::testGetSerializerThrowsWhenNotInjected` | 3 IMPLEMENTATION-DETAIL — same | Medium | — |
| `Controller/RestControllerCoverage2Test::testGetTranslatorThrowsWhenNotInjected` | 3 IMPLEMENTATION-DETAIL — same | Medium | — |
| `Controller/RestControllerCoverage2Test::testGetServiceThrowsWhenServicePropertyMissing` | 3 IMPLEMENTATION-DETAIL — same | Medium | — |
| `Controller/System/EntityControllerTest::testRetrieveActionHandlesSlashReplacementInEntityName` | 2 DUPLICATE — same `str_replace('/', '\\', ...)` branch; Extended version is stricter (asserts `getClassMetadata` arg) | High | `EntityControllerExtendedTest::testRetrieveActionReplacesAllSlashesInEntityName` |
| `Controller/System/EntityControllerTest::testRetrieveActionReturnsAssociationMappings` | 2 DUPLICATE — ManyToOne/OneToOne subset of the 4-type table | Medium-High | `EntityControllerExtendedTest::testRetrieveActionWithAllAssociationTypes` |
| `Controller/System/EntityControllerTest::testListActionReturnsEmptyArrayWhenNoEntities` | 2 DUPLICATE — same empty-metadata mock + `listAction()` path | Medium | `EntityControllerExtendedTest::testSuccessResponseStructureForListAction` |
| `Controller/System/RouterControllerExtendedTest::testListActionWithRoutesHavingDifferentMethods` | 1 COVERAGE-CHASING — `listAction()` is `success($router->getRouteCollection()->all())`; varying Route `methods` runs zero new code | Medium-High | `RouterControllerTest::testListActionReturnsAllRoutes` |
| `Controller/System/RouterControllerExtendedTest::testListActionWithRoutesHavingDefaults` | 1 COVERAGE-CHASING — same, varying defaults | Medium-High | `RouterControllerTest::testListActionReturnsAllRoutes` |
| `Controller/System/RouterControllerExtendedTest::testListActionWithComplexRouteRequirements` | 1 COVERAGE-CHASING — same, varying requirements | Medium-High | `RouterControllerTest::testListActionReturnsAllRoutes` |
| `Controller/System/RouterControllerExtendedTest::testListActionWithManyRoutes` | 1 COVERAGE-CHASING — same code path with 50 routes; asserts Symfony `RouteCollection` behavior, not the controller | Medium-High | `RouterControllerTest::testListActionReturnsAllRoutes` |
| `Serializer/SerializerContextFactoryTest::testCreateBuildsExpectedContext` | 1 COVERAGE-CHASING — input equals expected output, so it **passes even if `create()` were a no-op returning `$options`**; never exercises the cast/default logic | High | — |
| `Serializer/Normalizer/FlatNormalizerExtendedTest::testSetSerializerDoesNotThrow` | 5 NEAR-EMPTY — `assertTrue(true)` only | High | `FlatNormalizerCoverageTest::testSetSerializerForwardsAsNormalizerToNormalizerAwareDecorated` |
| `Serializer/Normalizer/FlatNormalizerExtendedTest::testSetNormalizerDoesNotThrow` | 5 NEAR-EMPTY — `assertTrue(true)` only | High | `FlatNormalizerCoverageTest::testSetNormalizerForwardsToNormalizerAwareDecorated` |
| `Exception/MessageHttpExceptionTest::testMessageErrorHttpExceptionDefaults` | 2 DUPLICATE — 403 + message + redirectUrl all asserted in Extended | High | `MessageHttpExceptionExtendedTest::testMessageErrorHttpExceptionStatusCode` + `testMessageErrorHttpExceptionHeadersWithRedirectUrl` |
| `Exception/MessageHttpExceptionTest::testMessageSuccessHttpExceptionDefaults` | 2 DUPLICATE — 200 + message + redirectUrl all asserted in Extended | High | `MessageHttpExceptionExtendedTest::testMessageSuccessHttpExceptionStatusCode` + `testMessageSuccessHttpExceptionHeadersWithRedirectUrl` |

## MERGE SUGGESTIONS

Merge candidates (consolidate `Extended`/`Coverage` files back into their base, keeping only the non-duplicated cases listed above):

1. `FixJSONTest` + `FixJSONExtendedTest` → one `FixJSONTest`.
2. `ArrayCommonTest` + `ArrayCommonExtendedTest` → one `ArrayCommonTest`.
3. `MathTest` + `MathExtendedTest` + `MathCoverageTest` → one `MathTest` (keep the three skipped bug-docs).
4. `StringCaseTest` + `StringCaseExtendedTest` → one `StringCaseTest`.
5. `UUIDTest` + `UUIDExtendedTest` → one `UUIDExtendedTest` (keep `testV4ProducesValidUuid` if desired).
6. `RestControllerTest` + `RestControllerExtendedTest` + `RestControllerCoverageTest` + `RestControllerCoverage2Test` → one `RestControllerTest` (the 5-file split is pure campaign residue).
7. `EntityControllerTest` + `EntityControllerExtendedTest` → one `EntityControllerTest`.
8. `RouterControllerTest` + `RouterControllerExtendedTest` → one `RouterControllerTest` (keep only the empty/non-empty envelope cases).
9. `MessageHttpExceptionTest` + `MessageHttpExceptionExtendedTest` → one file (Extended).
10. `ControllerListenerTest` + `ControllerListenerExtendedTest` → one file (Extended).
11. `ExceptionInterceptorTest` + `ExceptionInterceptorExtendedTest` → one file (Extended).
12. `InflectExtendedTest` + `InflectCoverageTest` → one file after the base is deleted.
13. `OpenApiEnricherListenerCoverageTest` — collapse `testJsonContentWithoutPathsReturnsEarly` + `testJsonArrayContentWithoutPathsReturnsEarly` into one.

## Verification steps

Run after every deletion (PHP 8.4+ runtime per TEST_STRATEGY):

```bash
/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Core/Utils tests/Core/EventListener tests/Core/Controller tests/Core/Serializer tests/Core/Exception tests/Core/DependencyInjection
```

Then the full gate:

```bash
/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit
composer phpstan
composer rector:types:check
```

Coverage caveats (important before batch-deleting):

- **Safe deletes** (no coverage regression because the duplicate keeps the lines covered): all `2 DUPLICATE` rows (UUID v4c, Inflect, FixJSON, RestController 204/post/reduce/expr, EntityController slash/assoc, MessageHttpException, LocaleListener missing-header, ControllerListener, ExceptionInterceptor).
- **Coverage-sensitive deletes** — deleting these drops coverage of lines that only these tests touch, and the 90% line gate may trip:
  - `SerializerContextFactoryTest` — `SerializerContextFactory` is only referenced by `services.yaml` + this test; deleting it uncovers the class.
  - `FilterDateTimeTest` — `FilterDateTime::get()` is exercised only here.
  - Dead utils (`Math`, `StringCase`, `Location`, `RsaClient`, `Utils\ArrayCollection`, `Serializer\Normalizer\CircularReferenceHandler`, `ObjectCallback`) are referenced from `src/` nowhere except tests. Prune only alongside a `src/` cleanup (add `@codeCoverageIgnore` or remove the dead class) or accept the coverage drop with maintainer sign-off.
- Verify the two `FlatNormalizerExtendedTest` `assertTrue(true)` deletions against `FlatNormalizerCoverageTest::testSetSerializerForwardsAsNormalizerToNormalizerAwareDecorated` / `testSetNormalizerForwardsToNormalizerAwareDecorated` (they assert the actual forwarding contract and keep `setSerializer`/`setNormalizer` covered).
- Do not run `RestControllerPaginationIntegrationTest` in parallel with other DB-bootstrapping integration tests (`var/test.db` schema teardown race).
- Skipped tests asserting documented `src/` bugs (Location L-2, Math M-1/M-2, OpenApi BUG-7) must remain — they are intentional KEEP.
