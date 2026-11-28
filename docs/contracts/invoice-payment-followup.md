# Invoice payment follow-up

## Collection without order settlement

After a verified payment is accepted by Core, the Invoice remains financially
`paid` even when its collection does not settle the Trade order. The first
eligible collection still settles the order and owns its `invoiceId` link.
Another paid Invoice does not replace that link or run the order payment
transition again.

The affected Invoice stores an audit record in `extraData.paid_followup`:

```json
{
  "status": "paid_followup_failed",
  "reason": "duplicate_collection",
  "orderUuid": "<source-order-uuid>",
  "orderStatus": "paid",
  "linkedInvoiceUuid": "<settling-invoice-uuid>",
  "recordedAt": "<ISO-8601 timestamp>"
}
```

Other reasons include `source_order_not_payable`, `source_order_missing`,
`source_order_payment_mismatch`, and `settled_invoice_missing`. For a merged
Counter source, `orderStatus` is `merged` and `mergedIntoOrderUuid` identifies
the destination from the recorded merge relation. That destination is not
credited by the collection. The marker is written in the existing payment
transaction; it is not a new Invoice workflow status, and it does not cover
arbitrary exceptions that cause rollback. Repeated accepted success callbacks
do not append duplicate records. Payment acceptance rules for locally failed
or cancelled Invoices are unchanged.

## Refund

Use the existing administrator endpoint
`POST /api/v1/manage/invoices/{id}/refund` with the affected Invoice numeric ID,
refund amount and reason. Gateway capability, refundable balance, adjustment
rules and refund idempotency still apply.

- A paid extra Invoice for an order settled by a different Invoice can be
  refunded independently, including historical duplicates already paid before
  this marker was introduced.
- A paid Invoice recorded as unapplied because the source order could not
  transition to paid (including a merged order) can be refunded independently
  when no payment settled that order. Neither the source order nor any merge
  destination is modified.
- Only a refund of the actual settling Invoice can update the Order. Missing
  or ambiguous associations and other exception reasons do not bypass the
  existing refund policy.

No automatic refund, reassignment, or reconciliation is performed. The original
follow-up record remains after refund; the Invoice's financial status and
refund history show the resolution. The public checkout projection does not
expose the follow-up record. The existing restriction on merging orders
with a linked Invoice is unchanged.
