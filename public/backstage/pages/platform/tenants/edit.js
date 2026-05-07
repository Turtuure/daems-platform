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

    // -----------------------------------------------------------------------
    // H3 — Basics tab
    // -----------------------------------------------------------------------
    var ALL_LOCALES = ['fi_FI', 'en_GB', 'sw_TZ'];

    function renderLocaleCards(host, fieldName, valuesByLocale, supportedLocales, multiline) {
        host.innerHTML = '';
        supportedLocales.forEach(function (loc) {
            var card = document.createElement('div');
            card.className = 'tenant-locale-card';
            var titleEl = document.createElement('p');
            titleEl.className = 'tenant-locale-card__title';
            titleEl.textContent = loc;
            card.appendChild(titleEl);

            var input = document.createElement(multiline ? 'textarea' : 'input');
            if (!multiline) input.type = 'text';
            input.name = fieldName + '[' + loc + ']';
            input.dataset.locale = loc;
            input.dataset.field  = fieldName;
            input.value = (valuesByLocale && valuesByLocale[loc]) || '';
            card.appendChild(input);
            host.appendChild(card);
        });
    }

    function collectLocaleMap(host) {
        var map = {};
        host.querySelectorAll('[data-locale]').forEach(function (el) {
            map[el.dataset.locale] = el.value || '';
        });
        return map;
    }

    window.DaemsTenantTabs.basics = {
        load: function (host, tenant) {
            var t = tenant || {};
            var supported = Array.isArray(t.supportedLocales) && t.supportedLocales.length
                ? t.supportedLocales
                : ALL_LOCALES.slice();

            var slugIn = host.querySelector('#tb-slug');
            if (slugIn) slugIn.value = t.slug || '';

            renderLocaleCards(host.querySelector('#tb-display-name-cards'),       'displayNameI18n',       t.displayNameI18n || {},       supported, false);
            renderLocaleCards(host.querySelector('#tb-public-description-cards'), 'publicDescriptionI18n', t.publicDescriptionI18n || {}, supported, true);

            // Tick the supported-locale checkboxes
            host.querySelectorAll('input[name="supportedLocales"]').forEach(function (cb) {
                cb.checked = supported.indexOf(cb.value) !== -1;
            });

            // Default locale
            var defLoc = host.querySelector('#tb-default-locale');
            if (defLoc) defLoc.value = t.defaultLocale || 'fi_FI';

            // Member number prefix
            var prefIn = host.querySelector('#tb-prefix');
            if (prefIn) prefIn.value = t.memberNumberPrefix || '';

            // Submit
            var form = host.querySelector('#tenant-basics-form');
            if (form) form.addEventListener('submit', function (e) {
                e.preventDefault();
                save(host);
            });
        }
    };

    function save(host) {
        var statusEl = host.querySelector('#tb-status');
        var btn = host.querySelector('#tb-save');
        if (statusEl) statusEl.textContent = 'Saving…';
        if (btn) btn.disabled = true;

        var supported = [];
        host.querySelectorAll('input[name="supportedLocales"]:checked').forEach(function (cb) { supported.push(cb.value); });

        var payload = {
            slug:                    (host.querySelector('#tb-slug') || {}).value || '',
            displayNamesI18n:        collectLocaleMap(host.querySelector('#tb-display-name-cards')),
            publicDescriptionsI18n:  collectLocaleMap(host.querySelector('#tb-public-description-cards')),
            supportedLocales:        supported,
            defaultLocale:           (host.querySelector('#tb-default-locale') || {}).value || 'fi_FI',
            memberNumberPrefix:      (host.querySelector('#tb-prefix') || {}).value || ''
        };

        var id = window.DAEMS_TENANT_EDIT.tenantId;
        fetch(PROXY + '?op=patch&id=' + encodeURIComponent(id), {
            method:  'POST', // proxy maps to PATCH upstream
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify(payload)
        })
            .then(function (r) { return r.text().then(function (txt) {
                var b = {};
                try { b = txt ? JSON.parse(txt) : {}; } catch (_) {}
                return { ok: r.ok, body: b, status: r.status };
            }); })
            .then(function (res) {
                if (!res.ok) {
                    var msg = (res.body && res.body.error) || ('HTTP ' + res.status);
                    if (statusEl) statusEl.textContent = 'Error: ' + msg;
                    toast('Save failed: ' + msg, 'error');
                    return;
                }
                if (statusEl) statusEl.textContent = 'Saved.';
                toast('Tenant basics saved.', 'success');
                // Refresh header + cached tenant data so the next tab visit sees fresh state.
                if (window.DaemsTenantEdit && window.DaemsTenantEdit.reloadHeader) {
                    window.DaemsTenantEdit.reloadHeader();
                }
            })
            .catch(function (e) {
                if (statusEl) statusEl.textContent = 'Network error: ' + e.message;
                toast('Save failed: ' + e.message, 'error');
            })
            .finally(function () { if (btn) btn.disabled = false; });
    }

    // -----------------------------------------------------------------------
    // H4 — Domains tab
    // -----------------------------------------------------------------------
    window.DaemsTenantTabs.domains = {
        load: function (host /* , tenant */) {
            var id = window.DAEMS_TENANT_EDIT.tenantId;
            var tbody    = host.querySelector('#td-tbody');
            var table    = host.querySelector('#td-table');
            var statusEl = host.querySelector('#td-status');
            var addBtn   = host.querySelector('#td-add-btn');
            var modal    = host.querySelector('#td-add-modal');
            var form     = host.querySelector('#td-add-form');
            var errEl    = host.querySelector('#td-add-error');

            function loadList() {
                statusEl.textContent = 'Loading…';
                fetch(PROXY + '?op=domains.list&id=' + encodeURIComponent(id))
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (!res.ok) throw new Error((res.body && res.body.error) || 'Load failed');
                        var rows = (res.body && res.body.data && res.body.data.domains) || [];
                        if (rows.length === 0) {
                            statusEl.textContent = 'No domains yet.';
                            table.hidden = true;
                            return;
                        }
                        statusEl.textContent = '';
                        table.hidden = false;
                        var primaryCount = rows.reduce(function (n, r) { return n + (r.isPrimary ? 1 : 0); }, 0);
                        tbody.innerHTML = rows.map(function (d) {
                            var isOnlyPrimary = d.isPrimary && primaryCount === 1;
                            var hostEnc = encodeURIComponent(d.hostname);
                            return '' +
                                '<tr data-host="' + escapeHtml(hostEnc) + '">' +
                                    '<td><code>' + escapeHtml(d.hostname) + '</code></td>' +
                                    '<td>' + (d.isPrimary
                                        ? '<span class="tenants-pill tenants-pill--active">Primary</span>'
                                        : '<button type="button" class="btn btn--ghost btn--sm" data-action="set-primary">Make primary</button>') + '</td>' +
                                    '<td>' + escapeHtml(d.createdAt || '—') + '</td>' +
                                    '<td><button type="button" class="btn btn--ghost btn--sm" data-action="remove"' +
                                        (isOnlyPrimary ? ' disabled title="Cannot remove the only primary domain"' : '') +
                                    '>Remove</button></td>' +
                                '</tr>';
                        }).join('');
                    })
                    .catch(function (err) {
                        statusEl.textContent = 'Error: ' + err.message;
                        toast('Domains load failed: ' + err.message, 'error');
                    });
            }

            // Row actions (event delegation)
            tbody.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('button[data-action]');
                if (!btn) return;
                var row = btn.closest('tr[data-host]');
                if (!row) return;
                var host = decodeURIComponent(row.getAttribute('data-host'));
                var action = btn.getAttribute('data-action');

                if (action === 'remove') {
                    if (!confirm('Remove ' + host + '?')) return;
                    fetch(PROXY + '?op=domains.remove&id=' + encodeURIComponent(id) + '&did=' + encodeURIComponent(host), { method: 'POST' })
                        .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                        .then(function (res) {
                            if (!res.ok) { toast('Remove failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                            toast('Domain removed.', 'success');
                            loadList();
                        })
                        .catch(function (e) { toast('Remove failed: ' + e.message, 'error'); });
                } else if (action === 'set-primary') {
                    fetch(PROXY + '?op=domains.update&id=' + encodeURIComponent(id) + '&did=' + encodeURIComponent(host), {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ isPrimary: true })
                    })
                        .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                        .then(function (res) {
                            if (!res.ok) { toast('Update failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                            toast('Primary updated.', 'success');
                            loadList();
                        })
                        .catch(function (e) { toast('Update failed: ' + e.message, 'error'); });
                }
            });

            // Add modal
            addBtn.addEventListener('click', function () { modal.hidden = false; });
            modal.addEventListener('click', function (e) {
                if (e.target.matches('[data-close]')) {
                    modal.hidden = true;
                    if (errEl) errEl.textContent = '';
                    if (form) form.reset();
                }
            });
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(form);
                var payload = {
                    hostname:  String(fd.get('hostname') || '').trim(),
                    isPrimary: fd.get('isPrimary') ? true : false
                };
                if (errEl) errEl.textContent = '';
                fetch(PROXY + '?op=domains.add&id=' + encodeURIComponent(id), {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload)
                })
                    .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            if (errEl) errEl.textContent = (res.body && res.body.error) || ('HTTP ' + res.status);
                            return;
                        }
                        modal.hidden = true;
                        form.reset();
                        toast('Domain added.', 'success');
                        loadList();
                    })
                    .catch(function (e) { if (errEl) errEl.textContent = 'Network error: ' + e.message; });
            });

            loadList();
        }
    };

    // -----------------------------------------------------------------------
    // H5 — Admins tab
    //
    // The list endpoint currently returns {admins: [], total: <count>}.
    // findAdminsForTenant in the repo is unimplemented (Wave F known limit).
    // Until that lands, show the count + a manual grant/revoke flow.
    // -----------------------------------------------------------------------
    window.DaemsTenantTabs.admins = {
        load: function (host) {
            var id    = window.DAEMS_TENANT_EDIT.tenantId;
            var countEl  = host.querySelector('#ta-count');
            var addBtn   = host.querySelector('#ta-add-btn');
            var modal    = host.querySelector('#ta-add-modal');
            var addForm  = host.querySelector('#ta-add-form');
            var addError = host.querySelector('#ta-add-error');
            var revoke   = host.querySelector('#ta-revoke-form');

            function loadCount() {
                fetch(PROXY + '?op=admins.list&id=' + encodeURIComponent(id))
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (!res.ok) throw new Error((res.body && res.body.error) || 'Load failed');
                        var total = (res.body && res.body.data && res.body.data.total) || 0;
                        if (countEl) countEl.textContent = String(total);
                    })
                    .catch(function (e) {
                        if (countEl) countEl.textContent = '—';
                        toast('Admin count load failed: ' + e.message, 'error');
                    });
            }

            addBtn.addEventListener('click', function () { modal.hidden = false; });
            modal.addEventListener('click', function (e) {
                if (e.target.matches('[data-close]')) {
                    modal.hidden = true;
                    if (addForm) addForm.reset();
                    if (addError) addError.textContent = '';
                }
            });

            addForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var fd = new FormData(addForm);
                var uid = String(fd.get('userId') || '').trim();
                if (!uid) { if (addError) addError.textContent = 'User id is required'; return; }
                if (addError) addError.textContent = '';
                fetch(PROXY + '?op=admins.grant&id=' + encodeURIComponent(id), {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ userId: uid })
                })
                    .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            if (addError) addError.textContent = (res.body && res.body.error) || ('HTTP ' + res.status);
                            return;
                        }
                        modal.hidden = true;
                        addForm.reset();
                        toast('Admin granted.', 'success');
                        loadCount();
                    })
                    .catch(function (e) { if (addError) addError.textContent = 'Network error: ' + e.message; });
            });

            revoke.addEventListener('submit', function (e) {
                e.preventDefault();
                var uid = String(new FormData(revoke).get('userId') || '').trim();
                if (!uid) return;
                if (!confirm('Revoke admin role from ' + uid + '?')) return;
                fetch(PROXY + '?op=admins.revoke&id=' + encodeURIComponent(id) + '&uid=' + encodeURIComponent(uid), { method: 'POST' })
                    .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) { toast('Revoke failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                        toast('Admin revoked.', 'success');
                        revoke.reset();
                        loadCount();
                    })
                    .catch(function (e) { toast('Revoke failed: ' + e.message, 'error'); });
            });

            loadCount();
        }
    };

    // -----------------------------------------------------------------------
    // H6 — Modules tab
    // -----------------------------------------------------------------------
    var STATE_LABELS = {
        'enabled':   'Enabled',
        'available': 'Available',
        'disabled':  'Disabled',
        'core':      'Core'
    };

    window.DaemsTenantTabs.modules = {
        load: function (host) {
            var id      = window.DAEMS_TENANT_EDIT.tenantId;
            var tbody   = host.querySelector('#tm-tbody');
            var table   = host.querySelector('#tm-table');
            var statusEl= host.querySelector('#tm-status');
            var confirm = host.querySelector('#tm-confirm');
            var reason  = host.querySelector('#tm-reason');
            var goBtn   = host.querySelector('#tm-confirm-go');
            var pending = null; // { slug, action }

            function loadList() {
                statusEl.textContent = 'Loading…';
                fetch(PROXY + '?op=modules.list&id=' + encodeURIComponent(id))
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (!res.ok) throw new Error((res.body && res.body.error) || 'Load failed');
                        var rows = (res.body && res.body.data && res.body.data.modules) || [];
                        if (rows.length === 0) {
                            statusEl.textContent = 'No modules registered.';
                            table.hidden = true;
                            return;
                        }
                        statusEl.textContent = '';
                        table.hidden = false;
                        tbody.innerHTML = rows.map(function (m) {
                            var state = String(m.state || '');
                            var label = STATE_LABELS[state] || state;
                            var stateClass = (state === 'enabled' || state === 'available' || state === 'core')
                                ? 'tenant-module-badge--available'
                                : (state === 'disabled' ? 'tenant-module-badge--unavailable' : '');
                            var actionBtn = '';
                            if (state === 'core') {
                                actionBtn = '<span class="tenants-pill">Core</span>';
                            } else if (state === 'disabled') {
                                actionBtn = '<button type="button" class="btn btn--primary btn--sm" data-action="grant" data-slug="' + escapeHtml(m.slug) + '">Grant</button>';
                            } else {
                                // enabled or available — both are 'granted'; offer revoke
                                actionBtn = '<button type="button" class="btn btn--ghost btn--sm" data-action="revoke" data-slug="' + escapeHtml(m.slug) + '">Revoke</button>';
                            }
                            return '' +
                                '<tr>' +
                                    '<td><code>' + escapeHtml(m.slug) + '</code></td>' +
                                    '<td>' + escapeHtml(m.nameKey || m.slug) + '</td>' +
                                    '<td>' + escapeHtml(m.category || '—') + '</td>' +
                                    '<td><span class="tenant-module-badge ' + stateClass + '">' + escapeHtml(label) + '</span></td>' +
                                    '<td>' + actionBtn + '</td>' +
                                '</tr>';
                        }).join('');
                    })
                    .catch(function (e) {
                        statusEl.textContent = 'Error: ' + e.message;
                        toast('Modules load failed: ' + e.message, 'error');
                    });
            }

            tbody.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('button[data-action]');
                if (!btn) return;
                var action = btn.getAttribute('data-action');
                var slug   = btn.getAttribute('data-slug');
                if (action === 'grant') {
                    pending = { slug: slug, action: 'grant' };
                    if (reason) reason.value = '';
                    host.querySelector('#tm-confirm-title').textContent = 'Grant module: ' + slug;
                    host.querySelector('#tm-confirm-body').textContent = 'Granting marks the module available so the tenant admin can enable it. Provide a reason for the audit log (optional).';
                    goBtn.textContent = 'Grant';
                    confirm.hidden = false;
                } else if (action === 'revoke') {
                    pending = { slug: slug, action: 'revoke' };
                    if (reason) reason.value = '';
                    host.querySelector('#tm-confirm-title').textContent = 'Revoke module: ' + slug;
                    host.querySelector('#tm-confirm-body').textContent = 'Revoking will force-disable any module that depends on this one (server-enforced cascade). Provide a reason — required.';
                    goBtn.textContent = 'Revoke';
                    confirm.hidden = false;
                }
            });

            confirm.addEventListener('click', function (e) {
                if (e.target.matches('[data-close]')) {
                    confirm.hidden = true;
                    pending = null;
                }
            });

            goBtn.addEventListener('click', function () {
                if (!pending) return;
                var r = (reason && reason.value) || '';
                if (pending.action === 'revoke' && r.trim() === '') {
                    toast('Reason is required for revoke.', 'error');
                    return;
                }
                var payload = { action: pending.action };
                if (r.trim() !== '') payload.reason = r.trim();
                fetch(PROXY + '?op=modules.availability&id=' + encodeURIComponent(id) + '&slug=' + encodeURIComponent(pending.slug), {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload)
                })
                    .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            toast('Module ' + pending.action + ' failed: ' + ((res.body && res.body.error) || res.status), 'error');
                            return;
                        }
                        toast('Module ' + pending.slug + ' ' + (pending.action === 'grant' ? 'granted' : 'revoked') + '.', 'success');
                        confirm.hidden = true;
                        pending = null;
                        loadList();
                    })
                    .catch(function (e) { toast('Module update failed: ' + e.message, 'error'); });
            });

            loadList();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
