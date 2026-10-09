# Payment Runbook

This runbook is the operational guide for configuring and operating the Payment
module (invoices, gateways, webhooks, adjustments). It complements
[`design/bundles/payment.md`](../design/bundles/payment.md).

## 1. Concepts In One Minute

- An **Invoice** is the unit of payment, with a state machine
  (`pending → paying → paid`, or `failed`/`cancelled`/`refunded`).
- A **gateway** (`mock`, `wallet`, `wechat`) executes the actual payment.
- **Webhooks** (`/api/payment/notify/{payment}`) deliver provider callbacks.
- **Adjustment providers** reduce the amount a gateway must handle (e.g. wallet balance).

## 2. Configuration

**File**: `config/packages/payment.yaml`

```yaml
payment:
    default_currency: 'CNY'
    system_wallet_id: 0
    gateways:
        mock:
            enabled: true
        wallet:
            enabled: true
    adjustments:
        wallet_balance:
            enabled: true
```

Sensitive provider values (merchant IDs, keys, notify URLs) MUST come from environment
variables and MUST NOT be committed.

## 3. Gateways

| Gateway | Purpose | Enabled by |
|---------|---------|-----------|
| `mock` | Local testing | `payment.gateways.mock.enabled` |
| `wallet` | Pay from wallet balance | `payment.gateways.wallet.enabled` |
| `wechat` | WeChat Pay V3 | `payment.gateways.wechat.enabled` + WeChat env vars |

Gateways are auto-discovered via the `payment.gateway` tag. To add a gateway, implement
`PaymentGatewayInterface` and register it; no manual wiring is needed.

## 4. Payment Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/invoices` | List user's invoices |
| POST | `/api/v1/app/invoices/{id}/pay/{payment}` | Pay invoice via gateway |
| GET | `/api/v1/manage/invoices` | Admin list invoices |
| POST | `/api/v1/manage/invoices` | Create invoice |
| POST | `/api/v1/manage/invoices/{id}/pay/{payment}` | Admin pay invoice |
| POST | `/api/v1/manage/invoices/{id}/cancel` | Cancel unpaid invoice |
| POST | `/api/v1/manage/invoices/{id}/refund` | Refund paid invoice |
| POST | `/api/payment/notify/{payment}` | Provider callback (webhook) |

## 5. Webhook Setup

1. Configure the provider's notify URL to point at
   `https://<host>/api/payment/notify/{payment}`.
2. Ensure the endpoint is reachable from the provider (no auth on the webhook).
3. Verify the provider signs the callback and the gateway validates the signature.

## 6. Wallet Balance Adjustment

When `payment.adjustments.wallet_balance.enabled=true`, the wallet balance is deducted
first; the gateway only handles the remaining invoice amount. The `system_wallet_id`
must point to a valid system wallet.

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Gateway not found | Gateway disabled or not registered | Check `payment.gateways.*.enabled` |
| Webhook ignored | Signature mismatch or wrong notify URL | Verify provider config and signature |
| Amount mismatch | Adjustment double-applied | Check `PaymentDeduction` audit records |
| Refund rejected | Invoice not in refundable state | Check invoice state machine |

## 8. Checklist Before Going Live

- [ ] `default_currency` and `system_wallet_id` set.
- [ ] Required gateways enabled.
- [ ] Provider notify URL configured and reachable.
- [ ] Sensitive provider values in env, not committed.
- [ ] Adjustment providers enabled as intended.
