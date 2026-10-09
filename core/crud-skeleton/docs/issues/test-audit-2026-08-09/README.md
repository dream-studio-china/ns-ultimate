# Test Suite Audit 2026-08-09 — Master Summary

> Date: 2026-08-09
> Campaign: **14 parallel sub-agents** audited the entire PHPUnit suite (300 test files) against the quality contract in `docs/testing/crud-skeleton-production/` (TEST_STRATEGY.md, TEST_MATRIX.md, BUSINESS_INVARIANTS.md) to find **unnecessary / redundant tests** that are candidates for later deletion.
> Constraint honored: **no `src/` or `tests/` file was modified** — only reports under `docs/issues/test-audit-2026-08-09/` were created.

## Baseline — full suite run (serial, this session)

| Metric | Value |
|---|---|
| PHPUnit | 12.5.30 (PHP 8.5.1) |
| Tests | **2686** |
| Assertions | **9264** |
| Failures / Errors | **0** |
| Skipped | **38** (each documents a known `src/` bug — all KEEP) |
| PHPUnit Notices | **186** (pre-existing mock/no-expectation noise) |
| Wall time | **51.3 s** |
| Memory | 143 MB |

Per-module distribution: Trade 391 tests / 16.8 s · Promotion 360 / 0.9 s · Core 638 / 0.5 s · Identity 281 / 4.7 s · Store 153 / 2.8 s · Integration 158 / **13.1 s** · Common 129 / 3.6 s · Wallet 173 / 3.5 s · Inventory 109 / 2.4 s · Wechat 78 / 0.1 s · Payment 76 / 2.0 s · Storage 20 / 0.0 s · (root) 120 / 0.8 s.

### Timing hotspots

- **145 tests ≥ 100 ms consume 35.5 s (69.3 %)** of the total 51.3 s.
- Slowest classes: `TradeApiIntegrationTest` 6.4 s, `OrderWorkflowApiTest` 5.5 s, `CoreOpenApiEnricherApiTest` 3.1 s, `PaymentTradeIntegrationTest` 2.3 s, `WalletApiRegressionTest` 2.0 s, `UserApiIntegrationTest` 1.9 s.
- Slowest individual test: `OrderWorkflowApiTest::testTransitionsEndpointListsEnabledTransitionsPerState` 1.22 s; `RedisOtpStorageTest::testTtlExpiredKeyBehavesLikeMissing` 1.10 s (probably real Redis wait, worth confirming).

Most of the integration cost is kernel+DB bootstrap per test class. Consolidating the duplicate full-flow clusters (see Trade / Integration reports) is the highest-leverage runtime saving.

## Consolidated deletion-candidate tally

| Report | Scope | DELETE rows | HIGH confidence |
|---|---|---|---|
| `trade-controllers-integration.md` | Trade controllers/integration/handlers | 46 | 29 |
| `wallet.md` | Wallet | 49 | 22 |
| `identity.md` | Identity | 58 | 21 |
| `trade-core.md` | Trade entity/service/workflow/repo | 47 | 11 |
| `core-rest-utils.md` | Core Utils/Listener/Controller/Serializer | 39 | 28 |
| `core-service-view-parser.md` | Core Service/View/Parser | 32 | 12 |
| `promotion.md` | Promotion (incl. DSL) | 32 | 9 |
| `payment.md` | Payment | 29 | 16 |
| `common.md` | Common (CMS/media) | 23 | 11 |
| `wechat.md` | Wechat | 19 | 11 |
| `integration.md` | Cross-module integration | 13 | 10 |
| `inventory.md` | Inventory | 13 | 4 |
| `storage.md` | Storage | 6 | 4 |
| `store.md` | Store | 6 | 2 |
| **Total** | | **412** | **190** |

Reason-code distribution (see each report): 1 = coverage-chasing, 2 = duplicate, 3 = implementation-detail, 4 = redundant-regression, 5 = near-empty. Roughly half are DUPLICATE (same behavior asserted again at a weaker layer); most of the rest are COVERAGE-CHASING tests added by the 2026-08-09 coverage campaign that assert trivial/tautological outcomes.

## Highest-value, lowest-risk deletion clusters (start here)

These are exact-duplicate clusters where the surviving test asserts the same behavior with **stronger** assertions, and the flagged tests are expensive (kernel bootstrap):

1. **`tests/Trade/Integration/TradeApiIntegrationTest.php` (26 tests, ~6.4 s file)** — full-flow/cancel/pay/fulfill/refund/transitions/todo tests duplicated 1:1 by `OrderWorkflowApiTest` (exact status codes + messages + unchanged-state assertions). See trade-controllers-integration.md.
2. **`tests/Trade/Integration/TradeRepositoryIntegrationTest.php` (whole file, 4 tests)** — weaker `assertNotEmpty()` versions of `TradeRepoFullTest`.
3. **`tests/Integration/CommonModulesIntegrationTest.php`** — duplicate of `CommonModulesApiRegressionTest` (see integration.md).
4. **`tests/Integration/CoreListenerHttpIntegrationTest.php`** — near-empty / duplicate of envelope tests (see integration.md).
5. **`tests/Payment/Controller/*` App + Manage + Webhook unit tests (~21 tests)** — the HTTP layer owns these routes; `PaymentApiIntegrationTest`/`PaymentTradeIntegrationTest` already assert them (see payment.md).
6. **`tests/Wallet/Service/TransferServiceTest` + `WalletGatewayTest` unit layers** — duplicated by persisted-state integration counterparts (see wallet.md).
7. **`tests/Identity/Controller/OtpControllerTest` + `OtpControllerCoverageTest` (13 runnable tests)** — OtpController is a shadowed copy-paste of AuthController's OTP methods (verified via `debug:router`), see identity.md.
8. **Entity accessor/coverage residue across modules** — trivial setter/getter/fluent/constant round-trips with no logic (~25 in Trade entities, plus Wallet/Wechat/Common/Inventory/Identity equivalents). Delete only where a behavioral test still exercises the line (verify per-module reports).

## Cross-module duplicate clusters (found by multiple agents)

- **`UserApiIntegrationTest`** (Identity dir) re-tests Wallet (transfer negative/same-wallet/missing-fields) and Trade endpoints that have dedicated module tests — move-or-delete candidates (identity.md).
- **`OrderWorkflowApiTest`** is the surviving owner of the Trade HTTP workflow contract; `TradeApiIntegrationTest`, `TradePaymentIntegrationTest::testAppOrderTransitionFailures`, `Manage/OrderControllerTest` duplicates all map onto it.
- **`PaymentAdjustmentIntegrationTest` (4 of 5 tests)** ≈ `PaymentAdjustmentMultiGatewayIntegrationTest` (payment.md).
- **`OpenApiIntegrationTest`** ≈ `CoreOpenApiEnricherApiTest`; **`CoreDynamicQueryApiTest`** has 5 tests duplicated by `CoreExceptionInterceptorApiTest`/others (integration.md).
- **`QiniuStorageTest`** missing-SDK/configuration tests ≈ `StorageServiceTest` same-throw tests (storage.md).
- **`PublishOutboxCommandTest` (mock-only, Trade)** ≈ real-DB `TradeOutboxMessageRepositoryTest` (trade-core.md).
- **`OrderWorkflowStateMachineTest` Section-5 timestamp tests** = verbatim duplicates of `OrderWorkflowListenerTest` (trade-core.md).

## What must NEVER be deleted

- All **38 skipped tests** — each documents a real `src/` bug (see coverage-2026-08-09 README); deleting them silently re-enables regressions.
- **Money-invariant tests**: integer cents, idempotent deposit/transfer, optimistic locking, rollback, reconciliation (Wallet/Payment).
- **Auth/security tests**: token rotation + reuse detection, JWt failure handling, owner/store/admin boundaries (Identity/Core/Store).
- **Outbox/inbox, ordering, tombstone, and concurrency tests** (Store/Inventory/Trade) — the async contracts.
- **DSL lexer/parser/evaluator behavioral tests and the pricing pipeline integration** (Promotion).
- **Unique-branch coverage** where the flagged test is the *only* coverer of a src line (flagged in each report as "sole coverage" — verify against the coverage gate before deleting).

## Coverage-gate caution

CI enforces **90 % line coverage on `src/`** (currently ~99 %). Many coverage-chasing tests are the only exercisers of **dead/defensive/unreachable branches** (`BaseService` guards, `ExpressionDqlParser` unreachable EOL branches, `QiniuStorage` config throws, trivial entity constructors). Deleting them will not lower coverage **only if** the corresponding `src/` dead code is also removed — which is a `src/` change and therefore out of scope for this audit. Each report's Verification section states where this caveat applies (notably core-rest-utils, wechat, inventory, common).

## Suggested execution order for later deletion work

1. **Round 1 (zero coverage risk):** delete the exact-duplicate HTTP/integration clusters (TradeApiIntegrationTest duplicates, TradeRepositoryIntegrationTest, CommonModulesIntegrationTest, CoreListenerHttpIntegrationTest, Payment controller unit tests, duplicate regression twins). Re-run full suite + `--coverage-filter src/Trade` etc. → zero delta, suite green, ~10–15 s faster.
2. **Round 2:** delete trivial entity accessor/fluency/constant tests where a behavioral test still covers the line (per-module HIGH rows). Re-run per-module + full suite.
3. **Round 3 (requires a `src/` PR):** remove the documented dead branches in `src/` first, then delete the coverage-chasing tests that only fed those branches; keep 90 % gate green.
4. **Round 4:** apply the MERGE suggestions (table-driven refactors) to cut bootstrap count — biggest runtime win is consolidating kernel-bootstrapping integration classes.

## How this audit ran

- Baseline: full serial PHPUnit run with `--log-junit` → 2686 tests / 9264 assertions / 51.3 s; per-test and per-class timing extracted from the JUnit XML.
- 14 sub-agents in parallel, one per module cluster, each instructed to (a) read the `docs/testing/` contract, (b) read every test file in scope, (c) classify every test as KEEP / DELETE-candidate (reason 1–5) / MERGE, (d) cite the exact covering test for every HIGH-confidence duplicate, (e) write exactly one report, and (f) **never modify `src/` or `tests/`**.
- All 14 reports written under `docs/issues/test-audit-2026-08-09/`; the suite was NOT re-run after (nothing changed).

## Report index

| Report | Scope | Key content |
|---|---|---|
| `core-service-view-parser.md` | Core Service/View/Parser | 3 whole files exact-duplicate (`BaseServiceUnitTest`, `BaseServiceCoverageTest`, `ExpressionDqlParserXTest`), BaseService trait test pairs, mixin tests KEEP |
| `core-rest-utils.md` | Core Utils/Listener/Controller/Serializer | 15 exact-duplicate deletes (UUIDTest, InflectTest, RestControllerCoverageTest dup rows…), SerializerContextFactory near-empty, 13 merges |
| `common.md` | Common CMS/media | 25 candidates (11 HIGH): entity coverage tests, MediaUploadTest duplicate, SettingApiIntegrationTest subset |
| `identity.md` | Identity | ~50 methods: OtpController pair (13), TokenManager empty-string test, Profile/Manage duplicates, UserApiIntegrationTest cross-module residue |
| `trade-core.md` | Trade entity/service/workflow/repo/command/listener | entity accessor residue (~25), TradeOutboxMessageTest 4/5 trivial, state-machine timestamp dup, PublishOutboxCommandTest mock dup |
| `trade-controllers-integration.md` | Trade controllers/integration/handlers | 41+ candidates: TradeApiIntegrationTest 26 duplicates, TradeRepositoryIntegrationTest whole file, tamper-regression twin |
| `wallet.md` | Wallet | ~47 candidates: coverage-chasing guard tests, unit-vs-integration dups (TransferService, WalletGateway), controller envelope dup, 9 merges |
| `promotion.md` | Promotion/DSL | 8 HIGH (dead-branch ParserCoverageTest rows), ~24 MEDIUM, pipeline/strategy/DSL KEEP |
| `payment.md` | Payment | ~30 candidates: controller unit tests dup of HTTP layer, MockGateway/Registry dup, adjustment integration dups |
| `wechat.md` | Wechat | 18 candidates (11 HIGH): LoginController empty-body dup, gateway dup via integration, trivial entity/constants |
| `store.md` | Store | 5 candidates (2 HIGH): id-assignment tautologies; entity/handler pairs verified NOT duplicates |
| `inventory.md` | Inventory | 10 candidates: 4 HIGH (null-id tautology, reflection hooks, container-instantiation tests), merge 6 groups |
| `integration.md` | Cross-module | 11 tests / 4 files: CoreListenerHttpIntegrationTest, CommonModulesIntegrationTest, OpenApiIntegrationTest merge, TradePaymentIntegrationTest dups; ≈2.5–4 s savings |
| `storage.md` | Storage | 4 candidates (3 HIGH dup of StorageServiceTest throws; TOCTOU stream-wrapper overkill), 1 merge |

## Follow-ups suggested (outside this audit's read-only scope)

1. Execute Rounds 1–4 above in separate PRs, keeping the 90 % coverage gate green.
2. Confirm the `RedisOtpStorageTest` 1.1 s test: if it performs a real Redis round-trip with TTL sleep, replace with an injected clock to cut suite time.
3. Track the 186 PHPUnit Notices: enforce `failOnRisky`/mock-without-expectations hygiene so new coverage-chasing tests can't silently add noise.
4. After deletion rounds, re-run this audit to keep the suite lean as modules grow.
