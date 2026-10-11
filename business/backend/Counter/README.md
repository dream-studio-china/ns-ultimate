# Counter business module

Counter owns store-scoped counter records and the many-to-many binding between counters and Trade orders. It depends on Trade only through the temporary `TradeCounterAdapter`; Trade and the core subtree do not depend on this module.

## Manage counters

Administrators manage counters under `/api/v1/manage/counters`. Store staff manage their own counters under `/api/v1/store/{scopeId}/counters`; the store scope is authorized by the Authorization and Store membership voters, and the API assigns the store UUID from that scope rather than trusting request data. A counter requires `storeUuid`, `code`, and `name` for administrator creation; `capacity` and `enabled` are optional. Counter codes are unique within a store. Store ownership cannot be changed through the store update endpoint.

Authenticated customers can list enabled counters for an active store at `/api/v1/app/stores/{storeUuid}/counters`. Store staff can list, bind, and unbind order counters under `/api/v1/store/{scopeId}/order-counters`; the app order-counter endpoint only returns bindings for the authenticated customer's own order. Administrators can inspect bindings at `/api/v1/manage/order-counters`.

Run `php bin/console app:counter:seed-authorization` after migrations to register the store-scoped permissions and the `store_counter_manager` system role. Assign that role to store memberships through the existing Authorization workflow.

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

`OrderCounter` stores the Trade order UUID as a scalar cross-module reference and uses a Doctrine relation to `Counter` inside this module. It snapshots the counter code and name. A future dining session can aggregate these bindings without changing the meaning of `Counter`.
