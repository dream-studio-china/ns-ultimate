# Config-first admin AI assistant

**Status: Read-only prototype implemented. It includes the independent launcher, locally persisted provider preferences and API key, same-origin OpenAPI lookup, config-first entity/field catalog, and explicitly confirmed bounded GET queries for documented admin collections. Empty API keys are supported for local providers. The admin-operation bridge, mutation policy, and full task execution remain unimplemented.**

## 1. Purpose and agreed scope

Build an assistant embedded in the admin web application. Develop it independently under a root-level `agent/` directory so that its generic functionality can later move into the admin core without carrying project-specific business rules.

The assistant can understand the API documentation, query approved read-only endpoints, and perform controlled operations through existing admin functionality. The model must not receive a general HTTP client, arbitrary JavaScript execution, or direct access to write endpoints.

The initial implementation must not modify `core/` or `business/`. Minimal, explicit loading and host wiring may be added under `integration/admin/`. Changes to build, test, deployment, or CI configuration must be explicit rather than relying on discovery of a new directory.

Low-risk, single-record edits may submit automatically after a detailed advance notice and a five-second cancellation window. All other enabled mutations require explicit confirmation. Payments, refunds, permission changes, and security configuration changes are initially unavailable.

This is an architecture and implementation specification, not a statement that the existing admin already exposes all required extension points. Read it alongside [architecture](architecture.md) and the [admin composition contract](../contracts/admin-composition.md).

## 2. Current code and constraints

The design is based on the following repository behavior:

| Existing surface | Relevant behavior |
| --- | --- |
| `integration/admin/config.ts` | Produces the composed routes and entities from core and business configuration. |
| `integration/admin/vite.config.ts` | Redirects the exact `@/config` and `@/i18n` imports; owns the composed build configuration. |
| `core/crud-admin/src/configs/entities.js` | Collects upstream entity definitions, including executable JavaScript and JSX configuration. |
| `core/crud-admin/src/easyadmin/ui/vue/FormAdmin.vue` | Owns form initialization, field behavior, validation, model updates, and the normal submit flow. Its action slot receives a submit callback. |
| `core/crud-admin/src/easyadmin/application/usecases/` | Contains reusable save, delete, batch-update, relation, and export logic. Calling a write use case alone does not establish that the complete admin UI flow was preserved. |
| `core/crud-skeleton/config/routes/nelmio_api_doc.yaml` | Defines the human-facing `/api/doc` and machine-readable `/api/doc.json` routes. |
| `business/admin/config/components/PrinterJobReprintAction.js` | Illustrates a custom operation whose behavior lives inside a component, not in a declarative action contract. |

There is no verified, universal assistant bridge for all current pages. Configuration includes functions, custom components, dynamic visibility, and nested workflows; it is not a complete serializable action specification. Actual OpenAPI coverage must also be checked against the running deployment.

The repository's core-edit authorization gate continues to apply. Future migration into core requires separate explicit authorization and review; this document does not authorize core edits.

## 3. Architecture and ownership

```text
integration/admin: optional loader and host composition
                  |
                  v
agent: UI -> task controller -> policy -> typed tools
                                    |          |
                                    |          +-> approved read adapter -> backend
                                    |
                                    +-> admin adapter -> existing admin components
                                                         -> normal admin writes

LLM provider receives only approved descriptions and minimized context.
```

Proposed module layout:

```text
agent/
  src/
    assistant/             Conversation and bounded task execution
    capabilities/          Serializable capability descriptions and validation
    providers/             LLM transport adapters
    policy/                Risk classification, previews, execution grants
    storage/               Preferences, encrypted credentials, optional history
    ui/                    Launcher, panel, settings, operation cards
    adapters/crud-admin/   Configuration and component integration
  tests/
  package.json
```

These are responsibility boundaries, not a requirement to create empty directories or a framework before they are needed.

Only the `crud-admin` adapter may depend on admin-specific configuration shapes, components, or imports. Generic assistant code must not import core internals, business modules, entity names, API prefixes, or the current repository layout. Host configuration supplies business policy and custom capabilities.

Integration owns registration and translation of stable interfaces, not risk rules or business behavior. The adapter consumes the already composed configuration; it must not reimplement core/business merging. The loader must avoid an import cycle between composed configuration and assistant initialization.

An assistant mount must be idempotent, occur only in an authenticated admin context, and provide disposal of UI, subscriptions, timers, and pending work. Disabling the feature must preserve normal admin behavior.

## 4. Config-first capability discovery

### 4.1 Sources of truth

Use the effective admin configuration as the primary source of management capabilities. Use runtime metadata for field types and relation identifiers where configuration is incomplete. Use OpenAPI for API knowledge and approved read queries, not to manufacture executable write capabilities.

The capability catalog describes entities, field labels and types, available read filters, navigation targets, standard operations, and runtime support limitations. Capability discovery does not grant permission to execute.

For each action, effective availability is the intersection of:

```text
configured functionality
  AND adapter support
  AND current user/session permissions
  AND current record/form state
  AND host assistant policy
```

Reevaluate this intersection before execution. Hidden or disabled fields, record-state restrictions, and session changes can invalidate an earlier plan. Backend authorization remains authoritative.

### 4.2 Normalize, do not serialize executable config

Create a serializable projection for the model. Do not transmit raw config modules, component source, callbacks, secrets, or unrestricted metadata. Trusted config callbacks remain in the host and are evaluated only through established admin behavior, never as model-supplied code.

String fields, structured field descriptors, wildcard field expansion, relation metadata, and create/update visibility need explicit adapter handling. A field's presence in configuration is neither proof of editability nor evidence that editing it is low-risk.

Preserve typed values and stable identifiers. For example, the composed Product-to-Store field uses a Store UUID, not its numeric persistence ID. Never substitute a display label for an identifier or silently convert ambiguous dates, monetary values, nulls, and empty strings.

Unsupported custom controls or unresolved dynamic behavior must be reported as unsupported. Do not fall back to DOM guessing, private Vue instance inspection, or direct write requests.

### 4.3 Standard and custom capabilities

Standard configuration-driven capabilities should cover discovery, list queries, record inspection, opening an editor, preparing field changes, validation, and supported form submission. Their implementation should be generic across compatible entity definitions.

Nested forms, file uploads, rich-text editors, and relationship editors require explicit support and tests. Do not infer safe automation merely because the corresponding UI renders.

Custom actions require a host-supplied capability adapter with parameter validation, risk classification, preconditions, execution through existing admin functionality, and result verification. A component reference or button label does not expose its business behavior. Unsupported actions remain available to users normally but unavailable to the assistant.

No coverage percentage is promised before testing representative composed configurations.

## 5. Host boundary and admin execution

The following names are proposed contract concepts, not existing APIs:

| Host operation | Responsibility |
| --- | --- |
| `getSessionContext` | Return stable user/session identity and permission context without exposing credentials. |
| `getCapabilities` | Return the normalized catalog, support status, and configuration revision. |
| `queryReadOnly` | Execute an approved typed read operation using the current authenticated session. |
| `openEditor` | Mount or navigate to a supported existing admin editor with the correct application context. |
| `prepareChange` | Apply allowed draft values through the supported form model and derive the effective change set. |
| `validateChange` | Run the normal admin validation and report field errors and effective payload differences. |
| `submitPreparedChange` | Consume a locally authorized execution grant and invoke the existing admin submit flow. |
| `verifyResult` | Establish the outcome from normal admin feedback and approved read-back where appropriate. |
| `dispose` | Release the assistant's mounts and pending non-submitted operations. |

A model must not be able to fabricate an execution grant or invoke privileged host functions directly. Parameter schemas, record scope, session binding, and policy checks apply in application code regardless of the provider's tool-calling behavior.

### 5.1 Reuse the complete form flow

Prefer composing existing public components with supported props, model updates, events, and slots. In particular, investigate whether a wrapper around `FormAdmin` can use the existing action-slot submit callback while preserving its normal initialization and validation.

An assistant-owned editor may appear in a visible panel or dialog; it need not depend on the currently open entity page. It must still run under the correct router, store, localization, component registration, and authorization context. A separate Vue root does not automatically inherit these dependencies. Reuse the host application context through a supported integration, or explicitly provide equivalent supported dependencies.

Do not create a second CRUD implementation, alter component prototypes, traverse private instances, rewrite component source at build time, or simulate arbitrary selectors. Shadow DOM styling isolation is optional and must be evaluated against existing component styles and teleported dialogs; it is not a security boundary.

The required first boundary proof is a single supported edit: load a real record, fill a configured field, run original validation, display the effective diff, invoke original submission, and verify the result. Until that works, execution support is unvalidated even if capability discovery works.

If supported component APIs cannot preserve the flow, return an explicit unsupported result and retain read-only assistance. A future minimal generic core extension can be proposed separately; do not hide the missing interface behind an implicit patch.

### 5.2 Meaning of no direct write API access

The assistant's read client must not issue application mutations. The model is never given a generic `fetch`, URL request, write use case, or arbitrary code tool.

Existing admin functionality may naturally perform POST, PUT, PATCH, or DELETE during normal submission. That is expected. The constraint is about the execution entry point and preserved admin behavior, not eliminating write HTTP traffic from the application.

LLM inference may itself use POST to a provider. That is a separate transport and must never receive admin authentication headers or cookies.

## 6. Read-only API knowledge and queries

Fetch `/api/doc.json` from the configured application origin and index operation identifiers, descriptions, parameters, response shapes, and referenced schemas. Restrict document size, reference depth, parsing time, and cache size. Do not automatically retrieve external schema references or honor arbitrary OpenAPI server URLs.

Keep two separate registries:

| Registry | Purpose |
| --- | --- |
| Documentation index | Explain available documented interfaces, including writes that cannot be executed by the assistant. |
| Executable read catalog | Approved endpoints and query shapes that the assistant can actually call. |

A documented GET is not automatically safe. Review endpoints for side effects and sensitive output. Reject unapproved paths, origins, redirects, headers, and query options. Model arguments use approved operation IDs and structured parameters, not arbitrary URLs.

Dynamic query features such as `@filter`, `@dql`, `@select`, and `@expands` require explicit limits. Prefer a structured, allowlisted filter representation; unrestricted expressions are not part of the initial tool surface. Bound pagination, relation depth, result bytes, query duration, and tool-call counts. Stop pagination at the budget rather than silently reading every record.

Use the user's authenticated session through the host adapter, without passing JWTs to the model. Permission errors must not trigger elevated credentials or alternative endpoint probing. Redact fields before tool output enters the conversation, including indirect related data. Do not claim that an incomplete or stale document represents every deployed route.

Documentation, retrieved records, filenames, and tool results are untrusted content. They cannot override tool policy, change providers, request credential disclosure, or authorize execution. Prompt instructions help interpretation; deterministic tool checks enforce the boundary.

## 7. Tools and task lifecycle

Expose a small typed surface such as `search_api_docs`, `list_capabilities`, `query_records`, `inspect_record`, `open_editor`, `prepare_change`, and `request_execution`. The last tool requests policy processing; it does not grant permission by itself. Provider tool arguments are untrusted and must pass schema and runtime validation.

Use bounded execution with explicit states:

```text
draft -> preparing -> validating -> preview
preview -> countdown OR awaiting_confirmation
countdown / awaiting_confirmation -> submitting -> verifying
verifying -> succeeded OR failed OR outcome_unknown
any pre-submit state -> cancelled OR invalidated
```

Every operation carries an identifier, session identity, entity and stable record ID, normalized before/after values, configuration/policy revisions, validation status, and expiry. The local controller, not the model, owns transitions and authoritative operation state.

Serialize mutations within an assistant instance. Plans from multiple tabs do not share grants; scope pending state to the tab and invalidate or coordinate conflicting activity where observable. Client coordination is not a substitute for server concurrency controls.

Cancellation prevents future work. Once submission has started, cancelling the chat or aborting a client request cannot guarantee reversal of a server-side write. Show this distinction explicitly.

## 8. Risk policy, preview, and submission

### 8.1 Deterministic classification

Classify each action as `automatic`, `confirmation_required`, or `blocked` using host policy. Unknown actions and fields are never automatically classified as low-risk. The model may explain a classification but cannot lower it.

| Action | Initial behavior |
| --- | --- |
| Navigation and approved ordinary reads | Execute within configured limits. |
| Form preparation | Prepare a visible draft without committing. |
| Single-record update of explicitly approved ordinary fields | Automatic after preview and countdown. |
| Creation, bulk mutation, price, inventory, publication, or order-state changes | Require confirmation if supported and enabled. |
| Delete and export | Disabled unless explicitly enabled with stronger confirmation and scope limits. |
| Payment, refund, permission, credential, and security-setting changes | Blocked initially. |

Descriptions, notes, and sort fields are only candidates for a low-risk whitelist. Their business meaning must be reviewed per entity. All changed fields must qualify for automatic submission; a mixed-risk plan takes the strictest classification.

Business policy is supplied as host configuration, optionally through an integration-owned policy module or deployment-specific extension. Generic code must not hardcode Product, Store, printer, or other application-specific rules. Browser policy is a safety mechanism, not a replacement for backend enforcement or protection against malicious same-origin scripts.

### 8.2 Detailed advance notice

Before every mutation, display a structured card containing:

- Entity, human-readable record label, stable ID, and affected record count.
- Every effective field change with original and proposed typed values.
- Secondary effects known to the adapter, warnings, and any limits to verification.
- Risk classification, execution mode, and cancellation or confirmation controls.

Sensitive values remain masked even in history; a secret-field change must not qualify for automatic submission. Avoid treating rich text as executable HTML in previews or model output.

Build the preview from effective form state after supported transformations and validation, not merely from model-requested values. Include changes introduced by defaults and callbacks where observable. If payload shaping has material effects that the adapter cannot determine, disable automatic submission and report the limitation; do not pretend the diff is complete.

### 8.3 Countdown and execution grants

The default automatic-submit cancellation window is five seconds, starting only after the detailed card is rendered in a visible, focused document. Pause or invalidate the countdown if the document is hidden or focus is lost; do not submit immediately on return because a background timer expired. Provide a persistent cancel action and a user option to require confirmation for all writes.

A confirmation or elapsed countdown creates a short-lived, single-use local grant bound to the exact session, record, change set, policy/configuration revision, and validation result. Any material change invalidates it and requires a new preview. Do not expose grants as model-editable conversation content or persist reusable grants.

Before submission, recheck authorization context, editor state, expiry, and supported record freshness. If original values changed, stop and regenerate the plan. A client read-before-write cannot eliminate a race: use existing version/ETag concurrency support if available. Where strong conflict detection is unavailable, disclose the limitation and keep automatic submission disabled unless the host explicitly accepts that risk for the affected capability.

Do not overwrite an existing user draft. Use a separate clearly identified assistant draft or ask the user how to proceed. Navigation and session changes invalidate pending operations that depend on the previous context.

### 8.4 Results and failures

Double-clicks, repeated model calls, and rerenders must not submit a plan twice. A local grant is consumed when submission starts. Do not invent server idempotency guarantees; use them only where existing admin/backend behavior supports them.

After submission, report actual success, validation failure, authorization failure, or unknown outcome. Use approved read-back where appropriate, accounting for eventual consistency and server normalization. A timeout after sending a write is `outcome_unknown`, not proof of failure. Do not automatically retry an ambiguous write; reconcile its outcome first.

Offer undo only when a supported inverse operation exists. An inverse edit is a new operation with fresh policy checks, preview, and authorization, not an unrestricted rollback promise.

## 9. Provider configuration and local storage

### 9.1 Provider boundary

Start with one explicitly supported provider protocol, preferably a compatible gateway, rather than promising universal provider compatibility. Adapter responsibilities include model discovery where supported, streaming, structured tool calls, cancellation, usage reporting, and normalized errors. A provider without required tool support may offer read-only chat but not silently bypass the controller.

Direct browser connections require provider CORS support and an appropriate credential model. Do not disable browser protections to make a provider work. A local model service is also subject to origin and browser networking restrictions.

A server-side relay is an optional separate deployment feature, not part of the browser-only baseline. A relay must constrain destinations, authenticate callers, limit usage, protect secrets, and prevent SSRF. Server-managed credentials are not described as local browser storage.

Changing a provider, base URL, or model invalidates pending operations. Warn before sending admin data to a new destination. Approved model access does not imply consent to send every record or the full API specification.

### 9.2 Storage decisions

| Data | Default storage and handling |
| --- | --- |
| Provider identifier, model, base URL, UI preferences | Namespaced `localStorage`; never include credentials in URLs. |
| API key | Memory only; clear on logout, lock, or reload. |
| Optional persistent API key | Authenticated encryption in IndexedDB, unlocked by a user passphrase. |
| Conversation history | Memory by default; optional bounded IndexedDB persistence after consent. |
| OpenAPI index/cache | Bounded IndexedDB cache with version, origin, expiry, and permission scope where relevant. |
| Operation history | Minimized local records with secret redaction; no pending grants. |

Namespace user data by application origin, host-provided tenant scope where applicable, stable authenticated user ID, and storage schema version. Clear active context immediately on logout or account switching. Lock persisted credentials, abort provider calls where possible, remove assistant drafts, and invalidate all grants.

For encrypted credentials, use a reviewed Web Crypto construction: a passphrase-derived key with stored salt and versioned KDF parameters, plus authenticated encryption with a fresh nonce for each write. Calibrate derivation cost for supported devices and test wrong-password and tamper handling. Never store the passphrase or derived key beside the ciphertext. Do not invent custom cryptography.

Forgotten passphrases require credential reset; no recovery backdoor is provided. Encryption protects stored bytes, not an unlocked page against XSS, browser extensions, or same-origin malicious code. Browser storage is not a security vault or a backup.

Handle unavailable storage, quota exhaustion, eviction, malformed data, and migration failures without blocking normal admin use. Fall back to memory and notify the user. Offer retention controls, clear-history, forget-credentials, and complete local-data removal. Configuration export excludes keys, tokens, and record data by default; import validates values and requires confirmation of provider destination changes.

Do not put API keys into `VITE_*` variables, tracked examples, request logs, telemetry, or error messages. Do not auto-resume persisted tasks or mutations after reload.

## 10. UI and accessibility

Provide an optional global launcher and responsive assistant panel, usable across entity routes. The panel includes chat, current task status, capability limitations, operation cards, stop/cancel controls, provider settings, and local-data controls.

Keep modification previews visible and distinguish draft, countdown, submitting, verified success, and uncertain outcome. User confirmation must occur through application-owned controls, not by interpreting a model-generated button or a tool-result instruction.

Support keyboard navigation, meaningful focus management, accessible status announcements, reduced motion, and a mode that always requires confirmation. Use host localization; canonical identifiers and persisted schemas are language-independent. New user-facing strings belong in localization resources.

Do not render model HTML unsafely. Apply content-security-policy-compatible loading, sanitized formatting, safe links, and bounds on rendered content. If necessary styles or network destinations are blocked, report the limitation rather than weakening deployment policy automatically.

## 11. Build, deployment, and operational behavior

The proposed `agent/package.json` owns assistant dependencies and test commands. Select and lock compatible tooling during implementation. Reuse the host Vue runtime rather than bundling a second incompatible copy. Explicitly configure dependency resolution, JSX/SFC transformation, development file access, watch behavior, and production asset inclusion for sources outside the current core root.

Use integration-owned loading, preferably a lazy entry, with an explicit feature flag that contains no secrets. Do not edit the core HTML or bootstrap. Verify that the actual loader can obtain a supported authenticated host context; HTML injection by itself does not provide router/store access. If this cannot be established without forbidden hooks, leave the feature disabled and report the integration gap.

Production paths must respect the composed `/admin/` base and its asset routing. Review Docker build context and COPY rules before assuming `agent/` reaches the image. Existing CI does not automatically discover assistant tests. Add deliberate build/test coverage when runtime code is introduced, following [development and validation](../operations/development.md).

Loading failure, invalid provider configuration, or provider downtime must leave admin navigation, editing, and authentication functional. Feature disablement must be a sufficient rollback for assistant behavior without a database migration. There is no new backend service in the baseline design.

Local history is a usability record, not tamper-resistant audit evidence. Existing backend audit facilities remain authoritative where available. Do not add remote telemetry by default. Diagnostic events should carry operation IDs, timing, and error classes, not credentials, full prompts, or raw record payloads.

## 12. Verification and acceptance criteria

Use deterministic fixtures and a fake provider for automated behavior tests; real LLM responses are not a correctness oracle. Suggested assistant scripts are `type-check`, `test`, and an appropriate build command, but these do not exist yet and must not be reported as runnable until implemented.

| Test boundary | Required coverage |
| --- | --- |
| Configuration normalization | String/structured fields, wildcard expansion, relation IDs, executable config isolation, unsupported controls, composed replacements/removals, and no source-config mutation. |
| Read tools | Endpoint/origin allowlists, redirect rejection, query budgets, sensitive-field filtering, permission failure, malformed OpenAPI, external references, and injected instructions in records/docs. |
| Policy | Unknown-action denial, strictest-field risk, protected fields, session/config changes, plan tampering, grant replay, and forbidden write-client access. |
| Lifecycle | Countdown visibility/focus rules, cancellation before submit, double execution, timeout ambiguity, navigation, multi-tab conflicts, and reload without task resumption. |
| Existing form composition | Real field model changes, normal validation, dynamic visibility, effective diffs, dirty-draft protection, supported slot submission, and server error display. |
| Storage | Wrong passphrase, ciphertext tampering, unique encryption nonces, account isolation, locking, schema migration, quota failure, deletion, and secret-free exports/logs. |
| Provider/UI | Fake streaming and tool calls, invalid arguments, unsupported tool mode, CORS/network failures, safe rendering, keyboard control, localization, and disabled-feature behavior. |

The decisive end-to-end acceptance scenario uses a test record and an explicitly approved low-risk field:

1. The assistant discovers the entity from effective config and locates the record using an approved read tool.
2. An existing admin form is mounted with the correct application context and permissions.
3. The proposed edit passes original validation and produces an accurate detailed preview.
4. Cancellation prevents submission; a new uncancelled plan waits the complete visible countdown.
5. Exactly one normal admin submission occurs. The assistant read client performs no application write request.
6. Normal feedback and read-back establish the result, and the operation card reports the actual outcome.

Failure scenarios must demonstrate that denied access, hidden fields, changed records, unsupported components, expired sessions, and ambiguous writes do not silently proceed or retry. Packet-level or transport-spy assertions should distinguish provider POST traffic, allowed admin writes, and prohibited assistant-originated backend writes.

For runtime implementation, run relevant assistant tests, dependency-free admin composition tests, applicable upstream frontend checks, and the composed production build. Test actual authenticated navigation and the deployed base path separately. Do not modify core tests without authorization. For this document-only change, review links and content rather than running unrelated application suites.

## 13. Future migration into core

The module should migrate as a reusable optional admin feature, not as an application-specific bundle. Preserve the generic controller, provider boundary, policy engine, storage, UI, and capability contracts. Move or adapt the admin integration alongside stable core extension points.

The current repository's integration then supplies registration, feature configuration, and business policy. Business-specific custom capabilities remain host-owned. No Product-, Store-, payment-, or printer-specific rules migrate into the generic assistant.

Migration is ready for consideration when public component composition is validated, the capability boundary is tested, unsupported cases fail closed, and core-internal imports are isolated in the adapter. Keep module imports independent of `../../core` and `../../business` outside that adapter so relocation does not require rewriting the assistant.

A future upstream change requires the exact core-edit authorization phrase and explicit scope from the user, independent compatibility review, license preservation, subtree workflow compliance, and separate approval before any upstream push or PR. See [core synchronization](../operations/core-sync.md).

## 14. Deliberate limits

This design does not promise all-button automation, arbitrary browser control, universal provider support, a secure sandbox inside the same page, server-level audit integrity, or race-free writes without backend concurrency support.

The preferred trade-off is explicit supported capabilities with safe failure, not hidden runtime patches. The assistant can understand more documented interfaces than it can execute, discover more configured fields than it can safely edit, and prepare more operations than it may automatically submit.
