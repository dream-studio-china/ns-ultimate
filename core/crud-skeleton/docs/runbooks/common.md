# Common Runbook

This runbook is the operational guide for configuring and operating the Common
module (CMS: categories, tags, contents, comments, pages, media, settings). It
complements [`design/bundles/common.md`](../design/bundles/common.md).

## 1. Concepts In One Minute

- **Common** provides 7 CMS entities: Category (tree), Tag, Content, Comment
  (polymorphic), Page, Media, Setting (KV).
- App endpoints are public read-only; Manage endpoints are admin CRUD.
- Media uploads are handled by the Storage module.

## 2. Entities

| Entity | Purpose |
|--------|---------|
| Category | Tree-structured taxonomy |
| Tag | Flat labels |
| Content | CMS content |
| Comment | Polymorphic comments |
| Page | Static pages |
| Media | Uploaded files (via Storage) |
| Setting | Key-value configuration |

## 3. App Endpoints (Public Read-Only)

| Method | Path |
|--------|------|
| GET | `/api/v1/app/categories[/{id}]` |
| GET | `/api/v1/app/contents[/{id}]` |
| GET | `/api/v1/app/tags[/{id}]` |
| GET | `/api/v1/app/comments[/{id}]` |
| GET | `/api/v1/app/pages[/{id}]` |
| GET | `/api/v1/app/media[/{id}]` |
| GET | `/api/v1/app/settings[/{id}]` |

## 4. Manage Endpoints (Admin CRUD)

All 7 entities support full CRUD:

```
GET/POST    /api/v1/manage/{resource}
GET/PUT/DELETE /api/v1/manage/{resource}/{id}
POST        /api/v1/manage/{resource}/batch-update
```

Resources: `categories`, `contents`, `tags`, `comments`, `pages`, `media`, `settings`.

## 5. Media Upload

Media uploads route through the Storage module. Configure the default storage driver
first (see the Storage runbook), then uploads via the media endpoints will use it.

## 6. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| App list empty | `enabled` filter | Check entity `enabled`/status |
| Media upload fails | Storage not configured | Configure Storage driver |
| Comment scope wrong | Polymorphic target mismatch | Check comment target type/id |

## 7. Checklist Before Going Live

- [ ] Storage driver configured for media.
- [ ] Categories/tags/pages/content seeded.
- [ ] Settings populated.
- [ ] App endpoints return expected public data.
