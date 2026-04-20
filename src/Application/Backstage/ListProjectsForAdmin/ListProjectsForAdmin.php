<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListProjectsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectRepositoryInterface;

final class ListProjectsForAdmin
{
    public function __construct(private readonly ProjectRepositoryInterface $projects) {}

    public function execute(ListProjectsForAdminInput $input): ListProjectsForAdminOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $filters = [];
        if ($input->status !== null && $input->status !== '') {
            $filters['status'] = $input->status;
        }
        if ($input->category !== null && $input->category !== '') {
            $filters['category'] = $input->category;
        }
        if ($input->featuredOnly === true) {
            $filters['featured'] = true;
        }
        if ($input->q !== null && $input->q !== '') {
            $filters['q'] = $input->q;
        }

        $items = [];
        foreach ($this->projects->listAllStatusesForTenant($tenantId, $filters) as $p) {
            $items[] = [
                'id'                 => $p->id()->value(),
                'slug'               => $p->slug(),
                'title'              => $p->title(),
                'category'           => $p->category(),
                'status'             => $p->status(),
                'featured'           => $p->featured(),
                'owner_id'           => $p->ownerId()?->value(),
                'participants_count' => $this->projects->countParticipants($p->id()->value()),
                'comments_count'     => count($this->projects->findCommentsByProjectId($p->id()->value())),
                'created_at'         => $p->createdAt(),
            ];
        }

        return new ListProjectsForAdminOutput($items);
    }
}
