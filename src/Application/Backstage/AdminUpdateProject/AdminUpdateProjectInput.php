<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\AdminUpdateProject;

use Daems\Domain\Auth\ActingUser;

/**
 * Partial-update semantics: a null field means "do not update this field".
 *
 * Does not accept status or featured — those go through ChangeProjectStatus / SetProjectFeatured.
 */
final class AdminUpdateProjectInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $projectId,
        public readonly ?string $title,
        public readonly ?string $category,
        public readonly ?string $icon,
        public readonly ?string $summary,
        public readonly ?string $description,
        public readonly ?int $sortOrder,
    ) {}
}
