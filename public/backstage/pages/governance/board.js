/* Backstage Governance — Hallitus (Board) page logic */
(async () => {
  'use strict';

  const rosterSection    = document.getElementById('board-roster');
  const bootstrapSection = document.getElementById('board-bootstrap');

  let data;
  try {
    const res = await fetch('/api/backstage/governance/board', { credentials: 'same-origin' });
    data = await res.json().catch(() => null);
  } catch (_) {
    data = null;
  }

  if (data && data.board) {
    rosterSection.hidden = false;
    renderRoster(data.members || []);
  } else {
    bootstrapSection.hidden = false;
    wireBootstrap();
  }

  // ---------------------------------------------------------------------------

  function renderRoster(members) {
    const list = document.getElementById('board-cards');
    if (members.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'board-empty';
      empty.textContent = 'Hallituksessa ei ole jäseniä.';
      list.appendChild(empty);
      return;
    }
    for (const m of members) {
      const card = document.createElement('div');
      card.className = 'board-card';
      card.dataset.role = m.role;

      const ends = m.term_ends_at ? new Date(m.term_ends_at) : null;
      const now  = new Date();
      const days = ends ? Math.round((ends - now) / 86_400_000) : null;

      const roleLabel = m.role === 'chair' ? 'Puheenjohtaja' : 'Jäsen';
      const startDate = m.term_started_at ? m.term_started_at.slice(0, 10) : '—';
      const endDate   = m.term_ends_at    ? m.term_ends_at.slice(0, 10)    : '—';

      let daysHtml = '';
      if (days !== null && days > 0) {
        daysHtml = `<span class="board-card__days">${days} päivää jäljellä</span>`;
      } else if (days !== null && days <= 0) {
        daysHtml = `<span class="board-card__days board-card__days--expired">Toimikausi päättynyt</span>`;
      }

      let endedHtml = '';
      if (m.term_ended_at) {
        const reason = m.term_ended_reason ? ` (${m.term_ended_reason})` : '';
        endedHtml = `<div class="board-card__ended">Päättynyt ${m.term_ended_at.slice(0, 10)}${reason}</div>`;
      }

      card.innerHTML = `
        <div class="board-card__role">${roleLabel}</div>
        <div class="board-card__user" title="${m.user_id}">${m.user_id.slice(0, 8)}&hellip;</div>
        <div class="board-card__term">
          ${startDate} &ndash; ${endDate}
          ${daysHtml}
        </div>
        ${endedHtml}
      `;
      list.appendChild(card);
    }
  }

  function wireBootstrap() {
    const openBtn = document.getElementById('open-bootstrap');
    const modal   = document.getElementById('bootstrap-modal');
    const form    = document.getElementById('bootstrap-form');
    const addBtn  = document.getElementById('add-row');
    const cancelBtn = document.getElementById('cancel-bootstrap');

    openBtn.addEventListener('click', () => {
      modal.showModal();
      // Add one empty row if the list is empty
      const rows = document.querySelectorAll('.bootstrap-row');
      if (rows.length === 0) addRow();
    });

    cancelBtn.addEventListener('click', () => modal.close());

    addBtn.addEventListener('click', () => addRow());

    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const rowEls  = [...document.querySelectorAll('.bootstrap-row')];
      const members = rowEls.map(r => ({
        user_id:         r.querySelector('[name=user_id]').value.trim(),
        role:            r.querySelector('[name=role]').value,
        term_started_at: r.querySelector('[name=term_started_at]').value,
        term_ends_at:    r.querySelector('[name=term_ends_at]').value,
      }));

      const submitBtn = form.querySelector('[type=submit]');
      submitBtn.disabled = true;

      try {
        const r = await fetch('/api/backstage/governance/board/bootstrap', {
          method:      'POST',
          credentials: 'same-origin',
          headers:     { 'Content-Type': 'application/json' },
          body:        JSON.stringify({ members }),
        });
        if (r.ok) {
          location.reload();
          return;
        }
        const err = await r.json().catch(() => ({}));
        alert(err.error || ('Virhe: HTTP ' + r.status));
      } catch (ex) {
        alert('Verkkovirhe: ' + ex.message);
      } finally {
        submitBtn.disabled = false;
      }
    });
  }

  function addRow() {
    const wrap = document.getElementById('bootstrap-rows');
    const div  = document.createElement('div');
    div.className = 'bootstrap-row';
    div.innerHTML = `
      <input name="user_id" placeholder="user_id (UUID)" required>
      <select name="role">
        <option value="chair">Puheenjohtaja</option>
        <option value="member" selected>Jäsen</option>
      </select>
      <input name="term_started_at" type="date" required>
      <input name="term_ends_at"    type="date" required>
      <button type="button" class="bootstrap-row__remove btn btn--ghost btn--sm" aria-label="Poista rivi">&times;</button>
    `;
    div.querySelector('.bootstrap-row__remove').addEventListener('click', () => div.remove());
    wrap.appendChild(div);
    div.querySelector('[name=user_id]').focus();
  }
})();
