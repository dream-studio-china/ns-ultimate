# NS Store Console

An independent mobile-first Web workspace for store staff, built with React, TypeScript, Vite, Ant Design Mobile and Tailwind CSS. It has a live employee API mode, an explicitly selected demo mode, and a separately built **demo-only** customer payment entry.

## Live employee mode

The default staff entry (`/` or `?mode=live`) uses the existing backend. Sign in with an existing employee account, select an active store or enter its UUID, then connect. The store list is a directory, **not** proof of membership. Connection reads the user's membership and effective store-scoped permissions. No registration, membership enrollment, role grant or administrator API call is performed. Administrators still require active store membership, matching the backend voter.

Connection failures identify the failed check and, when available, its HTTP status without exposing backend exception text or credentials. A membership-check 404 means the existing store has no membership for the current account (or the store is unavailable); it is shown as a membership requirement, not a generic connectivity error. Ask an administrator to provision active store membership and the required store-scoped permissions through the existing management workflow. The app never calls the self-enrollment endpoint to work around a missing membership.

Supported live operations:

- Product listing, creation and atomic editing of basic details plus subtitle/description, store-owned raster image, sort, recommendation, sold-out state, kitchen routing flag and daily sale window. Product category selection supports paginated choices and an advanced UUID field. Explicit selected-product availability batches are version checked and limited to the listed rows, not the whole store. Product and specification saves remain separate.
- Separate specification CRUD for names, integer-minor-unit prices, status and sort. A product and its specifications are saved separately: no cross-resource transaction or all-or-nothing save is claimed.
- Category CRUD for name, slug, enabled state and numeric `sortOrder`.
- Real counter-session board with participant counts/notes and ending closed sessions. Sessions open automatically when the first customer order is bound; the employee interface does not open sessions manually. In an active session, staff choose variants/quantities, review canonical server quotes and create anonymous orders/additional orders. An independent replacement grant permits changing eligible customer or employee orders with a reviewed quote and reason, retaining the original customer and recording both order UUIDs in the command receipt. Standalone cancellation is not enabled. Trade, operational and payment record states remain separate.
- Separate viewing and editing of existing table configuration for code, name, capacity, sort and enabled state. This frontend does not offer table creation or deletion; use the existing administration workflow for those operations. Configuration alone does not establish occupancy or payment state. Counter-session readers enter the session board by default and can switch to configuration if separately authorized.
- Paginated store order listing and restricted detail/snapshot reads, with accept/reject/fulfill/verify actions when both scoped permissions and the current workflow permit them. Reject requires a reason. Operational status is **not** interpreted as payment status.
- Server-side store-wide search/status filters for products, categories, tables and orders, with matching totals and bounded pagination. Order snapshots expose item names/quantities, not customer or delivery data. Authorized auditors can inspect successful operations through the account page.
- Local language/theme preferences, store switching and logout.

The live interface uses one responsive staff workspace with **Kitchen** (catalog/availability), **Cashier** (counter sessions/tables), authorized orders and account navigation. Modules require their actual store-scoped read grants; unavailable catalog mutations, category management and specification management are hidden rather than shown as disabled placeholders. Additional management grants retain the existing editors. Selected store cards fill the actual UUID, and record identifiers/restricted snapshots are disclosed on demand rather than dominating resource lists. Search is server-side; totals describe matching records, not sales/revenue. Phone layouts use a floating navigation dock with only the available entries; tablets and desktop use a wider multi-column layout with top navigation. Empty/loading/error/permission states never synthesize business data.

Counter-session readers start in Cashier by default; otherwise the first authorized module is selected (kitchen, table configuration, then orders). `?view=kitchen` and `?view=cashier` request a preferred entry only when authorized. Staff with both capabilities can switch through navigation. Store reconnection discards the selected page and reevaluates fresh grants and the entry preference; an account without business read grants lands on Account with an authorization notice. Role names and URL parameters do not grant access. Cashier is a workspace name, **not** an enabled collection service; the customer payment entry remains independent and demo-only.

The session board uses compact responsive table cards, with a visual distinction between active and idle sessions. Active cards show participants, session start, the open-order amount and order count; idle cards do not imply a bill. A reader without counter-order read grants sees the session with an explicit restriction notice instead of invented rows or zero amounts. The session detail groups table state and open-order amount above associated orders and keeps operational/payment states separate. Amount labels remain order summaries, not checkout or payment results.

Product, category and specification creation/editing and existing-table editing share one editor: a live information preview, grouped fields, explicit status switches, collapsed advanced fields and a fixed cancel/save bar. Table seating supports direct entry and bounded step controls; blank capacity remains unset. Editors open as bottom sheets on phones and centered dialogs on larger screens. Saving locks fields and dismissal; failed saves retain input, and invalid advanced fields expand for correction. Specification management and read-only order details use the same base dialog and theme without acquiring extra write capabilities.

The live and demo preference screens share a seven-color picker with an immediate preview. Root-level theme tokens color page backgrounds, decorative cards/icons, navigation, form controls and portal-rendered sheets, not just primary buttons. The preference survives reload and a fresh login; authentication still does not. Semantic success/error/availability indicators, payment-provider identities and product illustrations retain their independent colors. The isolated demo retains its demo-specific editing fields and workflows.

Sale/listing status is not a substitute for `isSoldout`. Business-owned operational APIs now expose catalog extensions without changing Core CRUD allowlists. The business pricing resolver rejects sold-out and out-of-window products for new quotes/orders; overnight windows use the store timezone. Existing incomplete schedules fail closed and should be reviewed before rollout. Repricing flows such as Counter merge also obey these rules; existing paid orders are not rewritten, and existing-order payment does not gain a new availability check. Kitchen flags only affect matching configured print rules; they do not directly print. Employee discretionary discounts, checkout, real payment links, refunds, financial reports and employee printer commands remain unavailable. No demo data is substituted on a failed live request.

### Operational API rollout and grants

The frontend requires the business operations API and Store migration `Version20261031090000` (command receipts and catalog images), plus the counter-operations API and Counter migration `Version20261031100000` for session-enabled staff. Review/backup/apply migrations through the normal backend deployment workflow before enabling this release; the app never migrates a store database. After reviewing the target environment, register capability roles with:

```sh
php integration/backend/bin/console app:store:seed-staff-authorization
php integration/backend/bin/console app:counter:seed-authorization
```

These commands register store-scoped **capability** roles. They create no users, memberships or assignments:

- `store_availability_operator`: product reads and sold-out/restored availability only; no product configuration, prices or payment authority.
- `store_order_reader`: restricted store order reads only.
- `store_counter_session_operator`: counter-session reads, participant/notes updates, ending closed sessions, and creating orders in an active session.
- `store_counter_order_editor`: counter-session reads and eligible order replacement.
- `store_catalog_operator`, `store_order_operator`, `store_operation_auditor` and `store_counter_manager` retain their documented capabilities.

The console's "Kitchen" and "Cashier" views are frontend compositions of those grants, not backend roles. A typical frontend cashier combines `store_counter_session_operator`, `store_counter_order_editor` and `store_order_reader`. Assign only the needed capabilities to active members for the intended store through existing authorization management. The seeds are additive and never remove previously configured extra permissions. `store_counter_manager` does not gain session or order-entry authority. Image upload, counter sessions and history require their own scoped grants. Order actions reuse existing accept/reject/fulfill/verify permissions. Do not grant global administrator status to bypass missing employee grants. Reconnect after role assignment to refresh the UI permission snapshot. This release does not automatically seed or assign roles in a running store database.

See the [operations contract](../../docs/contracts/store-console-operations.md) and [counter operations contract](../../docs/contracts/store-console-counter-operations.md) for route/DTO compatibility, exact grants, command/version semantics, deployment caveats and the explicitly proposed settlement/payment phase. Operations history covers successful operational and counter-session commands, not all legacy CRUD or financial transactions.

Session amounts are order summaries, not checkout totals or collection results. Additional items create independent orders. Replacements atomically cancel an eligible unpaid/unprocessed order and create its replacement; they do not overwrite issued lines or bypass payment/inventory safeguards. All orders must close before ending a session. Existing workers still perform Store projection, acceptance, inventory and fulfillment. No payment or printed result is fabricated. After an uncertain order submission, close and refresh to verify; an unchanged manual retry retains its durable command key. Do not open a new cart to blindly repeat a possibly committed order.

### Connection and credentials

The app's own `src/staff/adapters/api-client.ts` sends same-origin `/api/` requests with bearer tokens and no cookies; it does not share a client package or credentials with Cashier or the customer entry. API responses and pagination are runtime-validated. Access/refresh tokens live only in the staff page's memory: reload, switching into the isolated demo, or opening another tab requires a new login. Tokens are not written to URLs, local/session storage, logs or customer links. Logout clears local state immediately and attempts backend token revocation; a failed revocation is reported. Reload alone does not revoke server-side tokens.

Concurrent 401 responses share a single rotating refresh request. An expired/invalid refresh clears staff data and returns to login. Network-failed writes and refresh requests are not automatically replayed: a timeout can mean the server committed a write, so refresh and verify before manually retrying. New product/availability/order commands use opaque content versions and durable per-store/actor command receipts. Unchanged manual retries reuse their key; changed/stale input returns a conflict rather than overwriting. Image uploads have a separate command receipt; a failed product save or cancellation may leave an unused image. Existing category/specification/table CRUD remains unversioned and outside this receipt audit. After a confirmed write the list is re-read; stale page/unmounted-view responses are ignored. These protections do not establish conflict safety with legacy clients or MySQL concurrency. Backend membership and per-action checks remain authoritative; UI permissions are a snapshot, refreshed by reconnecting the store, and a later server denial is shown rather than bypassed.

The Vite development server proxies `/api` to `http://127.0.0.1:8000`. Start the backend using the [development guide](../../docs/operations/development.md); this app does not initialize databases, migrate schemas or create employee accounts. To use a different **trusted development backend**, set `STORE_CONSOLE_API_TARGET` in an ignored package `.env.local` and restart Vite. It is server-side proxy configuration, not a browser-supplied API host. Use test data: saves/deletes in live mode affect the connected backend. Do not put credentials in this setting or point it at production for routine checks.

Build preview does not proxy the API. A deployment must serve this app and route same-origin `/api/` requests to the composed backend under HTTPS, with appropriate security headers. No production routing, Docker or deployment changes are included here. The default staff entry now shows real login instead of demo fixtures; use `?mode=demo` for the previous demo experience.

## Isolated UI demo

Open `?mode=demo` explicitly. **This mode is not connected to real store authentication, APIs, payments or printers.** The employee/store identity and all initial products/tables are fixtures. They grant no actual permissions. Every payment action is visibly labeled as a simulation; no network request, provider SDK or real funds transfer is involved. Never enter real payment codes or credentials.

Implemented demo UI flows:

- Home dashboard with session-based collection totals, pending table amounts, shortcuts and dining tables.
- Product/category filtering, product name/code search, sold-out/restored availability, batch restore, category/product ordering and category creation.
- Product editing/creation, up to eight price variants, optional local image preview, kitchen print flags and sale schedule fields. Image previews accept PNG/JPEG/WebP up to 2 MiB and remain in memory. Sale schedules and print flags are UI metadata, not backend-enforced selling rules or actual print jobs.
- Table status filtering, item/variant additions, quantity editing, notes, demo discount validation and order cancellation. The demo does not offer table creation or deletion.
- Checkout review with an explicit extra-charge notice; no tea/tissue fees are assumed or silently added. A positive bill creates a restricted demo payment projection and freezes further editing until the link is voided or collection resolves.
- Customer payment method selection, success, failure, unknown outcomes and lookup. Staff receives same-browser payment updates and settles the table once, then displays the receipt/history/report data.
- Collected order search/date filtering, receipt previews, current-session date-range reports and product sales ranking. Reports start at zero; they do not fabricate paid sales or growth trends.
- Account/service-status screens, Chinese/English UI, seven locally persisted color preferences, help and confirmed demo reset.
- Offline device detection blocks new submissions. This is `navigator.onLine`, not a live backend health check.

Printer destinations and ordering-code screens deliberately show their not-connected state. They do not fabricate successful prints or display unusable QR codes as though they were real ordering/payment codes.

## Run locally

Use Node.js 22.12+ and npm. This package has its own lockfile; no root workspace or Vue admin dependency installation is required.

From the repository root:

```sh
npm ci --prefix apps/store-console
npm run dev --prefix apps/store-console
```

Open `http://127.0.0.1:5180/` for live login or `http://127.0.0.1:5180/?mode=demo` for fixtures. Phone viewports start at 320 px; the live workspace expands into multi-column tablet/desktop layouts, while demo/customer entries retain centered mobile layouts. The desktop cashier uses a different development port.

Demo staff navigation uses hash routes (`#home`, `#products`, `#tables`, `#reports`, `#mine` and `#history`). Live navigation is page-local and resets on reload. The demo customer entry is `payment.html?session=<demo-session-id>`. Use the link created by demo table checkout rather than inventing a session ID. Opening `payment.html` without a valid session displays a safe missing-bill screen. Live mode cannot generate links to this payment entry.

Demo payment collection uses the browser Web Locks API on localhost/HTTPS to coordinate tabs. A secure browser context is required. A link must be opened in the **same browser profile and origin** as the staff demo; a link copied to another device/browser has no matching demo data. Real cross-device payment needs a server-backed payment session and is not implemented. Do not expose the development server as a production payment service.

## Demo data lifetime and isolation

- Products, categories, table edits, held checkout associations and staff receipts live in memory only. Reloading the staff entry restores fixtures and loses those edits and associations. Existing order lines retain their own display/price snapshot when catalog data changes.
- Only the validated theme identifier is persisted as a preference (`ns-store-console.theme.v1`). Browser storage failures are shown without breaking the current theme.
- For cross-tab UI demonstration, `ns-store-console.demo-payments.v1` stores **only** a validated public projection: random demo ID, store/table display names, item names/quantities/prices, amount/discount, expiry and simulated payment state. It excludes employee identity, tokens, roles, order notes and staff product/catalog data. The record list is bounded to 20 sessions and new writes prune expired records. Links have a 15-minute collection lifetime; unresolved attempts can still be looked up so expiry does not falsely declare them failed.
- Demo payment records may remain locally until replaced or browser data is cleared. They are not durable financial receipts, not encrypted and not an authorization mechanism. Same-origin code/devtools can modify them. Do not put real order or personal data in this prototype.
- `src/payment/main.tsx` and `src/staff/main.tsx` are separate Vite entry points. The payment dependency graph has no staff modules; a boundary test enforces that. Shared code contains localization, base UI and the narrow demo payment projection. Independent entries on one origin do **not** create an origin-level security boundary.
- Unknown/processing payment states block further collection and order editing. Explicit lookup resolves the original simulated attempt. Failed/pending links may be voided before repricing. Browser locks and UI checks are demonstration safeguards, not server transactions, durable idempotency or production concurrency guarantees.

## Checks and build

```sh
npm test --prefix apps/store-console
npm run build --prefix apps/store-console
```

For mobile browser flows, run from this package:

```sh
cd apps/store-console
npx playwright install chromium
npm run test:e2e
```

Browser tests exercise both demo flows and live-mode UI against deterministic intercepted API responses. They cover permissions, conflicts/denials, raster uploads, catalog fields, order fulfillment, DTO failures, expired sessions and separation from demo/payment behavior, plus actual computed colors for all seven themes, preference persistence, pending-save locks, keyboard focus, advanced-field validation and fixed actions at 320/390/768/1280 px. They do not write to a running development or production service. Unit tests cover transport contracts, multipart encoding, pagination, refresh races and scope checks. Backend composition tests `StoreConsoleApiTest` and `StaffOperationsTest` independently exercise authentication, CRUD, scoped operations, durable receipts, redaction, availability, image ownership, seeding and the migration against isolated SQLite. Run them with `php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml --filter 'StoreConsoleApiTest|StaffOperationsTest'` from the repository root. These tests do not replace authenticated acceptance testing against the intended MySQL deployment.

`build` runs TypeScript checking and produces both HTML entries in `dist/`. `npm run preview` serves the built app on localhost port 4180 (without an API proxy). Serve both HTML files and generated assets from the same directory under HTTPS in a future deployment; the relative Vite asset base supports a dedicated subdirectory, while API calls remain rooted at `/api/`. No Nginx routes, Docker context, PWA/service worker or existing admin/API deployment are changed. CI explicitly installs and checks this app in its own job.

Counter-session unit/browser tests additionally cover restricted order DTOs, session/order read separation, per-action and edit-only grants, customer-order replacement, automatic session availability and addition/ending at responsive widths, quote conflicts, offline blocking and unchanged retries after a committed-but-network-failed write. Run the corresponding isolated backend suite with `php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml --filter CounterOperationsTest` from the repository root. It also tests single-pass/customer-context calculation, policy/price/version changes, receipt tracking, capability-role separation and rollback of replacement failures.

The current dependency audit reports a low-severity `deepmerge` advisory inherited through Ant Design Mobile (two affected dependency entries). The latest published `deepmerge` version has no patched release at implementation time. Configuration/locale objects are project-owned; do not pass untrusted objects to UI configuration merges. Track the upstream fix before production release. No forced downgrade or unreviewed dependency override is applied.

Ant Design Mobile declares React 19 support, but some transitive animation/component packages still declare peer ranges through React 18. npm emits peer warnings; lockfile-based `npm ci`, TypeScript/build checks and the implemented browser flows are validated without `--force` or `--legacy-peer-deps`. Keep this compatibility limitation in the dependency upgrade review.

## Ownership and live integration

```text
src/staff/              Demo staff UI and local catalog/table orchestration
src/staff/live/         Live login, permission-aware resource UI and paginated reads
src/staff/adapters/     App-owned live API client/DTOs and separate fixture gateway
src/payment/            Customer entry with no dependency on staff modules
src/shared/             Base UI, locale/preferences and narrow demo-payment transport
src/locales/            English and Simplified Chinese resources
tests/                  Domain, boundary and mobile browser checks
```

The app owns mobile UI and host adapters. Backend order/stock/pricing/discount/payment/printing rules remain in business modules. The demo gateway is a UI bootstrap seam, not a promised live transaction API. Live mode reuses the existing employee APIs; it does not reuse administrator CRUD or customer order ownership as staff authorization. Add missing business endpoints in `business/backend/` before expanding live workflows. Do not edit `core/` for this UI.

Before enabling live **collection**, implement durable payment idempotency/recovery, short-lived restricted customer payment sessions, trusted payment-result lookup/webhooks and configured print destinations. Employee ordering is implemented, but MySQL concurrency and deployment-specific business/worker policies still require acceptance. Keep employee credentials out of customer URLs/storage and isolate customer/employee credentials; an independently served customer origin may be appropriate for production. Browser/provider callbacks alone must never establish that a payment succeeded. Actual mobile browsers, WeChat/Alipay environments, printers and business integrations require separate acceptance testing.
