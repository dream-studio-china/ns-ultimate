# Authorization Runbook

Operational guide for the **Authorization** module (`src/Authorization/`). For the design contract see [`design/bundles/authorization.md`](../design/bundles/authorization.md); for developer setup see [`manual/authorization.md`](../manual/authorization.md).

## 1. Concepts In One Minute

- **Permission**: stable code `module:resource:action` (e.g. `common:content:update`), seeded and read-only via API.
- **Role**: named set of permissions + optional field allow-lists; scoped `global|store`; system roles are code-owned (`isSystem=true`).
- **Assignment**: one user UUID → one role in one scope (`global` needs no `scopeUuid`, `store` needs a Store UUID). Active rows have `revokedAt IS NULL`; revoke is soft.
- **Field grant** (`authorization_role_field_grant`): JSON list of writable fields for `resource:action`, validated against `AuthorizationResourceRegistry`.
- **Audit log** (`authorization_audit_log`): append-only, written in the same transaction as the mutation.
- Enforcement: `AuthorizationVoter` (`security.voter`) + `AuthorizationService` (`cache.app`, `authorization_effective_*`, 300 s, DB fallback) + `FieldAuthorizationService` (strict 403). Store-scoped example `store:order:*` composes `Authorization` with active `Store Membership`.

## 2. Prerequisites

- Migrations: `Version20260901000000` (authorization tables) and `Version20260901000001` (`common_content.metadata` only). SQLite tests use `doctrine:schema:create` instead.
- `ROLE_ADMIN` caller for `/api/v1/manage/*`; future Store-scoped callers need `ROLE_USER` plus membership.
- No extra env vars; cache is `cache.app` (filesystem by default — switch to Redis for multi-worker invalidation, otherwise stale ≤5 min).

## 3. Deploy / Upgrade

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:authorization:seed --env=prod
# Docker:
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:authorization:seed
```

The seed is idempotent and must be run on every deploy. It creates 11 permissions, 3 system roles, and 4 field grants; incompatible system-role `scopeType` or unknown permission codes fail the command (do not ignore).

Verify after deploy:

```bash
php bin/console debug:router | grep -E "manage-(roles|permissions|assignments|audit)|app-authorization"
php bin/console doctrine:schema:validate --skip-sync
curl -s http://localhost:8080/api/v1/manage/permissions -H "Authorization: Bearer <admin-token>" | jq .data[0]
```

## 4. Daily Operations

### 4.1 Permissions (read-only)

```
GET /api/v1/manage/permissions
GET /api/v1/manage/permissions/{id}
```

Do not create permissions via HTTP. To add one, add to `SeedAuthorizationCommand::getPermissionsData()` and re-seed.

### 4.2 Roles

```
GET  /api/v1/manage/roles
GET  /api/v1/manage/roles/{id}
POST /api/v1/manage/roles                      # code [a-z0-9_]+, name, scopeType global|store
PUT  /api/v1/manage/roles/{id}                 # non-system only
DELETE /api/v1/manage/roles/{id}               # non-system only; system → 403
POST /api/v1/manage/roles/{uuid}/permissions   # replace (non-system only, body {permissions: [...]})
PUT  /api/v1/manage/roles/{uuid}/field-grants/{resource}/{action}  # replace (registry-validated; system → 403)
```

List/detail and writes after success all invalidate affected users via `AuthorizationCacheInvalidator` (role change → `findActiveUserUuidsByRole` → delete `authorization_effective_*`).

### 4.3 Assignments

```
GET    /api/v1/manage/assignments?userUuid=&scopeType=&scopeUuid=&includeRevoked=&page=&limit=
GET    /api/v1/manage/assignments/{id}
POST   /api/v1/manage/assignments            # {userUuid, roleUuid|roleId|code, scopeType, scopeUuid?} — batch: send array
PUT    /api/v1/manage/assignments/{id}       # partial; re-validates role↔scope and unique key; audited as assignment.updated
DELETE /api/v1/manage/assignments/{id}       # soft revoke → audit assignment.revoked, cache evict, repeated → 204
```

Notes: `roleUuid` accepts uuid/int/code; `scopeType` must match `role.scopeType`; duplicate active assignments are idempotent; revoked rows are reactivated (`revokedAt=null`) and re-audited; global rows store `scope_key=''` so `UNIQUE(user_uuid,role_id,scope_type,scope_key)` works portably (no NULL in unique).

### 4.4 Audit Logs

```
GET /api/v1/manage/audit-logs?targetType=&actorUuid=&page=&limit=
GET /api/v1/manage/audit-logs/{id}
```

Appended actions: `role.created`, `role.permissions.replaced`, `field_grant.replaced`, `assignment.granted`, `assignment.updated`, `assignment.revoked`. Contains UUIDs/codes only, never secrets.

### 4.5 Self-Service

```
GET /api/v1/app/authorization/me   # ROLE_USER — {permissions, storeScopes, fieldGrants}
```

### 4.6 Content Pilot (Field-Grant Only)

Content is **not** Store-scoped (no `store_uuid`). The pilot is the `metadata` field grant on `common:content` `create/update`: `store_content_editor` → `title,body,category,tags`; `store_content_metadata_editor` → same + `metadata`. Manage Content (`/api/v1/manage/contents`) remains `ROLE_ADMIN` but now accepts `metadata` as the whitelisted field that demonstrates `FieldAuthorizationService` strict denial (previous `POST /store/{storeUuid}/contents` routes have been removed).

## 5. Caching

- Key: `authorization_effective_{userUuid}` in `cache.app`, TTL 300 s.
- Writes evict synchronously after commit (single user on assignment grant/revoke/update; all users of a role on role/permission/field-grant change). If `cache.app` is filesystem in a multi-process deployment, run a shared cache (Redis) or tolerate short staleness.
- On cache failure the service falls back to DB.

## 6. Monitoring & Health

- No dedicated metrics yet; audit log rate and `DELETE …/assignments` error rate are the primary signals.
- Confirm routes and schema after upgrades (see §3).
- For Content pilot, confirm `store_content_editor` vs `metadata_editor` via `FieldAuthorizationService` unit/integration flow (previous Store Content flow has been removed; see `tests/Integration/Authorization/AuthorizationContentPilotTest.php` updated).

## 7. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| 403 on system role field-grant/replace | System roles are seed-owned | Change seed and re-run `app:authorization:seed` |
| 400 `Role scope "x" incompatible` | Role/assignment scope mismatch | Use a role with matching `scopeType` |
| 400 `scopeUuid must be null / Valid scopeUuid required` | Missing or extra scopeUuid | `global` → no uuid; `store` → valid Store UUID |
| 400 `permissions must be array` / `Invalid permission code` | Bad `POST …/permissions` body | Send `{permissions: ["common:content:read", ...]}` with existing codes |
| 403 on Content `metadata` | `store_content_editor` lacks `metadata` grant | Grant `store_content_metadata_editor` or custom role with `metadata` field grant |
| 403/404 cross-Store decision | Scope filter + membership (for Store resources like `store:order`) | Ensure assignment Store UUID matches route Store UUID and membership is active |
| No effect after revoke (multi-worker) | Filesystem `cache.app` not shared | Use Redis `cache.app` or wait for TTL |
| 404 on `GET /manage/assignments/{id}` after POST | Queried `id` vs `uuid` | Both `int` and `uuid` match `mixIdToCommonFilter`; ensure `revokedAt` filter not hiding it (`includeRevoked=1`) |

## 8. Checklist Before Going Live

- [ ] Migrations applied and `app:authorization:seed` executed on the target env.
- [ ] Admin token (`ROLE_ADMIN`) can list permissions/roles.
- [ ] Content field-grant pilot roles as expected (editor vs metadata_editor on `metadata`).
- [ ] An assignment grant → revoke cycle invalidates the user's effective permissions (checked via `/app/authorization/me` or field-grant evaluation).
- [ ] Audit logs appear for role/assignment mutations.
