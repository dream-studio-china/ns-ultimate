# Admin configuration as the agent capability boundary

## Approved direction

The assistant is another interaction entry point for crud-admin, not an independently configured administration system. Effective crud-admin configuration is the first capability boundary. Agent-specific safety policies may narrow that boundary, never introduce an entity, action or field absent from it. Standard operations should reuse the existing configured submission semantics and backend authorization/validation rather than repeat domain writes in agent adapters.

Keep the generic agent tools, full previews, explicit human confirmation, conflict protection, transactions where supported, idempotency and uncertain-outcome recovery. Unsupported custom operations stay unavailable. Do not simulate atomic batches by independently posting rows. No core edit is authorized, and broad business-service extraction is not the implementation direction.

## Review policy

Review authorization, execution/submission routing, sensitive fields, confirmation, transactions, concurrency, idempotency and recovery immediately. Mark ordinary configuration mapping, documentation and non-security cleanup as pending review; deferred review does not waive deterministic tests or imply approval.

## Work in progress

The starting worktree was clean at `75542d8b`. Implementation first traces the actual configured form/action and submission paths and establishes the effective-configuration restriction. Trusted backend execution must not accept caller-supplied routes, class names or configuration as authorization. Do not claim removal of independent execution adapters until a replacement has been tested through the ordinary controller path.

## Execution design review

Sol approved a narrow in-process controller bridge subject to the ordinary controller pipeline, fixed trusted execution bindings, explicit full-admin authentication and existing target/dependency checks. Symfony subrequests do not replay firewall access control, although controller `IsGranted` attributes execute. The bridge must use a clean internally generated request, never inherit arbitrary outer attributes/query options, and reject both failed HTTP statuses and unsuccessful business envelopes (including HTTP-200 warnings).

The operation and controller must share the same EntityManager/connection and balanced transaction nesting. Abort on controller failure, manager replacement or verification mismatch; never write a success receipt or retry in a failed transaction. Controller invocation must not occur during preview. Tag/Counter are the initial audited persistence replacements; complex settings merges, hierarchy/scoping checks and external-effect operations are not generic forwarding candidates.

## Trusted configuration export

Frontend membership checks alone are not server authorization. The server-side negative gate will consume a private generated artifact at `var/admin-agent/admin-manifest.json`, derived from the actual merged frontend configuration using its existing loading path and a shared pure projection. It must not accept a browser-supplied manifest. Missing or invalid metadata disables new preparation/confirmation without blocking reads of existing operation outcomes. The generated file is ignored, not a second hand-maintained configuration table or public static asset.

Production builds and development configuration changes must regenerate the artifact; container composition must copy it from the admin build into the PHP application. No deployment or container build is executed as part of this implementation. Existing safety/support constraints remain a transitional narrowing layer, not proof that every configured custom action can execute safely.

## Resumed safety review

An interrupted Sol session was restarted at the user's request. The resumed reviews found blockers in nested-field/readonly projection, confirmation-time revalidation, revision ABA handling, stale manifest publication, binding-module invalidation, deletion identifier read-back, actual route matching, manager-reset detection and full authentication. Fixes required explicit re-review rather than treating earlier green suites as approval; the critical scopes below are now closed.

Ordinary documentation and non-security cleanup are deferred-review items by explicit user instruction. This includes README/design/session wording and removal of the obsolete local-only Counter preview declaration; canonical confirmation and generic preview tooling remain. Permission-bearing config mapping, build artifact publication, controller dispatch and outcome recovery remain immediate-review scope. Final test evidence and safety-review closure must be recorded separately after integration is complete.

## Bounded first replacement

This iteration establishes the shared configured boundary and removes duplicated Tag/Counter persistence plus the obsolete local-preview pilot. It does not yet make every configured collection executable without a supported server binding. Existing field-rule descriptors and complex entity adapters remain transitional support/safety constraints. In particular, a configuration-only new entity is discovered but not advertised as executable without a proven operation path; nested Specification context is not inferred from a nonexistent standalone route. These are explicit remaining implementation limits, not reasons to fall back to arbitrary requests or to call every retained rule a necessary business invariant.

## Reviewed critical scope closed

Sol approved the Tag/Counter controller bridge after saved-ID deletion verification, registry/UnitOfWork/connection continuity, actual matched-route guards and full-authentication checks were fixed. Trigger reinsertion, manager reset, shadow routing and remembered-token regressions exercise these boundaries.

Sol subsequently approved single/batch transport confirmation binding, independently passing 47 targeted tests. Prepared/restored proposals retain bounded policy metadata, not row values; current scope, generation-qualified identity and effective descriptor fingerprint must match before a commit POST. The final legacy-descriptor fallback gap was fixed by sharing effective-field selection between validation and fingerprinting. Status/cancel/history remain independent of current write policy, and already-dispatched outcomes can still settle.

Separate Sol reviews approved the frontend helper/integration-host scope and the producer/publication-to-backend gate scope. Nested forms reuse the actual core resolver, configuration is checked again after awaited confirmation refresh, and schemas with zero writable descendants omit their root field per action rather than becoming unrestricted root grants. The latter was verified through real SSR resolution, generated metadata and a PHP gate probe. Publication/revocation filesystem work shares one FIFO; stale generations cannot republish revoked metadata or delete a later artifact. These approvals do not convert deferred ordinary documentation/configuration cleanup into reviewed material.

## Independent integrated checks

- Node scripts, app tests, admin integration and actual-core-resolver proof: 380 passed, zero failures/skips.
- Isolated SQLite backend: 355 tests, 5,813 assertions passed.
- Real PHP/frontend contracts plus resolver proof: five passed, zero skips.
- Frontend type check, production build, PHP lint and level-8 PHPStan on seven affected production classes passed.
- MySQL execution-guard self-test passed without connecting to MySQL.
- Documentation link check passed with zero broken local targets; final whitespace/core/business diff checks passed.

Browser fixture initially exposed stale Vite optimized-dependency responses (504), not a successful acceptance run. The headless SSR loader had shared the browser optimizer cache. It now uses ignored private `var/admin-agent/vite-headless-cache` and disables both client/SSR dependency discovery. Cache isolation is tested; this ordinary tooling correction remains a deferred-review item.

After that correction, a fresh loopback-only dev server passed all 22 browser cases, zero failures/skips/page errors, with `descriptorsRestored: true` and `isolationRetained: false`. All three production-guard, patchability and timeout self-checks returned outer `ok: true`; their inner refusal/timeout results are expected. Both dev servers started for this verification were stopped; pre-existing servers were not terminated. No deployment, container build, real model/provider, printer operation or real database verification was performed.

The actual private artifact contains 42 configured entities and two semantic printing bindings, revision `eaaeca5761f94e36d00fbb593fa1d823e4d823278b7d9b45a7be55a5c4e5353b`, 48,599 bytes, mode `0600` under a `0700` directory. The real configured catalog snapshot contains nine capabilities. The separate all-ten synthetic legacy snapshot exists only to retain historical adapter safety coverage; both snapshots are checked against their explicitly different PHP exporters.

Implementation is recorded locally in `4a66a096`; documentation is committed separately. No core/business files changed. No push or deployment was performed. Ordinary documentation, non-security mapping/cleanup and headless optimizer-cache isolation remain marked for deferred consolidated review; their tests do not imply that deferred review has occurred.
