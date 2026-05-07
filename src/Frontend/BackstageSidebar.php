<?php

declare(strict_types=1);

namespace Daems\Frontend;

use Daems\Domain\Tenant\Tenant;
use Daems\Domain\Tenant\TenantModuleResolver;
use Daems\Domain\User\User;
use Daems\Infrastructure\Module\ModuleRegistry;

/**
 * Builds the ordered list of backstage sidebar entries for a given
 * (tenant, user) pair.
 *
 * Combines:
 *   - hardcoded shell items (Dashboard, Settings) — always present;
 *   - the platform group (Tenants admin) — only for platform admins;
 *   - module sidebar items — only for modules currently active for the tenant
 *     (ENABLED or CORE) AND that declare a sidebar entry in their platform
 *     catalog.
 *
 * Search is intentionally NOT in the sidebar — header Ctrl+K is the primary
 * search affordance per the project's "header search is canonical" policy
 * (`feedback_backstage_search_ui.md`). The /backstage/search?q=... route
 * remains as the typeahead "see all results" landing page.
 *
 * Items are sorted by group rank first, then by intra-group `order`. Group
 * ranks: shell=0, platform=1, members=2, content=3, community=4,
 * governance=5, anything else=99 (group rank is for stable cross-group
 * ordering — the actual group label remains whatever the manifest declared).
 */
final class BackstageSidebar
{
    private const GROUP_RANK = [
        'shell'      => 0,
        'platform'   => 1,
        'members'    => 2,
        'content'    => 3,
        'community'  => 4,
        'governance' => 5,
    ];
    private const GROUP_RANK_DEFAULT = 99;

    public function __construct(
        private readonly ModuleRegistry $registry,
        private readonly TenantModuleResolver $resolver,
    ) {}

    /**
     * @return list<array{group: string, label_key: string, href: string, icon: string, order: int}>
     */
    public function buildFor(Tenant $tenant, User $user): array
    {
        $items = [];

        // 1. Hardcoded shell items. Search is intentionally NOT here — see class
        //    docblock; header Ctrl+K is the canonical search affordance.
        $items[] = ['group' => 'shell', 'label_key' => 'shell.dashboard', 'href' => '/backstage/',         'icon' => 'home',     'order' => 0];
        $items[] = ['group' => 'shell', 'label_key' => 'shell.settings',  'href' => '/backstage/settings', 'icon' => 'settings', 'order' => 999];

        // 2. Platform group — only for platform admins.
        if ($user->isPlatformAdmin()) {
            $items[] = [
                'group'     => 'platform',
                'label_key' => 'platform.tenants.title',
                'href'      => '/backstage/platform/tenants',
                'icon'      => 'layers',
                'order'     => 0,
            ];
        }

        // 3. Module items — only for active modules that declare a sidebar entry.
        foreach ($this->registry->all() as $name => $manifest) {
            $sidebar = $manifest->sidebar();
            if ($sidebar === null) {
                continue;
            }
            if (!$this->resolver->stateFor($tenant->id, $name)->isActive()) {
                continue;
            }
            $items[] = [
                'group'     => $sidebar->group(),
                'label_key' => $manifest->nameKey() ?? "modules.{$name}.name",
                'href'      => $sidebar->href(),
                'icon'      => $sidebar->icon(),
                'order'     => $sidebar->order(),
            ];
        }

        // 4. Group-aware sort: by group rank, then intra-group order.
        usort(
            $items,
            static function (array $a, array $b): int {
                $rankA = self::GROUP_RANK[$a['group']] ?? self::GROUP_RANK_DEFAULT;
                $rankB = self::GROUP_RANK[$b['group']] ?? self::GROUP_RANK_DEFAULT;
                if ($rankA !== $rankB) {
                    return $rankA <=> $rankB;
                }
                return $a['order'] <=> $b['order'];
            },
        );

        return array_values($items);
    }

    /**
     * Group rank for ordering. Exposed so a template can render group dividers
     * in the same order the items appear.
     */
    public static function groupRank(string $group): int
    {
        return self::GROUP_RANK[$group] ?? self::GROUP_RANK_DEFAULT;
    }
}
