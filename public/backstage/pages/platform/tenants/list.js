/**
 * Wave H1 — Platform Tenants list JS.
 *
 * Fetches the tenant list, renders the table, and wires up the
 * "New tenant" modal. Everything else (sortable headers, density,
 * column toggles, etc.) is intentionally out of scope for the skeleton.
 *
 * Errors are surfaced in two places:
 *   - inline in the modal (#tenants-new-error) for create-flow problems
 *   - via window.DAEMS_TOASTS for list-load problems
 */
(function () {
    'use strict';

    var PROXY = '/api/backstage/platform-tenants';

    var state = { rows: [], filter: '', status: '' };

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function statusPill(status) {
        var s = String(status || '').toLowerCase();
        return '<span class="tenants-pill tenants-pill--' + escapeHtml(s) + '">' + escapeHtml(status || '—') + '</span>';
    }

    function modulesCell(m) {
        if (!m) return '—';
        var enabled   = m.enabled   != null ? m.enabled   : (m.enabled_count   != null ? m.enabled_count   : 0);
        var available = m.available != null ? m.available : (m.available_count != null ? m.available_count : 0);
        return escapeHtml(enabled + ' / ' + available);
    }

    function render() {
        var tbody = document.getElementById('tenants-tbody');
        var status = document.getElementById('tenants-status');
        var table = document.getElementById('tenants-table');
        if (!tbody || !table || !status) return;

        var rows = state.rows.filter(function (t) {
            if (state.status && String(t.status || '').toLowerCase() !== state.status) return false;
            if (state.filter) {
                var q = state.filter.toLowerCase();
                var hay = (t.slug + ' ' + (t.display_name || t.name || '')).toLowerCase();
                if (hay.indexOf(q) === -1) return false;
            }
            return true;
        });

        if (rows.length === 0) {
            table.hidden = true;
            status.textContent = state.rows.length === 0 ? 'No tenants found.' : 'No matches.';
            return;
        }
        status.textContent = '';
        table.hidden = false;

        tbody.innerHTML = rows.map(function (t) {
            var id = encodeURIComponent(t.id || '');
            var name = t.display_name || t.name || t.slug;
            var domains = t.domains_count != null ? t.domains_count : (Array.isArray(t.domains) ? t.domains.length : '—');
            var members = t.members_count != null ? t.members_count : '—';
            var admins  = t.admins_count  != null ? t.admins_count  : '—';
            return '' +
                '<tr data-id="' + id + '">' +
                    '<td><code>' + escapeHtml(t.slug) + '</code></td>' +
                    '<td>' + escapeHtml(name) + '</td>' +
                    '<td>' + statusPill(t.status) + '</td>' +
                    '<td>' + escapeHtml(String(domains)) + '</td>' +
                    '<td>' + escapeHtml(String(members)) + '</td>' +
                    '<td>' + escapeHtml(String(admins)) + '</td>' +
                    '<td>' + modulesCell(t.modules) + '</td>' +
                '</tr>';
        }).join('');
    }

    // -----------------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------------
    function load() {
        var status = document.getElementById('tenants-status');
        if (status) status.textContent = 'Loading…';

        fetch(PROXY + '?op=list', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || 'Failed to load tenants');
                var data = (res.body && res.body.data) || [];
                // Some controllers wrap the list under a key (e.g. {tenants: []}); accept both.
                if (data && !Array.isArray(data) && Array.isArray(data.tenants)) data = data.tenants;
                state.rows = Array.isArray(data) ? data : [];
                render();
            })
            .catch(function (err) {
                if (status) status.textContent = 'Error: ' + err.message;
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show('Tenants list failed: ' + err.message, 'error');
            });
    }

    // -----------------------------------------------------------------------
    // Modal — open/close + submit
    // -----------------------------------------------------------------------
    function openModal() {
        var m = document.getElementById('tenants-new-modal');
        if (m) m.hidden = false;
    }
    function closeModal() {
        var m = document.getElementById('tenants-new-modal');
        var f = document.getElementById('tenants-new-form');
        var e = document.getElementById('tenants-new-error');
        if (m) m.hidden = true;
        if (f) f.reset();
        if (e) e.textContent = '';
    }

    function submitNewTenant(form) {
        var fd = new FormData(form);
        var locales = fd.getAll('locales[]').map(String);
        var payload = {
            slug: String(fd.get('slug') || '').trim(),
            display_name: { en_GB: String(fd.get('display_name_en') || '').trim() },
            supported_locales: locales,
            default_locale: String(fd.get('default_locale') || ''),
            member_number_prefix: String(fd.get('member_number_prefix') || '').trim() || null
        };

        var err = document.getElementById('tenants-new-error');
        var btn = document.getElementById('tenants-new-submit');
        if (err) err.textContent = '';
        if (btn) btn.disabled = true;

        fetch(PROXY + '?op=create', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify(payload)
        })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (!res.ok) {
                    var msg = (res.body && res.body.error) ? res.body.error : 'Create failed.';
                    if (err) err.textContent = msg;
                    return;
                }
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show('Tenant created.', 'success');
                closeModal();
                load();
            })
            .catch(function (e) { if (err) err.textContent = 'Network error: ' + e.message; })
            .finally(function () { if (btn) btn.disabled = false; });
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    function init() {
        var search = document.getElementById('tenants-search');
        var filter = document.getElementById('tenants-status-filter');
        if (search) search.addEventListener('input', function (e) {
            state.filter = String(e.target.value || '').trim();
            render();
        });
        if (filter) filter.addEventListener('change', function (e) {
            state.status = String(e.target.value || '').trim();
            render();
        });

        var newBtn = document.getElementById('tenants-new-btn');
        if (newBtn) newBtn.addEventListener('click', openModal);

        var modal = document.getElementById('tenants-new-modal');
        if (modal) modal.addEventListener('click', function (e) {
            if (e.target && e.target.matches('[data-close]')) closeModal();
        });

        var form = document.getElementById('tenants-new-form');
        if (form) form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitNewTenant(form);
        });

        // Row click → tenant edit shell
        var tbody = document.getElementById('tenants-tbody');
        if (tbody) tbody.addEventListener('click', function (e) {
            var row = e.target && e.target.closest && e.target.closest('tr[data-id]');
            if (!row) return;
            var id = row.getAttribute('data-id');
            if (id) window.location.href = '/backstage/platform/tenants/' + id + '?tab=basics';
        });

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
