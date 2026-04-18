# Daems Platform

REST API backend for the Daem Society community platform. Built with PHP 8.1+ and a hand-rolled Clean Architecture framework — no Laravel, no Symfony.

## Tech stack

| Layer           | Technology                                                  |
| --------------- | ----------------------------------------------------------- |
| Language        | PHP 8.1+ (`declare(strict_types=1)` throughout)             |
| Database        | MySQL 8.x                                                   |
| Architecture    | Clean Architecture (Domain / Application / Infrastructure)  |
| HTTP            | Custom micro-framework (`src/Infrastructure/Framework/`)    |
| Testing         | PHPUnit 10                                                  |
| Static analysis | PHPStan 1.x                                                 |

## Prerequisites

- PHP 8.1 or newer (with `pdo_mysql`, `mbstring`, `json` extensions)
- MySQL 8.0 or newer
- Composer 2.x
- Laragon (or any web server able to point a virtual host at `public/`)

## Quick start

```bash
# 1. Clone
git clone <repo-url> daems-platform
cd daems-platform

# 2. Environment
cp .env.example .env
# Edit .env — set DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD

# 3. Dependencies
composer install

# 4. Database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS daems_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run all migrations in order
for f in database/migrations/*.sql; do
  mysql -u root -p daems_db < "$f"
done

# 5. Virtual host (Laragon)
# Add site: daems-platform.local → /path/to/daems-platform/public

# 6. Verify
curl http://daems-platform.local/api/v1/status
# {"data":{"status":"ok","version":"1.0.0"}}
```

## Composer scripts

| Command            | Description                              |
| ------------------ | ---------------------------------------- |
| `composer test`    | Run PHPUnit test suite (`tests/`)        |
| `composer analyse` | Run PHPStan static analysis              |

## Architecture overview

The project follows Clean Architecture with three layers. The `Domain` layer holds entities, value objects, and repository interfaces with zero framework dependencies. The `Application` layer contains single-responsibility use-case classes that orchestrate domain logic. The `Infrastructure` layer provides all concrete implementations — HTTP kernel, router, DI container, PDO-based SQL repositories, and API controllers. Dependency direction is strictly inward: infrastructure depends on application, application depends on domain, domain depends on nothing outside itself.

## Documentation

| Document                                     | Contents                                                         |
| -------------------------------------------- | ---------------------------------------------------------------- |
| [docs/architecture.md](docs/architecture.md) | Layer breakdown, DI container, request lifecycle, namespace map  |
| [docs/api.md](docs/api.md)                   | Complete REST API reference with request/response examples       |
| [docs/database.md](docs/database.md)         | Schema reference, migration index, ASCII ERD                     |
| [docs/setup.md](docs/setup.md)               | Full developer setup guide                                       |
| [docs/decisions.md](docs/decisions.md)       | Architecture Decision Records (ADR-001 – ADR-005)                |

## License

MIT
