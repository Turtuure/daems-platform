/**
 * Wave H2 — Tenant edit shell JS.
 *
 * Loads the tenant header (name + status) once on page load, then
 * dispatches the active tab to the matching loader. Each loader is
 * defined under window.DaemsTenantTabs[tabName] and is added by the
 * subsequent H3-H7 tasks.
 *
 * The shell itself is intentionally tiny — page navigation is plain
 * <a href="?tab=…"> so the back button works and per-tab state is
 * shared via the URL.
 */
(function () {
    'use strict';

    var PROXY = '/api/backstage/platform-tenants';

    window.DaemsTenantTabs = window.DaemsTenantTabs || {};

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Shared toast helpers used across all tabs.
    function toast(msg, kind) {
        if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(String(msg || ''), kind || 'info');
    }
    function setStatus(msg) {
        var el = document.getElementById('tenant-edit-status-line');
        if (el) el.textContent = msg || '';
    }

    // GET /platform/tenants/{id} — populates the page header AND caches
    // the response so the Basics tab (H3) doesn't need a second round-trip.
    function loadHeader() {
        var id = (window.DAEMS_TENANT_EDIT && window.DAEMS_TENANT_EDIT.tenantId) || '';
        if (!id) {
            setStatus('Missing tenant id.');
            return Promise.reject(new Error('missing_id'));
        }

        setStatus('Loading…');
        return fetch(PROXY + '?op=get&id=' + encodeURIComponent(id), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || 'Failed to load');
                var t = (res.body && res.body.data) || {};
                window.DAEMS_TENANT_EDIT.tenant = t;
                renderHeader(t);
                setStatus('');
                return t;
            })
            .catch(function (err) {
                setStatus('Error: ' + err.message);
                toast('Tenant load failed: ' + err.message, 'error');
                throw err;
            });
    }

    function renderHeader(t) {
        var nameEl = document.getElementById('tenant-edit-name');
        var slugEl = document.getElementById('tenant-edit-slug');
        var stEl   = document.getElementById('tenant-edit-status');

        var name = t.display_name || t.name || (t.slug || 'Tenant');
        if (typeof name === 'object') {
            // PR 5 i18n shape: {fi_FI: …, en_GB: …}; fall back to slug.
            name = name.en_GB || name.fi_FI || (t.slug || 'Tenant');
        }
        if (nameEl) nameEl.textContent = name;
        if (slugEl) slugEl.textContent = t.slug || '—';

        if (stEl) {
            var s = String(t.status || '').toLowerCase();
            stEl.textContent = t.status || '—';
            stEl.className = 'tenants-pill tenants-pill--' + s;
        }
    }

    // Tab dispatcher — clones the matching <template> into the host node,
    // then calls DaemsTenantTabs[name].load(host, tenant) if defined.
    function activateTab(name, tenant) {
        var host = document.getElementById('tenant-edit-content');
        var tpl  = document.getElementById('tab-tpl-' + name);
        if (!host || !tpl) return;
        host.innerHTML = '';
        host.appendChild(tpl.content.cloneNode(true));

        var tab = window.DaemsTenantTabs[name];
        if (tab && typeof tab.load === 'function') {
            try { tab.load(host, tenant); }
            catch (e) { setStatus('Tab error: ' + e.message); toast('Tab error: ' + e.message, 'error'); }
        }
    }

    // Boot
    function init() {
        var meta = window.DAEMS_TENANT_EDIT;
        if (!meta || !meta.tenantId) return;

        // Expose helpers for the per-tab modules added in H3-H7.
        window.DaemsTenantEdit = {
            proxy: PROXY,
            tenantId: meta.tenantId,
            escapeHtml: escapeHtml,
            toast: toast,
            setStatus: setStatus,
            // Re-fetch the tenant + re-render header (used after Basics save, suspend, etc.).
            reloadHeader: loadHeader,
            // Rerender the active tab (used after an action that mutates list state).
            reloadActiveTab: function () { activateTab(meta.activeTab, window.DAEMS_TENANT_EDIT.tenant || {}); }
        };

        loadHeader().then(function (tenant) {
            activateTab(meta.activeTab, tenant);
        }, function () { /* error already surfaced */ });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
