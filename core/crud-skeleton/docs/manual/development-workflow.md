# Development Workflow

> How this repository is branched, reviewed, analyzed, and verified in CI. Follow this
> process for every change, from a one-line fix to a new module.

---

## 1. Branching Model

The repository uses a trunk-based flow with a long-lived integration branch:

```
main  ──────────────────────────────────────────────►  stable / release (PR target)
   \                                                /
    └── dev ───────────────────────────────►  integration (PR target for features)
           \
            ├── feat/xxx         feature branch (merged into dev)
            ├── fix/yyy          bug-fix branch (merged into main or dev)
            └── chore/zzz        tooling / docs / refactor branches
```

| Branch | Purpose | CI runs on |
|--------|---------|------------|
| `main` | Stable trunk; only reviewed, tested, migration-verified code lands here | push + pull_request |
| `dev` | Integration branch where features accumulate before release | pull_request only (ci/migrations), push for docs deploy |
| `feat/*`, `fix/*`, `chore/*` | Short-lived working branches, one per PR | pull_request (targeting `main`/`dev`) |

Rules:

- **Never push directly to `main` or `dev`.** All changes arrive via pull requests.
- Open PRs against **`main`** (releases, hotfixes) or **`dev`** (features, larger changes).
- Rebase or merge frequently so branches stay close to their target.
- A newer push supersedes in-flight checks for the same PR or branch (CI is configured
  with `concurrency: cancel-in-progress: true`).

---

## 2. Coding Standards

PHP 8.4+ is required for all tooling.

| Requirement | Standard |
|-------------|----------|
| PHP version | `>= 8.4` (`composer.json` `require.php`) |
| Namespace | PSR-4 `App\` under `src/`, `App\Tests\` under `tests/` |
| Scalar/return types | Explicit everywhere possible; strict typing (`declare(strict_types=1)`) expected in new code |
| Property types | Explicit (never docblock-only) |
| Nullable | `?Type` syntax |
| Use statements | Alphabetically ordered |
| One class per file | File name equals class / trait / interface name |
| Comments | PHPDoc on interfaces and abstract methods only; no comments on self-documenting code |
| Naming | See the [naming convention](../design/naming-convention.md) — module namespace carries ownership, service/controller/repository mirror the entity name |

---

## 3. Static Analysis

Static analysis runs exactly as CI runs it — locally, they must be green before a PR.

### 3.1 PHPStan (Level 8)

```bash
composer phpstan
```

Configured in `phpstan.neon`:

| Setting | Value |
|---------|-------|
| Level | `8` |
| Paths | `src/` |
| Excluded | `src/*/Exception/*`, `src/Identity/Sms/AliyunSmsProvider.php` |
| Bootstrap | `vendor/autoload.php` |
| Symfony integration | `vendor/phpstan/phpstan-symfony/extension.neon` (reads `var/cache/dev/App_KernelDevDebugContainer.xml`) |
| Doctrine integration | `vendor/phpstan/phpstan-doctrine/extension.neon` with `objectManagerLoader: phpstan-bootstrap.php` |
| PHPDoc certainty | `treatPhpDocTypesAsCertain: false` (controllers use `$this->service`/`$this->get()` from trait mixins) |
| Universal object crates | EasyWeChat `AccessToken` / `Server` contracts |

`phpstan-bootstrap.php` boots a minimal `App\Kernel` and returns the Doctrine
`EntityManager` so PHPStan can resolve repository/Doctrine metadata. In CI it runs
with an isolated SQLite URL (`var/phpstan.db`), so it never touches a real database.

Generate or refresh a baseline (only if a strictness bump forces it):

```bash
composer phpstan-baseline
```

### 3.2 Rector (type rules only in CI)

```bash
composer rector:types:check      # dry-run — the CI gate
composer rector:types            # actually apply the type rules
```

`rector-types.php` enables exactly two rules over `src/`:

| Rule | What it enforces |
|------|------------------|
| `AddAnnotationToRepositoryRector` | Doctrine 3.6+ repositories must carry `@extends ServiceEntityRepository<...>` |
| `CompleteReturnDocblockFromToManyRector` | `->mappedBy`/`->inversedBy` OneToMany/ManyToMany return PHPDoc collections |

`composer rector` (broader opt-in refactoring) is **not** part of CI — review its diff
carefully before applying:

```bash
composer rector      # dry-run of the broad rule set
composer rector:fix  # apply it
```

### 3.3 Adding a fix / baseline entry

Do not silence PHPStan wholesale. If a genuine boundary case cannot be typed, add a
scoped `ignoreErrors` entry in `phpstan.neon` **with a comment explaining why** — the
existing entries all carry an explanation (optional SDKs, trait mixins, lifecycle
callbacks).

---

## 4. Commit Conventions

- **Conventional Commits**, English only.
- Format: `type(scope): subject` — e.g. `feat(settlement): add rule version diffing`,
  `fix(inventory): recover EM after rollback`, `test(wallet): cover concurrent transfers`.

| Type | Use for |
|------|---------|
| `feat` | New capability |
| `fix` | Bug correction |
| `refactor` | Behaviour-preserving restructuring |
| `test` | Tests only |
| `docs` | Documentation / comments |
| `chore` | Tooling, CI, dependencies, housekeeping |
| `ci` | Workflow file changes |
| `perf` | Performance work |

- Subject: imperative mood, no trailing period, lowercase (except nouns/proper names).
- Body (optional): what and why, not how. Reference issues with `Closes #123`.
- Keep each commit focused; one logical change per commit.
- Commit messages are written in **English only** — this is a hard rule.

---

## 5. Pull Request Checklist

Every PR must satisfy the repo template (`.github/pull_request_template.md`):

- [ ] Branch is up-to-date with `main`
- [ ] Summary describes the change; related issues linked
- [ ] Type of change flagged (bug / feature / docs / tests / refactor / CI / i18n)
- [ ] `./vendor/bin/phpunit` — all tests pass
- [ ] Coverage does not drop below **90%**
- [ ] `composer phpstan` — Level 8 check passes
- [ ] `composer rector:types:check` — no PHPDoc type changes required
- [ ] Manual smoke test performed (described in the PR)
- [ ] Commits follow Conventional Commits
- [ ] New features include tests
- [ ] API changes documented with `#[OA\*]` attributes
- [ ] Behaviour changes reflected in `docs/ai/context.md` where appropriate
- [ ] i18n translation keys added for new user-facing messages (en, zh, zh_Hant, ja)

---

## 6. CI Pipeline

Three workflows live in `.github/workflows/`. All are **path-triggered** — unrelated
changes do not re-run the whole pipeline.

### 6.1 `ci.yml` — PHPStan + PHPUnit (with coverage) + Rector

**Triggers** — push to `main`/`master` and pull requests against `main`, `master`, or
`dev`, limited to these paths:

```
src/**            tests/**          config/**         migrations/**
bin/**            composer.json     composer.lock     symfony.lock
phpunit.dist.xml  phpstan.neon      phpstan-bootstrap.php
rector*.php       .env.test         .github/workflows/ci.yml
```

**Jobs** (PHP 8.4 on `ubuntu-latest`):

| Job | What it runs | Notes |
|-----|--------------|-------|
| `phpstan` | `composer phpstan` | `DATABASE_URL=sqlite:///.../var/phpstan.db` |
| `tests` | `composer install` → boot Postgres 16 service → create `app_test_1..4` → run **ParaTest with 4 workers** + PCOV coverage → enforce 90% line threshold | matrix: `php: ['8.4']` |
| `rector` | `composer rector:types:check` (dry-run) | `DATABASE_URL=sqlite:///.../var/rector.db` |

**The parallel test job in detail:**

1. `shivammathur/setup-php` with `coverage: pcov`, `ini-values: pcov.directory=src`
   — so PCOV measures only application code under `src/`.
2. A `postgres:16` service container is started with `app`/`app_secret` credentials and
   health checks.
3. `app_test_1` … `app_test_4` databases are created via `psql`.
4. Tests run with:

   ```bash
   PCOV_ENABLED=1 PARATEST=1 php vendor/bin/paratest \
     --processes=4 --coverage-text=/tmp/coverage.txt --display-deprecations
   ```

   Each ParaTest worker (tokens 1–4) gets its own Postgres database through
   `PARATEST_DATABASE_URL_TEMPLATE=postgresql://.../app_test_{token}?...` — concurrent
   schema drops/creates can never race.
5. The 90% gate parses `Lines: <pct>` from the coverage text and fails via
   `bc` comparison if it drops below `90.0`.

Full env for the `tests` job mirrors `.env.test` (JWT keys at
`tests/Identity/Security/`, `APP_ENV=test`, `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0`,
`MAILER_DSN=null://null`, `OTP_REDIS_DSN`, Aliyun SMS test values with dry-run on).

### 6.2 `migrations.yml` — MySQL migration chain

**Triggers** — push to `main`/`master` and PRs against `main`/`master`/`dev`, on
`migrations/**`, `config/**`, `bin/**`, `composer.json`, `composer.lock`,
`symfony.lock`, and the workflow file itself.

**Job `mysql-migration-chain`:**

1. Start `mysql:8.4` service (`crud_skeleton_test` database, user `app`/`app`).
2. `composer install` with `DATABASE_URL=mysql://app:app@127.0.0.1:3306/crud_skeleton_test?serverVersion=8.4&charset=utf8mb4`.
3. `php bin/console doctrine:migrations:migrate --no-interaction --env=dev`
4. `php bin/console doctrine:migrations:status --env=dev`

This proves the full **migration chain** works against a real MySQL 8.4 instance
(downstream of the schema-tool-based test schema).

### 6.3 `docs.yml` — Docs build & GitHub Pages deploy

**Triggers** — push to `main`/`dev` and PRs against `main`/`master`/`dev`, on
`docs/**`, `README*.md`, `scripts/build-docs.sh`, `scripts/translate-docs.py`,
`mkdocs.yml`, and the workflow file itself.

| Job | Runs on | Action |
|-----|---------|--------|
| `build` | pull_request only | `pip install mkdocs-material deep-translator` → `bash scripts/build-docs.sh` (bilingual site build) |
| `deploy` | push only | same build, then `peaceiris/actions-gh-pages` publishes `./site` to the `gh-pages` branch |

`scripts/translate-docs.py` + `build-docs.sh` produce the bilingual
(English/Chinese) mkdocs site — do not bypass it by committing generated files.

---

## 7. Local vs CI Database

| Context | Database | How it is isolated |
|---------|----------|--------------------|
| `tests` job (CI) | PostgreSQL 16 (`app_test_1..4`) | one DB per ParaTest token |
| Local parallel run | SQLite per worker (`var/test_paratest_{pid}.db`) | one file per process |
| Local serial run | SQLite (`var/test.db`) | single file |

`tests/bootstrap.php` decides: if `PARATEST=1` and no explicit `DATABASE_URL` template
is provided, it switches each worker to its own `var/test_paratest_{pid}.db` and eagerly
creates the schema. When CI provides `PARATEST_DATABASE_URL_TEMPLATE`, it substitutes
the worker token instead. An explicit `DATABASE_URL` from the real environment is never
clobbered by the sqlite fallback.

Coverage is always measured with **PCOV** (`PCOV_ENABLED=1` / `coverage: pcov`).
Xdebug-based coverage (`XDEBUG_MODE=coverage`) works locally but is not used in CI.

---

## 8. Definition of Done

A change is done when, on top of the PR checklist:

1. `composer phpstan` — Level 8 clean (no new baseline entries without justification).
2. `composer rector:types:check` — no required type-rule changes.
3. `./vendor/bin/phpunit` (or `PARATEST=1 ./vendor/bin/paratest --processes 8 --runner WrapperRunner`) — green, coverage ≥ 90% Lines.
4. Migration chain verified against MySQL (CI `migrations.yml` job).
5. OpenAPI `#[OA\*]` attributes updated for any endpoint change.
6. User-facing strings have translation keys (en, zh, zh_Hant, ja).