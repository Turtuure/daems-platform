<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\GetUserLayout;

use Daems\Domain\Dashboard\LayoutEntry;

final class GetUserLayoutOutput
{
    /** @param list<LayoutEntry> $layout */
    public function __construct(
        private readonly array $layout,
        private readonly bool $isDefault,
    ) {}

    /** @return list<LayoutEntry> */
    public function layout(): array { return $this->layout; }
    public function isDefault(): bool { return $this->isDefault; }
}
