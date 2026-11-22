# Accounting (M1)

The manual ledger does not read or write Payment, Settlement, or Wallet records. There is no import, scheduler, or money-moving action. See the [architecture](../../../docs/design/accounting.md) and [accounting contract](../../../docs/contracts/accounting.md).

The separate [Store cash register](../../../docs/contracts/store-cash-register.md) does not create a Store book or post to this ledger. To bring a manual receipt or expense into the ledger, bind the store to a book and open **Finance → Cash journal settings**. Add a supported cash category with its debit and credit accounts, and delete a row when it is no longer needed. The list also flags saved mappings that use unavailable accounts. In **Store cash entries**, select **Generate journal draft** on an entry that has not already been booked manually, then review and post through the regular journal workflow. Use **Correct cash entry** for wrong values and **Void cash entry** for a duplicate or invalid entry. Voiding keeps the record and audit history, hides it from the Store Console, and is blocked while a linked journal is still active. Existing journals keep their lines if a mapping changes. A voided draft remains linked to the source and cannot be regenerated.

## Enable the ledger

1. Review the intended accounting entity, timezone, opening date, currency and decimal scale, chart of accounts, retention requirements, and separation of duties. Book parameters are fixed once initialized; do not use trial settings in a live database.
2. Back up the target database and apply the Accounting migrations through the normal [deployment workflow](../../../DEPLOY.md) before deploying the updated application. The initial ledger migration creates eight business-owned InnoDB tables and does not seed records. Later migrations add the journal attachment and metadata columns without changing existing entries. A module-owned schema listener preserves the existing same-book foreign keys. Do not roll back by dropping ledger history.
3. Users with `ROLE_ADMIN` have all Finance capabilities by default. To delegate to non-admin users, run `php integration/backend/bin/console app:accounting:seed-authorization` from the repository root and assign global Finance roles in Admin Authorization. The command creates no assignments. Use separate drafting and posting grants for delegated users when required by the owner. The Admin Accounting pages live under `/finance/accounting` and can use `/api/v1/finance/accounting/*` with these grants. `/api/v1/manage/accounting/*` remains administrator-only.
4. In **Finance → Book setup**, search for an available store by name or code when creating a book. Select the book before setting up accounts, periods and opening balances. The previous book remains unassigned. Uploaded attachments are served without Finance authorization; do not attach confidential files unless the media storage is access-controlled. If the opening position is zero, the posting role may approve a zero opening instead.
5. Open subsequent months as needed. Post through the explicit confirmation, lock finished periods, and run the reports. For an incorrect manual posting, use **Reverse journal** with an open accounting date on or after the original, then post a corrected journal if needed. For an incorrect opening posting use a reviewed adjustment.

| Global role | Capability in addition to Finance read |
| --- | --- |
| `accounting_reader` | None; reports, journal register and download |
| `accounting_auditor` | View audit and supporting evidence |
| `accounting_drafter` | Create, edit and void drafts |
| `accounting_poster` | Review evidence, post or approve a zero opening |
| `accounting_reverser` | Create and post an exact full reversal |
| `accounting_configurator` | Initialize book, maintain chart, add months |
| `accounting_period_locker` / `accounting_period_reopener` | Lock / reopen periods respectively |

For read-only Store Console access, rerun the Accounting seed after applying the store book migration, then assign `store_accounting_reader` to an active member at the intended store. This role grants no global Finance access or ledger writes.

To share a book, select it in **Finance → Book setup** and use **Add store to book**. Search by name or code; the picker only lists stores without a book. On journal drafts choose the store or **Headquarters only**. Do not treat the binding as an opening balance transfer; if a store needs an opening balance, post reviewed, balanced entries with that store attribution.

Use the optional **Journal metadata** field for structured references such as `{"source_id":"EXT-001"}`. Do not include personal data, secrets or raw provider payloads in finance notes, summaries, descriptions, metadata, or audit reasons. The UI preserves retry keys while the page remains open; after losing the page during a network failure, confirm the journal or period state before submitting a new command. Refresh a stale draft before editing again. CSV uses exact digits and escapes formula-like text; import amount columns as text when using spreadsheets.

## Verification

```sh
php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml --filter 'Ledger(Api|Entity)?Test'
node --test business/admin/accounting/ledger-ui.test.mjs integration/admin/accounting-ui.test.mjs
(cd core/crud-admin && ./node_modules/.bin/vitest run --config ../../integration/admin/accounting.vitest.config.ts)
```

The same [MySQL acceptance harness](tests/mysql-acceptance.php) used by project CI can be run locally using a freshly started disposable MySQL 8.4 instance bound to loopback with an empty `accounting_m1_test` database and user `accounting`. Provide that instance's port and test password as `ACCOUNTING_TEST_MYSQL_PORT` and `ACCOUNTING_TEST_MYSQL_PASSWORD`. The script refuses a nonempty schema. It checks that the applied migration matches the ORM schema and exercises rollback after assigning a number, concurrent posts and reversals, period locking, and exact aggregates. Never supply a shared or production database.

Before production use, perform an interactive walkthrough with the intended permissions and reviewed opening position, check export handling and report latency on representative data, and confirm backup/restore and retention policy. M1 has no report cache, standalone process, store-level permissions, file upload/storage, or statutory reporting.
