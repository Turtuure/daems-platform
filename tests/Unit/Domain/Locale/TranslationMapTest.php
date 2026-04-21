<?php
declare(strict_types=1);

namespace Daems\Tests\Unit\Domain\Locale;

use Daems\Domain\Locale\TranslationMap;
use Daems\Domain\Locale\SupportedLocale;
use PHPUnit\Framework\TestCase;

final class TranslationMapTest extends TestCase
{
    public function testViewReturnsRequestedLocaleFields(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous', 'description' => 'Kuvaus'],
            'en_GB' => ['title' => 'Meeting', 'description' => 'Description'],
            'sw_TZ' => null,
        ]);
        $view = $map->view(
            SupportedLocale::fromString('fi_FI'),
            SupportedLocale::contentFallback(),
            ['title', 'description'],
        );
        $this->assertSame('Kokous', $view->field('title'));
        $this->assertFalse($view->isFallback('title'));
        $this->assertFalse($view->isMissing('title'));
    }

    public function testViewFallsBackPerField(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous'], // description missing
            'en_GB' => ['title' => 'Meeting', 'description' => 'English desc'],
        ]);
        $view = $map->view(
            SupportedLocale::fromString('fi_FI'),
            SupportedLocale::contentFallback(),
            ['title', 'description'],
        );
        $this->assertSame('Kokous', $view->field('title'));
        $this->assertFalse($view->isFallback('title'));
        $this->assertSame('English desc', $view->field('description'));
        $this->assertTrue($view->isFallback('description'));
        $this->assertFalse($view->isMissing('description'));
    }

    public function testMissingWhenBothLocalesEmpty(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'Kokous'],
            'en_GB' => ['title' => 'Meeting'],
        ]);
        $view = $map->view(
            SupportedLocale::fromString('fi_FI'),
            SupportedLocale::contentFallback(),
            ['title', 'location'],
        );
        $this->assertNull($view->field('location'));
        $this->assertFalse($view->isFallback('location'));
        $this->assertTrue($view->isMissing('location'));
    }

    public function testCoverageCountsPerLocale(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => 'A', 'location' => 'B', 'description' => 'C'],
            'en_GB' => ['title' => 'A', 'location' => null, 'description' => null],
            'sw_TZ' => null,
        ]);
        $coverage = $map->coverage(['title', 'location', 'description']);
        $this->assertSame(['filled' => 3, 'total' => 3], $coverage['fi_FI']);
        $this->assertSame(['filled' => 1, 'total' => 3], $coverage['en_GB']);
        $this->assertSame(['filled' => 0, 'total' => 3], $coverage['sw_TZ']);
    }

    public function testEmptyStringDoesNotCountAsFilled(): void
    {
        $map = new TranslationMap([
            'fi_FI' => ['title' => '', 'location' => 'Hki', 'description' => 'D'],
        ]);
        $coverage = $map->coverage(['title', 'location', 'description']);
        $this->assertSame(['filled' => 2, 'total' => 3], $coverage['fi_FI']);
    }
}
