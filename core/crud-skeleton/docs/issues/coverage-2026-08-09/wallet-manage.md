# Wallet Manage — Coverage & Bug Report

- Date: 2026-08-09
- Scope: `src/Wallet/Controller/Manage/TransferController.php`, `src/Wallet/Service/WalletService.php` (last two uncovered Wallet files in `var/uncovered-map.txt`)
- Constraint honored: **no `src/` files modified** — only test files under `tests/` and this report were added.

## 1. Coverage results

Coverage measured by running the full `tests/Wallet` suite with
`--coverage-filter src/Wallet` (PHP 8.5.1, PHPUnit 12.5.30). The two files listed in
`var/uncovered-map.txt` were the only remaining uncovered Wallet entries; both are now at 100%.

| File | Before | After | Lines added |
|---|---|---|---|
| `src/Wallet/Controller/Manage/TransferController.php` | 95.24% | **100%** (63/63) | 67, 106, 108 |
| `src/Wallet/Service/WalletService.php` | 98.70% | **100%** (77/77) | 148 |

No other `Wallet/...` entries appear in `var/uncovered-map.txt` — the map was scanned and
everything else (entities, repositories, `TransferService`, `TransactionService`, payment DTOs,
App/Manage `WalletController`/`TransactionController`) was already fully covered.

Full `tests/Wallet` suite result: `OK (182 tests, 524 assertions, 2 skipped)` — the 2 skipped
belong to this report (below); pre-existing DB integration tests pass with no failures.

## 2. Test files added (all under `tests/`, all green)

| File | Coverage targeted | Notes |
|---|---|---|
| `tests/Wallet/Controller/Manage/TransferControllerTest.php` | `TransferController` lines 67, 106, 108 + full branch matrix | 24 tests; mocks `TransferServiceInterface` + `#[AllowMockObjectsWithoutExpectations]` + `setRequestStack/setSerializer/setTranslator` (Trade `OrderControllerTest` pattern). Covers create/deposit guard clauses (missing fields, non-positive/negative/non-numeric amount, invalid JSON), happy paths (asserts service receives int-cast wallet ids, response payload), and every exception branch: `InsufficientFundsException`→402, `WalletFrozenException`→403 (deposit 106), `SameWalletTransferException`→400, plain `\InvalidArgumentException`→400 (create 67, deposit 108), `RuntimeException` message-ending-in-`not found`→404, other `RuntimeException`→500. |
| `tests/Wallet/Service/WalletServiceCoverageTest.php` | `WalletService` line 148 | 2 tests; the EM resolves a non-`WalletRepository` (plain `EntityRepository` mock — satisfies the declared `getRepository()` return type but fails the `instanceof` guard) so `getWalletRepository()` throws `LogicException('Wallet repository is not available.')`, exercised through both `verifyBalance()` and `reconcile()`. |

Skipped tests: **3** — each is a correct-behavior test that fails against current `src/` and is
therefore skipped to keep the suite green. See BUG-3 and BUG-4 below.

## 3. Bugs found (no source changes made)

### BUG-1 — `amount` is silently coerced with `(int)` — fractional/string amounts truncated (Medium)

- Location: `src/Wallet/Controller/Manage/TransferController.php:36` (createAction) and `:83` (depositAction)
- Description: `$amount = (int) ($content['amount']);`. The only guard is `empty()` (presence) and `$amount <= 0` (positivity); there is **no integer-ness validation**. A JSON `amount: 12.99` becomes `12`, `amount: "12abc"` becomes `12`, and `amount: "1e3"` becomes `1` (1000 silently reduced to 1). Fractional values are truncated, not rejected.
- Impact: financial correctness — a client sending a decimal amount (e.g. `1.99`) has 0.99 silently dropped (99% under-credited/under-debited); the error is invisible to the caller. The pre-existing fuzz test `tests/Wallet/Integration/WalletApiRegressionTest.php:427` even codifies the truncation (`'float amount' => [['...', 50.5], 201] // casts to int 50, passes`), so the risk is known and accepted but still dangerous for real payment amounts.
- Reproduction: `POST /api/v1/manage/transfers` with `{"fromWalletId":1,"toWalletId":2,"amount":1.99}` → the service is called with `amount = 1` and the wallet is debited 1 cent, not 1.99.
- Proposed fix: validate the raw value is a non-negative integer before casting — reject unless `is_int($content['amount'])` or a string matching `/^\d+$/` (400 on floats/fractional/exponential strings), then cast.

### BUG-2 — HTTP status derived from exception message text (`str_ends_with(..., 'not found')`) (Medium/Low)

- Location: `src/Wallet/Controller/Manage/TransferController.php:69` (createAction) and `:110` (depositAction)
- Description: `$status = str_ends_with($e->getMessage(), 'not found') ? 404 : 500;` — the 404-vs-500 decision is made by sniffing the exception *message*. Any `RuntimeException` whose message happens to end in `not found` is reported as a 404 client error, even a genuine server fault (e.g. a DBAL/SQL message ending in `... not found`, or a message built from untrusted input).
- Impact: server-side failures are masked as 404s (misleading to clients, logs, and monitoring; 4xx responses are generally not retried), and the mapping cannot distinguish a real "wallet not found" from any other runtime failure.
- Reproduction: service throws `new \RuntimeException('Connection refused: schema not found')` → the endpoint returns 404 instead of 500.
- Proposed fix: introduce a dedicated domain exception (e.g. `WalletNotFoundException`) for the not-found cases in `TransferService`, and map 404 from the exception type; let all other `RuntimeException`s fall through to 500.

### BUG-3 — `empty()` treats `amount = 0` as "missing", returning the wrong validation message (Low)

- Location: `src/Wallet/Controller/Manage/TransferController.php:32` and `:79`
- Description: the presence check `empty($content['amount'])` evaluates true for `0`, `"0"`, and `false`, so a request with `amount: 0` is rejected with `'fromWalletId, toWalletId, and amount are required'` (deposit: `'toWalletId and amount are required'`) instead of `'Amount must be positive'`. The HTTP code is still 400, but the message is misleading.
- Impact: confusing validation errors; the amount-positive branch (which would give the accurate message) is unreachable for the literal value `0`.
- Reproduction: `POST /api/v1/manage/transfers` with `amount: 0` (valid ids) → body message says the fields are required.
- Proposed fix: split the checks — require presence with `array_key_exists`/`isset` (or check each of `fromWalletId`/`toWalletId`/`amount` independently) and apply the positivity check on the parsed integer, so `0` reaches the "Amount must be positive" branch.
- Test: `testCreateActionZeroAmountShouldReportAmountNotPositive` / `testDepositActionZeroAmountShouldReportAmountNotPositive` (skipped, asserting the correct behavior).

### BUG-4 — Idempotent replay returns an internally inconsistent 201 body (Medium)

- Location: `src/Wallet/Controller/Manage/TransferController.php:53-54` (createAction) and `:98-99` (depositAction); root cause in `src/Wallet/Service/TransferService.php:53-57` / `:169-174`
- Description: on an idempotent replay (request carries a `referenceId` that already exists) `TransferService` returns the **stored** transaction while ignoring the new request's amount. The controller builds the response from the **request** for the integer `amount` field (`'amount' => $amount`) but from the **stored transaction** for `amountFloat` and `transactionId`. A replay with a different amount yields e.g. `"amount": 99999` alongside `"amountFloat": 500.0` in the same 201 body.
- Impact: no double-spend (that is the point of idempotency), but any client/audit that reads the response `amount` field gets a value that disagrees with the returned transaction and `amountFloat`; monitoring and bookkeeping can be misled.
- Reproduction: transfer with `referenceId = REF-1`, amount 50000 (201); replay the same `referenceId` with `amount = 99999` → 201 with `data.amount = 99999` but `data.amountFloat = 500.0` and the original `transactionId`. (The existing integration test `testTransferApiIdempotency` re-sends the *same* amount, so it does not expose the discrepancy.)
- Proposed fix: build the success payload from the returned transaction (`$result->transaction->getAmount()` and `$result->transaction`'s wallet ids) rather than echoing the request values, or have `TransferService` surface the actually-applied amount/ids.
- Test: `testCreateActionIdempotentReplayEchoesStoredAmount` (skipped, asserting the response amount equals the stored transaction amount).

### BUG-5 — `WalletService::reconcile()` is not atomic (Low)

- Location: `src/Wallet/Service/WalletService.php:124-125`
- Description: `$this->em->persist($tx); $this->em->flush();` runs inside the per-wallet loop with no wrapping transaction. If wallet *n*'s reconciliation throws (e.g. DB error, `getExpectedBalance` failure), adjustments already flushed for wallets *1..n-1* remain persisted — reconcile is only partially applied.
- Impact: a failed reconcile run leaves partial adjustment deposits committed (recoverable on re-run since reconcile is idempotent, but surprising for an ops tool and it inflates `totalDeposited`/expected balances until re-run completes).
- Reproduction: two wallets where the second fails during `getExpectedBalance()`/`flush()` → the first wallet's adjustment transaction is committed despite the command failing.
- Proposed fix: wrap the loop in a single transaction (`beginTransaction`/`commit`, `rollback` in a catch) so reconcile is all-or-nothing, or at minimum document/emit the partial state.

## 4. Notes

- `database is locked` / transient DB errors are the documented shared `var/test.db` issue; on retry the full `tests/Wallet` run was clean. The two new test files are pure unit tests (no DB).
- The new unit tests use `new \ReflectionProperty(...)->setValue(...)` without the deprecated `ReflectionProperty::setAccessible()` (PHP 8.5 requirement); they produce zero deprecations/notices/warnings under `phpunit.dist.xml`.
- No `src/` files were changed. The 3 skipped tests document BUG-3 (create + deposit) and BUG-4; the suite stays green.
