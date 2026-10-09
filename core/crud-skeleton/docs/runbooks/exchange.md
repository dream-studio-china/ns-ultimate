# Exchange Runbook

This runbook describes the Exchange module (pool-backed points economy). It complements
[`design/bundles/exchange.md`](../design/bundles/exchange.md).

> **Status**: Design only — not yet implemented. This runbook documents the intended
> configuration and operations for when the module ships.

## 1. Concepts In One Minute

- **Exchange** provides a pool-backed points economy with effective-dated exchange rates.
- Rates use a hybrid model: anchor pairs + direct pairs.
- Booking flows (pledge, mint, exchange, redemption) preserve a **zero-sum invariant**.

## 2. Configuration

Exchange configuration is reserved but not yet implemented. The intended shape:

```yaml
exchange:
    default_anchor_currency: 'CNY'
    precision_scale: 18
```

Rates are effective-dated; a rate lookup uses the rate active at the booking time.

## 3. Money Math

Conversion uses `brick/math` (or bcmath) for exact arithmetic. No floats. The precision
scale is configurable; conversions preserve exactness until the final booking boundary.

## 4. Booking Flows

| Flow | Meaning |
|------|---------|
| Pledge | Lock funds into the pool |
| Mint | Create points backed by the pool |
| Exchange | Convert between currencies/points |
| Redemption | Redeem points for value |

Each flow must preserve the zero-sum invariant: total debits equal total credits.

## 5. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Rate not found | No effective rate for pair/time | Add an anchor or direct rate |
| Invariant broken | Booking imbalance | Review the booking ledger |

## 6. Checklist Before Going Live

- [ ] Anchor and direct rates configured.
- [ ] Precision scale set.
- [ ] Zero-sum invariant verified per flow.
- [ ] Ledger abstraction implemented.
