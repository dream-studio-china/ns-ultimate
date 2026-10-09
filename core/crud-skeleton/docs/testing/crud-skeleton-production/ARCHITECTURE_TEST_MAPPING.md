# Architecture Test Mapping

| Module / boundary | Responsibility | Primary test layers | Required regression focus |
|---|---|---|---|
| `Core` | reusable CRUD, expression/query engine, response and exception infrastructure | unit, HTTP integration | malformed expression, filter composition, pagination, serialization, locale, translated errors |
| `Identity` | authentication, authorization, user/profile lifecycle, OTP | unit, HTTP integration | token rotation/reuse, role/owner boundaries, missing/invalid credentials, profile lifecycle |
| `Common` + `Storage` | CMS data and upload drivers | unit, Doctrine/HTTP integration | public/app/manage visibility, parent scope, file validation, physical cleanup on delete/failure |
| `Trade` | catalog, price pipeline, order workflow, Trade outbox | unit, integration, HTTP | snapshot, quote/order equivalence, workflow guards, cancellation/payment/refund effects, outbox retries |
| `Promotion` | DSL parsing/evaluation and pricing rules | unit, Doctrine integration | invalid DSL, time/store/member/SKU scope, stacking and best-price selection |
| `Wallet` | balances, transfers, deductions, reconciliation | unit, transaction integration, HTTP | integer cents, insufficient funds, duplicate references, optimistic locking, audit repair |
| `Payment` | invoice lifecycle, gateway/adjustment contracts, webhook | unit, integration, HTTP | explicit amounts, invalid gateway/result, callback replay, refund limits, Trade propagation |
| `Wechat` | login identity extension and external payment adapter | unit, adapter contract, HTTP | malformed provider response, user binding, missing payer identity, signature/provider failures |
| `Store` | store context, membership, order acceptance, inbox/outbox | unit, integration, HTTP | context spoofing, staff boundaries, duplicate or delayed Trade events, cancellation ordering |
| `Inventory` | materials, recipe, reserve/release, ledger | unit, integration, handler tests | demand aggregation, stock policy, duplicate/reversed events, release/expiry; production-like concurrency before enablement |

Cross-module tests belong in `tests/Integration/` when the behaviour is owned by
the interaction rather than a single module. Module tests may use fakes for
neighbouring modules, but the critical path must retain at least one integration
test with the real boundary.
