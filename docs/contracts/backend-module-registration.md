# Backend module registration contract

**Status: Integration tests and Symfony console/route loading validated locally for the `Dummy` example.**

## Discovery convention

`integration/backend/src/ModuleRegistry.php` discovers immediate child directories of `business/backend/` that contain `src/`. A module is identified by its singular PascalCase directory name, for example `Dummy` or `MemberCenter`; adding a qualifying directory is sufficient to register it. No central module list or edit to `integration/` is required.

The directory name determines the `NsUltimate\Business\<Module>\` PSR-4 namespace, `Business<Module>` Doctrine alias, and `NsUltimate\Business\<Module>\Migrations` migration namespace. Module names must be singular PascalCase; colliding derived namespaces, aliases, and migration namespaces fail fast.

The bootstrap registers each discovered namespace against its `src/` directory. The integration Kernel autowires/autoconfigures classes under `src/`, excluding `src/Entity/` and `src/Migrations/`; REST controller setter injection is wired centrally. It removes business controllers from Symfony's broad `routing.controllers` service resource, then imports each module's `config/routes.yaml` independently so route-name collisions with core or other modules fail during route loading. Module route files own route prefixes (for example `/api/v1`) and may import controller attributes. If no route file exists, controller attributes are imported directly. Doctrine mappings are added when `src/Entity/` exists, and migrations are registered when `migrations/` exists. Module-specific overrides stay with the module.

## Ownership and security

The bootstrap reads project-owned env files under `integration/backend/`, not upstream core env files. Process environment overrides remain supported. Tests use project-owned test defaults and portable upstream test JWT fixtures, not development keys. See [environment management](../operations/development.md).

- Use the directory-derived namespace under `NsUltimate\Business\<Module>\`; use `NsUltimate\Integration\Backend\` for integration code.
- Routes and services stay within their business module. Admin API endpoints must enforce authorization on the backend; frontend role metadata is not an authorization boundary.
- Business entities and migration classes remain outside the core subtree. Preserve core project-directory semantics and use repository-level cache/log paths.
- The optional starter `Dummy` module demonstrates compatibility with the core `BaseService` and REST controller mixins. Its management routes require `ROLE_ADMIN`; the integration architecture does not depend on this module. Its historical Note migration identity is retained for database upgrade compatibility, and a follow-up migration renames the old example table without dropping rows.

Run backend checks using the core vendor dependencies and `integration/backend/phpunit.xml`. Tests use an isolated temporary SQLite database. Production database migration is outside the test workflow.
