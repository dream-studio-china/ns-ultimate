# API Documentation Contract

> How `/api/doc` works, how to extend it, and the rules every new endpoint must follow.

---

## 1. Architecture

```mermaid
flowchart TD
    A["Controller #[OA\*] attributes"] --> B["swagger-php (zircote)"]
    B -->|"generates raw OpenAPI paths + generic tags"| C["NelmioApiDocBundle"]
    C -->|"merges config/packages/nelmio_api_doc.yaml<br/>(schemas, security, info)"| D["OpenApiEnricherListener"]
    D -->|"kernel.response event<br/>overrides tags, summaries, descriptions"| E["/api/doc (Swagger UI)<br/>/api/doc.json (raw JSON)"]
```

## 2. Single-File Enricher

**File**: `src/Core/EventListener/OpenApiEnricherListener.php`

All API documentation enrichment lives in ONE file. No controller changes needed.

### 2.1 Tag Detection (`detectTag()`)

Extracts module tag from the route's `operationId`:

| operationId Pattern | Tag |
|---------------------|-----|
| `sys-auth-*` | Auth |
| `manage-products-*`, `app-products-*` | Products |
| `manage-orders-*`, `app-orders-*` | Orders |
| `manage-categories-*`, `app-categories-*` | Categories |
| `manage-tags-*`, `app-tags-*` | Tags |
| `manage-contents-*`, `app-contents-*` | Contents |
| `manage-comments-*`, `app-comments-*` | Comments |
| `manage-pages-*`, `app-pages-*` | Pages |
| `manage-media-*`, `app-media-*` | Media |
| `manage-settings-*`, `app-settings-*` | Settings |
| `manage-wallets-*`, `manage-transactions-*` | Wallet |
| Any other `manage-{X}-*` or `app-{X}-*` | {X} (auto-title-cased) |

### 2.2 Summary/Description Overrides (`META`)

The `META` constant provides optional summaries and descriptions for key endpoints. If an endpoint is not in META, it still gets the correct tag but uses the default summary from the `#[OA\*]` attribute.

Example:
```php
'/api/v1/manage/orders/{id}/pay' => [
    'summary' => ['post' => 'Pay for order (wallet)'],
    'desc' => ['post' => 'User wallet → system wallet. Sets paidAt. Order must be confirmed.'],
],
```

### 2.3 Adding a New Module

1. **Route naming**: Use `manage-{resource}-{action}` or `app-{resource}-{action}` convention
2. **Auto-detection**: The resource name is automatically title-cased as the tag
3. **Optional**: Add to the `$known` map in `detectTag()` for a custom display name:
   ```php
   'notification' => 'Notifications', 'notifications' => 'Notifications',
   ```
4. **Optional**: Add summary/description to `META` for key endpoints

## 3. Schema Definitions

**File**: `config/packages/nelmio_api_doc.yaml`

Entity schemas are defined under `documentation.components.schemas`. Each schema provides field-level type, description, enum, and example values visible in Swagger UI.

Current schemas: Order, OrderItem, Product, Specification, Category, Tag, Content, Comment, Page, Media, Setting, Wallet, Transaction, TransferRequest, LoginResponse, UserRef.

### 3.1 Adding a New Schema

```yaml
components:
    schemas:
        NewEntity:
            type: object
            properties:
                id: { type: integer }
                name: { type: string, example: 'Example' }
                status: { type: string, enum: [active, inactive] }
```

## 4. Controller Attribute Requirements

Every endpoint MUST have OpenAPI attributes. The View traits (`ListApiViewMixin`, etc.) already provide them. Custom endpoints need explicit attributes:

```php
#[OA\Post(
    path: '/api/v1/manage/resource/custom-action',
    summary: 'Custom action description',
    requestBody: new OA\RequestBody(
        required: true,
        content: new OA\JsonContent(properties: [
            new OA\Property(property: 'field', type: 'string', example: 'value'),
        ])
    ),
    responses: [
        new OA\Response(response: 200, description: 'Success'),
    ]
)]
#[Route('/resource/custom-action', name: 'manage-resource-custom', methods: ['POST'])]
public function customAction(): Response { ... }
```

**Route naming is critical** — the `name:` prefix determines the `operationId`, which determines the module tag:
- `manage-products-custom` → `operationId: post_manage-products-custom` → tag: **Products**
- `app-orders-export` → `operationId: get_app-orders-export` → tag: **Orders**

## 5. Security Documentation

Bearer JWT auth is configured globally:

```yaml
components:
    securitySchemes:
        bearerAuth:
            type: http
            scheme: bearer
            bearerFormat: JWT
```

Public endpoints (no auth required) override per-path with `security: []`.

## 6. Response Format

All endpoints return the unified envelope. This is documented in the OpenAPI `info.description`:

```json
{
    "data": {},
    "code": 200,
    "message": "SUCCESS",
    "paginator": null
}
```

Pagination metadata when applicable:
```json
{
    "paginator": {
        "page": 1,
        "limit": 20,
        "pages": 5,
        "total": 100
    }
}
```

## 7. Dynamic Query Parameters

List endpoints support these query parameters (documented on `ListApiViewMixin`):

| Parameter | Type | Description |
|-----------|------|-------------|
| `page` | int | Page number (1-based) |
| `limit` | int | Items per page |
| `@filter` | string | Expression WHERE (e.g. `entity.status == "active"`) |
| `@dql` | string | Raw DQL sub-query |
| `@order` | string | Sort: `field\|ASC` or `field\|DESC` |
| `@select` | string | DQL SELECT override |
| `@sort` | string | In-memory sort expression |
| `@expands` | string | Nested entity expansion |
| `@display` | string | Field projection mode |

## 8. Verification

```bash
# Check generated JSON
curl -s http://127.0.0.1:8080/api/doc.json | python3 -m json.tool | head -50

# Check embedded HTML spec
curl -s http://127.0.0.1:8080/api/doc | grep -o '"tags":\[.*\]' | head -1

# Count tags
curl -s http://127.0.0.1:8080/api/doc.json | python3 -c "
import sys,json;from collections import Counter
d=json.load(sys.stdin)
tc=Counter()
for p,ms in d['paths'].items():
    for m,op in ms.items():
        if isinstance(op,dict): tc.update(op.get('tags',[]))
for t in sorted(tc): print(f'  {t}: {tc[t]}')
"
```
