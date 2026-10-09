# WeChat Runbook

This runbook is the operational guide for configuring and operating the WeChat
module (Mini Program / Official Account login, WeChat Pay V3). It complements
[`design/bundles/wechat.md`](../design/bundles/wechat.md).

## 1. Concepts In One Minute

- **Mini Program login**: `js_code` → JWT.
- **Official Account login**: OAuth redirect + callback → JWT.
- **WeChat Pay V3**: a `PaymentGatewayInterface` implementation, auto-registered.
- **WechatUser**: OneToOne → User binding.

## 2. Environment Variables

| Variable | Purpose |
|----------|---------|
| `WECHAT_MINIAPP_APP_ID` | Mini Program app ID |
| `WECHAT_MINIAPP_SECRET` | Mini Program secret |
| `WECHAT_OFFICIAL_APP_ID` | Official Account app ID |
| `WECHAT_OFFICIAL_SECRET` | Official Account secret |
| `WECHAT_OFFICIAL_TOKEN` | Official Account token |
| `WECHAT_OFFICIAL_AES_KEY` | Official Account AES key |
| `WECHAT_PAY_MCH_ID` | WeChat Pay merchant ID |
| `WECHAT_PAY_SECRET_KEY` | WeChat Pay API v3 key |
| `WECHAT_PAY_PRIVATE_KEY` | Merchant private key path |
| `WECHAT_PAY_CERTIFICATE` | Merchant certificate path |
| `WECHAT_PAY_NOTIFY_URL` | Payment notify URL |

## 3. Configure Login

1. Set `WECHAT_MINIAPP_APP_ID` and `WECHAT_MINIAPP_SECRET` for Mini Program login.
2. Set `WECHAT_OFFICIAL_APP_ID`, `WECHAT_OFFICIAL_SECRET`, `WECHAT_OFFICIAL_TOKEN`,
   and `WECHAT_OFFICIAL_AES_KEY` for Official Account login.

## 4. Configure WeChat Pay V3

1. Set `WECHAT_PAY_MCH_ID`, `WECHAT_PAY_SECRET_KEY`, `WECHAT_PAY_PRIVATE_KEY`,
   `WECHAT_PAY_CERTIFICATE`.
2. Set `WECHAT_PAY_NOTIFY_URL` to `https://<host>/api/payment/notify/wechat`.
3. The gateway is auto-registered via the `payment.gateway` tag — no manual wiring.

## 5. Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/wechat/miniapp/login` | Mini Program login |
| POST | `/api/wechat/miniapp/phone` | Bind WeChat phone |
| GET | `/api/wechat/oauth/url` | Official Account OAuth redirect URL |
| POST | `/api/wechat/oauth/callback` | OAuth callback |
| GET | `/api/v1/app/wechat-users` | User-scoped WechatUser CRUD |
| GET | `/api/v1/manage/wechat-users` | Admin WechatUser CRUD |

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Login fails | Wrong app ID/secret | Verify WeChat credentials |
| Pay fails | Missing merchant cert/key | Check `WECHAT_PAY_*` vars |
| Notify ignored | Wrong notify URL or signature | Verify `WECHAT_PAY_NOTIFY_URL` |

## 7. Checklist Before Going Live

- [ ] Mini Program / Official Account credentials set.
- [ ] WeChat Pay merchant credentials set.
- [ ] Notify URL reachable.
- [ ] Sensitive values in env, not committed.
