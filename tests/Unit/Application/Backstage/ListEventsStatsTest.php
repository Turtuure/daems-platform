<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\Events\ListEventsStats\ListEventsStats;
use Daems\Application\Backstage\Events\ListEventsStats\ListEventsStatsInput;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Event\EventProposal;
use Daems\Domain\Event\EventProposalId;
use Daems\Domain\Event\EventRegistration;
use Daems\Domain\Tenant\TenantId;
use Daems\Tests\Support\ActingUserFactory;
use Daems\Tests\Support\Fake\InMemoryEventProposalRepository;
use Daems\Tests\Support\Fake\InMemoryEventRepository;
use PHPUnit\Framework\TestCase;

final class ListEventsStatsTest extends TestCase
{
    private const TENANT_ID = '019d0000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '019d0000-0000-7000-8000-000000000a01';
    private const USER_ID   = '019d0000-0000-7000-8000-000000000a02';

    // Event ids
    private const EVENT_1 = '019d0000-0000-7000-8000-000000003001';
    private const EVENT_2 = '019d0000-0000-7000-8000-000000003002';
    private const EVENT_3 = '019d0000-0000-7000-8000-000000003003';

    // Registration ids
    private const REG_1 = '019d0000-0000-7000-8000-000000004001';
    private const REG_2 = '019d0000-0000-7000-8000-000000004002';
    private const REG_3 = '019d0000-0000-7000-8000-000000004003';

    // Proposal ids
    private const PROP_1 = '019d0000-0000-7000-8000-000000005001';

    // User ids for registrations
    private const REG_USER_1 = '019d0000-0000-7000-8000-000000006001';
    private const REG_USER_2 = '019d0000-0000-7000-8000-000000006002';
    private const REG_USER_3 = '019d0000-0000-7000-8000-000000006003';

    public function test_orchestrates_3_slices_into_4_kpis(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $eventRepo    = new InMemoryEventRepository();
        $proposalRepo = new InMemoryEventProposalRepository();

        // Seed 2 published upcoming events (date in the future).
        $futureDate = (new \DateTimeImmutable('today'))->modify('+5 days')->format('Y-m-d');
        $eventRepo->save($this->makeEvent(self::EVENT_1, $tenantId, 'published', $futureDate));
        $eventRepo->save($this->makeEvent(self::EVENT_2, $tenantId, 'published', $futureDate));

        // Seed 1 draft event.
        $eventRepo->save($this->makeEvent(self::EVENT_3, $tenantId, 'draft', $futureDate));

        // Seed 3 registrations against EVENT_1 (within the last 30 days window).
        $now = (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s');
        $eventRepo->register(new EventRegistration(self::REG_1, self::EVENT_1, self::REG_USER_1, $now));
        $eventRepo->register(new EventRegistration(self::REG_2, self::EVENT_1, self::REG_USER_2, $now));
        $eventRepo->register(new EventRegistration(self::REG_3, self::EVENT_1, self::REG_USER_3, $now));

        // Seed 1 pending proposal.
        $proposalRepo->save($this->makeProposal(self::PROP_1, $tenantId, 'pending'));

        $usecase = new ListEventsStats($eventRepo, $proposalRepo);
        $out     = $usecase->execute(new ListEventsStatsInput(acting: $admin, tenantId: $tenantId));

        // KPI values
        self::assertSame(2, $out->stats['upcoming']['value']);
        self::assertSame(1, $out->stats['drafts']['value']);
        self::assertSame(3, $out->stats['registrations_30d']['value']);
        self::assertSame(1, $out->stats['pending_proposals']['value']);

        // All 4 KPIs exist with sparkline arrays.
        foreach (['upcoming', 'drafts', 'registrations_30d', 'pending_proposals'] as $key) {
            self::assertArrayHasKey($key, $out->stats);
            self::assertArrayHasKey('value', $out->stats[$key]);
            self::assertArrayHasKey('sparkline', $out->stats[$key]);
            self::assertIsArray($out->stats[$key]['sparkline']);
        }
    }

    public function test_throws_forbidden_for_non_admin(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $member   = ActingUserFactory::registeredInTenant(self::USER_ID, $tenantId);

        $this->expectException(ForbiddenException::class);

        $usecase = new ListEventsStats(
            new InMemoryEventRepository(),
            new InMemoryEventProposalRepository(),
        );
        $usecase->execute(new ListEventsStatsInput(acting: $member, tenantId: $tenantId));
    }

    public function test_returns_zero_state_with_no_data(): void
    {
        $tenantId = TenantId::fromString(self::TENANT_ID);
        $admin    = ActingUserFactory::adminInTenant(self::ADMIN_ID, $tenantId);

        $usecase = new ListEventsStats(
            new InMemoryEventRepository(),
            new InMemoryEventProposalRepository(),
        );
        $out = $usecase->execute(new ListEventsStatsInput(acting: $admin, tenantId: $tenantId));

        self::assertSame(0, $out->stats['upcoming']['value']);
        self::assertSame(0, $out->stats['drafts']['value']);
        self::assertSame(0, $out->stats['registrations_30d']['value']);
        self::assertSame(0, $out->stats['pending_proposals']['value']);

        // Each fake returns a 30-entry sparkline window; assert shape.
        self::assertCount(30, $out->stats['upcoming']['sparkline']);
        self::assertCount(30, $out->stats['drafts']['sparkline']);
        self::assertCount(30, $out->stats['registrations_30d']['sparkline']);
        self::assertCount(30, $out->stats['pending_proposals']['sparkline']);
    }

    private function makeEvent(string $id, TenantId $tenantId, string $status, string $date): Event
    {
        return new Event(
            id:          EventId::fromString($id),
            tenantId:    $tenantId,
            slug:        'test-event-' . substr($id, -4),
            title:       'Test Event ' . substr($id, -4),
            type:        'upcoming',
            date:        $date,
            time:        null,
            location:    null,
            online:      false,
            description: null,
            heroImage:   null,
            gallery:     [],
            status:      $status,
        );
    }

    private function makeProposal(string $id, TenantId $tenantId, string $status): EventProposal
    {
        return new EventProposal(
            id:           EventProposalId::fromString($id),
            tenantId:     $tenantId,
            userId:       self::USER_ID,
            authorName:   'Author',
            authorEmail:  'author@example.com',
            title:        'Proposed Event',
            eventDate:    '2026-09-01',
            eventTime:    null,
            location:     null,
            isOnline:     false,
            description:  'Description',
            sourceLocale: 'fi_FI',
            status:       $status,
            createdAt:    (new \DateTimeImmutable('today'))->format('Y-m-d H:i:s'),
        );
    }
}
