# Counter merge follow-up

## Outcome

Added Counter sessions and order merging without editing `core/`. The composed Trade service decorator serializes payment against merge at the source-order row; merged source UUIDs are recorded and cannot start payment. Automatic, store, and administrator bindings share the Counter session assignment path, with counter-level locking and a database uniqueness guard for the active session. Existing bindings are backfilled by the Counter migration.

## Explicitly deferred

- **Inventory reservation reconciliation:** merging does not transfer or release a source order's reservation. Source orders carry `metadata.counterMergeInventoryReconciliationRequired: true`; this is an operator/reconciliation marker only, not a worker or release request.
- **Store fulfillment reconciliation:** a source Store order is not cancelled or transferred when its Trade order becomes `merged`. Creating the target uses the normal Store/outbox flow, so duplicate Store work or reservations may result. Do not enable this flow for inventory-gated production use until a business-owned reconciliation operation safely releases/transfers reservations and closes or replaces the source Store order.
- **Production database verification:** row-lock concurrency and the forward migration have not been exercised against a live MySQL instance. SQLite tests cover schema/backfill behavior and transaction-level logic, not production contention.

## Validation

- Composed PHPUnit: 200 tests, 4,776 assertions passed.
- Counter source PHPStan analysis passed.
- Documentation link check and `git diff --check` passed.
- No core files were changed. The unrelated Qiniu PHP deprecation warning remains.

## Next step

Implement and test an idempotent Counter-owned Store/inventory reconciliation workflow, including reservation release/transfer and terminal handling of the source Store order. Then run migration and concurrent pay/merge/session-open/session-end checks against the supported MySQL version before enabling production use.
