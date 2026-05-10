/**
 * Dashboard edit mode — drag-drop reorder, hide widget, add from catalog.
 * Activates only when window.DaemsDashboard.editMode === true.
 */
(function () {
    'use strict';

    if (!window.DaemsDashboard || !window.DaemsDashboard.editMode) {
        return;
    }

    var grid = document.getElementById('dashboard-grid');
    if (!grid) {
        return;
    }

    var cfg = window.DaemsDashboard;
    var i18n = cfg.i18n || {};

    function readLayout() {
        var cells = grid.querySelectorAll('.dashboard-cell');
        return Array.prototype.map.call(cells, function (cell) {
            return {
                widget_id: cell.getAttribute('data-widget-id'),
                span: parseInt(cell.getAttribute('data-span') || '1', 10),
            };
        });
    }

    function saveLayout() {
        return fetch(cfg.layoutEndpoint, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ layout: readLayout() }),
            credentials: 'same-origin',
        }).then(function (resp) {
            if (!resp.ok) {
                alert(i18n.saveFailed || 'Could not save layout. Please retry.');
            }
            return resp;
        });
    }

    if (window.Sortable) {
        window.Sortable.create(grid, {
            handle: '.dashboard-cell__handle',
            animation: 150,
            filter: '.dashboard-add-widget',
            preventOnFilter: false,
            onEnd: saveLayout,
        });
    }

    grid.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.dashboard-cell__remove');
        if (!btn) {
            return;
        }
        var cell = btn.closest('.dashboard-cell');
        if (cell) {
            cell.remove();
            saveLayout();
        }
    });

    var resetBtn = document.getElementById('dashboard-reset');
    if (resetBtn) {
        resetBtn.addEventListener('click', function () {
            if (!window.confirm(i18n.resetConfirm || 'Reset your dashboard?')) {
                return;
            }
            fetch(cfg.layoutEndpoint, { method: 'DELETE', credentials: 'same-origin' })
                .then(function () { window.location.search = ''; });
        });
    }

    var doneBtn = document.getElementById('dashboard-save-done');
    if (doneBtn) {
        doneBtn.addEventListener('click', function () {
            window.location.search = '';
        });
    }

    var addBtn = document.getElementById('dashboard-add-widget');
    if (addBtn) {
        addBtn.addEventListener('click', openCatalog);
    }

    function openCatalog() {
        fetch(cfg.catalogEndpoint, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (payload) {
                var items = (payload && payload.data) ? payload.data : (payload || []);
                renderCatalogModal(items);
            })
            .catch(function () {
                alert(i18n.saveFailed || 'Could not load catalog.');
            });
    }

    function categoryLabel(cat) {
        var key = 'category' + cat.charAt(0).toUpperCase() + cat.slice(1);
        return i18n[key] || cat;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function renderCatalogModal(items) {
        var existing = document.querySelector('.dashboard-catalog-modal');
        if (existing) { existing.remove(); }

        var modal = document.createElement('div');
        modal.className = 'dashboard-catalog-modal';
        modal.innerHTML = buildCatalogHtml(items);
        document.body.appendChild(modal);

        modal.querySelector('.dashboard-catalog-modal__close')
            .addEventListener('click', function () { modal.remove(); });

        modal.addEventListener('click', function (e) {
            if (e.target === modal) { modal.remove(); return; }
            var card = e.target.closest && e.target.closest('.dashboard-catalog-modal__item');
            if (!card || card.classList.contains('is-locked') || card.classList.contains('is-in-layout')) {
                return;
            }
            var widgetId = card.getAttribute('data-widget-id');
            var span = parseInt(card.getAttribute('data-span') || '1', 10);
            var current = readLayout();
            current.push({ widget_id: widgetId, span: span });
            fetch(cfg.layoutEndpoint, {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ layout: current }),
                credentials: 'same-origin',
            }).then(function (resp) {
                if (resp.ok) {
                    window.location.reload();
                } else {
                    alert(i18n.saveFailed || 'Could not save.');
                }
            });
        });
    }

    function buildCatalogHtml(items) {
        var grouped = {};
        items.forEach(function (item) {
            var cat = item.category || 'other';
            if (!grouped[cat]) { grouped[cat] = []; }
            grouped[cat].push(item);
        });

        var html = '<div class="dashboard-catalog-modal__inner">';
        html += '<div class="dashboard-catalog-modal__header">';
        html += '<h3>' + escapeHtml(i18n.catalogTitle || 'Add widget') + '</h3>';
        html += '<button type="button" class="dashboard-catalog-modal__close" aria-label="Close">✕</button>';
        html += '</div>';
        html += '<div class="dashboard-catalog-modal__body">';

        if (items.length === 0) {
            html += '<p class="dashboard-catalog-modal__empty">' + escapeHtml(i18n.catalogEmpty || 'No widgets.') + '</p>';
        } else {
            Object.keys(grouped).sort().forEach(function (cat) {
                html += '<div class="dashboard-catalog-modal__category">';
                html += '<h4>' + escapeHtml(categoryLabel(cat)) + '</h4>';
                html += '<div class="dashboard-catalog-modal__grid">';
                grouped[cat].forEach(function (item) {
                    var classes = ['dashboard-catalog-modal__item'];
                    if (item.in_layout) { classes.push('is-in-layout'); }
                    if (item.locked_reason) { classes.push('is-locked'); }
                    html += '<div class="' + classes.join(' ') + '"';
                    html += ' data-widget-id="' + escapeHtml(item.widget_id) + '"';
                    html += ' data-span="' + escapeHtml(item.default_span) + '">';
                    html += '<div class="title">' + escapeHtml(item.label_key || item.widget_id) + '</div>';
                    html += '<div class="meta">span ' + escapeHtml(item.default_span);
                    if (item.module) { html += ' · ' + escapeHtml(item.module); }
                    html += '</div>';
                    if (item.in_layout) {
                        html += '<div class="status">✓ ' + escapeHtml(i18n.inLayout || 'Added') + '</div>';
                    }
                    if (item.locked_reason) {
                        html += '<div class="status">⊘ ' + escapeHtml(item.locked_reason) + '</div>';
                    }
                    html += '</div>';
                });
                html += '</div></div>';
            });
        }

        html += '</div></div>';
        return html;
    }
})();
