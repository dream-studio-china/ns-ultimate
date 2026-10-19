# Backend entity extension contract

**Status: Validated with `App\Store\Entity\Product` extended by the Store business module.** The integration currently configures this one inheritance mapping as an example; the driver and route substitution helper are reusable for additional explicit mappings.

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

`business/backend/Store/` adds integer `sort` to `trade_product` and reuses the core manage Product CRUD actions. The Store migration adds `product_type` and `sort`, then marks existing rows as `store` so they hydrate as the subtype and receive the default sort value. New core-class rows use the database discriminator default `core`.

The Product service queries the core root entity but creates business subtypes. Its business-owned query builder factory left-joins the subtype so `entity.sort` ordering can be translated to the joined field without excluding core rows. Core rows have no subtype sort value and use the database platform's NULL ordering; their core fields remain readable and writable. Only ordering is adapted: this does not make `sort` a root metadata field for arbitrary filter or select expressions. Sort changes call the root entity's `touch()` method to update `updatedAt`.

The integration test verifies create, update, serialization, subtype hydration through the core repository, mixed core/subtype list ordering in both directions, core-row detail and updates, and persistence of sort-only timestamp changes.

## Compatibility and migration constraints

- No upstream core files are changed; an unextended core installation retains its original mapping.
- Applying the Store migration changes the shared database schema and data format. A standalone, unmodified core application cannot hydrate rows tagged `store`, because its discriminator map does not know the business subtype. Do not point the standalone core runtime at a database migrated for this composition unless it has matching subtype code and mapping.
- Doctrine inheritance may affect to-one association loading performance. Profile important association paths before production rollout.
- The example covers the management Product controller. Other Product controllers retain their own write allowlists; this contract does not automatically grant them access to `sort`.
- Migrations must be backed up and reviewed for the target database platform. Routine integration tests use isolated SQLite databases and do not execute production migrations.

## Validation

Run the backend integration suite with:

```sh
php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml
```

The increased memory limit is needed by the current full suite when Doctrine enumerates all mapped classes in this environment.
