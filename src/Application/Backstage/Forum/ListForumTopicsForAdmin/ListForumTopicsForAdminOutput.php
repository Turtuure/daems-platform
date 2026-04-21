<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\Forum\ListForumTopicsForAdmin;

use Daems\Domain\Forum\ForumTopic;

final class ListForumTopicsForAdminOutput
{
    /**
     * @param list<ForumTopic> $topics
     */
    public function __construct(public readonly array $topics) {}
}
