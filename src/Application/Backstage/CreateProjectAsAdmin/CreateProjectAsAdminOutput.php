<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\CreateProjectAsAdmin;

final class CreateProjectAsAdminOutput
{
    public function __construct(
        public readonly string $id,
        public readonly string $slug,
    ) {}

    /** @return array{id: string, slug: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'slug' => $this->slug];
    }
}
