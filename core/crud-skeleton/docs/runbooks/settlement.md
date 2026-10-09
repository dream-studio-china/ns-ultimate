# Settlement Runbook

This runbook is the operational guide for configuring, publishing, and operating the
Settlement bundle. It complements the design document
[`design/bundles/settlement.md`](../design/bundles/settlement.md) with concrete,
step-by-step procedures and worked examples.

## 1. Concepts In One Minute

- A **funding** is a confirmed, trusted amount (e.g. an order payment) that Settlement
  must allocate.
- A **plan** is one funding amount split into **allocations** for recipients.
- A **rule** is a versioned, declarative definition of *who gets what*.
- Rules are **immutable once published**; a plan stores the exact version and trace used.
- Money is **exact** (18-decimal quantum, `brick/math`); no floats.
- Allocations are posted to **Wallet** through the `SettlementVoucherPort` boundary,
  asynchronously via the Settlement outbox.

## 2. The Rule Lifecycle

```
create rule draft → create draft version → update draft version → publish version
```

| Step | Endpoint |
|------|----------|
| Create rule draft | `POST /api/v1/manage/settlement-rules` (`code`, `name`) |
| Create draft version | `POST /api/v1/manage/settlement-rule-versions` |
| Update draft version | `PUT /api/v1/manage/settlement-rule-versions/{id}` |
| Publish version | `POST /api/v1/manage/settlement-rule-versions/{uuid}/publish` |
| Read accepted schema | `GET /api/v1/manage/settlement-rules/configuration` |

A published version is immutable. To change a rule, create a **new** version with a new
effective interval and publish it. Existing plans keep their historical snapshot.

## 3. Rule Definition Structure

```json
{
  "appliesTo": ["trade.order.v1"],
  "scope": "order",
  "conflictMode": "stack",
  "eligibility": { "all": [ { "factEquals": ["order.status", "paid"] } ] },
  "recipient": { "resolver": "context_candidate", "key": "store.owner" },
  "formula": { "rateOf": { "basis": "funding.distributable", "bps": 8000 } },
  "reasonCode": "store_revenue"
}
```

`priority`, `effectiveFrom`, and `effectiveTo` are **version metadata**, not definition
fields. They are supplied on the version create/update request.

### 3.1 Required vs Optional

| Field | Required | Notes |
|-------|----------|-------|
| `appliesTo` | yes | Source type(s), e.g. `trade.order.v1` |
| `recipient` | yes | `literal`, `context_candidate`, or `fact_reference` |
| `formula` | yes | Amount expression |
| `scope` | no | `order` (default) or `order_item` |
| `eligibility` | no | Predicate tree |
| `conflictMode` | no | `stack` (default), `exclusive_group`, `stop` |
| `group` | no | Required when `conflictMode=exclusive_group` |
| `allocationKey` | no | Override the auto-generated key |
| `reasonCode` | no | Audit label (default `rule`) |

## 4. Recipient Resolution

| Resolver | Input | Result |
|----------|-------|--------|
| `literal` | `type`, `id` | Fixed recipient |
| `context_candidate` | `key` | Recipient projected by the source adapter |
| `fact_reference` | `typeFact`, `idFact` | Recipient from declared facts |

Production recipients are `{"type":"wallet","id":"<wallet-id>"}`. The Wallet adapter
resolves these to real Wallet accounts.

```json
{ "resolver": "literal", "type": "wallet", "id": "platform-wallet-id" }
```

```json
{ "resolver": "context_candidate", "key": "store.owner" }
```

```json
{ "resolver": "fact_reference", "typeFact": "order.agentRecipientType", "idFact": "order.agentRecipientId" }
```

## 5. Eligibility Primitives

| Primitive | Example |
|-----------|---------|
| `factEquals` | `["order.status", "paid"]` |
| `factIn` | `["order.storeType", ["direct", "franchise"]]` |
| `intAtLeast` / `intAtMost` | `["order.itemCount", 3]` |
| `amountAtLeast` / `amountAtMost` | `["order.total", "100.00"]` |
| `occurredBefore` / `occurredAfter` | `["order.paidAt", "2026-12-31T23:59:59+00:00"]` |
| `all` / `any` / `not` | Composite predicates |

All facts must be projected into the frozen funding snapshot by the source adapter.
Unknown facts are rejected at plan creation.

## 6. Formula Primitives

| Primitive | Result |
|-----------|--------|
| `fundingAmount` | The plan's distributable funding amount |
| `fixedAmount` | A literal exact decimal |
| `factAmount` | A declared amount fact |
| `rateOf` | Fraction of a child formula in basis points |
| `multiplyByQuantity` | Formula × integer quantity |
| `add` / `subtract` | Exact arithmetic |
| `minOf` / `maxOf` | Exact min/max |

`bps` must be within `0..10000` (8000 = 80%). Amounts are never negative; a negative
result rejects plan creation.

## 7. Step-by-Step: Order-Level Percentage Split

Goal: give the store owner 80% of every paid order.

### 7.1 Create the rule draft

```
POST /api/v1/manage/settlement-rules
```

```json
{ "code": "store-revenue", "name": "Store revenue 80%" }
```

### 7.2 Create the draft version

```
POST /api/v1/manage/settlement-rule-versions
```

```json
{
  "ruleUuid": "<rule-uuid>",
  "definition": {
    "appliesTo": ["trade.order.v1"],
    "scope": "order",
    "conflictMode": "stack",
    "eligibility": { "all": [ { "factEquals": ["order.status", "paid"] } ] },
    "recipient": { "resolver": "context_candidate", "key": "store.owner" },
    "formula": { "rateOf": { "basis": "funding.distributable", "bps": 8000 } },
    "reasonCode": "store_revenue"
  },
  "priority": 100,
  "effectiveFrom": "2026-08-19T00:00:00+00:00",
  "effectiveTo": null
}
```

### 7.3 Publish

```
POST /api/v1/manage/settlement-rule-versions/{versionUuid}/publish
```

The version is now immutable and active from `effectiveFrom`.

## 8. Step-by-Step: Per-Item Agent Reward

Goal: for every order line whose specification is `32` and unit price is above `3.00`,
pay the order's agent `3.00 × quantity`.

### 8.1 Create the rule draft

```
POST /api/v1/manage/settlement-rules
```

```json
{ "code": "agent-spec-32", "name": "Agent reward spec 32" }
```

### 8.2 Create the draft version

```
POST /api/v1/manage/settlement-rule-versions
```

```json
{
  "ruleUuid": "<rule-uuid>",
  "definition": {
    "appliesTo": ["trade.order.v1"],
    "scope": "order_item",
    "eligibility": {
      "all": {
        "children": [
          { "factEquals": ["item.specificationId", 32] },
          { "amountAtLeast": ["item.unitPrice", "3.01"] }
        ]
      }
    },
    "recipient": {
      "resolver": "fact_reference",
      "typeFact": "order.agentRecipientType",
      "idFact": "order.agentRecipientId"
    },
    "formula": {
      "multiplyByQuantity": {
        "value": { "fixedAmount": { "amount": "3.00" } },
        "quantity": "item.quantity"
      }
    },
    "reasonCode": "agent_specification_reward"
  },
  "priority": 100,
  "effectiveFrom": "2026-08-19T00:00:00+00:00",
  "effectiveTo": null
}
```

### 8.3 Notes

- `item.unitPrice` is a decimal snapshot amount; `3.01` expresses strict greater-than
  `3.00` because the grammar supplies inclusive comparisons.
- Each qualifying line produces its own allocation with the source item ID and snapshot
  persisted for audit.
- The source adapter must project `item.specificationId`, `item.unitPrice`,
  `item.quantity`, and the order-level agent recipient facts.

## 9. Conflict Modes

| Mode | Meaning |
|------|---------|
| `stack` | Rule adds allocations alongside other matched rules (default) |
| `exclusive_group` | First matched rule in a named `group` wins; later rules in that group are skipped |
| `stop` | A matched rule ends further rule selection after its proposals are accepted |

## 10. Posting And Operations

Allocations are posted to Wallet asynchronously through the Settlement outbox.

| Command | Purpose |
|---------|---------|
| `app:settlement:outbox:publish` | Relay posting commands to Wallet |
| `app:settlement:allocations:requeue-due` | Requeue retryable posting failures |

These are scheduled in `compose.yaml`. To post a single allocation manually:

```
POST /api/v1/manage/settlement-plans/{planUuid}/allocations/{allocationUuid}/post
```

To reverse a posted allocation:

```
POST /api/v1/manage/settlement-plans/{planUuid}/allocations/{allocationUuid}/reverse
```

## 11. Audit And Reconciliation

| Endpoint | Purpose |
|----------|---------|
| `GET /api/v1/manage/settlement-plans` | List plans |
| `GET /api/v1/manage/settlement-plans/{id}` | Plan detail with allocations |
| `GET /api/v1/manage/settlement-allocations` | List allocations |
| `GET /api/v1/manage/settlement-rules` | List rules |
| `GET /api/v1/manage/settlement-rule-versions` | List rule versions |
| `GET /api/v1/manage/settlement-outbox-messages` | List outbox messages |
| `GET /api/v1/manage/settlement-consumed-events` | List consumed funding events |

Conservation invariants (must hold before a plan is `posted`):

```text
fundingAmount = allocationAmountTotal + unallocatedAmount
fundingPostingAmount = sum(allocation.postingAmount) + unallocatedPostingAmount
unallocatedAmount = 0
unallocatedPostingAmount = 0
```

## 12. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Plan creation rejected | Unknown fact, negative amount, or allocation total exceeds funding | Review eligibility/formula and snapshot facts |
| Rule not applied | `appliesTo` mismatch, not yet effective, or not published | Check source type, `effectiveFrom`, publish status |
| Allocation stuck in `posting_requested` | Outbox relay not running | Run `app:settlement:outbox:publish` |
| Allocation in `retryable_failure` | Transient Wallet error | Run `app:settlement:allocations:requeue-due` |
| Recipient rejected | Not a `wallet` type/id | Configure `{"type":"wallet","id":"<wallet-id>"}` |
| Conservation fails | Rounding or rule remainder not absorbed | Ensure a fallback recipient exists (default platform) |

## 13. Checklist Before Going Live

- [ ] Rule version parses and validates via the configuration schema.
- [ ] `appliesTo` matches the funding source type.
- [ ] `effectiveFrom` is set and `effectiveTo` does not overlap a published version.
- [ ] Recipient resolves to a real Wallet ID.
- [ ] Every `config`/fact referenced by eligibility and formula is projected by the source adapter.
- [ ] Outbox relay and requeue commands are scheduled.
- [ ] A fallback recipient exists so rounding remainder is conserved.
- [ ] Audit endpoints return the expected plans, allocations, and traces.
