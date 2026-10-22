# Admin AI assistant app

The assistant is an independent, optional frontend module. It provides a floating panel, locally persisted provider preferences and API key, automatic same-origin OpenAPI documentation loading on mount, config-first entity discovery, bounded authenticated read-only collection queries, allowlisted navigation to registered admin lists and record detail views, Markdown-rendered assistant replies (including GFM-style tables), and streamed documentation-grounded chat with an OpenAI Chat Completions-compatible endpoint.

Record queries run without per-query confirmation or assistant-level query-count/total-row quotas. Queries are limited to documented GET collection endpoints that also match the composed admin entity config; text filters use substring matching, configured select filters use exact equality, relation filters require statically configured options and validated identifiers, and configured numeric/date fields support validated comparisons. Dynamic relation option callbacks are not executed. Each request remains capped at 60 rows and 60 seconds; pagination is capped at page 100. Users can cancel an active task. A loop guard stops a task after 100 tool calls or repeated identical calls. Arbitrary URLs, filter expressions, and write methods are unavailable. Returned fields are projected from configured list columns, sensitive property names/types are excluded, and the result payload is capped before it is sent to the model provider. The user's question and matching API-documentation excerpts are also sent to that provider.

Users can explicitly continue or refine the previous query in natural language, open the next page, download the current sanitized result page as formula-protected CSV, and open a listed record's read-only detail page. API answers display the matching operation paths and summaries as source references.

The UI shows query conditions, configured sort order, browser retrieval time and duration. CSV export requires confirmation and includes only the current bounded page. A clear-conversation control removes chat and previous-query context. Conversation memory is bounded to 16 messages and 24,000 characters, and query results are not kept in conversation history. A page-session activity view keeps only entity, page, duration, row count and outcome category; it does not persist filter values or record contents.

Navigation is available only when explicitly requested by the user and only to registered list pages or read-only record detail routes. It does not open create/edit routes or arbitrary URLs.

The model does **not** submit writes. Registered host adapters can enable user-confirmed single-record create/update/delete operations. Review the full diff and confirm in the UI; deletion requires typing the exact confirmation phrase. Proposals expire after five minutes. Network cancellation after submission may leave the outcome unknown; use the status check instead of creating another operation. The app backend owns one generic `AgentOperation` entity/table for all adapters, not one operation entity per business resource. See the [operation contract](../../docs/contracts/admin-agent.md) for endpoints, migration and retention requirements. Real authenticated browser verification remains outstanding.

## Operation recovery

When a commit explicitly reports that the record changed or was deleted, its card explains that the proposal was not saved and disables further confirmation. Discard it and request a fresh preview; chatting is not blocked by this definitive conflict. Timeouts, disconnections and unclassified errors still require an outcome check and never trigger automatic retries. A pending outcome is revalidated against the original snapshot before the same card can be confirmed again.

Use **Operations** to retrieve the current account's server-owned receipts after a reload or a new login. Pages contain at most 20 records (up to page 100), newest first, with entity, action, target, field names, timestamps and status only; proposal values are not listed. Disabled or inaccessible adapters are omitted. Status reflects expiry even before the pruning job erases values.

**Check outcome** refreshes a receipt without repeating its mutation. **Restore preview** reads an unexpired pending operation, rechecks permissions and domain validation, and compares its original target snapshot and canonical changes. Changed, completed, cancelled or expired operations cannot be restored. A successful restore shows the original operation ID and expiry with a newly reconstructed full diff; it creates no replacement operation and requires a fresh explicit confirmation. Commit still performs its own conflict checks. Recovery requests are serialized with chat/write activity and cancelled on session disposal. Receipts and restored previews are UI-only, not model tools, and no recovery payload or account key is persisted in browser storage. This adds routes and response metadata, not a database migration.

## Update previews (phase one)

`preview_record_update` is a separate, local-only preview tool. The model must first query the target in the same task and provide an exact ID/UUID from the latest returned page. Missing, ambiguous, or potentially truncated old values are rejected. This tool cannot submit changes and does not validate backend authorization or business rules. Its policy comes from the app's host adapter, not the generic assistant code. Preview values are sent to the configured provider and may appear in bounded conversation summaries.

## Package and adapter boundaries

- `src/`, `tests/`, `package.json`: generic browser UI, tools, provider integration and tests.
- `backend/src/` outside `Adapter/`: generic operation lifecycle, registry, actor-provider interface, routes, entity and pruning command.
- `adapters/ns-ultimate/admin/`: host-specific frontend capability descriptions and Counter.name local-preview policy.
- `backend/src/Adapter/NsUltimate/`: host-specific Content mutation adapter, reusing existing business entities and validation without modifying business modules.
- `backend/tests/Integration/Adapter/NsUltimate/`: composition tests for that binding, separate from generic lifecycle tests.
- `backend/migrations/`, `backend/config/`, `backend/translations/`: app-owned schema and Symfony configuration.
- `backend/composer.json`: embeddable backend package manifest. This repository deliberately reuses the locked core dependencies; it does not install a second backend runtime.
- `integration/`: explicitly supplies app autoloading, service/route/mapping registration, stable actor identity, admin authentication and frontend build wiring. It explicitly enables the app-owned Content adapter; host adapters are excluded from generic service discovery. There is no automatic discovery of arbitrary apps.
- `business/` and `core/`: existing host domain models and validation, consumed but not modified for agent features. Agent-specific extensions stay in the app. Currently only Content create/update/delete and Counter.name local-only preview are enabled.

To add a writable resource, implement `MutationAdapter` in an app-owned host adapter directory and supply its capability descriptor. Explicitly enable the adapter through integration; do not add agent code to business modules or core. The adapter authorizes access and owns lookup, canonical snapshots, agent-specific field restrictions, row locking and read-back verification while reusing existing domain validation. The generic registry rejects unknown or duplicate keys; caller input never resolves PHP classes or arbitrary URLs. The app can be embedded in another compatible Symfony host by importing its service configuration, mapping, migrations and routes, and providing `ActorIdentityProvider` plus explicitly selected host adapters. It is an application package, not an independently running service in this deployment. Removing its registration must not require business-module edits; ordinary business CRUD remains independent of the agent.

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
npm test --prefix apps/admin-agent
node --test integration/admin/*.test.mjs
php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

Before using confirmed mutations, review pending migrations and apply the app's generic operation migration through `integration/backend/bin/console`. Schedule `app:admin-agent:prune` to erase expired proposal payloads. No environment database is migrated automatically by tests or source relocation. The previous uncommitted Content-specific operation table was never applied in this development session; if an environment independently applied that draft migration, inspect it and plan a data-preserving migration instead of blindly creating or dropping tables.

See the [design](../../docs/design/admin-ai-assistant.md) for security policy, implementation gaps and acceptance plans.
