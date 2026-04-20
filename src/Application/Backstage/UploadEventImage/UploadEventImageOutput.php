<?php

declare(strict_types=1);

namespace Daems\Application\Backstage\UploadEventImage;

final class UploadEventImageOutput
{
    public function __construct(public readonly string $url) {}

    /** @return array{url: string} */
    public function toArray(): array
    {
        return ['url' => $this->url];
    }
}
