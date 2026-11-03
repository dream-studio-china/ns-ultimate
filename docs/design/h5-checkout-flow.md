# H5 checkout flow

**Status: Proposed design.** This document defines the customer H5 checkout
target. It does not claim that every route or provider integration described
here is implemented. See [current gaps](#current-implementation-gaps) for the
parts that still need an implementation slice.

## 1. Scope

The cashier creates a checkout QR for one confirmed Trade order. The customer
scans it, signs in through WeChat or Alipay, reviews the server-owned order
snapshot, creates or reuses an invoice, pays through the Store's configured
payment channel, and returns to a server-confirmed result.

The first release covers:

- displaying or printing the same checkout QR from the Store console;
- WeChat H5 login and fixed guest-account auto-login for Alipay;
- a redacted checkout order projection with line items and amount due;
- invoice creation, reuse, replacement, and concurrent-payer protection;
- WeChat JSAPI and Store-configured Huifu dispatch;
- server-confirmed success, pending, and failure states;
- refreshing the order after payment.

Refunds, discretionary checkout rules, fiscal receipts, and printer-hardware
integration are outside this flow. Printing the QR may use the browser or an
existing printer integration, but the QR payload and lifecycle are the same as
the on-screen version.

## 2. Entry URL and trust boundary

The existing Store console builds this URL:

```text
https://<payment-host>/h5/payment/index.html?orderUuid=<orderUuid>&storeUuid=<storeUuid>
```

`orderUuid` selects the order. `storeUuid` binds the request to the Store whose
payment settings must be used. The H5 never supplies the amount, currency,
payer, invoice status, gateway credentials, or merchant identity to the server.

The current demo page is not this flow. It reads demo payment state from browser
storage. A production page must replace that state with authenticated API calls.

The order UUID is an identifier, not proof that the scanner may see every order
field. The checkout read endpoint returns a separate, restricted projection and
does not expose the general customer order resource. In particular, it must not
return customer identity, delivery details, staff data, internal metadata,
cost, profit, or arbitrary order metadata.

For additional hardening, the QR may later include a short-lived signed checkout
token. That token, when present, binds the URL to one Store/order pair and makes
the QR revocable. It must not replace server-side authorization or be called a
payment proof.

## 3. End-to-end flow

```mermaid
sequenceDiagram
    participant C as Cashier
    participant H as Customer H5
    participant A as Checkout API
    participant I as Identity
    participant P as Payment provider

    C->>C: Display or print checkout QR
    H->>H: Read orderUuid and storeUuid; detect WeChat/Alipay
    alt Valid bearer token
        H->>I: GET /api/v1/app/users/me
    else WeChat without valid token
        H->>I: Start Store-scoped WeChat OAuth
        I-->>H: JWT after callback
    else Alipay without valid token
        H->>I: POST /api/auth/guest
        I->>I: Sign in fixed guest account server-side
        I-->>H: Guest JWT
    end
    H->>A: GET checkout order projection
    A-->>H: Items, payable amount, payment state
    H->>A: Prepare invoice after customer taps Pay
    alt Existing pending invoice belongs to another payer
        A-->>H: 409 invoice payer confirmation required
        H->>H: Ask customer to confirm replacement
        H->>A: Retry with explicit confirmation and idempotency key
    end
    H->>A: Start payment for invoice
    A->>P: Dispatch Store-configured payment request
    P-->>A: Paying or unknown result
    A-->>H: Provider dispatch data
    H->>P: Open WeChat/Alipay payment UI
    P-->>A: Signed asynchronous notification
    H->>A: Poll payment/order state
    A-->>H: Paid only after server confirmation
    H->>H: Show success and refresh order
```

The client-side payment callback is never treated as proof of payment. A
verified provider notification or reconciliation query is authoritative.

## 4. Terminal detection and login

Terminal detection selects the login and payment UI path; it does not grant
authorization or choose a trusted payment amount.

| Container | Detection hint | Valid token | Missing or expired token |
| --- | --- | --- | --- |
| WeChat | User agent contains `MicroMessenger` | Continue after `/api/v1/app/users/me` succeeds | Start Store-scoped WeChat OAuth |
| Alipay | User agent contains `AlipayClient` | Continue after `/api/v1/app/users/me` succeeds | Call the fixed guest-account auto-login endpoint |
| Other | Neither hint | Continue if authenticated and payment is supported | Show an unsupported-container message |

For WeChat, the H5 is an Official Account page, not a Mini Program. It must use
the Store's configured Official Account OAuth flow. The callback must preserve
`orderUuid` and `storeUuid` and return to the same payment page. The server must
validate the callback host and return path before using them; an arbitrary
user-supplied `redirect_uri` is an open-redirect risk.

For Alipay, the H5 does not send a username or password. It calls a
server-owned auto-login endpoint that signs in the fixed guest account. The
candidate contract is:

```http
POST /api/auth/guest
Content-Type: application/json

{"storeUuid":"...","orderUuid":"...","terminal":"alipay"}
```

It returns the same JWT envelope as the other login endpoints. It must create or
resolve the configured guest principal, be rate limited, avoid collecting
unrelated personal data, and not expose store administration or staff
permissions. The backend signs in through the normal identity service using the
fixed identifier `游客`; the password is supplied as deployment secret
configuration and is never bundled into the H5, returned by the API, written to
logs, or committed to this repository. Suggested configuration names are
`GUEST_LOGIN_IDENTIFIER=游客` and `GUEST_LOGIN_PASSWORD=<deployment secret>`.

This route is not implemented in the current repository. It authenticates one
existing fixed account rather than creating a new guest account for every
scanner.

Because every Alipay customer signs in as the same fixed account, the invoice
`payer` alone cannot distinguish two Alipay sessions. Invoice resolution must
also compare a server-issued checkout-session identifier for the scanned order.
That identifier is issued when loading the order and recorded on the invoice
attempt. Independent scans receive different sessions even though their payer
user is the same.

JWTs never go in the checkout URL, QR, logs, or analytics events. Expiry returns
`401`; the H5 clears the token, reruns the terminal-specific login, and reloads
the same order rather than starting a new checkout.

## 5. Checkout order read

Use a checkout-specific projection rather than the owner-scoped
`GET /api/v1/app/orders/{id}` route. Store-counter orders may be anonymous, so
the customer who signs in after scanning is not necessarily the Trade order's
`user`.

Candidate read contract:

```http
GET /api/v1/app/checkout/orders/{orderUuid}?storeUuid=<storeUuid>
Authorization: Bearer <token>
```

The response contains only what the customer needs to verify the bill:

```json
{
  "orderUuid": "...",
  "storeName": "Example Store",
  "orderNo": "A001",
  "status": "confirmed",
  "paymentStatus": "pending",
  "currency": "CNY",
  "items": [
    {
      "productName": "Product",
      "specificationName": "Specification",
      "quantity": 2,
      "unitAmount": 1200,
      "lineAmount": 2400
    }
  ],
  "payableAmount": 2400,
  "payable": true,
  "displayState": "payable"
}
```

All amounts are integer minor units. The backend verifies that the order exists,
belongs to the requested Store through trusted lifecycle data, is confirmed for
payment, and is not already paid or otherwise terminal. A Store mismatch returns
`404`, not a detailed cross-Store existence error. `payable: false` plus a safe
reason code covers cancelled, already-paid, merged, and otherwise locked orders.

The customer UI must not recalculate the amount due or infer it from line totals.
It displays the server's order snapshot and refreshes it after a stale-state
conflict.

## 6. Invoice resolution when Pay is tapped

Invoice resolution occurs only after the customer taps Pay. It runs under an
order-row lock and uses a client-generated idempotency key, such as
`paymentRequestId`. Repeating the same request returns the same result instead of
creating another invoice.

The server applies this decision table:

| Current state | Result |
| --- | --- |
| Order has no invoice | Create an invoice for this order and current payer |
| Invoice is `pending`, owned by current payer, and matches order source, amount, and currency | Reuse it |
| Invoice is `pending`, owned by another payer or checkout session, customer has not confirmed | Return `409 INVOICE_PAYER_CONFLICT`; show the warning |
| Invoice is `pending`, owned by another payer or checkout session, customer confirmed replacement | Create a new invoice for the current payer/checkout session and move the order's invoice pointer |
| Invoice is `failed` or `cancelled` | Create a new invoice |
| Invoice row is missing or its source, amount, or currency does not match the order | Treat it as invalid and create a new invoice |
| Invoice is `paying` | Do not create or replace it; query and wait for a provider result |
| Invoice is `paid`, `partial_refunded`, or `refunded` | Do not create another invoice; show the settled state |

`paying` is intentionally not treated as a replaceable "normal" invoice. A
provider request may already have left the application, and creating a second
invoice would reintroduce the double-charge risk. The H5 shows a waiting state
and polls until the result is known.

When a confirmed replacement is allowed, the server must retire the old
unsubmitted `pending` invoice and update the order pointer in the same
transaction. If the old invoice already has any provider attempt, replacement
fails closed and enters the query flow. This prevents an earlier scanner from
paying an invoice that the current customer has replaced. Old invoice history is
retained and is never deleted.

The exact warning text is:

```text
已经有其他人扫码结账，可能会重复结账，是否确定？
```

The confirmation dialog has `确定` and `取消` actions. `取消` sends no payment
or invoice-creation request. `确定` retries the invoice endpoint with an explicit
`confirmPayerReplacement: true` and the previously returned invoice reference.
The backend rechecks all state and returns a conflict if it changed.

Candidate request:

```http
POST /api/v1/app/checkout/orders/{orderUuid}/invoice
Authorization: Bearer <token>
Content-Type: application/json

{
  "storeUuid": "...",
  "paymentRequestId": "...",
  "checkoutSessionId": "...",
  "confirmPayerReplacement": false,
  "expectedInvoiceUuid": null
}
```

The response returns the invoice UUID, status, amount, currency, and whether the
invoice already belongs to the current payer and checkout session. It must not
expose provider credentials or another payer's identity. With the fixed Alipay
account, `checkoutSessionId` is required to preserve the repeat-scan warning;
using only `payer` would make every Alipay customer look like the same initiator.

## 7. Store payment-channel dispatch

The browser selects a terminal and payment experience. The server selects the
provider from trusted Store configuration, the confirmed order, and the current
invoice. The client cannot request `huifu`, `wechat`, or any other gateway to
bypass a disabled Store channel.

Candidate request:

```http
POST /api/v1/app/checkout/orders/{orderUuid}/payments
Authorization: Bearer <token>
Content-Type: application/json

{
  "invoiceUuid": "...",
  "paymentRequestId": "...",
  "terminal": "wechat"
}
```

Before dispatch the server verifies the current payer, invoice status, invoice
amount and currency, order status, Store association, and enabled payment
channel. Provider operations are serialized per invoice so double taps and two
tabs cannot start independent attempts.

| Configured provider | H5 context | Server dispatch |
| --- | --- | --- |
| Huifu | WeChat | Store-scoped `huifu` gateway; `channel=jspay`, `tradeType=T_JSAPI`; OpenID resolved from the Store's configured WeChat application |
| Huifu | Native QR fallback | `channel=native`, `tradeType=T_NATIVE`; return the provider QR payload |
| WeChat Pay | WeChat H5 | Store-scoped `wechat` gateway; Official Account JSAPI parameters |
| Alipay or Huifu Alipay flow | Alipay | Only through a configured, provider-verified adapter; an Alipay user agent alone is not sufficient |

The current Huifu `quickpay` adapter exists but its live product contract is not
verified, and the repository has no Alipay adapter. Alipay payment must remain
unavailable until a Store-configured adapter has signed-request, callback,
query, amount, and reconciliation coverage. Fixed guest-account login alone does
not enable Alipay payment.

The current `POST /api/v1/store/{storeUuid}/huifu-payments` endpoint is
staff-authorized and must not be called directly by the customer H5. A new
customer checkout facade should call the same Store payment service through a
trusted server-side proof, after checking that the order, Store, invoice, and
current customer belong together.

The backend returns a provider-neutral envelope:

```json
{
  "invoiceUuid": "...",
  "status": "paying",
  "provider": "huifu",
  "action": "wechat_jsapi",
  "payInfo": {},
  "qrCode": null,
  "payUrl": null
}
```

For WeChat JSAPI, `payInfo` is passed to the WeChat bridge. The H5 never builds
or signs merchant requests itself. For a provider QR fallback, the H5 renders
the opaque `qrCode` value and asks the customer to scan it; it does not parse it
as a trusted instruction.

## 8. Result, polling, and order refresh

Provider success callbacks in the browser are hints only. After provider UI
returns, the H5 moves to `paying`/`checking` and polls the checkout order read
with bounded exponential backoff. It may also call a dedicated payment-query
endpoint if the provider adapter supports safe reconciliation.

The H5 shows success only when the server reports the invoice as `paid` (or the
order's payment status as paid). The backend notification path must be
idempotent and update the Trade order through the existing invoice listener. The
success state then shows a confirmation and refreshes the current order in both
the H5 and, on its next refresh, the cashier screen.

Result handling:

- `paid`: stop polling, show the success message, and refresh the order.
- `paying` or `unknown`: keep the payment locked, explain that confirmation is
  pending, and let the customer query again. Do not create another invoice.
- `failed`: show a retry action. A new tap runs invoice resolution again and may
  create a replacement invoice after the old one is confirmed failed.
- `cancelled`: do not continue payment; return to the refreshed bill.

For an unknown provider result, the server must create or retain an attempt
record, allow reconciliation, and block replacement until it is resolved. A
successful browser callback must never mark an invoice paid by itself.

## 9. UI states and failure behavior

The page has explicit states for `authenticating`, `loading-order`,
`order-unavailable`, `payable`, `confirm-repeat-payment`,
`creating-invoice`, `provider-pending`, `checking-result`, `paid`, and
`failed`. Payment buttons are disabled while a request is in flight and when the
browser is offline.

The H5 preserves `orderUuid` and `storeUuid` across login redirects. It does not
preserve customer-submitted amount, invoice, or provider fields as trusted
state. Reloading the page re-reads the order and resumes any known pending state.

Errors use stable codes so the UI can localize them:

| Code | HTTP | User behavior |
| --- | --- | --- |
| `ORDER_NOT_FOUND` | 404 | Show an invalid or expired checkout link |
| `ORDER_NOT_PAYABLE` | 409 | Refresh and show current order state |
| `ORDER_ALREADY_PAID` | 409 | Show paid state; do not pay again |
| `INVOICE_PAYER_CONFLICT` | 409 | Show the exact repeat-payment confirmation |
| `PAYMENT_IN_PROGRESS` | 409 | Show pending/query state; do not create another invoice |
| `PAYMENT_CHANNEL_UNAVAILABLE` | 409 | Tell the customer to contact staff |
| `AUTH_REQUIRED` | 401 | Rerun terminal-specific login |

## 10. Acceptance criteria

- The cashier can display and print the same checkout QR for a confirmed order.
- WeChat without a valid token is redirected through Store-scoped OAuth; Alipay
  without a valid token is automatically signed in to the fixed guest account
  by the server; neither flow puts a credential in the URL.
- A customer can only read the restricted item/amount snapshot for the scanned
  order and Store.
- Paying with no invoice creates one; a valid pending invoice from the same payer
  is reused; invalid invoices are replaced.
- A pending invoice from another payer produces the exact warning. Confirming
  creates a guarded replacement invoice; cancelling creates and pays nothing.
- Concurrent requests, double taps, and stale invoice changes cannot silently
  create two payable invoices or start two provider attempts.
- Huifu and direct WeChat dispatch use Store configuration and server-resolved
  payer identity. No provider parameter is trusted from the client.
- Success is shown only after server confirmation, and the order is refreshed.
- Unknown payment outcomes remain queryable and never become success or a new
  payment merely because the browser callback fired.

## 11. Current implementation gaps

The repository currently provides the checkout URL builder, Store console QR UI,
Store-scoped WeChat login, invoice/payment foundations, direct Store WeChat
gateway, and Huifu JSAPI/Native gateway. The following pieces are still needed
before this design can be called implemented:

- replace the demo-only `payment.html` application with the authenticated H5;
- add a customer checkout module/facade with scoped order reads, invoice
  resolution, payment dispatch, and status polling;
- add server-side fixed guest-account auto-login for Alipay and keep its password
  in deployment secret configuration;
- add and verify an Alipay or Huifu Alipay payment adapter;
- connect the checkout QR print action to a real print workflow;
- add live-provider, callback, reconciliation, and MySQL concurrency acceptance
  tests before enabling production payments.

