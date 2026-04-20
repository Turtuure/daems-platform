<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\AdminUpdateProject;

final class AdminUpdateProjectOutput
{
    public function __construct(public readonly string $id) {}

    /** @return array{id: string, updated: true} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'updated' => true];
    }
}
