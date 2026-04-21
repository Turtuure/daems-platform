<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class TranslationMap
{
    /**
     * @param array<string, array<string, ?string>|null> $data Keyed by locale value;
     *   inner array is field => value, or null if locale has no row.
     */
    public function __construct(private readonly array $data)
    {
    }

    /**
     * @param list<string> $fields
     */
    public function view(
        SupportedLocale $requested,
        SupportedLocale $fallback,
        array $fields,
    ): EntityTranslationView {
        $reqRow = $this->data[$requested->value()] ?? null;
        $fbRow  = $this->data[$fallback->value()] ?? null;

        $values = $fallbackFlags = $missingFlags = [];
        foreach ($fields as $f) {
            $reqVal = self::nonEmpty($reqRow[$f] ?? null);
            if ($reqVal !== null) {
                $values[$f] = $reqVal;
                $fallbackFlags[$f] = false;
                $missingFlags[$f]  = false;
                continue;
            }
            $fbVal = self::nonEmpty($fbRow[$f] ?? null);
            if ($fbVal !== null && !$requested->equals($fallback)) {
                $values[$f] = $fbVal;
                $fallbackFlags[$f] = true;
                $missingFlags[$f]  = false;
                continue;
            }
            $values[$f] = null;
            $fallbackFlags[$f] = false;
            $missingFlags[$f]  = true;
        }
        return new EntityTranslationView($values, $fallbackFlags, $missingFlags);
    }

    /**
     * @param list<string> $fields
     * @return array<string, array{filled: int, total: int}>
     */
    public function coverage(array $fields): array
    {
        $out = [];
        foreach (SupportedLocale::supportedValues() as $loc) {
            $row = $this->data[$loc] ?? null;
            $filled = 0;
            foreach ($fields as $f) {
                if (self::nonEmpty($row[$f] ?? null) !== null) {
                    $filled++;
                }
            }
            $out[$loc] = ['filled' => $filled, 'total' => count($fields)];
        }
        return $out;
    }

    /** @return array<string, array<string, ?string>|null> */
    public function raw(): array
    {
        return $this->data;
    }

    /** @return array<string, ?string>|null */
    public function rowFor(SupportedLocale $locale): ?array
    {
        return $this->data[$locale->value()] ?? null;
    }

    private static function nonEmpty(?string $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $trim = trim($v);
        return $trim === '' ? null : $v;
    }
}
