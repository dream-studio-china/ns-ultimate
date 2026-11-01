# HuifuFin Store Payment Integration Plan

**Status: Implemented for encrypted Store configuration, online payment, signed
notifications, refunds, reconciliation, and POS receipt intake. Live Huifu
sandbox/production behavior and the remaining account/treasury phase are not
verified.**

This document is the implementation plan and design record for
`business/backend/HuifuFin`. The module README describes the current operating
behavior and limitations; this page preserves the original design decisions and
phase boundaries.

Current implementation:

- `HuifuProviderProfile`, `HuifuMerchant`, `HuifuMerchantStoreGrant`, and
  `StoreHuifuSetting` provide encrypted, shareable per-Store configuration.
- The `huifu` payment gateway supports JSAPI and Native order creation through a
  Store-scoped payment endpoint.
- Signed attempt-specific notifications, payment/refund reconciliation, and a
  confirmed-refund workflow are implemented.
- POS devices and receipts are implemented without automatic wallet deposits.
- Account, transfer, withdrawal, and quick-pay refund behavior remain pending.

The implemented notification and refund-query contracts still require
verification against the contracted Huifu product. The tests use a fake provider
client; they do not prove live API compatibility.

This plan adds a `HuifuFin` business module and a Store-scoped Huifu payment
configuration. It is based on the legacy `night-keeper` Huifu bundle and payment
strategies, but deliberately replaces several legacy assumptions that do not fit
the current payment contract.

The target is a store-aware implementation that supports:

- multiple Stores sharing one Huifu configuration;
- different Stores using different Huifu configurations;
- online Huifu payments through the Core invoice workflow;
- signed notifications, queries, refunds, and POS receipts;
- later expansion to Huifu account, transfer, and withdrawal operations.

No `core/` change is planned. If implementation discovers a required Core
change, stop and request explicit authorization under the repository's
core-edit gate instead of bypassing it.

## 1. What the legacy implementation provides

The reference implementation is spread across the old `HuifuBundle`, the online
payment strategy, and the POS payment strategy. It contains four different
concerns that must not be copied as one unit.

| Legacy concern | Relevant behavior | Decision for this project |
| --- | --- | --- |
| Online payment | JSAPI, quick pay, scan-pay query, refund | Reimplement through the current `PaymentGatewayInterface` |
| Payment notification | Form-encoded callback, RSA verification, invoice status update | Reimplement with explicit attempt lookup and idempotency |
| POS notification | Device lookup, automatic wallet deposit from a POS transaction | Preserve the receipt, do not copy automatic recharge by default |
| Account operations | Merchant onboarding, profile sync, balance, transfer, withdrawal | Separate second phase inside `HuifuFin` |

The legacy code uses one global `huifu.payment` parameter for every Store and
assumes `sys_id` can also act as the merchant `huifu_id`. The new design must
keep the API access identity and the actual merchant identity separate.

Other legacy behavior that must not be carried forward:

- public endpoints that sign arbitrary caller-supplied payloads;
- network requests from Doctrine entity lifecycle listeners with errors ignored;
- treating a refund accepted for processing as a completed refund;
- deriving transaction dates from invoice creation time;
- automatic POS deposit creation with no authoritative order association;
- raw callback, credential, payment-code, or OpenID logging.

## 2. Module shape

The requested `HuifuFin` bundle is implemented as a conventional business module,
not a Symfony bundle class. The backend discovers
`business/backend/HuifuFin/src/` by directory convention and derives the PSR-4
namespace `NsUltimate\Business\HuifuFin`. See the
[backend module registration contract](../contracts/backend-module-registration.md).

```text
business/backend/HuifuFin/
├── README.md
├── config/
│   ├── routes.yaml
│   └── services.yaml
├── migrations/
├── src/
│   ├── Client/
│   │   ├── HuifuClient.php
│   │   ├── HuifuSigner.php
│   │   └── HuifuResponse.php
│   ├── Command/
│   │   ├── ReconcileHuifuPaymentsCommand.php
│   │   └── SeedHuifuAuthorizationCommand.php
│   ├── Controller/
│   │   ├── Admin/
│   │   ├── Store/
│   │   └── WebHook/
│   ├── Entity/
│   ├── Service/
│   │   ├── Configuration/
│   │   ├── Payment/
│   │   ├── Pos/
│   │   └── Refund/
│   └── Repository/
└── tests/
    ├── Integration/
    └── Unit/
```

The online gateway is `HuifuPaymentGateway`, registered with the
`payment.gateway` tag. Its payment code is `huifu`; the channel or trade type is
recorded separately on the invoice.

POS is device-initiated and does not implement `PaymentGatewayInterface::pay()`.
It is owned by `HuifuPosService` and its notification controller. Do not register
a fake `huifu-pos` gateway whose `pay()` always throws; that would misrepresent
the capability to the Core registry.

## 3. Configuration model

Use three layers instead of one global configuration or one credential blob per
Store.

### 3.1 `HuifuProviderProfile`

The API access profile. One profile represents one Huifu product/signing
identity.

Suggested fields:

- `uuid`, `name`, `environment`, `enabled`;
- `sysId`, `productId`;
- encrypted RSA merchant private key;
- encrypted synchronous response verification public key;
- encrypted asynchronous notification verification key or public key;
- credential version and rotation timestamps;
- created and updated timestamps.

`environment` maps to a fixed server-side API base URL. Clients cannot supply an
arbitrary endpoint.

Provider profiles are global administration resources. Changing a profile does
not mutate historical payment attempts or refund records, which retain the
credential version they used.

### 3.2 `HuifuMerchant`

The actual merchant receiving funds.

Suggested fields:

- `uuid`, `name`, `huifuId`;
- `providerProfile` reference;
- enabled capabilities;
- enabled flag;
- created and updated timestamps.

More than one merchant can share a provider profile.

### 3.3 `StoreHuifuSetting`

The Store binding.

Suggested fields:

- `uuid`;
- `storeUuid`, unique;
- `merchant` reference;
- `enabled`;
- allowed online payment methods;
- default online payment method;
- POS enabled flag;
- created and updated timestamps.

A Store can share another Store's merchant by binding the same merchant record.
It can also use a merchant backed by a different provider profile. Disabling a
Store setting blocks new payments; it does not delete history or block
notifications, reconciliation, or an approved refund for an earlier payment.

### 3.4 Optional Store grant

If Store members may change their own binding, add
`HuifuMerchantStoreGrant` with a unique `(merchant, storeUuid)` pair and
administrator-managed grants. Store option endpoints return only granted
merchants. If only platform administrators manage bindings in the first release,
this table can be deferred, but the API must still prevent a Store from selecting
an arbitrary merchant UUID.

## 4. Credential storage and configuration safety

- Encrypt private keys and callback secrets before persistence.
- Use a dedicated `HUIFU_CONFIG_KEY` and a dedicated `HuifuSecretCipher`; do not
  reuse the WeChat cipher or key.
- Store a key version so credentials can be rotated without making historical
  transactions unreadable.
- Return only a configured/not-configured status and masked identifiers through
  the API. Never return a private key or callback secret.
- Keep verification public keys separate from signing private keys.
- Do not put Huifu RSA secrets into the existing generic
  `business/admin/config/overrides/payment/PaymentChannels.json` schema. That
  schema is frontend form configuration, not a secret store.
- Do not migrate legacy `appId`/`apiKey` values by guesswork. Legacy data has a
  different shape and must be entered or imported explicitly.

The deployment must provide PHP OpenSSL and the configured key before any Huifu
profile can be created or used. The Core Composer manifest does not currently
require the Huifu SDK. Prefer a narrow module-owned client built on the existing
Symfony HTTP client and `openssl_sign`/`openssl_verify`; adding a Composer
dependency would affect the Core-owned manifest and requires a separate
decision.

The exact signing serialization, HTTP field encoding, and callback verification
rule must be fixed against the product documentation and captured in fixtures.
The legacy code uses RSA verification, while the current official SDK
documentation also describes a webhook-key variant. These are not
interchangeable. The selected product and notification contract must be recorded
before implementation.

## 5. Store resolution

Online payments must derive Store context from server-owned data, never from a
request field.

Recommended order:

1. For a store-scoped Trade order, read the immutable Store association from the
   order lifecycle, following the pattern used by the Store WeChat adapter.
2. For other invoice sources, use an explicit server-side resolver registered for
   that source type.
3. If no trusted Store context exists, reject the payment with a clear error.

The invoice does not need to gain a Store column. `HuifuPaymentAttempt` records
the resolved `storeUuid` and the selected merchant/profile snapshot.

The resolver is:

```text
trusted invoice source
    -> storeUuid
    -> StoreHuifuSetting
    -> HuifuMerchant
    -> HuifuProviderProfile + credential version
```

## 6. Persistent records

### 6.1 `HuifuPaymentAttempt`

Suggested fields:

- `uuid`, `invoice`, `storeUuid`;
- provider profile UUID, merchant UUID, `huifuId`, credential version;
- `reqDate`, `reqSeqId`, trade type/channel, amount in cents;
- local status: `created`, `submitted`, `pending`, `succeeded`, `failed`,
  `unknown`, `closed`;
- provider response code and message;
- Huifu sequence ID, channel transaction ID, provider transaction status;
- sanitized request/response snapshots;
- last queried time, query count, next query time;
- created and updated timestamps.

Constraints:

- unique `reqSeqId`;
- index on `(invoice, created_at)`;
- index on `(storeUuid, status)`;
- index on `(status, next_query_time)` for reconciliation.

The amount is the actual gateway amount after Core adjustments, in integer
cents. Convert to a fixed two-decimal yuan string only at the provider boundary.
Never use float arithmetic for money.

### 6.2 `HuifuRefund`

Suggested fields:

- `uuid`, invoice, original payment attempt;
- unique local refund number;
- provider refund request number and date;
- requested amount and confirmed amount;
- status: `created`, `submitted`, `processing`, `succeeded`, `failed`,
  `unknown`, `cancelled`;
- provider refund ID, response code/message, sanitized snapshots;
- confirmation time, query count, next query time;
- a flag indicating whether Core refund accounting has been applied.

### 6.3 `HuifuWebhookEvent`

Record the verified event key, type, related attempt/receipt, body hash, processing
result, error category, and timestamps. The unique event key prevents duplicate
processing. Raw payload retention must be minimized and redacted.

### 6.4 POS records

`HuifuPosDevice`:

- `uuid`, terminal ID, merchant reference, `storeUuid`;
- enabled flag and handling mode;
- unique `(merchant, terminalId)`.

`HuifuPosReceipt`:

- provider transaction and sequence IDs;
- merchant, terminal, and Store snapshot;
- amount, currency, paid time;
- optional order and invoice references;
- status: `received`, `linked`, `unclaimed`, `rejected`, `refunded`;
- processing error and timestamps;
- unique provider transaction identity.

## 7. Online payment flow

### 7.1 Initiation

The recommended flow is:

```text
business payment endpoint
    -> resolve Store and configuration
    -> create and commit payment attempt
    -> call Core InvoiceService::pay(..., ['huifuAttemptUuid' => ...])
    -> gateway sends the signed provider request
    -> update attempt and return payment payload
```

The attempt must exist before the Core pay transaction so a provider timeout is
recoverable. The gateway requires the attempt identifier and must refuse a
generic call that has no pre-created attempt. Existing generic invoice pay routes
remain usable for other gateways.

If the current Trade order payment path cannot be routed through this boundary
without a Core change, the first release must either:

- provide a business-owned order payment adapter that creates the attempt first;
- or reject Huifu through the unsupported generic path with an explicit message.

Do not silently submit a provider transaction whose local attempt can be rolled
back.

### 7.2 Request construction

The gateway must:

- use the resolved merchant's `huifuId`;
- use the Store's selected payment capability;
- use the gateway amount supplied by Core, not the invoice amount;
- generate a new request sequence and date for each attempt;
- use a server-generated notification URL that identifies the attempt;
- resolve the payer's OpenID from the exact WeChat application belonging to the
  Store, when the channel requires it;
- reject unsupported trade types and capabilities before contacting Huifu;
- store enough detail to reconcile the request if the HTTP call times out.

Do not accept private keys, merchant IDs, notification URLs, or API base URLs
from the payment request body.

### 7.3 Initial channels

Implement only channels that are enabled for the contracted merchant. The
initial candidate set is:

| Channel | Notes |
| --- | --- |
| WeChat JSAPI | First priority; requires the correct Store application identity and OpenID |
| WeChat Mini Program | Add only after confirming merchant capability and payload format |
| Native QR | Add only after confirming the product exposes it |
| Bank quick pay | Separate adapter and separate refund/query contract; do not reuse scan-pay assumptions |
| Merchant-scanned payment code | Later; never log the payment code |

The legacy code advertises quick pay and scan-pay query while using a shared
refund endpoint. The new implementation must use the query and refund endpoints
that match the original transaction type.

### 7.4 Amounts and status mapping

- Core amounts are integer cents.
- Provider amounts are fixed-point yuan strings.
- A provider success code for order creation does not mean the payment is paid.
  The invoice remains `paying` until a verified notification or query confirms
  success.
- Provider response codes are normalized by operation. Do not use one success
  code globally: create, query, notify, and refund have different response sets.
- Unknown or ambiguous responses become `unknown` and are queried; they do not
  become failures that invite a duplicate payment.

## 8. Notification processing

Use a dedicated route such as
`POST /api/payment/notify/huifu/{attemptUuid}`. The attempt lookup determines the
provider profile and credential version. The request path must not select a
configurable remote URL.

Processing order:

1. Parse the provider encoding without trusting it.
2. Load the attempt and its provider profile.
3. Verify the signature using the exact protocol for that attempt.
4. Validate merchant, request sequence, amount, currency, and Store association.
5. Acquire a lock for the attempt and the invoice.
6. Record the webhook event with a unique key.
7. Apply a successful payment through `InvoiceServiceInterface::handleNotifyResult`
   and `markPaid` semantics.
8. Commit the local transaction.
9. Return the provider's required acknowledgement.

Rules:

- A duplicate notification is acknowledged without emitting a second payment
  event.
- A notification for a cancelled invoice records the real receipt and raises an
  exception case. It must not silently restore the order. Core currently rejects
  a cancelled-to-paid transition.
- A notification with a mismatched amount, currency, merchant, or Store is
  rejected and recorded as a verification failure.
- Do not use `preUpdate` entity listeners to make provider calls.

## 9. Query, close, and reconciliation

Add provider query operations for both payments and refunds.

- A timeout, transport error, or ambiguous provider response schedules a query.
- The reconciliation command processes due attempts and refunds.
- Query results are applied through the same idempotent service used by
  notifications.
- Closing an unpaid order is an explicit operation with a recorded result.
- A late successful payment after cancellation enters the exception path rather
  than mutating the invoice state illegally.
- Repeated failures age into an operator-visible alert state; they are not
  silently retried forever.

The command should be safe to run concurrently and should use bounded batches,
row locking where supported, and a stable next-query time.

## 10. Refund flow

The Core contract currently treats the return of `PaymentRefundResult` as a
completed refund and increments `refundedAmount`. It does not model a provider
refund that is still processing. Therefore:

- A Huifu "accepted for processing" response must not be returned as a successful
  Core refund.
- A local `HuifuRefund` record is created and committed before submission.
- The provider refund is submitted with an idempotent local refund number.
- Only a confirmed success triggers Core refund accounting.
- A timeout or processing response remains pending and is queried.
- A retry of the same local refund never submits a second provider refund.
- Concurrent refunds reserve the remaining refundable amount so two requests
  cannot exceed the paid amount.

Recommended states:

```text
created -> submitted -> processing -> succeeded
                    \-> unknown -> succeeded | failed
                    \-> failed
```

Because the generic Core refund route calls the gateway inside the Core
transaction, the Huifu path must use a business-owned refund workflow:

1. Create the refund record and reserve the amount.
2. Submit to Huifu in a controlled operation.
3. Return `202 Accepted` while processing.
4. On confirmed success, call Core `InvoiceServiceInterface::refund` with the
   existing confirmed refund identifier.
5. The gateway recognizes the confirmed local refund and returns its stored
   result without contacting Huifu again.

All refund entry points must be audited. The generic Core refund route must not
be allowed to create an untracked Huifu refund. If a service decorator can
intercept every entry point, use one and test the existing invoice refund guard
chain. If the decoration chain is not safe, keep a module-owned refund endpoint
and make the generic path reject Huifu invoices with a clear message. Do not
modify Core to solve this without the required authorization.

The refund must use the original attempt's merchant, provider profile, credential
version, request date, and request sequence, even if the Store later changes its
binding.

## 11. POS integration

The legacy POS strategy receives a signed notification, finds a device, and
creates a wallet deposit for the device's target user. That is a business policy,
not a generic payment gateway requirement.

New default behavior:

- Verify the callback with the POS-specific asynchronous verification key.
- Resolve the device only from the merchant and terminal identity.
- Snapshot merchant, terminal, and Store on the receipt.
- Deduplicate by provider transaction identity.
- If a trusted order association exists, validate amount and apply the payment.
- If no order association exists, retain an `unclaimed` receipt for operator
  review.
- Never create a wallet deposit or choose a target user without an explicit,
  separately approved policy.

If the old automatic recharge behavior is still required, implement it as an
explicit `deposit_topup` mode with:

- an authorized target-user policy;
- a wallet account and deposit type;
- amount limits;
- refund and reversal rules;
- a test for duplicate callbacks and rebinding.

Device rebinding needs special handling: do not let a delayed notification for a
previous binding silently attribute funds to the new Store. Either block
rebinding while pending receipts exist or require a documented reconciliation
decision.

## 12. Account, transfer, and withdrawal operations

These belong in `HuifuFin` but are a separate phase from online payment.

| Capability | New direction |
| --- | --- |
| Individual/enterprise account registration | Account service with explicit approval and state tracking |
| Profile and settlement sync | Read-only synchronized snapshots with timestamps |
| Balance query | Operator-facing query with audit logging |
| Account-to-account transfer | Dedicated transfer record and state machine |
| Withdrawal | Dedicated withdrawal record, asynchronous confirmation, limits |
| Automatic withdrawal | Explicit scheduler, approval and amount policy |
| Failed withdrawal reversal | Tracked compensation workflow, not blind sleep-and-retry |

Do not expose account, transfer, or withdrawal routes merely because the legacy
bundle had them. Confirm the legal entity, fund ownership, approval policy,
operator permissions, and reconciliation procedure first.

## 13. Admin, Store API, and permissions

### 13.1 Administration

Add an admin navigation group for:

- provider profiles;
- merchants;
- merchant-to-Store grants, if enabled;
- Store bindings;
- POS devices;
- payment attempts;
- refunds;
- POS receipts and exception cases.

The existing `PaymentSetting` admin collection is not the Huifu credential store.
Keep the two concepts separate and update any Huifu placeholder in
`PaymentChannels.json` so it does not imply that generic `appId`/`apiKey` fields
are effective.

### 13.2 Store API

Follow the Store WeChat adapter pattern: a Store-scoped controller using
`StoreScopedAuthorizationApiMixin`, scoped queries, and backend authorization.
Route shapes can be:

```text
GET/POST        /api/v1/store/{scopeId}/huifu-settings
GET/PUT/DELETE  /api/v1/store/{scopeId}/huifu-settings/{id}
GET             /api/v1/store/{scopeId}/huifu-settings/options
```

Client input cannot change Store ownership. Options expose only permitted
merchants and never expose credentials.

### 13.3 Permissions

Seed system permissions and a Store-scoped role with a command, following the
existing authorization seed pattern. Suggested resource split:

- `huifu_provider:*` for platform credential profiles;
- `huifu_merchant:*` for merchants and grants;
- `store:huifu_setting:*` for Store bindings;
- `huifu_pos_device:*` for device management;
- `huifu_refund:*` for refund initiation and confirmation;
- `huifu_transaction:read` for attempt, refund, and receipt review.

Frontend menu visibility is not authorization. Every controller must enforce
the backend permission and Store scope.

## 14. Security, privacy, and observability

- Never log private keys, complete signed payloads, payment codes, card data,
  OpenIDs, or callback secrets.
- Log request identifiers, sanitized response codes, attempt IDs, and state
  transitions.
- Use structured logs and stable correlation IDs.
- Apply rate limits to public notifications if the deployment permits it, while
  preserving provider retry behavior.
- Keep provider calls behind a timeout and bounded retry policy.
- Do not retry non-idempotent create operations without first querying.
- Restrict notification host/proxy trust so generated callback URLs cannot be
  poisoned by client headers.
- Sanitize payloads before storing them in invoice extra data.
- Keep disabled profiles usable for verification and refunds, but block new
  payments.
- Do not hard-delete provider profiles, merchants, attempts, or refunds that are
  referenced by history.

## 15. Migration and compatibility

- Add all tables through module migrations; do not alter Core entities.
- Preserve the legacy migration namespace only if existing deployed data is
  imported. A new module can start with a fresh migration history.
- If legacy rows are imported, map them explicitly and retain original IDs or
  references; do not guess merchant and credential relationships.
- Add a forward migration for any renamed or retired legacy table according to
  the repository's migration compatibility policy.
- Provide a sanitized example environment file and deployment documentation for
  `HUIFU_CONFIG_KEY`; never commit a real key.
- Document that existing generic `PaymentSetting` rows are not migrated
  automatically.

## 16. Implementation phases

### Phase 1: configuration foundation

- Create the module skeleton and migrations.
- Add provider profile, merchant, Store binding, and optional grant entities.
- Add encrypted credential storage and key-version handling.
- Add admin and Store CRUD APIs with authorization and Store scoping.
- Add the authorization seed command.
- Add unit tests for encryption, masking, resolution, and cross-Store access.

### Phase 2: online payment

- Implement the Huifu client, signer, response parser, and endpoint allowlist.
- Implement payment attempt persistence and the payment initiation boundary.
- Implement the `huifu` gateway for the first approved channel.
- Add the notification controller with signature and identity validation.
- Add query and reconciliation command.
- Add duplicate, timeout, invalid signature, and cancelled-invoice tests.

### Phase 3: refunds and close

- Implement the refund record, reservation, submission, query, and confirmation.
- Integrate confirmed refunds with Core accounting.
- Audit every refund entry point.
- Implement close-order behavior and late-payment handling.
- Test partial refunds, concurrent refunds, unknown results, and configuration
  changes after payment.

### Phase 4: POS

- Add device and receipt entities.
- Implement signed POS notification handling and deduplication.
- Implement linked-order payment and unclaimed receipt review.
- Add rebinding safeguards.
- Add optional deposit-top-up policy only if separately approved.

### Phase 5: account and treasury

- Add account, balance, transfer, withdrawal, and compensation workflows.
- Add approval, limits, audit, and reconciliation.
- Validate against the contracted product and a non-production merchant account.

## 17. Test plan

### Unit

- RSA signing and verification fixtures, including canonical serialization.
- Provider response normalization for create, query, notify, and refund.
- Cent-to-yuan conversion and rounding rejection.
- Credential encryption, decryption, rotation, and failure modes.
- Store/profile resolution and disabled-config behavior.
- Status transitions and idempotency keys.

### Integration

- Two Stores sharing one merchant and provider profile.
- Two Stores using different merchants or provider profiles.
- A Store attempting to bind a merchant outside its grant.
- Payment with a trusted Store context and rejection without one.
- Cross-Store invoice and notification attempts.
- Duplicate and out-of-order notifications.
- Notification amount, currency, merchant, and signature mismatches.
- Provider timeout followed by a successful query.
- Refund processing, confirmation, partial refund, and duplicate retry.
- Cancelled invoice receiving a successful payment.
- POS duplicate receipt, unknown terminal, and rebinding.
- Authorization seeding and Store-scoped permission denial.

### Validation commands

Use the repository's documented commands rather than inventing a new suite:

```sh
make test-backend
make test-admin
make type-check
node scripts/check-doc-links.mjs
```

Run the relevant module tests with the integration PHPUnit configuration and the
Core vendor dependencies. Real Huifu sandbox or production calls are not part of
automated CI and require explicit manual verification with non-production
credentials.

## 18. Acceptance criteria

The first release is complete when:

- a Store can be bound to a Huifu merchant without exposing credentials;
- two Stores can share the same configuration and two Stores can use different
  configurations;
- a store-scoped invoice can initiate the approved Huifu channel;
- a signed notification or query confirms the payment exactly once;
- a timeout is recoverable without creating a duplicate charge;
- a refund is not marked complete until Huifu confirms it;
- a refund and a notification use the merchant and credential version from the
  original payment;
- an unconfigured, disabled, or unauthorized Store cannot pay through Huifu;
- POS receipts are verified, deduplicated, and either linked or retained as
  unclaimed;
- no secret or sensitive payment data appears in logs, API responses, or Git;
- tests and documentation checks listed above pass.

## 19. Decisions and remaining verification

The current implementation applies these defaults:

1. Provider credentials are platform-managed and encrypted at rest.
2. Store bindings are administrator-managed or limited by explicit merchant
   grants.
3. JSAPI and Native order creation are enabled; quick pay is submitted but its
   refund flow is disabled.
4. Generic Core pay/refund calls without a HuifuFin attempt or refund record are
   rejected.
5. POS receipts are linked or retained as unclaimed; automatic wallet top-up is
   not implemented.
6. Account, transfer, and withdrawal operations remain outside this release.

Still requiring provider-contract verification before production:

- the exact product, environment, signing serialization, and notification
  protocol;
- the refund and refund-query endpoints for the contracted product;
- live callback reachability, TLS/proxy behavior, and provider sandbox results;
- MySQL concurrency and long-running reconciliation behavior.

## 20. References

- [Architecture](../design/architecture.md)
- [Backend module registration](../contracts/backend-module-registration.md)
- [HuifuFin module README](../../business/backend/HuifuFin/README.md)
- [2026-11-01 implementation record](../sessions/2026-11-01-huifu-fin-store-payment.md)
- [Store WeChat adapter](../../business/backend/Wechat/README.md)
- [Store WeChat payment gateway](../../business/backend/Wechat/src/Service/StoreWechatPaymentGateway.php)
- [Core invoice service](../../core/crud-skeleton/src/Payment/Service/InvoiceService.php)
- [Core payment gateway contract](../../core/crud-skeleton/src/Payment/Service/PaymentGatewayInterface.php)
- [Huifu PHP SDK repository](https://github.com/huifurepo/bspay-php-sdk)
