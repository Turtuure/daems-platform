<?php

declare(strict_types=1);

namespace Daems\Domain\Forum;

use Daems\Domain\Shared\ValueObject\Uuid7;

final class ForumTopicId
{
    private function __construct(private readonly string $value) {}

    public static function generate(): self { return new self(Uuid7::generate()->value()); }
    public static function fromString(string $v): self { Uuid7::fromString($v); return new self(strtolower($v)); }

    public function value(): string { return $this->value; }
    public function __toString(): string { return $this->value; }
}
