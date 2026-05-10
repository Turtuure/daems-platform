<?php
declare(strict_types=1);

namespace Daems\Application\Dashboard\GetUserLayout;

use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\UserDashboardRepositoryInterface;
use Daems\Domain\Dashboard\WidgetRegistry;
use Daems\Domain\Tenant\TenantId;
use Daems\Domain\User\UserId;
use Daems\Frontend\Dashboard\DefaultLayouts;

final class GetUserLayout
{
    public function __construct(
        private readonly WidgetRegistry $registry,
        private readonly UserDashboardRepositoryInterface $repo,
    ) {}

    /** @param list<string> $enabledModules */
    public function execute(
        UserId $userId,
        TenantId $tenantId,
        MinRole $userRole,
        array $enabledModules,
    ): GetUserLayoutOutput {
        $saved = $this->repo->findFor($userId, $tenantId);
        $isDefault = $saved === null;
        $layout = $saved !== null
            ? $saved->layout()
            : DefaultLayouts::for($userRole);

        $allowedModules = array_merge($enabledModules, ['platform']);
        $filtered = [];
        foreach ($layout as $entry) {
            if (!$this->registry->has($entry->widgetId())) {
                continue;
            }
            $widget = $this->registry->find($entry->widgetId());
            if (!$widget->minRole()->isReachableBy($userRole)) {
                continue;
            }
            $module = $widget->module();
            if ($module !== null && !in_array($module, $allowedModules, true)) {
                continue;
            }
            $filtered[] = $entry;
        }

        return new GetUserLayoutOutput($filtered, $isDefault);
    }
}
