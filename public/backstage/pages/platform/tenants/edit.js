/**
 * Wave H — Tenant edit shell JS.
 *
 * Loads the tenant header (name + status) once on page load, then
 * dispatches the active tab to the matching loader. Each loader is
 * defined under window.DaemsTenantTabs[tabName].
 *
 * Page navigation is plain <a href="?tab=…"> so the back button works
 * and per-tab state is shared via the URL.
 */
(function () {
    'use strict';

    var PROXY = '/api/backstage/platform-tenants';

    window.DaemsTenantTabs = window.DaemsTenantTabs || {};

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function _t(key) {
        var T = window.DAEMS_TENANTS_I18N || {};
        if (T[key]) return T[key];
        return key;
    }
    function _tf(key, params) {
        // Token replacement supports both {name} and printf %s (latter consumes them in order).
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
    function setStatus(msg) {
        var el = document.getElementById('tenant-edit-status-line');
        if (el) el.textContent = msg || '';
    }

    // Locale catalogue (matches public/backstage/pages/shared/locale-cards.js)
    var LOCALES = [
        { code: 'fi_FI', label: 'Suomi',     flag: '🇫🇮' },
        { code: 'en_GB', label: 'English',   flag: '🇬🇧' },
        { code: 'sw_TZ', label: 'Kiswahili', flag: '🇹🇿' }
    ];
    function localeFlag(code) {
        for (var i = 0; i < LOCALES.length; i++) if (LOCALES[i].code === code) return LOCALES[i].flag;
        return '';
    }

    // -----------------------------------------------------------------------
    // Header
    // -----------------------------------------------------------------------
    function loadHeader() {
        var id = (window.DAEMS_TENANT_EDIT && window.DAEMS_TENANT_EDIT.tenantId) || '';
        if (!id) {
            setStatus('Missing tenant id.');
            return Promise.reject(new Error('missing_id'));
        }

        setStatus(_t('platform.common.loading'));
        return fetch(PROXY + '?op=get&id=' + encodeURIComponent(id), {
            headers: { 'Accept': 'application/json' }
        })
            .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
            .then(function (res) {
                if (!res.ok) throw new Error((res.body && res.body.error) || _t('platform.tenants.error.load_failed'));
                var t = (res.body && res.body.data) || {};
                window.DAEMS_TENANT_EDIT.tenant = t;
                renderHeader(t);
                setStatus('');
                return t;
            })
            .catch(function (err) {
                setStatus(_t('platform.common.error_prefix') + ': ' + err.message);
                toast(_t('platform.tenants.error.load_failed') + ': ' + err.message, 'error');
                throw err;
            });
    }

    function renderHeader(t) {
        var nameEl = document.getElementById('tenant-edit-name');
        var slugEl = document.getElementById('tenant-edit-slug');
        var stEl   = document.getElementById('tenant-edit-status');

        var name = t.display_name || t.name || (t.slug || 'Tenant');
        if (typeof name === 'object') {
            name = name.en_GB || name.fi_FI || (t.slug || 'Tenant');
        }
        if (nameEl) nameEl.textContent = name;
        if (slugEl) slugEl.textContent = t.slug || '—';

        if (stEl) {
            var s = String(t.status || '').toLowerCase();
            // Map server status → human label via i18n if known.
            var labelKey = 'platform.tenants.status.' + s;
            var label = _t(labelKey);
            if (label === labelKey) label = t.status || '—';
            stEl.textContent = label;
            stEl.className = 'tenants-pill' + ((s === 'active' || s === 'suspended') ? ' tenants-pill--' + s : '');
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
            catch (e) {
                setStatus(_t('platform.common.error_prefix') + ': ' + e.message);
                toast(_t('platform.common.error_prefix') + ': ' + e.message, 'error');
            }
        }
    }

    // Esc-to-close any open modal / confirm dialog inside the edit shell.
    function bindEscToClose() {
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            var openModals = document.querySelectorAll('.tenants-modal:not([hidden]), .tenant-confirm:not([hidden])');
            openModals.forEach(function (m) { m.hidden = true; });
        });
    }

    // -----------------------------------------------------------------------
    // Boot
    // -----------------------------------------------------------------------
    function init() {
        var meta = window.DAEMS_TENANT_EDIT;
        if (!meta || !meta.tenantId) return;

        bindEscToClose();

        window.DaemsTenantEdit = {
            proxy: PROXY,
            tenantId: meta.tenantId,
            escapeHtml: escapeHtml,
            toast: toast,
            setStatus: setStatus,
            t: _t,
            tf: _tf,
            reloadHeader: loadHeader,
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

    function renderLocaleCards(host, fieldName, valuesByLocale, supportedLocales, defaultLocale, multiline) {
        host.innerHTML = '';
        // Order: default locale first, then the rest in their canonical order.
        var ordered = supportedLocales.slice().sort(function (a, b) {
            if (a === defaultLocale) return -1;
            if (b === defaultLocale) return 1;
            return ALL_LOCALES.indexOf(a) - ALL_LOCALES.indexOf(b);
        });

        ordered.forEach(function (loc) {
            var card = document.createElement('div');
            card.className = 'tenant-locale-card';

            var head = document.createElement('div');
            head.className = 'tenant-locale-card__head';
            var title = document.createElement('p');
            title.className = 'tenant-locale-card__title';
            title.innerHTML = '<span class="tenant-locale-card__flag" aria-hidden="true">' + localeFlag(loc) + '</span>' + escapeHtml(loc);
            head.appendChild(title);
            if (loc === defaultLocale) {
                var pill = document.createElement('span');
                pill.className = 'tenant-locale-card__default-pill';
                pill.textContent = 'default';
                head.appendChild(pill);
            }
            card.appendChild(head);

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
            var defaultLocale = t.defaultLocale || 'fi_FI';

            var slugIn = host.querySelector('#tb-slug');
            if (slugIn) slugIn.value = t.slug || '';

            renderLocaleCards(host.querySelector('#tb-display-name-cards'),       'displayNameI18n',
                              t.displayNameI18n || {},       supported, defaultLocale, false);
            renderLocaleCards(host.querySelector('#tb-public-description-cards'), 'publicDescriptionI18n',
                              t.publicDescriptionI18n || {}, supported, defaultLocale, true);

            host.querySelectorAll('input[name="supportedLocales"]').forEach(function (cb) {
                cb.checked = supported.indexOf(cb.value) !== -1;
            });

            var defLoc = host.querySelector('#tb-default-locale');
            if (defLoc) defLoc.value = defaultLocale;

            var prefIn = host.querySelector('#tb-prefix');
            if (prefIn) prefIn.value = t.memberNumberPrefix || '';

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
        if (statusEl) {
            statusEl.textContent = _t('platform.tenants.basics.status.saving');
            statusEl.className = 'tenant-form__status';
        }
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
            method:  'POST',
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
                    if (statusEl) {
                        statusEl.textContent = _t('platform.common.error_prefix') + ': ' + msg;
                        statusEl.className = 'tenant-form__status is-error';
                    }
                    toast(_t('platform.tenants.basics.toast.save_failed') + ': ' + msg, 'error');
                    return;
                }
                if (statusEl) {
                    statusEl.textContent = _t('platform.tenants.basics.status.saved');
                    statusEl.className = 'tenant-form__status is-success';
                }
                toast(_t('platform.tenants.basics.toast.saved'), 'success');
                if (window.DaemsTenantEdit && window.DaemsTenantEdit.reloadHeader) {
                    window.DaemsTenantEdit.reloadHeader();
                }
            })
            .catch(function (e) {
                if (statusEl) {
                    statusEl.textContent = _t('platform.common.network_error') + ': ' + e.message;
                    statusEl.className = 'tenant-form__status is-error';
                }
                toast(_t('platform.tenants.basics.toast.save_failed') + ': ' + e.message, 'error');
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
            var emptyEl  = host.querySelector('#td-empty');
            var statusEl = host.querySelector('#td-status');
            var addBtn   = host.querySelector('#td-add-btn');
            var modal    = host.querySelector('#td-add-modal');
            var form     = host.querySelector('#td-add-form');
            var errEl    = host.querySelector('#td-add-error');

            function loadList() {
                statusEl.textContent = _t('platform.common.loading');
                fetch(PROXY + '?op=domains.list&id=' + encodeURIComponent(id))
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (!res.ok) throw new Error((res.body && res.body.error) || 'Load failed');
                        var rows = (res.body && res.body.data && res.body.data.domains) || [];
                        if (rows.length === 0) {
                            statusEl.textContent = '';
                            table.hidden = true;
                            if (emptyEl) emptyEl.hidden = false;
                            return;
                        }
                        statusEl.textContent = '';
                        table.hidden = false;
                        if (emptyEl) emptyEl.hidden = true;
                        var primaryCount = rows.reduce(function (n, r) { return n + (r.isPrimary ? 1 : 0); }, 0);
                        tbody.innerHTML = rows.map(function (d) {
                            var isOnlyPrimary = d.isPrimary && primaryCount === 1;
                            var hostEnc = encodeURIComponent(d.hostname);
                            return '' +
                                '<tr data-host="' + escapeHtml(hostEnc) + '">' +
                                    '<td><code>' + escapeHtml(d.hostname) + '</code></td>' +
                                    '<td>' + (d.isPrimary
                                        ? '<span class="tenants-pill tenants-pill--active">' + escapeHtml(_t('platform.tenants.domains.pill.primary')) + '</span>'
                                        : '<button type="button" class="btn btn--ghost btn--sm" data-action="set-primary">' + escapeHtml(_t('platform.tenants.domains.action.make_primary')) + '</button>') + '</td>' +
                                    '<td>' + escapeHtml(d.createdAt || '—') + '</td>' +
                                    '<td class="tenant-tab-table__actions"><button type="button" class="btn btn--ghost btn--sm" data-action="remove"' +
                                        (isOnlyPrimary ? ' disabled title="' + escapeHtml(_t('platform.tenants.domains.cannot_remove_primary')) + '"' : '') +
                                    '>' + escapeHtml(_t('platform.tenants.domains.action.remove')) + '</button></td>' +
                                '</tr>';
                        }).join('');
                    })
                    .catch(function (err) {
                        statusEl.textContent = _t('platform.common.error_prefix') + ': ' + err.message;
                        toast('Domains load failed: ' + err.message, 'error');
                    });
            }

            tbody.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('button[data-action]');
                if (!btn) return;
                var row = btn.closest('tr[data-host]');
                if (!row) return;
                var hostName = decodeURIComponent(row.getAttribute('data-host'));
                var action = btn.getAttribute('data-action');

                if (action === 'remove') {
                    if (!confirm(_tf('platform.tenants.domains.confirm_remove', [hostName]))) return;
                    fetch(PROXY + '?op=domains.remove&id=' + encodeURIComponent(id) + '&did=' + encodeURIComponent(hostName), { method: 'POST' })
                        .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                        .then(function (res) {
                            if (!res.ok) { toast('Remove failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                            toast(_t('platform.tenants.domains.toast.removed'), 'success');
                            loadList();
                        })
                        .catch(function (e) { toast('Remove failed: ' + e.message, 'error'); });
                } else if (action === 'set-primary') {
                    fetch(PROXY + '?op=domains.update&id=' + encodeURIComponent(id) + '&did=' + encodeURIComponent(hostName), {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body:    JSON.stringify({ isPrimary: true })
                    })
                        .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                        .then(function (res) {
                            if (!res.ok) { toast('Update failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                            toast(_t('platform.tenants.domains.toast.primary_updated'), 'success');
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
                        toast(_t('platform.tenants.domains.toast.added'), 'success');
                        loadList();
                    })
                    .catch(function (e) { if (errEl) errEl.textContent = _t('platform.common.network_error') + ': ' + e.message; });
            });

            loadList();
        }
    };

    // -----------------------------------------------------------------------
    // H5 — Admins tab
    // -----------------------------------------------------------------------
    window.DaemsTenantTabs.admins = {
        load: function (host) {
            var id       = window.DAEMS_TENANT_EDIT.tenantId;
            var countEl  = host.querySelector('#ta-count');
            var statusEl = host.querySelector('#ta-status');
            var emptyEl  = host.querySelector('#ta-empty');
            var table    = host.querySelector('#ta-table');
            var tbody    = host.querySelector('#ta-tbody');
            var addBtn   = host.querySelector('#ta-add-btn');
            var modal    = host.querySelector('#ta-add-modal');
            var addForm  = host.querySelector('#ta-add-form');
            var addError = host.querySelector('#ta-add-error');

            function fmtDate(iso) {
                if (!iso) return '—';
                // Show YYYY-MM-DD HH:MM (UTC) — keep it terse and locale-agnostic
                // until a proper time-format formatter is in scope on this page.
                var d = new Date(iso);
                if (isNaN(d.getTime())) return iso;
                var pad = function (n) { return n < 10 ? '0' + n : String(n); };
                return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) +
                       ' ' + pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + 'Z';
            }

            function loadList() {
                if (statusEl) statusEl.textContent = _t('platform.common.loading');
                fetch(PROXY + '?op=admins.list&id=' + encodeURIComponent(id))
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (!res.ok) throw new Error((res.body && res.body.error) || 'Load failed');
                        var data    = (res.body && res.body.data) || {};
                        var rows    = Array.isArray(data.admins) ? data.admins : [];
                        var total   = (typeof data.total === 'number') ? data.total : rows.length;

                        if (countEl)  countEl.textContent = String(total);
                        if (statusEl) statusEl.textContent = '';

                        if (rows.length === 0) {
                            if (table)   table.hidden = true;
                            if (tbody)   tbody.innerHTML = '';
                            if (emptyEl) emptyEl.hidden = false;
                            return;
                        }
                        if (emptyEl) emptyEl.hidden = true;
                        if (table)   table.hidden = false;

                        var revokeLabel = _t('platform.tenants.admins.action.revoke');
                        tbody.innerHTML = rows.map(function (a) {
                            return '' +
                                '<tr data-uid="' + escapeHtml(a.userId || '') + '">' +
                                    '<td>' + escapeHtml(a.name || a.userId || '—') + '</td>' +
                                    '<td>' + escapeHtml(a.email || '—') + '</td>' +
                                    '<td>' + escapeHtml(fmtDate(a.grantedAt)) + '</td>' +
                                    '<td class="tenant-tab-table__actions">' +
                                        '<button type="button" class="btn btn--danger-outline btn--sm" data-action="revoke">' +
                                            escapeHtml(revokeLabel) +
                                        '</button>' +
                                    '</td>' +
                                '</tr>';
                        }).join('');
                    })
                    .catch(function (e) {
                        if (countEl)  countEl.textContent = '—';
                        if (statusEl) statusEl.textContent = _t('platform.common.error_prefix') + ': ' + e.message;
                        toast('Admin list load failed: ' + e.message, 'error');
                    });
            }

            // Inline revoke handler bound on tbody (rows are re-rendered each load).
            tbody.addEventListener('click', function (e) {
                var btn = e.target.closest && e.target.closest('button[data-action="revoke"]');
                if (!btn) return;
                var row = btn.closest('tr[data-uid]');
                if (!row) return;
                var uid = row.getAttribute('data-uid') || '';
                if (!uid) return;
                if (!confirm(_tf('platform.tenants.admins.confirm_revoke', [uid]))) return;
                fetch(PROXY + '?op=admins.revoke&id=' + encodeURIComponent(id) + '&uid=' + encodeURIComponent(uid), { method: 'POST' })
                    .then(function (r) { return r.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) { toast('Revoke failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                        toast(_t('platform.tenants.admins.toast.revoked'), 'success');
                        loadList();
                    })
                    .catch(function (e) { toast('Revoke failed: ' + e.message, 'error'); });
            });

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
                if (!uid) {
                    if (addError) addError.textContent = _t('platform.tenants.admins.error.user_id_required');
                    return;
                }
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
                        toast(_t('platform.tenants.admins.toast.granted'), 'success');
                        loadList();
                    })
                    .catch(function (e) { if (addError) addError.textContent = _t('platform.common.network_error') + ': ' + e.message; });
            });

            loadList();
        }
    };

    // -----------------------------------------------------------------------
    // H6 — Modules tab
    // -----------------------------------------------------------------------
    window.DaemsTenantTabs.modules = {
        load: function (host) {
            var id      = window.DAEMS_TENANT_EDIT.tenantId;
            var tbody   = host.querySelector('#tm-tbody');
            var table   = host.querySelector('#tm-table');
            var statusEl= host.querySelector('#tm-status');
            var confirmDlg = host.querySelector('#tm-confirm');
            var reason  = host.querySelector('#tm-reason');
            var goBtn   = host.querySelector('#tm-confirm-go');
            var pending = null;

            var STATE_LABEL_KEYS = {
                'enabled':   'platform.tenants.modules.state.enabled',
                'available': 'platform.tenants.modules.state.available',
                'disabled':  'platform.tenants.modules.state.disabled',
                'core':      'platform.tenants.modules.state.core'
            };

            function badge(state) {
                var key = STATE_LABEL_KEYS[state] || null;
                var label = key ? _t(key) : (state || '—');
                var cls = 'tenant-module-badge';
                if (state === 'enabled' || state === 'available' || state === 'disabled' || state === 'core') {
                    cls += ' tenant-module-badge--' + state;
                }
                return '<span class="' + cls + '">' + escapeHtml(label) + '</span>';
            }

            function loadList() {
                statusEl.textContent = _t('platform.common.loading');
                fetch(PROXY + '?op=modules.list&id=' + encodeURIComponent(id))
                    .then(function (r) { return r.json().then(function (b) { return { ok: r.ok, body: b }; }); })
                    .then(function (res) {
                        if (!res.ok) throw new Error((res.body && res.body.error) || 'Load failed');
                        var rows = (res.body && res.body.data && res.body.data.modules) || [];
                        if (rows.length === 0) {
                            statusEl.textContent = _t('platform.tenants.modules.empty');
                            table.hidden = true;
                            return;
                        }
                        statusEl.textContent = '';
                        table.hidden = false;
                        tbody.innerHTML = rows.map(function (m) {
                            var state = String(m.state || '');
                            var actionBtn = '';
                            if (state === 'core') {
                                actionBtn = '<span class="tenant-module-badge tenant-module-badge--core">' + escapeHtml(_t('platform.tenants.modules.state.core')) + '</span>';
                            } else if (state === 'disabled') {
                                actionBtn = '<button type="button" class="btn btn--primary btn--sm" data-action="grant" data-slug="' + escapeHtml(m.slug) + '">' +
                                            escapeHtml(_t('platform.tenants.modules.action.grant')) + '</button>';
                            } else {
                                actionBtn = '<button type="button" class="btn btn--ghost btn--sm" data-action="revoke" data-slug="' + escapeHtml(m.slug) + '">' +
                                            escapeHtml(_t('platform.tenants.modules.action.revoke')) + '</button>';
                            }
                            return '' +
                                '<tr>' +
                                    '<td><code>' + escapeHtml(m.slug) + '</code></td>' +
                                    '<td>' + escapeHtml(m.nameKey || m.slug) + '</td>' +
                                    '<td>' + escapeHtml(m.category || '—') + '</td>' +
                                    '<td>' + badge(state) + '</td>' +
                                    '<td class="tenant-tab-table__actions">' + actionBtn + '</td>' +
                                '</tr>';
                        }).join('');
                    })
                    .catch(function (e) {
                        statusEl.textContent = _t('platform.common.error_prefix') + ': ' + e.message;
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
                    host.querySelector('#tm-confirm-title').textContent = _tf('platform.tenants.modules.confirm.grant.title', [slug]);
                    host.querySelector('#tm-confirm-body').textContent = _t('platform.tenants.modules.confirm.grant.body');
                    goBtn.textContent = _t('platform.tenants.modules.action.grant');
                    goBtn.classList.remove('btn--danger');
                    goBtn.classList.add('btn--primary');
                    confirmDlg.hidden = false;
                } else if (action === 'revoke') {
                    pending = { slug: slug, action: 'revoke' };
                    if (reason) reason.value = '';
                    host.querySelector('#tm-confirm-title').textContent = _tf('platform.tenants.modules.confirm.revoke.title', [slug]);
                    host.querySelector('#tm-confirm-body').textContent = _t('platform.tenants.modules.confirm.revoke.body');
                    goBtn.textContent = _t('platform.tenants.modules.action.revoke');
                    goBtn.classList.remove('btn--primary');
                    goBtn.classList.add('btn--danger');
                    confirmDlg.hidden = false;
                }
            });

            confirmDlg.addEventListener('click', function (e) {
                if (e.target.matches('[data-close]')) {
                    confirmDlg.hidden = true;
                    pending = null;
                }
            });

            goBtn.addEventListener('click', function () {
                if (!pending) return;
                var r = (reason && reason.value) || '';
                if (pending.action === 'revoke' && r.trim() === '') {
                    toast(_t('platform.tenants.modules.error.reason_required'), 'error');
                    return;
                }
                var payload = { action: pending.action };
                if (r.trim() !== '') payload.reason = r.trim();
                fetch(PROXY + '?op=modules.availability&id=' + encodeURIComponent(id) + '&slug=' + encodeURIComponent(pending.slug), {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload)
                })
                    .then(function (r2) { return r2.text().then(function (t) { var b = {}; try { b = t ? JSON.parse(t) : {}; } catch (_) {} return { ok: r2.ok, body: b, status: r2.status }; }); })
                    .then(function (res) {
                        if (!res.ok) {
                            toast('Module ' + pending.action + ' failed: ' + ((res.body && res.body.error) || res.status), 'error');
                            return;
                        }
                        var key = pending.action === 'grant'
                            ? 'platform.tenants.modules.toast.granted'
                            : 'platform.tenants.modules.toast.revoked';
                        toast(_tf(key, [pending.slug]), 'success');
                        confirmDlg.hidden = true;
                        pending = null;
                        loadList();
                    })
                    .catch(function (e) { toast('Module update failed: ' + e.message, 'error'); });
            });

            loadList();
        }
    };

    // -----------------------------------------------------------------------
    // H7 — Danger zone
    // -----------------------------------------------------------------------
    window.DaemsTenantTabs.danger = {
        load: function (host, tenant) {
            var id = window.DAEMS_TENANT_EDIT.tenantId;
            var t = tenant || window.DAEMS_TENANT_EDIT.tenant || {};
            var status = String(t.status || '').toLowerCase();

            var suspendCard    = host.querySelector('#td-suspend-card');
            var reactivateCard = host.querySelector('#td-reactivate-card');
            var reasonLine     = host.querySelector('#td-reactivate-reason-line');

            if (status === 'suspended') {
                suspendCard.hidden    = true;
                reactivateCard.hidden = false;
                if (t.suspendedReason && reasonLine) {
                    reasonLine.textContent = _tf('platform.tenants.danger.reactivate.with_reason', [t.suspendedReason]);
                }
            } else {
                suspendCard.hidden    = false;
                reactivateCard.hidden = true;
            }

            var suspendForm  = host.querySelector('#td-suspend-form');
            var reactivateBtn = host.querySelector('#td-reactivate-btn');

            suspendForm.addEventListener('submit', function (e) {
                e.preventDefault();
                var reason = String(host.querySelector('#td-suspend-reason').value || '').trim();
                if (!reason) { toast(_t('platform.tenants.danger.error.reason_required'), 'error'); return; }
                if (!confirm(_t('platform.tenants.danger.suspend.confirm_body'))) return;
                fetch(PROXY + '?op=suspend&id=' + encodeURIComponent(id), {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ reason: reason })
                })
                    .then(function (r) { return r.text().then(function (t2) { var b = {}; try { b = t2 ? JSON.parse(t2) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) { toast('Suspend failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                        toast(_t('platform.tenants.danger.toast.suspended'), 'success');
                        if (window.DaemsTenantEdit && window.DaemsTenantEdit.reloadHeader) {
                            window.DaemsTenantEdit.reloadHeader().then(function () {
                                window.DaemsTenantEdit.reloadActiveTab();
                            });
                        }
                    })
                    .catch(function (e) { toast('Suspend failed: ' + e.message, 'error'); });
            });

            reactivateBtn.addEventListener('click', function () {
                if (!confirm(_t('platform.tenants.danger.reactivate.confirm_body'))) return;
                fetch(PROXY + '?op=reactivate&id=' + encodeURIComponent(id), { method: 'POST' })
                    .then(function (r) { return r.text().then(function (t2) { var b = {}; try { b = t2 ? JSON.parse(t2) : {}; } catch (_) {} return { ok: r.ok, body: b, status: r.status }; }); })
                    .then(function (res) {
                        if (!res.ok) { toast('Reactivate failed: ' + ((res.body && res.body.error) || res.status), 'error'); return; }
                        toast(_t('platform.tenants.danger.toast.reactivated'), 'success');
                        if (window.DaemsTenantEdit && window.DaemsTenantEdit.reloadHeader) {
                            window.DaemsTenantEdit.reloadHeader().then(function () {
                                window.DaemsTenantEdit.reloadActiveTab();
                            });
                        }
                    })
                    .catch(function (e) { toast('Reactivate failed: ' + e.message, 'error'); });
            });
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
