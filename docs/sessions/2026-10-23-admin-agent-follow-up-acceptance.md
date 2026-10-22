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

A standalone real-DOM browser fixture is being developed to make the prior manual mocked-browser cases repeatable. It must isolate storage, intercept provider/API requests, use only synthetic data, restore patched globals and dispose all mounted instances. Simulated actor/session transitions must not be reported as real dual-account authentication. Results will be recorded after execution and review.
