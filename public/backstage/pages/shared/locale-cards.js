/**
 * Locale-cards component — shared between event + project editors.
 *
 * Exposes: window.LocaleCards.mount(container, options)
 *   container : HTMLElement (the .locale-cards-container)
 *   options   : { kind, entityId, translations, coverage }
 *     kind         — 'event' | 'project'
 *     entityId     — UUID string (may be empty on create)
 *     translations — { [locale]: { field: value | null } | null }
 *     coverage     — { [locale]: { filled: int, total: int } }
 *
 * Saving: POST /api/v1/backstage/{kind}s/{entityId}/translations/{locale}
 * with body of { field: value } for each field. Response.coverage (per-locale
 * { filled, total }) updates state and card progress bars re-render.
 *
 * Emits: document CustomEvent 'locale-cards:saved' with detail
 *        { kind, entityId, coverage } after a successful save.
 */
(function (global) {
    'use strict';

    var LOCALES = [
        { code: 'fi_FI', label: 'Suomi',     flag: '🇫🇮' }, // 🇫🇮
        { code: 'en_GB', label: 'English',   flag: '🇬🇧' }, // 🇬🇧
        { code: 'sw_TZ', label: 'Kiswahili', flag: '🇹🇿' }  // 🇹🇿
    ];

    var FIELDS = {
        event: [
            { name: 'title',       label: 'Title',       type: 'text',     required: true  },
            { name: 'location',    label: 'Location',    type: 'text',     required: false },
            { name: 'description', label: 'Description', type: 'textarea', required: false }
        ],
        project: [
            { name: 'title',       label: 'Title',       type: 'text',     required: true,  maxlength: 200 },
            { name: 'summary',     label: 'Summary',     type: 'textarea', required: true,  maxlength: 300 },
            { name: 'description', label: 'Description', type: 'textarea', required: true }
        ],
        insight: [
            { name: 'title',   label: 'Title',         type: 'text',     required: true,  maxlength: 255 },
            { name: 'excerpt', label: 'Excerpt',       type: 'textarea', required: true,  maxlength: 500 },
            { name: 'content', label: 'Body (HTML)',   type: 'textarea', required: true }
        ]
    };

    var KIND_PATHS = {
        event:   'events',
        project: 'projects',
        insight: 'insights'
    };

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function statusText(c) {
        if (!c || c.total === 0) return 'No fields';
        if (c.filled >= c.total) return 'Complete';
        if (c.filled === 0)      return 'Not translated';
        return 'Partial';
    }

    function coverageClass(c) {
        if (!c)                                 return 'coverage-empty';
        if (c.filled >= c.total && c.total > 0) return 'coverage-complete';
        if (c.filled === 0)                     return 'coverage-empty';
        return 'coverage-partial';
    }

    function renderCards(container, state) {
        var grid = container.querySelector('.locale-cards-grid');
        if (!grid) return;
        grid.innerHTML = '';

        LOCALES.forEach(function (l) {
            var defaultTotal = (FIELDS[state.kind] || []).length;
            var coverage = state.coverage[l.code] || { filled: 0, total: defaultTotal };
            var pct = coverage.total > 0
                ? Math.round((coverage.filled / coverage.total) * 100)
                : 0;
            var isActive = (l.code === state.activeLocale);

            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'locale-card ' + coverageClass(coverage);
            card.setAttribute('role', 'tab');
            card.setAttribute('aria-selected', isActive ? 'true' : 'false');
            card.dataset.locale = l.code;
            card.innerHTML =
                '<div class="locale-label">' + l.flag + ' ' + escHtml(l.label) + '</div>' +
                '<div class="locale-code">' + escHtml(l.code) + '</div>' +
                '<div class="coverage-bar"><span style="width:' + pct + '%"></span></div>' +
                '<div class="coverage-text">' + coverage.filled + '/' + coverage.total + ' · ' + escHtml(statusText(coverage)) + '</div>';

            card.addEventListener('click', function () {
                state.activeLocale = l.code;
                render(container, state);
            });
            grid.appendChild(card);
        });
    }

    function renderFields(container, state) {
        var fieldsEl = container.querySelector('.locale-cards-fields');
        if (!fieldsEl) return;
        fieldsEl.innerHTML = '';

        var fields = FIELDS[state.kind] || [];
        var row = state.translations[state.activeLocale] || {};

        fields.forEach(function (f) {
            var wrap = document.createElement('div');
            wrap.className = 'lc-field';
            var id = 'lc-' + state.kind + '-' + f.name;
            var value = (row && row[f.name] != null) ? row[f.name] : '';

            var label = document.createElement('label');
            label.setAttribute('for', id);
            label.innerHTML = escHtml(f.label) + (f.required
                ? ' <span class="lc-required" aria-hidden="true">*</span>'
                : '');
            wrap.appendChild(label);

            var input;
            if (f.type === 'textarea') {
                input = document.createElement('textarea');
                // Body fields (insight.content) get more vertical room.
                input.rows = (f.name === 'content') ? 14 : 5;
            } else {
                input = document.createElement('input');
                input.type = 'text';
            }
            input.id = id;
            input.name = f.name;
            input.value = value;
            if (f.maxlength) input.maxLength = f.maxlength;
            wrap.appendChild(input);

            fieldsEl.appendChild(wrap);
        });

        var saveBtn = container.querySelector('.locale-cards-save');
        if (saveBtn) {
            saveBtn.textContent = 'Save ' + state.activeLocale;
        }
    }

    function render(container, state) {
        renderCards(container, state);
        renderFields(container, state);
    }

    function setStatus(container, message, kind) {
        var el = container.querySelector('.locale-cards-status');
        if (!el) return;
        el.textContent = message || '';
        el.classList.remove('is-error', 'is-success');
        if (kind === 'error')   el.classList.add('is-error');
        if (kind === 'success') el.classList.add('is-success');
    }

    function collectBody(container) {
        var inputs = container.querySelectorAll('.locale-cards-fields input, .locale-cards-fields textarea');
        var body = {};
        inputs.forEach(function (i) {
            // Preserve empty string as "" — backend treats null/empty as "missing".
            body[i.name] = i.value;
        });
        return body;
    }

    function buildSaveUrl(state) {
        var kindPath = KIND_PATHS[state.kind] || (state.kind + 's');
        return '/api/v1/backstage/' + kindPath + '/' + encodeURIComponent(state.entityId) +
               '/translations/' + encodeURIComponent(state.activeLocale);
    }

    /**
     * Thin fetch wrapper with JSON body + credentials. When a caller-supplied
     * window.ApiClient exists (global.ApiClient), prefer that so auth headers
     * and base URL handling stay centralized.
     */
    function postJson(url, body) {
        if (global.ApiClient && typeof global.ApiClient.post === 'function') {
            return Promise.resolve(global.ApiClient.post(url, body));
        }
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(body || {})
        }).then(function (r) {
            return r.json().catch(function () { return {}; }).then(function (data) {
                if (!r.ok) {
                    var err = new Error((data && (data.error || data.message)) || ('HTTP ' + r.status));
                    err.status = r.status;
                    err.data = data;
                    throw err;
                }
                return data;
            });
        });
    }

    function save(container, state) {
        var saveBtn = container.querySelector('.locale-cards-save');
        if (!state.entityId) {
            setStatus(container,
                'Save the entity first, then translations can be edited.',
                'error');
            return;
        }

        var body = collectBody(container);
        if (saveBtn) saveBtn.disabled = true;
        setStatus(container, 'Saving…');

        postJson(buildSaveUrl(state), body)
            .then(function (res) {
                // Cache the just-saved values in state.
                state.translations[state.activeLocale] = body;
                // Response may carry coverage directly or under data/{coverage}.
                var cov = (res && res.coverage)
                    || (res && res.data && res.data.coverage)
                    || null;
                if (cov) state.coverage = cov;
                setStatus(container, 'Saved', 'success');
                render(container, state);
                document.dispatchEvent(new CustomEvent('locale-cards:saved', {
                    detail: {
                        kind: state.kind,
                        entityId: state.entityId,
                        locale: state.activeLocale,
                        coverage: state.coverage
                    }
                }));
                setTimeout(function () {
                    var el = container.querySelector('.locale-cards-status');
                    if (el && el.textContent === 'Saved') setStatus(container, '');
                }, 2000);
            })
            .catch(function (err) {
                var msg = (err && err.message) ? err.message : 'Save failed.';
                setStatus(container, 'Error: ' + msg, 'error');
            })
            .then(function () {
                if (saveBtn) saveBtn.disabled = false;
            });
    }

    function mount(container, options) {
        if (!container) return;
        options = options || {};
        var state = {
            kind:          options.kind     || container.dataset.kind || 'event',
            entityId:      options.entityId || container.dataset.entityId || '',
            translations:  options.translations || {},
            coverage:      options.coverage     || {},
            activeLocale:  'fi_FI'
        };
        // Persist entityId on the element for later DOM-driven updates.
        container.dataset.entityId = state.entityId;

        var saveBtn = container.querySelector('.locale-cards-save');
        if (saveBtn && !saveBtn.dataset.bound) {
            saveBtn.dataset.bound = '1';
            saveBtn.addEventListener('click', function () { save(container, state); });
        }
        render(container, state);
        // Expose the current state on the container for host pages that need
        // to read/update it (e.g. after a create flow captures the new id).
        container._localeCardsState = state;
    }

    function updateEntityId(container, entityId) {
        if (!container || !container._localeCardsState) return;
        container._localeCardsState.entityId = entityId;
        container.dataset.entityId = entityId;
    }

    global.LocaleCards = {
        mount: mount,
        updateEntityId: updateEntityId,
        LOCALES: LOCALES.slice(),
        FIELDS:  (function () {
            var out = {};
            Object.keys(FIELDS).forEach(function (k) { out[k] = FIELDS[k].slice(); });
            return out;
        })()
    };
}(window));
