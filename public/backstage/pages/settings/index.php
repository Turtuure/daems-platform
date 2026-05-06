<?php
/**
 * Backstage Settings
 *
 * Read-only overview of the admin's account, active tenant, and
 * platform details, plus stubs for upcoming per-tenant preferences.
 *
 * Data source: /api/v1/auth/me (served by AuthController::me).
 * Nothing here persists yet — the "Coming soon" cards mark the
 * extension points planned in the roadmap.
 */

declare(strict_types=1);

use Daems\Frontend\ApiClient;
use Daems\Frontend\I18n;

$pageTitle   = 'backstage.title.settings';
$activePage  = 'settings';
$breadcrumbs = [];

$token = (string) ($_SESSION['token'] ?? '');

/**
 * Fetch {user, tenant, role_in_tenant, token_expires_at} from the platform.
 * Returns an empty array + populates $meError on failure so the template
 * can render a diagnostic card instead of a silent blank page.
 */
$meError = null;
$me = (static function (string $token, ?string &$err): array {
    $ch = curl_init('http://daems-platform.local/api/v1/auth/me');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => array_filter([
            'Accept: application/json',
            $token !== '' ? ('Authorization: Bearer ' . $token) : null,
            'Host: daems-platform.local',
        ]),
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300 && is_string($raw)) {
        $d = json_decode($raw, true);
        if (is_array($d) && isset($d['data']) && is_array($d['data'])) {
            return $d['data'];
        }
    }
    $err = $code . (is_string($raw) ? ' — ' . substr($raw, 0, 160) : '');
    return [];
})($token, $meError);

$user   = is_array($me['user']   ?? null) ? $me['user']   : [];
$tenant = is_array($me['tenant'] ?? null) ? $me['tenant'] : [];
$roleInTenant = is_string($me['role_in_tenant'] ?? null) ? $me['role_in_tenant'] : null;
$tokenExpires = is_string($me['token_expires_at'] ?? null) ? $me['token_expires_at'] : null;

$esc   = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$empty = I18n::t('backstage.common.empty');

$tokenExpiresDisplay = $empty;
if ($tokenExpires) {
    $ts = strtotime($tokenExpires);
    if ($ts !== false) {
        $tokenExpiresDisplay = date('j.n.Y H:i', $ts);
    }
}

$roleLabels = [
    'admin'       => I18n::t('backstage.settings.role.admin'),
    'moderator'   => I18n::t('backstage.settings.role.moderator'),
    'member'      => I18n::t('backstage.settings.role.member'),
    'supporter'   => I18n::t('backstage.settings.role.supporter'),
    'registered'  => I18n::t('backstage.settings.role.registered'),
];
$roleDisplay = $roleInTenant !== null
    ? ($roleLabels[$roleInTenant] ?? ucfirst($roleInTenant))
    : I18n::t('backstage.settings.role.no_role');

$phpVersion = PHP_VERSION;
$appEnv     = $_ENV['APP_ENV'] ?? 'development';
$appVersion = 'v1.0.0';

ob_start();
?>
<div class="settings-admin">

<div class="page-header">
    <div>
        <h1 class="page-header__title"><?= I18n::e('backstage.settings.title') ?></h1>
        <p class="page-header__subtitle"><?= I18n::e('backstage.settings.subtitle') ?></p>
    </div>
</div>

<?php if ($meError !== null): ?>
<div class="card" style="border-left:4px solid var(--status-error); margin-bottom:1rem;">
    <div class="card__body">
        <strong style="color:var(--status-error);"><?= I18n::e('backstage.settings.error.load_failed') ?></strong>
        <code style="font-size:.8rem; display:block; margin-top:.5rem;"><?= $esc($meError) ?></code>
    </div>
</div>
<?php endif; ?>

<div class="settings-grid">

    <!-- You -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-person-circle"></i> <?= I18n::e('backstage.settings.you.heading') ?></h2>
            <dl class="settings-dl">
                <dt><?= I18n::e('backstage.settings.field.name') ?></dt>
                <dd><?= $esc((string) ($user['name'] ?? $empty)) ?></dd>

                <dt><?= I18n::e('backstage.settings.field.email') ?></dt>
                <dd><?= $esc((string) ($user['email'] ?? $empty)) ?></dd>

                <dt><?= I18n::e('backstage.settings.field.role') ?></dt>
                <dd>
                    <?php if (!empty($user['is_platform_admin'])): ?>
                        <span class="settings-pill settings-pill--gsa"><?= I18n::e('backstage.settings.role.gsa_full') ?></span>
                    <?php else: ?>
                        <span class="settings-pill settings-pill--muted"><?= I18n::e('backstage.settings.pill.muted') ?></span>
                    <?php endif; ?>
                </dd>

                <dt><?= I18n::e('backstage.settings.field.user_id') ?></dt>
                <dd><code><?= $esc((string) ($user['id'] ?? $empty)) ?></code></dd>
            </dl>
            <div class="settings-actions">
                <a href="/profile" class="btn btn--ghost btn--sm"><?= I18n::e('backstage.settings.you.edit_profile') ?> &rsaquo;</a>
            </div>
        </div>
    </div>

    <!-- Tenant -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-building"></i> <?= I18n::e('backstage.settings.tenant.heading') ?></h2>
            <dl class="settings-dl">
                <dt><?= I18n::e('backstage.settings.field.name') ?></dt>
                <dd><?= $esc((string) ($tenant['name'] ?? $empty)) ?></dd>

                <dt><?= I18n::e('backstage.settings.tenant.field.slug') ?></dt>
                <dd><code><?= $esc((string) ($tenant['slug'] ?? $empty)) ?></code></dd>

                <dt><?= I18n::e('backstage.settings.tenant.field.your_role') ?></dt>
                <dd><?= $esc($roleDisplay) ?></dd>
            </dl>
        </div>
    </div>

    <!-- Session -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-shield-lock"></i> <?= I18n::e('backstage.settings.session.heading') ?></h2>
            <dl class="settings-dl">
                <dt><?= I18n::e('backstage.settings.session.token_expires') ?></dt>
                <dd><?= $esc($tokenExpiresDisplay) ?></dd>
                <dt><?= I18n::e('backstage.settings.session.status') ?></dt>
                <dd><span class="settings-pill settings-pill--ok"><?= I18n::e('backstage.settings.session.status.active') ?></span></dd>
            </dl>
            <div class="settings-actions">
                <a href="/logout" class="btn btn--ghost btn--sm"><?= I18n::e('backstage.settings.session.logout') ?> &rsaquo;</a>
            </div>
        </div>
    </div>

    <!-- Platform -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-gear"></i> <?= I18n::e('backstage.settings.platform.heading') ?></h2>
            <dl class="settings-dl">
                <dt><?= I18n::e('backstage.settings.platform.app') ?></dt>
                <dd><?= $esc(I18n::t('backstage.settings.platform.app_name', ['version' => $appVersion])) ?></dd>

                <dt><?= I18n::e('backstage.settings.platform.php') ?></dt>
                <dd><?= $esc($phpVersion) ?></dd>

                <dt><?= I18n::e('backstage.settings.platform.env') ?></dt>
                <dd><code><?= $esc((string) $appEnv) ?></code></dd>
            </dl>
        </div>
    </div>

    <!-- Membership card — member-number prefix -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-credit-card"></i> <?= I18n::e('backstage.settings.member_card.heading') ?></h2>
            <p class="settings-help"><?= I18n::e('backstage.settings.member_card.help') ?></p>
            <form id="settings-form-membership-card" class="settings-form">
                <label class="settings-field">
                    <span class="settings-field-label"><?= I18n::e('backstage.settings.member_card.field.prefix') ?></span>
                    <input type="text" id="settings-member-number-prefix" maxlength="20"
                        pattern="[A-Z0-9\-]+"
                        placeholder="<?= I18n::e('backstage.settings.member_card.placeholder') ?>"
                        value="<?= $esc((string) ($tenant['member_number_prefix'] ?? '')) ?>" />
                </label>
                <div class="settings-actions">
                    <button type="submit" class="btn btn--primary btn--sm"><?= I18n::e('backstage.settings.member_card.save') ?></button>
                    <span id="settings-membership-card-status" class="settings-status" aria-live="polite"></span>
                </div>
            </form>
        </div>
    </div>

    <!-- Display preferences (DB-backed: tenant default + per-user override) -->
    <?php
        $__tfTenant = '24';
        $__tfUser   = null;   // null = inherit
        try {
            $__me = ApiClient::get('/auth/me');
            if (is_array($__me) && isset($__me['time_format']) && is_array($__me['time_format'])) {
                $td = $__me['time_format']['tenant_default'] ?? null;
                $uo = $__me['time_format']['user_override'] ?? null;
                if ($td === '12' || $td === '24') $__tfTenant = (string) $td;
                if ($uo === '12' || $uo === '24') $__tfUser   = (string) $uo;
            }
        } catch (\Throwable $e) { /* keep defaults */ }
        $__isTenantAdmin = !empty($user['is_platform_admin']) || $roleInTenant === 'admin';
    ?>
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-display"></i> <?= I18n::e('backstage.settings.display.heading') ?></h2>
            <p class="settings-help"><?= I18n::e('backstage.settings.display.help') ?></p>

            <div class="settings-pref-row">
                <div>
                    <span class="settings-pref-row__label"><?= I18n::e('backstage.settings.display.tenant_default_label') ?></span>
                    <p class="settings-help" style="margin:.15rem 0 0;">
                        <?= I18n::e('backstage.settings.display.tenant_default_help') ?>
                        <?php if (!$__isTenantAdmin): ?><br><em><?= I18n::e('backstage.settings.display.admin_only_note') ?></em><?php endif; ?>
                    </p>
                </div>
                <div class="settings-seg<?= $__isTenantAdmin ? '' : ' is-disabled' ?>" id="settings-tenant-time-format" data-current="<?= $__tfTenant ?>" data-can-edit="<?= $__isTenantAdmin ? '1' : '0' ?>" role="tablist" aria-label="<?= I18n::e('backstage.settings.display.aria.tenant_seg') ?>">
                    <button type="button" class="settings-seg-btn<?= $__tfTenant === '12' ? ' is-active' : '' ?>" data-value="12" <?= $__isTenantAdmin ? '' : 'disabled' ?>>12h</button>
                    <button type="button" class="settings-seg-btn<?= $__tfTenant === '24' ? ' is-active' : '' ?>" data-value="24" <?= $__isTenantAdmin ? '' : 'disabled' ?>>24h</button>
                </div>
            </div>

            <div class="settings-pref-row">
                <div>
                    <span class="settings-pref-row__label"><?= I18n::e('backstage.settings.display.user_label') ?></span>
                    <p class="settings-help" style="margin:.15rem 0 0;"><?= I18n::e('backstage.settings.display.user_help') ?></p>
                </div>
                <div class="settings-seg" id="settings-user-time-format" data-current="<?= $__tfUser ?? '' ?>" role="tablist" aria-label="<?= I18n::e('backstage.settings.display.aria.user_seg') ?>">
                    <button type="button" class="settings-seg-btn<?= $__tfUser === null ? ' is-active' : '' ?>" data-value=""><?= I18n::e('backstage.settings.display.user_inherit') ?></button>
                    <button type="button" class="settings-seg-btn<?= $__tfUser === '12' ? ' is-active' : '' ?>" data-value="12">12h</button>
                    <button type="button" class="settings-seg-btn<?= $__tfUser === '24' ? ' is-active' : '' ?>" data-value="24">24h</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Future: branding -->
    <div class="card settings-soon">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-palette"></i> <?= I18n::e('backstage.settings.branding.heading') ?> <span class="settings-pill settings-pill--muted"><?= I18n::e('backstage.settings.pill.coming_soon') ?></span></h2>
            <p class="settings-coming"><?= I18n::e('backstage.settings.branding.body') ?></p>
        </div>
    </div>

    <!-- Future: notifications -->
    <div class="card settings-soon">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-bell"></i> <?= I18n::e('backstage.settings.notifications.heading') ?> <span class="settings-pill settings-pill--muted"><?= I18n::e('backstage.settings.pill.coming_soon') ?></span></h2>
            <p class="settings-coming"><?= I18n::e('backstage.settings.notifications.body') ?></p>
        </div>
    </div>

</div><!-- /.settings-grid -->

</div><!-- /.settings-admin -->

<link rel="stylesheet" href="/backstage/pages/settings/settings.css">

<script>
window.DAEMS_SETTINGS_I18N = {
    saving:       <?= json_encode(I18n::t('backstage.settings.member_card.saving'),  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    saved:        <?= json_encode(I18n::t('backstage.settings.member_card.saved'),   JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    toastSaved:   <?= json_encode(I18n::t('backstage.settings.member_card.toast.saved'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    errorPrefix:  <?= json_encode(I18n::t('backstage.common.error_prefix'),          JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    networkError: <?= json_encode(I18n::t('backstage.common.network_error'),         JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    genericError: <?= json_encode(I18n::t('backstage.common.generic_error'),         JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    toastTenant:  <?= json_encode(I18n::t('backstage.settings.display.toast.tenant_set'),  JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    toastInherit: <?= json_encode(I18n::t('backstage.settings.display.toast.user_inherit'), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
    toastUserSet: <?= json_encode(I18n::t('backstage.settings.display.toast.user_set'),    JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
};
</script>

<script>
(function () {
    var T = window.DAEMS_SETTINGS_I18N;
    function fill(template, params) {
        if (!template) return '';
        if (!params) return template;
        return Object.keys(params).reduce(function (s, k) {
            return s.split('{' + k + '}').join(String(params[k]));
        }, template);
    }

    var form = document.getElementById('settings-form-membership-card');
    if (!form) return;
    var input  = document.getElementById('settings-member-number-prefix');
    var status = document.getElementById('settings-membership-card-status');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var raw = input.value.trim();
        var payload = { member_number_prefix: raw === '' ? null : raw };
        status.textContent = T.saving;

        fetch('/api/backstage/tenant-settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (res) {
            if (res.ok) {
                status.textContent = T.saved;
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(T.toastSaved, 'success');
            } else {
                var msg = (res.body && res.body.error) ? res.body.error : T.genericError;
                status.textContent = fill(T.errorPrefix, { msg: msg });
            }
        })
        .catch(function (err) { status.textContent = fill(T.networkError, { msg: err.message }); });
    });
})();

// Time format — DB-backed. Two segmented controls:
//   - tenant default (admin only) → POST /api/backstage/tenant-settings
//   - per-user override            → POST /api/me/time-format
// On success, the meta tag in <head> is also synced so the TimePicker on
// other pages opened in the same session reflects the new effective value.
(function () {
    var T = window.DAEMS_SETTINGS_I18N;
    function fill(template, params) {
        if (!template) return '';
        if (!params) return template;
        return Object.keys(params).reduce(function (s, k) {
            return s.split('{' + k + '}').join(String(params[k]));
        }, template);
    }
    function paint(seg) {
        var cur = seg.getAttribute('data-current') || '';
        Array.prototype.forEach.call(seg.querySelectorAll('[data-value]'), function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-value') === cur);
        });
    }
    function syncMeta(name, value) {
        var m = document.querySelector('meta[name="' + name + '"]');
        if (m) m.setAttribute('content', value);
    }

    // Tenant default
    var tSeg = document.getElementById('settings-tenant-time-format');
    if (tSeg && tSeg.getAttribute('data-can-edit') === '1') {
        tSeg.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-value]');
            if (!btn || btn.disabled) return;
            var fmt = btn.getAttribute('data-value');
            fetch('/api/backstage/tenant-settings', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ default_time_format: fmt }),
            })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || T.genericError);
                tSeg.setAttribute('data-current', fmt);
                paint(tSeg);
                syncMeta('daems-time-format-tenant-default', fmt);
                // If the user has no override, tenant default IS the effective value.
                var userOvr = document.querySelector('meta[name="daems-time-format-override"]');
                if (!userOvr || !userOvr.getAttribute('content')) {
                    syncMeta('daems-time-format', fmt);
                }
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(fill(T.toastTenant, { h: fmt }), 'success');
            })
            .catch(function (err) {
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(fill(T.errorPrefix, { msg: err.message }), 'error');
            });
        });
    }

    // Per-user override
    var uSeg = document.getElementById('settings-user-time-format');
    if (uSeg) {
        uSeg.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-value]');
            if (!btn) return;
            var fmt = btn.getAttribute('data-value');  // '' | '12' | '24'
            var payload = { time_format: fmt === '' ? null : fmt };
            fetch('/api/me/time-format', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            })
            .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || T.genericError);
                var data = (res.body && res.body.data) || {};
                uSeg.setAttribute('data-current', fmt);
                paint(uSeg);
                syncMeta('daems-time-format-override', payload.time_format || '');
                if (data.effective === '12' || data.effective === '24') {
                    syncMeta('daems-time-format', data.effective);
                }
                if (window.DAEMS_TOASTS) {
                    window.DAEMS_TOASTS.show(
                        fmt === '' ? T.toastInherit : fill(T.toastUserSet, { h: fmt }),
                        'success'
                    );
                }
            })
            .catch(function (err) {
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(fill(T.errorPrefix, { msg: err.message }), 'error');
            });
        });
    }
})();
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
