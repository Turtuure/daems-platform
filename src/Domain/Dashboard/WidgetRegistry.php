<?php
declare(strict_types=1);

namespace Daems\Domain\Dashboard;

use Daems\Domain\Dashboard\Exception\UnknownWidget;
use Daems\Domain\Dashboard\Exception\WidgetAlreadyRegistered;

final class WidgetRegistry
{
    /** @var array<string, Widget> */
    private array $widgets = [];

    public function register(Widget $w): void
    {
        $id = $w->id();
        if (isset($this->widgets[$id])) {
            throw new WidgetAlreadyRegistered("Widget '{$id}' already registered");
        }
        $this->widgets[$id] = $w;
    }

    public function find(string $id): Widget
    {
        return $this->widgets[$id] ?? throw new UnknownWidget("Unknown widget '{$id}'");
    }

    public function has(string $id): bool
    {
        return isset($this->widgets[$id]);
    }

    /**
     * @param list<string> $enabledModules — 'platform' module is implicit (always available).
     * @return list<Widget>
     */
    public function filterFor(MinRole $userRole, array $enabledModules): array
    {
        $allowedModules = array_merge($enabledModules, ['platform']);
        $out = [];
        foreach ($this->widgets as $w) {
            if (!$w->minRole()->isReachableBy($userRole)) {
                continue;
            }
            if ($w->module() !== null && !in_array($w->module(), $allowedModules, true)) {
                continue;
            }
            $out[] = $w;
        }
        return $out;
    }

    /** @return list<Widget> */
    public function all(): array
    {
        return array_values($this->widgets);
    }
}
