# Inventory module — Repositories & MessageHandlers coverage to ~100% & bug hunt (2026-08-09)

Scope: `src/Inventory/Repository/RecipeLineRepository.php`, `src/Inventory/Repository/ReservationLineRepository.php`, `src/Inventory/MessageHandler/InventoryReservationRequestedHandler.php`, `src/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandler.php`.
Goal: raise line coverage to ~100% and document bugs. **No code under `src/` was modified.**

## Test files added

| File | Tests | Purpose |
| --- | --- | --- |
| `tests/Inventory/Repository/RecipeLineRepositoryTest.php` | 5 (new) | Container instantiation of the trivial `ServiceEntityRepository` subclass (covers the constructor line), `findAll`, `findOneBy` material / `findBy` recipe, `find(id)` + unknown-id null, `remove`+flush. |
| `tests/Inventory/Repository/ReservationLineRepositoryTest.php` | 5 (new) | Container instantiation (covers the constructor line), `findAll`, `findBy` materialUuid, `findBy` reservation, `find(id)` via reflection (entity exposes no id getter) + unknown-id null, `remove`+flush. |
| `tests/Inventory/MessageHandler/InventoryReservationRequestedHandlerTest.php` | 12 (new, unit) | Envelope validation: wrong type/version, malformed envelope, missing payload fields, empty items, non-ISO timestamp, impossible calendar date (`2026-02-30`), fractional-second timestamp + expiry check, expiry ≤ request time, malformed item (non-UUID catalog ref, zero quantity, duplicate lineId); mock-based skip-inside-transaction (line 36). |
| `tests/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandlerTest.php` | 8 (new, unit) | Envelope validation: wrong type/version, malformed envelope, invalid correlation, empty reason, missing `requestedAt`, non-ISO timestamp; mock-based event-ID reuse conflict (lines 128-129) and skip-inside-transaction (line 37). |
| `tests/Inventory/MessageHandler/InventoryReservationRequestedHandlerIntegrationTest.php` | 3 (new) | Recipe-resolution failure through the handler (`MATERIAL_INACTIVE` rejection), missing specification (`SPECIFICATION_NOT_STOCKABLE` rejection), and service exception propagation with consumed-event rollback (`InventoryReservationConflictException`). |
| `tests/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandlerIntegrationTest.php` | 3 (new) | Release for unknown reservation throws (line 41), same-event-id idempotency (lines 32, 132, single consumed row), and already-released reservation is a silent no-op for a second (different event-id) release. |

Existing `tests/Inventory/*` were **not** modified. Total added: 36 tests, all passing, **0 skipped**.

## Coverage results

Measured with PHP 8.5 + Xdebug (`XDEBUG_MODE=coverage`, `--coverage-clover`) over the whole `tests/Inventory` tree (109 tests, 351 assertions, all green). Line coverage per target file:

| File | Before | After |
| --- | --- | --- |
| `Inventory/Repository/RecipeLineRepository.php` | **0%** (0/1) — line 16 | **100%** (1/1) |
| `Inventory/Repository/ReservationLineRepository.php` | **0%** (0/1) — line 16 | **100%** (1/1) |
| `Inventory/MessageHandler/InventoryReservationReleaseRequestedHandler.php` | 83.33% — lines **32, 37, 41, 76, 94, 98, 101, 102, 128, 129, 132** | **100%** (66/66 stmts) |
| `Inventory/MessageHandler/InventoryReservationRequestedHandler.php` | 92.86% — lines **36, 104, 105, 108, 125, 156, 163, 175** | **99.11%** (111/112 stmts) — sole remaining uncovered statement is **line 163**, which is provably unreachable (see Bug #2) |

Newly covered behaviour, by line:

- **Release handler**: line 32 outer idempotent early-return (same message delivered twice); line 37 inner skip-inside-transaction (defence in depth, exercised with a queueing repository mock); line 41 `Reservation was not found.`; line 76 malformed-envelope throw; line 94 invalid release payload (blank reason / missing `requestedAt`); lines 98/101/102 non-ISO `requestedAt` throw + re-wrap; lines 128-129 `InventoryMessageIntegrityException` on event-ID reuse with a different payload; line 132 `isAlreadyConsumed()` returning true for a matching hash.
- **Requested handler**: line 36 skip-inside-transaction (queueing repository mock); lines 104-105 timestamp parse error re-wrap; line 108 `expiry must be after request time`; line 125 malformed item (non-UUID catalog reference, zero quantity, duplicate lineId); line 156 non-ISO timestamp; lines 161-168 fractional-second `parseDate` branch (padding + `\.u` format); line 175 impossible-calendar-date rejection via `DateTimeImmutable::getLastErrors()`.
- **Repositories**: constructor `parent::__construct()` instantiated through the container and exercised with real `find`/`findBy`/`findAll`/`remove` against the SQLite test DB.

Lines 31 and 188 of the requested handler and line 48 of the release handler are covered by the pre-existing `tests/Inventory/Integration/InventoryMessagingAndApiTest.php` (`testRequestedAndReleasedMessagesAreIdempotent`, `testInboxRejectsEventIdReusedWithDifferentPayload`, `testReleaseMessageRejectsMismatchedReservationCorrelations`); they are **not** duplicated in the new files.

## Bugs found

### Bug #1 — Release handler accepts impossible calendar dates; its `new \DateTimeImmutable()` check is a no-op

- **File/line:** `src/Inventory/MessageHandler/InventoryReservationReleaseRequestedHandler.php:97-100`.
- **Description:** `requestedAt` is validated only by the regex at lines 97-98, then `new \DateTimeImmutable($requestedAt)` at line 100. PHP's `DateTimeImmutable` constructor silently *normalizes* out-of-range dates instead of throwing — `2026-02-30T00:00:00+00:00` becomes 2026-03-02 with no exception — so line 100 never fails and adds nothing beyond the regex. The `catch` at 101-102 can only ever be triggered by the regex `throw` at line 98. Meanwhile the reservation-request handler's `parseDate()` (`InventoryReservationRequestedHandler.php:153-179`) rejects the same impossible date via `DateTimeImmutable::getLastErrors()`. The two handlers validate the same kind of timestamp inconsistently.
- **Impact:** Low today (`requestedAt` is recorded but never compared), but the release side silently accepts corrupt timestamps that the request side rejects, and line 100 is dead defensive code.
- **Reproduction (integration probe):** a valid release message with `requestedAt = '2026-02-30T00:00:00+00:00'` is processed successfully (releases the reservation, consumes the event). The identical date in a reservation-request message is rejected with `InvalidArgumentException`.
- **Proposed fix:** parse `requestedAt` with the same strict helper as the request handler (check `getLastErrors()`, e.g. share `InventoryReservationRequestedHandler::parseDate()` or extract a common validator), and drop the redundant `new \DateTimeImmutable()` line.

### Bug #2 — Unreachable defensive branch in `parseDate()` fraction split

- **File/line:** `src/Inventory/MessageHandler/InventoryReservationRequestedHandler.php:161-163`.
- **Description:** `$parts = preg_split('/(?=[+-]\d{2}:\d{2}$)/', $normalized); if ($parts === false || count($parts) !== 2)`. The pattern is a zero-width lookahead anchored at end-of-string, so for any ISO string containing a dot it matches exactly once (immediately before the trailing timezone offset); `preg_split` always yields exactly two parts and never returns `false` for these inputs (the caller's regex has already constrained the timestamp shape and the fraction to `\d{1,6}`). Line 163 is unreachable.
- **Impact:** None functionally; it is the single statement keeping the requested handler at 99.11% and signals the check was written defensively against a malformed-input class the regex already excludes.
- **Reproduction:** Coverage — line 163 remains the only uncovered statement across all 109 Inventory tests; the branch cannot be entered for any input.
- **Proposed fix:** remove the `count($parts) !== 2` alternative (keep only a `$parts === false` guard), or replace the `preg_split`/`explode` dance with two capture groups in the timestamp regex.

### Bug #3 (operational, medium) — Any handler failure closes the shared EntityManager

- **File/line:** `InventoryReservationRequestedHandler.php:34` and `InventoryReservationReleaseRequestedHandler.php:35` call `$this->entityManager->wrapInTransaction(...)`; `Doctrine\ORM\EntityManager::wrapInTransaction()` (`vendor/doctrine/orm/src/EntityManager.php:180`) calls `$this->close()` in its `finally` block on any exception before rolling back.
- **Description:** Every failure path in these handlers — reservation not found (release:41), correlation mismatch (release:48), event-ID/payload conflict (requested:188, release:128-129), or a service exception such as an oversized quantity (see Bug #4) — leaves the container's EntityManager **closed** and its identity map cleared for the rest of the process. Verified empirically: after an `InventoryReservationConflictException`, `$em->isOpen()` returns `false` and the next `persist()`+`flush()` throws `Doctrine\ORM\Exception\EntityManagerClosed: The EntityManager is closed.` (read-only repository queries still work because `find()` does not assert `isOpen()`).
- **Impact:** In a Messenger worker processing several messages per process (sync transport, or async workers without per-message EM reset), a single failing Inventory message poisons the shared EM for every subsequent message — their writes crash with `EntityManagerClosed`. Each failure mode is also a permanent retry loop: because the consumed-event row is written inside the same transaction that failed, the message is never acknowledged, and the project has no dead-lettering (consistent with the previously documented no-max-attempts outbox finding).
- **Reproduction (integration probe):** trigger a reservation conflict through the requested handler, then `persist()` + `flush()` on the same container `EntityManager` → `EntityManagerClosed`.
- **Proposed fix:** run each handler on a fresh/settable EM (messenger `DoctrineClearEntityManagerWorkerSubscriber` or reset-on-failure middleware), or have the handlers acknowledge the consumed event *before* the failure-prone service call so poison messages don't wedge the worker; at minimum document that these handlers require a fresh-EM-per-message worker.

### Bug #4 (validation gap, low) — Oversized quantities pass handler validation, then crash inside the service

- **File/line:** `src/Inventory/MessageHandler/InventoryReservationRequestedHandler.php:122` (item quantity regex `/^[0-9]+(?:\.[0-9]{1,6})?$/`) vs `src/Inventory/Service/Quantity.php:20-22` (14-digit integer cap, `decimal(20,6)`).
- **Description:** The handler's quantity regex permits arbitrarily long integer parts. `Quantity::normalize()` caps the integer part at 14 digits and throws `InvalidArgumentException: Quantity exceeds decimal(20, 6).`. A producer sending `quantity: '123456789012345'` passes `validateEnvelope()`, then the service throws inside the handler's transaction, failing the message (and, per Bug #3, closing the EM).
- **Impact:** Low; a syntactically-valid but out-of-range quantity becomes a poison message retried forever instead of being rejected by the envelope validator.
- **Reproduction (integration probe):** requested message with `quantity = '123456789012345'` passes `validateEnvelope()`, then the handler fails with `InvalidArgumentException: Quantity exceeds decimal(20, 6).`.
- **Proposed fix:** bound the digits in the handler regex, e.g. `/^[0-9]{1,14}(?:\.[0-9]{1,6})?$/`, or call `Quantity::normalize()` on each item inside `validateEnvelope()` so out-of-range quantities are rejected up front.

## Notes

- `src/Inventory/Message/*` classes were already covered by the pre-existing `tests/Inventory/Message/InventoryMessageTest.php` plus the outbox-publish tests; no new message tests were needed.
- No correct-behaviour test had to be skipped: all 36 new tests pass against the current `src/`. Bugs #1, #3 and #4 were confirmed with throwaway integration probes (deleted afterwards), not by committed passing/failing tests.
- When several `DatabaseBootstrapTrait` test classes are executed in a single PHPUnit process, the shared `var/test.db` can throw transient `no such table` / `database is locked` / leftover-row failures; the workaround is running each file (or small group) separately, as documented in the task brief. Individually, every new file is green.
