# Local hotpot order, merge and printing flow

## Goal

Exercise the composed application end to end on `http://127.0.0.1:8000` without real printers or customer payments for individual orders: customers order on one table, orders reach the payable state, the table is merged, one customer settles, and printing rules emit jobs.

## Setup

- Store `HOTPOT-DEMO` (`牛肉火锅演示店`), four customers `hotpot1..4@example.com`, six menu items with one active specification each, and a fresh counter (`A-…`) per run.
- Printer base data came from `app:printer:seed-dummy`. The reserved `local-printer-demo` account and receipt device were temporarily enabled for the run, and the device was assigned to the test store.
- Two store-owned rules used the demo receipt template with `ticketMode=single` and no item filter:
  - production: event `confirmed`, condition `metadata.counterSettlement` `not_exists`;
  - settlement: event `paid`, condition `metadata.counterSettlement` `equals true`.

## Result

- The first order binding opened a counter session automatically; the session had no end time.
- Four orders were created and confirmed; none was paid individually. The counter listed all four as `confirmed`.
- The first customer merged the table into one target order (`confirmed`, 28200 cents). All four sources became `merged` and had no invoice.
- Paying the target with the mock gateway marked the target and invoice `paid` for 28200 CNY cents.
- Print requests: four production requests (one per source confirmation) and one settlement request (target payment). The merged order's own confirmation produced no production request, confirming the metadata condition.
- `app:printer:dispatch-orders` processed five requests and created five jobs. Every job was `failed` with `missing_credentials`, because the demo account has no credentials and no provider was contacted. The settlement snapshot contained the merged order's four item lines.

## Validation

- Composed PHPUnit: 252 tests, 5,092 assertions, 1 skipped.
- Admin composition tests: 72 passed; Admin Agent tests: 342 passed.
- Composed admin build, TypeScript check, documentation link check and `git diff --check` passed.
- Flow used the local development MySQL database and the running dev server, not the SQLite test suite.

## Risks and follow-up

- The demo printer account and device must remain disabled outside this check. Enabling them is only a local substitute for a real device and produces configuration-failure jobs.
- The counter session remained open because paying an order does not close it; ending a session requires all bound orders to be completed, cancelled, refunded or merged.
- This validates one development deployment, not production concurrency for merge, payment or dispatch.

## Code change during the check

Missing or undecryptable Xpyun credentials now fail the print job explicitly (`missing_credentials` / `invalid_credentials`) instead of recording an ambiguous `unknown` outcome. See `business/backend/Printer/README.md`.
