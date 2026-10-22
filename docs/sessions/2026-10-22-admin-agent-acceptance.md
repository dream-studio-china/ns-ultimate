# Admin agent local acceptance

## Scope and changes

Validated the composed production frontend against the real Symfony HTTP backend, with three test accounts and a new isolated SQLite database outside the repository. Only provider responses were replaced with deterministic streamed tool calls; no external model credentials or production database were used. Browser inspection opened the agent shadow root through a test-only initialization hook.

Fixed two composition failures discovered during acceptance:

- Accept the admin HTTP client's bounded `_locale` transport parameter on the operation list, while continuing to reject actor overrides, arbitrary filters, malformed locales and invalid pages.
- Allow IPv4 loopback provider URLs, consistently with the existing local-provider policy.

## Results

- Preparing Content did not persist a record. Explicit confirmation created one record; replaying the same commit returned success without another record.
- Reload discarded the local confirmation card. The operations UI restored the original operation, requiring explicit confirmation again.
- Confirmed update persisted the expected fields. Delete required its separate typed confirmation and the target subsequently returned 404.
- A concurrent ordinary CRUD edit prevented stale commit and preview restoration (409); the concurrent value remained intact.
- A deliberately lost successful commit response blocked chat until outcome lookup. Lookup recovered success, with only one commit request and no automatic retry.
- Another administrator saw an empty operation list and received 404 for the owner's status, preview, commit and cancellation. A non-admin prepare returned 403.
- Revoking the owner's admin role after preparation caused preview and commit to return 403. Test-only expiry adjustment caused status to report `expired` and preview/commit to return 409, without a Content write.
- Script-like proposal text did not execute in the confirmation UI. Provider requests contained neither host cookies nor host authorization headers.
- Backend suite: 185 tests; frontend/script suite: 124 tests. PHPStan and the composed production build passed. Existing Qiniu deprecation and CSS/chunk build warnings remain.

## Limits and next step

SQLite and the single-process development HTTP server do not establish production-database locking or simultaneous-request guarantees. Sequential commit replay was tested, not concurrent double-click submission. Schema was initialized with the ORM schema tool in the disposable database; production migrations, backups and pruning schedules were not exercised.

A stale commit currently follows the conservative unknown-outcome UI path; status lookup reports it pending even though the old snapshot cannot commit. The user must discard and prepare a fresh proposal. This is safe but could be made clearer in a separate UX improvement.

Next: validate migration/pruning and concurrent transactions in an approved staging environment using the production database engine before expanding executable capabilities.
