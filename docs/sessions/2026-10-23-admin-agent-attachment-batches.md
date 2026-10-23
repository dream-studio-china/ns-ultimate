# Attachment-driven agent batch creation

## User correction

The user requested normal attachment parsing and agent interaction instead of a dedicated Import button or field-mapping workbench. This supersedes the manual frontend workflow recorded in the [initial batch implementation](2026-10-23-admin-agent-batch-import.md); its backend transaction, actor-bound proposal and receipt guarantees remain unchanged.

## Intended flow and boundaries

Select a normal attachment, parse restricted XLSX/DOCX tables locally, then explicitly send the attachment and instruction to the model. TXT/CSV retain the existing text attachment path. The model interprets the untrusted data and prepares Tag/Counter rows through a create-only batch tool; complete canonical previews still require human confirmation. No dedicated import wizard, model commit tool, automatic selection upload, core/business changes, production migration or deployment is part of this correction.

Office input counts toward both the original-byte attachment budget and the transformed UTF-8 text budget. Parser warnings accompany the sent table; later conversation history retains only filenames. The previously reviewed restricted Office parser remains the format boundary.

## Status

Implementation and independent review are in progress. This record is not final acceptance evidence. No new commits, real business-data writes, provider calls or database migrations have been performed for this correction at this checkpoint.

## Review and intermediate checks

The dedicated Import button and mapping UI have been removed. Explicit batch capabilities register the prepare-only `prepare_record_batch` tool; model-facing output contains safe preparation metadata, not operation IDs or row values. Canonical batch cards render in chat and retain explicit checkbox confirmation, receipt binding, ambiguous-outcome recovery, proposal invalidation and single/batch serialization. Sol found no blockers in that scoped review; the coordinator corrected the reported unsupported-file localization key mismatch.

Office attachments are parsed to untrusted text with table JSON and all parser warnings. Review found forged size metadata and then global fingerprint-registry design flaws. The final implementation removes the registry and hashing entirely: a WeakMap associates original document objects and explicitly created send snapshots with frozen creation metadata. This preserves legitimate in-flight snapshots without global filename/text indexes, prevents document downgrade/tampering, and keeps identical ordinary TXT content valid. Sol independently passed all 37 attachment tests, including in-flight parse cancellation, and cleared the reviewed scope.

The coordinator independently passed the complete 226-test app suite, both existing single-record and batch real-backend cross-layer suites, frontend type checking and the composed production build. Existing build warnings remain unrelated. Updated real-DOM attachment-to-model batch acceptance is still pending; no real model/provider, account or business database has been exercised by these checks.

## Independent browser verification

The coordinator reran the updated 21-case fixture in a fresh isolated Chromium context with an additional outer network guard: all 21 passed, zero failed/skipped, no page/setup/cleanup errors or blocked requests, descriptors restored and isolation released. Cases use real local CSV and a statically embedded standard SheetJS XLSX fixture, synthetic provider tool calls, the real batch transport and synthetic backend responses. They verify no provider dispatch before Send, parsed Office text rather than ZIP bytes reaching the provider, prepare-only model behavior, explicit human confirmation, invalid batch rejection, unknown-outcome recovery, delayed-response disposal and single/batch locks. There is no Import button or field-mapping panel.

Three negative safety self-checks were independently run in separate fresh contexts; all returned `ok: true`. Production-page rejection performed zero memory/canary reads; unavailable-isolation rejection and timeout/late-work cleanup released isolation. These results are not real provider compatibility or live HTTP/account/production-MySQL validation. Browser-harness independent review remains pending at this checkpoint.

## Final review closure

Sol cleared the updated browser harness: the original 13 cases remain unchanged, the eight replacement cases exercise actual attachment/model/host/card interactions, the embedded XLSX matches the checked-in fixture, and isolation/cleanup semantics are preserved. Combined with the coordinator's fresh-context runtime verification, all scoped review findings are closed. The documentation link and final diff checks also passed. The correction adds no backend migration and does not apply the previously required batch migration; no core/business files, real business data, external provider or deployment were changed.
