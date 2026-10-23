# Admin-agent capability and business-service decoupling

## Approved scope

The user approved replacing duplicated frontend write descriptions with a backend-owned runtime capability catalog, and using Tag/Counter as a pilot for reusable business write services. This specifically permits focused Common/Counter business service changes; it does not authorize core edits or automatic exposure of new business fields.

Domain services own reusable field rules and business operations without importing agent contracts. App-owned policy explicitly selects allowed actions and fields. The agent lifecycle retains proposal ownership, canonical previews, manual confirmation, conflict detection, transactions, idempotency and recovery. Other capabilities remain explicit app policies; this pilot is not a universal reflective CRUD framework.

## Intended implementation

- Publish an authenticated, no-store capability catalog with a bounded versioned schema and stable revision.
- Compile generic frontend validators from that catalog rather than maintaining a second handwritten field table.
- Fail closed for mutation tools when metadata is unavailable, malformed or session-stale; preserve read-only chat.
- Share Tag/Counter rules and safe business operations with the agent adapters, using existing normal-admin extension points only where compatibility can be preserved without core edits.
- Keep new fields default-denied through explicit app allowlists and retain specialized safety checks for complex operations.

## Status

Implementation and independent review are in progress. No acceptance or deployment claim is made here. The initial worktree was clean at `48b3d91e`; no environment migration, push or real business-data mutation is authorized or required for this refactor.

## Intermediate backend checks

The backend catalog implementation exposes authenticated `GET /api/v1/manage/agent/capabilities` with `schemaVersion`, a canonical revision, single-record descriptors and explicitly selected batch descriptors. Tag/Counter schemas are read from the new domain services and intersected with app-owned field/action allowlists. Other capability schemas remain explicit app policies. A no-write exporter supports cross-layer tests against the actual catalog.

The coordinator independently passed the current composed backend suite: 315 tests and 5,658 assertions. Backend catalog and shared-business-service reviews are still pending. The business developer reported stricter standard-admin validation; compatibility impact is a review concern, not accepted behavior merely because tests pass.

The catalog revision is not persisted in proposal records. Existing adapter/service revalidation remains authoritative at restore/commit; no historical semantic-policy-version guarantee is introduced by the revision alone.

## Catalog review closure

Sol identified a denied-action projection bug: filtering `actions` and `actionFields` left fields from denied actions in the aggregate map. The fix rebuilds `fields` exclusively from authorized action fields, preserving order. Update-only Product omits create-only Store assignment; delete-only capabilities serialize empty maps as JSON objects. Sol independently passed 15 tests and 457 assertions, verified the 36,156-byte normal catalog and its canonical SHA-256, and approved the backend catalog scope.

Frontend support for delete-only empty maps remains a separate pending integration issue. Shared-business-service compatibility fixes and full frontend review are not yet complete.

## Business extraction review closure

Sol closed the business compatibility findings. The draft Tag controller override was removed, leaving the original Tag admin route/serializer unchanged. Counter retains its original validation, coercion, UUID and metadata behavior; only its existence-only Store lookup is shared. Active-Store requirements remain app-owned. Tag's new service is shared by agent single/batch writes, not falsely presented as a complete replacement for ordinary Tag CRUD. Services neither flush nor own transactions.

The coordinator independently passed the restored backend suite at 317 tests and 5,693 assertions. Integration inspection then found older direct adapter constructions in the two preview exporters and MySQL tooling. The developer supplied services bound to the exact current EntityManager at every construction/reset point, passed both real disposable-SQLite exporter round-trips, and ran the MySQL guard self-test without a database connection. Final frontend and deletion-preview metadata integration are still under review.

## Independent final integration checks

The coordinator independently passed the resulting 240-test app Node suite, composed backend suite (318 tests, 5,730 assertions), 60 admin integration tests, all three real-backend catalog/single-record/batch contract suites, frontend type checking and composed production build. The MySQL guard self-test also passed without a database connection. Existing Qiniu/build warnings remain unrelated.

The updated real-DOM suite passed all 22 cases in a fresh Chromium context with an outer network guard: zero failures/skips, page/setup/cleanup errors or blocked requests; descriptors restored and isolation released. Three safety self-checks passed in separate fresh contexts; production-page rejection performed zero memory/canary reads. New cases exercise catalog refresh/failure during single/batch uncertain outcomes without losing recovery controls or locks. These use synthetic browser host/provider data, not real authentication or production concurrency.

Frontend security review identified recovery invalidation and regex-complexity issues, plus missing type-specific operands. The developer separated confirmation invalidation from outcome tracking, introduced a strict bounded regex grammar with worker-timeout adversarial tests, and validates required operands before compilation. Backend deletion preview metadata is now separate from writable fields and was independently approved. Frontend fixes remain pending independent re-review at this checkpoint.

## Final review closure

Sol's final frontend re-review closed the grouped-regex bypass and production batch-recovery dependency on current write metadata. The accepted grammar allows only ungrouped anchored patterns or one whole-pattern outer alternation; independent hostile-input worker probes completed within their bounds. Batch status/cancel/list validate bounded outcome metadata independently of current write availability, while restore and undispatched commit fail closed after revocation. In-flight receipts can still settle. Sol independently passed 66 targeted tests, including actual catalog export consistency, and reviewed the real-runtime browser revocation case. No remaining P1/P2 blockers were identified in the reviewed frontend scope.

The coordinator independently reran the final 243-test Node suite, three backend cross-layer contract suites, type checking, production build and all 22 real-DOM cases in a fresh guarded Chromium context; all passed. Earlier checks at the unchanged backend revision passed 318 tests/5,730 assertions, 60 admin integration tests, all three browser safety self-checks and the no-connection MySQL guard self-test. Documentation links and final diff checks passed.

Nonblocking limit: the batch transport retains policy references by operation ID until its host becomes unreachable, without an explicit size cap. These are schemas, not submitted row values; long-lived-session retention could be bounded in a follow-up. This refactor adds no database migration or generalized CRUD reflection. No core edits, push, deployment, real account/provider calls, printing or production business-data mutation occurred. Real dual-account HTTP authorization and live MySQL contention are not established by the synthetic/disposable tests.

Implementation was saved in separate local commits: `6489901d` (shared business services and compatible adapters/tooling), `f3ab17f2` (backend capability catalog), and `aa64c1d2` (runtime frontend compilation and validation/recovery coverage). Documentation is committed separately. Deployment must include the backend endpoint and frontend together; clear composed backend caches after service registration changes. No migration is introduced by this refactor.
