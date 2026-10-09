# System Walkthrough And Test Seams

Use this map to decide where a change is best tested. The complete architecture
and endpoint inventory remain in `docs/ai/context.md` and `docs/design/`.

```text
HTTP request
  -> JwtAuthenticator / LocaleListener / controller authorization
  -> Controller validation and commonFilter scope
  -> Service transaction and domain rule
  -> Doctrine repository and database commit
  -> synchronous domain listener, or durable outbox row
  -> publisher claims outbox row
  -> Messenger consumer records inbox identity and applies side effect
  -> final persisted state and API response
```

| Seam | Primary assertion | Typical test layer |
|---|---|---|
| Request to controller | route, status, payload, authentication, owner/store/admin scope, locale | HTTP/API |
| Controller to service | accepted fields, validation, common filter cannot be bypassed | HTTP/API |
| Service to database | transaction commits atomically or rolls back completely | integration |
| Domain state transition | valid/invalid states and resulting timestamps/audit fields | unit plus integration |
| Payment/wallet boundary | explicit amounts, integer cents, duplicate reference safety | integration |
| Database to outbox | source mutation and versioned event commit together | integration |
| Outbox to consumer | claim/retry behaviour and exactly-once effective side effect | integration/handler |
| Cross-module final state | ordering, cancellation, compensation, and idempotency | cross-module integration |

If the changed requirement crosses a seam, retain a test on both sides or add a
single integration test that observes the final state. A unit test alone is not
sufficient for an HTTP authorization, transaction, or asynchronous delivery
contract.
