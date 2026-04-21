<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class EntityTranslationView
{
    /**
     * @param array<string, ?string> $values
     * @param array<string, bool>    $fallback per-field flag
     * @param array<string, bool>    $missing  per-field flag
     */
    public function __construct(
        private readonly array $values,
        private readonly array $fallback,
        private readonly array $missing,
    ) {
    }

    public function field(string $name): ?string
    {
        return $this->values[$name] ?? null;
    }

    public function isFallback(string $name): bool
    {
        return $this->fallback[$name] ?? false;
    }

    public function isMissing(string $name): bool
    {
        return $this->missing[$name] ?? false;
    }

    /**
     * Flat array with per-field fallback / missing flags.
     *
     * @return array<string, mixed> flat array: {field}, {field}_fallback, {field}_missing
     */
    public function toApiPayload(): array
    {
        $out = [];
        foreach ($this->values as $name => $value) {
            $out[$name] = $value;
            $out[$name . '_fallback'] = $this->isFallback($name);
            $out[$name . '_missing'] = $this->isMissing($name);
        }
        return $out;
    }
}
