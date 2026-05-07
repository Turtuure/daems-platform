<?php
declare(strict_types=1);

namespace Daems\Infrastructure\Module;

/**
 * Immutable value object describing a module's sidebar placement in the
 * backstage chrome. Lives at the platform-catalog level (config/modules.php),
 * not in the module's own module.json — placement is a platform concern, not
 * a module concern (different deployments may group sidebar entries
 * differently without forking the module).
 */
final class SidebarEntry
{
    public function __construct(
        private readonly string $group,
        private readonly int $order,
        private readonly string $icon,
        private readonly string $href,
    ) {
        if ($group === '') {
            throw new \InvalidArgumentException('SidebarEntry: group must be non-empty');
        }
        if ($order < 0) {
            throw new \InvalidArgumentException("SidebarEntry: order must be >= 0, got {$order}");
        }
        if ($icon === '') {
            throw new \InvalidArgumentException('SidebarEntry: icon must be non-empty');
        }
        if ($href === '' || $href[0] !== '/') {
            throw new \InvalidArgumentException("SidebarEntry: href must start with '/', got '{$href}'");
        }
    }

    public function group(): string { return $this->group; }
    public function order(): int { return $this->order; }
    public function icon(): string { return $this->icon; }
    public function href(): string { return $this->href; }
}
