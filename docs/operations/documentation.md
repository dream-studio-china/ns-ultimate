# Documentation ownership and maintenance

## Stable entry point

[The documentation hub](../README.md) routes readers by task. It contains links and applicability rules, not copied commands, runtime versions, field limits, feature matrices, or test counts. Routine code changes should not require editing the hub. Update navigation only when entry points change.

Do not maintain a parallel central handbook containing the same facts. Package guides and operational procedures remain independently readable and link to their authoritative references.

## One maintenance location per fact

| Information | Authoritative location | Other documents should |
| --- | --- | --- |
| Runtime defaults, field validation, permissions, state transitions | Owning implementation and deterministic tests | Explain the guarantee or link to its reference; avoid repeating incidental constants |
| HTTP routes and request/response schemas | Controller declarations and generated OpenAPI where covered | Link to API documentation; do not create another handwritten endpoint inventory |
| Cross-layer guarantees and compatibility | `docs/contracts/` | Link to the relevant contract |
| Architecture and rationale | `docs/design/` | Link to decisions; label unimplemented proposals separately from current behavior |
| Package setup, user workflow, extension boundaries | The owning app/module README or manual | Link from the hub; keep package-specific detail with its owner |
| Deployment topology, release and incident steps | Project deployment guides, `infra/` documentation, and `docs/runbooks/` | Choose the procedure for the actual environment; link rather than duplicate it |
| Upstream module behavior | Core manuals/runbooks, checked against the pinned source | Reference them and document only composition-specific differences outside `core/` |
| Verification results and historical work | Dated `docs/sessions/` and `docs/reviews/` records | Treat results as evidence for that revision/environment, not evergreen guarantees |
| Translations | Locale-suffixed companion documents | Preserve identifiers/commands and synchronize meaning with the canonical English source |

Some facts belong in both code and a public contract. If an observable guarantee changes, update its contract and tests together. This policy reduces duplicate maintenance; it does not remove the need to document behavior changes.

## Reusing upstream runbooks

Project-level runbooks take precedence for operating the composed deployment. Upstream runbooks remain module references, not automatically validated project procedures.

Before referencing an upstream procedure, check its implementation status, routes, permissions, command availability, environment, and external side effects against the pinned core and composition. Document only the project delta: application entry point, selected adapters, additional tasks, omitted services, and success/failure checks. Never mechanically replace command paths and assume the rest is equivalent.

For example, the composed backend enters through `integration/backend/bin/console`; an upstream scheduler description does not mean the project starts that scheduler. Disabling an app's frontend does not necessarily disable its backend routes. Link to the owning package's contract for these boundaries.

Do not copy upstream manuals into project docs or modify `core/` to fix project-specific guidance. Core edits require the explicit authorization defined by the repository guidelines; record upstream discrepancies separately until authorized.

## Change checklist

1. Identify the owning source from the table above.
2. Update only affected guarantees and operational steps. Add links instead of repeating detail across README, design, contract, and runbook pages.
3. If moving/removing a document, update incoming links. Change the hub only if one of its entry points changes.
4. Keep historical records dated. Add a follow-up link when useful rather than rewriting old results as new verification.
5. Check examples against the implementation and use placeholders for environment-specific values. Do not execute migrations, resets, or external-provider operations merely to validate prose.
6. Run the link checker and review the diff. Documentation-only edits do not require unrelated application suites.

## Automated local-target checks

```sh
node scripts/check-doc-links.mjs
node --test scripts/check-doc-links.test.mjs
```

The dependency-free checker discovers Git-tracked and non-ignored untracked Markdown files outside `core/`. It checks local inline links, images, and reference definitions relative to the source file; repository-root paths start at the repository root. Both file and directory targets are allowed, including links into the pinned cores. It rejects targets that escape the repository and reports missing targets with source line numbers. CI fails on these errors.

Fenced code blocks and inline code examples are ignored. URL query/fragment suffixes are removed before checking the filesystem; fragment-only links and external URL schemes are skipped. Anchor correctness, external availability, HTML links, dynamically constructed links, and full CommonMark parsing are not covered. Core documents are not scanned as sources: upstream documentation remains upstream-owned, while project links into core are checked for existence.

A passing check establishes link-target existence, not semantic accuracy, API compatibility, or production readiness. Runtime command/schema generation and executable documentation examples can be added separately when they can run safely in an isolated environment; they are not implemented by this checker.
