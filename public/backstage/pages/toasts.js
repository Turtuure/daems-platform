(function () {
    'use strict';

    // -----------------------------------------------------------------------
    // Reusable toast API: window.DAEMS_TOASTS.show(message, kind)
    // kinds: 'info' (default) | 'success' | 'error'
    // Toasts auto-dismiss after 3s; click to dismiss immediately.
    // -----------------------------------------------------------------------
    var _toastStack = null;
    function ensureToastStack() {
        if (_toastStack) { return _toastStack; }
        _toastStack = document.createElement('div');
        _toastStack.className = 'daems-toast-stack';
        document.body.appendChild(_toastStack);
        return _toastStack;
    }
    window.DAEMS_TOASTS = {
        show: function (message, kind) {
            var el = document.createElement('div');
            el.className = 'daems-toast daems-toast--' + (kind || 'info');
            el.textContent = String(message || '');
            el.addEventListener('click', function () { el.remove(); });
            ensureToastStack().appendChild(el);
            setTimeout(function () { el.classList.add('is-leaving'); }, 2700);
            setTimeout(function () { if (el.parentNode) { el.remove(); } }, 3200);
        }
    };

    var initial = window.DAEMS_PENDING_APPS;
    if (!initial || !Array.isArray(initial.items)) { return; }

    var stack = document.createElement('div');
    stack.className = 'pending-app-toast-stack';
    document.body.appendChild(stack);

    function formatRelative(iso) {
        var then = new Date(iso).getTime();
        var delta = Math.round((Date.now() - then) / 60000);
        if (delta < 1) return 'just now';
        if (delta < 60) return delta + ' min ago';
        var hours = Math.round(delta / 60);
        if (hours < 24) return hours + ' h ago';
        return Math.round(hours / 24) + ' d ago';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function renderToast(item) {
        var el = document.createElement('div');
        el.className = 'pending-app-toast';
        var title;
        var verb;
        switch (item.type) {
            case 'project_proposal':
                title = 'New Project Proposal'; verb = 'submitted'; break;
            case 'supporter':
                title = 'New Supporter Application'; verb = 'applied'; break;
            case 'forum_report':
                title = 'Uusi foorumi-raportti'; verb = 'raportoitu'; break;
            case 'member':
            default:
                title = 'New Member Application'; verb = 'applied'; break;
        }
        el.innerHTML =
            '<button type="button" class="pending-app-toast__close" aria-label="Dismiss">\u00d7</button>' +
            '<div class="pending-app-toast__title">' + title + '</div>' +
            '<div class="pending-app-toast__meta">' +
                escapeHtml(item.name) + ' \u2014 ' + verb + ' ' + formatRelative(item.created_at) +
            '</div>';

        el.addEventListener('click', function (e) {
            if (e.target.classList.contains('pending-app-toast__close')) { return; }
            // Navigating to handle it also counts as acknowledging — persist the
            // dismissal so the toast doesn't reappear if the item is still pending.
            // Best-effort: we don't wait for the response before navigating.
            fetch('/api/backstage/dismiss', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ type: item.type, id: item.id }),
                keepalive: true,
            }).catch(function () {});

            if (item.type === 'project_proposal') {
                window.location.href = '/backstage/projects?tab=proposals&highlight=' + encodeURIComponent(item.id);
            } else if (item.type === 'forum_report') {
                window.location.href = '/backstage/forum?tab=reports&highlight=' + encodeURIComponent(item.id);
            } else {
                window.location.href = '/backstage/members?view=pending&highlight=' + encodeURIComponent(item.id);
            }
        });
        el.querySelector('.pending-app-toast__close').addEventListener('click', function (e) {
            e.stopPropagation();
            // Optimistic close — dismissal persistence is best-effort.
            el.remove();
            fetch('/api/backstage/dismiss', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ type: item.type, id: item.id }),
            }).then(function (resp) {
                if (!resp.ok) { throw new Error('HTTP ' + resp.status); }
            }).catch(function (err) { console.error('dismiss failed', err); });
        });
        return el;
    }

    function renderRollup(total) {
        var el = document.createElement('div');
        el.className = 'pending-app-toast pending-app-toast--rollup';
        el.innerHTML =
            '<div class="pending-app-toast__title">' + total + ' pending applications</div>' +
            '<div class="pending-app-toast__meta">Click to review</div>';
        el.addEventListener('click', function () {
            window.location.href = '/backstage/members?view=pending';
        });
        return el;
    }

    function render() {
        var show = initial.items.slice(0, 3);
        for (var i = 0; i < show.length; i++) { stack.appendChild(renderToast(show[i])); }
        if (initial.total > 3) { stack.appendChild(renderRollup(initial.total)); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', render);
    } else {
        render();
    }
})();
