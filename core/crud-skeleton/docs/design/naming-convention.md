# Naming Convention

> Platform-level naming rules to prevent module prefixes and class names from falling into confusion again. All modules, entities, services, and controllers must comply.

---

## 1. Core Principles

**Namespaces carry the module; class names carry the domain concept.** Class names generally do not repeat the module prefix — module ownership is expressed by the
`App\<Module>\` namespace, and the class name only expresses "what domain concept this is".

```php
// The namespace already states module ownership
namespace App\Trade\Entity;   class Order { ... }
namespace App\Store\Entity;   class StoreOrder { ... }   // Prefix only for disambiguation, see §3
```

---

## 2. The Four Rules

| # | Rule |
|---|------|
| N1 | **Class name = domain concept** (bare name preferred); module ownership is expressed by the namespace |
| N2 | **Add a module prefix in only two cases**: (a) cross-module name collision/ambiguity; (b) intra-module infrastructure (outbox / registry / gateway / provider, etc., where the prefix aids discoverability) |
| N3 | **Service / Controller / Repository / Event / Exception strictly mirror the entity name**: `{Entity}Service`, `{Entity}Controller`, `{Entity}Repository`. The prefix is decided on the entity; everything else follows the entity |
| N4 | **Prefixes must be consistent within the same module**: either all prefixed or all bare, never a mix of prefixed and bare |

---

## 3. Prefix Decision Table

| Case | Approach | Example |
|---|---|---|
| Domain concept is unique across the platform | **Bare name** | `Order`, `Invoice`, `Product`, `User`, `Material`, `Media`, `Category` |
| Same name across modules / ambiguous | **Add module prefix** | `StoreOrder` (vs Trade `Order`), `WechatUser` (vs Identity `User`), `TradeOutboxMessage` / `StoreOutboxMessage` / `InventoryOutboxMessage`, `StoreConsumedEvent` / `InventoryConsumedEvent` |
| Intra-module infrastructure | Responsibility name + prefix only when needed | `PaymentGatewayRegistry`, `ReservationRequestedMessage`, `DepositService` |
| Entity name equals module name | Keep the original name | `Wallet`, `Store`, `Promotion` |

### 3.1 Cross-Module Conflict Groups (keep the prefix, must not remove)

| Concept | Reserved class name |
|---|---|
| Order | `Trade\Order`, `Store\StoreOrder` |
| User | `Identity\User`, `Wechat\WechatUser` |
| OutboxMessage | `Trade\TradeOutboxMessage`, `Store\StoreOutboxMessage`, `Inventory\InventoryOutboxMessage` |
| ConsumedEvent | `Store\StoreConsumedEvent`, `Inventory\InventoryConsumedEvent` |

> For these groups, even though the namespaces differ, removing the prefix would force any code that references both modules at once to write `use X as Y` aliases — fragile and needed everywhere.

---

## 4. Naming Checklist for New Classes

When adding a new class, ask yourself, in order:

1. **Is it an entity?** → Name it as a domain concept; check the §3.1 conflict table to decide whether to add a prefix.
2. **Is it the entity's Service / Controller / Repository / Event / Exception?** → **Strictly mirror the entity name** (N3); do not invent your own prefix.
3. **Is it intra-module infrastructure?** → Responsibility name; add a module prefix only when it would be ambiguous or when it aids discoverability.
4. **Is it consistent with neighboring classes in an existing module?** → Violating N4 (inconsistent prefixes within the same module) counts as a naming violation.

**Anti-patterns (forbidden)**:
- Entity `Transaction` but a service named `WalletTransactionService` (N3: the service must mirror the entity name; the prefix must not be invented)
- A module that already has `Stock` alongside `InventoryStock` (N4: prefixes must be consistent within the same module)
- Adding a `Service` suffix to a non-service class (e.g. `StoreContextResolver` keeps its original name; do not add `Service`)

---

## 5. Changelog

- **2026-08-15 · Minimal prefix refactor**: removed unnecessary module prefixes platform-wide —
  `WalletTransaction→Transaction`, `WalletVoucher→Voucher`, `WalletPaymentDeduction→PaymentDeduction`,
  `WalletDepositService→DepositService`, `WalletReconciliationService→ReconciliationService`,
  `InventoryStock→Stock`, `InventoryReservation→Reservation`, `InventoryLedgerEntry→LedgerEntry`,
  `StoreMembership→Membership`.
  **Table names / URLs / route names unchanged: zero migration, zero API breakage.** The 4 cross-module conflict groups keep their prefixes (§3.1).
