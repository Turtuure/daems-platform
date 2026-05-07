<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Infrastructure\Module;

use Daems\Infrastructure\Module\RoutePrefixes;
use PHPUnit\Framework\TestCase;

final class RoutePrefixesTest extends TestCase
{
    public function test_constructs_with_empty_lists(): void
    {
        $rp = new RoutePrefixes(backstage: [], api: []);
        self::assertSame([], $rp->backstagePrefixes());
        self::assertSame([], $rp->apiPrefixes());
    }

    public function test_returns_provided_lists(): void
    {
        $rp = new RoutePrefixes(
            backstage: ['/backstage/events'],
            api: ['/api/v1/events', '/api/v1/backstage/events'],
        );
        self::assertSame(['/backstage/events'], $rp->backstagePrefixes());
        self::assertSame(['/api/v1/events', '/api/v1/backstage/events'], $rp->apiPrefixes());
    }

    public function test_rejects_backstage_prefix_without_leading_slash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/leading slash/i');
        new RoutePrefixes(backstage: ['backstage/events'], api: []);
    }

    public function test_rejects_api_prefix_without_leading_slash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/leading slash/i');
        new RoutePrefixes(backstage: [], api: ['api/v1/events']);
    }

    public function test_longest_match_returns_null_when_no_prefixes(): void
    {
        $rp = new RoutePrefixes(backstage: [], api: []);
        self::assertNull($rp->longestMatch('/anything'));
    }

    public function test_longest_match_returns_null_when_path_does_not_match(): void
    {
        $rp = new RoutePrefixes(backstage: ['/backstage/events'], api: []);
        self::assertNull($rp->longestMatch('/backstage/projects'));
    }

    public function test_longest_match_finds_exact_backstage_match(): void
    {
        $rp = new RoutePrefixes(backstage: ['/backstage/events'], api: []);
        self::assertSame('/backstage/events', $rp->longestMatch('/backstage/events'));
    }

    public function test_longest_match_finds_api_match(): void
    {
        $rp = new RoutePrefixes(backstage: [], api: ['/api/v1/events']);
        self::assertSame('/api/v1/events', $rp->longestMatch('/api/v1/events/123'));
    }

    public function test_longest_match_picks_longest_among_multiple_matches(): void
    {
        $rp = new RoutePrefixes(
            backstage: [],
            api: ['/api/v1', '/api/v1/backstage', '/api/v1/backstage/events'],
        );
        self::assertSame(
            '/api/v1/backstage/events',
            $rp->longestMatch('/api/v1/backstage/events/42'),
        );
    }

    public function test_longest_match_does_not_match_partial_segment(): void
    {
        // /foo should not match /foobar — slash-segment boundary is required.
        $rp = new RoutePrefixes(backstage: [], api: ['/foo']);
        self::assertNull($rp->longestMatch('/foobar'));
    }

    public function test_overlaps_with_detects_equal_prefix(): void
    {
        $a = new RoutePrefixes(backstage: ['/backstage/events'], api: []);
        $b = new RoutePrefixes(backstage: ['/backstage/events'], api: []);
        self::assertTrue($a->overlapsWith($b));
    }

    public function test_overlaps_with_detects_one_under_other(): void
    {
        $a = new RoutePrefixes(backstage: [], api: ['/api/v1']);
        $b = new RoutePrefixes(backstage: [], api: ['/api/v1/events']);
        self::assertTrue($a->overlapsWith($b));
        self::assertTrue($b->overlapsWith($a));
    }

    public function test_overlaps_with_returns_false_for_sibling_prefixes(): void
    {
        // /foo and /foobar are siblings — should NOT overlap.
        $a = new RoutePrefixes(backstage: [], api: ['/foo']);
        $b = new RoutePrefixes(backstage: [], api: ['/foobar']);
        self::assertFalse($a->overlapsWith($b));
        self::assertFalse($b->overlapsWith($a));
    }

    public function test_overlaps_with_returns_false_for_unrelated_prefixes(): void
    {
        $a = new RoutePrefixes(backstage: ['/backstage/events'], api: ['/api/v1/events']);
        $b = new RoutePrefixes(backstage: ['/backstage/projects'], api: ['/api/v1/projects']);
        self::assertFalse($a->overlapsWith($b));
    }

    public function test_overlaps_with_returns_false_when_either_is_empty(): void
    {
        $empty = new RoutePrefixes(backstage: [], api: []);
        $other = new RoutePrefixes(backstage: ['/backstage/x'], api: ['/api/v1/x']);
        self::assertFalse($empty->overlapsWith($other));
        self::assertFalse($other->overlapsWith($empty));
    }

    public function test_overlaps_with_checks_across_backstage_and_api_lists(): void
    {
        // Cross-list check: even though one prefix is "backstage" and the
        // other is "api", they overlap if any combination matches the
        // path-prefix rule. Both lists are flattened for the comparison.
        $a = new RoutePrefixes(backstage: ['/shared/x'], api: []);
        $b = new RoutePrefixes(backstage: [], api: ['/shared/x/sub']);
        self::assertTrue($a->overlapsWith($b));
    }
}
