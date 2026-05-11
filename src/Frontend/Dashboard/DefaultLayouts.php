<?php
declare(strict_types=1);

namespace Daems\Frontend\Dashboard;

use Daems\Domain\Dashboard\LayoutEntry;
use Daems\Domain\Dashboard\MinRole;
use Daems\Domain\Dashboard\WidgetSpan;

final class DefaultLayouts
{
    /** @return list<LayoutEntry> */
    public static function for(MinRole $role): array
    {
        return match ($role) {
            MinRole::Admin     => self::admin(),
            MinRole::Moderator => self::moderator(),
            MinRole::Gsa       => self::gsa(),
            MinRole::Member    => [], // members don't see the dashboard, but be defensive
        };
    }

    /** @return list<LayoutEntry> */
    private static function admin(): array
    {
        return [
            new LayoutEntry('core.members_kpi',         WidgetSpan::of(1)),
            new LayoutEntry('core.applications_kpi',    WidgetSpan::of(1)),
            new LayoutEntry('events.events_kpi',        WidgetSpan::of(1)),
            new LayoutEntry('projects.projects_kpi',    WidgetSpan::of(1)),
            new LayoutEntry('core.member_growth_chart', WidgetSpan::of(3)),
            new LayoutEntry('core.quick_actions',       WidgetSpan::of(1)),
            new LayoutEntry('core.pending_apps_list',   WidgetSpan::of(2)),
            new LayoutEntry('core.activity_feed',       WidgetSpan::of(2)),
        ];
    }

    /** @return list<LayoutEntry> */
    private static function moderator(): array
    {
        return [
            new LayoutEntry('forum.reports_kpi',        WidgetSpan::of(1)),
            new LayoutEntry('forum.posts_today_kpi',    WidgetSpan::of(1)),
            new LayoutEntry('forum.flagged_users_kpi',  WidgetSpan::of(1)),
            new LayoutEntry('forum.pinned_topics_kpi',  WidgetSpan::of(1)),
            new LayoutEntry('forum.reports_queue',      WidgetSpan::of(4)),
            new LayoutEntry('forum.recent_posts_list',  WidgetSpan::of(2)),
            new LayoutEntry('core.activity_feed',       WidgetSpan::of(2)),
        ];
    }

    /** @return list<LayoutEntry> */
    private static function gsa(): array
    {
        // GSA sees a hybrid: the active tenant's admin KPIs on top (so the
        // dashboard reflects WHERE you are, not just WHAT you are), then the
        // cross-tenant platform-overview widgets below.
        return [
            // Active-tenant KPIs (real data via GetAdminStats)
            new LayoutEntry('core.members_kpi',               WidgetSpan::of(1)),
            new LayoutEntry('core.applications_kpi',          WidgetSpan::of(1)),
            new LayoutEntry('events.events_kpi',              WidgetSpan::of(1)),
            new LayoutEntry('projects.projects_kpi',          WidgetSpan::of(1)),
            new LayoutEntry('core.member_growth_chart',       WidgetSpan::of(3)),
            new LayoutEntry('core.pending_apps_list',         WidgetSpan::of(1)),
            // Platform-wide overview (cross-tenant)
            new LayoutEntry('platform.tenants_kpi',           WidgetSpan::of(1)),
            new LayoutEntry('platform.users_kpi',             WidgetSpan::of(1)),
            new LayoutEntry('platform.db_size_kpi',           WidgetSpan::of(1)),
            new LayoutEntry('platform.uptime_kpi',            WidgetSpan::of(1)),
            new LayoutEntry('platform.tenant_status_grid',    WidgetSpan::of(4)),
            new LayoutEntry('platform.tenant_activity_chart', WidgetSpan::of(2)),
            new LayoutEntry('core.activity_feed',             WidgetSpan::of(2)),
        ];
    }
}
