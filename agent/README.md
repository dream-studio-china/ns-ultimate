# Admin AI assistant (initial prototype)

The assistant is an independent, optional frontend module. It provides a floating panel, locally persisted provider preferences and API key, automatic same-origin OpenAPI documentation loading on mount, config-first entity discovery, bounded authenticated read-only collection queries, allowlisted navigation to registered admin lists and record detail views, Markdown-rendered assistant replies (including GFM-style tables), and streamed documentation-grounded chat with an OpenAI Chat Completions-compatible endpoint.

Bounded record queries run without per-query confirmation. Queries are limited to documented GET collection endpoints that also match the composed admin entity config; text filters use substring matching, configured select filters use exact equality, and configured numeric/date fields support validated comparisons. Pagination is capped at 20 rows per request. Arbitrary URLs, filter expressions, and write methods are unavailable. Returned fields are projected from safe list columns and sensitive property names/types are excluded. Results are shown in a bounded table, include total/next-page metadata when available, and are sent to the configured model provider, as are the user's question and matching API-documentation excerpts.

Users can explicitly continue or refine the previous query in natural language, open the next page, download the current sanitized result page as formula-protected CSV, and open a listed record's read-only detail page. API answers display the matching operation paths and summaries as source references.

Navigation is available only when explicitly requested by the user and only to registered list pages or read-only record detail routes. It does not open create/edit routes or arbitrary URLs.

It does **not** execute admin actions. It has no write API client. Do not treat this assistant as an agent for modifying production data.

## Enable or disable

The integration loader is enabled by default. Set `VITE_ADMIN_AGENT_ENABLED=false` in the integration admin environment to disable it, then restart Vite or rebuild. This flag is public frontend configuration and must never contain secrets.

The launcher appears only while the existing admin authentication token is present. The integration session adapter keeps that token inside the host boundary and removes the assistant when the token changes or disappears.

## Configure

Open the assistant settings and enter:

- Provider base URL, using HTTPS or HTTP for localhost/private LAN addresses (including RFC 1918 and carrier-grade NAT IPv4 ranges, ULA/link-local IPv6, and `.local`/`.lan` names). LAN HTTP is unencrypted; use only on a trusted network.
- For a provider root URL with no path, the assistant uses the OpenAI-compatible `/v1/chat/completions` endpoint. For example, Ollama at `http://192.168.1.20:11434/` is normalized to `http://192.168.1.20:11434/v1/chat/completions`.
- Model name.
- The same-origin API document URL, defaulting to `/api/doc.json`.
- Optional API key. Leave blank for providers such as Ollama that do not require one.

Provider URL compatibility depends on the provider's CORS and Chat Completions implementation. Provider settings and the API key are stored in browser local storage and persist across page reloads. The API key is sent only to the configured provider; leave it blank for providers that do not require one. Clear it with the settings action when no longer needed. Local storage is accessible to scripts running on this origin, so only use this on a trusted browser/device. The assistant sends the user's question and matching API-documentation excerpts to the configured provider. Bounded read query results are also sent to the provider without an additional confirmation. The admin token is never sent to the provider. Chinese UI text follows the admin's `app_locale`; English is the fallback for other locales.

## Development checks

```sh
npm test --prefix agent
node --test integration/admin/*.test.mjs
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

See [`../docs/design/admin-ai-assistant.md`](../docs/design/admin-ai-assistant.md) for the target architecture, security policy, implementation gaps, and acceptance plan.
