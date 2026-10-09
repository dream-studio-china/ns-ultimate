# Extension Points Catalog

> Central directory of every pluggable extension point in the system: the
> auto-tag, the contract interface, the owning module, the registry that
> collects implementations, where concrete providers live, and who consumes
> them. Use this document to discover or add a provider without grepping.

---

## 1. Placement Rule

Two rules decide where an extension point and its implementations live:

1. **The contract (interface + registry) lives in the module that owns/consumes the behavior.**
   Payment defines `PaymentGatewayInterface` because it orchestrates gateways.
   Wallet defines `DepositProviderInterface` because it gates every credit.
   Trade defines `PriceCalculatorInterface` because it runs the price pipeline.
2. **The implementation lives in the module that owns the side-effect.**
   `WalletGateway` lives in Wallet because it moves wallet balances; `WechatPayGateway`
   lives in Wechat because it drives EasyWeChat; `ManualDepositProvider` lives in
   Wallet because it authorizes a wallet credit. An implementation may live in a
   different module than the interface, as long as the dependency direction is
   allowed (implementing module imports the interface's module).

The rule that keeps Payment a generic facade is contractual:

> **Payment MUST NOT depend on Wallet** (services or entities). Wallet, Wechat,
> and other modules MAY implement Payment-defined interfaces. Any provider backing
> wallet credits therefore implements Wallet's `wallet.deposit_provider` extension
> point, not a Payment one — even when the funding source is a Payment invoice.

## 2. Auto-Tagging

Every extension point is registered centrally in `config/services.yaml` under
`_instanceof`. Any class implementing the interface — in any module namespace —
receives the tag automatically. No per-provider wiring is needed:

```yaml
services:
    _instanceof:
        App\Payment\Service\PaymentGatewayInterface:
            tags: ['payment.gateway']
        App\Payment\Service\Adjustment\PaymentAdjustmentProviderInterface:
            tags: ['payment.adjustment_provider']
        App\Wallet\Service\Deposit\DepositProviderInterface:
            tags: ['wallet.deposit_provider']
        App\Wallet\Service\Withdraw\WithdrawProviderInterface:
            tags: ['wallet.withdraw_provider']
        App\Storage\Service\MediaStorageInterface:
            tags: ['media.storage']
```

Tags are consumed with `#[AutowireIterator('tag.name')]`.

## 3. Catalog

| Tag | Interface (owner) | Registry | Consumers | Implementations (module) |
|-----|-------------------|----------|-----------|--------------------------|
| `payment.gateway` | `App\Payment\Service\PaymentGatewayInterface` (Payment) | `PaymentGatewayRegistry` | `InvoiceService` | `MockGateway` (Payment), `WalletGateway` (Wallet), `WechatPayGateway` (Wechat) |
| `payment.adjustment_provider` | `App\Payment\Service\Adjustment\PaymentAdjustmentProviderInterface` (Payment) | `PaymentAdjustmentRegistry` | `InvoiceService` | `WalletBalanceAdjustmentProvider` (Wallet) |
| `wallet.deposit_provider` | `App\Wallet\Service\Deposit\DepositProviderInterface` (Wallet) | `DepositProviderRegistry` | `DepositService` | `ManualDepositProvider` (Wallet), `InvoiceDepositProvider` (Wallet) |
| `wallet.withdraw_provider` | `App\Wallet\Service\Withdraw\WithdrawProviderInterface` (Wallet) | `WithdrawProviderRegistry` | `WithdrawService` | `ManualWithdrawProvider` (Wallet) |
| `media.storage` | `App\Storage\Service\MediaStorageInterface` (Storage) | `MediaStorageRegistry` | `MediaService` | `LocalStorage`, `QiniuStorage` (Storage) |
| `trade.price_calculator` | `App\Trade\Service\Pricing\PriceCalculatorInterface` (Trade) | collected inline in `OrderService` | `OrderService::calculatePrices()` | `BasePriceCalculator`, `QuantityCalculator`, `TotalAggregator` (Trade); `PromotionCalculator` (Promotion) |
| `promotion.strategy` | `App\Promotion\Strategy\PromotionStrategyInterface` (Promotion) | collected inline in `PromotionService` | `PromotionCalculator` | 7 strategies (Promotion) |

### 3.1 Registry Pattern

Registries index providers by `::getName()` and expose typed access:

```php
final class DepositProviderRegistry
{
    /** @param iterable<DepositProviderInterface> $providers */
    public function __construct(#[AutowireIterator('wallet.deposit_provider')] iterable $providers) {}

    public function has(string $name): bool;
    public function get(string $name): DepositProviderInterface;   // throws when unknown
    public function forVoucherType(string $voucherType): ?DepositProviderInterface; // whitelist routing
}
```

A provider that supports multiple concrete names uses `supports()` to route; a
provider that is itself one name returns its `getName()` from `supports()`.

## 4. Provider Contracts

### 4.1 Voucher-Backed Providers (Deposit / Withdraw)

`DepositProviderInterface` and `WithdrawProviderInterface` share the same shape:

| Method | Contract |
|--------|----------|
| `getName()` | Stable provider/voucher-type name |
| `supports(string $type)` | Whether this provider handles the requested voucher type |
| `assertPermitted(array $options)` | Own the permission rule; throw `AccessDeniedException` when the caller is not allowed. No active security context (CLI/queue/system) is a trusted caller and MUST pass |
| `authorize(Voucher, array $options)` | Verify the source backing the voucher is ready (e.g. an invoice is paid and matches). Called inside the deposit/withdraw transaction |
| `reverse(Voucher, string $reason, array $options)` | Source-side handling when an applied voucher is reversed. Runs after the wallet movement transaction |

The built-in `manual` providers are admin-only when a security context is active
and use `authorize()`/`reverse()` as no-ops. Domain-backed providers (e.g.
`InvoiceDepositProvider`) encode their source checks in `authorize()`.

### 4.2 Payment Gateways

`PaymentGatewayInterface` receives **explicit** `$amount` arguments and MUST NOT
derive the payable amount from the invoice or inspect adjustment options. See
`docs/design/bundles/payment.md` §6.

### 4.3 Payment Adjustment Providers

`PaymentAdjustmentProviderInterface` reduces the amount a gateway must process
(e.g. wallet-balance deduction). Implementations live in the owning module and
return only generic `PaymentAdjustmentResult` data. See `payment.md` §5.3.

## 5. Cross-Module Guards (Service Decoration)

Not every cross-module rule needs a registry. A rule that intercepts an existing
service entry point can be layered as a **service decorator**:

- `App\Wallet\Service\Payment\InvoiceDepositRefundGuard` decorates
  `App\Payment\Service\InvoiceService` and blocks `refund()` while an APPLIED
  `invoice` deposit voucher exists. The derived "deposited" state (paid invoice
  + applied voucher) unlocks automatically when the voucher is reversed.
- Wiring in `config/services.yaml`:
  ```yaml
  App\Wallet\Service\Payment\InvoiceDepositRefundGuard:
      decorates: App\Payment\Service\InvoiceService
      arguments: ['@.inner']
  App\Payment\Service\InvoiceServiceInterface: '@App\Wallet\Service\Payment\InvoiceDepositRefundGuard'
  ```
- The explicit interface alias is required once decoration creates two candidates
  for the interface.

Decoration applies to every consumer of the interface (controllers, Trade), so no
call-site changes are needed.

## 6. Adding a New Provider

1. Decide the owner of the side-effect → that module hosts the implementation.
   Keep the contract in its owning module unless the behavior being added is the
   contract itself.
2. Implement the interface (contract from §3). Rely on the global `_instanceof`
   tag — do **not** register the service manually.
3. If a registry needs no change (it collects by tag), stop there. If the new
   provider owns a new permission rule or source check, put it in
   `assertPermitted()`/`authorize()` per the contract.
4. Add translation keys for every user-facing message the provider can throw.
5. Add unit tests for the provider (success + every rejection path) and an
   integration test that drives the real registry, plus a lifecycle test for
   any domain-derived guard.
6. Verify PHPStan Level 8 and the 90% coverage gate.

## 7. Related Docs

- `docs/design/module-design.md` — module skeleton + dependency rules
- `docs/design/bundles/payment.md` — gateway/adjustment framework
- `docs/design/bundles/wallet.md` — voucher-backed deposit/withdraw
- `config/services.yaml` — the single source of truth for auto-tags