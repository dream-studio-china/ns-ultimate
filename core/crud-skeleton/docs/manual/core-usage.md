# Core Framework — Usage Recipes

Practical patterns for building features on the crud-skeleton Core framework.
All examples are drawn from actual classes in the codebase (mostly
`src/Common` and `src/Trade`).

---

## 1. Create a Controller with `RestController` + View Mixins

The fastest way to expose a resource is a controller that composes `RestController`
with the view mixins. Dependencies (RequestStack, Serializer, Translator,
container) are injected automatically via the `_instanceof` wiring in
`config/services.yaml`; you only declare module-specific deps.

A read-only App controller (public list + detail):

```php
namespace App\Common\Controller\App;

use App\Common\Service\CategoryServiceInterface;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/app/categories', name: 'app-categories-')]
class CategoryController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin;

    public function __construct(
        protected readonly CategoryServiceInterface $service
    ) {}
}
```

A full CRUD Manage controller (admin):

```php
namespace App\Common\Controller\Manage;

use App\Common\Service\CategoryService;
use App\Core\Controller\RestController;
use App\Core\View\ApiView;
use App\Core\View\CreateApiViewMixin;
use App\Core\View\DeleteApiViewMixin;
use App\Core\View\DetailApiViewMixin;
use App\Core\View\ListApiViewMixin;
use App\Core\View\UpdateApiViewMixin;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/manage/categories', name: 'manage-categories-')]
#[IsGranted('ROLE_ADMIN')]
class CategoryController extends RestController
{
    use ApiView, DetailApiViewMixin, ListApiViewMixin,
        CreateApiViewMixin, UpdateApiViewMixin, DeleteApiViewMixin;

    /** @var list<string> */
    protected array $requiredCreateProperties = ['name', 'slug'];
    /** @var list<string> */
    protected array $acceptedCreateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];
    /** @var list<string> */
    protected array $acceptedUpdateProperties = ['name', 'slug', 'description', 'parent', 'sortOrder', 'enabled'];

    public function __construct(
        protected readonly CategoryService $service
    ) {}
}
```

### `requiredCreateProperties` vs `acceptedCreateProperties`

Both are simply lists of property names:

- **`requiredCreateProperties`** — must be present in the payload. Missing
  fields throw `ValidatorException("{Property} is required")` → the create
  action returns a `400`.
- **`acceptedCreateProperties`** — an allowlist. Only keys listed here are
  forwarded to the service; everything else in the payload is ignored.

In the example above `name` and `slug` are required; `description`, `parent`,
`sortOrder`, `enabled` are optional but whitelisted. The `UpdateApiViewMixin`
has an analogous pair: `requiredUpdateProperties` and
`acceptedUpdateProperties`.

> Note: If you set **only** `requiredCreateProperties` without an
> `acceptedCreateProperties`, only the required keys are forwarded. If you set
> neither, the full payload is passed through.

### Scoping lists by the current user

Override `commonFilter()` to restrict every list/detail on the controller. The
classic array form is sufficient for simple equality:

```php
/** @return array<string, mixed> */
protected function commonFilter(): array
{
    $user = $this->getCurrentUser();
    return $user === null ? ['id' => -1] : ['user' => $user];
}
```

This is exactly how `src/Trade/Controller/App/OrderController` keeps a user from
seeing other users' orders, and how `src/Common/Controller/App/MediaController`
does `['user' => $this->getUser()]`.

For collection membership or combined predicates, return a server-owned
`DqlExpression` instead. It is compiled to DQL, validated against Doctrine
metadata, fail-closed (500 on error), and automatically `AND`ed with the
`id`/`uuid` added by `mixIdToCommonFilter` so detail/update/delete cannot be
bypassed:

```php
use App\Core\Query\DqlExpression;

// Ownership + status via explicit variables
protected function commonFilter(): DqlExpression
{
    return new DqlExpression(
        'entity.getUser() == user && entity.getStatus() != deleted',
        ['user' => $this->getUser(), 'deleted' => 'deleted']
    );
}

// Controller `this` shorthand — only inside commonFilter()
protected function commonFilter(): DqlExpression
{
    return new DqlExpression('entity.getUser() == this.getUser()');
}

// Multi-value Store scope with empty-collection safety
protected function commonFilter(): DqlExpression
{
    // $allowedStoreUuids may be empty → compiled to 1 = 0 (no rows)
    return new DqlExpression(
        'entity.getStoreUuid() in storeUuids',
        ['storeUuids' => $this->access->allowedStoreUuids($this->getUser(), 'common:content:read')]
    );
}

// `this` collection shorthand
protected function commonFilter(): DqlExpression
{
    return new DqlExpression('entity.getStoreUuid() in this.getAllowedStoreUuids()');
}
```

> `DqlExpression` is **server-owned only**: never construct it from HTTP input,
> database rows, or administrator-managed data. Compilation failures are 500
> configuration errors, never an in-memory fallback.

### Locking down public detail/filter behavior

`src/Common/Controller/App/CategoryController` is read-only public and always
filters to enabled categories, but lets detail look up by id:

```php
protected function commonFilter(): array
{
    return ['enabled' => true];
}

/** @param array<string,mixed>|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter */
protected function detailFilter(array|\Doctrine\ORM\QueryBuilder|\App\Core\Query\DqlExpression|null $filter = null)
{
    if (is_array($filter)) {
        unset($filter['enabled']);   // allow fetching a disabled category by id
    }
    return $filter;
}
```

For `DqlExpression` the `enabled` predicate is part of the expression itself, so
`detailFilter` should return the expression unchanged (or map it to a separate
read scope) rather than trying to unset an array key.

---

## 2. Create a Service Extending `BaseService`

Every service manages one entity and extends `BaseService`, implementing a
`{Name}ServiceInterface`. This forwards `get`/`list`/`new`/`update`/`remove`/`wrapInTransaction`.

```php
namespace App\Common\Service;

use App\Common\Entity\Category;
use App\Core\Service\BaseService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/** @extends BaseService<\App\Common\Entity\Category> */
final class CategoryService extends BaseService implements CategoryServiceInterface
{
    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container, Category::class);
    }
}
```

The matching interface just extends `BaseServiceInterface` (see `CategoryServiceInterface`).

### Adding custom methods

With other dependencies injected in the constructor:

```php
/** @extends BaseService<\App\Common\Entity\Media> */
final class MediaService extends BaseService implements MediaServiceInterface
{
    public function __construct(
        ContainerInterface $container,
        private readonly MediaStorageRegistry $storageRegistry,
        #[Autowire('%media.storage.default%')]
        private readonly string $defaultStorage,
        // ...
    ) {
        parent::__construct($container, Media::class);
    }

    public function createFromUpload(UploadedFile $file, ?string $storage = null,
        array $meta = [], ?User $owner = null): Media
    {
        // validate, store file via driver, build Media, persist & flush
        $this->em->persist($media);
        $this->em->flush();
        return $media;
    }
}
```

`BaseService` exposes protected state you can use inside custom methods:
`$this->em` (EntityManager), `$this->rep` (repository), `$this->logger`,
`$this->user` (current user), plus all the lazy accessors from
`BaseServiceInfrastructureTrait` (`getRepository`, `getSerializer`,
`getValidator`, `wrapInTransaction`, etc.).

---

## 3. Handling File Uploads

File uploads are custom actions (the view mixins are JSON-oriented). The
pattern used by `src/Common/Controller/App/MediaController`:

```php
#[Route('/upload', name: 'upload', methods: ['POST'])]
public function uploadAction(Request $request): Response
{
    $file = $request->files->get('file');
    if (!$file instanceof UploadedFile) {
        return $this->warning('Uploaded file is required', 400, '', 400);
    }

    try {
        $media = $this->service->createFromUpload(
            $file,
            $request->request->has('storage') ? (string) $request->request->get('storage') : null,
            $request->request->all(),
            $this->uploadOwner(),
        );
    } catch (ValidatorException|\RuntimeException $exception) {
        return $this->warning($exception->getMessage(), 400, '', 400);
    } catch (\Throwable $exception) {
        return $this->warning($exception->getMessage() ?: 'Upload failed', 500, '', 500);
    }

    return $this->success($media, 'Uploaded', 201);
}
```

The service validates the file (size limits and MIME-type allowlist), routes it
to the chosen storage driver (`local` or `qiniu` via `MediaStorageRegistry`),
persists a `Media` row, and returns it. The OpenAPI enricher documents the
multipart `file`/`storage`/`category`/`alt`/`title`/`width`/`height` fields.

---

## 4. Custom Actions

Custom actions are plain controller methods with `#[Route]` that use
`$this->service` and the `success`/`warning` helpers. Trade's `OrderController`
is the canonical example.

Quote without side effects:

```php
#[Route('/quote', name: 'quote', methods: ['POST'])]
public function quoteAction(Request $request): Response
{
    $content = json_decode($request->getContent(), true) ?: [];
    $items = $content['items'] ?? [];
    if (empty($items)) {
        return $this->warning('Items are required.', 400, '', 400);
    }

    try {
        $storeContext = $this->storeContextResolver->resolve();
        $result = $this->service->calculatePrices(
            $items, $content['currency'] ?? 'CNY',
            $storeContext?->storeCode, $content['meta'] ?? [],
        );
        return $this->success($result, 'Quote calculated');
    } catch (\Throwable $e) {
        return $this->warning($e->getMessage(), 400, '', 400);
    }
}
```

Sub-action with ownership + workflow guard (from `src/Trade/Controller/App/OrderController`):

```php
#[Route('/{id<\d+>}/submit', name: 'submit', methods: ['POST'])]
public function submitAction(int $id): Response
{
    $order = $this->service->get(['id' => $id]);
    if (!$order) {
        return $this->warning('Order not found.', 404, '', 404);
    }
    $user = $this->getCurrentUser();
    if ($user === null || $order->getUser()?->getId() !== $user->getId()) {
        return $this->warning('Order not found.', 404, '', 404);
    }
    if (!$this->workflow->can($order, 'submit')) {
        return $this->warning('Order cannot be submitted in current status.', 400, '', 400);
    }

    $this->service->wrapInTransaction(function () use ($order) {
        $this->workflow->apply($order, 'submit');
    });

    return $this->success($order, 'Order submitted');
}
```

---

## 5. Error Handling

### Response helpers (in controllers)

- `$this->success($content, $message = 'SUCCESS', $status = 200)` → envelope
  `{data, code: 0, message}` (+ optional `paginator`). `204` returns an empty
  body.
- `$this->warning($message, $code = -1, $raw = '', $status = 200)` → envelope
  `{code, message, raw_data}` (message translated).

### Exceptions for business control-flow

`MessageErrorHttpException` (403) and `MessageSuccessHttpException` (200) let
service code signal success/error as thrown exceptions that carry a message and
an optional `redirectUrl` response header. They are translated and rendered as
JSON by `ExceptionInterceptor`.

```php
use App\Core\Exception\MessageErrorHttpException;

if (!$allowed) {
    throw new MessageErrorHttpException('This operation is not permitted.');
}
```

### Try/catch in custom actions

Custom actions routinely wrap service calls and normalize exceptions to
`warning(...)` responses, distinguishing 400 (validation/domain) from 500:

```php
try {
    $order = $this->service->createOrder(...);
    return $this->success($order, 'Order created', 201);
} catch (\Throwable $e) {
    return $this->warning($e->getMessage(), 400, '', 400);
}
```

### Global exception handling

`ExceptionInterceptor` handles any uncaught exception on `/api/*` routes,
returning `{code, message, class}` JSON with an appropriate status and logging
the error. In production you therefore don't need per-controller try/catch for
transport-level errors, though domain actions typically still map to
`warning(...)` for precise codes.

---

## 6. Row-Level Scoping with `DqlExpression` vs `commonFilter` Array vs `QueryBuilder`

| Filter type | When to use |
|-------------|-------------|
| Array criteria | Simple equality such as `['user' => $this->getUser()]` |
| `DqlExpression` | Readable ownership / Store / status / collection-membership rules: `entity.getUser() == this.getUser()`, `entity.getStoreUuid() in storeUuids`, `entity.getStatus() != archived`. Compiled via `ExpressionDqlParser` + `ExpressionQueryBuilderAssembler`, fail-closed, automatically `AND`ed with `id`/`uuid`. |
| `QueryBuilder` | Aggregation, subqueries, database functions, or custom join shapes |

`DqlExpression` shares the same expression syntax as `@filter` but with two critical
differences: (1) it is constructed in PHP code, not from a query string, and (2) it
never uses `LegacyEvaluator`. An empty `in []` becomes `1 = 0` (no rows) rather than
`IN ()` so that a missing scope yields no data.

## 7. Access Control (`IsGranted`)

Add `#[IsGranted(...)]` at the class or method level.

Public / anonymous (no attribute — App controllers like
`App\Common\Controller\App\*`):

```php
#[Route('/app/contents', name: 'app-contents-')]
class ContentController extends RestController { /* ... */ }
```

Admin-only (class level):

```php
#[Route('/manage/categories', name: 'manage-categories-')]
#[IsGranted('ROLE_ADMIN')]
class CategoryController extends RestController { /* ... */ }
```

Authenticated users only (class level):

```php
#[Route('/app/orders', name: 'app-orders-')]
#[IsGranted('ROLE_USER')]
class OrderController extends RestController { /* ... */ }
```

Service-layer user checks: rely on `$this->user` in custom service methods, or
compare ownership in the controller via `$this->getUser()` (see the App
`OrderController` ownership guards above).

---

## 8. Transactions (`wrapInTransaction`)

`wrapInTransaction(callable $fn)` runs a callable inside a DB transaction with
all-or-nothing semantics; it flushes before commit and rolls back on any
`Throwable` (and recovers a closed EntityManager). Use it to make several
writes atomic.

Example — apply a workflow transition atomically with a wallet transfer
(comment from `src/Trade/Controller/Manage/OrderController::payAction`):

```php
$this->service->wrapInTransaction(function () use ($order, $systemWalletId, $paymentMethod) {
    $this->service->pay($order, $systemWalletId, $paymentMethod);
    $this->workflow->apply($order, 'pay');
    $this->service->update($order, []);
});
```

The callback receives the `EntityManager` as its first argument, so you can use
it directly:

```php
$this->service->wrapInTransaction(function ($em) use ($entity, $content, $transition) {
    if ($content) {
        $this->service->update($entity, $content);
    }
    $this->workflow->apply($entity, $transition);
});
```

Nested transactions are handled via the DB's savepoint mechanism, so the inner
wallet transfer can itself call `wrapInTransaction`.

---

## 9. API Documentation (NelmioApiDoc)

NelmioApiDoc serves Swagger at `/api/doc` (JSON at `/api/doc.json`).

### Configuring the spec

`config/packages/nelmio_api_doc.yaml` defines the OpenAPI 3.1 info, title,
version, security scheme (`bearerAuth` JWT), reusable components (envelopes
`SuccessResponse`/`ErrorResponse`/`Paginator`, per-resource request/response
schemas and examples), and the `default` area limited to `/api`, `/system`,
`/health`, `/metrics`.

### Documenting with attributes

The view mixins ship `#[OA\*]` attributes (parameters, tags, responses), so mixin
routes are documented automatically. For custom controllers add `#[OA\...]`
attributes, as in `HealthController`:

```php
#[OA\Get(
    path: '/health/ready',
    summary: 'Readiness probe (database + optional Redis)',
    responses: [
        new OA\Response(response: 200, description: 'Ready to serve traffic'),
        new OA\Response(response: 503, description: 'A required dependency is unavailable'),
    ],
    tags: ['System'],
)]
```

### Automatic enrichment

`OpenApiEnricherListener` post-processes the `/api/doc.json`/`/api/doc`
response to:

- inject canonical module tags (`Auth`, `Products`, `Orders`, `Categories`, ...)
  with descriptions;
- set per-endpoint `summary`/`description` from its `META` map;
- document multipart upload request bodies for `/api/v1/{app,manage}/media/upload`;
- remove the generic mixin tags (`List`, `Detail`, `Create`, `Update`, `Delete`,
  `Workflow`).

So after adding a module you usually get usable docs with no extra work; add
explicit `OA` attributes and/or a `META` entry only when you need richer text.

---

## 10. Putting It Together: Minimal New CRUD Module

1. **Entity** — `src/{Module}/Entity/{Thing}.php` (Doctrine attributes).
2. **Repository** — `src/{Module}/Repository/{Thing}Repository.php` extending
   `ServiceEntityRepository`.
3. **Service interface + service** — `{Thing}ServiceInterface extends
   BaseServiceInterface`; `ThingService extends BaseService implements
   ThingServiceInterface`, with `parent::__construct($container, Thing::class)`.
4. **Controllers** — App (read-only public) and Manage (admin CRUD) extending
   `RestController` and composing the view mixins.
5. **Routes** — register in `config/routes.yaml`.
6. **Migration** — `bin/console make:migration` / review & apply.

Autowiring handles everything: `App\:` is auto-registered, `RestController`
deps are wired via `_instanceof`, and the `$service` property is injected
through the constructor.
