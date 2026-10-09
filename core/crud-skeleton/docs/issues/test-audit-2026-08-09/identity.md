# Identity module test audit (2026-08-09)

> Read-only audit of `tests/Identity/` (33 files, 281 test methods) for
> unnecessary / redundant tests. No `src/` or `tests/` file was modified; this
> report is the only artifact. Classification scheme: **A = KEEP**,
> **B = DELETE CANDIDATE** (1 coverage-chasing, 2 duplicate, 3
> implementation-detail, 4 redundant-regression, 5 near-empty), **C = MERGE**.
> Skipped tests that document known bugs are KEEP (per
> `docs/issues/coverage-2026-08-09/README.md`).

## Summary

| File | Tests | Verdict |
|---|---|---|
| `Command/CreateUserCommandTest.php` | 4 | KEEP |
| `Command/CreateUserCommandCoverageTest.php` | 5 | KEEP (1 skipped bug test) |
| `Controller/App/ProfileControllerTest.php` | 8 | KEEP |
| `Controller/App/UserControllerTest.php` | 3 | KEEP (3 candidates, MED) |
| `Controller/AuthControllerTest.php` | 20 | KEEP |
| `Controller/AuthControllerCoverageTest.php` | 9 | KEEP (2 skipped bug tests) |
| `Controller/Manage/ProfileControllerTest.php` | 2 | DELETE CANDIDATE (2) |
| `Controller/OtpControllerTest.php` | 4 | DELETE CANDIDATE (4) |
| `Controller/OtpControllerCoverageTest.php` | 9 | DELETE CANDIDATE (8; 1 skipped bug test KEEP) |
| `Entity/ProfileTest.php` | 21 | KEEP (8 candidates) |
| `Entity/RefreshTokenTest.php` | 3 | KEEP (2 candidates, MED) |
| `Entity/UserAdditionalTest.php` | 5 | KEEP |
| `Entity/UserTest.php` | 12 | KEEP (3 candidates, MED) |
| `EventListener/UserProfileListenerTest.php` | 4 | KEEP |
| `Integration/AuthBlackBoxIntegrationTest.php` | 4 | KEEP (merge) |
| `Integration/AuthIntegrationTest.php` | 7 | KEEP (3 candidates, MED; merge) |
| `Integration/UserApiIntegrationTest.php` | 39 | KEEP core; 15 candidates (14 cross-module + 1 dup) |
| `Repository/ProfileRepositoryTest.php` | 7 | KEEP (1 candidate, LOW) |
| `Repository/RefreshTokenRepositoryTest.php` | 3 | KEEP |
| `Repository/UserRepositoryTest.php` | 3 | KEEP |
| `Security/JwtAuthenticatorAdditionalTest.php` | 3 | KEEP (1 candidate, HIGH) |
| `Security/JwtAuthenticatorTest.php` | 7 | KEEP |
| `Security/TokenManagerAdditionalTest.php` | 14 | KEEP (2 candidates, MED) |
| `Security/TokenManagerTest.php` | 16 | KEEP (1 candidate HIGH, 2 MED) |
| `Service/LocalCacheOtpStorageTest.php` | 4 | KEEP |
| `Service/NullOtpStorageTest.php` | 3 | KEEP |
| `Service/OtpServiceCoverageTest.php` | 3 | KEEP (PII masking contract) |
| `Service/OtpServiceTest.php` | 9 | KEEP |
| `Service/ProfileServiceTest.php` | 11 | KEEP (8 candidates, MED) |
| `Service/RedisOtpStorageTest.php` | 6 | KEEP (1 skipped bug test) |
| `Service/UserServiceTest.php` | 28 | KEEP |
| `Sms/AliyunSmsProviderLiveBranchTest.php` | 3 | KEEP |
| `Sms/AliyunSmsProviderTest.php` | 2 | KEEP |

Counts are `public function test*` methods. `UserApiIntegrationTest` (39) is a
roll-up: register (6), app profile/password (8), manage user CRUD (9),
Trade specifications (4), Wallet deposit/transfer/reconcile (10),
manage/user 404s (2). 14 of its 39 tests are cross-module (Wallet/Trade).

## KEEP

The following behavior is critical and must not be deleted:

- **Token lifecycle / rotation / reuse (critical path #1, identity invariant):**
  `TokenManagerTest` (rotation revokes old + issues new, reuse detection revokes
  all, tampered/expired/malformed token rejection, TTL, base64url),
  `TokenManagerAdditionalTest` (constructor key failure paths, signed-token
  validation branches, rotate rollback/no-rollback on flush failure),
  `RefreshTokenRepositoryTest` (findValidByHash, revokeAllForUser,
  removeExpired), `RefreshTokenTest` (isExpired/revoke semantics).
- **Authenticator:** `JwtAuthenticatorTest` (supports, missing/invalid token,
  user resolution, user-not-found), `JwtAuthenticatorAdditionalTest` (translated
  failure message, non-numeric `sub` edge).
- **Auth endpoints:** `AuthControllerTest` + `AuthControllerCoverageTest`
  (login by email/phone/username, unverified-phone 403, refresh 400/401,
  logout revocation variants, register, OTP verify paths). Includes the two
  skipped Bug A / Bug D regression tests.
- **OTP service + storage contracts:** `OtpServiceTest`, `OtpServiceCoverageTest`
  (PII masking), `LocalCacheOtpStorageTest`, `NullOtpStorageTest`,
  `RedisOtpStorageTest` (incl. skipped Bug #1 regression).
- **User/Profile services:** `UserServiceTest` (register validation,
  changePassword, updateProfile), `ProfileServiceTest::testJoinAsMember*`.
- **Repositories:** `UserRepositoryTest`, `ProfileRepositoryTest` (real DQL
  against DB), `RefreshTokenRepositoryTest`.
- **Commands:** `CreateUserCommandTest` + `CreateUserCommandCoverageTest`
  (normalization, role parsing, duplicate guards, skipped Bug E test).
- **Listeners:** `UserProfileListenerTest`.
- **App/Manage controllers:** `App/ProfileControllerTest`, plus the core of
  `UserApiIntegrationTest` (manage user CRUD, change-password E2E with login
  re-check, profile update, admin-boundary 403, 404s).
- **SMS:** `AliyunSmsProviderTest`, `AliyunSmsProviderLiveBranchTest`.
- **All 5 skipped bug-repro tests** (Bug A × 2, Bug D, Bug E, RedisOtpStorage
  Bug #1) are KEEP per the coverage campaign policy.

## DELETE CANDIDATES

`File::method` uses the short test-class name. Confidence is about the
deletion recommendation, not about whether the test passes.

| File::method | Reason | Confidence | Covered by |
|---|---|---|---|
| `Security/TokenManagerTest::testDecodeAccessTokenEmptyStringReturnsNull` | B2 DUPLICATE | HIGH | `TokenManagerTest::testDecodeInvalidTokenReturnsNull` (line 68) already asserts `decodeAccessToken('') === null` |
| `Security/TokenManagerTest::testDecodeAccessTokenWithWrongSegmentCountReturnsNull` | B2 DUPLICATE | MED | same wrong-segment-count guard already exercised by `decodeAccessToken('a.b')` in `testDecodeInvalidTokenReturnsNull`; extra inputs (`'one'`, 4-segment) are redundant variants |
| `Security/TokenManagerTest::testBase64UrlDecodeHandlesPadding` | B4 REDUNDANT-REGRESSION | MED | round-trip contract already asserted by `testBase64UrlEncodeDecode` |
| `Security/TokenManagerAdditionalTest::testRevokeAllForUserDelegatesToRepository` | B2 DUPLICATE | MED | the same `revokeAllForUser($user)` invocation is already asserted in `TokenManagerTest::testReuseDetectionRevokesAllTokens` |
| `Security/TokenManagerAdditionalTest::testUnencryptedKeyWithWrongPassphraseStillLoads` | B3 IMPLEMENTATION-DETAIL | MED | self-documented as exercising an unreachable dev-fallback branch (identity-security report); no observable contract |
| `Security/JwtAuthenticatorAdditionalTest::testOnAuthenticationSuccessReturnsNullToContinue` | B5 NEAR-EMPTY | HIGH | asserts `assertNull()` on a method that unconditionally returns `null` (`JwtAuthenticator.php:65-69`) |
| `Entity/ProfileTest::testUuidMatchesV4Format` | B2 DUPLICATE | HIGH | exact same regex assertion already in `ProfileTest::testConstructorInitializesCoreFields` (lines 18-21) |
| `Entity/ProfileTest::testNicknameAccessors` | B1 COVERAGE-CHASING | MED | tautological set/get on a plain string field (no logic) |
| `Entity/ProfileTest::testAvatarAccessors` | B1 COVERAGE-CHASING | MED | tautological set/get on a plain string field |
| `Entity/ProfileTest::testMetadataAccessors` | B1 COVERAGE-CHASING | MED | tautological set/get on a plain array field |
| `Entity/ProfileTest::testSetJoinedAt` / `testSetJoinedAtNull` | B1 COVERAGE-CHASING | MED | trivial set/get round-trip with null reset |
| `Entity/ProfileTest::testDefaultProfileFieldsAreNull` | B2 DUPLICATE | MED | re-asserts the initial-null state already asserted inside the three accessor tests above |
| `Entity/ProfileTest::testAllLevelsCanBeSet` | B1 COVERAGE-CHASING | MED | set/get round-trip over every constant; no validation logic asserted |
| `Entity/UserTest::testPhoneAccessors` | B1 COVERAGE-CHASING | MED | trivial accessor + defaults; real phone semantics live in controller/repository tests |
| `Entity/UserTest::testPasswordAccessors` | B1 COVERAGE-CHASING | MED | trivial set/get |
| `Entity/UserTest::testIdDefaultsToNull` | B1 COVERAGE-CHASING | MED | trivial default assertion |
| `Entity/RefreshTokenTest::testConstructorAndGetters` | B1 COVERAGE-CHASING | MED | mostly accessor round-trip; behavioral bits (default `isRevoked()===false`) already covered in `testRevokeAndMetadataMutators` |
| `Entity/RefreshTokenTest::testRevokeAndMetadataMutators` | B1 COVERAGE-CHASING | MED | `setReplacedBy`/`setIpAddress`/`setUserAgent` are trivial setters; `revoke()` semantics covered by repository tests |
| `Controller/Manage/ProfileControllerTest::testControllerIsInstantiable` | B5 NEAR-EMPTY | HIGH | asserts `instanceof` on a freshly constructed controller; docblock itself admits it only checks "instantiation and basic wiring" |
| `Controller/Manage/ProfileControllerTest::testAcceptedPropertiesAreDefined` | B3 IMPLEMENTATION-DETAIL | HIGH | reflection over private property *names*; asserts nothing observable |
| `Controller/App/UserControllerTest` (all 3: `testChangePasswordRejectsUnauthenticatedUser`, `testProfileRejectsUnauthenticatedUser`, `testUpdateProfileRejectsUnauthenticatedUser`) | B1 COVERAGE-CHASING | MED | three copies of the identical `Not authenticated → 401` guard (`App/UserController.php:31,55,67`); collapse into one guard-level test or cover the shared `getUser()` check once |
| `Controller/OtpControllerTest::testRequestOtpRequiresPhone` | B2 DUPLICATE | HIGH | `AuthControllerTest::testRequestOtpRequiresPhone` — identical logic (`OtpController` is a byte-for-byte copy of `AuthController::requestOtp/verifyOtp`) |
| `Controller/OtpControllerTest::testRequestOtpReturnsTooManyRequestsWhenServiceThrows` | B2 DUPLICATE | HIGH | `AuthControllerTest::testRequestOtpRateLimitedReturnsTooManyRequests` |
| `Controller/OtpControllerTest::testVerifyOtpLoginReturnsTokens` | B2 DUPLICATE | HIGH | `AuthControllerTest::testVerifyOtpLoginSuccessReturnsTokens` |
| `Controller/OtpControllerTest::testVerifyOtpVerifyPhoneSetsFlagAndFlushes` | B2 DUPLICATE | HIGH | `AuthControllerCoverageTest::testVerifyOtpVerifyPhoneMarksPhoneVerified` |
| `Controller/OtpControllerCoverageTest::testRequestOtpRejectsInvalidPurpose` | B2 DUPLICATE | HIGH | `AuthControllerTest::testRequestOtpRejectsInvalidPurpose` |
| `Controller/OtpControllerCoverageTest::testRequestOtpSuccessReturnsNoContent` | B2 DUPLICATE | HIGH | `AuthControllerTest::testRequestOtpSuccessReturnsNoContent` |
| `Controller/OtpControllerCoverageTest::testVerifyOtpRequiresPhoneAndOtp` | B2 DUPLICATE | HIGH | `AuthControllerTest::testVerifyOtpRequiresPhoneAndOtp` |
| `Controller/OtpControllerCoverageTest::testVerifyOtpRejectsInvalidPurpose` | B2 DUPLICATE | HIGH | `AuthControllerTest::testVerifyOtpRejectsInvalidPurpose` |
| `Controller/OtpControllerCoverageTest::testVerifyOtpRejectsInvalidOtp` | B2 DUPLICATE | HIGH | `AuthControllerTest::testVerifyOtpInvalidCodeReturnsUnauthorized` |
| `Controller/OtpControllerCoverageTest::testVerifyOtpLoginRejectsUnknownUser` | B2 DUPLICATE | HIGH | `AuthControllerCoverageTest::testVerifyOtpLoginRejectsUnknownUser` |
| `Controller/OtpControllerCoverageTest::testVerifyOtpLoginRejectsUnverifiedPhone` | B2 DUPLICATE | HIGH | `AuthControllerCoverageTest::testVerifyOtpLoginRejectsUnverifiedPhone` |
| `Controller/OtpControllerCoverageTest::testVerifyOtpVerifyPhoneReportsSuccessForUnknownUser` | B2 DUPLICATE | HIGH | `AuthControllerCoverageTest::testVerifyOtpVerifyPhoneReportsSuccessForUnknownUser` |
| `Service/ProfileServiceTest::testNewReturnsProfileInstance` | B5 NEAR-EMPTY | MED | trivial `instanceof`; the generic `BaseServiceMutationTrait::new()` contract is covered by `tests/Core/Service/BaseServiceMutationTraitTest::testNew*` |
| `Service/ProfileServiceTest::testGetCallsRepositoryFind` | B1 COVERAGE-CHASING | MED | thin delegation to `EntityRepository::find`; generic get semantics covered by Core `BaseService*Test` |
| `Service/ProfileServiceTest::testGetReturnsNullForMissingId` | B1 COVERAGE-CHASING | MED | same delegation, null case |
| `Service/ProfileServiceTest::testGetWithArrayCriteria` | B1 COVERAGE-CHASING | MED | same delegation via `findOneBy` |
| `Service/ProfileServiceTest::testUpdatePersistsAndFlushes` / `testUpdateProfileFields` / `testUpdateClearsProfileFields` | B1 COVERAGE-CHASING | MED | generic `update()` + persist/flush already covered by Core `BaseServiceMutationTraitTest` and `tests/Integration/BaseServiceIntegrationTest::testUpdateGetListAndRemoveFlow`; the serializer mock applies setters itself, so the assertions mostly re-verify the mock |
| `Service/ProfileServiceTest::testRemovePersistsAndFlushes` | B1 COVERAGE-CHASING | MED | generic `remove()` covered by Core `BaseServiceMutationTraitTest::testRemove*` |
| `Integration/UserApiIntegrationTest::testUpdateProfileShortPasswordRejected` | B2 DUPLICATE | HIGH | **identical** request to `testChangePasswordShortNew` (same endpoint `/api/v1/app/users/change-password`, same payload `currentPassword='P@ssw0rd', newPassword='ab'`, same 400 assertion); only the fixture username differs |
| `Integration/UserApiIntegrationTest::testTransferSameWalletRejected` | B2 DUPLICATE | HIGH | `tests/Wallet/Integration/WalletApiRegressionTest::testTransferApiSameWallet` (same endpoint, same 400) |
| `Integration/UserApiIntegrationTest::testTransferMissingFields` | B2 DUPLICATE | HIGH | `WalletApiRegressionTest::testTransferApiMissingFields` plus `testBlindTransferInputs` datasets `missing fromWalletId` / `missing toWalletId` / `missing amount` |
| `Integration/UserApiIntegrationTest::testTransferNegativeAmountRejected` | B2 DUPLICATE | HIGH | `WalletApiRegressionTest::testBlindTransferInputs` dataset `negative amount` (same payload, 400) |
| `Integration/UserApiIntegrationTest::testDepositFundsToWallet` | B2 DUPLICATE (cross-module) | MED | Wallet deposit semantics owned by `tests/Wallet/` (`TransferControllerTest` depositAction success + `WalletServiceTest`/`TransferServiceTest`); no exact API-level deposit duplicate found, so MED |
| `Integration/UserApiIntegrationTest::testDepositNegativeAmountRejected` | B2 DUPLICATE (cross-module) | MED | negative-amount rejection covered by `TransferControllerTest` depositAction branches and `testBlindTransferInputs` (transfers); MED |
| `Integration/UserApiIntegrationTest::testDepositToNonexistentWallet` | B2 DUPLICATE (cross-module) | MED | 404-not-found behavior covered by `WalletApiRegressionTest::testTransferNonexistentSourceWallet`; deposit variant only, MED |
| `Integration/UserApiIntegrationTest::testDepositMissingFields` | B2 DUPLICATE (cross-module) | MED | deposit missing-field 400 covered by `TransferControllerTest` depositAction validation tests; MED |
| `Integration/UserApiIntegrationTest::testBalanceVerification` | B2 DUPLICATE (cross-module) | MED | `/manage/wallets/balance` reconcile contract covered at service layer by `WalletServiceTest`/`WalletServiceCoverageTest`; API-level variant, MED |
| `Integration/UserApiIntegrationTest::testReconcileAfterDepositProducesZero` | B2 DUPLICATE (cross-module) | MED | reconcile-zero semantics covered by `WalletServiceTest::testReconcile*`; API-level variant, MED |
| `Integration/UserApiIntegrationTest::testAppListSpecifications` / `testAppSpecificationsByProduct` / `testAppSpecificationDetail` / `testManageSpecificationDetail` | B2 DUPLICATE (cross-module) | MED | specification endpoints are Trade module scope; manage spec detail covered by `tests/Trade/Integration/TradeApiIntegrationTest::testSpecificationList/Update/Delete`; app-spec routes not found elsewhere so MED |
| `Integration/AuthIntegrationTest::testEmptyCredentialsReturnBadRequest` | B2 DUPLICATE | MED | 400-missing-fields already asserted in `AuthBlackBoxIntegrationTest::testLoginRejectsMissingFieldsAndWrongPassword` (first half) |
| `Integration/AuthIntegrationTest::testLoginFailsWithInvalidPassword` | B2 DUPLICATE | MED | 401-wrong-password already asserted in `AuthBlackBoxIntegrationTest::testLoginRejectsMissingFieldsAndWrongPassword` (second half) |
| `Integration/AuthIntegrationTest::testFullAuthFlow` (partial) | B2 DUPLICATE | MED | refresh-rotation + reuse-detection sequence overlaps `AuthBlackBoxIntegrationTest::testRefreshTokenReuseDetectionRevokesAllActiveTokens`; the unique bits are logout+revoked-usage and the `assertNotSame` rotation check — merge, don't drop wholesale |
| `Repository/ProfileRepositoryTest::testProfileStoresNickname` | B2 DUPLICATE | LOW | findById + read-back of a field, re-testing `testFindByIdReturnsProfile`'s query path |
| `Service/OtpServiceTest::testHashOtpIsDeterministic` / `testHashOtpProducesDifferentHashes` | B1 COVERAGE-CHASING | LOW | hash determinism is implied by the verify-flow tests; tautological |
| `Integration/UserApiIntegrationTest::testRegisterMissingFields` / `testRegisterShortPassword` | B1 COVERAGE-CHASING | LOW | the same rules are asserted at unit (`UserServiceTest::testRegisterEmptyFieldsThrows` / `testRegisterShortPasswordThrows`) and controller (`AuthControllerTest::testRegisterWithInvalidArgumentsReturnsBadRequest`) layers; HTTP layer adds only status-mapping |
| `Integration/UserApiIntegrationTest::testChangePasswordMissingFields` / `testManageChangePasswordShort` | B1 COVERAGE-CHASING | LOW | 400 outcomes mirror `UserServiceTest::testChangePasswordEmptyFieldsThrows` / `testAdminChangePasswordShortThrows` |

**Note on the OtpController pair (HIGH).** `src/Identity/Controller/OtpController.php`
is a copy-paste of `AuthController::requestOtp/verifyOtp` (identical bodies,
`/api/auth/otp/request` + `/api/auth/otp/verify`). `debug:router` registers
both controllers for the same two paths; the `sys-auth-*` routes sort before the
`sys-auth-otp-*` routes, so **OtpController is shadowed/unreachable dead code**
and its tests only exercise an offline copy of the live AuthController logic.
The whole OtpController test surface is therefore redundant while the duplicate
controller still exists. The one exception is
`OtpControllerCoverageTest::testRequestOtpWithMalformedBodyShouldReturnBadRequest`
(skipped, Bug A) — that documentation must be preserved (move its description to
`AuthControllerCoverageTest`, since AuthController carries the same unguarded
`json_decode`), so the skipped test itself is KEEP.

## MERGE SUGGESTIONS

- **`OtpControllerTest` + `OtpControllerCoverageTest` → fold into the AuthController
  OTP tests** after the shadowed `OtpController` is removed from `src/` (a
  `src/` change, out of scope here). All 13 runnable OTP tests are already
  covered by the AuthController pair.
- **`Integration/AuthIntegrationTest` + `Integration/AuthBlackBoxIntegrationTest` → one
  auth black-box file.** They overlap on refresh-rotation/reuse-detection and on
  the 400/401 login-denial cases; consolidating removes ~3 duplicated tests and
  keeps the end-to-end logout + reuse-revokes-all assertions.
- **`Integration/UserApiIntegrationTest` → relocate the 10 Wallet tests and 4
  Trade specification tests to `tests/Wallet/` and `tests/Trade/`** where the
  owning module suites live. The file keeps its Identity scope (register,
  profile, password, manage-user CRUD, ownership/404 edges).
- **`Entity/ProfileTest` accessor tests → collapse into a single table-driven
  set/get + touch test** (nickname/avatar/metadata/joinedAt), dropping the
  duplicated `testUuidMatchesV4Format` and `testDefaultProfileFieldsAreNull`.
- **`ProfileServiceTest` generic CRUD (`new/get/update/remove`) → drop the
  delegation-only tests** and keep `testJoinAsMember*` (the only Profile-specific
  logic); the generic machinery is owned by `tests/Core/Service/BaseService*`.
- **`Controller/App/UserControllerTest` → one guard test** for the shared
  "unauthenticated → 401" path instead of three identical copies.

## Verification steps

Before acting on any recommendation, verify locally (project PHP 8.4+ runtime):

```bash
/opt/homebrew/opt/php@8.5/bin/php ./vendor/bin/phpunit --filter Identity
```

- To confirm a HIGH-confidence DUPLICATE is safe to remove, run the covering test
  with the candidate excluded (e.g. temporarily use `--filter` to run only the
  covering test), then delete the candidate and re-run the Identity filter green.
- For the OtpController claim, re-run `bin/console debug:router | grep otp` to
  confirm the `sys-auth-*` routes still shadow `sys-auth-otp-*`; if route order
  ever changes, re-evaluate before deleting.
- For the Wallet/Trade cross-module candidates, run
  `--filter 'Wallet|Trade'` to confirm the owning suites pass without the
  Identity-file copies.
- Never delete a skipped (`markTestSkipped`) test — each documents a known bug
  (Bug A, B, D, E, RedisOtpStorage Bug #1) and is part of the KEEP baseline.
- After any deletion, confirm the Identity line-coverage budget for
  `src/Identity/` does not drop below the 90% gate; the removed tests are all
  covered by surviving tests or the Core/Wallet/Trade suites, so this should hold.
