/* Backstage Governance — Päätökset list page logic */
(async () => {
  'use strict';

  const tbody    = document.getElementById('decisions-tbody');
  const table    = document.getElementById('decisions-table');
  const emptyMsg = document.getElementById('decisions-list-state');
  const statusSel = document.getElementById('filter-status');
  const mineChk   = document.getElementById('filter-mine');

  async function load() {
    tbody.innerHTML = '';
    table.hidden    = false;
    emptyMsg.hidden = true;

    const url = new URL('/api/backstage/governance/decisions', location.origin);
    if (statusSel.value) url.searchParams.set('status', statusSel.value);
    if (mineChk.checked) url.searchParams.set('my_pending', '1');

    let data;
    try {
      const r = await fetch(url, { credentials: 'same-origin' });
      data = await r.json().catch(() => null);
    } catch (_) {
      data = null;
    }

    const items = (data && data.data) ? data.data : [];

    if (items.length === 0) {
      table.hidden    = true;
      emptyMsg.hidden = false;
      return;
    }

    for (const d of items) {
      const tally = d.tally || { yes: 0, no: 0, abstain: 0 };
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td>${escHtml(d.decision_type)}</td>
        <td>${escHtml(String(d.threshold))} (${escHtml(d.mode)})</td>
        <td class="decisions-tally">${tally.yes}<span class="decisions-tally__yes"> K</span> / ${tally.no}<span class="decisions-tally__no"> E</span> / ${tally.abstain}<span class="decisions-tally__abstain"> T</span></td>
        <td><span class="badge badge--${escHtml(d.status)}">${escHtml(d.status)}</span></td>
        <td>${d.expires_at ? escHtml(d.expires_at.slice(0, 10)) : '—'}</td>
        <td><a href="/backstage/governance/decisions/detail?id=${encodeURIComponent(d.id)}" class="btn btn--ghost btn--sm">Avaa</a></td>
      `;
      tbody.appendChild(tr);
    }
  }

  function escHtml(s) {
    const d = document.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  }

  statusSel.addEventListener('change', load);
  mineChk.addEventListener('change', load);
  load();
})();
