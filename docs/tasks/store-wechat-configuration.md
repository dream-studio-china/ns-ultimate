# Store-scoped WeChat Configuration and Dynamic Adaptation

## 1. Status and purpose

- Created: 2026-10-11.
- Status: partially implemented. The Phase 1 backend configuration foundation (entities, encrypted credential storage, publication services, resolver, client factory, migration, and integration tests) is implemented and tested. Management APIs, Admin UI, login, payment, refunds, and legacy import remain pending. No financial capability is enabled.
- Goal: independently configure WeChat capabilities for each Store, dynamically select the correct application and merchant, and preserve login, payment, callback, and refund isolation across stores.
- Ownership: project-specific behavior belongs in `business/`; `integration/` owns composition only. Core changes require a demonstrated generic limitation and a separately reviewed fix.

This document is a task and acceptance specification, not a validated API contract. Proposed entity names, routes, and interfaces may be refined during implementation without weakening the security and financial invariants below. Promote implemented and tested contracts to `docs/contracts/` before release.

References:

- [Architecture](../design/architecture.md)
- [Backend module registration](../contracts/backend-module-registration.md)
- [Admin composition](../contracts/admin-composition.md)
- [Core synchronization policy](../operations/core-sync.md)
- [Development and environment loading](../operations/development.md)

## 2. Verified baseline and gaps

The following findings were checked against the current source, not inferred from historical session notes:

| Area | Existing behavior | Required change |
| --- | --- | --- |
| Client construction | `core/crud-skeleton/src/Wechat/Service/WechatService.php` reads constructor credentials and caches one MiniApp, OfficialAccount, and Pay instance | Resolve immutable configuration explicitly and construct isolated clients |
| Service configuration | `core/crud-skeleton/src/Wechat/Resources/config/services_wechat.yaml` supplies global environment values and one notify URL | Add project-owned configuration and explicit dynamic gateway composition |
| Login | `core/crud-skeleton/src/Wechat/Service/WechatAuthService.php` looks up users by OpenID alone | Scope identity lookup to the verified application |
| Identity schema | `core/crud-skeleton/src/Wechat/Entity/WechatUser.php` has globally unique OpenID and a unique one-to-one User relationship | Allow multiple application identities per system user |
| Payment | `core/crud-skeleton/src/Wechat/Service/Payment/WechatPayGateway.php` uses global merchant and MiniApp AppID and finds payer identity by User alone | Pin application, merchant, payer identity, and credential revisions to the payment attempt |
| Gateway registration | `core/crud-skeleton/src/Payment/Service/PaymentGatewayRegistry.php` indexes tagged services by `getName()`; duplicates overwrite according to iteration order | Ensure exactly one deterministic registration for the `wechat` payment name |
| Callbacks | `core/crud-skeleton/src/Payment/Controller/Webhook/PaymentNotifyController.php` selects a gateway only by payment name | Add a versioned callback locator and validate its relationship to the attempt |
| Refund accounting | `core/crud-skeleton/src/Payment/Service/InvoiceService.php::refund()` increments refunded amount after the gateway result without checking external refund completion | Introduce a safe asynchronous refund completion boundary before enabling real refunds |
| Duplicate paid notifications | `InvoiceService::markPaid()` returns immediately for already-paid invoices before checking notification amount/currency | Validate every notification in the adapter, including duplicates, before handing it to Core |
| Frontend payment schema | `business/admin/config/overrides/payment/PaymentChannels.json` describes credential fields | Do not treat this schema or its encryption description as proof of backend credential storage or dynamic payment wiring |
| Test discovery | `integration/backend/phpunit.xml` explicitly lists Dummy and Counter tests | Register new WeChat suites explicitly; module discovery does not imply test discovery |

EasyWeChat 6 supports constructing Application instances from configuration and setting cache namespaces. This is sufficient for the proposed factory, but does not automatically solve application identity, transaction routing, or secret lifecycle management.

## 3. Scope and decisions

### 3.1 Proposed first-release scope

Support both of these layouts using the same model:

1. Multiple stores share a Mini Program or Official Account while using independent merchant accounts.
2. Stores use independent applications and independent merchant accounts.

First-release capabilities:

- Admin configuration and Store-scoped management with backend authorization.
- Mini Program login and phone binding.
- Official Account OAuth login with protected state and redirect allowlists.
- Ordinary merchant direct payment: Mini Program JSAPI and Native.
- Payment notifications, payment-status reconciliation, asynchronous refunds, and refund reconciliation.
- Configuration publication, secret rotation, audit records, and legacy compatibility.

Official Account JSAPI, inbound account messages, template/subscription messages, H5/App payment, service-provider/sub-merchant payment, and Open Platform authorizer token management are follow-up capabilities. Their configuration must not imply that their runtime behavior already exists. In particular, enabling Official Account OAuth does not enable Official Account JSAPI payment.

### 3.2 Decisions requiring confirmation before implementation

| Decision | Proposed default | Gate |
| --- | --- | --- |
| Merchant integration | Ordinary merchant direct mode only in the first release | Confirm before payment implementation; service-provider mode needs different request and verification rules |
| Shared application credentials | Platform admins own shared profiles; store operators can bind only profiles explicitly granted to their store | Confirm the operational permission model before management APIs |
| System user sharing | Users remain platform-wide; WeChat identities are application-scoped; login never grants Store staff roles | Confirm account-linking policy before login implementation |
| UnionID linking | No automatic account merge; explicit verified linking only | Confirm separately if automatic linking is desired |
| Default profile | Explicit binding to a published platform profile; no runtime environment fallback for new store requests | Confirm legacy rollout expectations |
| Credential storage | Encrypted secret storage with an external master key and controlled private credential files | Confirm production key custody, backup, and rotation before release |
| Unresolved transaction retention | Keep historical profiles and usable credential references while payments/refunds need reconciliation | Approve a retention policy before deletion is enabled |
| Release packaging | Configuration/login may ship before payments; payments are gated independently | Confirm whether all capabilities must ship together |

Do not silently implement service-provider payment, user merging, shared secret access, or a new production key-management dependency as an assumed requirement.

## 4. Architecture and ownership

```text
Admin / Store operator                 Customer / webhook / worker
          |                                         |
Configuration commands                  Explicit operation context
          |                                         |
Draft validation -> publish             StoreWechatResolver
          |                                         |
Versioned profiles and bindings          Immutable resolved revision
          |                                         |
Encrypted credentials                   WechatClientFactory
                                                    |
                                  Login / payment / reconciliation
                                                    |
                                   Core User / Invoice interfaces
```

Proposed paths:

```text
business/backend/Wechat/
  src/Entity/
  src/Service/
  src/Adapter/
  src/Controller/{Admin,Store,App,Webhook}/
  src/Command/
  config/{services,routes}.yaml
  migrations/
  translations/
  tests/{Unit,Integration}/
business/admin/config/collections/wechat/
business/admin/config/components/
business/admin/i18n/
integration/admin/*wechat*.test.mjs
```

Use namespace `NsUltimate\Business\Wechat\`. Registration follows the existing module convention. Business overrides belong in the module's service configuration. Do not copy the Core WeChat module, introduce a generic plugin framework, or place domain resolution rules in the integration Kernel.

Suggested interfaces are `StoreWechatResolver`, `WechatClientFactory`, `WechatCredentialStore`, `WechatIdentityService`, and `InvoiceStoreContextAdapter`. Introduce interfaces only at tested substitution or ownership boundaries, not for every class.

## 5. Data model

All new WeChat module tables use the `wechat_` domain prefix, including any credential and audit tables introduced during implementation. Do not use `business_` or `business_wechat_`: repository-layer ownership does not determine database naming. Specific table suffixes below are proposed. Name explicit indices and constraints consistently with their tables, and validate uniqueness against existing Core and business tables before generating migrations. Existing Core `wechat_user` remains core-owned; sharing the domain prefix does not authorize remapping or altering that table.

| Entity / proposed table | Important fields and invariants |
| --- | --- |
| `WechatApplication` / `wechat_application` | UUID, name, application type, AppID, ownership/grants, enabled status, active revision; AppID identity cannot be silently changed in place |
| `WechatApplicationRevision` / `wechat_application_revision` | Profile UUID, revision number, AppID/type snapshot, Secret reference, optional Official Account token/AES key references, redirect policy, publication state and timestamps |
| `WechatMerchant` / `wechat_merchant` | UUID, name, merchant ID, mode, ownership/grants, enabled status, active revision; merchant identity is stable |
| `WechatMerchantRevision` / `wechat_merchant_revision` | Merchant snapshot, API v3 key reference, merchant private key and certificate/serial references, platform certificate/public-key verification configuration, publication state |
| `StoreWechatSetting` / `store_wechat_setting` | UUID, unique Store UUID, active binding revision, enabled state; references rather than copied plaintext credentials |
| `StoreWechatSettingRevision` / `store_wechat_setting_revision` | Mini Program and Official Account references, merchant reference, capability flags, compatible app/merchant pair, pinned published revisions, revision number |
| `WechatIdentity` / `wechat_identity` | UUID, User reference, application type, AppID, OpenID, optional UnionID and verified Open Platform scope; unique `(app_id, openid)` and many identities per User |
| `WechatPaymentAttempt` / `wechat_payment_attempt` | UUID, Invoice UUID, trusted Store UUID, application and merchant revision references, provider order number, trade type, expected external amount/currency, payer identity, idempotency key, status, transaction ID, timestamps |
| `WechatRefundAttempt` / `wechat_refund_attempt` | UUID, payment attempt reference, stable provider refund number, request idempotency key, amount, status, provider refund ID, accounting-applied timestamp |
| Credential/audit records | Versioned encrypted secret references and append-only redacted change/publication/security audit events; exact storage layout decided in Phase 0 |

Revision rules:

- Published revisions are immutable; edits create a new draft. Publication uses optimistic concurrency to reject lost updates.
- Store binding revisions pin compatible application and merchant revisions. Profile edits do not silently change every bound store. An explicit audited rebind or batch publication updates affected stores.
- Changes to AppID or merchant ID create a new logical profile and require explicit rebinding.
- Flexible metadata contains non-sensitive extension options only. Unknown capability keys are rejected, not silently enabled.
- Capability disablement immediately blocks new operations. Historical payment callbacks and refunds remain independently controlled; disabling new collection must not strand existing transactions.
- Hard revocation blocks use of compromised credentials, including historical operations. Reconciliation must then use an approved replacement credential for the same merchant identity, not switch merchants.
- Do not persist session keys in ordinary identity responses or unprotected metadata. Store them encrypted with an explicit lifetime only if a supported operation actually requires them.

## 6. Configuration management and secret security

### Publication workflow

1. Create or update a draft, with a version/concurrency precondition.
2. Validate schema, required fields, application type, merchant mode, key/certificate pairing, and reference grants.
3. Validate capability compatibility: JSAPI requires the correct application, a configured merchant, and their required WeChat-side binding. Local validation is not proof of WeChat platform authorization.
4. Run an explicit credential probe if requested. Probes are rate-limited and audited; they must not create a charge or refund.
5. Publish atomically, invalidate affected configuration caches, and show the active version and last validation result.

Secret handling:

- Encrypt sensitive fields with authenticated encryption; keep the master key outside the database and source control. Record encryption key IDs for rotation.
- APIs expose only safe fields and `configured` indicators. Credential replacement is write-only; omission preserves the current reference, and explicit clear is allowed only when the capability does not require it.
- Never return stored secret ciphertext through generic CRUD, metadata, expansion, exports, or exception serialization. Use purpose-built DTOs and endpoints where Core CRUD cannot enforce this boundary.
- Private keys/certificates are validated uploads or managed secret references. Reject arbitrary filesystem paths, executable payloads, oversized files, and arbitrary remote retrieval URLs.
- Runtime private files live in a controlled writable directory outside `core/` and public upload paths, with restrictive permissions and bounded cleanup. No generic upload downloader may expose them.
- Provider base URLs and callback origins are server-managed; OAuth redirects use HTTPS allowlists. User-controlled configuration cannot become an SSRF or open-redirect mechanism.
- Shared profiles require explicit Store grants. Store operators cannot enumerate other stores' profiles, retrieve shared secrets, or change shared profile credentials.
- Audit actor, Store, object/revision, operation, result, and request ID; redact secrets, authorization headers, OAuth codes, session keys, and unnecessary personal data.

## 7. Dynamic resolution, clients, and cache behavior

Resolve by explicit `(storeUuid, capability, applicationType)` plus trusted operation context. Return an immutable resolved configuration object containing revision references and validated non-secret identifiers.

For payments, derive Store ownership from persisted source data through an adapter. For Trade-backed invoices, investigate the existing Order/OrderStoreLifecycle relationship. Do not trust `options.storeUuid`, arbitrary Order metadata, request headers, or a client-supplied profile UUID as the source of truth. Unsupported invoice source types fail closed until they have a trusted resolver.

Client/cache requirements:

- Construct MiniApp, OfficialAccount, or Pay instances from the resolved revision and credential references.
- No mutable singleton `currentStore`, implicit RequestStack selection, or static cross-tenant client variable.
- HTTP, CLI, and Messenger workers use the same explicit context contract.
- Instance keys include application/merchant identity and relevant revision/credential IDs. Token caches use a stable application identity namespace plus credential generation where needed; deliberately shared applications may share a token, unrelated applications must not.
- Use a shared cache with bounded entries, stampede protection, and explicit invalidation. Workers must not require a restart to observe a publication.
- Proposed activation target: a newly started operation observes a published binding within five seconds across web and worker processes. Use versioned authoritative reads/invalidation to meet it; cache failure must not cause a global fallback.
- An operation already started keeps its selected revision. Emergency revocation is checked before external calls even for pinned operations.

## 8. Login and phone flows

Proposed customer routes use `/api/v1/app/stores/{storeUuid}/wechat/...`; exact suffixes are finalized with the route/security contract.

### Mini Program

1. Verify that the Store is available and the Mini Program login capability is enabled.
2. Resolve the published application; exchange the submitted one-time code with that application's credentials.
3. Find/create an identity by the resolved AppID and returned OpenID. Never accept OpenID/AppID supplied by the caller as verified identity.
4. Resolve the system User without assigning staff/store membership roles; issue existing platform JWTs.
5. Phone binding requires an authenticated User and the intended application context; bind only the phone returned by WeChat, with identity/ownership checks and rate limits.

### Official Account OAuth

- Generate a short-lived signed state containing a nonce, Store UUID, application revision, approved redirect, and expiry. Verify and consume it once on callback.
- Resolve callback application from validated state, not an arbitrary request Store/AppID. Reject Store/app substitutions, replay, expired state, and unapproved redirects.
- Retain the state's application revision for its short lifetime unless revoked.
- Do not automatically merge accounts on equal OpenID across AppIDs, an unscoped UnionID, or an unverified phone claim. Explicit linking requires proof of control and collision handling.

## 9. Payments, callbacks, and refunds

### 9.1 Payment initiation and recovery

- Implement a project-owned gateway using `PaymentGatewayInterface`. Keep exactly one registration for payment name `wechat`, with deterministic dispatch between explicitly identified legacy transactions and Store attempts.
- Resolve the Invoice's trusted Store, enabled payment capability, compatible app/merchant revisions, and the payer identity for that exact AppID.
- Persist the immutable attempt and stable provider `out_trade_no` before making the external call. Record the actual gateway amount after adjustments, not blindly the Invoice total.
- A repeated idempotency key with identical inputs reuses the attempt; conflicting inputs fail. Permit only one unresolved external collection attempt per Invoice until it is reconciled/closed.
- The Core payment call currently occurs inside a transaction. Phase 0 must prove a durable attempt/outbox or staged orchestration approach that survives a process crash after provider success but before local commit. An attempt inside a rolled-back transaction is not sufficient.
- Treat network timeouts as unknown results, not confirmed failures. Query the provider using the same order number before retrying or selecting a new merchant.
- Either retain Invoice `outTradeNo` as the provider number with safe retry constraints, or explicitly map an attempt-specific provider number back to the Invoice before calling Core. Document and test the chosen mapping.
- Persist only redacted reconciliation data, not credentials, in Invoice extra data or API payloads.

### 9.2 Payment and refund notifications

Proposed callback routes include an opaque published configuration/revision locator under a project-owned webhook prefix. The locator is a routing hint, not an authentication credential.

- Select the bounded candidate configuration from the locator; validate signature, decrypt using the proper API v3 key, and resolve the persisted provider order/refund number.
- Verify that the callback locator, attempt, merchant ID, application ID where applicable, transaction ID, event type, amount, and currency agree before changing state.
- Do not trust unverified payloads or certificate serial headers to select an arbitrary merchant, and do not brute-force every stored secret.
- Validate duplicate callbacks too; concurrent deliveries apply financial effects once using database constraints/locking and idempotent processing.
- Acknowledge only after a durable inbox/transaction commit. Unknown orders and verification failures never receive a success acknowledgment that discards a legitimate unprocessed event.
- Delayed callbacks after publication use the historical attempt configuration. Bounded retained verification-key/API-key versions and certificate refresh must support legitimate rotation; define and test the policy before release.
- Reconciliation queries recover missed callbacks and ambiguous provider results through the same validation and accounting path.

### 9.3 Refund lifecycle: mandatory release gate

Create a persisted refund request with a stable `out_refund_no`, then handle `requested -> processing -> succeeded | failed | unknown` with query/reconciliation support. Reserve pending refund amounts to prevent concurrent over-refunds.

Only verified `succeeded` results update refunded accounting or dispatch completed-refund events. Repeated callbacks/queries must not apply the refund twice. Pending/unknown refunds must not mark the Invoice refunded or restore adjustments as if external funds had already returned.

The current Core `InvoiceService::refund()` does not satisfy this invariant. Phase 0 must choose and validate one of:

1. A minimal generic Core extension separating external refund initiation from successful accounting completion, with isolated tests and subtree-policy review.
2. A project-owned refund orchestrator plus an explicit safe accounting adapter that preserves existing wallet/adjustment and Invoice state behavior without calling Core's eager-completion path for pending refunds.

Do not implement by returning `processing` from the gateway and allowing the current Core refund method to run unchanged. Real refund enablement remains blocked until this boundary is tested, including adjusted invoices. If adjusted refunds are deferred, reject them before any provider call and document the restriction explicitly.

## 10. API and Admin experience

Proposed management resources:

- `/api/v1/manage/wechat-applications` and `/wechat-merchants`: platform profile management, grants, draft/revision views, validate/publish/disable actions.
- `/api/v1/manage/store-wechat-settings`: admin binding management.
- `/api/v1/store/{scopeId}/wechat-setting`: authorized Store management with Store identity assigned by the backend.
- Customer login/phone/OAuth routes described in Section 8; project-owned payment/refund webhook routes in Section 9.

Implementation must explicitly configure anonymous login/OAuth/webhook access in the composed security rules; route creation alone is not sufficient. Management actions require Store membership plus capability-level authorization, not just a visible frontend menu. Restrict sensitive profile publication/grants to platform administrators by default.

Admin UI requirements:

- A dedicated WeChat configuration entry with Application, Merchant, and Store binding views; no unconditional change to unrelated menus.
- Grouped sections for Mini Program login, Official Account OAuth, payment, and advanced non-sensitive options.
- Profile selectors show only permitted records; shared profiles are visibly identified.
- Secret fields are write-only and show configured status. Draft edits, publication, validation errors, stale-version conflicts, and disabled capabilities are visible.
- Show the active binding and profile versions, affected stores before shared-profile rollout, and last redacted validation/audit result.
- Disable unsupported capabilities instead of presenting placeholder switches as functioning features.
- Provide en/zh/ja/zh-Hant locale resources; source, canonical documentation, and identifiers remain English.

## 11. Migration and rollout

1. Add project-owned tables and indices through forward migrations. Test fresh installs and upgrades on production-compatible MySQL/MariaDB, not just SchemaTool-created SQLite schemas.
2. Keep Core `wechat_user` and historical migration identities intact. Import legacy identities only with an explicit verified AppID/type mapping. Report conflicts; never guess an AppID or merge Users.
3. Provide an idempotent dry-run/import command with a redacted report. Existing User UUIDs, JWT behavior, and ownership links remain stable.
4. Legacy global endpoints and transactions use an explicitly frozen legacy profile/dispatcher path. Missing Store configuration must not implicitly route a new Store payment to the legacy merchant.
5. Inventory historical invoices. Backfill legacy transaction routing only where the merchant/application provenance is verified; ambiguous rows require operator resolution before refunds.
6. Roll out configuration and login first, then a small approved Store payment pilot. Keep creation flags separate from historical callback/reconciliation processing.
7. Drain or version workers during initial code deployment. Later configuration-only publication must not require restart, Composer installation, or cache-clear deployment.
8. Rollback disables new dynamic operations or republishes a previous safe binding; it does not drop tables, delete identities/attempts, or stop historical callbacks/reconciliation. Retain a compatible code version and a documented recovery path.

No deployment, credential import, live charge, or refund is authorized by writing this plan. Production operations require explicit approval and a named operator.

## 12. Implementation work packages

All items are pending. Each package ends with recorded evidence, not only a checked task list.

### Phase 0: boundary validation and product decisions

- [ ] Confirm Section 3 decisions and create an approved scope record.
- [ ] Prove module route/security loading and deterministic single `wechat` gateway registration in the compiled production container.
- [ ] Prove trusted Store derivation for supported Invoice sources; explicitly list unsupported sources.
- [ ] Design and test durable payment attempts across Core transaction rollback/crash windows.
- [ ] Decide the asynchronous refund completion integration; document any narrowly scoped Core change before implementation.
- [ ] Confirm merchant/app binding requirements, credential storage/key custody, and historical rotation policy against current official WeChat documentation.

Exit: no unresolved architectural blocker for supported login/payment/refund flows. Any deferred capability is disabled and has a documented reason.

### Phase 1: configuration foundation

- [x] Implement entities, constraints, forward migrations, DTOs, and encrypted credential storage for applications, merchants, and store bindings.
- [~] Implement draft validation, publication, disable semantics, resolver, and client factory. Publication is app-level (publishing supersedes the previous published revision); optimistic-concurrency protection, explicit revoke, redacted probes, and cache-invalidation tuning remain.
- [ ] Implement per-profile Store grants and append-only audit records.
- [ ] Add management APIs, authorization resources/seeding, and localized Admin views.
- [x] Register the Integration suite in project PHPUnit configuration and prove the module is discovered.
- [ ] Verify frontend checks discover new business tests (no frontend code exists yet).

Implemented evidence: `business/backend/Wechat/` (entities, `SecretCipher` AES-256-GCM, `WechatApplicationService`, `WechatMerchantService`, `StoreWechatBindingService`, `StoreWechatResolver`, `WechatClientFactory`), migration `Version20261011040000`, and `business/backend/Wechat/tests/Integration/WechatConfigurationTest.php`. The full backend suite passes (27 tests / 146 assertions), including encrypted-at-rest assertions, per-Store isolation, fail-closed behavior, and migration SQL on SQLite.

Exit: acceptance cases CFG, SEC, and DYN pass without real payment enablement. Not yet reached: only a subset of CFG/SEC/DYN is covered by automated tests so far.

### Phase 2: identities and login

- [ ] Implement application-scoped identity mapping, Mini Program login/phone, and state-protected Official Account OAuth.
- [ ] Implement explicit account-linking conflict handling without automatic merging.
- [ ] Add legacy identity import dry-run, conflict reporting, and compatibility regression tests.

Exit: acceptance cases AUTH and relevant MIG cases pass; approved real-app login smoke tests recorded.

### Phase 3: direct payments and refunds

- [ ] Implement persisted attempts, idempotent initiation, trusted Invoice adapter, and selected gateway composition.
- [ ] Implement JSAPI/Native, versioned callbacks, inbox/idempotency, provider queries, and payment reconciliation.
- [ ] Implement refund requests/reservations, notifications/queries, confirmed-success accounting, and failure recovery.
- [ ] Exercise revision changes, credential rotation, duplicate/concurrent events, and crashes around external calls.

Exit: all PAY/REF cases pass; Core accounting compatibility proven; no unsupported refund path can reach the provider.

### Phase 4: release validation and operations

- [ ] Run migration rehearsal and production-container/route tests; review final diff for accidental Core changes and secrets.
- [ ] Document deployment, master-key backup/recovery, rotation, revocation, reconciliation, and rollback procedures.
- [ ] Complete explicitly approved live smoke tests with controlled amounts and recorded provider outcomes.
- [ ] Publish validated API/adapter contracts, test evidence, known limitations, and an operator sign-off.

Exit: all mandatory acceptance cases pass or the associated capability is excluded from the release and technically disabled. Security/isolation/accounting requirements cannot be waived for an enabled capability.

## 13. Acceptance matrix

Use at least Store A/B, App A/B, Merchant A/B, a deliberately shared App S, two ordinary users, and a Store A operator. Credential fixtures are synthetic; live credentials never enter the repository or test output.

| ID | Scenario | Required observable result / evidence |
| --- | --- | --- |
| CFG-01 | Independent Store profiles | A resolves App A/Merchant A; B resolves App B/Merchant B; persisted references and outbound mocked requests match |
| CFG-02 | Shared application, distinct merchants | A/B use App S for login but their own merchants for collection; changing a Store binding leaves the other Store unchanged |
| CFG-03 | Draft/publication | Draft changes do not affect runtime; valid publish atomically activates one complete version; invalid publish leaves the active version unchanged |
| CFG-04 | Concurrent edits | Stale version update/publish returns a conflict; no lost update or mixed credential revision |
| CFG-05 | Disable, missing configuration, revoke | New operation fails closed; no global merchant fallback; historical processing follows the documented disable/revoke distinction |
| CFG-06 | Compatibility validation | Missing secrets, malformed keys, unsupported modes, and incompatible app/payment capabilities cannot publish; local checks are distinguished from provider checks |
| SEC-01 | Store authorization | A operator cannot read/update B settings or bind ungranted profiles; anonymous/non-staff users cannot access management routes |
| SEC-02 | Secret non-disclosure | List/detail/metadata/expansion/export/errors/audit/frontend state contain neither plaintext nor stored secret ciphertext; automated negative assertions cover each enabled surface |
| SEC-03 | Credential update semantics | Omitted secret is preserved, replacement creates a credential version, required secret cannot be cleared while enabled; access remains audited |
| SEC-04 | Storage and injection | Database ciphertext is authenticated; altered ciphertext fails; private files are non-public; path traversal, arbitrary paths/URLs, oversize uploads, and invalid key pairs fail |
| SEC-05 | Key recovery/rotation | A controlled encryption-key rotation preserves decryption for required history; missing keys fail closed; documented backup restore is rehearsed |
| DYN-01 | Long-running isolation | Repeated interleaved A/B operations in one worker/process always use the correct app/merchant; no mutable singleton state leaks |
| DYN-02 | Publication propagation | Newly started operations in separate web and worker processes observe publication within five seconds without restart; in-flight operations retain their revision |
| DYN-03 | Token cache isolation | App A/B token entries do not collide; intentional App S sharing follows the documented namespace; bounded-cache/stampede behavior is exercised |
| DYN-04 | Cache outage/revocation | Cache failure never selects another profile; revoked credentials cannot make external calls even through an old cached object |
| AUTH-01 | Mini Program login | Exchange request uses selected AppID/Secret; repeated identity login resolves the same User; wrong-app code does not create/link an identity |
| AUTH-02 | Multiple application identities | One User can hold App A/B identities through verified linking; identical fixture OpenID strings under different AppIDs never collide or merge automatically |
| AUTH-03 | Phone binding | Authenticated intended User receives only the provider-verified phone; wrong context, unauthenticated requests, and rate-limit abuse are rejected |
| AUTH-04 | OAuth state and redirects | Store/app substitution, invalid signature, replay, expiry, and non-allowlisted redirect fail; valid state completes once |
| AUTH-05 | Identity/account policy | Login does not grant staff roles; unscoped UnionID or unverified phone cannot merge accounts; collisions produce a safe actionable error |
| PAY-01 | Trusted Store provenance | Forged request Store/profile/AppID/OpenID and Order metadata cannot change merchant selection; unsupported sources are rejected before provider calls |
| PAY-02 | JSAPI and Native | JSAPI uses payer identity for the exact AppID and returns valid signed client parameters; Native returns the selected merchant's provider QR URL |
| PAY-03 | Initiation idempotency | Same key/input gives one provider order; conflicting input fails; concurrent requests cannot create two unresolved collections |
| PAY-04 | Timeout/crash recovery | Provider success plus process crash/local rollback is recovered by persisted attempt/reconciliation; retry never creates an uncontrolled second charge |
| PAY-05 | Changed Store configuration | Payment under A1/M1 still verifies/queries/refunds against its original merchant after Store binding becomes A2/M2 |
| PAY-06 | Notification verification | Invalid signature/key/locator, mismatched merchant/app/order/transaction/amount/currency, and unknown orders produce no financial mutation or false success acknowledgment |
| PAY-07 | Duplicate and concurrent notifications | Valid repetitions apply accounting/events once; invalid duplicates are rejected even when the Invoice is already paid; database-level concurrency test included |
| PAY-08 | Missed callback/reconciliation | Query confirms an ambiguous payment through the same validation path; no unverified payload marks an Invoice paid |
| PAY-09 | Rotation | Legitimate delayed callbacks across supported API-key/certificate rotation verify under bounded retained versions; forged serials cannot route to arbitrary merchants |
| PAY-10 | Existing accounting compatibility | Wallet/adjustment behavior, expected external amount, cancellation, and Invoice transition rules remain valid under the new adapter |
| REF-01 | Asynchronous completion | `PROCESSING`/unknown response creates pending state/reservation but does not increment completed refunded amount or emit a completed refund event |
| REF-02 | Success idempotency | Verified refund success applies exactly once under duplicate/concurrent callback/query results; full/partial status and events agree |
| REF-03 | Failure/timeout | Stable refund number reused for retries; unknown result reconciled before retry; definitive failure releases reservation without claiming completion |
| REF-04 | Over-refund protection | Concurrent requests cannot exceed paid remainder including pending reservations; incompatible adjusted refund is rejected before provider access if unsupported |
| REF-05 | Historical merchant and binding | Refund selects the original payment merchant despite current Store changes; compromised credentials require authorized same-merchant replacement, not merchant substitution |
| MIG-01 | Database upgrade and naming | Fresh install and existing-database migration succeed on supported MySQL/MariaDB and isolated SQLite; actual migrations, constraints, and indices are tested; all new module tables use `wechat_` rather than `business_`, with consistent explicit index/constraint names and no collisions or remapping of Core tables |
| MIG-02 | Identity import | Dry-run changes no data; repeat import is idempotent; original User UUIDs remain; unknown AppIDs/conflicts are reported without guessed merges |
| MIG-03 | Legacy gateway/routes | Frozen legacy transactions/endpoints work as documented; one compiled `wechat` gateway registration; missing Store config cannot create a legacy-routed new payment |
| MIG-04 | Rollback | Disable/rebind and code rollback preserve histories and callbacks/reconciliation; no table drops or lost credential references |
| UI-01 | Operator workflow | Authorized operator creates/selects profiles, edits draft, validates, publishes, and sees active version/conflicts/disabled states without exposing secrets |
| UI-02 | Localization and scope | Supported locales show field/help/error labels; frontend configuration composition/build pass; unrelated menus remain unchanged |
| OPS-01 | Test discovery | CI demonstrably runs new WeChat Unit/Integration/Admin tests rather than only compiling/discovering the module |
| OPS-02 | Controlled live smoke | Approved apps validate login/phone/OAuth; approved merchants validate collection, notification/query, and completed refund with provider receipts and redacted evidence |
| OPS-03 | Operational readiness | Named operator can inspect redacted correlation IDs, reconcile unresolved attempts, rotate/revoke credentials, and recover from backup using documented steps |

## 14. Validation execution and evidence

### Automated validation

Required checks during implementation:

- PHP syntax checks for changed files and relevant Composer/static-analysis checks declared by the affected Core toolchain.
- `php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml`, after explicitly registering new suites.
- `node --test integration/admin/*.test.mjs`, plus new deterministic frontend component/service tests as needed.
- Core admin declared type-check/config-validation scripts and a composed frontend build using `integration/admin/vite.config.ts`, following the current CI command.
- Production container compilation and route/security inspection through `integration/backend/bin/console` with sanitized test configuration.
- Actual migration up/upgrade tests against an isolated MySQL/MariaDB service; SQLite for fast integration tests; never run acceptance migrations against the developer's shared database.
- Database concurrency tests for attempt uniqueness, notification processing, refund reservations, and completion idempotency.
- Deterministic fake-provider scenarios for signatures/decryption, timeouts, replay, crashes, invalid data, rotation, and reconciliation. Mocked tests do not count as live provider acceptance.

Documentation-only preparation does not require these application suites. Do not record them as passed until executed.

### External validation

Before implementing provider-sensitive flows, verify current official WeChat API contracts for login codes, OAuth state/redirect policy, app/merchant associations, API v3 signatures/decryption, payment queries, refund statuses/notifications, and credential rotation. Record source URLs and dates in the implementation evidence. EasyWeChat reference documentation is supplemental, not authoritative for financial protocol requirements.

SDK references consulted for the architectural direction:

- [EasyWeChat 6 cache isolation](https://github.com/w7corp/easywechat/blob/6.x/docs/src/6.x/cache.md)
- [EasyWeChat 6 HTTP client](https://github.com/w7corp/easywechat/blob/6.x/docs/src/6.x/client.md)

Live validation requires explicit permission, approved test accounts/merchants, an agreed charge ceiling, and a refund/reconciliation owner. Never launch live financial tests automatically in CI.

### Evidence and completion

For every acceptance ID, record test path/command or manual scenario, environment, observed outcome, and redacted evidence. Include the tested code revision and any capability exclusions. A release report must distinguish automated validation, controlled live verification, and unverified behavior.

Release is blocked by cross-store credential/identity leakage, secret disclosure, ambiguous merchant selection, non-durable payment attempts, premature refund accounting, or an untested legacy cutover. Configuration/login-only delivery may proceed if payment creation is technically disabled and its limitations are explicit.

After implementation, add validated contracts and operational runbooks, link them here, and update this document's status. Do not mark the plan implemented merely because configuration entities or Admin forms exist.
