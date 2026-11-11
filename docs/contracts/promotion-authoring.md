# Promotion draft authoring

## Ownership and compatibility

`business/backend/Promotion/src/Service/PromotionDraftWorkbench.php` owns non-persistent authoring checks. Its controller requires `ROLE_ADMIN`. The integration Kernel discovers the service and route by convention. No Core file, pricing algorithm, persistence mapping or existing CRUD endpoint is changed, and no migration is required.

The assistant host adapter in `apps/admin-agent/adapters/ns-ultimate/admin/promotion-workbench.mjs` supplies explicitly registered build, check, existing-campaign inspection and stack-simulation tools. Generic assistant code only provides host-tool dispatch, a before-change validation hook and text-only evidence display. API documents, reference articles and model arguments cannot register tools, select HTTP destinations or submit mutations.

The ordinary campaign form now uses its JSON editor instead of a fixed cross-template schema. Assistant campaign configuration is checked against the selected template's actual parameter usages, not a global key list. JSON configuration is a complete replacement, not a nested patch. Unsupported legacy parameters or template field formats fail assistant preparation; they are not silently removed, migrated or rewritten. The ordinary admin editor and CRUD behavior remain available. These authoring checks are not new universal persistence constraints.

## Parameter contracts

The workbench parses the draft using the pinned Core lexer/parser and checks type/phase consistency, non-empty actions, strategy/action compatibility and parameter consumption. Supported authoring types remain `full_reduction`, `discount`, `member_discount`, `nth_discount`, `gift` and `free_shipping`.

`parameterSchema` describes required parameters inferred from condition/action/priority references and implicit strategy inputs. Custom names are permitted when actually consumed. Item discounts require a non-empty `specification_ids`; member discounts require `min_level`. Missing, unknown and out-of-range parameters are errors when configuration is supplied. No defaults are injected.

Optional template `fields` stores a bounded object-schema contract: `type: object`, `additionalProperties: false`, `properties` and `required`. Every consumed parameter must be declared and required; unused declarations are rejected. Property types must agree with their usages. Supported property keywords are type, title, description, unit, numeric bounds, enum, array items and item-count bounds. Contract constraints supplement rather than weaken strategy bounds. Legacy `fields` arrays are not interpreted as this contract.

Units are explicit: subtotal thresholds are integer `minor` units, fixed reductions and caps use `major` units, rates use `percent_kept`, and cart item count uses `item_groups`. Thus 100.00 currency units is a threshold of 10000, while a reduction of 10.00 uses 10. A rate of 90 keeps 90%, not a 90% reduction.

Known misleading constructs fail closed: first-item-only `item.*` conditions, ordered member-level string comparisons, nested logical blocks, strategy-incompatible actions and unsupported type headers. Member/nth rates and gift specification/count remain literals where parameter references are not supported by the current parser. Supporting these constructs would require a separately authorized engine change, not another frontend evaluator.

## Validation and simulation

`check_promotion_draft` accepts an inline template or an exact existing numeric template ID, optional complete configuration and optional sample cases. Template-only inspection returns its parameter schema without requiring activity values. Structured errors include a code, field path and message; syntax errors also carry line/column. Valid syntax does not imply passing expectations.

`read_promotion_draft` accepts only a known numeric campaign ID. It resolves the current template and returns only validated consumed config values, the numeric relation and parameter contracts, not arbitrary campaign fields. Unsupported legacy configuration returns errors without raw values. This allows complete parameter-preserving edits without widening generic nested record reads.

Simulation runs 1–8 isolated sample cases through the real tagged promotion strategies and Core condition evaluator. Each case supplies 1–20 item groups with numeric specification ID, positive integer quantity and integer minor-unit unit price, plus an optional synthetic member level. Group prices and subtotal are calculated by the workbench from these values. Supplied totals, arbitrary users, metadata and item prices are not accepted.

Results distinguish condition matching from actual effects and include before/after totals, item changes, gifts, free-shipping flags, condition traces and expectation differences. Expectations can compare matching, total, items and shipping. Missing expectations never establish a pass. Request/DSL/sample limits and fixed result fields bound processing and disclosure. No persist/flush, order creation, provider operation or live quote call occurs.

Simulation scope is **one rule**. It does not verify catalog existence, real catalog prices, store/time/enabled eligibility, priority selection, phases across campaigns, conflict modes or stacking. Synthetic member fixtures do not read real users. AI-authored expected results are review aids, not independent proof of user intent.

## Stack simulation

`POST /api/v1/manage/promotion-stacking/check` (administrator only) simulates one sample cart against multiple campaigns through the existing `PromotionCalculator`. Stacking, priority ordering, phases, conflict modes (`stackable`, `exclusive`, `lock_item`, `best_price`), best-price candidates and action execution are the production implementation; the business adapter only narrows candidates and records the applied sequence. It never persists, flushes, creates an order or calls an external service.

Three modes are supported:

- `environment`: all campaigns currently eligible for the exact `storeCode` (empty means global only) at the evaluation time.
- `selected`: only the exact existing numeric `campaignIds`; requested campaigns that are not currently eligible are returned in `notAppliedCampaignIds` without exposing a reason.
- `drafts`: 1–20 validated unsaved campaigns. Each draft is treated as enabled, unscheduled and scoped to the request `storeCode`; it receives a synthetic negative identity only so the calculator can de-duplicate it. Drafts are validated with the same template/config checks as `check_promotion_draft`.

Each of 1–4 cases supplies 1–20 item groups with specification ID, positive integer quantity and integer minor-unit unit price, plus an optional synthetic member level. The response contains before/after/discount totals, the applied chain with phase, conflict mode, total after each step and changed amount, a bounded item summary (groups, gift groups and gift quantity), free-shipping state, best-price winner/candidates, and `notAppliedCampaignIds` for selected mode.

The applied chain is capped at 12 entries and best-price candidates at 6; `appliedCount` and the `appliedTruncated`/`candidatesTruncated` flags make omissions explicit. Expected comparison still uses the complete internal trace. `expected` accepts `totalAmount`, `freeShipping` and, for existing campaigns, the exact ordered `appliedCampaignIds`. Each case reports `passed` only when expectations are present and identical; `verified` is true only when every case has passing expectations. Unsupported, malformed or oversized requests fail with HTTP 400/413 rather than returning empty results.

This is a promotion-only single-store snapshot, not a checkout. It does not compute base catalog price, tax, shipping fees or payment, does not verify catalog records or real prices, does not lock concurrent campaign changes, and does not simulate cross-store behavior. Draft mode is not a preview of a saved campaign and does not establish that its future save will succeed. A passing stack result is evidence for the supplied expectations only; it is not authorization to save or proof of production checkout behavior.

## Confirmation and invalidation

Standard recipes deterministically generate a disabled template and its matching campaign config; the backend supplies the parameter contract. Advanced drafts use the same validation/simulation path instead of bypassing it. A new template must be confirmed and saved first; campaign creation then uses its acknowledged numeric relation. These are separate ordinary CRUD writes, not an atomic pair.

Template proposals require syntax/parameter validation. Campaign proposals, including batch rows, additionally require at least two distinct sample inputs with passing expected totals for the exact template/config. Evidence is session-local and bounded. Inline-template evidence can be reused after a fresh detail read of the saved numeric relation; no match is treated as proof that a prior request succeeded.

Preparation and pre-submission checks re-run validation/simulation. A server-derived template revision includes its type, phase, DSL, fields and enabled flag. A changed revision or edited config cannot reuse stale confirmation. Evidence is displayed as text with the proposal; the model receives no submit tool. Batch dependencies are rechecked before starting sequential writes. These reads/checks are not atomic locks: concurrent changes after the final check remain possible and batches may partially succeed. Session disposal clears evidence, and unknown writes retain the existing no-automatic-retry rule.
