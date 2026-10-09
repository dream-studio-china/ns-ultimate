# Business layer

All application-specific frontend and backend code belongs under this directory. Admin routes are defined in `admin/router.js`, entity configuration follows the core collection layout under `admin/config/collections/`, and translations live under `admin/i18n/`. Backend capabilities use singular PascalCase directories under `backend/<Module>/`, each owning its source, optional config, migrations, and tests. `backend/Note/` is a minimal integration example.

Do not make business modules depend on implementation details inside `core/` where a stable interface or `integration/` adapter can be used. Backend business code should have a project-owned namespace rather than adding classes to the core's `App\\` namespace.
