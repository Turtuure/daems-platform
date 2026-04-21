<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ListEventProposalsForAdmin;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\EventProposalRepositoryInterface;

final class ListEventProposalsForAdmin
{
    public function __construct(private readonly EventProposalRepositoryInterface $proposals)
    {
    }

    public function execute(ListEventProposalsForAdminInput $input): ListEventProposalsForAdminOutput
    {
        if (!$input->actor->isAdminIn($input->tenantId) && !$input->actor->isPlatformAdmin()) {
            throw new ForbiddenException();
        }
        $items = $this->proposals->listForTenant($input->tenantId, $input->status);
        $out = [];
        foreach ($items as $p) {
            $out[] = [
                'id'            => $p->id()->value(),
                'user_id'       => $p->userId(),
                'author_name'   => $p->authorName(),
                'author_email'  => $p->authorEmail(),
                'title'         => $p->title(),
                'event_date'    => $p->eventDate(),
                'event_time'    => $p->eventTime(),
                'location'      => $p->location(),
                'is_online'     => $p->isOnline(),
                'description'   => $p->description(),
                'source_locale' => $p->sourceLocale(),
                'status'        => $p->status(),
                'created_at'    => $p->createdAt(),
                'decided_at'    => $p->decidedAt(),
                'decided_by'    => $p->decidedBy(),
                'decision_note' => $p->decisionNote(),
            ];
        }
        return new ListEventProposalsForAdminOutput($out);
    }
}
