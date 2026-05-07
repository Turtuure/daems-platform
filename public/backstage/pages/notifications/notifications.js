(function () {
    'use strict';

    var list = document.getElementById('notif-list');
    if (!list) { return; }

    function toast(msg, kind) {
        if (window.DAEMS_TOASTS && typeof window.DAEMS_TOASTS.show === 'function') {
            window.DAEMS_TOASTS.show(msg, kind || 'info');
        }
    }

    function decrementSidebarBadge() {
        var badge = document.querySelector('.sidebar__item[href="/backstage/notifications"] .sidebar__badge--danger');
        if (!badge) { return; }
        var n = Math.max(0, parseInt(badge.textContent || '0', 10) - 1);
        if (n > 0) { badge.textContent = String(n); }
        else       { badge.remove(); }
    }

    list.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-role="dismiss"]');
        if (!btn) { return; }
        var card = btn.closest('.notif-card');
        if (!card) { return; }

        var id   = card.getAttribute('data-id');
        var type = card.getAttribute('data-type');

        btn.disabled = true;
        card.classList.add('is-dismissing');

        fetch('/api/backstage/dismiss', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ type: type, id: id }),
        }).then(function (r) {
            if (r.ok) {
                setTimeout(function () {
                    card.remove();
                    decrementSidebarBadge();
                    if (!list.querySelector('.notif-card')) {
                        list.outerHTML = '<div class="card"><div class="card__body notif-empty">' +
                            '<i class="bi bi-bell-slash" style="font-size:2rem;"></i>' +
                            '<p class="mt-2 mb-0">Kaikki käsitelty — ei ilmoituksia.</p>' +
                            '</div></div>';
                    }
                }, 220);
                toast('Hylätty', 'success');
            } else {
                card.classList.remove('is-dismissing');
                btn.disabled = false;
                toast('Virhe: ' + r.status, 'error');
            }
        }).catch(function () {
            card.classList.remove('is-dismissing');
            btn.disabled = false;
            toast('Verkkovirhe', 'error');
        });
    });
})();
