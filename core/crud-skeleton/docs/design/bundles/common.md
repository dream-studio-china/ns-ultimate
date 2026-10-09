# Common Bundle Design

> The Common bundle (`src/Common/`) is the CMS (Content Management System) module. It provides
> standard content entities (Category, Tag, Content, Comment, Page, Media, Setting) with
> public read-only APIs and admin CRUD endpoints.

---

## 1. Overview

Common is a generic CMS module with 7 entity types for building content-driven applications. It follows the standard module contract: Entities -> Repositories -> Services -> Controllers (App + Manage).

### 1.1 Entities

| Entity | Table | Purpose |
|--------|-------|---------|
| `Category` | `common_category` | Hierarchical categories (self-referencing tree) |
| `Tag` | `common_tag` | Flat label/tag system |
| `Content` | `common_content` | Article-like content with category + tags |
| `Comment` | `common_comment` | Polymorphic comments on any entity |
| `Page` | `common_page` | Standalone pages (About, Terms, etc.) |
| `Media` | `common_media` | File metadata (filename, path, mime, size) |
| `Setting` | `common_setting` | Key-value configuration storage |

---

## 2. File Structure

```
src/Common/
|-- Controller/
|   |-- App/
|   |   |-- CategoryController.php      # GET list/detail (enabled only)
|   |   |-- CommentController.php       # GET list/detail
|   |   |-- ContentController.php       # GET list/detail
|   |   |-- MediaController.php         # GET list/detail
|   |   |-- PageController.php          # GET list/detail
|   |   |-- SettingController.php       # GET list/detail
|   |   |-- TagController.php           # GET list/detail
|   |-- Manage/
|       |-- CategoryController.php      # Full CRUD
|       |-- CommentController.php       # Full CRUD
|       |-- ContentController.php       # Full CRUD
|       |-- MediaController.php         # Full CRUD
|       |-- PageController.php          # Full CRUD
|       |-- SettingController.php       # Full CRUD
|       |-- TagController.php           # Full CRUD
|-- Entity/
|   |-- Category.php
|   |-- Comment.php
|   |-- Content.php
|   |-- Media.php
|   |-- Page.php
|   |-- Setting.php
|   |-- Tag.php
|-- Repository/
|   |-- CategoryRepository.php
|   |-- CommentRepository.php
|   |-- ContentRepository.php + ContentRepositoryInterface.php
|   |-- MediaRepository.php
|   |-- PageRepository.php
|   |-- SettingRepository.php
|   |-- TagRepository.php
|-- Service/
    |-- CategoryService.php + CategoryServiceInterface.php
    |-- CommentService.php + CommentServiceInterface.php
    |-- ContentService.php + ContentServiceInterface.php
    |-- MediaService.php + MediaServiceInterface.php
    |-- PageService.php + PageServiceInterface.php
    |-- SettingService.php + SettingServiceInterface.php
    |-- TagService.php + TagServiceInterface.php
```

---

## 3. Entity Relationships

```
Category (self-ref: parent -> children)
  |
  +-- 1:N -> Content (category_id FK)

Tag
  |
  +-- M:N -> Content (common_content_tag join table)

Comment (self-ref: parent -> replies)
  |
  +-- M:1 -> User (author)
  +-- Polymorphic: entityType + entityId -> any entity

Page       (standalone, no relations)

Media      (standalone, file metadata)

Setting    (standalone, key-value)
```

---

## 4. Entity Design

### 4.1 Category -- Hierarchical Tree

- Self-referencing: `parent` (ManyToOne) + `children` (OneToMany)
- Unique `slug` for URL-friendly references
- `sortOrder` for manual ordering
- `enabled` boolean for soft-publishing
- App controller filters to `enabled = true`

### 4.2 Content -- Article/Post

- `title` + `body` (text)
- Belongs to one `category`
- Many-to-many with `tags`
- Custom repository methods: `findLatest($limit)`, `findById($id)`

### 4.3 Tag -- Flat Labels

- `name` + unique `slug` + `color`

### 4.4 Comment -- Polymorphic

- `entityType` (FQCN) + `entityId` -- can comment on any entity
- Self-referencing `parent` for threaded replies
- `status` for moderation (pending/approved/rejected)
- `author` is a ManyToOne to User

### 4.5 Page -- Standalone Content

- `title`, `slug`, `body` (text)
- SEO fields: `metaTitle`, `metaDescription`
- `status` + `publishedAt` for scheduling

### 4.6 Media -- File Metadata

- `filename`, `originalFilename`, `mimeType`, `size`, `path`
- Display fields: `alt`, `title`
- Dimension fields: `width`, `height`

### 4.7 Setting -- Key-Value Store

- Unique `key`, `value`, `type`, `groupName`
- UI metadata: `label`, `description`, `sortOrder`

---

## 5. API Endpoints

### 5.1 App (Public, Read-Only)

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/app/categories` | List categories (enabled only) |
| GET | `/api/v1/app/categories/{id}` | Category detail |
| GET | `/api/v1/app/contents` | List contents |
| GET | `/api/v1/app/contents/{id}` | Content detail |
| GET | `/api/v1/app/tags` | List tags |
| GET | `/api/v1/app/tags/{id}` | Tag detail |
| GET | `/api/v1/app/comments` | List comments |
| GET | `/api/v1/app/comments/{id}` | Comment detail |
| GET | `/api/v1/app/pages` | List pages |
| GET | `/api/v1/app/pages/{id}` | Page detail |
| GET | `/api/v1/app/media` | List media |
| GET | `/api/v1/app/media/{id}` | Media detail |
| GET | `/api/v1/app/settings` | List settings |
| GET | `/api/v1/app/settings/{id}` | Setting detail |

### 5.2 Manage (Admin CRUD, ROLE_ADMIN)

All 7 entities have full CRUD:
- `GET /api/v1/manage/{resource}`
- `GET /api/v1/manage/{resource}/{id}`
- `POST /api/v1/manage/{resource}`
- `PUT /api/v1/manage/{resource}/{id}`
- `DELETE /api/v1/manage/{resource}/{id}`
- `POST /api/v1/manage/{resource}/batch-update` (with `@mode=mixed`)

### 5.3 App Controller commonFilter() Overrides

Each App controller scopes public access:

| Controller | commonFilter() |
|------------|---------------|
| CategoryController | `['enabled' => true]` |
| PageController | May filter by status |
| Others | Default (no filter) |

---

## 6. Service Architecture

All services extend `BaseService` + implement their `*ServiceInterface`:

```php
class CategoryService extends BaseService implements CategoryServiceInterface
{
    public function __construct(ContainerInterface $container, ...)
    {
        parent::__construct($container, Category::class, ...);
    }
}
```

Service interfaces all extend `BaseServiceInterface` (empty -- no custom methods by default).

---

## 7. Security

| Layer | Mechanism |
|-------|-----------|
| App endpoints | No auth required (read-only public access) |
| Manage endpoints | `#[IsGranted('ROLE_ADMIN')]` on class |
| Comment author | `commonFilter()` scoping (optional) |

---

## 8. ContentRepository Interface

`ContentRepository` is the only repository with an explicit interface:

```php
interface ContentRepositoryInterface
{
    public function findLatest(int $limit): array;
    public function findById(int $id): ?Content;
}
```

This allows other modules to consume content without depending on the concrete repository.

---

## 9. Database Migration

**Version**: `Version20250516000000`

Creates all 7 tables + the M:N join table `common_content_tag`. Adds `category_id` FK to the pre-existing `content` table (renamed from `content` to `common_content`).

---

## 10. Testing

| Suite | Tests |
|-------|-------|
| `tests/Common/Entity/` | Unit tests for all 7 entities |
| `tests/Common/Integration/` | API regression tests, batch update tests, comment API tests |
