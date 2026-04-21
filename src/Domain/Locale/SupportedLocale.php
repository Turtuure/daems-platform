<?php
declare(strict_types=1);

namespace Daems\Domain\Locale;

final class SupportedLocale
{
    private const SUPPORTED = ['fi_FI', 'en_GB', 'sw_TZ'];
    /** @var array<string, string> */
    private const SHORT_MAP = ['fi' => 'fi_FI', 'en' => 'en_GB', 'sw' => 'sw_TZ'];

    public const UI_DEFAULT = 'fi_FI';
    public const CONTENT_FALLBACK = 'en_GB';

    private function __construct(private readonly string $value)
    {
    }

    public static function fromString(string $input): self
    {
        $normalized = str_replace('-', '_', trim($input));
        if (!in_array($normalized, self::SUPPORTED, true)) {
            throw InvalidLocaleException::forValue($input);
        }
        return new self($normalized);
    }

    public static function fromShort(string $short): self
    {
        $s = strtolower(trim($short));
        if (!isset(self::SHORT_MAP[$s])) {
            throw InvalidLocaleException::forValue($short);
        }
        return new self(self::SHORT_MAP[$s]);
    }

    public static function uiDefault(): self
    {
        return new self(self::UI_DEFAULT);
    }

    public static function contentFallback(): self
    {
        return new self(self::CONTENT_FALLBACK);
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(fn(string $v) => new self($v), self::SUPPORTED);
    }

    /** @return list<string> */
    public static function supportedValues(): array
    {
        return self::SUPPORTED;
    }

    public static function isSupported(string $input): bool
    {
        $normalized = str_replace('-', '_', trim($input));
        return in_array($normalized, self::SUPPORTED, true);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
