<?php

declare(strict_types=1);

namespace Daems\Application\Project\AddProjectUpdate;

final class AddProjectUpdateInput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly string $content,
        public readonly string $authorName,
    ) {}
}
