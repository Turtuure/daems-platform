(() => {
    const script = document.currentScript;
    const I18N = {
        empty:        script?.dataset.emptyText    ?? 'No invoices.',
        markPaid:     script?.dataset.actionMarkPaid ?? 'Mark paid',
        waive:        script?.dataset.actionWaive    ?? 'Waive',
        reduce:       script?.dataset.actionReduce   ?? 'Reduce',
        audit:        script?.dataset.actionAudit    ?? 'History',
        statuses: {
            PENDING:  script?.dataset.statusPending  ?? 'Pending',
            PAID:     script?.dataset.statusPaid     ?? 'Paid',
            OVERDUE:  script?.dataset.statusOverdue  ?? 'Overdue',
            WAIVED:   script?.dataset.statusWaived   ?? 'Waived',
            REDUCED:  script?.dataset.statusReduced  ?? 'Reduced',
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        const table = document.querySelector('.billing-invoices__list');
        if (table) loadInvoices(table);

        bindCancelButtons();
        bindActionForms();
    });

    async function loadInvoices(table) {
        const qs = new URLSearchParams();
        if (table.dataset.year)     qs.set('year',     table.dataset.year);
        if (table.dataset.status)   qs.set('status',   table.dataset.status);
        if (table.dataset.feeType)  qs.set('fee_type', table.dataset.feeType);

        const resp = await fetch(`/api/backstage/governance/billing/invoices?${qs}`, {
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
            tbody.innerHTML = `<tr><td colspan="6">${escapeHtml(I18N.empty)}</td></tr>`;
            return;
        }
        tbody.innerHTML = data.rows.map(r => renderRow(r)).join('');
        tbody.querySelectorAll('[data-action]').forEach(btn => {
            btn.addEventListener('click', () => openDialog(btn.dataset.action, btn.dataset.id));
        });
    }

    function renderRow(r) {
        const amount = (r.amount_cents / 100).toFixed(2);
        const orig = r.original_amount_cents !== null
            ? `<small>(orig ${(r.original_amount_cents / 100).toFixed(2)} €)</small>`
            : '';
        const open = r.status === 'PENDING' || r.status === 'OVERDUE' || r.status === 'REDUCED';
        const actionButtons = (open ? `
            <button type="button" class="btn btn--sm" data-action="mark-paid" data-id="${escapeHtml(r.id)}">${escapeHtml(I18N.markPaid)}</button>
            <button type="button" class="btn btn--sm" data-action="waive"     data-id="${escapeHtml(r.id)}">${escapeHtml(I18N.waive)}</button>
            <button type="button" class="btn btn--sm" data-action="reduce"    data-id="${escapeHtml(r.id)}">${escapeHtml(I18N.reduce)}</button>
        ` : '') + `
            <button type="button" class="btn btn--ghost btn--sm" data-action="audit" data-id="${escapeHtml(r.id)}">${escapeHtml(I18N.audit)}</button>
        `;
        const label = I18N.statuses[r.status] ?? r.status;
        return `
            <tr data-status="${escapeHtml(r.status)}">
                <td><a href="/members/${encodeURIComponent(r.user_id)}"><code>${escapeHtml(r.user_id.slice(0, 8))}…</code></a></td>
                <td>${escapeHtml(r.fee_type)}</td>
                <td>${amount} € ${orig}</td>
                <td>${escapeHtml(r.due_date)}</td>
                <td><span class="status-badge status-${escapeHtml(r.status)}">${escapeHtml(label)}</span></td>
                <td>${actionButtons}</td>
            </tr>
        `;
    }

    function openDialog(action, invoiceId) {
        if (action === 'audit') return loadAndShowAudit(invoiceId);
        const dialogIdMap = { 'mark-paid': 'mark-paid-dialog', 'waive': 'waive-dialog', 'reduce': 'reduce-dialog' };
        const dialog = document.getElementById(dialogIdMap[action]);
        if (!dialog) return;
        dialog.querySelector('input[name="invoice_id"]').value = invoiceId;
        dialog.showModal();
    }

    async function loadAndShowAudit(invoiceId) {
        const resp = await fetch(`/api/backstage/governance/billing/invoices/${encodeURIComponent(invoiceId)}/audit`);
        const list = document.getElementById('audit-list');
        if (!resp.ok) {
            list.innerHTML = `<li>HTTP ${resp.status}</li>`;
        } else {
            const data = await resp.json();
            list.innerHTML = data.rows.map(r => `
                <li>
                    <strong>${escapeHtml(r.action)}</strong>
                    — ${new Date(r.performed_at).toLocaleString()}
                    ${r.performed_by ? `by <code>${escapeHtml(r.performed_by.slice(0, 8))}…</code>` : '(cron)'}
                    ${r.payload ? `<pre>${escapeHtml(JSON.stringify(r.payload, null, 2))}</pre>` : ''}
                </li>
            `).join('');
        }
        document.getElementById('audit-dialog').showModal();
    }

    function bindCancelButtons() {
        document.querySelectorAll('[data-action="cancel"]').forEach(btn => {
            btn.addEventListener('click', () => btn.closest('dialog')?.close());
        });
        document.querySelectorAll('[data-action="close-audit"]').forEach(btn => {
            btn.addEventListener('click', () => btn.closest('dialog')?.close());
        });
    }

    function bindActionForms() {
        document.getElementById('mark-paid-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.currentTarget;
            const fd = new FormData(form);
            const amountEuros = parseFloat(String(fd.get('amount_euro') ?? '0'));
            const id = String(fd.get('invoice_id') ?? '');
            const payload = {
                amount_cents: Math.round(amountEuros * 100),
                paid_at:      new Date(String(fd.get('paid_at') ?? '')).toISOString(),
                method:       String(fd.get('method') ?? ''),
                reference:    String(fd.get('reference') ?? ''),
            };
            await postJson(`/api/backstage/governance/billing/invoices/${encodeURIComponent(id)}/mark-paid`, payload);
        });

        document.getElementById('waive-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.currentTarget;
            const fd = new FormData(form);
            const id = String(fd.get('invoice_id') ?? '');
            await postJson(`/api/backstage/governance/billing/invoices/${encodeURIComponent(id)}/waive`, {
                reason: String(fd.get('reason') ?? ''),
            });
        });

        document.getElementById('reduce-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const form = e.currentTarget;
            const fd = new FormData(form);
            const amountEuros = parseFloat(String(fd.get('amount_euro') ?? '0'));
            const id = String(fd.get('invoice_id') ?? '');
            await postJson(`/api/backstage/governance/billing/invoices/${encodeURIComponent(id)}/reduce`, {
                amount_cents: Math.round(amountEuros * 100),
                reason:       String(fd.get('reason') ?? ''),
            });
        });
    }

    async function postJson(url, payload) {
        const resp = await fetch(url, {
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
        window.location.reload();
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
})();
