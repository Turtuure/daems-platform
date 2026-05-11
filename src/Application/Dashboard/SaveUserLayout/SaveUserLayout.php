<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\SaveUserLayout;

use Daems\Domain\Dashboard\Exception\InvalidLayout;
use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\UserDashboard;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Dashboard\WidgetSpan;

final class SaveUserLayout
{
    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly UserDashboardRepositoryInterface $repo,
        private readonly \DateTimeImmutable $now,
    ) {}

    public function execute(SaveUserLayoutInput $in): void
    {
        $allowedModules = array_merge($in->enabledModules, ['platform']);
        $seenIds = [];
        $entries = [];

        foreach ($in->rawLayout as $raw) {
            if (!isset($raw['widget_id'], $raw['span'])) {
                throw InvalidLayout::malformedEntry();
            }
            $id = (string) $raw['widget_id'];
            $span = (int) $raw['span'];

            if (isset($seenIds[$id])) {
                throw InvalidLayout::duplicateWidget($id);
            }
            $seenIds[$id] = true;

            if (!$this->registry->has($id)) {
                throw InvalidLayout::unknownWidget($id);
            }
            $widget = $this->registry->find($id);

            if (!$widget->minRole()->isReachableBy($in->userRole)) {
                throw InvalidLayout::aboveRole($id);
            }
            $module = $widget->module();
            if ($module !== null && !in_array($module, $allowedModules, true)) {
                throw InvalidLayout::disabledModule($id, $module);
            }
            if ($span < 1 || $span > 4) {
                throw InvalidLayout::invalidSpan($id, $span);
            }

            $entries[] = new LayoutEntry($id, WidgetSpan::of($span));
        }

        $this->repo->save(new UserDashboard(
            $in->userId,
            $in->tenantId,
            $entries,
            $this->now,
        ));
    }
}
