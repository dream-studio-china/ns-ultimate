# Admin-agent business write scope approval — 2026-10-22

The user approved a broad but finite admin-agent rollout covering seven business groups: Content; generic Category/Tag; ProductCategory; Product; Specification; Store; and Counter. Approval is for implementation, not a claim that every operation is implemented or active. The authoritative scope and safeguards are in [Admin-agent write capability scope](../design/admin-agent-write-capabilities.md).

The approved scope includes conventional entity management and specified operational changes, including product availability and specification price. It is not limited to the earlier eight-entity/nineteen-field proposal, and it is not per-field approval gating beyond a human-confirmed diff for each single-record operation. Category/Tag routes were subsequently source-confirmed; do not repeat the earlier characterization that their routes were unverified.

Shared boundaries: one record per operation; exact allowlists; human-visible diff and confirmation; schema- and source-based reference validation; hierarchy cycle protection; no cross-tenant/store reassignment; and no silent cascading deletion. Unsafe deletes are blocked, while other independent operations may continue. Printing remains paused. General payments/transactions, inventory, order workflow, arbitrary metadata/settings or JSON, credentials/auth changes, bulk writes, and store timezone/currency/lifecycle settings remain excluded.

Implementation coordination recorded for this rollout: Luna develops and tests; Sol reviews; the main coordinator manages integration and commits. No push is authorized. No database/provider operations or secrets were used for this approval record. This note records scope and coordination only; it does not certify implementation or test results.

## Review decisions during implementation

- Product hard deletion remains blocked because its mapped Specification cascade would exceed a single-record preview.
- Specification hard deletion remains blocked: references in historical JSON and other business modules are not protected by a shared referential-integrity/serialization mechanism. Heuristic scans can miss references or race their writers and are not an acceptable delete guard.
- Product and Specification create/update continue independently. Specification price uses a shared nonnegative signed-32-bit minor-unit bound so PHP, JSON and JavaScript agree exactly.
- Global Products remain supported by the domain; the adapter must not silently assume every Product has a Store. Store reassignment remains excluded.
- Reviews identified separate fixes for MySQL JSON-map ordering, relationship-state refresh/locking, hierarchy serialization, meaningful delete differences, restored-preview validation and effective partial-object no-op detection. These are implementation work items, not verified guarantees until their tests and re-reviews pass.

## Final implementation verification

The seven groups are implemented within the action limits above, with explicit app service registration. Final focused Sol reviews passed taxonomy, Counter/Store, Product/Specification, frontend recovery, the cross-layer preview fixture and MySQL harness. The composed backend passed 242 tests / 4,647 assertions; frontend passed 103; admin integration passed 60; production build passed. Existing vendor deprecation/build warnings remain, not application failures.

The disposable-SQLite cross-layer test passed 54 actual backend prepared/restored previews, 27 mutations and all 20 enabled entity/action combinations. Real MySQL 8.0.41 passed the original two Counter races, crossed hierarchy races for Category and ProductCategory, and fresh-entity-manager JSON-map restoration. Review caught and removed a shared instrumentation row that would otherwise serialize hierarchy workers artificially; only the rerun with independent counter rows is relied on. Each hierarchy race produced one success and one conflict, an acyclic final graph and the winning edge; disposable-schema cleanup was verified.

These checks do not establish production-wide safety, nonparticipating-writer hierarchy integrity or real two-account switching. Browser acceptance and its bounded outcomes are maintained in the [app README](../../apps/admin-agent/README.md#verification-status). No push or deployment is included.

Representative live-model/browser acceptance completed synthetic Tag create, read-back, name/color update, read-back and unreferenced delete with explicit UI confirmation for each mutation. The final exact-slug query returned zero untruncated records; the synthetic tag was cleaned up. Other package-specific browser paths remain unverified.
