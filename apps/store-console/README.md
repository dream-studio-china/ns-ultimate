# NS Store Console

An independent mobile-first Web workspace for store staff, built with React, TypeScript, Vite, Ant Design Mobile and Tailwind CSS. It contains a separately built customer payment entry. The product workflow is inspired by the referenced kitchen/cashier/payment H5, without copying its implementation, branding, assets or API assumptions.

## Status: explicit UI demo

**This application is not connected to real store authentication, APIs, payments or printers.** The employee/store identity and all initial products/tables are fixtures. They grant no actual permissions. Every payment action is visibly labeled as a simulation; no network request, provider SDK or real funds transfer is involved. Never enter real payment codes or credentials.

Implemented UI flows:

- Home dashboard with session-based collection totals, pending table amounts, shortcuts and dining tables.
- Product/category filtering, product name/code search, sold-out/restored availability, batch restore, category/product ordering and category creation.
- Product editing/creation, up to eight price variants, optional local image preview, kitchen print flags and sale schedule fields. Image previews accept PNG/JPEG/WebP up to 2 MiB and remain in memory. Sale schedules and print flags are UI metadata, not backend-enforced selling rules or actual print jobs.
- Table status filtering, table creation, item/variant additions, quantity editing, notes, demo discount validation and order cancellation.
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

Open `http://127.0.0.1:5180/`. The workspace is designed for phone viewports from 320 px wide, with a centered maximum width on tablets and desktop browsers. The desktop cashier uses a different development port.

Staff navigation uses hash routes (`#home`, `#products`, `#tables`, `#reports`, `#mine` and `#history`). The customer entry is `payment.html?session=<demo-session-id>`. Use the link created by table checkout rather than inventing a session ID. Opening `payment.html` without a valid session displays a safe missing-bill screen.

Demo payment collection uses the browser Web Locks API on localhost/HTTPS to coordinate tabs. A secure browser context is required. A link must be opened in the **same browser profile and origin** as the staff demo; a link copied to another device/browser has no matching demo data. Real cross-device payment needs a server-backed payment session and is not implemented. Do not expose the development server as a production payment service.

## Data lifetime and isolation

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

Browser tests exercise mobile navigation/layout, catalog/category/table editing, checkout, cross-entry payment completion, failure/unknown/void states, offline gating, language and local theme persistence. Tests use isolated browser contexts and do not call live services.

`build` runs TypeScript checking and produces both HTML entries in `dist/`. `npm run preview` serves the built app on localhost port 4180. Serve both HTML files and generated assets from the same directory under HTTPS in a future deployment; the relative Vite base supports a dedicated subdirectory. No Nginx routes, Docker context, PWA/service worker or existing admin/API deployment are changed. CI explicitly installs and checks this app in its own job.

The current dependency audit reports a low-severity `deepmerge` advisory inherited through Ant Design Mobile (two affected dependency entries). The latest published `deepmerge` version has no patched release at implementation time. Configuration/locale objects are project-owned; do not pass untrusted objects to UI configuration merges. Track the upstream fix before production release. No forced downgrade or unreviewed dependency override is applied.

Ant Design Mobile declares React 19 support, but some transitive animation/component packages still declare peer ranges through React 18. npm emits peer warnings; lockfile-based `npm ci`, TypeScript/build checks and the implemented browser flows are validated without `--force` or `--legacy-peer-deps`. Keep this compatibility limitation in the dependency upgrade review.

## Ownership and live integration

```text
src/staff/              Staff UI, fixture gateway, local catalog/table orchestration
src/staff/adapters/     Explicit host integration seam (currently demo only)
src/payment/            Customer entry with no dependency on staff modules
src/shared/             Base UI, locale/preferences and narrow demo-payment transport
src/locales/            English and Simplified Chinese resources
tests/                  Domain, boundary and mobile browser checks
```

The app owns mobile UI and host adapters. Backend order/stock/pricing/discount/payment/printing rules remain in business modules. The current gateway is a UI bootstrap seam, not a promised live transaction API. Verify the existing store-facing contracts and add missing product-specific endpoints in `business/backend/`; do not reuse administrator rights or customer order ownership as staff authorization. Do not edit `core/` for this UI.

Before enabling live operations, implement store-member authentication and per-action authorization, canonical server pricing, concurrent edit conflict handling, durable payment idempotency/recovery, short-lived restricted customer payment sessions, trusted payment-result lookup/webhooks, configured print destinations and secure deployment headers. Keep employee credentials out of customer URLs/storage and isolate customer/employee credentials; an independently served customer origin may be appropriate for production. Browser/provider callbacks alone must never establish that a payment succeeded. Actual mobile browsers, WeChat/Alipay environments, printers and business integrations require separate acceptance testing.
