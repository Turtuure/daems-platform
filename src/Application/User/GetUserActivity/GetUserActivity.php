<?php

declare(strict_types=1);

namespace Daems\Application\User\GetUserActivity;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\User\UserId;
use DaemsModule\Events\Domain\EventRepositoryInterface;

final class GetUserActivity
{
    public function __construct(
        private readonly ForumRepositoryInterface $forum,
        private readonly EventRepositoryInterface $events,
    ) {}

    public function execute(GetUserActivityInput $input): GetUserActivityOutput
    {
        $target = UserId::fromString($input->userId);
        if (!$input->acting->owns($target) && !$input->acting->isAdmin()) {
            throw new ForbiddenException();
        }

        $forumData   = $this->forum->findPostsByUserId($input->userId, 5);
        $eventRows   = $this->events->findRegistrationsByUserId($input->userId);

        $attendedEvents = array_map(static fn(array $r) => [
            'slug'  => $r['slug'],
            'title' => $r['title'],
            'type'  => $r['type'],
            'date'  => date('M j, Y', (int) strtotime($r['date'])),
        ], $eventRows);

        return new GetUserActivityOutput([
            'forum_posts'     => $forumData['total'],
            'recent_posts'    => $forumData['posts'],
            'events_attended' => count($attendedEvents),
            'attended_events' => $attendedEvents,
        ]);
    }
}
