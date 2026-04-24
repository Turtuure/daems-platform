<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Search;

use Daems\Domain\Search\SearchHit;
use PHPUnit\Framework\TestCase;

final class SearchHitTest extends TestCase
{
    public function test_to_array_produces_flat_json_shape(): void
    {
        $hit = new SearchHit(
            entityType: 'event',
            entityId: '019d-uuid-xyz',
            title: 'Summer Meetup',
            snippet: '...a summer event for members...',
            url: '/events/summer-2026',
            localeCode: null,
            status: 'published',
            publishedAt: '2026-06-01',
            relevance: 3.14,
        );

        self::assertSame([
            'entity_type' => 'event',
            'entity_id' => '019d-uuid-xyz',
            'title' => 'Summer Meetup',
            'snippet' => '...a summer event for members...',
            'url' => '/events/summer-2026',
            'locale_code' => null,
            'status' => 'published',
            'published_at' => '2026-06-01',
            'relevance' => 3.14,
        ], $hit->toArray());
    }

    public function test_locale_code_present_when_fallback_used(): void
    {
        $hit = new SearchHit(
            'project', 'id', 'Title', 'snip', '/projects/p', 'en_GB', 'published', null, 1.0,
        );
        self::assertSame('en_GB', $hit->toArray()['locale_code']);
    }
}
