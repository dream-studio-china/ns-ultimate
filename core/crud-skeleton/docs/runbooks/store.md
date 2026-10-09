# Store Runbook

This runbook is the operational guide for configuring and operating the Store
module (multi-store operations, store orders, membership). It complements
[`design/bundles/store.md`](../design/bundles/store.md).

## 1. Concepts In One Minute

- **Store** is the multi-store tenant boundary.
- **StoreOrder** extends the trade order flow with store acceptance/rejection.
- **Membership** tracks store-level membership.
- Store context is resolved per request and drives promotion and inventory scoping.

## 2. Store Context

A `StoreContextResolver` resolves the current store from the request. It is bound to
`App\Trade\Service\StoreContextResolverInterface`. Store context drives:

- Promotion availability (`storeCode`).
- Inventory scoping (per-store stock).
- Order store association.

## 3. Store Order Flow

Trade orders are extended with store acceptance:

```
trade order created → store submit → store accept / store reject
```

The store workflow is enforced by Symfony Workflow. Use the transitions endpoint to
discover available moves.

## 4. Store Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET/POST/PUT/DELETE | `/api/v1/manage/stores[/{id}]` | Store CRUD |
| GET/POST/PUT/DELETE | `/api/v1/manage/store-orders[/{id}]` | Store order management |
| GET/POST/PUT/DELETE | `/api/v1/staff/store-orders[/{id}]` | Staff store order operations |

## 5. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Store not resolved | Missing store context | Check `StoreContextResolver` and request store header |
| Order cannot be accepted | Wrong state | Check store workflow transitions |
| Promotion not scoped | `storeCode` mismatch | Verify promotion `storeCode` |

## 6. Checklist Before Going Live

- [ ] Stores created.
- [ ] Store context resolver configured.
- [ ] Store workflow transitions verified.
- [ ] Promotions and inventory scoped to stores.
