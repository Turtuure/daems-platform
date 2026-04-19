<?php

declare(strict_types=1);

namespace Daems\Application\Forum\CreateForumTopic;

use Daems\Domain\Auth\ActingUser;

final class CreateForumTopicInput
{
    public function __construct(
        public readonly ActingUser $acting,
        public readonly string $categorySlug,
        public readonly string $title,
        public readonly string $content,
    ) {}
}
