/**
 * Project-proposals admin — review modal with approve/reject actions.
 *
 * Same UX as backstage/event-proposals but targeting the project-proposal
 * endpoints. Clicking Review on a row opens a modal showing the proposal +
 * Approve / Reject buttons. On approve, the backend creates a Project row
 * and a projects_i18n row in the proposal's source_locale.
 */
(function (global) {
    'use strict';

    var APPROVE_URL = function (id) {
        return '/api/v1/backstage/project-proposals/' + encodeURIComponent(id) + '/approve';
    };
    var REJECT_URL = function (id) {
        return '/api/v1/backstage/project-proposals/' + encodeURIComponent(id) + '/reject';
    };

    var CATEGORIES = {
        community:  'Community',
        technology: 'Technology',
        events:     'Events',
        research:   'Research'
    };

    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function postJson(url, body) {
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

    function renderReview(p) {
        var catLabel = CATEGORIES[p.category] || (p.category || '—');
        return '<div class="evt-modal-backdrop" role="dialog" aria-modal="true">' +
            '<div class="evt-modal" style="max-width:640px;">' +
                '<div class="evt-modal__header">' +
                    '<h2 class="evt-modal__title">' + escHtml(p.title || '') + '</h2>' +
                    '<button type="button" class="evt-modal__close" data-action="cancel" aria-label="Close">&times;</button>' +
                '</div>' +
                '<div class="evt-modal__body pp-review-body">' +
                    '<dl>' +
                        '<dt>Author</dt>' +
                        '<dd>' + escHtml(p.author_name || '') + ' &lt;' + escHtml(p.author_email || '') + '&gt;</dd>' +
                        '<dt>Category</dt>' +
                        '<dd>' + escHtml(catLabel) + '</dd>' +
                        '<dt>Source locale</dt>' +
                        '<dd><span class="locale-badge">' + escHtml(p.source_locale || 'fi_FI') + '</span></dd>' +
                        (p.summary ? ('<dt>Summary</dt><dd>' + escHtml(p.summary) + '</dd>') : '') +
                        '<dt>Description</dt>' +
                        '<dd>' + escHtml(p.description || '') + '</dd>' +
                    '</dl>' +
                    '<div class="pp-review-notice">' +
                        'Approval will create a Project visible only in <code>' +
                        escHtml(p.source_locale || 'fi_FI') +
                        '</code> until you add translations for the other locales.' +
                    '</div>' +
                '</div>' +
                '<div class="evt-modal__footer">' +
                    '<span class="evt-error-msg" data-status></span>' +
                    '<button type="button" class="btn btn--ghost"   data-action="cancel">Cancel</button>' +
                    '<button type="button" class="btn btn--danger"  data-action="reject">Reject</button>' +
                    '<button type="button" class="btn btn--primary" data-action="approve">Approve</button>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function openReview(p) {
        var wrap = document.createElement('div');
        wrap.innerHTML = renderReview(p);
        var backdrop = wrap.firstChild;
        document.body.appendChild(backdrop);

        var statusEl = backdrop.querySelector('[data-status]');
        function setStatus(msg, isError) {
            if (!statusEl) return;
            statusEl.textContent = msg || '';
            statusEl.style.color = isError ? 'var(--status-error)' : '';
        }
        function setDisabled(on) {
            backdrop.querySelectorAll('button[data-action]').forEach(function (b) {
                b.disabled = !!on;
            });
        }
        function closeModal() {
            document.removeEventListener('keydown', onKey);
            if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
        }
        function onKey(e) {
            if (e.key === 'Escape') closeModal();
        }
        document.addEventListener('keydown', onKey);
        backdrop.addEventListener('click', function (e) {
            if (e.target === backdrop) closeModal();
        });

        backdrop.querySelectorAll('button[data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.dataset.action;
                if (action === 'cancel') { closeModal(); return; }
                if (action === 'approve') {
                    if (!confirm('Approve this project proposal? It will be created as a project.')) return;
                    setDisabled(true);
                    setStatus('Approving…');
                    postJson(APPROVE_URL(p.id), {}).then(function () {
                        setStatus('Approved — reloading…');
                        location.reload();
                    }).catch(function (err) {
                        setDisabled(false);
                        setStatus('Error: ' + (err.message || 'approve failed'), true);
                    });
                }
                if (action === 'reject') {
                    var note = prompt('Rejection note (optional):') || '';
                    setDisabled(true);
                    setStatus('Rejecting…');
                    postJson(REJECT_URL(p.id), { note: note }).then(function () {
                        setStatus('Rejected — reloading…');
                        location.reload();
                    }).catch(function (err) {
                        setDisabled(false);
                        setStatus('Error: ' + (err.message || 'reject failed'), true);
                    });
                }
            });
        });
    }

    function boot() {
        document.querySelectorAll('[data-review]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var raw = btn.getAttribute('data-review');
                if (!raw) return;
                var parsed;
                try { parsed = JSON.parse(raw); } catch (e) { return; }
                if (parsed) openReview(parsed);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
}(window));
