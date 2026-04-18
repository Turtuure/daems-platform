<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\MemberApplication;
use Daems\Domain\Membership\MemberApplicationId;
use Daems\Domain\Membership\MemberApplicationRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlMemberApplicationRepository implements MemberApplicationRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(MemberApplication $app): void
    {
        $this->db->execute(
            'INSERT INTO member_applications
                (id, name, email, date_of_birth, country, motivation, how_heard, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $app->id()->value(),
                $app->name(),
                $app->email(),
                $app->dateOfBirth(),
                $app->country(),
                $app->motivation(),
                $app->howHeard(),
                $app->status(),
            ],
        );
    }
}
