# Admin composition contract

**Status: Composition tests and Vite production build validated locally.**

## Inputs

- `business/admin/router.js` exports `{ add, replace, remove }`.
- `business/admin/config/collections/` follows the core collection layout. Each entity file (for example, `note/Note.js`) default-exports an entity-name-to-CRUD-config object; `business/admin/config/index.js` eagerly collects these files and exports `{ add, replace, remove }`.
- `business/admin/i18n/` contains one translation resource per supported locale. `integration/admin/i18n.js` overlays business keys on the core translator while preserving core locale selection and fallback behavior.
- `integration/admin/config.ts` supplies the core's `{ routes, entities }` shape to the exact `@/config` import. The Vite config also redirects only `@/i18n` to the translation overlay.

## Rules

The composed Vite config reads environment defaults and overrides from `integration/admin/`, including env used by the inherited proxy/define configuration. Core env files are not loaded. See [environment management](../operations/development.md).

- Routes are added as top-level menu groups. A replacement must name an existing core top-level route and preserve its name. A removal names an existing core top-level route. Replacements are whole-group replacements; nested route merging is not implicit.
- Duplicate route names and normalized paths are rejected, including duplicates inside nested routes. Dynamic parameter names normalize to the same path segment for collision detection.
- Entity additions may not shadow core names. Replacement and removal of core entity keys must be explicit. Unknown replacement/removal keys are errors.
- Core configuration is not mutated. Authentication, permission filtering, layout, and generic CRUD behavior remain owned by core.
- Custom business pages must be imported explicitly from business routes; core view auto-discovery does not scan the business directory.

Run the dependency-free composition tests with `node --test integration/admin/*.test.mjs`. Build the composed application with `make build`; interactive business flows require separate runtime validation.
