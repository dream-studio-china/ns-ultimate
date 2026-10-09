# Testing

> How the test suites are organised and run. The suite currently stands at
> **2224 tests · 7951 assertions** in the default run, plus **477 low-value tests**
> that are excluded by default.

---

## 1. Suite Layout

Tests live under `tests/` and are namespaced `App\Tests\...`:

```
tests/
|-- bootstrap.php                    # env bootstrapping, DB isolation, paratest wiring
|-- UnitTest/                        # pure unit tests, no kernel, no DB
|-- Integration/                     # kernel + DB + HTTP tests, shared helpers
|-- LowValue/                        # audit-flagged tests, excluded by default
|-- Smoke/                           # long fuzzy smoke suites (Settlement/Nightclub)
`-- Identity/Security/               # JWT test keypair used by .env.test
```

| Directory | Namespace root | Tests | Approx. files |
|-----------|----------------|-------|---------------|
| `tests/UnitTest` | `App\Tests\UnitTest` | Entities, utils, DSL engine, promotion strategies, mock-based services/controllers, workflow state machine | 189 |
| `tests/Integration` | `App\Tests\Integration` | Cross-module flows, API regressions, outbox/inbox idempotency, concurrency, health/metrics/rate-limit endpoints | 71 |
| `tests/LowValue` | `App\Tests\LowValue` | Audit-flagged duplicates and coverage-chasing tests | 43 |

`tests/Integration` also contains the shared helpers:

- `DatabaseBootstrapTrait.php`
- `IntegrationKernelTestCase.php`
- `IntegrationWebTestCase.php`

Plus API regression suites (`*ApiRegressionTest.php`), integration suites
(`*IntegrationTest.php`), and the OpenAPI integration test that builds the complete
specification in-process.

---

## 2. phpunit.dist.xml

`phpunit.dist.xml` is the single PHPUnit configuration:

| Setting | Value |
|---------|-------|
| Bootstrap | `tests/bootstrap.php` |
| Env (forced) | `APP_ENV=test`, `KERNEL_CLASS=App\Kernel` |
| Failures | `failOnDeprecation`, `failOnNotice`, `failOnWarning` all `true` |
| Memory | `memory_limit=512M` (OpenAPI integration builds the full spec in-process) |
| Suites | `Project Test Suite` (UnitTest + Integration), `Low Value` (LowValue) |
| Groups | `low-value` globally **excluded** from every run |
| Coverage source | `src/`, with `ignoreSuppressionOfDeprecations`, deprecation triggers mapped to Doctrine/Symfony deprecation APIs |

The `Low Value` suite exists so the excluded files stay discoverable for
`--group low-value`; the suite itself runs empty because the global exclusion applies
to every run.

---

## 3. Shared Integration Helpers

### 3.1 `DatabaseBootstrapTrait`

Used by kernel/web integration tests that need a working database:

```php
use App\Tests\Integration\DatabaseBootstrapTrait;

class MyKernelTest extends IntegrationKernelTestCase
{
    use DatabaseBootstrapTrait;

    public function testSomething(): void
    {
        $this->bootTestDatabase();
        // ...kernel is booted, schema is ready...
    }
}
```

What `bootTestDatabase()` does — once per process (guarded by a static flag):

1. Boots the kernel.
2. `doctrine:schema:drop --force --full-database` (test env, quiet).
3. `doctrine:schema:create` (test env, quiet).

The schema is built from the **Doctrine schema tool**, not from migrations — that is
the contract for tests (see §6). It also pins `getKernelClass()` to `App\Kernel`.

### 3.2 `IntegrationKernelTestCase`

Abstract base extending `KernelTestCase`. Resolves the kernel class directly to
`App\Kernel` (no autoloader assumptions) and exposes `createKernel()` using
`APP_ENV`/`APP_DEBUG` from the environment with `test`/`true` defaults. Use it for
kernel-booted tests that need container services or the database.

### 3.3 `IntegrationWebTestCase`

Abstract base extending `WebTestCase` for full HTTP request/response tests. Its key
addition:

```php
$client = static::createAuthenticatedClient();
```

- Boots the kernel, creates the persistent test user `testauth@example.com`
  (username `testauth`, password `TestPass123!`, `ROLE_ADMIN`) if missing, and
  - generates a **real JWT** through `App\Identity\Security\TokenManager`, then
  - injects `HTTP_AUTHORIZATION: Bearer <token>` as a server parameter.

This satisfies firewall rules that require `IS_AUTHENTICATED_FULLY` without re-dealing
with the login flow in every test.

---

## 4. Test Categories

| Category | Base / pattern | Purpose |
|----------|----------------|---------|
| Unit | `PHPUnit\Framework\TestCase` | Isolated logic — no kernel, no DB |
| Kernel | `IntegrationKernelTestCase` (+ `DatabaseBootstrapTrait`) | Booted kernel, container services, DB access |
| Web | `IntegrationWebTestCase` | Full HTTP request/response cycle, with or without JWT auth |
| Regression | `*ApiRegressionTest` | API contract stability across modules |
| Smoke | `tests/Smoke/` (e.g. `NightclubSettlementFuzzySmokeTest`) | Long-running fuzz/property smoke suites |

Naming pattern: `{Class}Test.php`, `{Module}ApiRegressionTest.php`,
`{Module}IntegrationTest.php`.

---

## 5. Running Tests

### 5.1 Full default suite (serial)

```bash
./vendor/bin/phpunit
```

Runs `tests/UnitTest` + `tests/Integration` (the `low-value` group is excluded
automatically). Uses SQLite `var/test.db` by default.

### 5.2 Parallel (recommended for speed)

```bash
PARATEST=1 ./vendor/bin/paratest --processes 8 --runner WrapperRunner
```

Aprox. 2–3× faster. `tests/bootstrap.php` gives every worker its **own** SQLite file
(`var/test_paratest_{pid}.db`), eagerly creates the schema, and routes the test access
log to a per-process file (`var/log/access-{pid}.log`) so concurrent workers never
race. CI uses the same mechanism but with 4 workers and one PostgreSQL database per
token (`app_test_1..4`) via `PARATEST_DATABASE_URL_TEMPLATE`.

### 5.3 Single file

```bash
./vendor/bin/phpunit tests/UnitTest/Core/Service/BaseServiceInfrastructureTraitTest.php
```

### 5.4 Low-value group (excluded by default)

```bash
./vendor/bin/phpunit --group low-value
```

These are kept for reference and historical audit coverage; they are not part of the
CI gate.

### 5.5 Coverage

Coverage is measured over `src/` only (`pcov.directory=src`). Use **PCOV** in CI; for
local runs the same flag applies:

```bash
PCOV_ENABLED=1 ./vendor/bin/phpunit --coverage-text
```

or with Xdebug (slower, no PCOV extension required):

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-text
```

HTML report:

```bash
XDEBUG_MODE=coverage ./vendor/bin/phpunit --coverage-html var/coverage
# open var/coverage/index.html
```

### 5.6 The 90% coverage gate

CI enforces a **minimum 90% line coverage** on the `src/` source set. The `tests` job
runs paraTest with `--coverage-text`, extracts the `Lines:` figure, and fails the build
if it is below `90.0` (compared with `bc`). Treat 90% as a floor, not a target — new
code should travel with tests that cover its behaviours, especially error paths.

---

## 6. Test Database Contract

| Rule | Detail |
|------|--------|
| Environment | `APP_ENV=test`, `KERNEL_CLASS=App\Kernel` (forced in `phpunit.dist.xml`) |
| Default DB | SQLite (`var/test.db`), no external service required |
| CI DB | PostgreSQL 16, one database per ParaTest token |
| Schema | Created from the **Doctrine schema tool** (`doctrine:schema:create`) — never from migrations in local tests |
| Cleanliness | `DatabaseBootstrapTrait` drops and recreates the schema once per process; tests seed fixtures per test/class |
| Isolation | Each ParaTest worker runs against its own database; serial runs share `var/test.db` but are flushed per test |

Required test configuration comes from `.env.test`:

```env
APP_SECRET='$ecretf0rt3st'
DATABASE_URL='sqlite:///%kernel.project_dir%/var/test.db'
INVENTORY_ENABLED=0
JWT_PRIVATE_KEY_PATH=.../tests/Identity/Security/test_private.pem
JWT_PUBLIC_KEY_PATH=.../tests/Identity/Security/test_public.pem
JWT_PASSPHRASE=''
ACCESS_TOKEN_TTL=7200
REFRESH_TOKEN_TTL=31536000
REFRESH_TOKEN_SECRET=test_refresh_secret_key_32bytes
OTP_TTL=300
OTP_REDIS_DSN=redis://localhost:6379/0
ALIYUN_*  # SMS values with ALIYUN_SMS_DRY_RUN=true
# WeChat:*  # left empty — WeChat is disabled for tests
```

---

## 7. Static Analysis and the Coverage Gate in CI

The `tests` job in `.github/workflows/ci.yml` is the authoritative gate:

```
Set up PHP 8.4 (ext pdo_pgsql, coverage: pcov)
  → composer install
  → start postgres:16, create app_test_1..4
  → PCOV_ENABLED=1 PARATEST=1 php vendor/bin/paratest \
      --processes=4 --coverage-text=/tmp/coverage.txt --display-deprecations
  → extract "Lines: <pct>%" → fail if < 90.0
```

PHPStan and Rector gates are separate jobs (`phpstan`, `rector`). See
[development-workflow.md](development-workflow.md) for the full pipeline.

---

## 8. Troubleshooting

| Symptom | Cause / fix |
|---------|-------------|
| `Missing DATABASE_URL` / tables not found | Run with the bootstrap loaded (phpunit does this automatically); don't set a real `DATABASE_URL` while using the sqlite default |
| Parallel run races on schema | `tests/bootstrap.php` only isolates databases when `PARATEST=1`; do not mix a project-level `DATABASE_URL` with a parallel run unless a `PARATEST_DATABASE_URL_TEMPLATE` is supplied |
| Coverage below threshold after adding code | Add tests for the new source lines; check `var/coverage/index.html` |
| JWT errors in tests | Ensure `JWT_PRIVATE_KEY_PATH`/`JWT_PUBLIC_KEY_PATH` point at `tests/Identity/Security/` keypair and `JWT_PASSPHRASE=''` |
| Memory exhaustion on OpenAPI tests | `memory_limit=512M` is preset in `phpunit.dist.xml`; raise only when actually extending the spec |

Refer to the test-quality contract in
[`docs/testing/crud-skeleton-production/README.md`](../testing/crud-skeleton-production/README.md)
and the audit that flagged the low-value tests in
[`docs/issues/test-audit-2026-08-09/`](../issues/test-audit-2026-08-09/README.md).