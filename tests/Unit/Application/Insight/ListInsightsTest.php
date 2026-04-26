<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\ListInsights\ListInsights;
use Daems\Application\Insight\ListInsights\ListInsightsInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class ListInsightsTest extends TestCase
{
    public function test_include_unpublished_propagates_to_repo(): void
    {
        $repo = new class implements InsightRepositoryInterface {
            public bool $flag = false;
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array
            {
                $this->flag = $u;
                return [];
            }
            public function findBySlugForTenant(string $s, TenantId $t): ?Insight { return null; }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void {}
            public function statsForTenant(TenantId $t): array { return ['published' => ['value' => 0, 'sparkline' => []], 'scheduled' => ['value' => 0, 'sparkline' => []], 'featured' => ['value' => 0, 'sparkline' => [], 'sparkline_scheduled' => []]]; }
        };
        $uc = new ListInsights($repo);
        $uc->execute(new ListInsightsInput(
            TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
            null,
            true,
        ));
        self::assertTrue($repo->flag);
    }
}
