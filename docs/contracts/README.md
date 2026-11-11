# Contracts

Document stable, cross-layer interfaces here: ownership, names, input/output, registration mechanism, compatibility expectations, and tests. Keep business-specific rules in `business/` and core implementation details in the relevant core repository.

The initial contracts are described in [`admin-composition.md`](admin-composition.md), [`backend-module-registration.md`](backend-module-registration.md), and [`backend-entity-extension.md`](backend-entity-extension.md). Their local composition tests and runtime checks are recorded in each contract. Keep unverified behavior and known follow-up findings explicit; distinguish proposals from validated behavior.

The generic assistant operation lifecycle is described in [`admin-agent.md`](admin-agent.md), including adapter ownership, migration, confirmation boundary, concurrency limits and proposal retention requirements.

The promotion authoring and read-only stacking boundary is described in [`promotion-authoring.md`](promotion-authoring.md), including parameter contracts, units, sample cases, dependency invalidation and the limits of single-rule and multi-campaign simulation.

The employee catalog and order-fulfillment boundary is described in [`store-console-operations.md`](store-console-operations.md), including scoped grants, command receipts, availability policy and the explicitly unimplemented transaction phase. Counter sessions, employee ordering, and eligible replacement/cancellation are described in [`store-console-counter-operations.md`](store-console-counter-operations.md).

The anonymous, read-only order payment-page projection is described in [`public-order-payment.md`](public-order-payment.md). It exposes restricted order/Invoice information and Huifu channel configuration, not public payment initiation.

The separate authenticated customer initiation path and its signed notification boundary are described in [`huifu-customer-payments.md`](huifu-customer-payments.md).
