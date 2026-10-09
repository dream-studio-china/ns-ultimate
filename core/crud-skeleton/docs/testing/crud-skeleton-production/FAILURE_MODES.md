# Failure Modes And Regression Controls

| Failure mode | Customer or system impact | Required prevention test | Detection / recovery |
|---|---|---|---|
| Authorization scope omitted or widened | cross-user or cross-store data exposure | HTTP tests for owner, non-owner, anonymous, and admin paths | access logs; revoke exposure and patch with regression test |
| Invalid workflow transition accepted | order reaches an impossible state | valid/invalid transition tests and persisted side-effect assertions | workflow errors and order-state audit; compensate only via defined transition |
| Partial write after a service error | money, stock, or order state diverges | transaction rollback integration test covering every mutated aggregate | consistency audit; reconcile using audited compensating operation |
| Duplicate command or message | double debit, reservation, or notification | duplicate HTTP/message delivery test asserting one durable side effect | inbox/outbox metrics and unique constraints; replay only idempotently |
| Out-of-order async event | cancelled order is later accepted or stock remains held | ordering/tombstone test for both arrival orders | dead-letter/handler logs; retry using durable event identity |
| Lost outbox publication | downstream module never observes a committed change | transaction plus publisher claim/retry test | backlog age and unpublished-row monitoring; safely republish |
| Database dialect/schema regression | CI passes but deployed query or migration fails | fresh-schema CI test and MySQL-compatible staging migration/query validation | migration/deployment health check; stop rollout and roll back schema-compatible release |
| Payment callback replay or wrong amount | fraudulent or duplicate settlement | signature/provider contract test, explicit amount test, and replay test | gateway logs and invoice audit; refund/reconcile under provider rules |
| Inventory race or oversell | unavailable goods accepted or reservation leaked | serialized/concurrency test on a production-like database before enabling inventory | stock/reservation audit; release or compensate through ledger-backed command |
| External provider outage | login, upload, or payment path fails unpredictably | deterministic adapter failure/timeout test and staging certification | provider error metrics; retry only idempotent operations and expose actionable failure |
| Migration with no rollback plan | deploy cannot safely recover | migration rehearsal with backup/restore and forward-fix plan | deployment guard; halt rollout before destructive change |

An incident that is not covered by a row above still follows the same rule:
write the smallest regression test that reproduces the escaped behaviour, then
add or refine the row and invariant if the failure exposed a new class of risk.
