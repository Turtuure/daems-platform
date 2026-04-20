<?php

declare(strict_types=1);

namespace Daems\Tests\E2E\Backstage;

use Daems\Domain\Event\Event;
use Daems\Domain\Event\EventId;
use Daems\Domain\Shared\ValueObject\Uuid7;
use Daems\Tests\Support\FrozenClock;
use Daems\Tests\Support\KernelHarness;
use PHPUnit\Framework\TestCase;

final class EventUploadTest extends TestCase
{
    private KernelHarness $h;
    private string $token;

    protected function setUp(): void
    {
        $this->h     = new KernelHarness(FrozenClock::at('2026-04-20T12:00:00Z'));
        $admin       = $this->h->seedUser('admin-upl@x.com', 'pass1234', 'admin');
        $this->token = $this->h->tokenFor($admin);
    }

    protected function tearDown(): void
    {
        // Reset $_FILES to avoid pollution between tests
        $_FILES = [];
    }

    /** Create a minimal 1×1 PNG temp file and populate $_FILES['file']. Returns the tmp path. */
    private function populateFilesGlobal(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'evtup');
        if ($tmp === false) {
            self::fail('Could not create temp file');
        }
        // Write a minimal valid PNG (1×1 transparent pixel)
        $img = imagecreatetruecolor(1, 1);
        if ($img === false) {
            self::fail('GD imagecreatetruecolor failed');
        }
        imagepng($img, $tmp);
        imagedestroy($img);

        $_FILES = [
            'file' => [
                'name'     => 'tiny.png',
                'type'     => 'image/png',
                'tmp_name' => $tmp,
                'error'    => UPLOAD_ERR_OK,
                'size'     => (int) filesize($tmp),
            ],
        ];

        return $tmp;
    }

    /** Create an event via API and return its ID. */
    private function createEvent(string $title = 'Upload Test Event Title'): string
    {
        $resp = $this->h->authedRequest('POST', '/api/v1/backstage/events', $this->token, [
            'title'       => $title,
            'type'        => 'upcoming',
            'event_date'  => '2026-12-01',
            'event_time'  => null,
            'location'    => 'Oulu',
            'is_online'   => false,
            'description' => 'Description for upload test, enough characters here.',
        ]);
        $this->assertSame(201, $resp->status(), 'createEvent failed: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        return (string) ($body['data']['id'] ?? '');
    }

    public function test_upload_succeeds_returns_url(): void
    {
        $id = $this->createEvent('Upload Happy Path Event');
        $this->populateFilesGlobal();

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/events/' . $id . '/images',
            $this->token,
        );

        $this->assertSame(201, $resp->status(), 'Upload failed: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('data', $body);
        $this->assertArrayHasKey('url', $body['data']);
        $this->assertNotEmpty($body['data']['url']);
    }

    public function test_upload_enforces_15_image_cap(): void
    {
        $id = $this->createEvent('Upload Cap Test Event');

        // Retrieve the event from the InMemory repo and replace it with one that has
        // 1 hero image + 14 gallery images = 15 total (the cap).
        $existing = $this->h->events->byId[$id] ?? null;
        $this->assertNotNull($existing, 'Event not found in harness after creation');

        $gallery = [];
        for ($i = 0; $i < 14; $i++) {
            $gallery[] = '/uploads/events/' . $id . '/gallery-' . $i . '.webp';
        }

        $capped = new Event(
            $existing->id(),
            $existing->tenantId(),
            $existing->slug(),
            $existing->title(),
            $existing->type(),
            $existing->date(),
            $existing->time(),
            $existing->location(),
            $existing->online(),
            $existing->description(),
            '/uploads/events/' . $id . '/hero.webp', // 1 hero
            $gallery,                                  // 14 gallery = 15 total
            $existing->status(),
        );
        $this->h->events->save($capped);

        $this->populateFilesGlobal();

        $resp = $this->h->authedRequest(
            'POST',
            '/api/v1/backstage/events/' . $id . '/images',
            $this->token,
        );

        $this->assertSame(422, $resp->status(), 'Expected 422 for image cap, got: ' . $resp->body());
        $body = json_decode($resp->body(), true);
        $this->assertIsArray($body);
        $errors = $body['errors'] ?? [];
        $this->assertSame('max_15_images', $errors['limit'] ?? null);
    }
}
