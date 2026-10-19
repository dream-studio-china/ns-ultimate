# Backend entity extension contract

**Status: Validated with `App\Store\Entity\Product` and `App\Common\Entity\Content` extended by their business modules.** Optional module declarations configure inheritance, controller substitutions, and extension-field ordering without entity-specific edits to integration.

## Goal and ownership

Integration may extend a core entity with additional mapped fields while reusing the core service and CRUD actions, without modifying `core/crud-skeleton/`.

- Core entities remain the default implementation and own the base fields and behavior.
- A business module owns the subtype, added fields, business migrations, and CRUD field permissions.
- Business modules declare their inheritance maps and controller substitutions. Integration owns discovery, validation, Doctrine composition, route substitution, and the shared ordering adapter.
- Extension is additive. This contract does not support removing core fields, changing their types, or replacing the core model with an unrelated class.

## Mechanism

Each discovered module may provide `config/entity_extensions.yaml`. For example, Store declares:

```yaml
entities:
    App\Store\Entity\Product:
        column: product_type
        map:
            core: App\Store\Entity\Product
            store: NsUltimate\Business\Store\Entity\Product
        order_fields:
            sortOrder: NsUltimate\Business\Store\Entity\Product

controllers:
    App\Store\Controller\Manage\ProductController: NsUltimate\Business\Store\Adapter\ManageProductController
```

`EntityExtensionRegistry` merges these declarations. Missing or empty files are valid; `entities`, `controllers`, and `order_fields` are optional. Unknown keys, duplicate root/controller registrations, invalid inheritance, duplicate discriminator classes, and unmapped ordering fields fail during configuration loading. Replacement controllers must expose the original public attribute-routed actions. The same declaration file cannot be split between modules for one root entity. Configuration existence and contents are tracked as both container and routing cache resources, including optional files not yet present. Production deployments still require normal cache clearing/warmup after changing configuration.

`InheritanceMappingDriver` decorates Doctrine's default metadata driver using the merged maps. The integration mapping is single-table inheritance. Each extension class must be a concrete Doctrine entity extending its configured root entity. The discriminator map must list every concrete class in that entity hierarchy, starting with the root; its first discriminator value supplies the database default. Added fields must be nullable at the database mapping level because rows of other discriminator types share the table. Declarative composition does not create schema migrations automatically.

The business subtype declares only its additional mapped fields. Its repository must bind to the subtype so subtype-only fields are queryable through that repository. A business service can reuse `BaseService` and be selected through the core service interface. When the composition retains concrete root-class rows, read queries must use the root class rather than the subtype; otherwise Doctrine filters those rows out. Override entity creation separately to select the business subtype.

For CRUD writes, business controller composition must explicitly add extension fields to both create and update allowlists. Field presence in Doctrine metadata does not grant API write access. `ControllerRouteOverride` re-points existing routes to a compatible replacement class while preserving route names and other route metadata. Replacement controllers remain responsible for their authorization attributes and dependencies; declaring an override does not copy class-level security attributes.

## Shared service and query adaptation

Business services can extend `ExtendedEntityService<CoreEntity>` and pass the root class plus the injected `EntityExtensionQueryBuilderFactory` to its constructor. They still implement the core service interface, select it through module `config/services.yaml`, and override `new()` to construct their business subtype. Entity initialization, field validation, authorization, lifecycle effects, and association handling stay in business code.

`order_fields` maps scalar property names to their discriminator-mapped subtype. The shared query factory left-joins each declared subtype once and rewrites `entity.<field>` ordering to the joined alias. It also supports caller-provided query builders, custom root aliases, and programmatic order arrays, preserving order priority. Only declared field names are rewritten; conflicting query aliases fail explicitly. Temporary request-order changes are restored even when core processing throws. The adapter follows the core service convention of a single root with an `id` identifier.

This is an ordering adapter, not a general extension-query language: `@filter`, `@select`, grouping, and aggregation over subtype-only fields are not automatically supported. Read serialization and explicit CRUD write permissions are separate from query adaptation. Existing concrete root rows remain visible but have NULL subtype-field values in ordering; their placement follows the database platform's NULL ordering. Enabling or changing `order_fields` does not grant additional endpoint permissions.

## Business-owned JSON Schema validation

Each business module may provide `config/validation.yaml` using Symfony's YAML validation mapping format and place schema files in `resources/json-schema/<Name>.json`. The logical schema name is `<Module>/<Name>`; for example Common uses:

```yaml
App\Common\Entity\Content:
    properties:
        metadata:
            - NsUltimate\Integration\Backend\Validator\JsonSchema:
                  schema: Common/ContentMetadata
```

The business module owns both mapping and JSON file. Integration discovers the optional mapping and schema files, registers Symfony's validation mapping, and resolves the logical schema name. The schema is validated against the final entity value during Doctrine `onFlush`, after the core service has deserialized input and before changes are written. This also covers writes that use Doctrine flush without going through the JSON API. Invalid values become Symfony validation violations and the existing REST create/update handling returns a client error.

The initial adapter supports standalone JSON Schema documents and delegates value validation to the core `JsonSchemaValidator::validateInline()` implementation. External or relative `$ref` resolution is not currently supported by the business schema adapter. A `null` value is skipped, matching core validator behavior; declare an additional Symfony constraint when a value must be non-null. Schemas are mapped on the root entity so the constraint is inherited by its business subtype. Validation applies to the entire entity whenever the unit of work flushes it, so historical JSON must satisfy a newly deployed schema before such entities can be updated. Direct SQL bypasses Doctrine validation.

Content's `metadata` schema is an integration example: only `featured` boolean and `layout` values `article` or `landing` are accepted, with unknown properties rejected. Integration tests verify valid and invalid create/update values, rejection before persistence, optional file discovery, and invalid JSON schema failure during configuration discovery.

## Product example

`business/backend/Store/` adds integer `sortOrder` (database column `sort_order`) to `trade_product` and reuses the core manage Product CRUD actions. The initial Store migration adds `product_type` and `sort`, then marks existing rows as `store` so they hydrate as the subtype and receive the default sort value. A follow-up migration renames `sort` to `sort_order`, preserving existing values. New core-class rows use the database discriminator default `core`.

The Product service queries the core root entity but creates business subtypes. Its module declares `sortOrder` for the shared query adapter; its core fields remain readable and writable for both root and subtype rows. Changes to `sortOrder` call the root entity's `touch()` method to update `updatedAt`.

The extension API uses `sortOrder` for request and response fields; the former `sort` field and `entity.sort` ordering expression are no longer supported. Existing installations must apply the rename migration before running the updated application.

The integration test verifies create, update, serialization, subtype hydration through the core repository, mixed core/subtype list ordering in both directions, core-row detail and updates, and persistence of sort-only timestamp changes.

## Content extension

`business/backend/Common/` adds integer `sortOrder` (database column `sort_order`, default `0`) to `App\Common\Entity\Content` without changing the core subtree. Its migration adds `content_type` to `common_content` and marks existing rows as `common` so they hydrate as the business subtype. Apply the migration before deploying the updated application.

The Content service reads the root entity, creates business subtypes using the core constructor, and declares `sortOrder` for the same shared adapter. The existing management API at `/api/v1/manage/contents` accepts and returns `sortOrder`. The app API at `/api/v1/app/contents` also returns the extension field and supports the same ordering expression, for example `?@order=entity.sortOrder|ASC`. Updating `sortOrder` refreshes `updatedAt`.

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

Integration tests cover declaration discovery and conflicts, invalid mappings/controllers, optional configuration cache resources, shared query adaptation, alias collisions, and request restoration. Existing Product and Content API tests exercise the composed container, routes, and mixed root/subtype data. Adding another extension requires module-owned declarations, code, migrations, and tests, not edits to the integration Kernel or central service configuration. The current PHPUnit configuration still explicitly lists business test directories; add new module suites there when necessary.

Run the backend integration suite with:

```sh
php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml
```

The increased memory limit is needed by the current full suite when Doctrine enumerates all mapped classes in this environment.
