/* Backstage Governance — Päätöksen tiedot (detail) page logic */
(async () => {
  'use strict';

  const id = new URLSearchParams(location.search).get('id');

  const view     = document.getElementById('decision-view');
  const notFound = document.getElementById('decision-not-found');

  if (!id) {
    notFound.hidden = false;
    return;
  }

  let data;
  try {
    const r = await fetch('/api/backstage/governance/decisions/' + encodeURIComponent(id), {
      credentials: 'same-origin',
    });
    if (!r.ok) {
      notFound.hidden = false;
      return;
    }
    data = await r.json().catch(() => null);
  } catch (_) {
    notFound.hidden = false;
    return;
  }

  if (!data) {
    notFound.hidden = false;
    return;
  }

  const d     = data.decision || {};
  const tally = data.tally    || { yes: 0, no: 0, abstain: 0 };
  const votes = data.votes    || [];

  // Update heading to include type
  const heading = document.getElementById('decision-heading');
  if (heading && d.decision_type) heading.textContent = d.decision_type;

  setText('decision-type', d.decision_type || '');
  document.getElementById('decision-status').innerHTML =
    `<span class="badge badge--${esc(d.status)}">${esc(d.status)}</span>`;
  setText('decision-th-mode', `${d.threshold} / ${d.mode}`);
  setText('decision-expires', d.expires_at ? d.expires_at.slice(0, 16).replace('T', ' ') : '—');
  setText('decision-meeting', d.meeting_reference || '—');
  setText('decision-via-delegation', d.via_delegation ? 'Kyllä' : 'Ei');

  document.getElementById('decision-payload').textContent =
    JSON.stringify(d.payload || {}, null, 2);

  document.getElementById('decision-tally').textContent =
    `${tally.yes} kyllä / ${tally.no} ei / ${tally.abstain} tyhjä`;

  const list = document.getElementById('decision-votes');
  if (votes.length === 0) {
    const li = document.createElement('li');
    li.className = 'decision-votes-list__empty';
    li.textContent = 'Ei äänestystietoja (anonyymi tai äänestämätön).';
    list.appendChild(li);
  } else {
    for (const v of votes) {
      const li = document.createElement('li');
      li.textContent =
        `${String(v.board_member_id).slice(0, 8)}…: ${v.vote} @ ${String(v.cast_at).slice(0, 16).replace('T', ' ')}`;
      list.appendChild(li);
    }
  }

  view.hidden = false;

  // Vote panel — shown when status=pending
  if (d.status === 'pending') {
    document.getElementById('vote-panel').hidden = false;
    document.getElementById('vote-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const vote      = (new FormData(e.target)).get('vote');
      const submitBtn = e.target.querySelector('[type=submit]');
      submitBtn.disabled = true;
      try {
        const r2 = await fetch(
          '/api/backstage/governance/decisions/' + encodeURIComponent(id) + '/vote',
          {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ vote }),
          },
        );
        if (r2.ok) {
          location.reload();
        } else {
          const err = await r2.json().catch(() => ({}));
          alert(err.error || ('Virhe: HTTP ' + r2.status));
          submitBtn.disabled = false;
        }
      } catch (ex) {
        alert('Verkkovirhe: ' + ex.message);
        submitBtn.disabled = false;
      }
    });

    document.getElementById('withdraw-panel').hidden = false;
    document.getElementById('withdraw-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const reason    = (new FormData(e.target)).get('withdrawal_reason');
      const submitBtn = e.target.querySelector('[type=submit]');
      submitBtn.disabled = true;
      try {
        const r2 = await fetch(
          '/api/backstage/governance/decisions/' + encodeURIComponent(id) + '/withdraw',
          {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify({ withdrawal_reason: reason }),
          },
        );
        if (r2.ok) {
          location.reload();
        } else {
          const err = await r2.json().catch(() => ({}));
          alert(err.error || ('Virhe: HTTP ' + r2.status));
          submitBtn.disabled = false;
        }
      } catch (ex) {
        alert('Verkkovirhe: ' + ex.message);
        submitBtn.disabled = false;
      }
    });
  }

  // ---------------------------------------------------------------------------

  function setText(id, value) {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  }

  function esc(s) {
    const d = document.createElement('div');
    d.textContent = String(s ?? '');
    return d.innerHTML;
  }
})();
