<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProposalsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectProposalRepositoryInterface;

final class ListProposalsForAdmin
{
    public function __construct(
        private readonly ProjectProposalRepositoryInterface $proposals,
    ) {}

    public function execute(ListProposalsForAdminInput $input): ListProposalsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $items = [];
        foreach ($this->proposals->listPendingForTenant($tenantId) as $p) {
            $items[] = [
                'id'           => $p->id()->value(),
                'user_id'      => $p->userId(),
                'author_name'  => $p->authorName(),
                'author_email' => $p->authorEmail(),
                'title'        => $p->title(),
                'category'     => $p->category(),
                'summary'      => $p->summary(),
                'description'  => $p->description(),
                'status'       => $p->status(),
                'created_at'   => $p->createdAt(),
            ];
        }

        return new ListProposalsForAdminOutput($items);
    }
}
