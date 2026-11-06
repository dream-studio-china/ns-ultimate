# Architecture

## Layers and dependency direction

```text
business/admin router + config ──> integration/admin config injection ──> core admin
apps/admin-agent frontend ───────> integration/admin loader + host adapter ─> core admin
apps/admin-agent confirmed writes ──> existing authenticated admin CRUD APIs
business/backend modules ───────> integration/backend Kernel ──────────> core Symfony app
                                           ▲
                              infra/docker Nginx + PHP-FPM
```

The diagram describes ownership, not a runtime network boundary: the frontend and backend are composed into one deployable application unless deployment needs later call for separation.

### `core/`

Contains the two upstream repositories as Git subtrees. Treat these directories as upstream-owned. Do not put product-specific modules here. Core changes should be generic, minimal, independently testable, and proposed upstream via a PR.

### `business/`

Owns all application-specific behavior, including frontend pages/components, menu and CRUD configuration, API clients, backend domain models, services, controllers, migrations, and business tests. Organize by business capability rather than by technical layer where practical. Backend modules should use a project namespace distinct from core's `App\\` namespace to avoid class collisions.

### `apps/`

Owns application packages. `apps/admin-agent/` is explicitly composed into the admin/API deployment and included in CI and Docker; other app directories remain excluded and are not automatically discovered. This establishes a frontend/backend ownership boundary, not an additional network service. General domain models and rules stay in business modules; agent-specific host bindings stay in the app's separate adapter directories and reuse those existing models and validation.

`apps/cashier/` is a separately built Electron/React desktop app with operator and customer renderers. It has explicit package-local UI checks and manual installer generation, not Docker or admin composition. Its current gateway is demo-only; live store authentication, employee order creation and payment integration are not implemented. Desktop/UI orchestration belongs to the app, while authoritative order/payment rules remain in backend business modules. See its [app guide](../../apps/cashier/README.md).

`apps/store-console/` is a separately built React mobile Web app with staff and demo customer-payment HTML entries. The default staff mode uses an app-owned API adapter for employee login, store-scoped catalog/table configuration, authorized order fulfillment and counter-session order entry; tokens remain in staff page memory. Business-owned operations provide explicit reads, catalog extensions, image ownership, versioned commands and receipts without changing Core CRUD. Counter sessions and their order entry are provided by the [counter operations contract](../contracts/store-console-counter-operations.md); reusable employee pricing/catalog/order writes live in the Store module, while Counter owns session, binding and replacement-eligibility rules. The explicitly selected demo retains same-browser simulated payments. Checkout and real payments are not enabled. The payment entry does not import staff modules, but shared-origin browser storage is not a security boundary. UI/adapters belong to the app; authoritative catalog/order/payment rules remain in backend business modules. It has explicit UI checks and is not composed into Docker or the admin. See its [app guide](../../apps/store-console/README.md) and [operations contract](../contracts/store-console-operations.md).

### `apps/admin-agent/`

Contains the optional assistant's generic UI, provider/documentation processing and config-driven local preview/confirmation lifecycle. Host-specific component bindings and read policies live in `adapters/ns-ultimate/admin/`. Integration supplies effective admin configuration, metadata, request and navigation APIs. Confirmed writes reuse existing CRUD endpoints rather than duplicate domain validation in Agent PHP adapters. No assistant backend package or HTTP operation routes remain; integration retains the historical migration identities and owns the forward migration dropping the retired tables. No agent implementation is added to business modules or core. See the [assistant design](admin-ai-assistant.md), [app guide](../../apps/admin-agent/README.md), and [operation contract](../contracts/admin-agent.md).

### `integration/`

Owns composition only. The admin side injects the business router and entity configuration at the core's `@/config` seam and optionally loads the independent agent through the integration-owned Vite plugin and session adapter. The backend Kernel discovers modules by the `business/backend/<PascalCaseSingular>/src/` convention and automatically wires their namespaces, services, routes, Doctrine mappings, and migrations when present. It must not become a home for domain rules.

### `infra/`

Owns project deployment assets. `infra/docker/` builds the composed frontend and backend and defines the project-level PHP-FPM, Nginx, MySQL, and Redis services; `infra/nginx/` routes `/admin/`, uploads, and backend requests to the integration front controller. These files do not modify or reuse the upstream core's Docker entrypoint. See the [Docker deployment foundation](../../infra/docker/README.md).

### `docs/`

Records layer boundaries, local development decisions, core compatibility, and synchronization procedures.

## Integration constraints discovered in upstream

- `crud-admin` is a Vite/Vue application whose router consumes `@/config`. `integration/admin/vite.config.ts` redirects only that exact specifier; all other `@/...` imports remain rooted in core. Business router and entity configuration are declared in `business/admin/`.
- Admin appearance settings live in `business/admin/appearance/`. Integration appends the business page to the existing System Options menu (displayed as System settings by the business translation overlay) at `/system-option/appearance`, preserving its existing children and administrator role restrictions; `/settings/appearance` redirects to this page. Integration loads palette styles and restores the browser-local `ns_admin_palette` preference through the config seam. Ten accent palettes (emerald, violet, orange, rose, sky, teal, indigo, amber, slate and pink) overlay the existing base themes using a separate `data-admin-palette` attribute; the core theme whitelist, `app_theme` preference and top-bar menu are unchanged. The responsive settings page provides a workspace preview and one-click restoration of the original palette. Selecting the original palette removes the overlay. Storage failures do not prevent in-session selection.
- `crud-skeleton` is a Symfony project. `integration/backend/src/Kernel.php` extends the core Kernel and preserves the core project directory. `ModuleRegistry` discovers business modules by directory convention; module service/route overrides stay inside the module. A sibling `business/backend/<name>/` without a `src/` directory is not a module.

The admin merge helpers and independent assistant have Node tests. The backend has a `Dummy` example module and an isolated SQLite integration test configuration. The example's API is under `/api/v1/manage/dummies`; its prior Note migration identity is retained to support a forward table rename on existing databases. GitHub Actions runs project workflow, assistant, and admin composition tests, integration/business PHPUnit tests, and the composed Vite build; it intentionally excludes upstream core test suites. Interactive business flows still require separate validation. Project-owned environment loading and local commands are described in [the development guide](../operations/development.md).

## Payment evolution: multiple invoices and public checkout

**Status: proposed follow-up, recorded on 2026-11-03; not an implemented payment guarantee.** This section records the intended behavior for a later change. It does not enable public payment initiation, introduce metadata fields, or change existing payment flows.

### Current behavior and limitations

- [Trade payment creation](../../core/crud-skeleton/src/Trade/Service/OrderService.php) requires a confirmed order, reuses a linked pending Invoice, and otherwise creates a new Invoice and changes the order's current Invoice reference. The previous Invoice retains its `sourceType = trade_order` and `sourceId = orderUuid`; changing the reference does not close its provider transaction.
- An Invoice receives its UUID and `outTradeNo` at creation, not on each `pay` call. A new Invoice has new identifiers. Huifu additionally creates its own attempt UUID and request sequence for a permitted new attempt; unresolved attempts are blocked per Invoice, not across every Invoice for the same order. See the [Huifu adapter](../../business/backend/HuifuFin/README.md).
- [Invoice event handling](../../core/crud-skeleton/src/Trade/EventListener/OrderInvoiceListener.php) resolves the order by the Invoice's source, not its current Invoice reference. An older Invoice can therefore settle a still-payable order if amount and currency match. A second success currently returns early when the order is already paid, without a dedicated duplicate-payment record. Failed and cancelled Invoice events currently overwrite the order's `paymentStatus` without checking whether the Invoice is current. Refund events also lack current/settled-Invoice isolation.

The business-owned [explicit payment closure capability](../contracts/order-payment-closure.md) now verifies noncollection through a common channel interface before local cancellation/release. Older Invoices must have confirmed closure receipts; ambiguous multiple-Invoice sources remain blocked. It does not implement the multiple-Invoice settlement/isolation policy below or change ordinary payment initiation and reconciliation.

### Intended multiple-Invoice policy

Provider closure can take minutes. A deliberately requested new payment may therefore create a new Invoice while the previous provider transaction is still unresolved; forcing customers to wait for closure is not the intended universal policy. Retain all Invoice/provider records. Distinguish the currently presented payment from the Invoice that actually settled the order; do not use one mutable association as the sole record of both concepts.

The first verified, eligible success with the correct order amount and currency settles the order once, even if it belongs to an older Invoice. A later success on a different Invoice must not replace the settled Invoice, repeat order side effects, or disappear silently: record it for manual review. Automated refunding is not required. Repeated delivery of the same Invoice success is idempotent, not a second collection. An additional success after fulfillment or completion must still be recognized as a duplicate collection; checking only `order.status == paid` is insufficient.

Failure or cancellation of a superseded Invoice must update that Invoice's own history but must not overwrite the current payment's state or downgrade an already settled order. Record the ignored stale event and its association for investigation. Apply the same isolation principle to refunds: refunding an extra collection must not make the settled order appear refunded. A success after order cancellation, an amount/currency mismatch, or another non-payable state also requires visible manual-review evidence rather than fabricated settlement. Locally cancelling an Invoice is not proof that the provider transaction was closed; a late success requires reconciliation even if the ordinary local workflow rejects it.

### Proposed metadata evidence

Initially, order metadata can hold the exception history rather than introducing a new exception entity. The following is illustrative, not a finalized schema or current API response:

```json
{
  "_paymentExceptions": [
    {
      "type": "duplicate_payment",
      "settledInvoiceUuid": "<settled-invoice-uuid>",
      "extraInvoiceUuid": "<extra-invoice-uuid>",
      "transactionId": "<provider-transaction-id>",
      "amount": 35000,
      "currency": "CNY",
      "paidAt": "<provider-payment-time>",
      "recordedAt": "<server-time>",
      "status": "pending_review"
    },
    {
      "type": "stale_invoice_event",
      "invoiceUuid": "<superseded-invoice-uuid>",
      "currentInvoiceUuid": "<current-invoice-uuid>",
      "event": "cancelled",
      "orderPaymentStatus": "paid",
      "recordedAt": "<server-time>",
      "effect": "ignored_for_order_state"
    }
  ]
}
```

Failure events use the same stale-event structure with `event = failed`. Exception evidence should retain Invoice identifiers, relevant provider references, amounts, currencies and event/recording times, without raw provider payloads, credentials or payer personal data. Manual resolution can append the operator, resolution time, refund/reference details and a bounded note; resolving a record must not erase the original evidence or automatically refund a payment. Informational stale-event records need not all become refund work items.

Server-side protection must cover every metadata creation/update path, not just the public checkout endpoint. Deduplicate extra successes by Invoice identity and stale events by Invoice/event identity (including a provider event identifier where applicable). Serialize settlement and metadata appends under an order lock and transaction so concurrent callbacks cannot settle twice or lose evidence. Metadata is an audit/display projection, not authorization or proof of payment; verified Invoice/provider records remain authoritative. If later history size or review queries require a dedicated entity, preserve the same semantics rather than relying on unbounded JSON growth. Intended validation includes concurrent successes, callback replay, old failure/cancellation after a newer payment, duplicate collection after completion, and refunding an extra collection without refunding the order.

### Public checkout boundary

The read-only order-UUID payment summary is now implemented under the [public order payment contract](../contracts/public-order-payment.md). It exposes a restricted Store/item/Invoice projection and configured Huifu channels, not payment-launch credentials. Anonymous lookup uses possession of the order UUID; stronger checkout credentials and payment initiation remain proposed. Store ownership comes from the trusted Trade lifecycle, not arbitrary metadata. An `outTradeNo` alone is not a payment-launch credential.

Huifu initiation can return Native QR data or JSAPI launch parameters after a provider request, but it is a write operation, not a side effect of GET. Reuse the existing Huifu attempt tracking and verified notification/reconciliation path; refreshes must not blindly initiate another payment, and deliberate new attempts must be distinguished from retries. Existing app Invoice payment requires the bound payer, management payment requires an administrator, and Store Huifu initiation requires scoped employee permissions; none is an existing anonymous checkout contract. Public initiation needs explicit order-level access/anti-abuse controls and safe credential expiry/reuse behavior. Huifu JSAPI currently resolves WeChat identity from `invoice.payer`, so guest/third-party payment must not silently use the original customer or employee's identity. Public access credentials, exact routes and DTOs, and manual-review permissions remain undecided. No changes to confirm-time pricing are part of this follow-up; existing creation/replacement pricing remains in place.

## Change policy

1. New business behavior belongs in `business/`.
2. Wiring belongs in `integration/`.
3. Only generic capabilities belong in core.
4. Keep license notices and upstream attribution intact.
5. Test core updates against integration and business modules before deployment.
