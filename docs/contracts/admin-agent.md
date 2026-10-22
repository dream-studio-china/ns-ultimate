# Generic admin-agent operations

## Ownership and adapters

`apps/admin-agent/` owns the generic browser assistant and Symfony operation backend. One app-owned `AgentOperation` entity/table stores proposals and receipts for all explicitly registered mutation adapters. Unknown and duplicate adapter keys fail closed; input never resolves PHP classes or arbitrary URLs. No core files are changed.

Integration explicitly wires the app namespace, services, routes, Doctrine mapping, migrations, tests and Docker inclusion. It supplies `ActorIdentityProvider` using the authenticated account UUID and explicitly selects app-owned host mutation adapters. The app's generic service import excludes `src/Adapter/`, so unselected host adapters are not enabled implicitly. Adapters implement `MutationAdapter`: `entityKey`, `authorize`, side-effect-free `prepare`, and transactional `execute`. Target/action/field permissions, exact lookup, canonical snapshots, agent-specific restrictions, target locking, mutation and read-back verification belong to adapters, reusing existing domain entities and validation without adding agent code to business modules or core. The app owns account binding, expiry, operation locking, idempotency and receipts. Adapters are trusted application code and must not perform non-transactional external side effects under this contract.

`apps/admin-agent/adapters/ns-ultimate/admin/mutation-capabilities.mjs` supplies capability descriptions through the integration host to the generic fixed-route transport. The adjacent `preview-policy.mjs` owns the local-only Counter.name preview policy. The model tool `prepare_record_change` only prepares proposals; there is no commit tool. Full differences and operation IDs stay inside the host/UI boundary rather than entering tool messages. Proposed values already present in chat are still sent to the configured provider. Confirmation cards display field values as text, never rendered HTML.

## Backend protocol

The generic controller requires full authentication. This host mounts it under the existing management security policy; adapters additionally enforce domain permissions on prepare, commit, status and cancel. Operations belong to the stable actor key; other users receive 404 even if administrators. Responses use `Cache-Control: no-store` and the normal admin request client.

| Method and path under `/api/v1/manage/agent/operations` | Behavior |
| --- | --- |
| `POST /` (no trailing slash required) | Prepare `{entity, action, identifier?, changes?}` through a registered adapter. Persists a proposal, not target data. |
| `POST /{operationId}/commit` | Accepts exactly `{confirmed: true}`. Executes only the stored proposal; replacement values are rejected. |
| `GET /{operationId}` | Minimal status/receipt without field values, for recovery after an ambiguous outcome. |
| `POST /{operationId}/cancel` | Invalidates a pending proposal; does not undo a completed mutation. |

Preparation returns `{operationId, entity, action, identifier, title, affectedRecords, expiresAt, differences, saved: false}`; differences contain `{property, before, after}`. Operations affect one target and expire in five minutes. Target identifiers are strings up to 128 bytes; adapters define exact formats. Requests are bounded to 40 KB and full differences to 60 KB. Oversized previews must use the standard editor.

The current host enables only Content through `apps/admin-agent/backend/src/Adapter/NsUltimate/ContentMutationAdapter.php`: fields `title`, `body`, `category`, `tags`, `metadata`, `sortOrder`, requiring ROLE_ADMIN. Title is non-empty and at most 255 characters; body is nullable and at most 20,000 UTF-8 bytes; category/tags reference existing numeric IDs (up to 50 tags); metadata follows the existing schema (`featured`, `layout`); sortOrder is a signed 32-bit integer available only on the business subtype. Category/Tag mutations, bulk changes and execution of the Counter.name local-preview pilot are not enabled. These restrictions belong to the explicit host adapter, not the generic operation framework; underlying domain validation stays in the existing host backend.

Commit rechecks owner, adapter authorization, pending state and expiry, locks the operation row in a transaction, and delegates target locking, conflict detection, revalidation and execution to the adapter. The receipt and domain mutation commit together. Replaying a succeeded operation rechecks permissions and returns the same target without repeating the mutation. Failed read-back verification rolls back the transaction. The Content adapter rejects stale snapshots with 409. Existing unversioned CRUD writers do not participate: this is not a universal optimistic-locking guarantee across every writer. SQLite tests do not validate production MySQL lock contention.

## UI lifecycle

The user reviews all differences and explicitly confirms; deletion requires typing `DELETE <identifier>`. A newer message or preview, clearing the conversation, or a session change invalidates the visible pending confirmation. Expiry is checked in UI and backend. Submission is serialized, with no automatic retry after timeout/abort. An ambiguous outcome offers a status check and blocks new tasks/clearing until resolved; only a server-pending response permits another explicit confirmation of the same proposal. Cancellation cannot guarantee reversal after submission starts.

Closing/reloading the page or changing sessions loses the local recovery card; durable receipts remain in the database and must be checked before recreating an ambiguous operation. Authorized API callers and same-origin scripts can invoke the API directly: browser confirmation is an accidental-action safeguard, not proof of human presence against malicious code.

## Migration, retention and validation

Apply `NsUltimate\Apps\AdminAgent\Backend\Migrations\Version20261022120000` through the composed backend workflow. It creates only `admin_agent_operation`, including entity key and string target ID; there are no per-domain operation tables. No environment database is migrated automatically by tests or relocation. The previous uncommitted Content-specific draft migration was not applied in this development session. If another environment applied it, inspect that state and plan a data-preserving conversion first.

Review pending migrations before running `php integration/backend/bin/console doctrine:migrations:migrate`. Schedule `php integration/backend/bin/console app:admin-agent:prune` at least every five minutes and clear composed backend caches after registration changes. Use the integration console, not the core console. The uncommitted draft endpoint `/manage/content-agent/operations` is replaced by `/manage/agent/operations` without a compatibility alias.

Pending proposals retain values in the database. Success and explicit discard erase values, retaining actor, entity, action, field names, target, before-state hash, status and timestamps. Expiry alone does not physically erase values; the pruning command does. Receipts are not a tamper-proof audit ledger, nor are failed requests an exhaustive security audit. Backups, access controls and receipt retention remain deployment responsibilities.

Tests cover multi-adapter dispatch, string IDs, unknown/duplicate keys, authenticated CRUD, conflict detection, idempotency, validation, account isolation, expiry, cancellation, migration preservation and client state transitions. Real-browser interaction and production-database concurrency verification remain required before deployment.
