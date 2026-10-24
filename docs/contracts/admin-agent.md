# Admin-agent contract

## Ownership and execution

`apps/admin-agent/` owns the generic browser assistant. `integration/admin/agent-host.js` composes effective admin configuration, the existing metadata provider, authenticated request client and router. Domain authorization and validation stay in existing backend CRUD controllers/services. No agent code is added to core or business modules.

The assistant uses **configuration as the capability whitelist and agent policy as exceptions**:

```text
configured entity/action/form fields ∩ current navigation availability − agent prohibitions
```

This is browser capability membership, not a server authorization grant. The CRUD endpoint independently authorizes every request. Ordinary fields are not duplicated in PHP capability schemas or per-entity mutation adapters; only a small host exception map removes controller-ignored or credential fields. The model cannot choose URLs, HTTP methods, controllers or transformation query parameters. OpenAPI, reference articles and record content cannot grant write capabilities.

## Configuration and exceptions

The host resolves field types and relation identifiers using the same `CrudSkeletonAdapter` metadata and `resolveRelation` helper as the ordinary form. Explicit form types take precedence for scalar controls. Configuration defaults appear in create previews; they are never silently added after confirmation. Relations use the configured ID/UUID value key, including arrays for to-many fields. Explicit null values are retained rather than silently stripping a requested clear.

Only configured, available standard actions are offered. Hidden, disabled, read-only and non-editable controls are excluded. Dynamic callbacks, unbound custom components, uploads and unsupported types are reported as unsupported, not assumed writable. Optional unsupported nested controls are omitted while supported siblings remain writable; a required unsupported control disables creation rather than producing an incomplete create request. Missing metadata disables form writes, not read-only chat. The known scalar ProductCategory name component retains its explicit component binding. No embedded manager or custom action is treated as a generic CRUD field. Actions whose method/detail path is absent from the loaded API document are not advertised, even when the admin form enables them.

`adapters/ns-ultimate/admin/write-policy.mjs` owns host exceptions and is explicitly injected into the generic mutation host. Wallet entities, PaymentDeduction, system settings, permission/role/assignment management, Store WeChat bindings, credential profiles and direct print-job writes are prohibited. PrinterDevice creation is prohibited because its ordinary POST registers with an external provider. Credential-like fields are blocked except the configured new User plainPassword. User/Profile/WechatUser updates and deletions are prohibited; configured creation can be offered. New WeChat users expose only profile fields: session keys, raw payloads, app IDs and user-account links are blocked. User creation cannot assign roles, groups or permissions. Deletion remains separately limited to Category, ProductCategory, Tag and Comment, and only when configuration permits it.

Fields that the controller silently ignores are omitted from that entity/action. This currently covers Store `code` on update and `paymentSetting`, Material `code` on update, Comment structure fields on update, OrderItem fields not accepted by its controller, Counter/ProductCategory store reassignment, immutable PrinterDevice/PrinterTemplate identity fields, and recipe structure on update. The host also applies the admin form's value semantics: currency controls accept major units and submit the configured minor-unit multiplier; nested JSON-schema constraints and dependencies are checked before preview; User `plainPassword` is submitted as `password`; OrderCounter `counter` as `counterUuid`; and Store status uses the dedicated status endpoint instead of the ignored collection update field. A create with a non-default Store status is rejected because it would require a second write.

The host deletion guard checks Category child/content/picture/media references, ProductCategory child/product references, Comment children and Tag-to-Content membership before preview and again before submission. Missing access or malformed reference-query results block deletion. These client reads are not lock-protected referential checks: concurrent changes after the final read remain subject to the ordinary backend's constraints and cascade behavior.

JSON controls accept bounded safe JSON. Schema-form patches are limited to configured editable nested paths and merged with current data to preserve omitted values. Hidden descendants cannot be cleared through their container. The bounded schema subset used by the composed forms (types, required properties, enumerations, ranges, lengths, patterns, arrays, `additionalProperties` and property dependencies) is validated before preview; the backend remains authoritative for `$ref`, formats and domain rules outside that subset. Ordinary JSON controls replace the supplied value. Settings changes may affect future operations; the assistant does not invoke print, payment, upload, reprint or other custom action endpoints. Existing CRUD endpoint side effects remain those of the ordinary admin form.

## Preview and confirmation

`src/config-mutation-host.js` prepares proposals locally. Preparation performs GET detail reads for update/delete but never POST/PUT/DELETE. It checks configured field membership, basic types, explicit options, JSON safety and relation identifier format; existence, authorization and domain validation are deferred to the normal backend request.

The host retains an immutable-by-copy payload and local operation identifier. Full before/after values are displayed as text in the UI, not rendered as HTML or forwarded as tool results. The model has prepare tools only, never a submit tool. Explicit UI confirmation submits exactly the retained changes, not replacement model values. Deletion requires typing `DELETE <identifier>`. Proposals expire after five minutes. Session disposal, superseding previews and configuration changes invalidate confirmation.

Before submission the host rechecks the effective configuration and relevant current values. A pre-send conflict prevents the write. This is **not atomic optimistic locking**: another writer can change data between GET and PUT/DELETE. There is no additional transaction, rollback, server confirmation credential, durable operation receipt or cross-session idempotency layer. CRUD authorization and business validation remain authoritative. Browser confirmation prevents accidental actions, not malicious same-origin code or direct authorized API callers.

Normal successful CRUD responses establish acknowledged submission, not a separate read-back verification. Repeated confirmation in the same session cannot resubmit a consumed operation. Network failures, cancellation after send and ambiguous responses remain unknown and are never automatically retried. Inspect the ordinary admin record/list manually; matching current data does not prove which request produced it.

## Create-only batches

Any configured create capability can be used for a batch of 1–20 rows. There is no batch-update or batch-delete capability. Every row is previewed and requires one explicit UI confirmation for the whole proposal. Attachments do not authorize a batch; the user's own message must request creation.

Rows are sent sequentially through the same ordinary create endpoint, **not in one transaction**. Submission stops at the first uncertain request. Results distinguish acknowledged successful rows, the uncertain row, and unsubmitted rows. Completed rows are not rolled back. The whole batch must not be retried automatically. Successful batch navigation points to the ordinary list; partial/unknown batches do not receive a success link.

## Operation history and legacy retirement

Operations and their values live only in the current host's memory. History contains metadata and per-row outcomes, not proposal values. Refresh, sign-out, account change or host disposal loses this history. Checking an unknown operation returns the local observation only; it does not query an authoritative server receipt. Unknown outcomes keep the write lock and require manual inspection before starting over.

The former `/api/v1/manage/agent/capabilities`, `/agent/operations` and `/agent/batch-operations` routes are removed. PHP capability tables, mutation adapters, controller dispatch bridges, registries and operation execution services are retired. The SSR manifest export and its Vite/CI generation step are also removed. No compatibility endpoint can execute old proposals.

The entire `apps/admin-agent/backend/` package, entity mappings, translations, service/autoload registration and pruning command are removed. The system was not live when this retirement was authorized; historical proposals and receipts do not need retention. Project migration `NsUltimate\Integration\Backend\Migrations\Version20261024120000` permanently drops `admin_agent_operation` and `admin_agent_batch_operation`, including every status and payload. Clear backend caches and apply the normal project migrations:

```sh
php integration/backend/bin/console doctrine:migrations:migrate --no-interaction
```

The two original migrations now live under `integration/backend/legacy-migrations/admin-agent/`, with their original class namespaces/version identifiers unchanged. This is migration history, not a backend runtime package: existing development databases recognize applied versions, and a fresh migration chain creates then drops the retired tables. The forward retirement migration tolerates absent tables and is explicitly irreversible; rollback cannot recover deleted records. Business tables and migration metadata are retained. No environment database is migrated automatically by this source change.

## Navigation

Success links are generated by the host from registered routes: single create/update links to details when an acknowledged identifier exists, deletes and complete batches link to lists. No deleted-record link is produced. A prohibited assistant deletion can offer a manual admin destination when configuration and current navigation allow it. Unknown single-record outcomes can offer a clearly labeled manual inspection link, never a success link. Links require a user click, do not bypass authorization and do not claim to apply an exact list filter.

## Read-only tools

`list_entities` is the configured discovery index. Its result is paged below the conversation tool-result budget, with `offset`, `hasMore` and `nextOffset`; an exact `entity` argument returns only that entity's configured fields, filters and sort fields. This keeps the full catalog available instead of replacing an oversized result with an error. A collection is marked readable only when the loaded API document has its list endpoint and the current account has a registered admin page, so the catalog never advertises a collection that `query_records` will reject.

Collection queries continue to derive from effective admin configuration and documented GET endpoints. Filters use configured static expressions, never executable callbacks or model-supplied query DSL. They accept at most five filters, page 1–100, bounded page size and configured sorting. List responses omit sensitive/generic values, cap strings at 300 characters and serialize at most 14,000 characters; truncation is explicit.

`read_record({entity, identifier})` reads an exact positive numeric ID or UUID through a documented detail GET. The current host policy exposes fixed Content and Page fields, including body text. Strings are capped at 12,000 characters and the projection at 14,000 characters, with omissions/truncation reported. There is no arbitrary field/path/URL input. Record content is untrusted data, never instructions.

`inspect_record_configuration` remains a separate fixed-policy read for selected Store/Product configuration scalars, capped at 2,000 serialized characters. It does not expose arbitrary settings, private contact details or credentials. Missing/malformed fields are diagnosed without raw values; cached configuration is not evidence that a worker or external device is running.

## Chat attachments and knowledge

The sidebar also provides browser-local chat sessions (up to 20, each bounded to 24,000 characters). Only user/assistant text and filename summaries are stored; no raw attachment, tool call/result, query state, executable preview or operation receipt is restored. The normal provider context budget still applies when continuing a saved chat. Restored text is marked historical and cannot establish current state or authorization. New chat retains the old conversation; clear/delete removes it. Switching invalidates pending confirmation cards and is blocked during active tasks or unresolved writes.

Integration supplies a local history partition derived from the authenticated token's stable numeric account subject; the token itself is not persisted by chat storage. The subject hint is not used to authorize anything. Unknown identity disables persistence; storage failure retains page-memory functionality. History is not encrypted and remains accessible to the browser profile and same-origin scripts. Chat/model answers may contain sensitive information or quoted record/attachment content; users can delete stored conversations.

Attachment selection/parsing is local and does not upload media to the backend. Explicit Send transmits supported text or opted-in images to the configured model. Restricted XLSX/DOCX readers reject active content, formulas and external relationships and report ignored content. Attachments cannot supply instructions, routes, missing authorization or write confirmation. Original contents are excluded from preferences and later retained conversation context; only bounded filename summaries survive. See [the app guide](../../apps/admin-agent/README.md#images-and-file-input).

Curated host knowledge is explicitly registered under `adapters/ns-ultimate/admin/knowledge/`; no repository scan or remote file tool is provided. Retrieval sends bounded, untrusted excerpts to the selected provider. Articles cannot grant capabilities and must contain no secrets. Provider preferences and optional API keys remain local browser settings; admin authentication tokens never go to the provider.

## Validation boundaries

Tests cover config-driven membership, new fields/entities, blacklist exceptions, safe payloads, relation conversion, GET-only preparation, explicit confirmation, pre-send conflicts, session disposal, unknown outcomes, sequential partial batches, retired routes and legacy data cleanup. Synthetic browser tests do not establish real-account authorization, live-model behavior or production-database concurrency. Those remain deployment acceptance checks.
