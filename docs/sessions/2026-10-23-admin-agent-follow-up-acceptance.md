# Assistant follow-up acceptance — 2026-10-23

## Scope

The user requested continuation after the printing-configuration and attachment packages. Work is restricted to reproducible acceptance and test tooling: no core or business-module edits, real printing/provider calls, account creation, permission changes, PDF/binary input or assistant reprint. Development uses `opencode-go/deepseek-v4.1-flash`; review uses `openai/gpt-6.1-sol`.

## MySQL printing evidence

The extended disposable-schema harness ran on local MySQL 8.0.41 / InnoDB / `REPEATABLE-READ`. The development agent reports all previous Counter/hierarchy/JSON scenarios passed, plus:

- A shared StorePrinting proposal committed from four independent connections returned four successful results with one receipt and one committed adapter execution.
- Four independent proposals from one settings snapshot produced one success and three proposal conflicts. The final copy count matched the winning proposal; unrelated settings were preserved. Additional runs had different winners.
- Device reassignment through an independent PDO connection to either an enabled replacement account or a subsequently disabled replacement account caused proposal conflicts, despite retaining the old managed entity state in the parent. Store settings stayed unchanged.
- PrinterJob and PrinterOrderRequest counts remained zero. No provider registry/client, credential decryption or print-job service was invoked.
- Disposable-schema and cache cleanup was verified; the agent's final leftover checks reported zero schemas and cache environments.

The coordinator independently ran the connection-free guard self-test successfully. Sol reviewed the tool diff with no blockers and independently passed lint/self-tests; the reported live MySQL runs were not independently repeated by the coordinator or reviewer. These checks are direct service calls with stubbed authorization, not HTTP authorization or production/multi-host guarantees. No credentials or generated schema identifiers are retained in this record.

## Browser acceptance

A standalone [real-DOM browser fixture](../../apps/admin-agent/tests/browser/fixture.html) now makes the prior manual mocked-browser cases repeatable. The coordinator independently executed it in a fresh Chromium context with an additional outer network guard: **12 passed, zero failed, zero skipped**; no page errors, blocked requests, setup failures or cleanup errors. The isolated context was closed afterward.

The cases cover local selection without sending, current-turn text disclosure without later raw-content retention, vision off/on, remove/clear, delayed knowledge cancellation and replacement-task ownership, duplicate Send, a deferred old file read after clear/reselection, stale provider/prepared-change/query results after disposal/remount, busy file-drop default prevention, and the actual integration session monitor with synthetic in-memory actor cookies. Storage is replaced before app import; network entry points are intercepted; patched globals and mounts are restored/disposed. The final browser-harness source and workflow documentation passed focused Sol re-review.

The first review blocked approval despite the passing positive run: rejected non-fixture pages could still be mutated by broad cleanup; timed-out case continuations/observers could outlive cleanup; unavailable isolation/monitor coverage could be skipped; and image-part evidence used a constant instead of its actual count. The fixes move all checks before mutation, track owned resources, abort/settle timed-out cases before restoration, require every isolation channel, reject skips, and count actual image parts.

The coordinator independently reran the final positive suite and three negative self-checks in four separate fresh Chromium contexts, with an outer network guard. The main run again passed all 12 cases with restored descriptors and no retained isolation. Production-page rejection preserved the synthetic existing agent/DOM/storage/globals with zero test cases run. An unpatchable required cookie rejected setup with zero mounts and unchanged network/storage descriptors. The timeout check deliberately failed its inner case, stopped the run, restored native fetch only after settlement, disconnected the observer and rejected late mount/read/fetch attempts; its outer self-check passed. All four contexts had zero page errors and zero unexpected network requests and were closed afterward.

Review additionally caught native-storage access inside the guard self-check itself. It now captures/restores descriptors without invoking original accessors and verifies its sentinel through a closure-owned memory map. The coordinator installed an outer throwing storage getter and independently reran the final guard check: original getter calls, canary reads/writes and memory accessor reads were all zero; the original descriptor and existing synthetic agent were preserved. Zero memory reads is included in the success gate, not merely reported. The final 142-test Node suite, browser-module syntax checks, local document links and diff whitespace checks passed. No application, integration, core or business implementation was changed for this follow-up; browser fixtures are opt-in test files, not production entries.

The query case executes the real `query_records` tool path with a delayed synthetic host response. It establishes suppression at the rendering/model-context boundary in this fixture, not real backend read authorization or real delayed authenticated responses. Synthetic actor transitions exercise the real monitor but do not establish dual-account authentication. Live-provider compatibility and real dual-account/backend authorization acceptance remain outstanding. Reproduction and safety requirements belong to the [app workflow](../../apps/admin-agent/README.md#repeatable-mounted-browser-acceptance).
