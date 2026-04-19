<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\User;

use Daems\Application\User\GetUserActivity\GetUserActivity;
use Daems\Application\User\GetUserActivity\GetUserActivityInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Forum\ForumRepositoryInterface;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class GetUserActivityTest extends TestCase
{
    private function uc(): GetUserActivity
    {
        $forum = $this->createStub(ForumRepositoryInterface::class);
        $forum->method('findPostsByUserId')->willReturn(['posts' => [], 'total' => 0]);
        $events = $this->createStub(EventRepositoryInterface::class);
        $events->method('findRegistrationsByUserId')->willReturn([]);
        return new GetUserActivity($forum, $events);
    }

    public function testSelfReturnsData(): void
    {
        $id = UserId::generate();
        $out = $this->uc()->execute(new GetUserActivityInput(new ActingUser($id, 'registered'), $id->value()));
        $this->assertSame(0, $out->data['forum_posts']);
    }

    public function testAdminCanViewAnyone(): void
    {
        $out = $this->uc()->execute(new GetUserActivityInput(new ActingUser(UserId::generate(), 'admin'), UserId::generate()->value()));
        $this->assertSame(0, $out->data['forum_posts']);
    }

    public function testOtherUserForbidden(): void
    {
        $this->expectException(ForbiddenException::class);
        $this->uc()->execute(new GetUserActivityInput(new ActingUser(UserId::generate(), 'registered'), UserId::generate()->value()));
    }
}
