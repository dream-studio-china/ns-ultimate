# Wechat module test audit (2026-08-09)

Scope: `tests/Wechat/**` (14 files, 78 tests, 1 skipped), cross-read against `src/Wechat/**`,
`tests/Payment/Integration/PaymentAdjustmentMultiGatewayIntegrationTest.php`, and
`tests/Core/Service/BaseServiceCoverageTest.php`. READ-ONLY — nothing under `src/` or `tests/` was modified.

## Summary

| File | Tests | Verdict |
|---|---|---|
| `Controller/App/WechatUserControllerTest.php` | 3 | KEEP |
| `Controller/LoginControllerCoverageTest.php` | 3 | MERGE into `LoginControllerTest` |
| `Controller/LoginControllerTest.php` | 10 | 9 KEEP, 1 DELETE |
| `Controller/Manage/WechatUserControllerTest.php` | 2 | KEEP |
| `Entity/WechatUserCoverageTest.php` | 3 | 1 DELETE, 2 MERGE into `WechatUserTest` |
| `Entity/WechatUserTest.php` | 6 | 3 KEEP, 3 DELETE |
| `Repository/WechatUserRepositoryTest.php` | 3 | 3 DELETE |
| `Service/Gateway/WechatPayGatewayCoverageTest.php` | 3 | 1 DELETE, 2 MERGE into `WechatPayGatewayTest` |
| `Service/Gateway/WechatPayGatewayNotifyBugTest.php` | 1 (skipped) | KEEP (documents Bug 1) |
| `Service/Payment/WechatPayGatewayTest.php` | 16 | 12 KEEP, 2 DELETE, 2 MERGE |
| `Service/WechatAuthServiceTest.php` | 6 | KEEP |
| `Service/WechatServiceCoverageTest.php` | 6 | 4 KEEP, 2 DELETE |
| `Service/WechatServiceTest.php` | 14 | 11 KEEP, 3 DELETE |
| `Service/WechatUserServiceTest.php` | 2 | 2 DELETE |

Totals: **60 KEEP / MERGE (content retained), 18 DELETE CANDIDATES** (11 HIGH, 7 MEDIUM confidence). No test documents an unfixed bug except `WechatPayGatewayNotifyBugTest`, which is KEPT.

## KEEP

Behavioral tests with genuine branch or contract value:

- **`Controller/LoginControllerTest.php`** — success, missing-input (400) and WeChat-error (401) paths for miniapp login, oauth url, oauth callback, and miniapp phone. Each hits a distinct branch.
- **`Controller/App/WechatUserControllerTest.php`** — `commonFilter()` owner-scoping is the only Wechat-specific logic in the App controller (lines 35-39): both user and anonymous branches plus filter delegation.
- **`Controller/Manage/WechatUserControllerTest.php`** — asserts the admin controller does **not** add owner scoping (inherited empty `commonFilter`); the only test asserting the admin vs app boundary.
- **`Entity/WechatUserTest.php`** — `testConstructorSetsRequiredFields` (constructor + `__toString` contract), `testOpenidCanBeUpdated` (setter `touch()` side effect), `testMetadata` (field composition).
- **`Service/WechatAuthServiceTest.php`** — existing/new-user paths for both providers and both `bindPhone` outcomes; fully covers `WechatAuthService` and was already 100% pre-campaign (no coverage churn here).
- **`Service/WechatServiceTest.php`** — `code2Session`, `getPhoneNumber`, `getOAuthUser` success/error/null-profile branches, `getOAuthRedirectUrl` (appid + scope), lazy-cache of miniapp/official apps.
- **`Service/WechatServiceCoverageTest.php`** — the four `getPayApp()` config-branch tests (platform cert / pub-key / both / neither; `WechatService.php:84-89`) are the **only** coverage of those config branches and assert real EasyWeChat config keys.
- **`Service/Payment/WechatPayGatewayTest.php`** — native/jsapi success, description/`'Payment'` fallbacks, refund status computation (SUCCESS full/partial vs pending), all three `notify()` error/success paths, and `getNotifySuccessResponse` fallback body.
- **`Service/Gateway/WechatPayGatewayCoverageTest.php`** — `testPayNativeClientWithoutPostJsonSupportThrows` and `testPayNativeClientReturningInvalidResponseThrows` are the only coverage of the `postJson()` defensive branches (`WechatPayGateway.php:199,204`).
- **`Service/Gateway/WechatPayGatewayNotifyBugTest.php`** — KEEP. Skipped regression documenting Bug 1 (notify never propagates the request to EasyWeChat; `serve()` reads an empty request). Per instructions, skipped tests documenting bugs are KEEP.
- **`Controller/LoginControllerCoverageTest.php`** — all 3 tests are the only coverage of `miniappPhone` success (204), `bindPhone` error → 400, and `oauthCallback` RuntimeException → 401. Content retained (see MERGE).

## DELETE CANDIDATES

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `Entity/WechatUserTest::testSettersAndGetters` | 1 — trivial accessor round-trips, no logic; the only non-trivial assertion (`updatedAt` non-null) duplicates `testOpenidCanBeUpdated` | HIGH | `Entity/WechatUserTest::testOpenidCanBeUpdated` |
| `Entity/WechatUserTest::testConstants` | 1 — tautology: `assertSame('miniapp', APP_TYPE_MINIAPP)` compares a constant to its own literal | HIGH | — |
| `Entity/WechatUserTest::testToStringWithNullId` | 2 — exact duplicate | HIGH | `Entity/WechatUserTest::testConstructorSetsRequiredFields` (asserts `'WechatUser#0 miniapp oTest1234'`, same `#0` prefix for a non-persisted entity) |
| `Entity/WechatUserCoverageTest::testGetIdReturnsNullBeforePersist` | 1 — `getId()` is `return $this->id` with `?int $id = null`; asserts a default | HIGH | — |
| `Repository/WechatUserRepositoryTest::testRepositoryIsProperlyInitialized` | 5 — `assertInstanceOf` on the object built in `setUp`; tautology | HIGH | — |
| `Service/WechatUserServiceTest::testCanBeInstantiatedWithContainer` | 5 — instantiates a 5-line logic-less `BaseService` subclass (`WechatUserService.php:12-17`) and asserts it is an instance of itself | HIGH | — |
| `Service/WechatServiceTest::testConstructorStoresConfiguration` | 5 — `assertInstanceOf(WechatService::class, $this->service)` immediately after the constructor already ran in `setUp` | HIGH | — |
| `Controller/LoginControllerTest::testMiniappLoginEmptyBody` | 2 — exact duplicate branch | HIGH | `Controller/LoginControllerTest::testMiniappLoginMissingJsCode` (both drive `$jsCode === ''` → 400, same assertion) |
| `Service/Payment/WechatPayGatewayTest::testGetName` | 2 — exact duplicate assertion | HIGH | `Payment/Integration/PaymentAdjustmentMultiGatewayIntegrationTest::testWechatGatewayIsRegistered` (`assertSame(Invoice::PAYMENT_WECHAT, $gateway::getName())`); also self-referential (`'wechat' === 'wechat'`) |
| `Service/Payment/WechatPayGatewayTest::testPayUnsupportedTradeTypeThrows` | 2 — exact duplicate branch | HIGH | `Payment/Integration/PaymentAdjustmentMultiGatewayIntegrationTest::testWechatGatewayPayThrowsForUnsupportedTradeType` (same `InvalidArgumentException` for `tradeType 'unsupported'`, `WechatPayGateway.php:93`) |
| `Service/Gateway/WechatPayGatewayCoverageTest::testPayJsapiWithPayerButNoWechatUserThrows` | 2 — exact duplicate branch | HIGH | `Payment/Integration/PaymentAdjustmentMultiGatewayIntegrationTest::testWechatGatewayPayJsapiRequiresWechatUser` (real payer, no `wechat_user` row → `findByUser` null → `RuntimeException 'WeChat user not found'`, `WechatPayGateway.php:60-63`); the integration test is the owning layer for this persistence boundary |
| `Repository/WechatUserRepositoryTest::testFindByOpenidReturnsNullWhenNoMatch` | 3 — mocks `QueryBuilder`/`Query` so it only proves delegation to Doctrine `findOneBy`; asserts nothing about persisted state | MEDIUM | — |
| `Repository/WechatUserRepositoryTest::testFindByUserReturnsNullWhenNoMatch` | 3 — same as above | MEDIUM | — |
| `Service/WechatUserServiceTest::testNewCreatesEntityInstance` | 2 — tests generic `BaseService::new()` through a logic-less subclass | MEDIUM | `Core/Service/BaseServiceCoverageTest::testNewOnStdClass` (same `new()` behavior, generic fixture) |
| `Service/WechatServiceTest::testSetMiniAppOverridesCachedInstance` | 3 — exercises the `@internal For testing` injection seam (`setMiniApp`, `WechatService.php:99-102`), not production behavior | MEDIUM | — |
| `Service/WechatServiceTest::testSetOfficialAccountOverridesCachedInstance` | 3 — same internal testing seam | MEDIUM | — |
| `Service/WechatServiceCoverageTest::testSetPayAppOverridesCachedInstance` | 3 — same internal testing seam (`setPayApp`, `WechatService.php:115-118`) | MEDIUM | — |
| `Service/WechatServiceCoverageTest::testGetPayAppReturnsCachedInstance` | 2 — third repetition of the same lazy-cache behavior already asserted for the other two accessors | MEDIUM | `Service/WechatServiceTest::testGetMiniAppReturnsCachedInstance` (identical `assertSame($a, $b)` on consecutive accessor calls) |

Confidence rules: HIGH used only where the covering test exercises the identical code path/assertion (exact citation above). MEDIUM covers layer/wrong-layer and internal-seam arguments.

Coverage-gate implication (not a blocker, but required follow-up if these are deleted):
- `WechatUserServiceTest` is the only coverage of `WechatUserService.php` (0% → 100% in the campaign). Deleting it drops the file to 0%; it should be `@codeCoverageIgnore`d (a `src/` change) or the deletion paired with that.
- Deleting `WechatUserRepositoryTest` drops the only coverage of `WechatUserRepository.php`; `findOneBy` delegation is best proven by the owning integration layer instead (see Verification).
- `WechatUserCoverageTest::testGetIdReturnsNullBeforePersist` is the only caller of `getId()`; its return line falls uncovered unless the follow-up also ignores the trivial getter.

## MERGE SUGGESTIONS

Consolidate same-class coverage-file splits created by the 2026-08-09 campaign back into their primary files (contents are KEEP; only file placement changes):

1. `Controller/LoginControllerCoverageTest.php` → fold its 3 tests into `Controller/LoginControllerTest.php` (identical `setUp`, helpers already present). One file per class.
2. `Entity/WechatUserCoverageTest.php` → fold `testPrePersistInitializesCreatedAtWhenUnset` and `testPrePersistKeepsExistingCreatedAt` into `Entity/WechatUserTest.php` (real `prePersist` lifecycle branches, same fixture). Delete `testGetIdReturnsNullBeforePersist` (see above).
3. `Service/WechatServiceCoverageTest.php` → fold the four `getPayApp()` config-branch tests into `Service/WechatServiceTest.php`. Keep them as one table-driven set; drop `testSetPayAppOverridesCachedInstance` and `testGetPayAppReturnsCachedInstance`.
4. `Service/Gateway/WechatPayGatewayCoverageTest.php` → fold the two `postJson()` defensive-branch tests into `Service/Payment/WechatPayGatewayTest.php`; delete `testPayJsapiWithPayerButNoWechatUserThrows`.
5. `Service/Payment/WechatPayGatewayTest.php` — merge `testGetNotifySuccessResponse` and `testNotifySuccessResponseIsJson` into one test (both cover the success response; fold the `Content-Type` assertion into the primary).

## Verification steps

1. Baseline: `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Wechat` → 78 tests, 1 skipped, all green.
2. For each HIGH-confidence DUPLICATE candidate, confirm the covering test exists and asserts the same branch: re-run the named covering files, e.g.
   `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit tests/Payment/Integration/PaymentAdjustmentMultiGatewayIntegrationTest.php` (covers gateway name, unsupported-trade-type, and jsapi-no-wechat-user).
3. After the (future, non-read-only) deletion, verify no Wechat line loses coverage except the explicitly flagged trivial/delegation lines:
   `XDEBUG_MODE=coverage /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --coverage-text tests/Wechat` and check `src/Wechat/**` per file.
4. Confirm merge moves are pure file placement: run the merged files and assert test count/assertions are unchanged and the suite stays at 0 failures / 1 skipped.
5. Run the full suite once (`XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit`) to catch cross-test coupling before and after changes.
