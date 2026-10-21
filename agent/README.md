# Admin AI assistant (initial prototype)

The assistant is an independent, optional frontend module. The current prototype provides a floating panel, local provider preferences, a session-only API key, same-origin OpenAPI documentation loading, and documentation-grounded chat with an OpenAI Chat Completions-compatible endpoint.

It does **not** query business records or execute admin actions yet. It has no write API client. Do not treat this prototype as an operational agent for modifying production data.

## Enable or disable

The integration loader is enabled by default. Set `VITE_ADMIN_AGENT_ENABLED=false` in the integration admin environment to disable it, then restart Vite or rebuild. This flag is public frontend configuration and must never contain secrets.

The launcher appears only while the existing admin authentication token is present. The integration session adapter keeps that token inside the host boundary and removes the assistant when the token changes or disappears.

## Configure

Open the assistant settings and enter:

- Provider base URL, using HTTPS (HTTP is permitted only for localhost).
- Model name.
- The same-origin API document URL, defaulting to `/api/doc.json`.
- API key for the current page session.

Provider URL compatibility depends on the provider's CORS and Chat Completions implementation. The API key remains in page memory and is cleared when the admin session changes or the page unloads. Provider settings are stored in local storage. The assistant sends the user's question and matching API-documentation excerpts to the configured provider; it does not send the admin token or business records. Chinese UI text follows the admin's `app_locale`; English is the fallback for other locales.

## Development checks

```sh
npm test --prefix agent
node --test integration/admin/*.test.mjs
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

See [`../docs/design/admin-ai-assistant.md`](../docs/design/admin-ai-assistant.md) for the target architecture, security policy, implementation gaps, and acceptance plan.
