<?php
declare(strict_types=1);

namespace Daems\Tests\E2E;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Locale\TranslationMap;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class EventsLocaleE2ETest extends TestCase
{
    private KernelHarness $h;

    protected function setUp(): void
    {
        $this->h = new KernelHarness(FrozenClock::at('2026-04-21T12:00:00Z'));
    }

    private function seedBilingualEvent(string $slug): EventId
    {
        $id = EventId::generate();
        $this->h->events->save(new Event(
            $id,
            $this->h->testTenantId,
            $slug,
            'Kokous',
            'upcoming',
            '2026-06-15',
            '18:00',
            'Helsinki',
            false,
            'Kuvaus',
            null,
            [],
            'published',
            new TranslationMap([
                'fi_FI' => ['title' => 'Kokous', 'location' => 'Helsinki', 'description' => 'Kuvaus'],
                'en_GB' => ['title' => 'Meeting', 'location' => 'Helsinki', 'description' => 'Description'],
            ]),
        ));
        return $id;
    }

    public function test_accept_language_switches_content_to_english(): void
    {
        $this->seedBilingualEvent('ann-meet');

        $resp = $this->h->request('GET', '/api/v1/events/ann-meet', [], ['Accept-Language' => 'en-GB,en;q=0.9']);
        $this->assertSame(200, $resp->status());
        $data = json_decode($resp->body(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('data', $data);
        $this->assertSame('Meeting', $data['data']['title']);
        $this->assertFalse($data['data']['title_fallback']);
        $this->assertFalse($data['data']['title_missing']);
    }

    public function test_accept_language_returns_finnish_by_preference(): void
    {
        $this->seedBilingualEvent('fi-ann');

        $resp = $this->h->request('GET', '/api/v1/events/fi-ann', [], ['Accept-Language' => 'fi-FI,fi;q=0.9']);
        $this->assertSame(200, $resp->status());
        $data = json_decode($resp->body(), true);
        $this->assertSame('Kokous', $data['data']['title']);
    }

    public function test_default_locale_fallback_to_en_gb_when_missing(): void
    {
        // Seed only Finnish
        $this->h->events->save(new Event(
            EventId::generate(),
            $this->h->testTenantId,
            'fi-only',
            'Vain Suomeksi',
            'upcoming',
            '2026-06-15',
            null,
            null,
            false,
            null,
            null,
            [],
            'published',
            new TranslationMap([
                'fi_FI' => ['title' => 'Vain Suomeksi', 'location' => null, 'description' => null],
            ]),
        ));

        // Request en_GB — the entity has no en_GB row; fallback = en_GB too, so fields mark missing.
        $resp = $this->h->request('GET', '/api/v1/events/fi-only', [], ['Accept-Language' => 'en-GB']);
        $this->assertSame(200, $resp->status());
        $data = json_decode($resp->body(), true);
        $this->assertNull($data['data']['title']);
        $this->assertTrue($data['data']['title_missing']);
        $this->assertFalse($data['data']['title_fallback']);
    }
}
