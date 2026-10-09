# Database & Migrations

Everything about how crud-skeleton persists data: Doctrine configuration, the schema
conventions every entity follows (UUID identity, timestamps, JSON columns, money), and the
migration workflow you use day-to-day.

---

## 1. Configuration

### 1.1 `config/packages/doctrine.yaml`

```yaml
doctrine:
    dbal:
        url: '%env(resolve:DATABASE_URL)%'
        profiling_collect_backtrace: '%kernel.debug%'
    orm:
        validate_xml_mapping: true
        naming_strategy: doctrine.orm.naming_strategy.underscore_number_aware
        identity_generation_preferences:
            Doctrine\DBAL\Platforms\PostgreSQLPlatform: identity
        auto_mapping: true
        mappings:
            App:
                type: attribute
                is_bundle: false
                dir: '%kernel.project_dir%/src'
                prefix: 'App'
                alias: App
        dql:
            string_functions:
                regexp: DoctrineExtensions\Query\Mysql\Regexp
            numeric_functions:
                rand: DoctrineExtensions\Query\Mysql\Rand
            datetime_functions:
                date_format: DoctrineExtensions\Query\Mysql\DateFormat
```

- **Attribute mapping for the entire `src/` tree** with prefix `App`. Entities are plain
  PHP classes anywhere under `src/<Module>/Entity/` annotated with `#[ORM\Entity]`,
  `#[ORM\Table]`, etc. — no YAML/XML/annotation mapping, and each new module needs zero
  mapping config.
- **Naming strategy** `underscore_number_aware` — `allocationKey` → `allocation_key`,
  `sourceItemSnapshot` → `source_item_snapshot`, and numeric suffixes are kept (`api2` stays
  distinct from `api`).
- `auto_mapping: true` maps the whole `App` namespace; `controller_resolver.auto_mapping`
  is left **off** so controllers are resolved explicitly.
- **DBAL** URL comes from `DATABASE_URL`:

  | Environment | `DATABASE_URL` |
  |-------------|----------------|
  | Compose (`app`) | `mysql://app:***@database:3306/app?serverVersion=8.0&charset=utf8mb4` |
  | Local dev (native PHP) | any of the commented examples in `.env` / `README.md`, e.g. `mysql://app:!ChangeMe!@127.0.0.1:3306/app?serverVersion=8.0&charset=utf8mb4` |
  | Tests | `sqlite:///%kernel.project_dir%/var/test.db` (`.env.test`) |
  | CI (migration chain) | `mysql://app:app@127.0.0.1:3306/crud_skeleton_test?serverVersion=8.4&charset=utf8mb4` |

  Tests run on SQLite (per-process files under paratest so parallel workers never race);
  production and the migration CI use MySQL.

### 1.2 `config/packages/doctrine_migrations.yaml`

```yaml
doctrine_migrations:
    migrations_paths:
        'DoctrineMigrations': '%kernel.project_dir%/migrations'
    enable_profiler: false
```

Migration classes live in the project root `migrations/`, in the `DoctrineMigrations`
namespace (deliberately **not** autoloadable, so they are never loaded except when running
a migration command).

---

## 2. Migration files

Each migration is a `final class Version⟨YYMMDDHHMMSS⟩ extends AbstractMigration` (generated
timestamps use the date, e.g. `Version20250620000000`, `Version20260725010000`,
`Version20260819000000`). It implements `getDescription()` plus `up()` / `down()`:

```php
namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260725020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Trade integration outbox table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE trade_outbox_message (
            id BIGINT AUTO_INCREMENT NOT NULL,
            event_id VARCHAR(36) NOT NULL,
            topic VARCHAR(120) NOT NULL,
            aggregate_type VARCHAR(80) NOT NULL,
            aggregate_id VARCHAR(64) NOT NULL,
            payload JSON NOT NULL,
            occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            available_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
            published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
            attempts INT NOT NULL,
            last_error LONGTEXT DEFAULT NULL,
            UNIQUE INDEX uniq_trade_outbox_event_id (event_id),
            INDEX idx_trade_outbox_unpublished_available (published_at, available_at),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE trade_outbox_message');
    }
}
```

Current chain (excerpt):

| Migration | Contains |
|-----------|----------|
| `Version20250514000000` | identity `users`, `content` |
| `Version20250516000000` | common CMS: category, tag, content_tag, media, page, comment, setting |
| `Version20250517000000` | `wallet`, `wallet_transaction` |
| `Version20250620000000` | trade product, specification, order, order_item |
| `Version20260624223701` | payment `invoice`, `wechat_user`, **`messenger_messages`** |
| `Version20260725010000` | store, store_membership, store_order, store_consumed_event, store_outbox_message |
| `Version20260725020000` | trade_outbox_message |
| `Version20260726000000` | inventory material/stock/recipe/reservation/ledger + inbox/outbox + store_trade_order_cancellation |
| `Version20260819000000` | settlement_rule, settlement_rule_version, settlement_plan, settlement_allocation, settlement_consumed_event, settlement_outbox_message |
| `Version20260819000001` | + `source_item_id`, `source_item_snapshot` on settlement_allocation |

---

## 3. Schema conventions

### 3.1 Global rules

- **Engine / charset:** every table is `InnoDB`, character set `utf8mb4`,
  collation `utf8mb4_unicode_ci`.
- **Surrogate key:** auto-increment `id` — `INT` for most tables, `BIGINT` where volume or
  event throughput is high (ledger, outbox/inbox tables, `messenger_messages`).
  Declared in attributes: `#[ORM\Id] #[ORM\GeneratedValue] #[ORM\Column(type: 'bigint')]`.
- **`datetime_immutable` everywhere** for time columns, with the `COMMENT
  '(DC2Type:datetime_immutable)'` marker in generated SQL so the DBAL/ORM types line up on
  both MySQL and SQLite.
- **Named constraints:** `UNIQUE INDEX uniq_<table>_<cols>`, `INDEX
  idx_<table>_<purpose>`, `CONSTRAINT FK_<TABLE>_<COL>`. The attribute declarations use the
  same names, so `doctrine:migrations:diff` produces clean no-op diffs.
- **FK `ON DELETE` policy:** `RESTRICT` for ownership relationships that must not silently
  disappear (store→store_order, inventory FK chains), `CASCADE` for child collections
  (recipe lines, reservation lines, settlement allocations), `SET NULL` for optional
  cross-entity refs (trade_order.user).

### 3.2 UUID identity

Every **aggregate** carries a business-facing `uuid VARCHAR(36) NOT NULL UNIQUE`, generated
**application-side** at construction with `App\Core\Utils\UUID::v4()` — never by the
database, and never exposed as the numeric `id`:

```php
#[ORM\Entity(repositoryClass: StoreOrderRepository::class)]
#[ORM\Table(name: 'store_order')]
#[ORM\UniqueConstraint(name: 'uniq_store_order_uuid', columns: ['uuid'])]
class StoreOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    public function __construct(/* ... */)
    {
        $this->uuid = UUID::v4();
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

- UUIDs are the stable key **across** module boundaries: `store_uuid`, `trade_order_uuid`,
  `store_order_uuid`, `reservation_id`, `plan_uuid`, `allocation_uuid` appear as plain
  `VARCHAR(36)` FK-style references that are deliberately *not* real FKs, because the
  referenced row may live in another module's aggregate.
- UUIDs are what appear in event envelopes and API payloads; the numeric `id` is internal.
  Inventory and the event handlers re-validate UUID format with the strict v4 regex
  `^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i`.

### 3.3 Timestamps and lifecycle callbacks

Two timestamp conventions, both using `datetime_immutable`:

- **`created_at`** — set once in the constructor (`new \DateTimeImmutable()`).
- **`updated_at`** — nullable in most modules (Trade, Store, Inventory); **NOT NULL** in
  Settlement (`SettlementPlan`, `SettlementAllocation`).

Timestamps are maintained by **`touch()`**, wired to the ORM lifecycle callbacks:

```php
#[ORM\Entity(...)]
#[ORM\HasLifecycleCallbacks]
class SettlementAllocation
{
    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}
```

Trade's `Order` shows the other pattern — no `#[ORM\PreUpdate]`; every mutator calls
`$this->touch()` explicitly, plus a `#[ORM\PrePersist]` that initialises `createdAt`:

```php
#[ORM\PrePersist]
public function prePersist(): void
{
    if (!isset($this->createdAt)) {
        $this->createdAt = new \DateTimeImmutable();
    }
}
```

Rich modules add domain timestamps alongside the audit ones (`paid_at`, `cancelled_at`,
`accepted_at`, `rejected_at`, `expires_at`, `released_at`, `posted_at` …).

### 3.4 JSON columns

Schemaless payloads, snapshots, and metadata are stored as `JSON` with `#[ORM\Column(type:
'json')]` mapping to an `array` property (nullable when the column is nullable):

```php
/** @var array<string, mixed>|null */
#[ORM\Column(type: 'json', nullable: true)]
private ?array $metadata = null;
```

Typical uses: `trade_order.metadata`, `trade_order_item.spec_snapshot` /
`product_snapshot`, `store_order.order_snapshot`, `inventory_reservation_line.
source_specification_uuids`, and the Settlement proof-of-work columns
`settlement_plan.context_snapshot / funding_snapshot / rule_snapshot /
calculation_trace` and `settlement_allocation.recipient_snapshot /
source_item_snapshot`. Because these tables carry JSON in MySQL, they cannot use standard
indexes on those columns (except the `utc`-style functional indexing); query them through
application-side fields that are also stored as plain columns (e.g. `context_hash`,
`funding_fingerprint`).

### 3.5 Money: integer cents vs. settlement quantum

There are **two** money representations, and it is important not to mix them.

**Classic commerce money (integer minor units / cents).** `trade_order.total_amount`,
`trade_specification.price`, `trade_order_item.unit_price / price / cost / profit`,
`payment_invoice.amount / refunded_amount`, `store_order.total_amount` are all
`BIGINT` columns storing *cents* with `options: ['default' => 0]`:

```php
#[ORM\Column(type: 'bigint', options: ['default' => 0])]
private int $totalAmount = 0;
```

Helpers convert to major units for display: `Order::getTotalAmountAsFloat()` is
`$this->totalAmount / 100`; `Order::__toString()` prints `$this->totalAmount / 100`.
31-bit `INT` is avoided deliberately — cents can exceed 2¹⁵·sometimes and sums overflow
`INT`; `BIGINT` keeps arithmetic safe.

**Settlement quantum money (arbitrary-precision).** Settlement must distribute a funding
amount exactly, so it stores **canonical base-10 integer strings** at a fixed scale
(default **18**), in `VARCHAR(128)` columns, and does arithmetic with **brick/math**
(`BigInteger`) rather than floats or ints:

| Column pattern | Example column | Meaning |
|----------------|---------------|---------|
| `…_quantum` | `funding_amount_quantum`, `exact_amount_quantum` | exact integer at `calculation_scale` (quantum / 10^scale units) |
| `posting_amount` | `posting_amount`, `funding_posting_amount` | amount converted to `posting_scale` (default **2** = minor units) |

```php
// SettlementAllocation
#[ORM\Column(name: 'exact_amount_quantum', type: 'string', length: 128)]
private string $exactAmountQuantum;

#[ORM\Column(name: 'posting_amount', type: 'string', length: 128)]
private string $postingAmount;

#[ORM\Column(name: 'posting_scale', type: 'smallint')]
private int $postingScale;
```

- `App\Settlement\Service\Money\QuantumAmount` is the value object: non-negative exact
  integer `quantum`, `scale` (0..18), uppercased `currency`; `toPostingMinor()` floors
  toward zero and `postingRemainder()` keeps the discarded fraction.
- `App\Settlement\Service\Money\AllocationRoundingService::distribute()` performs
  largest-remainder allocation of the funding to posting units with deterministic tie-break
  (descending remainder, then ascending `allocationKey`), guaranteeing the residual is
  always fully assigned or it throws.
- `brick/math` (`^0.19.1`) is in `composer.json` and imported directly in these services.

**Quantities (not money but same idea).** Inventory stores quantities as `NUMERIC(20,6)`
and the `App\Inventory\Service\Quantity` value object operates on decimal strings
(`'12.500000'`) with a fixed 6-decimal scale, rejecting anything that would not fit the
column (max 14 integer digits) — see `Quantity::normalize()`.

### 3.6 Unique constraints and indexes

Uniqueness rules are declared **twice** — on the entity and in the migration — with the
same names:

```php
#[ORM\UniqueConstraint(name: 'uniq_store_order_trade_order_uuid', columns: ['trade_order_uuid'])]
#[ORM\Index(name: 'idx_store_order_store_status_created', columns: ['store_id', 'operational_status', 'created_at'])]
```

Notable business uniques:

| Constraint | Columns | Why |
|------------|---------|-----|
| `uniq_<agg>_uuid` | `(uuid)` | every aggregate's stable external key |
| `uniq_store_order_trade_order_uuid` | `(trade_order_uuid)` | one store order per trade order |
| `uniq_inventory_reservation_store_order` | `(store_order_uuid)` | one reservation per store order |
| `uniq_inventory_stock_store_material` | `(store_uuid, material_id)` | one stock row per store+material |
| `uniq_store_membership_store_user` | `(store_id, user_uuid)` | one membership per user in a store |
| `uniq_settlement_plan_funding` | `(funding_id)` | one plan per funding event |
| `uniq_settlement_plan_source` | `(source_type, source_id, funding_kind)` | one plan per source fact |
| `uniq_settlement_allocation_plan_key` | `(plan_uuid, allocation_key)` | one allocation per rule key |
| `uniq_settlement_allocation_posting_key` / `…_reversal_key` | `(posting_idempotency_key)` / `(reversal_idempotency_key)` | idempotent voucher post/reverse |
| `uniq_inventory_ledger_operation` | `(type, reference_id, store_uuid, material_id)` | ledger idempotency |
| `uniq_trade_outbox_event_id` (and the other outbox/inbox tables) | `(event_id)` | transactional-outbox/inbox dedup |

Indexes are added for the query patterns the application actually runs:
`(status, <time>)` list screens, `(published_at, available_at)` outbox polling,
`(status, next_attempt_at)` settlement retries, and `(store_uuid, material_id, created_at)`
ledgers.

---

## 4. Migration workflow

### 4.1 Standard loop

```bash
# 1. Change an entity (add/remove attributes, constraints, indexes)

# 2. Generate the migration from the entity metadata diff
php bin/console doctrine:migrations:diff

# 3. Review the generated SQL (in migrations/Version<timestamp>.php) — rename it to a
#    descriptive Date(Version20260819…) if you prefer, and give it a getDescription()

# 4. Apply
php bin/console doctrine:migrations:migrate

# 5. Verify
php bin/console doctrine:migrations:status
```

Inside Docker these become `docker compose exec app php bin/console …`.

Useful commands:

| Command | Purpose |
|---------|---------|
| `doctrine:migrations:migrate` | apply all pending migrations (`--no-interaction` in CI/scripts) |
| `doctrine:migrations:diff` | generate a migration from entity↔schema differences |
| `doctrine:migrations:status` | show the current version and what is available/executed |
| `doctrine:migrations:list` | list all migrations with their description |
| `doctrine:migrations:generate` | create an empty skeleton to hand-write SQL |
| `doctrine:migrations:execute` | run/rollback a specific version (`--down` for the inverse) |

### 4.2 Rules of thumb

- **Never edit a migration that has already been merged and applied** since it may exist in
  DBs you cannot see. Add a *new* migration instead.
- Keep a hand-written `getDescription()` so `doctrine:migrations:list` and `status` are
  readable.
- Always provide a `down()` that reverses `up()` (drop the tables/columns/constraints you
  added in the reverse order — drop FKs before their tables).
- `doctrine:migrations:diff` needs a reachable database matching the configured
  `DATABASE_URL`. In CI and fresh dev setups, run `doctrine:migrations:migrate` first to
  create the baseline.
- When a migration affects the outbox/inbox or settlement tables, remember the constraint
  naming and `ON DELETE` policy conventions above so the CI schema check stays clean.
- Tests run on SQLite, so keep generated SQL portable: avoid MySQL-only syntax in manual
  migration edits.

---

## 5. CI migration chain (MySQL 8.4)

`.github/workflows/migrations.yml` runs the **`mysql-migration-chain`** job on
`ubuntu-latest`. It triggers on push/PR against `main`/`master` when `migrations/`,
`config/`, `bin/`, `composer.json`, `composer.lock`, `symfony.lock`, or the workflow itself
changes.

What it does:

1. Boots a **MySQL 8.4** service container (`crud_skeleton_test` DB, `app`/`app` user).
2. Checks out the code and installs PHP 8.4 with the `pdo_mysql` extension via
   `shivammathur/setup-php`.
3. Runs `composer install`.
4. Runs `php bin/console doctrine:migrations:migrate --no-interaction --env=dev` against the
   MySQL service via `DATABASE_URL` — this exercises the **full migration chain start to
   finish** on the production engine, which SQLite-based unit/functional tests never do
   (JSON `(DC2Type:…)` remarks, `BIGINT` auto-increment, unsigned/inline index options all
   behave differently on MySQL).
5. Verifies `php bin/console doctrine:migrations:status --env=dev` to confirm the schema is
   fully migrated.

This is the gate that catches broken SQL that only appears on MySQL. If your PR touches the
schema, wait for this job to pass.

---

## 6. Reference: one entity, end to end

A representative attribute-mapped entity combining UUID identity, lifecycle-callback
timestamps, JSON, and bigint money — `App\Store\Entity\StoreOrder` (table `store_order`):

```php
#[ORM\Entity(repositoryClass: StoreOrderRepository::class)]
#[ORM\Table(name: 'store_order')]
#[ORM\UniqueConstraint(name: 'uniq_store_order_uuid', columns: ['uuid'])]
#[ORM\UniqueConstraint(name: 'uniq_store_order_trade_order_uuid', columns: ['trade_order_uuid'])]
#[ORM\Index(name: 'idx_store_order_store_status_created', columns: ['store_id', 'operational_status', 'created_at'])]
class StoreOrder
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 36, unique: true)]
    private string $uuid;

    #[ORM\ManyToOne(targetEntity: Store::class)]
    #[ORM\JoinColumn(name: 'store_id', referencedColumnName: 'id', nullable: false, onDelete: 'RESTRICT')]
    private Store $store;

    #[ORM\Column(name: 'total_amount', type: 'bigint')]
    private int $totalAmount;                         // cents

    /** @var array<string, mixed> */
    #[ORM\Column(name: 'order_snapshot', type: 'json')]
    private array $orderSnapshot;

    #[ORM\Column(name: 'operational_status', type: 'string', length: 40)]
    private string $operationalStatus = self::STATUS_PENDING_VALIDATION;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(Store $store, string $tradeOrderUuid, /* … */ )
    {
        $this->uuid = UUID::v4();
        $this->store = $store;
        $this->createdAt = new \DateTimeImmutable();
    }

    private function touch(): self { $this->updatedAt = new \DateTimeImmutable(); return $this; }
}
```