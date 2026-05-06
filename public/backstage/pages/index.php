<?php
/**
 * Admin Dashboard — metric cards and activity overview.
 * Session is started in public/backstage.php; ApiClient is autoloaded.
 */

use Daems\Frontend\ApiClient;

$pageTitle   = 'Dashboard';
$activePage  = 'dashboard';
$breadcrumbs = [];

$stats = ApiClient::get('/backstage/stats');

// Normalise stat values — API returns { value, change, sparkline } per key
$members      = (int)($stats['members']['value']              ?? $stats['members']              ?? 0);
$applications = (int)($stats['pending_applications']['value'] ?? $stats['pending_applications'] ?? 0);
$events       = (int)($stats['upcoming_events']['value']      ?? $stats['upcoming_events']      ?? 0);
$projects     = (int)($stats['active_projects']['value']      ?? $stats['active_projects']      ?? 0);

$changes = [
    'members'      => (float)($stats['members']['change']              ?? 0),
    'applications' => (float)($stats['pending_applications']['change'] ?? 0),
    'events'       => (float)($stats['upcoming_events']['change']      ?? 0),
    'projects'     => (float)($stats['active_projects']['change']      ?? 0),
];

$sparklines = [
    'members'      => $stats['members']['sparkline']              ?? [],
    'applications' => $stats['pending_applications']['sparkline'] ?? [],
    'events'       => $stats['upcoming_events']['sparkline']      ?? [],
    'projects'     => $stats['active_projects']['sparkline']      ?? [],
    'forum'        => $stats['forum_activity']['sparkline']       ?? [],
    'insights'     => $stats['insights_activity']['sparkline']    ?? [],
];

$memberGrowth = $stats['member_growth'] ?? ['labels' => [], 'series' => []];

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Dashboard</h1>
        <p class="page-header__subtitle">Platform overview — <?= date('l, j F Y') ?></p>
    </div>
</div>

<!-- Metric cards — 4 columns matching SIP layout -->
<div class="metric-grid">
    <?php
    $cards = [
        [
            'id'     => 'members',
            'label'  => 'Members',
            'color'  => 'blue',
            'value'  => $members,
            'change' => $changes['members'],
            'enter'  => 1,
            'icon'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        ],
        [
            'id'     => 'applications',
            'label'  => 'Applications',
            'color'  => 'amber',
            'value'  => $applications,
            'change' => $changes['applications'],
            'enter'  => 2,
            'icon'   => '<path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="13" y2="16"/>',
        ],
        [
            'id'     => 'events',
            'label'  => 'Upcoming events',
            'color'  => 'green',
            'value'  => $events,
            'change' => $changes['events'],
            'enter'  => 3,
            'icon'   => '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        ],
        [
            'id'     => 'projects',
            'label'  => 'Active projects',
            'color'  => 'purple',
            'value'  => $projects,
            'change' => $changes['projects'],
            'enter'  => 4,
            'icon'   => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        ],
    ];
    foreach ($cards as $card):
        extract($card);
        require __DIR__ . '/partials/metric-card.php';
    endforeach;
    ?>
</div>

<!-- Embed chart data for dashboard JS — no XHR needed -->
<script>
window.DaemsDashboard = {
    sparklines:   <?= json_encode($sparklines,   JSON_UNESCAPED_UNICODE) ?>,
    memberGrowth: <?= json_encode($memberGrowth, JSON_UNESCAPED_UNICODE) ?>,
};
</script>

<!-- Charts row -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:var(--space-4);">
    <div class="card">
        <div class="card__body">
            <div class="flex items-center" style="justify-content:space-between;margin-bottom:var(--space-4);">
                <p class="card__title">Member growth</p>
                <div class="chart-period-tabs" role="tablist" aria-label="Chart period">
                    <button class="chart-period-tab is-active" data-period="30d" role="tab" aria-selected="true">30d</button>
                    <button class="chart-period-tab" data-period="90d" role="tab" aria-selected="false">90d</button>
                    <button class="chart-period-tab" data-period="1y" role="tab" aria-selected="false">1y</button>
                    <button class="chart-period-tab" data-period="all" role="tab" aria-selected="false">All</button>
                </div>
            </div>
            <div id="chart-member-growth" style="min-height:200px;"></div>
        </div>
    </div>
    <div class="card">
        <div class="card__body">
            <p class="card__title" style="margin-bottom:var(--space-4);">Platform activity</p>
            <div id="chart-platform-activity" style="min-height:200px;"></div>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/layout.php';
