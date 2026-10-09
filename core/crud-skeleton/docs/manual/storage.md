# Media Storage & Qiniu

This document is the operational reference for the pluggable media storage layer.
It covers the storage drivers, the default driver selection, per-upload driver
overrides, and how to enable the optional Qiniu Kodo driver.

---

## Drivers

Media upload supports multiple storage drivers through
`App\Storage\Service\MediaStorageInterface`.

| Driver | Status | Notes |
|--------|--------|-------|
| `local` | Built in | Default driver. Stores files under `public/uploads/{YYYYMM}/...` and returns `/uploads/...` paths. |
| `qiniu` | Optional | Qiniu Kodo driver. Requires the Qiniu PHP SDK and `common_setting` records. |

## Default Driver

The default upload driver is controlled by:

```dotenv
MEDIA_STORAGE_DEFAULT=local
```

## Per-Upload Override

You can override the driver per upload by sending a multipart form field named `storage`:

```bash
curl -X POST http://localhost:8080/api/v1/manage/media/upload \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/photo.jpg" \
  -F "storage=qiniu"
```

## Enable Qiniu

The Qiniu SDK is intentionally not required by default. Install it only on
deployments that use `storage=qiniu`:

```bash
composer require qiniu/php-sdk
```

With Docker:

```bash
docker compose exec app composer require qiniu/php-sdk
```

For production compose commands, include your production compose files and env file:

```bash
docker compose -f compose.yaml -f compose.prod.yaml --env-file .env.prod.local exec app composer require qiniu/php-sdk
```

### Qiniu Credentials

Qiniu credentials are read from `common_setting`, not from `.env`. Create these
settings before using the driver:

| Key | Value |
|-----|-------|
| `qiniu.access_key` | Qiniu access key |
| `qiniu.secret_key` | Qiniu secret key |
| `qiniu.bucket` | Bucket name |
| `qiniu.domain` | Public bucket domain, for example `https://cdn.example.com` |

Use the console command to create any missing keys without overwriting existing values:

```bash
php bin/console app:storage:qiniu:settings:init \
  --access-key=<access-key> \
  --secret-key=<secret-key> \
  --bucket=<bucket> \
  --domain=https://cdn.example.com
```

With Docker:

```bash
docker compose exec app php bin/console app:storage:qiniu:settings:init \
  --access-key=<access-key> \
  --secret-key=<secret-key> \
  --bucket=<bucket> \
  --domain=https://cdn.example.com
```

Alternatively, create the settings through the manage settings API:

```bash
curl -X POST http://localhost:8080/api/v1/manage/settings \
  -H "Authorization: Bearer <admin-token>" \
  -H "Content-Type: application/json" \
  -d '[
    {"key":"qiniu.access_key","value":"<access-key>","type":"string","groupName":"storage","label":"Qiniu Access Key"},
    {"key":"qiniu.secret_key","value":"<secret-key>","type":"string","groupName":"storage","label":"Qiniu Secret Key"},
    {"key":"qiniu.bucket","value":"<bucket>","type":"string","groupName":"storage","label":"Qiniu Bucket"},
    {"key":"qiniu.domain","value":"https://cdn.example.com","type":"string","groupName":"storage","label":"Qiniu Domain"}
  ]'
```

If `storage=qiniu` is used without the SDK installed, the API returns a clear
runtime error asking you to install `qiniu/php-sdk`.
