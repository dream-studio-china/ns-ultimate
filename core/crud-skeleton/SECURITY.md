# Security Policy

Thank you for helping keep **CRUD Skeleton** secure.

If you discover a security vulnerability, **please do not open a public GitHub issue**. Instead, use **GitHub Private Vulnerability Reporting** to report the issue privately to the maintainers.

## Reporting a Vulnerability

Please include as much information as possible:

- A clear description of the vulnerability
- Steps to reproduce or a proof-of-concept (PoC)
- Affected versions or commit ranges
- Potential impact
- Any suggested mitigations (if available)

We aim to acknowledge reports within **48 hours** and will work with the reporter to investigate, resolve, and coordinate responsible disclosure where appropriate.

---

## Supported Versions

| Version | Status |
|---------|--------|
| `main` | Latest stable |
| `dev` | Active development |

Only the latest stable release receives security fixes. The `dev` branch may receive patches before they are merged into `main`.

---

## Security Model

CRUD Skeleton is a Symfony 8.1 API backend skeleton. Security-critical components:

### Authentication

- **JWT (RS256)**: Access tokens signed with RSA 2048-bit keys, 7200s TTL
- **Refresh tokens**: HMAC-SHA256, rotation with reuse detection
- **OTP login**: Phone-based via Alibaba Cloud SMS, rate-limited
- **WeChat login**: Mini Program (`js_code`) and Official Account OAuth

### Authorization

- Role-based: `ROLE_USER` (app endpoints) and `ROLE_ADMIN` (manage endpoints)
- `commonFilter()` pattern scopes data per-user at the query level
- Public paths are explicitly whitelisted in `config/packages/security.yaml`

### Data Protection

- Passwords hashed with Symfony's `auto` password hasher (bcrypt/argon2)
- Refresh tokens stored as HMAC-SHA256 hashes, never plaintext
- Money values stored in `bigint` cents to avoid floating-point issues
- OTP codes stored in Redis with TTL

### Wallet Security

- **Optimistic locking** (`#[ORM\Version]`) prevents race conditions
- **Deadlock prevention**: consistent wallet lock ordering in transfers
- **Idempotency**: `referenceId` unique constraint on transactions
- **Balance invariant**: `SUM(wallets) == SUM(deposits)` verified via audit endpoints

### Payment Gateway Security

- Gateways receive **explicit payment amounts** — they cannot modify invoice totals
- WeChat Pay V3 uses certificate-based signature verification for callbacks
- Payment adjustments are pluggable but scoped to the owning module

### File Upload Security

- File size limits enforced before persistence
- MIME type validation (see [security-hardening.md](docs/design/security-hardening.md) for planned improvements)
- Extension allow-list planned (deny executable types: `.php`, `.phtml`, etc.)
- PHP execution disabled in upload directory (planned `.htaccess` for `public/uploads/`)
- User-scoped media ownership via `commonFilter()`
- Public media endpoints only expose ownerless media (`user IS NULL`)
- Rate limiting on upload endpoints planned

### Dynamic Query Security

- `@dql`, `@sort`, `@hints` restricted to `ROLE_ADMIN`
- `@showDQL` restricted to `dev` environment only
- `@select` blocks Identity module entities and sensitive field paths
- `@filter` in-memory fallback restricted to `ROLE_ADMIN`
- TransformContent `Service` and `entity` bindings use identity-only proxies (only `getId()` exposed)

### Serialization Safety

- Serializer groups and `#[Ignore]` attributes planned for entities with sensitive getters
- `@expands` and `@display` field allow-lists planned to prevent traversal into sensitive data

---

## In Scope

The following components are **in scope**:

- `src/` — All application code
- `config/` — Symfony and package configuration
- `migrations/` — Doctrine schema migrations
- `public/` — Front controller and `.htaccess`
- `docker/` — Docker images and entrypoint scripts
- `compose.yaml` / `compose.prod.yaml` — Docker Compose configuration
- JWT implementation (`App\Identity\Security\JwtAuthenticator`, `TokenManager`)
- Payment gateway implementations (`WalletGateway`, `WechatPayGateway`, `MockGateway`)

---

## Out of Scope

The following are generally **out of scope**:

- Third-party services (Alibaba Cloud SMS, WeChat APIs, Qiniu Kodo)
- Docker base images (nginx, MySQL, Redis, Mailpit)
- Demo data or seed scripts
- Documentation typos (non-security)
- Social engineering or phishing attacks
- Physical access attacks
- Issues in PHP, Symfony, or Doctrine upstream

---

## Dependency Security

Dependencies are managed via Composer and monitored through GitHub Dependabot and the GitHub Advisory Database.

Critical upstream security advisories will be addressed as soon as practical, with a target response time of **7 days** whenever possible.

---

## Responsible Disclosure

Please allow reasonable time for a fix before publicly disclosing a vulnerability.

We appreciate responsible disclosure and, where appropriate, will acknowledge security researchers for their contributions unless anonymity is requested.
