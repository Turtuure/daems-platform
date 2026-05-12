(async () => {
  const tbody = document.querySelector('#expulsions-tbody');
  const emptyEl = document.querySelector('#expulsions-empty');
  const statusSel = document.querySelector('#filter-status');

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  async function load() {
    const status = statusSel.value;
    const url = new URL('/api/backstage/governance/expulsions', location.origin);
    if (status) url.searchParams.set('status', status);

    const r = await fetch(url, { credentials: 'same-origin' });
    if (!r.ok) {
      tbody.innerHTML = `<tr><td colspan="5">Virhe: HTTP ${r.status}</td></tr>`;
      return;
    }
    const data = await r.json();
    const rows = data.data || [];

    tbody.innerHTML = '';
    if (rows.length === 0) {
      emptyEl.hidden = false;
      return;
    }
    emptyEl.hidden = true;

    for (const e of rows) {
      const tr = document.createElement('tr');
      const targetDisplay = String(e.target_user_id ?? '').slice(0, 8) + '…';
      const reasonDisplay = (e.reason || '').length > 60
        ? esc((e.reason || '').slice(0, 60)) + '…'
        : esc(e.reason || '');
      const deadline = e.hearing_deadline_at ? e.hearing_deadline_at.slice(0, 10) : '—';
      tr.innerHTML = `
        <td>${esc(targetDisplay)}</td>
        <td>${reasonDisplay}</td>
        <td><span class="badge badge--${esc(e.status)}">${esc(e.status)}</span></td>
        <td>${esc(deadline)}</td>
        <td><a href="/backstage/governance/expulsions/detail?id=${encodeURIComponent(e.id)}">Avaa</a></td>
      `;
      tbody.appendChild(tr);
    }
  }

  statusSel.addEventListener('change', load);
  load();
})();
