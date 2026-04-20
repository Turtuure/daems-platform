<?php

declare(strict_types=1);

namespace Daems\Domain\Storage;

interface ImageStorageInterface
{
    /**
     * Store an uploaded image at a canonical location under the given bucket/event.
     * Implementations MUST validate the input is a supported image (JPEG/PNG/WebP/GIF),
     * resize to max 2048px longest edge, strip EXIF, and re-encode as WebP when possible.
     *
     * @param string $eventId UUID7 of the event the image belongs to
     * @param string $tmpPath path to a readable file on disk (typically $_FILES['file']['tmp_name'])
     * @param string $originalMime MIME type as reported by the upload
     * @return string URL path where the stored image can be fetched (e.g. /uploads/events/{id}/{uuid}.webp)
     * @throws \Daems\Domain\Storage\ImageStorageException on invalid input or IO failure
     */
    public function storeEventImage(string $eventId, string $tmpPath, string $originalMime): string;

    /**
     * Delete a stored image by the URL path returned from storeEventImage.
     * Idempotent: returns silently if the file does not exist.
     */
    public function deleteByUrl(string $url): void;
}
