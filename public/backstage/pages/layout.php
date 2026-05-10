<?php
/**
 * Admin panel layout shell.
 *
 * Required variables (set by the including page before requiring this file):
 * @var string $pageTitle    Document title and breadcrumb current item
 * @var string $activePage   Sidebar active key: dashboard|applications|members|events|projects|forum|notifications|settings
 * @var array  $breadcrumbs  Optional: [['label'=>'...', 'url'=>'...'], ...] — last item has no 'url'
 * @var string $pageContent  Output-buffered main content HTML
 */

// Backstage guard — only GSA and admin are allowed
$__backstageRole = $_SESSION['user']['role'] ?? '';
if (empty($_SESSION['user']) || !in_array($__backstageRole, ['global_system_administrator', 'admin'], true)) {
    http_response_code(403);
    header('Location: /');
    exit;
}

// Fetch pending applications count for global toast stack
$__pendingApps = ['items' => [], 'total' => 0];
if (in_array($__backstageRole, ['global_system_administrator', 'admin'], true)) {
    try {
        $resp = ApiClient::get('/backstage/applications/pending-count');
        if (is_array($resp) && isset($resp['items'], $resp['total'])) {
            $__pendingApps = $resp;
        }
    } catch (\Throwable $e) {
        // Silent — failing to render toasts must not break the page.
    }
}

// Active tenant name for the header-bar pill, plus the effective TimePicker
// format (user override > tenant default > '24'). Both come from /auth/me.
$__tenantName            = null;
$__effectiveTimeFormat   = '24';
$__userTimeFormatOverride = null;
$__tenantDefaultTimeFmt  = '24';
if (in_array($__backstageRole, ['global_system_administrator', 'admin'], true)) {
    try {
        $me = ApiClient::get('/auth/me');
        if (is_array($me)) {
            if (isset($me['tenant']['name']) && is_string($me['tenant']['name']) && $me['tenant']['name'] !== '') {
                $__tenantName = $me['tenant']['name'];
            }
            $tf = $me['time_format'] ?? null;
            if (is_array($tf)) {
                $eff = $tf['effective'] ?? null;
                if ($eff === '12' || $eff === '24') $__effectiveTimeFormat = (string) $eff;
                $ovr = $tf['user_override'] ?? null;
                if ($ovr === '12' || $ovr === '24') $__userTimeFormatOverride = (string) $ovr;
                $td  = $tf['tenant_default'] ?? null;
                if ($td === '12' || $td === '24') $__tenantDefaultTimeFmt = (string) $td;
            }
        }
    } catch (\Throwable $e) {
        // Silent — pill simply does not render.
    }
}

// Count open forum reports for the sidebar badge. The pending-count endpoint
// already aggregates forum_report items, so no extra round-trip is needed.
$__pendingForumReportCount = 0;
foreach (($__pendingApps['items'] ?? []) as $__pItem) {
    if (is_array($__pItem) && ($__pItem['type'] ?? '') === 'forum_report') {
        $__pendingForumReportCount++;
    }
}

use Daems\Frontend\I18n;

$__locale     = I18n::locale();          // fi_FI | en_GB | sw_TZ
$__htmlLang   = strtolower(substr($__locale, 0, 2)); // fi | en | sw
$__bcp47      = str_replace('_', '-', $__locale);    // fi-FI | en-GB | sw-TZ

$__adminUser   = $_SESSION['user'];
$__adminName   = htmlspecialchars($__adminUser['name'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$__isPlatformAdmin = ($__adminUser['is_platform_admin'] ?? false) === true
                  || $__backstageRole === 'global_system_administrator';
$__adminRoleShort = $__isPlatformAdmin
    ? I18n::t('backstage.layout.user.role.gsa_short')
    : I18n::t('backstage.layout.user.role.admin_short');
$__adminRoleFull = $__isPlatformAdmin
    ? I18n::t('backstage.layout.user.role.gsa_full')
    : I18n::t('backstage.layout.user.role.admin_full');

// Translate the page title via key (preferred) — fall back to literal $pageTitle
// for any older module page that hasn't been migrated yet.
$__titleKeyOrLiteral = $pageTitle ?? 'backstage.title.dashboard';
$__titleResolved = I18n::t($__titleKeyOrLiteral);
if ($__titleResolved === $__titleKeyOrLiteral && !str_starts_with($__titleKeyOrLiteral, 'backstage.')) {
    $__titleResolved = $__titleKeyOrLiteral; // literal value, leave as-is
}
$__pageTitle   = htmlspecialchars($__titleResolved, ENT_QUOTES, 'UTF-8');

$__activePage  = $activePage ?? 'dashboard';
$__breadcrumbs = $breadcrumbs ?? [];
$__safeId      = preg_replace('/[^a-f0-9\-]/', '', $__adminUser['id'] ?? '');
$__avatarDisk  = __DIR__ . '/../../uploads/avatars/' . $__safeId . '.webp';
$__avatarUrl   = file_exists($__avatarDisk)
    ? '/uploads/avatars/' . $__safeId . '.webp?v=' . filemtime($__avatarDisk)
    : '';
$__initials    = strtoupper(mb_substr($__adminUser['name'] ?? 'A', 0, 1) . mb_substr(strstr($__adminUser['name'] ?? '', ' ') ?: '', 1, 1));
$__viewAs      = $_SESSION['view_as_role'] ?? null;
$__viewAsLabels = [
    'guest'                => I18n::t('backstage.layout.user.viewas.guest'),
    'registered'           => I18n::t('backstage.layout.user.viewas.registered'),
    'member'               => I18n::t('backstage.layout.user.viewas.member'),
    'supporter'            => I18n::t('backstage.layout.user.viewas.supporter'),
    'moderator'            => I18n::t('backstage.layout.user.viewas.moderator'),
    'administrator'        => I18n::t('backstage.layout.user.viewas.administrator'),
    'system_administrator' => I18n::t('backstage.layout.user.viewas.system_administrator'),
];

// Detect direct-access on the platform host vs delegated via tenant frontend.
// On platform host, /profile, /view-as[/-exit] are not available (those are
// society routes). Hide those menu items in fallback mode.
$__hostHeader = (string) ($_SERVER['HTTP_HOST'] ?? '');
$__isPlatformHost = str_starts_with($__hostHeader, 'daems-platform.')
                 || str_starts_with($__hostHeader, 'sahegroup-platform.');

/** Sidebar nav item helper — returns 'is-active' when page matches */
$__isActive = static fn(string $key): string => $__activePage === $key ? 'is-active' : '';

// ---------------------------------------------------------------------------
// Wave G3 — sidebar items via BackstageSidebar (module-aware nav).
//
// _module-guard.php stashes the bootstrap container + resolved tenant in
// $GLOBALS during the router pass. We reach back into them here to build
// the dynamic nav. If anything is missing (very early bootstrap, or the
// fallback path before _module-guard.php was wired) we degrade to an
// empty list and the legacy hardcoded markup fills in.
//
// Items shape (from BackstageSidebar):
//   list<array{group, label_key, href, icon, order}>
// ---------------------------------------------------------------------------
$__sidebarItems = [];
$__sidebarIcons = [];
try {
    $__container = $GLOBALS['daems_backstage_container'] ?? null;
    $__tenant    = $GLOBALS['daems_backstage_tenant']    ?? null;
    if ($__container instanceof \Daems\Infrastructure\Framework\Container\Container
        && $__tenant instanceof \Daems\Domain\Tenant\Tenant
    ) {
        $__userIdRaw = $__adminUser['id'] ?? null;
        if (is_string($__userIdRaw) && $__userIdRaw !== '') {
            /** @var \Daems\Domain\User\UserRepositoryInterface $__userRepo */
            $__userRepo = $__container->make(\Daems\Domain\User\UserRepositoryInterface::class);
            $__userEntity = $__userRepo->findById($__userIdRaw);
            if ($__userEntity instanceof \Daems\Domain\User\User) {
                /** @var \Daems\Frontend\BackstageSidebar $__sidebarBuilder */
                $__sidebarBuilder = $__container->make(\Daems\Frontend\BackstageSidebar::class);
                $__sidebarItems   = $__sidebarBuilder->buildFor($__tenant, $__userEntity);
                $__sidebarIcons   = (array) require __DIR__ . '/_sidebar-icons.php';
            }
        }
    }
} catch (\Throwable $__e) {
    // Sidebar build is non-critical — fail open with the hardcoded fallback.
    $__sidebarItems = [];
    $__sidebarIcons = [];
}

/** Map item href → active-page key the rest of the layout uses. */
$__hrefToActive = [
    '/backstage/'         => 'dashboard',
    '/backstage'          => 'dashboard',
    '/backstage/search'   => 'search',
    '/backstage/settings' => 'settings',
    '/backstage/members'  => 'members',
    '/backstage/events'   => 'events',
    '/backstage/projects' => 'projects',
    '/backstage/forum'    => 'forum',
    '/backstage/insights' => 'insights',
    '/backstage/platform/tenants' => 'platform-tenants',
];

/** Group items by `group` while preserving the BackstageSidebar order. */
$__groupedItems = [];
foreach ($__sidebarItems as $__si) {
    $__groupedItems[$__si['group']][] = $__si;
}

/** Translate an icon name to its SVG inner markup, falling back to a dot. */
$__renderIcon = static function (string $name) use ($__sidebarIcons): string {
    return is_string($__sidebarIcons[$name] ?? null)
        ? (string) $__sidebarIcons[$name]
        : '<circle cx="12" cy="12" r="2"/>';
};
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($__htmlLang, ENT_QUOTES, 'UTF-8') ?>" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $__pageTitle ?> — <?= I18n::e('backstage.layout.title_suffix') ?></title>
    <link rel="shortcut icon" href="/backstage/assets/img/brand/daems-favicon.svg">
    <meta name="daems-time-format" content="<?= htmlspecialchars($__effectiveTimeFormat, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="daems-time-format-override" content="<?= htmlspecialchars((string) $__userTimeFormatOverride, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="daems-time-format-tenant-default" content="<?= htmlspecialchars($__tenantDefaultTimeFmt, ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="/backstage/assets/css/daems-backstage.css">
    <link rel="stylesheet" href="/backstage/assets/css/daems-backstage-system.css">
    <link rel="stylesheet" href="/backstage/assets/css/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/backstage/assets/css/daems-search.css">
    <link rel="stylesheet" href="/backstage/pages/toasts.css">
    <link rel="stylesheet" href="/modules-shared/date-picker/date-picker.css">
    <script src="/modules-shared/date-picker/date-picker.js" defer></script>
    <link rel="stylesheet" href="/modules-shared/time-picker/time-picker.css">
    <link rel="stylesheet" href="/modules-shared/components/cards/kpi-card/kpi-card.css">
    <script src="/modules-shared/time-picker/time-picker.js" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/apexcharts@3/dist/apexcharts.min.js"></script>
    <script src="/backstage/assets/js/vendor/sortable.min.js" defer></script>
    <script src="/backstage/assets/js/dashboard-edit.js" defer></script>
    <script>
    (function(){
        var t = localStorage.getItem('daems-admin-theme') || 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
</head>
<body class="daems-admin">
<a href="#main-content" class="skip-link"><?= I18n::e('backstage.layout.skip_to_content') ?></a>

<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebar-overlay" aria-hidden="true"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar" role="navigation" aria-label="<?= I18n::e('backstage.layout.nav_aria') ?>">
    <div class="sidebar__brand">
        <a href="/backstage" class="sidebar__brand-link">
            <img src="/backstage/assets/img/brand/daems-logo-text-black.svg"
                 alt="DAEMS"
                 class="sidebar__brand-logo sidebar__brand-logo--text sidebar__brand-logo--light">
            <img src="/backstage/assets/img/brand/daems-logo-text-white.svg"
                 alt="DAEMS"
                 class="sidebar__brand-logo sidebar__brand-logo--text sidebar__brand-logo--dark">
            <img src="/backstage/assets/img/brand/daems-icon-black.svg"
                 alt="DAEMS"
                 class="sidebar__brand-logo sidebar__brand-logo--icon sidebar__brand-logo--light">
            <img src="/backstage/assets/img/brand/daems-icon-white.svg"
                 alt="DAEMS"
                 class="sidebar__brand-logo sidebar__brand-logo--icon sidebar__brand-logo--dark">
        </a>
        <button class="sidebar__collapse" id="sidebar-collapse" aria-label="<?= I18n::e('backstage.layout.nav.collapse_sidebar') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                <polyline points="15 18 9 12 15 6"/>
            </svg>
        </button>
    </div>

    <nav class="sidebar__nav">
<?php if (!empty($__groupedItems)): /* Wave G3 — module-aware sidebar render */ ?>
        <?php
        // Group label key. Wave J added the localised keys; missing keys
        // gracefully render as the key itself.
        $__groupLabelKey = static fn(string $g): string => 'sidebar.group.' . $g;
        // Hide the section label for single-item groups (Members, Forum) so
        // we don't get a redundant "Members > Members" stack of labels. Group
        // headers only render when the group has 2+ items.
        foreach ($__groupedItems as $__groupName => $__items):
            $__hideGroupLabel = count($__items) < 2;
        ?>
        <div class="sidebar__section" data-group="<?= htmlspecialchars((string) $__groupName, ENT_QUOTES, 'UTF-8') ?>">
            <?php if (!$__hideGroupLabel): ?>
            <span class="sidebar__section-label"><?= htmlspecialchars(I18n::t($__groupLabelKey($__groupName)), ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
            <ul class="sidebar__list" role="list">
                <?php foreach ($__items as $__item):
                    $__href      = (string) $__item['href'];
                    $__labelKey  = (string) $__item['label_key'];
                    $__iconName  = (string) $__item['icon'];
                    $__activeKey = $__hrefToActive[$__href] ?? '';
                    $__isCurrent = $__activeKey !== '' && $__activePage === $__activeKey;
                    ?>
                <li>
                    <a href="<?= htmlspecialchars($__href, ENT_QUOTES, 'UTF-8') ?>"
                       class="sidebar__item <?= $__isCurrent ? 'is-active' : '' ?>"
                       <?= $__isCurrent ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <?= $__renderIcon($__iconName) ?>
                        </svg>
                        <span class="sidebar__label"><?= htmlspecialchars(I18n::t($__labelKey), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($__href === '/backstage/members' && !empty($__pendingApps) && (int) ($__pendingApps['total'] ?? 0) > 0): ?>
                            <span class="sidebar__badge sidebar__badge--danger" id="members-pending-badge"><?= (int) $__pendingApps['total'] ?></span>
                        <?php elseif ($__href === '/backstage/forum' && $__pendingForumReportCount > 0): ?>
                            <span class="sidebar__badge sidebar__badge--danger" id="forum-badge"
                                  title="<?= I18n::e('backstage.layout.forum.open_reports') ?>"><?= (int) $__pendingForumReportCount ?></span>
                        <?php elseif ($__href === '/backstage/notifications' && !empty($__pendingApps) && isset($__pendingApps['total']) && (int) $__pendingApps['total'] > 0): ?>
                            <span class="sidebar__badge sidebar__badge--danger"><?= (int) $__pendingApps['total'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
<?php else: /* Fallback — legacy hardcoded sidebar when BackstageSidebar is unavailable */ ?>
        <!-- Main -->
        <div class="sidebar__section">
            <span class="sidebar__section-label"><?= I18n::e('backstage.layout.section.main') ?></span>
            <ul class="sidebar__list" role="list">
                <li>
                    <a href="/backstage" class="sidebar__item <?= $__isActive('dashboard') ?>"
                       <?= $__activePage === 'dashboard' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
                            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.dashboard') ?></span>
                    </a>
                </li>
                <li>
                    <a href="/backstage/members" class="sidebar__item <?= $__isActive('members') ?>"
                       <?= $__activePage === 'members' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                            <circle cx="9" cy="7" r="4"/>
                            <path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.members') ?></span>
                        <?php if (!empty($__pendingApps) && isset($__pendingApps['total']) && (int) $__pendingApps['total'] > 0): ?>
                            <span class="sidebar__badge sidebar__badge--danger" id="members-pending-badge"><?= (int) $__pendingApps['total'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="/backstage/events" class="sidebar__item <?= $__isActive('events') ?>"
                       <?= $__activePage === 'events' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.events') ?></span>
                    </a>
                </li>
                <li>
                    <a href="/backstage/projects" class="sidebar__item <?= $__isActive('projects') ?>"
                       <?= $__activePage === 'projects' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.projects') ?></span>
                    </a>
                </li>
                <li>
                    <a href="/backstage/insights" class="sidebar__item <?= $__isActive('insights') ?>"
                       <?= $__activePage === 'insights' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M12 20h9M12 4h9M3 8h4l2 4-2 4H3z"/>
                            <circle cx="5" cy="12" r="1" fill="currentColor" stroke="none"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.insights') ?></span>
                    </a>
                </li>
                <li>
                    <a href="/backstage/forum" class="sidebar__item <?= $__isActive('forum') ?>"
                       <?= $__activePage === 'forum' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.forum') ?></span>
                        <?php if ($__pendingForumReportCount > 0): ?>
                            <span class="sidebar__badge sidebar__badge--danger" id="forum-badge"
                                  title="<?= I18n::e('backstage.layout.forum.open_reports') ?>"><?= (int) $__pendingForumReportCount ?></span>
                        <?php endif; ?>
                    </a>
                </li>
            </ul>
        </div>

        <!-- System -->
        <div class="sidebar__section">
            <span class="sidebar__section-label"><?= I18n::e('backstage.layout.section.system') ?></span>
            <ul class="sidebar__list" role="list">
                <li>
                    <a href="/backstage/notifications" class="sidebar__item <?= $__isActive('notifications') ?>"
                       <?= $__activePage === 'notifications' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.notifications') ?></span>
                        <?php if (!empty($__pendingApps) && isset($__pendingApps['total']) && (int) $__pendingApps['total'] > 0): ?>
                            <span class="sidebar__badge sidebar__badge--danger"><?= (int) $__pendingApps['total'] ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li>
                    <a href="/backstage/settings" class="sidebar__item <?= $__isActive('settings') ?>"
                       <?= $__activePage === 'settings' ? 'aria-current="page"' : '' ?>>
                        <svg class="sidebar__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <circle cx="12" cy="12" r="3"/>
                            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                        <span class="sidebar__label"><?= I18n::e('backstage.layout.nav.settings') ?></span>
                    </a>
                </li>
            </ul>
        </div>
<?php endif; /* end module-aware vs legacy fallback */ ?>
    </nav>

    <div class="sidebar__footer">
        <a href="#" class="sidebar__footer-link sidebar__footer-link--report" aria-label="<?= I18n::e('backstage.layout.nav.report_issue') ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
            </svg>
            <span><?= I18n::e('backstage.layout.nav.report_issue') ?></span>
        </a>
    </div>
</aside>

<!-- Main area -->
<div class="main-area">
    <?php if (!$__isPlatformHost && $__viewAs !== null): ?>
    <div class="view-as-banner" role="status">
        <i class="bi bi-eye view-as-banner__icon" aria-hidden="true"></i>
        <span class="view-as-banner__text">
            <?= I18n::e('backstage.layout.viewas.banner_prefix') ?>
            <strong><?= htmlspecialchars($__viewAsLabels[$__viewAs] ?? $__viewAs, ENT_QUOTES, 'UTF-8') ?></strong>
        </span>
        <a href="/view-as-exit" class="view-as-banner__exit">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
            <?= I18n::e('backstage.layout.viewas.exit') ?>
        </a>
    </div>
    <?php endif; ?>
    <header class="header-bar">
        <div class="header-bar__left">
            <button class="sidebar-toggle" id="sidebar-toggle" aria-label="<?= I18n::e('backstage.layout.nav.open_navigation') ?>" aria-expanded="false">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>
                </svg>
            </button>

            <button class="search-trigger" id="search-trigger" aria-label="<?= I18n::e('backstage.layout.search.button_aria') ?>">
                <svg class="search-trigger__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                <span class="search-trigger__text"><?= I18n::e('backstage.layout.search.button_text') ?></span>
                <kbd class="search-trigger__kbd">Ctrl K</kbd>
            </button>

            <!-- Breadcrumb -->
            <nav class="breadcrumb" aria-label="<?= I18n::e('backstage.layout.breadcrumb.aria') ?>">
                <a href="/backstage" class="breadcrumb__link"><?= I18n::e('backstage.layout.breadcrumb.root') ?></a>
                <?php foreach ($__breadcrumbs as $crumb): ?>
                    <span class="breadcrumb__separator" aria-hidden="true">/</span>
                    <?php if (isset($crumb['url'])): ?>
                        <a href="<?= htmlspecialchars($crumb['url'], ENT_QUOTES, 'UTF-8') ?>" class="breadcrumb__link">
                            <?= htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php else: ?>
                        <span class="breadcrumb__current" aria-current="page">
                            <?= htmlspecialchars($crumb['label'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($__breadcrumbs) && $__activePage !== 'dashboard'): ?>
                    <span class="breadcrumb__separator" aria-hidden="true">/</span>
                    <span class="breadcrumb__current" aria-current="page"><?= $__pageTitle ?></span>
                <?php endif; ?>
            </nav>
        </div>

        <div class="header-bar__right">
            <?php if ($__tenantName !== null): ?>
                <a href="/" class="header-bar__tenant" title="<?= I18n::e('backstage.layout.tenant.public_site_title') ?>">
                    <span class="header-bar__tenant-name"><?= htmlspecialchars($__tenantName, ENT_QUOTES, 'UTF-8') ?></span>
                    <svg class="header-bar__tenant-icon" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>
                    </svg>
                </a>
            <?php endif; ?>

            <!-- Theme toggle -->
            <button class="header-bar__action" id="theme-toggle" aria-label="<?= I18n::e('backstage.layout.theme.toggle') ?>">
                <svg class="theme-icon theme-icon--dark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <svg class="theme-icon theme-icon--light" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
            </button>

            <!-- User dropdown -->
            <div class="user-dropdown" id="user-dropdown">
                <button class="user-dropdown__trigger" id="user-dropdown-trigger" aria-haspopup="true" aria-expanded="false">
                    <div class="user-dropdown__avatar">
                        <?php if ($__avatarUrl): ?>
                            <img src="<?= htmlspecialchars($__avatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="">
                        <?php else: ?>
                            <?= htmlspecialchars($__initials, ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                    <div class="user-dropdown__info">
                        <span class="user-dropdown__name"><?= $__adminName ?></span>
                        <span class="user-dropdown__role" title="<?= htmlspecialchars($__adminRoleFull, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($__adminRoleShort, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <svg class="user-dropdown__chevron" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <polyline points="6 9 12 15 18 9"/>
                    </svg>
                </button>
                <div class="user-dropdown__menu" role="menu">
                    <?php if (!$__isPlatformHost): ?>
                    <a href="/profile" class="user-dropdown__item" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                        </svg>
                        <?= I18n::e('backstage.layout.user.profile') ?>
                    </a>
                    <div class="user-dropdown__divider"></div>
                    <?php endif; ?>
                    <a href="/" class="user-dropdown__item" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                            <polyline points="9 22 9 12 15 12 15 22"/>
                        </svg>
                        <?= I18n::e('backstage.layout.user.frontend') ?>
                    </a>
                    <div class="user-dropdown__divider"></div>
                    <?php if (!$__isPlatformHost): ?>
                    <div class="user-dropdown__submenu-wrap" data-submenu>
                        <button type="button" class="user-dropdown__item user-dropdown__submenu-trigger" aria-haspopup="menu" aria-expanded="false">
                            <i class="bi bi-person-badge" aria-hidden="true"></i>
                            <span class="user-dropdown__submenu-label"><?= I18n::e('backstage.layout.user.view_as') ?></span>
                            <svg class="user-dropdown__submenu-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="9 6 15 12 9 18"/>
                            </svg>
                        </button>
                        <div class="user-dropdown__submenu" role="menu">
                            <?php foreach ($__viewAsLabels as $__role => $__label): ?>
                            <a href="/view-as?role=<?= rawurlencode($__role) ?>" class="user-dropdown__item" role="menuitem">
                                <?php if ($__viewAs === $__role): ?>
                                <i class="bi bi-check2" aria-hidden="true"></i>
                                <?php else: ?>
                                <i class="bi bi-person-badge opacity-50" aria-hidden="true"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($__label, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?php endforeach; ?>
                            <?php if ($__viewAs): ?>
                            <div class="user-dropdown__divider"></div>
                            <a href="/view-as-exit" class="user-dropdown__item" role="menuitem">
                                <i class="bi bi-x-lg" aria-hidden="true"></i>
                                <?= I18n::e('backstage.layout.user.exit_view_as') ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php
                    $__currentLocale = \Daems\Frontend\I18n::locale();
                    $__currentPath = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
                    $__qsBase = $_GET;
                    $__localeFlagFiles = [
                        'fi_FI' => '/backstage/assets/img/flags/fi.svg',
                        'en_GB' => '/backstage/assets/img/flags/gb.svg',
                        'sw_TZ' => '/backstage/assets/img/flags/tz.svg',
                    ];
                    ?>
                    <div class="user-dropdown__submenu-wrap" data-submenu>
                        <button type="button" class="user-dropdown__item user-dropdown__submenu-trigger" aria-haspopup="menu" aria-expanded="false">
                            <i class="bi bi-translate" aria-hidden="true"></i>
                            <span class="user-dropdown__submenu-label"><?= I18n::e('backstage.layout.user.language') ?></span>
                            <svg class="user-dropdown__submenu-chevron" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <polyline points="9 6 15 12 9 18"/>
                            </svg>
                        </button>
                        <div class="user-dropdown__submenu" role="menu">
                            <?php foreach (\Daems\Frontend\I18n::SUPPORTED as $__loc):
                                $__qsBase['lang'] = $__loc;
                                $__langUrl = $__currentPath . '?' . http_build_query($__qsBase);
                                $__flagFile = $__localeFlagFiles[$__loc] ?? '';
                                $__localeName = I18n::t('locale.' . $__loc);
                            ?>
                            <a href="<?= htmlspecialchars($__langUrl, ENT_QUOTES, 'UTF-8') ?>" class="user-dropdown__item user-dropdown__item--lang" role="menuitem">
                                <?php if ($__flagFile !== ''): ?>
                                <img src="<?= $__flagFile ?>" alt="" class="user-dropdown__flag" width="20" height="15" aria-hidden="true">
                                <?php endif; ?>
                                <span class="user-dropdown__lang-name"><?= htmlspecialchars($__localeName, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if ($__loc === $__currentLocale): ?>
                                <i class="bi bi-check2 user-dropdown__active-mark" aria-hidden="true"></i>
                                <?php endif; ?>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="user-dropdown__divider"></div>
                    <a href="/backstage/logout" class="user-dropdown__item user-dropdown__item--danger" role="menuitem">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
                            <polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                        <?= I18n::e('backstage.layout.user.logout') ?>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Main content -->
    <main id="main-content" class="content<?= isset($contentClass) ? ' ' . htmlspecialchars((string)$contentClass, ENT_QUOTES, 'UTF-8') : '' ?>">
        <?= $pageContent ?? '' ?>
    </main>

    <!-- Status bar -->
    <footer class="status-bar" role="contentinfo">
        <div class="status-bar__segment">
            <span class="status-bar__label">PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></span>
        </div>
        <div class="status-bar__segment">
            <span class="status-bar__label"><?= htmlspecialchars(php_uname('n'), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="status-bar__spacer"></div>
        <div class="status-bar__segment">
            <span class="status-bar__label" id="status-bar-time"></span>
        </div>
        <button type="button" class="status-bar__btn status-bar__segment" id="status-search-trigger">
            <kbd class="status-bar__kbd">Ctrl K</kbd>
            <span class="status-bar__label"><?= I18n::e('backstage.layout.search.label') ?></span>
        </button>
    </footer>
</div>

<!-- Mobile bottom nav -->
<nav class="bottom-nav" aria-label="<?= I18n::e('backstage.layout.nav_aria') ?>">
    <a href="/backstage" class="bottom-nav__item <?= $__isActive('dashboard') ?>">
        <svg class="bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/>
            <rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
        </svg>
        <span class="bottom-nav__label"><?= I18n::e('backstage.layout.nav.dashboard') ?></span>
    </a>
    <a href="/backstage/members" class="bottom-nav__item <?= $__isActive('members') ?>">
        <svg class="bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>
        </svg>
        <span class="bottom-nav__label"><?= I18n::e('backstage.layout.nav.members') ?></span>
    </a>
    <a href="/backstage/settings" class="bottom-nav__item <?= $__isActive('settings') ?>">
        <svg class="bottom-nav__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
            <circle cx="12" cy="12" r="3"/>
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>
        </svg>
        <span class="bottom-nav__label"><?= I18n::e('backstage.layout.nav.settings') ?></span>
    </a>
</nav>

<!-- Toast container -->
<div class="toast-container" id="toast-container" aria-live="polite" aria-atomic="false"></div>

<!-- Command palette -->
<div class="cmd-palette" id="cmd-palette" hidden>
    <div class="cmd-palette__backdrop" id="cmd-palette-backdrop"></div>
    <div class="cmd-palette__panel" role="combobox" aria-expanded="true" aria-haspopup="listbox" aria-label="<?= I18n::e('backstage.layout.search.label') ?>">
        <div class="cmd-palette__header">
            <svg class="cmd-palette__search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input class="cmd-palette__input" id="cmd-palette-input" type="text" placeholder="<?= I18n::e('backstage.layout.search.placeholder') ?>" autocomplete="off" aria-autocomplete="list" aria-controls="cmd-palette-list">
        </div>
        <ul class="cmd-palette__list" id="cmd-palette-list" role="listbox">
            <li class="cmd-palette__item" role="option" data-href="/backstage"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.dashboard') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.main') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/backstage/members"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.members') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.main') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/backstage/members?view=pending"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.cmd.pending_apps') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.main') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/backstage/events"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.events') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.main') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/backstage/projects"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.projects') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.main') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/backstage/forum"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.forum') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.main') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/backstage/notifications"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.notifications') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.system') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/backstage/settings"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.settings') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.system') ?></span></li>
            <li class="cmd-palette__item" role="option" data-href="/"><span class="cmd-palette__item-label"><?= I18n::e('backstage.layout.nav.public_site') ?></span><span class="cmd-palette__item-section"><?= I18n::e('backstage.layout.cmd.section.navigation') ?></span></li>
        </ul>
        <div class="cmd-palette__empty" id="cmd-palette-empty" hidden><?= I18n::e('backstage.layout.search.no_results') ?></div>
        <div class="cmd-palette__footer">
            <kbd>↑↓</kbd> <?= I18n::e('backstage.layout.cmd.navigate') ?>
            <kbd>↵</kbd> <?= I18n::e('backstage.layout.cmd.select') ?>
            <kbd>Esc</kbd> <?= I18n::e('backstage.layout.cmd.close') ?>
        </div>
    </div>
</div>

<script>window.DAEMS_PENDING_APPS = <?= json_encode($__pendingApps, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="/backstage/pages/toasts.js" defer></script>
<script src="/backstage/assets/js/daems-backstage.js" defer></script>
<script src="/backstage/assets/js/daems-backstage-system.js" defer></script>
<script src="/backstage/assets/js/daems-search.js" defer></script>
<?php if (($activePage ?? '') === 'dashboard'): ?>
<script src="/backstage/assets/js/daems-backstage-dashboard.js" defer></script>
<?php endif; ?>
<script>
(function () {
    var el = document.getElementById('status-bar-time');
    if (!el) return;
    var now = new Date();
    el.textContent = now.toLocaleTimeString(<?= json_encode($__bcp47) ?>, {hour:'2-digit', minute:'2-digit', second:'2-digit'});
})();
</script>
</body>
</html>
