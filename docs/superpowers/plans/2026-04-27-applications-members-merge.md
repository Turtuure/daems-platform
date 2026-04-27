# Applications + Members Merge — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate `/backstage/applications` into `/backstage/members` as a single tabbed admin page with no backend changes.

**Architecture:** Frontend-only refactor on the `daem-society` repo. The Members page gains a 2-tab UI ("Register" default + "Pending"), the Applications page becomes a 302 redirect, and the sidebar/bottom-nav/command-palette drop the Applications entry while moving the pending-count badge onto the Members entry. Backend (`daems-platform`) is untouched.

**Tech Stack:** PHP 8.1 (server-rendered templates), vanilla JS, CSS using existing `--surface-*` / `--brand-*` design tokens. No build step. Spec: `docs/superpowers/specs/2026-04-27-applications-members-merge-design.md`.

---

## Working repo for this plan

All file paths in this plan are relative to `C:/laragon/www/sites/daem-society/` unless prefixed with `daems-platform/`. The plan document itself lives in the platform repo (`C:/laragon/www/daems-platform/docs/superpowers/plans/`) per project convention, but every code change happens in the `daem-society` working tree. Each task's commit step uses the `daem-society` repo. The platform repo gets one commit at the end recording the plan only — covered by Task 9.

**Commit identity** (every commit, both repos):
```bash
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "..."
```

**Browser check baseline:** Laragon serves `daem-society.local`. Before starting Task 1, open `http://daem-society.local/backstage` in a browser and confirm you can log in as a GSA user. The smoke checks reuse this session.

---

## File structure

| File | Status | Responsibility |
|---|---|---|
| `public/assets/css/daems-backstage.css` | modify | Append tab-bar styles colocated with existing `.members-*` rules |
| `public/pages/backstage/members/index.php` | rewrite | Tabbed page; handles both Register POSTs (status changes) and Pending POSTs (approve/reject) |
| `public/pages/backstage/members/applications-stats.js` | create (moved) | KPI strip script for the Pending tab — currently lives under `applications/`, file content unchanged |
| `public/pages/backstage/applications/index.php` | shrink to redirect | One-line 302 to `/backstage/members?tab=pending` |
| `public/pages/backstage/applications/applications-stats.js` | delete | Replaced by the copy under `members/` |
| `public/pages/backstage/layout.php` | modify | Drop Applications nav rows; rename badge id; rebind pending-count fetch target |
| `public/pages/backstage/toasts.js` | modify | Two hardcoded `/backstage/applications` URLs → `/backstage/members?tab=pending` |
| `public/pages/backstage/notifications/index.php` | modify | One hardcoded `/backstage/applications?highlight=` URL → `/backstage/members?tab=pending&highlight=` |
| `public/index.php` | unchanged | The `/applications` router entry continues to load `pages/backstage/applications/index.php`, which now redirects |

---

## Task 1: Add tab-bar CSS

**Files:**
- Modify: `public/assets/css/daems-backstage.css` (append after line ~1273, near other `.members-*` rules)

- [ ] **Step 1: Append tab styles**

Append this block to the end of `public/assets/css/daems-backstage.css`:

```css
/* === Members tabs (Register | Pending) === */
.members-tabs {
    display: flex;
    gap: var(--space-1);
    margin-bottom: var(--space-4);
    border-bottom: 1px solid var(--surface-border);
}
.members-tab {
    display: inline-flex;
    align-items: center;
    gap: var(--space-2);
    padding: var(--space-2) var(--space-3);
    border: 1px solid transparent;
    border-bottom: none;
    border-top-left-radius: var(--radius-md, 6px);
    border-top-right-radius: var(--radius-md, 6px);
    color: var(--text-muted);
    text-decoration: none;
    font-weight: 600;
    font-size: 14px;
    margin-bottom: -1px;
}
.members-tab:hover {
    color: var(--text-primary, #1a1a1a);
    background: var(--surface-hover, rgba(0,0,0,0.03));
}
.members-tab.is-active {
    color: var(--brand-primary);
    border-color: var(--surface-border);
    border-bottom-color: var(--surface-bg, #fff);
    background: var(--surface-bg, #fff);
}
.members-tab__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--status-warning, #f59e0b);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
}
.members-tab__badge[hidden] { display: none; }
```

- [ ] **Step 2: Visual sanity check**

Open `http://daem-society.local/backstage/members` in browser. Page renders unchanged (rules are unused so far). Confirm no CSS parse error in DevTools console.

- [ ] **Step 3: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git add public/assets/css/daems-backstage.css
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): tab-bar styles for Members page (Register | Pending)"
```

---

## Task 2: Copy applications-stats.js into members/

**Files:**
- Create: `public/pages/backstage/members/applications-stats.js` (byte-identical copy)
- Keep (for now): `public/pages/backstage/applications/applications-stats.js` (deleted in Task 4)

- [ ] **Step 1: Copy the file**

PowerShell:
```powershell
Copy-Item public/pages/backstage/applications/applications-stats.js public/pages/backstage/members/applications-stats.js
```

The script's fetch URL `/api/backstage/applications.php?op=stats` is environment-relative — moving the file does not change the endpoint it hits.

- [ ] **Step 2: Verify byte-identical copy**

```bash
cd C:/laragon/www/sites/daem-society
diff public/pages/backstage/applications/applications-stats.js public/pages/backstage/members/applications-stats.js
```

Expected: no output (identical).

- [ ] **Step 3: Commit**

```bash
git add public/pages/backstage/members/applications-stats.js
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Add(backstage): copy applications-stats.js into members/ for Pending tab"
```

---

## Task 3: Rewrite members/index.php as tabbed page

**Files:**
- Modify (full rewrite): `public/pages/backstage/members/index.php`

After this task `/backstage/members` shows a tab bar, defaults to Register tab (existing rekisteri behavior), and `?tab=pending` shows the imported Applications page body. The original `/backstage/applications` URL still works because we have not yet replaced its index.php (Task 4).

- [ ] **Step 1: Read current members/index.php to confirm structure**

The file is 688 lines. Key sections to preserve:
- Lines 1-54: bootstrap + status-change POST handler
- Lines 65-72: members data fetch
- Lines 74-127: CSV export branch
- Lines 130-137: audit fetch
- Lines 139-172: filter/query helpers
- Lines 174-557: Register UI (header, KPI strip, filters, table, cards, modals, audit log)
- Lines 559-683: page JS (view toggle, modal handling)
- Lines 686-688: layout include

We wrap the Register UI in a tabpanel and append a Pending tabpanel. The Pending tabpanel content is ported from `applications/index.php`.

- [ ] **Step 2: Read applications/index.php to confirm what to port**

Source: `public/pages/backstage/applications/index.php` (252 lines). Sections to port into Pending tabpanel:
- Lines 27-63: POST handler for approve/reject (must run **only** when tab=pending)
- Lines 65-72: pending fetch
- Lines 89-99: KPI strip + applications-stats.js include (script src must change to `/pages/backstage/members/applications-stats.js`)
- Lines 101-164: flash banners + invite-link toast
- Lines 166-231: Member Applications card + Supporter Applications card with approve/reject forms (form action changes to `/backstage/members?tab=pending`)
- Lines 233-248: highlight script

- [ ] **Step 3: Write the new members/index.php**

Replace the entire contents of `public/pages/backstage/members/index.php` with:

```php
<?php
/**
 * Backstage Members — tabbed page combining the official Members Register
 * with pending member/supporter applications.
 *
 * Tabs (?tab=register|pending, default register):
 *   - register: full member register (taulukko + kortit, filtterit, status-modaalit, audit, CSV)
 *   - pending:  member + supporter applications awaiting decision (approve/reject + invite-link)
 */

declare(strict_types=1);

if (!class_exists('ApiClient')) {
    require_once __DIR__ . '/../../../../src/ApiClient.php';
}

$pageTitle = 'Members';
$activePage = 'members';
$breadcrumbs = [];

$activeTab = (($_GET['tab'] ?? 'register') === 'pending') ? 'pending' : 'register';

// ---------------------------------------------------------------------------
// State for both tabs
// ---------------------------------------------------------------------------
$flashSuccess = null;
$flashError = null;

// Pending-tab approve/reject side-channel state
$inviteUrl = null;
$inviteExpiresAt = null;
$assignedNumber = null;
$assignedNumberLabel = null;

$isGsa = (string) ($_SESSION['user']['role'] ?? '') === 'global_system_administrator';

// ---------------------------------------------------------------------------
// POST dispatch — routed by tab
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if ($activeTab === 'pending') {
        // Approve / Reject application (member or supporter)
        $type = trim((string) ($_POST['type'] ?? ''));
        $id = trim((string) ($_POST['id'] ?? ''));
        $decision = trim((string) ($_POST['decision'] ?? ''));
        $note = trim((string) ($_POST['note'] ?? ''));

        if (!in_array($type, ['member', 'supporter'], true) || $id === '' || !in_array($decision, ['approved', 'rejected'], true)) {
            $flashError = 'Invalid application decision payload.';
        } else {
            $result = ApiClient::post('/backstage/applications/' . rawurlencode($type) . '/' . rawurlencode($id) . '/decision', [
                'decision' => $decision,
                'note' => $note,
            ]);

            if (($result['status'] ?? 500) === 200) {
                if ($decision === 'approved') {
                    $flashSuccess = 'Application approved successfully.';
                    $payload = $result['body']['data'] ?? $result['body'] ?? [];
                    if (is_array($payload) && !empty($payload['invite_url'])) {
                        $inviteUrl = (string) $payload['invite_url'];
                        $inviteExpiresAt = is_string($payload['invite_expires_at'] ?? null) ? $payload['invite_expires_at'] : null;
                        if (!empty($payload['member_number'])) {
                            $assignedNumber = (string) $payload['member_number'];
                            $assignedNumberLabel = 'Member number';
                        } elseif (!empty($payload['supporter_number'])) {
                            $assignedNumber = (string) $payload['supporter_number'];
                            $assignedNumberLabel = 'Supporter number';
                        }
                    }
                } else {
                    $flashSuccess = 'Application rejected successfully.';
                }
            } else {
                $flashError = (string) ($result['body']['error'] ?? 'Failed to process application.');
            }
        }
    } else {
        // Register-tab status change (activate/suspend/terminate)
        $memberId = trim((string) ($_POST['member_id'] ?? ''));
        $newStatus = trim((string) ($_POST['status'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if (!$isGsa) {
            $flashError = 'Only GSA can change member status.';
        } elseif ($memberId === '' || $newStatus === '') {
            $flashError = 'Invalid member status request.';
        } elseif ($reason === '') {
            $flashError = 'Status change reason is required.';
        } else {
            $result = ApiClient::post('/backstage/members/' . rawurlencode($memberId) . '/status', [
                'status' => $newStatus,
                'reason' => $reason,
            ]);

            if (($result['status'] ?? 500) === 200) {
                $flashSuccess = 'Member status updated successfully.';
            } else {
                $flashError = (string) ($result['body']['error'] ?? 'Failed to update member status.');
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Register-tab data fetch
// ---------------------------------------------------------------------------
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$typeFilter = trim((string) ($_GET['type'] ?? ''));
$qFilter = trim((string) ($_GET['q'] ?? ''));
$sort = trim((string) ($_GET['sort'] ?? 'member_number'));
$dir = strtoupper(trim((string) ($_GET['dir'] ?? 'ASC')));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = max(10, min(200, (int) ($_GET['per_page'] ?? 50)));

$filters = [
    'status' => $statusFilter,
    'type' => $typeFilter,
    'q' => $qFilter,
    'sort' => $sort,
    'dir' => $dir,
    'page' => $page,
    'per_page' => $perPage,
];

$data = ApiClient::get('/backstage/members', $filters);
if (!is_array($data)) {
    $data = ['items' => [], 'total' => 0];
}

$members = is_array($data['items'] ?? null) ? $data['items'] : [];
$total = (int) ($data['total'] ?? 0);
$totalPages = max(1, (int) ceil($total / $perPage));

// CSV export branch — Register only
if ($activeTab === 'register' && ($_GET['export'] ?? '') === 'csv') {
    $exportRows = ApiClient::get('/backstage/members', [
        'status' => $statusFilter,
        'type' => $typeFilter,
        'q' => $qFilter,
        'sort' => $sort,
        'dir' => $dir,
        'page' => 1,
        'per_page' => 2000,
    ]);
    $rows = is_array($exportRows['items'] ?? null) ? $exportRows['items'] : [];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members-register.csv"');

    $out = fopen('php://output', 'wb');
    if ($out !== false) {
        fputcsv($out, [
            'Member Number',
            'Name',
            'Email',
            'Category',
            'Status',
            'Joined Date',
            'Country',
            'Date of Birth',
            'Status Reason',
        ]);

        foreach ($rows as $row) {
            $category = match ((string) ($row['membership_type'] ?? '')) {
                'supporter' => 'Kannattava jäsen',
                'honorary' => 'Kunniajäsen',
                'full', 'founding' => 'Varsinainen jäsen',
                default => 'Perusjäsen',
            };

            fputcsv($out, [
                (string) ($row['member_number'] ?? ''),
                (string) ($row['name'] ?? ''),
                (string) ($row['email'] ?? ''),
                $category,
                (string) ($row['membership_status'] ?? ''),
                (string) (($row['membership_started_at'] ?? '') ?: ($row['created_at'] ?? '')),
                (string) ($row['country'] ?? ''),
                (string) ($row['date_of_birth'] ?? ''),
                (string) ($row['membership_status_reason'] ?? ''),
            ]);
        }

        fclose($out);
    }

    exit;
}

$auditFor = trim((string) ($_GET['audit'] ?? ''));
$auditRows = [];
if ($auditFor !== '') {
    $auditData = ApiClient::get('/backstage/members/' . rawurlencode($auditFor) . '/audit', ['limit' => 25]);
    if (is_array($auditData)) {
        $auditRows = $auditData;
    }
}

$keepQuery = [
    'status' => $statusFilter,
    'type' => $typeFilter,
    'q' => $qFilter,
    'sort' => $sort,
    'dir' => $dir,
    'per_page' => $perPage,
];

$buildQuery = static function (array $extra) use ($keepQuery): string {
    $merged = array_merge($keepQuery, $extra);
    $merged = array_filter($merged, static fn($v) => $v !== '' && $v !== null);
    $qs = http_build_query($merged);
    return $qs === '' ? '' : ('?' . $qs);
};

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$nextStatuses = static function (string $current): array {
    return match ($current) {
        'active' => ['suspended' => 'Suspended', 'terminated' => 'Terminated'],
        'suspended' => ['active' => 'Active', 'terminated' => 'Terminated'],
        default => [],
    };
};
$sortLink = static function (string $key) use ($sort, $dir, $buildQuery): string {
    $nextDir = ($sort === $key && $dir === 'ASC') ? 'DESC' : 'ASC';
    return '/backstage/members' . $buildQuery(['tab' => 'register', 'sort' => $key, 'dir' => $nextDir, 'page' => 1]);
};
$sortMark = static function (string $key) use ($sort, $dir): string {
    if ($sort !== $key) {
        return '';
    }
    return $dir === 'DESC' ? ' ↓' : ' ↑';
};

// ---------------------------------------------------------------------------
// Pending-tab data fetch
// ---------------------------------------------------------------------------
$pending = ApiClient::get('/backstage/applications/pending', ['limit' => 200]);
if (!is_array($pending)) {
    $pending = ['member' => [], 'supporter' => [], 'total' => 0];
}

$memberPending = is_array($pending['member'] ?? null) ? $pending['member'] : [];
$supporterPending = is_array($pending['supporter'] ?? null) ? $pending['supporter'] : [];
$totalPending = (int) ($pending['total'] ?? (count($memberPending) + count($supporterPending)));

ob_start();
?>
<div class="members-register">
<div class="page-header">
    <div>
        <h1 class="page-header__title">Members Register</h1>
        <p class="page-header__subtitle">Official register for kannattava, perus, varsinainen, and kunniajäsen categories.</p>
    </div>
    <?php if ($activeTab === 'register'): ?>
    <div class="members-header-actions">
        <div class="members-view-toggle" role="group" aria-label="Change members view">
            <button type="button" class="members-view-toggle__btn is-active" id="members-view-list" data-view="list">List</button>
            <button type="button" class="members-view-toggle__btn" id="members-view-cards" data-view="cards">Cards</button>
        </div>
        <a href="/backstage/members<?= $buildQuery(['tab' => 'register', 'export' => 'csv', 'page' => 1]) ?>" class="btn btn--ghost btn--sm">Export CSV</a>
    </div>
    <?php endif; ?>
</div>

<!-- Section: tab bar -->
<div class="members-tabs" role="tablist" aria-label="Members views">
    <a role="tab" aria-selected="<?= $activeTab === 'register' ? 'true' : 'false' ?>"
       class="members-tab<?= $activeTab === 'register' ? ' is-active' : '' ?>"
       href="/backstage/members?tab=register">Register</a>
    <a role="tab" aria-selected="<?= $activeTab === 'pending' ? 'true' : 'false' ?>"
       class="members-tab<?= $activeTab === 'pending' ? ' is-active' : '' ?>"
       href="/backstage/members?tab=pending">
        Pending
        <span class="members-tab__badge"<?= $totalPending > 0 ? '' : ' hidden' ?>><?= $totalPending ?></span>
    </a>
</div>

<?php if ($activeTab === 'register'): ?>
<!-- ============================================================ Register tab -->
<section role="tabpanel" data-tab="register">
<?php
$icon_member  = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/></svg>';
$icon_plus    = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 5v14M5 12h14"/></svg>';
$icon_heart   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>';
$icon_pause   = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/></svg>';

$registerKpis = [
    ['kpi_id' => 'total_members', 'label' => 'Total members', 'value' => '—', 'icon_html' => $icon_member, 'icon_variant' => 'blue',   'trend_label' => 'active in tenant', 'trend_direction' => 'muted'],
    ['kpi_id' => 'new_members',   'label' => 'New (30d)',     'value' => '—', 'icon_html' => $icon_plus,   'icon_variant' => 'green',  'trend_label' => 'last 30 days',     'trend_direction' => 'muted'],
    ['kpi_id' => 'supporters',    'label' => 'Supporters',    'value' => '—', 'icon_html' => $icon_heart,  'icon_variant' => 'purple', 'trend_label' => 'supporting members', 'trend_direction' => 'muted'],
    ['kpi_id' => 'inactive',      'label' => 'Inactive',      'value' => '—', 'icon_html' => $icon_pause,  'icon_variant' => 'amber',  'trend_label' => 'paused status',    'trend_direction' => 'warn'],
];
?>
<div class="kpis-grid">
  <?php foreach ($registerKpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
</div>
<script src="/pages/backstage/members/members-stats.js" defer></script>

<?php if ($flashSuccess !== null): ?>
<div class="card members-flash members-flash--success">
    <div class="card__body"><?= $esc($flashSuccess) ?></div>
</div>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="card members-flash members-flash--error">
    <div class="card__body"><?= $esc($flashError) ?></div>
</div>
<?php endif; ?>

<div class="card members-filters-card">
    <div class="card__body">
        <form method="get" action="/backstage/members" class="members-filters-grid">
            <input type="hidden" name="tab" value="register">
            <label class="members-field">
                <span class="members-label">Search</span>
                <input type="text" name="q" value="<?= $esc($qFilter) ?>" placeholder="Name, email, member number">
            </label>
            <label class="members-field">
                <span class="members-label">Status</span>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Active</option>
                    <option value="suspended"<?= $statusFilter === 'suspended' ? ' selected' : '' ?>>Suspended</option>
                    <option value="terminated"<?= $statusFilter === 'terminated' ? ' selected' : '' ?>>Terminated</option>
                </select>
            </label>
            <label class="members-field">
                <span class="members-label">Category</span>
                <select name="type">
                    <option value="">All categories</option>
                    <option value="basic"<?= $typeFilter === 'basic' ? ' selected' : '' ?>>Perusjäsen</option>
                    <option value="full"<?= $typeFilter === 'full' ? ' selected' : '' ?>>Varsinainen jäsen</option>
                    <option value="supporter"<?= $typeFilter === 'supporter' ? ' selected' : '' ?>>Kannattava jäsen</option>
                    <option value="honorary"<?= $typeFilter === 'honorary' ? ' selected' : '' ?>>Kunniajäsen</option>
                </select>
            </label>
            <label class="members-field">
                <span class="members-label">Rows</span>
                <select name="per_page">
                    <option value="25"<?= $perPage === 25 ? ' selected' : '' ?>>25</option>
                    <option value="50"<?= $perPage === 50 ? ' selected' : '' ?>>50</option>
                    <option value="100"<?= $perPage === 100 ? ' selected' : '' ?>>100</option>
                    <option value="200"<?= $perPage === 200 ? ' selected' : '' ?>>200</option>
                </select>
            </label>
            <button type="submit" class="btn btn--primary btn--sm members-apply-btn">Apply</button>
            <input type="hidden" name="sort" value="<?= $esc($sort) ?>">
            <input type="hidden" name="dir" value="<?= $esc($dir) ?>">
        </form>
    </div>
</div>

<div class="card members-table-card">
    <div class="card__body members-table-body" id="members-list-view">
        <div class="members-meta-row">
            <strong>Total records: <?= $total ?></strong>
            <span class="members-page-info">Page <?= $page ?> / <?= $totalPages ?></span>
        </div>
        <table class="data-table members-table">
            <thead>
                <tr>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('member_number')) ?>">Member #<?= $esc($sortMark('member_number')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('name')) ?>">Name<?= $esc($sortMark('name')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('email')) ?>">Email<?= $esc($sortMark('email')) ?></a></th>
                    <th>Category</th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('membership_status')) ?>">Status<?= $esc($sortMark('membership_status')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('joined_at')) ?>">Joined<?= $esc($sortMark('joined_at')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('country')) ?>">Country<?= $esc($sortMark('country')) ?></a></th>
                    <th><a class="members-sort-link" href="<?= $esc($sortLink('dob')) ?>">Date of Birth<?= $esc($sortMark('dob')) ?></a></th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$members): ?>
                <tr><td colspan="9" class="members-empty">No records found.</td></tr>
            <?php else: ?>
                <?php foreach ($members as $member): ?>
                    <?php
                        $memberId = (string) ($member['id'] ?? '');
                        $memberType = (string) ($member['membership_type'] ?? '');
                        $category = match ($memberType) {
                            'supporter' => 'Kannattava jäsen',
                            'honorary' => 'Kunniajäsen',
                            'full', 'founding' => 'Varsinainen jäsen',
                            default => 'Perusjäsen',
                        };
                        $currentStatus = (string) ($member['membership_status'] ?? '');
                        $allowedNext = $nextStatuses($currentStatus);
                        $canActivate = isset($allowedNext['active']);
                        $canSuspend = isset($allowedNext['suspended']);
                        $canTerminate = isset($allowedNext['terminated']);
                        $joinedAt = (string) (($member['membership_started_at'] ?? '') ?: ($member['created_at'] ?? ''));
                    ?>
                    <tr>
                        <td><?= $esc((string) ($member['member_number'] ?? '-')) ?></td>
                        <td><?= $esc((string) ($member['name'] ?? '')) ?></td>
                        <td><?= $esc((string) ($member['email'] ?? '')) ?></td>
                        <td><?= $esc($category) ?></td>
                        <td>
                            <span class="badge badge--default"><?= $esc((string) ($member['membership_status'] ?? '')) ?></span>
                        </td>
                        <td><?= $esc($joinedAt !== '' ? substr($joinedAt, 0, 10) : '-') ?></td>
                        <td><?= $esc((string) ($member['country'] ?? '-')) ?></td>
                        <td><?= $esc((string) (($member['date_of_birth'] ?? '') ?: '-')) ?></td>
                        <td class="members-actions-cell">
                            <?php if (!$isGsa): ?>
                                <span class="members-actions-disabled">GSA only</span>
                            <?php else: ?>
                            <div class="members-icon-actions" role="group" aria-label="Member actions for <?= $esc((string) ($member['name'] ?? '')) ?>">
                                <button type="button" class="members-icon-action" data-modal-action="activate" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" aria-label="Activate member" title="<?= $canActivate ? 'Activate' : 'Not allowed from current status' ?>"<?= $canActivate ? '' : ' disabled' ?>>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M10 16.5 6 12.5l1.4-1.4 2.6 2.6 6.6-6.6L18 8.5z"/></svg>
                                </button>
                                <button type="button" class="members-icon-action" data-modal-action="suspend" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" aria-label="Suspend member" title="<?= $canSuspend ? 'Suspend' : 'Not allowed from current status' ?>"<?= $canSuspend ? '' : ' disabled' ?>>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 5h2v14H7zm8 0h2v14h-2z"/></svg>
                                </button>
                                <button type="button" class="members-icon-action members-icon-action--danger" data-modal-action="terminate" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" aria-label="Terminate member" title="<?= $canTerminate ? 'Terminate' : 'Not allowed from current status' ?>"<?= $canTerminate ? '' : ' disabled' ?>>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg>
                                </button>
                                <button type="button" class="members-icon-action" data-modal-action="audit" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" data-audit-url="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page, 'audit' => $memberId]) ?>" aria-label="Open audit log" title="Audit">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13 3a9 9 0 1 0 8.7 11.3h-2.1A7 7 0 1 1 13 5v4l5-5-5-5z"/></svg>
                                </button>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="members-pagination">
            <?php if ($page > 1): ?>
                <a href="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page - 1, 'audit' => $auditFor]) ?>" class="btn btn--ghost btn--sm">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a href="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page + 1, 'audit' => $auditFor]) ?>" class="btn btn--ghost btn--sm">Next</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="members-cards-view" id="members-cards-view" hidden>
    <?php if (!$members): ?>
        <div class="card">
            <div class="card__body members-empty">No records found.</div>
        </div>
    <?php else: ?>
        <?php foreach ($members as $member): ?>
            <?php
                $memberId = (string) ($member['id'] ?? '');
                $memberType = (string) ($member['membership_type'] ?? '');
                $category = match ($memberType) {
                    'supporter' => 'Kannattava jäsen',
                    'honorary' => 'Kunniajäsen',
                    'full', 'founding' => 'Varsinainen jäsen',
                    default => 'Perusjäsen',
                };
                $currentStatus = (string) ($member['membership_status'] ?? '');
                $allowedNext = $nextStatuses($currentStatus);
                $canActivate = isset($allowedNext['active']);
                $canSuspend = isset($allowedNext['suspended']);
                $canTerminate = isset($allowedNext['terminated']);
                $joinedAt = (string) (($member['membership_started_at'] ?? '') ?: ($member['created_at'] ?? ''));
            ?>
            <article class="card members-person-card">
                <div class="card__body">
                    <div class="members-person-card__top">
                        <div>
                            <h3 class="members-person-card__name"><?= $esc((string) ($member['name'] ?? '')) ?></h3>
                            <p class="members-person-card__email"><?= $esc((string) ($member['email'] ?? '')) ?></p>
                        </div>
                        <?php if ($isGsa): ?>
                        <div class="members-icon-actions" role="group" aria-label="Member actions for <?= $esc((string) ($member['name'] ?? '')) ?>">
                            <button type="button" class="members-icon-action" data-modal-action="activate" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" aria-label="Activate member" title="<?= $canActivate ? 'Activate' : 'Not allowed from current status' ?>"<?= $canActivate ? '' : ' disabled' ?>>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M10 16.5 6 12.5l1.4-1.4 2.6 2.6 6.6-6.6L18 8.5z"/></svg>
                            </button>
                            <button type="button" class="members-icon-action" data-modal-action="suspend" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" aria-label="Suspend member" title="<?= $canSuspend ? 'Suspend' : 'Not allowed from current status' ?>"<?= $canSuspend ? '' : ' disabled' ?>>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M7 5h2v14H7zm8 0h2v14h-2z"/></svg>
                            </button>
                            <button type="button" class="members-icon-action members-icon-action--danger" data-modal-action="terminate" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" aria-label="Terminate member" title="<?= $canTerminate ? 'Terminate' : 'Not allowed from current status' ?>"<?= $canTerminate ? '' : ' disabled' ?>>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M19 6.4 17.6 5 12 10.6 6.4 5 5 6.4 10.6 12 5 17.6 6.4 19 12 13.4 17.6 19 19 17.6 13.4 12z"/></svg>
                            </button>
                            <button type="button" class="members-icon-action" data-modal-action="audit" data-member-id="<?= $esc($memberId) ?>" data-member-name="<?= $esc((string) ($member['name'] ?? '')) ?>" data-audit-url="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page, 'audit' => $memberId]) ?>" aria-label="Open audit log" title="Audit">
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M13 3a9 9 0 1 0 8.7 11.3h-2.1A7 7 0 1 1 13 5v4l5-5-5-5z"/></svg>
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="members-actions-disabled">GSA only</span>
                        <?php endif; ?>
                    </div>

                    <div class="members-person-card__meta">
                        <span class="members-chip"># <?= $esc((string) ($member['member_number'] ?? '-')) ?></span>
                        <span class="members-chip"><?= $esc($category) ?></span>
                        <span class="members-chip"><?= $esc((string) ($member['membership_status'] ?? '')) ?></span>
                    </div>

                    <dl class="members-person-card__grid">
                        <div>
                            <dt>Joined</dt>
                            <dd><?= $esc($joinedAt !== '' ? substr($joinedAt, 0, 10) : '-') ?></dd>
                        </div>
                        <div>
                            <dt>Country</dt>
                            <dd><?= $esc((string) ($member['country'] ?? '-')) ?></dd>
                        </div>
                        <div>
                            <dt>Date of Birth</dt>
                            <dd><?= $esc((string) (($member['date_of_birth'] ?? '') ?: '-')) ?></dd>
                        </div>
                    </dl>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php if ($isGsa): ?>
<!-- Section: Action modals for member status and audit -->
<div class="members-modal-backdrop" id="members-modal-activate" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-activate-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-activate-title">Activate Member</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Member: <strong data-field="member-name">-</strong></p>
            <form method="post" action="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page, 'audit' => $auditFor]) ?>" class="members-modal__form">
                <input type="hidden" name="member_id" data-field="member-id" value="">
                <input type="hidden" name="status" value="active">
                <label class="members-field">
                    <span class="members-label">Note / reason</span>
                    <textarea name="reason" rows="4" required placeholder="Write reason for activation"></textarea>
                </label>
                <div class="members-modal__footer">
                    <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn--primary">Activate</button>
                </div>
            </form>
        </div>
    </div>
</div>
<div class="members-modal-backdrop" id="members-modal-suspend" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-suspend-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-suspend-title">Suspend Member</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Member: <strong data-field="member-name">-</strong></p>
            <form method="post" action="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page, 'audit' => $auditFor]) ?>" class="members-modal__form">
                <input type="hidden" name="member_id" data-field="member-id" value="">
                <input type="hidden" name="status" value="suspended">
                <label class="members-field">
                    <span class="members-label">Note / reason</span>
                    <textarea name="reason" rows="4" required placeholder="Write reason for suspension"></textarea>
                </label>
                <div class="members-modal__footer">
                    <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn btn--primary">Suspend</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="members-modal-backdrop" id="members-modal-terminate" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-terminate-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-terminate-title">Terminate Member</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Member: <strong data-field="member-name">-</strong></p>
            <form method="post" action="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page, 'audit' => $auditFor]) ?>" class="members-modal__form">
                <input type="hidden" name="member_id" data-field="member-id" value="">
                <input type="hidden" name="status" value="terminated">
                <label class="members-field">
                    <span class="members-label">Note / reason</span>
                    <textarea name="reason" rows="4" required placeholder="Write reason for termination"></textarea>
                </label>
                <div class="members-modal__footer">
                    <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                    <button type="submit" class="btn members-btn-danger">Terminate</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="members-modal-backdrop" id="members-modal-audit" hidden aria-hidden="true">
    <div class="members-modal" role="dialog" aria-modal="true" aria-labelledby="members-modal-audit-title">
        <div class="members-modal__header">
            <h2 class="members-modal__title" id="members-modal-audit-title">Member Audit</h2>
            <button type="button" class="members-modal__close" data-modal-close aria-label="Close dialog">×</button>
        </div>
        <div class="members-modal__body">
            <p class="members-modal__member">Open audit log for <strong data-field="member-name">-</strong></p>
            <div class="members-modal__footer">
                <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
                <a href="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page]) ?>" class="btn btn--primary" data-field="audit-link">Open Audit</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($auditFor !== ''): ?>
<div class="card members-audit-card">
    <div class="card__body">
        <div class="members-audit-header">
            <h2 class="card__title">Audit Log</h2>
            <a class="btn btn--ghost btn--sm" href="/backstage/members<?= $buildQuery(['tab' => 'register', 'page' => $page]) ?>">Close</a>
        </div>
        <?php if (!$auditRows): ?>
            <p class="members-empty-text">No audit entries found for this member.</p>
        <?php else: ?>
            <div class="members-audit-wrap">
                <table class="data-table members-audit-table">
                    <thead>
                        <tr>
                            <th>Changed At</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Changed By</th>
                            <th>Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($auditRows as $row): ?>
                        <tr>
                            <td><?= $esc((string) ($row['changed_at'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['from_status'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['to_status'] ?? '')) ?></td>
                            <td><?= $esc((string) ($row['changed_by_name'] ?? 'Unknown')) ?></td>
                            <td><?= $esc((string) ($row['reason'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
</section>
<?php else: ?>
<!-- ============================================================ Pending tab -->
<section role="tabpanel" data-tab="pending">
<?php
$icon_clock     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></svg>';
$icon_check     = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M20 6L9 17l-5-5"/></svg>';
$icon_x         = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M18 6L6 18M6 6l12 12"/></svg>';
$icon_hourglass = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M5 22h14M5 2h14M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22M17 2v4.172a2 2 0 0 1-.586 1.414L12 12 7.586 7.586A2 2 0 0 1 7 6.172V2"/></svg>';

$pendingKpis = [
    ['kpi_id' => 'pending',            'label' => 'Pending',         'value' => '—', 'icon_html' => $icon_clock,     'icon_variant' => 'amber',  'trend_label' => 'awaiting decision', 'trend_direction' => 'warn'],
    ['kpi_id' => 'approved_30d',       'label' => 'Approved (30d)',  'value' => '—', 'icon_html' => $icon_check,     'icon_variant' => 'green',  'trend_label' => 'last 30 days',      'trend_direction' => 'muted'],
    ['kpi_id' => 'rejected_30d',       'label' => 'Rejected (30d)',  'value' => '—', 'icon_html' => $icon_x,         'icon_variant' => 'red',    'trend_label' => 'last 30 days',      'trend_direction' => 'muted'],
    ['kpi_id' => 'avg_response_hours', 'label' => 'Avg response',    'value' => '—', 'icon_html' => $icon_hourglass, 'icon_variant' => 'gray',   'trend_label' => 'created → decided', 'trend_direction' => 'muted'],
];
?>
<div class="kpis-grid">
  <?php foreach ($pendingKpis as $kpi): daems_shared_partial('components/cards/kpi-card/kpi-card', $kpi); endforeach; ?>
</div>
<script src="/pages/backstage/members/applications-stats.js" defer></script>

<?php if ($flashSuccess !== null): ?>
<div class="card" style="border-left:4px solid var(--status-success);margin-bottom:var(--space-4);">
    <div class="card__body" style="color:var(--status-success);font-weight:600;"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
</div>
<?php endif; ?>

<?php if ($inviteUrl !== null): ?>
<div class="pending-app-toast pending-app-toast--success" style="position:static;animation:none;margin-bottom:var(--space-4);cursor:default;" id="invite-success-banner">
    <div class="pending-app-toast__title">Application approved — invite link (valid 7 days)</div>
    <?php if ($assignedNumber !== null && $assignedNumberLabel !== null): ?>
    <div class="pending-app-toast__meta"><?= htmlspecialchars($assignedNumberLabel, ENT_QUOTES, 'UTF-8') ?>: <strong><?= htmlspecialchars($assignedNumber, ENT_QUOTES, 'UTF-8') ?></strong></div>
    <?php endif; ?>
    <?php if ($inviteExpiresAt !== null): ?>
    <div class="pending-app-toast__meta">Expires: <?= htmlspecialchars($inviteExpiresAt, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <div style="margin-top:8px;word-break:break-all;">
        <a href="<?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?>" id="invite-url-link" target="_blank" rel="noopener"><?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?></a>
    </div>
    <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <button type="button" id="copy-invite" style="padding:4px 12px;border:1px solid currentColor;border-radius:4px;background:transparent;cursor:pointer;font-size:13px;">Copy link</button>
        <button type="button" id="email-invite" style="padding:4px 12px;border:1px solid currentColor;border-radius:4px;background:transparent;cursor:pointer;font-size:13px;">Send via email</button>
        <span id="email-invite-status" style="font-size:13px;color:var(--status-success);display:none;"></span>
    </div>
</div>
<script>
(function () {
    var copyBtn = document.getElementById('copy-invite');
    var emailBtn = document.getElementById('email-invite');
    var statusEl = document.getElementById('email-invite-status');
    var url = <?= json_encode($inviteUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    if (!copyBtn || !url) { return; }
    copyBtn.addEventListener('click', function () {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(function () {
                copyBtn.textContent = 'Copied!';
                setTimeout(function () { copyBtn.textContent = 'Copy link'; }, 2000);
            }).catch(function () {
                prompt('Copy this invite link:', url);
            });
        } else {
            prompt('Copy this invite link:', url);
        }
    });
    if (emailBtn && statusEl) {
        emailBtn.addEventListener('click', function () {
            emailBtn.disabled = true;
            emailBtn.textContent = 'Sending…';
            // TODO: replace with real POST to /api/auth/send-invite-email when Mailu is deployed.
            setTimeout(function () {
                emailBtn.textContent = 'Sent';
                statusEl.style.display = 'inline';
                statusEl.textContent = 'Invite link sent to the applicant (mail delivery not yet wired — shown here for UX).';
            }, 400);
        });
    }
})();
</script>
<?php endif; ?>

<?php if ($flashError !== null): ?>
<div class="card" style="border-left:4px solid var(--status-danger);margin-bottom:var(--space-4);">
    <div class="card__body" style="color:var(--status-danger);font-weight:600;"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
</div>
<?php endif; ?>

<div class="card" style="margin-bottom:var(--space-4);">
    <div class="card__body" style="display:flex;justify-content:space-between;align-items:center;gap:var(--space-3);">
        <strong>Pending applications: <?= $totalPending ?></strong>
        <a href="/backstage" class="btn btn--ghost btn--sm">Back to dashboard</a>
    </div>
</div>

<div class="card" style="margin-bottom:var(--space-4);">
    <div class="card__body">
        <h2 class="card__title" style="margin-bottom:var(--space-3);">Member Applications (<?= count($memberPending) ?>)</h2>
        <?php if (!$memberPending): ?>
            <p style="color:var(--text-muted);">No pending member applications.</p>
        <?php else: ?>
            <?php foreach ($memberPending as $app): ?>
                <article id="app-<?= htmlspecialchars((string) ($app['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="card" style="margin-bottom:var(--space-3);border:1px solid var(--surface-border);">
                    <div class="card__body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-2);margin-bottom:var(--space-3);">
                            <div><strong>Name:</strong> <?= htmlspecialchars((string) ($app['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Email:</strong> <?= htmlspecialchars((string) ($app['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>DOB:</strong> <?= htmlspecialchars((string) ($app['date_of_birth'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Country:</strong> <?= htmlspecialchars((string) ($app['country'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <p style="margin-bottom:var(--space-2);"><strong>Motivation:</strong><br><?= nl2br(htmlspecialchars((string) ($app['motivation'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                        <form method="post" action="/backstage/members?tab=pending" style="display:grid;grid-template-columns:1fr auto auto;gap:var(--space-2);align-items:center;">
                            <input type="hidden" name="type" value="member">
                            <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($app['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <textarea name="note" rows="2" placeholder="Optional review note" style="width:100%;"></textarea>
                            <button type="submit" name="decision" value="approved" class="btn btn--primary btn--sm">Approve</button>
                            <button type="submit" name="decision" value="rejected" class="btn btn--ghost btn--sm">Reject</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card__body">
        <h2 class="card__title" style="margin-bottom:var(--space-3);">Supporter Applications (<?= count($supporterPending) ?>)</h2>
        <?php if (!$supporterPending): ?>
            <p style="color:var(--text-muted);">No pending supporter applications.</p>
        <?php else: ?>
            <?php foreach ($supporterPending as $app): ?>
                <article id="app-<?= htmlspecialchars((string) ($app['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" class="card" style="margin-bottom:var(--space-3);border:1px solid var(--surface-border);">
                    <div class="card__body">
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:var(--space-2);margin-bottom:var(--space-3);">
                            <div><strong>Organization:</strong> <?= htmlspecialchars((string) ($app['org_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Contact:</strong> <?= htmlspecialchars((string) ($app['contact_person'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Email:</strong> <?= htmlspecialchars((string) ($app['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                            <div><strong>Country:</strong> <?= htmlspecialchars((string) ($app['country'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <p style="margin-bottom:var(--space-2);"><strong>Motivation:</strong><br><?= nl2br(htmlspecialchars((string) ($app['motivation'] ?? ''), ENT_QUOTES, 'UTF-8')) ?></p>
                        <form method="post" action="/backstage/members?tab=pending" style="display:grid;grid-template-columns:1fr auto auto;gap:var(--space-2);align-items:center;">
                            <input type="hidden" name="type" value="supporter">
                            <input type="hidden" name="id" value="<?= htmlspecialchars((string) ($app['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <textarea name="note" rows="2" placeholder="Optional review note" style="width:100%;"></textarea>
                            <button type="submit" name="decision" value="approved" class="btn btn--primary btn--sm">Approve</button>
                            <button type="submit" name="decision" value="rejected" class="btn btn--ghost btn--sm">Reject</button>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var params = new URLSearchParams(window.location.search);
    var highlightId = params.get('highlight');
    if (!highlightId) { return; }
    var el = document.getElementById('app-' + highlightId);
    if (!el) { return; }
    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    el.style.outline = '3px solid var(--brand-primary)';
    el.style.outlineOffset = '3px';
    setTimeout(function () {
        el.style.outline = '';
        el.style.outlineOffset = '';
    }, 2000);
})();
</script>
</section>
<?php endif; ?>
</div>

<script>
(function () {
    // Section: list/cards view toggle (Register tab only)
    const listView = document.getElementById('members-list-view');
    const cardsView = document.getElementById('members-cards-view');
    const listBtn = document.getElementById('members-view-list');
    const cardsBtn = document.getElementById('members-view-cards');

    if (!listView || !cardsView || !listBtn || !cardsBtn) {
        // Pending tab — view-toggle nodes absent; skip block.
    } else {
        const applyView = function (view) {
            const useCards = view === 'cards';
            cardsView.hidden = !useCards;
            listView.hidden = useCards;
            listBtn.classList.toggle('is-active', !useCards);
            listBtn.setAttribute('aria-pressed', useCards ? 'false' : 'true');
            cardsBtn.classList.toggle('is-active', useCards);
            cardsBtn.setAttribute('aria-pressed', useCards ? 'true' : 'false');
        };

        const saved = localStorage.getItem('daems-members-view');
        applyView(saved === 'cards' ? 'cards' : 'list');

        listBtn.addEventListener('click', function () {
            localStorage.setItem('daems-members-view', 'list');
            applyView('list');
        });

        cardsBtn.addEventListener('click', function () {
            localStorage.setItem('daems-members-view', 'cards');
            applyView('cards');
        });
    }

    // Section: per-action modals for icon actions (Register tab only)
    const modalByAction = {
        activate: document.getElementById('members-modal-activate'),
        suspend: document.getElementById('members-modal-suspend'),
        terminate: document.getElementById('members-modal-terminate'),
        audit: document.getElementById('members-modal-audit')
    };

    let currentModal = null;

    const closeModal = function () {
        if (!currentModal) {
            return;
        }
        currentModal.hidden = true;
        currentModal.setAttribute('aria-hidden', 'true');
        currentModal = null;
    };

    const openModal = function (action, trigger) {
        const modal = modalByAction[action];
        if (!modal) {
            return;
        }

        const memberId = trigger.getAttribute('data-member-id') || '';
        const memberName = trigger.getAttribute('data-member-name') || 'Unknown';
        const auditUrl = trigger.getAttribute('data-audit-url') || '/backstage/members?tab=register';

        modal.querySelectorAll('[data-field="member-name"]').forEach(function (el) {
            el.textContent = memberName;
        });
        modal.querySelectorAll('[data-field="member-id"]').forEach(function (el) {
            el.value = memberId;
        });

        const auditLink = modal.querySelector('[data-field="audit-link"]');
        if (auditLink) {
            auditLink.setAttribute('href', auditUrl);
        }

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        currentModal = modal;

        const focusTarget = modal.querySelector('textarea, button, a');
        if (focusTarget) {
            focusTarget.focus();
        }
    };

    document.querySelectorAll('.members-icon-action[data-modal-action]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) {
                return;
            }
            openModal(btn.getAttribute('data-modal-action') || '', btn);
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', closeModal);
    });

    Object.values(modalByAction).forEach(function (modal) {
        if (!modal) {
            return;
        }
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeModal();
        }
    });
})();
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
```

- [ ] **Step 4: Browser smoke — Register tab**

Visit `http://daem-society.local/backstage/members` (no `tab=` param).
Expected:
- Page title "Members Register"
- Tab bar shows "Register" (active) + "Pending" with badge count
- KPI strip = 4 cards (Total members, New, Supporters, Inactive)
- Filters, table, cards-toggle all work as before
- Click a sortable column header → URL gets `?tab=register&sort=...` and page re-renders sorted

- [ ] **Step 5: Browser smoke — Pending tab**

Visit `http://daem-society.local/backstage/members?tab=pending`.
Expected:
- Tab bar shows "Pending" (active)
- KPI strip = 4 cards (Pending, Approved 30d, Rejected 30d, Avg response)
- Two cards: "Member Applications (N)" + "Supporter Applications (M)"
- Each pending row has Approve / Reject buttons + textarea note

- [ ] **Step 6: Browser smoke — Approve flow inside Members page**

On Pending tab, approve a test application (or reject if no DB seed exists).
Expected:
- POST hits `/backstage/members?tab=pending`
- Page reloads on Pending tab
- Invite-link banner renders inline at top of pending content
- Member-number assignment shows
- "Copy link" button works

- [ ] **Step 7: Commit**

```bash
cd C:/laragon/www/sites/daem-society
git add public/pages/backstage/members/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Refactor(backstage): tabbed Members page (Register + Pending)"
```

---

## Task 4: Replace applications/index.php with 302 redirect

**Files:**
- Modify (full rewrite): `public/pages/backstage/applications/index.php`
- Delete: `public/pages/backstage/applications/applications-stats.js`

After this task `/backstage/applications` 302-redirects to `/backstage/members?tab=pending`. The router entry in `public/index.php:384` continues to map `/applications` → `pages/backstage/applications/index.php`, which is now a one-line redirect stub.

- [ ] **Step 1: Replace applications/index.php**

Overwrite `public/pages/backstage/applications/index.php` with exactly:

```php
<?php
/**
 * Legacy /backstage/applications URL — redirected to the merged
 * /backstage/members?tab=pending page (see plan
 * docs/superpowers/plans/2026-04-27-applications-members-merge.md).
 */
declare(strict_types=1);

header('Location: /backstage/members?tab=pending', true, 302);
exit;
```

- [ ] **Step 2: Delete obsolete applications-stats.js**

```bash
cd C:/laragon/www/sites/daem-society
rm public/pages/backstage/applications/applications-stats.js
```

- [ ] **Step 3: Browser smoke — redirect**

Visit `http://daem-society.local/backstage/applications`.
Expected: browser lands on `http://daem-society.local/backstage/members?tab=pending` (note URL bar shows the new path), Pending tab is active.

DevTools Network tab: first request is 302, second is 200 GET on the members page.

- [ ] **Step 4: Commit**

```bash
git add public/pages/backstage/applications/index.php
git rm public/pages/backstage/applications/applications-stats.js
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Replace(backstage): /backstage/applications becomes 302 → members?tab=pending"
```

---

## Task 5: Sidebar — drop Applications row, badge moves to Members

**Files:**
- Modify: `public/pages/backstage/layout.php` (sidebar block, lines ~165-200)

After this task the sidebar shows only the Members entry, with the pending-count badge attached to it.

- [ ] **Step 1: Read current sidebar block**

```bash
cd C:/laragon/www/sites/daem-society
```

Read lines 160-205 of `public/pages/backstage/layout.php`. Two link rows are present:
1. Applications row (`href="/backstage/applications"`, `applications-badge` element inside)
2. Members row (`href="/backstage/members"`)

- [ ] **Step 2: Find and replace the Applications sidebar row**

Find this exact block in `public/pages/backstage/layout.php` (the Applications sidebar row, ~lines 171-182):

```php
                    <a href="/backstage/applications" class="sidebar__item <?= $__isActive('applications') ?>"
                       <?= $__activePage === 'applications' ? 'aria-current="page"' : '' ?>>
                        <span class="sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/>
                                <polyline points="14 2 14 8 20 8"/>
                            </svg>
                        </span>
                        <span class="sidebar__label">Applications</span>
                        <span class="sidebar__badge" id="applications-badge" style="display:none"></span>
                    </a>
```

Replace it with the empty string (delete the row entirely).

- [ ] **Step 3: Find and modify the Members sidebar row**

Locate the Members row (immediately after the deleted Applications row, ~lines 184-191):

```php
                    <a href="/backstage/members" class="sidebar__item <?= $__isActive('members') ?>"
                       <?= $__activePage === 'members' ? 'aria-current="page"' : '' ?>>
                        <span class="sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M4 21a8 8 0 0 1 16 0"/>
                            </svg>
                        </span>
                        <span class="sidebar__label">Members</span>
                    </a>
```

Replace it with (badge added before closing `</a>`):

```php
                    <a href="/backstage/members" class="sidebar__item <?= $__isActive('members') ?>"
                       <?= $__activePage === 'members' ? 'aria-current="page"' : '' ?>>
                        <span class="sidebar__icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7">
                                <circle cx="12" cy="8" r="4"/>
                                <path d="M4 21a8 8 0 0 1 16 0"/>
                            </svg>
                        </span>
                        <span class="sidebar__label">Members</span>
                        <span class="sidebar__badge" id="members-pending-badge" style="display:none"></span>
                    </a>
```

- [ ] **Step 4: Find the badge-update JS in layout.php and rebind it**

Search `layout.php` for `applications-badge` (should be one or more JS references that update the badge from the fetched count). Rename every remaining occurrence of `applications-badge` to `members-pending-badge` in the layout file.

```bash
grep -n "applications-badge" public/pages/backstage/layout.php
```

Replace each match. There may be one in a `<script>` block that does `document.getElementById('applications-badge')` — change to `document.getElementById('members-pending-badge')`.

- [ ] **Step 5: Browser smoke — sidebar**

Visit `http://daem-society.local/backstage/members`.
Expected:
- Sidebar shows Members entry, no Applications entry
- Pending count badge visible on Members row (if there are pending apps in DB)
- Badge hidden if count is 0
- Click Members row → page does not change (already there)

- [ ] **Step 6: Commit**

```bash
git add public/pages/backstage/layout.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(backstage): drop Applications sidebar entry, badge moves to Members"
```

---

## Task 6: Bottom-nav and command palette cleanup

**Files:**
- Modify: `public/pages/backstage/layout.php` (bottom-nav block ~line 439, command-palette block ~line 476)

- [ ] **Step 1: Remove Applications from bottom-nav**

Find this block in `public/pages/backstage/layout.php` (~line 439):

```php
    <a href="/backstage/applications" class="bottom-nav__item <?= $__isActive('applications') ?>">
        <svg ...>
            ...
        </svg>
        <span class="bottom-nav__label">Applications</span>
    </a>
```

Delete the entire `<a>` block (typically 5-8 lines). Confirm by greping after the edit:

```bash
grep -n 'bottom-nav.*applications' public/pages/backstage/layout.php
```

Expected: no matches.

- [ ] **Step 2: Update command palette listing**

Find this exact line (~line 476):

```php
            <li class="cmd-palette__item" role="option" data-href="/backstage/applications"><span class="cmd-palette__item-label">Applications</span><span class="cmd-palette__item-section">Main</span></li>
```

Replace it with:

```php
            <li class="cmd-palette__item" role="option" data-href="/backstage/members?tab=pending"><span class="cmd-palette__item-label">Pending applications</span><span class="cmd-palette__item-section">Main</span></li>
```

- [ ] **Step 3: Browser smoke — bottom nav + cmd palette**

Visit `http://daem-society.local/backstage/members` on a narrow viewport (DevTools mobile preview).
Expected: bottom nav has Members but not Applications.

Press `Ctrl+K` to open command palette. Search `appli` → "Pending applications" entry appears, clicking it routes to `/backstage/members?tab=pending`.

- [ ] **Step 4: Commit**

```bash
git add public/pages/backstage/layout.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(backstage): drop Applications from bottom-nav, rename cmd-palette entry"
```

---

## Task 7: Update toasts.js + notifications page URL references

**Files:**
- Modify: `public/pages/backstage/toasts.js` (lines 93, 119)
- Modify: `public/pages/backstage/notifications/index.php` (line 90)

- [ ] **Step 1: Update toasts.js line 93**

Find:

```javascript
                window.location.href = '/backstage/applications?highlight=' + encodeURIComponent(item.id);
```

Replace with:

```javascript
                window.location.href = '/backstage/members?tab=pending&highlight=' + encodeURIComponent(item.id);
```

- [ ] **Step 2: Update toasts.js line 119**

Find:

```javascript
            window.location.href = '/backstage/applications';
```

Replace with:

```javascript
            window.location.href = '/backstage/members?tab=pending';
```

- [ ] **Step 3: Update notifications/index.php line 90**

Find:

```php
        case 'supporter':        return '/backstage/applications?highlight=' . rawurlencode($id);
```

Replace with:

```php
        case 'supporter':        return '/backstage/members?tab=pending&highlight=' . rawurlencode($id);
```

- [ ] **Step 4: Confirm no other hardcoded /backstage/applications URLs remain (besides the redirect stub)**

```bash
grep -rn "/backstage/applications" public/pages/ public/api/
```

Expected results:
- `pages/backstage/applications/index.php` — the redirect stub (allowed)
- `pages/backstage/notifications/index.php` (line 5, 26) — these reference the **API** path `/api/v1/backstage/applications/pending-count`, not the frontend page; allowed
- `layout.php` (line 24) — same, references API path; allowed
- `api/backstage/applications.php` and `api/backstage/dismiss.php` — proxy files calling backend; allowed

If any other frontend HTML/JS link to `/backstage/applications` (without the `/api/v1/` prefix and not the redirect stub) appears, fix it. Otherwise proceed.

- [ ] **Step 5: Browser smoke — toast + notifications**

1. From dashboard `/backstage`, if the global pending-applications toast renders, click "View" → routes to `/backstage/members?tab=pending`.
2. Visit `/backstage/notifications` → click an item with type=member or type=supporter → routes to `/backstage/members?tab=pending&highlight=<id>` and the highlighted row scrolls into view.

- [ ] **Step 6: Commit**

```bash
git add public/pages/backstage/toasts.js public/pages/backstage/notifications/index.php
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Update(backstage): toast + notifications links point to /members?tab=pending"
```

---

## Task 8: Browser smoke checklist (full)

**Files:** none modified — verification only.

This task runs the full smoke checklist from the spec section 6.3. No commit. If any check fails, stop and diagnose before declaring the work done.

- [ ] **Smoke 1: Register tab default**

Visit `http://daem-society.local/backstage/members`. Expected: Register tab active, Members KPI strip visible (Total members, New 30d, Supporters, Inactive), table populated.

- [ ] **Smoke 2: Pending tab via query**

Visit `http://daem-society.local/backstage/members?tab=pending`. Expected: Pending tab active, Applications KPI strip visible (Pending, Approved 30d, Rejected 30d, Avg response), both sub-section cards render.

- [ ] **Smoke 3: Legacy URL redirect**

Visit `http://daem-society.local/backstage/applications`. Expected: 302 redirect lands on `/backstage/members?tab=pending`.

- [ ] **Smoke 4: Sidebar single entry**

Confirm sidebar shows only one Members entry. Click it → lands on `/backstage/members` (Register default).

- [ ] **Smoke 5: Sidebar badge**

Confirm pending count on the Members sidebar row matches the dashboard's pending-applications toast count (or both 0).

- [ ] **Smoke 6: Approve flow inline**

On Pending tab, approve an application (use a test record or seed if needed). Expected:
- POST hits `/backstage/members?tab=pending`
- Invite-link banner renders inline
- Assigned member_number / supporter_number shows in the banner
- Page stays on Pending tab after submit
- Copy-link button works

- [ ] **Smoke 7: Status modals on Register tab**

On Register tab, with a GSA login, open Activate / Suspend / Terminate modal for any member. Expected:
- Modal opens with member name shown
- Submit with reason → flash success banner appears
- Audit log shows new entry on next visit

- [ ] **Smoke 8: Dashboard toast routing**

From `/backstage`, click "View applications" on the global pending-toast → lands on `/backstage/members?tab=pending`.

- [ ] **Smoke 9: Command palette routing**

Press `Ctrl+K`. Search `appli` → "Pending applications" entry routes to Pending tab. Search `member` → "Members" entry routes to Register tab.

- [ ] **Smoke 10: CSV export**

On Register tab, click "Export CSV" → download starts, file contains all expected columns including the active filter result set.

- [ ] **Smoke 11: Notifications page item routing**

Visit `/backstage/notifications`. Click any item with type=member or type=supporter (if any exist) → lands on `/backstage/members?tab=pending&highlight=<id>` with the row outlined.

- [ ] **Smoke 12: Console / network sanity**

DevTools Console: no JS errors on either tab.
DevTools Network: KPI fetch requests succeed (200 from `/api/backstage/members.php?op=stats` on Register and `/api/backstage/applications.php?op=stats` on Pending).

---

## Task 9: Commit the plan to platform repo

**Files:**
- Add: `daems-platform/docs/superpowers/plans/2026-04-27-applications-members-merge.md`

The plan itself lives in the platform repo even though all code changes happen in daem-society. Commit the plan file before closing the task.

- [ ] **Step 1: Verify plan file exists**

```bash
ls -la C:/laragon/www/daems-platform/docs/superpowers/plans/2026-04-27-applications-members-merge.md
```

Expected: file exists.

- [ ] **Step 2: Commit the plan**

```bash
cd C:/laragon/www/daems-platform
git add docs/superpowers/plans/2026-04-27-applications-members-merge.md
git -c user.name="Dev Team" -c user.email="dev@daems.fi" commit -m "Plan(backstage): merge Applications into Members as tabbed page"
```

- [ ] **Step 3: Report SHAs**

Print the commit SHAs from both repos:

```bash
cd C:/laragon/www/sites/daem-society && git log --oneline -10
cd C:/laragon/www/daems-platform && git log --oneline -3
```

Hand off the list of new SHAs to the user. Wait for explicit "pushaa" instruction before any push.
