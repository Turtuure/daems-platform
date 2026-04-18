# Auth Layer & SAST Remediation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close SAST findings F-001..F-010 by adding a coherent authentication + authorization layer to the Daems Platform, preserving Clean Architecture boundaries.

**Architecture:** Opaque bearer tokens hashed in DB (`auth_tokens`). Middleware pipeline in `Router` (`AuthMiddleware`, `RateLimitLoginMiddleware`). Authorization enforced in use cases via `ActingUser` on Input DTOs; violations thrown as `ForbiddenException` and translated to HTTP 403 by `Kernel`. Error bodies sanitised, attacker-controlled identity fields stripped from DTOs, 72-byte password cap.

**Tech Stack:** PHP 8.1+, MariaDB, PHPUnit 10, PHPStan, Infection (new dev dep).

**Source spec:** `docs/superpowers/specs/2026-04-19-auth-and-sast-remediation-design.md`

**Branch:** `fix/sast-auth-remediation`

---

## File Structure

### New files (domain layer — no framework deps)

- `src/Domain/Shared/Clock.php` — interface `now(): DateTimeImmutable`
- `src/Domain/Auth/ActingUser.php` — value object (UserId + role)
- `src/Domain/Auth/AuthToken.php` — entity
- `src/Domain/Auth/AuthTokenId.php` — UUIDv7 value object
- `src/Domain/Auth/AuthTokenRepositoryInterface.php`
- `src/Domain/Auth/AuthLoginAttemptRepositoryInterface.php`
- `src/Domain/Auth/AuthorizationException.php` — base class
- `src/Domain/Auth/ForbiddenException.php`
- `src/Domain/Auth/UnauthorizedException.php`
- `src/Domain/Auth/TooManyRequestsException.php` — carries `retryAfter` int
- `src/Domain/Shared/ValidationException.php`
- `src/Domain/Shared/NotFoundException.php`

### New files (application layer)

- `src/Application/Auth/CreateAuthToken/{CreateAuthToken,CreateAuthTokenInput,CreateAuthTokenOutput}.php`
- `src/Application/Auth/AuthenticateToken/{AuthenticateToken,AuthenticateTokenInput,AuthenticateTokenOutput}.php`
- `src/Application/Auth/RevokeAuthToken/{RevokeAuthToken,RevokeAuthTokenInput,RevokeAuthTokenOutput}.php`
- `src/Application/Auth/LogoutUser/{LogoutUser,LogoutUserInput,LogoutUserOutput}.php`

### New files (infrastructure layer)

- `src/Infrastructure/Framework/Http/MiddlewareInterface.php`
- `src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php`
- `src/Infrastructure/Framework/Http/Middleware/RateLimitLoginMiddleware.php`
- `src/Infrastructure/Framework/Logging/LoggerInterface.php`
- `src/Infrastructure/Framework/Logging/ErrorLogLogger.php`
- `src/Infrastructure/Framework/Clock/SystemClock.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlAuthTokenRepository.php`
- `src/Infrastructure/Adapter/Persistence/Sql/SqlAuthLoginAttemptRepository.php`

### Modified files

- `src/Infrastructure/Framework/Http/Router.php` — middleware list support
- `src/Infrastructure/Framework/Http/Kernel.php` — exception mapping + logger
- `src/Infrastructure/Framework/Http/Request.php` — `withActingUser`, `actingUser`, `clientIp`
- `src/Infrastructure/Framework/Http/Response.php` — `unauthorized`, `forbidden`, `tooManyRequests`
- `src/Infrastructure/Adapter/Api/Controller/AuthController.php` — login returns token, adds `logout`
- `src/Infrastructure/Adapter/Api/Controller/UserController.php` — pass acting user
- `src/Infrastructure/Adapter/Api/Controller/ProjectController.php` — pass acting user, drop body identity fields
- `src/Infrastructure/Adapter/Api/Controller/ForumController.php` — pass acting user, drop body identity fields
- `src/Infrastructure/Adapter/Api/Controller/EventController.php` — pass acting user
- `src/Infrastructure/Adapter/Api/Controller/ApplicationController.php` — pass acting user
- `src/Application/**` — each protected use case: add `ActingUser` to Input, enforce policy in `execute`
- `src/Application/Auth/LoginUser/LoginUser.php` — records login attempt, 72-byte cap
- `src/Application/Auth/RegisterUser/RegisterUser.php` — 72-byte cap
- `src/Application/User/ChangePassword/ChangePassword.php` — 72-byte cap
- `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php` — catch PDO 23000 as `ValidationException`
- `routes/api.php` — attach middleware to protected routes
- `bootstrap/app.php` — bind new services
- `database/migrations/014_create_auth_tokens.sql` (new)
- `database/migrations/015_create_auth_login_attempts.sql` (new)
- `database/migrations/016_add_owner_id_to_projects.sql` (new)
- `.env.example` — `APP_DEBUG`, `AUTH_*`, `DB_TEST_*`
- `composer.json` — `test:e2e`, `test:mutation` scripts; `infection/infection` dev dep
- `phpunit.xml` — Unit + Integration + E2E suites
- `infection.json.dist` (new)
- `tests/Unit/**` — one test file per new domain/application class
- `tests/Support/Fake/InMemoryAuthTokenRepository.php` (new)
- `tests/Support/Fake/InMemoryAuthLoginAttemptRepository.php` (new)
- `tests/Support/Fake/InMemoryUserRepository.php` (new if missing)
- `tests/Support/FrozenClock.php` (new)
- `tests/Support/E2E/E2EHarness.php` (new) — spins Kernel with a test container, test DB
- `tests/Integration/Http/*` (new)
- `tests/E2E/F001_..F010_*.php` (new)
- `docs/api.md` — auth flow documented
- `docs/decisions.md` — append ADR-006..ADR-013
- `docs/database.md` — new tables documented

---

## Preflight (run once, before Task 1)

- [ ] **P0.1: Verify baseline**

Run:
```bash
cd /root/daems/daems-platform/.worktrees/auth-remediation
./vendor/bin/phpunit
```
Expected: `OK (62 tests, 125 assertions)`. If not passing, stop and investigate before adding code.

- [ ] **P0.2: Confirm git identity**

Run:
```bash
git config user.email   # expect: hammersmashed89@gmail.com
git config user.name    # expect: Hammer
```

---

## Phase A — Foundations (infrastructure primitives, no DB, no HTTP I/O)

### Task 1: `Clock` interface + `SystemClock` + `FrozenClock`

**Files:**
- Create: `src/Domain/Shared/Clock.php`
- Create: `src/Infrastructure/Framework/Clock/SystemClock.php`
- Create: `tests/Support/FrozenClock.php`
- Create: `tests/Unit/Infrastructure/Framework/Clock/SystemClockTest.php`
- Create: `tests/Unit/Support/FrozenClockTest.php`

- [ ] **Step 1.1: Write failing test for `SystemClock`**

`tests/Unit/Infrastructure/Framework/Clock/SystemClockTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Clock;

use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use PHPUnit\Framework\TestCase;

final class SystemClockTest extends TestCase
{
    public function testImplementsClockInterface(): void
    {
        $this->assertInstanceOf(Clock::class, new SystemClock());
    }

    public function testNowReturnsCurrentTime(): void
    {
        $before = new \DateTimeImmutable('now');
        $now = (new SystemClock())->now();
        $after = new \DateTimeImmutable('now');

        $this->assertGreaterThanOrEqual($before->getTimestamp(), $now->getTimestamp());
        $this->assertLessThanOrEqual($after->getTimestamp(), $now->getTimestamp());
    }
}
```

- [ ] **Step 1.2: Run test — expect failure**

```bash
./vendor/bin/phpunit --filter SystemClockTest
```
Expected: `Class "Daems\Infrastructure\Framework\Clock\SystemClock" not found`.

- [ ] **Step 1.3: Write `Clock` interface**

`src/Domain/Shared/Clock.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

use DateTimeImmutable;

interface Clock
{
    public function now(): DateTimeImmutable;
}
```

- [ ] **Step 1.4: Write `SystemClock`**

`src/Infrastructure/Framework/Clock/SystemClock.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Clock;

use Daems\Domain\Shared\Clock;
use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
```

- [ ] **Step 1.5: Run — expect pass**

```bash
./vendor/bin/phpunit --filter SystemClockTest
```
Expected: `OK (2 tests, …)`.

- [ ] **Step 1.6: Write `FrozenClock` test support + its test**

`tests/Support/FrozenClock.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support;

use Daems\Domain\Shared\Clock;
use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $iso): self
    {
        return new self(new DateTimeImmutable($iso));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advance(string $modifier): void
    {
        $next = $this->now->modify($modifier);
        if ($next === false) {
            throw new \InvalidArgumentException("Invalid modifier: {$modifier}");
        }
        $this->now = $next;
    }
}
```

`tests/Unit/Support/FrozenClockTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Support;

use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class FrozenClockTest extends TestCase
{
    public function testReturnsFrozenTime(): void
    {
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $first = $clock->now();
        sleep(0); // no wall-clock advance
        $this->assertSame($first->getTimestamp(), $clock->now()->getTimestamp());
    }

    public function testAdvance(): void
    {
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $clock->advance('+1 hour');
        $this->assertSame('2026-04-19T13:00:00+00:00', $clock->now()->format('c'));
    }
}
```

- [ ] **Step 1.7: Register `tests/Support` in composer autoload-dev and regenerate**

Edit `composer.json` `autoload-dev` block to add `"files": []` or a `"psr-4"` for the `Daems\Tests\Support\` namespace:

```json
"autoload-dev": {
    "psr-4": {
        "Daems\\Tests\\": "tests/"
    }
}
```

(The existing entry already maps `Daems\Tests\\` to `tests/`, so `Daems\Tests\Support\` resolves under `tests/Support/`. No change needed — verify.)

Run:
```bash
composer dump-autoload
./vendor/bin/phpunit --filter FrozenClockTest
```
Expected: `OK (2 tests, …)`.

- [ ] **Step 1.8: Commit**

```bash
git add src/Domain/Shared/Clock.php \
        src/Infrastructure/Framework/Clock/SystemClock.php \
        tests/Support/FrozenClock.php \
        tests/Unit/Infrastructure/Framework/Clock/SystemClockTest.php \
        tests/Unit/Support/FrozenClockTest.php
git commit -m "Add Clock interface with SystemClock and FrozenClock test double"
```

---

### Task 2: `LoggerInterface` + `ErrorLogLogger`

**Files:**
- Create: `src/Infrastructure/Framework/Logging/LoggerInterface.php`
- Create: `src/Infrastructure/Framework/Logging/ErrorLogLogger.php`
- Create: `tests/Unit/Infrastructure/Framework/Logging/ErrorLogLoggerTest.php`

- [ ] **Step 2.1: Write failing test**

`tests/Unit/Infrastructure/Framework/Logging/ErrorLogLoggerTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Logging;

use Daems\Infrastructure\Framework\Logging\ErrorLogLogger;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use PHPUnit\Framework\TestCase;

final class ErrorLogLoggerTest extends TestCase
{
    private string $tmpfile;

    protected function setUp(): void
    {
        $this->tmpfile = tempnam(sys_get_temp_dir(), 'daems-log-');
        ini_set('error_log', $this->tmpfile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpfile)) {
            unlink($this->tmpfile);
        }
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(LoggerInterface::class, new ErrorLogLogger());
    }

    public function testErrorWritesToErrorLog(): void
    {
        (new ErrorLogLogger())->error('boom', ['k' => 'v']);
        $contents = (string) file_get_contents($this->tmpfile);
        $this->assertStringContainsString('boom', $contents);
        $this->assertStringContainsString('"k":"v"', $contents);
    }

    public function testErrorSerialisesException(): void
    {
        (new ErrorLogLogger())->error('failed', ['exception' => new \RuntimeException('inner')]);
        $contents = (string) file_get_contents($this->tmpfile);
        $this->assertStringContainsString('RuntimeException', $contents);
        $this->assertStringContainsString('inner', $contents);
    }
}
```

- [ ] **Step 2.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter ErrorLogLoggerTest
```
Expected: class not found.

- [ ] **Step 2.3: Implement interface + logger**

`src/Infrastructure/Framework/Logging/LoggerInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Logging;

interface LoggerInterface
{
    public function error(string $message, array $context = []): void;
}
```

`src/Infrastructure/Framework/Logging/ErrorLogLogger.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Logging;

use Throwable;

final class ErrorLogLogger implements LoggerInterface
{
    public function error(string $message, array $context = []): void
    {
        $encoded = [];
        foreach ($context as $k => $v) {
            if ($v instanceof Throwable) {
                $encoded[$k] = [
                    'class'   => $v::class,
                    'message' => $v->getMessage(),
                    'file'    => $v->getFile(),
                    'line'    => $v->getLine(),
                ];
            } else {
                $encoded[$k] = $v;
            }
        }
        $payload = json_encode($encoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log('[daems] ' . $message . ' ' . ($payload === false ? '{}' : $payload));
    }
}
```

- [ ] **Step 2.4: Run — expect pass**

```bash
./vendor/bin/phpunit --filter ErrorLogLoggerTest
```
Expected: `OK (3 tests, …)`.

- [ ] **Step 2.5: Commit**

```bash
git add src/Infrastructure/Framework/Logging/ tests/Unit/Infrastructure/Framework/Logging/
git commit -m "Add LoggerInterface with error_log-backed adapter"
```

---

### Task 3: Exception family

**Files:**
- Create: `src/Domain/Auth/AuthorizationException.php`
- Create: `src/Domain/Auth/UnauthorizedException.php`
- Create: `src/Domain/Auth/ForbiddenException.php`
- Create: `src/Domain/Auth/TooManyRequestsException.php`
- Create: `src/Domain/Shared/ValidationException.php`
- Create: `src/Domain/Shared/NotFoundException.php`
- Create: `tests/Unit/Domain/Auth/ExceptionTest.php`

- [ ] **Step 3.1: Write failing test**

`tests/Unit/Domain/Auth/ExceptionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\AuthorizationException;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Auth\UnauthorizedException;
use PHPUnit\Framework\TestCase;

final class ExceptionTest extends TestCase
{
    public function testUnauthorizedIsAuthorizationException(): void
    {
        $this->assertInstanceOf(AuthorizationException::class, new UnauthorizedException());
    }

    public function testForbiddenIsAuthorizationException(): void
    {
        $this->assertInstanceOf(AuthorizationException::class, new ForbiddenException());
    }

    public function testTooManyRequestsCarriesRetryAfter(): void
    {
        $e = new TooManyRequestsException(900);
        $this->assertSame(900, $e->retryAfter);
    }
}
```

- [ ] **Step 3.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter ExceptionTest
```

- [ ] **Step 3.3: Implement**

`src/Domain/Auth/AuthorizationException.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use RuntimeException;

abstract class AuthorizationException extends RuntimeException {}
```

`src/Domain/Auth/UnauthorizedException.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

final class UnauthorizedException extends AuthorizationException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message);
    }
}
```

`src/Domain/Auth/ForbiddenException.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

final class ForbiddenException extends AuthorizationException
{
    public function __construct(string $message = 'Forbidden.')
    {
        parent::__construct($message);
    }
}
```

`src/Domain/Auth/TooManyRequestsException.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use RuntimeException;

final class TooManyRequestsException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter, string $message = 'Too many requests.')
    {
        parent::__construct($message);
    }
}
```

`src/Domain/Shared/ValidationException.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

use RuntimeException;

final class ValidationException extends RuntimeException {}
```

`src/Domain/Shared/NotFoundException.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Shared;

use RuntimeException;

final class NotFoundException extends RuntimeException {}
```

- [ ] **Step 3.4: Run — expect pass**

```bash
./vendor/bin/phpunit --filter ExceptionTest
```

- [ ] **Step 3.5: Commit**

```bash
git add src/Domain/Auth/ src/Domain/Shared/ValidationException.php src/Domain/Shared/NotFoundException.php tests/Unit/Domain/Auth/ExceptionTest.php
git commit -m "Add domain exception family for auth/validation/not-found"
```

---

### Task 4: Response helpers (`unauthorized`, `forbidden`, `tooManyRequests`)

**Files:**
- Modify: `src/Infrastructure/Framework/Http/Response.php`
- Create: `tests/Unit/Infrastructure/Framework/Http/ResponseTest.php`

- [ ] **Step 4.1: Write failing test**

`tests/Unit/Infrastructure/Framework/Http/ResponseTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Infrastructure\Framework\Http\Response;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    public function testUnauthorizedReturns401Json(): void
    {
        $r = Response::unauthorized('Authentication required.');
        $this->assertSame(401, $r->status());
        $this->assertStringContainsString('Authentication required.', $r->body());
    }

    public function testForbiddenReturns403Json(): void
    {
        $r = Response::forbidden();
        $this->assertSame(403, $r->status());
        $this->assertStringContainsString('Forbidden.', $r->body());
    }

    public function testTooManyRequestsSetsRetryAfterHeader(): void
    {
        $r = Response::tooManyRequests('Slow down.', 900);
        $this->assertSame(429, $r->status());
        $this->assertSame('900', $r->header('Retry-After'));
    }
}
```

- [ ] **Step 4.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter ResponseTest
```
Expected: `Call to undefined method … unauthorized`, etc. (Also `status()`, `body()`, `header()` don't exist.)

- [ ] **Step 4.3: Extend `Response`**

Edit `src/Infrastructure/Framework/Http/Response.php` — append inside the class and expose accessors:

```php
public function status(): int { return $this->status; }
public function body(): string { return $this->body; }
public function header(string $name): ?string { return $this->headers[$name] ?? null; }

public static function unauthorized(string $message = 'Authentication required.'): self
{
    return self::json(['error' => $message], 401);
}

public static function forbidden(string $message = 'Forbidden.'): self
{
    return self::json(['error' => $message], 403);
}

public static function tooManyRequests(string $message, int $retryAfter): self
{
    $response = self::json(['error' => $message], 429);
    return new self(
        429,
        $response->headers + ['Retry-After' => (string) $retryAfter],
        $response->body,
    );
}
```

Note: the `headers` and `body` properties are currently private; add `public function __get` via the accessors above rather than touching visibility. For the `tooManyRequests` composition we access via `self::json` then rebuild — shown above.

- [ ] **Step 4.4: Run — expect pass**

```bash
./vendor/bin/phpunit --filter ResponseTest
```

- [ ] **Step 4.5: Commit**

```bash
git add src/Infrastructure/Framework/Http/Response.php tests/Unit/Infrastructure/Framework/Http/ResponseTest.php
git commit -m "Add Response helpers for 401/403/429 plus accessors"
```

---

### Task 5: `Request::withActingUser`, `actingUser`, `clientIp`, `bearerToken`

**Files:**
- Modify: `src/Infrastructure/Framework/Http/Request.php`
- Create: `tests/Unit/Infrastructure/Framework/Http/RequestTest.php`

- [ ] **Step 5.1: Write failing test**

`tests/Unit/Infrastructure/Framework/Http/RequestTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    private function make(array $headers = [], array $server = []): Request
    {
        return Request::forTesting('POST', '/x', [], [], $headers, $server);
    }

    public function testActingUserDefaultsToNull(): void
    {
        $this->assertNull($this->make()->actingUser());
    }

    public function testWithActingUserReturnsNewInstanceCarryingUser(): void
    {
        $req = $this->make();
        $acting = new ActingUser(UserId::generate(), 'registered');
        $req2 = $req->withActingUser($acting);

        $this->assertNull($req->actingUser());
        $this->assertSame($acting, $req2->actingUser());
    }

    public function testBearerTokenExtractedFromAuthorizationHeader(): void
    {
        $req = $this->make(['Authorization' => 'Bearer abc123']);
        $this->assertSame('abc123', $req->bearerToken());
    }

    public function testBearerTokenNullWhenHeaderMissing(): void
    {
        $this->assertNull($this->make()->bearerToken());
    }

    public function testBearerTokenNullWhenSchemeNotBearer(): void
    {
        $this->assertNull($this->make(['Authorization' => 'Basic xyz'])->bearerToken());
    }

    public function testClientIpFromRemoteAddr(): void
    {
        $req = $this->make([], ['REMOTE_ADDR' => '10.0.0.1']);
        $this->assertSame('10.0.0.1', $req->clientIp());
    }

    public function testClientIpDefaultsWhenAbsent(): void
    {
        $this->assertSame('0.0.0.0', $this->make()->clientIp());
    }
}
```

- [ ] **Step 5.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter RequestTest
```

- [ ] **Step 5.3: Extend `Request`**

Rewrite `src/Infrastructure/Framework/Http/Request.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

use Daems\Domain\Auth\ActingUser;

final class Request
{
    private function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $query,
        private readonly array $body,
        private readonly array $headers,
        private readonly array $server,
        private readonly ?ActingUser $actingUser = null,
    ) {}

    public static function fromGlobals(): self
    {
        $uri = strtok(rawurldecode($_SERVER['REQUEST_URI'] ?? '/'), '?') ?: '/';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $body = [];
        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $type = $_SERVER['CONTENT_TYPE'] ?? '';
            if (str_contains($type, 'application/json')) {
                $body = (array) (json_decode((string) file_get_contents('php://input'), true) ?? []);
            } else {
                $body = $_POST;
            }
        }

        return new self($method, $uri, $_GET, $body, getallheaders() ?: [], $_SERVER);
    }

    public static function forTesting(
        string $method,
        string $uri,
        array $query = [],
        array $body = [],
        array $headers = [],
        array $server = [],
    ): self {
        return new self($method, $uri, $query, $body, $headers, $server);
    }

    public function method(): string { return $this->method; }
    public function uri(): string { return $this->uri; }
    public function query(string $key, mixed $default = null): mixed { return $this->query[$key] ?? $default; }
    public function input(string $key, mixed $default = null): mixed { return $this->body[$key] ?? $default; }
    public function all(): array { return $this->body; }
    public function header(string $key, ?string $default = null): ?string { return $this->headers[$key] ?? $default; }

    public function actingUser(): ?ActingUser { return $this->actingUser; }

    public function withActingUser(ActingUser $user): self
    {
        return new self(
            $this->method, $this->uri, $this->query, $this->body, $this->headers, $this->server, $user,
        );
    }

    public function bearerToken(): ?string
    {
        $auth = $this->headers['Authorization'] ?? $this->headers['authorization'] ?? null;
        if ($auth === null) {
            return null;
        }
        if (!str_starts_with($auth, 'Bearer ')) {
            return null;
        }
        $token = trim(substr($auth, 7));
        return $token === '' ? null : $token;
    }

    public function clientIp(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
```

- [ ] **Step 5.4: Run — expect pass**

```bash
./vendor/bin/phpunit --filter RequestTest
```

- [ ] **Step 5.5: Commit**

```bash
git add src/Infrastructure/Framework/Http/Request.php tests/Unit/Infrastructure/Framework/Http/RequestTest.php
git commit -m "Extend Request with acting user, bearer token, client IP"
```

---

### Task 6: `MiddlewareInterface`

**Files:**
- Create: `src/Infrastructure/Framework/Http/MiddlewareInterface.php`

- [ ] **Step 6.1: Create interface (no test needed — interface only)**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

interface MiddlewareInterface
{
    /**
     * @param callable(Request): Response $next
     */
    public function process(Request $request, callable $next): Response;
}
```

- [ ] **Step 6.2: Commit**

```bash
git add src/Infrastructure/Framework/Http/MiddlewareInterface.php
git commit -m "Add MiddlewareInterface for Router pipeline"
```

---

## Phase B — Router middleware pipeline

### Task 7: Extend `Router` to accept per-route middleware list

**Files:**
- Modify: `src/Infrastructure/Framework/Http/Router.php`
- Create: `tests/Unit/Infrastructure/Framework/Http/RouterTest.php`

- [ ] **Step 7.1: Write failing test**

`tests/Unit/Infrastructure/Framework/Http/RouterTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Framework\Http;

use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testDispatchInvokesHandlerWhenNoMiddleware(): void
    {
        $router = new Router(fn(string $class) => throw new \LogicException("should not resolve"));
        $router->get('/x', fn() => Response::json(['ok' => true]));

        $resp = $router->dispatch(Request::forTesting('GET', '/x'));
        $this->assertSame(200, $resp->status());
    }

    public function testMiddlewareRunsBeforeHandlerAndCanShortCircuit(): void
    {
        $blocker = new class implements MiddlewareInterface {
            public function process(Request $r, callable $next): Response
            {
                return Response::unauthorized();
            }
        };

        $router = new Router(fn(string $class) => $blocker);
        $router->get('/x', fn() => Response::json(['ok' => true]), [$blocker::class]);

        $resp = $router->dispatch(Request::forTesting('GET', '/x'));
        $this->assertSame(401, $resp->status());
    }

    public function testMiddlewareComposesInOrderAndReachesHandler(): void
    {
        $marker = new class implements MiddlewareInterface {
            public function process(Request $r, callable $next): Response
            {
                return $next($r);
            }
        };

        $router = new Router(fn(string $class) => $marker);
        $router->get('/x', fn(Request $r) => Response::json(['uri' => $r->uri()]), [$marker::class]);

        $resp = $router->dispatch(Request::forTesting('GET', '/x'));
        $this->assertSame(200, $resp->status());
        $this->assertStringContainsString('/x', $resp->body());
    }

    public function test404WhenRouteMissing(): void
    {
        $router = new Router(fn(string $class) => throw new \LogicException());
        $resp = $router->dispatch(Request::forTesting('GET', '/nope'));
        $this->assertSame(404, $resp->status());
    }
}
```

- [ ] **Step 7.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter RouterTest
```

- [ ] **Step 7.3: Rewrite `Router`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

use Closure;

final class Router
{
    /** @var array<array{method: string, pattern: string, handler: callable, middleware: list<class-string<MiddlewareInterface>>}> */
    private array $routes = [];

    /** @var Closure(class-string): MiddlewareInterface */
    private Closure $resolver;

    /**
     * @param callable(class-string): MiddlewareInterface $middlewareResolver
     */
    public function __construct(callable $middlewareResolver)
    {
        $this->resolver = $middlewareResolver(...);
    }

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function get(string $path, callable $handler, array $middleware = []): void
    {
        $this->routes[] = ['method' => 'GET', 'pattern' => $path, 'handler' => $handler, 'middleware' => $middleware];
    }

    /** @param list<class-string<MiddlewareInterface>> $middleware */
    public function post(string $path, callable $handler, array $middleware = []): void
    {
        $this->routes[] = ['method' => 'POST', 'pattern' => $path, 'handler' => $handler, 'middleware' => $middleware];
    }

    public function dispatch(Request $request): Response
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }
            $params = $this->match($route['pattern'], $request->uri());
            if ($params === null) {
                continue;
            }
            $final = static function (Request $req) use ($route, $params): Response {
                return ($route['handler'])($req, $params);
            };
            $pipeline = array_reduce(
                array_reverse($route['middleware']),
                function (callable $next, string $class): callable {
                    $mw = ($this->resolver)($class);
                    return static function (Request $req) use ($mw, $next): Response {
                        return $mw->process($req, $next);
                    };
                },
                $final,
            );
            return $pipeline($request);
        }
        return Response::notFound('Route not found');
    }

    private function match(string $pattern, string $uri): ?array
    {
        $regex = preg_replace('/\{([a-z_]+)\}/', '(?P<\1>[^/]+)', $pattern);
        if (!preg_match('#^' . $regex . '$#', $uri, $matches)) {
            return null;
        }
        return array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
    }
}
```

- [ ] **Step 7.4: Update `bootstrap/app.php`: Router factory now takes a resolver**

In `bootstrap/app.php`, change the Router singleton:

```php
$container->singleton(Router::class, static function (Container $container): Router {
    $router = new Router(static fn(string $class) => $container->make($class));
    (require dirname(__DIR__) . '/routes/api.php')($router, $container);
    return $router;
});
```

- [ ] **Step 7.5: Run — expect pass**

```bash
./vendor/bin/phpunit
```
All previous tests + RouterTest should pass.

- [ ] **Step 7.6: Commit**

```bash
git add src/Infrastructure/Framework/Http/Router.php bootstrap/app.php tests/Unit/Infrastructure/Framework/Http/RouterTest.php
git commit -m "Add middleware pipeline to Router with per-route registration"
```

---

## Phase C — Domain entities

### Task 8: `ActingUser` value object

**Files:**
- Create: `src/Domain/Auth/ActingUser.php`
- Create: `tests/Unit/Domain/Auth/ActingUserTest.php`

- [ ] **Step 8.1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class ActingUserTest extends TestCase
{
    public function testIsAdminTrueForAdminRole(): void
    {
        $a = new ActingUser(UserId::generate(), 'admin');
        $this->assertTrue($a->isAdmin());
    }

    public function testIsAdminFalseForOtherRoles(): void
    {
        foreach (['registered', 'member', 'supporter'] as $role) {
            $this->assertFalse((new ActingUser(UserId::generate(), $role))->isAdmin(), $role);
        }
    }

    public function testOwnsReturnsTrueForSameId(): void
    {
        $id = UserId::generate();
        $a = new ActingUser($id, 'registered');
        $this->assertTrue($a->owns($id));
    }

    public function testOwnsReturnsFalseForDifferentId(): void
    {
        $a = new ActingUser(UserId::generate(), 'registered');
        $this->assertFalse($a->owns(UserId::generate()));
    }
}
```

- [ ] **Step 8.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter ActingUserTest
```

- [ ] **Step 8.3: Implement**

`src/Domain/Auth/ActingUser.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\User\UserId;

final class ActingUser
{
    public function __construct(
        public readonly UserId $id,
        public readonly string $role,
    ) {}

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function owns(UserId $id): bool
    {
        return $this->id->value() === $id->value();
    }
}
```

- [ ] **Step 8.4: Run + commit**

```bash
./vendor/bin/phpunit --filter ActingUserTest
git add src/Domain/Auth/ActingUser.php tests/Unit/Domain/Auth/ActingUserTest.php
git commit -m "Add ActingUser value object with role and ownership helpers"
```

---

### Task 9: `AuthTokenId`, `AuthToken`, and repository interfaces

**Files:**
- Create: `src/Domain/Auth/AuthTokenId.php`
- Create: `src/Domain/Auth/AuthToken.php`
- Create: `src/Domain/Auth/AuthTokenRepositoryInterface.php`
- Create: `src/Domain/Auth/AuthLoginAttemptRepositoryInterface.php`
- Create: `tests/Unit/Domain/Auth/AuthTokenTest.php`

- [ ] **Step 9.1: Write failing test**

`tests/Unit/Domain/Auth/AuthTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Auth;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthTokenTest extends TestCase
{
    private function make(?DateTimeImmutable $expires = null, ?DateTimeImmutable $revoked = null): AuthToken
    {
        return new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'raw'),
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            $expires ?? new DateTimeImmutable('2026-04-26T00:00:00Z'),
            $revoked,
            null,
            null,
        );
    }

    public function testIsValidAtBeforeExpiry(): void
    {
        $t = $this->make(new DateTimeImmutable('2026-04-26T00:00:00Z'));
        $this->assertTrue($t->isValidAt(new DateTimeImmutable('2026-04-25T00:00:00Z')));
    }

    public function testIsNotValidAfterExpiry(): void
    {
        $t = $this->make(new DateTimeImmutable('2026-04-26T00:00:00Z'));
        $this->assertFalse($t->isValidAt(new DateTimeImmutable('2026-04-27T00:00:00Z')));
    }

    public function testIsNotValidWhenRevoked(): void
    {
        $t = $this->make(null, new DateTimeImmutable('2026-04-20T00:00:00Z'));
        $this->assertFalse($t->isValidAt(new DateTimeImmutable('2026-04-21T00:00:00Z')));
    }
}
```

- [ ] **Step 9.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter AuthTokenTest
```

- [ ] **Step 9.3: Implement**

`src/Domain/Auth/AuthTokenId.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\Shared\Uuid7;

final class AuthTokenId
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self
    {
        return new self(Uuid7::generate());
    }

    public static function fromString(string $value): self
    {
        return new self(Uuid7::fromString($value));
    }

    public function value(): string { return $this->value; }
}
```

`src/Domain/Auth/AuthToken.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use Daems\Domain\User\UserId;
use DateTimeImmutable;

final class AuthToken
{
    public function __construct(
        private readonly AuthTokenId $id,
        private readonly string $tokenHash,
        private readonly UserId $userId,
        private readonly DateTimeImmutable $issuedAt,
        private readonly DateTimeImmutable $lastUsedAt,
        private readonly DateTimeImmutable $expiresAt,
        private readonly ?DateTimeImmutable $revokedAt,
        private readonly ?string $userAgent,
        private readonly ?string $ip,
    ) {}

    public function id(): AuthTokenId { return $this->id; }
    public function tokenHash(): string { return $this->tokenHash; }
    public function userId(): UserId { return $this->userId; }
    public function issuedAt(): DateTimeImmutable { return $this->issuedAt; }
    public function lastUsedAt(): DateTimeImmutable { return $this->lastUsedAt; }
    public function expiresAt(): DateTimeImmutable { return $this->expiresAt; }
    public function revokedAt(): ?DateTimeImmutable { return $this->revokedAt; }
    public function userAgent(): ?string { return $this->userAgent; }
    public function ip(): ?string { return $this->ip; }

    public function isValidAt(DateTimeImmutable $now): bool
    {
        if ($this->revokedAt !== null) {
            return false;
        }
        return $now < $this->expiresAt;
    }
}
```

`src/Domain/Auth/AuthTokenRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use DateTimeImmutable;

interface AuthTokenRepositoryInterface
{
    public function store(AuthToken $token): void;

    public function findByHash(string $hash): ?AuthToken;

    public function touchLastUsed(AuthTokenId $id, DateTimeImmutable $now, DateTimeImmutable $newExpiry): void;

    public function revoke(AuthTokenId $id, DateTimeImmutable $at): void;
}
```

`src/Domain/Auth/AuthLoginAttemptRepositoryInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Domain\Auth;

use DateTimeImmutable;

interface AuthLoginAttemptRepositoryInterface
{
    public function record(string $ip, string $email, bool $success, DateTimeImmutable $at): void;

    public function countFailuresSince(string $ip, string $email, DateTimeImmutable $since): int;
}
```

- [ ] **Step 9.4: Run + commit**

```bash
./vendor/bin/phpunit --filter AuthTokenTest
git add src/Domain/Auth/AuthTokenId.php src/Domain/Auth/AuthToken.php src/Domain/Auth/AuthTokenRepositoryInterface.php src/Domain/Auth/AuthLoginAttemptRepositoryInterface.php tests/Unit/Domain/Auth/AuthTokenTest.php
git commit -m "Add AuthToken entity and repository interfaces"
```

---

## Phase D — Migrations

### Task 10: Write three migrations

**Files:**
- Create: `database/migrations/014_create_auth_tokens.sql`
- Create: `database/migrations/015_create_auth_login_attempts.sql`
- Create: `database/migrations/016_add_owner_id_to_projects.sql`

- [ ] **Step 10.1: Write `014_create_auth_tokens.sql`**

```sql
CREATE TABLE IF NOT EXISTS auth_tokens (
    id            CHAR(36)     NOT NULL,
    token_hash    CHAR(64)     NOT NULL,
    user_id       CHAR(36)     NOT NULL,
    issued_at     DATETIME     NOT NULL,
    last_used_at  DATETIME     NOT NULL,
    expires_at    DATETIME     NOT NULL,
    revoked_at    DATETIME         NULL,
    user_agent    VARCHAR(255)     NULL,
    ip            VARCHAR(45)      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_auth_tokens_hash (token_hash),
    KEY idx_auth_tokens_user (user_id),
    CONSTRAINT fk_auth_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 10.2: Write `015_create_auth_login_attempts.sql`**

```sql
CREATE TABLE IF NOT EXISTS auth_login_attempts (
    id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip           VARCHAR(45)     NOT NULL,
    email        VARCHAR(255)    NOT NULL,
    attempted_at DATETIME        NOT NULL,
    success      TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_auth_login_attempts_window (ip, email, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 10.3: Write `016_add_owner_id_to_projects.sql`**

```sql
ALTER TABLE projects
    ADD COLUMN owner_id CHAR(36) NULL AFTER id,
    ADD KEY idx_projects_owner (owner_id),
    ADD CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL;
```

- [ ] **Step 10.4: Apply migrations against the local DB (if one is running)**

Run (adjust credentials to your `.env`):
```bash
mysql -h 127.0.0.1 -P 3306 -u root daems_db < database/migrations/014_create_auth_tokens.sql
mysql -h 127.0.0.1 -P 3306 -u root daems_db < database/migrations/015_create_auth_login_attempts.sql
mysql -h 127.0.0.1 -P 3306 -u root daems_db < database/migrations/016_add_owner_id_to_projects.sql
```
Expected: no errors. If the DB is not running, skip — the Integration/E2E suites apply migrations programmatically (Task 30).

- [ ] **Step 10.5: Commit**

```bash
git add database/migrations/014_create_auth_tokens.sql database/migrations/015_create_auth_login_attempts.sql database/migrations/016_add_owner_id_to_projects.sql
git commit -m "Add migrations for auth_tokens, login attempts, project owner_id"
```

---

## Phase E — SQL repository adapters

### Task 11: `SqlAuthTokenRepository` + in-memory fake

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlAuthTokenRepository.php`
- Create: `tests/Support/Fake/InMemoryAuthTokenRepository.php`
- Create: `tests/Unit/Support/Fake/InMemoryAuthTokenRepositoryTest.php`
- Create: `tests/Integration/Persistence/SqlAuthTokenRepositoryTest.php`

- [ ] **Step 11.1: Write in-memory fake + unit test**

`tests/Support/Fake/InMemoryAuthTokenRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use DateTimeImmutable;

final class InMemoryAuthTokenRepository implements AuthTokenRepositoryInterface
{
    /** @var array<string, AuthToken> keyed by hash */
    public array $byHash = [];

    public function store(AuthToken $token): void
    {
        $this->byHash[$token->tokenHash()] = $token;
    }

    public function findByHash(string $hash): ?AuthToken
    {
        return $this->byHash[$hash] ?? null;
    }

    public function touchLastUsed(AuthTokenId $id, DateTimeImmutable $now, DateTimeImmutable $newExpiry): void
    {
        foreach ($this->byHash as $hash => $t) {
            if ($t->id()->value() === $id->value()) {
                $this->byHash[$hash] = new AuthToken(
                    $t->id(),
                    $t->tokenHash(),
                    $t->userId(),
                    $t->issuedAt(),
                    $now,
                    $newExpiry,
                    $t->revokedAt(),
                    $t->userAgent(),
                    $t->ip(),
                );
                return;
            }
        }
    }

    public function revoke(AuthTokenId $id, DateTimeImmutable $at): void
    {
        foreach ($this->byHash as $hash => $t) {
            if ($t->id()->value() === $id->value()) {
                $this->byHash[$hash] = new AuthToken(
                    $t->id(),
                    $t->tokenHash(),
                    $t->userId(),
                    $t->issuedAt(),
                    $t->lastUsedAt(),
                    $t->expiresAt(),
                    $at,
                    $t->userAgent(),
                    $t->ip(),
                );
                return;
            }
        }
    }
}
```

`tests/Unit/Support/Fake/InMemoryAuthTokenRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Support\Fake;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryAuthTokenRepositoryTest extends TestCase
{
    private function make(string $hash = 'h'): AuthToken
    {
        return new AuthToken(
            AuthTokenId::generate(),
            $hash,
            UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null, null, null,
        );
    }

    public function testStoreAndFind(): void
    {
        $r = new InMemoryAuthTokenRepository();
        $t = $this->make('abc');
        $r->store($t);
        $this->assertSame($t, $r->findByHash('abc'));
        $this->assertNull($r->findByHash('missing'));
    }

    public function testTouchLastUsedAdvancesExpiry(): void
    {
        $r = new InMemoryAuthTokenRepository();
        $t = $this->make();
        $r->store($t);
        $r->touchLastUsed($t->id(),
            new DateTimeImmutable('2026-04-20T00:00:00Z'),
            new DateTimeImmutable('2026-04-27T00:00:00Z'));
        $updated = $r->findByHash($t->tokenHash());
        $this->assertNotNull($updated);
        $this->assertSame('2026-04-27T00:00:00+00:00', $updated->expiresAt()->format('c'));
    }

    public function testRevoke(): void
    {
        $r = new InMemoryAuthTokenRepository();
        $t = $this->make();
        $r->store($t);
        $r->revoke($t->id(), new DateTimeImmutable('2026-04-20T00:00:00Z'));
        $this->assertNotNull($r->findByHash($t->tokenHash())->revokedAt());
    }
}
```

- [ ] **Step 11.2: Run — expect pass (no SQL adapter yet, fake is enough)**

```bash
./vendor/bin/phpunit --filter InMemoryAuthTokenRepositoryTest
```

- [ ] **Step 11.3: Write `SqlAuthTokenRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlAuthTokenRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class SqlAuthTokenRepository implements AuthTokenRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function store(AuthToken $token): void
    {
        $this->db->execute(
            'INSERT INTO auth_tokens
               (id, token_hash, user_id, issued_at, last_used_at, expires_at, revoked_at, user_agent, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $token->id()->value(),
                $token->tokenHash(),
                $token->userId()->value(),
                $token->issuedAt()->format('Y-m-d H:i:s'),
                $token->lastUsedAt()->format('Y-m-d H:i:s'),
                $token->expiresAt()->format('Y-m-d H:i:s'),
                $token->revokedAt()?->format('Y-m-d H:i:s'),
                $token->userAgent(),
                $token->ip(),
            ],
        );
    }

    public function findByHash(string $hash): ?AuthToken
    {
        $row = $this->db->queryOne('SELECT * FROM auth_tokens WHERE token_hash = ?', [$hash]);
        return $row !== null ? $this->hydrate($row) : null;
    }

    public function touchLastUsed(AuthTokenId $id, DateTimeImmutable $now, DateTimeImmutable $newExpiry): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET last_used_at = ?, expires_at = ? WHERE id = ?',
            [
                $now->format('Y-m-d H:i:s'),
                $newExpiry->format('Y-m-d H:i:s'),
                $id->value(),
            ],
        );
    }

    public function revoke(AuthTokenId $id, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'UPDATE auth_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [$at->format('Y-m-d H:i:s'), $id->value()],
        );
    }

    private function hydrate(array $row): AuthToken
    {
        return new AuthToken(
            AuthTokenId::fromString($row['id']),
            $row['token_hash'],
            UserId::fromString($row['user_id']),
            new DateTimeImmutable($row['issued_at']),
            new DateTimeImmutable($row['last_used_at']),
            new DateTimeImmutable($row['expires_at']),
            $row['revoked_at'] !== null ? new DateTimeImmutable($row['revoked_at']) : null,
            $row['user_agent'] ?? null,
            $row['ip'] ?? null,
        );
    }
}
```

- [ ] **Step 11.4: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlAuthTokenRepository.php tests/Support/Fake/InMemoryAuthTokenRepository.php tests/Unit/Support/Fake/InMemoryAuthTokenRepositoryTest.php
git commit -m "Add SqlAuthTokenRepository and in-memory test fake"
```

---

### Task 12: `SqlAuthLoginAttemptRepository` + fake

**Files:**
- Create: `src/Infrastructure/Adapter/Persistence/Sql/SqlAuthLoginAttemptRepository.php`
- Create: `tests/Support/Fake/InMemoryAuthLoginAttemptRepository.php`
- Create: `tests/Unit/Support/Fake/InMemoryAuthLoginAttemptRepositoryTest.php`

- [ ] **Step 12.1: Write in-memory fake + unit test**

`tests/Support/Fake/InMemoryAuthLoginAttemptRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use DateTimeImmutable;

final class InMemoryAuthLoginAttemptRepository implements AuthLoginAttemptRepositoryInterface
{
    /** @var list<array{ip:string,email:string,success:bool,at:DateTimeImmutable}> */
    public array $rows = [];

    public function record(string $ip, string $email, bool $success, DateTimeImmutable $at): void
    {
        $this->rows[] = ['ip' => $ip, 'email' => $email, 'success' => $success, 'at' => $at];
    }

    public function countFailuresSince(string $ip, string $email, DateTimeImmutable $since): int
    {
        $count = 0;
        foreach ($this->rows as $r) {
            if (!$r['success'] && $r['ip'] === $ip && $r['email'] === $email && $r['at'] >= $since) {
                $count++;
            }
        }
        return $count;
    }
}
```

`tests/Unit/Support/Fake/InMemoryAuthLoginAttemptRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Support\Fake;

use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class InMemoryAuthLoginAttemptRepositoryTest extends TestCase
{
    public function testCountsOnlyFailuresInWindow(): void
    {
        $r = new InMemoryAuthLoginAttemptRepository();
        $r->record('1.1.1.1', 'x@y.com', false, new DateTimeImmutable('2026-04-19T10:00:00Z'));
        $r->record('1.1.1.1', 'x@y.com', false, new DateTimeImmutable('2026-04-19T10:05:00Z'));
        $r->record('1.1.1.1', 'x@y.com', true,  new DateTimeImmutable('2026-04-19T10:06:00Z')); // success excluded
        $r->record('1.1.1.1', 'other@y.com', false, new DateTimeImmutable('2026-04-19T10:06:00Z')); // different email
        $r->record('2.2.2.2', 'x@y.com', false, new DateTimeImmutable('2026-04-19T10:06:00Z')); // different ip

        $this->assertSame(2, $r->countFailuresSince('1.1.1.1', 'x@y.com', new DateTimeImmutable('2026-04-19T09:50:00Z')));
        $this->assertSame(0, $r->countFailuresSince('1.1.1.1', 'x@y.com', new DateTimeImmutable('2026-04-19T10:10:00Z')));
    }
}
```

- [ ] **Step 12.2: Run — expect pass**

```bash
./vendor/bin/phpunit --filter InMemoryAuthLoginAttemptRepositoryTest
```

- [ ] **Step 12.3: Write `SqlAuthLoginAttemptRepository`**

`src/Infrastructure/Adapter/Persistence/Sql/SqlAuthLoginAttemptRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;
use DateTimeImmutable;

final class SqlAuthLoginAttemptRepository implements AuthLoginAttemptRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function record(string $ip, string $email, bool $success, DateTimeImmutable $at): void
    {
        $this->db->execute(
            'INSERT INTO auth_login_attempts (ip, email, attempted_at, success) VALUES (?, ?, ?, ?)',
            [$ip, $email, $at->format('Y-m-d H:i:s'), $success ? 1 : 0],
        );

        // Opportunistic cleanup: 1-in-100 chance per insert.
        if (random_int(0, 99) === 0) {
            $this->db->execute(
                'DELETE FROM auth_login_attempts WHERE attempted_at < ?',
                [$at->modify('-24 hours')->format('Y-m-d H:i:s')],
            );
        }
    }

    public function countFailuresSince(string $ip, string $email, DateTimeImmutable $since): int
    {
        $row = $this->db->queryOne(
            'SELECT COUNT(*) AS n FROM auth_login_attempts
             WHERE ip = ? AND email = ? AND success = 0 AND attempted_at >= ?',
            [$ip, $email, $since->format('Y-m-d H:i:s')],
        );
        return (int) ($row['n'] ?? 0);
    }
}
```

- [ ] **Step 12.4: Commit**

```bash
git add src/Infrastructure/Adapter/Persistence/Sql/SqlAuthLoginAttemptRepository.php tests/Support/Fake/InMemoryAuthLoginAttemptRepository.php tests/Unit/Support/Fake/InMemoryAuthLoginAttemptRepositoryTest.php
git commit -m "Add SqlAuthLoginAttemptRepository and in-memory test fake"
```

---

## Phase F — Token use cases

### Task 13: `CreateAuthToken` use case

**Files:**
- Create: `src/Application/Auth/CreateAuthToken/CreateAuthToken.php`
- Create: `src/Application/Auth/CreateAuthToken/CreateAuthTokenInput.php`
- Create: `src/Application/Auth/CreateAuthToken/CreateAuthTokenOutput.php`
- Create: `tests/Unit/Application/Auth/CreateAuthTokenTest.php`

- [ ] **Step 13.1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthTokenInput;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class CreateAuthTokenTest extends TestCase
{
    public function testStoresHashNotRawToken(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $clock = FrozenClock::at('2026-04-19T00:00:00Z');
        $userId = UserId::generate();

        $out = (new CreateAuthToken($repo, $clock))
            ->execute(new CreateAuthTokenInput($userId, 'ua', '1.1.1.1'));

        $raw = $out->rawToken;
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $raw);
        foreach ($repo->byHash as $hash => $_) {
            $this->assertSame(hash('sha256', $raw), $hash);
        }
    }

    public function testExpiresAtSevenDaysFromIssue(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $clock = FrozenClock::at('2026-04-19T00:00:00Z');

        $out = (new CreateAuthToken($repo, $clock))
            ->execute(new CreateAuthTokenInput(UserId::generate(), null, null));

        $this->assertSame('2026-04-26T00:00:00+00:00', $out->expiresAt->format('c'));
    }

    public function testGeneratesDistinctTokens(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $clock = FrozenClock::at('2026-04-19T00:00:00Z');
        $uc = new CreateAuthToken($repo, $clock);
        $a = $uc->execute(new CreateAuthTokenInput(UserId::generate(), null, null));
        $b = $uc->execute(new CreateAuthTokenInput(UserId::generate(), null, null));
        $this->assertNotSame($a->rawToken, $b->rawToken);
    }
}
```

- [ ] **Step 13.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter CreateAuthTokenTest
```

- [ ] **Step 13.3: Implement**

`src/Application/Auth/CreateAuthToken/CreateAuthTokenInput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\CreateAuthToken;

use Daems\Domain\User\UserId;

final class CreateAuthTokenInput
{
    public function __construct(
        public readonly UserId $userId,
        public readonly ?string $userAgent,
        public readonly ?string $ip,
    ) {}
}
```

`src/Application/Auth/CreateAuthToken/CreateAuthTokenOutput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\CreateAuthToken;

use Daems\Domain\Auth\AuthTokenId;
use DateTimeImmutable;

final class CreateAuthTokenOutput
{
    public function __construct(
        public readonly AuthTokenId $id,
        public readonly string $rawToken,
        public readonly DateTimeImmutable $expiresAt,
    ) {}
}
```

`src/Application/Auth/CreateAuthToken/CreateAuthToken.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\CreateAuthToken;

use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class CreateAuthToken
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly Clock $clock,
    ) {}

    public function execute(CreateAuthTokenInput $input): CreateAuthTokenOutput
    {
        $raw = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);

        $now = $this->clock->now();
        $expiresAt = $now->modify('+7 days');

        $id = AuthTokenId::generate();
        $this->tokens->store(new AuthToken(
            $id, $hash, $input->userId, $now, $now, $expiresAt, null, $input->userAgent, $input->ip,
        ));

        return new CreateAuthTokenOutput($id, $raw, $expiresAt);
    }
}
```

- [ ] **Step 13.4: Run — expect pass. Commit.**

```bash
./vendor/bin/phpunit --filter CreateAuthTokenTest
git add src/Application/Auth/CreateAuthToken/ tests/Unit/Application/Auth/CreateAuthTokenTest.php
git commit -m "Add CreateAuthToken use case"
```

---

### Task 14: `AuthenticateToken` use case

**Files:**
- Create: `src/Application/Auth/AuthenticateToken/{AuthenticateToken,AuthenticateTokenInput,AuthenticateTokenOutput}.php`
- Create: `tests/Unit/Application/Auth/AuthenticateTokenTest.php`

- [ ] **Step 14.1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\AuthenticateToken\AuthenticateTokenInput;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthenticateTokenTest extends TestCase
{
    public function testReturnsActingUserForValidToken(): void
    {
        $userId = UserId::generate();
        $user = new User($userId, 'Jane', 'jane@x.com', password_hash('p', PASSWORD_BCRYPT), '1990-01-01', 'registered');
        $users = new InMemoryUserRepository();
        $users->save($user);

        $tokenRepo = new InMemoryAuthTokenRepository();
        $tokenRepo->store(new AuthToken(
            AuthTokenId::generate(),
            hash('sha256', 'secret'),
            $userId,
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null, null, null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');

        $out = (new AuthenticateToken($tokenRepo, $users, $clock))
            ->execute(new AuthenticateTokenInput('secret'));

        $this->assertNull($out->error);
        $this->assertNotNull($out->actingUser);
        $this->assertSame($userId->value(), $out->actingUser->id->value());
        $this->assertSame('registered', $out->actingUser->role);
    }

    public function testRejectsMissingToken(): void
    {
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $out = (new AuthenticateToken(new InMemoryAuthTokenRepository(), new InMemoryUserRepository(), $clock))
            ->execute(new AuthenticateTokenInput('unknown'));
        $this->assertNotNull($out->error);
        $this->assertNull($out->actingUser);
    }

    public function testRejectsExpiredToken(): void
    {
        $tokenRepo = new InMemoryAuthTokenRepository();
        $userId = UserId::generate();
        $tokenRepo->store(new AuthToken(
            AuthTokenId::generate(), hash('sha256', 'secret'), $userId,
            new DateTimeImmutable('2026-04-01T00:00:00Z'),
            new DateTimeImmutable('2026-04-01T00:00:00Z'),
            new DateTimeImmutable('2026-04-08T00:00:00Z'),
            null, null, null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $out = (new AuthenticateToken($tokenRepo, new InMemoryUserRepository(), $clock))
            ->execute(new AuthenticateTokenInput('secret'));
        $this->assertNotNull($out->error);
    }

    public function testRejectsRevokedToken(): void
    {
        $tokenRepo = new InMemoryAuthTokenRepository();
        $userId = UserId::generate();
        $tokenRepo->store(new AuthToken(
            AuthTokenId::generate(), hash('sha256', 'secret'), $userId,
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T12:00:00Z'), null, null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $out = (new AuthenticateToken($tokenRepo, new InMemoryUserRepository(), $clock))
            ->execute(new AuthenticateTokenInput('secret'));
        $this->assertNotNull($out->error);
    }

    public function testSlidingExpiryAdvancesOnSuccess(): void
    {
        $tokenRepo = new InMemoryAuthTokenRepository();
        $users = new InMemoryUserRepository();
        $userId = UserId::generate();
        $users->save(new User($userId, 'Jane', 'j@x.com', password_hash('p', PASSWORD_BCRYPT), '1990-01-01', 'registered'));

        $issued = new DateTimeImmutable('2026-04-19T00:00:00Z');
        $tokenRepo->store(new AuthToken(
            AuthTokenId::generate(), hash('sha256', 'secret'), $userId,
            $issued, $issued, $issued->modify('+7 days'),
            null, null, null,
        ));
        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        (new AuthenticateToken($tokenRepo, $users, $clock))
            ->execute(new AuthenticateTokenInput('secret'));

        $updated = $tokenRepo->findByHash(hash('sha256', 'secret'));
        $this->assertNotNull($updated);
        // last_used advances to now, expires = min(now+7d, issued+30d)
        $this->assertSame('2026-04-27T00:00:00+00:00', $updated->expiresAt()->format('c'));
    }

    public function testHardCapAtIssuedPlus30Days(): void
    {
        $tokenRepo = new InMemoryAuthTokenRepository();
        $users = new InMemoryUserRepository();
        $userId = UserId::generate();
        $users->save(new User($userId, 'Jane', 'j@x.com', password_hash('p', PASSWORD_BCRYPT), '1990-01-01', 'registered'));

        $issued = new DateTimeImmutable('2026-04-01T00:00:00Z');
        $tokenRepo->store(new AuthToken(
            AuthTokenId::generate(), hash('sha256', 'secret'), $userId,
            $issued, $issued, $issued->modify('+30 days'), null, null, null,
        ));
        // Call on day 28, sliding would want +7 = day 35 but hard cap is issued+30 = 2026-05-01
        $clock = FrozenClock::at('2026-04-28T00:00:00Z');
        (new AuthenticateToken($tokenRepo, $users, $clock))
            ->execute(new AuthenticateTokenInput('secret'));

        $updated = $tokenRepo->findByHash(hash('sha256', 'secret'));
        $this->assertNotNull($updated);
        $this->assertSame('2026-05-01T00:00:00+00:00', $updated->expiresAt()->format('c'));
    }
}
```

- [ ] **Step 14.2: Add `InMemoryUserRepository` if missing**

`tests/Support/Fake/InMemoryUserRepository.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\User\User;
use Daems\Domain\User\UserRepositoryInterface;

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<string, User> keyed by id */
    public array $byId = [];
    /** @var array<string, string> email → id */
    public array $idByEmail = [];

    public function findByEmail(string $email): ?User
    {
        $id = $this->idByEmail[$email] ?? null;
        return $id !== null ? $this->byId[$id] : null;
    }

    public function findById(string $id): ?User
    {
        return $this->byId[$id] ?? null;
    }

    public function save(User $user): void
    {
        $this->byId[$user->id()->value()] = $user;
        $this->idByEmail[$user->email()] = $user->id()->value();
    }

    public function updateProfile(string $id, array $fields): void
    {
        // left out — only tests of UpdateProfile need this, wire when needed.
    }

    public function updatePassword(string $id, string $newHash): void
    {
        // left out — only tests of ChangePassword need this, wire when needed.
    }

    public function deleteById(string $id): void
    {
        if (isset($this->byId[$id])) {
            unset($this->idByEmail[$this->byId[$id]->email()]);
            unset($this->byId[$id]);
        }
    }
}
```

Check `Daems\Domain\User\UserRepositoryInterface` for the exact method list (names must match the interface; if `updateProfile` is spelled differently, update accordingly):

```bash
grep -A30 'interface UserRepositoryInterface' src/Domain/User/UserRepositoryInterface.php
```

Adjust the fake's method names to match.

- [ ] **Step 14.3: Implement `AuthenticateToken`**

`src/Application/Auth/AuthenticateToken/AuthenticateTokenInput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

final class AuthenticateTokenInput
{
    public function __construct(public readonly string $rawToken) {}
}
```

`src/Application/Auth/AuthenticateToken/AuthenticateTokenOutput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenId;

final class AuthenticateTokenOutput
{
    public function __construct(
        public readonly ?ActingUser $actingUser,
        public readonly ?AuthTokenId $tokenId,
        public readonly ?string $error,
    ) {}
}
```

`src/Application/Auth/AuthenticateToken/AuthenticateToken.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\AuthenticateToken;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\User\UserRepositoryInterface;

final class AuthenticateToken
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly UserRepositoryInterface $users,
        private readonly Clock $clock,
    ) {}

    public function execute(AuthenticateTokenInput $input): AuthenticateTokenOutput
    {
        $hash = hash('sha256', $input->rawToken);
        $token = $this->tokens->findByHash($hash);
        if ($token === null) {
            return new AuthenticateTokenOutput(null, null, 'token-not-found');
        }
        $now = $this->clock->now();
        if (!$token->isValidAt($now)) {
            return new AuthenticateTokenOutput(null, null, 'token-invalid');
        }

        $user = $this->users->findById($token->userId()->value());
        if ($user === null) {
            return new AuthenticateTokenOutput(null, null, 'user-not-found');
        }

        $slidingExpiry = $now->modify('+7 days');
        $hardCap = $token->issuedAt()->modify('+30 days');
        $newExpiry = $slidingExpiry < $hardCap ? $slidingExpiry : $hardCap;
        $this->tokens->touchLastUsed($token->id(), $now, $newExpiry);

        return new AuthenticateTokenOutput(
            new ActingUser($user->id(), $user->role()),
            $token->id(),
            null,
        );
    }
}
```

- [ ] **Step 14.4: Run + commit**

```bash
./vendor/bin/phpunit --filter AuthenticateTokenTest
git add src/Application/Auth/AuthenticateToken/ tests/Support/Fake/InMemoryUserRepository.php tests/Unit/Application/Auth/AuthenticateTokenTest.php
git commit -m "Add AuthenticateToken use case with sliding expiry and hard cap"
```

---

### Task 15: `RevokeAuthToken` + `LogoutUser` use cases

**Files:**
- Create: `src/Application/Auth/RevokeAuthToken/{RevokeAuthToken,RevokeAuthTokenInput,RevokeAuthTokenOutput}.php`
- Create: `src/Application/Auth/LogoutUser/{LogoutUser,LogoutUserInput,LogoutUserOutput}.php`
- Create: `tests/Unit/Application/Auth/RevokeAuthTokenTest.php`
- Create: `tests/Unit/Application/Auth/LogoutUserTest.php`

- [ ] **Step 15.1: Write failing tests**

`tests/Unit/Application/Auth/RevokeAuthTokenTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\RevokeAuthToken\RevokeAuthToken;
use Daems\Application\Auth\RevokeAuthToken\RevokeAuthTokenInput;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class RevokeAuthTokenTest extends TestCase
{
    public function testSetsRevokedAt(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $id = AuthTokenId::generate();
        $repo->store(new AuthToken(
            $id, hash('sha256','s'), UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null, null, null,
        ));

        (new RevokeAuthToken($repo, FrozenClock::at('2026-04-20T12:00:00Z')))
            ->execute(new RevokeAuthTokenInput($id));

        $updated = $repo->findByHash(hash('sha256','s'));
        $this->assertNotNull($updated->revokedAt());
    }
}
```

`tests/Unit/Application/Auth/LogoutUserTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Auth;

use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Application\Auth\LogoutUser\LogoutUserInput;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LogoutUserTest extends TestCase
{
    public function testRevokesToken(): void
    {
        $repo = new InMemoryAuthTokenRepository();
        $id = AuthTokenId::generate();
        $repo->store(new AuthToken(
            $id, hash('sha256','s'), UserId::generate(),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null, null, null,
        ));

        (new LogoutUser($repo, FrozenClock::at('2026-04-20T00:00:00Z')))
            ->execute(new LogoutUserInput($id));

        $this->assertNotNull($repo->findByHash(hash('sha256','s'))->revokedAt());
    }
}
```

- [ ] **Step 15.2: Run — expect fail**

- [ ] **Step 15.3: Implement**

`src/Application/Auth/RevokeAuthToken/RevokeAuthTokenInput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RevokeAuthToken;

use Daems\Domain\Auth\AuthTokenId;

final class RevokeAuthTokenInput
{
    public function __construct(public readonly AuthTokenId $tokenId) {}
}
```

`src/Application/Auth/RevokeAuthToken/RevokeAuthTokenOutput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RevokeAuthToken;

final class RevokeAuthTokenOutput
{
    public function __construct(public readonly bool $revoked = true) {}
}
```

`src/Application/Auth/RevokeAuthToken/RevokeAuthToken.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\RevokeAuthToken;

use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class RevokeAuthToken
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly Clock $clock,
    ) {}

    public function execute(RevokeAuthTokenInput $input): RevokeAuthTokenOutput
    {
        $this->tokens->revoke($input->tokenId, $this->clock->now());
        return new RevokeAuthTokenOutput();
    }
}
```

`src/Application/Auth/LogoutUser/LogoutUserInput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

use Daems\Domain\Auth\AuthTokenId;

final class LogoutUserInput
{
    public function __construct(public readonly AuthTokenId $tokenId) {}
}
```

`src/Application/Auth/LogoutUser/LogoutUserOutput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

final class LogoutUserOutput
{
    public function __construct(public readonly bool $ok = true) {}
}
```

`src/Application/Auth/LogoutUser/LogoutUser.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

use Daems\Application\Auth\RevokeAuthToken\RevokeAuthToken;
use Daems\Application\Auth\RevokeAuthToken\RevokeAuthTokenInput;

final class LogoutUser
{
    public function __construct(private readonly RevokeAuthToken $revoke) {}

    public function execute(LogoutUserInput $input): LogoutUserOutput
    {
        $this->revoke->execute(new RevokeAuthTokenInput($input->tokenId));
        return new LogoutUserOutput();
    }
}
```

- [ ] **Step 15.4: Run + commit**

```bash
./vendor/bin/phpunit --filter 'RevokeAuthTokenTest|LogoutUserTest'
git add src/Application/Auth/RevokeAuthToken/ src/Application/Auth/LogoutUser/ tests/Unit/Application/Auth/RevokeAuthTokenTest.php tests/Unit/Application/Auth/LogoutUserTest.php
git commit -m "Add RevokeAuthToken and LogoutUser use cases"
```

---

## Phase G — Middleware

### Task 16: `AuthMiddleware`

**Files:**
- Create: `src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php`
- Create: `tests/Integration/Http/AuthMiddlewareTest.php`

- [ ] **Step 16.1: Add `Integration` testsuite to `phpunit.xml`**

Replace `phpunit.xml`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         stopOnFailure="false">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
        <testsuite name="E2E">
            <directory>tests/E2E</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory suffix=".php">src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 16.2: Write failing test**

`tests/Integration/Http/AuthMiddlewareTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Domain\Auth\AuthToken;
use Daems\Domain\Auth\AuthTokenId;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Tests\Support\Fake\InMemoryAuthTokenRepository;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class AuthMiddlewareTest extends TestCase
{
    private function harness(InMemoryAuthTokenRepository $tokens, InMemoryUserRepository $users, FrozenClock $clock): AuthMiddleware
    {
        return new AuthMiddleware(new AuthenticateToken($tokens, $users, $clock));
    }

    public function testThrowsUnauthorizedWhenHeaderMissing(): void
    {
        $this->expectException(UnauthorizedException::class);
        $mw = $this->harness(new InMemoryAuthTokenRepository(), new InMemoryUserRepository(), FrozenClock::at('2026-04-20T00:00:00Z'));
        $mw->process(Request::forTesting('GET', '/x'), fn() => Response::json([]));
    }

    public function testThrowsUnauthorizedWhenTokenUnknown(): void
    {
        $this->expectException(UnauthorizedException::class);
        $mw = $this->harness(new InMemoryAuthTokenRepository(), new InMemoryUserRepository(), FrozenClock::at('2026-04-20T00:00:00Z'));
        $mw->process(Request::forTesting('GET', '/x', [], [], ['Authorization' => 'Bearer unknown']), fn() => Response::json([]));
    }

    public function testAttachesActingUserAndCallsNextWhenValid(): void
    {
        $userId = UserId::generate();
        $user = new User($userId, 'Jane', 'j@x.com', password_hash('p', PASSWORD_BCRYPT), '1990-01-01', 'registered');

        $users = new InMemoryUserRepository();
        $users->save($user);

        $tokens = new InMemoryAuthTokenRepository();
        $tokens->store(new AuthToken(
            AuthTokenId::generate(), hash('sha256', 'secret'), $userId,
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-19T00:00:00Z'),
            new DateTimeImmutable('2026-04-26T00:00:00Z'),
            null, null, null,
        ));

        $clock = FrozenClock::at('2026-04-20T00:00:00Z');
        $mw = $this->harness($tokens, $users, $clock);

        $received = null;
        $resp = $mw->process(
            Request::forTesting('GET', '/x', [], [], ['Authorization' => 'Bearer secret']),
            function (Request $r) use (&$received): Response {
                $received = $r;
                return Response::json(['ok' => true]);
            },
        );

        $this->assertSame(200, $resp->status());
        $this->assertNotNull($received?->actingUser());
        $this->assertSame($userId->value(), $received->actingUser()->id->value());
    }
}
```

- [ ] **Step 16.3: Run — expect fail**

```bash
./vendor/bin/phpunit --testsuite Integration
```

- [ ] **Step 16.4: Implement `AuthMiddleware`**

`src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\AuthenticateToken\AuthenticateTokenInput;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthenticateToken $authenticate) {}

    public function process(Request $request, callable $next): Response
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            throw new UnauthorizedException();
        }

        $out = $this->authenticate->execute(new AuthenticateTokenInput($raw));
        if ($out->actingUser === null) {
            throw new UnauthorizedException();
        }

        $request = $request->withActingUser($out->actingUser);
        // Stash token id on the request via a reserved header for logout to read.
        // (We smuggle through a side header because Request is immutable by design.)
        return $next($request);
    }
}
```

Note: `LogoutUser` needs the current token's `AuthTokenId`. Since `ActingUser` carries only `UserId` + role, we need a path to resolve the token id at logout time. Two options:

- **Preferred:** have logout re-hash the bearer token and revoke by hash. Add a `revokeByHash(string $hash, DateTimeImmutable $at)` method to `AuthTokenRepositoryInterface`. This keeps the middleware stateless.

Apply that preference now:

Edit `src/Domain/Auth/AuthTokenRepositoryInterface.php` — append:

```php
public function revokeByHash(string $hash, \DateTimeImmutable $at): void;
```

Add to `InMemoryAuthTokenRepository`:

```php
public function revokeByHash(string $hash, \DateTimeImmutable $at): void
{
    if (isset($this->byHash[$hash])) {
        $t = $this->byHash[$hash];
        $this->byHash[$hash] = new \Daems\Domain\Auth\AuthToken(
            $t->id(), $t->tokenHash(), $t->userId(),
            $t->issuedAt(), $t->lastUsedAt(), $t->expiresAt(),
            $at, $t->userAgent(), $t->ip(),
        );
    }
}
```

Add to `SqlAuthTokenRepository`:

```php
public function revokeByHash(string $hash, \DateTimeImmutable $at): void
{
    $this->db->execute(
        'UPDATE auth_tokens SET revoked_at = ? WHERE token_hash = ? AND revoked_at IS NULL',
        [$at->format('Y-m-d H:i:s'), $hash],
    );
}
```

Adjust `LogoutUser` to accept the raw token instead of tokenId:

`src/Application/Auth/LogoutUser/LogoutUserInput.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

final class LogoutUserInput
{
    public function __construct(public readonly string $rawToken) {}
}
```

`src/Application/Auth/LogoutUser/LogoutUser.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LogoutUser;

use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Shared\Clock;

final class LogoutUser
{
    public function __construct(
        private readonly AuthTokenRepositoryInterface $tokens,
        private readonly Clock $clock,
    ) {}

    public function execute(LogoutUserInput $input): LogoutUserOutput
    {
        $this->tokens->revokeByHash(hash('sha256', $input->rawToken), $this->clock->now());
        return new LogoutUserOutput();
    }
}
```

Update `LogoutUserTest.php` to use raw token:

```php
// in tests/Unit/Application/Auth/LogoutUserTest.php
(new LogoutUser($repo, FrozenClock::at('2026-04-20T00:00:00Z')))
    ->execute(new LogoutUserInput('s'));
```

- [ ] **Step 16.5: Run all tests**

```bash
./vendor/bin/phpunit
```
Expected: all pass.

- [ ] **Step 16.6: Commit**

```bash
git add src/Domain/Auth/AuthTokenRepositoryInterface.php \
        src/Infrastructure/Adapter/Persistence/Sql/SqlAuthTokenRepository.php \
        src/Application/Auth/LogoutUser/ \
        src/Infrastructure/Framework/Http/Middleware/AuthMiddleware.php \
        tests/Support/Fake/InMemoryAuthTokenRepository.php \
        tests/Integration/Http/AuthMiddlewareTest.php \
        tests/Unit/Application/Auth/LogoutUserTest.php \
        phpunit.xml
git commit -m "Add AuthMiddleware with token-hash-based logout path"
```

---

### Task 17: `RateLimitLoginMiddleware`

**Files:**
- Create: `src/Infrastructure/Framework/Http/Middleware/RateLimitLoginMiddleware.php`
- Create: `tests/Integration/Http/RateLimitLoginMiddlewareTest.php`

- [ ] **Step 17.1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository;
use Daems\Tests\Support\FrozenClock;
use PHPUnit\Framework\TestCase;

final class RateLimitLoginMiddlewareTest extends TestCase
{
    public function testAllowsFirstFourFailures(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        $mw = new RateLimitLoginMiddleware($repo, $clock, maxFailures: 5, windowMinutes: 15, lockoutSeconds: 900);

        for ($i = 0; $i < 4; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            fn() => Response::json(['ok' => true]),
        );

        $this->assertSame(200, $resp->status());
    }

    public function testThrowsTooManyAfterFiveFailures(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $this->expectException(TooManyRequestsException::class);
        $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            fn() => Response::json(['ok' => true]),
        );
    }

    public function testDifferentIpNotAffected(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '2.2.2.2']),
            fn() => Response::json(['ok' => true]),
        );
        $this->assertSame(200, $resp->status());
    }

    public function testPassesThroughNonLoginRoutes(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $clock = FrozenClock::at('2026-04-19T12:00:00Z');
        for ($i = 0; $i < 10; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $clock->now());
        }
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('GET', '/api/v1/status', [], [], [], ['REMOTE_ADDR' => '1.1.1.1']),
            fn() => Response::json(['ok' => true]),
        );
        $this->assertSame(200, $resp->status());
    }

    public function testWindowExpires(): void
    {
        $repo = new InMemoryAuthLoginAttemptRepository();
        $past = new \DateTimeImmutable('2026-04-19T11:00:00Z');
        for ($i = 0; $i < 5; $i++) {
            $repo->record('1.1.1.1', 'x@y.com', false, $past);
        }
        $clock = FrozenClock::at('2026-04-19T12:00:00Z'); // 60 minutes later — window is 15
        $mw = new RateLimitLoginMiddleware($repo, $clock, 5, 15, 900);

        $resp = $mw->process(
            Request::forTesting('POST', '/api/v1/auth/login', [], ['email' => 'x@y.com'], [], ['REMOTE_ADDR' => '1.1.1.1']),
            fn() => Response::json(['ok' => true]),
        );
        $this->assertSame(200, $resp->status());
    }
}
```

- [ ] **Step 17.2: Run — expect fail**

- [ ] **Step 17.3: Implement**

`src/Infrastructure/Framework/Http/Middleware/RateLimitLoginMiddleware.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http\Middleware;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Http\MiddlewareInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class RateLimitLoginMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthLoginAttemptRepositoryInterface $attempts,
        private readonly Clock $clock,
        private readonly int $maxFailures = 5,
        private readonly int $windowMinutes = 15,
        private readonly int $lockoutSeconds = 900,
    ) {}

    public function process(Request $request, callable $next): Response
    {
        if ($request->method() !== 'POST' || $request->uri() !== '/api/v1/auth/login') {
            return $next($request);
        }

        $email = trim((string) $request->input('email'));
        if ($email === '') {
            return $next($request);
        }

        $since = $this->clock->now()->modify("-{$this->windowMinutes} minutes");
        $fails = $this->attempts->countFailuresSince($request->clientIp(), $email, $since);

        if ($fails >= $this->maxFailures) {
            throw new TooManyRequestsException($this->lockoutSeconds, 'Too many login attempts. Try again later.');
        }

        return $next($request);
    }
}
```

- [ ] **Step 17.4: Run + commit**

```bash
./vendor/bin/phpunit --testsuite Integration
git add src/Infrastructure/Framework/Http/Middleware/RateLimitLoginMiddleware.php tests/Integration/Http/RateLimitLoginMiddlewareTest.php
git commit -m "Add RateLimitLoginMiddleware with configurable window and lockout"
```

---

## Phase H — Kernel rewrite (F-006)

### Task 18: Kernel translates exceptions to HTTP responses

**Files:**
- Modify: `src/Infrastructure/Framework/Http/Kernel.php`
- Modify: `bootstrap/app.php` (bind Logger + Kernel construction)
- Create: `tests/Integration/Http/KernelErrorSanitisationTest.php`

- [ ] **Step 18.1: Write failing test**

`tests/Integration/Http/KernelErrorSanitisationTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Integration\Http;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Http\Kernel;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;
use Daems\Infrastructure\Framework\Http\Router;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class KernelErrorSanitisationTest extends TestCase
{
    private function kernelThatThrows(\Throwable $e, bool $debug = false): Kernel
    {
        $logger = new class implements LoggerInterface {
            public array $calls = [];
            public function error(string $message, array $context = []): void {
                $this->calls[] = [$message, $context];
            }
        };

        $container = new Container();
        $router = new Router(fn(string $c) => throw new RuntimeException("no mw"));
        $router->get('/boom', static function () use ($e): Response { throw $e; });
        $container->singleton(Router::class, static fn() => $router);

        return new Kernel($container, $logger, $debug);
    }

    public function test401ForUnauthorized(): void
    {
        $k = $this->kernelThatThrows(new UnauthorizedException());
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(401, $r->status());
        $this->assertStringContainsString('Authentication required.', $r->body());
    }

    public function test403ForForbidden(): void
    {
        $k = $this->kernelThatThrows(new ForbiddenException());
        $this->assertSame(403, $k->handle(Request::forTesting('GET', '/boom'))->status());
    }

    public function test404ForNotFound(): void
    {
        $k = $this->kernelThatThrows(new NotFoundException());
        $this->assertSame(404, $k->handle(Request::forTesting('GET', '/boom'))->status());
    }

    public function test400ForValidation(): void
    {
        $k = $this->kernelThatThrows(new ValidationException('bad input'));
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(400, $r->status());
        $this->assertStringContainsString('bad input', $r->body());
    }

    public function test429ForTooMany(): void
    {
        $k = $this->kernelThatThrows(new TooManyRequestsException(900, 'slow'));
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(429, $r->status());
        $this->assertSame('900', $r->header('Retry-After'));
    }

    public function testSqlStateMessageNotLeaked(): void
    {
        $k = $this->kernelThatThrows(new \PDOException(
            "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'attacker@evil.com' for key 'users_email_unique'",
        ));
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(500, $r->status());
        $this->assertStringNotContainsString('SQLSTATE', $r->body());
        $this->assertStringNotContainsString('Duplicate entry', $r->body());
        $this->assertStringNotContainsString('attacker@evil.com', $r->body());
        $this->assertStringContainsString('Internal server error', $r->body());
    }

    public function testDebugModeLeaksExceptionBody(): void
    {
        $k = $this->kernelThatThrows(new RuntimeException('secret-detail'), debug: true);
        $r = $k->handle(Request::forTesting('GET', '/boom'));
        $this->assertSame(500, $r->status());
        $this->assertStringContainsString('secret-detail', $r->body());
    }

    public function testUnhandledExceptionIsLogged(): void
    {
        $logger = new class implements LoggerInterface {
            public array $calls = [];
            public function error(string $message, array $context = []): void {
                $this->calls[] = [$message, $context];
            }
        };
        $container = new Container();
        $router = new Router(fn(string $c) => throw new RuntimeException());
        $router->get('/boom', static function () { throw new RuntimeException('x'); });
        $container->singleton(Router::class, static fn() => $router);

        $k = new Kernel($container, $logger, false);
        $k->handle(Request::forTesting('GET', '/boom'));

        $this->assertNotEmpty($logger->calls);
    }
}
```

- [ ] **Step 18.2: Run — expect fail**

- [ ] **Step 18.3: Rewrite `Kernel`**

`src/Infrastructure/Framework/Http/Kernel.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Http;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Auth\TooManyRequestsException;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use Throwable;

final class Kernel
{
    public function __construct(
        private readonly Container $container,
        private readonly LoggerInterface $logger,
        private readonly bool $debug = false,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            return $this->container->make(Router::class)->dispatch($request);
        } catch (UnauthorizedException $e) {
            return Response::unauthorized($e->getMessage() ?: 'Authentication required.');
        } catch (ForbiddenException $e) {
            return Response::forbidden($e->getMessage() ?: 'Forbidden.');
        } catch (NotFoundException $e) {
            return Response::notFound($e->getMessage() ?: 'Not found.');
        } catch (ValidationException $e) {
            return Response::badRequest($e->getMessage());
        } catch (TooManyRequestsException $e) {
            return Response::tooManyRequests($e->getMessage(), $e->retryAfter);
        } catch (Throwable $e) {
            $this->logger->error('Unhandled exception', ['exception' => $e]);
            $body = $this->debug
                ? sprintf('%s: %s (%s:%d)', $e::class, $e->getMessage(), $e->getFile(), $e->getLine())
                : 'Internal server error.';
            return Response::serverError($body);
        }
    }

    public function send(Response $response): void
    {
        $response->send();
    }
}
```

- [ ] **Step 18.4: Update `bootstrap/app.php` to bind Logger and construct Kernel with debug from env**

Append to bindings in `bootstrap/app.php`:

```php
use Daems\Infrastructure\Framework\Logging\ErrorLogLogger;
use Daems\Infrastructure\Framework\Logging\LoggerInterface;
use Daems\Domain\Shared\Clock;
use Daems\Infrastructure\Framework\Clock\SystemClock;
use Daems\Domain\Auth\AuthTokenRepositoryInterface;
use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAuthTokenRepository;
use Daems\Infrastructure\Adapter\Persistence\Sql\SqlAuthLoginAttemptRepository;
use Daems\Application\Auth\AuthenticateToken\AuthenticateToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;

$container->singleton(LoggerInterface::class, static fn() => new ErrorLogLogger());
$container->singleton(Clock::class, static fn() => new SystemClock());

$container->singleton(AuthTokenRepositoryInterface::class,
    static fn(Container $c) => new SqlAuthTokenRepository($c->make(Connection::class)));
$container->singleton(AuthLoginAttemptRepositoryInterface::class,
    static fn(Container $c) => new SqlAuthLoginAttemptRepository($c->make(Connection::class)));

$container->bind(CreateAuthToken::class,
    static fn(Container $c) => new CreateAuthToken($c->make(AuthTokenRepositoryInterface::class), $c->make(Clock::class)));
$container->bind(AuthenticateToken::class,
    static fn(Container $c) => new AuthenticateToken(
        $c->make(AuthTokenRepositoryInterface::class),
        $c->make(UserRepositoryInterface::class),
        $c->make(Clock::class),
    ));
$container->bind(LogoutUser::class,
    static fn(Container $c) => new LogoutUser($c->make(AuthTokenRepositoryInterface::class), $c->make(Clock::class)));

$container->bind(AuthMiddleware::class,
    static fn(Container $c) => new AuthMiddleware($c->make(AuthenticateToken::class)));
$container->bind(RateLimitLoginMiddleware::class,
    static fn(Container $c) => new RateLimitLoginMiddleware(
        $c->make(AuthLoginAttemptRepositoryInterface::class),
        $c->make(Clock::class),
        (int)($_ENV['AUTH_RATE_LIMIT_MAX_FAILS'] ?? 5),
        (int)($_ENV['AUTH_RATE_LIMIT_WINDOW_MIN'] ?? 15),
        (int)($_ENV['AUTH_RATE_LIMIT_LOCKOUT_MIN'] ?? 15) * 60,
    ));
```

Change the final return:
```php
return new Kernel(
    $container,
    $container->make(LoggerInterface::class),
    ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
);
```

- [ ] **Step 18.5: Extend `.env.example`**

Append to `.env.example`:

```
APP_DEBUG=false

AUTH_TOKEN_TTL_DAYS=7
AUTH_TOKEN_HARD_CAP_DAYS=30
AUTH_RATE_LIMIT_WINDOW_MIN=15
AUTH_RATE_LIMIT_MAX_FAILS=5
AUTH_RATE_LIMIT_LOCKOUT_MIN=15

DB_TEST_HOST=127.0.0.1
DB_TEST_PORT=3306
DB_TEST_DATABASE=daems_test
DB_TEST_USERNAME=root
DB_TEST_PASSWORD=
```

- [ ] **Step 18.6: Run + commit**

```bash
./vendor/bin/phpunit
git add src/Infrastructure/Framework/Http/Kernel.php bootstrap/app.php .env.example tests/Integration/Http/KernelErrorSanitisationTest.php
git commit -m "Sanitise Kernel errors and map domain exceptions to HTTP codes"
```

---

## Phase I — AuthController changes

### Task 19: Login returns token + records attempt; 72-byte password cap

**Files:**
- Modify: `src/Application/Auth/LoginUser/LoginUser.php`
- Modify: `src/Application/Auth/LoginUser/LoginUserOutput.php` (check if token needed there — or return from use case)
- Modify: `src/Application/Auth/RegisterUser/RegisterUser.php`
- Modify: `src/Application/User/ChangePassword/ChangePassword.php`
- Modify: `src/Infrastructure/Adapter/Api/Controller/AuthController.php`
- Modify: `src/Infrastructure/Adapter/Api/Controller/UserController.php` (password cap propagates through ChangePassword)
- Modify: `tests/Unit/Application/Auth/LoginUserTest.php`
- Modify: `tests/Unit/Application/Auth/RegisterUserTest.php`
- Modify: `tests/Unit/Application/User/ChangePasswordTest.php`
- Modify: `bootstrap/app.php`

- [ ] **Step 19.1: Extend `LoginUser` to record attempts + 72-byte cap**

First extend the test file `tests/Unit/Application/Auth/LoginUserTest.php` with new tests (add to existing class):

```php
public function testRecordsSuccessfulAttempt(): void
{
    $user = $this->makeUser('correct-pass');
    $repo = $this->createMock(UserRepositoryInterface::class);
    $repo->method('findByEmail')->willReturn($user);
    $attempts = new \Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository();
    $clock = \Daems\Tests\Support\FrozenClock::at('2026-04-19T12:00:00Z');

    (new LoginUser($repo, $attempts, $clock))
        ->execute(new LoginUserInput('jane@example.com', 'correct-pass', '1.2.3.4'));

    $this->assertCount(1, $attempts->rows);
    $this->assertTrue($attempts->rows[0]['success']);
}

public function testRecordsFailedAttempt(): void
{
    $repo = $this->createMock(UserRepositoryInterface::class);
    $repo->method('findByEmail')->willReturn(null);
    $attempts = new \Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository();
    $clock = \Daems\Tests\Support\FrozenClock::at('2026-04-19T12:00:00Z');

    (new LoginUser($repo, $attempts, $clock))
        ->execute(new LoginUserInput('nope@example.com', 'x', '1.2.3.4'));

    $this->assertCount(1, $attempts->rows);
    $this->assertFalse($attempts->rows[0]['success']);
}

public function testRejectsPasswordLongerThan72Bytes(): void
{
    $repo = $this->createMock(UserRepositoryInterface::class);
    $attempts = new \Daems\Tests\Support\Fake\InMemoryAuthLoginAttemptRepository();
    $clock = \Daems\Tests\Support\FrozenClock::at('2026-04-19T12:00:00Z');

    $out = (new LoginUser($repo, $attempts, $clock))
        ->execute(new LoginUserInput('x@example.com', str_repeat('a', 73), '1.2.3.4'));

    $this->assertNotNull($out->error);
    $this->assertCount(1, $attempts->rows);
    $this->assertFalse($attempts->rows[0]['success']);
}
```

Also update existing tests in that file to pass the three extra constructor parameters; add `$attempts = new InMemoryAuthLoginAttemptRepository(); $clock = FrozenClock::at(...);` and update `LoginUserInput` with the third `ip` arg.

- [ ] **Step 19.2: Extend `LoginUserInput`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

final class LoginUserInput
{
    public function __construct(
        public readonly string $email,
        public readonly string $password,
        public readonly string $ip,
    ) {}
}
```

- [ ] **Step 19.3: Rewrite `LoginUser`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\Auth\LoginUser;

use Daems\Domain\Auth\AuthLoginAttemptRepositoryInterface;
use Daems\Domain\Shared\Clock;
use Daems\Domain\User\UserRepositoryInterface;

final class LoginUser
{
    public function __construct(
        private readonly UserRepositoryInterface $users,
        private readonly AuthLoginAttemptRepositoryInterface $attempts,
        private readonly Clock $clock,
    ) {}

    public function execute(LoginUserInput $input): LoginUserOutput
    {
        $now = $this->clock->now();

        if (strlen($input->password) > 72) {
            $this->attempts->record($input->ip, $input->email, false, $now);
            return new LoginUserOutput(null, 'Invalid email or password.');
        }

        $user = $this->users->findByEmail($input->email);
        $ok = $user !== null && password_verify($input->password, $user->passwordHash());
        $this->attempts->record($input->ip, $input->email, $ok, $now);

        if (!$ok) {
            return new LoginUserOutput(null, 'Invalid email or password.');
        }

        return new LoginUserOutput([
            'id'               => $user->id()->value(),
            'name'             => $user->name(),
            'email'            => $user->email(),
            'dob'              => $user->dateOfBirth(),
            'role'             => $user->role(),
            'country'          => $user->country(),
            'address_street'   => $user->addressStreet(),
            'address_zip'      => $user->addressZip(),
            'address_city'     => $user->addressCity(),
            'address_country'  => $user->addressCountry(),
            'membership_type'  => $user->membershipType(),
            'membership_status'=> $user->membershipStatus(),
            'member_number'    => $user->memberNumber(),
            'created_at'       => $user->createdAt(),
        ]);
    }
}
```

- [ ] **Step 19.4: Extend `RegisterUser` with 72-byte cap**

Append test to `tests/Unit/Application/Auth/RegisterUserTest.php`:

```php
public function testRejectsPasswordLongerThan72Bytes(): void
{
    $repo = $this->createMock(UserRepositoryInterface::class);
    $out = (new RegisterUser($repo))->execute(
        new RegisterUserInput('T', 't@example.com', str_repeat('a', 73), '1990-01-01'),
    );
    $this->assertNull($out->id);
    $this->assertNotNull($out->error);
}
```

Add to `RegisterUser::execute` at the top:

```php
if (strlen($input->password) > 72) {
    return new RegisterUserOutput(null, 'Password must be at most 72 bytes.');
}
```

- [ ] **Step 19.5: Extend `ChangePassword` with 72-byte cap**

Append test:

```php
public function testRejectsNewPasswordLongerThan72Bytes(): void
{
    $repo = $this->createMock(UserRepositoryInterface::class);
    $out = (new ChangePassword($repo))->execute(
        new ChangePasswordInput('user-id', 'current', str_repeat('a', 73)),
    );
    $this->assertNotNull($out->error);
}
```

Add at top of `ChangePassword::execute`:

```php
if (strlen($input->newPassword) > 72) {
    return new ChangePasswordOutput('Password must be at most 72 bytes.');
}
```

- [ ] **Step 19.6: Rewrite `AuthController`**

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Api\Controller;

use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthTokenInput;
use Daems\Application\Auth\LoginUser\LoginUser;
use Daems\Application\Auth\LoginUser\LoginUserInput;
use Daems\Application\Auth\LogoutUser\LogoutUser;
use Daems\Application\Auth\LogoutUser\LogoutUserInput;
use Daems\Application\Auth\RegisterUser\RegisterUser;
use Daems\Application\Auth\RegisterUser\RegisterUserInput;
use Daems\Domain\Auth\UnauthorizedException;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class AuthController
{
    public function __construct(
        private readonly RegisterUser $registerUser,
        private readonly LoginUser $loginUser,
        private readonly CreateAuthToken $createAuthToken,
        private readonly LogoutUser $logoutUser,
        private readonly UserRepositoryInterface $users,
    ) {}

    public function login(Request $request): Response
    {
        $email    = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return Response::badRequest('Email and password are required.');
        }

        $output = $this->loginUser->execute(new LoginUserInput($email, $password, $request->clientIp()));

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 401);
        }

        $user = $this->users->findByEmail($email);
        if ($user === null) {
            return Response::serverError('Authentication error.');
        }

        $token = $this->createAuthToken->execute(new CreateAuthTokenInput(
            $user->id(),
            $request->header('User-Agent'),
            $request->clientIp(),
        ));

        return Response::json([
            'data' => [
                'user'       => $output->user,
                'token'      => $token->rawToken,
                'expires_at' => $token->expiresAt->format('c'),
            ],
        ]);
    }

    public function register(Request $request): Response
    {
        $name     = trim((string) $request->input('name'));
        $email    = trim((string) $request->input('email'));
        $password = (string) $request->input('password');
        $dob      = trim((string) $request->input('date_of_birth'));

        if ($name === '' || $email === '' || $password === '' || $dob === '') {
            return Response::badRequest('Name, email, password and date of birth are required.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::badRequest('Invalid email address.');
        }

        if (strlen($password) < 8) {
            return Response::badRequest('Password must be at least 8 characters.');
        }

        if (strlen($password) > 72) {
            return Response::badRequest('Password must be at most 72 bytes.');
        }

        $output = $this->registerUser->execute(
            new RegisterUserInput($name, $email, $password, $dob),
        );

        if ($output->error !== null) {
            return Response::json(['error' => $output->error], 409);
        }

        return Response::json(['data' => ['id' => $output->id]], 201);
    }

    public function logout(Request $request): Response
    {
        $raw = $request->bearerToken();
        if ($raw === null) {
            throw new UnauthorizedException();
        }
        $this->logoutUser->execute(new LogoutUserInput($raw));
        return Response::json(null, 204);
    }
}
```

Also add a `null` overload to `Response::json`:
- If `$data === null`, send empty body with the given status.

Edit `Response::json`:

```php
public static function json(mixed $data, int $status = 200): self
{
    $body = $data === null
        ? ''
        : (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return new self(
        $status,
        ['Content-Type' => 'application/json; charset=utf-8'],
        $body,
    );
}
```

- [ ] **Step 19.7: Update `bootstrap/app.php` AuthController binding**

```php
$container->bind(AuthController::class,
    static fn(Container $c) => new AuthController(
        $c->make(RegisterUser::class),
        $c->make(LoginUser::class),
        $c->make(CreateAuthToken::class),
        $c->make(LogoutUser::class),
        $c->make(UserRepositoryInterface::class),
    ));
$container->bind(LoginUser::class,
    static fn(Container $c) => new LoginUser(
        $c->make(UserRepositoryInterface::class),
        $c->make(AuthLoginAttemptRepositoryInterface::class),
        $c->make(Clock::class),
    ));
```

- [ ] **Step 19.8: Run + commit**

```bash
./vendor/bin/phpunit
git add src/Application/Auth/ src/Application/User/ChangePassword/ChangePassword.php src/Infrastructure/Adapter/Api/Controller/AuthController.php src/Infrastructure/Framework/Http/Response.php bootstrap/app.php tests/Unit/Application/Auth/ tests/Unit/Application/User/ChangePasswordTest.php
git commit -m "Login issues token, records attempts; logout endpoint; 72-byte cap"
```

---

## Phase J — Policy-ify use cases

> **Reading this phase:** Task 20 is the canonical template. All later tasks in this phase follow the same pattern but enumerate their own policy rules, DTO field changes, and test cases. Treat each task as self-contained — repeat-apply the template shape without re-reading Task 20.

### Task 20 (TEMPLATE): `DeleteAccount` (F-001)

**Files:**
- Modify: `src/Application/User/DeleteAccount/DeleteAccount.php`
- Modify: `src/Application/User/DeleteAccount/DeleteAccountInput.php`
- Create: `tests/Unit/Application/User/DeleteAccountTest.php`
- Modify: `src/Infrastructure/Adapter/Api/Controller/UserController.php`

**Policy rule (from spec §4):** acting user must own target OR be admin.

- [ ] **Step 20.1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\DeleteAccount\DeleteAccount;
use Daems\Application\User\DeleteAccount\DeleteAccountInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class DeleteAccountTest extends TestCase
{
    private function seed(InMemoryUserRepository $repo, string $role = 'registered'): User
    {
        $u = new User(
            UserId::generate(), 'N', 'n@x.com',
            password_hash('p', PASSWORD_BCRYPT), '1990-01-01', $role,
        );
        $repo->save($u);
        return $u;
    }

    public function testSelfDeleteSucceeds(): void
    {
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $acting = new ActingUser($victim->id(), 'registered');

        $out = (new DeleteAccount($repo))
            ->execute(new DeleteAccountInput($acting, $victim->id()->value()));

        $this->assertTrue($out->deleted);
        $this->assertNull($repo->findById($victim->id()->value()));
    }

    public function testDeletingOtherUserThrowsForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $attacker = new ActingUser(UserId::generate(), 'registered');

        (new DeleteAccount($repo))
            ->execute(new DeleteAccountInput($attacker, $victim->id()->value()));
    }

    public function testAdminCanDeleteAnyone(): void
    {
        $repo = new InMemoryUserRepository();
        $victim = $this->seed($repo);
        $admin = new ActingUser(UserId::generate(), 'admin');

        $out = (new DeleteAccount($repo))
            ->execute(new DeleteAccountInput($admin, $victim->id()->value()));

        $this->assertTrue($out->deleted);
    }

    public function testTargetUserNotFoundReturnsErrorOutput(): void
    {
        $repo = new InMemoryUserRepository();
        $acting = new ActingUser(UserId::generate(), 'admin');

        $out = (new DeleteAccount($repo))
            ->execute(new DeleteAccountInput($acting, UserId::generate()->value()));

        $this->assertFalse($out->deleted);
        $this->assertNotNull($out->error);
    }
}
```

- [ ] **Step 20.2: Run — expect fail**

```bash
./vendor/bin/phpunit --filter DeleteAccountTest
```

- [ ] **Step 20.3: Extend `DeleteAccountInput`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\User\DeleteAccount;

use Daems\Domain\Auth\ActingUser;

final class DeleteAccountInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $userId,
    ) {}
}
```

- [ ] **Step 20.4: Enforce policy in `DeleteAccount::execute`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\User\DeleteAccount;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;

final class DeleteAccount
{
    public function __construct(private readonly UserRepositoryInterface $users) {}

    public function execute(DeleteAccountInput $input): DeleteAccountOutput
    {
        $target = UserId::fromString($input->userId);
        if (!$input->acting->owns($target) && !$input->acting->isAdmin()) {
            throw new ForbiddenException();
        }

        $user = $this->users->findById($input->userId);
        if ($user === null) {
            return new DeleteAccountOutput(false, 'User not found.');
        }

        $this->users->deleteById($input->userId);
        return new DeleteAccountOutput(true);
    }
}
```

- [ ] **Step 20.5: Update `UserController::delete` to pass `ActingUser`**

```php
public function delete(Request $request, array $params): Response
{
    $id = $params['id'] ?? '';
    if ($id === '') {
        return Response::badRequest('User ID is required.');
    }
    $acting = $request->actingUser();
    if ($acting === null) {
        throw new \Daems\Domain\Auth\UnauthorizedException();
    }

    $output = $this->deleteAccount->execute(new DeleteAccountInput($acting, $id));

    if (!$output->deleted) {
        return Response::json(['error' => $output->error], 404);
    }
    return Response::json(['data' => ['deleted' => true]]);
}
```

- [ ] **Step 20.6: Run + commit**

```bash
./vendor/bin/phpunit
git add src/Application/User/DeleteAccount/ src/Infrastructure/Adapter/Api/Controller/UserController.php tests/Unit/Application/User/DeleteAccountTest.php
git commit -m "Enforce self-or-admin policy on DeleteAccount (F-001)"
```

---

### Task 21: `UpdateProfile` (F-002) + duplicate-email sanitisation (F-006 chain)

**Files:**
- Modify: `src/Application/User/UpdateProfile/UpdateProfile.php`
- Modify: `src/Application/User/UpdateProfile/UpdateProfileInput.php`
- Modify: `src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php`
- Create: `tests/Unit/Application/User/UpdateProfileTest.php`
- Modify: `src/Infrastructure/Adapter/Api/Controller/UserController.php`

**Policy:** self or admin.

**DTO change:** prepend `ActingUser $acting`. No fields dropped (UpdateProfile fields are legitimately all user-supplied — the *who* is derived, not the *what*).

**SQL adapter change:** catch `PDOException` with SQLSTATE `23000` and rethrow as `ValidationException('Invalid email.')`.

- [ ] **Step 21.1: Test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\UpdateProfile\UpdateProfile;
use Daems\Application\User\UpdateProfile\UpdateProfileInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class UpdateProfileTest extends TestCase
{
    private function input(ActingUser $a, string $userId, string $firstName = 'J', string $email = 'j@x.com'): UpdateProfileInput
    {
        return new UpdateProfileInput($a, $userId, $firstName, 'D', $email, '1990-01-01', 'US', '', '', '', '');
    }

    public function testSelfUpdate(): void
    {
        $repo = new InMemoryUserRepository();
        $id = UserId::generate();
        $out = (new UpdateProfile($repo))->execute($this->input(new ActingUser($id, 'registered'), $id->value()));
        $this->assertNull($out->error);
    }

    public function testUpdatingOtherForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryUserRepository();
        (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser(UserId::generate(), 'registered'), UserId::generate()->value()));
    }

    public function testAdminCanUpdateAnyone(): void
    {
        $repo = new InMemoryUserRepository();
        $out = (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser(UserId::generate(), 'admin'), UserId::generate()->value()));
        $this->assertNull($out->error);
    }

    public function testEmptyFirstNameReturnsValidationError(): void
    {
        $repo = new InMemoryUserRepository();
        $id = UserId::generate();
        $out = (new UpdateProfile($repo))
            ->execute($this->input(new ActingUser($id, 'registered'), $id->value(), firstName: ''));
        $this->assertNotNull($out->error);
    }
}
```

- [ ] **Step 21.2: Modify `UpdateProfileInput`**

Prepend `ActingUser $acting`:

```php
<?php

declare(strict_types=1);

namespace Daems\Application\User\UpdateProfile;

use Daems\Domain\Auth\ActingUser;

final class UpdateProfileInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $userId,
        public readonly string $firstName,
        public readonly string $lastName,
        public readonly string $email,
        public readonly string $dob,
        public readonly string $country,
        public readonly string $addressStreet,
        public readonly string $addressZip,
        public readonly string $addressCity,
        public readonly string $addressCountry,
    ) {}
}
```

- [ ] **Step 21.3: Enforce policy in `UpdateProfile::execute`**

Prepend:
```php
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\User\UserId;
```

Top of `execute`:
```php
$target = UserId::fromString($input->userId);
if (!$input->acting->owns($target) && !$input->acting->isAdmin()) {
    throw new ForbiddenException();
}
```

- [ ] **Step 21.4: Sanitise duplicate-email in `SqlUserRepository::updateProfile`**

Replace `updateProfile` implementation with:

```php
public function updateProfile(string $id, array $fields): void
{
    $allowed = ['name', 'email', 'date_of_birth', 'country',
                'address_street', 'address_zip', 'address_city', 'address_country'];
    $set = [];
    $params = [];
    foreach ($allowed as $col) {
        if (array_key_exists($col, $fields)) {
            $set[]    = "{$col} = ?";
            $params[] = $fields[$col];
        }
    }
    if ($set === []) {
        return;
    }
    $params[] = $id;
    try {
        $this->db->execute('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
    } catch (\PDOException $e) {
        if (str_starts_with((string) $e->getCode(), '23')) {
            throw new \Daems\Domain\Shared\ValidationException('Invalid email.');
        }
        throw $e;
    }
}
```

Apply the same pattern to `SqlUserRepository::save` for register-time duplicates.

- [ ] **Step 21.5: Update controller to pass acting user**

In `UserController::update`:
```php
$acting = $request->actingUser();
if ($acting === null) {
    throw new \Daems\Domain\Auth\UnauthorizedException();
}
// pass $acting as first arg to UpdateProfileInput
```

- [ ] **Step 21.6: Run + commit**

```bash
./vendor/bin/phpunit
git add src/Application/User/UpdateProfile/ src/Infrastructure/Adapter/Persistence/Sql/SqlUserRepository.php src/Infrastructure/Adapter/Api/Controller/UserController.php tests/Unit/Application/User/UpdateProfileTest.php
git commit -m "Enforce self/admin on UpdateProfile; sanitise duplicate email (F-002, F-006)"
```

---

### Task 22: `GetProfile` (F-003) — degrade, not reject

**Files:** `src/Application/User/GetProfile/*`, controller, test.

**Policy:** always return a value. Self or admin → full profile. Otherwise → reduced `{id, name}` only.

- [ ] **Step 22.1: Test**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\GetProfile\GetProfile;
use Daems\Application\User\GetProfile\GetProfileInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use PHPUnit\Framework\TestCase;

final class GetProfileTest extends TestCase
{
    private function seed(InMemoryUserRepository $repo): User
    {
        $u = new User(
            UserId::generate(), 'Jane Doe', 'j@x.com', password_hash('p', PASSWORD_BCRYPT), '1990-01-01',
            'registered', 'US', 'Street 1', '00000', 'City', 'US', 'individual', 'active', 'M-1', '2026-04-19',
        );
        $repo->save($u);
        return $u;
    }

    public function testSelfSeesFullProfile(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $acting = new ActingUser($u->id(), 'registered');

        $out = (new GetProfile($repo))->execute(new GetProfileInput($acting, $u->id()->value()));

        $this->assertNull($out->error);
        $this->assertArrayHasKey('dob', $out->profile);
        $this->assertArrayHasKey('address_street', $out->profile);
    }

    public function testAdminSeesFullProfile(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $admin = new ActingUser(UserId::generate(), 'admin');

        $out = (new GetProfile($repo))->execute(new GetProfileInput($admin, $u->id()->value()));
        $this->assertArrayHasKey('dob', $out->profile);
    }

    public function testOtherUserSeesReducedView(): void
    {
        $repo = new InMemoryUserRepository();
        $u = $this->seed($repo);
        $other = new ActingUser(UserId::generate(), 'registered');

        $out = (new GetProfile($repo))->execute(new GetProfileInput($other, $u->id()->value()));

        $this->assertNull($out->error);
        $this->assertSame(['id', 'name'], array_keys($out->profile));
        $this->assertArrayNotHasKey('dob', $out->profile);
        $this->assertArrayNotHasKey('address_street', $out->profile);
    }
}
```

- [ ] **Step 22.2: Modify `GetProfileInput`**

```php
<?php

declare(strict_types=1);

namespace Daems\Application\User\GetProfile;

use Daems\Domain\Auth\ActingUser;

final class GetProfileInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $userId,
    ) {}
}
```

- [ ] **Step 22.3: Rewrite `GetProfile::execute`**

```php
public function execute(GetProfileInput $input): GetProfileOutput
{
    $user = $this->users->findById($input->userId);
    if ($user === null) {
        return new GetProfileOutput(null, 'User not found.');
    }

    $self = $input->acting->owns($user->id()) || $input->acting->isAdmin();

    if (!$self) {
        return new GetProfileOutput([
            'id'   => $user->id()->value(),
            'name' => $user->name(),
        ]);
    }

    // full view — unchanged from existing implementation
    return new GetProfileOutput([/* all fields as before */]);
}
```

Preserve the original full-view keys from the existing implementation; just wrap it behind the `$self` branch.

- [ ] **Step 22.4: Update controller — pass acting user, always 200**

```php
public function profile(Request $request, array $params): Response
{
    $id = $params['id'] ?? '';
    if ($id === '') {
        return Response::badRequest('User ID is required.');
    }
    $acting = $request->actingUser();
    if ($acting === null) {
        throw new \Daems\Domain\Auth\UnauthorizedException();
    }
    $output = $this->getProfile->execute(new GetProfileInput($acting, $id));
    if ($output->error !== null) {
        return Response::json(['error' => $output->error], 404);
    }
    return Response::json(['data' => $output->profile]);
}
```

- [ ] **Step 22.5: Run + commit**

```bash
./vendor/bin/phpunit --filter GetProfileTest
git add src/Application/User/GetProfile/ src/Infrastructure/Adapter/Api/Controller/UserController.php tests/Unit/Application/User/GetProfileTest.php
git commit -m "Reduce PII view for non-self non-admin GetProfile (F-003)"
```

---

### Task 23: `GetUserActivity` (F-008)

**Policy:** self or admin. Deny all else.

- [ ] **Step 23.1: Test + implement per Task 20 template** using `ActingUser` prepend on input, `owns || isAdmin` guard, `ForbiddenException` on mismatch. Keep existing return shape for permitted callers.

- [ ] **Step 23.2: Controller passes `$acting`.**

- [ ] **Step 23.3: Commit**

```bash
git commit -m "Enforce self/admin on GetUserActivity (F-008)"
```

---

### Task 24: `ChangePassword` (no SAST finding — ownership always needed)

**Policy:** self only. Admin cannot reset passwords via this endpoint; that's a future "admin reset" feature.

Guard:
```php
if (!$input->acting->owns(UserId::fromString($input->userId))) {
    throw new ForbiddenException();
}
```

Add tests for self-success / other-forbidden / admin-forbidden. Commit:
```bash
git commit -m "Restrict ChangePassword to self"
```

---

### Task 25: `CreateProject` sets `owner_id` from acting user

**Files:** `CreateProjectInput`, `CreateProject`, `ProjectController::create`, `Domain\Project\Project`, `SqlProjectRepository`, test.

**Changes:**
1. `Project` entity gains optional `?UserId $ownerId` constructor arg + accessor.
2. `CreateProjectInput` prepends `ActingUser $acting` (first positional).
3. `CreateProject::execute` passes `$input->acting->id` to the `Project` constructor.
4. `SqlProjectRepository::save` inserts `owner_id` column.

Test cases:
- project is created with owner_id matching acting user
- anonymous caller → N/A (middleware rejects before here)

- [ ] **Step 25.1: Extend Project entity**

Add constructor param `?UserId $ownerId = null` and `public function ownerId(): ?UserId { return $this->ownerId; }`.

- [ ] **Step 25.2: Test + implement per Task 20 template**

- [ ] **Step 25.3: Modify SqlProjectRepository `save` to include `owner_id`**

- [ ] **Step 25.4: Commit**

```bash
git commit -m "CreateProject sets owner_id from acting user"
```

---

### Task 26: `UpdateProject`, `ArchiveProject`, `AddProjectUpdate` (F-004)

**Policy (all three):** `project.ownerId === acting.id OR acting.isAdmin()`. Legacy `ownerId IS NULL` → admin only.

Each use case: add `ActingUser $acting` to its Input, fetch the project via `ProjectRepositoryInterface::findBySlug` (add this method if missing), enforce policy, then existing mutation logic.

- [ ] **Step 26.1: Add `ProjectRepositoryInterface::findBySlug` if missing**

Check `src/Domain/Project/ProjectRepositoryInterface.php`. If `findBySlug` not present, add it. Update `SqlProjectRepository::findBySlug` to also load `owner_id`.

- [ ] **Step 26.2: Per-use-case: test + enforce policy**

Apply to each of the three. Example policy snippet (same in all):

```php
$project = $this->projects->findBySlug($input->slug);
if ($project === null) {
    return new SomeOutput(false, 'Project not found.');
}
$ownerId = $project->ownerId();
if ($ownerId === null) {
    if (!$input->acting->isAdmin()) {
        throw new ForbiddenException();
    }
} elseif (!$input->acting->owns($ownerId) && !$input->acting->isAdmin()) {
    throw new ForbiddenException();
}
```

Test matrix per use case:
- owner → 2xx
- non-owner → Forbidden
- admin non-owner → 2xx
- legacy NULL owner, non-admin → Forbidden
- legacy NULL owner, admin → 2xx
- slug not found → Output.error (NOT Forbidden — don't leak existence)

- [ ] **Step 26.3: `AddProjectUpdate` — also drop `author_name` from input DTO, derive from `users.findById(acting.id).name()`**

Drop `author_name` field. `AddProjectUpdate::execute` now fetches the acting user's name from the repository (inject `UserRepositoryInterface`).

- [ ] **Step 26.4: Commit**

```bash
git commit -m "Owner-or-admin policy on project mutations (F-004); derive author_name"
```

---

### Task 27: `AddProjectComment`, `LikeProjectComment` (F-007 identity derivation)

**DTO changes:**
- `AddProjectCommentInput`: drop `userId`, `authorName`. Keep `avatarInitials`, `avatarColor`, `content`. Prepend `ActingUser $acting`.
- `LikeProjectCommentInput`: prepend `ActingUser $acting` (no identity fields to drop).

**Use case:** `AddProjectComment` injects `UserRepositoryInterface`, looks up acting user's name, passes it as `authorName` to the repository save.

**Policy:** authenticated (handled by middleware). No per-object policy needed.

Tests:
- attacker-supplied `user_id` in request body is ignored (body only contains content + avatar); use-case saves with `acting.id`.
- stored comment's `user_id` matches `acting.id` even when a different id is attempted client-side (not possible after DTO change — prove compile-time).

- [ ] **Step 27.1-27.4: Test + implement + controller update + commit**

```bash
git commit -m "Derive user_id and author_name from acting user on project comment (F-007)"
```

---

### Task 28: `JoinProject`, `LeaveProject` (F-007)

**DTO change:** drop `userId` from both inputs; prepend `ActingUser $acting`.

**Policy:** each endpoint derives participant id from `acting.id`. **Explicit rule:** `LeaveProject` removes only the acting user's participation, never another user's.

Tests:
- `JoinProject`: acting user added as participant
- `LeaveProject`: only acting user is removed — attempting to pass another `userId` in body has no effect (compile-time impossible)

Commit:
```bash
git commit -m "Derive participant id from acting user on join/leave (F-007)"
```

---

### Task 29: `SubmitProjectProposal` (F-007)

**DTO change:** drop `userId`, `authorName`, `authorEmail`. Prepend `ActingUser $acting`.

Use case fetches acting user's name + email from `UserRepositoryInterface::findById`.

```bash
git commit -m "Derive proposer identity from acting user (F-007)"
```

---

### Task 30: `CreateForumTopic`, `CreateForumPost` (F-005) — role badge derivation

**DTO change (both):** drop `userId`, `authorName`, `avatarInitials`, `avatarColor`, `role`, `roleClass`, `joinedText`. Prepend `ActingUser $acting`.

Use case: fetch acting user's `role` from DB, derive:
- `role` column = human role label (`$user->role() === 'admin' ? 'Administrator' : 'Member'`)
- `role_class` = `'role-' . strtolower($user->role())`
- `joined_text` = `'Joined ' . substr($user->createdAt(), 0, 10)`
- `author_name` = `$user->name()`
- `avatar_initials` = initials from name
- `avatar_color` = derived or default (`'#64748b'`)

**Rule:** a user cannot post as "Administrator" unless `users.role === 'admin'`. Test this explicitly — post with body `{"role": "Administrator"}` (field doesn't even exist on DTO) and assert stored `role` matches users.role, not body.

```bash
git commit -m "Derive forum role badge from users.role (F-005)"
```

---

### Task 31: `LikeForumPost`, `IncrementTopicView`

- `LikeForumPost`: add `ActingUser $acting` (no per-object policy; middleware-authed only).
- `IncrementTopicView`: stays public (no change).

```bash
git commit -m "Pass acting user through LikeForumPost"
```

---

### Task 32: `RegisterForEvent`, `UnregisterFromEvent`

**DTO change:** drop `userId`; prepend `ActingUser $acting`.

Use case derives participant id from `acting.id`.

```bash
git commit -m "Derive event registrant from acting user"
```

---

### Task 33: `SubmitMemberApplication`, `SubmitSupporterApplication`

**DTO change:** drop any `userId` field; prepend `ActingUser $acting`. Use case fetches acting user's data from repository.

```bash
git commit -m "Derive applicant identity from acting user"
```

---

## Phase K — Wire middleware to routes

### Task 34: Attach `AuthMiddleware` and `RateLimitLoginMiddleware` per policy matrix

**Files:**
- Modify: `routes/api.php`

- [ ] **Step 34.1: Rewrite `routes/api.php` to pass middleware lists**

Import middleware at the top:

```php
use Daems\Infrastructure\Framework\Http\Middleware\AuthMiddleware;
use Daems\Infrastructure\Framework\Http\Middleware\RateLimitLoginMiddleware;
```

For every **non-public** route (per matrix in spec §4), pass `[AuthMiddleware::class]` as the third argument. Examples:

```php
$router->post('/api/v1/auth/login', static function (Request $req) use ($container): Response {
    return $container->make(AuthController::class)->login($req);
}, [RateLimitLoginMiddleware::class]);

$router->post('/api/v1/auth/logout', static function (Request $req) use ($container): Response {
    return $container->make(AuthController::class)->logout($req);
}, [AuthMiddleware::class]);

// GET lists and shows stay public:
$router->get('/api/v1/events', static function (Request $req) use ($container): Response {
    return $container->make(EventController::class)->index($req);
});
// (no middleware array — 3rd arg omitted)

// Delete user:
$router->post('/api/v1/users/{id}/delete', static function (Request $req, array $params) use ($container): Response {
    return $container->make(UserController::class)->delete($req, $params);
}, [AuthMiddleware::class]);
```

**Complete protected-route list** (apply `[AuthMiddleware::class]` to each):

- `POST /api/v1/auth/logout`
- `GET /api/v1/users/{id}`
- `POST /api/v1/users/{id}`
- `POST /api/v1/users/{id}/password`
- `GET /api/v1/users/{id}/activity`
- `POST /api/v1/users/{id}/delete`
- `POST /api/v1/projects`
- `POST /api/v1/projects/{slug}`
- `POST /api/v1/projects/{slug}/archive`
- `POST /api/v1/projects/{slug}/updates`
- `POST /api/v1/projects/{slug}/comments`
- `POST /api/v1/project-comments/{id}/like`
- `POST /api/v1/projects/{slug}/join`
- `POST /api/v1/projects/{slug}/leave`
- `POST /api/v1/project-proposals`
- `POST /api/v1/forum/categories/{slug}/topics`
- `POST /api/v1/forum/topics/{slug}/posts`
- `POST /api/v1/forum/posts/{id}/like`
- `POST /api/v1/events/{slug}/register`
- `POST /api/v1/events/{slug}/unregister`
- `POST /api/v1/applications/member`
- `POST /api/v1/applications/supporter`

**Login** gets only `[RateLimitLoginMiddleware::class]`. **Register** stays public. **All `GET` lists/shows + `POST /api/v1/forum/topics/{slug}/view`** stay public.

Add a new route entry: `POST /api/v1/auth/logout` (not currently registered).

- [ ] **Step 34.2: Run — expect all existing suites still pass**

```bash
./vendor/bin/phpunit
```

- [ ] **Step 34.3: Commit**

```bash
git add routes/api.php
git commit -m "Attach auth and rate-limit middleware to protected routes"
```

---

## Phase L — E2E PoC replays (one per finding)

### Task 35: E2E harness

**Files:**
- Create: `tests/Support/E2E/E2EHarness.php`
- Create: `tests/Support/E2E/TestDatabase.php`
- Create: `tests/E2E/SmokeTest.php` (proves harness works)

The harness constructs a test `Kernel` wired against a real MariaDB test database (schema bootstrapped from `database/migrations/`). Tests build `Request` objects and call `$kernel->handle($request)` directly — no HTTP server spun up.

- [ ] **Step 35.1: Write `TestDatabase`**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support\E2E;

use Daems\Infrastructure\Framework\Database\Connection;
use PDO;

final class TestDatabase
{
    private static ?Connection $connection = null;

    public static function connection(): Connection
    {
        if (self::$connection === null) {
            self::$connection = new Connection([
                'host'     => $_ENV['DB_TEST_HOST']     ?? '127.0.0.1',
                'port'     => $_ENV['DB_TEST_PORT']     ?? '3306',
                'database' => $_ENV['DB_TEST_DATABASE'] ?? 'daems_test',
                'username' => $_ENV['DB_TEST_USERNAME'] ?? 'root',
                'password' => $_ENV['DB_TEST_PASSWORD'] ?? '',
            ]);
        }
        return self::$connection;
    }

    public static function reset(): void
    {
        $pdo = self::connection()->pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        foreach ((array) $tables as $t) {
            $pdo->exec('DROP TABLE IF EXISTS `' . $t . '`');
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        self::applyMigrations();
    }

    private static function applyMigrations(): void
    {
        $pdo = self::connection()->pdo();
        $files = glob(dirname(__DIR__, 3) . '/database/migrations/*.sql');
        sort($files);
        foreach ($files as $file) {
            $pdo->exec((string) file_get_contents($file));
        }
    }
}
```

If `Connection::pdo()` accessor doesn't exist, add it:

```php
// in Connection
public function pdo(): \PDO { return $this->pdo; }
```

- [ ] **Step 35.2: Write `E2EHarness`**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Support\E2E;

use Daems\Application\Auth\CreateAuthToken\CreateAuthToken;
use Daems\Application\Auth\CreateAuthToken\CreateAuthTokenInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Domain\User\UserRepositoryInterface;
use Daems\Infrastructure\Framework\Container\Container;
use Daems\Infrastructure\Framework\Database\Connection;
use Daems\Infrastructure\Framework\Http\Kernel;
use Daems\Infrastructure\Framework\Http\Request;
use Daems\Infrastructure\Framework\Http\Response;

final class E2EHarness
{
    public Container $container;
    public Kernel $kernel;

    public function __construct()
    {
        // Override DB env so bootstrap uses the test DB.
        $_ENV['DB_HOST']     = $_ENV['DB_TEST_HOST']     ?? '127.0.0.1';
        $_ENV['DB_PORT']     = $_ENV['DB_TEST_PORT']     ?? '3306';
        $_ENV['DB_DATABASE'] = $_ENV['DB_TEST_DATABASE'] ?? 'daems_test';
        $_ENV['DB_USERNAME'] = $_ENV['DB_TEST_USERNAME'] ?? 'root';
        $_ENV['DB_PASSWORD'] = $_ENV['DB_TEST_PASSWORD'] ?? '';

        $kernel = require dirname(__DIR__, 3) . '/bootstrap/app.php';
        $this->kernel = $kernel;
        // bootstrap/app.php returns Kernel; get container via reflection or expose.
        // Simplest: bootstrap/app.php should return [$container, $kernel] or set a global.
        // For this plan, export container by modifying bootstrap to set a variable
        // accessible via a static getter — or simply duplicate the bindings here.
    }

    public function request(string $method, string $uri, array $body = [], array $headers = []): Response
    {
        return $this->kernel->handle(Request::forTesting(
            $method, $uri, [], $body, $headers, ['REMOTE_ADDR' => '127.0.0.1'],
        ));
    }

    public function createUser(string $email = 'user@x.com', string $password = 'pass1234', string $role = 'registered'): User
    {
        $u = new User(UserId::generate(), 'Test User', $email, password_hash($password, PASSWORD_BCRYPT), '1990-01-01', $role);
        $this->container->make(UserRepositoryInterface::class)->save($u);
        return $u;
    }

    public function tokenFor(User $user): string
    {
        $out = $this->container->make(CreateAuthToken::class)
            ->execute(new CreateAuthTokenInput($user->id(), 'e2e', '127.0.0.1'));
        return $out->rawToken;
    }
}
```

**Implementation note on container access:** change `bootstrap/app.php`'s final return to:

```php
return ['container' => $container, 'kernel' => new Kernel(
    $container,
    $container->make(LoggerInterface::class),
    ($_ENV['APP_DEBUG'] ?? 'false') === 'true',
)];
```

…and update `public/index.php` to unpack: `$app = require ...; ($app['kernel'])->send(($app['kernel'])->handle(Request::fromGlobals()));`

Then the harness does `$app = require bootstrap; $this->container = $app['container']; $this->kernel = $app['kernel'];`.

- [ ] **Step 35.3: Skip smoke test if DB env not set**

Each E2E test class adds:

```php
protected function setUp(): void
{
    if (!isset($_ENV['DB_TEST_HOST'])) {
        $this->markTestSkipped('Set DB_TEST_* env vars to run E2E tests.');
    }
    TestDatabase::reset();
    $this->harness = new E2EHarness();
}
```

- [ ] **Step 35.4: Commit**

```bash
git add tests/Support/E2E/ bootstrap/app.php public/index.php
git commit -m "Add E2E harness backed by real MariaDB test database"
```

---

### Tasks 36–45: One E2E test per SAST finding

Each task: write `tests/E2E/F0NN_*.php`. Run `./vendor/bin/phpunit --testsuite E2E` after each. Commit after each.

- [ ] **Task 36: F-001 — `tests/E2E/F001_UnauthDeletionTest.php`**

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Tests\Support\E2E\E2EHarness;
use Daems\Tests\Support\E2E\TestDatabase;
use PHPUnit\Framework\TestCase;

final class F001_UnauthDeletionTest extends TestCase
{
    private E2EHarness $h;

    protected function setUp(): void
    {
        if (!isset($_ENV['DB_TEST_HOST'])) {
            $this->markTestSkipped('DB_TEST_* env required.');
        }
        TestDatabase::reset();
        $this->h = new E2EHarness();
    }

    public function testAnonymousDeletionReturns401(): void
    {
        $victim = $this->h->createUser('victim@x.com');
        $resp = $this->h->request('POST', "/api/v1/users/{$victim->id()->value()}/delete");
        $this->assertSame(401, $resp->status());
    }

    public function testNonOwnerTokenReturns403(): void
    {
        $victim = $this->h->createUser('victim2@x.com');
        $attacker = $this->h->createUser('attacker@x.com');
        $token = $this->h->tokenFor($attacker);

        $resp = $this->h->request('POST', "/api/v1/users/{$victim->id()->value()}/delete", [], ['Authorization' => "Bearer {$token}"]);
        $this->assertSame(403, $resp->status());
    }

    public function testSelfCanDelete(): void
    {
        $u = $this->h->createUser('self@x.com');
        $token = $this->h->tokenFor($u);

        $resp = $this->h->request('POST', "/api/v1/users/{$u->id()->value()}/delete", [], ['Authorization' => "Bearer {$token}"]);
        $this->assertSame(200, $resp->status());
    }

    public function testAdminCanDeleteAnyone(): void
    {
        $victim = $this->h->createUser('victim3@x.com');
        $admin = $this->h->createUser('admin@x.com', 'adminpass', 'admin');
        $token = $this->h->tokenFor($admin);

        $resp = $this->h->request('POST', "/api/v1/users/{$victim->id()->value()}/delete", [], ['Authorization' => "Bearer {$token}"]);
        $this->assertSame(200, $resp->status());
    }
}
```

Commit: `git commit -m "E2E: F-001 unauth deletion regression test"`

- [ ] **Task 37: F-002 — `tests/E2E/F002_UnauthUpdateTest.php`**

Scenarios:
- anonymous POST → 401
- non-owner token → 403
- self → 200
- duplicate email → 400 with message NOT containing `SQLSTATE` / `Duplicate` / the attempted email

Commit: `git commit -m "E2E: F-002 unauth update + duplicate-email sanitisation"`

- [ ] **Task 38: F-003 — `tests/E2E/F003_UnauthPIIReadTest.php`**

Scenarios:
- anonymous → 401
- other-user token → 200 but body only has `{id, name}` (no `dob`, `address_*`)
- self → 200 with full profile
- admin → 200 with full profile

Commit: `git commit -m "E2E: F-003 PII read degrade"`

- [ ] **Task 39: F-004 — `tests/E2E/F004_UnauthProjectMutationTest.php`**

Create a project via an authenticated user. Then:
- anonymous archive/update/addUpdate → 401
- non-owner token → 403
- owner token → 200
- admin token → 200
- Directly INSERT a project with `owner_id = NULL` (simulating legacy), then:
  - non-admin token → 403
  - admin token → 200

Commit: `git commit -m "E2E: F-004 project mutation policy"`

- [ ] **Task 40: F-005 — `tests/E2E/F005_ForumRoleImpersonationTest.php`**

Create a non-admin user. Post a topic with body `{"content": "...", "role": "Administrator", "role_class": "role-admin"}`. Then fetch the topic and assert the stored `role` reflects `users.role` (e.g., `'Member'`), not the attacker's `'Administrator'` value.

Commit: `git commit -m "E2E: F-005 forum role badge derived from users.role"`

- [ ] **Task 41: F-006 — `tests/E2E/F006_ErrorSanitisationTest.php`**

Register `a@example.com`. Then POST `/api/v1/users/{any-id}` with body `{"email":"a@example.com"}` while authenticated as admin (so we pass policy and actually reach the SQL). Assert:
- status 400
- body does NOT contain `SQLSTATE`
- body does NOT contain `Duplicate entry`
- body does NOT contain `a@example.com`
- body contains exactly `{"error":"Invalid email."}`

Commit: `git commit -m "E2E: F-006 no SQL leak + no email enumeration oracle"`

- [ ] **Task 42: F-007 — `tests/E2E/F007_IdentitySpoofingTest.php`**

As Bob, POST a comment with body `{"user_id": "<alice-uuid>", "author_name": "Alice"}`. Fetch the project comments and assert stored `user_id` is Bob's and `author_name` is Bob's, not Alice's. Repeat for proposal/join/leave/update.

Commit: `git commit -m "E2E: F-007 identity fields derived from acting user"`

- [ ] **Task 43: F-008 — `tests/E2E/F008_UnauthActivityTest.php`**

- anonymous → 401
- other-user token → 403
- self → 200
- admin → 200

Commit: `git commit -m "E2E: F-008 activity log self/admin only"`

- [ ] **Task 44: F-009 — `tests/E2E/F009_LoginRateLimitTest.php`**

Loop: POST `/api/v1/auth/login` with wrong password 5 times from the same IP/email. Assert 6th attempt returns 429 + `Retry-After: 900`.

Note: this test's timing is independent of wall-clock — the `Clock` binding used by the middleware is `SystemClock`, which reads real time. For deterministic tests, override the `Clock` binding in the container via a test-only branch. Simplest: add a `Container::rebind()` method (or drop the binding override) and swap `SystemClock` for a `FrozenClock` in the harness setup. Alternative: rely on 5 requests within 15 minutes being deterministic enough on a fast test runner (acceptable — the real concern is ordering, not wall-time precision).

Commit: `git commit -m "E2E: F-009 login rate limit regression test"`

- [ ] **Task 45: F-010 — `tests/E2E/F010_BcryptTruncationTest.php`**

- POST `/api/v1/auth/register` with 73-byte password → 400
- POST with 72-byte password → 201

Commit: `git commit -m "E2E: F-010 72-byte password cap"`

- [ ] **Step L-final: Run full E2E suite**

```bash
./vendor/bin/phpunit --testsuite E2E
```
Expected: all 10 tests pass. (Or all skipped if `DB_TEST_HOST` unset — that's acceptable.)

---

## Phase M — Mutation testing (Infection)

### Task 46: Install and configure Infection

**Files:**
- Modify: `composer.json`
- Create: `infection.json.dist`

- [ ] **Step 46.1: Install**

```bash
composer require --dev infection/infection:^0.27
```

- [ ] **Step 46.2: Write `infection.json.dist`**

```json
{
    "$schema": "vendor/infection/infection/resources/schema.json",
    "source": {
        "directories": [
            "src/Application/Auth",
            "src/Application/User",
            "src/Application/Project",
            "src/Application/Forum",
            "src/Domain/Auth"
        ],
        "excludes": [
            "**/*Input.php",
            "**/*Output.php"
        ]
    },
    "testFrameworkOptions": "--testsuite=Unit,Integration",
    "logs": {
        "text": "build/infection.log",
        "summary": "build/infection-summary.log"
    },
    "minMsi": 85,
    "minCoveredMsi": 90,
    "mutators": {
        "@default": true
    }
}
```

- [ ] **Step 46.3: Add composer scripts**

In `composer.json`, update the `scripts` block:

```json
"scripts": {
    "test":          "vendor/bin/phpunit --testsuite=Unit,Integration",
    "test:e2e":      "vendor/bin/phpunit --testsuite=E2E",
    "test:mutation": "vendor/bin/infection --threads=4",
    "analyse":       "vendor/bin/phpstan analyse"
}
```

- [ ] **Step 46.4: Add `.gitignore` entry**

Append to `.gitignore`:
```
/build/
```

- [ ] **Step 46.5: Run locally, tune threshold if needed**

```bash
composer test:mutation
```

If the MSI falls below 85, the report shows which mutants escaped. **Do not lower the threshold** — either kill the mutants (add missing tests) or justify each survivor inline in `infection.json.dist` with a `profile` entry.

- [ ] **Step 46.6: Commit**

```bash
git add composer.json composer.lock infection.json.dist .gitignore
git commit -m "Configure Infection mutation testing with 85% MSI threshold"
```

---

## Phase N — Documentation

### Task 47: Update `docs/api.md` with auth flow

**Files:**
- Modify: `docs/api.md`

Document:
- `POST /api/v1/auth/register` — unchanged body, 72-byte password cap noted
- `POST /api/v1/auth/login` — new response shape `{user, token, expires_at}`; rate-limit 5 fails / 15 min / (ip,email); 429 + `Retry-After`
- `POST /api/v1/auth/logout` — `Authorization: Bearer <token>` required; 204
- "Authenticated requests" section: attach `Authorization: Bearer <token>`; 7-day sliding, 30-day cap
- Per-endpoint auth column in the routes table

Commit: `git commit -m "Document auth flow in docs/api.md"`

---

### Task 48: Append ADR-006..ADR-013 to `docs/decisions.md`

**Files:**
- Modify: `docs/decisions.md`

One ADR per AD in the spec:

- ADR-006: Opaque bearer tokens hashed in DB (AD-1)
- ADR-007: Middleware pipeline on Router (AD-2)
- ADR-008: Authorization in Application use cases via ActingUser (AD-3)
- ADR-009: 7-day sliding expiry with 30-day hard cap (AD-4)
- ADR-010: DB-based fixed-window rate limit (AD-5)
- ADR-011: Kernel error sanitisation (AD-6)
- ADR-012: Strip attacker-controlled identity fields from DTOs (AD-7)
- ADR-013: 72-byte password cap (AD-8)

Each ADR: Context / Decision / Consequences. Use the existing ADR-001..005 format from `docs/decisions.md`.

Commit: `git commit -m "Record ADR-006..ADR-013 for auth layer decisions"`

---

### Task 49: Update `docs/database.md` with new tables

**Files:**
- Modify: `docs/database.md`

Add schema documentation for `auth_tokens` and `auth_login_attempts`, and note the `projects.owner_id` column addition. Update any ASCII ERD if present.

Commit: `git commit -m "Document auth_tokens and auth_login_attempts schema"`

---

## Wrap-up

### Task 50: Full-suite verification

- [ ] **Step 50.1: Run everything**

```bash
./vendor/bin/phpunit                    # Unit + Integration
./vendor/bin/phpunit --testsuite E2E    # E2E (if DB_TEST_* set)
composer test:mutation                  # Infection
composer analyse                        # PHPStan (new files only)
```

All green. Expected counts:
- Unit: ~150+ tests
- Integration: ~20 tests
- E2E: 10 tests (or all skipped)
- Infection: MSI ≥ 85, Covered MSI ≥ 90

- [ ] **Step 50.2: Regenerate `composer.lock` checksums** (if any dev deps changed)

```bash
composer validate --strict
```

- [ ] **Step 50.3: Final review**

Spot-check:
- `grep -rn 'Authorization' src/` — confirm middleware + AuthController are the only touches
- `grep -rn 'SQLSTATE' src/Infrastructure/Framework/Http/Kernel.php` — must return nothing
- `grep -rn 'user_id' src/Application/Forum/` — must return nothing (F-005 / F-007)
- `grep -rn 'owner_id' src/Infrastructure/Adapter/Persistence/Sql/SqlProjectRepository.php` — must appear in save + hydrate
- `grep -rn 'strlen.*> 72' src/Application/` — three hits: LoginUser, RegisterUser, ChangePassword (F-010)

- [ ] **Step 50.4: Push branch**

```bash
git push -u origin fix/sast-auth-remediation
```

Open PR. Title: `Add auth layer + SAST remediation (F-001..F-010)`. Body should reference the spec at `docs/superpowers/specs/2026-04-19-auth-and-sast-remediation-design.md`.
