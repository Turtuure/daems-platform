/**
 * Daems Admin Panel — UI interactions
 * Sidebar collapse, theme toggle, command palette, user dropdown, toasts.
 */

(function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Theme
    // -------------------------------------------------------------------------
    var THEME_KEY = 'daems-admin-theme';

    function getTheme() {
        return localStorage.getItem(THEME_KEY) || 'light';
    }

    function setTheme(t) {
        localStorage.setItem(THEME_KEY, t);
        document.documentElement.setAttribute('data-theme', t);
    }

    // -------------------------------------------------------------------------
    // Sidebar collapse (desktop)
    // -------------------------------------------------------------------------
    var SIDEBAR_KEY = 'daems-admin-sidebar-collapsed';

    function initSidebar() {
        var sidebar  = document.getElementById('sidebar');
        var colBtn   = document.getElementById('sidebar-collapse');
        var togBtn   = document.getElementById('sidebar-toggle');
        var overlay  = document.getElementById('sidebar-overlay');
        if (!sidebar) return;

        // Restore desktop collapse state
        if (localStorage.getItem(SIDEBAR_KEY) === '1') {
            sidebar.classList.add('is-collapsed');
        }

        if (colBtn) {
            colBtn.addEventListener('click', function () {
                var collapsed = sidebar.classList.toggle('is-collapsed');
                localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
            });
        }

        // Mobile: hamburger opens off-canvas sidebar
        if (togBtn) {
            togBtn.addEventListener('click', function () {
                var isOpen = sidebar.classList.toggle('is-open');
                togBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                if (overlay) overlay.classList.toggle('is-visible', isOpen);
            });
        }

        if (overlay) {
            overlay.addEventListener('click', function () {
                sidebar.classList.remove('is-open');
                overlay.classList.remove('is-visible');
                if (togBtn) togBtn.setAttribute('aria-expanded', 'false');
            });
        }
    }

    // -------------------------------------------------------------------------
    // Theme toggle
    // -------------------------------------------------------------------------
    function initThemeToggle() {
        var btn = document.getElementById('theme-toggle');
        if (!btn) return;
        btn.addEventListener('click', function () {
            setTheme(getTheme() === 'dark' ? 'light' : 'dark');
        });
    }

    // -------------------------------------------------------------------------
    // User dropdown
    // -------------------------------------------------------------------------
    function initUserDropdown() {
        var wrap    = document.getElementById('user-dropdown');
        var trigger = document.getElementById('user-dropdown-trigger');
        if (!wrap || !trigger) return;

        trigger.addEventListener('click', function () {
            var isOpen = wrap.classList.toggle('is-open');
            trigger.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });

        document.addEventListener('click', function (e) {
            if (!wrap.contains(e.target)) {
                wrap.classList.remove('is-open');
                trigger.setAttribute('aria-expanded', 'false');
            }
        });
    }

    // -------------------------------------------------------------------------
    // Command palette
    // -------------------------------------------------------------------------
    function initCommandPalette() {
        var palette   = document.getElementById('cmd-palette');
        var input     = document.getElementById('cmd-palette-input');
        var list      = document.getElementById('cmd-palette-list');
        var empty     = document.getElementById('cmd-palette-empty');
        var backdrop  = document.getElementById('cmd-palette-backdrop');
        var triggers  = [
            document.getElementById('search-trigger'),
            document.getElementById('status-search-trigger')
        ];
        if (!palette || !input || !list) return;

        var items     = Array.from(list.querySelectorAll('.cmd-palette__item'));
        var selected  = -1;

        function open() {
            palette.hidden = false;
            input.value = '';
            filterItems('');
            input.focus();
            selected = -1;
        }

        function close() {
            palette.hidden = true;
        }

        function filterItems(q) {
            var lower = q.toLowerCase().trim();
            var visible = 0;
            items.forEach(function (item) {
                var label = item.querySelector('.cmd-palette__item-label');
                var matches = !lower || (label && label.textContent.toLowerCase().includes(lower));
                item.style.display = matches ? '' : 'none';
                if (matches) visible++;
            });
            if (empty) empty.hidden = visible > 0;
        }

        function selectItem(idx) {
            items.forEach(function (item, i) {
                item.classList.toggle('is-selected', i === idx);
            });
            selected = idx;
        }

        function getVisibleItems() {
            return items.filter(function (item) { return item.style.display !== 'none'; });
        }

        input.addEventListener('input', function () {
            filterItems(input.value);
            selected = -1;
        });

        input.addEventListener('keydown', function (e) {
            var vis = getVisibleItems();
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selected = Math.min(selected + 1, vis.length - 1);
                selectItem(items.indexOf(vis[selected]));
                if (vis[selected]) vis[selected].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selected = Math.max(selected - 1, 0);
                selectItem(items.indexOf(vis[selected]));
                if (vis[selected]) vis[selected].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter') {
                var cur = vis[selected] || vis[0];
                if (cur && cur.dataset.href) window.location.href = cur.dataset.href;
            } else if (e.key === 'Escape') {
                close();
            }
        });

        items.forEach(function (item) {
            item.addEventListener('click', function () {
                if (item.dataset.href) window.location.href = item.dataset.href;
            });
        });

        if (backdrop) backdrop.addEventListener('click', close);

        triggers.forEach(function (btn) {
            if (btn) btn.addEventListener('click', open);
        });

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                palette.hidden ? open() : close();
            }
        });
    }

    // -------------------------------------------------------------------------
    // Toasts
    // -------------------------------------------------------------------------
    function showToast(message, type) {
        var container = document.getElementById('toast-container');
        if (!container) return;
        type = type || 'info';

        var toast = document.createElement('div');
        toast.className = 'toast toast--' + type;
        toast.setAttribute('role', 'alert');

        var msg = document.createElement('span');
        msg.className = 'toast__message';
        msg.textContent = message;

        var close = document.createElement('button');
        close.className = 'toast__close';
        close.setAttribute('aria-label', 'Close notification');
        close.innerHTML = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
        function dismiss() {
            if (toast.parentNode) toast.parentNode.removeChild(toast);
        }
        close.addEventListener('click', dismiss);

        toast.appendChild(msg);
        toast.appendChild(close);
        container.appendChild(toast);

        setTimeout(dismiss, 6000);
    }

    // -------------------------------------------------------------------------
    // Expose public API
    // -------------------------------------------------------------------------
    window.DaemsAdmin = { showToast: showToast };

    // -------------------------------------------------------------------------
    // Init
    // -------------------------------------------------------------------------
    document.addEventListener('DOMContentLoaded', function () {
        initSidebar();
        initThemeToggle();
        initUserDropdown();
        initCommandPalette();
    });

})();
