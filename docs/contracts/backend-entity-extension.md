# Backend entity extension contract

**Status: Validated with `App\Store\Entity\Product` and `App\Common\Entity\Content` extended by their business modules.** The integration explicitly configures both inheritance mappings; the driver and route substitution helper are reusable for additional explicit mappings.

## Goal and ownership

Integration may extend a core entity with additional mapped fields while reusing the core service and CRUD actions, without modifying `core/crud-skeleton/`.

- Core entities remain the default implementation and own the base fields and behavior.
- A business module owns the subtype, added fields, business migrations, and CRUD field permissions.
- Integration owns the Doctrine inheritance composition and any service/controller substitutions needed to select the extension.
- Extension is additive. This contract does not support removing core fields, changing their types, or replacing the core model with an unrelated class.

## Mechanism

`NsUltimate\Integration\Backend\Doctrine\InheritanceMappingDriver` decorates Doctrine's default metadata driver. Configuration maps each root entity to a discriminator column and a complete discriminator map:

```yaml
NsUltimate\Integration\Backend\Doctrine\InheritanceMappingDriver:
    decorates: doctrine.orm.default_metadata_driver
    arguments:
        - '@.inner'
        -
            App\Store\Entity\Product:
                column: product_type
                map:
                    core: App\Store\Entity\Product
                    store: NsUltimate\Business\Store\Entity\Product
```

The integration mapping is single-table inheritance. Each extension class must be a Doctrine entity extending its configured root entity. The discriminator map must list every concrete class in that entity hierarchy, including the root. Added fields must be nullable at the database mapping level because rows of other discriminator types share the table.

The business subtype declares only its additional mapped fields. Its repository must bind to the subtype so subtype-only fields are queryable through that repository. A business service can reuse `BaseService` and be selected through the core service interface. When the composition retains concrete root-class rows, read queries must use the root class rather than the subtype; otherwise Doctrine filters those rows out. Override entity creation separately to select the business subtype.

For CRUD writes, business controller composition must explicitly add extension fields to both create and update allowlists. Field presence in Doctrine metadata does not grant API write access. `ControllerRouteOverride` re-points existing routes to a compatible controller subclass while preserving route names and other route metadata.

## Product example

`business/backend/Store/` adds integer `sortOrder` (database column `sort_order`) to `trade_product` and reuses the core manage Product CRUD actions. The initial Store migration adds `product_type` and `sort`, then marks existing rows as `store` so they hydrate as the subtype and receive the default sort value. A follow-up migration renames `sort` to `sort_order`, preserving existing values. New core-class rows use the database discriminator default `core`.

The Product service queries the core root entity but creates business subtypes. Its business-owned query builder factory left-joins the subtype so `entity.sortOrder` ordering can be translated to the joined field without excluding core rows. Core rows have no subtype sort value and use the database platform's NULL ordering; their core fields remain readable and writable. Only ordering is adapted: this does not make `sortOrder` a root metadata field for arbitrary filter or select expressions. Changes to `sortOrder` call the root entity's `touch()` method to update `updatedAt`.

The extension API uses `sortOrder` for request and response fields; the former `sort` field and `entity.sort` ordering expression are no longer supported. Existing installations must apply the rename migration before running the updated application.

The integration test verifies create, update, serialization, subtype hydration through the core repository, mixed core/subtype list ordering in both directions, core-row detail and updates, and persistence of sort-only timestamp changes.

## Content extension

`business/backend/Common/` adds integer `sortOrder` (database column `sort_order`, default `0`) to `App\Common\Entity\Content` without changing the core subtree. Its migration adds `content_type` to `common_content` and marks existing rows as `common` so they hydrate as the business subtype. Apply the migration before deploying the updated application.

The Content service reads the root entity, creates business subtypes using the core constructor, and left-joins the subtype for `entity.sortOrder` ordering. The existing management API at `/api/v1/manage/contents` accepts and returns `sortOrder`. The app API at `/api/v1/app/contents` also returns the extension field and supports the same ordering expression, for example `?@order=entity.sortOrder|ASC`. Core-type rows remain visible and retain database-platform NULL ordering. Like Product, this adaptation covers ordering, not arbitrary subtype-field filtering or projection. Updating `sortOrder` refreshes `updatedAt`.

The core management controller depends on a final concrete service rather than its interface. Its business replacement therefore reuses the core REST mixins with `ContentServiceInterface`, preserves the existing route names and `ROLE_ADMIN` requirement, and adds `sortOrder` to the write allowlists. It explicitly resolves `category` and `tags` ids to preserve existing association writes: the core mutation service's reflection does not inspect private fields inherited by an entity subtype.

Content tests cover default values, CRUD, mixed-type ordering on both APIs, root-type reads and updates, category/tag writes, timestamp persistence, anonymous/non-admin rejection, and migration/rollback preservation of existing content on isolated SQLite. MySQL migration execution is not covered by these tests.

## Compatibility and migration constraints

- No upstream core files are changed; an unextended core installation retains its original mapping.
- Applying the Store migration changes the shared database schema and data format. A standalone, unmodified core application cannot hydrate rows tagged `store`, because its discriminator map does not know the business subtype. Do not point the standalone core runtime at a database migrated for this composition unless it has matching subtype code and mapping.
- The Common migration similarly introduces the `common` Content discriminator and requires the composed runtime and its business subtype mapping.
- Doctrine inheritance may affect to-one association loading performance. Profile important association paths before production rollout.
- The example covers the management Product controller. Other Product controllers retain their own write allowlists; this contract does not automatically grant them access to `sortOrder`.
- Migrations must be backed up and reviewed for the target database platform. Routine integration tests use isolated SQLite databases and do not execute production migrations.

## Validation

Run the backend integration suite with:

```sh
php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml
```

The increased memory limit is needed by the current full suite when Doctrine enumerates all mapped classes in this environment.
