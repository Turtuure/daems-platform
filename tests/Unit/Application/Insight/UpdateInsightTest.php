<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\UpdateInsight\UpdateInsight;
use Daems\Application\Insight\UpdateInsight\UpdateInsightInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\NotFoundException;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class UpdateInsightTest extends TestCase
{
    private const TENANT_A = '019d0000-0000-7000-8000-000000000001';
    private const TENANT_B = '019d0000-0000-7000-8000-000000000002';
    private const INSIGHT  = '019d0000-0000-7000-8000-000000000010';

    public function test_rejects_cross_tenant_edit(): void
    {
        $insightInTenantA = $this->buildInsight();
        $repo = $this->fakeRepo($insightInTenantA);
        $uc = new UpdateInsight($repo);

        $this->expectException(NotFoundException::class);
        $uc->execute($this->validInput(tenantId: self::TENANT_B));
    }

    public function test_allows_slug_unchanged(): void
    {
        $existing = $this->buildInsight(slug: 'same-slug');
        $repo = $this->fakeRepo($existing);
        $uc = new UpdateInsight($repo);

        $out = $uc->execute($this->validInput(slug: 'same-slug', title: 'Updated title'));
        self::assertSame('Updated title', $out->insight->title());
        self::assertSame('same-slug', $out->insight->slug());
    }

    public function test_rejects_slug_taken_by_another_insight(): void
    {
        $existing = $this->buildInsight(slug: 'original');
        $repo = $this->fakeRepo($existing);
        $repo->slugOwner = [
            'taken-by-other' => InsightId::fromString('019d0000-0000-7000-8000-000000000FFF'),
        ];
        $uc = new UpdateInsight($repo);

        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(slug: 'taken-by-other'));
    }

    public function test_preserves_id_on_update(): void
    {
        $existing = $this->buildInsight();
        $repo = $this->fakeRepo($existing);
        $uc = new UpdateInsight($repo);

        $out = $uc->execute($this->validInput(title: 'New title'));
        self::assertSame(self::INSIGHT, $out->insight->id()->value());
    }

    private function validInput(
        string $tenantId = self::TENANT_A,
        string $slug = 'original',
        string $title = 'Hello',
    ): UpdateInsightInput {
        return new UpdateInsightInput(
            insightId: InsightId::fromString(self::INSIGHT),
            tenantId: TenantId::fromString($tenantId),
            slug: $slug,
            title: $title,
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-04-24',
            author: 'Sam',
            excerpt: 'Teaser',
            heroImage: null,
            tags: [],
            content: '<p>body</p>',
        );
    }

    private function buildInsight(string $slug = 'original'): Insight
    {
        return new Insight(
            id: InsightId::fromString(self::INSIGHT),
            tenantId: TenantId::fromString(self::TENANT_A),
            slug: $slug,
            title: 'Existing',
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            date: '2026-01-01',
            author: 'Sam',
            readingTime: 1,
            excerpt: 'x',
            heroImage: null,
            tags: [],
            content: 'y',
        );
    }

    /** @param Insight $existing */
    private function fakeRepo(Insight $existing): object
    {
        return new class ($existing) implements InsightRepositoryInterface {
            /** @var array<string, InsightId> */ public array $slugOwner = [];
            public function __construct(private readonly Insight $existing) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $slug, TenantId $t): ?Insight
            {
                if ($slug === $this->existing->slug()) return $this->existing;
                if (isset($this->slugOwner[$slug])) {
                    return new Insight(
                        id: $this->slugOwner[$slug],
                        tenantId: $t, slug: $slug, title: 'other', category: 'c', categoryLabel: 'C',
                        featured: false, date: '2026-01-01', author: 'a', readingTime: 1,
                        excerpt: 'x', heroImage: null, tags: [], content: 'y',
                    );
                }
                return null;
            }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight
            {
                return ($id->value() === $this->existing->id()->value()
                     && $t->value() === $this->existing->tenantId()->value())
                    ? $this->existing : null;
            }
            public function save(Insight $i): void {}
            public function delete(InsightId $id, TenantId $t): void {}
            public function statsForTenant(TenantId $t): array { return ['published' => ['value' => 0, 'sparkline' => []], 'scheduled' => ['value' => 0, 'sparkline' => []], 'featured' => ['value' => 0, 'sparkline' => [], 'sparkline_scheduled' => []]]; }
        };
    }
}
