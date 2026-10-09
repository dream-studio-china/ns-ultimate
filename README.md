# ns-ultimate

Integrated application built from two upstream cores and project-owned business modules.

## Repository layout

- `core/crud-admin/` — Vue 3 admin frontend, tracked as a Git subtree of `immane/crud-admin`.
- `core/crud-skeleton/` — Symfony backend, tracked as a Git subtree of `immane/crud-skeleton`.
- `integration/` — project-owned application wiring and adapters between core and business modules.
- `business/` — all project-specific frontend and backend business functionality.
- `docs/` — architecture, development boundaries, and upstream synchronization guidance.

Keep upstream code intact where possible. Put product behavior in `business/`, and keep `integration/` focused on registration and adaptation. If a reusable extension point is missing, make the smallest generic change in the relevant core and submit it upstream via a branch and PR.

See [docs/architecture.md](docs/architecture.md) and [docs/core-sync.md](docs/core-sync.md) before adding modules or updating a subtree.

## Upstream licenses

The admin core is MIT-licensed. The backend core currently identifies itself as Apache-2.0 in its `LICENSE` and `composer.json`. Preserve each core's license and notices when redistributing it.
