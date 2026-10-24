# Config-driven admin-agent CRUD

## Outcome

Replaced the composed assistant mutation path with effective form configuration, existing admin metadata/relation helpers and ordinary authenticated CRUD endpoints. Host-specific restrictions and deletion reference checks live in explicit app adapters. New ordinary fields/entities no longer require Agent PHP schemas or mutation adapters.

Removed the Agent capability/operation HTTP controllers, execution services, registries, per-entity adapters, SSR manifest export and related build/CI wiring. Historical receipt mappings, migrations and translations remain. The retained `app:admin-agent:prune` command explicitly retires all legacy pending proposals and erases their values while retaining receipt metadata. It was tested against isolated databases; no deployment data was cleaned.

## Compatibility

Local proposals and receipts are session-only. Pre-send reads are not atomic locking. Normal CRUD acknowledgements are not independent read-back verification. Batch creation uses sequential requests and can partially succeed. Unknown writes are not retried, and mutation requests disable the shared client's authentication replay. See the [contract](../contracts/admin-agent.md) for current limitations and rollout guidance.

## Validation

- Node composition/application/script suites: 400 tests passed.
- Isolated mounted-browser suite: 26 cases passed, including confirmed config-driven CRUD and session-history messaging; no cleanup errors or escaped requests, descriptors restored.
- Composed PHPUnit: 182 tests, 4,673 assertions passed.
- Both `make phpstan` configurations passed.
- Composed Vite production build passed with existing dependency/CSS/chunk warnings.
- Documentation links and `git diff --check` passed.

No core files were changed. Real-login/live-model acceptance and production-database behavior remain unverified. Existing Qiniu PHP deprecation warnings remain. No commit, push, deployed migration or production cleanup was performed.
