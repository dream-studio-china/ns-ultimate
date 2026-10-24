# Admin-agent write capability scope

## Current decision

The effective admin configuration is the ordinary entity/action/form-field whitelist. Agent policy is an additional blacklist, not a second field whitelist. Ordinary writes reuse existing authenticated CRUD endpoints, with local previews and explicit UI confirmation. The previous finite per-entity PHP adapter rollout is superseded.

The current [contract](../contracts/admin-agent.md) is authoritative for capability membership, exceptions, special-control support and changed persistence guarantees. [The design](admin-ai-assistant.md) explains the simplification and legacy retirement. Existing backend authorization and domain rules are not replaced by browser policy.

## Retained restrictions

- System settings, authorization management, wallet writes and Store WeChat application/merchant bindings remain prohibited.
- Existing users cannot be modified or deleted; configured creation does not permit role/permission assignment. New WeChat users expose only profile fields, not session keys, raw payloads, app IDs or account links.
- Deletion retains the explicit Category/ProductCategory/Tag/Comment whitelist, plus effective configuration and normal backend checks; Tag deletion also verifies Content membership.
- The host applies the form's value semantics where the API differs (currency minor units, User password field, OrderCounter relation name, Store status endpoint) and omits controller-ignored fields or undocumented actions.
- No batch update/delete, arbitrary HTTP tool, console execution or custom provider-action endpoint is exposed.
- Uploads and embedded/custom controls require a supported host flow; configuration alone does not implement one.

## Compatibility

The old Agent capability/operation endpoints, generated manifest and per-entity PHP adapters are removed. Session-only operations do not provide durable receipts, server deduplication, transactional batches or atomic optimistic locking. Earlier verification of the retired adapter protocol must not be presented as verification of this implementation.

Historical migration identities remain in integration, but the assistant backend package and entity mappings are removed. An explicitly authorized forward migration irreversibly drops both proposal/receipt tables. See [the retirement procedure](../contracts/admin-agent.md#operation-history-and-legacy-retirement).
