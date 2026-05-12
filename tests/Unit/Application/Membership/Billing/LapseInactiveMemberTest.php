<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMember;
use Daems\Application\Membership\Billing\LapseInactiveMember\LapseInactiveMemberInput;
use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class LapseInactiveMemberTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_active_member_flips_to_lapsed(): void
    {
        $users = new InMemoryUserRepository();
        $users->save($this->user('active'));
        $audit = $this->newAuditRepo();
        $ids = $this->newIdGenerator();

        $useCase = new LapseInactiveMember($users, $audit, $ids, new FrozenClock(new DateTimeImmutable('2027-01-15T03:00:00')));
        $out = $useCase->handle(new LapseInactiveMemberInput(
            tenantId:     TenantId::fromString(self::TENANT_ID),
            userId:       UserId::fromString(self::USER_ID),
            overdueYears: [2025, 2026],
        ));

        $this->assertTrue($out->lapsed);
        $this->assertSame('active', $out->previousStatus);
        $this->assertSame('lapsed', $out->newStatus);
        $this->assertSame('lapsed', $users->findById(self::USER_ID)?->membershipStatus());
        $this->assertCount(1, $audit->saved);
        $this->assertNull($audit->saved[0]->performedByAdminId);
        $this->assertSame('2v maksamatta: 2025, 2026', $audit->saved[0]->reason);
    }

    public function test_already_lapsed_is_no_op(): void
    {
        $users = new InMemoryUserRepository();
        $users->save($this->user('lapsed'));
        $audit = $this->newAuditRepo();
        $ids = $this->newIdGenerator();

        $useCase = new LapseInactiveMember($users, $audit, $ids, new FrozenClock(new DateTimeImmutable()));
        $out = $useCase->handle(new LapseInactiveMemberInput(
            tenantId:     TenantId::fromString(self::TENANT_ID),
            userId:       UserId::fromString(self::USER_ID),
            overdueYears: [2025, 2026],
        ));

        $this->assertFalse($out->lapsed);
        $this->assertSame('lapsed', $out->newStatus);
        $this->assertCount(0, $audit->saved);
    }

    public function test_user_not_found_throws(): void
    {
        $users = new InMemoryUserRepository();
        $audit = $this->newAuditRepo();
        $ids = $this->newIdGenerator();

        $useCase = new LapseInactiveMember($users, $audit, $ids, new FrozenClock(new DateTimeImmutable()));
        $this->expectException(\DomainException::class);
        $useCase->handle(new LapseInactiveMemberInput(
            tenantId:     TenantId::fromString(self::TENANT_ID),
            userId:       UserId::fromString(self::USER_ID),
            overdueYears: [2025, 2026],
        ));
    }

    private function user(string $status): User
    {
        return new User(
            id:               UserId::fromString(self::USER_ID),
            name:             'Test',
            email:            'test@daems.fi',
            passwordHash:     null,
            dateOfBirth:      '1990-01-01',
            country:          'FI',
            membershipType:   'BASIC',
            membershipStatus: $status,
        );
    }

    private function newAuditRepo(): MemberStatusAuditRepositoryInterface
    {
        return new class implements MemberStatusAuditRepositoryInterface {
            /** @var list<MemberStatusAudit> */
            public array $saved = [];
            public function save(MemberStatusAudit $audit): void
            {
                $this->saved[] = $audit;
            }
            public function dailyTransitionsForTenant(TenantId $tenantId, string $newStatus): array
            {
                return [];
            }
        };
    }

    private function newIdGenerator(): IdGeneratorInterface
    {
        return new class implements IdGeneratorInterface {
            public function generate(): string
            {
                return Uuid7::generate()->value();
            }
        };
    }
}
