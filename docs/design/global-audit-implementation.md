# Global audit implementation plan

**Status: deferred; proposal only, not implemented or enabled.** As of 2026-11-15,
the existing rotating `audit.log` remains the audit-log mechanism; a separate
database, collector, and viewer are not currently planned. Revisit this proposal
only if structured cross-request querying, longer-term retention, or stronger
audit guarantees become a concrete requirement.

This deferral does not imply that the existing log covers every business event or
that its current redaction is complete. See the [API audit logging guide](../operations/audit-logging.md)
for its actual coverage and limitations.

This document authorizes no dependency installation, schema change, production
configuration change, payment, refund, print submission, or historical replay.

[Simplified Chinese](global-audit-implementation.zh-cn.md)

## 1. Decision and production invariants

Introduce one project-wide audit vocabulary, context, redaction policy and query
boundary. Reuse **AuditorBundle/DoctrineProvider** for explicitly approved ORM
change history, and **Monolog** for best-effort operational events. Do not build
another general-purpose ORM auditing framework or turn audit into event sourcing.

Production continuity takes precedence over audit completeness in the initial
release. The initial production mode does **not** install synchronous audit writes
into payment, refund, order-settlement, inventory or printing transactions.

The following are release invariants:

- No edits under `core/`, including Composer files, mappings and entity attributes.
- Existing API routes, response envelopes, permissions, payment channel names,
  amounts, template syntax, ticket counts and idempotency behavior stay unchanged.
- No additional provider calls, automatic retries, deductions, refunds or prints.
- Existing financial records remain authoritative. Audit never marks a payment
  successful, releases an Invoice, applies a refund or changes a print job.
- New capture, export and viewing are independently disabled by default. Absence
  of the feature must not prevent existing HTTP requests or workers from starting.
- No backfill presented as historical evidence. Coverage begins at a documented
  activation time; gaps and disabled periods remain visible.

"Minimal intrusion" means bounded, reversible and measured changes, **not zero
overhead or guaranteed zero risk**. Native transactional auditing has an explicit
failure-coupling trade-off described below; it must never be advertised as a
transparent fail-open addition.

## 2. Verified baseline and constraints

| Existing implementation | Consequence for this plan |
| --- | --- |
| [API audit listener](../../integration/backend/src/EventListener/ApiAuditLogListener.php) records API/WeChat requests, caller identity, bodies and exceptions | Extend its context/sanitization; do not add a second request-body logger |
| [Audit Monolog configuration](../../integration/backend/config/packages/monolog.yaml) writes rotating JSON files with 30-file retention | Keep this operational channel; introduce a separate sanitized business-event stream rather than silently repurposing its schema |
| [Huifu client](../../business/backend/HuifuFin/src/Client/HuifuClient.php) and webhook controllers log provider traffic/processing | They currently include raw payloads; provider logging needs the same sanitization boundary |
| Huifu attempts, refunds and webhook events already persist provider evidence | Reuse identifiers/statuses; do not duplicate provider workflow or create a second financial ledger |
| [Staff command service](../../business/backend/Store/src/Service/StaffCommandService.php) persists command receipts inside its transaction | Reuse receipts as evidence; replay of a command must not become a second business success |
| [Printer request writer](../../business/backend/Printer/src/Service/OrderPrintRequestWriter.php) uses direct DBAL SQL | ORM auditing alone cannot claim full printing coverage |
| [Integration Kernel](../../integration/backend/src/Kernel.php) imports project package/service configuration but preserves the core project directory | Bundle registration needs an explicit integration-owned seam; dropping a bundles file into integration is not sufficient |
| [Bootstrap](../../integration/backend/bootstrap.php) loads both core and integration Composer autoloaders | Package compatibility includes runtime class ownership, not just two successful Composer installs |
| [Dockerfile](../../infra/docker/Dockerfile) explicitly installs core dependencies; integration dependencies also have their own manifest | Verify both dependency trees in clean release images; do not rely on a workstation's copied vendor directories |

Current log risks and retention are documented in the
[API audit runbook](../operations/audit-logging.md). In particular, query strings,
nested provider strings and some credential-management fields are not fully
redacted. This proposal does not claim those risks are already fixed.

## 3. Component selection and dependency gate

Candidate stable versions checked for this proposal:

| Component | Candidate | Responsibility |
| --- | --- | --- |
| `damienharper/auditor-bundle` | `7.2.1` | Symfony integration and optional entity-history viewer |
| `damienharper/auditor` | `4.3.1` | Audit orchestration |
| `damienharper/auditor-doctrine-provider` | `1.2.0` | ORM changesets, audit tables and readers |
| Existing Monolog setup | Keep locked project versions | Bounded operational event output |

These are evaluation candidates, not an instruction to install floating latest
versions. Their PHP >= 8.4, Symfony 8 and Doctrine ORM 3/DBAL 4 constraints broadly
match the project. Implementation must still resolve exact versions and licenses
against the then-current locks, PHP extensions and release image.

Dependency work belongs in `integration/backend/composer.json` and its lockfile.
It must not update the core manifests, core vendor contents or core Symfony Flex
recipes. Do not fake dependency compatibility with blanket `replace` declarations.
Test that integration's autoloader cannot override core Symfony/Doctrine/PSR
classes with divergent versions. If safe dependency ownership cannot be proven,
stop the bundle phase and retain the existing Monolog-only mode; request an
explicit dependency-composition decision rather than widening the core scope.

Verify production `--no-dev` installation, `intl`, cache warmup, CLI, workers and
HTTP entry points. Register the bundle from an integration-owned override that
preserves the inherited bundle list. Do not edit core `config/bundles.php` or
enable development viewers through recipes.

## 4. Two audit classes, with different guarantees

### 4.1 Operational events: first production release

Use a small `AuditRecorder` boundary with a disabled/no-op implementation and a
bounded JSON/Monolog sink. Producers build allowlisted value objects, not entity
serialization. They must never use the business EntityManager to persist audit.
An optional out-of-process collector can project events to a dedicated audit
database for querying.

The recorder must not start a transaction, flush/clear the business EntityManager,
load lazy associations, perform network I/O, dispatch a payment or change a
response. Catch audit serialization/output failures **inside this boundary**, with
a non-recursive failure counter and safe fallback signal. Local file output still
has latency: benchmark slow/full disks, bound event sizes, and disable capture if
it threatens production response times. Do not claim catching exceptions bounds a
blocked filesystem write.

Operational events are best-effort. A crash, full spool or output outage can lose
events. They support diagnosis, not a regulatory claim of complete atomic history.
The collector must not be required to finish an HTTP request or provider callback.

### 4.2 Auditor native ORM history: separately gated

The Doctrine provider observes ORM flushes, including inserts, updates, deletes
and supported relationships. It does not automatically capture read access,
rejected operations, unchanged replays, raw SQL, bulk DQL or external provider
actions. Register entities/ignored fields outside their source files.

**Same connection:** native audit writes participate in the business transaction.
An audit SQL failure can fail that transaction. Do not swallow a failed `flush()`
and continue, because the transaction or EntityManager may be unusable.

**Separate connection/database:** native capture still performs synchronous work
and loses atomic rollback coupling. It is not a free fail-open/outbox solution;
it can create orphan audits or miss committed changes.

Therefore:

1. Evaluate native mode only on isolated fixtures first.
2. Initial production ORM entity list is empty. Payment, Invoice, refund,
   inventory, order settlement, and print processing remain excluded.
3. Later opt-in can cover an approved low-volume configuration entity after
   explicit acceptance of failure coupling, latency and schema behavior.
4. If the business requirement is that audit failure must never fail even those
   administrative writes, do not enable native storage for them. Use operational
   events and documented coverage limits instead.
5. Strict atomic financial history is a separate future decision. A transactional
   outbox also adds writes and failure coupling to the money transaction; it is
   not part of this continuity-first release.

## 5. Ownership and proposed file layout

All names below are proposed, not existing interfaces or routes.

| Location | Proposed responsibility |
| --- | --- |
| `business/backend/Audit/src/` | Event schema, recorder interface, redaction rules, entity policy registry and scoped query authorization |
| `business/backend/Audit/config/services.yaml` | Business services and no-op defaults, using existing module discovery |
| `business/backend/Audit/migrations/` | Versioned projection schema, if/when approved |
| `integration/backend/src/Audit/` | Request/CLI context adapters, Auditor wiring and Monolog sink adapters only |
| `integration/backend/config/` and integration Kernel | Conditional bundle/config registration, empty initial ORM entity list, no public viewer imports |
| Existing Huifu/Printer/Store business modules | Narrow explicit event producers at verified workflow boundaries |
| `business/admin/` | Optional read-only global audit page, server-enforced permissions |
| `infra/` and project deployment scripts | Explicit dependency installation, spool persistence, rotation and optional collector scheduling |

Do not introduce a new entity inheritance chain, wrap every controller/service,
replace the default EntityManager, scan and annotate all core entities, or refactor
payment code just to insert audit. Prefer existing receipts/events; add small
explicit hooks only where they do not alter the transaction boundary.

## 6. Event envelope and trust model

Use a versioned schema shared by all modules. Proposed minimal fields:

| Field | Rule |
| --- | --- |
| `schemaVersion`, `eventId`, `eventName` | Versioned vocabulary; server-generated event UUID; bounded name |
| `occurredAt`, `recordedAt`, `source` | UTC timestamps; HTTP, CLI, worker, provider callback or reconciliation |
| `traceId`, `parentEventId` | Generated/validated correlation, never an authorization token |
| `actorType`, `actorId` | Trusted authenticated subject, service identity or `unknown`; no username/email snapshot |
| `storeUuid` | Derived from trusted scope/lifecycle; nullable only for explicitly global events |
| `subjectType`, `subjectId`, `references` | Stable allowlisted identifiers for order, Invoice, attempt, refund, command, print job/rule/template |
| `outcome`, `commitState` | Separate observed/rejected/unknown outcomes from verified committed facts |
| `amountMinor`, `currency` | Integer minor units and validated currency when relevant; never infer from free text |
| `fromState`, `toState`, `reasonCode` | Allowlisted bounded values; no arbitrary exception dump |
| `changes` | Explicitly permitted scalar differences or redacted summaries, not whole records |
| `verification`, `operationKey` | Trusted verification result and safe correlation reference; do not copy internal proofs or credentials |

Proposed limits: 16 KiB per event, 64 changed fields, 256 characters per safe text
value and 16 references. Oversize events become a safe summary with explicit
omission metadata; do not serialize the original object again in the error path.
Limits must be tested before activation and cannot truncate essential identifiers
or silently change event meaning.

Generate the primary trace ID server-side. Treat incoming `X-Request-Id` as an
optional length/character-validated secondary correlation value, not an identity.
Derive actor and Store context from existing authorization, never request JSON.
Reset context per request and per worker message to prevent cross-user/Store leaks.

Repeated delivery keeps the same `eventId` through collector retries. Independent
observations have independent IDs and can share `operationKey`; do not hide
replays or assume request ID uniqueness. Collector insertion deduplicates by event
ID, not by order UUID, so multiple legitimate payments/refunds remain visible.

## 7. Coverage and transaction semantics

### 7.1 Initial event inventory

| Area | Proposed events | Evidence/limits |
| --- | --- | --- |
| HTTP/security | `operation.rejected`, `audit.accessed`, `audit.exported` | Known route/context and outcome; no extra request-body capture |
| Payment | `payment.attempt_created`, `payment.submission_observed`, `payment.result_unknown`, `payment.confirmation_observed` | Existing attempt/Invoice identifiers and persisted status; Huifu acceptance is not payment completion |
| Callbacks | `provider.notification_rejected`, `provider.notification_duplicate`, `provider.notification_applied` | Explicit signature/amount/identity outcome and acknowledgement; receiving a callback is not applying it |
| Refund | `refund.requested`, `refund.confirmation_observed`, `refund.core_application_observed` | Keep requested/provider-confirmed/applied amounts and states distinct |
| Compensation | `payment.query_observed`, `payment.close_observed`, `refund.reconcile_observed` | Actor or scheduler, original reference and result; never initiate another collection |
| Printing | `print.request_observed`, `print.submission_observed`, `print.result_unknown` | Snapshot quantities, rendered page count and copies; no full print content or physical-output guarantee |
| Configuration | `configuration.changed` | Changed safe fields, revision/digest, actor and scope; no credential payload |

Configuration changes to capture include merchant/Store binding, channel enablement
and defaults, print template definition/ticket mode, rule copies and activation.
For template JSON, prefer approved structural differences, node counts, ticket
mode and a digest; do not log embedded payment URLs or entire rendered orders.

### 7.2 Committed fact versus observation

- A Doctrine `postFlush` is **not** proof of an enclosing transaction commit.
  An HTTP 200, method return, workflow event or `kernel.terminate` is not universal
  commit proof either.
- Native same-connection Auditor entries have transaction coupling; keep that
  evidence class distinct from best-effort operational observations.
- At explicit business boundaries, mark `commitState = committed` only after the
  outermost transaction is known to have completed. If the boundary cannot prove
  that, use `observed`/`unknown`; do not introduce extra locks or reads on the hot
  path solely to upgrade the label.
- Rollback must not emit `payment.paid` or `refund.applied` as a committed fact.
  An attempted operation may still be recorded with the failure/unknown outcome.
- Do not add a second transaction solely for logging a provider callback. The
  existing verified financial workflow remains the only state-changing path.
- Existing `StaffOperation` receipts and webhook deduplication are reused, not
  replaced. Raw SQL print inserts and worker paths need explicit instrumentation
  or documented exclusions; generic ORM listeners do not cover them.

Audit UI wording must distinguish "observed", "verified provider confirmation"
and "application recorded completion". Audit event presence/absence alone is
neither payment proof nor a reason to resubmit an uncertain transaction.

## 8. Privacy, redaction and diagnostic continuity

Apply one reusable sanitization policy before output to API logs, provider logs,
explicit events and optional ORM diffs. Entity ignored-column configuration is an
extra defense, not a substitute for recursive payload sanitization.

- Prefer event-specific allowlists. Exclude passwords/hashes, private keys,
  credential ciphertext, tokens/cookies, OTPs, WeChat secrets/AES/API keys,
  OpenID/buyer identifiers, internal proofs and complete payment launch data.
- Match credential keys case-insensitively across camelCase, snake_case and
  hyphenated variants. Handle bounded JSON/form data nested inside strings,
  including `wx_data`, `alipay_data` and `resp_data`, before generic truncation.
- Keep safe provider error codes, verified status, timing, endpoint name and
  correlation IDs. Do not blindly log full exception messages or signed payloads.
- Treat query strings, headers, return URLs and QR contents as untrusted and
  potentially credential-bearing. Drop unknown keys; redact free-text reasons.
- For credential changes, record that credentials changed and the version, never
  old/new secret values. Do not record hashes of passwords or low-entropy secrets.
- Malformed/oversize/binary input yields a safe type/size summary, never raw fallback.

Removing raw provider bodies changes operator diagnostics, not payment semantics.
Before rollout, verify that rejection reasons and correlation still support callback
investigation. Update the existing audit runbook to describe the new safe fields.
If raw forensic captures are ever needed, use a separately approved, encrypted,
restricted, short-lived mechanism; they are not enabled by this design.

Existing logs may already contain credentials. Restrict access now, review retention
and rotate confirmed exposed secrets through the existing operational process.
Do not mass-delete logs, rotate every credential or upload samples as part of this
feature without explicit operator approval.

## 9. Storage, schema, reader and authorization

Initial capture uses bounded local JSON events in a separately rotated file. No
new audit table is required to activate this first mode. Validate writable
persistent storage before canary; do not assume the PHP-FPM log filesystem survives
a container replacement.

The optional collector writes an append-only `audit_event` **projection** using a
separate connection/process, never the business EntityManager. Proposed keys:
`event_id` (unique), `schema_version`, event/source/timestamps, actor/scope,
subject/references, outcome/commit state, bounded sanitized JSON and ingestion time.
Index `(store_uuid, recorded_at, event_id)`, `(subject_type, subject_id, recorded_at)`,
and `trace_id`; use a bounded reference index if cross-subject queries require it.
Do not add foreign keys or indexes to existing business tables.

Use checkpointed, bounded batches and event-ID deduplication for collection. Handle
rotation, truncated/invalid records, restarts and backlog caps. Acknowledge only
after projection commit; quarantine malformed sanitized records safely. Monitor
backlog age, dropped events, disk usage and collector failures. Projection lag or
loss is displayed as a coverage limitation, not silently treated as full history.

Auditor native tables are a different storage format, generally per-entity audit
tables; do not pretend the bundle automatically supplies one universal event
table. Keep native rows in their supported schema and normalize them through a
read adapter alongside explicit events. Disable native capture before any audit
table maintenance. Do not audit audit tables or recursively audit ingestion.

All schema work uses reviewed project-owned additive migrations and a dedicated
deployment credential. No startup auto-DDL, blanket schema update, edits to
already-applied migrations, destructive rollback or historical business rewrites.
Verify schema-diff tools do not propose dropping externally managed audit tables.

The default viewer stays off and no vendor routes are imported. A business-owned
read-only API/page can expose timeline/search after coverage and privacy checks:

- Proposed permissions: global `audit:read`; Store-scoped `store:audit:read`;
  exports require a separate permission. Reuse existing authorization resolution.
- Scope before pagination, counts, detail reads, related-reference lookups and
  exports. A nullable/global scope is never visible to Store users by default.
- Do not authorize historical rows through the current owner of a mutable entity.
  Preserve the trusted historical Store attribution, including deleted entities.
- No generic CRUD update/delete endpoints, public routes, unbounded JSON queries
  or editable status fields. Audit access/export is itself audited without recursion.
- Cursor pagination, bounded date windows/page sizes and export quotas are
  mandatory. Rendering treats every recorded value as untrusted text.

Retention is an operational policy, not a financial-history guarantee. Initially
retain the existing diagnostic schedule; obtain approval before assigning event
projection retention. Purge only audit-owned data in bounded jobs, support legal
holds where required, and restrict purge authority separately from viewing.
Append-only application APIs are not database-level tamper resistance; stronger
compliance storage/signatures require a separate threat model.

## 10. Controls, rollout and rollback

Proposed independent controls are `capture`, `collector`, `viewer`, and
`native_orm`, all off initially. Store/entity/event allowlists narrow capture;
financial success/failure evidence must not be probabilistically sampled after
approval. Document disabled intervals instead of pretending completeness.

The capture kill switch must be checked before assembling event data. Native
listener registration/bundle dependency flags are container-time settings: changing
an environment variable is not enough for already-built containers or long-running
workers. A release must warm compatible caches and restart the affected workers;
do not vary the compiled service graph per HTTP request.

| Phase | Changes and exit gate |
| --- | --- |
| 0: baseline | Inventory ORM/raw SQL/CLI/provider paths; capture latency, errors and financial/print call counts; approve threat model and field allowlists |
| 1: safety foundation | Test sanitization and correlation across existing API/provider logs; verify useful safe diagnostics and no semantic response changes |
| 2: dependency evaluation | Resolve components in clean images with all capture/viewer disabled; prove class ownership, startup and supported DB versions; block release if unsafe |
| 3: operational canary | Enable selected events for one test Store/operator, using the non-transactional sink; no native financial capture, no external provider retests |
| 4: collection/viewing | Add projection-only schema and collector; verify replay, access controls, coverage display and bounded searches before enabling the read-only UI |
| 5: optional native canary | Separately approve low-volume entities, schema and transaction-coupling risk; leave money/printing paths excluded; skip this phase if failure coupling is unacceptable |
| 6: gradual expansion | Expand approved Stores/events; set budgets, retention and alerts; record activation times and known unsupported paths |

Before each phase, compare against an audit-disabled deployment using the same
business inputs. Suggested starting stop thresholds: any audit-attributable
business failure, changed money/print output/provider call count, cross-Store data
exposure, or sustained p95 latency increase above 5%. Operators must approve actual
budgets from measurements; the suggested threshold is not an established SLA.

Rollback order:

1. Disable event capture and native listeners through their supported controls;
   if container-time registration cannot be changed live, use the tested prior
   artifact/cache and restart relevant processes.
2. Stop collector/export jobs if they cause load; hide the viewer independently.
3. Restore the prior code and lockfile/image if dependency/startup issues occur.
4. Keep additive audit tables/files for investigation; do not run down migrations
   or rewrite business data to roll back audit.
5. Verify provider calls, payment/refund statuses, print counts and normal operator
   workflows against baseline. Record the capture gap and incident reason.

No rollback step retries financial operations, resets unknown attempts, changes
idempotency keys or reprints historical jobs.

## 11. Validation and acceptance

All tests use isolated fixtures and fake provider clients; no production payments,
refunds, prints or credential-management requests are test fixtures.

| Test area | Required evidence |
| --- | --- |
| Feature off | No audit schema dependency, routes, queries, entity listeners or provider side effects; normal startup and old API results |
| Dependency/build | Clean core/integration installs, no-dev images, runtime class file ownership, lock stability and no core diff |
| Privacy | Nested JSON/form/query/header cases, key variants, malformed/oversize input, credential updates and exception strings; no secret canary reaches any sink |
| Context | Untrusted trace IDs, anonymous callbacks, CLI/system actors, successive worker messages and cross-Store isolation |
| Workflow parity | Same status, money, deductions, locks, requests and ticket counts with capture on/off; WeChat, Alipay, legacy filters and templates remain compatible |
| Callback/refund | Invalid/duplicate/late notifications, unknown outcomes, wrong amounts, reconciliation and confirmed-but-not-applied refunds remain unchanged |
| Transactions | Outer rollback, nested flush and failed commit cannot become committed audit success; native mode rollback coupling is explicitly demonstrated |
| Failure isolation | Disk full/slow/read-only, serialization failure, collector outage/backlog, missing projection tables and restarts do not initiate/retry business work |
| Native mode | Missing audit table, DB failure/deadlock and ORM failure tested; do not claim fail-open if the business transaction fails |
| Coverage | Direct SQL printing, rejected/no-change operations, HTTP/CLI and queues have explicit coverage or a visible exclusion |
| Reader | Scope before counts/pagination/detail/export, deleted subjects, global rows, XSS and bounded queries |
| Operations | Capture off/on, container cache changes, worker restarts, checkpoint/rotation recovery, rollback without DDL or business replay |

Use declared project/backend/frontend checks appropriate to the implementation.
Backend integration tests use the isolated SQLite bootstrap, with additional
MySQL staging tests for transaction/DDL behavior. Do not extrapolate SQLite-only
success to production locking or performance. Documentation changes use the
[link checker](../operations/documentation.md).

Release acceptance requires an approved coverage matrix, schema/dependency review,
privacy tests, parity and failure-injection results, performance budgets, tested
kill switches/rollback, retention/access policy and an operator-facing runbook.
Publish tests actually executed and unresolved acceptance items. No claim of
"all events captured", "exactly once", "immutable ledger" or "no production
impact" is permitted without evidence matching that claim.

## 12. Implementation work packages

1. **Audit safety:** shared sanitizer, safe provider diagnostics, correlation and
   tests; update the current audit logging runbook, preserving old API behavior.
2. **Component spike:** clean dependency resolution, integration-only bundle seam,
   disabled startup and failure/transaction characterization; no production enablement.
3. **Operational capture:** schema/recorder/no-op/sink, bounded context and a narrow
   Huifu/StaffOperation/Printer event inventory, with explicit transaction labels.
4. **Read projection:** additive migration, optional collector, checkpoints and
   loss/coverage metrics; no dependencies on financial writes.
5. **Scoped viewer:** read-only business API/admin page, permissions, pagination,
   reference timelines, export/access auditing and retention controls.
6. **Optional native ORM:** reviewed entity/field registrations, supported audit
   schema and a small canary only after separately accepting its coupling risk.
7. **Release hardening:** production-like parity/load/failure tests and rollback
   rehearsal; operator-approved staged enablement.

Keep these as independent commits/PRs where practical. The Alipay feature can
ship without this audit proposal; audit rollout must not become an unreviewed
payment deployment prerequisite. Implementation documents/contracts should be
updated only as each behavior becomes real; this design remains a proposal until
the corresponding acceptance gates have passed.

## References

- [AuditorBundle documentation](https://damienharper.github.io/auditor-docs/)
- [Doctrine provider configuration](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/configuration)
- [Provider multi-database transaction warning](https://damienharper.github.io/auditor-docs/auditor-doctrine-provider/providers/doctrine/multi-database)
- [AuditorBundle stable package metadata](https://packagist.org/packages/damienharper/auditor-bundle)
- [Current ownership and composition](architecture.md)
- [Current API audit logging](../operations/audit-logging.md)
- [Huifu customer payment guarantees](../contracts/huifu-customer-payments.md)
- [Verified payment closure boundary](../contracts/order-payment-closure.md)
