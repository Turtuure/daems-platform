<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\I18n;

use PHPUnit\Framework\TestCase;

final class CommunicationsParityTest extends TestCase
{
    public function test_all_communications_keys_present_in_three_locales(): void
    {
        /** @var array<string,string> $fi */
        $fi = require __DIR__ . '/../../../lang/fi_FI.php';
        /** @var array<string,string> $en */
        $en = require __DIR__ . '/../../../lang/en_GB.php';
        /** @var array<string,string> $sw */
        $sw = require __DIR__ . '/../../../lang/sw_TZ.php';

        $isCommunicationsKey = static fn(string $k): bool =>
            str_starts_with($k, 'modules.communications.')
            || str_starts_with($k, 'shell.communications.')
            || str_starts_with($k, 'backstage.title.communications.')
            || str_starts_with($k, 'communications.')
            || $k === 'sidebar.group.communications'
            || $k === 'modules.category.communications';

        $commKeys = array_filter(array_keys($fi), $isCommunicationsKey);

        $missingEn = array_diff($commKeys, array_keys($en));
        $missingSw = array_diff($commKeys, array_keys($sw));

        $this->assertEmpty(
            $missingEn,
            'en_GB missing communications keys: ' . implode(', ', $missingEn)
        );
        $this->assertEmpty(
            $missingSw,
            'sw_TZ missing communications keys: ' . implode(', ', $missingSw)
        );

        // Also guard the reverse direction: extra keys in en_GB/sw_TZ that aren't in fi_FI
        $extraEn = array_filter(
            array_diff(array_keys($en), $commKeys),
            $isCommunicationsKey
        );
        $extraSw = array_filter(
            array_diff(array_keys($sw), $commKeys),
            $isCommunicationsKey
        );
        $this->assertEmpty(
            $extraEn,
            'en_GB has extra communications keys not in fi_FI: ' . implode(', ', $extraEn)
        );
        $this->assertEmpty(
            $extraSw,
            'sw_TZ has extra communications keys not in fi_FI: ' . implode(', ', $extraSw)
        );

        // Sanity floor: after A7 there should be at least 80 keys (the spec listed 78 new + 7 pre-existing = 85; floor at 80 gives some headroom for refactoring)
        $this->assertGreaterThanOrEqual(
            80,
            count($commKeys),
            'expected at least 80 communications keys'
        );
    }
}
