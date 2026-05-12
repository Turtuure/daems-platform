(async () => {
  const id = new URLSearchParams(location.search).get('id');
  if (!id) {
    document.querySelector('#expulsion-not-found').hidden = false;
    return;
  }

  const r = await fetch('/api/backstage/governance/expulsions/' + encodeURIComponent(id), { credentials: 'same-origin' });
  if (!r.ok) {
    document.querySelector('#expulsion-not-found').hidden = false;
    return;
  }

  const data = await r.json();
  const e = data.expulsion || {};

  document.querySelector('#expulsion-view').hidden = false;

  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

  document.querySelector('#exp-target').textContent    = String(e.target_user_id ?? '').slice(0, 8) + '…';
  document.querySelector('#exp-proposer').textContent  = String(e.proposed_by_user_id ?? '').slice(0, 8) + '…';
  document.querySelector('#exp-reason').textContent    = e.reason || '';
  document.querySelector('#exp-status').innerHTML      = `<span class="badge badge--${esc(e.status)}">${esc(e.status)}</span>`;
  document.querySelector('#exp-deadline').textContent  = e.hearing_deadline_at
    ? e.hearing_deadline_at.slice(0, 16).replace('T', ' ')
    : '—';
  document.querySelector('#exp-statement-received').textContent = e.statement_received_at
    ? e.statement_received_at.slice(0, 16).replace('T', ' ')
    : '—';
  document.querySelector('#exp-decision-link').innerHTML = e.decision_id
    ? `<a href="/backstage/governance/decisions/detail?id=${encodeURIComponent(e.decision_id)}">${esc(e.decision_id.slice(0, 8))}…</a>`
    : '—';
  document.querySelector('#exp-expelled-at').textContent = e.expelled_at
    ? e.expelled_at.slice(0, 16).replace('T', ' ')
    : '—';
  document.querySelector('#exp-appeal-filed').textContent = e.appeal_filed_at
    ? e.appeal_filed_at.slice(0, 16).replace('T', ' ')
    : '—';

  // Show existing statement text (read-only)
  if (e.statement_text) {
    document.querySelector('#statement-text-section').hidden = false;
    document.querySelector('#statement-text').textContent = e.statement_text;
  }

  // Show existing appeal text (read-only)
  if (e.appeal_text) {
    document.querySelector('#appeal-text-section').hidden = false;
    document.querySelector('#appeal-text').textContent = e.appeal_text;
  }

  const deadline = e.hearing_deadline_at ? new Date(e.hearing_deadline_at) : null;
  const now = new Date();

  // Statement form — status=hearing, no statement yet, deadline not elapsed
  if (e.status === 'hearing' && !e.statement_received_at && deadline && now <= deadline) {
    document.querySelector('#statement-panel').hidden = false;
    document.querySelector('#statement-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = ev.target.querySelector('button[type="submit"]');
      btn.disabled = true;
      const payload = Object.fromEntries(new FormData(ev.target).entries());
      const r2 = await fetch('/api/backstage/governance/expulsions/' + encodeURIComponent(id) + '/statement', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      if (r2.ok) {
        location.reload();
      } else {
        btn.disabled = false;
        alert('Virhe: HTTP ' + r2.status + ' — vain kohde voi lähettää vastineen.');
      }
    });
  }

  // Advance-to-vote — status=hearing and (deadline elapsed OR statement received)
  if (e.status === 'hearing' && ((deadline && now >= deadline) || e.statement_received_at)) {
    document.querySelector('#advance-panel').hidden = false;
    document.querySelector('#advance-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = ev.target.querySelector('button[type="submit"]');
      btn.disabled = true;
      const payload = Object.fromEntries(new FormData(ev.target).entries());
      const r2 = await fetch('/api/backstage/governance/expulsions/' + encodeURIComponent(id) + '/advance-to-vote', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      if (r2.ok) {
        const body = await r2.json();
        if (body.decision_id) {
          location.href = '/backstage/governance/decisions/detail?id=' + encodeURIComponent(body.decision_id);
        } else {
          location.reload();
        }
      } else {
        btn.disabled = false;
        alert('Virhe: HTTP ' + r2.status + ' — vain pj voi viedä äänestykseen.');
      }
    });
  }

  // Appeal form — status=expelled, no appeal yet
  if (e.status === 'expelled' && !e.appeal_filed_at) {
    document.querySelector('#appeal-panel').hidden = false;
    document.querySelector('#appeal-form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const btn = ev.target.querySelector('button[type="submit"]');
      btn.disabled = true;
      const payload = Object.fromEntries(new FormData(ev.target).entries());
      const r2 = await fetch('/api/backstage/governance/expulsions/' + encodeURIComponent(id) + '/appeal', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
      });
      if (r2.ok) {
        location.reload();
      } else {
        btn.disabled = false;
        alert('Virhe: HTTP ' + r2.status + ' — vain erotettu kohde voi tehdä valituksen.');
      }
    });
  }
})();
