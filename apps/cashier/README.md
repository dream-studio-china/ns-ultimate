# NS Cashier

An independent, touch-first store cashier application built with Electron, React, TypeScript, Vite, Tailwind CSS and locally owned shadcn-style components backed by Radix primitives. macOS is supported for development and testing; Windows x64 is the delivery target.

## Current scope and safety boundary

**This is an interactive UI framework and explicit demo, not a production cashier.** It starts directly in the labeled demo workspace. The displayed employee and store are fixtures, not authenticated identities. No request reaches a store API or payment provider. QR artwork is an icon placeholder, not a payable QR code, and the app never accepts real payment codes.

Implemented UI flows:

- Product categories, name/code search, availability, size/temperature selection and quantity limits.
- Basket editing, dine-in/takeaway selection, notes, clearing, holding and restoring up to 20 baskets.
- Cash keypad, change calculation, and customer-scan / merchant-scan payment layouts.
- Explicit simulated success, failure and unknown outcomes. Unknown outcomes block closing the payment dialog, further collection and basket editing until a lookup resolves the original attempt. Concurrent demo requests share a session-local request key.
- Session order history, receipt preview, shift summaries, help, demo exit/re-entry and Chinese/English UI.
- Seven color schemes (red, orange, yellow, green, blue, indigo and purple) in Settings. The operator saves the selected theme locally and projects it to the customer display; product artwork and semantic error/success colors remain unchanged.
- Independent customer window displaying only a narrow basket/status projection, without staff identity, credentials or order notes.
- Desktop display selection, fullscreen control, secondary-display auto-selection, single-screen windowed preview and display-change listeners. When the selected customer display is removed, the customer window closes rather than covering the operator; it reopens on an available external display. F11 toggles fullscreen and Escape exits it.

All basket, held-order and receipt data is **memory-only**. Reloading, exiting the demo or closing the app loses it. This is deliberate until durable store transaction APIs and recovery contracts exist. Device `navigator.onLine` status blocks new payment attempts when offline; it is not proof of backend availability. A real adapter must implement authenticated service reachability and fail closed.

The color preference is the exception: `ns-cashier.theme.v1` in local storage contains only a validated theme identifier, defaults to green, and survives reloads and application restarts. It is scoped to the local application/browser profile, not an employee account or store. Browser development and the packaged desktop app use different origins and do not share preferences. Storage failures keep the current theme usable and show a warning; the customer window follows operator snapshots without writing preferences. Clearing application/browser data resets the preference. The internal customer snapshot requires a validated `theme` identifier; both renderers and the main process are built together.

## Local development

Use Node.js 22.12+ (Node 22 is used in CI) and npm. This app has its own lockfile and is not part of the Vue admin build.

From the repository root:

```sh
npm ci --prefix apps/cashier
npm run dev --prefix apps/cashier
```

`dev` builds the Electron main/preload bundles, starts Vite on `http://127.0.0.1:5178` and launches Electron. Renderer updates use Vite HMR. Restart `dev` after editing Electron or shared IPC code. Do not run `dev:web` on the same port at the same time.

For browser-only UI work:

```sh
npm run dev:web --prefix apps/cashier
```

Open the printed URL. Settings provides a customer-screen preview link; browser tabs synchronize demo display data through a same-origin `BroadcastChannel`. Keep only one operator tab open. Browser preview does not manage physical displays or desktop fullscreen. The main desktop process is the sole publisher in Electron.

The touch workspace targets at least **1024 × 768 device-independent pixels**. Validate Windows display scaling, native touch, input methods and actual dual-screen hardware separately; browser emulation and single-display development are not hardware acceptance tests.

## Checks

```sh
npm run type-check --prefix apps/cashier
npm test --prefix apps/cashier
npm run build --prefix apps/cashier
```

Run browser tests from this package directory:

```sh
cd apps/cashier
npx playwright install chromium
npm run test:e2e
npm run test:desktop
```

`test:e2e` starts Vite if needed and exercises checkout, held baskets, failure/unknown recovery, offline gating, localization, all seven color schemes, preference storage failures and customer-display synchronization. `test:desktop` builds and launches actual Electron windows, checks the production custom protocol, renderer isolation, customer IPC restrictions, synchronization, demo checkout and theme persistence across a full application restart. It uses a temporary profile so it does not overwrite the operator's preferences; a graphical macOS/Windows session is required.

After `npm run pack` on Apple Silicon macOS, run the same smoke against the actual packaged executable:

```sh
node scripts/test-desktop.mjs "release/mac-arm64/NS Cashier.app/Contents/MacOS/NS Cashier"
```

The repository CI has a dedicated cashier browser/unit/build job. Installer generation is an explicit, manually triggered [desktop packaging workflow](../../.github/workflows/cashier-package.yaml), separate from the composed admin/API Docker deployment. This package does not change the Docker context or backend discovery rules.

## Build and packaging

From the package directory:

```sh
npm run build
npm start
npm run pack
```

Build target installers on their native platform:

```sh
# On Windows, produce an x64 NSIS installer.
npm run dist:win

# On macOS, produce a DMG for the host architecture.
npm run dist:mac
```

Artifacts go to `release/`. The package currently uses default Electron icons and has no updater, code-signing identity or notarization configured. The manual workflow produces unsigned test installers only; it does not publish releases. Configure signing/notarization and release acceptance before distributing to stores. A macOS UI check does not establish that the Windows installer, drivers, touch or payment devices work.

## Ownership and extension points

```text
electron/               Main process, restricted protocol, displays and preload
shared/desktop.ts       Validated customer projection and narrow desktop bridge
src/adapters/           Demo gateway and future explicit host API adapters
src/domain/             UI basket types and integer-minor-unit arithmetic
src/components/ui/      Locally owned, shadcn-style foundation components
src/components/         Cashier interaction components
src/pages/              Orders, shift, settings and customer display
src/locales/            English and Simplified Chinese resources
tests/                  Deterministic domain and browser tests
```

The app owns desktop/UI behavior and host-specific adapters. Order, stock, pricing, payment authorization and general domain rules remain authoritative in `business/backend/` and existing backend services. Do not copy backend rules into the renderer or call administrator APIs as a substitute for store authorization. Store-facing product APIs exist, but the currently inspected order quote/create/payment endpoints are app-user flows; store-employee collection needs a verified contract before a production adapter is enabled. The gateway currently accepts a demo outcome intentionally and must not be treated as a live payment contract.

No `core/` changes are needed for this framework. New store business endpoints, when required, belong in business modules. No live API base URL or token is configured yet. A future adapter must own store-scoped authentication, role checks, canonical server pricing, durable idempotency and lookup/recovery. Enabling remote calls also requires a deliberately scoped production CSP and secure token handling; the current production policy allows only local resources.

Electron uses sandboxed renderers, context isolation, no Node integration, blocked navigation/popups/permissions, a constrained local `cashier://app` protocol, validated IPC senders and role-gated customer publication. These are a baseline, not a completed production security review. Hardware APIs are not exposed to the renderer.
