# Architecture

## Layers and dependency direction

```text
business/admin router + config ──> integration/admin config injection ──> core admin
business/backend modules ───────> integration/backend Kernel ──────────> core Symfony app
```

The diagram describes ownership, not a runtime network boundary: the frontend and backend are composed into one deployable application unless deployment needs later call for separation.

### `core/`

Contains the two upstream repositories as Git subtrees. Treat these directories as upstream-owned. Do not put product-specific modules here. Core changes should be generic, minimal, independently testable, and proposed upstream via a PR.

### `business/`

Owns all application-specific behavior, including frontend pages/components, menu and CRUD configuration, API clients, backend domain models, services, controllers, migrations, and business tests. Organize by business capability rather than by technical layer where practical. Backend modules should use a project namespace distinct from core's `App\\` namespace to avoid class collisions.

### `integration/`

Owns composition only. The admin side injects the business router and entity configuration at the core's `@/config` seam. The backend Kernel discovers modules by the `business/backend/<PascalCaseSingular>/src/` convention and automatically wires their namespaces, services, routes, Doctrine mappings, and migrations when present. It must not become a home for domain rules.

### `docs/`

Records layer boundaries, local development decisions, core compatibility, and synchronization procedures.

## Integration constraints discovered in upstream

- `crud-admin` is a Vite/Vue application whose router consumes `@/config`. `integration/admin/vite.config.ts` redirects only that exact specifier; all other `@/...` imports remain rooted in core. Business router and entity configuration are declared in `business/admin/`.
- `crud-skeleton` is a Symfony project. `integration/backend/src/Kernel.php` extends the core Kernel and preserves the core project directory. `ModuleRegistry` discovers business modules by directory convention; module service/route overrides stay inside the module. A sibling `business/backend/<name>/` without a `src/` directory is not a module.

The admin merge helpers have standalone Node tests. The backend has a `Note` example module and an isolated SQLite integration test configuration. The composed Vite build, Symfony console/routes, and integration tests have been validated locally after installing core dependencies. Interactive business flows still require separate validation. Project-owned environment loading and local commands are described in [the development guide](../operations/development.md).

## Change policy

1. New business behavior belongs in `business/`.
2. Wiring belongs in `integration/`.
3. Only generic capabilities belong in core.
4. Keep license notices and upstream attribution intact.
5. Test core updates against integration and business modules before deployment.
