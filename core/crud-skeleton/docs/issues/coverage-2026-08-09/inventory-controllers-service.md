# Inventory Controllers & Services — Coverage & Bug Report

- Date: 2026-08-09
- Scope: Inventory management controllers, Inventory service layer, Inventory entities, outbox command
  - `src/Inventory/Controller/Manage/RecipeController.php`
  - `src/Inventory/Controller/Manage/StockController.php`
  - `src/Inventory/Service/InventoryService.php`
  - `src/Inventory/Service/Quantity.php`
  - `src/Inventory/Service/SpecificationRecipeService.php`
  - `src/Inventory/Entity/InventoryReservation.php`, `InventoryStock.php`, `Material.php`
  - `src/Inventory/Command/PublishOutboxCommand.php`
- Task: raise line coverage of these files to ~100% and find bugs.
- Constraint honored: **nothing under `src/` was modified**. Only new test files under `tests/` and this report were added.

## Test files added

All new files use `declare(strict_types=1);` and only ADD coverage — nothing in `tests/Inventory/*` was modified.

| File | Namespace | Covers |
|---|---|---|
| `tests/Inventory/Controller/Manage/RecipeControllerTest.php` | `App\Tests\Inventory\Controller\Manage` | `RecipeController` (unit, mocked service) |
| `tests/Inventory/Controller/Manage/StockControllerTest.php` | `App\Tests\Inventory\Controller\Manage` | `StockController` (unit, mocked services) |
| `tests/Inventory/Service/QuantityCoverageTest.php` | `App\Tests\Inventory\Service` | `Quantity` normalize/multiply edge cases |
| `tests/Inventory/Service/SpecificationRecipeServiceTest.php` | `App\Tests\Inventory\Service` | `SpecificationRecipeService` (integration, real DB) |
| `tests/Inventory/Entity/InventoryEntityCoverageTest.php` | `App\Tests\Inventory\Entity` | entity branch gaps (unit) |
| `tests/Inventory/Command/PublishOutboxCommandTest.php` | `App\Tests\Inventory\Command` | `PublishOutboxCommand` (unit, mocked repo/bus) |
| `tests/Inventory/Integration/InventoryServiceCoverageTest.php` | `App\Tests\Inventory\Integration` | `InventoryService` failure/idempotency paths (integration, `DatabaseBootstrapTrait` + `WebTestCase`) |

Controller unit tests follow the existing `tests/Trade/Controller/Manage/OrderControllerTest.php` pattern: mocked collaborators, `#[AllowMockObjectsWithoutExpectations]`, and `setRequestStack`/`setSerializer`/`setTranslator` injection. DB tests reuse `DatabaseBootstrapTrait` + `IntegrationWebTestCase`. No `ReflectionProperty::setAccessible()` is used anywhere.

For the three one-line create hooks on `RecipeController` (`defaultCreateValues`, `processCreateContent`, `afterCreated` at lines 35/44/49) the tests invoke them via `ReflectionMethod::invoke()` (no `setAccessible()`, which is deprecated in PHP 8.5). These hooks are only reachable through `UpdateApiViewMixin`'s create-mode (`@mode=mixed`) path, which is not wired up by this controller's own `createAction`; direct invocation is the only way to exercise them without modifying `src/`.

## Coverage results

Baseline from `var/uncovered-map.txt` (generated 2026-08-09). After-values verified by running the combined Inventory suite with `XDEBUG_MODE=coverage` and diffing the `count="0"` statement lines from the Clover report:

```
XDEBUG_MODE=coverage php vendor/bin/phpunit tests/Inventory/** --coverage-clover /tmp/inv-final.xml
```

| File | Baseline | After new tests |
|---|---|---|
| `RecipeController` (was 79.31%) | 35,44,49,73,76,83 | **100% — all covered** |
| `StockController` (was 93.1%) | 58,59 | **100% — all covered** |
| `SpecificationRecipeService` (was 93.33%) | 35 | **100% — all covered** |
| `InventoryService` (was 94.16%) | 78,101,102,105,112,117,143,159,167,212,284,289,331,341,379 | **100% — all covered** |
| `Quantity` (was 96.15%) | 14,26,75 | **98.72% — 14,26 covered; line 75 is dead code (see Bug 1)** |
| `InventoryReservation` (was 97.14%) | 91 | **100% — covered** |
| `InventoryStock` (was 96.3%) | 91 | **100% — covered** |
| `Material` (was 97.56%) | 190 | **100% — covered** |
| `PublishOutboxCommand` (was 97.22%) | 32 | **100% — covered** |

### What each group of tests covers

**`InventoryServiceCoverageTest`** (the biggest win — all 15 baseline-uncovered lines):

- **78** — `adjustStock` rejects `0.000000` delta and blank reason.
- **105** — repeated `adjustStock` with the same `referenceId` is idempotent: the existing stock is returned and the on-hand balance is not re-applied.
- **101/102** — a ledger entry whose stock row has been removed (orphan ledger) makes the idempotent `adjustStock` fail loudly with `Adjustment ledger does not have a stock balance.`.
- **112** — `allowNegativeStock` parameter applied to an *existing* stock inside `adjustStock`.
- **117** — adjusting on-hand below the confirmed-reserved quantity is rejected.
- **143 / 159** — `setStockAllowNegative` with a missing material in both the pre-check branch (`false`) and the transaction branch (`true`).
- **167** — `setStockAllowNegative` updates an existing stock row.
- **212** — a second `reserve` for a `storeOrderUuid` that already has a reservation raises `InventoryReservationConflictException`.
- **284 / 289** — `release` fails explicitly when a reservation line's material, or its stock row, is missing.
- **331 / 341** — `reserve` rejects an empty item list and duplicate/malformed line IDs.
- **379** — a recipe whose line material is inactive yields a `MATERIAL_INACTIVE` rejection.

**`SpecificationRecipeServiceTest`** — **35** (duplicate `specificationUuid`) plus the missing/inactive-material branch, against the real DB.

**`RecipeControllerTest`** — every `createAction` branch: 201 with and without an integer `sort` (83), missing specification / empty lines (62), line missing `materialUuid`/`quantityPerUnit` (73), non-integer `sort` (76), and the service-throws → 400 path (92); the three create hooks (35/44/49) via reflection.

**`StockControllerTest`** — `detailAction` success + 404, `adjustAction` payload validation (44), success (57) and the `catch` (58/59), `policyAction` validation (68), success and `catch` (77).

**`QuantityCoverageTest`** — **14** (malformed decimal strings incl. 7-fraction-digit inputs, exponents, empty) and **26** (`positive=true` with zero/negative). The small-product `multiply` cases (`0.000000`, `0.000001 × n`) document the padding area.

**`InventoryEntityCoverageTest`** — `InventoryReservation::getId()` (91), `InventoryStock::setAllowNegativeStock(false)` with a negative available balance (91), `Material` setters rejecting blank code/name/unit (190).

**`PublishOutboxCommandTest`** — **32** (claim returns false → `continue`), plus the confirmed/rejected/released dispatch successes, the unsupported-topic deferral, and the transport-failure retry path.

## Bugs found

### Bug 1 — `Quantity::multiply()` contains unreachable padding code (LOW / dead code)

- **File/line:** `src/Inventory/Service/Quantity.php:74-75`
- **Description:** `if (strlen($result) <= 12) { $result = str_pad($result, 13, '0', STR_PAD_LEFT); }` can never be true. `Quantity::parts()` always returns the digits of a normalized value, which is at least `'0.000000'` → a 7-char digit string. The long-multiplication accumulator is built from `(string)$carry . $partial . str_repeat('0', $position)` where `$partial` is exactly `len(strrev(leftDigits)) = 7` chars; the running result therefore never has fewer than 14 characters by the final digit position. Verified empirically: every operand pair (including all-zero operands) yields a raw accumulator of length ≥ 14.
- **Impact:** None functionally — dead code. It is the only statement left uncovered in `Quantity` (98.72%), and it cannot be covered through the public API because there is no input that makes the guard true.
- **Reproduction:** instrumented copy of `multiply()` — `strlen($result)` is always ≥ 14 for `0.000000×0.000000`, `1×0.000001`, `2×3`, `0.5×0.25`, `10×0.000001`, etc.
- **Proposed fix:** delete lines 74-75 (the split logic on lines 77-81 already handles the minimum-width case correctly, as the `0.5 × 0.25 = 0.125000` test proves).

### Bug 2 — `InventoryService::reserve()` idempotency hash is timezone-sensitive (MEDIUM)

- **File/line:** `src/Inventory/Service/InventoryService.php:186-193` (specifically `'expiresAt' => $expiresAt?->format(DATE_ATOM)`)
- **Description:** The idempotency hash embeds `expiresAt` serialized with `DATE_ATOM`, which keeps the original timezone offset. The same instant expressed in a different offset (e.g. `2026-07-27T00:00:00+00:00` vs `2026-07-27T08:00:00+08:00` — identical timestamps) produces a **different hash** (verified: `032530aa…` vs `47da849e…`).
- **Impact:** A producer that retries the same reservation request (at-least-once delivery after a timeout) with `expiresAt` written in a different offset hits the `hash_equals` guard at line 205 and gets a spurious `InventoryReservationConflictException` ("Reservation ID was reused with a different request") instead of the original reservation, breaking idempotency for a semantically identical retry. The reservation/release message handlers in the same module round-trip `expiresAt` through ISO strings, so this is reachable from the messaging path.
- **Reproduction:** `reserve(reservationId, store, trade, order, items, new DateTimeImmutable('2026-07-27T00:00:00+00:00'))` then retry with `new DateTimeImmutable('2026-07-27T08:00:00+08:00')` — same instant, different hashes.
- **Proposed fix:** normalize `expiresAt` to UTC before hashing, e.g. `$expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM)`, so the hash is offset-independent.

### Bug 3 — `StockController` injects a service it never uses (LOW / dead dependency)

- **File/line:** `src/Inventory/Controller/Manage/StockController.php:24` (`protected readonly InventoryStockServiceInterface $service`)
- **Description:** The constructor property `$service` is never referenced; every action delegates to the `$inventory` (`InventoryServiceInterface`) dependency instead. The unused `InventoryStockService`/`InventoryStockServiceInterface` remains wired in DI.
- **Impact:** Dead dependency — no functional effect, but the DI wiring and the interface are misleading: a reader expects the `service` property to back the list/detail endpoints, when in fact those are served by `InventoryService::getStockView()`.
- **Proposed fix:** remove the unused constructor parameter (and its service wiring), leaving `StockController` with a single `InventoryServiceInterface` dependency.

### Bug 4 — `InventoryService::release()` partially mutates the reservation in memory before failing (LOW / robustness)

- **File/line:** `src/Inventory/Service/InventoryService.php:277-290`
- **Description:** `$reservation->release()` (line 277) flips the in-memory status to `RELEASED` and sets `releasedAt` before the loop can throw `Reservation material was not found.` (284) or `Reservation stock was not found.` (289). `wrapInTransaction` rolls the DB back, but the managed entity keeps its `RELEASED` in-memory state; Doctrine does not refresh/revert managed entities on rollback.
- **Impact:** In the failure path the entity is left reporting `released` while the DB still holds `confirmed` and the reserved quantities remain allocated. If the exception is caught in the same request/worker and the entity manager is subsequently flushed, the half-applied release would be committed without releasing the stock — a reserved-quantity leak. Only reachable when the reservation/material/stock data is already inconsistent (e.g. a material row removed while a reservation is confirmed), which is why the existing green tests never exercised it.
- **Reproduction:** persist a confirmed reservation whose line references a material that does not exist, then call `release()` — it throws at line 284 *after* `$reservation->release()` already returned `true`; `$reservation->getStatus()` reports `released` although nothing was persisted.
- **Proposed fix:** pre-validate all lines (material + stock lookups) before calling `$reservation->release()`, or wrap the failure in a `$this->entityManager->refresh($reservation)` / re-fetch so the entity cannot retain the half-applied state.

## Skipped tests

None. Every new test passes against the current `src/` (43 tests, 142 assertions, green). No correct-behavior test had to be skipped due to a `src/` bug — the InventoryService/controller code behaves as documented for all covered paths. The bugs above are found by inspection/analysis and, for Bug 1 and Bug 2, verified empirically, but none of them cause a failure of a correct-behavior test that needed to be recorded as skipped.

## Notes

- Transient SQLite `database is locked` errors were observed when integration classes that drop/recreate `var/test.db` run back-to-back; per the project convention, waiting 10–15 s and re-running resolved them (all runs ended green).
- The full `tests/Inventory` directory shows pre-existing flakiness in `InventoryReservationRequestedHandlerIntegrationTest` when all Inventory tests run together (unrelated to these changes); the new files are green both individually and together.
