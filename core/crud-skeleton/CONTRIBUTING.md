# Contributing to CRUD Skeleton

## Getting Started

1. Fork the repository and clone it locally.
2. Install dependencies:

   ```bash
   composer install
   ```

   Use PHP 8.4 or newer. `composer install` runs Symfony's cache-clear script,
   so configure `DATABASE_URL` before installing dependencies.

3. Set up local environment:

   ```bash
   cp .env.example .env.local
   # Edit .env.local with your local database credentials
   ```

4. Generate JWT keys and initialize the database:

   ```bash
   mkdir -p var/jwt
   openssl genpkey -algorithm RSA -out var/jwt/jwt_private.pem -pkeyopt rsa_keygen_bits:2048
   openssl rsa -pubout -in var/jwt/jwt_private.pem -out var/jwt/jwt_public.pem
   php bin/console doctrine:database:create --env=dev --if-not-exists
   php bin/console doctrine:schema:update --env=dev --force
   ```

5. Start the development server:

   ```bash
   symfony server:start
   # or
   php -S 127.0.0.1:8000 -t public
   ```

### Docker (Alternative)

```bash
docker compose up -d --build
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console app:identity:user:create admin@example.com admin 'P@ssw0rd' --admin
```

## Development Workflow

### Branch Naming

- `feat/xxx` — new features
- `fix/xxx` — bug fixes
- `docs/xxx` — documentation changes
- `test/xxx` — test coverage improvements
- `chore/xxx` — tooling, CI, dependencies
- `refactor/xxx` — code restructuring

### Commit Messages

Follow [Conventional Commits](https://www.conventionalcommits.org/):

```
feat(common): add batch import for categories
fix(trade): correct order total calculation
docs(i18n): add Japanese translation README
test(wallet): improve transfer service coverage
chore: upgrade doctrine/orm to 3.7
```

Supported scopes: `common`, `trade`, `wallet`, `payment`, `wechat`, `identity`, `storage`, `core`, `i18n`, `ci`, `docs`

### Before Submitting a PR

```bash
# Run PHPStan at Level 8
composer phpstan

# Verify Doctrine Collection/Repository PHPDoc rules without modifying files
composer rector:types:check

# Run all tests (CI enforces 90% minimum coverage)
XDEBUG_MODE=coverage vendor/bin/phpunit --coverage-text

# Run tests for a specific module (layered suites)
vendor/bin/phpunit tests/UnitTest/Trade/ tests/Integration/Trade/

# Apply the focused Rector type rules when needed
composer rector:types
```

`composer rector` runs the broader opt-in Rector configuration. Do not use it
as a formatting step without reviewing its proposed changes.

### PR Checklist

- [ ] Branch is up-to-date with `main`
- [ ] Commits follow conventional commit format
- [ ] All tests pass (`vendor/bin/phpunit`)
- [ ] Coverage does not drop below 90% (CI enforced)
- [ ] PHPStan passes (`composer phpstan`)
- [ ] Rector type-rule check passes (`composer rector:types:check`)
- [ ] New features include tests
- [ ] Behavior changes are reflected in `docs/ai/context.md` where appropriate
- [ ] API changes are documented with `#[OA\*]` attributes

## Project Structure

```text
src/
├── Core/         # Framework core (RestController, BaseService, View mixins, Expression parser)
├── Common/       # CMS module (Category, Tag, Content, Comment, Page, Media, Setting)
├── Trade/        # E-commerce module (Product, Specification, Order, OrderItem)
├── Wallet/       # Wallet module (Wallet, Transaction, TransferService)
├── Payment/      # Payment module (Invoice, Gateways, Adjustment providers)
├── Wechat/       # WeChat module (Mini Program, Official Account, Pay V3)
├── Storage/      # File storage module (LocalStorage, QiniuStorage)
└── Identity/     # Authentication module (User, JWT, OTP)
config/           # Symfony configuration
├── packages/     # Per-component config (security, workflow, translation, etc.)
migrations/       # Doctrine migrations
translations/     # i18n translation files (en, zh, zh_Hant, ja)
tests/            # PHPUnit tests, 90%+ coverage
docs/
├── ai/           # AI context snapshot
├── design/       # Design contracts and bundle docs
└── openapi/      # API flow documentation
```

## Code Style

- PHP 8.4+ with strict types (`declare(strict_types=1)`)
- Doctrine ORM for database layer
- Symfony service container with autowiring
- Controllers extend `RestController` and use trait mixins
- Business logic in service classes extending `BaseService`
- All documentation and comments in English
- Translation keys are English message strings
- Use `#[Attribute]` annotations over docblock annotations

## Module Design

When adding a new module, follow the [Module Design Contract](docs/design/module-design.md):

1. Create entity in `src/{Module}/Entity`
2. Create repository extending `ServiceEntityRepository`
3. Create service extending `BaseService` + implementing `{Name}ServiceInterface`
4. Create App (public read) and Manage (admin CRUD) controllers using mixin traits
5. Register routes in `config/routes.yaml`
6. Create Doctrine migration
7. Add OpenAPI `#[OA\*]` attributes for API documentation

## Internationalization (i18n)

All user-facing messages must pass through the Symfony translator:

- Exception messages in service classes: use English strings as translation keys (automatically translated by `ExceptionInterceptor`)
- Controller errors: use `RestController::warning()` or inject `TranslatorInterface`
- Entity field names: automatically translated by the `EntityController` system introspection endpoint

To add translations for a new string:

1. Add the English key to `translations/messages.en.yaml`
2. Add translations to `translations/messages.zh.yaml`, `messages.zh_Hant.yaml`, and `messages.ja.yaml`
3. No code changes needed — the message text IS the translation key

## Documentation

- Design contracts live in `docs/design/`
- AI context snapshot is at `docs/ai/context.md` — update it when adding new modules, patterns, or significant structural changes
- API documentation is generated via `#[OA\*]` attributes on controllers and enriched by `OpenApiEnricherListener`

## Reporting Issues

Use the [bug report form](https://github.com/immane/crud-skeleton/issues/new?template=bug_report.yml) for bugs. For security vulnerabilities, see [SECURITY.md](SECURITY.md).

## License

By contributing, you agree that your contributions will be licensed under the Apache-2.0 License.
