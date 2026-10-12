# Store WeChat adapter

This module adds application and merchant profiles and lets authorized Store
members select them. WeChat login, JWT creation, invoices, and payment
notifications reuse Core services; SDK calls use EasyWeChat applications built
from the selected Store configuration.

## Setup

Set `WECHAT_CONFIG_KEY` to a private, stable value before creating profiles. It
encrypts application and merchant credentials at rest. Losing or changing this
key makes stored credentials unreadable. The key is only required when creating
or using WeChat profiles; other payment methods remain available without it.
Merchant profiles must include the WeChat Pay platform certificate used to
verify callback signatures. The `.env.test` value is test-only.
Apply the module migrations in version order.

Administrators manage application and merchant profiles under `/manage` in the
Admin UI. Authorized Store members use the standard Core CRUD routes under
`/api/v1/store/{storeUuid}/wechat-settings`: `GET` lists the setting, `POST`
creates it, and `GET`/`PUT`/`DELETE /{id}` read, update, or delete it. Application
and merchant choices are available from `GET /options`.

Run `php bin/console app:wechat:seed-authorization` after migrations to register
`store:wechat_setting:read`, `create`, `update`, and `delete` permissions (each
with the same `store:wechat_setting:` prefix) and the `store_wechat_manager`
system role. Assign that role through the existing Authorization workflow with
the target Store scope. Like Counter, Store endpoints use
`StoreScopedAuthorizationApiMixin` and the Store voter: access requires both
active Store membership and the corresponding scoped permission. Membership
roles alone do not grant access. List, detail, and options require `read`;
writes require their respective permissions. Resource queries are always
restricted to the route's Store, and client input cannot change Store ownership.

Login continues to use
Core's `/api/wechat/miniapp/login`, `/api/wechat/oauth/url`, and
`/api/wechat/oauth/callback` endpoints; include `store_uuid` in the request
body (or query for OAuth URL). Mini Program and Official Account OpenIDs remain
separate naturally because WeChat issues them per AppID.

Login identities are stored as `(AppID, OpenID)` mappings to Core Users; no
session key or raw WeChat response is retained. A payer must have logged in
through the exact application selected by the order's Store.

Payments support Core Invoice JSAPI and Native flows for store-scoped Trade
orders. A payment records the selected profile identifiers on the Invoice so
refunds use the original merchant configuration. Notifications use a
profile-specific callback route and are applied through Core's Invoice service.
Only the Store binding is mutable; profile AppIDs and merchant IDs are not
editable through the API.

Both direct-merchant and WeChat Pay v3 service-provider modes are supported.
For a service-provider merchant profile, `merchantId` is the provider MchID,
`serviceProviderAppId` is the provider AppID, and `subMerchantId` is the
sub-merchant MchID. The Store's selected WeChat application AppID is used as
`sub_appid`; provider API credentials and certificates belong to the provider
merchant. Service-provider configuration is immutable after profile creation.
JSAPI uses the sub-merchant OpenID and Native does not require a WeChat login.
The partner API request, callback identity checks, and refund request include
the provider/sub-merchant identifiers.

Refund accounting is delegated to Core. This adapter only reports a refund as
successful when WeChat returns `SUCCESS`; pending refunds fail closed and use a
stable refund number on retry. There is no refund-notification/reconciliation
worker in this minimal scope, so live asynchronous refund behavior still needs
provider verification before enabling production refunds.

Live WeChat credentials and callbacks have not been exercised by automated
tests. The Core Invoice service still owns transaction and refund accounting
behavior; this adapter does not change it.
