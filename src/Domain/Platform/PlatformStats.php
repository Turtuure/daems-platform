<?php

declare(strict_types=1);

namespace Daems\Domain\Platform;

final class PlatformStats
{
    /**
     * @param list<int>                                          $usersSparkline    Daily registrations, last 14 days
     * @param list<int>                                          $tenantsSparkline  Daily tenant creations, last 14 days
     * @param list<int>                                          $activitySparkline Daily activity counts, last 14 days
     * @param list<array{slug:string, name:string, suspended:bool, members:int}> $tenants
     * @param array{labels: list<string>, series: list<int>}     $tenantActivity   Cross-tenant activity over time
     * @param list<array{type:string, message:string, when:string}> $recentActivity
     */
    public function __construct(
        public readonly int $tenantCount,
        public readonly int $userCount,
        public readonly int $dbSizeMb,
        public readonly int $mysqlUptimeSeconds,
        public readonly array $usersSparkline,
        public readonly array $tenantsSparkline,
        public readonly array $activitySparkline,
        public readonly array $tenants,
        public readonly array $tenantActivity,
        public readonly array $recentActivity,
    ) {}
}
