# Authorization Setup Guide

This guide shows how to configure the **Authorization** module (`src/Authorization/`, independent RBAC) introduced in Phase 1. It complements [`design/bundles/authorization.md`](../design/bundles/authorization.md) and the runbook [`runbooks/authorization.md`](../runbooks/authorization.md).

## 1. Prerequisites

- Migrations applied: `authorization_*` tables + `common_content.metadata` (no `store_uuid`).
- An admin user with `ROLE_ADMIN` (required for `/api/v1/manage/*`).
- The module is autowired via `src/Authorization/Resources/config/services_authorization.yaml` (imported by `config/services.yaml`) and routed via `config/routes.yaml` (`/api/v1`).

Verify wiring:

```bash
php bin/console debug:router | grep -E "manage-(roles|permissions|assignments|audit-logs)|app-authorization"
php bin/console lint:container --env=dev
```

If routes are missing, ensure the Docker image was rebuilt after the migration.

## 2. Seed System Data

System permissions, roles, and field grants are **not created via HTTP**. They are reconciled by a versioned seed command:

```bash
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:authorization:seed
# Docker:
docker compose exec app php bin/console app:authorization:seed
```

The command is **idempotent**: missing system records are created, changed names are updated, incompatible `scopeType` or missing permission codes cause a non-zero exit. It reports `created/updated` counts and `Field grants: x created, y updated`.

What it seeds (11 permissions / 3 roles / 4 field grants):

- Permissions: `authorization:role:manage`, `authorization:assignment:manage`, `common:content:{read,create,update,delete}`, `store:order:{read,accept,reject,fulfill}`, `wallet:voucher:manual`.
- Roles: `store_content_editor` (store, content CRUD without `metadata`), `store_content_metadata_editor` (store, content CRUD with `metadata`), `authorization_administrator` (global).
- Field grants (`common:content`): `store_content_editor` → `title,body,category,tags` on `create/update`; `store_content_metadata_editor` → same + `metadata`.

Re-run the command after each deployment; do not edit system roles via API beyond what is allowed (see §4–§6).

## 3. Permission Catalogue (Read-Only)

Permissions are a **stable code catalogue**. Manage API is read-only:

```
GET /api/v1/manage/permissions              # list
GET /api/v1/manage/permissions/{id}         # detail
```

Creating, updating, or deleting permissions via HTTP is intentionally unsupported. If a new permission is needed, add it to `SeedAuthorizationCommand::getPermissionsData()` and re-run the seed.

List example:

```bash
curl -s http://localhost:8080/api/v1/manage/permissions \
  -H "Authorization: Bearer <admin-token>" | jq .
```

## 4. Roles

```
GET    /api/v1/manage/roles                 # list
GET    /api/v1/manage/roles/{id}            # detail (id = int or uuid)
POST   /api/v1/manage/roles                 # create non-system role
PUT    /api/v1/manage/roles/{id}            # rename/update non-system role
DELETE /api/v1/manage/roles/{id}            # delete non-system role (system roles → 403)
POST   /api/v1/manage/roles/{uuid}/permissions                              # replace permissions (non-system only, 403 on system)
PUT    /api/v1/manage/roles/{uuid}/field-grants/{resource}/{action}         # replace field grant (non-system: registry-validated; system → 403)
```

Create fields: `code` (`[a-z0-9_]+`), `name`, `scopeType` (`global|store`). `isSystem` cannot be set via API.

```bash
# Create a custom store role
curl -s -X POST http://localhost:8080/api/v1/manage/roles \
  -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"code":"custom_store_auditor","name":"Custom Store Auditor","scopeType":"store"}'

# Assign permissions (must be existing codes)
curl -s -X POST http://localhost:8080/api/v1/manage/roles/<role-uuid>/permissions \
  -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"permissions":["common:content:read","store:order:read"]}'

# Allow only title/body on update
curl -s -X PUT http://localhost:8080/api/v1/manage/roles/<role-uuid>/field-grants/common:content/update \
  -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"fields":["title","body"]}'
```

System roles (`store_content_editor`, `store_content_metadata_editor`, `authorization_administrator`) are protected: `PUT /roles/{id}`, `POST …/permissions`, `PUT …/field-grants`, and `DELETE` all return **403**.

## 5. Assignments (User → Role → Scope)

Assignments use the standard View mixins, so list/detail/create/update/delete are all routed:

```
GET    /api/v1/manage/assignments?userUuid=&scopeType=&scopeUuid=&includeRevoked=0   # list (filters share ApiView mixins)
GET    /api/v1/manage/assignments/{id}                                                # detail
POST   /api/v1/manage/assignments                                                     # grant (idempotent)
PUT    /api/v1/manage/assignments/{id}                                                # update user/role/scope with re-validation
DELETE /api/v1/manage/assignments/{id}                                                # revoke (soft, revokedAt; already revoked → 204)
```

Grant payload (single object or array for batch):

```json
{
  "userUuid": "550e8400-e29b-41d4-a716-446655440000",
  "roleUuid": "<role-uuid-or-id-or-code>",
  "scopeType": "store",
  "scopeUuid": "<store-uuid>"
}
```

Rules:

- `scopeType` must match `role.scopeType` (`global` ↔ `global`, `store` ↔ `store`); otherwise **400**.
- `global` requires `scopeUuid = null/empty`; `store` requires a valid UUID.
- Idempotent: active duplicate returns the existing assignment (no duplicate row).
- Revoked rows are **reactivated** on re-grant (same `user/role/scope` unique key `scope_key`).
- `roleUuid` accepts **uuid, integer id, or code** for ergonomics.

Examples:

```bash
# Grant store_content_editor at Store X to userA
curl -s -X POST http://localhost:8080/api/v1/manage/assignments \
  -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"userUuid":"<userA-uuid>","roleUuid":"store_content_editor","scopeType":"store","scopeUuid":"<storeX-uuid>"}'

# Revoke
curl -s -X DELETE http://localhost:8080/api/v1/manage/assignments/<assignment-uuid> \
  -H "Authorization: Bearer <admin-token>"

# Update scope (e.g., move from Store X to Store Y, re-validated and deduped)
curl -s -X PUT http://localhost:8080/api/v1/manage/assignments/<assignment-uuid> \
  -H "Authorization: Bearer <admin-token>" -H "Content-Type: application/json" \
  -d '{"scopeUuid":"<storeY-uuid>"}'
```

Query params: `userUuid`, `scopeType`, `scopeUuid`; by default only active (`revokedAt IS NULL`) are listed, set `includeRevoked=1` to include revoked.

## 6. Field Grants

Field grants narrow an **already-authorized** action; they never widen the controller's static `acceptedCreateProperties`/`acceptedUpdateProperties`.

- Valid fields per resource are declared in `AuthorizationResourceRegistry` (`common:content` → `create/update` → `title,body,category,tags,metadata`).
- Without a grant, a permitted action exposes **no writable fields** (fail-closed).
- Strict denial: any accepted field outside the effective grant returns **403** with no partial write.

Manage via `PUT /manage/roles/{uuid}/field-grants/{resource}/{action}` as shown above. System roles reject this path with **403** — change them only through the seed.

## 7. Self-Service Check

```bash
curl -s http://localhost:8080/api/v1/app/authorization/me \
  -H "Authorization: Bearer <user-token>" | jq .
# → { data: { permissions: [...], storeScopes: { "common:content:update": ["<storeX-uuid>"] }, fieldGrants: { "common:content:update": ["title","body",...] } } }
```

This is **UI-only**; the server remains authoritative.

## 8. Field-Grant Pilot (End-to-End, `common:content` `metadata`)

Content is **not** Store-scoped in this phase (no `store_uuid`). The Store association was removed; only `metadata` remains as the field that distinguishes `store_content_editor` (no `metadata`) from `store_content_metadata_editor` (with `metadata`). Field enforcement is via `FieldAuthorizationService`; the example below uses direct service evaluation — the same grant would be enforced by any future controller that composes `FieldAuthorizationService` (the previous `POST /store/{storeUuid}/contents` pilot has been removed).

```php
// In a controller/service that handles Content create/update:
$filtered = $fieldAuth->filterWritableFields(
    $user, 'common:content', 'update',
    ['title' => 'Try', 'metadata' => ['foo' => 'bar']],
    ['title','body','category','tags','metadata'],
    AuthorizationScope::store($storeXUuid) // store scope still matters for the assignment
);
// store_content_editor → throws AccessDenied (403), no mutation
// store_content_metadata_editor → returns filtered array, proceeds to update
```

Manage Content itself (`/api/v1/manage/contents`) remains `ROLE_ADMIN` and now accepts `metadata` — it is not yet gated by Authorization but demonstrates the whitelisted field.

Verify via `/app/authorization/me`: `permissions` contains `common:content:update`, `fieldGrants["common:content:update"]` differs by role.

## 9. Seed After Schema Change & Troubleshooting

| Symptom | Cause | Fix |
|---------|-------|-----|
| `System role field grants cannot be modified via API` (403) | Attempt to modify system role via HTTP | Change seed data and re-run `app:authorization:seed` |
| `System role cannot be deleted` (403) | Delete of system role | System roles are code-owned; remove via migration/seed only |
| `Role scope ... incompatible` (400) | `role.scopeType` ≠ `assignment.scopeType` | Use a role whose scope matches the assignment |
| Duplicate global assignment rejected | `UNIQUE(user_uuid,role_id,scope_type,scope_key)` with `scope_key=''` | Expected — revoked rows are reactivated, not duplicated |
| `/manage/permissions 404` on POST | Permission write disabled by design | Use the seed to add permissions |
| No effect after grant/revoke | `cache.app` stale before invalidation | Mutations evict `authorization_effective_*`; if running multiple workers, ensure shared cache (Redis) or wait ≤5 min fallback |

See also: `design/bundles/authorization.md` (contract), `runbooks/authorization.md` (operational details), `tests/Integration/Authorization/AuthorizationContentPilotTest.php` (reference flow).
