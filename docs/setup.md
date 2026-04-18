# Developer Setup Guide

## Requirements

| Requirement     | Minimum version | Notes                                    |
| --------------- | --------------- | ---------------------------------------- |
| PHP             | 8.1             | Extensions: `pdo_mysql`, `mbstring`, `json`, `openssl` |
| MySQL           | 8.0             | MariaDB 10.6+ also works                 |
| Composer        | 2.x             |                                          |
| Laragon         | 6.x             | Or any WAMP/LAMP with virtual host support |

---

## 1. Laragon setup

1. Install [Laragon](https://laragon.org/) and start the Apache and MySQL services.
2. Clone the repository into Laragon's `www` directory:

   ```bash
   cd C:/laragon/www
   git clone <repo-url> daems-platform
   ```

3. In Laragon, right-click the tray icon → **Quick App** → **Add a site** — or manually add a virtual host:

   - **Document root:** `C:/laragon/www/daems-platform/public`
   - **Server name:** `daems-platform.local`

4. Laragon automatically adds the hostname to `C:/Windows/System32/drivers/etc/hosts`. Restart Apache.

---

## 2. PHP dependencies

```bash
cd C:/laragon/www/daems-platform
composer install
```

This installs `phpunit/phpunit` and `phpstan/phpstan` as dev dependencies. There are no production Composer dependencies.

---

## 3. Environment configuration

Create a `.env` file in the project root. There is no committed `.env.example` — create it from scratch:

```dotenv
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=daems_db
DB_USERNAME=root
DB_PASSWORD=
```

The `.env` loader in `bootstrap/app.php` reads this file at boot time. It skips keys already present in `$_ENV` so environment variables set at the server level take precedence.

---

## 4. Database setup

```bash
# Create the database
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS daems_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Run migrations in order (Git Bash / WSL)
for f in database/migrations/*.sql; do
  mysql -u root -p daems_db < "$f"
done
```

On Windows PowerShell:

```powershell
Get-ChildItem database\migrations\*.sql | Sort-Object Name | ForEach-Object {
    mysql -u root -p daems_db < $_.FullName
}
```

Migrations are plain SQL files — there is no migration runner CLI. Run them sequentially by filename. Migrations are idempotent where possible (`CREATE TABLE IF NOT EXISTS`, `ALTER TABLE ADD COLUMN`).

---

## 5. Verify the setup

```bash
curl http://daems-platform.local/api/v1/status
```

Expected response:

```json
{"data":{"status":"ok","version":"1.0.0"}}
```

---

## 6. Running tests

```bash
composer test
# or directly:
vendor/bin/phpunit
```

Test configuration is in `phpunit.xml`. Tests live in `tests/`.

---

## 7. Running static analysis

```bash
composer analyse
# or directly:
vendor/bin/phpstan analyse
```

PHPStan configuration is in `phpstan.neon`. The default level is set in that file.

---

## 8. Project structure reference

```
daems-platform/
├── bootstrap/
│   └── app.php           Entry point for DI container + Kernel construction
├── config/               (reserved for future config files)
├── database/
│   └── migrations/       SQL migration files (run in order)
├── public/
│   └── index.php         Web entry point — all requests routed here
├── routes/
│   └── api.php           Route definitions
├── src/
│   ├── Application/      Use cases
│   ├── Domain/           Entities, value objects, repository interfaces
│   └── Infrastructure/   Framework, controllers, SQL repositories
├── tests/                PHPUnit tests
├── composer.json
├── phpstan.neon
└── phpunit.xml
```

---

## 9. Common issues

### "No binding registered for: ..." (500 error)

A class was requested from the container but was not registered in `bootstrap/app.php`. Add the appropriate `bind()` or `singleton()` call.

### Blank page / no JSON output

Check that Apache is pointing at `public/` as the document root and that `mod_rewrite` is enabled. The `public/` directory needs a `.htaccess` that routes all requests through `index.php`:

```apacheconf
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
```

### PDO connection refused

Verify that MySQL is running and that `DB_HOST`, `DB_PORT`, `DB_USERNAME`, and `DB_PASSWORD` in `.env` are correct. The PDO `Connection` class throws a `PDOException` on failure; Kernel catches it and returns a 500 JSON response.

### Migrations fail with "Table already exists"

The migrations use `CREATE TABLE IF NOT EXISTS`, so re-running them is safe. `ALTER TABLE` migrations do not have a guard — if you re-run migration 008, 011, or 013, MySQL will report a duplicate column error. This is expected. Either skip those files or check the schema state first with `DESCRIBE users;`.

### PHPStan reports type errors

Run `composer dump-autoload` first. If errors persist, check that the PHPStan `phpstan.neon` `paths:` setting includes `src/`.
