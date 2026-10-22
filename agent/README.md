# Admin AI assistant (initial prototype)

The assistant is an independent, optional frontend module. It provides a floating panel, locally persisted provider preferences and API key, automatic same-origin OpenAPI documentation loading on mount, config-first entity discovery, bounded authenticated read-only collection queries, allowlisted navigation to registered admin lists and record detail views, Markdown-rendered assistant replies (including GFM-style tables), and streamed documentation-grounded chat with an OpenAI Chat Completions-compatible endpoint.

Record queries run without per-query confirmation or assistant-level query-count/total-row quotas. Queries are limited to documented GET collection endpoints that also match the composed admin entity config; text filters use substring matching, configured select filters use exact equality, relation filters require statically configured options and validated identifiers, and configured numeric/date fields support validated comparisons. Dynamic relation option callbacks are not executed. Each request remains capped at 60 rows and 60 seconds; pagination is capped at page 100. Users can cancel an active task. A loop guard stops a task after 100 tool calls or repeated identical calls. Arbitrary URLs, filter expressions, and write methods are unavailable. Returned fields are projected from configured list columns, sensitive property names/types are excluded, and the result payload is capped before it is sent to the model provider. The user's question and matching API-documentation excerpts are also sent to that provider.

Users can explicitly continue or refine the previous query in natural language, open the next page, download the current sanitized result page as formula-protected CSV, and open a listed record's read-only detail page. API answers display the matching operation paths and summaries as source references.

The UI shows query conditions, configured sort order, browser retrieval time and duration. CSV export requires confirmation and includes only the current bounded page. A clear-conversation control removes chat and previous-query context. Conversation memory is bounded to 16 messages and 24,000 characters, and query results are not kept in conversation history. A page-session activity view keeps only entity, page, duration, row count and outcome category; it does not persist filter values or record contents.

Navigation is available only when explicitly requested by the user and only to registered list pages or read-only record detail routes. It does not open create/edit routes or arbitrary URLs.

It does **not** execute admin actions. It has no write API client. Do not treat this assistant as an agent for modifying production data.

## Update previews (phase one)

`preview_record_update` creates an unsaved, single-record diff only. The business-owned policy in `business/admin/agent-preview-policy.mjs` currently permits only `Counter.name` (non-empty string, at most 120 characters as a local preview constraint). No other entity, field, creation, deletion, or batch operation is enabled. The model must query the target in the same task, then provide an exact ID/UUID from the latest returned page. Missing, ambiguous, or potentially truncated current values are rejected; the model cannot supply the old value. Proposed values receive only local type/length/required checks, not backend authorization or business validation. There is no execution/confirmation button or write endpoint. Preview content is shown in a diff card and sent to the configured provider; an assistant summary may retain it within bounded conversation memory. Query snapshots are task-local and may already be stale. Example: ask to preview changing counter ID 7's name to a new name; no data will be saved.

The backend remains the authority for record and field authorization. Frontend route checks and sensitive-name filtering are defense-in-depth, not a replacement for server-side field-level grants. The activity view is not a tamper-resistant audit log and is cleared with the page/session; no backend audit endpoint is added by this module. Response size is bounded after the authenticated admin client receives the response, so server-side response-byte and query-cost limits remain deployment responsibilities.

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
