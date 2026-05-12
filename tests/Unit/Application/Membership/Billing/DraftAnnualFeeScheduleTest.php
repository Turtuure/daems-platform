<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Membership\Billing;

use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeSchedule;
use Daems\Application\Membership\Billing\DraftAnnualFeeSchedule\DraftAnnualFeeScheduleInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Governance\Board;
use Daems\Domain\Governance\BoardDecisionStatus;
use Daems\Domain\Governance\BoardDecisionType;
use Daems\Domain\Governance\BoardId;
use Daems\Domain\Governance\TenantGovernanceSettings;
use Daems\Domain\Membership\Billing\AnnualFeeSchedule;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleId;
use Daems\Domain\Membership\Billing\AnnualFeeScheduleStatus;
use Daems\Domain\Membership\MembershipType;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\Fake\InMemoryAnnualFeeScheduleRepository;
use Daems\Tests\Support\Fake\InMemoryBoardDecisionRepository;
use Daems\Tests\Support\Fake\InMemoryBoardRepository;
use Daems\Tests\Support\Fake\InMemoryTenantGovernanceSettingsRepository;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class DraftAnnualFeeScheduleTest extends TestCase
{
    private const TENANT_ID = '01958000-0000-7000-8000-000000000001';
    private const ADMIN_ID  = '01958000-0000-7000-8000-0000000000aa';
    private const BOARD_ID  = '01958000-0000-7000-8000-0000000000b0';

    public function test_direct_activation_when_formal_decision_not_required(): void
    {
        [$useCase, $schedRepo, $deciRepo, $boardId] = $this->makeUseCase(requiresFormal: false);

        $output = $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));

        $this->assertNull($output->decisionId);
        $rows = $schedRepo->listForTenantYear(TenantId::fromString(self::TENANT_ID), 2027);
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame(AnnualFeeScheduleStatus::Active, $r->status());
        }
        $decisions = $deciRepo->listForBoard($boardId, null, BoardDecisionType::AnnualFeeSchedule);
        $this->assertCount(0, $decisions);
    }

    public function test_formal_decision_path_when_required(): void
    {
        [$useCase, $schedRepo, $deciRepo, $boardId] = $this->makeUseCase(requiresFormal: true);

        $output = $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));

        $this->assertNotNull($output->decisionId);
        $rows = $schedRepo->listForTenantYear(TenantId::fromString(self::TENANT_ID), 2027);
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame(AnnualFeeScheduleStatus::Proposed, $r->status());
            $this->assertSame($output->decisionId, $r->decisionId());
        }
        $decisions = $deciRepo->listForBoard($boardId, null, BoardDecisionType::AnnualFeeSchedule);
        $this->assertCount(1, $decisions);
        $this->assertSame(BoardDecisionStatus::Pending, $decisions[0]->status);
        $this->assertSame($output->decisionId, $decisions[0]->id->value());
    }

    public function test_non_admin_actor_rejected(): void
    {
        [$useCase] = $this->makeUseCase(requiresFormal: false);

        $this->expectException(ForbiddenException::class);
        $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(role: UserTenantRole::Member),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));
    }

    public function test_no_settings_defaults_to_direct_activation_path(): void
    {
        $schedRepo    = new InMemoryAnnualFeeScheduleRepository();
        $deciRepo     = new InMemoryBoardDecisionRepository();
        $settingsRepo = new InMemoryTenantGovernanceSettingsRepository();
        $boardRepo    = new InMemoryBoardRepository();
        // Intentionally: NO settings saved, NO board saved.

        $useCase = new DraftAnnualFeeSchedule(
            $schedRepo,
            $deciRepo,
            $settingsRepo,
            $boardRepo,
            FrozenClock::at('2026-11-01T00:00:00'),
        );

        $output = $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));

        $this->assertNull($output->decisionId);
        $rows = $schedRepo->listForTenantYear(TenantId::fromString(self::TENANT_ID), 2027);
        $this->assertCount(3, $rows);
        foreach ($rows as $r) {
            $this->assertSame(AnnualFeeScheduleStatus::Active, $r->status());
        }
    }

    public function test_formal_path_throws_when_tenant_has_no_board(): void
    {
        $schedRepo    = new InMemoryAnnualFeeScheduleRepository();
        $deciRepo     = new InMemoryBoardDecisionRepository();
        $settingsRepo = new InMemoryTenantGovernanceSettingsRepository();
        $boardRepo    = new InMemoryBoardRepository(); // empty — no board saved

        $settingsRepo->save(new TenantGovernanceSettings(
            tenantId:                          TenantId::fromString(self::TENANT_ID),
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     true,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  30,
            lapseCheckEnabled:                 true,
        ));

        $useCase = new DraftAnnualFeeSchedule(
            $schedRepo,
            $deciRepo,
            $settingsRepo,
            $boardRepo,
            FrozenClock::at('2026-11-01T00:00:00'),
        );

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tenant has no board');
        $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));
    }

    /** @dataProvider invalidFeesProvider */
    public function test_invalid_fees_rejected(array $fees, string $expectedFragment): void
    {
        [$useCase] = $this->makeUseCase(requiresFormal: false);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedFragment);
        $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     $fees,
        ));
    }

    /** @return array<string,array{0:array<string,int>,1:string}> */
    public static function invalidFeesProvider(): array
    {
        return [
            'missing FULL'         => [['SUPPORTING' => 1000, 'BASIC' => 5000], 'Must provide exactly 3 fees'],
            'HONORARY not allowed' => [['SUPPORTING' => 1000, 'BASIC' => 5000, 'HONORARY' => 0], 'Must provide exactly 3 fees'],
            'extra fourth key'     => [['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0, 'EXTRA' => 1], 'Must provide exactly 3 fees'],
        ];
    }

    public function test_direct_path_supersedes_prior_active_rows(): void
    {
        [$useCase, $schedRepo] = $this->makeUseCase(requiresFormal: false);

        // Pre-seed an existing Active row for (daems, 2027, BASIC).
        $priorActive = new AnnualFeeSchedule(
            id:           AnnualFeeScheduleId::fromString('01958000-0000-7000-8000-0000000000ab'),
            tenantId:     TenantId::fromString(self::TENANT_ID),
            year:         2027,
            feeType:      MembershipType::Basic,
            amountCents:  4000,
            currency:     'EUR',
            status:       AnnualFeeScheduleStatus::Active,
            decisionId:   null,
            activatedAt:  new DateTimeImmutable('2026-06-01T00:00:00'),
            activatedBy:  null,
            supersededAt: null,
            createdAt:    new DateTimeImmutable('2026-05-01T00:00:00'),
            createdBy:    null,
        );
        $schedRepo->save($priorActive);

        $useCase->handle(new DraftAnnualFeeScheduleInput(
            actor:    $this->actor(),
            tenantId: TenantId::fromString(self::TENANT_ID),
            year:     2027,
            fees:     ['SUPPORTING' => 1000, 'BASIC' => 5000, 'FULL' => 0],
        ));

        // Prior Active is now Superseded
        $reloaded = $schedRepo->findById($priorActive->id());
        $this->assertNotNull($reloaded);
        $this->assertSame(AnnualFeeScheduleStatus::Superseded, $reloaded->status());

        // Exactly one Active BASIC row for that (tenant, year) remains
        $rows       = $schedRepo->listForTenantYear(TenantId::fromString(self::TENANT_ID), 2027);
        $basics      = array_filter($rows, fn($r) => $r->feeType() === MembershipType::Basic);
        $activeBasics = array_filter($basics, fn($r) => $r->status() === AnnualFeeScheduleStatus::Active);
        $this->assertCount(1, $activeBasics);
        $this->assertSame(5000, array_values($activeBasics)[0]->amountCents());
    }

    /**
     * @return array{0:DraftAnnualFeeSchedule,1:InMemoryAnnualFeeScheduleRepository,2:InMemoryBoardDecisionRepository,3:BoardId}
     */
    private function makeUseCase(bool $requiresFormal): array
    {
        $schedRepo    = new InMemoryAnnualFeeScheduleRepository();
        $deciRepo     = new InMemoryBoardDecisionRepository();
        $settingsRepo = new InMemoryTenantGovernanceSettingsRepository();
        $boardRepo    = new InMemoryBoardRepository();

        $tenantId = TenantId::fromString(self::TENANT_ID);
        $boardId  = BoardId::fromString(self::BOARD_ID);
        $boardRepo->save(new Board(
            id:                   $boardId,
            tenantId:             $tenantId,
            bootstrappedByUserId: UserId::fromString(self::ADMIN_ID),
            bootstrappedAt:       new DateTimeImmutable('2026-01-01T00:00:00'),
            createdAt:            new DateTimeImmutable('2026-01-01T00:00:00'),
        ));

        $settingsRepo->save(new TenantGovernanceSettings(
            tenantId:                          $tenantId,
            expulsionHearingDays:              14,
            decisionExpirationDays:            60,
            requiresFormalDecisionForFees:     $requiresFormal,
            defaultDueDaysFromAnniversary:     60,
            overdueGraceDays:                  30,
            lapseCheckEnabled:                 true,
        ));

        $useCase = new DraftAnnualFeeSchedule(
            $schedRepo,
            $deciRepo,
            $settingsRepo,
            $boardRepo,
            FrozenClock::at('2026-11-01T00:00:00'),
        );

        return [$useCase, $schedRepo, $deciRepo, $boardId];
    }

    private function actor(UserTenantRole $role = UserTenantRole::Admin): ActingUser
    {
        return new ActingUser(
            id:                 UserId::fromString(self::ADMIN_ID),
            email:              'admin@test',
            isPlatformAdmin:    false,
            activeTenant:       TenantId::fromString(self::TENANT_ID),
            roleInActiveTenant: $role,
        );
    }
}
