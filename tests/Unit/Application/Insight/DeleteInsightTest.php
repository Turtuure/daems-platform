<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\DeleteInsight\DeleteInsight;
use Daems\Application\Insight\DeleteInsight\DeleteInsightInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class DeleteInsightTest extends TestCase
{
    private const TENANT_A = '019d0000-0000-7000-8000-000000000001';
    private const TENANT_B = '019d0000-0000-7000-8000-000000000002';
    private const INSIGHT  = '019d0000-0000-7000-8000-000000000010';

    public function test_deletes_existing_insight(): void
    {
        $existing = $this->buildInsight();
        $repo = $this->fakeRepo($existing);
        $uc = new DeleteInsight($repo);

        $uc->execute(new DeleteInsightInput(
            InsightId::fromString(self::INSIGHT),
            TenantId::fromString(self::TENANT_A),
        ));

        self::assertTrue($repo->deleted, 'repo.delete must have been called');
    }

    public function test_rejects_cross_tenant_delete(): void
    {
        $existing = $this->buildInsight();
        $repo = $this->fakeRepo($existing);
        $uc = new DeleteInsight($repo);

        $this->expectException(NotFoundException::class);
        $uc->execute(new DeleteInsightInput(
            InsightId::fromString(self::INSIGHT),
            TenantId::fromString(self::TENANT_B),
        ));
        self::assertFalse($repo->deleted);
    }

    private function buildInsight(): Insight
    {
        return new Insight(
            id: InsightId::fromString(self::INSIGHT),
            tenantId: TenantId::fromString(self::TENANT_A),
            slug: 's', title: 't', category: 'c', categoryLabel: 'C', featured: false,
            date: '2026-01-01', author: 'a', readingTime: 1, excerpt: 'x',
            heroImage: null, tags: [], content: 'y',
        );
    }

    /** @param Insight $existing */
    private function fakeRepo(Insight $existing): object
    {
        return new class ($existing) implements InsightRepositoryInterface {
            public bool $deleted = false;
            public function __construct(private readonly Insight $existing) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $slug, TenantId $t): ?Insight { return null; }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight
            {
                return ($id->value() === $this->existing->id()->value()
                     && $t->value() === $this->existing->tenantId()->value())
                    ? $this->existing : null;
            }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void { $this->deleted = true; }
            public function statsForTenant(TenantId $t): array { return ['published' => ['value' => 0, 'sparkline' => []], 'scheduled' => ['value' => 0, 'sparkline' => []], 'featured' => ['value' => 0, 'sparkline' => []]]; }
        };
    }
}
