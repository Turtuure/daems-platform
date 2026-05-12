/**
 * Governance integration for the application detail page (members module).
 *
 * Adds two governance-path buttons next to the existing GSA Approve/Reject form:
 *
 *   "Ehdota hallitukselle" (.js-gov-propose-approve)
 *     - Fetches GET /api/backstage/governance/delegations to check whether
 *       approve_basic has been delegated to the admin role.
 *     - If delegated → calls POST /api/backstage/governance/decisions/approve-basic
 *       silently (backend auto-uses the delegate path).
 *     - If NOT delegated → prompts for vote_visibility and submits the same endpoint
 *       (backend creates a Pending board decision).
 *
 *   "Pakkohyväksy (GSA)" (.js-gov-force-approve)
 *     - Asks for a reason (min 10 chars).
 *     - Calls POST /api/backstage/governance/gsa-overrides/approve-basic.
 *
 * Both buttons read app ID and kind from the wrapping
 * .members-detail__governance-actions[data-governance-app-id][data-governance-app-kind].
 *
 * Deferred: member-detail page board-action buttons (full upgrade, sub-tier proposal).
 * These require a dedicated member-detail view and eligibility-checking logic that
 * are out of scope for 0.6b.
 */
(() => {
  const wrap = document.querySelector('.members-detail__governance-actions');
  if (!wrap) return;

  const appId   = wrap.getAttribute('data-governance-app-id')   || '';
  const appKind = wrap.getAttribute('data-governance-app-kind') || 'member';

  const proposeBtn = wrap.querySelector('.js-gov-propose-approve');
  const forceBtn   = wrap.querySelector('.js-gov-force-approve');

  // ---------------------------------------------------------------------------
  // Helper: disable/re-enable a button while async work is in flight
  // ---------------------------------------------------------------------------
  const withSpinner = async (btn, label, fn) => {
    const orig = btn.textContent;
    btn.disabled = true;
    btn.textContent = label;
    try {
      await fn();
    } finally {
      btn.disabled = false;
      btn.textContent = orig;
    }
  };

  // ---------------------------------------------------------------------------
  // "Ehdota hallitukselle" — option B side-by-side flow
  // ---------------------------------------------------------------------------
  if (proposeBtn) {
    proposeBtn.addEventListener('click', () => {
      withSpinner(proposeBtn, 'Haetaan…', async () => {
        // Step 1: fetch delegation state
        let delegations = [];
        try {
          const dr = await fetch('/api/backstage/governance/delegations', {
            credentials: 'same-origin',
          });
          if (dr.ok) {
            const djson = await dr.json();
            delegations = Array.isArray(djson.data) ? djson.data : [];
          }
        } catch (_) {
          // Delegation check is best-effort; proceed to prompted path on failure
        }

        const isApproveBasicDelegatedToAdmin = delegations.some(
          (d) =>
            d.decision_type === 'approve_basic' &&
            d.delegated_to_role === 'admin',
        );

        // Step 2: build payload
        let voteVisibility = 'visible';
        if (!isApproveBasicDelegatedToAdmin) {
          const choice = prompt(
            'Päätösehdotus lähetetään hallitukselle.\n\nÄänestyksen näkyvyys:\n  • "visible"  — nimet näkyvät\n  • "anonymous" — anonyymi äänestys\n\nKirjoita "visible" tai "anonymous":',
            'visible',
          );
          if (!choice || !choice.trim()) return; // cancelled
          const trimmed = choice.trim().toLowerCase();
          if (trimmed !== 'visible' && trimmed !== 'anonymous') {
            alert('Virheellinen arvo. Kirjoita "visible" tai "anonymous".');
            return;
          }
          voteVisibility = trimmed;
        }

        const payload = {
          application_id:   appId,
          application_type: appKind,
          vote_visibility:  voteVisibility,
        };

        // Step 3: submit governance decision
        const r = await fetch(
          '/api/backstage/governance/decisions/approve-basic',
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
          },
        );

        if (r.ok) {
          const body = await r.json().catch(() => ({}));
          const decisionId = body.decision_id || null;
          if (decisionId) {
            location.href =
              '/backstage/governance/decisions/detail?id=' +
              encodeURIComponent(decisionId);
          } else {
            location.href = '/backstage/governance/decisions';
          }
        } else {
          const err = await r.json().catch(() => ({}));
          alert(
            'Virhe: ' + (err.error || ('HTTP ' + r.status)),
          );
        }
      });
    });
  }

  // ---------------------------------------------------------------------------
  // "Pakkohyväksy (GSA)" — force-approve override
  // ---------------------------------------------------------------------------
  if (forceBtn) {
    forceBtn.addEventListener('click', () => {
      withSpinner(forceBtn, 'Lähetetään…', async () => {
        const reason = prompt(
          'Anna GSA-ylikuittauksen perustelu (vähintään 10 merkkiä):',
        );
        if (!reason || reason.trim().length < 10) {
          if (reason !== null) {
            alert('Perustelu on liian lyhyt (vähintään 10 merkkiä).');
          }
          return;
        }

        const payload = {
          application_id:   appId,
          application_type: appKind,
          reason:           reason.trim(),
        };

        const r = await fetch(
          '/api/backstage/governance/gsa-overrides/approve-basic',
          {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload),
          },
        );

        if (r.ok) {
          const body = await r.json().catch(() => ({}));
          const overrideId = body.override_id || null;
          if (overrideId) {
            location.href =
              '/backstage/governance/decisions/detail?id=' +
              encodeURIComponent(overrideId);
          } else {
            location.href = '/backstage/governance/decisions';
          }
        } else {
          const err = await r.json().catch(() => ({}));
          alert(
            'Virhe: ' + (err.error || ('HTTP ' + r.status)),
          );
        }
      });
    });
  }
})();
