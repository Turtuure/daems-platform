<?php
declare(strict_types=1);

use Daems\Frontend\ApiClient;
use Daems\Frontend\I18n;

$u = $_SESSION['user'] ?? null;
$isAdmin = $u && (!empty($u['is_platform_admin']) || in_array(($u['role'] ?? ''), ['admin', 'moderator'], true));
if (!$isAdmin) { header('Location: /'); exit; }

$pageTitle   = 'backstage.title.search';
$activePage  = 'search';
$breadcrumbs = [];

$q    = trim((string) ($_GET['q']    ?? ''));
$type = (string) ($_GET['type'] ?? 'all');

$results = ['data' => [], 'meta' => ['count' => 0]];
if (mb_strlen($q) >= 2) {
    $token = (string) ($_SESSION['token'] ?? '');
    $qs = http_build_query(['q' => $q, 'type' => $type !== 'all' ? $type : null, 'limit' => 20], '', '&');
    $ch = curl_init('http://daems-platform.local/api/v1/backstage/search?' . $qs);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: application/json',
            'Accept-Language: ' . I18n::locale(),
            'Authorization: Bearer ' . $token,
            'Host: daems-platform.local',
        ]),
    ]);
    $raw = curl_exec($ch);
    curl_close($ch);
    $decoded = json_decode((string) $raw, true);
    if (is_array($decoded)) $results = $decoded;
}

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-header__title">Search</h1>
        <p class="page-header__subtitle">Hae tapahtumia, projekteja, foorumia, jäseniä ja muuta.</p>
    </div>
</div>

<div class="daems-search-chips mb-3">
    <?php foreach (['all', 'events', 'projects', 'forum', 'insights', 'members'] as $c): ?>
        <a class="daems-search-chip<?= $type === $c ? ' is-active' : '' ?>"
           href="/backstage/search?q=<?= urlencode($q) ?>&type=<?= $c ?>"><?= ucfirst($c) ?></a>
    <?php endforeach; ?>
</div>

<?php if ($q === ''): ?>
    <p class="text-muted">Kirjoita hakusana yläpalkin hakukenttään.</p>
<?php elseif (empty($results['data'])): ?>
    <p>Ei tuloksia haulle "<?= $esc($q) ?>".</p>
<?php else: ?>
    <p class="text-muted mb-3">Hakutulokset haulle "<?= $esc($q) ?>"</p>
    <ul class="daems-search-results-list list-unstyled">
        <?php foreach ($results['data'] as $h): ?>
            <li class="daems-search-result-item mb-3">
                <a href="<?= $esc((string) $h['url']) ?>" class="h5 d-block"><?= $esc((string) $h['title']) ?></a>
                <small class="text-muted"><?= $esc(ucfirst((string) $h['entity_type'])) ?></small>
                <div><?= $esc((string) $h['snippet']) ?></div>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
