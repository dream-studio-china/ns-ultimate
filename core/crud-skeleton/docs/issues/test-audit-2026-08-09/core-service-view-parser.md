# Core (Service/View/Parser) test audit (2026-08-09)

Read-only audit of `tests/Core/Service`, `tests/Core/View`, `tests/Core/Parser` for redundant tests
added by the 2026-08-09 coverage campaign (commit `4c62b5f`, +96 test files) and pre-existing
duplication. Nothing under `src/` or `tests/` was modified; this report is the only deliverable.

Campaign context: `docs/issues/coverage-2026-08-09/README.md`; per-module reports
`core-service.md`, `core-view.md`, `core-parser-listener.md`. Test-ownership rule:
`TEST_STRATEGY.md` ("one behaviour → one primary layer"), `BUSINESS_INVARIANTS.md`
("assert the public or persisted outcome, not that the implementation method was called").

## Summary

| File | Tests | Verdict |
|---|---|---|
| tests/Core/Service/BaseServiceUnitTest.php | 3 | DELETE |
| tests/Core/Service/BaseServiceCoverageTest.php | 5 | DELETE |
| tests/Core/Service/BaseServiceInfrastructureTraitTest.php | 14 | KEEP |
| tests/Core/Service/BaseServiceInfrastructureTraitCoverageTest.php | 17 | KEEP (MERGE) |
| tests/Core/Service/BaseServiceMutationTraitTest.php | 17 | KEEP |
| tests/Core/Service/BaseServiceMutationTraitCoverageTest.php | 14 (1 skipped) | KEEP (MERGE) |
| tests/Core/Service/BaseServiceReadListTraitTest.php | 20 | KEEP |
| tests/Core/Service/BaseServiceReadListTraitCoverageTest.php | 5 | KEEP (MERGE) |
| tests/Core/Service/DefaultServiceLocatorTest.php | 8 | KEEP |
| tests/Core/Service/DefaultServiceLocatorCoverageTest.php | 4 | MERGE (1 duplicate) |
| tests/Core/Service/ExpressionServiceTest.php | 1 | KEEP |
| tests/Core/Service/ExpressionServiceCoverageTest.php | 4 | KEEP (MERGE) |
| tests/Core/Service/LegacyEvaluatorTest.php | 2 | KEEP |
| tests/Core/Service/LegacyEvaluatorCoverageTest.php | 1 | DELETE |
| tests/Core/Service/QueryBuilderFactoryTest.php | 1 | KEEP |
| tests/Core/View/ApiViewMessagesTest.php | 3 | KEEP |
| tests/Core/View/ApiViewTest.php | 5 | KEEP |
| tests/Core/View/CreateApiViewMixinTest.php | 5 | KEEP |
| tests/Core/View/DeleteApiViewMixinTest.php | 4 | KEEP |
| tests/Core/View/DetailApiViewMixinTest.php | 3 | KEEP |
| tests/Core/View/ScopedApiViewMixinTest.php | 2 | KEEP |
| tests/Core/View/SingleCreateAndUpdateApiViewMixinTest.php | 12 | KEEP |
| tests/Core/View/SingleCreateAndUpdateApiViewMixinCoverageTest.php | 2 | MERGE |
| tests/Core/View/TransformContentTest.php | 11 | KEEP |
| tests/Core/View/UpdateApiViewMixinTest.php | 11 | KEEP |
| tests/Core/View/WorkflowApiViewMixinTest.php | 11 (2 skipped) | KEEP |
| tests/Core/Parser/ExpressionDqlParserTest.php | 20 | KEEP |
| tests/Core/Parser/ExpressionDqlParserXTest.php | 8 | DELETE (mostly duplicate) |
| tests/Core/Parser/ExpressionDqlParserFullTest.php | 24 | KEEP (trim 3 matches dups) |
| tests/Core/Parser/ExpressionDqlParserMatchesTest.php | 8 methods / ~37 cases | KEEP |
| tests/Core/Parser/ExpressionDqlParserCoverageTest.php | 10 | KEEP (trim 2 impl-detail) |
| tests/Core/Parser/ExpressionQueryBuilderAssemblerFullTest.php | 12 | KEEP |
| tests/Core/Parser/ExpressionQueryBuilderAssemblerCoverageTest.php | 4 | KEEP (trim 3 defensive) |

Net: **2 files** fully redundant (`BaseServiceUnitTest`, `BaseServiceCoverageTest`),
**1 file** largely redundant (`ExpressionDqlParserXTest`), **1 test** coverage-chasing
(`LegacyEvaluatorCoverageTest`), plus ~15 individual methods flagged for trimming and
6 merge groups. Deleting the HIGH items has **zero** coverage impact because every one
has an exact covering test listed below.

## KEEP — behavioral tests worth preserving

- `BaseServiceInfrastructureTraitTest` — `listResultToCollection`, `externalExpressionValues`,
  lazy EM/logger/serializer/validator/QBF/ExpressionService/LegacyEvaluator accessors, current-request
  resolution. Primary unit coverage of the trait.
- `BaseServiceMutationTraitTest` — `new()` (no-ctor/required-ctor), scalar/refetch update, to-one/to-many
  relation resolution + sync, date mapping conversion, flush-skip, validation rejection, remove() success/failure.
- `BaseServiceReadListTraitTest` — `get()` by id/uuid/object/array/QB (incl. NoResult), `list()` with
  `@dql/@filter/@order/@sort/@select/@groupBy/@hints/@showDQL`, admin-gating of privileged params, identity-select
  and non-string-select rejections, legacy in-memory filter fallback. This is the only place the
  admin-gated `@dql/@sort/@hints` branches can be exercised (HTTP path is broken — BUG-1 in
  `core-integration-extra.md`, asserted by `tests/Integration/CoreDynamicQueryApiTest`).
- `BaseServiceInfrastructureTraitCoverageTest` — the `wrapInTransaction()` commit/rollback/inactive-skip/fallback
  paths and container re-fetch branches the base test does not reach; genuine branch coverage.
- `BaseServiceMutationTraitCoverageTest` — relation not-found → `NotFoundHttpException`, unknown-key `continue`,
  `UniqueConstraintViolationException` → `ValidatorException('Duplication entries')`, generic flush rethrow,
  direct `DateTimeInterface` assignment, `ReflectionException` → `false`. Distinct branches.
- `BaseServiceReadListTraitCoverageTest` — empty root-alias `ValidatorException`, multi-segment `@select` join
  generation, sorter-failure `return 0` (locks in the comparator contract bug 2).
- `DefaultServiceLocatorTest` — entity-manager/logger/token-storage/serializer/validator lookup and fallbacks.
- `ExpressionServiceTest` + `ExpressionServiceCoverageTest` — `buildFilter()` cache-hit rebuild, garbage-cache
  fallthrough, cache-store, `ArrayCollection`→array parameter conversion.
- `LegacyEvaluatorTest` — globals/context evaluation, invalid-expression → `false`.
- `QueryBuilderFactoryTest` — `create()` = `select()+from()`.
- `ApiViewMessagesTest` — string constants + the two message helpers (protects the response envelope text).
- `ApiViewTest` — `mixToCommonFilter`/`mixIdToCommonFilter` numeric-vs-uuid merging.
- `CreateApiViewMixinTest` — partial-create skips transaction, empty transformer, invalid-JSON scalar rejection,
  error envelope for create failures.
- `DeleteApiViewMixinTest` / `DetailApiViewMixinTest` — 404/success/warning envelopes for the public read/write mixins.
- `ScopedApiViewMixinTest` — scoped list/detail filter composition.
- `SingleCreateAndUpdateApiViewMixinTest` (+Coverage) — required/accepted property filtering for create and update,
  combined rules, empty-accepted pass-through, falsy result → warning, `NotFoundHttpException` → 404.
- `TransformContentTest` — Service/entity gateway allow-listing (blocks `eraseEverything`, `getSecret`, mutators,
  relation getters), criteria validation, non-iterable/non-object list results, missing-service fallback.
- `UpdateApiViewMixinTest` — required-update-property 400, transformer application, batch non-partial (transaction)
  and partial (skip-failed) paths, non-array content rejection, generic-exception 500 envelope.
- `WorkflowApiViewMixinTest` — `todoAction`/`availableTransitionsAction`/`doTransitionAction`/`resetMarkingAction`
  behavior; skipped tests document the `resetMarkingAction` route bug (Bug 1) and missing not-found guards (Bug 2).
- `ExpressionDqlParserTest` — compile basics, cache hit/write/failure, syntax/empty-expression errors, negation,
  `&&`/`||` on attributes, `getSource` QB/manual, `reset`, setters return `$this`.
- `ExpressionDqlParserFullTest` — operator matrix (`!=`, `>`, `<=`, `+`, booleans, `!`), nested relations,
  dynamic-attribute values, `validateFragments` accept/reject matrix.
- `ExpressionDqlParserMatchesTest` — the `matches` operator: plain-text LIKE+ESCAPE escaping table, regex-flag
  normalization table, invalid-flag rejection, non-string operand, field-to-field rejection, composition,
  join retention, SQL-injection parameterization. The definitive matches spec.
- `ExpressionQueryBuilderAssemblerFullTest` — parameter-collision rename, add-`from`-when-no-root, wrapper
  exception messages, plus happy-path assembly.
- `ExpressionQueryBuilderAssemblerCoverageTest::testDuplicateJoinAliasIsSkipped` — the only behavioral (non-defensive)
  test in that file.

## DELETE CANDIDATES

| File::method | Reason code | Confidence | What still covers the behavior |
|---|---|---|---|
| Service/BaseServiceUnitTest.php::testListResultToCollectionSupportsArrayAndFallback | 2 DUPLICATE | HIGH | `BaseServiceInfrastructureTraitTest::testListResultToCollectionWithArray` (same input `[1,2,3]`, same assertCount) + `::testListResultToCollectionWithString` (same `'invalid'` fallback) |
| Service/BaseServiceUnitTest.php::testGetByIdAndCriteriaAndQueryBuilder | 2 DUPLICATE | HIGH | `BaseServiceReadListTraitTest::testGetByIntegerId` (id `7`, same assertSame), `::testGetByArrayCriteria`, `::testGetByQueryBuilderReturnsSingleResult` |
| Service/BaseServiceUnitTest.php::testExternalExpressionValuesContainExpectedKeys | 2 DUPLICATE | HIGH | `BaseServiceInfrastructureTraitTest::testExternalExpressionValues` (asserts the same keys `math/datetime/ArrayCommon` plus two more) |
| Service/BaseServiceCoverageTest.php::testListWithNoFilter | 2 DUPLICATE | HIGH | `BaseServiceReadListTraitTest::testListWithNoFilterReturnsAll` (same `list(null,null,true)`, same two-entity fake, assertCount 2) |
| Service/BaseServiceCoverageTest.php::testListWithArrayFilter | 2 DUPLICATE | HIGH | `BaseServiceReadListTraitTest::testListWithArrayFilter` (same `['name'=>'alpha']` filter, assertCount 1 + name check) |
| Service/BaseServiceCoverageTest.php::testGetByIntegerId | 2 DUPLICATE | HIGH | `BaseServiceReadListTraitTest::testGetByIntegerId` |
| Service/BaseServiceCoverageTest.php::testGetByArray | 2 DUPLICATE | HIGH | `BaseServiceReadListTraitTest::testGetByArrayCriteria` |
| Service/BaseServiceCoverageTest.php::testNewOnStdClass | 2 DUPLICATE | MEDIUM | `BaseServiceMutationTraitTest::testNewWithNoConstructor` (same `new()` no-required-ctor branch; only entity class differs) |
| Service/DefaultServiceLocatorCoverageTest.php::testGetSerializerReturnsNullWhenBothServicesMissing | 2 DUPLICATE | HIGH | `DefaultServiceLocatorTest::testGetSerializerWhenMissing` (both `get()` calls throw → `null`; the Coverage version only swaps the exception type for `ServiceNotFoundException`) |
| Service/LegacyEvaluatorCoverageTest.php::testEvaluateReturnsFalseWhenLanguageIsNull | 1 COVERAGE-CHASING (defensive dead branch) | MEDIUM | None needed: `$language === null` is unreachable in production — the constructor always builds `new ExpressionLanguage()` (`LegacyEvaluator.php:22`), and the test only reaches the branch by subclassing and nulling `$this->language`. |
| Service/BaseServiceReadListTraitCoverageTest.php::testListLegacyFilterFallbackCatchesEvaluatorFailure | 2 DUPLICATE (partial) | LOW | `BaseServiceReadListTraitTest::testListWithFilterErrorFallsBackToLegacyFilterAndSorter` (same expression-service-throw fallback; only the injected evaluator differs) |
| Service/BaseServiceReadListTraitCoverageTest.php::testListLegacySorterSortsWithWorkingEvaluator | 2 DUPLICATE (partial) | LOW | `BaseServiceReadListTraitTest::testListWithFilterErrorFallsBackToLegacyFilterAndSorter` (same `@sort=x.getId() > y.getId()` in-memory sort path) |
| Service/BaseServiceMutationTraitCoverageTest.php::testUpdateConvertsStringValueForDateTimeTypedProperty | 2 DUPLICATE (partial) | LOW | `BaseServiceMutationTraitTest::testUpdateConvertsDateMapping` (same `isDateLikeMapping`→`new \DateTime` path; only the mapping-vs-named-type branch differs) |
| Parser/ExpressionDqlParserXTest.php::testCompileWithTwoLevelNestedRelation | 2 DUPLICATE | HIGH | `ExpressionDqlParserFullTest::testGetSourceIncludesJoinsAndWhere` — the exact same expression `entity.getCategory().getParent().getId() == 1` is compiled there |
| Parser/ExpressionDqlParserXTest.php::testValidateFragmentsDoesNotThrow | 2 DUPLICATE + 5 NEAR-EMPTY | HIGH | `ExpressionDqlParserTest::testValidateFragmentsWithValidFragmentsSucceeds` (identical mocked EM/metadata + `assertTrue(true)`) |
| Parser/ExpressionDqlParserXTest.php::testGetSourceWithQueryBuilder | 2 DUPLICATE | HIGH | `ExpressionDqlParserTest::testGetSourceWithoutQueryBuilderBuildsManualDql` (despite its name, XTest calls `getSource(null)` and asserts the same manual `SELECT filter_entity ... WHERE` output) |
| Parser/ExpressionDqlParserXTest.php::testCompileWithLessThan | 2 DUPLICATE | MEDIUM | `ExpressionDqlParserFullTest::testCompileWithLogicalAnd` already compiles `<` (`getId() > 0 && getId() < 100`); the XTest assertion `assertStringContainsString('<', ...)` is also weak |
| Parser/ExpressionDqlParserXTest.php::testCompileWithTernaryComparison | 2 DUPLICATE | MEDIUM | `ExpressionDqlParserFullTest::testCompileWithLogicalAnd` (`&&` between comparisons) and `ExpressionDqlParserTest::testCompileWithLogicOperatorOnGetAttrNodes` |
| Parser/ExpressionDqlParserXTest.php::testCompileWithOrComparison | 2 DUPLICATE | MEDIUM | `ExpressionDqlParserFullTest::testCompileWithLogicalOr` (`||` between comparisons, OR + 2 params) |
| Parser/ExpressionDqlParserXTest.php::testGetFragmentsBeforeCompile | 2 DUPLICATE (partial) | LOW | `ExpressionDqlParserTest::testGetFragmentsReturnsStructuredArray` (same keys; XTest only differs in asserting the pre-compile empty state) |
| Parser/ExpressionDqlParserXTest.php::testGetParametersArrayEmptyBeforeCompile | 5 NEAR-EMPTY | LOW | Trivially true; `ExpressionDqlParserTest::testGetParametersArrayReturnsAssociativeArray` covers the meaningful non-empty path |
| Parser/ExpressionDqlParserFullTest.php::testCompileWithMatchesPlainText | 2 DUPLICATE | HIGH | `ExpressionDqlParserMatchesTest::testPlainMatchUsesEscapedContainsPattern` case `'all LIKE special characters'` — the exact same pattern `50%_off!` → `%50!%!_off!!%` and `ESCAPE '!'` |
| Parser/ExpressionDqlParserFullTest.php::testCompileWithMatchesRegex | 2 DUPLICATE | MEDIUM | `ExpressionDqlParserMatchesTest::testRegexLiteralIsNormalized` (same REGEXP + flag-normalization path, richer table) |
| Parser/ExpressionDqlParserFullTest.php::testCompileWithUnsupportedMatchesRegexFlagThrows | 2 DUPLICATE | MEDIUM | `ExpressionDqlParserMatchesTest::testUnsupportedRegexFlagsAreRejected` (same message + path) |
| Parser/ExpressionDqlParserCoverageTest.php::testValidateFragmentsRejectsUnknownAliasInWhere | 3 IMPLEMENTATION-DETAIL | MEDIUM | None; branch is unreachable via public input — private `where` state injected through `ReflectionProperty::setValue` (documents `core-parser-listener.md` §3.4 dead lines) |
| Parser/ExpressionDqlParserCoverageTest.php::testValidateFragmentsSkipsEmptyJoinPath | 3 IMPLEMENTATION-DETAIL + 5 NEAR-EMPTY | MEDIUM | None; same reflection-injected unreachable branch; assertion is only `assertTrue(true)` |
| Parser/ExpressionDqlParserCoverageTest.php::testCompileWithoutSetValuesAutoAddsEntitySignature | 2 DUPLICATE (partial) | LOW | `ExpressionDqlParserTest::testCompileWithExpressionThatHasNonEntityKeyAutoAddsSignature` (same signature auto-add branch) |
| Parser/ExpressionQueryBuilderAssemblerCoverageTest.php::testGetRootAliasesFailureFallsBackToEmptyAndAddsFrom | 1 COVERAGE-CHASING (defensive) | MEDIUM | None; `getRootAliases()` never throws on a real `QueryBuilder`; test exists only to hit the `catch` at `ExpressionQueryBuilderAssembler.php:79` |
| Parser/ExpressionQueryBuilderAssemblerCoverageTest.php::testGetAllAliasesFailureFallsBackToRootAliases | 1 COVERAGE-CHASING (defensive) | MEDIUM | None; same unreachable `catch` at `:111` |
| Parser/ExpressionQueryBuilderAssemblerCoverageTest.php::testLeftJoinFailureIsSilentlyIgnored | 1 COVERAGE-CHASING (locks in documented swallow bug) | MEDIUM | None; asserts the silently-ignored `leftJoin` failure documented in `core-parser-listener.md` §3.5 — keep only if the team wants to pin that buggy behavior |
| Parser/ExpressionQueryBuilderAssemblerFullTest.php::testBuildQueryBuilder / ::testBuildQueryBuilderWithOptions / ::testApplyToQueryBuilder / ::testApplyFragmentsWithJoinsAndParams / ::testApplyToQueryBuilderWithTargetAliasOption | 1 COVERAGE-CHASING (weak) | LOW | All five assert only `assertInstanceOf(QueryBuilder::class, ...)` on a mock QB, so they pass even if fragments are never applied; they would survive deletion via the strong assertions in `testApplyToQueryBuilderRenamesCollidingParameters` and `testApplyToQueryBuilderAddsFromWhenQueryBuilderHasNoRootAliases` |

## MERGE SUGGESTIONS

- **Service/BaseServiceUnitTest.php + Service/BaseServiceCoverageTest.php** → delete outright; every method is
  duplicated (see table). No merge needed.
- **Service/BaseServiceInfrastructureTraitTest.php + Service/BaseServiceInfrastructureTraitCoverageTest.php** → merge
  (same SUT, shared fake style); the Coverage file's `wrapInTransaction` and container-re-fetch branches fold into
  the base file.
- **Service/BaseServiceMutationTraitTest.php + Service/BaseServiceMutationTraitCoverageTest.php** → merge; the
  Coverage file's relation/exception branches fold into the base file (drop the one duplicate date-conversion test).
- **Service/BaseServiceReadListTraitTest.php + Service/BaseServiceReadListTraitCoverageTest.php** → merge.
- **Service/DefaultServiceLocatorTest.php + Service/DefaultServiceLocatorCoverageTest.php** → merge; drop the
  duplicate both-missing serializer test.
- **Service/ExpressionServiceTest.php + Service/ExpressionServiceCoverageTest.php** → merge (cache branches into
  the base buildFilter test).
- **Service/LegacyEvaluatorTest.php + Service/LegacyEvaluatorCoverageTest.php** → merge and delete the
  `language===null` test.
- **Parser/ExpressionDqlParserTest.php + ExpressionDqlParserXTest.php** → merge/delete XTest; its 2 unique cases
  (`<` operator, pre-compile fragments) are either duplicated elsewhere or trivial.
- **View/SingleCreateAndUpdateApiViewMixinTest.php + View/SingleCreateAndUpdateApiViewMixinCoverageTest.php** → merge
  the two branch tests (falsy-result warning, NotFound→404) into the base file.

## Verification steps

All commands from repo root with the project PHP runtime (`TEST_STRATEGY.md`):
`/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit`

1. **Baseline (before deleting anything):**
   ```
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit
   XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit \
     --coverage-filter src/Core/Service --coverage-filter src/Core/View --coverage-filter src/Core/Parser \
     --coverage-xml var/coverage-audit-before --no-progress
   ```
   Record the per-file line counts for `BaseService.php`, the three `Concern/*Trait.php` files,
   `DefaultServiceLocator.php`, `ExpressionService.php`, `LegacyEvaluator.php`, `QueryBuilderFactory.php`,
   `src/Core/View/*` and `src/Core/Parser/*`.

2. **Delete the HIGH-confidence items** (exact duplicates only), in two rounds so each is independently
   verifiable:
   - Round 1 (whole files): `tests/Core/Service/BaseServiceUnitTest.php`,
     `tests/Core/Service/BaseServiceCoverageTest.php`, `tests/Core/Parser/ExpressionDqlParserXTest.php`.
   - Round 2 (methods, requires editing): `DefaultServiceLocatorCoverageTest::testGetSerializerReturnsNullWhenBothServicesMissing`,
     `ExpressionDqlParserFullTest::{testCompileWithMatchesPlainText,testCompileWithMatchesRegex,testCompileWithUnsupportedMatchesRegexFlagThrows}`,
     `ExpressionDqlParserCoverageTest::{testValidateFragmentsRejectsUnknownAliasInWhere,testValidateFragmentsSkipsEmptyJoinPath}`,
     `ExpressionQueryBuilderAssemblerCoverageTest::{testGetRootAliasesFailureFallsBackToEmptyAndAddsFrom,testGetAllAliasesFailureFallsBackToRootAliases,testLeftJoinFailureIsSilentlyIgnored}`.

3. **Re-run after each round:**
   ```
   /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit
   XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit \
     --coverage-filter src/Core/Service --coverage-filter src/Core/View --coverage-filter src/Core/Parser \
     --coverage-xml var/coverage-audit-after --no-progress
   ```
   Assert: 0 failures; and `var/coverage-audit-after` shows **no decrease** in line coverage for any
   `src/Core/{Service,View,Parser}` file versus the before-run (for the HIGH deletions the delta must be zero —
   that is the correctness criterion for this audit).

4. **Static gates:** `composer phpstan` and `composer rector:types:check` must stay green (they scan the whole
   tree; deleting unused test helpers is safe).

5. **MEDIUM/LOW items** (defensive branches, partial duplicates): defer deletion until the src bugs they pin are
   fixed (e.g. `BaseServiceMutationTrait` immutable-DateTime bug, `LegacyEvaluator` null-language guard,
   `ExpressionQueryBuilderAssembler` swallowed joins) — or delete alongside those fixes since the branch tests only
   exist to cover dead/defensive code.

6. **Coverage-gate caveat:** `TEST_STRATEGY.md` requires ≥90% line coverage on `src/`. Because every HIGH deletion
   is covered by a named surviving test, the gate is unaffected; if the MEDIUM defensive-branch deletions are
   pursued, expect the affected `src` file's percentage to drop only on the previously-dead lines (acceptable only
   after the dead code is removed — see the campaign's follow-up 2).
