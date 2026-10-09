# Trade Runbook

This runbook is the operational guide for configuring and operating the Trade
module (products, specifications, orders, pricing). It complements
[`design/bundles/trade.md`](../design/bundles/trade.md).

## 1. Concepts In One Minute

- **Product** → **Specification** (SKU) → **OrderItem** → **Order**.
- Orders follow a state machine: `draft → pending → confirmed → paid → fulfilled → completed`.
- Pricing runs through a **calculator pipeline** (priority-ordered).

## 2. Pricing Pipeline

Pricing is computed by a chain of calculators. Each calculator is tagged and ordered by
priority. The pipeline produces unit prices, line amounts, and totals.

| Calculator | Purpose |
|-----------|---------|
| `BasePriceCalculator` | Base unit price from specification |
| `QuantityCalculator` | Line amount = unit price × quantity |
| `TotalAggregator` | Order total |

Promotion adds a `trade.price_calculator` at priority 60.

## 3. Order State Machine

```
draft → pending → confirmed → paid → fulfilled → completed
                          ↘ cancelled
                          ↘ refunded
```

Transitions are enforced by Symfony Workflow. Use the transitions endpoint to discover
available moves.

## 4. Trade Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/products` | List active products |
| GET | `/api/v1/app/specifications` | Browse active specs |
| GET | `/api/v1/app/orders` | List user's orders |
| GET/POST/PUT/DELETE | `/api/v1/manage/products[/{id}]` | Product CRUD |
| GET/POST/PUT/DELETE | `/api/v1/manage/specifications[/{id}]` | Specification CRUD |
| POST | `/api/v1/manage/orders` | Create order (with pricing) |
| POST | `/api/v1/manage/orders/quote` | Price preview |
| POST | `/api/v1/manage/orders/{id}/do/{transition}` | Execute transition |
| POST | `/api/v1/app/orders/{id}/payment` | Start order payment |

## 5. Money Handling

Order amounts are stored in **cents** (integer). Never use floats for money. The pricing
pipeline and payment integration both operate on integer minor units.

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Order cannot transition | Wrong current state | Check state machine and transitions endpoint |
| Price wrong | Calculator order or promotion conflict | Review pipeline priority and promotion phase |
| Spec not found | Inactive or deleted | Check specification status |

## 7. Checklist Before Going Live

- [ ] Products and specifications created and active.
- [ ] Pricing pipeline calculators ordered correctly.
- [ ] Order state machine transitions verified.
- [ ] Payment integration configured (see Payment runbook).
