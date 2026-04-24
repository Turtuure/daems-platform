<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Application\Search;

use Daems\Application\Search\Search\Search;
use Daems\Application\Search\Search\SearchInput;
use Daems\Domain\Search\SearchHit;
use Daems\Tests\Support\Fake\InMemorySearchRepository;
use PHPUnit\Framework\TestCase;

final class SearchTest extends TestCase
{
    private const T = '019d-tenant';

    public function test_rejects_query_below_min_length(): void
    {
        $uc = new Search(new InMemorySearchRepository());
        $out = $uc->execute(new SearchInput(self::T, 'a', null, false, false, 5, 'fi_FI'));
        self::assertSame(0, $out->count);
        self::assertSame('query_too_short', $out->reason);
    }

    public function test_invalid_type_falls_back_to_all(): void
    {
        $repo = new InMemorySearchRepository();
        $repo->seed([$this->hit('event', 'Summer', 'published')]);
        $uc = new Search($repo);
        $out = $uc->execute(new SearchInput(self::T, 'summer', 'bogus', false, false, 5, 'fi_FI'));
        self::assertNull($out->type);  // normalised
        self::assertSame(1, $out->count);
    }

    public function test_members_excluded_when_not_admin_on_type_all(): void
    {
        $repo = new InMemorySearchRepository();
        $repo->seed([
            $this->hit('event', 'Summer', 'published'),
            $this->hit('member', 'Summer Smith', 'published'),
        ]);
        $uc = new Search($repo);
        $out = $uc->execute(new SearchInput(self::T, 'summer', null, false, false, 5, 'fi_FI'));
        $types = array_map(fn($h) => $h->entityType, $out->hits);
        self::assertSame(['event'], $types);
    }

    public function test_includes_drafts_when_include_unpublished_true(): void
    {
        $repo = new InMemorySearchRepository();
        $repo->seed([
            $this->hit('event', 'Summer 1', 'draft'),
            $this->hit('event', 'Summer 2', 'published'),
        ]);
        $uc = new Search($repo);
        $out = $uc->execute(new SearchInput(self::T, 'summer', null, true, false, 5, 'fi_FI'));
        self::assertSame(2, $out->count);
    }

    private function hit(string $type, string $title, string $status): SearchHit
    {
        return new SearchHit($type, 'id-' . uniqid(), $title, $title, "/$type", null, $status, null, 1.0);
    }
}
