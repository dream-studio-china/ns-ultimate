# Development Manual

Hands-on documentation for developers working on the **crud-skeleton** project —
a Symfony 8.1 API backend with a modular monolith architecture, JWT authentication,
a dynamic query engine, and pluggable business modules (Trade, Payment, Wallet,
Inventory, Settlement, and more).

The manual is task-oriented: it tells you how to run the app, how the architecture
is organised, and where new code goes. Deeper contracts and per-module design notes
live next to it under `docs/design`, `docs/runbooks`, and `docs/testing`.

## Table of Contents

### Foundation

| Document | Purpose |
|----------|---------|
| [Getting Started](getting-started.md) | Prerequisites, Docker and native setup, JWT configuration, first-run verification, troubleshooting |
| [Architecture](architecture.md) | Modular monolith model, layer rules, cross-module communication contract, key design patterns |
| [Project Structure](project-structure.md) | Directory tree, module layout, naming conventions, where new code goes |

### Core Framework

| Document | Purpose |
|----------|---------|
| [Core Framework — Deep Dive](core-framework.md) | RestController, BaseService, View mixins, Expression engine, listeners, utils |
| [Core — Usage Recipes](core-usage.md) | Practical, codebase-derived patterns for building on the Core framework |
| [Query System](query-system.md) | Complete reference for `@filter`, `@sort`, `@order`, `@dql`, `@select`, and related parameters |
| [Authorization Setup](authorization.md) | How to seed and configure Authorization: permissions, roles, assignments, field grants, Content pilot |

### Development Process

| Document | Purpose |
|----------|---------|
| [Development Workflow](development-workflow.md) | Branching model, coding standards, PHPStan/Rector, commit conventions, PR checklist, CI pipeline |
| [Testing](testing.md) | Test structure, running tests, parallel ParaTest, coverage threshold |
| [API Contracts](api-contracts.md) | JSON envelope, authentication, URL conventions, pagination, error handling, NelmioApiDoc, webhooks |
| [Development Contracts](development-contracts.md) | Layer rules, cross-module communication, service boundaries, naming, code style, security |

### Integration & Data

| Document | Purpose |
|----------|---------|
| [Integration Events](integration-events.md) | Outbox/Inbox pattern, envelope structure, publishing/consuming, correlation tracing, scheduler commands |
| [Database & Migrations](database-and-migrations.md) | Doctrine conventions, entity patterns, migration workflow, UUID identity, money handling |

### Operations

| Document | Purpose |
|----------|---------|
| [Deployment](deployment.md) | Docker Compose services, environment variables, JWT keys, production migrations |
| [Media Storage & Qiniu](storage.md) | Storage drivers, default driver selection, enabling the Qiniu Kodo driver |
| [Extracting a Service](extracting-a-service.md) | How to carve a module out of the modular monolith into an independent app |
| [Internationalization](i18n.md) | Runtime API translations and the bilingual docs pipeline |

## Quick Links

- **Interactive API docs:** `http://localhost:8080/api/doc` (Swagger UI, while running) or `http://localhost:8000/api/doc` for the native PHP server
- **OpenAPI JSON:** `http://localhost:8080/api/doc.json`
- **Live health check:** `http://localhost:8080/health/ready` (via `GET /health/ready`)
- **Admin panel frontend:** [crud-admin](https://github.com/immane/crud-admin) — configuration-driven, consumes this API

## Getting Around

- Start here: [Getting Started](getting-started.md)
- Understand the shape of the codebase: [Project Structure](project-structure.md)
- The one rule to remember: services talk to services through **interfaces**, never repositories or entities across module boundaries — see [Architecture](architecture.md)
