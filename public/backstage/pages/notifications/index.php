<?php
/**
 * Backstage Notifications — unified inbox of every pending admin action.
 *
 * Server-renders from GET /api/v1/backstage/applications/pending-count
 * (the existing ListPendingApplicationsForAdmin feed that also powers
 * the dashboard toast stack). Each row deep-links to the right
 * moderation surface and offers a persistent Dismiss that hits the
 * same /api/backstage/dismiss proxy the dashboard toast × uses.
 */

declare(strict_types=1);

use Daems\Frontend\ApiClient;

$pageTitle   = 'backstage.title.notifications';
$activePage  = 'notifications';
$breadcrumbs = [];

$token = (string) ($_SESSION['token'] ?? '');

$apiError = null;
$feed = (static function (string $token, ?string &$err): array {
    $ch = curl_init('http://daems-platform.local/api/v1/backstage/applications/pending-count');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: application/json',
            $token !== '' ? ('Authorization: Bearer ' . $token) : null,
            'Host: daems-platform.local',
        ]),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && is_string($raw)) {
        $d = json_decode($raw, true);
        // Controller wraps the output: { data: { items, total } }.
        $inner = is_array($d) ? ($d['data'] ?? $d) : null;
        if (is_array($inner) && isset($inner['items'])) {
            return $inner;
        }
    }
    $err = $code . (is_string($raw) ? ' · ' . substr($raw, 0, 200) : '');
    return ['items' => [], 'total' => 0];
})($token, $apiError);

/** @var array<int, array{id:string, type:string, name:string, created_at:string}> $items */
$items = is_array($feed['items'] ?? null) ? $feed['items'] : [];
$total = (int) ($feed['total'] ?? count($items));

$filter = $_GET['type'] ?? 'all';
$allowedFilters = ['all', 'member', 'supporter', 'project_proposal', 'forum_report'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'all';
}

$filtered = $filter === 'all'
    ? $items
    : array_values(array_filter($items, static fn (array $r): bool => $r['type'] === $filter));

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$typeLabels = [
    'member'           => ['Jäsenhakemus',    'bi-person-plus',   'var(--brand-primary)'],
    'supporter'        => ['Tukijäsenhakemus','bi-heart',         'var(--accent)'],
    'project_proposal' => ['Projektiehdotus', 'bi-lightbulb',     'var(--status-warning)'],
    'forum_report'     => ['Foorumi-raportti','bi-flag',          'var(--status-error)'],
];

function relTime(string $iso): string
{
    $ts = strtotime($iso);
    if ($ts === false) { return $iso; }
    $delta = time() - $ts;
    if ($delta < 60)    { return 'juuri nyt'; }
    if ($delta < 3600)  { return (int) floor($delta / 60) . ' min sitten'; }
    if ($delta < 86400) { return (int) floor($delta / 3600) . ' h sitten'; }
    if ($delta < 604800){ return (int) floor($delta / 86400) . ' pv sitten'; }
    return date('j.n.Y', $ts);
}

function hrefFor(string $type, string $id): string
{
    switch ($type) {
        case 'member':
        case 'supporter':        return '/backstage/members?view=pending&highlight=' . rawurlencode($id);
        case 'project_proposal': return '/backstage/projects?tab=proposals&highlight=' . rawurlencode($id);
        case 'forum_report':     return '/backstage/forum?tab=reports&highlight=' . rawurlencode($id);
        default:                 return '/backstage';
    }
}

$filterCounts = array_fill_keys(array_keys($typeLabels), 0);
foreach ($items as $r) {
    $t = $r['type'];
    if (isset($filterCounts[$t])) { $filterCounts[$t]++; }
}

ob_start();
?>
<div class="notifications-admin">

<div class="page-header">
    <div>
        <h1 class="page-header__title">Notifications</h1>
        <p class="page-header__subtitle">
            <?php if ($total > 0): ?>
                Sinulla on <strong><?= $total ?></strong> käsittelemätöntä ilmoitusta.
            <?php else: ?>
                Ei uusia ilmoituksia.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php
$icon_bell  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>';
$icon_inbox = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>';
$icon_check = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 6L9 17l-5-5"/></svg>';
$icon_clock = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';

$kpis = [
    ['kpi_id' => 'pending_you',      'label' => 'Pending (you)',    'value' => '—', 'icon_html' => $icon_bell,  'icon_variant' => 'amber', 'trend_label' => 'awaiting you',     'trend_direction' => 'warn'],
    ['kpi_id' => 'pending_all',      'label' => 'Pending (all)',    'value' => '—', 'icon_html' => $icon_inbox, 'icon_variant' => 'gray',  'trend_label' => 'across team',      'trend_direction' => 'muted'],
    ['kpi_id' => 'cleared_30d',      'label' => 'Cleared (30d)',    'value' => '—', 'icon_html' => $icon_check, 'icon_variant' => 'green', 'trend_label' => 'last 30 days',     'trend_direction' => 'muted'],
    ['kpi_id' => 'oldest_pending_d', 'label' => 'Oldest pending',   'value' => '—', 'icon_html' => $icon_clock, 'icon_variant' => 'red',   'trend_label' => 'days unresolved',  'trend_direction' => 'warn'],
];
?>
<div class="kpis-grid">
  <?php foreach ($kpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
</div>
<script src="/backstage/pages/notifications/notifications-stats.js" defer></script>

<?php if ($apiError !== null): ?>
<div class="card" style="border-left:4px solid var(--status-error); margin-bottom:1rem;">
    <div class="card__body">
        <strong style="color:var(--status-error);">Feediä ei voitu ladata:</strong>
        <code style="font-size:.8rem; display:block; margin-top:.5rem;"><?= $esc($apiError) ?></code>
    </div>
</div>
<?php endif; ?>

<div class="card notif-filters-card">
    <div class="card__body">
        <div class="notif-filters-row">
            <a class="notif-filter-chip <?= $filter === 'all' ? 'is-active' : '' ?>" href="?type=all">
                Kaikki <span class="notif-filter-chip__count"><?= $total ?></span>
            </a>
            <?php foreach ($typeLabels as $key => [$label, , ]): ?>
                <?php if (($filterCounts[$key] ?? 0) > 0 || $filter === $key): ?>
                <a class="notif-filter-chip <?= $filter === $key ? 'is-active' : '' ?>" href="?type=<?= $esc($key) ?>">
                    <?= $esc($label) ?>
                    <span class="notif-filter-chip__count"><?= (int) ($filterCounts[$key] ?? 0) ?></span>
                </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php if (empty($filtered)): ?>
    <div class="card">
        <div class="card__body notif-empty">
            <i class="bi bi-bell-slash" style="font-size:2rem;"></i>
            <p class="mt-2 mb-0"><?= $filter === 'all' ? 'Kaikki kiinni — ei käsittelemättömiä ilmoituksia.' : 'Ei tämän tyypin ilmoituksia.' ?></p>
        </div>
    </div>
<?php else: ?>
    <ul id="notif-list" class="notif-list">
        <?php foreach ($filtered as $r): ?>
            <?php
            $t = $r['type'];
            [$label, $icon, $accent] = $typeLabels[$t] ?? [$t, 'bi-bell', 'var(--text-secondary)'];
            ?>
            <li class="notif-card" data-id="<?= $esc((string) $r['id']) ?>" data-type="<?= $esc($t) ?>" style="--notif-accent: <?= $esc($accent) ?>;">
                <a href="<?= $esc(hrefFor($t, (string) $r['id'])) ?>" class="notif-card__link">
                    <span class="notif-card__icon"><i class="bi <?= $esc($icon) ?>"></i></span>
                    <span class="notif-card__body">
                        <span class="notif-card__type"><?= $esc($label) ?></span>
                        <span class="notif-card__name"><?= $esc((string) ($r['name'] ?? '')) ?></span>
                        <span class="notif-card__meta"><?= $esc(relTime((string) $r['created_at'])) ?></span>
                    </span>
                </a>
                <div class="notif-card__actions">
                    <a href="<?= $esc(hrefFor($t, (string) $r['id'])) ?>" class="btn btn--sm btn--primary">Käsittele</a>
                    <button type="button" class="btn btn--sm btn--ghost" data-role="dismiss" aria-label="Hylkää ilmoitus">
                        Hylkää
                    </button>
                </div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

</div><!-- /.notifications-admin -->

<link rel="stylesheet" href="/backstage/pages/notifications/notifications.css">
<script src="/backstage/pages/notifications/notifications.js"></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
