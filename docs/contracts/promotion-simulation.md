# Promotion simulation workbench

## Ownership and scope

The human workbench is owned by `business/admin/simulation/` and composed as `/promotion/simulation` in the existing Promotion menu by `business/admin/router.js`. Backend simulation is owned by `business/backend/Promotion/`; the integration Kernel discovers its controller and services by convention. No `core/` file, persistence mapping or migration changes.

The workbench is a **read-only verification surface**. It never creates, updates or deletes campaigns, templates, orders or users, and it does not call payment, print, catalog-write or external provider actions. It complements the ordinary campaign/template CRUD screens; saving remains a separate, explicitly confirmed operation.

Scope of this phase:

- frozen campaign/template snapshots, including disabled and scheduled records;
- multiple named configuration sets (baseline plus experiments);
- multiple cart cases with store, simulated time, currency, user context, snapshot prices or current catalog price;
- matrix results, ordered application details, item/gift/shipping summaries, diagnostics and expected-result differences;
- import from current campaigns and an existing order;
- JSON scenario import/export and browser-local workspace persistence.

The UI separates scenario configuration from simulation results. Summary cards show world/case counts, cases with assertions and planned execution count. Result charts compare before/after totals by world and currency (execution errors are excluded), and show verified, failed, error and unverified counts separately. Amount totals are descriptive aggregates, not checkout revenue; unverified results never count as assertion passes.

Store selectors load the existing paginated `GET /api/v1/manage/stores` list and search locally by name, numeric ID, UUID or code. Selecting a store still submits its exact `code`, not its ID/UUID, to the unchanged simulation APIs. Clearing selects global scope. Records without a non-empty code are not selectable. Existing imported codes are retained even when missing from the store list; failed list loads can be retried.

### Boundary probes

The expandable assistant uses a selectable base cart and campaign snapshots from every world. Dimensions can be enabled independently:

- subtotal comparisons against literal integers or `config.<parameter>` produce threshold −1, threshold and threshold +1 minor-unit cases; an optional manual yuan threshold is converted to minor units, including zero;
- `cart.items.count` comparisons generate item-group counts (not summed quantities), while literal `discount item N` positions generate quantity probes around N;
- campaign start/end timestamps generate −1 second, exact-time and +1 second probes, preserving offset-bearing schedule instants as UTC ISO timestamps;
- anonymous/all supported membership levels, global/campaign stores and an intentionally unmatched store provide context probes.

Amount probes use one base item at snapshot prices and retain its specification identity/UUID/name. Item-group probes can repeat base lines. Other dimensions retain base prices/mode/context except the dimension being changed. These are single-dimension probes, not exhaustive combinations or inferred expected outcomes. Detection recognizes only the documented DSL patterns and is not a replacement DSL parser. Other eligibility conditions can still block a promotion. All generated assertions are cleared and must be supplied manually.

The preview reports per-dimension counts and omissions. Inputs are deduplicated against existing cases and each other, allocated round-robin across dimensions and limited to the remaining 30-case budget. Generated keys are unique; repeated clicks do not append identical inputs. No campaigns or backend state are mutated.

Order import uses historical item price snapshots. It does **not** reconstruct the campaigns, time or environment of that order; it creates a case for explicit re-simulation against the chosen world.

## Endpoints

All routes require `ROLE_ADMIN` and are read-only:

| Route | Purpose |
|---|---|
| `GET /api/v1/manage/promotion-simulation/current?storeCode=...` | Load up to 100 campaigns visible to the exact store plus global campaigns, with templates, flags, schedule, config and revision hash |
| `GET /api/v1/manage/promotion-simulation/orders/{id}` | Load item price snapshots, store code, currency and member level from an order; no contact/credential/note data |
| `GET /api/v1/manage/promotion-simulation/users/{id}` | Load only the user ID and member level |
| `POST /api/v1/manage/promotion-simulation/run` | Execute worlds × cases through the existing promotion calculator |

The run payload contains 1–4 worlds and 1–30 cases. Each world contains at most 30 campaign snapshots. Each case contains at most 30 item groups and accepts optional `expected` or per-world `expectedByWorld` values.

Campaign snapshots include the campaign key, name, enabled/template-enabled flags, exact store code (empty means global), start/end time, conflict mode, config and template type/phase/DSL. Malformed campaigns are excluded from execution and reported with a world-level error; they are not silently approximated.

## Price and user modes

- `snapshot`: item `unitPrice` is integer minor units supplied by the user or order import.
- `current_catalog`: the backend resolves each specification through the Trade catalog port and runs only `BasePriceCalculator`, `QuantityCalculator`, `TotalAggregator` and `PromotionCalculator`. This is a promotion-oriented pipeline, not a complete order quote. Catalog visibility and product availability use the current catalog state; `simulatedAt` only affects campaign schedules.
- User context is anonymous, a synthetic member level, or an exact existing user ID reduced to its member level. The response never includes user contact fields.

Totals are integer minor units. The UI formats them but sends minor units. Fixed reduction amounts and DSL rate semantics follow the normal promotion engine; percentage rates mean the percentage of price kept (90 = 10% off).

## Results and diagnostics

Final item prices reflect item-level actions; order-level reductions affect only the order total and do not allocate amounts back to individual items. The optional `expected` object may also contain `items`, a list of up to 30 partial item assertions `{specificationId, quantity, price, isGift?}`. Each asserted item must appear in the final item list with matching specification, quantity, price and, when supplied, gift status; extra final items are allowed. The UI accepts this list as JSON and never sends it as executable content.

Every world/case result contains:

- status, before/after/discount totals and final item amounts;
- applied campaigns in actual execution order, with phase, conflict mode, total after each step and changed amount;
- item summary, gift quantity and free-shipping flag;
- expected differences and `passed`/`verified` state;
- diagnostics for every configured campaign.

Diagnostic statuses distinguish disabled/template-disabled, store mismatch, not started/ended, unsatisfied DSL conditions, applied, applied with no amount change, best-price candidate not selected, unsupported/invalid definitions and `eligible_not_selected`. The last status means the public calculator did not select an otherwise eligible campaign; the workbench does not invent a priority/conflict reason that the engine did not expose. Diagnostics are auxiliary evidence, separate from the actual applied trace.

## Reproducibility and limits

Imported current campaigns are frozen into the scenario. Running repeatedly uses that fixed snapshot and the case's simulated time; it does not silently refresh live data. The UI displays the import time and revision. Refreshing requires an explicit import.

Simulation does not lock records. Concurrent edits are not detected. It does not verify tax, shipping fees, payment, inventory, catalog visibility beyond the catalog resolver, production checkout concurrency or real historical replay. Same-priority ordering is whatever the real repository/calculator produces for the fixed world; the workbench does not add a tie-breaker.

Browser workspace state is local to the profile and may contain business data. JSON export/import is a scenario exchange, not authorization. Imported JSON cannot register tools, select URLs or execute code. Model tool results remain separately bounded; this workbench's report is not subject to the assistant's 18,000-character tool budget.

## AI relationship

The existing assistant stacking tool remains a bounded single-run helper for selected/environment/draft campaigns. This workbench is the complete human surface for phase 1/2. Any AI scenario proposal must be an explicit diff applied to this workspace after user confirmation and must carry the workspace revision; it must not overwrite the scenario, publish campaigns or treat imported order data as historical proof.
