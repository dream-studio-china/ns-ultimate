# Counter business module

Counter owns store-scoped counter records, dining sessions, and bindings between counters and Trade orders. Trade-specific orchestration stays in this module's adapters and merge service; Trade and the core subtree do not depend on this module.

## Manage counters

Administrators manage counters under `/api/v1/manage/counters`. Store staff manage their own counters under `/api/v1/store/{scopeId}/counters`; the store scope is authorized by the Authorization and Store membership voters, and the API assigns the store UUID from that scope rather than trusting request data. A counter requires `storeUuid`, `code`, and `name` for administrator creation; `capacity`, `sort`, `metadata`, and `enabled` are optional. `sort` is a non-negative integer used for display ordering, and `metadata` is an optional JSON object for counter-specific data. Counter codes are unique within a store. Store ownership cannot be changed through the store update endpoint.

Authenticated customers can list enabled counters for the active store context at `/api/v1/app/counters`, selected implicitly with the `X-Store-Code` request header, as with Trade order APIs. Without a store context the list is empty. Authenticated app users can list non-closed orders in the counter's active session at `/api/v1/app/counters/{counterUuid}/orders`, including orders placed on previous days. This replaces the former current-day listing. `POST /api/v1/app/counters/{counterUuid}/orders/merge` combines eligible unpaid orders in the counter's active `CounterSession` into a new order for the caller, using the existing Trade pricing flow. Source orders become terminal `merged` orders and expose their destination UUID in the listing. Orders carrying an existing invoice are rejected rather than risking payment of both source and merged orders. Sessions open automatically when a counter order is first bound and can be ended by store staff at `/api/v1/store/{scopeId}/counters/{counterUuid}/sessions/end` once their orders are closed. The response includes order status, total and safe item display/pricing fields, but omits customer details and internal item cost/profit. Store staff can list, bind, and unbind order counters under `/api/v1/store/{scopeId}/order-counters`. Administrators can inspect bindings at `/api/v1/manage/order-counters`.

Run `php bin/console app:counter:seed-authorization` after migrations to register the store-scoped permissions and the `store_counter_manager` system role. Assign that role to store memberships through the existing Authorization workflow.

### Merge concurrency and deferred inventory reconciliation

All automatic, store, and administrator bindings use the same session assignment path. Session operations lock the counter before reading session state; a nullable unique active-counter identity additionally prevents duplicate open sessions. Direct ORM binding writes must open an explicit transaction before `flush()` because Doctrine dispatches `onFlush` before starting its implicit transaction. Normal Trade creation and Counter CRUD services provide that transaction. The migration backfills existing bindings through queued SQL, including dry-run support.

The composed Trade order service is decorated by `CounterTradeOrderService`. Payment refreshes and locks the source order inside a transaction before calling the upstream payment flow. Merge takes the same order-row locks in UUID order before checking status and invoice references. An existing invoice rejects merging, and an `OrderMerge` record permanently blocks payment of a merged source even if another workflow later changes its status. SQLite tests exercise refresh and rollback behavior; production MySQL uses pessimistic row locks.

**Settlement-only merge remains incomplete.** The target is confirmed immediately and does not emit a Trade order-created event, so it does not itself create a Store order or request another inventory reservation. Source Store orders and their reservations remain unchanged. Sources must already have been accepted by Store. Source orders are marked `merged`, and settlement cancellation and re-merge are blocked. Each source retains `metadata.counterMergeInventoryReconciliationRequired: true`; this marker does not implement reconciliation. Existing merges created before this change may already have duplicate Store orders or reservations and are not repaired by this change. Source fulfillment, reservation expiry or failure, settlement completion, refunds, printing and production concurrency still require end-to-end reconciliation and verification. Do not enable this flow for production inventory-gated stores.

Merge requires at least two eligible unpaid orders, including one owned by the caller. Orders bound to more than one counter are rejected rather than losing their other counter associations. This does not provide request idempotency when new orders arrive between retries, nor does it replace inventory or fulfillment reconciliation.

The `/api/v1` routes are the canonical API used by Admin. The earlier unprefixed Counter routes remain available for compatibility, with the same authorization requirements.

The business Admin menu includes Counter management and immutable OrderCounter bindings. Collection files at `business/admin/config/collections/counter/*.js` export named entity maps. The composed Vite server watches the business Admin directory, including newly added glob matches outside the core root.

## Bind counters when creating an order

Use the existing app order endpoint and include the counter UUID in order metadata:

```json
{
  "items": [],
  "metadata": {
    "counterUuid": "<counter-uuid>"
  }
}
```

For orders spanning multiple counters, use `metadata.counterUuids` as a list. The singular key remains supported for a single counter. When an order is inserted, the adapter gets store ownership from Trade's `OrderStoreLifecycle` record—not client-supplied metadata—validates that each referenced counter is enabled and belongs to that store, then creates `OrderCounter` rows in the same Doctrine flush. Invalid references fail the order transaction. Orders without counter metadata are unaffected.

`OrderCounter` stores the Trade order UUID as a scalar cross-module reference and uses Doctrine relations to `Counter` and `CounterSession` inside this module. It snapshots the counter code and name. `OrderMerge` records source-to-target order UUIDs without coupling the Trade entity to Counter.
