<?php
/**
 * Backstage — Project Proposals admin page.
 *
 * Lists member-submitted project proposals with columns:
 *   Author | Title | Category | Source locale | Status | Submitted | Review
 *
 * Approve creates a Project row (+ projects_i18n row in source_locale) via
 *   POST /api/v1/backstage/project-proposals/{id}/approve
 * Reject sets status = 'rejected' via
 *   POST /api/v1/backstage/project-proposals/{id}/reject { note }
 *
 * Note: A separate tab inside /backstage/projects already renders an
 * inline approve/reject UI against the legacy /api/v1/backstage/proposals
 * endpoint. That UI continues to work against the renamed alias; this page
 * is the new, dedicated locale-aware review surface referenced from the
 * sidebar's "Project Proposals" sub-item.
 */

declare(strict_types=1);

use Daems\Frontend\ApiClient;
use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.project_proposals';
$activePage  = 'projects';
$breadcrumbs = [
    ['label' => I18n::t('backstage.title.projects'),          'url' => '/backstage/projects'],
    ['label' => I18n::t('backstage.title.project_proposals')],
];

$fetchList = static function (string $path): array {
    $token   = (string) ($_SESSION['token'] ?? '');
    $headers = ['Accept: application/json', 'Host: daems-platform.local'];
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init('http://daems-platform.local/api/v1' . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && is_string($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded) && isset($decoded['items'])) {
            return $decoded;
        }
    }
    return ['items' => [], 'total' => 0];
};

// Try the renamed endpoint first (Workstream A, task A24); fall back to the
// legacy /backstage/proposals if the rename hasn't landed yet.
$proposals = $fetchList('/backstage/project-proposals');
if (empty($proposals['items']) && ($proposals['total'] ?? 0) === 0) {
    $legacy = $fetchList('/backstage/proposals');
    if (!empty($legacy['items'])) {
        $proposals = $legacy;
    }
}

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$categoryLabels = [
    'community'  => 'Community',
    'technology' => 'Technology',
    'events'     => 'Events',
    'research'   => 'Research',
];

ob_start();
?>
<div class="project-proposals-admin">

<div class="page-header">
    <div>
        <h1 class="page-header__title">Project Proposals</h1>
        <p class="page-header__subtitle">Review, approve, or reject member-submitted project proposals.</p>
    </div>
</div>

<div class="card evt-props-card">
    <div class="card__body">
        <div class="proj-meta-row"><strong id="pp-count"><?= (int) ($proposals['total'] ?? count($proposals['items'])) ?> proposal(s)</strong></div>
        <?php if (empty($proposals['items'])): ?>
            <p class="proj-empty">No pending project proposals.</p>
        <?php else: ?>
            <table class="data-table evt-props-table" id="project-proposals-table">
                <thead>
                    <tr>
                        <th>Author</th>
                        <th>Title</th>
                        <th>Category</th>
                        <th>Locale</th>
                        <th>Status</th>
                        <th>Submitted</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($proposals['items'] as $p): ?>
                        <?php $cat = (string) ($p['category'] ?? ''); ?>
                        <tr id="pp-<?= $esc((string) ($p['id'] ?? '')) ?>">
                            <td><?= $esc((string) ($p['author_name'] ?? '')) ?></td>
                            <td><strong><?= $esc((string) ($p['title'] ?? '')) ?></strong></td>
                            <td><?= $esc($categoryLabels[$cat] ?? ucfirst($cat)) ?></td>
                            <td><span class="locale-badge"><?= $esc((string) ($p['source_locale'] ?? 'fi_FI')) ?></span></td>
                            <td><span class="status-pill status-pill--<?= $esc((string) ($p['status'] ?? 'pending')) ?>"><?= $esc((string) ($p['status'] ?? 'pending')) ?></span></td>
                            <td><?= $esc(substr((string) ($p['created_at'] ?? ''), 0, 16)) ?></td>
                            <td class="evt-props-actions">
                                <?php if (($p['status'] ?? 'pending') === 'pending'): ?>
                                    <button type="button" class="btn btn--ghost btn--sm"
                                            data-review='<?= $esc(json_encode($p, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) ?>'>
                                        Review
                                    </button>
                                <?php else: ?>
                                    <span class="evt-props-muted">Decided</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

</div><!-- /.project-proposals-admin -->

<link rel="stylesheet" href="/modules/events/assets/backstage/proposal-modal.css">
<link rel="stylesheet" href="/backstage/pages/project-proposals/proposal-modal.css">
<script>
window.DAEMS_PROJECT_PROPOSALS = <?= json_encode($proposals, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="/backstage/pages/project-proposals/proposal-modal.js"></script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
