# Core and Business Integration Proposal

## Status and scope

The agreed direction is a thin integration layer: the admin frontend needs only business router and configuration injection; the backend discovers business modules by directory convention. The implementation is present and the frontend build, Symfony console/route loading, and integration suites have been validated locally. The complete admin runtime and deployment behavior remain outside this validation.

The two cores are already imported as Git subtrees. This work should leave their source unchanged where possible. It does not include dependency upgrades, an admin plugin framework, a general plugin system, or a separate integration service. Backend modules are discovered by a fixed directory convention.

See [the architecture guide](../design/architecture.md) for layer ownership and [the subtree workflow](../operations/core-sync.md) for core synchronization.

## Proposed layout

```text
integration/
  admin/
    config.ts
    vite.config.ts
  backend/
    bootstrap.php
    bin/console
    public/index.php
    src/Kernel.php
    config/services.yaml
    phpunit.xml
    tests/bootstrap.php
business/
  admin/
    router.js
    config/index.js
    config/collections/
    i18n/
  backend/
    Dummy/
      src/
      config/
      migrations/
      legacy-migrations/ # preserved migration identities after module renames
      tests/
```

The initial implementation uses these paths. Admin code is not divided into backend-style modules. Custom admin components and pages remain in `business/admin/` and are referenced by business routes or configuration.

Implemented files include the exact `@/config` Vite alias, explicit route/entity merge operations and duplicate checks, and convention-based backend module discovery consumed by a project-owned Kernel. The `Dummy` vertical slice demonstrates the core's generic CRUD controller/service conventions without modifying either subtree; adding another module does not require editing `integration/`.

## Admin composition

### Two business inputs, one core seam

`business/admin/router.js` owns business menu and route definitions. Entity CRUD configuration follows the core collection layout under `business/admin/config/collections/`; locale dictionaries live under `business/admin/i18n/`. Neither area should contain application bootstrap or a module-discovery framework.

The current core exports `{ routes, entities }` through `src/config.js`. Its router consumes `@/config`, so the integration config can assemble both business inputs into that existing shape:

```text
core default routes and entities -----+
                                      |
business/admin/router.js ----------------------+--> integration/admin/config.ts --> core @/config consumers
business/admin/config/collections/* ----------+
```

Reuse the core's Vue bootstrap, layout, authentication, permission handling, and generic CRUD pages. Do not duplicate `main.js` or replace the core router merely to inject business behavior.

### Build-time injection

The integration Vite configuration should reuse the core configuration and resolve the exact `@/config` specifier to `integration/admin/config.ts` and `@/i18n` to a locale overlay that falls back to the core translator. Other `@/...` imports must continue to resolve to the core source tree. Resolve core defaults through a separate explicit path to avoid an import cycle through the injected alias.

Keep alias ordering explicit: the exact injection alias must take precedence over the broad core `@` alias. Restrict development filesystem access to required project directories. Verify dependency resolution for business files outside the core directory, environment loading, development proxy behavior, the production base path, and output locations. Do not copy business files into the subtree during build.

Use explicit lazy imports for custom business pages. The current core route generator scans only its own views; it must not be relied on to discover sibling business pages.

### Merge and override policy

Preserve core defaults unless business configuration explicitly replaces or removes them. The proposed default is to reject accidental duplicate entity keys, route names, and conflicting route paths rather than silently letting import order decide behavior.

Represent intended replacement/removal separately from additions. Define the concrete export format during implementation using the smallest data structure that supports those operations. For nested routes, make the target and replacement behavior explicit rather than performing an unrestricted recursive merge.

Route metadata and role filtering must remain compatible with the core permission/menu pipeline. Business routes should enter the existing dynamic route flow, not bypass it through unrelated `router.addRoute()` calls. Confirm that entity configuration resolves correctly for both generic CRUD routes and custom pages.

Frontend route visibility is not backend authorization. New business APIs must enforce authorization independently.

## Backend composition

### Module ownership and registration

Each `business/backend/<module>/` owns its domain code, controllers, services, configuration, migrations, and tests. Use a project-owned namespace such as `NsUltimate\Business\<Module>\`, distinct from the core's `App\` namespace. Integration code uses `NsUltimate\Integration\`. When renaming a deployed module, preserve historical migration class namespaces through an explicit legacy migration mapping and add a forward migration; do not rewrite a migration already recorded in a database.

Discover backend modules by the `business/backend/<PascalCaseSingular>/src/` convention (for example, `business/backend/Dummy/`). Keep service and optional route configuration inside each module; register Doctrine mappings and migrations when their conventional directories exist. Avoid scanning arbitrary repository paths. Do not require every module to be a Symfony bundle or invent a plugin registry.

### Kernel and entrypoints

Use a project-owned Kernel and HTTP/console entrypoints to load the core bundles and configuration, then integration and enabled business configuration. Configure runtime environment loading explicitly; do not accidentally combine core development defaults with production deployment values.

The first implementation should reuse the existing core Composer dependencies and lockfile where practical, with project-owned bootstrap/autoload registration for integration and business namespaces. Verify that application checks, tests, and runtime entrypoints all load these namespaces consistently. If dependency ownership later requires a host Composer manifest, treat it as a separate decision rather than duplicating manifests and lockfiles during initial integration.

### Paths and configuration order

Core configuration currently uses `%kernel.project_dir%` for its source tree, translations, migrations, and storage paths. Preserve the core project-directory semantics initially and explicitly configure business resource paths and project-owned runtime locations. Merely changing the project directory to the repository root would invalidate existing mappings.

Load core defaults before integration and business settings. Keep intentional overrides explicit and avoid changing unrelated security or service definitions. Symfony service defaults and `_instanceof` rules are configuration-file scoped; verify required controller injection, tags, and interface bindings for business services instead of assuming core rules carry across imports.

Register core and business Doctrine mappings with distinct namespaces. Register both core and business migration paths with distinct migration namespaces. Keep generated business migrations outside core and inspect schema differences so core tables are not accidentally dropped or recreated.

Preserve core routing and authorization behavior while adding business routes. Reject unintended route-name collisions. Configure cache, logs, and writable application artifacts outside the upstream-owned source trees where feasible; keep their ownership and deployment paths explicit.

## Validation and completion criteria

Validate the seam with one small vertical slice: a business menu/route and entity configuration on the admin side, backed by a business module with a service, authorized endpoint, and persisted entity. Keep the example business behavior in `business/`, not in integration adapters.

Admin validation should exercise additions, explicit replacements/removals, duplicate rejection, custom lazy-loaded pages, login, logout/reset, role-based route filtering, and generic CRUD configuration. Run the affected frontend checks and verify both development and production builds.

Backend validation should exercise HTTP and console bootstrap, service injection, module route registration, entity discovery, core plus business migration discovery, and authorized/unauthorized requests. Use an isolated test database; do not apply migrations to an existing shared database as part of validation.

Completion requires unchanged core source, working core and business behavior through the integration entrypoints, and documented validated interfaces in `docs/contracts/`. If the no-core-change approach fails because a generic extension point is missing, document the exact limitation and propose a narrow upstream change before introducing product-specific core edits.

Validation completed locally: admin composition tests (7), core admin tests (1,361), frontend type-check and production build, backend integration tests (6 tests / 13 assertions), workflow/env tests, Symfony console and route loading, local dev/proxy startup and shutdown, and documentation links. The production build reports existing CSS/minification and large-chunk warnings. Open follow-up findings are recorded in `docs/reviews/2026-10-09-integration-follow-up.md`; interactive admin workflows and external service integrations remain unverified.

## Documentation impact

After implementation, update `docs/design/architecture.md` and the layer README files to reflect the actual admin configuration composition and backend module registration. Promote validated registration and override formats into `docs/contracts/`; do not treat this proposal as a stable API contract. Record execution outcomes only when a session note is useful or requested.
