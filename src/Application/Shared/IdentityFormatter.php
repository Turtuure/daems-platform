<?php

declare(strict_types=1);

namespace Daems\Application\Shared;

final class IdentityFormatter
{
    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach ($parts as $p) {
            if ($p !== '') {
                $letters .= strtoupper(substr($p, 0, 1));
            }
            if (strlen($letters) >= 2) {
                break;
            }
        }
        return $letters === '' ? '??' : $letters;
    }
}
