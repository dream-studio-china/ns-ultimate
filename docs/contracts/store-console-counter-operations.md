# Store counter operations contract

## Status and ownership

Implemented by `business/backend/Counter/src/Controller/Store/CounterOperationsController.php` and `CounterSessionOperations`, with reusable employee order pricing, catalog and write helpers in `business/backend/Store/`. Consumed by `apps/store-console/`. The controller declarations and app adapter are the authoritative route and DTO definitions. No Core files or customer order routes are replaced.

This enables counter-session inspection, participant/notes updates, anonymous employee ordering in an active session, additional orders, authorized replacement or cancellation of eligible customer or employee orders and ending closed sessions. **It does not implement checkout, discretionary discounts, collection, customer payment links, refunds, financial reports or employee print commands.** The independent demo payment entry remains demo-only.

Terminology is business-neutral on the backend: a counter is a service point, a counter session is one service period, and its orders carry staff or customer origin without a separate provenance entity. The store console may label the same capability "Cashier" or "tables"; those job labels do not exist in backend authorization.

## Scope and authorization

The canonical prefix is `/api/v1/store/{storeUuid}/counter-operations`. Requests require an authenticated user, active membership and the exact Store-voter permission for the requested store. UI grants are only a snapshot. A membership's role label grants nothing by itself; administrator handling retains the membership requirement.

| Operation | Permission |
| --- | --- |
| Counter session list, detail and session summary | `store:counter_session:read` |
| Associated order rows and session amounts | additionally `store:counter_order:read` |
| Participant count and session notes | `store:counter_session:update` |
| End a fully closed session | `store:counter_session:end` |
| Orderable catalog, quotes for new orders | `store:counter_order:create` |
| Quotes for replacement | `store:counter_order:replace` |
| Create an order in the active session | `store:counter_order:create` |
| Replace an eligible session order | `store:counter_order:replace` |
| Cancel an eligible session order | `store:counter_order:cancel` |
| Preview and merge eligible session orders | `store:counter_order:merge` |

Session reads are independent of order reads. Without `store:counter_order:read`, list/detail responses omit `orders`, `openAmount`, `orderedAmount`, `openOrderCount` and `paymentHold`, and return `ordersVisible: false`. The canonical `version` still covers the full server state so every actor receives the same token for the same snapshot. A client must not infer zero amounts from a redacted response.

Existing `store:counter` table configuration and `store:order` fulfillment permissions do not imply session or order-entry grants, and the reverse is also true. Catalog reads accept `store:counter_order:create` or `replace`. Quotes require `replace` when `replaceOrderUuid` is supplied, otherwise `create`. Replacement does not require `create`; permission to add orders does not imply permission to change them. There is no employee open command.

## Capability roles

`app:counter:seed-authorization` registers the counter configuration permissions and, separately, these capability roles:

| Role | Grants |
| --- | --- |
| `store_counter_manager` | existing `store:counter` and `store:order_counter` configuration grants only |
| `store_counter_session_operator` | `store:counter_session:read`, `update`, `end`; `store:counter_order:read`, `create` |
| `store_counter_order_editor` | `store:counter_session:read`; `store:counter_order:read`, `replace` |
| `store_counter_order_canceller` | `store:counter_session:read`; `store:counter_order:read`, `cancel` |
| `store_counter_order_merger` | `store:counter_session:read`; `store:counter_order:read`, `merge` |

The command is repeatable and creates no memberships or user assignments. It never adds the new session/order permissions to an existing `store_counter_manager`; capability grants are opt-in. `app:store:seed-staff-authorization` registers Store capability roles separately:

| Role | Grants |
| --- | --- |
| `store_availability_operator` | `store:product:read`, `store:product:availability` |
| `store_order_reader` | `store:order:read` |
| `store_order_operator` | `store:order:read`, `accept`, `reject`, `fulfill`, `verify` |
| `store_catalog_operator` | `store:product`, `product_category`, `specification` CRUD; `catalog_image:create`; `product:availability` |
| `store_operation_auditor` | `store:operation:read` |

A frontend job such as "cashier" is a chosen combination of these capability roles, not a backend role. Assign only the grants the employee needs through existing authorization management; audit history remains separately granted.

## Session and display guarantees

Counter list/detail reads identify the current `CounterSession`, participant count, notes, start time and revision. The existing Counter binding path automatically creates a session when the first customer order is bound; it can also be invoked by existing administrator/staff binding APIs. Employee commands cannot open a session or place an order at an idle counter. Automatically opened sessions may have no participant count; authorized staff can update it afterwards. Session edits do not change orders or the configured capacity. Ending requires every bound order to be terminal (`completed`, `cancelled`, `refunded` or `merged`); payment/fulfillment in progress is not terminal. Disabled counters remain inspectable but cannot receive new orders.

Lists search by counter name/code before pagination, with literal wildcard characters, matching totals and bounded pages. Catalog reads search active, non-deleted store-owned products/specifications; unavailable business products are shown as unavailable rather than made orderable. Catalog prices are indicative; only the server quote is confirmable. Global/cross-store catalog items are not admitted to employee carts.

Session DTOs distinguish Trade state, Store operational state, invoice presence and recorded payment status. They expose restricted item names, quantities and monetary snapshots, order notes and `canReplace`/`canCancel` eligibility. They omit customer identities, delivery details, arbitrary metadata, employee identities and cost/profit fields. Staff identities remain in command audit receipts. Legacy lines without catalog UUIDs remain displayable, not orderable cart references.

Full order rows also expose nullable `changeBlockedReason` and `replaceBlockedReason` codes for employee diagnostics. These describe current eligibility, not permission grants; every write is checked again. Redacted session responses omit order rows and reasons.

Full session order rows expose `mergedIntoOrderUuid` and `mergedSourceOrderUuids` for existing merge records when both endpoints are bound to the same session. Links are omitted across session boundaries. Replacement links remain in command receipts rather than this merge projection.

`openAmount` sums only draft/pending/confirmed orders. `orderedAmount` excludes cancelled/refunded/merged sources. Neither is an authoritative settlement amount, amount due, revenue or collection result. Amounts use integer minor units, the store currency and JavaScript-safe bounds; mixed-currency sessions fail closed instead of combining currencies. Missing/inconsistent bindings also fail closed. A session is bounded to 200 bound orders; an oversized historical session requires operator investigation rather than silently truncating totals.

## Quote, create and additional orders

Quote inputs contain the latest session `version`, 1–30 distinct `{specificationUuid, quantity}` rows, notes, optional custom `metadata` and an optional `replaceOrderUuid`. Quantities are integers from 1–99. Ownership, actor/customer identities, prices, totals and unknown top-level fields cannot be supplied. Notes are bounded. The request body is limited to 64 KiB.

Employee order metadata is a JSON object of custom keys; null, nonempty arrays, empty keys, keys beginning with `_`, and the system-owned keys `counterUuid`, `counterUuids`, `counterSessionUuid`, `counterSettlement` and `mergedIntoOrderUuid` are rejected. The same metadata must be supplied to quote and create/replace: the quote token binds its value. On replacement the supplied top-level keys overlay the original metadata; omitted keys are retained and nested objects are replaced, not recursively merged. Metadata may affect downstream rules or delivery data and must not contain credentials or be treated as a trusted authorization, payment or ownership source. Session reads do not expose arbitrary metadata. The API is supported independently of the current staff UI, which does not send it.

`StaffOrderPricing` runs the registered Trade calculator pipeline with the actual store code and currency. New staff additions use `user = null`. Replacement uses the original order's customer for both pricing and the replacement order, never the acting employee; customer-owned orders remain visible to that customer. Existing sold-out/sale-window rules apply. Inventory and acceptance policies are preserved in the server-created Store snapshot; a quote is not a stock reservation or acceptance guarantee.

The quote returns restricted item/amount DTOs and an opaque content token bound to the counter/session/version, calculated line snapshots, total, currency, notes and current Store policy. Replacement tokens also bind the original order identity, customer and stored metadata without exposing them. It is not a persisted quote, stock hold, payment credential or timed reservation. Submission recalculates once, compares that result's token and uses **that same calculation** to create the order. Changed price, availability, session or policy rejects the request; a new amount is never silently accepted.

Each creation writes a new anonymous Trade order in the same active session through `StaffOrderService` and the ordinary Trade creation/workflow/outbox path with channel `staff`; Store projections, acceptance, inventory, verification and configured downstream printing still need their normal workers. No fake accepted/paid/printed state is created. Additional items create another order, not modifications to previously issued lines. An invoice on a still-unpaid order blocks additions until the existing payment workflow is resolved elsewhere.

## Order modification and cancellation

Authorized staff can replace customer-owned or anonymous employee orders in the current session. No employee-origin table or metadata marker is required. Ownership comes from the existing Counter binding and trusted Trade lifecycle, not caller-supplied metadata.

The source must be pending/confirmed, bound only to this counter, without an invoice or payment record, pending acceptance/fulfillment, not verified, and projected to a Store order still pending validation with no reservation. The trusted creation event must explicitly require no inventory, and no reservation record may exist. Merged sources/targets, missing projections/events, payment-in-progress and already processed orders fail closed. A replacement also needs an enabled counter, current quote and session version, plus a non-empty reason of at most 500 characters.

Cancellation uses the same eligibility checks but does not require an enabled counter and does not prefill catalog lines. It requires the current session `version`, the target `orderUuid` and a non-empty reason of at most 500 characters. The command marks the Trade order cancelled, applies the existing order workflow, cancels its projected Store order and retains the Counter binding so the cancelled order remains visible in the session. It never deletes order history, refunds money or frees an existing reservation beyond the underlying cancellation behavior. `canCancel` is false for paid, accepted, fulfilling, verified, merged, reservation-backed or cross-counter targets.

The replacement command atomically creates the server-priced replacement order, cancels the original through Store order services/workflows and persists the existing `StaffOperation` receipt. Historical order lines are not overwritten. The original customer and metadata are retained, while counter references and the new Store snapshot are set by the server. The new order follows current acceptance/inventory policies and normal workers. Failure rolls back the replacement, cancellation, bindings, outbox messages and receipt.

The receipt's restricted before snapshot includes the old order's display/amount state and reason; its result includes `replacedOrderUuid` and the new `orderUuid`, with the employee and timestamp recorded by the existing receipt. Cancellation records the same restricted order snapshot and reason, returning `orderUuid` and `status: cancelled`. This is the current tracking mechanism, not a claim that future Core-wide auditing already exists. No new provenance/entity table is introduced. These endpoints do not perform refunds or modifications to paid/fulfilled orders. The UI keeps cancelled orders visible after refresh; replacement linkage is retained in the audit receipt and command response.

## Employee order merge

`POST /counters/{uuid}/merge/preview` accepts the current session `version` and optional `user` UUID. Omission selects the authenticated employee as the new order owner; an explicit UUID selects an existing user, and null or an invalid user is rejected. Preview returns source order UUIDs, `targetUserUuid`, recalculated items and totals, original source total, difference and a `quoteToken`. Prices can change during recalculation; the caller must review the difference. The token binds the session, order state, target owner, store policy and calculated result.

`POST /counters/{uuid}/merge/confirm` additionally requires `commandUuid` and `quoteToken`. It rechecks the version and recalculates, then creates the target Trade order for the selected owner, records existing `OrderMerge` links, marks sources merged and cancels their Store projections in the command transaction. The receipt records the employee separately from the target owner. This is order consolidation, not collection or proof of payment. The customer merge endpoint retains its separate existing behavior.

The employee path requires at least two confirmed eligible orders and fails closed if any non-closed order is ineligible, including payment, multi-counter binding, inventory-required/reserved, missing projection or progressed fulfillment states. It does not transfer inventory reservations or reverse external effects. Source orders may already have produced print requests; configure and validate printing to avoid duplicate work. Real MySQL concurrency, worker races, and actual printer behavior remain unverified. Current employee UI does not call these merge routes.

## Transactions, retries and limitations

All writes reuse the [operations contract](store-console-operations.md)'s `StaffCommandService` store/actor/command receipts, atomic audit/outbox behavior and payload-conflict rules. `commandUuid` and the latest `version` are required. Reads/quotes do not create successful write receipts. Receipt replay still requires current route permissions. A replay result identifies the historical counter/session/order, so clients refresh rather than treating it as current state.

Commands and quotes take store, counter/session, existing order/lifecycle/Store-order and sorted catalog-row locks. This coordinates these employee commands and the existing Counter payment/merge order locks; it is **not** a guarantee against every legacy CRUD, worker or cross-module race. Quote/catalog locks do not reserve inventory. Store consumers and legacy mutation paths do not universally acquire the same locks. SQLite tests establish validation, rollback and replay behavior, not MySQL isolation/deadlock/load correctness. Inventory-gated production operation and previously documented Counter merge limitations require separate acceptance testing.

The UI never retries network-failed writes automatically. Unchanged manual ordering retries retain the original command key and payload. After a submission attempt the review cannot be edited into a different order; close and refresh the session to investigate uncertain outcomes before starting another order. An offline browser pauses submission. These UI safeguards are not a substitute for backend receipts or payment reconciliation.

## Rollout and verification

Apply Counter migration `Version20261031100000` in addition to the operations migration described in the operations contract. It adds nullable `participant_count`, nullable `notes` and a `revision` default for existing sessions. Review the target database, backup and migration plan first; MySQL DDL is not claimed to be transactional. Rolling back removes the new session data and must not be used as a data-preserving business rollback.

Deliberately seed and assign scoped permissions after deployment. The app selects the session board when `store:counter_session:read` is granted and retains the separate table-configuration tab only when `store:counter` read is also granted. No grant means no synthesized session data. Existing Counter customer ordering/merging and the fulfillment page remain separate interfaces. A missing migration/API is an error, not a reason to show demo success.

Isolated backend tests cover the employee lifecycle, anonymous/single-pass calculation, customer-preserving replacement pricing, eligible cancellation and audit, receipt tracking, independent/revoked permissions, session/order read separation, atomic replacement rollback, policy preservation, scoping, versions, replay, malformed/changed/unavailable carts, payment/processing/inventory holds, currency rejection and portable migration SQL. Browser interception tests exercise ordering and session flows at phone/tablet/desktop widths, edit-only, cancel-only and read-only grants, session-only redaction, customer-order replacement and cancellation, quote conflict, offline blocking and committed-but-network-failed retry. No test writes to a running store. Real roles, workers, MySQL concurrency, configured providers and hardware still require deployment-specific acceptance.
