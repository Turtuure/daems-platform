<?php

declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Insight;

use Daems\Application\Insight\CreateInsight\CreateInsight;
use Daems\Application\Insight\CreateInsight\CreateInsightInput;
use Daems\Domain\Insight\Insight;
use Daems\Domain\Insight\InsightId;
use Daems\Domain\Insight\InsightRepositoryInterface;
use Daems\Domain\Shared\ValidationException;
use Daems\Domain\Tenant\TenantId;
use PHPUnit\Framework\TestCase;

final class CreateInsightTest extends TestCase
{
    public function test_creates_with_required_fields(): void
    {
        $repo = $this->fakeRepo();
        $uc   = new CreateInsight($repo);
        $out  = $uc->execute($this->validInput());

        self::assertInstanceOf(Insight::class, $out->insight);
        self::assertSame('Hello world', $out->insight->title());
    }

    public function test_requires_title(): void
    {
        $uc = new CreateInsight($this->fakeRepo());
        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(title: ''));
    }

    public function test_requires_excerpt(): void
    {
        $uc = new CreateInsight($this->fakeRepo());
        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(excerpt: ''));
    }

    public function test_requires_content(): void
    {
        $uc = new CreateInsight($this->fakeRepo());
        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(content: ''));
    }

    public function test_rejects_duplicate_slug(): void
    {
        $existing = $this->buildInsight(slug: 'already-taken');
        $repo = $this->fakeRepo($existing);
        $uc = new CreateInsight($repo);

        $this->expectException(ValidationException::class);
        $uc->execute($this->validInput(slug: 'already-taken'));
    }

    public function test_computes_reading_time_from_content_word_count(): void
    {
        $repo = $this->fakeRepo();
        $uc   = new CreateInsight($repo);
        $content = str_repeat('word ', 600);  // 600 words → ceil(600/200) = 3 min
        $out = $uc->execute($this->validInput(content: $content));
        self::assertSame(3, $out->insight->readingTime());
    }

    public function test_reading_time_minimum_is_one(): void
    {
        $repo = $this->fakeRepo();
        $uc   = new CreateInsight($repo);
        $out = $uc->execute($this->validInput(content: 'two words'));
        self::assertSame(1, $out->insight->readingTime());
    }

    private function validInput(
        string $title = 'Hello world',
        string $slug  = 'hello-world',
        string $excerpt = 'A teaser',
        string $content = '<p>Body</p>',
    ): CreateInsightInput {
        return new CreateInsightInput(
            tenantId: TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
            slug: $slug,
            title: $title,
            category: 'tech',
            categoryLabel: 'Tech',
            featured: false,
            publishedDate: '2026-04-24',
            author: 'Sam',
            excerpt: $excerpt,
            heroImage: null,
            tags: [],
            content: $content,
        );
    }

    private function buildInsight(string $slug): Insight
    {
        return new Insight(
            id: InsightId::fromString('019d0000-0000-7000-8000-000000000999'),
            tenantId: TenantId::fromString('019d0000-0000-7000-8000-000000000001'),
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

    private function fakeRepo(?Insight $existing = null): InsightRepositoryInterface
    {
        return new class ($existing) implements InsightRepositoryInterface {
            /** @var Insight[] */
            public array $saved = [];
            public function __construct(private readonly ?Insight $existing) {}
            public function listForTenant(TenantId $t, ?string $c = null, bool $u = false): array { return []; }
            public function findBySlugForTenant(string $slug, TenantId $t): ?Insight
            {
                return ($this->existing && $this->existing->slug() === $slug) ? $this->existing : null;
            }
            public function findByIdForTenant(InsightId $id, TenantId $t): ?Insight { return null; }
            public function save(Insight $i): void { $this->saved[] = $i; }
            public function delete(InsightId $id, TenantId $t): void {}
            public function statsForTenant(TenantId $t): array { return ['published' => ['value' => 0, 'sparkline' => []], 'scheduled' => ['value' => 0, 'sparkline' => []], 'featured' => ['value' => 0, 'sparkline' => [], 'sparkline_scheduled' => []]]; }
        };
    }
}
