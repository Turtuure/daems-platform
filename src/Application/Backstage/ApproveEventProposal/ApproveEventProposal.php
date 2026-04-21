<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\ApproveEventProposal;

use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventProposalRepositoryInterface;
use Daems\Domain\Event\EventRepositoryInterface;
use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Shared\Clock;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;

final class ApproveEventProposal
{
    public function __construct(
        private readonly EventProposalRepositoryInterface $proposals,
        private readonly EventRepositoryInterface $events,
        private readonly Clock $clock,
        private readonly IdGeneratorInterface $ids,
    ) {
    }

    public function execute(ApproveEventProposalInput $input): ApproveEventProposalOutput
    {
        $tenantId = $input->acting->activeTenant;
        if (!$input->acting->isAdminIn($tenantId)) {
            throw new ForbiddenException('not_tenant_admin');
        }

        $proposal = $this->proposals->findByIdForTenant($input->proposalId, $tenantId)
            ?? throw new NotFoundException('proposal_not_found');

        if ($proposal->status() !== 'pending') {
            throw new ValidationException(['status' => 'already_decided']);
        }

        $now = $this->clock->now();

        $eventId = EventId::fromString($this->ids->generate());
        $slug = $this->uniqueSlug($proposal->title(), $tenantId);

        $translations = new TranslationMap([
            $proposal->sourceLocale() => [
                'title'       => $proposal->title(),
                'location'    => $proposal->location(),
                'description' => $proposal->description(),
            ],
        ]);

        $event = new Event(
            $eventId,
            $tenantId,
            $slug,
            $proposal->title(),
            'upcoming',
            $proposal->eventDate(),
            $proposal->eventTime(),
            $proposal->location(),
            $proposal->isOnline(),
            $proposal->description(),
            null,
            [],
            'published',
            $translations,
        );
        $this->events->save($event);

        $this->proposals->recordDecision(
            $proposal->id()->value(),
            $tenantId,
            'approved',
            $input->acting->id->value(),
            $input->note,
            $now,
        );

        return new ApproveEventProposalOutput($eventId->value(), $slug);
    }

    private function uniqueSlug(string $title, TenantId $tenantId): string
    {
        $base = preg_replace('/[^a-z0-9]+/', '-', strtolower($title)) ?? 'event';
        $base = trim((string) $base, '-');
        if ($base === '') {
            $base = 'event';
        }
        if ($this->events->findBySlugForTenant($base, $tenantId) === null) {
            return $base;
        }
        for ($i = 0; $i < 5; $i++) {
            $suffix = substr($this->ids->generate(), 0, 8);
            $candidate = $base . '-' . $suffix;
            if ($this->events->findBySlugForTenant($candidate, $tenantId) === null) {
                return $candidate;
            }
        }
        throw new ValidationException(['slug' => 'could_not_generate_unique']);
    }
}
