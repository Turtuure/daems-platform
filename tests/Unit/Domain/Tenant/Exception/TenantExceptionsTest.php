<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Tenant\Exception;

use Daems\Domain\Tenant\Exception\ModuleDependencyUnmetException;
use Daems\Domain\Tenant\Exception\ModuleDependentEnabledException;
use Daems\Domain\Tenant\Exception\ModuleNotAvailableException;
use Daems\Domain\Tenant\Exception\TenantPrimaryDomainRequiredException;
use Daems\Domain\Tenant\Exception\TenantSlugImmutableException;
use Daems\Domain\Tenant\Exception\TenantSuspendedException;
use PHPUnit\Framework\TestCase;

final class TenantExceptionsTest extends TestCase
{
    public function testModuleNotAvailableMessage(): void
    {
        $e = ModuleNotAvailableException::for('forum', 'daems');
        $this->assertInstanceOf(\DomainException::class, $e);
        $this->assertInstanceOf(\Throwable::class, $e);
        $this->assertSame("Module 'forum' is not available for tenant 'daems'", $e->getMessage());
    }

    public function testModuleDependencyUnmetMessage(): void
    {
        $e = ModuleDependencyUnmetException::for('forum', ['users', 'auth']);
        $this->assertInstanceOf(\DomainException::class, $e);
        $this->assertSame(
            "Module 'forum' cannot be enabled: required dependencies not enabled: users, auth",
            $e->getMessage()
        );
    }

    public function testModuleDependentEnabledMessage(): void
    {
        $e = ModuleDependentEnabledException::for('users', ['forum', 'events']);
        $this->assertInstanceOf(\DomainException::class, $e);
        $this->assertSame(
            "Module 'users' cannot be disabled: still required by enabled modules: forum, events",
            $e->getMessage()
        );
    }

    public function testTenantSlugImmutableMessage(): void
    {
        $e = TenantSlugImmutableException::for('daems', 'sahegroup');
        $this->assertInstanceOf(\DomainException::class, $e);
        $this->assertSame(
            "Tenant slug is immutable: cannot change from 'daems' to 'sahegroup'",
            $e->getMessage()
        );
    }

    public function testTenantPrimaryDomainRequiredMessage(): void
    {
        $e = TenantPrimaryDomainRequiredException::for('daems');
        $this->assertInstanceOf(\DomainException::class, $e);
        $this->assertSame("Tenant 'daems' must have at least one primary domain", $e->getMessage());
    }

    public function testTenantSuspendedMessage(): void
    {
        $e = TenantSuspendedException::for('daems', 'Non-payment');
        $this->assertInstanceOf(\DomainException::class, $e);
        $this->assertSame("Tenant 'daems' is suspended: Non-payment", $e->getMessage());
    }
}
