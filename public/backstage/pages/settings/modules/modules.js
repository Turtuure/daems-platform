/**
 * Wave H8 — Settings → Modules.
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

    function toast(msg, kind) {
        if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(String(msg || ''), kind || 'info');
    }

    function renderCards(container, entries, action) {
        if (!container) return;
        if (!entries || entries.length === 0) {
            container.innerHTML = '<div class="settings-modules-empty">Nothing here.</div>';
            return;
        }
        container.innerHTML = entries.map(function (e) {
            var name = e.nameKey || e.slug;
            var desc = e.descriptionKey || '';
            var since = e.sinceAt ? '<div class="settings-module-card__since">Since ' + escapeHtml(e.sinceAt) + '</div>' : '';
            // Core modules surface as enabled but cannot be disabled — annotate.
            var btn = '';
            if (action === 'disable') {
                btn = e.isCore
                    ? '<span class="settings-module-card__core">Core</span>'
                    : '<button type="button" class="btn btn--ghost btn--sm" data-action="disable" data-slug="' + escapeHtml(e.slug) + '">Disable</button>';
            } else if (action === 'enable') {
                btn = '<button type="button" class="btn btn--primary btn--sm" data-action="enable" data-slug="' + escapeHtml(e.slug) + '">Activate</button>';
            }
            return '' +
                '<article class="settings-module-card">' +
                    '<header class="settings-module-card__head">' +
                        '<h3 class="settings-module-card__name">' + escapeHtml(name) + '</h3>' +
                        '<span class="settings-module-card__slug">' + escapeHtml(e.slug) + '</span>' +
                    '</header>' +
                    (desc ? '<p class="settings-module-card__desc">' + escapeHtml(desc) + '</p>' : '<p class="settings-module-card__desc">—</p>') +
                    since +
                    '<div class="settings-module-card__actions">' + btn + '</div>' +
                '</article>';
        }).join('');
    }

    function load() {
        var status = document.getElementById('modules-status');
        if (status) status.textContent = 'Loading…';

        fetch(PROXY + '?op=list', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || 'Failed to load modules');
                var data = (res.body && res.body.data) || {};
                renderCards(document.getElementById('modules-enabled'),   data.enabled || [],             'disable');
                renderCards(document.getElementById('modules-available'), data.availableNotEnabled || [], 'enable');

                var dis = data.disabled || [];
                var disHost = document.getElementById('modules-disabled');
                var disSection = document.getElementById('modules-disabled-section');
                if (dis.length > 0) {
                    if (disSection) disSection.hidden = false;
                    renderCards(disHost, dis, 'enable');
                } else {
                    if (disSection) disSection.hidden = true;
                }

                if (status) status.textContent = '';
            })
            .catch(function (err) {
                if (status) status.textContent = 'Error: ' + err.message;
                toast('Modules load failed: ' + err.message, 'error');
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
                    // Server-side dependency rejection messages contain "depends_on"
                    // or "dependent" — surface them verbatim.
                    toast(action.charAt(0).toUpperCase() + action.slice(1) + ' failed: ' + msg, 'error');
                    return;
                }
                toast('Module ' + slug + ' ' + (action === 'enable' ? 'activated' : 'disabled') + '.', 'success');
                load();
            })
            .catch(function (e) { toast('Network error: ' + e.message, 'error'); });
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
            if (action === 'disable' && !confirm('Disable module ' + slug + '?')) return;
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
