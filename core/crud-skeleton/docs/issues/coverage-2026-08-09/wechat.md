# WeChat module — coverage to ~100% and bug report

Date: 2026-08-09
Scope: `src/Wechat/**` (+ the WeChat-facing parts of `src/Payment/**` exercised via the gateway)
Runner: `XDEBUG_MODE=off /opt/homebrew/opt/php@8.5/bin/php vendor/bin/phpunit <file> --no-coverage`
Rule: **no changes under `src/`** — only tests added under `tests/` plus this report.

## Coverage before → after

| File | Before | After |
|---|---|---|
| `Wechat/Controller/LoginController.php` | 85.37% | **100%** (41/41 lines, 7/7 methods) |
| `Wechat/Controller/App/WechatUserController.php` | 100% | 100% (3/3) |
| `Wechat/Controller/Manage/WechatUserController.php` | 100% | 100% (1/1) |
| `Wechat/Entity/WechatUser.php` | 92.86% | **100%** (42/42, 32/32) |
| `Wechat/Repository/WechatUserRepository.php` | 100% | 100% (3/3) |
| `Wechat/Service/Payment/WechatPayGateway.php` | 98.29% | **100%** (117/117, 7/7) |
| `Wechat/Service/WechatAuthService.php` | 100% | 100% (65/65) |
| `Wechat/Service/WechatService.php` | 96.63% | **100%** (89/89, 11/11) |
| `Wechat/Service/WechatUserService.php` | 0% | **100%** (1/1) |

Every executable line in the WeChat module is now covered (verified with Xdebug + Clover XML).

## Test files added (18 tests, 39 assertions)

- `tests/Wechat/Entity/WechatUserCoverageTest.php` — `getId()`; `prePersist()` when `createdAt` is uninitialized (via `ReflectionClass::newInstanceWithoutConstructor()`, no deprecated `setAccessible()`); `prePersist()` keeps an existing `createdAt`.
- `tests/Wechat/Service/WechatServiceCoverageTest.php` — `getPayApp()` with a platform cert path, a public-key id/path, both, and neither; `setPayApp()` override; cached instance.
- `tests/Wechat/Service/WechatUserServiceTest.php` — instantiates the trivial `BaseService` subclass with a mocked `ContainerInterface` (lazy `doctrine.orm.entity_manager`), verifies interface + `new()`.
- `tests/Wechat/Service/Gateway/WechatPayGatewayCoverageTest.php` — jsapi with a payer but no matching `WechatUser` (line 62); `postJson()` defensive branches: client without `postJson()` (line 199) and client returning a non-`ResponseInterface` (line 204). Lines 199/204 are covered using anonymous `HttpClientInterface` implementations (no `__call`, so `is_callable()` behaves correctly).
- `tests/Wechat/Controller/LoginControllerCoverageTest.php` — `miniappPhone` success (204), `bindPhone` RuntimeException → 400, `oauthCallback` RuntimeException → 401.
- `tests/Wechat/Service/Gateway/WechatPayGatewayNotifyBugTest.php` — **skipped**; see Bug 1 below.

The 26 `PHPUnit Notices` shown in the run are pre-existing (mock-object-without-expectations in the old `WechatPayGatewayTest`); none of the new tests trigger notices/deprecations/warnings. Suite stays green (`OK`, 78 tests, 1 skipped).

## Bugs found

### Bug 1 (HIGH) — WeChat Pay v3 notify can never succeed

- **File / line:** `src/Wechat/Service/Payment/WechatPayGateway.php` — `notify()` (server obtained at line 102, PSR request built at line 105, `serve()` called at line 126).
- **Description:** `notify()` builds a PSR request from the incoming Symfony request **only for the signature validator**, but never feeds it to the EasyWeChat server. `$app->getServer()` is constructed internally with `RequestUtil::createDefaultServerRequest()` → `ServerRequestCreator::fromGlobals()`. In a Symfony request cycle `php://input` has already been consumed by HttpFoundation, and in CLI/queue contexts it is empty, so `$server->serve()` always parses an **empty body**.
- **Impact:** Every real WeChat Pay v3 payment callback fails with `PaymentVerificationException: WeChat notify verification failed: Invalid request body.` (`PaymentNotifyController::notifyAction` returns HTTP 400 `FAIL: ...`). Invoices are never marked `paid` via the webhook.
- **Reproduction:** `tests/Wechat/Service/Gateway/WechatPayGatewayNotifyBugTest.php` builds a real `EasyWeChat\Pay\Application` (real `Merchant`), calls `WechatPayGateway::notify()` with a well-formed `TRANSACTION.SUCCESS` JSON body, and observes `PaymentVerificationException: ... Invalid request body.` — the body was never seen by the server. (Removing the `markTestSkipped()` makes the test fail, i.e. the assertion "failure must not be 'Invalid request body'" is not met.)
- **Proposed fix:** before `$response = $server->serve();`, propagate the incoming request, e.g. `$app->setRequestFromSymfonyRequest($request);` (or `$server->setRequest($psrRequest);`), and use that same request for validation instead of a freshly-built one.

### Bug 2 (LOW) — `code2Session` uses the access-token client

- **File / line:** `src/Wechat/Service/WechatService.php` — `code2Session()` line 127: `$app->getClient()->get('/sns/jscode2session', ...)`.
- **Description:** `MiniApp::getClient()` returns an `EasyWeChat\Kernel\HttpClient\AccessTokenAwareClient` that appends `access_token` to every request (and fetches one via `GET /cgi-bin/token` when not cached). `/sns/jscode2session` is an appid+secret endpoint that does **not** require an access token. EasyWeChat's canonical helper `MiniApp\Utils::codeToSession()` uses the plain `getHttpClient()`.
- **Impact:** Every mini-program login makes an extra `/cgi-bin/token` round-trip (cache miss the first time) and the login fails if the token endpoint fails even though `code2Session` would have succeeded. Works but wasteful / extra failure surface.
- **Reproduction:** not directly reproducible without live credentials; provable by inspecting `MiniApp::createClient()` / `AccessTokenAwareClient::request()`.
- **Proposed fix:** `return $this->getMiniApp()->getUtils()->codeToSession($jsCode);` or call `$this->getMiniApp()->getHttpClient()->request('GET', '/sns/jscode2session', ['query' => [...]])->toArray(false)`.

### Bug 3 (MEDIUM) — auto-generated WeChat user identity can collide

- **File / line:** `src/Wechat/Service/WechatAuthService.php` — `findOrCreateUser()` lines 116–119.
- **Description:** new users are created with `username`/`email` derived from the **last 8 characters** of the openid: `wx_<suffix>` / `wx_<suffix>@wechat.local` (both lowercased by `User::setUsername`/`setEmail`). Distinct openids sharing the same 8-char tail produce identical usernames/emails. The `User` entity has unique constraints on both (`uniq_users_username`, `uniq_users_email`).
- **Impact:** at scale (birthday-paradox on 8 chars, ~60k users), a second new-user login with a colliding suffix throws a `UniqueConstraintViolationException` during `flush()` → HTTP 500, user cannot log in. The `WechatUser`/`User` records are also created before the constraint is enforced, so a partial write is left behind.
- **Reproduction:** unit-level — two calls to `findOrCreateUser()` with openids differing only outside the last 8 chars (e.g. `aaa...o12345678` vs `bbb...o12345678`) both derive `wx_o12345678`. Needs a real DB flush to surface; not added as a test to keep the suite green.
- **Proposed fix:** use the full openid (or a hash of it) as the suffix, e.g. `mb_substr($openid, 0, 40)` + sanitize, or `substr(hash('sha256', $openid), 0, 12)`, and/or catch `UniqueConstraintViolationException` and fall back to the existing user.

## Notes

- `WechatAuthService` and `WechatUserRepository` were already at 100% — no new tests were added for them.
- Bug 1 is the one "correct-behavior test fails" case; it is marked skipped and documented above so the suite remains green.
- Bugs are reported only — **no source files were modified**.
