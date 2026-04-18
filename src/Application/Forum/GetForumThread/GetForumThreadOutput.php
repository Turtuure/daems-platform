<?php

declare(strict_types=1);

namespace Daems\Application\Forum\GetForumThread;

final class GetForumThreadOutput
{
    public function __construct(
        public readonly ?array $data,
    ) {}
}
