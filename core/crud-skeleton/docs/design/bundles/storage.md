# Storage Bundle Design

> The Storage bundle (`src/Storage/`) provides a pluggable file storage abstraction
> backed by tagged drivers. Common/CMS depends on the Storage interface but never
> touches a cloud SDK or filesystem directly.

---

## 1. Scope

### 1.1 Goal

Storage is an infrastructure module, not a business module. It owns no domain entities
and provides exactly one capability:

| Capability | Purpose |
|------------|---------|
| File storage driver | Accept an `UploadedFile`, persist it to a named storage backend, and return a public URL |
| File deletion | Remove a previously stored file from its backend |
| Driver registry | Expose registered drivers by name so callers can choose `local` / `qiniu` / etc. |

### 1.2 Non-Goals

| Excluded | Reason |
|----------|--------|
| Media entity ownership | Media entity lives in Common/CMS |
| Upload endpoint ownership | Controllers live in Common/CMS and depend on the Storage abstraction |
| Image processing / thumbnails | Separate concern; may be added later as a decorator or pipeline step |
| Multi-driver mirror / sync | First phase uses one driver per upload; simultaneous multi-driver write is out of scope |
| Account / bucket management | Cloud console responsibility |

---

## 2. Module Boundary

### 2.1 Module Location

```
src/Storage/
|-- Service/
|   |-- MediaStorageInterface.php          # Driver contract
|   |-- MediaStorageRegistry.php           # Tagged iterator collection
|   |-- LocalStorage.php                   # Local filesystem (default)
|   |-- QiniuStorage.php                   # Qiniu Kodo cloud storage
|-- Resources/config/
|   |-- services_storage.yaml              # Explicit service wiring for drivers with env vars
```

### 2.2 Dependency Direction

| From | To | Allowed | Rule |
|------|----|---------|------|
| Storage | Core | Yes | UUID, config parameters |
| Storage | Common | No | Storage MUST NOT import Media entity or CMS services |
| Common | Storage | Yes | Common depends on `MediaStorageInterface` and `MediaStorageRegistry` |
| Storage | Qiniu SDK | Yes | `QiniuStorage` is the only place where the Qiniu SDK is imported |

Storage is a leaf infrastructure module. It exports interfaces, not entities.

---

## 3. Driver Contract

### 3.1 MediaStorageInterface

**File**: `src/Storage/Service/MediaStorageInterface.php`

```php
interface MediaStorageInterface
{
    /** Driver name used in registry and the `storage` query parameter, e.g. "local", "qiniu". */
    public static function getName(): string;

    /**
     * Persist an uploaded file and return a publicly accessible URL.
     *
     * For local storage the URL is a root-relative path such as
     * `/uploads/202601/a1b2c3d4.jpg`.
     * For cloud storage the URL is a full CDN URL such as
     * `https://cdn.example.com/a1b2c3d4.jpg`.
     */
    public function store(UploadedFile $file, string $name): string;

    /** Remove a previously stored file. The `$path` parameter is the value returned by `store()`. */
    public function delete(string $path): void;
}
```

Driver implementations MUST NOT throw domain exceptions from callers. They MAY throw
`\RuntimeException` or driver-specific exceptions; callers should catch `\Throwable`.

### 3.2 MediaStorageRegistry

**File**: `src/Storage/Service/MediaStorageRegistry.php`

```php
final class MediaStorageRegistry
{
    /** @param iterable<MediaStorageInterface> $drivers */
    public function __construct(#[AutowireIterator('media.storage')] iterable $drivers) {}

    /** @throws \RuntimeException when driver name is unknown */
    public function get(string $name): MediaStorageInterface {}

    /** @return string[] */
    public function names(): array {}
}
```

All `MediaStorageInterface` implementations are auto-tagged `media.storage` via `_instanceof`.

---

## 4. First-Phase Drivers

### 4.1 LocalStorage

**File**: `src/Storage/Service/LocalStorage.php`

Stores files on the local filesystem under `public/uploads/`.

| Behaviour | Detail |
|-----------|--------|
| Directory layout | `{base_path}/{YYYYMM}/{uuid}.{ext}` |
| Public URL | Root-relative path: `/uploads/202601/a1b2c3d4.jpg` |
| Delete | `unlink()` the physical file |
| Configuration | `%media.local.upload_path%`, `%media.local.base_url%` |
| Dependencies | None (pure PHP filesystem functions) |

LocalStorage is always enabled and is the default driver. It is useful for
development, testing, and single-server production deployments without a CDN.

### 4.2 QiniuStorage

**File**: `src/Storage/Service/QiniuStorage.php`

Stores files on Qiniu Kodo (七牛云对象存储) and returns CDN URLs.

| Behaviour | Detail |
|-----------|--------|
| Upload | Qiniu SDK upload manager with upload token |
| Public URL | `{domain}/{filename}` e.g. `https://cdn.example.com/a1b2c3d4.jpg` |
| Delete | Qiniu bucket manager `delete()` |
| Configuration | `%env(QINIU_ACCESS_KEY)%`, `%env(QINIU_SECRET_KEY)%`, `%env(QINIU_BUCKET)%`, `%env(QINIU_DOMAIN)%` |
| Dependencies | `overtrue/qiniu-filesystem` or `qiniu/php-sdk` |

QiniuStorage is optional and toggled by the caller at upload time by passing
`storage=qiniu`. When Qiniu env vars are empty, the service definition should
either be removed or its constructor should gracefully degrade (return `false`
from `supports()` / throw only when `store()` is called with valid credentials).

---

## 5. Caller Contract (Common / Media)

### 5.1 Media Entity Extension

The Common `Media` entity gains one additional scalar field:

| Field | Type | Detail |
|-------|------|--------|
| `storage` | string(20) | Driver name used at upload time: `local`, `qiniu`, etc. |

This allows `MediaService::remove()` to resolve the correct driver when deleting
the physical file, without requiring the caller to remember which driver was used.

### 5.2 MediaService Integration

```php
class MediaService extends BaseService
{
    public function __construct(
        ContainerInterface $container,
        private readonly MediaStorageRegistry $storageRegistry,
    ) {}
}
```

| Method | Responsibility |
|--------|----------------|
| `createFromUpload(UploadedFile $file, string $storage, array $meta)` | Resolve driver → store file → persist Media entity with `storage` field |
| `remove(Media $media)` | Resolve driver from `media.storage` → delete physical file → remove entity |

`MediaService` MUST NOT import any Storage driver class or cloud SDK. It only
talks to `MediaStorageInterface` through the registry.

### 5.3 Controller Upload Action

```php
// App/MediaController
#[Route('/upload', name: 'upload', methods: ['POST'])]
public function uploadAction(Request $request): Response
{
    $file = $request->files->get('file');
    $storage = $request->request->get('storage', 'local');
    $media = $this->service->createFromUpload($file, $storage, $request->request->all());
    return $this->success($media, 'Uploaded', 201);
}
```

The same action pattern is repeated in `Manage/MediaController`.

---

## 6. Configuration

**File**: `config/packages/media.yaml`

```yaml
parameters:
    media.storage.default: 'local'

    media.local.upload_path: '%kernel.project_dir%/public/uploads'
    media.local.base_url: '/uploads'
```

**File**: `.env`

```ini
### Storage ###
MEDIA_STORAGE_DEFAULT=local

### Qiniu Kodo (optional — leave empty to disable) ###
QINIU_ACCESS_KEY=
QINIU_SECRET_KEY=
QINIU_BUCKET=
QINIU_DOMAIN=
```

**File**: `src/Storage/Resources/config/services_storage.yaml`

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true

    App\Storage\Service\LocalStorage:
        arguments:
            $basePath: '%media.local.upload_path%'
            $baseUrl: '%media.local.base_url%'

    App\Storage\Service\QiniuStorage:
        arguments:
            $accessKey: '%env(QINIU_ACCESS_KEY)%'
            $secretKey: '%env(QINIU_SECRET_KEY)%'
            $bucket: '%env(QINIU_BUCKET)%'
            $domain: '%env(QINIU_DOMAIN)%'
```

**File**: `config/services.yaml` (addition)

```yaml
_instanceof:
    App\Storage\Service\MediaStorageInterface:
        tags: ['media.storage']
```

---

## 7. Request Flow

```
POST /api/v1/app/media/upload
  Content-Type: multipart/form-data
  Body:
    file: <binary>
    storage: "qiniu"        (optional, defaults to "local")
    alt: "描述文字"           (optional)

Controller::uploadAction()
  → $request->files->get('file')
  → MediaService::createFromUpload(file, storage, meta)
      → MediaStorageRegistry::get('qiniu')
      → QiniuStorage::store(file, uuid.ext) → "https://cdn.example.com/abc.jpg"
      → persist Media { storage: "qiniu", path: "https://cdn.example.com/abc.jpg", ... }
      → return Media JSON
```

### 7.1 Delete Flow

```
DELETE /api/v1/manage/media/{id}

Controller::deleteAction()
  → MediaService::get({ id })
  → MediaService::remove(media)
      → MediaStorageRegistry::get(media.storage)
      → QiniuStorage::delete(media.path)
      → remove entity
```

---

## 8. Driver Auto-Discovery

Drivers are collected by Symfony's tagged iterator, not by a hardcoded registry:

```yaml
# config/services.yaml
_instanceof:
    App\Storage\Service\MediaStorageInterface:
        tags: ['media.storage']
```

```php
final class MediaStorageRegistry
{
    public function __construct(
        #[AutowireIterator('media.storage')]
        iterable $drivers,
    ) {
        foreach ($drivers as $driver) {
            $this->drivers[$driver::getName()] = $driver;
        }
    }
}
```

Adding a new driver (e.g., `S3Storage`) requires only:
1. Create `src/Storage/Service/S3Storage.php` implementing `MediaStorageInterface`
2. If it needs env vars, add a service definition in `services_storage.yaml`

No changes to `MediaStorageRegistry`, `MediaService`, or any controller.

---

## 9. Testing

### 9.1 Unit Tests

| Test | Coverage |
|------|----------|
| `LocalStorageTest` | store / delete / URL generation |
| `MediaStorageRegistryTest` | resolve by name / unknown driver exception / names list |

### 9.2 Integration Tests

| Test | Coverage |
|------|----------|
| `MediaUploadIntegrationTest` | Full upload → Media entity → delete (local driver) |
| `MediaStorageSwitchingTest` | Upload with `storage=local` and `storage=qiniu` in same test |

Qiniu integration tests should be skipped when `QINIU_ACCESS_KEY` is empty
(marked `@group qiniu` or guarded by an env-var check in `setUp()`).

---

## 10. Error Handling

| Scenario | Behaviour |
|----------|-----------|
| Unknown driver name | `\RuntimeException` from registry, caught by controller → `warning()` |
| Upload exceeds PHP `upload_max_filesize` | Symfony `UploadedFile` validation before driver call |
| Driver store fails | Exception propagates; Media entity is NOT persisted (no orphan records) |
| Driver delete fails | Logged but entity is still removed (best-effort cleanup) |

Controller MUST validate the uploaded file before calling the driver:

| Check | Rule |
|-------|------|
| File present | `$request->files->get('file') !== null` |
| Size limit | Configurable via `media.upload.max_size` (default 10M) |
| MIME allowlist | Configurable via `media.upload.allowed_mime_types` |

---

## 11. File Validation Contract

| Constraint | Default | Configurable |
|------------|---------|-------------|
| Max file size | 10 MB | `media.upload.max_size` |
| Allowed MIME types | `image/jpeg`, `image/png`, `image/gif`, `image/webp`, `application/pdf` | `media.upload.allowed_mime_types` |
| Empty file | Rejected | — |

Validation happens in the controller or in `MediaService::createFromUpload()` before
the driver is called, so invalid files never reach the storage backend.

---

## 12. Future Drivers

The same interface supports any storage backend:

```
src/Storage/Service/
|-- LocalStorage.php            # First phase (always enabled)
|-- QiniuStorage.php            # First phase (optional)
|-- S3Storage.php               # Future — AWS S3
|-- OssStorage.php              # Future — Alibaba Cloud OSS
|-- CosStorage.php              # Future — Tencent Cloud COS
```

Each is a single class implementing `MediaStorageInterface`. No registry or
caller code changes required.

---

## 13. Open Questions

| Question | Default Decision |
|----------|------------------|
| Should Storage own the Media entity? | No, Media stays in Common/CMS |
| Should `public/uploads/` be gitignored? | Yes |
| Should QiniuStorage be in a separate Qiniu bundle? | Not in first phase; keep in Storage until Qiniu-specific complexity (account mgmt, callback handling) justifies a split |
| Should delete be synchronous or queued? | Synchronous for first phase |
| Should there be a `serve` endpoint for private files? | Out of scope; `public/uploads/` is directly served by nginx |
