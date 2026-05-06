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

$pageTitle   = 'Settings';
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

$esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

$tokenExpiresDisplay = '—';
if ($tokenExpires) {
    $ts = strtotime($tokenExpires);
    if ($ts !== false) {
        $tokenExpiresDisplay = date('j.n.Y H:i', $ts);
    }
}

$roleLabels = [
    'admin'       => 'Ylläpitäjä',
    'moderator'   => 'Moderaattori',
    'member'      => 'Jäsen',
    'supporter'   => 'Tukijäsen',
    'registered'  => 'Rekisteröitynyt',
];
$roleDisplay = $roleInTenant !== null
    ? ($roleLabels[$roleInTenant] ?? ucfirst($roleInTenant))
    : 'Ei roolia tenantissa';

$phpVersion = PHP_VERSION;
$appEnv = $_ENV['APP_ENV'] ?? 'development';
$appVersion = 'v1.0.0';

ob_start();
?>
<div class="settings-admin">

<div class="page-header">
    <div>
        <h1 class="page-header__title">Settings</h1>
        <p class="page-header__subtitle">Tilin, tenantin ja alustan tiedot yhdellä silmäyksellä.</p>
    </div>
</div>

<?php if ($meError !== null): ?>
<div class="card" style="border-left:4px solid var(--status-error); margin-bottom:1rem;">
    <div class="card__body">
        <strong style="color:var(--status-error);">Tietoja ei voitu ladata:</strong>
        <code style="font-size:.8rem; display:block; margin-top:.5rem;"><?= $esc($meError) ?></code>
    </div>
</div>
<?php endif; ?>

<div class="settings-grid">

    <!-- You -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-person-circle"></i> Sinä</h2>
            <dl class="settings-dl">
                <dt>Nimi</dt>
                <dd><?= $esc((string) ($user['name'] ?? '—')) ?></dd>

                <dt>Sähköposti</dt>
                <dd><?= $esc((string) ($user['email'] ?? '—')) ?></dd>

                <dt>Rooli</dt>
                <dd>
                    <?php if (!empty($user['is_platform_admin'])): ?>
                        <span class="settings-pill settings-pill--gsa">Global System Administrator</span>
                    <?php else: ?>
                        <span class="settings-pill settings-pill--muted">Tenant-rooli</span>
                    <?php endif; ?>
                </dd>

                <dt>Käyttäjä-ID</dt>
                <dd><code><?= $esc((string) ($user['id'] ?? '—')) ?></code></dd>
            </dl>
            <div class="settings-actions">
                <a href="/profile" class="btn btn--ghost btn--sm">Muokkaa profiilia &rsaquo;</a>
            </div>
        </div>
    </div>

    <!-- Tenant -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-building"></i> Tenant</h2>
            <dl class="settings-dl">
                <dt>Nimi</dt>
                <dd><?= $esc((string) ($tenant['name'] ?? '—')) ?></dd>

                <dt>Slug</dt>
                <dd><code><?= $esc((string) ($tenant['slug'] ?? '—')) ?></code></dd>

                <dt>Roolisi tenantissa</dt>
                <dd><?= $esc($roleDisplay) ?></dd>
            </dl>
        </div>
    </div>

    <!-- Session -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-shield-lock"></i> Sessio</h2>
            <dl class="settings-dl">
                <dt>Token vanhenee</dt>
                <dd><?= $esc($tokenExpiresDisplay) ?></dd>
                <dt>Istunnon tila</dt>
                <dd><span class="settings-pill settings-pill--ok">Aktiivinen</span></dd>
            </dl>
            <div class="settings-actions">
                <a href="/logout" class="btn btn--ghost btn--sm">Kirjaudu ulos &rsaquo;</a>
            </div>
        </div>
    </div>

    <!-- Platform -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-gear"></i> Alusta</h2>
            <dl class="settings-dl">
                <dt>Sovellus</dt>
                <dd>Daem Society Platform <?= $esc($appVersion) ?></dd>

                <dt>PHP</dt>
                <dd><?= $esc($phpVersion) ?></dd>

                <dt>Ympäristö</dt>
                <dd><code><?= $esc($appEnv) ?></code></dd>
            </dl>
        </div>
    </div>

    <!-- Membership card — member-number prefix -->
    <div class="card">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-credit-card"></i> Jäsenkortti</h2>
            <p class="settings-help">Jäsennumeron etuliite jäsenkortilla ja julkisella verifiointisivulla. Jätä tyhjäksi näyttääksesi raa&#8217;an numeron (123).</p>
            <form id="settings-form-membership-card" class="settings-form">
                <label class="settings-field">
                    <span class="settings-field-label">Jäsennumeron etuliite</span>
                    <input type="text" id="settings-member-number-prefix" maxlength="20"
                        pattern="[A-Z0-9\-]+"
                        placeholder="esim. DAEMS"
                        value="<?= $esc((string) ($tenant['member_number_prefix'] ?? '')) ?>" />
                </label>
                <div class="settings-actions">
                    <button type="submit" class="btn btn--primary btn--sm">Tallenna</button>
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
            <h2 class="card__title"><i class="bi bi-display"></i> Näyttöasetukset</h2>
            <p class="settings-help">Tallennetaan tietokantaan. Tenantin oletus voi olla yhteinen kaikille; oma valinta yliajaa sen sinun osaltasi.</p>

            <div class="settings-pref-row">
                <div>
                    <span class="settings-pref-row__label">Tenantin oletus &mdash; kellojärjestelmä</span>
                    <p class="settings-help" style="margin:.15rem 0 0;">
                        Käytetäänkö 12-tuntista (AM/PM) vai 24-tuntista oletusarvoa julkaisuajan valitsijassa.
                        <?php if (!$__isTenantAdmin): ?><br><em>(Vain ylläpitäjä voi muuttaa.)</em><?php endif; ?>
                    </p>
                </div>
                <div class="settings-seg<?= $__isTenantAdmin ? '' : ' is-disabled' ?>" id="settings-tenant-time-format" data-current="<?= $__tfTenant ?>" data-can-edit="<?= $__isTenantAdmin ? '1' : '0' ?>" role="tablist" aria-label="Tenant default time format">
                    <button type="button" class="settings-seg-btn<?= $__tfTenant === '12' ? ' is-active' : '' ?>" data-value="12" <?= $__isTenantAdmin ? '' : 'disabled' ?>>12h</button>
                    <button type="button" class="settings-seg-btn<?= $__tfTenant === '24' ? ' is-active' : '' ?>" data-value="24" <?= $__isTenantAdmin ? '' : 'disabled' ?>>24h</button>
                </div>
            </div>

            <div class="settings-pref-row">
                <div>
                    <span class="settings-pref-row__label">Oma valinta</span>
                    <p class="settings-help" style="margin:.15rem 0 0;">Yliajaa tenantin oletuksen vain sinulla. Valitse "Käytä tenantin oletusta" palauttaaksesi.</p>
                </div>
                <div class="settings-seg" id="settings-user-time-format" data-current="<?= $__tfUser ?? '' ?>" role="tablist" aria-label="My time format">
                    <button type="button" class="settings-seg-btn<?= $__tfUser === null ? ' is-active' : '' ?>" data-value="">Tenant</button>
                    <button type="button" class="settings-seg-btn<?= $__tfUser === '12' ? ' is-active' : '' ?>" data-value="12">12h</button>
                    <button type="button" class="settings-seg-btn<?= $__tfUser === '24' ? ' is-active' : '' ?>" data-value="24">24h</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Future: branding -->
    <div class="card settings-soon">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-palette"></i> Brändäys <span class="settings-pill settings-pill--muted">Tulossa</span></h2>
            <p class="settings-coming">Tenantin logo, pääväri ja sähköpostit määritellään täällä tulevassa päivityksessä.</p>
        </div>
    </div>

    <!-- Future: notifications -->
    <div class="card settings-soon">
        <div class="card__body">
            <h2 class="card__title"><i class="bi bi-bell"></i> Ilmoitukset <span class="settings-pill settings-pill--muted">Tulossa</span></h2>
            <p class="settings-coming">Sähköposti-ilmoitusten asetukset (uudet jäsenhakemukset, foorumi-raportit) per admin.</p>
        </div>
    </div>

</div><!-- /.settings-grid -->

</div><!-- /.settings-admin -->

<link rel="stylesheet" href="/pages/backstage/settings/settings.css">

<script>
(function () {
    var form = document.getElementById('settings-form-membership-card');
    if (!form) return;
    var input  = document.getElementById('settings-member-number-prefix');
    var status = document.getElementById('settings-membership-card-status');

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var raw = input.value.trim();
        var payload = { member_number_prefix: raw === '' ? null : raw };
        status.textContent = 'Tallennetaan…';

        fetch('/api/backstage/tenant-settings', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        })
        .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, body: d }; }); })
        .then(function (res) {
            if (res.ok) {
                status.textContent = 'Tallennettu.';
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show('Jäsenkortin etuliite tallennettu.', 'success');
            } else {
                var msg = (res.body && res.body.error) ? res.body.error : 'virhe';
                status.textContent = 'Virhe: ' + msg;
            }
        })
        .catch(function (err) { status.textContent = 'Verkkovirhe: ' + err.message; });
    });
})();

// Time format — DB-backed. Two segmented controls:
//   - tenant default (admin only) → POST /api/backstage/tenant-settings
//   - per-user override            → POST /api/me/time-format
// On success, the meta tag in <head> is also synced so the TimePicker on
// other pages opened in the same session reflects the new effective value.
(function () {
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
                if (!res.ok) throw new Error((res.body && res.body.error) || 'virhe');
                tSeg.setAttribute('data-current', fmt);
                paint(tSeg);
                syncMeta('daems-time-format-tenant-default', fmt);
                // If the user has no override, tenant default IS the effective value.
                var userOvr = document.querySelector('meta[name="daems-time-format-override"]');
                if (!userOvr || !userOvr.getAttribute('content')) {
                    syncMeta('daems-time-format', fmt);
                }
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show('Tenantin oletus: ' + fmt + 'h', 'success');
            })
            .catch(function (err) {
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show('Virhe: ' + err.message, 'error');
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
                if (!res.ok) throw new Error((res.body && res.body.error) || 'virhe');
                var data = (res.body && res.body.data) || {};
                uSeg.setAttribute('data-current', fmt);
                paint(uSeg);
                syncMeta('daems-time-format-override', payload.time_format || '');
                if (data.effective === '12' || data.effective === '24') {
                    syncMeta('daems-time-format', data.effective);
                }
                if (window.DAEMS_TOASTS) {
                    window.DAEMS_TOASTS.show(
                        fmt === '' ? 'Käytetään tenantin oletusta' : 'Oma valinta: ' + fmt + 'h',
                        'success'
                    );
                }
            })
            .catch(function (err) {
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show('Virhe: ' + err.message, 'error');
            });
        });
    }
})();
</script>

<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../layout.php';
