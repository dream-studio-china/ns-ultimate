# Integration tests audit (2026-08-09)

> Scope: `tests/Integration/` (root level only, 20 files, 158 tests ≈ 13s of the 51s suite).
> READ-ONLY audit — no `src/` or `tests/` file was modified. Only this report was created.
> Classification: A=KEEP, B=DELETE candidate (1 coverage-chasing, 2 duplicate, 3 implementation-detail,
> 4 redundant-regression, 5 near-empty), C=merge suggestion.
> Cross-references cite the covering test (file::method). Skipped tests that document known bugs are KEEP.

## Summary

| File | Tests | Verdict |
|---|---|---|
| ApiRegressionTest | 3 | A — KEEP (only manage-contents CRUD) |
| AppApiRegressionTest | 25 | A — KEEP (App boundary; distinct filters/ownership) |
| BaseServiceIntegrationTest | 2 | C — MERGE into CommonModulesIntegrationTest |
| CommonModulesApiRegressionTest | 17 | A — KEEP (canonical manage-CRUD layer) |
| CommonModulesIntegrationTest | 8 | B-2 (MEDIUM) / C — merge into BaseService layer |
| ContentRepositoryIntegrationTest | 2 | A — KEEP (newest-first ordering is unique) |
| CoreAccessLogAndPaginationApiTest | 7 | A — KEEP (access.log + pagination edge cases unique) |
| CoreDynamicQueryApiTest | 23 | B-2/B-4 (partial: @showDQL + BUG-1 x4) |
| CoreExceptionInterceptorApiTest | 7 | A — KEEP (canonical envelope tests) |
| CoreListenerHttpIntegrationTest | 2 | B-2/B-5 — DELETE whole file |
| CoreLocaleDetectionApiTest | 9 | A — KEEP (locale mapping unique) |
| CoreOpenApiEnricherApiTest | 9 | A — KEEP (canonical OpenAPI tags) |
| CoreServiceIntegrationTest | 5 | A — KEEP (ExpressionService/DQL ≠ BaseService) |
| CoreSystemEndpointsApiTest | 10 | A — KEEP (HTTP layer of system endpoints) |
| OpenApiIntegrationTest | 1 | B-2 (MEDIUM-HIGH) / C — merge into CoreOpenApiEnricherApiTest |
| PaymentTradeIntegrationTest | 14 | A — KEEP (survivor of payment cluster) |
| StoreTradeFlowTest | 8 | A — KEEP (E2E, inventory disabled) |
| StoreTradeFlowTestCase | 0 | infra — KEEP |
| StoreTradeInventoryEnabledFlowTest | 3 | A — KEEP (NOT a duplicate of StoreTradeFlowTest) |
| TokenRevocationIntegrationTest | 3 | B-2/B-5 (partial: 2 of 3) |

Skipped (documented bugs) counted as KEEP throughout: CoreDynamicQueryApiTest (5 skipped: BUG-1/2/3/4),
CoreOpenApiEnricherApiTest::testStoreOrderEndpointsShouldBeTaggedStore (BUG-7),
CoreSystemEndpointsApiTest::testMissingEntityShouldReturn404Json (BUG-5),
StoreTradeInventoryEnabledFlowTest::testReleaseBeforeReserveIsHandledGracefully (release-before-reserve TODO).

## KEEP

- **ApiRegressionTest** (3): the only full manage-CRUD flow for `Content`; the "Title is required"
  validation assertion (line 82) and manage-contents 404s are not covered by the other API files.
- **AppApiRegressionTest** (25): App-boundary contract (only-enabled categories, only-published pages,
  only-approved comments, comment author auto-recorded / status ignored, cross-user 404). The App comment
  cluster overlaps one smoke test in `tests/Common/Integration/CommentApiExtraTest.php::testCommentCreateAndList`,
  but the AppApiRegressionTest version is a superset — keep it.
- **CommonModulesApiRegressionTest** (17): canonical manage-CRUD + validation + batch-create for the six
  Common modules. `testMissingEntityAcrossAllModules` (line 274) is the only manage-GET/DELETE-404 coverage
  for tags/media/pages/comments/settings — keep.
- **ContentRepositoryIntegrationTest** (2): `testFindLatestReturnsNewestFirstAndRespectsLimit` is the ONLY
  test asserting newest-first *ordering* (`CommonRepoFullTest::testContentFindLatest` only checks count).
- **CoreAccessLogAndPaginationApiTest** (7): access.log write/GET-suppression/auth-body hiding are unique;
  the pagination edge cases (page beyond range, negative page, limit=0, paginator field completeness) are
  unique at the HTTP layer (`tests/Core/Controller/RestControllerPaginationIntegrationTest` is a single
  QB-level case, `RestControllerTest::testPaginationUsesBuiltInPaginationOnGet` is an array-level case).
- **CoreExceptionInterceptorApiTest** (7): canonical envelope-shape tests (500/404/403/warning/success).
- **CoreLocaleDetectionApiTest** (9): the only end-to-end locale-mapping tests (zh/zh_TW/ja/en, q-values,
  fallback). Overlaps `CoreExceptionInterceptorApiTest::testWarning404EnvelopeShape` only on the English
  default probe message; both facets are asserted, keep both.
- **CoreOpenApiEnricherApiTest** (9): canonical OpenAPI tag-enrichment contract + BUG-7 regression pair.
- **CoreServiceIntegrationTest** (5): ExpressionService/DQL-function registration against real Doctrine
  metadata — NOT a duplicate of `BaseServiceIntegrationTest` (different concern).
- **CoreSystemEndpointsApiTest** (10): HTTP layer of `/system/*`; BUG-5 regression pair.
- **PaymentTradeIntegrationTest** (14): the comprehensive payment↔trade↔wallet E2E (webhook verification,
  wallet deduction full/partial/insufficient, idempotency, bad-path guards, BUG-001/BUG-002 reproductions).
- **StoreTradeFlowTest** (8) + **StoreTradeFlowTestCase**: E2E Store↔Trade with inventory disabled
  (accept/reject/unavailable/unknown-store/dedup/tombstones). Note: `tests/Store/Integration/StoreScopedOrderFlowTest`
  and `tests/Store/Integration/TradeOrderCreatedHandlerTest` re-cover the acceptance/dedup/tombstone paths at
  handler level — if ever consolidated, the E2E `StoreTradeFlowTest` is the survivor.
- **StoreTradeInventoryEnabledFlowTest** (3): the only INVENTORY_ENABLED=1 E2E — NOT a duplicate of
  `StoreTradeFlowTest` (complementary env), KEEP.
- **CoreDynamicQueryApiTest** (partial): the filter/order/select/groupBy/display/transform/limit-validation
  tests and all 5 skipped BUG regressions.

## DELETE CANDIDATES

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| CoreListenerHttpIntegrationTest::testExceptionInterceptorReturnsJsonOnApiErrorInTestEnv | 2 (DUPLICATE) — same POST-bad-JSON → JSON-envelope behavior, asserted only loosely (`in_array([200,400])`) | HIGH | CoreExceptionInterceptorApiTest::testInvalidJsonReturnsWarningEnvelope (asserts 400 + `Invalid JSON` + warning shape) |
| CoreListenerHttpIntegrationTest::testCoreListenersDoNotBreakNormalApiFlow | 5 (NEAR-EMPTY) — plain "GET list returns 200 JSON" smoke | HIGH | CoreExceptionInterceptorApiTest::testSuccessListEnvelopeShape (GET list → 200 + envelope) |
| CoreDynamicQueryApiTest::testShowDqlRejectedOutsideDev | 2 (DUPLICATE) — identical request `GET /api/v1/manage/categories?@showDQL=1`, same 403 gate | HIGH | CoreExceptionInterceptorApiTest::testAccessDeniedOnShowDqlReturnsJsonEnvelope (same request + 403; keeps the envelope facet; fold the `@showDQL is only available in the dev environment` message assertion into it before deleting) |
| CoreDynamicQueryApiTest::testSortRestrictedForEveryoneCurrently | 4 (REDUNDANT-REGRESSION) — same BUG-1 (BaseService::$user null → admin-gated params 403), kept 4× for @dql/@sort/@hints/@filter-fallback | MEDIUM-HIGH | Keep one representative (`testDqlRestrictedForEveryoneCurrently`) + the 2 skipped BUG-1 tests |
| CoreDynamicQueryApiTest::testHintsRestrictedForEveryoneCurrently | 4 (REDUNDANT-REGRESSION) — same BUG-1 as above | MEDIUM-HIGH | same as above |
| CoreDynamicQueryApiTest::testInvalidFilterFallsToAdminGateCurrently | 4 (REDUNDANT-REGRESSION) — same BUG-1 as above | MEDIUM-HIGH | same as above |
| OpenApiIntegrationTest::testSwaggerUiAndJsonEndpointsAreAvailable | 2 (DUPLICATE, partial) — openapi 3.1.0, `/api/v1/manage/contents` path, Wechat/Payment tags all re-asserted in CoreOpenApiEnricherApiTest | MEDIUM-HIGH | CoreOpenApiEnricherApiTest::testSpecIsOpenApi31 + testKeyEndpointsTaggedByModule (keep its unique Media-schema + `/api/doc` UI assertions by merging, see below) |
| TradePaymentIntegrationTest::testOrderPaymentWithWalletDeductionAndMockRemainder | 2 (DUPLICATE) — same partial-wallet-deduction + mock-remainder pay (order paid, user/system balances moved) | HIGH | PaymentTradeIntegrationTest::testWalletDeductionPartialReducesMockGatewayAmount (same scenario, also asserts gateway amount + webhook confirm) |
| TradePaymentIntegrationTest::testOrderPaymentAndRefundThroughInvoiceEvents | 2 (DUPLICATE) — same pay→fulfill→complete→refund journey ending `REFUNDED` + `paymentStatus=refunded` | MEDIUM | PaymentTradeIntegrationTest::testRefundFlowRefundsOrderAndReturnsMoney (same journey + money-movement assertions; only the payment method differs: mock autoPaid vs wallet) |
| TokenRevocationIntegrationTest::testRefreshTokenIsRevokedAfterLogout | 2 (DUPLICATE) — logout then refresh with revoked refresh token → 401 | HIGH | tests/Identity/Integration/AuthIntegrationTest::testFullAuthFlow (steps 5–6: logout 204 → refresh 401) |
| TokenRevocationIntegrationTest::testAccessTokenStillWorksBeforeLogout | 5 (NEAR-EMPTY) — authenticated GET returns 200; already asserted in the same file's `testAccessTokenIsRevokedAfterLogout` (pre-logout 200, lines 40–41) | HIGH | TokenRevocationIntegrationTest::testAccessTokenIsRevokedAfterLogout (in-file) |
| CommonModulesIntegrationTest (whole file, 8 tests) | 2 (DUPLICATE) — same six-module create→read→update→list→remove round-trips asserted at HTTP manage layer; `testUpdateForAllModuleTypes` is pure coverage-chasing (`assertNotNull` only) | MEDIUM | CommonModulesApiRegressionTest::test{Category,Tag,Media,Page,Comment,Setting}CrudRegression + AppApiRegressionTest::testApp{Category,Tag,Media,Page,Comment,Setting}*; `testCategoryHierarchy` → CommonModulesApiRegressionTest::testCategoryHierarchyApi |

## MERGE SUGGESTIONS (with estimated time savings)

1. **`BaseServiceIntegrationTest` (2 tests) → merge into `CommonModulesIntegrationTest`** (8 tests), keep ONE
   service-layer file. Both instantiate a `BaseService` subclass and boot the kernel to exercise the identical
   `update/get/list/remove` path; only the entity differs (Content vs six Common entities). Survivor:
   `CommonModulesIntegrationTest` (add a Content row to its table-driven style, or vice versa).
   *Savings: ~1 file × 2 kernel boots ≈ 0.4–0.6s.*
2. **`OpenApiIntegrationTest` → merge into `CoreOpenApiEnricherApiTest`**. Move the unique `/api/doc` swagger-UI
   HTML assertion, the public-Media endpoint tags/summaries, and the Media-upload requestBody + Media-schema
   assertions into `CoreOpenApiEnricherApiTest`, then delete `OpenApiIntegrationTest`.
   *Savings: 1 kernel boot ≈ 0.3–0.5s.*
3. **`TradePaymentIntegrationTest` → merge into `PaymentTradeIntegrationTest`** (survivor: PaymentTradeIntegrationTest,
   14 tests). The two payment/refund overlaps (see table) plus the app-order submit/confirm/pay and transition-failure
   tests duplicate what `PaymentTradeIntegrationTest` and `tests/Trade/Controller/App/OrderControllerTest` already
   cover. *Savings: 2–4 kernel boots ≈ 0.5–0.8s.*
4. **`CoreDynamicQueryApiTest::testShowDqlRejectedOutsideDev`**: fold its message assertion into
   `CoreExceptionInterceptorApiTest::testAccessDeniedOnShowDqlReturnsJsonEnvelope`, then delete the DynamicQuery
   copy. *Savings: 1 kernel boot ≈ 0.2–0.3s.*

Total estimated savings: **≈ 2.5–4s of the ≈13s Integration group (roughly 20–30%)** by deleting 10–12 redundant
tests and collapsing 3–4 files, without losing any behavior documented as a known bug.

## Verification steps

1. After any deletion, run the full suite on CI PostgreSQL to confirm zero failures and zero NEW skipped tests:
   `/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit`.
2. Confirm `composer phpstan` and `composer rector:types:check` still pass (no test-namespace references removed
   from any `src/` or other test file).
3. Confirm `src/` line coverage stays ≥ 90% after deletions (`XDEBUG_MODE=coverage ... --coverage-html var/coverage`);
   the candidates above assert behavior already covered by the cited surviving tests.
4. Confirm every listed DUPLICATE pair fails/passes together: temporarily revert one side, run the surviving test,
   then restore — the surviving test must still catch the same regression.
5. Re-check that the 38 skipped bug-documentation tests are untouched (all `markTestSkipped` cases remain KEEP).
