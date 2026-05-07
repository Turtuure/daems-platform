<?php

declare(strict_types=1);

namespace Daems\Domain\Tenant;

use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Resolves the runtime ModuleState for a (tenant, module) pair.
 *
 * Lives in Domain even though it depends on Infrastructure\Module — the
 * registry is configuration data treated as read-only at request time, not
 * a framework dependency. Keeping the resolver here lets domain code
 * (route guard, sidebar builder) consume it without crossing back into
 * Application or Infrastructure.
 *
 * Memoises the per-tenant state map for the lifetime of the instance so
 * a single request only hits the DB once even if many checks happen.
 */
final class TenantModuleResolver
{
    /** @var array<string, array<string, ModuleState>> tenantId.value() => slug => state */
    private array $memo = [];

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantModulesRepositoryInterface $modules,
    ) {}

    public function stateFor(TenantId $tenantId, string $moduleSlug): ModuleState
    {
        $states = $this->statesForTenant($tenantId);
        return $states[$moduleSlug] ?? ModuleState::DISABLED;
    }

    public function isEnabledFor(TenantId $tenantId, string $moduleSlug): bool
    {
        return $this->stateFor($tenantId, $moduleSlug)->isActive();
    }

    /**
     * @return array<string, ModuleState> keyed by module slug
     */
    public function statesForTenant(TenantId $tenantId): array
    {
        $key = $tenantId->value();
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $rows = $this->modules->findByTenant($tenantId);
        /** @var array<string, TenantModule> $bySlug */
        $bySlug = [];
        foreach ($rows as $row) {
            $bySlug[$row->moduleSlug()] = $row;
        }

        $states = [];

        // Manifest-driven modules: state derived from manifest + row.
        foreach ($this->registry->all() as $manifest) {
            $slug = $manifest->name();
            if ($manifest->isCore()) {
                $states[$slug] = ModuleState::CORE;
                continue;
            }
            $row = $bySlug[$slug] ?? null;
            if ($row === null) {
                $states[$slug] = ModuleState::DISABLED;
                continue;
            }
            if (!$row->isAvailable()) {
                $states[$slug] = ModuleState::DISABLED;
                continue;
            }
            $states[$slug] = $row->isEnabled()
                ? ModuleState::ENABLED
                : ModuleState::AVAILABLE_NOT_ENABLED;
        }

        // Orphan rows: DB row exists for a slug the registry doesn't know about.
        // Surface as DISABLED so the GSA "unknown module" cleanup UI can list them.
        foreach ($bySlug as $slug => $row) {
            if (!isset($states[$slug])) {
                $states[$slug] = ModuleState::DISABLED;
            }
        }

        $this->memo[$key] = $states;
        return $states;
    }

    /**
     * @return list<string> enabled module slugs (ENABLED or CORE)
     */
    public function enabledSlugsFor(TenantId $tenantId): array
    {
        $out = [];
        foreach ($this->statesForTenant($tenantId) as $slug => $state) {
            if ($state->isActive()) {
                $out[] = $slug;
            }
        }
        return $out;
    }

    public function invalidate(TenantId $tenantId): void
    {
        unset($this->memo[$tenantId->value()]);
    }
}
