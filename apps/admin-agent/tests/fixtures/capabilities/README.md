# Capability catalog fixtures

This directory separates the actual configured catalog from the explicit legacy
safety fixture. Both are test resources only; production code has no static
fallback and reads the authenticated runtime catalog.

## Actual configured catalog

`configured-catalog.v1.json` is the current effective-configuration export. It
is produced from the generated admin manifest through the real capability
service:

```sh
node integration/admin/export-agent-manifest-cli.mjs
php apps/admin-agent/backend/tests/Contract/export-capability-catalog.php \
  | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>process.stdout.write(JSON.stringify(JSON.parse(s),null,2)+'\n'))" \
  > apps/admin-agent/tests/fixtures/capabilities/configured-catalog.v1.json
```

The actual exporter ignores test-environment overrides and always reads the
materialized effective-configuration manifest. For the current configuration it
contains nine capabilities, excludes `Specification`, excludes unconfigured
Content `metadata`/`sortOrder`, and retains the two printing configuration
capabilities.

## Legacy safety fixture

`catalog.v1.json` is the explicit pre-gate all-ten fixture used by generic
compiler and browser safety tests. It is not the configured catalog and must not
be cited as current deployment behavior. It is checked against the dedicated
synthetic legacy exporter, not a hand-maintained field table:

```sh
php apps/admin-agent/backend/tests/Contract/export-legacy-capability-catalog.php \
  | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>process.stdout.write(JSON.stringify(JSON.parse(s),null,2)+'\n'))" \
  > apps/admin-agent/tests/fixtures/capabilities/catalog.v1.json
```

The synthetic exporter installs the legacy test manifest, runs the same
capability service pipeline, and restores the environment afterward. Changing
the legacy safety descriptors without regenerating this fixture fails the
catalog contract tests.

## Rules

The response contains only `schemaVersion`, `revision`, `capabilities`, and
`batchCapabilities`, with no functions, credentials, record values, or browser
storage data. Do not add a second hand-maintained field/action table to
production JavaScript, and do not use either fixture as a runtime fallback.
