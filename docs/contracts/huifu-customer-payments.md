# Authenticated customer Huifu payment

## Implemented boundary

The HuifuFin business module handles `payment = huifu` through the existing unified `POST /api/v1/app/orders/{id}/payment` endpoint for signed-in customers. No provider-specific customer HTTP route is added. This is separate from the employee Store endpoint and the [public read-only payment summary](public-order-payment.md). No Core authentication rules, ownership filters or generic gateway proofs are relaxed.

The owner may pay their own confirmed order. Another authenticated user may pay only when the global Common Setting `payment.allowThirdPartyPayment` has type `boolean` and stored value `true`. Missing/disabled/malformed settings deny non-owner access with `404 order_not_found`. Anonymous access remains denied. Payment permission does not grant order-detail, cancellation or refund access.

Use the ordinary order ID or UUID and select the provider in `payment` and its internal channel in `gateway`:

```json
{ "payment": "huifu", "gateway": "native", "tradeType": "T_NATIVE" }
```

An omitted/empty `gateway` selects the effective configured default. `native` uses `T_NATIVE`; `jspay` and `quickpay` use `T_JSAPI`. A supplied trade type must match that selection. The channel must be allowed by the enabled Store setting and merchant capabilities, with an enabled provider, stored credentials and a current merchant-to-Store grant. Credential validity and provider support are checked during initiation, not inferred from the public channel list. JSAPI requires the actual Invoice payer's WeChat identity for the Store's configured application. Quick-pay product compatibility and its previously documented refund limitations remain unchanged.

Only positive CNY orders within the customer adapter's amount bound are accepted. Store ownership is taken from the Trade lifecycle; the Store must be active. A currently linked Invoice must match the order source, scene, amount and currency, and must not already record a paid/refunded collection against a still-confirmed order. The Huifu dispatch adapter accepts only `payment`, `gateway`, `tradeType` and the server-supplied `_payer` User context. Core always replaces any request-supplied `_payer` with the authenticated user. Other fields, including amounts, payer/OpenID, Store/merchant selection, attempt IDs, proofs, preparation contexts, wallet deductions and credentials, are rejected.

## Preparation and initiation

1. Authorize the current user and validate the Store/channel configuration.
2. The outer Huifu Trade adapter delegates preparation to the existing inner `OrderServiceInterface::createPayment()` chain with a server-only typed preparation context and the authenticated payer. Existing Counter merge/inventory checks and Core Invoice creation/reuse run normally, but the Huifu Invoice preparation decorator does not start payment or apply deductions.
3. Let that composed transaction finish. The actual payer belongs to the Invoice; the order owner is unchanged. A pending Invoice belonging to a different payer is not reassigned.
4. Revalidate source/current-Invoice identity and customer eligibility under the source-order lock, then commit a normal Huifu attempt before calling Core payment.
5. During the Core payment transaction, validate that customer context again before sending the Huifu request. Keep the existing internal attempt proof and response-signature/amount validation.

The preparation context is a PHP object, not a JSON marker or authorization token. Direct generic Invoice `huifu` calls still cannot start/resume Huifu attempts without the existing controlled proof. The context is not returned, persisted in metadata or forwarded as a provider parameter. The outer customer adapter runs outside Counter's preparation transaction and rejects a caller-owned outer transaction so the attempt remains durable if the subsequent payment transaction rolls back. Other payment names continue through the existing dispatch chain unchanged.

## Responses and outcomes

A successful initiation returns the existing Core HTTP 200 success envelope (`code: 0`). A non-owner receives Core's restricted Invoice/payment projection, for example:

```json
{
  "invoice": {
    "uuid": "<invoice-uuid>",
    "outTradeNo": "<invoice-payment-number>",
    "status": "paying",
    "amount": 6000,
    "currency": "CNY"
  },
  "status": "paying",
  "payUrl": "<provider-qr-url-or-null>",
  "qrCode": "<provider-qr-data-or-null>",
  "payload": {
    "attemptUuid": "<attempt-uuid>",
    "reqSeqId": "<provider-request-sequence>",
    "reqDate": "<provider-request-date>",
    "channel": "native",
    "tradeType": "T_NATIVE",
    "payInfo": null
  }
}
```

The existing owner response shape is preserved. JSAPI launch information, when returned by Huifu, is in `payload.payInfo`. Do not interpret HTTP 200 or a QR/JSAPI result as proof of payment. Signed notifications on the existing `/api/payment/notify/huifu/{attemptUuid}` endpoint or reconciliation establish success/failure through the existing Invoice/Trade workflow. A verified failure must not mark the order paid.

An unresolved initiation (for example, a timeout after submission) uses the same HTTP 200 result envelope with `data.status = unknown`, the Invoice summary and attempt identity, without fabricated launch parameters. The Invoice summary reflects persisted state after rollback, not a transient in-memory transition. The durable attempt remains available for reconciliation. Do not automatically retry or treat an unknown result as a confirmed failure. Existing Core controller semantics are retained: service-level state/configuration/validation failures return HTTP 400, non-owner authorization failure returns 404, and anonymous callers are denied by authentication. Provider failure details are redacted by the customer service; internal proofs and owner notes are not returned in the non-owner projection. A business response subscriber marks shared app order payment responses `Cache-Control: no-store, private` and adds `Vary: Authorization` without editing Core.

This endpoint is not an order-wide idempotent command. Deliberately starting another payment can create another Invoice when the old one is paying or belongs to another payer, matching the established multiple-Invoice policy. An unresolved attempt on the same pending Invoice is not submitted twice. No blanket order-wide exclusion, automatic provider closure, duplicate-collection refund automation or metadata exception-history feature is introduced. See the [recorded payment follow-up](../design/architecture.md#payment-evolution-multiple-invoices-and-public-checkout).

## Validation scope

The customer API tests use isolated database fixtures, the real composed HTTP controllers and Invoice services, a fake Huifu HTTP client with RSA-signed responses, and the real signed notification route. No production simulation endpoint is added and no real Huifu payment is requested. Coverage and results must be reported from the executed test suite; simulated results do not establish real provider/browser compatibility, production concurrency or merchant-product acceptance.
