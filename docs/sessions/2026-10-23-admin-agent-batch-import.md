# Admin-agent batch import implementation

## Approved boundary

Implement a separate local file-import workbench for create-only Tag and Counter batches. A batch contains at most 20 records of one entity; Counter rows share one explicitly selected existing active Store UUID. Complete canonical row previews and explicit human confirmation precede an atomic backend commit. No core or business module changes, overwrites, batch deletion, model commit tools, external printing, deployment or production migrations are authorized.

TXT/CSV and bounded XLSX/DOCX parsing are separate from chat attachments. Legacy DOC/XLS and actual XSL are not part of the first implementation. Imported file content must not be automatically uploaded to a model or persisted in browser storage.

## Baseline verification

Before integration, the coordinator ran the app Node suite: 144 tests passed. The composed backend suite passed 276 tests and 4,906 assertions using the configured disposable SQLite environment. The existing Qiniu implicit-nullability deprecation remains unrelated. The coordinator also ran the 60 admin integration tests and the existing 13-case real-DOM browser regression in a fresh isolated Chromium context; both passed. The browser run reported no page/setup/cleanup errors or blocked requests, restored descriptors, and released isolation. That existing browser suite does not yet exercise batch import.

## Review and integration status

Implementation and review are in progress; this record is not final acceptance evidence for the new feature. Initial backend review identified an unquoted MySQL-reserved physical column name and missing direct-commit payload-hash verification. Cross-layer test review also identified incompatible verification markers on replayed commit responses; acceptance must send the actual replay through the commit host method, not substitute a status request. These require fixes and regression coverage before acceptance. SQLite success does not establish MySQL concurrency behavior.

No environment migration, deployment, push or real business-data write has been performed for this work.

## Intermediate cross-layer verification

The coordinator independently ran `node --test apps/admin-agent/tests/backend-batch-preview-contract.integration.mjs` after the commit-replay protocol fix; it passed. The fixture exercises real Tag/Counter adapters in disposable SQLite, checks prepare/restore without domain writes, verifies complete rollback and metadata-only receipts, and passes first and replayed commit responses through the real batch host transport. First and replayed commit receipts are identical, including the verification marker, and physical row counts do not increase on replay. This does not close the remaining frontend preview-binding review findings or establish browser import acceptance.

## Backend and transport review closure

Sol re-review closed the backend column-name and integrity findings: the physical column is `proposal_rows`; commit and restore verify the persisted payload hash and canonical revalidation before executing the original stored rows. The reviewer independently passed 14 tests and 160 assertions, including stale Store and partial-execution rollback. The coordinator independently passed the complete composed backend suite: 290 tests and 5,118 assertions.

Sol also closed the transport findings after frozen-submission row/field/value binding and signed-32-bit numeric result-ID checks were added. The reviewer independently passed all 16 transport tests. The coordinator independently passed the current 193-test app Node suite, frontend type checking, and composed production build. Existing Vite/CSS/chunk warnings remain. These are intermediate checks; parser review and batch-specific real-DOM acceptance are still pending.

## Open follow-up review findings

Workbench review identified cross-flow locking gaps between batch submission/unknown outcomes and existing single-record confirmation/history recovery, plus insufficient receipt-to-proposal metadata matching. These are under repair with interleaving and mismatched-receipt regression tests.

Parser review found unsupported Word field instructions, package-wide active-content/formula screening gaps, silent DOCX structural/text loss, ambiguous worksheet/container and package-part declarations, missing integrity verification for unused ZIP entries, lost explicitly empty CSV rows, and unhandled SpreadsheetML string escapes. No Office execution or file-exfiltration path was observed. These remain delivery blockers until fixed, regression-tested and re-reviewed. Standard-tool-generated Office samples are also required for interoperability evidence; handcrafted fixtures alone do not establish normal Office compatibility.

## Batch browser acceptance tooling

The isolated real-DOM suite now contains 21 cases, preserving the original 13 and adding eight batch cases. Its developer reported 19 passing and two failing cases that reproduce the workbench locking/receipt findings. The harness review identified an over-constrained enabled-control expectation and unmanaged deferred-commit cleanup. Both were fixed and cleared by Sol re-review: enabled controls use normal clicks; disabled controls receive synthetic events to exercise action-time guards, with actual zero-host-call assertions retained; deferred commits are owned by the abort-managed case context. The reported failing run restored descriptors, released isolation and had no cleanup errors. This is useful failure evidence, not a passing feature acceptance run.

After the workbench fixes, the coordinator independently reran all 21 cases in a fresh isolated Chromium context with an outer network guard: 21 passed, zero failed/skipped, no page/setup/cleanup errors or blocked requests, descriptors restored and isolation released. Three negative safety self-checks were independently run in separate fresh contexts and returned `ok: true`: production-page rejection (zero memory/canary reads), unavailable-isolation rejection, and timeout suppression of late work. No context contacted real authentication, application APIs, model providers, file storage or printers. These browser results use synthetic host/server responses and do not replace parser security review, real HTTP authorization or production-database concurrency validation.

## Final integration checks pending parser approval

Workbench Sol re-review closed both lifecycle findings and independently passed 55 Node tests plus three late-response disposal probes. The coordinator then independently passed the full 209-test app suite, both real-backend single-record/batch cross-layer contract suites, the composed production build and the documentation link check. Backend and transport were committed separately as `b38b14f0` and `fbbb7f7f`.

The coordinator also parsed the checked-in SheetJS-generated XLSX and Pandoc-generated DOCX in a fresh Chromium context using the actual browser parser and `File` API. Both preserved all six columns and four data rows, including newline/punctuation/empty-cell values; only the expected formatting/ancillary-text warnings were returned. Static fixture GET requests were the only network access. These are standard-generator samples, not Microsoft Office/LibreOffice certification. Parser security re-review remains pending at this checkpoint.

Parser follow-up review found further path-bound part discovery, incorrect shared-string relationship handling, unsupported cell/text loss and decompression-abort cleanup gaps. The developer addressed these with effective-content-type/relationship discovery, strict value containers, preserved Word paragraph boundaries, explicit unsupported-text rejection, and abort-managed reader cancellation/release. The coordinator independently passed the resulting 213-test Node suite, type checking, documentation links and diff checks. Independent parser approval remains outstanding; developer test success is not security signoff.

## Final review closure and acceptance

The final parser review identified one additional indexed-shared-string structural gap. After applying the same strict string-container validation to every shared-string entry and rejecting unsupported collection children rather than skipping indexes, Sol independently approved the reviewed parser source/tests/fixtures and passed all 34 parser tests. No remaining blockers were identified in the backend, transport, workbench, parser or browser-harness review scopes.

The coordinator independently passed the resulting 214-test Node suite and production build, then reran all 21 browser cases and both standard-generator fixtures in a fresh guarded Chromium context. All passed; the run had no page/setup/cleanup errors, blocked requests or retained isolation. Earlier independently run checks in this session also passed: backend 290 tests/5,118 assertions, admin integration 60 tests, both cross-layer suites, type checking and all three browser safety self-checks.

Remaining validation boundaries: no real HTTP dual-account authentication, production-topology/load testing, or MySQL batch contention run was performed. The offline MySQL SQL regression checks column compatibility, not live locking. Office support is restricted OOXML; the fixtures demonstrate SheetJS/Pandoc interoperability, not arbitrary Microsoft Office/LibreOffice document compatibility. The batch migration remains unapplied in real environments. No core/business files were edited, and no push, deployment, printing or real business-data mutation was performed.
