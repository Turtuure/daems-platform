<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdmin;
use Daems\Application\Backstage\ListProposalsForAdmin\ListProposalsForAdminInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Project\ProjectProposal;
use Daems\Domain\Project\ProjectProposalId;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryProjectProposalRepository;
use PHPUnit\Framework\TestCase;

final class ListProposalsForAdminTest extends TestCase
{
    private const TENANT = '01958000-0000-7000-8000-000000000001';
    private const OTHER_TENANT = '01958000-0000-7000-8000-000000000002';

    private function acting(bool $platformAdmin, ?UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id: UserId::generate(),
            email: 'admin@x',
            isPlatformAdmin: $platformAdmin,
            activeTenant: TenantId::fromString(self::TENANT),
            roleInActiveTenant: $role,
        );
    }

    public function test_rejects_non_admin(): void
    {
        $this->expectException(ForbiddenException::class);
        $repo = new InMemoryProjectProposalRepository();
        (new ListProposalsForAdmin($repo))->execute(
            new ListProposalsForAdminInput($this->acting(platformAdmin: false, role: null)),
        );
    }

    public function test_returns_only_pending_for_own_tenant(): void
    {
        $repo = new InMemoryProjectProposalRepository();

        // Own tenant pending
        $repo->save($this->makeProposal('01959900-0000-7000-8000-000000000001', self::TENANT, 'Pending one', 'pending', '2026-04-18 10:00:00'));
        // Own tenant approved (should NOT appear)
        $repo->save($this->makeProposal('01959900-0000-7000-8000-000000000002', self::TENANT, 'Approved one', 'approved', '2026-04-17 10:00:00'));
        // Own tenant rejected (should NOT appear)
        $repo->save($this->makeProposal('01959900-0000-7000-8000-000000000003', self::TENANT, 'Rejected one', 'rejected', '2026-04-16 10:00:00'));
        // Other tenant pending (should NOT appear)
        $repo->save($this->makeProposal('01959900-0000-7000-8000-000000000004', self::OTHER_TENANT, 'Other tenant pending', 'pending', '2026-04-19 10:00:00'));

        $out = (new ListProposalsForAdmin($repo))->execute(
            new ListProposalsForAdminInput($this->acting(true)),
        );

        self::assertCount(1, $out->items);
        self::assertSame('Pending one', $out->items[0]['title']);
    }

    public function test_output_shape_contains_all_ten_fields(): void
    {
        $repo = new InMemoryProjectProposalRepository();
        $repo->save(new ProjectProposal(
            ProjectProposalId::fromString('01959900-0000-7000-8000-00000000000a'),
            TenantId::fromString(self::TENANT),
            '01958000-0000-7000-8000-000000000aaa',
            'Anna Author',
            'anna@x',
            'My Proposal Title',
            'community',
            'A concise summary that passes min length',
            'A longer description with details.',
            'pending',
            '2026-04-18 09:00:00',
        ));

        $out = (new ListProposalsForAdmin($repo))->execute(
            new ListProposalsForAdminInput($this->acting(true)),
        );

        self::assertCount(1, $out->items);
        $row = $out->items[0];
        self::assertSame('01959900-0000-7000-8000-00000000000a', $row['id']);
        self::assertSame('01958000-0000-7000-8000-000000000aaa', $row['user_id']);
        self::assertSame('Anna Author', $row['author_name']);
        self::assertSame('anna@x', $row['author_email']);
        self::assertSame('My Proposal Title', $row['title']);
        self::assertSame('community', $row['category']);
        self::assertSame('A concise summary that passes min length', $row['summary']);
        self::assertSame('A longer description with details.', $row['description']);
        self::assertSame('pending', $row['status']);
        self::assertSame('2026-04-18 09:00:00', $row['created_at']);
    }

    public function test_output_toArray_shape(): void
    {
        $repo = new InMemoryProjectProposalRepository();
        $repo->save($this->makeProposal('01959900-0000-7000-8000-000000000001', self::TENANT, 'P1', 'pending', '2026-04-18 10:00:00'));
        $repo->save($this->makeProposal('01959900-0000-7000-8000-000000000002', self::TENANT, 'P2', 'pending', '2026-04-19 10:00:00'));

        $out = (new ListProposalsForAdmin($repo))->execute(
            new ListProposalsForAdminInput($this->acting(true)),
        );

        $arr = $out->toArray();
        self::assertArrayHasKey('items', $arr);
        self::assertArrayHasKey('total', $arr);
        self::assertSame(2, $arr['total']);
        self::assertCount(2, $arr['items']);
    }

    private function makeProposal(string $id, string $tenantId, string $title, string $status, string $createdAt): ProjectProposal
    {
        return new ProjectProposal(
            ProjectProposalId::fromString($id),
            TenantId::fromString($tenantId),
            '01958000-0000-7000-8000-000000000aaa',
            'Anna',
            'anna@x',
            $title,
            'community',
            'A summary long enough',
            'A description long enough for display',
            $status,
            $createdAt,
        );
    }
}
