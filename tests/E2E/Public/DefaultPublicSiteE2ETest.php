<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Public;

use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use Daems\Frontend\I18n;
use Daems\Infrastructure\Framework\Container\Container;
use DaemsModule\Members\Application\Membership\SubmitMemberApplication\SubmitMemberApplication;
use DaemsModule\Members\Tests\Support\InMemoryMemberApplicationRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Default public-site fallback pages — the bundle served when a tenant has
 * no custom Laragon site at C:\laragon\www\sites\<slug>\public\.
 *
 * NOTE on dispatch path: `public/sites-router.php` is a CGI front controller
 * (it `require`s page files after side-effect-loading the kernel). Its
 * page templates expect `$GLOBALS['_default_site_tenant']` /
 * `$GLOBALS['_default_site_locale']` and call `\Daems\Frontend\I18n::t()`
 * for every translatable string. This test invokes the page templates
 * directly with the same globals and a pre-loaded i18n dictionary, then
 * captures their output via output buffering.
 *
 * `login.php` calls `header() + exit;` which is incompatible with the
 * test harness's CLI environment, so its behaviour is verified by
 * inspecting the file contents instead of executing it.
 */
final class DefaultPublicSiteE2ETest extends TestCase
{
    private const DEFAULT_DIR = __DIR__ . '/../../../public/sites/_default';

    /** @var array<string, mixed> backup of $GLOBALS keys this test mutates */
    private array $globalsBackup = [];

    protected function setUp(): void
    {
        // Capture globals so we can restore between tests.
        foreach (['_default_site_tenant', '_default_site_locale', '_default_site_container'] as $k) {
            $this->globalsBackup[$k] = $GLOBALS[$k] ?? null;
        }

        // Load the en_GB platform-wide dict and merge the default-site
        // dict the same way sites-router.php does. We use 'en_GB' so the
        // tests are stable regardless of host locale negotiation.
        $extra = require self::DEFAULT_DIR . '/lang/en_GB.php';
        if (is_array($extra)) {
            /** @var array<string, string> $extra */
            I18n::merge('en_GB', $extra);
        }

        // Lock the locale so I18n::locale() doesn't try to negotiate from
        // $_SERVER (which is empty in CLI). We do this via reflection
        // because $locale is private static.
        $ref = new \ReflectionClass(I18n::class);
        $prop = $ref->getProperty('locale');
        $prop->setAccessible(true);
        $prop->setValue(null, 'en_GB');
    }

    protected function tearDown(): void
    {
        foreach ($this->globalsBackup as $k => $v) {
            if ($v === null) {
                unset($GLOBALS[$k]);
            } else {
                $GLOBALS[$k] = $v;
            }
        }

        // Reset locale.
        $ref = new \ReflectionClass(I18n::class);
        $prop = $ref->getProperty('locale');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function makeTenant(
        string $slug = 'fallback-tenant',
        ?string $displayName = 'Fallback Tenant',
        bool $suspended = false,
        ?string $reason = null,
    ): Tenant {
        $now = new DateTimeImmutable('2026-05-07T10:00:00+00:00');
        return new Tenant(
            id: TenantId::generate(),
            slug: TenantSlug::fromString($slug),
            name: $slug,
            createdAt: $now,
            memberNumberPrefix: null,
            defaultTimeFormat: '24',
            displayNameI18n: $displayName !== null ? ['en_GB' => $displayName] : null,
            publicDescriptionI18n: ['en_GB' => 'A test tenant.'],
            supportedLocales: ['en_GB', 'fi_FI'],
            defaultLocale: 'en_GB',
            suspendedAt: $suspended ? $now : null,
            suspendedReason: $suspended ? $reason : null,
        );
    }

    private function renderPage(string $relPath): string
    {
        ob_start();
        try {
            require self::DEFAULT_DIR . '/' . $relPath;
        } finally {
            $output = (string) ob_get_clean();
        }
        return $output;
    }

    public function test_home_renders_tenant_display_name(): void
    {
        $tenant = $this->makeTenant(displayName: 'Acme Society');
        $GLOBALS['_default_site_tenant'] = $tenant;
        $GLOBALS['_default_site_locale'] = 'en_GB';

        $html = $this->renderPage('index.php');

        // Welcome string is "Welcome to %s" with tenant display name.
        self::assertStringContainsString('Welcome to Acme Society', $html);
        // CTA buttons present.
        self::assertStringContainsString('Join us', $html);
        self::assertStringContainsString('Sign in', $html);
        // Join CTA links to /join.
        self::assertStringContainsString('href="/join"', $html);
    }

    /**
     * Build a minimal container that binds SubmitMemberApplication to an
     * in-memory repository fake — sufficient for verifying that join.php
     * actually persists submissions.
     */
    private function makeJoinContainer(InMemoryMemberApplicationRepository $repo): Container
    {
        $container = new Container();
        $container->singleton(
            MemberApplicationRepositoryInterface::class,
            static fn() => $repo,
        );
        $container->bind(
            SubmitMemberApplication::class,
            static fn(Container $c) => new SubmitMemberApplication(
                $c->make(MemberApplicationRepositoryInterface::class),
            ),
        );
        return $container;
    }

    public function test_join_get_renders_form(): void
    {
        $tenant = $this->makeTenant();
        $GLOBALS['_default_site_tenant'] = $tenant;
        $GLOBALS['_default_site_locale'] = 'en_GB';

        // Default REQUEST_METHOD in CLI is empty; explicitly mark GET.
        $_SERVER['REQUEST_METHOD'] = 'GET';
        try {
            $html = $this->renderPage('join.php');
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
        }

        self::assertStringContainsString('Join Fallback Tenant', $html);
        self::assertStringContainsString('Apply for membership.', $html);
        // Form is rendered via partials/join-form.php.
        self::assertStringContainsString('<form method="post" action="/join"', $html);
        self::assertStringContainsString('name="name"', $html);
        self::assertStringContainsString('name="email"', $html);
        self::assertStringContainsString('name="dob"', $html);
        self::assertStringContainsString('name="motivation"', $html);
    }

    public function test_join_post_with_valid_fields_persists_application_and_shows_success(): void
    {
        $tenant = $this->makeTenant();
        $repo   = new InMemoryMemberApplicationRepository();

        $GLOBALS['_default_site_tenant']    = $tenant;
        $GLOBALS['_default_site_locale']    = 'en_GB';
        $GLOBALS['_default_site_container'] = $this->makeJoinContainer($repo);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'name'       => 'Alice',
            'email'      => 'alice@example.test',
            'dob'        => '1990-01-01',
            'motivation' => 'I want to contribute.',
        ];
        try {
            $html = $this->renderPage('join.php');
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
            $_POST = [];
        }

        self::assertStringContainsString('Your application has been submitted.', $html);
        // Form NOT shown after success.
        self::assertStringNotContainsString('<form method="post" action="/join"', $html);

        // The use case ran: an application row was actually persisted.
        self::assertCount(1, $repo->applications);
        $saved = $repo->applications[0];
        self::assertSame('Alice', $saved->name());
        self::assertSame('alice@example.test', $saved->email());
        self::assertSame('1990-01-01', $saved->dateOfBirth());
        self::assertSame('I want to contribute.', $saved->motivation());
        self::assertSame('pending', $saved->status());
        self::assertTrue($saved->tenantId()->equals($tenant->id));
    }

    public function test_join_post_with_invalid_email_shows_errors_and_does_not_persist(): void
    {
        $tenant = $this->makeTenant();
        $repo   = new InMemoryMemberApplicationRepository();

        $GLOBALS['_default_site_tenant']    = $tenant;
        $GLOBALS['_default_site_locale']    = 'en_GB';
        $GLOBALS['_default_site_container'] = $this->makeJoinContainer($repo);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'name'       => 'Alice',
            'email'      => 'bad-email',
            'dob'        => '1990-01-01',
            'motivation' => 'I want to contribute.',
        ];
        try {
            $html = $this->renderPage('join.php');
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
            $_POST = [];
        }

        // Error block rendered with the email error.
        self::assertStringContainsString('email:', $html);
        self::assertStringContainsString('Invalid', $html);
        // Form still rendered for retry.
        self::assertStringContainsString('<form method="post" action="/join"', $html);
        // Nothing persisted on validation failure.
        self::assertSame([], $repo->applications);
    }

    public function test_join_post_without_motivation_shows_error_and_does_not_persist(): void
    {
        $tenant = $this->makeTenant();
        $repo   = new InMemoryMemberApplicationRepository();

        $GLOBALS['_default_site_tenant']    = $tenant;
        $GLOBALS['_default_site_locale']    = 'en_GB';
        $GLOBALS['_default_site_container'] = $this->makeJoinContainer($repo);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'name'       => 'Alice',
            'email'      => 'alice@example.test',
            'dob'        => '1990-01-01',
            'motivation' => '',
        ];
        try {
            $html = $this->renderPage('join.php');
        } finally {
            unset($_SERVER['REQUEST_METHOD']);
            $_POST = [];
        }

        self::assertStringContainsString('motivation:', $html);
        // Form still rendered for retry.
        self::assertStringContainsString('<form method="post" action="/join"', $html);
        // Use case never invoked.
        self::assertSame([], $repo->applications);
    }

    public function test_login_redirects_to_backstage(): void
    {
        // login.php does `header('Location: /backstage/login', true, 302); exit;`
        // — incompatible with CLI test execution. Verify by reading the
        // file source for the redirect target so the contract is still
        // pinned by tests.
        $source = (string) file_get_contents(self::DEFAULT_DIR . '/login.php');
        self::assertStringContainsString("'Location: /backstage/login'", $source);
        self::assertStringContainsString('302', $source);
        self::assertStringContainsString('exit', $source);
    }

    public function test_suspended_renders_503_and_reason(): void
    {
        $tenant = $this->makeTenant(suspended: true, reason: 'Awaiting payment');
        $GLOBALS['_default_site_tenant'] = $tenant;
        $GLOBALS['_default_site_locale'] = 'en_GB';

        $html = $this->renderPage('suspended.php');

        // Title + body + reason all present.
        self::assertStringContainsString('Site temporarily unavailable', $html);
        self::assertStringContainsString('temporarily suspended', $html);
        self::assertStringContainsString('Awaiting payment', $html);

        // http_response_code(503) was set; verify via captured value.
        // Note: in CLI, http_response_code() returns the last value set
        // (default = false in PHP < 8 / 200 in PHP 8). We can't reliably
        // round-trip this in unit tests because it's process-global, but
        // the suspended.php file calls it on line 1 — we can pin that.
        $source = (string) file_get_contents(self::DEFAULT_DIR . '/suspended.php');
        self::assertStringContainsString('http_response_code(503)', $source);
    }

    public function test_suspended_falls_back_when_tenant_missing(): void
    {
        // Edge case: the page handles a missing tenant by emitting a
        // standalone shell. Ensures the page is robust if the router fails
        // to populate $GLOBALS for some reason.
        unset($GLOBALS['_default_site_tenant']);
        $GLOBALS['_default_site_locale'] = 'en_GB';

        $html = $this->renderPage('suspended.php');

        self::assertStringContainsString('<!DOCTYPE html>', $html);
        self::assertStringContainsString('Site temporarily unavailable', $html);
    }
}
