document.addEventListener('DOMContentLoaded', () => {
    const table = document.querySelector('.billing-table');
    if (table) loadBillingFees(table);

    const form = document.getElementById('billing-form');
    if (form) form.addEventListener('submit', onSubmitBillingFees);
});

async function loadBillingFees(table) {
    const year = table.dataset.year;
    const resp = await fetch(`/api/backstage/governance/billing/fee-schedules?year=${encodeURIComponent(year)}`, {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
    });
    const tbody = table.querySelector('tbody');
    if (!resp.ok) {
        tbody.innerHTML = `<tr><td colspan="5">HTTP ${resp.status}</td></tr>`;
        return;
    }
    const data = await resp.json();
    if (!data.rows || data.rows.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5">${escapeHtml(`Ei hinnastoa vuodelle ${data.year}.`)}</td></tr>`;
        return;
    }
    tbody.innerHTML = data.rows.map(r => `
        <tr data-status="${escapeHtml(r.status)}">
            <td>${escapeHtml(r.fee_type)}</td>
            <td>${(r.amount_cents / 100).toFixed(2)} ${escapeHtml(r.currency)}</td>
            <td>${escapeHtml(r.status)}</td>
            <td>${r.activated_at ? new Date(r.activated_at).toLocaleString('fi-FI') : '—'}</td>
            <td>${r.decision_id ? `<a href="/backstage/governance/decisions/detail?id=${encodeURIComponent(r.decision_id)}">${escapeHtml(r.decision_id.slice(0, 8))}…</a>` : '—'}</td>
        </tr>
    `).join('');
}

async function onSubmitBillingFees(e) {
    e.preventDefault();
    const form = e.currentTarget;
    const fd = new FormData(form);
    const payload = {
        year: parseInt(String(fd.get('year')), 10),
        fees: {
            SUPPORTING: parseInt(String(fd.get('supporting')), 10) * 100,
            BASIC:      parseInt(String(fd.get('basic')),      10) * 100,
            FULL:       parseInt(String(fd.get('full')),       10) * 100,
        },
    };
    const resp = await fetch('/api/backstage/governance/billing/fee-schedules', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });
    if (!resp.ok) {
        const err = await resp.json().catch(() => ({}));
        alert(`Tallennus epäonnistui: ${err.error || resp.status}`);
        return;
    }
    const body = await resp.json();
    if (body.decision_id) {
        window.location.href = `/backstage/governance/decisions/detail?id=${encodeURIComponent(body.decision_id)}`;
    } else {
        window.location.href = form.dataset.redirect || `/backstage/governance/billing?year=${payload.year}`;
    }
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}
