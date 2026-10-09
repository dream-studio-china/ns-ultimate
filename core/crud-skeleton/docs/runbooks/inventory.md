# Inventory Runbook

This runbook is the operational guide for configuring and operating the Inventory
module (stock, reservations, recipes, negative-stock policy). It complements
[`design/bundles/inventory.md`](../design/bundles/inventory.md).

## 1. Concepts In One Minute

- **Stock** is per-store, per-material, with a local negative-stock policy.
- **Specification Recipes** map a finished good to its material components.
- **Reservations** hold stock during order processing, with compensation on failure.
- **Negative stock** is controlled by `allowNegativeStock` (default `false`).

## 2. Negative-Stock Policy

`allowNegativeStock` defaults to `false`. When `false`, a reservation that would drive
stock below zero is rejected. Set it per stock via the policy endpoint when the business
allows overselling.

```
PUT /api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/policy
```

```json
{ "allowNegativeStock": false }
```

## 3. Global Enable

Inventory work can be disabled at deployment time. When disabled, no inventory
reservation/ledger work is performed. Respect the deployment configuration.

## 4. Key Concepts

| Concept | Meaning |
|---------|---------|
| `Stock` | Per-store material balance and local policy |
| `Reservation` | Holds stock for an order |
| `Recipe` | Finished good → material components |
| `Stock Ledger` | Immutable movement record |

## 5. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Reservation rejected | Insufficient stock, negative policy off | Check stock and `allowNegativeStock` |
| Recipe not resolved | Missing recipe or inactive spec | Verify recipe and specification status |
| Stock mismatch | Ledger gap | Review stock ledger and reconciliation |

## 6. Checklist Before Going Live

- [ ] Materials and stock initialized per store.
- [ ] Recipes defined for finished goods.
- [ ] Negative-stock policy set per business need.
- [ ] Inventory enabled/disabled per deployment.
