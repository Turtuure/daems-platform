(() => {
    const script = document.currentScript;
    const I18N = {
        high:           script?.dataset.confidenceHigh           ?? 'Sure',
        amountMismatch: script?.dataset.confidenceAmountMismatch ?? 'Amount differs',
        noMatch:        script?.dataset.confidenceNoMatch        ?? 'No match',
        alertSelect:    script?.dataset.alertSelect              ?? 'Select at least one match.',
        alertPreview:   script?.dataset.alertPreview             ?? 'Preview failed',
        alertConfirm:   script?.dataset.alertConfirm             ?? 'Confirmation failed',
    };
    let previewData = null;

    document.addEventListener('DOMContentLoaded', () => {
        const form = document.getElementById('import-form');
        const confirmBtn = document.getElementById('confirm-btn');
        const selectAll = document.getElementById('select-all');

        if (form) form.addEventListener('submit', onPreview);
        if (confirmBtn) confirmBtn.addEventListener('click', onConfirm);
        if (selectAll) selectAll.addEventListener('change', onSelectAll);
    });

    async function onPreview(e) {
        e.preventDefault();
        const form = e.currentTarget;
        const formData = new FormData(form);
        const resp = await fetch('/api/backstage/governance/billing/payments/import-csv', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            alert(`${I18N.alertPreview}: ${err.error ?? resp.status}`);
            return;
        }
        previewData = await resp.json();
        renderPreview(previewData);
    }

    function renderPreview(data) {
        document.getElementById('preview').hidden = false;
        document.getElementById('high-confidence-count').textContent = String(data.high_confidence_count ?? 0);
        const tbody = document.querySelector('.billing-import__preview tbody');
        tbody.innerHTML = (data.results ?? []).map((r, idx) => {
            const amount = (r.amount_cents / 100).toFixed(2);
            const matchedAmt = r.matched_amount_cents !== null && r.matched_amount_cents !== r.amount_cents
                ? `<small>(odotettu ${(r.matched_amount_cents / 100).toFixed(2)} €)</small>`
                : '';
            const reason = r.low_confidence_reason
                ? `<small class="muted">${escapeHtml(r.low_confidence_reason)}</small>`
                : '';
            const disabled = r.matched_invoice_id === null ? 'disabled' : '';
            const checked = r.confidence === 'high' ? 'checked' : '';
            return `
                <tr data-confidence="${escapeHtml(r.confidence)}">
                    <td><input type="checkbox" class="row-select" data-index="${idx}" ${checked} ${disabled}></td>
                    <td>${r.row_number}</td>
                    <td>${escapeHtml(r.payer_name ?? '')}</td>
                    <td>${escapeHtml(r.reference ?? '—')}</td>
                    <td>${amount} €</td>
                    <td>${r.matched_invoice_id ? escapeHtml(r.matched_invoice_id.slice(0, 8)) + '…' : '—'} ${matchedAmt}</td>
                    <td><span class="confidence confidence-${escapeHtml(r.confidence)}">${labelConfidence(r.confidence)}</span> ${reason}</td>
                </tr>
            `;
        }).join('');
    }

    function labelConfidence(c) {
        return ({ high: I18N.high, amount_mismatch: I18N.amountMismatch, no_match: I18N.noMatch })[c] ?? c;
    }

    function onSelectAll(e) {
        document.querySelectorAll('.row-select:not(:disabled)').forEach(cb => { cb.checked = e.target.checked; });
    }

    async function onConfirm() {
        if (!previewData) return;
        const selectedIndices = [...document.querySelectorAll('.row-select:checked')]
            .map(cb => parseInt(cb.dataset.index, 10))
            .filter(n => !Number.isNaN(n));

        const matches = selectedIndices.map(idx => {
            const r = previewData.results[idx];
            return {
                invoice_id:   r.matched_invoice_id,
                amount_cents: r.amount_cents,
                paid_at:      new Date(r.value_date + 'T12:00:00').toISOString(),
                reference:    r.reference ?? '',
            };
        }).filter(m => m.invoice_id);

        if (matches.length === 0) {
            alert(I18N.alertSelect);
            return;
        }
        const resp = await fetch('/api/backstage/governance/billing/payments/import-csv/confirm', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ matches }),
        });
        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            alert(`${I18N.alertConfirm}: ${err.error ?? resp.status}`);
            return;
        }
        const data = await resp.json();
        document.getElementById('preview').hidden = true;
        document.getElementById('results').hidden = false;
        document.getElementById('applied-count').textContent = String(data.applied?.length ?? 0);
        document.getElementById('error-count').textContent = String(data.errors?.length ?? 0);
        document.getElementById('results-details').textContent = JSON.stringify(data, null, 2);
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
    }
})();
