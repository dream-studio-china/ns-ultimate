# Public order payment information

## Scope and ownership

`GET /api/v1/public/orders/{orderUuid}/payment` is implemented by the business Store module's [public controller](../../business/backend/Store/src/Controller/Public/OrderPaymentController.php). Huifu channel configuration is projected by [HuifuProfileResolver](../../business/backend/HuifuFin/src/Service/Configuration/HuifuProfileResolver.php). No Core routes or authentication policies are changed.

This is an anonymous, read-only payment-page summary. Possession of an order UUID permits reading the fields below, including items and payment status, regardless of the caller's relationship to the payer. UUIDs are not access tokens: deployments must treat links containing them as sensitive and apply appropriate edge rate limits. This release does not add signed checkout tokens or application-level rate limiting. No list/search or numeric-ID lookup is provided.

## Response

Successful responses use `code: 0`, `message: SUCCESS`, and the following `data` shape:

```json
{
  "orderUuid": "<order-uuid>",
  "status": "confirmed",
  "store": { "uuid": "<store-uuid>", "name": "Example Store" },
  "currency": "CNY",
  "amount": 2400,
  "items": [
    { "name": "Lunch", "specification": "Regular", "quantity": 2, "unitPrice": 1200, "amount": 2400 }
  ],
  "provider": "huifu",
  "channels": ["native"],
  "defaultChannel": "native",
  "invoice": {
    "uuid": "<invoice-uuid>",
    "outTradeNo": "<invoice-payment-number>",
    "status": "paying",
    "amount": 2400,
    "currency": "CNY",
    "payment": "huifu",
    "refundedAmount": 0,
    "paidAt": null
  }
}
```

Amounts are stored integer minor units, not floating-point values. Item prices and names come from persisted order lines/snapshots, not a new catalog quotation. Order-level discounts can make the order amount differ from the sum of item amounts. Missing legacy item names/specification titles are empty strings. `paidAt` is an ISO-8601 timestamp or null; `payment` may be null before initiation.

`invoice` is null if the order has no linked Invoice. Otherwise it describes only the currently linked Invoice, not every historical Invoice or the latest successful one. Its `payment` is the Invoice's recorded method, not a promise that the order was paid through Huifu. The top-level `provider` identifies the channel configuration source; it remains `huifu` even when no Huifu channels are enabled.

## Visibility and consistency

- UUID lookups accept either case. Numeric IDs, malformed/unknown UUIDs, orders without a trusted `OrderStoreLifecycle`, and orders whose lifecycle points to a missing/inactive Store are not exposed.
- Store ownership comes from that lifecycle, not `metadata._store`, a request-supplied Store ID or the Invoice's payer.
- A linked Invoice must exist and match `sourceType = trade_order`, `sourceId = orderUuid`, scene `order`, total amount and currency. A dangling or inconsistent link fails closed without exposing the Invoice.
- Pending and terminal orders remain readable. Neither a successful GET nor a nonempty `channels` list grants permission to pay. Consumers must inspect order and Invoice statuses; payment initiation must enforce its own current-state checks.
- The explicit projection excludes users/payers, notes, arbitrary order/item metadata, costs/profit, complete snapshots, merchant/provider identities and credentials, gateway transaction IDs, Invoice descriptions/extra data and payment-launch payloads.

All controller responses, including its 404/409 errors, use `Cache-Control: no-store, private` and `Vary: Authorization`. Unknown/invisible orders return `404 order_not_found`; inconsistent linked Invoices return `409 order_payment_conflict`. Routing rejects malformed path shapes before the controller.

## Channel projection and side effects

`channels` is the intersection of Store-enabled Huifu methods and merchant capabilities. The Store setting, merchant and provider must be enabled, the provider must have stored credentials, and the merchant must currently be granted to the Store. Missing or disabled configuration yields `channels: []` and `defaultChannel: null`. If the configured default is excluded by merchant capabilities, the first remaining channel is the default.

This projection neither decrypts nor validates stored credentials, contacts Huifu, checks the payer's WeChat identity, nor guarantees browser/device or currency compatibility. In particular, JSAPI can still require WeChat sign-in. These checks remain the responsibility of the future payment initiation flow.

GET never creates/replaces an Invoice, generates an attempt/request sequence, recalculates prices, confirms an order, or initiates/retries payment. It does not return a QR code, JSAPI `payInfo`, or signing material. `outTradeNo` is an identifier, not a launch credential. Public initiation and multiple-Invoice exception handling remain [proposed follow-ups](../design/architecture.md#payment-evolution-multiple-invoices-and-public-checkout).

## Validation

[PublicOrderPaymentApiTest](../../business/backend/Store/tests/Integration/PublicOrderPaymentApiTest.php) exercises anonymous access, the exact public projection, existing/no Invoice, current versus historical Invoice selection, stored status reads, rejected numeric/unknown IDs, lifecycle visibility, inactive Stores, conflicting Invoice ownership/amounts, channel intersection/default fallback, disabled/revoked configuration, no credential decryption, and repeated GET/POST rejection without payment creation. Tests run through the composed Kernel with an isolated SQLite schema. Real provider launch behavior, production rate limiting, browser identity and MySQL concurrency are not established by these tests.
