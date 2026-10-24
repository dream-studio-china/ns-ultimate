# Historical capability catalog fixtures

These JSON files are test-only snapshots of the retired Agent backend protocol.
They are not the current configured capability surface. The composed host now
derives ordinary actions and fields from effective admin forms and metadata;
there is no backend catalog, generated manifest or PHP fixture exporter.

- `catalog.v1.json`: legacy all-ten capability surface used by generic compiler
  and synthetic browser protocol-compatibility tests.
- `configured-catalog.v1.json`: historical effective-configuration export, not
  evidence of present deployment capabilities.

Do not regenerate these fixtures from current production configuration or use
them as runtime fallbacks. They contain protocol descriptors only, never
credentials, record values or browser-storage data. Tests of the current host
must use the config-driven projection and local mutation host instead.
