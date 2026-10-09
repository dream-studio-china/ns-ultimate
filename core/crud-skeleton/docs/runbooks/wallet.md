# Wallet Runbook

This runbook is the operational guide for configuring and operating the Wallet
module (balances, transfers, vouchers, reconciliation). It complements
[`design/bundles/wallet.md`](../design/bundles/wallet.md).

## 1. Concepts In One Minute

- A **Wallet** holds a balance per currency (cents) for a user.
- **Transactions** record deposits, withdrawals, transfers, fees, refunds, and adjustments.
- **Vouchers** back single-sided deposits/withdrawals and are provider-permissioned.
- **Invariant**: `SUM(all wallet balances) == SUM(all deposit + adjustment transactions)`.

## 2. Unit Of Account

`currency` is the account discriminator. A plain ISO code (`CNY`, `USD`) is the default
balance wallet; extended codes (`CNY.ESCROW`, `CNY.COMMISSION`, `POINTS`) are category
accounts. The default balance wallet MUST use the plain ISO code — invoice payment
resolution relies on this.

## 3. Wallet Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET/POST/PUT/DELETE | `/api/v1/manage/wallets[/{id}]` | Wallet CRUD |
| GET | `/api/v1/manage/wallets/balance` | Verify accounting invariant |
| POST | `/api/v1/manage/wallets/reconcile` | Per-wallet reconciliation |
| GET/POST | `/api/v1/manage/transactions` | List / atomic transfer |
| POST | `/api/v1/manage/vouchers/deposit` | Voucher-backed deposit |
| POST | `/api/v1/manage/vouchers/withdraw` | Voucher-backed withdrawal |
| POST | `/api/v1/manage/vouchers/{uuid}/reverse` | Reverse a voucher |
| POST | `/api/v1/app/vouchers/deposit` | Self-service deposit |
| POST | `/api/v1/app/vouchers/withdraw` | Self-service withdrawal |

## 4. Voucher Types And Permissions

`voucherType` is a request parameter on deposit/withdraw. `Manage` defaults to `manual`
(admin-only); `App` requires a type supplied by the external integration. Permission is
enforced by the registered provider's `assertPermitted()`.

| Type | Direction | Meaning |
|------|-----------|---------|
| `manual` | deposit/withdraw | Admin-only manual funding |
| `settlement` | deposit | Internal settlement voucher (via Settlement port) |
| `invoice` | deposit | Invoice-backed deposit |

## 5. Reconciliation

1. Run `GET /api/v1/manage/wallets/balance` to verify the global invariant.
2. If a gap exists, run `POST /api/v1/manage/wallets/reconcile` per wallet to fix it.
3. Review the resulting transactions for audit.

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Transfer fails | Same wallet, frozen wallet, or insufficient funds | Check wallet state and balance |
| Voucher rejected | Provider permission denied | Check `voucherType` and provider `assertPermitted()` |
| Balance mismatch | Missing/duplicate transaction | Run reconciliation |
| Invoice payment fails | No default balance wallet for currency | Ensure plain ISO wallet exists |

## 7. Checklist Before Going Live

- [ ] System wallet exists and is referenced by Payment config.
- [ ] Voucher providers registered and permissioned.
- [ ] Reconciliation endpoint reachable by ops.
- [ ] Unit-of-account codes follow the plain-ISO convention.
