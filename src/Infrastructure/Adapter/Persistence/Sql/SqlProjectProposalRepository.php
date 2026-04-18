<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Adapter\Persistence\Sql;

use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;
use Daems\Infrastructure\Framework\Database\Connection;

final class SqlProjectProposalRepository implements ProjectProposalRepositoryInterface
{
    public function __construct(private readonly Connection $db) {}

    public function save(ProjectProposal $proposal): void
    {
        $this->db->execute(
            'INSERT INTO project_proposals
                (id, user_id, author_name, author_email, title, category, summary, description, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $proposal->id()->value(),
                $proposal->userId(),
                $proposal->authorName(),
                $proposal->authorEmail(),
                $proposal->title(),
                $proposal->category(),
                $proposal->summary(),
                $proposal->description(),
                $proposal->status(),
                $proposal->createdAt(),
            ],
        );
    }
}
