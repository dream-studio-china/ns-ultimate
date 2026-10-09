# Promotion module test audit (2026-08-09)

Scope: every test under `tests/Promotion/` (27 files, 360 tests). This audit identifies tests that add no observable-behavior protection and are candidates for later deletion. It is **read-only** — no `src/` or `tests/` file was modified.

Classification keys:
1. COVERAGE-CHASING — trivial / tautological assertions
2. DUPLICATE — same behavior covered elsewhere (naming test cited)
3. IMPLEMENTATION-DETAIL — asserts internals, not a public/persisted contract
4. REDUNDANT-REGRESSION — tests a fixed regression that a broader test already guards
5. NEAR-EMPTY

Context relied upon: `TEST_STRATEGY.md` (unit = deterministic domain decisions; one primary layer per behavior; no controller tests merely to raise coverage), `TEST_MATRIX.md`, `BUSINESS_INVARIANTS.md`, and `docs/issues/coverage-2026-08-09/` (the 2026-08-09 coverage campaign that added the `*CoverageTest` / `ParserBugReproTest` files).

## Summary — File | Tests | Verdict

| File | Tests | Verdict |
|---|---|---|
| Controller/App/PromotionControllerTest.php | 8 | MIXED — 4 candidates, 2 real behaviors keep |
| Controller/Manage/PromotionControllerTest.php | 4 | MIXED — reflection/whitelist; 2 candidates |
| Controller/Manage/PromotionTemplateControllerTest.php | 7 | KEEP — validate/dry-run action tests are the module's only HTTP-like controller coverage |
| Entity/PromotionTest.php | 14 | KEEP |
| Entity/PromotionTemplateTest.php | 14 | KEEP |
| Integration/PromotionPricingPipelineIntegrationTest.php | 8 | KEEP (critical — pipeline E2E, promotion invariant) |
| Repository/PromotionRepositoryTest.php | 6 | KEEP (Doctrine mapping) — 1 trivial candidate |
| Repository/PromotionTemplateRepositoryTest.php | 2 | KEEP |
| Service/Dsl/EvaluatorCoverageTest.php | 7 | KEEP (user.* path resolution is real, previously-missing behavior) — 2 low-value candidates |
| Service/Dsl/EvaluatorTest.php | 47 | KEEP (core evaluator behavior) |
| Service/Dsl/LexerTest.php | 26 | KEEP (core lexer behavior) |
| Service/Dsl/ParserBugReproTest.php | 11 | KEEP (bug regression guards incl. 3 skipped — per campaign, skipped bug tests are KEEP) |
| Service/Dsl/ParserCoverageTest.php | 27 | MIXED — 7 candidates (6 test lexer-unreachable branches, 1 exact duplicate) |
| Service/Dsl/ParserTest.php | 52 | KEEP (core parser behavior) |
| Service/PromotionCalculatorCoverageTest.php | 3 | KEEP (exclusive + best-price conflict modes not in main test) |
| Service/PromotionCalculatorTest.php | 9 | KEEP |
| Service/PromotionServiceCoverageTest.php | 2 | MIXED — 1 candidate |
| Service/PromotionServiceTest.php | 34 | MIXED — 3 candidates (1 exact duplicate) |
| Service/PromotionTemplateServiceCoverageTest.php | 4 | KEEP (type/phase mismatch rejection — real validation, absent from main test) |
| Service/PromotionTemplateServiceTest.php | 23 | MIXED — 1 candidate + merge opportunities |
| Strategy/DiscountStrategyTest.php | 10 | KEEP — 1 duplicate candidate |
| Strategy/FreeShippingStrategyTest.php | 3 | KEEP |
| Strategy/FullReductionStrategyTest.php | 9 | KEEP — 2 duplicate candidates |
| Strategy/GiftStrategyTest.php | 9 | KEEP — 2 duplicate candidates |
| Strategy/MemberDiscountStrategyTest.php | 8 | KEEP — 1 duplicate candidate |
| Strategy/NthItemDiscountStrategyTest.php | 3 | KEEP |
| Strategy/TieredStrategyTest.php | 10 | KEEP — 2 duplicate candidates (elsewhere table-driven) |

## KEEP

- **DSL behavioral tests** — `LexerTest`, `ParserTest`, `EvaluatorTest`, `ParserBugReproTest`. The lexer/parser/evaluator are the module's core rule engine; these tests pin token/grammar/evaluation semantics. `ParserBugReproTest` pins known defects (tiered-type unparseable, OR-block absorption, trailing-dot error, unused `desc`, multiple `when:`, missing `when:` simulate mismatch) and its 3 skipped tests document intended behavior — explicitly KEEP per campaign rules.
- **Pipeline integration** — `PromotionPricingPipelineIntegrationTest` (store scoping, global campaigns, best-price selection, multi-SKU stacking, expiry) is the evidence for the BUSINESS_INVARIANTS "Promotions" row.
- **Calculator conflict modes** — `PromotionCalculatorTest` (stackable loop, lock_item) + `PromotionCalculatorCoverageTest` (exclusive break, best-price scan skip) are complementary: the coverage file covers the two conflict modes the main test omits.
- **PromotionService filtering/priority/apply** — the 30+ `PromotionServiceTest` filtering, time-window, DSL-condition, and sorting tests plus the coverage-test priority fallback.
- **PromotionTemplateService parse/simulate/update** + coverage (type/phase mismatch validation).
- **Strategy core rules** — the primary case per strategy (Discount rate/cap/items, FullReduction value, Gift add, Member level gate, Nth item, Tiered best-match, FreeShipping meta).
- **Repository + Entity tests** — Doctrine mapping validation on persisted state (`findById` with template/config/time fields), entity constructor/accessor/touch contracts.
- **PromotionTemplateControllerTest validate/dry-run action tests** — the only controller tests asserting actual status codes (200/422/404).

## DELETE CANDIDATES — File::method | Reason | Confidence | Covered by

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| Service/Dsl/ParserCoverageTest::testParseLeadingEolAtTopLevel | 1 (exercises lexer-unreachable leading-EOL branch; campaign Bug 7 documents the branch is dead for any DSL string) | HIGH | none — dead-branch-only (documented `promotion-dsl.md` Bug 7) |
| Service/Dsl/ParserCoverageTest::testParseLogicBlockSkipsLeadingEol | 1 (consecutive-EOL skip in `and:`/`or:` block; unreachable from lexer output) | HIGH | none — dead-branch-only (Bug 7) |
| Service/Dsl/ParserCoverageTest::testParseNotBlockSkipsLeadingEols | 1 (consecutive-EOL skip before `not:` body; unreachable) | HIGH | none — dead-branch-only (Bug 7) |
| Service/Dsl/ParserCoverageTest::testParseDoSkipsLeadingEol | 1 (consecutive-EOL skip in `do:` block; unreachable) | HIGH | none — dead-branch-only (Bug 7) |
| Service/Dsl/ParserCoverageTest::testParseTieredSkipsLeadingEol | 1 (consecutive-EOL skip in `tiered:` block; unreachable) | HIGH | none — dead-branch-only (Bug 7) |
| Service/Dsl/ParserCoverageTest::testParseFieldsSkipsLeadingEol | 1 (consecutive-EOL skip in `fields:` block; unreachable) | HIGH | none — dead-branch-only (Bug 7) |
| Service/Dsl/ParserCoverageTest::testLexerRoundTripItemsNumericRate | 2 | HIGH | ParserCoverageTest::testParseDiscountItemsNumericRate — same grammar production (`discount items N%`), same assertions (`rate`, `isPercent`); one feeds hand-built tokens, the other the real lexer |
| Service/PromotionServiceTest::testApplyWithNullTemplateDoesNothing | 2 | HIGH | PromotionServiceTest::testApplyWithNoTemplate — identical branch (`apply()` early-return on null template), identical assertion (totalAmount 50000 unchanged); helper `createPromotion('No Template')` already leaves template null |
| Service/PromotionServiceCoverageTest::testNumericStringPrioritySortsStable | 2 | MODERATE | PromotionServiceTest::testGetAvailableSortsByPriority (and ::testGetAvailableSortsByConfigPriorityRef) — same descending sort behavior; only delta is string-typed vs int-typed numeric values, both hitting the same `is_numeric` → `(float)` cast |
| Service/PromotionServiceTest::testApplyWithNoDoNode | 2 | MODERATE | PromotionServiceTest::testApplyWithEmptyDoChildren — both assert "no `do:` actions ⇒ totalAmount unchanged"; AST shape difference (absent vs empty `do`) is not observable |
| Service/PromotionServiceTest::testGetAvailableWithConfigRefPriority | 2 | MODERATE | PromotionServiceTest::testGetAvailableSortsByConfigPriorityRef — same config-priority branch; weaker (asserts count only, no order) and fully subsumed |
| Strategy/DiscountStrategyTest::testApplyWithNonNumericRateDefaultsToZeroRate | 2 | MODERATE | DiscountStrategyTest::testApplyWithMissingConfigRate — both assert the "unresolvable rate ⇒ fallback 0 ⇒ 100% discount ⇒ totalAmount 0" outcome; differ only in which `resolveValue()` fallback line is hit |
| Strategy/FullReductionStrategyTest::testApplyWithoutConfig | 2 | MODERATE | FullReductionStrategyTest::testApplyReducesTotalAmount — identical numeric-value code path (`value`→cents→subtract); the `['threshold'=>200]` arg in the primary test is unused by the strategy |
| Strategy/FullReductionStrategyTest::testApplyWithNonNumericString | 2 | MODERATE | FullReductionStrategyTest::testApplyWithMissingConfigRef — both assert "unresolvable value ⇒ fallback 0 ⇒ no change (50000)" |
| Strategy/GiftStrategyTest::testApplyWithNumericSpec | 2 | MODERATE | GiftStrategyTest::testApplyAddsGiftItem — same numeric-spec branch; the primary test already asserts the full item shape |
| Strategy/GiftStrategyTest::testApplyWithConfigSpecAndCount | 2 | MODERATE | GiftStrategyTest::testApplyWithConfigSpecRef (spec resolution) + testApplyAddsGiftItem/testApplyDefaultCountWhenNotSpecified (count behavior) |
| Strategy/MemberDiscountStrategyTest::testApplyWithDiamondLevel | 2 | MODERATE | MemberDiscountStrategyTest::testApplyWithMatchingLevel — both assert "user rank ≥ min ⇒ discount"; only level constant / rate / amounts differ |
| Strategy/TieredStrategyTest::testApplyWithTiersBelowSubtotal | 2 | MODERATE | TieredStrategyTest::testApplyPicksHighestMatchingTier — both drive the "all tiers match ⇒ pick highest `less`" loop |
| Strategy/TieredStrategyTest::testApplyPicksSecondTierWhenFirstDoesNotMatch | 2 | MODERATE | TieredStrategyTest::testApplySkipsTierWhenFromExceedsSubtotal — both drive the "some tiers match ⇒ best matching applied" loop |
| Controller/App/PromotionControllerTest::testCommonFilterOnlyContainsEnabled | 2 | MODERATE | App/PromotionControllerTest::testCommonFilterReturnsEnabledTrue — same no-`entityManager` branch, same return value; only `assertCount(1)` vs key/value assertion differs |
| Controller/App/PromotionControllerTest::testControllerUsesServiceInterface | 3 | MODERATE | App/PromotionControllerTest::testGetServiceReturnsInjectedService — same contract via public `getService()`; reflection on the private `service` property adds nothing |
| Controller/App/PromotionControllerTest::testControllerHasNoWriteMixins | 3 | MODERATE | none directly (structural guard); a read-only-App contract is better proven by an HTTP deny test per TEST_STRATEGY |
| Controller/Manage/PromotionControllerTest::testAcceptedPropertiesAreDefined | 3 | MODERATE | Manage/PromotionControllerTest::testRequiredCreatePropertiesHaveCorrectValues + ::testAcceptedPropertiesIncludeAllFields — `hasProperty` checks are subsumed by the value-assertion tests in the same file |
| Service/PromotionTemplateServiceTest::testUpdateWithDslDataOnlyParsesAndSetsCache | 2 | MODERATE | PromotionTemplateServiceTest::testUpdateSetsAstCacheWhenDslIsValid (+ TemplateServiceCoverageTest::testUpdateAcceptsDslTypeMatchWhenObjectTypeUsed) — identical "valid dsl ⇒ parse ⇒ set astCache" branch; only the DSL string differs |
| Service/Dsl/EvaluatorCoverageTest::testNullOperandIsResolvedToNull | 1 | LOW | none — `resolveOperand(null)` cannot be produced by the parser/`arrayToAstNode`; only reachable via a hand-built AST |
| Service/Dsl/EvaluatorCoverageTest::testNullOperandWithUnknownOperatorReturnsFalse | 1 | LOW | none — default-false operator branch is unreachable from parser output (parser only emits validated operators) |
| Controller/App/PromotionControllerTest::testControllerIsInstantiable | 1 | LOW | the other tests in the file already construct the controller |
| Controller/Manage/PromotionControllerTest::testControllerIsInstantiable | 1 | LOW | the other tests in the file already construct the controller |
| Controller/Manage/PromotionTemplateControllerTest::testRequiredAndAcceptedPropertiesAreDefined | 3 | LOW | structural/reflection assertion; whitelist enforcement is best tested via HTTP create/update (see merge suggestion) |
| Repository/PromotionRepositoryTest::testRepositoryResolvesCorrectly | 1 | LOW | trivial container-wiring `assertInstanceOf` |
| Strategy/*::testSupportedType (7 files: Discount, FreeShipping, FullReduction, Gift, Member, NthItem, Tiered) | 1 | LOW | tautological constant==literal; registry-key drift is caught by the pipeline integration tests |

## MERGE SUGGESTIONS

1. **TieredStrategyTest** — convert the six overlapping tier cases into one table-driven test (all-match / none-match / partial-match / from-vs-less precedence / clamp / defaults), per TEST_STRATEGY's "prefer table-driven tests for a rule with many equivalent inputs". This would absorb candidates rows 18–19.
2. **Strategy fallback pairs** — `DiscountStrategyTest::testApplyWithMissingConfigRate` + `testApplyWithNonNumericRateDefaultsToZeroRate`, and `FullReductionStrategyTest::testApplyWithMissingConfigRef` + `testApplyWithNonNumericString`, are table rows of the same `resolveValue()` fallback-to-0 rule; table-drive them.
3. **PromotionServiceTest time-window quartet** — `testGetAvailableFiltersByStartTime`, `testGetAvailableFiltersByEndTime`, `testGetAvailablePromotionWithinTimeRange`, `testGetAvailableWithoutStartAndEndTime` are four rows of one filter rule; table-drive.
4. **PromotionServiceTest apply no-action trio** — `testApplyWithNoTemplate`, `testApplyWithNoAstCache`, `testApplyWithEmptyDoChildren`/`testApplyWithNoDoNode` are three early-return branches; merge the null-template pair (candidate) and the no-action pair into one parameterised test.
5. **PromotionTemplateServiceTest simulate envelope trio** — `testSimulateReturnsStructure`, `testSimulatePassesSampleContext`, `testSimulateWithEmptyContext` assert one no-`when` simulate path; merge into a single shape/passthrough test.
6. **PromotionTemplateServiceTest parseDsl per-type smoke tests** — `testParseDslFullReduction`, `testParseDslDiscountWithPercent`, `testParseDslTieredType`, `testParseDslNthDiscountType`, `testParseDslMemberDiscountType` are a table of wrapper-level round-trips (the per-type grammar is already covered by ParserTest); table-drive.
7. **Manage controller whitelist tests** — `Manage/PromotionControllerTest` and `Manage/PromotionTemplateControllerTest`'s reflection-based property assertions duplicate structurally. Replace with HTTP create/update integration tests that assert whitelist enforcement (field accepted / rejected), consistent with TEST_STRATEGY's API-layer rule and with the validate/dry-run action tests that already exist.
8. **Best-price coverage** — `PromotionCalculatorCoverageTest::testBestPricePromotionSkippedDuringStandardScan` / `::testBestPriceCandidateScanSkipsNonBestPricePromotions` overlap the pipeline-level best-price scenarios in `PromotionPricingPipelineIntegrationTest`. Keep both today (unit isolation vs E2E proof), but if the integration suite is judged sufficient, the two unit tests can be dropped together.

## Verification steps

1. Confirm the module suite size and that it is green before any change:
   `/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Promotion` (expect 360 tests).
2. For the **HIGH-confidence** candidates only (8 tests: 6 ParserCoverageTest dead-branch tests, `ParserCoverageTest::testLexerRoundTripItemsNumericRate`, `PromotionServiceTest::testApplyWithNullTemplateDoesNothing`), delete them, then re-run `tests/Promotion` plus the full suite — must stay green with zero failures/skips changes.
3. Cross-check each ParserCoverageTest removal against `docs/issues/coverage-2026-08-09/promotion-dsl.md` Bug 7 (EOL-skip branches documented as dead for lexer output) and Bug 4/5/6 (other ParserCoverageTest cases) to confirm the retained error-path tests still pin the reachable grammar errors.
4. Re-run the quality gates from TEST_STRATEGY after any deletion:
   `composer phpstan` and `composer rector:types:check`.
5. Re-check the 90% line-coverage gate: the removed HIGH-confidence tests only touched lines the campaign already documents as unreachable defensive code, so coverage should not regress below the gate; if it does, keep the tests rather than lowering the threshold.
6. Record any accepted deletions in `TEST_MATRIX.md` / this audit as the module's retained evidence set (per the matrix-maintenance rule).
