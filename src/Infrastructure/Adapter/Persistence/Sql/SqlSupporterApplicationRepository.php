<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Membership\SupporterApplication;
use Daems\Domain\Membership\SupporterApplicationId;
use Daems\Domain\Membership\SupporterApplicationRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlSupporterApplicationRepository implements SupporterApplicationRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(SupporterApplication $app): void
    {
        $this->db->execute(
            'INSERT INTO supporter_applications
                (id, org_name, contact_person, reg_no, email, country, motivation, how_heard, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $app->id()->value(),
                $app->orgName(),
                $app->contactPerson(),
                $app->regNo(),
                $app->email(),
                $app->country(),
                $app->motivation(),
                $app->howHeard(),
                $app->status(),
            ],
        );
    }
}
