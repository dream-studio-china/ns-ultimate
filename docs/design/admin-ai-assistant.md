# Admin AI assistant design

## Architecture

The assistant is a browser application composed through `integration/admin/agent-host.js`. It reuses the effective admin configuration, existing metadata provider, request client and router. Existing backend CRUD endpoints remain responsible for authentication, authorization, domain validation and persistence.

```text
Effective admin form configuration + metadata + available navigation
                         ↓
                Agent-specific prohibitions
                         ↓
             Local preview → human confirmation
                         ↓
              Existing authenticated CRUD API
```

Configuration is the ordinary entity/action/field whitelist. Agent policy contains exceptions rather than a parallel business-field table. The model does not receive a general HTTP tool. Host-owned endpoint resolution and retained proposals separate model intent from actual execution.

## Ownership

- `apps/admin-agent/src/`: generic UI, provider interaction, bounded reads, configuration projection and local mutation lifecycle.
- `apps/admin-agent/adapters/ns-ultimate/admin/`: project-owned component bindings, read policies and curated knowledge.
- `integration/admin/`: composition with existing config, metadata, request and router APIs.
- Existing business/core backend: normal CRUD authorization and validation, unchanged by this redesign.
- `integration/backend/legacy-migrations/admin-agent/` and `integration/backend/migrations/`: preserved migration identities and the forward table-retirement migration; no assistant backend package, entity mappings or services remain.

New ordinary form fields do not require PHP capability descriptors, mutation adapters, generated manifests or a duplicated frontend whitelist. Unsupported uploads, custom actions and embedded managers need an actual host flow; they are reported as unsupported rather than silently guessed.

## Deliberate tradeoff

Removing the parallel operation backend reduces coupling and duplicated domain rules, but also removes its durable proposals, transactional receipts and execution deduplication. Local before-submit reads are not atomic conflict detection. Ordinary CRUD responses acknowledge requests without an extra read-back transaction. Create batches are sequential and may partially succeed. Network-uncertain writes cannot be resolved from a durable assistant receipt and must never be retried automatically.

Preview and confirmation remain accidental-action safeguards. Browser restrictions cannot protect an API against malicious direct callers; existing backend security must do that. The assistant's blacklist retains the agreed wallet, configuration, authorization and existing-user restrictions. Deletion keeps its separate explicit whitelist.

## Compatibility and rollout

Retired assistant capability/operation endpoints have no write compatibility fallback. With no live deployment requiring receipt retention, retirement explicitly drops both legacy tables through a project migration. Original migration classes move to integration without changing version identities; mappings, services and the pruning command are removed with the Agent backend package. Clear backend caches and run the normal project migrations. The deletion is irreversible and does not affect browser chat history or business tables.

See the [contract](../contracts/admin-agent.md) for current guarantees and limitations, the [app guide](../../apps/admin-agent/README.md) for workflows, and dated session records for historical verification. Results for the previous adapter-based backend do not establish behavior of the config-driven CRUD path.
