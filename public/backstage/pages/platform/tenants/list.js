/**
 * Wave H — Platform Tenants list JS.
 *
 * Fetches the tenant list, renders the table, and wires up the
 * "New tenant" modal. Uses the shared design-system classes (.btn,
 * .tenants-pill, .tenants-skeleton__cell) and the global toast stack.
 *
 * Errors are surfaced in two places:
 *   - inline in the modal (#tenants-new-error) for create-flow problems
 *   - via window.DAEMS_TOASTS for list-load problems
 */
(function () {
    'use strict';

    var PROXY = '/api/backstage/platform-tenants';

    var state = { rows: [], filter: '', status: '', loading: true };
    var lastFocused = null;

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function statusPill(status) {
        var s = String(status || '').toLowerCase();
        var label = status || '—';
        var cls = (s === 'active' || s === 'suspended') ? ('tenants-pill--' + s) : '';
        return '<span class="tenants-pill ' + cls + '">' + escapeHtml(label) + '</span>';
    }

    function modulesCell(m) {
        if (!m) return '<span class="tenants-table__num">—</span>';
        var enabled   = m.enabled   != null ? m.enabled   : (m.enabled_count   != null ? m.enabled_count   : 0);
        var available = m.available != null ? m.available : (m.available_count != null ? m.available_count : 0);
        return enabled + ' / ' + available;
    }

    function num(v) {
        return v == null || v === '—' ? '—' : String(v);
    }

    // -----------------------------------------------------------------------
    // Skeleton — shows during the very first fetch
    // -----------------------------------------------------------------------
    function renderSkeleton() {
        var tbody  = document.getElementById('tenants-tbody');
        var table  = document.getElementById('tenants-table');
        var status = document.getElementById('tenants-status');
        var empty  = document.getElementById('tenants-empty');
        if (!tbody || !table || !status) return;

        if (empty) empty.hidden = true;
        status.textContent = '';
        table.hidden = false;

        var row = ''
            + '<tr aria-hidden="true">'
            +   '<td><span class="tenants-skeleton__cell tenants-skeleton__cell--narrow"></span></td>'
            +   '<td><span class="tenants-skeleton__cell"></span></td>'
            +   '<td><span class="tenants-skeleton__cell tenants-skeleton__cell--pill"></span></td>'
            +   '<td><span class="tenants-skeleton__cell tenants-skeleton__cell--narrow"></span></td>'
            +   '<td><span class="tenants-skeleton__cell tenants-skeleton__cell--narrow"></span></td>'
            +   '<td><span class="tenants-skeleton__cell tenants-skeleton__cell--narrow"></span></td>'
            +   '<td><span class="tenants-skeleton__cell tenants-skeleton__cell--narrow"></span></td>'
            + '</tr>';
        tbody.innerHTML = row + row + row;
    }

    // -----------------------------------------------------------------------
    // Render
    // -----------------------------------------------------------------------
    function render() {
        var tbody  = document.getElementById('tenants-tbody');
        var status = document.getElementById('tenants-status');
        var table  = document.getElementById('tenants-table');
        var empty  = document.getElementById('tenants-empty');
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

        // First-time empty (no tenants at all): full empty state.
        if (state.rows.length === 0) {
            table.hidden = true;
            status.textContent = '';
            if (empty) empty.hidden = false;
            return;
        }
        if (empty) empty.hidden = true;

        // Filter applied but no matches: keep the table headers, swap body to a "no matches" stub.
        if (rows.length === 0) {
            table.hidden = false;
            status.textContent = '';
            tbody.innerHTML = ''
                + '<tr><td colspan="7">'
                +   '<div class="tenants-empty" style="padding:var(--space-8) var(--space-4);">'
                +     '<p class="tenants-empty__body">' + escapeHtml(_t('platform.tenants.empty.no_matches')) + '</p>'
                +   '</div>'
                + '</td></tr>';
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
                '<tr data-id="' + id + '" tabindex="0">' +
                    '<td><code>' + escapeHtml(t.slug) + '</code></td>' +
                    '<td class="tenants-table__name">' + escapeHtml(name) + '</td>' +
                    '<td>' + statusPill(t.status) + '</td>' +
                    '<td class="tenants-table__num">' + escapeHtml(num(domains)) + '</td>' +
                    '<td class="tenants-table__num">' + escapeHtml(num(members)) + '</td>' +
                    '<td class="tenants-table__num">' + escapeHtml(num(admins)) + '</td>' +
                    '<td class="tenants-table__num">' + modulesCell(t.modules) + '</td>' +
                '</tr>';
        }).join('');
    }

    // -----------------------------------------------------------------------
    // i18n — pulls strings from window.DAEMS_TENANTS_I18N (set inline by PHP)
    // with safe fallbacks so this file works even before keys are added.
    // -----------------------------------------------------------------------
    function _t(key) {
        var T = window.DAEMS_TENANTS_I18N || {};
        if (T[key]) return T[key];
        var FALLBACK = {
            'platform.tenants.empty.no_matches':   'No tenants match the current filters.',
            'platform.tenants.error.load_failed':  'Failed to load tenants',
            'platform.tenants.toast.created':      'Tenant created.',
            'platform.tenants.toast.create_failed':'Create failed.',
            'platform.common.network_error':       'Network error',
            'platform.common.loading':             'Loading…'
        };
        return FALLBACK[key] || key;
    }

    // -----------------------------------------------------------------------
    // Load
    // -----------------------------------------------------------------------
    function load() {
        renderSkeleton();
        state.loading = true;

        fetch(PROXY + '?op=list', { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || _t('platform.tenants.error.load_failed'));
                var data = (res.body && res.body.data) || [];
                if (data && !Array.isArray(data) && Array.isArray(data.tenants)) data = data.tenants;
                state.rows = Array.isArray(data) ? data : [];
                state.loading = false;
                render();
            })
            .catch(function (err) {
                state.loading = false;
                var status = document.getElementById('tenants-status');
                if (status) status.textContent = _t('platform.tenants.error.load_failed') + ': ' + err.message;
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(_t('platform.tenants.error.load_failed') + ': ' + err.message, 'error');
            });
    }

    // -----------------------------------------------------------------------
    // Modal — open/close + submit, with focus trap + esc-to-close
    // -----------------------------------------------------------------------
    function focusableNodes(modal) {
        return Array.prototype.slice.call(
            modal.querySelectorAll('input, select, textarea, button, [tabindex]:not([tabindex="-1"])')
        ).filter(function (n) { return !n.disabled && n.offsetParent !== null; });
    }

    function trapFocus(e) {
        var modal = document.getElementById('tenants-new-modal');
        if (!modal || modal.hidden || e.key !== 'Tab') return;
        var nodes = focusableNodes(modal);
        if (nodes.length === 0) return;
        var first = nodes[0];
        var last  = nodes[nodes.length - 1];
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault(); last.focus();
        } else if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault(); first.focus();
        }
    }

    function openModal() {
        var m = document.getElementById('tenants-new-modal');
        if (!m) return;
        lastFocused = document.activeElement;
        m.hidden = false;
        // Defer focus so the open animation can start.
        setTimeout(function () {
            var first = m.querySelector('input[name="slug"]');
            if (first) first.focus();
        }, 30);
    }

    function closeModal() {
        var m = document.getElementById('tenants-new-modal');
        var f = document.getElementById('tenants-new-form');
        var e = document.getElementById('tenants-new-error');
        if (m) m.hidden = true;
        if (f) f.reset();
        if (e) e.textContent = '';
        if (lastFocused && typeof lastFocused.focus === 'function') {
            try { lastFocused.focus(); } catch (_) { /* noop */ }
        }
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
                    var msg = (res.body && res.body.error) ? res.body.error : _t('platform.tenants.toast.create_failed');
                    if (err) err.textContent = msg;
                    return;
                }
                if (window.DAEMS_TOASTS) window.DAEMS_TOASTS.show(_t('platform.tenants.toast.created'), 'success');
                closeModal();
                load();
            })
            .catch(function (e) { if (err) err.textContent = _t('platform.common.network_error') + ': ' + e.message; })
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

        // Two New-tenant triggers — header button and empty-state CTA.
        ['tenants-new-btn', 'tenants-new-btn-empty'].forEach(function (id) {
            var b = document.getElementById(id);
            if (b) b.addEventListener('click', openModal);
        });

        var modal = document.getElementById('tenants-new-modal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target && e.target.matches && e.target.matches('[data-close]')) closeModal();
            });
        }
        // Esc closes any open modal.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal && !modal.hidden) closeModal();
            trapFocus(e);
        });

        var form = document.getElementById('tenants-new-form');
        if (form) form.addEventListener('submit', function (e) {
            e.preventDefault();
            submitNewTenant(form);
        });

        // Row click / keyboard activation → tenant edit shell
        var tbody = document.getElementById('tenants-tbody');
        function activateRow(row) {
            var id = row.getAttribute('data-id');
            if (id) window.location.href = '/backstage/platform/tenants/' + id + '?tab=basics';
        }
        if (tbody) {
            tbody.addEventListener('click', function (e) {
                var row = e.target && e.target.closest && e.target.closest('tr[data-id]');
                if (row) activateRow(row);
            });
            tbody.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter' && e.key !== ' ') return;
                var row = e.target && e.target.closest && e.target.closest('tr[data-id]');
                if (!row) return;
                e.preventDefault();
                activateRow(row);
            });
        }

        load();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
