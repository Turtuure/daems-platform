# PHPStan Level 9 Baseline Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Raise PHPStan from level 6 to level 9 across the whole codebase (0 errors, no baseline file), and add typed accessor helpers for `Request` and a new `Session` wrapper class that later tasks depend on.

**Architecture:** Stepwise level raise (6 → 7 → 8 → 9) with separate commits per level. Mechanical fixes driven by PHPStan output. Two new typed-access helpers to eliminate `mixed`-typed superglobal usage.

**Tech Stack:** PHP 8.1+, PHPStan 2.x (already installed), PHPUnit 10, Infection mutation testing.

**Spec:** `docs/superpowers/specs/2026-04-19-DS_UPGRADE.md` section 8 (PHPStan level 9 baseline).

**PR target:** PR 1 of 3.

---

## File Structure

**Created:**
- `src/Infrastructure/Framework/Session/Session.php` — typed session wrapper
- `src/Infrastructure/Framework/Session/SessionInterface.php` — interface for testability
- `tests/Unit/Framework/Http/RequestTypedAccessorsTest.php`
- `tests/Unit/Framework/Session/SessionTest.php`

**Modified:**
- `src/Infrastructure/Framework/Http/Request.php` — add typed accessors (`string`, `int`, `bool`, `arrayValue`) + `withAttribute`/`attribute`
- `phpstan.neon` — bump level 6 → 7 → 8 → 9 in sequence
- Various `src/**/*.php` files — null-safety fixes, mixed narrowing, type cleanups (exact files discovered by PHPStan)
- `bootstrap/app.php` — register Session service if needed by helpers
- `docs/decisions.md` — append ADR-015

---

## Task 1: Add `withAttribute`/`attribute` to Request

Per-request scalar/object attribute bag — needed by `TenantContextMiddleware` (PR 2) but easier to add now with the other Request changes. Immutable API (returns new Request instance).

**Files:**
- Modify: `src/Infrastructure/Framework/Http/Request.php`
- Test: `tests/Unit/Framework/Http/RequestAttributesTest.php`

- [ ] **Step 1.1: Write the failing test**

Create `tests/Unit/Framework/Http/RequestAttributesTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Framework\Http;

use Daems\Infrastructure\Framework\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestAttributesTest extends TestCase
{
    public function test_attribute_returns_null_when_not_set(): void
    {
        $req = Request::forTesting('GET', '/test');
        self::assertNull($req->attribute('missing'));
    }

    public function test_with_attribute_returns_new_instance(): void
    {
        $req = Request::forTesting('GET', '/test');
        $req2 = $req->withAttribute('key', 'value');

        self::assertNotSame($req, $req2);
        self::assertNull($req->attribute('key'));
        self::assertSame('value', $req2->attribute('key'));
    }

    public function test_with_attribute_preserves_other_attributes(): void
    {
        $req = Request::forTesting('GET', '/test')
            ->withAttribute('a', 1)
            ->withAttribute('b', 2);

        self::assertSame(1, $req->attribute('a'));
        self::assertSame(2, $req->attribute('b'));
    }

    public function test_attribute_supports_object_values(): void
    {
        $obj = new \stdClass();
        $req = Request::forTesting('GET', '/test')->withAttribute('obj', $obj);

        self::assertSame($obj, $req->attribute('obj'));
    }
}
```

- [ ] **Step 1.2: Run the test, verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Framework/Http/RequestAttributesTest.php`
Expected: FAIL with "method attribute does not exist" or similar.

- [ ] **Step 1.3: Add attribute support to Request**

In `src/Infrastructure/Framework/Http/Request.php`, modify the constructor and add two methods:

Update constructor signature (add `$attributes` parameter):
```php
private function __construct(
    private readonly string $method,
    private readonly string $uri,
    private readonly array $query,
    private readonly array $body,
    private readonly array $headers,
    private readonly array $server,
    private readonly ?ActingUser $actingUser = null,
    /** @var array<string, mixed> */
    private readonly array $attributes = [],
) {}
```

Update `fromGlobals()` and `forTesting()` to pass empty array for attributes (they already call `new self(...)` — no change needed if PHP default takes over; verify).

Add the new methods before `clientIp()`:
```php
public function attribute(string $key): mixed
{
    return $this->attributes[$key] ?? null;
}

public function withAttribute(string $key, mixed $value): self
{
    return new self(
        $this->method,
        $this->uri,
        $this->query,
        $this->body,
        $this->headers,
        $this->server,
        $this->actingUser,
        [...$this->attributes, $key => $value],
    );
}
```

Update `withActingUser()` to preserve attributes:
```php
public function withActingUser(ActingUser $user): self
{
    return new self(
        $this->method, $this->uri, $this->query, $this->body,
        $this->headers, $this->server, $user, $this->attributes,
    );
}
```

- [ ] **Step 1.4: Run the test, verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Framework/Http/RequestAttributesTest.php`
Expected: PASS (4 tests).

- [ ] **Step 1.5: Commit**

```bash
git add src/Infrastructure/Framework/Http/Request.php tests/Unit/Framework/Http/RequestAttributesTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Framework: add Request::withAttribute/attribute for middleware context passing"
```

---

## Task 2: Add typed accessors to Request

`string()`, `int()`, `bool()`, `arrayValue()` — narrow `mixed` from `$body`/`$query` to strict types at the read site. Required for level 9 cleanup.

**Files:**
- Modify: `src/Infrastructure/Framework/Http/Request.php`
- Test: `tests/Unit/Framework/Http/RequestTypedAccessorsTest.php`

- [ ] **Step 2.1: Write the failing tests**

Create `tests/Unit/Framework/Http/RequestTypedAccessorsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Framework\Http;

use Daems\Infrastructure\Framework\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTypedAccessorsTest extends TestCase
{
    // --- string() ---

    public function test_string_returns_body_value_as_string(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['email' => 'a@b']);
        self::assertSame('a@b', $req->string('email'));
    }

    public function test_string_casts_non_string_scalar(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['id' => 42]);
        self::assertSame('42', $req->string('id'));
    }

    public function test_string_returns_default_when_missing(): void
    {
        $req = Request::forTesting('POST', '/t');
        self::assertSame('fallback', $req->string('missing', 'fallback'));
        self::assertNull($req->string('missing'));
    }

    public function test_string_returns_default_when_array_or_null(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['nested' => ['x' => 1], 'nothing' => null]);
        self::assertNull($req->string('nested'));
        self::assertNull($req->string('nothing'));
    }

    // --- int() ---

    public function test_int_returns_int_from_numeric(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['age' => '18', 'score' => 100]);
        self::assertSame(18, $req->int('age'));
        self::assertSame(100, $req->int('score'));
    }

    public function test_int_returns_default_when_not_numeric(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['x' => 'abc']);
        self::assertSame(0, $req->int('x', 0));
        self::assertNull($req->int('missing'));
    }

    // --- bool() ---

    public function test_bool_true_values(): void
    {
        foreach (['1', 'true', 'on', 'yes', true, 1] as $truthy) {
            $req = Request::forTesting('POST', '/t', body: ['v' => $truthy]);
            self::assertTrue($req->bool('v'), "expected true for " . var_export($truthy, true));
        }
    }

    public function test_bool_false_values(): void
    {
        foreach (['0', 'false', 'off', 'no', false, 0] as $falsy) {
            $req = Request::forTesting('POST', '/t', body: ['v' => $falsy]);
            self::assertFalse($req->bool('v'), "expected false for " . var_export($falsy, true));
        }
    }

    public function test_bool_returns_default_when_missing(): void
    {
        $req = Request::forTesting('POST', '/t');
        self::assertFalse($req->bool('missing', false));
        self::assertTrue($req->bool('missing', true));
        self::assertNull($req->bool('missing'));
    }

    // --- arrayValue() ---

    public function test_arrayValue_returns_array(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['list' => [1, 2, 3]]);
        self::assertSame([1, 2, 3], $req->arrayValue('list'));
    }

    public function test_arrayValue_returns_null_when_not_array(): void
    {
        $req = Request::forTesting('POST', '/t', body: ['scalar' => 'x']);
        self::assertNull($req->arrayValue('scalar'));
        self::assertNull($req->arrayValue('missing'));
    }
}
```

- [ ] **Step 2.2: Run the tests, verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Framework/Http/RequestTypedAccessorsTest.php`
Expected: FAIL — methods don't exist.

- [ ] **Step 2.3: Add the typed accessors to Request**

Add these methods to `src/Infrastructure/Framework/Http/Request.php` after `input()`:

```php
public function string(string $key, ?string $default = null): ?string
{
    $v = $this->body[$key] ?? $this->query[$key] ?? null;
    if ($v === null) {
        return $default;
    }
    if (is_scalar($v)) {
        return (string) $v;
    }
    return $default;
}

public function int(string $key, ?int $default = null): ?int
{
    $v = $this->body[$key] ?? $this->query[$key] ?? null;
    if ($v === null) {
        return $default;
    }
    if (is_int($v)) {
        return $v;
    }
    if (is_string($v) && is_numeric($v)) {
        return (int) $v;
    }
    return $default;
}

public function bool(string $key, ?bool $default = null): ?bool
{
    $v = $this->body[$key] ?? $this->query[$key] ?? null;
    if ($v === null) {
        return $default;
    }
    if (is_bool($v)) {
        return $v;
    }
    if (is_int($v)) {
        return $v !== 0;
    }
    if (is_string($v)) {
        return match (strtolower($v)) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no', '' => false,
            default => $default,
        };
    }
    return $default;
}

/**
 * @return array<array-key, mixed>|null
 */
public function arrayValue(string $key): ?array
{
    $v = $this->body[$key] ?? $this->query[$key] ?? null;
    return is_array($v) ? $v : null;
}
```

- [ ] **Step 2.4: Run the tests, verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Framework/Http/RequestTypedAccessorsTest.php`
Expected: PASS (all tests).

- [ ] **Step 2.5: Commit**

```bash
git add src/Infrastructure/Framework/Http/Request.php tests/Unit/Framework/Http/RequestTypedAccessorsTest.php
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Framework: add typed accessors to Request (string, int, bool, arrayValue)"
```

---

## Task 3: Create Session wrapper

New `Session` class wrapping `$_SESSION` with typed accessors. Non-static for testability.

**Files:**
- Create: `src/Infrastructure/Framework/Session/SessionInterface.php`
- Create: `src/Infrastructure/Framework/Session/Session.php`
- Create: `src/Infrastructure/Framework/Session/ArraySession.php` (test double)
- Create: `tests/Unit/Framework/Session/SessionTest.php`

- [ ] **Step 3.1: Write the failing tests**

Create `tests/Unit/Framework/Session/SessionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Framework\Session;

use Daems\Infrastructure\Framework\Session\ArraySession;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    public function test_string_returns_stored_string(): void
    {
        $s = new ArraySession(['name' => 'Sam']);
        self::assertSame('Sam', $s->string('name'));
    }

    public function test_string_returns_default_when_missing(): void
    {
        $s = new ArraySession();
        self::assertSame('x', $s->string('missing', 'x'));
        self::assertNull($s->string('missing'));
    }

    public function test_string_returns_default_when_not_string(): void
    {
        $s = new ArraySession(['n' => 42, 'a' => ['x']]);
        self::assertSame('fallback', $s->string('n', 'fallback'));
        self::assertNull($s->string('a'));
    }

    public function test_array_returns_stored_array(): void
    {
        $s = new ArraySession(['user' => ['id' => '1', 'name' => 'Sam']]);
        self::assertSame(['id' => '1', 'name' => 'Sam'], $s->array('user'));
    }

    public function test_array_returns_null_when_not_array(): void
    {
        $s = new ArraySession(['x' => 'scalar']);
        self::assertNull($s->array('x'));
        self::assertNull($s->array('missing'));
    }

    public function test_set_stores_value(): void
    {
        $s = new ArraySession();
        $s->set('k', 'v');
        self::assertSame('v', $s->string('k'));
    }

    public function test_unset_removes_key(): void
    {
        $s = new ArraySession(['k' => 'v']);
        $s->unset('k');
        self::assertNull($s->string('k'));
    }

    public function test_has_returns_true_when_key_exists(): void
    {
        $s = new ArraySession(['k' => null]);
        self::assertTrue($s->has('k'));
        self::assertFalse($s->has('missing'));
    }
}
```

- [ ] **Step 3.2: Run the tests, verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Framework/Session/SessionTest.php`
Expected: FAIL — classes don't exist.

- [ ] **Step 3.3: Write SessionInterface**

Create `src/Infrastructure/Framework/Session/SessionInterface.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Session;

interface SessionInterface
{
    public function string(string $key, ?string $default = null): ?string;

    public function int(string $key, ?int $default = null): ?int;

    public function bool(string $key, ?bool $default = null): ?bool;

    /**
     * @return array<array-key, mixed>|null
     */
    public function array(string $key): ?array;

    public function has(string $key): bool;

    public function set(string $key, mixed $value): void;

    public function unset(string $key): void;
}
```

- [ ] **Step 3.4: Write ArraySession (test double)**

Create `src/Infrastructure/Framework/Session/ArraySession.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Session;

final class ArraySession implements SessionInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = []) {}

    public function string(string $key, ?string $default = null): ?string
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_string($v) ? $v : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        $v = $this->data[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_bool($v) ? $v : $default;
    }

    public function array(string $key): ?array
    {
        $v = $this->data[$key] ?? null;
        return is_array($v) ? $v : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function unset(string $key): void
    {
        unset($this->data[$key]);
    }
}
```

- [ ] **Step 3.5: Write Session (production, reads/writes $_SESSION)**

Create `src/Infrastructure/Framework/Session/Session.php`:

```php
<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Framework\Session;

use RuntimeException;

final class Session implements SessionInterface
{
    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            throw new RuntimeException('Session not started — call session_start() first.');
        }
    }

    public function string(string $key, ?string $default = null): ?string
    {
        $v = $_SESSION[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_string($v) ? $v : $default;
    }

    public function int(string $key, ?int $default = null): ?int
    {
        $v = $_SESSION[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (int) $v;
        }
        return $default;
    }

    public function bool(string $key, ?bool $default = null): ?bool
    {
        $v = $_SESSION[$key] ?? null;
        if ($v === null) {
            return $default;
        }
        return is_bool($v) ? $v : $default;
    }

    public function array(string $key): ?array
    {
        $v = $_SESSION[$key] ?? null;
        return is_array($v) ? $v : null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function unset(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
```

- [ ] **Step 3.6: Run the tests, verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Framework/Session/SessionTest.php`
Expected: PASS (8 tests).

- [ ] **Step 3.7: Commit**

```bash
git add src/Infrastructure/Framework/Session/ tests/Unit/Framework/Session/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Framework: add Session wrapper with typed accessors (production + array test double)"
```

---

## Task 4: Raise PHPStan to level 7 and fix errors

Level 7 adds union-type strictness and array-offset-access checks. Likely 30–60 errors across the codebase. Fix them mechanically.

**Files:**
- Modify: `phpstan.neon`
- Modify: various `src/**/*.php` files (determined by PHPStan output)

- [ ] **Step 4.1: Bump level to 7**

Edit `phpstan.neon`:
```yaml
parameters:
    level: 7
    paths:
        - src
    phpVersion: 80100
    ignoreErrors:
        -
            identifier: missingType.iterableValue
```

- [ ] **Step 4.2: Run PHPStan and capture errors**

Run: `composer analyse 2>&1 | tee phpstan-level7.log`

Count errors: check the final summary line (e.g., `[ERROR] Found 42 errors`).

- [ ] **Step 4.3: Fix errors by pattern**

Common patterns to apply (each error category gets its own fix pass):

**Array offset access on possibly-missing key:**
```php
// Before (error: "Offset 'x' on array ... in isset() always exists"):
$name = $data['name'] ?? 'default';

// After (when PHPDoc knows type):
/** @var array{name?: string} $data */
$name = $data['name'] ?? 'default';
```

**Partially wrong union return:**
```php
// Before:
function find(int $id): User|false { ... }

// After (prefer nullable):
function find(int $id): ?User { ... }
```

**Array shape annotation for dynamic arrays:**
```php
/** @param array<string, string|int> $attrs */
public function render(array $attrs): string
```

- [ ] **Step 4.4: Verify 0 errors**

Run: `composer analyse`
Expected: `[OK] No errors`.

- [ ] **Step 4.5: Run the test suite to confirm no regression**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 4.6: Commit**

```bash
git add phpstan.neon src/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Chore: raise PHPStan to level 7, fix union and array-offset issues"
```

---

## Task 5: Raise PHPStan to level 8 and fix errors

Level 8 adds null-safety. This is the biggest batch (80–150 errors expected) — lots of `?Entity` chains in the codebase.

**Files:**
- Modify: `phpstan.neon`
- Modify: various `src/**/*.php` files

- [ ] **Step 5.1: Bump level to 8**

Edit `phpstan.neon`: `level: 8`

- [ ] **Step 5.2: Run PHPStan and capture errors**

Run: `composer analyse 2>&1 | tee phpstan-level8.log`
Record error count.

- [ ] **Step 5.3: Fix errors by pattern — batch 1 (early-return)**

For each error of the form "Cannot call method X() on SomeType|null":

**Pattern A — early-return with domain exception:**
```php
// Before:
public function execute(Input $input): Output
{
    $project = $this->repo->findBySlug($input->slug);
    return new Output($project->getTitle());   // ERROR: $project possibly null
}

// After:
public function execute(Input $input): Output
{
    $project = $this->repo->findBySlug($input->slug);
    if ($project === null) {
        throw new ProjectNotFoundException();
    }
    return new Output($project->getTitle());
}
```

**Pattern B — coalesce-throw (PHP 8.0+):**
```php
$project = $this->repo->findBySlug($input->slug)
    ?? throw new ProjectNotFoundException();
return new Output($project->getTitle());
```

Use Pattern B when there's no other logic needed before the check. Pattern A when you need branching (e.g., "if not found, create one").

- [ ] **Step 5.4: Fix errors by pattern — batch 2 (assert for invariants)**

For cases where null is logically impossible but PHPStan can't prove it:

```php
// Before:
$user = $this->session->get('user');
$id = $user['id'];   // ERROR: $user possibly null

// After (when AuthMiddleware guarantees session is populated):
$user = $this->session->array('user');
assert($user !== null, 'session user present (enforced by AuthMiddleware)');
$id = $user['id'];
```

Assertions are stripped in production but keep PHPStan happy.

- [ ] **Step 5.5: Verify 0 errors**

Run: `composer analyse`
Expected: `[OK] No errors`.

- [ ] **Step 5.6: Run the test suite**

Run: `composer test`
Expected: all tests pass.

- [ ] **Step 5.7: Commit**

```bash
git add phpstan.neon src/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Chore: raise PHPStan to level 8, add null-safety checks"
```

---

## Task 6: Raise PHPStan to level 9 and fix errors

Level 9 adds strict `mixed` handling. Most common fix: replace raw `$_POST`, `$_SESSION`, `$request->input()` with typed accessors from Tasks 2+3.

**Files:**
- Modify: `phpstan.neon`
- Modify: various `src/**/*.php` files

- [ ] **Step 6.1: Bump level to 9**

Edit `phpstan.neon`: `level: 9`

- [ ] **Step 6.2: Run PHPStan and capture errors**

Run: `composer analyse 2>&1 | tee phpstan-level9.log`
Record error count (expect 100–200+).

- [ ] **Step 6.3: Fix errors by pattern — batch 1 (Request input narrowing)**

Replace untyped `input()`/`query()` calls with typed accessors:

```php
// Before:
$email = $req->input('email');              // mixed
$data = ['email' => $email];                // ERROR at level 9 if $data typed
$user = $this->svc->login($email, $pw);     // ERROR: expected string

// After:
$email = $req->string('email', '');         // string (non-null)
$pw    = $req->string('password', '');
$user  = $this->svc->login($email, $pw);    // OK
```

For array bodies:
```php
// Before:
$roles = $req->input('roles');              // mixed
foreach ($roles as $r) { ... }              // ERROR

// After:
$roles = $req->arrayValue('roles') ?? [];   // array
foreach ($roles as $r) {
    if (!is_string($r)) continue;
    // ...
}
```

- [ ] **Step 6.4: Fix errors by pattern — batch 2 (session narrowing)**

Replace raw `$_SESSION` access with `Session` accessor:

```php
// Before:
if ($_SESSION['user']['role'] === 'admin') { ... }   // mixed

// After:
$session = new Session();
$userArr = $session->array('user') ?? [];
$role    = is_string($userArr['role'] ?? null) ? $userArr['role'] : null;
if ($role === 'admin') { ... }
```

Controllers that access `$_SESSION` directly should receive `SessionInterface` via constructor injection after this task — if encountered, add the dependency in the constructor and wire it in `bootstrap/app.php`.

- [ ] **Step 6.5: Fix errors by pattern — batch 3 (json_decode narrowing)**

```php
// Before:
$data = json_decode($raw, true);            // mixed
$name = $data['name'];                      // ERROR: $data is mixed

// After:
$decoded = json_decode($raw, true);
$data = is_array($decoded) ? $decoded : [];
$name = is_string($data['name'] ?? null) ? $data['name'] : '';
```

- [ ] **Step 6.6: Fix errors by pattern — batch 4 (mixed return from DB row)**

Repository SQL methods that do `$row['col']` on `mixed`:

```php
// Before:
return new User($row['id'], $row['email']);      // mixed values

// After:
/** @var array<string, mixed> $row */
return new User(
    is_string($row['id'])    ? $row['id']    : throw new DomainException('Corrupt user row'),
    is_string($row['email']) ? $row['email'] : throw new DomainException('Corrupt user row'),
);
```

For repeated patterns, extract a small helper:
```php
/** @param array<string,mixed> $row */
private static function str(array $row, string $key): string
{
    return is_string($row[$key] ?? null) ? $row[$key] : throw new DomainException("Missing or non-string column: $key");
}
```

- [ ] **Step 6.7: Verify 0 errors**

Run: `composer analyse`
Expected: `[OK] No errors`.

- [ ] **Step 6.8: Run the full test suite**

Run: `composer test && composer test:e2e && composer test:mutation`
Expected: all green. Mutation MSI must stay ≥ 85 %.

- [ ] **Step 6.9: Delete temporary log files**

```bash
rm -f phpstan-level7.log phpstan-level8.log phpstan-level9.log
```

- [ ] **Step 6.10: Commit**

```bash
git add phpstan.neon src/
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Chore: raise PHPStan to level 9, narrow mixed types throughout"
```

---

## Task 7: Update CI to enforce level 9

Ensure GitHub Actions (or whatever CI is in use) runs `composer analyse` at the new level.

**Files:**
- Modify: `.github/workflows/*.yml` (if present)
- Modify: `composer.json` — add `analyse:strict` script that fails on any error

- [ ] **Step 7.1: Check existing CI config**

Run: `ls .github/workflows/ 2>&1`

If workflows exist, read them to see if they already call `composer analyse`. If they do, no change needed (level is read from `phpstan.neon`).

- [ ] **Step 7.2: If no CI call for analyse, add it**

If missing, add to the CI workflow (example for GitHub Actions):

```yaml
      - name: PHPStan
        run: composer analyse -- --error-format=github
```

- [ ] **Step 7.3: Commit**

```bash
git add .github/ composer.json
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "CI: enforce PHPStan level 9 in pipeline"
```

(Skip this task if CI already runs `composer analyse`.)

---

## Task 8: Write ADR-015 for PHPStan level 9 baseline

Document the decision in the architecture decision record log.

**Files:**
- Modify: `docs/decisions.md` (append ADR)

- [ ] **Step 8.1: Append ADR-015 to docs/decisions.md**

```markdown

## ADR-015: PHPStan Level 9 Baseline

**Status:** Accepted — 2026-04-19
**Context:** Codebase was at PHPStan level 6 (basic typing). As the codebase grew, null-chain bugs and untyped `mixed` usage from superglobals became a recurring source of defects.
**Decision:** Raise PHPStan baseline to level 9 across the entire codebase (0 errors, no baseline file). New code must be level-9 clean. Add typed accessors to `Request` and a new `Session` wrapper to replace raw superglobal access.
**Consequences:**
- All `mixed` usage must be narrowed at read sites.
- `Request::string()`/`int()`/`bool()`/`arrayValue()` replace `input()`/`query()` in most controllers.
- `SessionInterface` + `Session` class wraps `$_SESSION` — controllers that need session data receive it via DI.
- Null-chain bugs are caught by static analysis, not production.
- CI enforces level 9; merging requires `composer analyse` to report 0 errors.
```

- [ ] **Step 8.2: Commit**

```bash
git add docs/decisions.md
git -c user.name="Dev Team" -c user.email="dev@daems.org" commit -m "Docs: ADR-015 — PHPStan level 9 baseline"
```

---

## Task 9: Final verification and PR prep

- [ ] **Step 9.1: Run all tests and analyse**

```bash
composer test
composer analyse
composer test:mutation
```

All must pass. MSI ≥ 85 %.

- [ ] **Step 9.2: Review git log**

```bash
git log --oneline origin/dev..HEAD
```

Expected commits in this PR (order):
1. Framework: add Request::withAttribute/attribute ...
2. Framework: add typed accessors to Request ...
3. Framework: add Session wrapper ...
4. Chore: raise PHPStan to level 7 ...
5. Chore: raise PHPStan to level 8 ...
6. Chore: raise PHPStan to level 9 ...
7. (optional) CI: enforce PHPStan level 9 ...
8. Docs: ADR-015 — PHPStan level 9 baseline

- [ ] **Step 9.3: Ask user for push approval**

Do NOT push automatically. Show the user the commit log (step 9.2) and ask:
> "PR 1 (PHPStan level 9 baseline) on valmis. X committia dev-haarassa paikallisesti. Pushaanko origin/dev:hen?"

If approved: `git push origin dev`. Otherwise, leave for user to push.

---

## Completion criteria

- [ ] `composer analyse` → 0 errors at level 9, no `phpstan-baseline.neon` file
- [ ] `composer test` → green
- [ ] `composer test:mutation` → MSI ≥ 85 %
- [ ] `Request` has `attribute`, `withAttribute`, `string`, `int`, `bool`, `arrayValue` methods
- [ ] `Session` + `SessionInterface` + `ArraySession` exist with 100 % test coverage
- [ ] ADR-015 committed
- [ ] Commits follow project convention ("Dev Team" author, `-m` with Feat/Chore/Docs prefix)
- [ ] No merge conflicts against `origin/dev`
