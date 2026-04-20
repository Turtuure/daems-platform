<?php

declare(strict_types=1);

namespace Daems\Infrastructure\Storage;

use Daems\Domain\Shared\IdGeneratorInterface;
use Daems\Domain\Storage\ImageStorageException;
use Daems\Domain\Storage\ImageStorageInterface;

final class LocalImageStorage implements ImageStorageInterface
{
    private const MAX_EDGE = 2048;
    private const WEBP_QUALITY = 85;
    private const JPEG_FALLBACK_QUALITY = 85;
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct(
        private readonly string $publicRoot,
        private readonly string $urlPrefix,
        private readonly IdGeneratorInterface $ids,
    ) {}

    public function storeEventImage(string $eventId, string $tmpPath, string $originalMime): string
    {
        if (!in_array($originalMime, self::ALLOWED_MIME, true)) {
            throw new ImageStorageException('unsupported_mime');
        }
        if (!is_readable($tmpPath)) {
            throw new ImageStorageException('tmp_not_readable');
        }

        $img = $this->openImage($tmpPath, $originalMime);
        if ($img === null) {
            throw new ImageStorageException('cannot_decode_image');
        }

        // Reject animated GIF.
        if ($originalMime === 'image/gif') {
            $raw = @file_get_contents($tmpPath) ?: '';
            if (substr_count($raw, "\x00\x21\xF9\x04") > 1) {
                imagedestroy($img);
                throw new ImageStorageException('animated_gif_not_supported');
            }
        }

        $img = $this->resizeIfTooLarge($img);

        $dir = rtrim($this->publicRoot, '/\\') . DIRECTORY_SEPARATOR . 'uploads'
             . DIRECTORY_SEPARATOR . 'events' . DIRECTORY_SEPARATOR . $eventId;
        if (!is_dir($dir) && !mkdir($dir, 0o755, true) && !is_dir($dir)) {
            imagedestroy($img);
            throw new ImageStorageException('mkdir_failed');
        }

        $useWebp = $this->supportsWebp();
        $ext = $useWebp ? 'webp' : 'jpg';
        $fileName = $this->ids->generate() . '.' . $ext;
        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;

        $ok = $useWebp
            ? imagewebp($img, $filePath, self::WEBP_QUALITY)
            : imagejpeg($img, $filePath, self::JPEG_FALLBACK_QUALITY);
        imagedestroy($img);
        if (!$ok) {
            throw new ImageStorageException('write_failed');
        }

        return rtrim($this->urlPrefix, '/') . '/uploads/events/' . $eventId . '/' . $fileName;
    }

    public function deleteByUrl(string $url): void
    {
        // URL shape: {prefix}/uploads/events/{eventId}/{fileName}
        $relative = parse_url($url, PHP_URL_PATH);
        if (!is_string($relative)) {
            return;
        }
        if (!preg_match('#^/uploads/events/[0-9a-f-]{36}/[A-Za-z0-9-]+\.(webp|jpg|jpeg|png|gif)$#', $relative)) {
            return;
        }
        $absolute = rtrim($this->publicRoot, '/\\') . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (is_file($absolute)) {
            @unlink($absolute);
        }
    }

    private function openImage(string $path, string $mime): \GdImage|null
    {
        return match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png'  => @imagecreatefrompng($path)  ?: null,
            'image/webp' => @imagecreatefromwebp($path) ?: null,
            'image/gif'  => @imagecreatefromgif($path)  ?: null,
            default      => null,
        };
    }

    private function resizeIfTooLarge(\GdImage $img): \GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $long = max($w, $h);
        if ($long <= self::MAX_EDGE) {
            return $img;
        }
        $scale = self::MAX_EDGE / $long;
        $nw = (int) round($w * $scale);
        $nh = (int) round($h * $scale);
        $dst = imagecreatetruecolor($nw, $nh);
        if ($dst === false) {
            return $img;
        }
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        return $dst;
    }

    private function supportsWebp(): bool
    {
        $info = gd_info();
        $webp = $info['WebP Support'] ?? false;
        return $webp === true;
    }
}
