# Channel-confirmed order payment closure

## Scope

This business-owned capability closes the current payment of a confirmed Trade order, not the Trade order itself. After successful release, existing cancellation, merge and payment initiation flows keep their own eligibility checks. A new payment creates a new Invoice through the normal payment entry point; the old Invoice and provider attempts are retained. No Core gateway interface, workflow, schema, merge guard or generic Invoice cancellation route is changed. No UI button is added.

`business/backend/Payment/src/Contract/PaymentCloseGatewayInterface.php` is an optional channel capability, automatically tagged as `ns.payment.close_gateway`. `PaymentCloseGatewayRegistry` dispatches by the Invoice's stored payment name. Implementations confirm that **all** provider attempts for that Invoice are terminal and unable to collect, or return an unconfirmed result/throw. They never initiate payment, refund, change the Invoice or release an order. A verified collection discovered during closure is returned as `PaymentNotifyResult` instead. Unsupported channels fail closed; there is no fallback that merely cancels the local Invoice.

`OrderPaymentCloseService` owns Store/order/Invoice validation, transaction and lock ordering, channel dispatch, local Invoice cancellation through the existing service, source-reference release and durable command audit. Huifu is one capability implementation, not a separate closure API or permission model. Other payment integrations can implement the same interface without changing the controllers or orchestration.

## API and permissions

| Endpoint | Authorization |
| --- | --- |
| `POST /api/v1/manage/orders/{tradeOrderUuid}/payment/close` | `ROLE_ADMIN`; Store derived from trusted order lifecycle; no employee membership required |
| `POST /api/v1/store/{storeUuid}/trade-orders/{tradeOrderUuid}/payment/close` | Active membership and `store:order:payment_close` for the target Store, including administrator callers using this employee route |

Both identifiers are UUIDs, not numeric admin IDs or StoreOrder UUIDs. Cross-Store orders are rejected. Run `app:store:seed-staff-authorization` to register `store_order_payment_closer` and explicitly assign it. Existing order reader/operator, counter merge and refund roles are not granted this capability automatically. The endpoints require an authenticated actor, JSON, and exactly:

```json
{
  "commandUuid": "<unique-command-uuid>",
  "invoiceUuid": "<expected-current-invoice-uuid>",
  "reason": "Customer requests closure before merging"
}
```

The reason is trimmed, nonempty and at most 1,000 bytes. Bodies over 64 KiB, unknown fields and invalid identifiers are rejected. The expected Invoice UUID prevents a delayed request from closing a newer payment. Clients must retain the same command UUID, Invoice and reason after a network error rather than silently target another Invoice.

Success returns HTTP 200 with `code: 0` and:

```json
{
  "status": "closed",
  "tradeOrderUuid": "<trade-order-uuid>",
  "invoiceUuid": "<closed-invoice-uuid>",
  "invoiceStatus": "cancelled",
  "released": true
}
```

The object is inside the usual `data` envelope. Source `invoiceId`, `invoiceNo`, `paymentStatus` and `paymentMethod` are cleared; the order remains `confirmed`. No historical Invoice, provider attempt or merge/counter binding is deleted, and the operation does not directly cancel an order, merge orders or create a replacement Invoice.

If the channel reports payment success during closure, the existing Invoice notification service applies it and HTTP 409 returns `payment_already_paid` with `data.status: paid` and `released: false`. If the local Invoice was already failed/cancelled and Core cannot apply that success, the verified collection is retained in its `payment_close_collection` extra data, the channel evidence and command receipt, with `requiresReview: true` and a critical diagnostic. No fabricated paid transition or source release is performed.

An unsupported channel, unresolved/rejected close, stale Invoice, ambiguous historical Invoice set or ineligible order returns 409 without release. Provider transport/verification exceptions and local application errors return a redacted 502 `payment_close_failed`. Caller validation returns 400/413, authorization 401/403, and missing/out-of-scope orders 404. Financial responses are private and non-cacheable; raw provider data and credentials are never returned.

## Transaction, retry and audit

The existing `StaffCommandService` serializes commands on the Store, then this service locks and refreshes the source order and its Invoice records before invoking the channel. Locks are intentionally held through the bounded provider calls to serialize with source-locked payment initiation and merge. This can delay other commands for the Store; clients may time out before server completion. This is not a claim that every legacy write or callback follows the same lock ordering; production MySQL deadlock/load acceptance remains required.

Successful closure and discovered-payment outcomes write a `StaffOperation` receipt with actor, action `order.payment_close.<tradeOrderUuid>`, fingerprint, reason, prior Invoice state and restricted result in the same transaction. Same actor/Store/command/payload replays the historical result without another channel call, including if a newer Invoice has since been created. Changed payload returns 409. Refresh current order state after replay. Employee command-result lookup requires `store:order:payment_close` and returns only safe identifiers; its `succeeded` means the command completed, not that payment was necessarily closed. Inspect the closure response and current state.

Unconfirmed/failed operations roll back local mutations and do not create a completed receipt. Provider effects cannot be rolled back, so implementations must query the original payment on retry rather than assume a rejected/timed-out close had no effect. The protected HTTP audit log retains channel requests/responses even when local changes roll back. Do not infer success from HTTP 200 from a provider, a locally failed Invoice, a timeout or a missing provider record.

## Huifu adapter and current limits

The Huifu adapter supports `native` and `jspay`, not quickpay or POS. It freshly queries **every** recorded attempt, including locally failed attempts, and verifies signature, success code, merchant, echoed identity and amount. Original payment status `F` is treated as terminal noncollection; `S` is returned for normal settlement, never closure. For `I`/`P`, it submits `/v2/trade/payment/scanpay/close` with a distinct close sequence and the original payment date/sequence, verifies the response, and queries the original payment again. Only final `F` permits release. An accepted close or still-processing query does not. Up to ten attempts are supported; no attempts, malformed evidence, missing final state and unsupported channels remain blocked. It does not reuse the refund-query contract.

Other historical Invoices are accepted only when they remain `cancelled` and have a matching successful channel-confirmed closure receipt for this Store/order. This allows normal close → new Invoice → close cycles without deleting history. Multiple-Invoice sources with an unproven older payment require separate reconciliation; local failed/cancelled status alone is not proof of provider closure. Do not delete history to bypass this guard. A future extension must verify every other potentially collectable historical Invoice before broadening this rule.

Tests use an alternative fake channel and signed Huifu fake responses. No production provider or live order is changed by these tests. Real merchant-product closure behavior, late notifications, MySQL concurrency and network timeouts require deployment-specific acceptance. This capability does not automatically close payments on a schedule, replace ordinary payment reconciliation, or repair all historical failure projections.
