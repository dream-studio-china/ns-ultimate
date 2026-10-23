# Admin AI assistant app

The assistant is an independent, optional frontend module. It provides a floating panel, locally persisted provider preferences and API key, automatic same-origin OpenAPI documentation loading on mount, config-first entity discovery, bounded authenticated read-only collection queries, allowlisted navigation to registered admin lists and record detail views, Markdown-rendered assistant replies (including GFM-style tables), and streamed chat with an OpenAI Chat Completions-compatible endpoint. In the ns-ultimate host, explicitly curated business guides are bundled with the frontend, searched locally, and only relevant bounded excerpts are sent as untrusted reference data; selected references and their repository source paths are shown separately from API sources.

Record queries run without per-query confirmation or assistant-level query-count/total-row quotas. Queries are limited to documented GET collection endpoints that also match the composed admin entity config; text filters use substring matching, configured select filters use exact equality, relation filters require statically configured options and validated identifiers, and configured numeric/date fields support validated comparisons. Dynamic relation option callbacks are not executed. Each request remains capped at 60 rows and 60 seconds; pagination is capped at page 100. Users can cancel an active task. A loop guard stops a task after 100 tool calls or repeated identical calls. Arbitrary URLs, filter expressions, and write methods are unavailable. Returned fields are projected from configured list columns, sensitive property names/types are excluded, and the result payload is capped before it is sent to the model provider. The user's question and matching API-documentation excerpts are also sent to that provider.

Users can explicitly continue or refine the previous query in natural language, open the next page, download the current sanitized result page as formula-protected CSV, and open a listed record's read-only detail page. API answers display the matching operation paths and summaries as source references.

The UI shows query conditions, configured sort order, browser retrieval time and duration. CSV export requires confirmation and includes only the current bounded page. A clear-conversation control removes chat and previous-query context. Conversation memory is bounded to 16 messages and 24,000 characters, and query results are not kept in conversation history. A page-session activity view keeps only entity, page, duration, row count and outcome category; it does not persist filter values or record contents.

Navigation is available only when explicitly requested by the user and only to registered list pages or read-only record detail routes. It does not open create/edit routes or arbitrary URLs.

The model does **not** submit writes. Registered host adapters enable user-confirmed single-record operations through one generic `AgentOperation` entity/table, not one operation entity per resource. Review the full diff and confirm in the UI; deletion requires typing the exact confirmation phrase. Proposals expire after five minutes. Network cancellation after submission may leave the outcome unknown; use the status check instead of creating another operation. Host packages cover Content, Counter, Category, Tag, ProductCategory, Product, Specification and Store, plus separate `StorePrinting` and `ProductPrinting` configuration updates; see the [operation contract](../../docs/contracts/admin-agent.md#business-capability-packages) for exact actions and fields. Product and Specification hard deletion remain blocked. Capability descriptions are not proof of authorization: each backend adapter validates permissions and domain constraints independently. Saving printing rules creates no job and prints nothing immediately, but affects future automatic printing. No agent mutation submits printing, payment, inventory adjustment, order workflow or other external actions. Reprint and provider/device operations remain manual-only. The bounded local acceptance below distinguishes verified behavior from remaining gaps.

## Verification status

Date: 2026-10-22. Environment: local MySQL 8.0.41 (InnoDB, `REPEATABLE-READ`) through the composed dev backend, using a disposable schema that was dropped afterwards.

- The generic operation migration was already applied; the table mapping matched the schema and the app backend tests passed against MySQL after the field-order fix below.
- Eight simultaneous commits of one prepared Content create, served by four loopback worker processes, all returned `succeeded` with the same target and produced exactly one record.
- That run exposed one portability defect: MySQL's `JSON` type reorders stored object keys, so a restored preview listed differences in a different order than the original preview. The Content adapter now emits differences in its declared field order. SQLite-only tests did not detect this.
- This isolated run did not verify browser sessions or live model behavior; see the later Counter acceptance below. Production MySQL topologies, multi-writer optimistic locking outside the adapter, and prune timing under long-running operations remain unverified.

### Counter browser acceptance

On 2026-10-22, the local authenticated admin and configured live model completed a Counter name-only update, followed by a fresh query confirming the changed name. A second proposal restored the original name; reloading the page, retrieving Operations, restoring that same pending preview, and explicitly confirming it succeeded. A fresh query confirmed the original name was restored. Both successful receipts remained available, and no Counter creation/deletion, other business-field changes, or external actions were performed. The setter also updates `updatedAt` as part of normal domain behavior. The model initially attempted an unsupported ID list filter, which was rejected; a subsequent unfiltered query succeeded. See the subsequent bounded concurrency verification below; this browser run did not itself test lock contention.

### Write-boundary hardening

On 2026-10-22, the composed SQLite suite passed 196 tests (4,316 assertions), including same-actor commit-time role revocation and conflicts after raw-SQL changes outside Doctrine's managed state. The frontend suite passed 101 tests, including post-dispatch cancellation with late success or an unknown outcome; neither path automatically retries a write.

The hardened opt-in MySQL harness passed two four-worker races on local MySQL 8.0.41, InnoDB, `REPEATABLE-READ`: one shared proposal returned successful receipts with exactly one instrumented adapter execution; independent proposals from one snapshot produced one success and three proposal conflicts, with the persisted name matching the winning proposal. Effective connection identity was checked before application writes; the disposable schema and per-run cache were verified removed. Authorization is stubbed in this direct-service harness, which uses numeric targets; it does not establish HTTP authorization, UUID-path concurrency, production topology, or other adapters' concurrency. A prior harness version lacked effective-connection guards and is not relied on for isolation evidence. See the [safe execution workflow](../../docs/operations/development.md#optional-mysql-verification-for-the-admin-agent).

A live-model browser cancellation check prepared then discarded a Counter proposal: confirmation became disabled, a fresh query retained the original name, and Operations reported cancellation. In a separate isolated browser context, the real mounted UI with fake host callbacks aborted an in-flight preview restore on disposal; resolving its old promise after a fresh mount exposed neither the sentinel value nor a confirmation control and invoked no commit. This simulates session disposal, not a real two-account switch. The later [repeatable browser fixture](#repeatable-mounted-browser-acceptance) exercises rendered delayed query results through a synthetic host; real delayed authenticated-read acceptance remains outstanding.

The procedure and its destructive-schema warning are documented in [local development](../../docs/operations/development.md). Do not repeat it against a development or production database.

### Business-package verification

The prior business packages, excluding the new printing/attachment package, passed the composed SQLite suite (242 tests, 4,647 assertions), frontend tests (103), admin integration tests (60), and the composed production build. Final Sol reviews found no remaining blockers after preview/default/scope fixes. The opt-in cross-layer test exercised 54 real prepared/restored backend previews for 27 mutations, all then-enabled 20 entity/action combinations and eight entities through the actual frontend preparation/recovery policy. It covered global/store scopes, redacted Store objects, and 255-code-point Product/Specification names; it used disposable SQLite and no frontend commit transport.

The extended MySQL 8.0.41 harness passed both original four-worker Counter races, two two-worker hierarchy races, and persisted multi-field JSON restoration with a fresh entity manager. Each hierarchy starts with `B → C` and `D → A` and races `A → B` against `C → D`: exactly one succeeds, one conflicts, and the final graph is acyclic with the winning edge persisted. Per-worker instrumentation rows avoid artificial serialization; disposable-schema cleanup was verified. This proves the participating adapters' bounded schedule, not safety against nonparticipating legacy writers, production topologies or HTTP authorization. Product/Specification hard deletion remains disabled. Broad browser coverage and real two-account switching remain outstanding.

Subsequent StorePrinting shared/independent-proposal and account-dependency MySQL checks are recorded separately in the [follow-up acceptance record](../../docs/sessions/2026-10-23-admin-agent-follow-up-acceptance.md). They create no print jobs and do not call providers; the authorization and production-topology limitations still apply.

The authenticated local browser and configured live model also completed one synthetic Tag lifecycle: exact-slug absence check, create preparation and UI confirmation, read-back, name/color update preparation and UI confirmation, read-back, then deletion preparation and the exact `DELETE 1` confirmation phrase. All three cards reported success. A final exact-slug query returned zero records without truncation, confirming cleanup of the synthetic tag. No other business records were changed in this acceptance. This representative lifecycle does not establish browser coverage for every package, role or recovery path; the earlier Counter recovery/cancellation evidence remains separate.

## Write capability ownership

The authenticated backend capability catalog owns write descriptions; the frontend compiles validators and model-tool descriptions from the same session-local runtime snapshot. It does not contain a second handwritten field table and does not fall back to a bundled policy if catalog loading fails. Mutation capability discovery is separate from record-level authorization, which is rechecked on the backend for each operation.

The host refreshes the catalog before each user turn and uses a short in-memory cache for other accesses. A changed revision or failed refresh revokes pending confirmation permissions, but preserves submitting/unknown operations, their locks and outcome-check controls. Status recovery does not require metadata; returning to confirmation requires a successful current-policy preview restoration. Session disposal aborts catalog requests and ignores late results. Unsupported schema versions, rule operands or regex patterns disable writes rather than being guessed or evaluated dynamically.

For the Tag/Counter pilot, reusable business write services provide field schemas and shared operations. The app explicitly allowlists which actions and fields the assistant may use, so changing an existing allowed field rule flows into the catalog, while adding a new domain field does not automatically expose it. Complex host-specific safety semantics remain explicit adapters; the other entities' descriptions are centralized backend app policies, not automatically inferred business contracts.

Ordinary Tag administration retains its existing controller and serializer behavior. Counter administration reuses only the compatible Store existence lookup; its established validation/coercion remains unchanged. Agent single/batch writes share the new services, but retain additional app-owned restrictions such as requiring an active Counter Store. This is a bounded extraction, not a claim that all normal-admin and agent validation has been unified.

When extending assistant writes, update the authoritative business schema or backend app policy, explicitly opt in new fields/actions, and run the catalog export/drift and real-preview contract tests. Generated test fixtures are test resources, not production fallbacks. Catalog revisions fingerprint descriptors only; they are not persisted semantic-policy versions on proposals. Existing server restore/commit revalidation still applies.

Regenerate the catalog fixture with the command documented in `tests/fixtures/capabilities/README.md` after an intentional descriptor change, then run `tests/backend-capability-catalog.integration.mjs` to compare it with the real backend export. Adding a new rule type still requires support in the generic frontend compiler; dynamic metadata does not make arbitrary business semantics executable.

Deploy the corresponding backend catalog endpoint with this frontend and clear composed backend caches after service-registration changes. This refactor needs no new database migration. An older/unavailable backend leaves writes disabled rather than falling back to stale client policy. Batch receipt tracking currently retains per-operation policy references until its session host is released; those references contain schemas, not submitted row values.

## Batch-create file import

Attach a TXT, CSV, XLSX or DOCX file with the normal attachment control and tell the assistant what to create, then press **Send**. There is no separate Import button or field-mapping wizard. The assistant interprets the supplied content and prepares a server-validated Tag or Counter batch; ambiguous entities, missing required values or Store references require clarification, not invented identifiers. Counter batches require one existing active Store UUID; numeric Store IDs are not accepted. Each batch contains 1–20 rows of one entity. Names are not a uniqueness key: Tag slugs and Counter codes within the same Store must not conflict.

Office file parsing is local; selection does not upload or send anything. **Send** discloses the parsed table and parser warnings, or the selected text file's content, to the configured model as untrusted attachment text. The model can prepare a proposal but cannot commit it. Original Office bytes are not uploaded to the provider or a file-storage endpoint. Later conversation turns retain filename summaries only, not parsed tables or file contents. Legacy `.doc`/`.xls`, actual `.xsl`, arbitrary binary files, batch updates and deletion remain unavailable.

The Office parser accepts files up to 5 MiB, 20 data rows, 64 columns and 4,096 characters per cell. TXT/CSV continue through the normal strict UTF-8 text attachment path. XLSX reads the first worksheet; DOCX reads the first non-empty body table, using its first row as headers. Additional sheets/tables and ignored document content produce warnings included with the parsed attachment: inspect those warnings and split the source file when needed. Prose-only Word documents are not a record-extraction feature. Dates/styles are not converted to displayed spreadsheet values. Formulas, merged cells, macros, embedded objects, encrypted archives, external relationships (including ordinary hyperlinks) and unsupported structural features are rejected. Deflated Office files require browser `DecompressionStream('deflate-raw')` support. Unsupported files fail explicitly rather than being sent to a model for unrestricted interpretation.

Review every canonical row and field in the server preview, check the confirmation box, and explicitly confirm creation. Request corrections in chat rather than using a separate import editor. The backend saves all rows and the receipt in one transaction or none; it never overwrites a conflicting record. An ambiguous commit result requires **Check outcome** before further work. A pending result must restore the same preview and receive another explicit confirmation; do not create a replacement batch to work around uncertainty.

Batch endpoints require the additional app migration `Version20261023120000` through the composed backend workflow; tests do not apply it to any environment database. Review and apply migrations deliberately before using this feature. See the [batch contract](../../docs/contracts/admin-agent.md#explicit-batch-creation) for lifecycle and ownership guarantees.

## Images and file input

The composer accepts file selection, drag/drop and pasted images, shows local raster-image thumbnails, and lets users remove attachments before sending. Pasting text or non-image files is left to the browser and does not create an attachment. Selecting or previewing a file does not upload it. **Send** discloses the selected content to the configured model provider, not to an application file-storage endpoint. Cancellation cannot retract content already transmitted. Chat attachments do not upload a Content cover or grant additional tools, record permissions or write approval.

Supported inputs are static PNG, JPEG, static WebP/GIF, strict UTF-8 text/code/CSV/JSON/Markdown files, and restricted XLSX/DOCX tables parsed locally into text. PDF, SVG, HTML/XML, animated PNG/WebP/GIF, general archives and arbitrary binaries are not supported; they fail explicitly rather than being silently truncated or converted. Limits are four files, 5 MiB original bytes each, 10 MiB original bytes combined; text, including transformed Office table text and warnings, additionally has 128 KiB per-file and 256 KiB combined limits. Images are bounded to 4,096 pixels per side and 16 megapixels. Every serialized provider request, including tool rounds, is limited to 16 MiB.

Image understanding requires explicitly enabling the vision preference, which defaults off. Local previews work without it, but sending images is rejected until enabled. This preference declares intended provider support; it does not make a text-only model understand images. The client uses Chat Completions image data parts, not a simulated provider file-upload API. Model-authored Markdown images remain alt text instead of automatically loading remote images.

Raw attachment text and image data are not stored in preferences or retained conversation context. Later turns retain bounded filename summaries rather than resending prior content. The mounted page may retain bounded image previews until eviction or conversation/session cleanup; no physical printer or external file-storage integration is involved.

Raster checks enforce bounded container structure and browser decoding, not a custom complete pixel codec. Uncommon JPEG/WebP variants may fail closed. Local mocked-browser acceptance does not establish compatibility with every provider/model; see the [printing and attachment verification record](../../docs/sessions/2026-10-23-admin-agent-printing-attachments.md).

## Operation recovery

When a commit explicitly reports that the record changed or was deleted, its card explains that the proposal was not saved and disables further confirmation. Discard it and request a fresh preview; chatting is not blocked by this definitive conflict. Timeouts, disconnections and unclassified errors still require an outcome check and never trigger automatic retries. A pending outcome is revalidated against the original snapshot before the same card can be confirmed again.

Use **Operations** to retrieve the current account's server-owned receipts after a reload or a new login. Pages contain at most 20 records (up to page 100), newest first, with entity, action, target, field names, timestamps and status only; proposal values are not listed. Disabled or inaccessible adapters are omitted. Status reflects expiry even before the pruning job erases values.

**Check outcome** refreshes a receipt without repeating its mutation. **Restore preview** reads an unexpired pending operation, rechecks permissions and domain validation, and compares its original target snapshot and canonical changes. Changed, completed, cancelled or expired operations cannot be restored. A successful restore shows the original operation ID and expiry with a newly reconstructed full diff; it creates no replacement operation and requires a fresh explicit confirmation. Commit still performs its own conflict checks. Recovery requests are serialized with chat/write activity and cancelled on session disposal. Receipts and restored previews are UI-only, not model tools, and no recovery payload or account key is persisted in browser storage. This adds routes and response metadata, not a database migration.

## Update previews (phase one)

`preview_record_update` is a separate, local-only preview tool. The model must first query the target in the same task and provide an exact ID/UUID from the latest returned page. Missing, ambiguous, or potentially truncated old values are rejected. This tool cannot submit changes and does not validate backend authorization or business rules. Its policy comes from the app's host adapter, not the generic assistant code. Preview values are sent to the configured provider and may appear in bounded conversation summaries.

## Nested configuration inspection (read-only)

`inspect_record_configuration({entity, identifier})` reads one record's allowlisted configuration scalars through its documented GET detail endpoint. The generic helper (`src/inspect.js`) is business-name-free: it validates the exact numeric ID/UUID, resolves the fixed detail path only when the OpenAPI document contains the GET detail route and the composed entity config marks the collection readable, performs one authenticated GET with navigation availability checks, cancellation, and a 30-second timeout, then projects only the host policy's scalar nested paths into a 2,000-character configuration result. Caller-supplied URLs, fields, and JSON paths are rejected; unknown entities, identifiers, routes, methods, malformed or oversized projections fail closed. For allowlisted values not projected, bounded diagnostics distinguish missing paths, null ancestors, invalid non-scalars, and oversized strings. Diagnostic paths come only from validated host policy and include no raw values; count and serialized diagnostics are bounded. A missing setting in a successful response is not evidence of a permission failure, disabled state, or business default. This explicitly expands assistant-readable fields for selected records; it grants no backend permissions and adds no write or navigation capability. Selected configuration values are sent to the configured model provider without additional per-read confirmation. The projection cap applies after the authenticated client receives the full detail response; server-side response-size limits remain the host's responsibility.

The ns-ultimate host policy (`adapters/ns-ultimate/admin/inspection-policy.mjs`) currently allows Store printing configuration (`code`, `name`, `status`, `settings.order.requireAcceptance`, and per-case `enabled`/`deviceUuid`/`templateUuid`/`copies` for the five fixed `settings.printing` cases) and the Product kitchen-print flag (`name`, `status`, `settings.kitchenPrinterEnable`). Credentials, contact data, full settings blobs, snapshots, and metadata are never returned. The UI shows the values with their retrieval time; the model prompt treats the result as cached backend state, not live device or scheduler state, and forbids inferring scheduler activity or a complete root cause from it.

## Package and adapter boundaries

- `src/`, `tests/`, `package.json`: generic browser UI, tools, provider integration and tests.
- `backend/src/` outside `Adapter/`: generic operation lifecycle, registry, actor-provider interface, routes, entity and pruning command.
- `adapters/ns-ultimate/admin/`: host-specific, per-action field/value policies and the separate local Counter.name preview policy.
- `adapters/ns-ultimate/admin/knowledge/`: explicitly selected, frontend-bundled ns-ultimate task and support guides. These are user-visible reference material, not confidential documents or permissions; changes ship with the frontend build.
- `backend/src/Adapter/NsUltimate/`: explicitly selected business mutation adapters, reusing existing domain entities and validation without modifying business modules.
- `backend/tests/Integration/Adapter/NsUltimate/`: composition tests for that binding, separate from generic lifecycle tests.
- `backend/migrations/`, `backend/config/`, `backend/translations/`: app-owned schema and Symfony configuration.
- `backend/composer.json`: embeddable backend package manifest. This repository deliberately reuses the locked core dependencies; it does not install a second backend runtime.
- `integration/`: explicitly supplies app autoloading, service/route/mapping registration, stable actor identity, admin authentication and frontend build wiring. Content is selected in integration service configuration; the other business adapters are explicitly selected in app service configuration. Host adapters are excluded from generic service discovery. There is no automatic discovery of arbitrary apps.
- `business/` and `core/`: existing host domain models and validation, consumed but not modified for agent features. Agent-specific capability restrictions stay in the app.

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

Provider URL compatibility depends on the provider's CORS and Chat Completions implementation. Provider settings and the API key are stored in browser local storage and persist across page reloads. The API key is sent only to the configured provider; leave it blank for providers that do not require one. Clear it with the settings action when no longer needed. Local storage is accessible to scripts running on this origin, so only use this on a trusted browser/device. The assistant sends the user's question, matching API-documentation excerpts, and any matching excerpts from the bundled host knowledge articles to the configured provider. Bounded read query results are also sent to the provider without an additional confirmation. The admin token is never sent to the provider. Chinese UI text follows the admin's `app_locale`; English is the fallback for other locales.

## Development checks

### Repeatable mounted-browser acceptance

The default Node suite does not mount a browser. With the composed Vite development server running, open this fixture in a **new isolated browser context/private profile**, never an authenticated admin tab:

```text
http://127.0.0.1:9528/@fs<absolute-repository-path>/apps/admin-agent/tests/browser/fixture.html
```

Replace the repository-path placeholder with the absolute checkout path, including its leading slash. The fixture automatically runs [the real-DOM acceptance harness](tests/browser/browser-acceptance.mjs) and displays a structured result. Browser automation must first wait for the asynchronous fixture boot:

```js
await page.waitForFunction(() => Boolean(window.__NS_ADMIN_AGENT_ACCEPTANCE__?.promise))
const result = await page.evaluate(() => window.__NS_ADMIN_AGENT_ACCEPTANCE__.promise)
```

Require `ok === true`, all 22 cases passed, no failures/skips, empty setup/cleanup/blocked-request errors, `descriptorsRestored === true` and `isolationRetained === false`. The suite includes attachment-to-model batch preparation, Office-to-text parsing, explicit confirmation, ambiguous-outcome recovery, single/batch-write interleavings and catalog-refresh recovery. Do not interpret successful loading alone as a passing run. Module syntax can be checked with `node --check apps/admin-agent/tests/browser/browser-acceptance.mjs`, but that does not execute browser tests.

Negative safety checks use the fixture URL with `?autorun=0`. In a separate fresh context for **each** check, import `browser-acceptance.mjs` relative to the fixture and call `runBrowserAcceptanceProductionGuardSelfCheck()`, `runBrowserAcceptancePatchabilitySelfCheck()` or `runBrowserAcceptanceTimeoutSelfCheck({ caseTimeoutMs: 300 })`. Each outer self-check must return `ok: true`; its inner acceptance run is expected to reject setup or fail on timeout, not pass the positive suite. Close every test context afterward. If any run reports retained isolation or cleanup failure, discard the context instead of navigating it to an application page.

The harness replaces storage with memory before mounting, intercepts network entry points, uses synthetic files/host/model responses and restores patched globals on completion. It tests real rendering and the integration session monitor, not real account authentication, backend authorization, actual delayed authenticated reads or live-provider compatibility. Its body/path safety guards are not a substitute for the required isolated context. It adds no production entry point, browser-test dependency or real upload/printing action. Dated results are in the [follow-up acceptance record](../../docs/sessions/2026-10-23-admin-agent-follow-up-acceptance.md), [initial batch-import record](../../docs/sessions/2026-10-23-admin-agent-batch-import.md) and [attachment-driven follow-up](../../docs/sessions/2026-10-23-admin-agent-attachment-batches.md).

```sh
npm test --prefix apps/admin-agent
node --test apps/admin-agent/tests/backend-preview-contract.integration.mjs
node --test apps/admin-agent/tests/backend-batch-preview-contract.integration.mjs
node --test apps/admin-agent/tests/backend-capability-catalog.integration.mjs
node --test integration/admin/*.test.mjs
php -d memory_limit=512M core/crud-skeleton/vendor/bin/phpunit -c integration/backend/phpunit.xml
npm run build --prefix core/crud-admin -- --config ../../integration/admin/vite.config.ts
```

Before using confirmed mutations, review pending migrations and apply the app's operation migrations through `integration/backend/bin/console`; batch creation additionally requires `Version20261023120000`. Schedule `app:admin-agent:prune` to erase expired single-record and batch proposal payloads. No environment database is migrated automatically by tests or source relocation. The previous uncommitted Content-specific operation table was never applied in this development session; if an environment independently applied that draft migration, inspect it and plan a data-preserving migration instead of blindly creating or dropping tables.

See the [design](../../docs/design/admin-ai-assistant.md) for security policy, implementation gaps and acceptance plans.
