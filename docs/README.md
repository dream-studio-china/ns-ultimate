# Documentation hub

Start with the task below. This page is a stable map, not a second copy of configuration, API contracts, capability lists, or validation results. Update it when an entry point moves, is added, or is retired—not for routine implementation changes.

## Find the right guide

| I want to… | Start here | Continue with |
| --- | --- | --- |
| Set up and run the project | [Quickstart](../QUICKSTART.md) ([Simplified Chinese](../QUICKSTART.zh-cn.md)) | [Development and debugging](operations/development.md) |
| Deploy the composed application | [Deployment](../DEPLOY.md) ([Simplified Chinese](../DEPLOY.zh-cn.md)) | [Docker deployment](../infra/docker/README.md) ([Simplified Chinese](../infra/docker/README.zh-cn.md)) or [production runbooks](runbooks/README.md) |
| Release, roll back, or investigate an incident | [Production runbooks](runbooks/README.md) | Follow the procedure for your actual deployment topology |
| Understand ownership and architecture | [Architecture](design/architecture.md) | [App package boundaries](../apps/README.md) and [cross-layer contracts](contracts/README.md) |
| Use, configure, or extend the assistant | [Assistant app guide](../apps/admin-agent/README.md) | [Operation contract](contracts/admin-agent.md); [design and proposals](design/admin-ai-assistant.md) |
| Understand an upstream module | [Backend manual](../core/crud-skeleton/docs/manual/index.md) and [module runbooks](../core/crud-skeleton/docs/runbooks/) | [Frontend configuration manual](../core/crud-admin/docs/manual/config-reference.md) |
| Update an upstream core | [Core synchronization workflow](operations/core-sync.md) | Review composition contracts and the repository's core-edit authorization gate |
| Find checks and automation | [Development checks](operations/development.md) | [CI workflow](../.github/workflows/ci.yaml) |
| Maintain documentation | [Documentation ownership and checks](operations/documentation.md) | Change the authoritative source, not every page that links to it |

## Which source applies?

- **This repository's deployment guides and runbooks** describe the composed application. Upstream deployment examples describe a standalone core and are not interchangeable with them.
- **App/module guides** explain that package's use and maintenance. **Contracts** specify cross-layer guarantees; implementation and tests establish current behavior.
- **Core manuals and runbooks** explain upstream modules. Check their implementation status and project overrides before using them. Do not assume upstream console paths, environment defaults, scheduler services, or permissions apply unchanged here.
- **Design documents** explain decisions and may include explicitly labeled proposals. A proposal is not an enabled capability.
- **[Session records](sessions/)** and **[reviews](reviews/)** are dated evidence, not continuously maintained specifications or production-readiness guarantees. The [integration checklist](tasks/core-business-integration.md) tracks that workstream, not every feature in the repository.

## Additional references

- [Project overview in Simplified Chinese](../README.zh-cn.md).
- [Project license](../LICENSE); upstream licenses remain with their respective cores.

## Check documentation links

From the repository root:

```sh
node scripts/check-doc-links.mjs
node --test scripts/check-doc-links.test.mjs
```

CI runs the same local-target check. See the [scope and limitations](operations/documentation.md) before interpreting a passing result as proof of documentation correctness.
