<?php

declare(strict_types=1);

namespace Daems\Domain\Project;

use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Locale\EntityTranslationView;
use Daems\Domain\Locale\SupportedLocale;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;

final class Project
{
    public const TRANSLATABLE_FIELDS = ['title', 'summary', 'description'];

    private readonly TranslationMap $translations;

    public function __construct(
        private readonly ProjectId $id,
        private readonly TenantId $tenantId,
        private readonly string $slug,
        private readonly string $title,
        private readonly string $category,
        private readonly string $icon,
        private readonly string $summary,
        private readonly string $description,
        private readonly string $status,
        private readonly int $sortOrder,
        private readonly ?UserId $ownerId = null,
        private readonly bool $featured = false,
        private readonly string $createdAt = '',
        ?TranslationMap $translations = null,
    ) {
        $this->translations = $translations ?? new TranslationMap([
            SupportedLocale::UI_DEFAULT => [
                'title'       => $this->title,
                'summary'     => $this->summary,
                'description' => $this->description,
            ],
        ]);
    }

    public function id(): ProjectId
    {
        return $this->id;
    }
    public function tenantId(): TenantId
    {
        return $this->tenantId;
    }
    public function slug(): string
    {
        return $this->slug;
    }
    public function title(): string
    {
        return $this->title;
    }
    public function category(): string
    {
        return $this->category;
    }
    public function icon(): string
    {
        return $this->icon;
    }
    public function summary(): string
    {
        return $this->summary;
    }
    public function description(): string
    {
        return $this->description;
    }
    public function status(): string
    {
        return $this->status;
    }
    public function sortOrder(): int
    {
        return $this->sortOrder;
    }
    public function ownerId(): ?UserId
    {
        return $this->ownerId;
    }
    public function featured(): bool
    {
        return $this->featured;
    }
    public function createdAt(): string
    {
        return $this->createdAt;
    }

    public function translations(): TranslationMap
    {
        return $this->translations;
    }

    public function view(SupportedLocale $requested, SupportedLocale $fallback): EntityTranslationView
    {
        return $this->translations->view($requested, $fallback, self::TRANSLATABLE_FIELDS);
    }

    public function assertMutableBy(ActingUser $acting): void
    {
        if ($acting->isAdmin()) {
            return;
        }
        if ($this->ownerId === null || !$acting->owns($this->ownerId)) {
            throw new ForbiddenException();
        }
    }
}
