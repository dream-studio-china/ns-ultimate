# Core Runbook

This runbook is the operational guide for the Core framework (RestController,
BaseService, View mixins, dynamic query engine, serialization). It complements
[`design/bundles/core.md`](../design/bundles/core.md).

## 1. Concepts In One Minute

- **Core** is the framework foundation shared by all modules.
- **RestController** + **View mixins** compose CRUD controllers from traits.
- **BaseService** provides the CRUD service contract.
- **Expression dynamic query engine** compiles `@filter`, `@sort`, `@dql`, `@order`,
  `@select` query parameters into DQL.

## 2. Controller Composition

Controllers are assembled from traits:

| Trait | Provides |
|-------|----------|
| `ListApiViewMixin` | List endpoint |
| `DetailApiViewMixin` | Detail endpoint |
| `CreateApiViewMixin` | Create endpoint |
| `UpdateApiViewMixin` | Update endpoint |
| `DeleteApiViewMixin` | Delete endpoint |

Each controller declares `requiredCreateProperties` and `acceptedCreateProperties` to
control the request contract.

## 3. Dynamic Query System

Query parameters are compiled into DQL:

| Parameter | Purpose |
|-----------|---------|
| `@filter` | Filter conditions |
| `@sort` | Sort order |
| `@dql` | Raw DQL fragment |
| `@order` | Ordering |
| `@select` | Field selection |

Use these to build flexible list endpoints without writing custom query code.

## 4. Serialization

Responses are serialized through a pipeline. Use the serializer callbacks and
normalizers to shape output. The `FlatNormalizer` is excluded from auto-registration.

## 5. Configuration

Core has no special environment variables. Configuration is via `config/services.yaml`
(service auto-registration and `_instanceof` rules).

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Endpoint 404 | Route not registered | Check route registration and controller import |
| Serialization error | Circular reference | Use the circular reference handler |
| Query error | Invalid `@dql` | Validate the DQL fragment |

## 7. Checklist Before Going Live

- [ ] Controllers composed with the intended mixins.
- [ ] `requiredCreateProperties` / `acceptedCreateProperties` correct.
- [ ] Dynamic query parameters validated.
- [ ] Serialization pipeline handles entity graphs.
