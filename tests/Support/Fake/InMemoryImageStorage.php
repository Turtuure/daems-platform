<?php

declare(strict_types=1);

namespace Daems\Tests\Support\Fake;

use Daems\Domain\Storage\ImageStorageInterface;

final class InMemoryImageStorage implements ImageStorageInterface
{
    /** @var array<string, string> url => mime (for assertions) */
    public array $stored = [];

    public function storeEventImage(string $eventId, string $tmpPath, string $originalMime): string
    {
        $url = '/uploads/events/' . $eventId . '/' . bin2hex(random_bytes(8)) . '.webp';
        $this->stored[$url] = $originalMime;
        return $url;
    }

    public function deleteByUrl(string $url): void
    {
        unset($this->stored[$url]);
    }
}
