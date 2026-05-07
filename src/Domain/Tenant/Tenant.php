<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use DateTimeImmutable;

final class Tenant
{
    /**
     * @param array<string, string>|null $displayNameI18n        ['fi_FI' => '…', 'en_GB' => '…']
     * @param array<string, string>|null $publicDescriptionI18n  same shape as $displayNameI18n
     * @param list<string>               $supportedLocales       defaults to ['en_GB']
     */
    public function __construct(
        public readonly TenantId $id,
        public readonly TenantSlug $slug,
        public readonly string $name,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $memberNumberPrefix = null,
        /** Tenant-wide default for the backstage TimePicker: '12' or '24'. */
        public readonly string $defaultTimeFormat = '24',
        private readonly ?array $displayNameI18n = null,
        private readonly ?array $publicDescriptionI18n = null,
        private readonly array $supportedLocales = ['en_GB'],
        private readonly string $defaultLocale = 'en_GB',
        private readonly ?DateTimeImmutable $suspendedAt = null,
        private readonly ?string $suspendedReason = null,
    ) {}

    /**
     * Localised tenant display name. Falls back to en_GB, then to the
     * technical short name ($this->name) if no i18n was provided at all.
     */
    public function displayName(string $locale): string
    {
        if ($this->displayNameI18n !== null
            && isset($this->displayNameI18n[$locale])
            && $this->displayNameI18n[$locale] !== ''
        ) {
            return $this->displayNameI18n[$locale];
        }
        if ($this->displayNameI18n !== null
            && isset($this->displayNameI18n['en_GB'])
            && $this->displayNameI18n['en_GB'] !== ''
        ) {
            return $this->displayNameI18n['en_GB'];
        }
        return $this->name;
    }

    /**
     * Optional public-facing description. Falls back to en_GB, then null.
     */
    public function publicDescription(string $locale): ?string
    {
        if ($this->publicDescriptionI18n === null) {
            return null;
        }
        if (isset($this->publicDescriptionI18n[$locale])) {
            return $this->publicDescriptionI18n[$locale];
        }
        if (isset($this->publicDescriptionI18n['en_GB'])) {
            return $this->publicDescriptionI18n['en_GB'];
        }
        return null;
    }

    /** @return list<string> */
    public function supportedLocales(): array
    {
        return $this->supportedLocales;
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function suspended(): bool
    {
        return $this->suspendedAt !== null;
    }

    public function suspendedAt(): ?DateTimeImmutable
    {
        return $this->suspendedAt;
    }

    public function suspendedReason(): ?string
    {
        return $this->suspendedReason;
    }
}
