# Accounting (M1)

The manual ledger does not read or write Payment, Settlement, or Wallet records. There is no import, scheduler, or money-moving action. See the [architecture](../../../docs/design/accounting.md) and [accounting contract](../../../docs/contracts/accounting.md).

The separate [Store cash register](../../../docs/contracts/store-cash-register.md) uses its own additive migration and the same authorization seed. It does not create a Store book or post to this ledger.

## Enable the ledger

1. Review the intended accounting entity, timezone, opening date, currency and decimal scale, chart of accounts, retention requirements, and separation of duties. Book parameters are fixed once initialized; do not use trial settings in a live database.
2. Back up the target database and apply the Accounting migration through the normal [deployment workflow](../../../DEPLOY.md). It creates eight business-owned InnoDB tables and does not seed records. The tables are mapped with Doctrine; a module-owned schema listener preserves the existing same-book foreign keys. Evolve them using forward Accounting migrations. Do not roll back by dropping ledger history.
3. Users with `ROLE_ADMIN` have all Finance capabilities by default. To delegate to non-admin users, run `php integration/backend/bin/console app:accounting:seed-authorization` from the repository root and assign global Finance roles in Admin Authorization. The command creates no assignments. Use separate drafting and posting grants for delegated users when required by the owner. The Admin Accounting pages live under `/finance/accounting` and can use `/api/v1/finance/accounting/*` with these grants. `/api/v1/manage/accounting/*` remains administrator-only.
4. In **Finance**, initialize the book, create the approved accounts and opening month, then post a reviewed opening journal using the opening date. Add the supporting note or reference, not a file, private URL or sensitive payload. If the true opening position is zero, the posting role may explicitly approve a zero opening instead.
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

Do not include personal data, secrets or raw provider payloads in finance notes, summaries, descriptions, or audit reasons. The UI preserves retry keys while the page remains open; after losing the page during a network failure, confirm the journal or period state before submitting a new command. Refresh a stale draft before editing again. CSV uses exact digits and escapes formula-like text; import amount columns as text when using spreadsheets.

## Verification

```sh
php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml --filter 'Ledger(Api|Entity)?Test'
node --test business/admin/accounting/ledger-ui.test.mjs
```

The same [MySQL acceptance harness](tests/mysql-acceptance.php) used by project CI can be run locally using a freshly started disposable MySQL 8.4 instance bound to loopback with an empty `accounting_m1_test` database and user `accounting`. Provide that instance's port and test password as `ACCOUNTING_TEST_MYSQL_PORT` and `ACCOUNTING_TEST_MYSQL_PASSWORD`. The script refuses a nonempty schema. It checks that the applied migration matches the ORM schema and exercises rollback after assigning a number, concurrent posts and reversals, period locking, and exact aggregates. Never supply a shared or production database.

Before production use, perform an interactive walkthrough with the intended permissions and reviewed opening position, check export handling and report latency on representative data, and confirm backup/restore and retention policy. M1 has no report cache, standalone process, store-level permissions, file attachments, or statutory reporting.
