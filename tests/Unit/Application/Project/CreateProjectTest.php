<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Project;

use Daems\Application\Project\CreateProject\CreateProject;
use Daems\Application\Project\CreateProject\CreateProjectInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Project\Project;
use Daems\Domain\Project\ProjectRepositoryInterface;
use Daems\Domain\User\UserId;
use PHPUnit\Framework\TestCase;

final class CreateProjectTest extends TestCase
{
    public function testSetsOwnerIdFromActingUser(): void
    {
        $ownerId = UserId::generate();
        $acting = new ActingUser($ownerId, 'registered');

        $captured = null;
        $repo = $this->createMock(ProjectRepositoryInterface::class);
        $repo->method('save')->willReturnCallback(
            function (Project $p) use (&$captured): void {
                $captured = $p;
            },
        );

        $out = (new CreateProject($repo))->execute(
            new CreateProjectInput($acting, 'My Project', 'cat', 'icon', 's', 'd', 'active'),
        );

        $this->assertNotNull($captured);
        $this->assertNotNull($captured->ownerId());
        $this->assertSame($ownerId->value(), $captured->ownerId()->value());
        $this->assertNotEmpty($out->project['slug']);
    }
}
