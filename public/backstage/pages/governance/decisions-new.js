/* Backstage Governance — Uusi päätös wizard logic */
(() => {
  'use strict';

  const typeSel      = document.getElementById('decision-type');
  const fieldsHost   = document.getElementById('type-fields');
  const meetingWrap  = document.getElementById('meeting-ref-wrap');
  const form         = document.getElementById('propose-form');

  // Types that are always async-unanimous — meeting_reference not required/shown.
  const ASYNC_ONLY = new Set(['approve_basic', 'invite_full']);

  const fieldsByType = {
    approve_basic: () => `
      <label class="decisions-form__label">
        Hakemus-id
        <input name="application_id" class="form-control" required placeholder="UUID">
      </label>`,

    invite_full: () => `
      <label class="decisions-form__label">
        Käyttäjä-id
        <input name="user_id" class="form-control" required placeholder="UUID">
      </label>
      <label class="decisions-form__label">
        Perustelu
        <textarea name="reason" class="form-control" rows="2" required></textarea>
      </label>`,

    award_subtier: () => `
      <label class="decisions-form__label">
        Käyttäjä-id
        <input name="user_id" class="form-control" required placeholder="UUID">
      </label>
      <label class="decisions-form__label">
        Sub-tier slug
        <input name="sub_tier_slug" class="form-control" required placeholder="esim. hopea">
      </label>
      <label class="decisions-form__label">
        Perustelu
        <textarea name="reason" class="form-control" rows="2" required></textarea>
      </label>`,

    revoke_subtier: () => `
      <label class="decisions-form__label">
        Käyttäjä-id
        <input name="user_id" class="form-control" required placeholder="UUID">
      </label>
      <label class="decisions-form__label">
        Perustelu
        <textarea name="reason" class="form-control" rows="2" required></textarea>
      </label>`,

    subtier_crud: () => `
      <label class="decisions-form__label">
        Operaatio
        <select name="operation" class="form-control">
          <option value="create">Luo uusi</option>
          <option value="update">Muokkaa</option>
          <option value="delete">Poista</option>
        </select>
      </label>
      <label class="decisions-form__label">
        Slug
        <input name="sub_tier_slug" class="form-control" required placeholder="esim. hopea">
      </label>
      <label class="decisions-form__label">
        Nimi
        <input name="name" class="form-control" placeholder="esim. Hopea">
      </label>
      <label class="decisions-form__label">
        Rank order
        <input name="rank_order" class="form-control" type="number" min="1" placeholder="1">
      </label>
      <label class="decisions-form__label">
        Applies to
        <select name="applies_to" class="form-control">
          <option value="SUPPORTING">Kannattava</option>
          <option value="BASIC" selected>Perus</option>
        </select>
      </label>`,

    remove_board_member: () => `
      <label class="decisions-form__label">
        Hallituksen jäsen-id
        <input name="board_member_id" class="form-control" required placeholder="UUID">
      </label>
      <label class="decisions-form__label">
        Perustelu
        <textarea name="reason" class="form-control" rows="2" required></textarea>
      </label>`,

    delegate_authority: () => `
      <label class="decisions-form__label">
        Delegoitava päätöstyyppi
        <select name="decision_type" class="form-control">
          <option value="approve_basic">approve_basic</option>
          <option value="invite_full">invite_full</option>
          <option value="award_subtier">award_subtier</option>
        </select>
      </label>
      <input type="hidden" name="delegated_to_role" value="admin">`,

    revoke_delegation: () => `
      <label class="decisions-form__label">
        Delegoinnin id
        <input name="delegation_id" class="form-control" required placeholder="UUID">
      </label>
      <label class="decisions-form__label">
        Perustelu
        <textarea name="reason" class="form-control" rows="2" required></textarea>
      </label>`,
  };

  function render() {
    const t = typeSel.value;
    fieldsHost.innerHTML = (fieldsByType[t] || (() => ''))();
    meetingWrap.style.display = ASYNC_ONLY.has(t) ? 'none' : '';
  }

  typeSel.addEventListener('change', render);
  render();

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const t   = typeSel.value;
    const fd  = new FormData(form);
    const raw = Object.fromEntries(fd.entries());

    // Coerce rank_order to int when present and non-empty.
    if (raw.rank_order !== undefined && raw.rank_order !== '') {
      raw.rank_order = parseInt(raw.rank_order, 10);
    } else {
      delete raw.rank_order;
    }

    // Remove decision_type from root payload — it's the URL segment.
    delete raw.decision_type;

    const submitBtn = form.querySelector('[type=submit]');
    submitBtn.disabled = true;

    try {
      const endpoint = '/api/backstage/governance/decisions/' + t.replace(/_/g, '-');
      const r = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(raw),
      });

      if (r.ok) {
        const body = await r.json().catch(() => ({}));
        const id   = body.decision_id;
        if (id) {
          location.href = '/backstage/governance/decisions/detail?id=' + encodeURIComponent(id);
        } else {
          location.href = '/backstage/governance/decisions';
        }
      } else {
        const err = await r.json().catch(() => ({}));
        alert(err.error || ('Virhe: HTTP ' + r.status));
        submitBtn.disabled = false;
      }
    } catch (ex) {
      alert('Verkkovirhe: ' + ex.message);
      submitBtn.disabled = false;
    }
  });
})();
