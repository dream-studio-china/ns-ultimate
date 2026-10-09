# Promotion Runbook

This runbook is the operational guide for configuring, validating, and troubleshooting
the Promotion bundle. It complements the design document
[`design/bundles/promotion.md`](../design/bundles/promotion.md) with concrete,
step-by-step procedures.

## 1. Concepts In One Minute

- A **PromotionTemplate** is a reusable DSL recipe (the "what to do").
- A **Promotion** is a concrete, time-boxed instance of a template bound to a store
  (the "when and where").
- The **DSL** is a small, closed language parsed into an AST at save time. It is **not**
  ExpressionLanguage and has no `eval`.
- The engine resolves the template `type` to a **strategy** and applies each `do:` action
  in order.
- Promotions are **multi-store**: every promotion carries a `storeCode`.

## 2. The Two-Level Model

| Level | Entity | Purpose | Example |
|-------|--------|---------|---------|
| Recipe | `PromotionTemplate` | Reusable DSL + type + phase | "满减: spend X save Y" |
| Instance | `Promotion` | Template + store + time window + config | "Store A, 2026-08-01 → 08-31, threshold=200, amount=30" |

Always author the template once, then create many promotions from it. This keeps the
DSL in one place and lets operators tune only the `config` per instance.

## 3. DSL Quick Reference

### 3.1 Structure

```
type: <promotion_type>       # full_reduction | discount | gift | nth_discount | tiered | free_shipping | member_discount
phase: <inner|outer>         # execution phase (default: inner)
when:
  <condition>
do:
  <action>
priority: <expression>       # optional sort key
fields:
  <name>:<type>:<label>      # optional config field declarations
```

### 3.2 Conditions

| Scope | Example |
|-------|---------|
| Cart | `cart.subtotal >= 200.00`, `cart.items.count >= 3` |
| Item | `item.price >= 50.00`, `item.quantity >= 2`, `item.spec.id in [42, 43]`, `item.tags includes "fruit"` |
| User | `user.level >= "vip"`, `user.tags includes "new_user"` |
| Config | `cart.subtotal >= config.threshold` |
| Logic | `and:` / `or:` lists, `not:` |

### 3.3 Actions

| Action | Example |
|--------|---------|
| Full reduction | `discount order config.amount` |
| Percentage | `discount order 10%` |
| Percentage with cap | `discount order 10% max 50.00` |
| Gift | `add gift spec:config.gift_spec count:1` |
| Nth item | `discount item 3 50%` |
| Tiered | `tiered:` block with `from:`/`less:` rows |
| Free shipping | `free shipping` |
| Member discount | `member discount 95%` |

### 3.4 Config Fields

```
fields:
  threshold:number:消费门槛(元)
  amount:number:优惠金额(元)
  gift_spec:spec:赠品规格
  min_qty:number:最低件数
```

Field types: `number`, `spec`, `string`, `bool`. Values are supplied per-promotion in
the `config` JSON and referenced in the DSL as `config.<name>`.

## 4. Step-by-Step: Create a Full-Reduction Promotion

### 4.1 Create the template

```
POST /api/v1/manage/promotion-templates
```

```json
{
  "name": "满减模板",
  "type": "full_reduction",
  "phase": "inner",
  "dsl": "# Spend X, save Y\n\ntype: full_reduction\nphase: inner\n\nwhen:\n  cart.subtotal >= config.threshold\n\ndo:\n  discount order config.amount\n\npriority: config.amount\nfields:\n  threshold:number:消费门槛(元)\n  amount:number:优惠金额(元)\n"
}
```

The DSL is parsed and validated on save. A syntax error returns `422` with
`line:column` details.

### 4.2 Validate the DSL (optional dry-run)

```
POST /api/v1/manage/promotion-templates/{id}/validate
```

Returns the parsed AST and any errors. Use this before publishing a template to catch
typos without side effects.

### 4.3 Create the promotion instance

```
POST /api/v1/manage/promotions
```

```json
{
  "name": "Store A 满200减30",
  "template": "<template-id>",
  "storeCode": "store-a",
  "enabled": true,
  "startTime": "2026-08-01T00:00:00+08:00",
  "endTime": "2026-08-31T23:59:59+08:00",
  "config": { "threshold": 200.00, "amount": 30.00 },
  "conflictMode": "best_price"
}
```

### 4.4 Verify availability

```
GET /api/v1/app/promotions?storeCode=store-a
```

Only `enabled = true` promotions inside their time window appear. If the promotion is
missing, check `enabled`, `startTime`/`endTime`, and `storeCode`.

## 5. Step-by-Step: Nth-Item Discount

```
POST /api/v1/manage/promotion-templates
```

```json
{
  "name": "第3件5折",
  "type": "nth_discount",
  "dsl": "# Nth item at discounted price\n\ntype: nth_discount\nphase: inner\n\nwhen:\n  item.quantity >= 3\n\ndo:\n  discount item 3 50%\n"
}
```

Then create a promotion instance bound to the target store and specification set
(`specifications` array on the promotion).

## 6. Step-by-Step: Tiered Discount

```
POST /api/v1/manage/promotion-templates
```

```json
{
  "name": "阶梯满减",
  "type": "tiered",
  "dsl": "# Tiered/ladder discount\n\ntype: tiered\n\nwhen:\n  cart.subtotal >= config.from_1\n\ndo:\n  tiered:\n    - from: 100.00  less: 10.00\n    - from: 200.00  less: 30.00\n    - from: 500.00  less: 80.00\n\nfields:\n  from_1:number:起始门槛(元)\n"
}
```

The highest matching tier wins.

## 7. Conflict Modes

| Mode | Meaning |
|------|---------|
| `best_price` | Only the most favorable matching promotion applies |
| `stack` | Multiple promotions may combine |

Set `conflictMode` on the promotion instance. When several promotions match the same
cart, `best_price` picks the single best discount; `stack` lets them combine.

## 8. Phase (inner vs outer)

- **inner**: applied before other pricing steps (e.g. full reduction, gift).
- **outer**: applied after (e.g. percentage discount on the reduced total).

Choose the phase so the discount basis matches the business intent. A percentage
discount usually runs `outer` so it applies to the already-reduced subtotal.

## 9. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Promotion not visible in app list | `enabled=false`, outside time window, or wrong `storeCode` | Check instance fields |
| DSL save returns 422 | Syntax error | Run `/validate`, fix `line:column` |
| Discount not applied at checkout | Wrong `phase`, or `conflictMode` excludes it | Review phase and conflict mode |
| Config value ignored | `config.<key>` not declared in `fields` or missing in instance `config` | Add field declaration and supply value |
| Wrong store sees promotion | `storeCode` mismatch | Set correct `storeCode` on instance |

## 10. Checklist Before Going Live

- [ ] Template DSL parses via `/validate`.
- [ ] Promotion `enabled=true` and time window is correct.
- [ ] `storeCode` matches the target store.
- [ ] `config` supplies every `config.<key>` referenced by the DSL.
- [ ] `conflictMode` and `phase` match the business intent.
- [ ] App list endpoint returns the promotion for the target store.
