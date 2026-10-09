# Inventory Bundle Design

> **Status: preview, implemented but not production-ready.** The Inventory bundle
> (`src/Inventory/`) owns materials, per-store stock, Specification recipes,
> reservations, and the stock ledger. It is the deferred reservation boundary defined
> by the Store bundle. Trade remains the commercial-order authority; Store remains the
> authority for store acceptance and operational orders. The approved catalog target
> moves Product/Specification ownership to Store; Inventory continues to use scalar
> Specification UUIDs and does not need a cross-module entity relation.
>
> `INVENTORY_ENABLED` MUST remain `false` outside isolated development and testing.
> Confirmed reservations currently expire without a fulfillment-driven consume flow,
> and cross-message cancellation races are not fully serialized. The schema and disabled
> module may be merged, but enabling Inventory for live orders is blocked by the
> production-readiness work below.

### Production-Readiness TODO

- Implement fulfillment-driven reservation consumption that atomically reduces on-hand
  and reserved quantities and writes `consume` ledger entries.
- Define expiry semantics so an accepted or fulfilled order cannot lose its reservation
  while it still depends on stock.
- Serialize Store order confirmation and cancellation transitions to prevent stale state
  from resurrecting cancelled orders.
- Handle release-before-reserve ordering with a durable Inventory cancellation tombstone
  and preserve per-reservation outbox causality.
- Add concurrency and out-of-order integration tests for these transitions before
  allowing `INVENTORY_ENABLED=true` in production.

---

## 1. Goals And Scope

### 1.1 Goal

Provide inventory control for stores without coupling the Trade catalog, Store order
projection, or future extracted services through shared database relations.

```text
Trade order
  -> Store validates local eligibility
  -> Store requests Inventory reservation
  -> Inventory expands the Specification recipe into materials
  -> Inventory reserves, or rejects, atomically
  -> Store accepts, or rejects, the commercial order
```

The bundle owns:

- The material master, including both raw materials and finished goods.
- Per-store on-hand and reserved quantities.
- The per-stock negative-inventory policy.
- The current, unique recipe for a Specification UUID.
- Reservation, release, consumption, adjustment, and audit-ledger records.
- Idempotent consumption and publication of Inventory integration events.
- Inventory availability checks when inventory is globally enabled.

The bundle does not own:

- Product or Specification master data, pricing, or SKU activation.
- Customer-facing commercial orders, payment, refund, or cancellation authority.
- Store identity, membership, store eligibility, or StoreOrder acceptance authority.
- A Doctrine association or foreign key to Trade, Store, Identity, Payment, or Wallet.

### 1.2 Non-Goals

The initial Inventory phase MUST NOT:

- Create a second SKU catalog or duplicate catalog Specification fields.
- Create stock rows as a side effect of a read request.
- Treat a broker as exactly-once, ordered globally, or transactionally coupled to SQL.
- Use distributed transactions between Store and Inventory.
- Persist a historical recipe as a mutable reference on an order; reservation lines are
  the immutable material snapshot.
- Deduct inventory at reservation time. Reservation only changes reserved quantity.
- Support multiple active recipes, recipe versions, substitutions, lots, serial numbers,
  expiry, warehouse bins, or production work orders in phase one.

### 1.3 Ownership Matrix

| Concern | Owner | Inventory Responsibility |
|---|---|---|
| Product and Specification catalog | Store (target) | Reference Specification UUID only |
| Commercial order and payment state | Trade + Payment | Consume immutable Store request only |
| Store identity and acceptance | Store | Reference Store UUID only |
| Material master | Inventory | Authoritative |
| Store material balance | Inventory | Authoritative |
| SKU-to-material recipe | Inventory | Authoritative |
| Reservation decision and ledger | Inventory | Authoritative |
| Global inventory bypass | Deployment configuration | Respect it; do no inventory work when disabled |

---

## 2. Architectural Principles

### 2.1 Stable Scalar References

Inventory references adjacent modules through UUIDs and immutable snapshots only:

| Reference | Stored as | Not allowed |
|---|---|---|
| Store | `storeUuid` string(36) | `ManyToOne Store` |
| Trade order | `tradeOrderUuid` string(36) | `ManyToOne Order` |
| Store order | `storeOrderUuid` string(36) | `ManyToOne StoreOrder` |
| Specification | `specificationUuid` string(36) | `ManyToOne Specification` |
| Store reservation | `reservationId` string(36) | Cross-module entity relation |

The Store-provided `catalogReference` is the Specification UUID. Inventory never loads
catalog tables while reserving inventory. This preserves a future extraction path.

### 2.2 Globally Optional, Locally Enforced

The modular monolith supports deployments that do not operate inventory. A single
runtime configuration controls whether Store invokes the Inventory boundary:

```yaml
# Inventory Resources/config/services_inventory.yaml
parameters:
    inventory.enabled: '%env(bool:INVENTORY_ENABLED)%'
```

| `INVENTORY_ENABLED` | Store behavior | Inventory behavior |
|---|---|---|
| `false` | Performs Store validation and accepts directly | No reservation request, no Inventory read/write, no Inventory event |
| `true` | Creates a reservation ID and waits in `awaiting_inventory` | Consumes reservation requests and responds asynchronously |

The global setting takes precedence over all material-level inventory policies. It is a
deployment concern, not a database setting or a request parameter. Its default MUST be
documented explicitly in `.env` and deployment configuration. While this bundle remains
in preview, production deployments MUST keep it `false`.

### 2.3 Per-Store, Per-Material Policy

Inventory is isolated by `(storeUuid, material)`. Each balance has its own
`allowNegativeStock` flag, defaulting to `false`.

```text
availableQuantity = onHandQuantity - reservedQuantity
```

| Global enabled | `allowNegativeStock` | Reservation rule |
|---|---|---|
| no | any value | Inventory is bypassed entirely |
| yes | false | `availableQuantity >= requestedQuantity` for every material |
| yes | true | Reserve even when `availableQuantity < requestedQuantity` |

Negative inventory is recorded rather than hidden: the balance and the ledger retain
the resulting over-reservation or later negative on-hand quantity. The flag cannot
silently bypass ledger entries or idempotency.

### 2.4 Reads Never Materialize Balances

An absent `Stock` record means a zero balance with the default local policy;

```json
{
  "storeUuid": "store-uuid",
  "material": "material-uuid",
  "exists": false,
  "onHandQuantity": 0,
  "reservedQuantity": 0,
  "availableQuantity": 0,
  "allowNegativeStock": false
}
```

The management read API may return this virtual view for an explicitly requested
material/store pair. List APIs return persisted rows only. A real balance is created
only by a write operation: initial stock, adjustment, reservation when negative stock
is allowed, or another future stock mutation.

### 2.5 Local Transaction And At-Least-Once Delivery

Each Inventory mutation writes its local aggregate and its outgoing event in one SQL
transaction. An Inbox table deduplicates at-least-once delivery by `eventId`; business
records add unique keys for race-safe idempotency.

```text
Inventory transaction:
  consumed event + reservation + stock changes + ledger entries + outbox event

Delivery:
  at least once + Inbox deduplication + reservationId uniqueness
```

No handler may acknowledge a message before its transaction commits. No consumer may
assume an event is delivered only once or that events from different aggregates arrive
in a global order.

---

## 3. Domain Model

### 3.1 Entity Overview

| Entity | Table | Purpose |
|---|---|---|
| `Material` | `inventory_material` | Raw material or finished-good master data |
| `Stock` | `inventory_stock` | Per-store material balance and local policy |
| `SpecificationRecipe` | `inventory_specification_recipe` | One current recipe per catalog Specification UUID |
| `RecipeLine` | `inventory_recipe_line` | Material quantity required for one Specification unit |
| `Reservation` | `inventory_reservation` | Idempotent request-level reservation aggregate |
| `ReservationLine` | `inventory_reservation_line` | Immutable material demand and reserved quantities |
| `LedgerEntry` | `inventory_ledger_entry` | Append-only inventory audit trail |
| `InventoryConsumedEvent` | `inventory_consumed_event` | Inbox deduplication and event audit |
| `InventoryOutboxMessage` | `inventory_outbox_message` | Durable integration-event relay |

All externally referenced Inventory entities use UUID v4 identifiers in addition to an
internal integer primary key. Quantities use `decimal(20, 6)`, never floats. The unit
belongs to the material and recipe quantities must use that unit.

### 3.2 Material

`Material` represents an inventory-controlled thing. It supports both raw inputs and
finished goods so a Specification can be stocked directly or assembled from materials.

| Field | Type | Rules |
|---|---|---|
| `id` | int | Internal PK |
| `uuid` | string(36) | Unique external identity |
| `code` | string(64) | Unique immutable business code after first stock mutation |
| `name` | string(255) | Required display name |
| `kind` | string(20) | `raw` or `finished` |
| `unit` | string(20) | Required normalized unit, e.g. `piece`, `kg`, `ml` |
| `status` | string(20) | `active` or `inactive` |
| `metadata` | json nullable | Non-accounting extension data |
| `createdAt`, `updatedAt` | datetime immutable | Audit timestamps |

Inactive materials cannot be added to new recipes or new manual adjustments. Existing
reservations and historical ledger rows remain readable.

### 3.3 Stock

One `Stock` represents one material at one Store. It has a local FK to
`Material`, but stores only `storeUuid` for Store identity.

| Field | Type | Rules |
|---|---|---|
| `id` | int | Internal PK |
| `material` | ManyToOne Material | Required local relation |
| `storeUuid` | string(36) | Required scalar Store reference |
| `onHandQuantity` | decimal(20,6) | Physical counted quantity; may become negative only after consumption when allowed |
| `reservedQuantity` | decimal(20,6) | Active reservation total; never negative |
| `allowNegativeStock` | boolean | Defaults to `false` |
| `version` | integer | Doctrine optimistic-lock version |
| `createdAt`, `updatedAt` | datetime immutable | Audit timestamps |

Constraints and indexes:

- Unique `(store_uuid, material_id)`.
- Index `(store_uuid, material_id)` for reservation locking and balance reads.
- Index `(store_uuid, updated_at)` for management reporting.
- `reservedQuantity >= 0` is enforced in domain code and, where portable, a DB check.

There is deliberately no public `setOnHandQuantity()` or `setReservedQuantity()`.
`InventoryService` changes quantities only with a corresponding ledger entry.

### 3.4 SpecificationRecipe And RecipeLine

`SpecificationRecipe` owns the current bill of materials for one catalog Specification.
It stores no Trade entity relation.

| Field | Type | Rules |
|---|---|---|
| `uuid` | string(36) | External recipe identity |
| `specificationUuid` | string(36) | Unique current catalog Specification reference |
| `status` | string(20) | `active` or `inactive` |
| `lines` | OneToMany RecipeLine | At least one active line |
| `createdAt`, `updatedAt` | datetime immutable | Audit timestamps |

Each `RecipeLine` has `material`, `quantityPerUnit decimal(20,6)`, and `sort`. Its
unique constraint is `(recipe_id, material_id)`, so duplicate material demand is
impossible in an authored recipe. The reservation service still aggregates demand by
material because a request can contain repeated Specification lines.

Only one recipe is allowed per Specification in phase one. Updating an existing recipe
replaces its current lines before it can affect a future reservation. Existing
`ReservationLine` rows preserve the resolved material and quantity snapshot and are
never recalculated from a changed recipe.

### 3.5 Finished-Good Fallback

Every inventory-controlled Specification resolves by exactly one of these rules:

1. An active recipe exists for `specificationUuid`: reserve every recipe material at
   `orderQuantity * quantityPerUnit`.
2. No active recipe exists: reserve the active `finished` Material whose `code` equals
   the Specification UUID (`catalogReference`).
3. Neither exists: reject with `SPECIFICATION_NOT_STOCKABLE`.

The direct-finished-good convention avoids a second mapping table in phase one while
remaining explicit and cross-module safe. Material code validation MUST accept UUIDs.
If a human-readable SKU code is later needed, add an Inventory-owned
`SpecificationMaterialMapping` through a separately versioned migration and event
contract; do not read Trade tables as a fallback.

### 3.6 Reservation And ReservationLine

`Reservation` is the idempotent reservation aggregate. `reservationId` is
generated by Store before the request is published.

| Field | Type | Rules |
|---|---|---|
| `id` / `uuid` | int / string(36) | Internal and external identities |
| `reservationId` | string(36) | Unique Store idempotency and correlation key |
| `storeUuid` | string(36) | Required scalar reference |
| `tradeOrderUuid` | string(36) | Required scalar reference |
| `storeOrderUuid` | string(36) | Required scalar reference |
| `status` | string(30) | See state machine below |
| `requestHash` | string(64) | SHA-256 of normalized request payload |
| `expiresAt` | datetime immutable nullable | Reservation deadline snapshot |
| `releasedAt`, `consumedAt` | datetime immutable nullable | Terminal-operation timestamps |
| `createdAt`, `updatedAt` | datetime immutable | Audit timestamps |

`ReservationLine` stores `materialUuid`, `materialCodeSnapshot`, `unitSnapshot`,
`requestedQuantity`, `reservedQuantity`, and the source Specification UUIDs. It must
retain enough data to audit a reservation after materials or recipes are renamed,
deactivated, or altered.

Reservation statuses:

```text
requested -> confirmed -> released
                     \-> consumed
requested -> rejected
```

`requested` exists only while processing the local transaction. A committed reservation
is either `confirmed` or `rejected`. `released`, `consumed`, and `rejected` are terminal.
Duplicate release and consume requests are no-ops if they agree with the existing state;
conflicting requests are operational errors and must not rewrite history.

### 3.7 LedgerEntry

The ledger is append-only. It is the audit source for stock mutations; `Stock`
is the current balance projection.

| Type | On-hand delta | Reserved delta | Reference |
|---|---:|---:|---|
| `initial` | positive or zero | 0 | Manual initialization |
| `adjustment` | positive or negative | 0 | Count correction / operator reason |
| `reservation` | 0 | positive | Reservation UUID |
| `release` | 0 | negative | Reservation UUID |
| `consume` | negative | negative | Reservation UUID / fulfillment reference |

Each row includes `storeUuid`, local `material`, delta quantities, post-mutation balance
snapshots, `referenceType`, `referenceId`, a non-secret actor reference where available,
and timestamps. A uniqueness constraint on `(type, reference_id, material_id)` prevents
the same reservation operation from being applied twice to the same material.

---

## 4. Reservation Processing

### 4.1 Reservation Request Contract

Store publishes `inventory.reservation.requested.v1` only when `INVENTORY_ENABLED=true`.

```json
{
  "reservationId": "inventory-reservation-uuid",
  "storeUuid": "store-uuid",
  "tradeOrderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "items": [
    {
      "lineId": "trade-order-item-uuid",
      "catalogReference": "specification-uuid",
      "quantity": "2.000000"
    }
  ],
  "expiresAt": "2026-07-26T12:30:00+00:00",
  "requestedAt": "2026-07-26T12:00:00+00:00"
}
```

Required validation:

- IDs must be UUID strings; `quantity` is a positive decimal string with at most six
  fraction digits.
- `items` must contain at least one unique `lineId`.
- The reservation deadline must be an ISO-8601 date after `requestedAt`.
- All request identities and the normalized item snapshot must match on duplicate
  `reservationId` delivery.
- Store must not issue more than one active reservation for the same `storeOrderUuid`.

### 4.2 Reservation Algorithm

Within one Inventory transaction:

1. Validate the message envelope and payload version.
2. Insert `InventoryConsumedEvent(eventId)`, or acknowledge immediately if it already
   exists.
3. Find the unique `reservationId`; if present, compare its normalized request hash.
   Return its existing outcome if equal; fail the message if conflicting.
4. Resolve each item using the active recipe or finished-good fallback.
5. Aggregate requirements by Material UUID.
6. Lock existing `(storeUuid, material)` balances in deterministic Material UUID order.
7. Reject with a business outcome when a required material is inactive, unstockable, or
   insufficient while its local negative-stock flag is disabled.
8. On success, create absent balances only when a negative-stock reservation requires
   them, increment `reservedQuantity`, create reservation ledger rows, and persist
   immutable reservation lines.
9. Persist a confirmed or rejected reservation and the matching Inventory outbox event.
10. Commit, then let Messenger acknowledge the message.

The service uses DB row locks for existing balance rows and a unique balance key for
absent-row races. On a balance-key collision it reloads and retries the locked
calculation. It never uses floats or a read-then-write quantity update without locking.

### 4.3 Rejection Codes

| Code | Meaning | Retry behavior |
|---|---|---|
| `SPECIFICATION_NOT_STOCKABLE` | No active recipe or direct finished material | Business rejection |
| `MATERIAL_INACTIVE` | Resolved material cannot be newly reserved | Business rejection |
| `OUT_OF_STOCK` | One or more balances are insufficient | Business rejection |
| `RESERVATION_EXPIRED` | Request arrived after its deadline | Business rejection |
| `INVALID_RESERVATION_REQUEST` | Schema, quantity, or correlation validation failed | DLQ / operator correction |
| `RESERVATION_CONFLICT` | Duplicate business key with different content | DLQ / critical alert |

Business rejections commit a rejected reservation, the Inbox record, and the rejected
outbox event. Transport or database failures roll back and retry through Messenger.

### 4.4 Confirmation And Rejection Events

Inventory publishes exactly one initial outcome per reservation.

`inventory.reservation.confirmed.v1`:

```json
{
  "reservationId": "inventory-reservation-uuid",
  "storeUuid": "store-uuid",
  "tradeOrderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "confirmedAt": "2026-07-26T12:00:02+00:00"
}
```

`inventory.reservation.rejected.v1`:

```json
{
  "reservationId": "inventory-reservation-uuid",
  "storeUuid": "store-uuid",
  "tradeOrderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "reasonCode": "OUT_OF_STOCK",
  "reason": "One or more required materials are unavailable.",
  "rejectedAt": "2026-07-26T12:00:02+00:00"
}
```

Store validates all correlation UUIDs against its local `StoreOrder`. It accepts only a
matching confirmed event while the order is `awaiting_inventory`; it rejects only a
matching rejected event in that state. Duplicate or late events are harmless no-ops.
Store maps Inventory `OUT_OF_STOCK` to its documented `OUT_OF_STOCK` rejection code and
does not expose material details to customers.

---

## 5. Lifecycle And Compensation

### 5.1 Store Integration

When Inventory is enabled, Store changes its present auto-accept behavior:

```text
trade.order.created.v1
  -> Store creates StoreOrder
  -> validates Store policy
  -> generates reservationId
  -> StoreOrder.awaitInventory(reservationId)
  -> writes inventory.reservation.requested.v1 to Store Outbox
  -> Inventory outcome
  -> Store accepts or rejects and emits its existing Trade result event
```

The `StoreOrder` update and Store request outbox insert happen in the same Store local
transaction. Inventory must not be invoked synchronously from the Store consumer.

When Inventory is disabled, Store does not create a reservation ID or a request event;
it follows the current direct acceptance path. The absence of a reservation is therefore
intentional and not an Inventory error.

### 5.2 Release On Cancellation

Trade must eventually publish `trade.order.cancelled.v1` for Store-scoped orders. Store
consumes it, marks the local operation cancelled, and publishes
`inventory.reservation.release.requested.v1` if it has a `reservationId`.

```json
{
  "reservationId": "inventory-reservation-uuid",
  "storeUuid": "store-uuid",
  "tradeOrderUuid": "trade-order-uuid",
  "storeOrderUuid": "store-order-uuid",
  "reason": "trade_order_cancelled",
  "requestedAt": "2026-07-26T12:05:00+00:00"
}
```

Inventory consumes the release idempotently. For a confirmed reservation it decrements
each balance's `reservedQuantity`, writes release ledger entries, marks the reservation
released, and publishes `inventory.reservation.released.v1`. For rejected, released, or
consumed reservations it produces no second quantity mutation. Store does not need to
wait for release confirmation to expose cancellation to Trade.

### 5.3 Consumption

Inventory is consumed only at a later explicit Store fulfillment boundary, never merely
because an order was paid. A future `inventory.reservation.consume.requested.v1` must
carry the reservation and Store order IDs. It atomically decrements both on-hand and
reserved quantity and writes `consume` ledger entries.

Phase one creates the `consume` state and ledger contract but does not add a Store
fulfillment consumer until the product's fulfillment policy is approved. This keeps the
initial reservation rollout reversible and prevents accidental stock deduction.

### 5.4 Expiry And Reconciliation

`expiresAt` is a snapshot supplied by Store. A future scheduled Inventory command finds
expired confirmed reservations, releases them idempotently, and emits a release event.
Store/Trade then decide whether to reject or cancel the commercial order. The first
implementation may omit automatic expiry only if Store acceptance deadlines and
cancellation release are implemented together; it must not silently retain expired
reservations indefinitely.

Inventory reconciliation compares each balance against its ledger deltas. Any repair is
a new `adjustment` ledger entry, never a direct balance overwrite.

---

## 6. Inbox, Outbox, And Messenger

### 6.1 Persistent Message Records

`InventoryConsumedEvent` follows `StoreConsumedEvent`:

| Field | Purpose |
|---|---|
| `eventId` | Unique broker-event idempotency key |
| `topic` | Versioned source event topic |
| `aggregateId` | Source aggregate identity |
| `payloadHash` | SHA-256 full-envelope audit value |
| `processedAt` | Consumption time |

`InventoryOutboxMessage` follows `StoreOutboxMessage` and includes UUID `eventId`,
topic, aggregate type/ID, payload, occurrence time, availability time, publication time,
attempt count, and last error. Index due unpublished rows by `(published_at,
available_at, id)`.

### 6.2 Event Envelope

All Inventory messages use the established versioned envelope:

```json
{
  "eventId": "event-uuid",
  "type": "inventory.reservation.confirmed",
  "version": 1,
  "aggregateType": "inventory_reservation",
  "aggregateId": "inventory-reservation-uuid",
  "correlationId": "store-order-uuid",
  "causationId": "source-event-uuid",
  "occurredAt": "2026-07-26T12:00:02+00:00",
  "payload": {}
}
```

The publisher is a transport adapter only. It fetches due outbox rows, maps known topics
attempts with delayed availability. It does not make stock decisions.

### 6.3 Message Routing

New explicit DTOs are required for:

| DTO | Published by | Consumed by |
|---|---|---|
| `ReservationRequestedMessage` | Store | Inventory |
| `ReservationConfirmedMessage` | Inventory | Store |
| `ReservationRejectedMessage` | Inventory | Store |
| `ReservationReleaseRequestedMessage` | Store | Inventory |
| `ReservationReleasedMessage` | Inventory | Store, if operational visibility needs it |

`config/packages/messenger.yaml` routes all DTOs to `async`. The scheduler invokes
`app:inventory:outbox:publish` alongside the existing Trade and Store publishers. The
worker remains `messenger:consume async`; no consumer runs in PHP-FPM.

---

## 7. API And Authorization

Inventory APIs are management APIs in phase one. Customers and Store staff do not see
material names, stock levels, recipes, negative-balance information, or ledger details.
Store staff continue to operate through StoreOrder endpoints.

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET/POST/PUT | `/api/v1/manage/inventory/materials` | `ROLE_ADMIN` | Manage materials |
| GET/POST/PUT | `/api/v1/manage/inventory/recipes` | `ROLE_ADMIN` | Manage one Specification recipe and lines |
| GET | `/api/v1/manage/inventory/stocks` | `ROLE_ADMIN` | List persisted balances |
| GET | `/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}` | `ROLE_ADMIN` | Balance or virtual zero view |
| POST | `/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/adjust` | `ROLE_ADMIN` | Initial stock or audited adjustment |
| PUT | `/api/v1/manage/inventory/stocks/{storeUuid}/{materialUuid}/policy` | `ROLE_ADMIN` | Set `allowNegativeStock` |
| GET | `/api/v1/manage/inventory/reservations` | `ROLE_ADMIN` | Reservation audit/search |
| GET | `/api/v1/manage/inventory/reservations/{reservationId}` | `ROLE_ADMIN` | Reservation detail and resolved lines |
| GET | `/api/v1/manage/inventory/ledger` | `ROLE_ADMIN` | Ledger audit/search |

All mutation endpoints require a reason, reject floating-point quantities, and use
service methods rather than generic entity updates for stock or reservation fields.
Generic CRUD is acceptable only for harmless material metadata and recipe authoring;
the controller allowlists must exclude all accounting fields.

### 7.1 Management Adjustment Request

```json
{
  "quantityDelta": "50.000000",
  "reason": "initial_receipt",
  "referenceId": "purchase-order-123",
  "allowNegativeStock": false
}
```

`quantityDelta` is signed. A negative adjustment must not drive on-hand below reserved
quantity unless `allowNegativeStock=true`; otherwise it would invalidate already
confirmed reservations. The operation is idempotent for the same management reference
when a reference is supplied.

---

## 8. File Structure

```text
src/Inventory/
|-- Command/
|   `-- PublishOutboxCommand.php
|-- Controller/Manage/
|   |-- MaterialController.php
|   |-- RecipeController.php
|   |-- StockController.php
|   |-- ReservationController.php
|   `-- LedgerController.php
|-- Entity/
|   |-- Material.php
|   |-- Stock.php
|   |-- SpecificationRecipe.php
|   |-- RecipeLine.php
|   |-- Reservation.php
|   |-- ReservationLine.php
|   |-- LedgerEntry.php
|   |-- InventoryConsumedEvent.php
|   `-- InventoryOutboxMessage.php
|-- Message/
|-- MessageHandler/
|-- Repository/
|-- Service/
|   |-- InventoryService.php
|   |-- InventoryServiceInterface.php
|   |-- InventoryOutboxService.php
|   `-- RecipeResolver.php
`-- Resources/config/
    `-- services_inventory.yaml
```

Store changes remain limited to its existing boundary: configuration binding, request
event publishing, and Inventory outcome/release handlers. Trade changes remain limited
repository, or domain service.

---

## 9. Persistence And Migration Plan

One Inventory migration creates the phase-one tables and only local foreign keys:

| Table | Key constraints and indexes |
|---|---|
| `inventory_material` | unique `uuid`, unique `code`, status index |
| `inventory_stock` | unique `(store_uuid, material_id)`, balance lookup indexes, FK to material |
| `inventory_specification_recipe` | unique `uuid`, unique `specification_uuid` |
| `inventory_recipe_line` | unique `(recipe_id, material_id)`, FKs to recipe/material |
| `inventory_reservation` | unique `uuid`, unique `reservation_id`, unique active/request key for Store order |
| `inventory_reservation_line` | unique `(reservation_id, material_uuid)`, FK to reservation |
| `inventory_ledger_entry` | operation/reference/material idempotency key, store/material/time indexes |
| `inventory_consumed_event` | unique `event_id`, processed-time index |
| `inventory_outbox_message` | unique `event_id`, due-unpublished index |

No migration references `trade_specification`, `trade_order`, `store_order`, or
`store` through a foreign key. UUID relation validity belongs to the event contract and
local snapshots, not database joins.

---

## 10. Testing Strategy

### 10.1 Unit Tests

- Material validation, status changes, and immutable business code after stock activity.
- Recipe uniqueness, line quantity validation, and material activation checks.
- Stock available-quantity calculation and local negative-stock policy.
- Reservation state transitions and immutable line snapshots.
- Ledger entry construction and reference idempotency.
- Inventory Inbox/Outbox entity metadata and publisher retry behavior.

### 10.2 Service And Integration Tests

- Read of an absent balance returns a virtual zero view and does not persist a row.
- Manual adjustment creates a balance and a matching ledger entry atomically.
- Recipe expansion aggregates repeated Specifications and repeated material demand.
- Direct finished-good fallback reserves a Material coded with the Specification UUID.
- Recipe or direct material absence produces `SPECIFICATION_NOT_STOCKABLE`.
- Disabled negative stock rejects insufficient availability without partial reservation.
- Enabled negative stock creates or over-reserves a balance with auditable ledger rows.
- Concurrent reservation attempts do not oversell when negative stock is disabled.
- Duplicate `eventId` is a no-op; duplicate `reservationId` with identical input returns
  the original result; a conflicting duplicate fails safely.
- Database or transport errors roll back inbox, balances, reservation, ledger, and
  outbox together.
- Release is idempotent and makes reserved quantity available again.

### 10.3 Cross-Bundle Tests

- `INVENTORY_ENABLED=false` keeps current Store direct acceptance and creates no
  Inventory event or records.
- `INVENTORY_ENABLED=true` creates a Store request, waits in `awaiting_inventory`, and
  accepts only after a matching Inventory confirmation.
- Inventory rejection becomes Store `OUT_OF_STOCK`, then follows the existing Trade
  rejection/cancellation workflow.
- Trade cancellation leads to Store release request and Inventory release without
  changing historical reservation lines.
- Invalid, stale, duplicate, and mismatched correlation events do not alter Store or
  Trade order state.

---

## 11. Implementation Sequence

1. Add this bundle's service configuration, routes, message DTO routing, and migration.
2. Implement Material, Stock, recipes, repositories, and management APIs.
3. Implement audited manual stock adjustment and virtual-zero read behavior.
4. Implement reservation service, ledger, Inbox, Outbox, publisher, and tests.
5. Add Store request/outcome behavior behind `INVENTORY_ENABLED`.
6. Add cancellation-driven release and cross-bundle integration tests.
7. Add fulfillment-driven consumption and expiry scheduling only after the corresponding
   Store/Trade business policies are approved.

This order keeps the Inventory module independently testable while making the Store
integration an explicit, feature-gated rollout rather than an implicit change to every
order flow.
