# Core/business integration follow-up review

> Historical snapshot: this review describes the starter module before its rename from `Note` to `Dummy`. Its recorded `/notes` route and `business/backend/Note/` paths were superseded by the rename; see the current [backend module registration contract](../contracts/backend-module-registration.md).

**Date:** 2026-10-09
**Scope:** Project-owned integration and business code, plus its contracts and validation notes. Upstream core files were not reviewed as change targets.
**Status:** Follow-up findings remain open; this document records findings, not fixes.

## Summary

The follow-up review found that the previous fixes for Kernel configuration loading, service namespace discovery, Note route prefix composition, admin entity duplicate handling, PHPUnit test discovery, and module-directory cache invalidation are present. The route-name collision guard only partially closes the original route collision finding. Additional validation and contract gaps remain below.

## Open findings

### [P2] Route-name collision guard misses aliases and duplicates inside one imported collection

**Locations:** `integration/backend/src/RouteImportGuard.php:26-37,43-63`

The guard compares only `RouteCollection::all()` keys. Symfony stores route aliases separately, so an incoming alias can use the name of an existing route and replace it when the collection is merged. Also, the guard inspects the collection returned by the loader after its internal merges; duplicate names inside that imported collection have already been overwritten and are no longer observable.

The current PHPUnit test calls `assertNoCollisions()` directly with two ordinary route collections. It does not cover alias collisions, duplicates within a module's imported controller directory, or duplicate names inside nested route imports.

**Impact:** Conflicting route names can still silently replace existing routes despite the fail-fast contract. The guard also uses reflection to inspect Symfony configurator properties, which couples it to Symfony implementation details.

**Recommended follow-up:** Validate names and aliases before each collection merge, including names within an individual module import. Add tests for route/alias, alias/alias, same-import, and cross-module collisions. Prefer a supported extension point over reflecting protected configurator properties where practical.

### [P2] Attribute-only modules do not inherit the core API route prefix

**Locations:** `integration/backend/src/Kernel.php:115-122`; `docs/contracts/backend-module-registration.md:11`

The `Note` example gets `/api/v1/manage/notes` because its module-owned `config/routes.yaml` explicitly adds the `/api/v1` prefix. When a discovered module has no `config/routes.yaml`, the Kernel imports its controller attributes directly, with no default prefix. An attribute such as `#[Route('/manage/items')]` therefore does not automatically become `/api/v1/manage/items`.

**Impact:** The fallback registration path is inconsistent with the expected core API route convention and can produce endpoints outside the frontend's configured API prefix.

**Recommended follow-up:** Make the default prefix behavior explicit and consistent for every discovered module; test both modules with and without `config/routes.yaml`. Keep the prefix in integration routing configuration rather than hard-coding `/api/v1` into each controller attribute.

### [P2] Authorization coverage does not validate role boundaries or protected operations

**Location:** `business/backend/Note/tests/Integration/NotesAuthorizationTest.php:10-18`

The authorization test verifies only that an unauthenticated `GET` returns 401. It does not prove that an authenticated non-admin is denied, that an administrator can access the endpoint, or that create/update/delete operations enforce the intended authorization.

**Impact:** A regression that weakens role checks or protects only the list endpoint could pass the existing suite.

**Recommended follow-up:** Add deterministic tests for anonymous 401, authenticated non-admin 403, admin success, and representative protected write operations.

### [P3] Integration validation status in the proposal is stale

**Locations:** `docs/tasks/core-business-integration.md:5,113`; `docs/contracts/admin-composition.md:3`; `docs/contracts/backend-module-registration.md:3`

The docs still say dependencies are not installed and that runtime/build validation is pending. Subsequent recorded runs installed both dependency sets and passed the frontend type-check, admin production build, backend console boot, route inspection, and backend PHPUnit suite.

**Impact:** Readers cannot tell which contracts and integration paths have actually been validated.

**Recommended follow-up:** Update each status from actual verification evidence. Keep explicitly untested behavior (including the open findings above) marked as pending rather than describing the entire runtime as unverified.

## Validation evidence reviewed

The preceding implementation session recorded:

- Backend PHPUnit: 6 tests, 13 assertions, passed.
- Admin composition tests: 7 tests, passed.
- Frontend type-check and production build: passed; the build emitted existing CSS and large-chunk warnings.
- Symfony console boot and `business-notes-list` route inspection: passed; the route resolved to `/api/v1/manage/notes` through the module route prefix.
- The route guard's primitive route-to-route collision check rejects a duplicate. Read-only probes confirmed the alias and same-import gaps described above.

These checks do not close the open findings without the additional cases listed above.
