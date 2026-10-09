# Integration layer

Project-owned application composition belongs here, not in either upstream subtree. Keep this layer limited to wiring; business behavior remains under `business/`.

Use the root Makefile for startup, debugging, and checks; see [local development](../docs/operations/development.md). Both composed applications load env files from their respective integration directories, never from core. Initialize local configuration with `make env-init`.

## Dependencies

From the repository root, run `npm install` to install both dependency sets using the core lockfiles: frontend packages go to `core/crud-admin/node_modules/`, and Composer packages go to `core/crud-skeleton/vendor/`. This requires Node.js/npm, PHP, and Composer. The root `package.json` only orchestrates installs; it does not duplicate either core's dependency declarations. Composer scripts are disabled during installation so the core app is not initialized accidentally.

## Admin

Run the core Vite scripts with `integration/admin/vite.config.ts` to redirect `@/config` to the integration config and `@/i18n` to an overlay preserving the core translator. Business menu routes are declared in `business/admin/router.js`; CRUD entity configs follow the core structure under `business/admin/config/collections/`, and translations live under `business/admin/i18n/`. Merge helpers reject accidental duplicate route names/paths and entity names; replacements and removals must be explicit.

From the repository root:

```sh
cd core/crud-admin
npm ci
npm run dev -- --config ../../integration/admin/vite.config.ts
```

Build with `npm run build -- --config ../../integration/admin/vite.config.ts`. Output is written to the repository-level `dist/admin/`, not into the core subtree. The independent admin composition tests can run without frontend dependencies using `node --test integration/admin/*.test.mjs` from the repository root.

## Backend

`integration/backend/bootstrap.php` loads the existing core Composer autoloader and project-owned environment, then discovers immediate PascalCase child directories under `business/backend/` that contain `src/`. The singular directory name determines the module PSR-4 namespace and integration aliases. The integration Kernel automatically registers module classes as Symfony services, controller routes, Doctrine entities, and migrations when their conventional directories exist. The Kernel inherits the core Kernel, uses the core directory for existing config semantics, and writes cache/log output under the repository-level `var/` directory.

The `Note` module under `business/backend/Note/` is a starter vertical slice, not a required integration module. It demonstrates a module-owned entity, service, admin-protected CRUD controller using core mixins, attribute routes, and a migration. New modules do not require editing `integration/`; add them under `business/backend/<PascalCaseSingular>/src/`. Optional `config/services.yaml` and `config/routes.yaml` files remain module-owned overrides. Integration registers module controller attributes independently of core's `routing.controllers` resource so duplicate route names are rejected; route prefixes remain module-owned.

Install Composer dependencies from `core/crud-skeleton/`, then use:

```sh
composer install --working-dir=core/crud-skeleton
php integration/backend/bin/console about
php core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml
```

The PHPUnit bootstrap creates a unique SQLite database under the system temporary directory and removes it at shutdown. Do not run migrations against a development or production database as part of routine validation.
