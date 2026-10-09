# Architecture

## Layers and dependency direction

```text
business modules  ──┐
                    ├──> integration adapters / app composition ──> core APIs and extension points
core capabilities ──┘
```

The diagram describes ownership, not a runtime network boundary: the frontend and backend are composed into one deployable application unless deployment needs later call for separation.

### `core/`

Contains the two upstream repositories as Git subtrees. Treat these directories as upstream-owned. Do not put product-specific modules here. Core changes should be generic, minimal, independently testable, and proposed upstream via a PR.

### `business/`

Owns all application-specific behavior, including frontend pages/components, menu and CRUD configuration, API clients, backend domain models, services, controllers, migrations, and business tests. Organize by business capability rather than by technical layer where practical. Backend modules should use a project namespace distinct from core's `App\\` namespace to avoid class collisions.

### `integration/`

Owns composition and adapters only: frontend bootstrap/config merging and route registration; backend module/service/route/entity registration; and translations between core contracts and business interfaces. It must not become a home for domain rules.

### `docs/`

Records layer boundaries, local development decisions, core compatibility, and synchronization procedures.

## Integration constraints discovered in upstream

- `crud-admin` is a Vite/Vue application whose current `src/main.js` creates and mounts the Vue app directly. Its router consumes `@/config`. Business modules should be registered through a project-owned config/bootstrap seam; if that seam is insufficient, add a generic upstream extension point rather than carrying product-specific edits in core.
- `crud-skeleton` is a Symfony project. Its current service discovery, route resources, and Doctrine entity mapping are centered on the core project's `src/` tree. Integration must explicitly register project-owned business namespaces, service configuration, routes, and entity mappings. Do not assume simply creating a sibling `business/` directory makes Symfony discover it.

These are initial integration requirements, not a claim that composition is already implemented. Validate each extension point with a small end-to-end business module before migrating broader functionality.

## Change policy

1. New business behavior belongs in `business/`.
2. Wiring belongs in `integration/`.
3. Only generic capabilities belong in core.
4. Keep license notices and upstream attribution intact.
5. Test core updates against integration and business modules before deployment.
