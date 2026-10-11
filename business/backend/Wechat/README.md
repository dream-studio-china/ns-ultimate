# Wechat business module

The Wechat module owns store-scoped WeChat configuration and the dynamic
adaptation that lets each Store use its own Mini Program, Official Account, and
merchant credentials. It is independent of the core `App\Wechat` module: core
keeps a single environment-configured client, while this module resolves an
immutable, per-Store configuration at runtime.

See [`docs/tasks/store-wechat-configuration.md`](../../../docs/tasks/store-wechat-configuration.md)
for the full design and acceptance criteria. This README describes only what is
implemented.

## Implemented in the configuration foundation

- Entities (tables use the `wechat_` prefix, never `business_`):
  - `wechat_application` / `wechat_application_revision`
  - `wechat_merchant` / `wechat_merchant_revision`
  - `store_wechat_setting`
- Entity constructors take no required arguments and expose property setters, so
  the Core `BaseService` CRUD lifecycle can instantiate and populate them.
- `SecretCipher` encrypts credential material with AES-256-GCM. The master key is
  supplied as a base64-encoded 32-byte value through `WECHAT_CREDENTIAL_KEY` and
  is never stored with the ciphertext. Payloads record a key id for rotation.
- Encrypted columns are exposed only through non-getter methods (`encryptedX()`),
  so the core serializer never emits ciphertext. Mutating commands expose
  `isXConfigured()` booleans instead.
- `WechatApplicationRevisionService` and `WechatMerchantRevisionService` validate
  and encrypt credentials during creation, and publish revisions by superseding
  the previous published revision of the same profile.
- `StoreWechatSetting` pins specific published application/merchant revisions, so
  editing a shared profile does not silently change every bound Store.
- `StoreWechatResolver` resolves `(storeUuid, capability)` to a decrypted,
  immutable `ResolvedWechatConfig` and fails closed when configuration is
  missing, disabled, unpublished, or mismatched. It reads authoritative database
  state on every call, so a saved binding takes effect without a restart.
- `WechatClientFactory` builds EasyWeChat clients from a resolved configuration
  and memoizes them per revision identity. Access-token caches are namespaced per
  AppID. There is no mutable "current store" state, so long-running processes
  cannot leak one Store into another.
- Migration `Version20261011040000` creates the tables and explicit
  indices/constraints.

## Management API

Every management controller follows the Core view-mixin lifecycle
(`ApiView` + List/Detail/Create/Update/Delete mixins) with a `BaseService`
subclass, like the Counter module. Domain validation lives in
`processCreateContent` / `processUpdateContent` / `processDeletion`; responses are
produced by the core `success()`/`warning()` pipeline.

- Administrator (`ROLE_ADMIN`), also served under `/api/v1`:
  - `/manage/wechat-applications`
  - `/manage/wechat-application-revisions` (create encrypts credentials; update
    with `{"state": "published"}` publishes)
  - `/manage/wechat-merchants`
  - `/manage/wechat-merchant-revisions`
  - `/manage/store-wechat-settings`
- Store-scoped, guarded by `store:wechat:read` / `store:wechat:create` /
  `store:wechat:update` permissions and the resolved Store scope:
  - `/store/{scopeId}/wechat-setting`
- `app:wechat:seed-authorization` seeds those permissions and the
  `store_wechat_manager` Store role.

## Admin UI

The Admin frontend provides configuration-driven collections under
`business/admin/config/collections/wechat/` and a "Wechat management" menu group in
`business/admin/router.js`. Endpoints follow the default
`/api/v1/manage/<plural>` convention:

| Collection | Endpoint |
| --- | --- |
| `WechatApplication` | `/api/v1/manage/wechat-applications` |
| `WechatApplicationRevision` | `/api/v1/manage/wechat-application-revisions` |
| `WechatMerchant` | `/api/v1/manage/wechat-merchants` |
| `WechatMerchantRevision` | `/api/v1/manage/wechat-merchant-revisions` |
| `StoreWechatSetting` | `/api/v1/manage/store-wechat-settings` |

Credential inputs (`secret`, `officialToken`, `officialAesKey`, `apiV3Key`,
`privateKey`, `certificate`, `platformPublicKey`) are create-only fields; they are
hidden on update and the backend never returns them. Publishing a revision is done
by setting its `state` to `published` in the update form. Store-scoped
self-management is available through the API but has no dedicated Admin page yet.

## Login and identity

- `WechatIdentity` (`wechat_identity`) keys identity by the verified
  `(app_id, openid)` pair and may attach many identities to one system User.
  Session keys are encrypted; equal OpenID strings under different AppIDs never
  collide and accounts are never merged implicitly.
- `StoreWechatLoginService` orchestrates Mini Program login, phone binding,
  explicit identity linking, and Official Account OAuth, always resolving the
  published Store configuration first.
- `WechatLoginGatewayInterface` is the provider boundary; `EasyWeChatLoginGateway`
  implements it over the client factory, and tests substitute a deterministic
  fake.
- Official Account OAuth uses an opaque single-use state row
  (`wechat_oauth_state`) that binds Store, application revision, approved redirect
  host, and expiry; callbacks reject substitution, replay, and expiry.
- Customer routes (also served under `/api/v1`):
  - `/app/stores/{storeUuid}/wechat/miniapp/login` (anonymous)
  - `/app/stores/{storeUuid}/wechat/miniapp/phone` (authenticated)
  - `/app/stores/{storeUuid}/wechat/miniapp/link` (authenticated)
  - `/app/stores/{storeUuid}/wechat/oauth/url` (anonymous)
  - `/app/stores/{storeUuid}/wechat/oauth/callback` (anonymous)
- `StoreWechatAccessMap` decorates `security.access_map` to expose only the
  login/OAuth endpoints anonymously, without modifying the core security subtree.
- `app:wechat:import-legacy-identities --app-id=... --type=miniapp|official
  [--dry-run]` maps legacy core `WechatUser` rows onto app-scoped identities
  without guessing an AppID or merging users.

## Security controls

- `WechatProfileGrant` (`wechat_profile_grant`) grants a Store the right to bind a
  shared application/merchant profile. Store-scoped binding enforces the grant;
  platform administrators bypass it. Grants are immutable and managed through
  `/api/v1/manage/wechat-profile-grants`.
- `WechatAuditEvent` (`wechat_audit_event`) is an append-only, redacted audit log.
  `WechatAuditLogger` records publication, grant changes, and probes with the
  actor, Store, object, and result; sensitive context keys are redacted.
- Revision and Store-binding entities use Doctrine `#[ORM\Version]` optimistic
  locking; a concurrent publication surfaces as HTTP 409.
- `app:wechat:probe-credentials {application|merchant} {uuid}` performs an offline
  readiness probe (decryption and PEM parsing only, never contacting WeChat) and
  audits the result.

## Payments and refunds (foundation)

Merchant mode supports `direct` (ordinary merchant) and `partner` (service
provider + sub-merchant); a partner binding requires `subMerchantId` (and optional
`subAppId`).

- `WechatPaymentAttempt` (`wechat_payment_attempt`) persists one unresolved
  collection attempt per Invoice, pinning the trusted Store, application/merchant
  revisions, payer identity, provider order number, and expected external amount.
  A repeated idempotency key with identical inputs reuses the attempt; conflicting
  inputs and restarting a completed attempt are rejected.
- `WechatRefundAttempt` (`wechat_refund_attempt`) persists refund requests with a
  stable provider refund number and an idempotent `accounting_applied_at` marker.
- `InvoiceStoreContextAdapter` derives the trusted Store from persisted source
  data (`trade_order` → `OrderStoreLifecycle`) and fails closed for unsupported
  invoice sources.
- `WechatPaymentGateway` implements the Core `PaymentGatewayInterface` for payment
  name `wechat` with Mini Program JSAPI, Official Account JSAPI, and Native
  prepay, a signed JSAPI payload, and notify verification against the pinned
  attempt (order number, amount, currency, merchant, application). A compiler pass
  removes the core registration so exactly one deterministic `wechat` gateway
  exists. Core-initiated refunds are refused so refunds cannot bypass the
  orchestrator.
- Trade types map to capabilities and application types: `jsapi`
  (`payment_jsapi`, Mini Program), `official_jsapi` (`payment_official`, Official
  Account; payer resolved as an Official Account identity and the payload built
  with `buildSdkConfig`), and `native` (`payment_native`, Mini Program).
- Partner mode sends service-provider fields (`sp_appid`, `sp_mchid`, `sub_mchid`,
  optional `sub_appid`, `payer.sub_openid`); direct mode sends `appid`, `mchid`,
  and `payer.openid`.
- Notify callbacks arrive at `/api/payment/notify/wechat?locator=<attempt uuid>`;
  the locator selects the pinned attempt configuration.

Still to come: the provider query/reconciliation command, the versioned
provider-query inbox, and the project-owned refund orchestrator with its safe
accounting adapter (confirmed option A). No refund path can reach WeChat yet.

## Not implemented yet

- Store-aware payment initiation, callbacks, refunds, and reconciliation.
- A frozen legacy gateway dispatcher for pre-existing global transactions.
- A dedicated Store-scoped Admin page and a purpose-built credential/revision UI.
- Per-profile Store grants, append-only audit records, redacted credential
  probes, optimistic-concurrency protection on publication, and key rotation.

Real payment and refund enablement stays blocked until the asynchronous refund
accounting boundary in the task plan is resolved and tested.

## Configuration

Set the credential master key in the integration backend env (declared as an
empty placeholder in `.env`; tests use a test-only key in `.env.test`):

```dotenv
WECHAT_CREDENTIAL_KEY=base64-encoded-32-byte-key
```

Generate a key with `openssl rand -base64 32`.

## Tests

The module's integration tests are registered in `integration/backend/phpunit.xml`:

```sh
php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml \
  business/backend/Wechat/tests/Integration
```

They drive the CRUD lifecycle over HTTP and cover entity registration, cipher
round-trip and tamper detection, per-Store resolution and isolation, fail-closed
behavior, rejection of unpublished revisions, encrypted-at-rest storage, secret
non-disclosure in API responses, deletion protection, authorization, the seed
command, and migration SQL on SQLite.
