/**
 * Wave H — Settings → Modules.
 *
 * Tenant-admin enable/disable for the modules included in their plan.
 * Talks to /api/backstage/tenant-modules (proxy → /api/v1/backstage/tenant/modules).
 *
 * The "Disable" button on enabled cards is best-effort: the server enforces
 * dependency rules and returns a 422 with a descriptive error which we
 * surface in a toast.
 */
(function () {
    'use strict';

    var PROXY = '/api/backstage/tenant-modules';

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function _t(key) {
        var T = window.DAEMS_TENANT_MODULES_I18N || {};
        return T[key] || key;
    }
    function _tf(key, params) {
        var s = _t(key);
        if (!params) return s;
        if (Object.prototype.toString.call(params) === '[object Array]') {
            params.forEach(function (v) { s = s.replace('%s', v); });
        } else {
            Object.keys(params).forEach(function (k) {
                s = s.split('{' + k + '}').join(String(params[k]));
            });
        }
        return s;
    }

    function toast(msg, kind) {
        if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(String(msg || ''), kind || 'info');
    }

    // -----------------------------------------------------------------------
    // Skeleton — three placeholder cards while initial fetch is in flight
    // -----------------------------------------------------------------------
    function skeletonHtml() {
        var line = '<div class="settings-module-skeleton__line settings-module-skeleton__line--';
        var card = ''
            + '<div class="settings-module-skeleton" aria-hidden="true">'
            +   line + 'mid"></div>'
            +   line + 'short"></div>'
            +   line + 'full"></div>'
            +   line + 'full"></div>'
            + '</div>';
        return card + card + card;
    }

    function showSkeletons() {
        ['modules-enabled', 'modules-available'].forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.innerHTML = skeletonHtml();
        });
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------
    function pillFor(state) {
        var cls = 'settings-module-pill settings-module-pill--' + state;
        var labelKey = 'settings.modules.pill.' + state;
        var label = _t(labelKey);
        if (label === labelKey) label = state;
        return '<span class="' + cls + '">' + escapeHtml(label) + '</span>';
    }

    function renderCards(container, entries, action, emptyKey) {
        if (!container) return;
        if (!entries || entries.length === 0) {
            container.innerHTML = '<div class="settings-modules-empty">' +
                escapeHtml(_t(emptyKey || 'settings.modules.empty')) + '</div>';
            return;
        }
        container.innerHTML = entries.map(function (e) {
            var name = e.nameKey || e.slug;
            var desc = e.descriptionKey || '';
            // State drives the pill colour. "core" overrides "enabled" so the user
            // sees that the module can't be deactivated.
            var pillState = e.isCore ? 'core'
                : (action === 'disable' ? 'enabled'
                : (action === 'enable'  ? (e.state === 'disabled' ? 'disabled' : 'available')
                : 'available'));

            var statusPill = pillFor(pillState);

            var since = '';
            if (e.sinceAt) {
                since = '<div class="settings-module-card__since">' +
                          '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
                            '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>' +
                          '</svg>' +
                          escapeHtml(_tf('settings.modules.granted_at', [e.sinceAt])) +
                        '</div>';
            }

            var btn = '';
            if (action === 'disable') {
                btn = e.isCore
                    ? ''   // core modules: no action button — pill alone communicates the state
                    : '<button type="button" class="btn btn--ghost btn--sm" data-action="disable" data-slug="' + escapeHtml(e.slug) + '">' +
                      escapeHtml(_t('settings.modules.deactivate')) + '</button>';
            } else if (action === 'enable') {
                btn = '<button type="button" class="btn btn--primary btn--sm" data-action="enable" data-slug="' + escapeHtml(e.slug) + '">' +
                      escapeHtml(_t('settings.modules.activate')) + '</button>';
            }

            return '' +
                '<article class="settings-module-card">' +
                    '<header class="settings-module-card__head">' +
                        '<div class="settings-module-card__heading">' +
                            '<h3 class="settings-module-card__name">' + escapeHtml(name) + '</h3>' +
                            '<span class="settings-module-card__slug">' + escapeHtml(e.slug) + '</span>' +
                        '</div>' +
                        '<span class="settings-module-card__status">' + statusPill + '</span>' +
                    '</header>' +
                    (desc
                        ? '<p class="settings-module-card__desc">' + escapeHtml(desc) + '</p>'
                        : '<p class="settings-module-card__desc">—</p>') +
                    since +
                    (btn ? '<div class="settings-module-card__actions">' + btn + '</div>' : '') +
                '</article>';
        }).join('');
    }

    function setSectionCount(id, n) {
        var el = document.getElementById(id);
        if (!el) return;
        if (n > 0) {
            el.hidden = false;
            el.textContent = String(n);
        } else {
            el.hidden = true;
        }
    }

    // -----------------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------------
    function load() {
        var status = document.getElementById('modules-status');
        if (status) status.textContent = '';
        showSkeletons();

        fetch(PROXY + '?op=list', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || _t('settings.modules.error.load_failed'));
                var data = (res.body && res.body.data) || {};

                var enabled   = data.enabled || [];
                var available = data.availableNotEnabled || [];
                var disabled  = data.disabled || [];

                renderCards(document.getElementById('modules-enabled'),   enabled,   'disable', 'settings.modules.empty.enabled');
                renderCards(document.getElementById('modules-available'), available, 'enable',  'settings.modules.empty.available');

                setSectionCount('mod-enabled-count',   enabled.length);
                setSectionCount('mod-available-count', available.length);
                setSectionCount('mod-disabled-count',  disabled.length);

                var disHost = document.getElementById('modules-disabled');
                var disSection = document.getElementById('modules-disabled-section');
                if (disabled.length > 0) {
                    if (disSection) disSection.hidden = false;
                    renderCards(disHost, disabled, 'enable', 'settings.modules.empty');
                } else {
                    if (disSection) disSection.hidden = true;
                }

                if (status) status.textContent = '';
            })
            .catch(function (err) {
                if (status) status.textContent = _t('settings.modules.error.load_failed') + ': ' + err.message;
                ['modules-enabled', 'modules-available'].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.innerHTML = '';
                });
                toast(_t('settings.modules.error.load_failed') + ': ' + err.message, 'error');
            });
    }

    function toggle(slug, action) {
        fetch(PROXY + '?op=state&slug=' + encodeURIComponent(slug), {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ action: action })
        })
            .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
            .then(function (res) {
                if (!res.ok) {
                    var msg = (res.body && res.body.error) || ('HTTP ' + res.status);
                    var failKey = action === 'enable'
                        ? 'settings.modules.toast.activate_failed'
                        : 'settings.modules.toast.deactivate_failed';
                    toast(_t(failKey) + ': ' + msg, 'error');
                    return;
                }
                var okKey = action === 'enable'
                    ? 'settings.modules.toast.activated'
                    : 'settings.modules.toast.deactivated';
                toast(_tf(okKey, [slug]), 'success');
                load();
            })
            .catch(function (e) { toast(_t('platform.common.network_error') + ': ' + e.message, 'error'); });
    }

    function init() {
        document.body.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('button[data-action][data-slug]');
            if (!btn) return;
            // Only handle clicks inside our page sections.
            if (!btn.closest('.settings-modules-grid')) return;
            var action = btn.getAttribute('data-action');
            var slug   = btn.getAttribute('data-slug');
            if (!action || !slug) return;
            if (action === 'disable' && !confirm(_tf('settings.modules.confirm_deactivate', [slug]))) return;
            toggle(slug, action);
        });

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
