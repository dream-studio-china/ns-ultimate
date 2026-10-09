# Exchange Bundle Design

> The Exchange bundle (`src/ExchangeBundle/`) is a **collateral-backed points/currency economy**.
> It provides exchange rates, a conversion engine, liquidity pools, collateral pledges,
> point minting/issuance, user exchange (conversion) between units, and redemption — all
> against a market-maker-supervised pool. **STATUS: DESIGN ONLY — not implemented.**

---

## 1. Overview

The Exchange domain covers the full lifecycle of a custom, pool-backed unit (e.g. `POINT`):

```
Pledge          Market maker pledges collateral (e.g. 1 CNY) into a pool → issuance ceiling
Mint            POINT are minted up to the pledge ceiling (solvency-constrained)
Exchange        Users convert between units (CNY ↔ POINT, etc.) via the pool — zero-sum
Redemption      Users redeem POINT back to collateral currency → POINT burned, ceiling lowered
Market maker    Manages pledges, sets rates / spread, watches pool exposure
```

### 1.1 Scope

| In scope (design) | Out of scope (deferred) |
|---|---|
| Rate table (effective-dated, hybrid model) | Booking into `App\Wallet` ledger (P1+) |
| `CurrencyConverter` (bcmath, display-grade precision) | External rate feed / auto-sync |
| Pool, Pledge, Mint, Exchange, Redemption records | JPY / 0-decimal currencies |
| Market-maker policy (spread, limits) | Payment / Trade pricing integration |
| Zero-sum & solvency invariants | API surface (P3) |

### 1.2 Bundle Location & Naming

- Directory: `src/ExchangeBundle/`
- Namespace: `App\ExchangeBundle` (in-repo; extractable to a standalone composer package later by renamespacing)
- Bundle class: `ExchangeBundle` (extends `Symfony\Component\HttpKernel\Bundle\AbstractBundle`)

---

## 2. File Structure

```
src/ExchangeBundle/
|-- ExchangeBundle.php
|-- DependencyInjection/
|   |-- ExchangeBundleExtension.php
|-- Domain/
|   |-- Rate/
|   |   |-- Entity/ExchangeRate.php
|   |   |-- Repository/ExchangeRateRepository.php
|   |   |-- Service/ExchangeRateService.php
|   |-- Converter/
|   |   |-- Service/CurrencyConverter.php
|   |   |-- Service/CurrencyConverterInterface.php
|   |   |-- Exception/ExchangeRateNotFoundException.php
|   |-- Ledger/
|   |   |-- LedgerInterface.php                # host-app ledger abstraction
|   |   |-- LedgerException.php
|   |-- Pool/
|   |   |-- Entity/Pool.php
|   |   |-- Entity/PoolAccount.php             # per-currency balance within a pool
|   |   |-- Repository/PoolRepository.php
|   |   |-- Service/PoolService.php
|   |-- Pledge/
|   |   |-- Entity/Pledge.php
|   |   |-- Repository/PledgeRepository.php
|   |   |-- Service/PledgeService.php
|   |-- Mint/
|   |   |-- Entity/MintRecord.php
|   |   |-- Repository/MintRecordRepository.php
|   |   |-- Service/MintService.php
|   |-- Exchange/
|   |   |-- Entity/ExchangeRecord.php
|   |   |-- Repository/ExchangeRecordRepository.php
|   |   |-- Service/ExchangeService.php
|   |-- Redemption/
|   |   |-- Entity/RedemptionRecord.php
|   |   |-- Repository/RedemptionRecordRepository.php
|   |   |-- Service/RedemptionService.php
|   |-- Maker/
|   |   |-- Entity/MakerPolicy.php
|   |   |-- Repository/MakerPolicyRepository.php
|   |   |-- Service/MakerPolicyService.php
|-- Resources/config/services.yaml
|-- Tests/
|   |-- Unit/
|   |   |-- Converter/CurrencyConverterTest.php
|   |   |-- Service/PledgeServiceTest.php
|   |   |-- Service/MintServiceTest.php
|   |   |-- Service/ExchangeServiceTest.php
|   |   |-- Service/RedemptionServiceTest.php
|   |-- Integration/
|   |   |-- Rate/ExchangeRateRepositoryTest.php
|   |   |-- Pool/PoolServiceTest.php
```

---

## 3. Data Model

### 3.1 `ExchangeRate` — rate table (hybrid, effective-dated)

Single table holds BOTH anchor rows (base = configured base currency) and direct-pair overrides.

| Field | Type | Notes |
|-------|------|-------|
| `id` | int PK auto | |
| `base_currency` | string(10) | Anchor rows: `exchange.base_currency`; override rows: the `from` currency |
| `quote_currency` | string(10) | `CHECK base != quote` |
| `rate` | decimal(20,8) string | `CHECK rate > 0`; direction `1 base = rate quote` |
| `source` | string(50) null | `'manual'`, `'maker'`, `'feed'` (feed reserved) |
| `valid_from` | datetime_immutable | effective start |
| `valid_to` | datetime_immutable null | null = open-ended |
| `created_at` / `updated_at` | datetime_immutable | |

- Unique: `(base_currency, quote_currency, valid_from)`
- Index: `(base_currency, quote_currency, valid_from)`
- Write validation (via `ExchangeRateService::setRate`): rate > 0, base != quote, interval valid, and **no overlapping intervals** for the same pair.

### 3.2 `Pool` + `PoolAccount` — liquidity pool

| Entity | Purpose |
|--------|---------|
| `Pool` | A pool identity: `code` (unique), `name`, `status`, `makerPolicy` (owning market maker) |
| `PoolAccount` | One row per currency held by the pool: `pool`, `currency`, `balance` (minor units), `outstanding` (issued-but-not-yet-redeemed units) |

Balances are delegated to the host ledger via `LedgerInterface` (see §6); `PoolAccount` keeps the exchange-specific accounting (outstanding, ceiling).

### 3.3 `Pledge` — collateral pledge

| Field | Notes |
|-------|-------|
| `pool`, `pledgor` | pledgor is an opaque reference (string id/type) to keep the bundle host-agnostic |
| `collateral_currency`, `collateral_amount` | e.g. `CNY`, `1_0000` (minor units) |
| `coefficient` | decimal(20,8) — the anchored conversion coefficient (e.g. `100000000` = 1 CNY ⇒ 100,000,000 POINT) |
| `status` | `active` / `released` |
| `created_at` | |

Issuance ceiling from a pledge: `collateral_amount × coefficient` (for POINT direction).

### 3.4 `MintRecord` — issuance

| Field | Notes |
|-------|-------|
| `pledge`, `unit` | unit being minted (e.g. `POINT`) |
| `amount` | units minted |
| `reference_id` | unique — idempotency key |
| `created_at` | |

Constraint: `Σ minted(unit) − Σ redeemed(unit) ≤ Σ active pledges × coefficient`.

### 3.5 `ExchangeRecord` — user conversion

| Field | Notes |
|-------|-------|
| `pool`, `user` (opaque ref), `from` / `to` (currency), `amount`, `result` | all in minor units |
| `rate` | decimal(20,8) string — the rate actually applied |
| `rate_path` | `direct` / `inverse` / `cross` — for audit |
| `reference_id` | unique — idempotency key |
| `created_at` | |

### 3.6 `RedemptionRecord` — redemption

| Field | Notes |
|-------|-------|
| `pool`, `user` (opaque ref), `unit`, `amount`, `payout_currency`, `payout_amount` | |
| `reference_id` | unique |
| `created_at` | |

Redemption burns units and pays collateral out of the pool within available balances.

### 3.7 `MakerPolicy` — market maker policy

| Field | Notes |
|-------|-------|
| `maker` (opaque ref), `pool` | a market maker supervises a pool |
| `spread_bps` | spread in basis points applied on top of the mid rate |
| `fee_bps` | conversion fee in basis points |
| `max_exposure` | per-currency max exposure limit (risk guard) |
| `active` | |

---

## 4. Conversion Engine (`CurrencyConverter`)

### 4.1 API

```php
interface CurrencyConverterInterface
{
    /** Convert amount (minor units of $from) to minor units of $to. */
    public function convert(int $amount, string $from, string $to, ?\DateTimeImmutable $at = null): int;

    /** Resolve the rate 1 $from = ? $to, as a decimal string. */
    public function rate(string $from, string $to, ?\DateTimeImmutable $at = null): string;
}
```

### 4.2 Algorithm

1. Normalize both codes: `strtoupper`; extended codes (`CNY.ESCROW`) collapse to the main unit before the `.`.
2. Same unit, or `amount === 0` → return `amount` unchanged.
3. Resolve the rate — first hit wins:
   1. **Direct pair**: effective `(from → to)` row.
   2. **Inverse pair**: effective `(to → from)` row, take reciprocal.
   3. **Cross via base**: `rate = rt / rb` where `rt = 1 base = ? to`, `rb = 1 base = ? from` (each itself resolved direct-or-inverse).
   4. Missing rate / unknown unit → `ExchangeRateNotFoundException`.
4. Compute with bcmath at **scale 16**, round **once** at the end (see §5).
5. The whole call shares a single `at` instant (captured once when `$at` is null) so multi-lookup resolution is consistent.

### 4.3 Read-only, transaction-agnostic

`CurrencyConverter` performs only `findEffective()` reads — it never begins a transaction, never flushes, never writes. Inside a caller's transaction it participates as ordinary SELECTs (no nesting, no savepoints, no side effects); the caller owns the transaction boundary.

---

## 5. Precision & Money Math

Amounts are **integer minor units** (cents, ×100) everywhere — consistent with `App\Wallet` and `App\Payment`.

| Layer | Precision |
|-------|-----------|
| Rate storage | `DECIMAL(20,8)`, mapped to PHP `string` |
| Intermediate math | bcmath `scale 16` |
| Final result | integer, rounded once via `bcRoundHalfUp` |

### 5.1 Rounding

No floats. Implemented as string math:

```php
private function bcRoundHalfUp(string $value): int
{
    $sign = $value[0] === '-' ? -1 : 1;
    $abs  = $sign < 0 ? substr($value, 1) : $value;
    $rounded = bcadd($abs, '0.5', 0);
    return $sign * (int) $rounded;
}
```

- Half-up semantics (away from zero), exact for negatives (refund flows).

### 5.2 Direction convention

`rate(base, quote) = 1 base = rate quote` (per **unit**, not per cent). Because `from`/`to` both use the same ×100 exponent, `cents × rate` is already in `to`-cents:

| Case | Formula |
|------|---------|
| direct `(from→to)` | `X × rate` |
| inverse `(to→from)` | `X ÷ rate` |
| cross via base | `X × rt / rb` |

### 5.3 Known limitations

- **2-decimal fiat assumption**: all supported units use ×100. JPY/KRW (0-decimal) are **out of scope**; a per-currency exponent map would be required before supporting them (`X × rate × 10^(exp_to − exp_from)`).
- **Dust**: a coefficient like `1 CNY = 100,000,000 POINT` means `1 cent = 1,000,000 POINT`; converting POINT → CNY always leaves up to 1M POINT of residual. Display is fine; redemption must define dust handling (burn / accumulate / carry to next).

---

## 6. Ledger Abstraction

The bundle stays host-agnostic by defining its own ledger contract; the host app provides the implementation (reusing `App\Wallet`'s atomic, idempotent `TransferService`).

```php
interface LedgerInterface
{
    /** Credit an account (mint / pool funding). Idempotent via $referenceId. */
    public function credit(string $account, string $currency, int $amount, string $referenceId, string $memo): void;

    /** Debit an account (redemption / pool payout). */
    public function debit(string $account, string $currency, int $amount, string $referenceId, string $memo): void;

    /** Atomic transfer between two accounts (exchange legs). */
    public function transfer(string $fromAccount, string $toAccount, string $currency, int $amount, string $referenceId, string $memo): void;

    public function balance(string $account, string $currency): int;
}
```

- In this app, the implementation adapts `App\Wallet` (wallets + `TransferService`, idempotent by `referenceId`).
- This avoids a **second ledger** and keeps the zero-sum guarantee in one place.

---

## 7. Booking Flows & Zero-Sum Invariant

Every conversion is booked as paired, equal-and-opposite entries against the pool → **no money is created or destroyed outside of minting**; per-currency total balances are conserved.

### 7.1 Pledge

```
wrapInTransaction:
  Ledger.transfer(pledgor → pool[CNY], 1_0000, ref, 'pledge')   # collateral into pool
  Pledge(active) + PoolAccount.outstanding/Ceiling updated
```

### 7.2 Mint

```
wrapInTransaction:
  assert Σminted(unit) − Σredeemed(unit) ≤ Σactive_pledges × coefficient   # solvency
  Ledger.credit(pool[POINT], Y, ref, 'mint')                                # mint into pool
  MintRecord
```

### 7.3 Exchange — user CNY → POINT

```
wrapInTransaction:
  Y = converter.convert(X, CNY, POINT, now)          # rate with spread applied by MakerPolicy
  Ledger.transfer(user[CNY]  → pool[CNY], X, ref)    # leg 1
  Ledger.transfer(pool[POINT] → user[POINT], Y, ref) # leg 2
  ExchangeRecord (rate, rate_path)
```

Zero-sum: `user.CNY −X / pool.CNY +X` and `pool.POINT −Y / user.POINT +Y` — the pool is the counterparty; CNY total and POINT total are each conserved.

### 7.4 Redemption

```
wrapInTransaction:
  Z = converter.convert(Y, POINT, CNY, now)
  Ledger.transfer(user[POINT]  → pool[POINT], Y, ref)   # burn units
  Ledger.transfer(pool[CNY]    → user[CNY], Z, ref)     # payout collateral
  RedemptionRecord; PoolAccount.outstanding reduced
```

### 7.5 Invariants

- **Zero-sum**: internal transfers never change any currency's aggregate balance across all accounts.
- **Solvency**: `Σ issued(unit) − Σ redeemed(unit) ≤ Σ active pledges × coefficient`, and `pool[collateral] ≥ liabilities` in the collateral currency. Zero-sum keeps books balanced; pledges keep the pool solvent.
- **Spread is revenue, not a breach**: `MakerPolicy.spread_bps` / `fee_bps` leave a small residual with the pool; the ledger still balances by construction.

### 7.6 Market maker

`MakerPolicyService`:
- Owns the pool and its pledge ceiling.
- Applies spread/fee to the mid rate during `ExchangeService` booking.
- Guards `max_exposure` per currency before booking large conversions.
- Owns rate updates (anchor rows + direct overrides) with effective dating.

---

## 8. Rate Lookup Semantics

`ExchangeRateRepository::findEffective(string $base, string $quote, \DateTimeImmutable $at)`:

- `WHERE base = :base AND quote = :quote AND valid_from <= :at AND (valid_to IS NULL OR valid_to > :at)`
- `ORDER BY valid_from DESC LIMIT 1`

Lookup path (direct → inverse → cross) is **fixed and auditable** (`rate_path` on `ExchangeRecord`); a missing rate throws rather than silently degrading.

---

## 9. Configuration

```yaml
# config/packages/exchange.yaml
parameters:
    exchange.base_currency: 'USD'   # anchor currency (configurable; 'CNY' also sensible)
```

`CurrencyConverter` injects `exchange.base_currency` via `#[Autowire('%exchange.base_currency%')]`.

Doctrine mapping for the bundle (`config/packages/doctrine.yaml`):

```yaml
doctrine:
    orm:
        mappings:
            ExchangeBundle:
                dir: '%kernel.project_dir%/src/ExchangeBundle/Domain/*/Entity'
                is_bundle: false
                prefix: App\ExchangeBundle\Domain
```

---

## 10. Database Migration

Raw SQL DDL matching existing migration style (`migrations/Version2026XXXXXXXX.php`):

- `exchange_rate` — CHECK (`rate > 0`, `base != quote`), unique `(base_currency, quote_currency, valid_from)`, index on the same.
- `exchange_pool`, `exchange_pool_account`, `exchange_pledge`, `exchange_mint_record`, `exchange_exchange_record`, `exchange_redemption_record`, `exchange_maker_policy`.
- `reference_id` unique indexes on mint/exchange/redemption records (idempotency).

---

## 11. Testing

- **Unit (`CurrencyConverter`, fake rate repo)**: same-unit short-circuit, direct pair, inverse pair, cross via base, half-up boundaries (incl. `.5`, negatives), unknown unit / no effective rate → exception.
- **Unit (services, fake `LedgerInterface`)**: pledge ceiling math, mint solvency rejection, exchange double-entry balance effects, redemption payout limits, idempotency via `referenceId`.
- **Integration (real repo)**: effective-dated lookup boundaries (`valid_from <= at < valid_to`), open-ended validity, latest-of-multiple versions, `setRate` overlap rejection.

---

## 12. Phased Rollout

| Phase | Scope | Depends on |
|-------|-------|-----------|
| **P0** | Bundle skeleton + Rate domain + `CurrencyConverter` (pure read-only) | — |
| **P1** | `LedgerInterface` + Pool + Pledge + Mint (issuance + solvency) | P0 |
| **P2** | Exchange booking + Redemption (zero-sum double-entry) | P1 |
| **P3** | Maker policy + Manage/App API + reconciliation/audit reporting | P2 |

Each phase is independently testable and reviewable. Booking into `App\Wallet` (the host `LedgerInterface` implementation) lands with P1.

---

## 13. Open Decisions & Non-Goals

- **Bundle packaging**: in-repo `App\ExchangeBundle` first; extractable later.
- **Redemption of `POINT` back to CNY**: required → makes collateral mandatory. If points become one-way (earn/spend, never redeemed), the pledge layer reduces to an audit backstop.
- **Fixed anchor vs floating rate**: `1 CNY = 100,000,000 POINT` as a hard-coded coefficient is a *fixed peg* (pledge ceiling, no rate lookup); floating rates use the rate table. Both are expressible — decide priority when booking lands.
- **JPY / 0-decimal currencies**: not supported (requires per-currency exponent map).
- **External feed / auto-sync**: reserved via `source`; not implemented.
- **No integration with `PaymentDeductionService` / Trade pricing** in this design; `PaymentDeductionService` still enforces same-currency today.
