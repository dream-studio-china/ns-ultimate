# Accounting Foundation Implementation Plan

**Status: proposed implementation backlog; no Accounting capability is implemented by this document.**

**Created: 2026-11-15.** Checkboxes describe future work, not verified guarantees. Recheck the pinned implementation before starting each integration phase.

## 1. Objective and delivery boundaries

Build the smallest reliable double-entry accounting framework for internal bookkeeping: accounts, journal entries, posting, reversal, period locking, and ledger reports. Preserve a clear path to Settlement, Wallet, and Payment integration without making Accounting a prerequisite for their execution.

Use `Accounting` as the backend capability name and Finance as the user-facing menu concept. Implement in `business/` first. Future extraction into Core is a separate architectural decision, not a deliverable or a reason to introduce speculative abstractions now.

Two independently releasable milestones:

- **M1 — manual accounting foundation:** a complete, authorized, auditable manual bookkeeping workflow. Settlement integration is not required to release M1.
- **M2 — first settlement integration:** one explicitly approved settlement scenario can generate reviewable accounting drafts, identify missing/conflicting evidence, and reconcile successful Wallet bookings and reversals. M2 is not a complete payment, payout, or receivables/payables implementation.

### Included

- One initial legal/accounting entity, one book, and one configured base currency. Persist book identity explicitly; do not implement multi-book UI or cross-book transactions yet.
- A small configurable chart of accounts, journal headers and lines, source references, and minimal supporting evidence.
- Draft editing, atomic posting, corrective reversal, accounting-period locks, opening balances, and read-only ledger reports.
- Server-side authorization, concurrency protection, idempotency, audit records, deterministic tests, and deployment instructions.
- Settlement-aware source ingestion and reconciliation in M2, with manual review before accounting posting.

### Excluded

- Statutory financial reporting or a claim of tax/accounting compliance; accountant review remains necessary.
- Tax filing, payroll, fixed assets, inventory valuation, automatic year-end closing, budgeting, FX, consolidation, or a general workflow engine.
- Full A/R-A/P document workflows, collection of unpaid settlement reversals, automated refunds, or automatic payouts.
- Automatic accounting posting, a generic visual mapping DSL, and complete historical imports.
- Changes to existing Wallet balances, Payment transitions, Settlement rules, refund guards, or provider behavior.
- Any Core edit. A missing extension point must be documented and separately authorized under the repository Core-edit gate; never work around it by writing Core tables directly.

## 2. Existing architecture and evidence

Follow [architecture](../design/architecture.md), [backend module registration](../contracts/backend-module-registration.md), [admin composition](../contracts/admin-composition.md), and [documentation ownership](../operations/documentation.md).

Current source-level findings, not end-to-end deployment validation:

| Finding | Evidence and implication |
| --- | --- |
| Business modules are convention-discovered | `business/backend/<PascalCaseSingular>/src/` owns namespaces, services, routes, mappings, and migrations; no central application module list is needed. |
| Test discovery is explicit | `integration/backend/phpunit.xml` lists business test directories. Add Accounting tests explicitly; module discovery does not imply test discovery. |
| Wallet Voucher is not an accounting journal | [Voucher](../../core/crud-skeleton/src/Wallet/Entity/Voucher.php) records wallet/source identity, amount, direction, applied/reversed status, and transaction references; it has no accounting accounts, balanced lines, or periods. |
| Voucher is not every Wallet movement | [DepositService](../../core/crud-skeleton/src/Wallet/Service/Deposit/DepositService.php) handles deposits and original-voucher reversal. [Transaction](../../core/crud-skeleton/src/Wallet/Entity/Transaction.php) also models other movement types. Do not infer full financial coverage from Voucher import. |
| Settlement already has executable code | [SettlementService](../../core/crud-skeleton/src/Settlement/Service/SettlementService.php) and [SettlementAllocation](../../core/crud-skeleton/src/Settlement/Entity/SettlementAllocation.php) implement planning, posting, and reversal paths. The [design document](../../core/crud-skeleton/docs/design/bundles/settlement.md) still says design-only; its entire proposed behavior must not be assumed implemented. |
| A Settlement-to-Wallet link exists | [WalletSettlementVoucherPort](../../core/crud-skeleton/src/Wallet/Integration/Settlement/WalletSettlementVoucherPort.php) creates `voucherType = settlement`, `voucherId = allocationUuid` and returns the Voucher UUID. Allocation preserves a posting reference. |
| Existing success states have different meanings | Settlement posting, Wallet application, Accounting posting, and bank/provider payout are separate facts, not a shared status. |
| Account units need explicit mapping | [Wallet](../../core/crud-skeleton/src/Wallet/Entity/Wallet.php) permits extended unit codes such as `CNY.ESCROW`; these are not automatically valid accounting currencies. |
| No deposit event hook was established by inspection | The inspected deposit/reversal paths do not explicitly dispatch accounting events. Do not assume a usable event bus contract; begin with read-only, repeatable ingestion. |

## 3. Ownership and dependency rules

```text
Payment / trusted source facts ───────────────┐
Settlement Plan / Allocation ──> Wallet       │
                                Voucher      │
                                Transaction  │
                   │                         │
                   └─────────────────────────┤
                                             v
                          Accounting-owned source adapters
                                             v
                             normalized facts + evidence
                                             v
                           versioned accounting mapping
                                             v
                                draft -> posted journal
```

- Settlement owns allocation amounts, recipients, rule snapshots, and execution states.
- Wallet owns balances, transactions, vouchers, and successful fund movements.
- Payment/providers own collection, refund, and payout evidence where applicable.
- Accounting owns books, accounts, recognition policies, journals, accounting periods, and financial projections.
- Accounting must not recalculate Settlement allocations or treat Wallet credit/debit as accounting debit/credit.
- Accounting adapters read existing sources through available stable interfaces where possible. Isolate any repository-specific reads within explicit adapters and document the dependency.
- Cross-module references use stable identifiers and bounded snapshots, not new ORM associations to Core entities. Preserve evidence if the original object later becomes unavailable.
- Integration owns registration and composition only. Business accounting mappings stay in Accounting.
- Source operations must continue if Accounting is unavailable. Ingestion retries must never retry a payment, a Wallet credit, or a Settlement booking.

### Proposed layout

```text
business/backend/Accounting/
  README.md
  config/
  migrations/
  src/
    Entity/
    Repository/
    Service/
    Controller/Manage/
    Command/
    Contract/
    Adapter/Settlement/
    Adapter/Wallet/
    Mapping/
  tests/
business/admin/
  accounting/
  config/collections/accounting/
  i18n/
```

This is an ownership guide, not a requirement to create empty directories or a custom plugin framework.

## 4. Decisions to resolve before implementation

Record answers in the eventual module guide/design. Suggested starting choices below are proposals, not established business policy.

| Decision | Suggested initial scope | Gate |
| --- | --- | --- |
| Accounting entity and opening date | One identified entity, explicit go-live date and timezone | Before schema/fixtures |
| Base currency and scale | CNY, scale 2, only if confirmed by the owner | Before money handling |
| Initial chart | Small reviewed chart; account classifications and normal balance direction explicit | Before opening balances |
| Permissions | Explicit read, draft, post/reverse, configuration, and period-management capabilities mapped to existing authorization | Before exposing writes |
| Opening balances | Reviewed, balanced opening journal with complete equity/retained balance treatment | Before production use |
| Store/party meaning | Optional stable store and counterparty references; stores do not automatically become separate books | Before dimension implementation |
| First settlement scenario | Select exactly one: internal store attribution, merchant settlement, or employee commission | Before M2 mapping |
| Recognition point | Define when an enforceable obligation/revenue exists; a generated plan is not automatically that point | Before M2 journal generation |
| Principal vs agent treatment | Confirm whether gross receipts are revenue, liabilities, or clearing amounts for this entity | Before collection mappings |
| Wallet semantics | Identify wallet owner, legal liability, unit/category mapping, and whether a platform wallet is internal | Before Wallet mappings |
| Start/import scope | Explicit source cutoff and opening-balance policy; no silent historical backfill | Before enabling ingestion |

M1 may proceed without final settlement accounting policy. M2 may not produce journals until its scenario and policy are approved. If source data cannot prove the selected recognition point, keep the fact pending or exclude that stage; do not invent a new meaning for an existing Core status.

## 5. Minimum accounting model

Names below are proposed; prefer the smallest model that enforces these invariants.

| Concept | Minimum responsibilities |
| --- | --- |
| AccountingBook | Stable identity, accounting entity, base currency/scale, timezone, opening date. Currency/scale become immutable once posted entries exist. |
| AccountingPeriod | Non-overlapping date range and open/locked state per book. Date assignment uses the book timezone. |
| LedgerAccount | Book-scoped unique code, name, classification, normal balance direction, active/postable flags. Optional grouping; do not build an arbitrary hierarchy engine. |
| JournalEntry | UUID, book, accounting date, summary, draft/posted/voided state, revision, posting number/time/actor, origin, and optional reversal-of reference. |
| JournalLine | Ordered account, debit or credit minor-unit amount, description, optional store/counterparty dimensions. |
| JournalSourceLink | Main recognition fact plus supporting stable references and bounded immutable evidence snapshots. Multiple evidence links do not imply multiple recognition events. |
| AccountingAuditRecord | Actor, action, time, target, correlation/request identity, and safe change evidence; append-only via application APIs. |
| AccountingSourceFact (M2) | Unique business-fact identity, fingerprint, source occurrence time, normalized amount/unit, approved mapping version, processing state, linked draft/journal, and actionable error. |

### Mandatory invariants

1. A journal has at least two lines, is non-zero, and balances exactly in the book currency.
2. Each line has exactly one positive side; negative amounts, two-sided lines, floats, and unsupported scales are rejected.
3. Use exact minor-unit integer arithmetic with checked limits. Serialize amounts without JavaScript precision loss. Reject overflow rather than truncate or silently round.
4. All accounts and periods belong to the same book; posting accounts must be active and postable. Disabling an account must not hide historical lines.
5. Do not expose unrestricted generic CRUD for posted journals, lines, source identities, audit records, or posting metadata. Validate nested/batch paths as well as dedicated actions.
6. A draft is editable with optimistic concurrency. Draft voiding preserves source/deduplication history; it does not make an imported fact eligible for a duplicate draft.
7. Posting locks/rechecks the journal, relevant period, accounts, and expected revision; balance checks, number allocation, state change, and audit append commit atomically.
8. Posting number is unique within a documented book/year series. Assign on posting with concurrency-safe allocation; never use unprotected `MAX + 1`. No gap-free statutory numbering claim.
9. Posted financial content is immutable. A correction creates a balanced opposite journal linked to the original, then a new corrected journal if needed. Keep the original posted entry visible.
10. M1 supports only full reversal with at most one posted reversal per original. Do not reverse a reversal in M1; any required further correction is a separately explained journal.
11. A reversal uses the same posting checks. Its date must be in an open period; preserve the original date and reason. Source reversal time and accounting date are distinct.
12. Period locking and posting serialize against the same period boundary. Reopening requires explicit permission, reason, and audit evidence; no silent backdating into locked periods.
13. Explicit journals establish opening balances. Reports must not read Wallet balances or mutable manual totals as accounting opening balances.
14. Journal lines are the authoritative ledger. M1 reports query posted lines; any later summary cache must be rebuildable and reconciled.
15. Referenced accounts, books, and periods cannot be hard-deleted or semantically repurposed. Posted audit evidence does not contain secrets or raw provider payloads.

### Read models

- Journal register with date, number, status, source, account, store, and counterparty filters where applicable.
- Account detail ledger showing opening, movements, and running balance with deterministic date/number/line ordering.
- Account balance report showing opening debit/credit, period movements, and closing debit/credit using documented sign conventions.
- Trial balance derived from posted entries, with exact debit/credit totals.
- Paginated/exportable bounded reads with authorization and spreadsheet-formula escaping in CSV exports.

Drafts, voided drafts, and incomplete source facts never affect ledger totals. Opposite journals remain visible rather than filtering away reversed originals.

## 6. Commands, access, and frontend scope

Expose explicit business operations for draft creation/update/voiding, posting, full reversal, and period lock/reopen. Account and book configuration must enforce their historical-use restrictions. Do not publish final route/schema guarantees until implemented and tested.

- Apply backend capability and book/dimension scope checks to lists, details, commands, source evidence, and exports. Deny by default. Frontend route metadata is not authorization.
- Reuse existing authentication and permission infrastructure; no parallel finance login system.
- Accept explicit idempotency keys for posting, reversal, and import commands; persist a payload fingerprint. Same request returns the original result; conflicting reuse fails visibly.
- A repeated reversal must return the existing result rather than reverse twice. Protect concurrent callers with database uniqueness and locking, not only a preflight query.
- Reuse generic admin CRUD only where safe, such as constrained account maintenance and read-only journal lists.
- Use a custom journal editor for ordered lines, totals, source evidence, and draft revision handling. Backend validation remains authoritative.
- Provide account maintenance, journal list/editor/detail, period management, ledger/balance/trial-balance pages, and an M2 source queue.
- Put user-facing messages in business localization resources. Warn that accounting reversal neither reverses a Wallet transaction nor sends a refund.
- Supporting evidence begins with bounded notes and validated references. Do not introduce arbitrary URL fetching or a new upload service; actual attachments require a confirmed, authorized file-ownership contract.

## 7. Settlement and Wallet integration design

### 7.1 Fact identity and evidence

Use one accounting-owned normalized fact boundary rather than building the entire module around Wallet Voucher. A fact includes book, semantic fact type, stable source identifier, occurrence time, amount/scale/currency, business classification, evidence references, and fingerprint.

Suggested identities include `settlement_allocation_booked:<allocationUuid>` and `settlement_allocation_reversed:<allocationUuid>:<reversalIdentity>`. Confirm actual source identifiers before finalizing the schema. Mapping version is evidence, not a new identity that permits recognizing the same business fact twice.

- Deduplicate on book plus business-fact identity with a database unique constraint.
- Link Plan, Allocation, Voucher, and Transaction as evidence for one booking; do not create separate journals for each representation of that same booking.
- A Payment collection and a Settlement booking may be separate recognition events. Document their distinct debit/credit effects rather than deduplicating every stage into one event.
- Missing recipient/account mapping, unsupported units, mismatching amounts, reused identity with changed content, or inconsistent source status goes to a visible blocked/conflict state.
- Mapping implementations are small reviewed business rules with explicit versions, not executable database expressions. Capture selected accounts, dimensions, and source values in the generated draft.
- An unmapped fact cannot be discarded or marked accounted. Correcting mappings requires explicit reprocessing; it must not mutate posted history or create duplicate drafts.

### 7.2 Recognition stages

| Source fact | Planned treatment |
| --- | --- |
| Simulation or merely calculated plan | No automatic formal journal. |
| Obligation confirmed under approved policy | Optional recognition stage only if current trusted evidence proves it; otherwise deferred. |
| Successful allocation booking with matching Wallet evidence | Eligible for a draft under the selected scenario, not automatic accounting posting. |
| Booking failed/requested/retrying | No successful movement journal. Expose pending/inconsistent evidence. |
| Successful original booking reversal | Separate reversal fact linked to the original accounting treatment. |
| Reversal requested/insufficient funds | Not a completed reversal; retain unresolved obligation/exception according to policy. |
| Provider/bank payout | Separate fact; a Wallet credit is not proof of cash leaving a bank account. Payout integration is deferred. |

Never assume a fixed universal entry such as “credit revenue” for all settlement recipients. Merchant payables, employee commission, and internal attribution require different policies.

### 7.3 Read-only ingestion and recovery

- M2 begins with an authorized Accounting-owned console command and bounded batch import. Production scheduling is explicit and initially disabled; no new assumed worker/event infrastructure.
- Scan committed source records using stable pagination, persisted progress, and a documented cutoff. Cover old records changing state through overlap/rescan or separate lifecycle scans; creation-time-only watermarks are insufficient.
- Do not filter only current `applied` Wallet Vouchers. A Voucher already in `reversed` state contains both original application and reversal evidence; ingest both in causal order.
- Validate exact final posting amounts/scales against Wallet evidence. Settlement calculation quantum is not automatically the Accounting posting amount. Reject unsupported unit mappings or precision loss.
- Source readers must tolerate partial success across boundaries: Wallet may have applied while Settlement has not yet saved a successful receipt. Record/retry the inconsistency; never invoke Wallet again from Accounting.
- Save a fact and its generated draft/link atomically in Accounting. Resume safely after crashes or concurrent imports. Record conflicting fingerprints without overwriting original evidence.
- If a source reverses before the original journal is posted, preserve both facts and block/review the draft; do not create an orphan reversal or silently delete the evidence. If already posted, propose a linked accounting reversal.
- A locked historical period produces an exception requiring an authorized accounting-date decision. Keep the source time unchanged; never silently move the fact into the current month.
- Operator accounting reversals do not prove a source reversal. Reconciliation must distinguish accounting correction, actual source reversal, and pending source operations.

### 7.4 Reconciliation

For the selected cutoff/book/scenario, compare counts and exact totals by currency, stage, and recipient/category where meaningful:

1. Eligible source facts versus ingested/blocked/conflicting facts.
2. Successful Allocation references versus applied/reversed Wallet evidence.
3. Ingested facts versus draft, posted, voided, or reversed accounting treatment.
4. Successful source reversals versus corresponding accounting treatment and unresolved exceptions.

Separate ingestion completeness from accounting-posting completeness. A matching aggregate total alone does not prove item-level completeness. Do not compare the entire trial balance directly to the sum of Wallet balances; define the specific liability/control accounts, units, scope, cutoff, and timing differences first.

## 8. Ordered implementation tasks and acceptance gates

### Phase 0 — Confirm scope and contracts

- [ ] Confirm the M1 decisions in section 4 with the owner; identify the reviewer for accounting policy.
- [ ] Inventory authorization, controller/serialization conventions, decimal handling, file references, and project-owned test/build discovery before selecting implementations.
- [ ] Record M2 scenario and unresolved policy questions without blocking manual bookkeeping unnecessarily.
- [ ] Define journal lifecycle, exact amount limits, numbering, reversal semantics, period timezone, audit retention, and permission mapping.
- [ ] Identify any missing Core seams; keep them as separate blockers rather than expanding this task's authorization.

**Gate:** M1 scope and financial invariants are agreed; no speculative source recognition policy is presented as implemented.

### Phase 1 — Backend module and persistence

- [ ] Add the convention-discovered Accounting module, local service/routes configuration, and namespace.
- [ ] Implement book, account, period, journal/line, source link, and audit persistence with constraints and indexes.
- [ ] Create additive business-owned migrations; inspect generated SQL for accidental Core schema changes.
- [ ] Provide explicit, repeatable initialization for the reviewed initial book/chart/periods. Do not seed production balances automatically.
- [ ] Register Accounting tests in the integration suite and confirm CI actually discovers them.
- [ ] Test namespace/container/routes/mappings/migrations through the integration entrypoints.

**Gate:** a clean isolated database can install the module, and existing Core tables/behavior remain unchanged.

### Phase 2 — Accounting commands and safeguards

- [ ] Implement draft lifecycle, revision checks, full line validation, and source/evidence restrictions.
- [ ] Implement atomic, idempotent posting and number allocation with account/period revalidation.
- [ ] Implement full reversal, uniqueness, reason, open-period date selection, and original linkage.
- [ ] Implement lock/reopen and serialization against posting; audit all sensitive actions.
- [ ] Enforce authorization and historical immutability across every API write path, including generic/batch/nested paths.
- [ ] Implement and test the opening-journal workflow.

**Gate:** concurrent retries cannot double-post/reverse, bypass locks, overwrite newer drafts, or mutate posted financial data.

### Phase 3 — Reports and admin workflow (M1)

- [ ] Implement posted-line ledger queries, balances, trial balance, stable pagination, and safe bounded CSV export.
- [ ] Add business-owned account, journal, period, and report routes/configuration/localization.
- [ ] Build the custom journal editor and explicit posting/reversal confirmations; handle stale revisions clearly.
- [ ] Show source/correction history and audit evidence with appropriate access controls.
- [ ] Run the M1 end-to-end acceptance scenario and frontend composition/build checks.
- [ ] Write module setup/operation instructions and promote only tested guarantees into a new Accounting contract.

**M1 gate:** authorized users can establish opening balances, record and correct manual transactions, lock a month, and obtain reproducible balanced reports without Wallet or Settlement writes.

### Phase 4 — Source facts and one settlement mapping

- [ ] Approve one M2 business scenario, control accounts, dimensions, recognition point, and go-live/import cutoff.
- [ ] Implement AccountingSourceFact, unique identities, fingerprints, processing states, versioned mapping, and safe reprocessing.
- [ ] Add read-only Settlement and Wallet adapters that normalize booking/reversal facts and validate matching evidence.
- [ ] Implement bounded resumable ingestion, lifecycle rescans, dry-run preview, and conflict/missing-evidence handling.
- [ ] Generate reviewable drafts atomically, retaining both original and reversal evidence even if imported after reversal.
- [ ] Add a source queue showing evidence, mapping version, draft/journal, pending reasons, retry and review actions.
- [ ] Test manual correction/void/reversal interactions so ingestion cannot recreate or double-recognize the fact.

**Gate:** replaying or concurrently importing the same source set produces no duplicate recognition and never executes a money-moving command.

### Phase 5 — Reconciliation and operational release (M2)

- [ ] Implement item-level discrepancy reads plus grouped counts/totals for source, Wallet evidence, and Accounting treatment.
- [ ] Exercise partial booking, reversal-pending, stale source state, and post-before-Accounting-crash recovery scenarios.
- [ ] Review migration SQL against an isolated MySQL instance, not only SQLite-generated test schemas.
- [ ] Document explicit enablement, source cutoff, dry run, review, batch execution, optional scheduling, monitoring, and recovery.
- [ ] Define import-disable behavior: stop new ingestion without deleting facts, journals, audit evidence, or source records.
- [ ] Review security, source snapshot minimization, export limits, indexes, and report performance on a bounded representative dataset.
- [ ] Record actual check results and remaining limitations; update the contract and owning module guide.

**M2 gate:** the chosen settlement scenario reconciles item by item; exceptions remain visible; replay is safe; Accounting failure does not alter Settlement/Wallet execution semantics.

## 9. Required test matrix

| Area | Required deterministic cases |
| --- | --- |
| Money | Balanced/unbalanced entries, zero/negative/two-sided amounts, account/book mismatch, unsupported currency/scale, overflow, and JSON/CSV precision preservation. |
| Draft safety | Stale revisions, concurrent edits, voided imported draft, tampered source/audit fields, nested and batch writes. |
| Posting | Double click, concurrent callers, same idempotency key with changed payload, duplicate numbering, rollback after number allocation, disabled account, and date outside configured periods. |
| Periods | Lock racing with posting, locked-period backdate, unauthorized reopen, timezone/date boundaries, and overlapping periods. |
| Corrections | Exactly one full reversal, concurrent reversal, immutable original, reversal into an open period, retry, and non-financial audit visibility. |
| Reports | Opening journal, date-filtered opening balance, deterministic running balance, balanced trial totals, reversal effects, draft exclusion, inactive-account history, pagination, and formula-safe export. |
| Authorization | Unauthenticated and unauthorized list/detail/write/export/evidence access, book/dimension scope, and permission changes. |
| Source processing | Same fact replay, changed fingerprint, mapping version change, missing/disabled account mapping, unsupported Wallet unit, and source amount mismatch. |
| Settlement recovery | Plan-only fact, partial posting, Wallet success before Settlement receipt, already-reversed Voucher, pending/failed reversal, and source reversal before original accounting posting. |
| Import recovery | Concurrent workers, crash before/after fact/draft persistence, old source changing lifecycle, progress recovery, cutoff handling, and locked historical periods. |
| Composition | Module/controller/migration discovery, explicit PHPUnit inclusion, admin route/config collisions, localizations, and unchanged Core source. |

Use isolated SQLite tests where supported for fast logic/composition checks, plus an isolated MySQL concurrency and migration harness for database-specific behavior. SQLite success alone does not validate production row locking, isolation, numbering, or unique-constraint recovery. Never run concurrency/migration validation against shared or production data.

### End-to-end acceptance fixtures

**M1 manual workflow:** use a synthetic book in an isolated database with reviewed fixture accounts, two open monthly periods, and one unauthorized test user.

1. Post opening cash of 100.00 against opening equity; the trial balance has equal debit and credit totals of 100.00.
2. Draft a 20.00 expense paid from cash. Confirm that drafts do not change balances; post it and verify cash is 80.00 and expense is 20.00.
3. Retry posting with the same key and concurrently submit the same posting. There is one posted entry, one posting number, and no extra financial effect.
4. Reject direct edits/deletion of the posted entry, an unbalanced replacement, stale draft updates, and unauthorized reads/writes/exports.
5. Lock the first period and reject backdated posting. Fully reverse the expense in the second open period with a reason; cash returns to 100.00, expense nets to zero across both periods, and all original/corrective lines remain visible.
6. Compare period-specific openings, movements, closing balances, running balances, and exported minor-unit values against independently calculated fixture results.

**M2 source workflow:** use trusted synthetic source records or controlled existing service fixtures, never real provider calls.

1. Generate a plan with multiple allocations; successfully book one and leave another failed/pending. Only the matching successful booking is eligible under the approved mapping.
2. Import twice and concurrently. Confirm one fact/draft for the same semantic booking and that Allocation, Voucher, and Transaction are evidence links, not three accounting events.
3. Exercise a Wallet-success/Settlement-receipt gap, an unmapped recipient, and an amount mismatch. Confirm visible exceptions and no source writes.
4. Import a source already fully reversed, then separately reverse a source whose accounting entry is already posted. Preserve both lifecycle facts and produce the appropriate reviewed correction without duplicate recognition.
5. Stop/restart ingestion around persistence boundaries; repeat with an old source changing state and a locked accounting period. Reconciliation reports the exact outstanding items and never silently changes accounting dates.

## 10. Validation workflow

Use the current [development guide](../operations/development.md) and owning manifests; recheck scripts when implementation begins. Expected repository-root commands include:

```sh
make test-backend
make test-admin
make type-check
make build
node scripts/check-doc-links.mjs
```

Run targeted Accounting tests during development and the broader affected composition checks before release. Add a project-owned MySQL harness and ensure it runs in the intended CI/release workflow; do not claim existing PHPUnit tests already validate production concurrency. Do not modify Core test/config files merely to discover business-owned tests.

Validation evidence must state commands, environment, results, and skipped checks. This plan itself requires only documentation review and link checking; it does not authorize applying migrations, enabling imports, or contacting providers.

## 11. Documentation and release checklist

- [ ] Keep this file as the implementation backlog; record completion honestly and leave deferred work explicit.
- [ ] Add an Accounting module README for setup, configuration, workflows, permissions, mapping ownership, and troubleshooting.
- [ ] Add a validated cross-layer contract after implementation for source identities, lifecycle, idempotency, immutability, period behavior, and reconciliation guarantees. Do not label the proposal as a validated contract.
- [ ] Update architecture only for actual ownership/composition changes; do not copy the entire backlog into design docs.
- [ ] Review additive migrations, backups, restore strategy, opening balances, and production cutover before first use. After live postings, prefer forward fixes over destructive rollback.
- [ ] Verify ingest remains opt-in and mapping/cutoff approval is recorded before M2 enablement.
- [ ] Review the final diff for accidental Core edits, unrelated changes, secrets, and unsupported financial claims.

## 12. Deferred extraction into Core

Reassess after the manual workflow and at least one real settlement scenario are stable. Extraction is justified by demonstrated independent reuse, not by the name Finance.

A possible future reusable package contains books, accounts, periods, journals, exact balancing, posting/reversal, and source-idempotency primitives. Merchant/store/employee policies, recipient mappings, and application permissions remain business-owned.

Before any move, inventory public namespaces, service IDs, routes, entity mappings, table names, migration identities, permissions, references, and historical data. Preserve deployed migration history and provide forward-compatible adapters where needed. Moving files is not sufficient evidence of a safe extraction. No extraction work or Core authorization is implied by this plan.
