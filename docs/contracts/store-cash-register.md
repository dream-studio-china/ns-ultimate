# Store cash register

Store Console exposes a small manual cash register to active members with `store:cash_entry:read` for the selected store. Recording also requires `store:cash_entry:create`. Store and actor come from the route and authenticated session, not the input. Run the Accounting authorization seed and assign `store_cash_reader` or `store_cash_recorder` in the appropriate Store scope; the seed creates no assignments.

The register is separate from the company general ledger. Entries are append-only and recorded with the existing Store staff command receipt; repeating the same command and normalized payload returns the existing entry, while changing the payload with the same key conflicts. No payment, inventory movement, or accounting posting is triggered. The page keeps the same command ID on an uncertain in-page retry; if the page is closed, inspect the register before re-entering the transaction.

The frontend controls the category-specific form. The backend accepts version 1 metadata with optional `reference` and bounded `extra` strings. Purchase metadata must also supply item name, decimal quantity and `g`, `kg`, `ml`, `l`, or `piece` (whole items only). Item name and reference are display values, not a catalog association or proof of an external purchase. Extra fields do not enter calculations.

The backend calculates purchase price from the whole entry's amount and quantity, converting kg to g and L to mL. Amounts and totals are exact string minor units at the Store console's existing two-decimal scale. Unit-price results are decimal strings rounded half-up to eight places in major units. The monthly totals are split by currency, direction, and category and are independent of the current entry page. They describe recorded cash activity and purchase prices, not stock consumption, cost of goods sold, or margin.

There is currently **no source purchase order model connected to this workflow**, so records are explicitly marked manual. A future import must use a trusted confirmed purchase source, stable source identity, and deduplication/adjustment rules; an entered text reference is not an import key. There is no automatic synchronization with the company ledger.

See the [owning controller](../../business/backend/Accounting/src/Controller/Store/StoreCashController.php) and [integration fixture](../../business/backend/Accounting/tests/StoreCashApiTest.php).
