<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Backstage;

use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettings;
use Daems\Application\Backstage\UpdateTenantSettings\UpdateTenantSettingsInput;
use Daems\Domain\Auth\ActingUser;
use Daems\Domain\Auth\ForbiddenException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\Tenant\UserTenantRole;
use Daems\Domain\User\UserId;
use Daems\Tests\Support\Fake\InMemoryTenantRepository;
use PHPUnit\Framework\TestCase;

final class UpdateTenantSettingsTest extends TestCase
{
    private TenantId $tenantId;

    protected function setUp(): void
    {
        $this->tenantId = TenantId::fromString('01958000-0000-7000-8000-000000000001');
    }

    public function test_admin_sets_prefix(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, 'daems', 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), 'DAEMS'));
        self::assertSame('DAEMS', $repo->find($this->tenantId)?->memberNumberPrefix);
    }

    public function test_admin_clears_prefix_with_null(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, 'daems', 'Daems', 'DAEMS');
        $uc = new UpdateTenantSettings($repo);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), null));
        self::assertNull($repo->find($this->tenantId)?->memberNumberPrefix);
    }

    public function test_admin_clears_prefix_with_empty_string(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, 'daems', 'Daems', 'DAEMS');
        $uc = new UpdateTenantSettings($repo);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), '   '));
        self::assertNull($repo->find($this->tenantId)?->memberNumberPrefix);
    }

    public function test_non_admin_rejected(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, 'daems', 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $this->expectException(ForbiddenException::class);
        $uc->execute(new UpdateTenantSettingsInput($this->actingMember(), 'X'));
    }

    public function test_too_long_prefix_rejected(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, 'daems', 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $this->expectException(ValidationException::class);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), str_repeat('A', 21)));
    }

    public function test_invalid_chars_rejected(): void
    {
        $repo = new InMemoryTenantRepository();
        $repo->seedTenant($this->tenantId, 'daems', 'Daems');
        $uc = new UpdateTenantSettings($repo);
        $this->expectException(ValidationException::class);
        $uc->execute(new UpdateTenantSettingsInput($this->actingAdmin(), 'DAEMS$'));
    }

    private function actingAdmin(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString('01958000-0000-7000-8000-00000000a001'),
            email: 'admin@x',
            isPlatformAdmin: false,
            activeTenant: $this->tenantId,
            roleInActiveTenant: UserTenantRole::Admin,
        );
    }

    private function actingMember(): ActingUser
    {
        return new ActingUser(
            id: UserId::fromString('01958000-0000-7000-8000-00000000a002'),
            email: 'mem@x',
            isPlatformAdmin: false,
            activeTenant: $this->tenantId,
            roleInActiveTenant: UserTenantRole::Member,
        );
    }
}
