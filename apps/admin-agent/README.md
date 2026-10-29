# Admin AI assistant app

An optional browser assistant composed into the existing admin application. It supports API-documentation Q&A, bounded record queries/content reads, navigation, attachments, local write previews and explicit UI-confirmed CRUD calls.

## Write capability ownership

The effective admin form configuration is the ordinary capability whitelist; `adapters/ns-ultimate/admin/write-policy.mjs` contains only host-specific prohibitions, explicitly injected into the generic transport. The host reuses existing form metadata, relation conversion and the authenticated CRUD request client. No per-entity Agent PHP adapter, static field allowlist or build-generated capability manifest is required; the small exception map removes only fields that the corresponding controller ignores and sensitive identity fields.

Only the user can confirm submission. The model can prepare a proposal, never execute it. The host rechecks configuration and relevant current values before submitting the retained payload. Ordinary backend permissions and business validation remain authoritative. Unsupported upload controls, custom callbacks/actions and embedded managers are reported rather than interpreted as ordinary writable fields. Optional unsupported nested controls are omitted while supported siblings remain writable; a required unsupported control still blocks creation.

The host applies the same value semantics as the admin form where the API contract differs: currency controls accept major units and submit the configured minor-unit multiplier, nested JSON-schema constraints and dependencies are validated before preview, User `plainPassword` is submitted as `password`, OrderCounter `counter` as `counterUuid`, and Store status uses its dedicated status endpoint rather than the ignored collection field. Actions with no documented CRUD route (for example Invoice update) are not advertised. Fields that the controller silently ignores are omitted from that action.

Wallet/configuration/permission management and existing-user changes remain prohibited. Store WeChat bindings, WeChat credentials and existing WeChat-user changes are prohibited; new WeChat users expose only safe profile fields. New users may be created from configured fields without assigning roles or permissions. Deletion retains its separate Category/ProductCategory/Tag/Comment whitelist and normal backend behavior, and Tag deletion checks Content references before preparation and submission.

**Compatibility change:** writes no longer use the Agent-specific operation backend. There is no durable operation receipt, additional transaction, atomic conflict guarantee or cross-session deduplication. The [contract](../../docs/contracts/admin-agent.md) describes the exact boundary.

## Operation recovery

### Browser chat sessions

The sidebar's Chat sessions view supports creating, switching and deleting up to 20 local conversations. User/assistant text and attachment filename summaries persist in local storage across refreshes. Each conversation is bounded to 24,000 characters. Oldest conversations are evicted when the limit is reached. Clear conversation deletes the active saved chat; New chat preserves it and starts another.

The composed host partitions history by account ID, without storing authentication tokens. This is local organization, not encryption or an authorization boundary: other same-origin scripts and users of the browser profile may access the data. Chat text can contain sensitive content, including details quoted by the model. Delete chats when using a shared device. Missing account identity or unavailable storage falls back to page memory.

Restoration includes chat text only, not original attachments, raw tool results, queries, previews, confirmation cards or operation receipts. Restored text is historical, not fresh evidence. Switching is blocked during active tasks or unresolved writes, invalidates pending confirmations and never replays requests.

### Mutation outcomes

Operations are current-session memory only. Refresh, sign-out and session disposal lose proposals and receipts. The Operations panel states this explicitly. Successful CRUD responses are acknowledged saves, not independently verified read-backs.

Timeouts and other ambiguous submission failures stay unknown. Checking the local outcome cannot reconstruct a server receipt or prove success from matching current data. Inspect the ordinary admin page manually and never automatically retry. Unknown operations keep the confirmation/write lock. Cancel/discard cannot undo a request already sent.

The entire assistant backend package and old HTTP operation/capability routes are removed. After clearing backend caches, apply project migrations to permanently drop both retired proposal/receipt tables:

```sh
php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

This irreversibly deletes all legacy assistant proposals and receipts. Original migration identities are retained under the integration legacy-migrations directory, not in an Agent backend package. No migration runs automatically. See the [retirement contract](../../docs/contracts/admin-agent.md#operation-history-and-legacy-retirement).

## Batch-create file import

Attach supported text or an Office table and explicitly ask for creation. Every configured create capability can prepare 1–20 rows; all rows must be reviewed and confirmed. There is no batch-update/delete tool.

Rows use sequential ordinary create requests, **not an all-or-nothing transaction**. A failure can leave successful earlier rows. The UI reports successful, uncertain and unsubmitted rows. Do not retry the whole batch automatically.

Office files are limited to 5 MiB, 20 data rows, 64 columns and 4,096 characters per cell. XLSX reads the first worksheet; DOCX reads the first supported non-empty body table. Ignored content produces warnings. Formulas, active content, external relationships and unsupported structures fail explicitly. Deflated Office parsing needs `DecompressionStream('deflate-raw')`. This is a restricted table reader, not an Office interpreter or a record-media upload flow.

## Images and file input

Selection and local preview do not send files. Explicit Send discloses supported content to the selected model; images additionally require opting into vision. Attachments are untrusted data, never authorization or tool instructions.

Supported inputs include static PNG/JPEG/WebP/GIF, strict UTF-8 text/code/CSV/JSON/Markdown, and restricted XLSX/DOCX tables. PDF, SVG, HTML/XML, animated images, general archives and arbitrary binaries are rejected. Limits are four files, 5 MiB each, 10 MiB combined; transformed text has 128 KiB per-file and 256 KiB combined limits. Images are bounded to 4,096 pixels per side and 16 megapixels. Serialized provider requests, including tool rounds, are limited to 16 MiB.

Original contents are not stored in preferences or later conversation history; only bounded filename summaries survive. Already-sent provider content cannot be retracted by cancellation.

## Nested configuration inspection (read-only)

Read queries and exact Content/Page body reads retain their separate bounded policies. Selected Store/Product configuration inspection exposes fixed scalar paths only, never arbitrary settings, credentials or private contact data. Cached configuration does not prove worker/device status. Read tools perform no writes and cannot authorize mutations.

## Printing rules and store operations

Order print rules and printer templates are ordinary configured CRUD. Rule and template changes require the operator's explicit UI confirmation, affect future automatic printing only, and remain subject to backend validation. Printer accounts and jobs are prohibited, and device creation is prohibited because the ordinary POST registers with the provider. The assistant cannot run `app:printer:dispatch-orders`, refresh provider status, submit a print, or reprint a job, and cannot verify physical output. Template item filters and rule conditions are data, not instructions. Legacy `Store.settings.printing` is no longer read. Curated guidance lives in `adapters/ns-ultimate/admin/knowledge/printer-auto-printing.mjs`, `printer-templates.mjs`, `printer-setup.mjs` and `counter.mjs`.

## Package and adapter boundaries

- `src/`: generic assistant and config-driven local mutation host.
- `adapters/ns-ultimate/admin/`: scalar component bindings, read policies and curated knowledge.
- `integration/admin/agent-host.js`: existing admin request/router/metadata composition.
- No assistant backend package remains; integration owns only legacy migration history and the table-retirement migration.

See the [design](../../docs/design/admin-ai-assistant.md) and [contract](../../docs/contracts/admin-agent.md).

## Enable or disable

Set `VITE_ADMIN_AGENT_ENABLED=false` in the integration admin environment, then restart Vite or rebuild. The launcher requires the existing authentication token. The integration session monitor disposes/remounts the assistant when the token changes or disappears.

## Configure

Choose DeepSeek, OpenCode Go, Ollama, or Custom (OpenAI-compatible). The selection fills a default endpoint and model suggestion; both remain editable. Ollama defaults to `http://localhost:11434/v1` and does not send an API key. OpenCode Go defaults to `https://opencode.ai/zen/go/v1` with model `deepseek-v4.1-flash` and sends the active chat ID in `x-opencode-session`. DeepSeek defaults to `https://api.deepseek.com/v1`. Custom defaults to the OpenAI endpoint. Enter the same-origin API document URL (default `/api/doc.json`) as before. Root provider URLs use `/chat/completions` when the path already includes `/v1`, or the existing normalizer appends it. HTTPS is required except for localhost/private LAN providers; LAN HTTP is unencrypted. CORS and Chat Completions compatibility depend on the provider.

Provider settings and optional keys persist in browser local storage. Use a trusted browser/device; same-origin scripts can access local storage. The admin token is never sent to the provider. Questions, selected documentation/knowledge excerpts, explicitly sent attachments and bounded read results are disclosed to the selected model. Read results do not require additional per-query confirmation. Chinese UI follows `app_locale`, with English fallback.

## Development checks

```sh
node --test apps/admin-agent/tests/*.test.mjs integration/admin/*.test.mjs scripts/*.test.mjs
php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml
make phpstan
node scripts/check-doc-links.mjs
```

Build with the composed Vite configuration from `core/crud-admin`:

```sh
./node_modules/.bin/vite build --config ../../integration/admin/vite.config.ts
```

### Repeatable mounted-browser acceptance

With composed Vite running, use a **new isolated browser context**, never an authenticated admin tab:

```text
http://127.0.0.1:9528/@fs<absolute-repository-path>/apps/admin-agent/tests/browser/fixture.html
```

Wait for `window.__NS_ADMIN_AGENT_ACCEPTANCE__?.promise`, await it, and require all cases to pass with clean setup/cleanup and restored descriptors. The harness uses synthetic hosts/providers/data; legacy protocol fixtures test generic host compatibility, not the current deployment's backend. Node tests do not mount this browser suite. Neither synthetic suite establishes real-login authorization, live-model behavior or production-database concurrency.
