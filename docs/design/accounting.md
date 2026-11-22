# Accounting architecture

**Status: M1 manual ledger implemented; M2 import remains proposed.** See the [M1 contract](../contracts/accounting.md) for validated behavior and the [implementation plan](../tasks/accounting-foundation.md) for acceptance and remaining tasks. Organization-specific approvals are listed at the end; examples are not accounting policy.

## Scope and dependency direction

**M1 is a standalone manual general ledger.** Headquarters can configure one independent book per store and retains the unassigned legacy book. Each book supports reviewed opening balances, manual journals, corrections, period locks, and reports. M1 does not read Payment, Settlement, or Wallet data. It reuses the deployed Admin shell, identity, authorization infrastructure, and database; it is not a separate service.

**M2 adds one explicitly enabled, read-only Settlement-to-Wallet import.** Accounting can observe source data; no source system calls Accounting or waits for its success. Payment collection, provider payout, tax, FX, full A/R and A/P workflows, and statutory reports are out of scope. Neither milestone requires Core changes.

```text
Admin Finance UI ──> Accounting commands / queries ──> Accounting-owned tables
                               ^
                               |
                 M2 importer and reviewed mapping
                               ^
                               | committed, read-only evidence
                 Settlement Allocation + Wallet Voucher / Transaction
```

- Settlement owns allocations and their execution state. Wallet owns balances and movements. Payment and providers own collection/refund/payout evidence. Accounting owns recognition, books, journal entries, and reports. A Wallet credit is not an accounting credit or proof of a bank payout.
- `business/backend/Accounting/` owns the domain, commands, queries, source adapters, migrations, and tests. Source adapters translate stable identifiers and bounded source evidence into Accounting's own types. No ORM relationship or foreign key crosses into a source module. No adapter calls a money-moving service.
- The Admin Finance UI is EasyAdmin configuration in `business/admin/config/collections/accounting/`, with its field plugins, command actions, and route adapter in `business/admin/accounting/`. Register navigation and localization through the existing [Admin composition](../contracts/admin-composition.md). Generic create and edit apply only to drafts and constrained configuration; posting, voiding, reversal, and period state changes are explicit actions. No Finance-specific authentication stack or assistant writes.
- Backend registration follows the [module convention](../contracts/backend-module-registration.md); test discovery is explicit in `integration/backend/phpunit.xml`. Integration composes the module, not its accounting rules. Do not bypass a missing seam by modifying source tables directly.
- Journal and account reads use Doctrine entities and `BaseService`; journal list/detail use the read-only ViewMixins with Finance-scoped projections, and journal resource create/update delegate to the draft commands. Entities enforce their local transitions. The command service validates cross-record prerequisites and persists entities through a fresh Doctrine unit of work under the book lock. DBAL retains the portable lock, same-key draft line replacement, and explicit report and evidence projections; neither path exposes generic ledger writes.

## Ledger and storage

| Record | Ownership and integrity |
| --- | --- |
| Book | Stable ID, optional unique Store UUID, entity identifier, base currency and scale, IANA timezone, opening date. Book ownership and configuration do not change after initialization. |
| Period | Book and local calendar month, `open` or `locked`; unique `(book, month)`. The first month's usable range begins at the book opening date. No date outside a configured, open period can post. |
| Account | Book-unique code, name, classification, normal balance, active/postable flags. Do not reuse codes or reclassify accounts with posted history; disabling an account does not hide it from reports. |
| Journal and lines | Journal UUID, book, local accounting date, origin (`opening`, `manual`, `imported`, `reversal`), status, revision, summary, posting sequence/time/actor, optional reason and reversal link. Lines have a unique position per journal, a same-book account, a side and one positive minor-unit amount. Optional store/party fields are classifications, not security scopes. |
| Posting series | Transactional counter keyed by `(book, calendar year of accounting date)`. A unique `(book, year, sequence)` identifies a posted journal. Drafts and voids receive no number. No statutory gap-free guarantee. |
| Command receipt and audit | A successful retryable command stores the actor, book, action, key, normalized request fingerprint and result reference. Append-only audit records capture transitions, configuration changes, actor, timestamp, correlation ID and bounded reasons. Receipts, audit, and financial changes commit together. |
| Evidence and source fact | Internal links store allowlisted source identifiers and immutable evidence snapshots. M2 adds a book-unique semantic fact identity, canonical fingerprint, occurrence time, review state, journal link, and mapping version used to generate the draft. Changed evidence is recorded separately rather than overwriting recognized history. |

Use internal foreign keys and uniqueness constraints for same-book relationships, source identity, and at most one posted reversal per original. Require an established opening position before ordinary posting is allowed. Do not hard-delete referenced books, periods, accounts, journals, or source facts. Fiscal-year closing and separate fiscal numbering are deferred.

### Money, date, and opening position

- Use exact signed 64-bit minor units for persisted amounts on the 64-bit backend; each line is positive with one explicit side. Validate range before parsing, and use checked addition for each side of a journal. Require at least two lines and equal non-zero debit and credit totals to post. Report sums may exceed a single journal's range: aggregate and keep exact decimal integer strings rather than narrowing or using floating point. Send money in API payloads as decimal strings; CSV emits exact digits, though spreadsheet software may coerce a long number when opening the file.
- Store the accounting date as a date, not a timestamp. Store posting, audit, and source occurrence timestamps in UTC. Convert a source timestamp to a proposed accounting date using the immutable book timezone. Keep the source timestamp unchanged when an authorized reviewer chooses a different date with a recorded justification.
- The opening entry records only the approved cutover position, with supporting references and equity/retained-balance treatment reviewed. Post it on the opening date; do not build it by reading Wallet balances. For a truly zero opening, record an explicit audited zero-opening approval instead of posting a zero journal. Enforce mutual exclusivity under the book lock. Reports for ranges including the opening date count opening journal lines in movements on that date, not a second time as brought-forward balances.

## Commands, locking, and corrections

```text
draft ──post──> posted     (financial content is immutable)
  └────void──> voided      (kept for audit and source deduplication)

posted original ──full reversal──> separate posted opposite journal
```

`CreateDraft`, `UpdateDraft`, `VoidDraft`, `PostJournal`, `ReverseJournal`, `ApproveZeroOpening`, `LockPeriod`, and `ReopenPeriod` are explicit commands. Their names are conceptual, not promised routes. Do not expose generic nested or batch writes to journals, lines, evidence, audit, or posting fields. Draft updates and transitions compare the expected revision; stale edits return a conflict and do not overwrite state.

For M1, serialize mutations per book with a database row lock. Acquire the book lock before rereading mutable state and before locking any other Accounting rows. This includes configuration, periods, draft mutations, posting, reversals, and M2 fact/draft updates. Reads do not take this lock. It intentionally trades write throughput for a simple ordering during a single-book rollout; keep transactions small and never hold the lock while reading source batches or performing network I/O.

1. Authenticate and authorize the book operation; inside the transaction, acquire the book lock and load current state. Check the command receipt before checking stale revisions so an exact authorized retry can return its prior result.
2. For posting, recheck the submitted draft revision, book, opening gate, date/period, line constraints, and current account eligibility. Apply the same checks to reversal except for the historical account rule below. For imported entries, also check the stored fact and review state; no source read occurs inside the posting transaction.
3. Reserve the next sequence, persist posting metadata and immutable financial content, append the audit and receipt, then commit. Failure rolls back all of them. A retry with the same actor, book, action, and key returns the previous result only if its normalized payload matches; conflicting reuse fails. On a transient database deadlock, retry the whole transaction with the same key and bounded attempts.
4. Period lock and reopen use the same book lock. Lock does not silently post or void outstanding drafts. Reopen requires separate permission, a reason, and audit history. Pending or draft items dated in a locked period cannot post there.

Full reversal copies the original lines and dimensions with debit and credit swapped. An inactive or non-postable **historical account may be used only by the system-generated exact reversal**, not by a new or edited draft. Require a reason, an open date no earlier than the original, and at most one posted reversal; do not reverse a reversal. A retry cannot create another journal; an independent request with a different date or reason returns a conflict pointing to the authorized existing result, not a silent correction. If the opening position needs correction after posting, use a separately reviewed adjustment, not a second opening or a reversal of the opening journal. Reversal only changes Accounting: it does not refund money or reverse a source booking.

## Access, evidence, and reporting

Use existing identity and authorization and check access on every read, command, record lookup, and export. Headquarters uses global Finance grants. Store readers use a separate read-only API and a Store grant; it resolves the book through the authorized store, not a client-selected dimension or book identifier. Identify the accounting-policy reviewer and decide role separation for delegated users before enabling production writes.

Only the journal service writes the ledger. Imported source evidence and audit use allowlisted identifiers, amounts, timestamps, reasons and bounded notes, never credentials, raw provider payloads or arbitrary file URLs. A manual journal can keep separate attachment references and bounded JSON metadata for operators; neither is verified source evidence or a reporting dimension. The posted ledger, not audit logs or import state, is the authority for balances. Audit, diagnostics, and evidence have their own read permissions.

- The journal register may show drafts, voids, and posted journals. The account ledger, account balances, and trial balance read posted lines only, including both original and reversing entries. Order ledger detail by `(accounting date, sequence, journal ID, line position)`.
- For an inclusive date range, opening is all posted activity before the start date; movement is within the range; closing is opening plus movement. Signed balances use `debit - credit`, displayed on the debit side if positive and credit side if negative. Trial balance includes all accounts with activity up to the cutoff and end date (including inactive accounts with history) and exact totals. A filtered subset is not presented as a whole-book trial balance.
- A report/export request reads a consistent database snapshot. To keep pagination consistent across requests, capture a posting cutoff from the book's last committed posting count and carry it in the cursor; increment the count and store its value on the journal in the posting transaction. Queries and running-balance prefixes use the same cutoff. New or backdated postings become visible on refresh, not between pages of the same report. Exports are bounded, permission-checked, and escape spreadsheet formulas in text cells.

## M2: opt-in source integration

### Evidence and attribution

Approve one scenario (internal store attribution, merchant settlement, or employee commission) before generating any draft. The owner and accounting reviewer decide the entity, recognition point, debit/credit accounts and dimensions, wallet-owner and unit mapping, control account, and cutoff. A source status alone does not establish when a liability or revenue should be recognized. The approved versioned mapping runs in Accounting and does not independently calculate Settlement allocations.

Verify the pinned [Allocation](../../core/crud-skeleton/src/Settlement/Entity/SettlementAllocation.php), [Wallet settlement adapter](../../core/crud-skeleton/src/Wallet/Integration/Settlement/WalletSettlementVoucherPort.php), and [Wallet Voucher](../../core/crud-skeleton/src/Wallet/Entity/Voucher.php) before implementation or Core upgrades:

| Lifecycle fact | Required evidence and outcome |
| --- | --- |
| Booking | A successful Allocation posting receipt links to the Voucher UUID. Match the `settlement` source type, Allocation UUID, recipient Wallet, direction, and exact final posting amount and scale against the Voucher and original Wallet Transaction. Use the application timestamp for occurrence; `applied` or `reversed` can supply the original evidence. Missing receipt or a Wallet success not yet reflected in Settlement is pending; conflicting identities, units, or amounts block draft creation. |
| Reversal | Check the Allocation reversal reference (`wallet-voucher-reversal:<voucherUuid>` for the current adapter) against the original Voucher in `reversed` state. Resolve the actual reversal Transaction via the Voucher's reversal transaction ID; verify completion, amount, and movement from the original Wallet. Use the Wallet reversal timestamp for occurrence. A requested, failed, partial-evidence, or insufficient-funds reversal is not a completed reversal. |
| Plan, pending booking, or other movement | A calculated plan never generates a formal journal. Wallet movements outside the approved path, collection, and payout are not covered by this importer. |

Use semantic keys `settlement_allocation_booked:<allocationUuid>` and `settlement_allocation_reversed:<allocationUuid>:<reversalTransactionUuid>` within a book. The reversal Transaction UUID must match the Voucher reference and be proven against Settlement evidence; retain the separate Settlement reversal receipt/key as supporting evidence. Plan, Allocation, Voucher, and Transaction are evidence for a business fact, not four events. The original key and fingerprint do not change when a previously applied Voucher becomes reversed: lifecycle records and immutable fact fields are fingerprinted separately, without transient source statuses or mapping version.

### Import, review, and recovery

1. Default to disabled per book/scenario. An authorized, Accounting-owned command can preview or import bounded batches after policy and cutoff are configured. Preview writes nothing and is not a reservation. No automatic import on module installation, application startup, payments, or Wallet updates. Scheduling is explicitly configured later only if needed.
2. Read committed source rows in bounded batches, normalize them, and reattempt missing/conflicting links through lifecycle rescans. Keep checkpoints per book/scenario and source scan, with stable tie-breaking and a periodic bounded rescan that can find old Allocations or Vouchers changing state even after the creation cursor has passed them. Advance progress only after the Accounting records have committed; a crash may replay work, not skip it. Do not lock source rows across an Accounting write.
3. Persist a fact, its evidence, any exception, and any generated draft/link in a single Accounting transaction for that fact. Same identity and canonical fingerprint are a no-op on replay. Changed immutable evidence records a conflict without overwriting prior evidence or posted history. Missing mapping, unsupported units or precision, and locked periods stay visible. Use a stable exception reference for incomplete evidence before a canonical fact can be established.
4. Reviewers may explicitly retry blocked mappings, approve an alternate open accounting date with a reason, or mark a fact covered by an existing Accounting entry with a verified link and audit. Do not replace a generated draft or change its mapped lines silently; voiding preserves the fact link. Manual journals may refer to sources for documentation, but this alone does not satisfy reconciliation. An authorized review must explicitly link any existing treatment before generating or posting a second entry.
5. An imported reversal with an unposted original blocks both from ordinary posting until reviewed. Preserve both facts. If already posted, generate a reviewable opposite draft linked to the original; run it through the same reversal constraints at posting. If an Accounting correction already reversed the original, require review of the existing treatment rather than proposing a second full reversal. An operator's correction alone does not establish that a source reversal occurred. Recently imported evidence can become stale before review; expose its age and refresh it through the importer. Late source changes are reconciled on later scans, not hidden by promising atomicity across domains.

The cutoff applies to occurrence, not ingestion time. Do not silently backfill old bookings. If a post-cutoff reversal refers to a pre-cutoff booking, show it as an exception and require the reviewer to establish any opening-balance or prior-entry treatment before posting a current-period adjustment. A disabled importer stops accepting new runs and stops active runs at the next bounded batch boundary; it does not delete existing records or prevent M1 operation. Do not run a retry that moves funds or updates a source status.

### Reconciliation and monitoring

Report item-level differences plus exact totals and counts by book, scenario, source window, unit and applicable recipient/control account. Separate (1) eligible source bookings/reversals, (2) matched Wallet evidence, (3) imported/pending/conflicting facts, and (4) accounting treatment: awaiting review, draft, posted, voided, reversed, or explicitly covered by a verified existing entry. Show the source cutoff and last successful scan, not a claim of live coverage.

Never use matching aggregate totals as the only reconciliation test or compare the whole trial balance to Wallet balances. Compare the approved control-account population, exact units, and timing differences. Monitor scan lag, unmatched evidence, conflicts, unposted drafts, and locked-date exceptions without logging sensitive source payloads. Once live, recover through replay, reviewed Accounting corrections, or forward fixes rather than deleting history.

## Release verification and approvals

- **M1 gate:** demonstrate book setup, opening, draft exclusion, posting, lock/reopen, next-period reversal, permissions, and exact reports through the composed UI/API. Validate migrations and lock races on isolated MySQL before M1 release, including rollback after numbering, duplicate retries, locking versus posting, and reversal against an inactive historical account. SQLite is useful for fast checks but cannot prove MySQL locking. No source reads are necessary.
- **M2 gate:** use synthetic Settlement and Wallet fixtures to exercise replay, concurrent imports, checkpoint crashes, receipt gaps, already reversed vouchers, cutover exceptions, and changed evidence. Reconcile item by item and confirm no source-writing path is callable. Roll out with a dry run and reviewed mappings; keep scheduling disabled unless separately approved.
- **Owner decisions before M1 use:** identify the accounting entity, opening date/timezone, currency and scale, reviewed chart, role assignments/separation, opening evidence, and record retention for each book. Do not assume CNY or a fiscal calendar without approval.
- **Owner decisions before M2 enablement:** select the scenario, recognition point, principal/agent treatment where relevant, liability/control accounts, exact unit mappings, occurrence cutoff, and handling of pre-cutover activity. Missing accounting policy blocks M2 journal generation, not M1.

The [implementation plan](../tasks/accounting-foundation.md) contains the ordered tasks and representative acceptance cases. The [module guide](../../business/backend/Accounting/README.md) covers M1 operation.
