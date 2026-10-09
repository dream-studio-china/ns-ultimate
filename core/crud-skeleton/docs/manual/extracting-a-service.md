# Extracting a Service from the Monolith

CRUD Skeleton is a **single modular monolith** — one Symfony app, one composer
project, one `src/` tree with namespaced modules (`App\Trade`, `App\Wallet`,
`App\Settlement`, ...). It is **not** a multi-app monorepo. This guide explains
how to take one module and turn it into an independent service/app, using the
existing module boundaries as the blueprint.

The good news: the codebase was designed so this is possible. The
Settlement ↔ Wallet relationship is a working example of what a service
boundary looks like. This guide formalizes the process.

---

## 1. Pre-extraction rules

Before any code moves, the module must already be boundary-clean. The following
three rules are what make extraction mechanical instead of surgical.

### 1.1 No cross-module Doctrine associations

Entities must **never** hold an ORM association to an entity in another
module. There are no `ManyToOne`/`OneToMany` properties pointing outside the
module, and no foreign keys between tables owned by different modules.

Check for violations:

```bash
# In the module you intend to extract, grep for cross-module entity imports
rg "use App\\\\(Trade|Payment|Store|Inventory|Identity)\\\\Entity" src/Settlement
```

Every table in the target module must be self-contained. Cross-module lookup
is done by hand via **UUID or scalar references**, never via join keys.

### 1.2 UUID references instead of object/ID linkage

Foreign identity inside an entity is a plain string column holding the remote
aggregate's UUID, not a DB-level foreign key and not an object reference.

`src/Settlement/Entity/SettlementAllocation.php` is the canonical example:

```php
#[ORM\Column(name: 'uuid', type: 'string', length: 36)]
private string $uuid;

#[ORM\Column(name: 'plan_uuid', type: 'string', length: 36)]
private string $planUuid;

#[ORM\Column(name: 'rule_version_uuid', type: 'string', length: 36, nullable: true)]
private ?string $ruleVersionUuid;
```

- Local rows keep integer surrogate keys (`id`) plus a UUID.
- Remote identity is stored as a `string(36)` UUID column, indexed for lookup.
- Never assume a referenced row exists; the service layer re-validates.

Representing the matter with a type/id pair is the pattern used by the
Settlement contracts — `SettlementSubject` is `type : id` as strings:

```php
final readonly class SettlementSubject
{
    public function __construct(
        public string $type,
        public string $id,
        public string $version,
    ) { }
}
```

### 1.3 Scalar contracts across boundaries

Modules interact through **interfaces and immutable DTOs** — never by
instantiating another module's entities or calling another module's services
directly. Cross-module contracts live in `Contract/` (read-only value objects)
and `Port/` (interfaces the consumer implements or injects).

Settlement defines the boundary it needs:

- `src/Settlement/Contract/*` — `ConfirmedAllocation`, `PostedAllocation`,
  `ReversalRequest`, `VoucherPostingReceipt`, `SettlementSubject`, ... all
  `final readonly class` DTOs containing only scalars.
- `src/Settlement/Port/SettlementVoucherPort.php` — an interface owned by
  Settlement, implemented by the host (Wallet):

```php
interface SettlementVoucherPort
{
    public function post(ConfirmedAllocation $allocation): VoucherPostingReceipt;
    public function reverse(PostedAllocation $allocation, ReversalRequest $request): VoucherPostingReceipt;
}
```

The implementation lives in the consumer's own namespace:
`src/Wallet/Integration/Settlement/WalletSettlementVoucherPort.php`. The host
module owns the implementation; the consuming module owns the interface.

**Consequence of rule 1.3:** a module that only touches `Contract/*` and
`Port/*` from other modules can be lifted out and pointed at a different host
implementation (an HTTP client, another service, a fake in tests) without
changing its own code.

---

## 2. Choose the module and map its boundary

`src/Settlement` is the best candidate for a first extraction because it is the
most self-contained. Its layout is the template:

```
src/Settlement/
├── Command/          # app:settlement:* console commands (scheduler loop)
├── Context/          # SettlementContextResolver registry pattern
├── Contract/         # immutable cross-module DTOs (scalar-only)
├── Controller/       # API controllers (Manage/ namespace)
├── Entity/           # Doctrine entities — self-contained tables
├── Exception/        # module exception hierarchy
├── Integration/      # Fake/ and Fixture/ test doubles
├── Message/          # Messenger message classes (async)
├── MessageHandler/   # MessageHandler::__invoke consumers
├── Port/             # interfaces the module depends on (implemented elsewhere)
├── Repository/       # Doctrine repositories
├── Resources/config/ # services_settlement.yaml (service wiring)
└── Service/          # domain services + *ServiceInterface aliases
```

Other modules follow the same shape (Wallet, Trade, Inventory, Store, ...),
each with the same sub-namespaces. The only differences are details — e.g.
Wallet splits `Service/` into `Deposit/`, `Withdraw/`, `Transfer/`,
`Payment/` feature folders and has App `Controller/` and Manage `Controller/`.

### Map the dependencies

For each module, two lists:

1. **What it depends on from others** — contracts, ports, and messages it
   imports. For Settlement these are **none**; it only depends on its own
   `Contract/`/`Port/`. (Wallet is the opposite: it implements
   `SettlementVoucherPort` and consumes `App\Settlement\Contract\*`.)
2. **What others depend on from it** — who implements its ports
   (`App\Wallet\Integration\Settlement\WalletSettlementVoucherPort`), who
   consumes its contracts, and which of its messages are routed in
   `config/packages/messenger.yaml`.

A module whose "depends on others" list is empty (or only contracts) is
extractable as-is. A module that a port points at (Settlement ← Wallet) keeps
its domain and swaps the port implementation at wiring time.

### Extraction checklist per module

- [ ] No `use App\...\Entity` cross-module imports inside the module
- [ ] All cross-module references are `Contract/*` and `Port/*`
- [ ] Every outbound event is an `OutboxMessage` row + Messenger message
- [ ] Every inbound event is consumed from an inbox/consumed-event table
- [ ] Command names follow `app:{module}:{...}` and are listed in `compose.yaml`
- [ ] `Resources/config/services_{module}.yaml` holds all module wiring
- [ ] `config/services.yaml` `App\|:` autowiring excludes only infra classes

---

## 3. Create the app skeleton

Create a new standalone Symfony app (or a new composer package in a monorepo
top-level) that will host the module:

```bash
composer create-project symfony/skeleton settlement-service
cd settlement-service
composer require symfony/orm-pack symfony/messenger symfony/translation
```

What you are reproducing, in order:

1. `src/Kernel.php` — the MicroKernel bootstrap.
2. `composer.json` — the autoload PSR-4 map: `"App\\": "src/"`.
3. `config/services.yaml` — imports the module config + global autowiring.
4. Module config wiring (see [Wiring](#6-wiring)).

The skeleton is deliberately minimal: no `templates/`, no webpack, nothing the
API skeleton had beyond the pieces the module actually uses.

---

## 4. Move the code

Physically move the module's directory into the new app:

```bash
# Inside the new app
mkdir -p src
mv ../crud-skeleton/src/Settlement src/Settlement
```

Because modules are namespace-isolated (`App\Settlement\...`), the namespace
stays valid with zero renames. Keep it `App\Settlement` unless you want to
rename the root namespace of the new service; if you do, a namespace rename is
a mechanical find/replace.

### Move the parts that must follow

| Ship with the module                    | Example (Settlement)                              |
|-----------------------------------------|---------------------------------------------------|
| Entities + repositories                 | `Entity/*`, `Repository/*`                        |
| Domain services                         | `Service/*`, `Service/Money/*`                    |
| Service interfaces + aliases            | `*ServiceInterface` + `services_settlement.yaml`  |
| Controllers                             | `Controller/Manage/*`                             |
| Console commands                        | `Command/PublishOutboxCommand.php`, `RequeueDuePostingCommand.php` |
| Messages + handlers                     | `Message/*`, `MessageHandler/*`                   |
| Contracts & ports                       | `Contract/*`, `Port/*`                            |
| Test doubles                            | `Integration/Fake/*`, `Integration/Fixture/*`     |
| Module migration files                  | `migrations/Version2026...*.php` (Settlement tables) |

### Leave behind

- **Port implementations owned by other modules.** Wallet's
  `WalletSettlementVoucherPort` stays in the monolith (or becomes a small
  adapter in the extraction upstream). Settlement never imports it directly;
  it depends on the interface `App\Settlement\Port\SettlementVoucherPort`.
- **Any global autowiring that is not module-specific** (`config/services.yaml`
  `App\:` block, `_instanceof` tag rules). These move only in the form the new
  app needs for its own modules.

---

## 5. Baseline migration

The module's tables currently live inside the monolith's single migration
history. Before extraction, produce a **baseline** so the new service can
start with only its own schema.

1. **Squash** all migrations that create/modify the module's tables into one
   new `Version{time}BaselineSettlement.php` inside the new app:

   ```php
   final class Version20260820000000BaselineSettlement extends AbstractMigration
   {
       public function up(Schema $schema): void
       {
           // CREATE TABLE settlement_plan (...)
           // CREATE TABLE settlement_rule (...)
           // CREATE TABLE settlement_allocation (...)
           // ... module tables only
       }
   }
   ```

2. **Remove** the module's table statements from the monolith migration
   history *after* the cutover migration that exports the data. Keep the
   monolith's history linear: the extraction release ships one migration that
   migrates data out, the new service ships one baseline migration.

3. Set `version_control` on from the first deploy of the new service:
   `doctrine:migrations:version --add --all` after the baseline is applied, or
   `latest_as_of` in the migrations config, so the standalone history matches
   the baked-in schema.

4. Validate with the same job pattern as `.github/workflows/migrations.yml`:
   apply the chain from scratch against a fresh MySQL 8.4 and assert
   `doctrine:migrations:status` reports the baseline as the latest.

## 6. Wiring

Reproduce the module's service wiring in the new app. Every module owns one
config file that already contains its dependency graph:

- `src/Core/Resources/config/services.yaml`
- `src/Identity/Resources/config/services_identity.yaml`
- `src/Settlement/Resources/config/services_settlement.yaml`
- ... one per module

`src/Settlement/Resources/config/services_settlement.yaml` shows the pattern:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Settlement\Service\SettlementServiceInterface:
        alias: App\Settlement\Service\SettlementService

    # ...

    App\Settlement\Port\ClockInterface:
        alias: App\Settlement\Service\SystemClock

    App\Settlement\Port\SettlementVoucherPort:
        alias: App\Wallet\Integration\Settlement\WalletSettlementVoucherPort   # <-- host impl

    when@test:
        services:
            App\Settlement\Integration\Fake\InMemorySettlementVoucherPort:
                public: true
            App\Settlement\Port\SettlementVoucherPort:
                alias: App\Settlement\Integration\Fake\InMemorySettlementVoucherPort
```

Import it from `config/services.yaml`:

```yaml
imports:
    - { resource: '../src/Settlement/Resources/config/services_settlement.yaml', ignore_errors: true }
```

### Re-point the ports

In the monolith, `SettlementVoucherPort` is aliased to
`App\Wallet\Integration\Settlement\WalletSettlementVoucherPort`. In the new
service, the port must resolve to an implementation you ship with the service:

- **In-process** during migration: keep the Wallet adapter if you also move it
  into the new app (it pulls in Wallet entities/services — de-facto a Wallet
  extraction too).
- **Independent service**: implement the port against the new service's own
  storage or an outbound HTTP call to the Wallet service:

  ```php
  final class HttpSettlementVoucherPort implements SettlementVoucherPort
  {
      public function post(ConfirmedAllocation $allocation): VoucherPostingReceipt { /* POST to wallet.svc /api/... */ }
      public function reverse(PostedAllocation $allocation, ReversalRequest $request): VoucherPostingReceipt { /* ... */ }
  }
  ```

  and change the alias in `services_settlement.yaml`. No Settlement code
  changes — that is the entire point of the port indirection.

### Messaging wiring

Move the module's entries in `config/packages/messenger.yaml` to the new app
(e.g. `routing: App\Settlement\Message\SettlementFundingConfirmedMessage: async`).
In the monolith, delete those routes and, if the module's messages are now
delivered out-of-process, replace the remaining consumers with integration
points (webhook/HTTP dispatch or a shared transport).

### Scheduler and console commands

The commands already exported (`app:settlement:...`) are run by the
`scheduler` service in `compose.yaml`. In the new deployment:

- Remove the Settlement lines from the monolith's `scheduler` loop.
- Add a `scheduler` (or host cron) in the new service that runs
  `app:settlement:outbox:publish` and
  `app:settlement:allocations:requeue-due` on the same loop pattern:

  ```bash
  while :; do
    php bin/console app:settlement:outbox:publish --no-interaction
    php bin/console app:settlement:allocations:requeue-due --no-interaction
    sleep "${OUTBOX_PUBLISH_INTERVAL:-5}"
  done
  ```

## 7. Testing

The module already ships its own test doubles, so the new service can test the
module in complete isolation:

- `App\Settlement\Integration\Fake\InMemorySettlementVoucherPort` — implements
  the host port with no database.
- `App\Settlement\Integration\Fixture\FundingSnapshotContextResolver` — a
  tagged `settlement.context_resolver` fixture for integration tests.

Reuse the monolith's testing conventions (see `docs/design/system-contracts.md`
§7 and `docs/testing/crud-skeleton-production/`):

| Contract                                    | Requirement                                    |
|---------------------------------------------|------------------------------------------------|
| Coverage                                    | ≥ 90% line coverage, enforced in CI            |
| Test DB                                     | Clean schema per test, built by schema tool    |
| Unit tests                                  | `PHPUnit\Framework\TestCase` — no kernel       |
| Kernel tests                                | `IntegrationKernelTestCase` — booted kernel    |
| Web tests                                   | `IntegrationWebTestCase` — full HTTP cycle     |
| `when@test` wiring                          | Ports/fakes + `when@test` sections in the module config |

Copy the relevant test files from `tests/UnitTest/Settlement` and
`tests/Integration` that exercise the module, then delete them from the
monolith. Static analysis stays identical:

```bash
composer phpstan            # Level 8, isolated SQLite DATABASE_URL
composer rector:types:check # Rector type rules dry-run
```

## 8. CI

The monolith's CI is three jobs in `.github/workflows/*`. Re-produce them in
the new repo:

| Job            | Source workflow      | Command                                        |
|----------------|----------------------|------------------------------------------------|
| Static analysis | `ci.yml` → `phpstan` | `composer phpstan` (PHP 8.4, SQLite DB URL)   |
| Rector         | `ci.yml` → `rector`  | `composer rector:types:check` (SQLite DB URL) |
| Tests          | `ci.yml` → `tests`   | Paratest with coverage ≥ 90%, PostgreSQL 16   |
| Migrations     | `migrations.yml`     | Fresh MySQL 8.4 chain apply + status          |

Reference `DATABASE_URL` values used by CI:

```dotenv
# phpstan / rector (no DB server needed)
DATABASE_URL="sqlite:///%kernel.project_dir%/var/phpstan.db"

# tests matrix
DATABASE_URL="postgresql://app:app_secret@127.0.0.1:5432/app_test?serverVersion=16&charset=utf8"
PARATEST_DATABASE_URL_TEMPLATE="postgresql://app:app_secret@127.0.0.1:5432/app_test_{token}?serverVersion=16&charset=utf8"

# migrations
DATABASE_URL="mysql://app:app@127.0.0.1:3306/...?serverVersion=8.4&charset=utf8mb4"
```

## 9. Docker

Re-use the existing `Dockerfile` and compose layout as the template for the new
service. The production picture after extraction:

```yaml
# monolith compose.yaml — Settlement lines removed from scheduler's loop,
# Settlement messages removed from messenger routing
scheduler:
  command:
    - /bin/sh
    - -ec
    - |
      while :; do
        php bin/console app:trade:outbox:publish --no-interaction
        php bin/console app:store:outbox:publish --no-interaction
        php bin/console app:inventory:outbox:publish --no-interaction
        php bin/console app:inventory:reservations:release-expired --no-interaction
        sleep "${OUTBOX_PUBLISH_INTERVAL:-5}"
      done
```

```yaml
# settlement-service compose.yaml — same shapes, own database volume
services:
  app:
    build: .                     # same Dockerfile style
    environment: &app-env
      APP_ENV: prod
      APP_SECRET: ${APP_SECRET:?required}
      DATABASE_URL: "mysql://...@settlement-db:3306/..."
      MESSENGER_TRANSPORT_DSN: ${MESSENGER_TRANSPORT_DSN:?required}   # shared/broker
    volumes:
      - ./var:/var/www/html/var   # JWT keys + cache, persisted

  worker:
    extends: { service: app }
    command: ["php", "bin/console", "messenger:consume", "async", "--time-limit=3600", "--memory-limit=256M", "--no-interaction"]

  scheduler:
    extends: { service: app }
    command:
      - /bin/sh
      - -ec
      - |
        while :; do
          php bin/console app:settlement:outbox:publish --no-interaction
          php bin/console app:settlement:allocations:requeue-due --no-interaction
          sleep "${OUTBOX_PUBLISH_INTERVAL:-5}"
        done

  nginx:
    image: nginx:alpine
    ports: ["${APP_PORT:-8081}:80"]      # separate port from the monolith
    volumes:
      - ./public:/var/www/html/public:ro
      - ./docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro
    healthcheck:
      test: ["CMD", "wget", "-q", "-O", "/dev/null", "http://localhost/health/ready"]

  settlement-db:
    image: mysql:${MYSQL_VERSION:-8.4}
    environment:
      MYSQL_DATABASE: ${MYSQL_DATABASE:-settlement}
      MYSQL_USER: ${MYSQL_USER:-app}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD:?required}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD:?required}
    volumes: [settlement_db_data:/var/lib/mysql]

  redis:
    image: redis:7-alpine

  mailer:
    image: axllent/mailpit

networks:
  internal:

volumes:
  settlement_db_data:
```

Key points carried over unchanged:

- The entrypoint (JWT key bootstrap + prod validation) and healthcheck
  endpoints (`/health/live`, `/health/ready`) move with the app — they live in
  `src/Core`, so copy `src/Core` (or at least the parts the module uses) with
  the extraction.
- `nginx` publishes on its own host port and health-checks through
  `/health/ready`.
- Cross-service messaging is the only genuinely new piece: the two services
  either share a transport (same `MESSENGER_TRANSPORT_DSN` broker) or the
  consuming service calls a port implementation via HTTP.
- Production rollout is additive: deploy the new service, migrate the data
  (one cutover migration on the monolith, one baseline on the new service),
  then remove the module's scheduler lines, messenger routes, and tables.

## Practice: Settlement vs Wallet

| Concern            | Settlement (extract first)                     | Wallet (currently the host)                          |
|--------------------|------------------------------------------------|------------------------------------------------------|
| Owns contracts     | `Contract/*`, `Port/SettlementVoucherPort`     | consumes `App\Settlement\Contract\*`                 |
| Implements ports   | —                                              | `Integration/Settlement/WalletSettlementVoucherPort` |
| DB tables          | `settlement_*` only, no FKs out                 | `wallet_*`, `voucher_*`, `transaction_*`, ...         |
| Cross-module refs  | UUID columns (`plan_uuid`, `rule_version_uuid`) | `deposit('settlement', ...)` + voucher fields         |
| Async edges        | outbox publish + `SettlementFundingConfirmedMessage`, `SettlementAllocationPostingMessage` | consumes settlement postings synchronously via port   |
| Test seams          | `InMemorySettlementVoucherPort`, `FundingSnapshotContextResolver` | — (it provides them)                                 |

Extracting Settlement = taking the domain, contracts, commands, and test
doubles, implementing `SettlementVoucherPort` as an HTTP/queue adapter, and
shipping its baseline migration + CI + compose. Because Wallet only ever touches
Settlement through `Contract/*` and `Port/SettlementVoucherPort`, the monolith
side needs no business-logic changes — only the alias in
`services_settlement.yaml` (which leaves the monolith) and the messenger routing
entries (which leave the monolith).

Extracting Wallet (a later step) then uses the identical recipe, with the extra
step that the module that hosted its port (Settlement) must now call it
remotely.