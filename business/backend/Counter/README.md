# Counter business module

Counter owns store-scoped counter records, dining sessions, and bindings between counters and Trade orders. Trade-specific orchestration stays in this module's adapters and merge service; Trade and the core subtree do not depend on this module.

## Manage counters

Administrators manage counters under `/api/v1/manage/counters`. Store staff manage their own counters under `/api/v1/store/{scopeId}/counters`; the store scope is authorized by the Authorization and Store membership voters, and the API assigns the store UUID from that scope rather than trusting request data. A counter requires `storeUuid`, `code`, and `name` for administrator creation; `capacity`, `sort`, `metadata`, and `enabled` are optional. `sort` is a non-negative integer used for display ordering, and `metadata` is an optional JSON object for counter-specific data. Counter codes are unique within a store. Store ownership cannot be changed through the store update endpoint.

Authenticated customers can list enabled counters for the active store context at `/api/v1/app/counters`, selected implicitly with the `X-Store-Code` request header, as with Trade order APIs. Without a store context the list is empty. Authenticated app users can list the current day's non-closed orders for a counter at `/api/v1/app/counters/{counterUuid}/orders`; the store's timezone defines the day. `POST /api/v1/app/counters/{counterUuid}/orders/merge` combines eligible unpaid orders in the counter's active `CounterSession` into a new order for the caller, using the existing Trade pricing flow. Source orders become terminal `merged` orders and expose their destination UUID in the listing. Orders carrying an existing invoice are rejected rather than risking payment of both source and merged orders. Sessions open automatically when a counter order is first bound and can be ended by store staff at `/api/v1/store/{scopeId}/counters/{counterUuid}/sessions/end` once their orders are closed. The response includes order status, total and safe item display/pricing fields, but omits customer details and internal item cost/profit. Store staff can list, bind, and unbind order counters under `/api/v1/store/{scopeId}/order-counters`. Administrators can inspect bindings at `/api/v1/manage/order-counters`.

Run `php bin/console app:counter:seed-authorization` after migrations to register the store-scoped permissions and the `store_counter_manager` system role. Assign that role to store memberships through the existing Authorization workflow.

### Merge concurrency and deferred inventory reconciliation

All automatic, store, and administrator bindings use the same session assignment path. Session operations lock the counter before reading session state; a nullable unique active-counter identity additionally prevents duplicate open sessions. Direct ORM binding writes must open an explicit transaction before `flush()` because Doctrine dispatches `onFlush` before starting its implicit transaction. Normal Trade creation and Counter CRUD services provide that transaction. The migration backfills existing bindings through queued SQL, including dry-run support.

The composed Trade order service is decorated by `CounterTradeOrderService`. Payment refreshes and locks the source order inside a transaction before calling the upstream payment flow. Merge takes the same order-row locks in UUID order before checking status and invoice references. An existing invoice rejects merging, and an `OrderMerge` record permanently blocks payment of a merged source even if another workflow later changes its status. SQLite tests exercise refresh and rollback behavior; production MySQL uses pessimistic row locks.

**Inventory and Store fulfillment reconciliation are intentionally deferred.** Merge changes each source Trade order to `merged`; it does not cancel or transfer the source Store order or release its inventory reservation. The target follows normal Trade order creation, including its Store/outbox flow, so duplicate Store work and inventory reservations remain possible. Each source carries `metadata.counterMergeInventoryReconciliationRequired: true` and its target UUID for later reconciliation. This marker is not an implemented release worker or an automatic fulfillment migration. Do not treat merge as production-ready for inventory-gated stores until reservation release/transfer and Store fulfillment behavior are implemented and verified.

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
