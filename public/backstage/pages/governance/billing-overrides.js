(() => {
    const script = document.currentScript;
    const I18N = {
        revokeConfirm: script?.dataset.revokeConfirm ?? 'Revoke this discount?',
        revokeButton:  script?.dataset.revokeButton  ?? 'Revoke',
        revokedBadge:  script?.dataset.revokedBadge  ?? 'Revoked',
        emptyText:     script?.dataset.emptyText     ?? 'No discounts.',
    };

    document.addEventListener('DOMContentLoaded', () => {
        const table = document.querySelector('.billing-overrides__list');
        if (table) loadOverrides(table);

        const newBtn = document.getElementById('new-override-btn');
        const dialog = document.getElementById('new-override-dialog');
        const form = document.getElementById('new-override-form');
        const cancelBtn = dialog?.querySelector('[data-action="cancel"]');
        const toggle = document.getElementById('active-only-toggle');

        if (newBtn && dialog) newBtn.addEventListener('click', () => dialog.showModal());
        if (cancelBtn && dialog) cancelBtn.addEventListener('click', () => dialog.close());
        if (form) form.addEventListener('submit', (e) => onSubmitNewOverride(e, dialog));
        if (toggle) toggle.addEventListener('change', () => {
            const url = new URL(window.location.href);
            url.searchParams.set('active_only', toggle.checked ? '1' : '0');
            window.location.href = url.toString();
        });
    });

    async function loadOverrides(table) {
        const activeOnly = table.dataset.activeOnly === '1';
        const qs = activeOnly ? '?active_only=1' : '';
        const resp = await fetch(`/api/backstage/governance/billing/overrides${qs}`, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        const tbody = table.querySelector('tbody');
        if (!resp.ok) {
            tbody.innerHTML = `<tr><td colspan="6">HTTP ${resp.status}</td></tr>`;
            return;
        }
        const data = await resp.json();
        if (!data.rows || data.rows.length === 0) {
            tbody.innerHTML = `<tr><td colspan="6">${escapeHtml(I18N.emptyText)}</td></tr>`;
            return;
        }
        tbody.innerHTML = data.rows.map(r => renderRow(r)).join('');

        tbody.querySelectorAll('[data-action="revoke"]').forEach(btn => {
            btn.addEventListener('click', () => onRevoke(btn.dataset.id));
        });
    }

    function renderRow(r) {
        const window = `${escapeHtml(r.valid_from)} – ${r.valid_until ? escapeHtml(r.valid_until) : '∞'}`;
        const amount = (r.override_amount_cents / 100).toFixed(2);
        const revoked = r.revoked_at !== null;
        const actions = revoked
            ? `<span class="badge badge--muted">${escapeHtml(I18N.revokedBadge)}</span>`
            : `<button type="button" class="btn btn--ghost btn--sm" data-action="revoke" data-id="${escapeHtml(r.id)}">${escapeHtml(I18N.revokeButton)}</button>`;
        const userLabel = r.user_id.slice(0, 8) + '…';
        return `
            <tr data-revoked="${revoked ? '1' : '0'}">
                <td><a href="/members/${encodeURIComponent(r.user_id)}">${escapeHtml(userLabel)}</a></td>
                <td>${escapeHtml(r.fee_type)}</td>
                <td>${amount} €</td>
                <td>${window}</td>
                <td>${escapeHtml(r.reason)}</td>
                <td>${actions}</td>
            </tr>
        `;
    }

    async function onSubmitNewOverride(e, dialog) {
        e.preventDefault();
        const form = e.currentTarget;
        const fd = new FormData(form);
        const amountEuros = parseFloat(String(fd.get('override_amount_euro') ?? '0'));
        const payload = {
            user_id:               String(fd.get('user_id') ?? ''),
            fee_type:              String(fd.get('fee_type') ?? 'BASIC'),
            override_amount_cents: Math.round(amountEuros * 100),
            valid_from:            String(fd.get('valid_from') ?? ''),
            valid_until:           String(fd.get('valid_until') ?? '') || null,
            reason:                String(fd.get('reason') ?? ''),
            decision_id:           String(fd.get('decision_id') ?? '') || null,
        };
        const resp = await fetch('/api/backstage/governance/billing/overrides', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            alert(`HTTP ${resp.status}: ${err.error || ''}`);
            return;
        }
        dialog?.close();
        window.location.reload();
    }

    async function onRevoke(id) {
        if (!confirm(I18N.revokeConfirm)) return;
        const resp = await fetch(`/api/backstage/governance/billing/overrides/${encodeURIComponent(id)}/revoke`, {
            method: 'POST',
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            alert(`HTTP ${resp.status}: ${err.error || ''}`);
            return;
        }
        window.location.reload();
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
})();
