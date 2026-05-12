(async () => {
  const tbody = document.querySelector('#delegations-tbody');
  const emptyEl = document.querySelector('#delegations-empty');

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  const r = await fetch('/api/backstage/governance/delegations', { credentials: 'same-origin' });
  if (!r.ok) {
    tbody.innerHTML = `<tr><td colspan="5">Virhe: HTTP ${r.status}</td></tr>`;
    return;
  }
  const data = await r.json();
  const rows = data.data || [];

  if (rows.length === 0) {
    emptyEl.hidden = false;
    return;
  }
  emptyEl.hidden = true;

  for (const d of rows) {
    const tr = document.createElement('tr');
    const srcShort = esc(String(d.source_decision_id ?? '').slice(0, 8));
    tr.innerHTML = `
      <td>${esc(d.decision_type)}</td>
      <td>${esc(d.delegated_to_role)}</td>
      <td>${d.valid_from ? esc(d.valid_from.slice(0, 10)) : '—'}</td>
      <td><a href="/backstage/governance/decisions/detail?id=${encodeURIComponent(d.source_decision_id)}">${srcShort}…</a></td>
      <td><button class="btn-secondary btn-sm" data-revoke="${esc(d.id)}">Ehdota peruutusta</button></td>
    `;
    tbody.appendChild(tr);
  }

  tbody.addEventListener('click', async (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    const delegId = target.getAttribute('data-revoke');
    if (!delegId) return;

    const reason = prompt('Anna perustelu peruutukselle:');
    if (!reason || !reason.trim()) return;
    const mref = prompt('Kokousviite (synkroninen päätös vaatii):');
    if (!mref || !mref.trim()) return;

    const r2 = await fetch('/api/backstage/governance/decisions/revoke-delegation', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify({
        delegation_id: delegId,
        reason: reason.trim(),
        meeting_reference: mref.trim(),
        vote_visibility: 'visible',
      }),
    });

    if (r2.ok) {
      const body = await r2.json();
      if (body.decision_id) {
        location.href = '/backstage/governance/decisions/detail?id=' + encodeURIComponent(body.decision_id);
      } else {
        location.reload();
      }
    } else {
      alert('Virhe: HTTP ' + r2.status);
    }
  });
})();
