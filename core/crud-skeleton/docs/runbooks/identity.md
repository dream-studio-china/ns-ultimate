# Identity Runbook

This runbook is the operational guide for configuring and operating the Identity
module (authentication, OTP, JWT, profiles). It complements
[`design/bundles/identity.md`](../design/bundles/identity.md).

## 1. Concepts In One Minute

- **JWT access tokens** (RS256) with a configurable TTL.
- **Refresh tokens** stored hashed in MySQL, rotated on use, with reuse detection.
- **OTP login** delivered via Alibaba Cloud SMS.
- **Password registration** as a self-service onboarding path.
- **Profiles** are auto-created 1:1 with users; points are delegated to Wallet.

## 2. Environment Variables

| Variable | Purpose | Default |
|----------|---------|---------|
| `JWT_PRIVATE_KEY_PATH` | RS256 private key path | — |
| `JWT_PUBLIC_KEY_PATH` | RS256 public key path | — |
| `JWT_PASSPHRASE` | Private key passphrase | — |
| `ACCESS_TOKEN_TTL` | Access token TTL (seconds) | `7200` |
| `REFRESH_TOKEN_TTL` | Refresh token TTL (seconds) | `31536000` |
| `REFRESH_TOKEN_SECRET` | HMAC secret for refresh token hashing | — |
| `OTP_TTL` | OTP validity (seconds) | `300` |
| `OTP_REDIS_DSN` | Redis DSN for OTP storage | — |
| `ALIYUN_ACCESS_KEY_ID` | Alibaba Cloud access key | — |
| `ALIYUN_ACCESS_KEY_SECRET` | Alibaba Cloud secret | — |
| `ALIYUN_SMS_SIGN_NAME` | SMS sign name | — |
| `ALIYUN_SMS_TEMPLATE_LOGIN_OTP` | Login OTP template | — |
| `ALIYUN_SMS_TEMPLATE_VERIFY_PHONE` | Verify-phone template | — |
| `ALIYUN_SMS_DRY_RUN` | Dry-run flag (dev) | `false` |

## 3. Generate JWT Keys

```bash
openssl genrsa -out config/jwt/private.pem -aes256 4096
openssl rsa -pubout -in config/jwt/private.pem -out config/jwt/public.pem
```

Set `JWT_PRIVATE_KEY_PATH`, `JWT_PUBLIC_KEY_PATH`, and `JWT_PASSPHRASE` to match.

## 4. Configure OTP (Alibaba Cloud SMS)

1. Set `ALIYUN_ACCESS_KEY_ID` and `ALIYUN_ACCESS_KEY_SECRET`.
2. Set `ALIYUN_SMS_SIGN_NAME` to your approved sign name.
3. Set `ALIYUN_SMS_TEMPLATE_LOGIN_OTP` and `ALIYUN_SMS_TEMPLATE_VERIFY_PHONE` to your
   approved template codes.
4. Set `OTP_REDIS_DSN` to a reachable Redis.
5. In development, set `ALIYUN_SMS_DRY_RUN=true` to avoid sending real SMS.

## 5. Auth Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | `/api/auth/register` | Password self-registration → tokens |
| POST | `/api/auth/login` | Identifier + password login |
| POST | `/api/auth/otp/request` | Request OTP via SMS |
| POST | `/api/auth/otp/verify` | Verify OTP |
| POST | `/api/auth/token/refresh` | Rotate refresh token |
| POST | `/api/auth/logout` | Revoke tokens |

## 6. Profile And User Management

| Method | Path | Description |
|--------|------|-------------|
| GET/PUT | `/api/v1/app/profiles` | Current user profile |
| GET/POST/PUT/DELETE | `/api/v1/manage/profiles[/{id}]` | Admin profile CRUD (incl. level) |
| GET/PUT | `/api/v1/app/users/me` | Current user |
| GET/POST/PUT/DELETE | `/api/v1/manage/users[/{id}]` | Admin user CRUD |
| POST | `/api/v1/manage/users/{id}/change-password` | Admin change password |

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| 401 on all requests | JWT keys not configured or mismatched | Regenerate keys, check paths/passphrase |
| OTP not delivered | Missing SMS config or dry-run | Check Aliyun vars, `ALIYUN_SMS_DRY_RUN` |
| Refresh token rejected | `REFRESH_TOKEN_SECRET` changed | Keep the secret stable across restarts |
| Redis errors | `OTP_REDIS_DSN` unreachable | Verify Redis connectivity |

## 8. Checklist Before Going Live

- [ ] JWT key pair generated and paths set.
- [ ] `REFRESH_TOKEN_SECRET` is a strong, stable secret.
- [ ] SMS templates approved and configured.
- [ ] Redis reachable for OTP.
- [ ] `ALIYUN_SMS_DRY_RUN=false` in production.
