# Capability catalog fixture

`catalog.v1.json` is a snapshot of the canonical runtime capability response
used by frontend tests. It is generated from the backend exporter, not written
by hand and not imported by production code:

```sh
php apps/admin-agent/backend/tests/Contract/export-capability-catalog.php \
  | node -e "let s='';process.stdin.on('data',d=>s+=d).on('end',()=>process.stdout.write(JSON.stringify(JSON.parse(s),null,2)+'\n'))" \
  > apps/admin-agent/tests/fixtures/capabilities/catalog.v1.json
```

The response contains only `schemaVersion`, `revision`, `capabilities`, and
`batchCapabilities`, with no functions, credentials, record values, or browser
storage data. Production code has no static fallback: it only reads the
authenticated `GET /api/v1/manage/agent/capabilities` response at runtime.

Regenerate this fixture from the exporter whenever the backend capability schema
changes, and review the diff. Do not add a second hand-maintained field/action
table to production JavaScript, and do not use this fixture as a runtime
fallback.
