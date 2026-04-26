<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\ListInsightStats\ListInsightStats;
use Daems\Application\Insight\ListInsightStats\ListInsightStatsInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class ListInsightStatsTest extends TestCase
{
    public function test_passes_tenant_id_through_to_repository(): void
    {
        $repo = new class implements InsightRepositoryInterface {
            public ?TenantId $captured = null;
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $s, TenantId $t): ?Insight { return null; }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void {}
            public function statsForTenant(TenantId $t): array
            {
                $this->captured = $t;
                return [
                    'published' => ['value' => 0, 'sparkline' => []],
                    'scheduled' => ['value' => 0, 'sparkline' => []],
                    'featured'  => ['value' => 0, 'sparkline' => [], 'sparkline_scheduled' => []],
                ];
            }
        };

        $tid = TenantId::fromString('019d0000-0000-7000-8000-000000000001');
        $uc  = new ListInsightStats($repo);
        $uc->execute(new ListInsightStatsInput($tid));

        self::assertNotNull($repo->captured);
        self::assertSame($tid->value(), $repo->captured->value());
    }

    public function test_returns_repository_payload_unchanged(): void
    {
        $payload = [
            'published' => ['value' => 5, 'sparkline' => [['date' => '2026-04-25', 'value' => 1]]],
            'scheduled' => ['value' => 2, 'sparkline' => []],
            'featured'  => ['value' => 1, 'sparkline' => [], 'sparkline_scheduled' => []],
        ];
        $repo = new class($payload) implements InsightRepositoryInterface {
            public function __construct(private array $payload) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $s, TenantId $t): ?Insight { return null; }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void {}
            public function statsForTenant(TenantId $t): array { return $this->payload; }
        };

        $uc  = new ListInsightStats($repo);
        $out = $uc->execute(new ListInsightStatsInput(
            TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
        ));

        self::assertSame(5, $out->stats['published']['value']);
        self::assertSame(1, $out->stats['featured']['value']);
        self::assertCount(1, $out->stats['published']['sparkline']);
    }
}
