# Admin-agent write capability scope

**Approval state: APPROVED FOR IMPLEMENTATION (2026-10-22; printing configuration resumed 2026-10-23).** The user approved the bounded, single-record business management and operational changes below, with the shared safeguards in this document. This is approval of scope, not evidence that every capability is implemented, registered, tested, or active. The implementation ledger records the observed state; verify source and deterministic tests before claiming availability. Printing configuration is implemented separately and locally verified; assistant print submission and reprint remain blocked.

## Current boundary and implementation status

The host registry now provides the implemented [business capability packages](../contracts/admin-agent.md#business-capability-packages), using the app-owned prepare/confirm/commit protocol. Content retains its existing bounded fields. Product and Specification hard deletion remain blocked; approval has not been treated as permission to bypass that restriction. See the [host capability descriptions](../../apps/admin-agent/adapters/ns-ultimate/admin/mutation-capabilities.mjs), [integration service selection](../../integration/backend/config/services.yaml), and [app service configuration](../../apps/admin-agent/backend/config/services.yaml).

The host frontend description is not itself an authorization grant. Backend authorization, adapter allowlists, domain validation, and tests must agree. Host-specific mutation policy belongs to `apps/admin-agent/`; integration selects adapters and business rules remain in their owning modules ([architecture](architecture.md#appsadmin-agent), [assistant design](admin-ai-assistant.md#8-risk-policy-preview-and-submission)).

## Approved finite scope

All writes are one record at a time and require a human-visible diff followed by explicit confirmation through the existing prepare/confirm/commit flow. The approved actions and field families are finite; they do not grant arbitrary property updates. Entity names below refer to the composed application entities/routes and their source-defined schemas. Validate exact field names, relations, authorization, and current behavior against source before implementation; do not invent schema properties from this summary.

| Business group | Approved actions and boundaries | Create/delete and operational safeguards | Implementation state |
| --- | --- | --- | --- |
| Content | Existing bounded Content CRUD only; preserve its current field allowlist and behavior. No new arbitrary metadata/JSON permission. | Existing entity-specific behavior only. | Implemented; regression verified. |
| Generic Category and Tag | Create, edit, hierarchy/parent changes, and delete. | Scope/cycle protection; reference checks include pictures and media to prevent cascade or detachment. | Implemented; locally verified. |
| ProductCategory | Create, edit, sorting, enable/disable, hierarchy/parent changes and unreferenced delete. | Hierarchy serialization and reference checks; no store reassignment. | Implemented; locally verified. |
| Product | Create and edit information, category within scope, sorting, recommendation, publication and sold-out state. | Global/store scope is explicit; no store reassignment or cascading delete. | Create/update implemented; hard delete blocked. |
| Specification | Create and edit name, price, sort and status; deletion approved only when safe. | Parent scope is immutable; price uses bounded integer minor units. Historical JSON references prevent reliable hard deletion. | Create/update implemented; hard delete blocked. |
| Store | Maintain name, contact and address only. | Strict nested schemas; private existing contact/address data redacted. No settings, timezone, currency or lifecycle changes. | Update implemented; locally verified. |
| Counter | Create with store assigned once; edit name, code, capacity, sort and enabled; unreferenced delete. | Active Store locked/rechecked on create; order bindings block deletion. | Expanded CRUD implemented; locally verified. |

### Shared safety rules

- Keep each proposed operation to one record. No global or multi-record bulk mutation.
- Present the exact target and field-level diff for human confirmation. Reject fields outside the relevant entity/action allowlist.
- Resolve and validate references using source-defined schemas and current persisted records; do not guess field names or relationship semantics. Enforce tenant/store scope and reject cross-scope reassignment.
- Protect parent changes against invalid references and cycles. For delete, check references and block when unsafe; never silently cascade. A blocked unsafe action may be skipped while other independently approved operations continue.
- Preserve existing authorization, validation, conflict checks, and read-back safeguards. Scope approval does not bypass them or establish that they are already implemented.

## Explicit exclusions

No approval is granted for arbitrary metadata/settings/JSON blobs; secrets, credentials, authentication or authorization changes; inventory/stock; payment/refund/transaction operations; order, fulfillment, or other order-workflow states; cross-tenant/store reassignment; bulk writes; or printer registration/provider operations. Product specification price is the specific approved price change; it does not authorize general payment or transaction actions. Product availability changes do not authorize order workflow changes.

### Resumed printing-configuration scope

The user resumed assistant printing work, then explicitly chose not to change business modules. The first package therefore covers only app-owned, database-only `StorePrinting` and `ProductPrinting` update adapters: the five fixed Store printing cases (`enabled`, `deviceUuid`, `templateUuid`, `copies`) and the Product `kitchenPrinterEnable` flag. Partial patches preserve unrelated settings, strictly validate references and types, and show the future automatic-printing impact before confirmation. Saving these settings performs no provider calls and creates no print jobs. The adapters, frontend policy and real backend-to-frontend previews passed focused Sol reviews and disposable-SQLite tests; this is not evidence of deployed availability or MySQL concurrency safety.

Assistant reprint remains disabled: the existing business reprint service creates a fresh random task on each invocation and lacks a caller-stable deduplication key and durable source association. Directly invoking it inside a transactional mutation cannot make external effects rollback-safe. No generic CRUD fallback, provider dispatch, device registration, credential editing, template-definition editing or console-command execution is authorized by this bounded package. See the [follow-up record](../sessions/2026-10-23-admin-agent-printing-attachments.md).

## Implementation ledger

This ledger distinguishes approval from implementation. “Pending verification/implementation” must not be read as active. Update it only after inspecting the relevant adapter, registry, and deterministic tests; this approval document alone is not evidence of completion.

| Capability | Implementation state | Evidence/owner boundary |
| --- | --- | --- |
| Content bounded CRUD | Preserved | Existing adapter plus shared lifecycle and frontend regressions. |
| Category/Tag and ProductCategory CRUD | Implemented | App-owned adapters, reference/cycle tests and real MySQL participating-adapter hierarchy races. |
| Product and Specification create/update | Implemented | Parent/store scope, bounds, fresh-state and actual backend-to-frontend preview tests. |
| Product and Specification hard delete | Blocked | Cascade and non-authoritative historical/JSON references; no generic CRUD fallback. |
| Store maintenance | Implemented | Strict name/contact/address policy, partial no-op, redaction and nullable-preview tests. |
| Counter expanded CRUD | Implemented | Active-store locking, scoped uniqueness, reference checks and retained lifecycle/concurrency tests. |
| Store/Product printing configuration | Implemented and locally verified | Separate app-owned update adapters, fixed host schemas, fresh dependency checks, settings preservation and real preview round-trips; no provider/job submission. |

The printing extension raised the opt-in [cross-layer test](../../apps/admin-agent/tests/backend-preview-contract.integration.mjs) to 60 actual prepared/restored previews, 30 mutations, 22 entity/action combinations and 10 entities, preserving all prior business-package cases. The test uses disposable SQLite fixtures and the real frontend host policy; it does not replace HTTP authorization or production-concurrency validation. Participating taxonomy adapters serialize their own hierarchy writes; nonparticipating legacy writers are not covered by that protocol.

These statements describe repository review and approved intent, not a guarantee about deployed routes, effective role grants, database state, dynamic configuration, or production readiness. Do not modify `core/` or business modules as an implementation shortcut; report a blocker if an approved action cannot be safely composed at the app-owned boundary.
