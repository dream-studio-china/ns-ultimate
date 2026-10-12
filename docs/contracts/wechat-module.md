# WeChat Module Contract

**Status: Validated by integration tests (`business/backend/Wechat/tests/Integration/`,
admin composition tests, composed admin build, production container lint, and a
MySQL migration rehearsal on an isolated database). Live-provider behavior is
explicitly out of scope until approved smoke tests run.**

## Ownership and registration

- Project-owned module `business/backend/Wechat/`, namespace
  `NsUltimate\Business\Wechat\`. Registration follows
  [backend-module-registration](backend-module-registration.md): no edit to
  `integration/` is needed to discover it.
- Tables use the `wechat_` prefix (never `business_`): `wechat_application`,
  `wechat_application_revision`, `wechat_merchant`, `wechat_merchant_revision`,
  `store_wechat_setting`, `wechat_profile_grant`, `wechat_audit_event`,
  `wechat_identity`, `wechat_oauth_state`, `wechat_payment_attempt`,
  `wechat_refund_attempt`. Explicit index/constraint names follow their tables.
- Migrations `Version20261011040000`–`Version20261011080000` create the schema;
  forward-only, data-preserving.
- One narrow core dependency: `App\Store\Entity\Store` implements
  `App\Authorization\Service\ScopedResourceInterface` so the authorization voter
  can resolve the store scope. Proposed upstream via the subtree workflow.

## Configuration model

- Profiles (`WechatApplication`, `WechatMerchant`) hold stable identities (AppID,
  merchant ID, mode `direct|partner`). Credentials live on immutable revisions
  encrypted with AES-256-GCM (`SecretCipher`, key from `WECHAT_CREDENTIAL_KEY`).
- Publishing supersedes the previous published revision (optimistic locking;
  concurrent publication returns 409). Only published, non-revoked revisions bind.
- A store binding (`StoreWechatSetting`) pins specific published revisions and a
  capability map (`miniapp_login`, `official_oauth`, `payment_jsapi`,
  `payment_native`, `payment_official`). Partner bindings require `subMerchantId`.
- Store operators bind only explicitly granted profiles (`WechatProfileGrant`);
  administrators bypass grants.
- Credential columns are never serialized (non-getter accessors only); APIs expose
  `isXConfigured()` booleans.

## Management APIs (all under `/api/v1/manage`, `ROLE_ADMIN`)

| Resource | Path | Notes |
| --- | --- | --- |
| Applications | `/manage/wechat-applications` | Standard CRUD |
| Application revisions | `/manage/wechat-application-revisions` | Create encrypts; `PUT {state: published}` publishes |
| Merchants | `/manage/wechat-merchants` | Standard CRUD, incl. `mode` |
| Merchant revisions | `/manage/wechat-merchant-revisions` | Create encrypts; publish as above |
| Profile grants | `/manage/wechat-profile-grants` | Immutable; revoke by delete |
| Store settings | `/manage/store-wechat-settings` | Pins published revisions |
| Refunds | `/manage/wechat-refunds` | Read-only list/detail plus `POST /request` |

Store-scoped (`/api/v1/store/{scopeId}/...`, `store:wechat:*` permissions):
`wechat-setting` (read/create/update), `wechat-refunds` (read + `POST /request`
requiring `store:wechat:refund`).

## Customer and webhook routes

- Mini Program: `POST /app/stores/{storeUuid}/wechat/miniapp/login` (anonymous),
  `/miniapp/phone` and `/miniapp/link` (authenticated).
- Official Account OAuth: `GET .../oauth/url` and `POST .../oauth/callback`
  (anonymous) with single-use opaque state bound to store, revision, redirect
  host, and expiry.
- Webhook: `POST /api/v1/webhooks/wechat/notify/{locator}` (anonymous) routes an
  opaque payment/refund attempt UUID; acknowledges only after the durable
  transition commits.

Anonymous exposure is composed by `StoreWechatAccessMap` decorating
`security.access_map`; no core security file is modified.

## Payment and refund behavior

- Exactly one deterministic `wechat` gateway: `WechatPaymentGateway`; a compiler
  pass removes the core registration tag. Trade types `jsapi`, `official_jsapi`,
  `native` map to capabilities and application types; partner mode sends
  `sp_*`/`sub_*` fields.
- Attempts pin store, revisions, payer identity, provider numbers, and expected
  amounts. One unresolved collection per invoice; idempotency keys with
  conflicting inputs fail.
- Payment confirmation (notify, query, reconcile) shares one verification path
  with conditional-update transitions; Core accounting via idempotent
  `handleNotifyResult`.
- Refunds (option A): persisted requests with stable refund numbers, pending
  reservation under pessimistic lock, accounting only after verified success via
  an idempotent claim, adjusted-invoice rejection before provider access.
  Core-initiated refunds are refused.
- Frozen legacy path: explicit `legacy: true` options and locator-less callbacks
  delegate to the core gateway. New store payments fail closed without a binding.

## Commands

`app:wechat:seed-authorization`, `app:wechat:import-legacy-identities`,
`app:wechat:probe-credentials`, `app:wechat:revoke`, `app:wechat:rotate-key`,
`app:wechat:reconcile`. All are idempotent or dry-run capable where stated.

## Admin UI

Collections for all seven resources plus a `WechatManagement` menu group;
credential inputs are create-only; en/zh/ja/zh-Hant locales.

## Known limitations (must not be relied upon)

- Official Account JSAPI payload shape, partner `sp_*`/`sub_*` field mapping, and
  refund notification timing are implemented against SDK/docs, pending live
  verification (`OPS-02`).
- Binding-level draft/publication history is deferred; bindings apply immediately.
- No dedicated store self-service Admin page; store operators use the API.
- Adjusted-invoice refunds are rejected until adjusted-refund accounting exists.
