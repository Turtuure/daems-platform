<?php

declare(strict_types=1);

namespace Daems\Frontend;

/**
 * Format a raw member_number string for display per tenant convention.
 *
 * - Strips leading zeros from the raw number (a single "0" survives)
 * - Joins with the tenant prefix via "-" if a prefix is set
 * - Returns the bare stripped number when prefix is null/empty
 */
final class MemberNumberFormatter
{
    public static function format(?string $rawNumber, ?string $prefix): string
    {
        $raw = $rawNumber ?? '';
        if ($raw === '') {
            return '';
        }
        $stripped = ltrim($raw, '0');
        if ($stripped === '') {
            $stripped = '0';
        }

        $prefix = is_string($prefix) ? trim($prefix) : '';
        if ($prefix === '') {
            return $stripped;
        }
        return $prefix . '-' . $stripped;
    }
}
