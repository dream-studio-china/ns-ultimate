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

## Not implemented yet

- Mini Program login, phone binding, and Official Account OAuth endpoints.
- Store-aware payment initiation, callbacks, refunds, and reconciliation.
- Legacy identity import and the frozen legacy gateway dispatcher.
- A dedicated Store-scoped Admin page and a purpose-built credential/revision UI.
- Per-profile Store grants, append-only audit records, redacted credential
  probes, and optimistic-concurrency protection on publication.

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
