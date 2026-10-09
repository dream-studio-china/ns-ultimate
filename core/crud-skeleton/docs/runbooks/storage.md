# Storage Runbook

This runbook is the operational guide for configuring and operating the Storage
module (file upload drivers). It complements
[`design/bundles/storage.md`](../design/bundles/storage.md).

## 1. Concepts In One Minute

- **Storage** abstracts file uploads behind `MediaStorageInterface`.
- Drivers: **LocalStorage** and **QiniuStorage**.
- The default driver is selected by configuration; drivers are auto-discovered via the
  `media.storage` tag.

## 2. Configuration

**File**: `config/packages/media.yaml`

```yaml
parameters:
    media.storage.default: 'local'

    media.local.upload_path: '%kernel.project_dir%/public/uploads'
    media.local.base_url: '/uploads'
```

**File**: `.env`

```ini
MEDIA_STORAGE_DEFAULT=local

QINIU_ACCESS_KEY=
QINIU_SECRET_KEY=
QINIU_BUCKET=
QINIU_DOMAIN=
```

## 3. Enable Local Storage

1. Set `MEDIA_STORAGE_DEFAULT=local`.
2. Ensure `media.local.upload_path` exists and is writable.
3. Set `media.local.base_url` to the public URL prefix.

## 4. Enable Qiniu Storage

1. Set `MEDIA_STORAGE_DEFAULT=qiniu`.
2. Fill `QINIU_ACCESS_KEY`, `QINIU_SECRET_KEY`, `QINIU_BUCKET`, `QINIU_DOMAIN`.
3. Leave Qiniu vars empty to disable the driver.

## 5. Troubleshooting

| Symptom | Likely cause | Fix |
|---------|--------------|-----|
| Upload fails | Upload path not writable | Fix permissions on `media.local.upload_path` |
| Qiniu upload fails | Missing/invalid credentials | Check Qiniu env vars |
| Wrong driver used | `MEDIA_STORAGE_DEFAULT` mismatch | Set the intended default driver |

## 6. Checklist Before Going Live

- [ ] Default driver selected.
- [ ] Local upload path writable (if local).
- [ ] Qiniu credentials set (if qiniu).
- [ ] Public base URL reachable.
