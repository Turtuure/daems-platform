<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\ReverseLapse\ReverseLapse;
use Daems\Application\Membership\Billing\ReverseLapse\ReverseLapseInput;
use Daems\Domain\Audit\GsaOverride;
use Daems\Domain\Audit\GsaOverrideAction;
use Daems\Domain\Audit\GsaOverrideRepositoryInterface;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Governance\Exception\GsaOverrideRequiresReason;
use Daems\Domain\Membership\MemberStatusAudit;
use Daems\Domain\Membership\MemberStatusAuditRepositoryInterface;
use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\User;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryUserRepository;
use Daems\Tests\Support\FrozenClock;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ReverseLapseTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const GSA_ID    = '01958000-0000-7000-8000-0000000000aa';
    private const USER_ID   = '01958000-0000-7000-8000-000000000099';

    public function test_gsa_reverses_lapsed_user(): void
    {
        $users = new InMemoryUserRepository();
        $users->save($this->user('lapsed'));
        $statusAudit = $this->newStatusAuditRepo();
        $gsaOverrides = $this->newGsaOverrideRepo();
        $ids = $this->newIdGenerator();

        $useCase = new ReverseLapse($users, $statusAudit, $gsaOverrides, $ids, new FrozenClock(new DateTimeImmutable('2027-03-15')));
        $useCase->handle(new ReverseLapseInput(
            actor:         $this->gsa(),
            tenantId:      TenantId::fromString(self::TENANT_ID),
            userId:        UserId::fromString(self::USER_ID),
            justification: 'Maksanut velkansa täysimääräisesti, palautetaan jäsenoikeudet',
        ));

        $this->assertSame('active', $users->findById(self::USER_ID)?->membershipStatus());
        $this->assertCount(1, $statusAudit->saved);
        $this->assertCount(1, $gsaOverrides->saved);
        $this->assertSame(GsaOverrideAction::ReverseLapse, $gsaOverrides->saved[0]->action);
        $this->assertSame(self::USER_ID, $gsaOverrides->saved[0]->targetId);
    }

    public function test_non_gsa_admin_rejected(): void
    {
        $users = new InMemoryUserRepository();
        $users->save($this->user('lapsed'));
        $useCase = new ReverseLapse(
            $users,
            $this->newStatusAuditRepo(),
            $this->newGsaOverrideRepo(),
            $this->newIdGenerator(),
            new FrozenClock(new DateTimeImmutable()),
        );

        $this->expectException(ForbiddenException::class);
        $useCase->handle(new ReverseLapseInput(
            actor: new ActingUser(
                id:                 UserId::fromString(self::GSA_ID),
                email:              'admin@test',
                isPlatformAdmin:    false,
                activeTenant:       TenantId::fromString(self::TENANT_ID),
                roleInActiveTenant: UserTenantRole::Admin,
            ),
            tenantId:      TenantId::fromString(self::TENANT_ID),
            userId:        UserId::fromString(self::USER_ID),
            justification: 'tenant admin yrittää',
        ));
    }

    public function test_user_not_lapsed_throws(): void
    {
        $users = new InMemoryUserRepository();
        $users->save($this->user('active'));
        $useCase = new ReverseLapse(
            $users,
            $this->newStatusAuditRepo(),
            $this->newGsaOverrideRepo(),
            $this->newIdGenerator(),
            new FrozenClock(new DateTimeImmutable()),
        );

        $this->expectException(\DomainException::class);
        $useCase->handle(new ReverseLapseInput(
            actor:         $this->gsa(),
            tenantId:      TenantId::fromString(self::TENANT_ID),
            userId:        UserId::fromString(self::USER_ID),
            justification: 'on jo aktiivinen, ei lapsattu',
        ));
    }

    public function test_short_justification_rejected_by_gsa_override(): void
    {
        $users = new InMemoryUserRepository();
        $users->save($this->user('lapsed'));
        $useCase = new ReverseLapse(
            $users,
            $this->newStatusAuditRepo(),
            $this->newGsaOverrideRepo(),
            $this->newIdGenerator(),
            new FrozenClock(new DateTimeImmutable()),
        );

        // GsaOverride entity enforces min 10 chars on reason → bubbles up.
        $this->expectException(GsaOverrideRequiresReason::class);
        $useCase->handle(new ReverseLapseInput(
            actor:         $this->gsa(),
            tenantId:      TenantId::fromString(self::TENANT_ID),
            userId:        UserId::fromString(self::USER_ID),
            justification: 'short',
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

    private function gsa(): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::GSA_ID),
            email:              'gsa@test',
            isPlatformAdmin:    true,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: null,
        );
    }

    private function newStatusAuditRepo(): MemberStatusAuditRepositoryInterface
    {
        return new class implements MemberStatusAuditRepositoryInterface {
            /** @var list<MemberStatusAudit> */
            public array $saved = [];
            public function save(MemberStatusAudit $audit): void { $this->saved[] = $audit; }
            public function dailyTransitionsForTenant(TenantId $tenantId, string $newStatus): array { return []; }
        };
    }

    private function newGsaOverrideRepo(): GsaOverrideRepositoryInterface
    {
        return new class implements GsaOverrideRepositoryInterface {
            /** @var list<GsaOverride> */
            public array $saved = [];
            public function save(GsaOverride $override): void { $this->saved[] = $override; }
            public function listForTenant(TenantId $tenantId, int $limit = 100): array { return $this->saved; }
        };
    }

    private function newIdGenerator(): IdGeneratorInterface
    {
        return new class implements IdGeneratorInterface {
            public function generate(): string { return Uuid7::generate()->value(); }
        };
    }
}
