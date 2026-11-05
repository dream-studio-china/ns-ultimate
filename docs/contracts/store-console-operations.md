# Store console operations contract

## Status and ownership

Implemented by `business/backend/Store/src/Controller/Staff/OperationsController.php` and consumed by `apps/store-console/`. Existing Core employee CRUD routes remain available and unchanged. Employee counter sessions and order entry have a separate [counter operations contract](store-console-counter-operations.md). A backend-only, store-scoped order refund command is documented below; the console does not expose a refund UI. No employee checkout, financial report or print-command contract is enabled.

Business code owns operational validation, DTOs, availability policy, command receipts and permissions. The app owns presentation and its authenticated adapter. Integration discovers the module normally; there is no Core controller replacement or generic CRUD allowlist expansion.

## Authentication and authorization

The operations routes below have the prefix `/api/v1/store/{storeUuid}/operations`. They require authenticated staff and the existing Store voter: active membership plus the corresponding permission for exactly that store. A membership role label is not a grant. Administrator handling follows that existing voter and does not remove the membership requirement. The backend checks each request; frontend permissions are only a UI snapshot. Existing Authorization caching/invalidation behavior still applies.

| Operation | Permission |
| --- | --- |
| Product reads / creation / editing | Existing `store:product:read` / `create` / `update` |
| Explicit selected-product availability batch | New `store:product:availability` |
| Category / counter / order reads | Existing `store:product_category:read`, `store:counter:read`, `store:order:read` |
| Accept / reject / fulfill / verify | Existing `store:order:accept`, `reject`, `fulfill`, `verify` |
| Request an order refund | New `store:order:refund` |
| Upload a store-owned catalog image | New `store:catalog_image:create` |
| Read operational history | New `store:operation:read` |

Product update is the broader catalog-edit grant and includes availability fields; availability-only staff can use the dedicated batch endpoint without receiving general product editing. Receipt replay still requires the route's current permission.

`app:store:seed-staff-authorization` idempotently registers store-scoped Store capability roles `store_catalog_operator`, `store_availability_operator`, `store_order_reader`, `store_order_operator`, `store_order_refund_operator` and `store_operation_auditor`. Counter configuration, counter-session and counter-order capability roles are registered by `app:counter:seed-authorization`; see the [counter operations contract](store-console-counter-operations.md).

| Capability role | Default grants |
| --- | --- |
| `store_availability_operator` | `store:product:read`, `store:product:availability` |
| `store_order_reader` | `store:order:read` |
| `store_order_operator` | `store:order:read`, `accept`, `reject`, `fulfill`, `verify` |
| `store_order_refund_operator` | `store:order:refund` |
| `store_catalog_operator` | product/category/specification CRUD, `catalog_image:create`, `product:availability` |
| `store_operation_auditor` | `store:operation:read` |

No backend role encodes a frontend job title. The store console's "Kitchen" and "Cashier" views are combinations of these capability grants chosen per employee. No preset grants counter configuration writes, payment initiation or financial reporting. Order fulfillment is limited to `store_order_operator`, refunds require the separate `store_order_refund_operator`, and audit reads are limited to `store_operation_auditor`. Counter-session and counter-order entry capabilities come from the Counter seed described in the counter operations contract. The seed is additive: extra permissions on an existing role are not removed. An existing preset code with a non-store scope fails validation before seed writes. It does not create users, memberships or assignments and does not modify the existing catalog/counter roles. Assign roles through the existing authorization management workflow. Audit access is intentionally separate. Do not replace scoped grants with `ROLE_ADMIN`.

## Order refund command (backend only)

`POST /api/v1/store/{storeUuid}/orders/{orderUuid}/refund` requests a refund for an order in `paid` state. It requires an authenticated user with active membership in the Store and `store:order:refund` for that Store. Only the order's currently linked, matching `trade_order` Invoice can be refunded. New requests for other order states are rejected; this endpoint does not alter Core refund eligibility or order workflow rules. No Store Console refund UI is included.

```json
{"amount": 100, "reason": "Customer request", "idempotencyKey": "unique-operation-key"}
```

All three fields are required. `amount` is a positive integer in minor currency units (for example, cents); `reason` is trimmed, non-empty and at most 1,000 bytes; `idempotencyKey` is non-blank and at most 200 bytes. Unknown fields (including provider, Invoice, merchant or proof overrides) are rejected. Malformed bodies return 400; ineligible orders, excessive amounts, a pending refund or reusing a key with different amount/reason return 409. Orders outside the Store return 404 after Store authorization. Refund access does not require granting broader order mutation permissions.

The request identity is scoped to the Trade order, not to an employee or the current Invoice. A durable receipt in `Order.metadata.storeRefundRequests`, keyed by the SHA-256 hash of the idempotency key, records the refund UUID, original actor, reason, amount, Invoice, payment name and creation time before provider submission. This reuses the existing JSON column; no refund receipt entity, table or schema migration is introduced. The PHP receipt DTO is only a value projection. Same-key replays return the original refund without another submission, including after the order becomes fully refunded; a new key permits a separate same-amount/same-reason partial refund. Only one unresolved unified refund per order is allowed. Run `app:store:seed-staff-authorization` and explicitly assign the new refund capability role. Existing operator roles are not silently granted financial access.

Receipt writes refresh and lock the order, merge only the server-owned `storeRefundRequests` namespace, and commit before provider submission. A business Doctrine listener strips this namespace from newly persisted orders (including client metadata and replacement copies) and preserves the current database namespace when ordinary ORM metadata updates would otherwise overwrite it. Protection uses a current row-locking read inside the flush transaction, including for stale metadata snapshots or `metadata: null`; only the refund writer's in-process context can change receipts. This context is not an HTTP field. Other metadata keys retain their ordinary behavior. Direct SQL/DQL updates bypass ORM listeners and must not overwrite or forge the namespace. Receipts are retained for replay, not pruned or copied to a replacement order.

`GET /api/v1/store/{storeUuid}/orders/{orderUuid}/refunds/{refundUuid}` requires the same Store refund permission and returns the latest persisted outcome without sending provider requests. Both APIs return a success envelope with the same explicit `data` fields: `uuid`, `orderUuid`, `invoiceUuid`, `amount`, `reason`, `status`, `actorUuid`, `createdAt`, `invoiceStatus`, `refundedAmount`. No provider snapshots, credentials or raw gateway results are returned. Status is `processing`, `unknown`, `succeeded` or `failed`; the create/replay response uses HTTP 202 for unresolved results and 200 for terminal results (including a confirmed failure, which must not be mistaken for success). GET uses 200. Financial responses are private and non-cacheable.

Summary reads run inside a short source-before-Invoice transaction and keep the Invoice locked while reading the provider/Core completion marker. This prevents a concurrent refund commit from pairing `succeeded` with a pre-refund Invoice balance. Existing caller-owned transactions are neither committed nor rolled back by summary reads. Reads do not submit provider requests or flush entity changes.

The Store API selects the provider from the Invoice. Huifu requests use the existing Huifu refund workflow; other payment methods use the Core Invoice refund service with a receipt-specific key. Refund status and totals are derived from existing provider/Core records, not maintained as a second financial state machine in metadata. Only a caught pre-submission rejection is recorded as `submissionRejected: true` in the receipt. Provider acceptance is not completion: `succeeded` requires Core application. Transport/signature uncertainty returns an unresolved receipt, not a safe-to-retry validation error. Existing `app:huifu:reconcile` processing updates Huifu results and applies them to Core. Non-Huifu uncertain attempts retain Core's existing manual reconciliation requirement. Do not start a new key to bypass an unknown outcome.

Invoice and Order state are updated by the existing Core refund/event flow after refund completion. Partial refunds update the Invoice balance/state; the Order remains paid until its linked Invoice is fully refunded. Adjusted invoices remain full-refund-only; quickpay and POS limitations remain unchanged. Business workflow guards block both Trade and Store fulfillment while unified refunds or historical Huifu refunds are unresolved. After completion, Store fulfillment remains blocked if the linked Trade order is `refunded`, even if the Store order is still operationally `accepted`; a completed partial refund does not itself block fulfillment. Store fulfillment locks and refreshes its Trade source inside the existing mutation transaction, and advisory availability checks also refresh the source rather than trusting a cached status. Current reads avoid stale MySQL snapshots. SQLite tests do not establish production MySQL concurrency guarantees.

**Compatibility change:** both GET and POST `/api/v1/store/{storeUuid}/huifu-refunds` are removed (404). The Huifu authorization seed no longer creates/grants its retired Store refund permissions; previously stored permissions are inert and are not automatically deleted. Migrate callers to the order API and explicitly assign `store:order:refund`. Administrator Huifu record reads/refresh and the internal workflow remain for reconciliation, including historical records that have no unified receipt.

A process crash after receipt commit but before a provider attempt is persisted leaves a conservative pending reservation. Replays never automatically resubmit it. Operators must inspect the receipt's Invoice and provider/Core attempt records before resolving an abandoned reservation; never clear one solely because the caller timed out. A pre-submission rejection caught by the service is recorded as `failed` and releases the guard. This change does not add a background worker for abandoned unified reservations.

The live UI derives Kitchen visibility from product or category read grants, Cashier visibility from counter-session or counter-configuration read grants, and Orders visibility from order read grants. Individual controls retain their own per-action checks. Counter-session readers default to Cashier; otherwise the first accessible module is used, falling back to Account with a no-business-access notice. `?view=kitchen|cashier` only selects an accessible preferred page. Store reconnection refreshes the permission snapshot and reevaluates that preference; it cannot preserve an unauthorized page from a prior store. These labels do not change API authorization or enable financial commands. The customer payment build remains isolated and demo-only; no employee payment role or live payment link is introduced.

## Explicit reads and pagination

`GET /products`, `/product-categories`, `/counters`, `/orders` and `/history` return a standard success envelope whose **data** is:

```json
{"items": [], "page": 1, "hasNext": false, "total": 0}
```

This is a separate contract from legacy Core lists with a top-level `paginator`. Query parameters are `page` (1–100000), `limit` (1–100), `q` (up to 100 characters) and the resource's `status`. Search is server-side and scoped before counting/pagination. `%` and `_` are literal search characters. No generic `@filter`, DQL, select, expansion or ordering expression is applied. Products are ordered by business sort then ID; legacy Core product rows remain visible. Category lists retain the existing employee API's store ownership filter; global and cross-store categories are not presented as editable store records. An existing global association or explicit valid global UUID can still be used by the product editor, following the existing product association rule.

History lists expose only receipt UUID, actor/command UUIDs, action and timestamp. Restricted before/result snapshots remain stored for audit/replay, not embedded in every history-list response. Authenticated operational responses use `Cache-Control: no-store, private`.

Product status filters are `all`, `active`, `inactive`, `soldout`. Category/counter filters are `all`, `enabled`, `disabled`. Orders accept `all` or a known Store operational status. Counts describe matching records, not whole-store sales or revenue.

`GET /orders/{storeOrderUuid}` returns a restricted order DTO. Both list and detail identify the Trade order separately, expose operational status, verification requirement, rejection reason, a version token and permitted workflow actions. Snapshots contain only item names and quantities, not customer identities, delivery details or arbitrary metadata. Operational state is not payment state.

## Versioned, durable commands

`POST /products`, `PUT /products/{productUuid}`, `POST /products/availability` and `POST /orders/{storeOrderUuid}/{accept|reject|fulfill|verify}` require a UUID `commandUuid`. Product updates, availability rows and order commands also require the latest opaque `version` returned by a read.

Successful commands persist a `StaffOperation` receipt with store, actor, command, action, fingerprint, restricted before/result snapshots and timestamp in the **same database transaction** as the mutation and its outbox writes. Retrying the same actor/store/command/payload returns the stored result rather than mutating again. Reusing a key for a different action or payload returns 409. Permission failures, invalid requests and rolled-back commands do not create successful receipts. The frontend reuses its key for an unchanged manual retry and never automatically retries a network-failed write. A receipt's result is a historical command result, not necessarily the latest resource state; refresh after uncertain outcomes.

`GET /commands/{commandUuid}` looks up the current employee's receipt in the current store. A found receipt requires the current permission for its recorded action and returns `status: succeeded` and only allowlisted UUID identifiers. A missing or another employee's receipt returns `status: unknown`: the operation may still be in flight, so this is not proof of failure or authorization to issue a new command. Unknown recorded actions fail closed. This lookup is separate from store-wide auditor history and does not expose receipt snapshots.

Commands serialize through a store-row lock; existing product/order rows are refreshed under row locks before comparing content-version tokens. These are not monotonically increasing ORM versions. This protection covers the new command routes, **not** legacy Core/admin writes or all cross-module races. Existing CRUD clients do not acquire the same store lock or require these tokens. SQLite tests establish rollback/state behavior, not production MySQL concurrency guarantees.

409 means conflict/stale state/invalid transition; 422 means invalid input; 403 means denied authorization. Unknown fields and attempted ownership changes are rejected. JSON command bodies are capped at 64 KiB. Do not change payload and create a new key to blindly retry an uncertain operation.

### Product edits

The explicit field set is name, description, listing status, category UUID, subtitle, cover image reference, sort order, recommendation level, sold-out state, kitchen routing flag and sale-window start/end. Metadata/settings updates merge these owned keys and preserve unrelated stored keys. Category association must be global or owned by this store; disabled categories cannot be newly assigned, but an existing disabled association may be retained. Images must belong to the same store. Store ownership, arbitrary metadata/settings objects and unknown fields are not writable here.

Core-only product rows expose `extensionEditable: false`; they remain readable and support base-field edits, but extension-field writes fail closed. Creating through this endpoint creates the business Product subtype. Specifications are still saved separately through existing employee APIs; no product-plus-specification transaction is claimed. Deletion, category, specification and table CRUD continue using existing APIs and are not covered by this command audit/version guarantee.

Availability batches contain 1–30 explicit `{uuid, version}` rows and a boolean `isSoldout`. All rows are checked before mutation; an invalid, inaccessible or changed row rejects the entire batch. The UI's page restore lists exactly the affected loaded products and never claims to restore an entire store.

### Availability and compatibility impact

The business catalog resolver now enforces sold-out state and sale windows through Trade's catalog pricing port. Both times must be set or both blank. Valid windows are daily in the owning store's timezone, start-inclusive/end-exclusive, with overnight ranges supported. Equal times, incomplete/invalid historical schedules and invalid store timezones fail closed. Global products use UTC. Kitchen flags do not submit print jobs; existing configured print rules/templates/providers remain authoritative.

**This intentionally changes new quote/order eligibility for existing business products.** Review historical schedules before rollout. Any flow that reprices through this port, including Counter merge, can now reject sold-out or out-of-window items. Existing paid orders are not cancelled, historical prices are not rewritten, and payment of already-created orders is not newly revalidated by this resolver. Inventory reservation/concurrency safeguards remain separate; this is not an inventory implementation or a guarantee that availability cannot change between quote and order creation.

### Order commands

Commands call the existing Store order service/workflow rather than changing status fields directly. `allowedActions` is calculated from current scoped grants, workflow eligibility and verification configuration, and is checked again under the command transaction. Reject requires a non-empty reason of at most 500 characters. The backend supplies the rejection code and actor; callers cannot override reservation IDs, fulfillment snapshots or actor identity. Outbox consumers still require the normal operational worker setup. A fulfillment or verification result is not confirmation that Trade payment/settlement has completed.

## Catalog images

`POST /images` accepts multipart `file` and `commandUuid`. Only detected PNG/JPEG/WebP raster images up to 2 MiB and 8192 pixels per dimension are accepted. Client MIME declarations are not trusted. A digest binds the receipt to the uploaded bytes. The upload uses existing local media storage, records store ownership separately, and returns only `{uuid, url}`. Product commands use that UUID rather than arbitrary new cover URLs. Store media has no personal app-user owner, so a staff member's personal media API cannot delete it. Images are public product assets, not private documents.

The deployment must serve the configured same-origin uploads path. Product metadata accepts safe local paths under `/uploads/`, including the legacy `/uploads/images/` shape. A business media-service decorator also blocks generic media deletion for catalog-image references **before** Core can remove the physical file; the database foreign key alone would be too late. This applies to generic administrator cleanup as well as individual media use. No catalog-image deletion API is enabled.

Database/receipt rollback attempts to delete a returned physical upload; storage failures require operator cleanup. Upload and product save are separate commands: cancellation or a failed product save may leave an unused image. There is no automatic image deletion, retention job or quota system in this scope; apply storage/request limits and review retention before broad rollout. Any future cleanup command must coordinate references and physical deletion explicitly rather than bypass this guard.

## Deployment and verification

Apply Store migration `Version20261031090000` before enabling the new app/backend release. It adds receipts and catalog-image references without modifying existing order/product data. Review the migration plan, backup and target database first; tests never migrate a running store database. Seed and assign permissions deliberately. The previous UI cannot silently stand in when the operations API or migration is missing; failed live reads remain errors, not demo data.

Backend integration tests cover scoped reads, literal full-store search, DTO redaction, versions, atomic batches, replay conflicts, order workflows, image ownership, seed repeatability and the SQLite migration. Browser tests use intercepted APIs, including denial/conflict, multipart images, editor fields and operational commands. Validate MySQL lock ordering/load, upload storage, workers and real employee roles separately before production.

## Proposed settlement and payment contract — not implemented

Counter-session reads, quote/create/add/replace and eligible order cancellation are implemented under the separate [counter operations contract](store-console-counter-operations.md). The remaining financial phase must use store-scoped employee commands rather than reusing customer-owned `/app/orders` or administrator CRUD. Required contracts are:

- Settlement previews with canonical discounts/fees and approval limits, a frozen payable order version and safe concurrent terminal access.
- Restricted expiring customer payment sessions, durable payment-attempt identity and trusted callback/query recovery. Unknown outcomes must block duplicate collection. Staff credentials must never enter customer URLs or projections.
- Separate store-scoped grants for order creation/change/cancellation, discounts, collection, refunds, reporting and reprints, with actor/reason auditing and appropriate limits/approvals.
- Reconciliation/refund recovery, receipt/print ownership and worker operations, followed by provider/hardware and MySQL concurrency acceptance tests.

Route names, DTOs and financial policies for those proposed commands are not frozen here. No endpoint or permission should be represented as implemented solely because it appears in this checklist.
