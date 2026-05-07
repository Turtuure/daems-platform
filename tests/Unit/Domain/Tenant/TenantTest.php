<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\TenantSlug;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class TenantTest extends TestCase
{
    /**
     * @param array<string, string>|null $displayNameI18n
     * @param array<string, string>|null $publicDescriptionI18n
     * @param list<string>               $supportedLocales
     */
    private function makeTenant(
        ?array $displayNameI18n = null,
        ?array $publicDescriptionI18n = null,
        array $supportedLocales = ['en_GB'],
        string $defaultLocale = 'en_GB',
        ?DateTimeImmutable $suspendedAt = null,
        ?string $suspendedReason = null,
        string $name = 'daems-society',
    ): Tenant {
        return new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            $name,
            new DateTimeImmutable('2026-04-19T10:00:00+00:00'),
            null,
            '24',
            $displayNameI18n,
            $publicDescriptionI18n,
            $supportedLocales,
            $defaultLocale,
            $suspendedAt,
            $suspendedReason,
        );
    }

    public function testConstruction(): void
    {
        // Existing back-compat: positional construction still works.
        $t = new Tenant(
            TenantId::fromString('01958000-0000-7000-8000-000000000001'),
            TenantSlug::fromString('daems'),
            'Daems Society',
            new DateTimeImmutable('2026-04-19T10:00:00+00:00'),
        );

        $this->assertSame('daems',         $t->slug->value());
        $this->assertSame('Daems Society', $t->name);
        $this->assertFalse($t->suspended());
        $this->assertSame('en_GB', $t->defaultLocale());
        $this->assertSame(['en_GB'], $t->supportedLocales());
    }

    public function testDisplayNameReturnsLocalisedWhenPresent(): void
    {
        $t = $this->makeTenant(
            displayNameI18n: ['fi_FI' => 'Daemsin Yhteisö', 'en_GB' => 'Daems Society'],
        );

        $this->assertSame('Daemsin Yhteisö', $t->displayName('fi_FI'));
        $this->assertSame('Daems Society', $t->displayName('en_GB'));
    }

    public function testDisplayNameFallsBackToEnGbWhenRequestedLocaleMissing(): void
    {
        $t = $this->makeTenant(
            displayNameI18n: ['en_GB' => 'Daems Society'],
        );

        $this->assertSame('Daems Society', $t->displayName('sw_TZ'));
    }

    public function testDisplayNameFallsBackToTechnicalNameWhenNoI18nAtAll(): void
    {
        $t = $this->makeTenant(
            displayNameI18n: null,
            name: 'daems-society',
        );

        $this->assertSame('daems-society', $t->displayName('fi_FI'));
        $this->assertSame('daems-society', $t->displayName('en_GB'));
    }

    public function testPublicDescriptionFallbackChain(): void
    {
        $t = $this->makeTenant(
            publicDescriptionI18n: ['fi_FI' => 'Yhteisömme.', 'en_GB' => 'Our community.'],
        );

        $this->assertSame('Yhteisömme.', $t->publicDescription('fi_FI'));
        $this->assertSame('Our community.', $t->publicDescription('en_GB'));
        // Missing locale → en_GB fallback.
        $this->assertSame('Our community.', $t->publicDescription('sw_TZ'));
    }

    public function testPublicDescriptionReturnsNullWhenAbsent(): void
    {
        $t = $this->makeTenant(publicDescriptionI18n: null);
        $this->assertNull($t->publicDescription('fi_FI'));
        $this->assertNull($t->publicDescription('en_GB'));
    }

    public function testSuspendedTrueWhenSuspendedAtSet(): void
    {
        $t = $this->makeTenant(
            suspendedAt: new DateTimeImmutable('2026-05-01T10:00:00+00:00'),
            suspendedReason: 'Non-payment',
        );

        $this->assertTrue($t->suspended());
        $this->assertSame('Non-payment', $t->suspendedReason());
        $this->assertNotNull($t->suspendedAt());
    }

    public function testSuspendedFalseWhenSuspendedAtNull(): void
    {
        $t = $this->makeTenant(suspendedAt: null);

        $this->assertFalse($t->suspended());
        $this->assertNull($t->suspendedAt());
        $this->assertNull($t->suspendedReason());
    }

    public function testSupportedLocalesAndDefaultLocaleRoundTrip(): void
    {
        $t = $this->makeTenant(
            supportedLocales: ['fi_FI', 'en_GB', 'sw_TZ'],
            defaultLocale: 'fi_FI',
        );

        $this->assertSame(['fi_FI', 'en_GB', 'sw_TZ'], $t->supportedLocales());
        $this->assertSame('fi_FI', $t->defaultLocale());
    }
}
