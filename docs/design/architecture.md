# Architecture

## Layers and dependency direction

```text
business/admin router + config ──> integration/admin config injection ──> core admin
apps/admin-agent frontend ───────> integration/admin loader + host adapter ─> core admin
apps/admin-agent operation backend <── app-owned host mutation adapters
                 └──────────────> integration/backend explicit app wiring ─> core Symfony app
business/backend modules ───────> integration/backend Kernel ──────────> core Symfony app
                                           ▲
                              infra/docker Nginx + PHP-FPM
```

The diagram describes ownership, not a runtime network boundary: the frontend and backend are composed into one deployable application unless deployment needs later call for separation.

### `core/`

Contains the two upstream repositories as Git subtrees. Treat these directories as upstream-owned. Do not put product-specific modules here. Core changes should be generic, minimal, independently testable, and proposed upstream via a PR.

### `business/`

Owns all application-specific behavior, including frontend pages/components, menu and CRUD configuration, API clients, backend domain models, services, controllers, migrations, and business tests. Organize by business capability rather than by technical layer where practical. Backend modules should use a project namespace distinct from core's `App\\` namespace to avoid class collisions.

### `apps/`

Owns application packages. `apps/admin-agent/` is explicitly composed into the admin/API deployment and included in CI and Docker; other app directories remain excluded and are not automatically discovered. This establishes a frontend/backend ownership boundary, not an additional network service. General domain models and rules stay in business modules; agent-specific host bindings stay in the app's separate adapter directories and reuse those existing models and validation.

### `apps/admin-agent/`

Contains the optional assistant's generic UI, provider/documentation processing, and Symfony operation lifecycle. One app-owned `AgentOperation` entity serves all registered mutation adapters; it does not create one operation table per business entity. Host-specific frontend policies live in `adapters/ns-ultimate/admin/`; backend bindings live in `backend/src/Adapter/NsUltimate/`, separate from generic code. Integration explicitly selects adapters, registers the namespace, services, routes, Doctrine mapping and migrations, and supplies account identity. No agent implementation is added to business modules or core; adapters reuse existing entities and validation. See the [assistant design](admin-ai-assistant.md), [app guide](../../apps/admin-agent/README.md), and [operation contract](../contracts/admin-agent.md).

### `integration/`

Owns composition only. The admin side injects the business router and entity configuration at the core's `@/config` seam and optionally loads the independent agent through the integration-owned Vite plugin and session adapter. The backend Kernel discovers modules by the `business/backend/<PascalCaseSingular>/src/` convention and automatically wires their namespaces, services, routes, Doctrine mappings, and migrations when present. It must not become a home for domain rules.

### `infra/`

Owns project deployment assets. `infra/docker/` builds the composed frontend and backend and defines the project-level PHP-FPM, Nginx, MySQL, and Redis services; `infra/nginx/` routes `/admin/`, uploads, and backend requests to the integration front controller. These files do not modify or reuse the upstream core's Docker entrypoint. See the [Docker deployment foundation](../../infra/docker/README.md).

### `docs/`

Records layer boundaries, local development decisions, core compatibility, and synchronization procedures.

## Integration constraints discovered in upstream

- `crud-admin` is a Vite/Vue application whose router consumes `@/config`. `integration/admin/vite.config.ts` redirects only that exact specifier; all other `@/...` imports remain rooted in core. Business router and entity configuration are declared in `business/admin/`.
- `crud-skeleton` is a Symfony project. `integration/backend/src/Kernel.php` extends the core Kernel and preserves the core project directory. `ModuleRegistry` discovers business modules by directory convention; module service/route overrides stay inside the module. A sibling `business/backend/<name>/` without a `src/` directory is not a module.

The admin merge helpers and independent assistant have Node tests. The backend has a `Dummy` example module and an isolated SQLite integration test configuration. The example's API is under `/api/v1/manage/dummies`; its prior Note migration identity is retained to support a forward table rename on existing databases. GitHub Actions runs project workflow, assistant, and admin composition tests, integration/business PHPUnit tests, and the composed Vite build; it intentionally excludes upstream core test suites. Interactive business flows still require separate validation. Project-owned environment loading and local commands are described in [the development guide](../operations/development.md).

## Change policy

1. New business behavior belongs in `business/`.
2. Wiring belongs in `integration/`.
3. Only generic capabilities belong in core.
4. Keep license notices and upstream attribution intact.
5. Test core updates against integration and business modules before deployment.
