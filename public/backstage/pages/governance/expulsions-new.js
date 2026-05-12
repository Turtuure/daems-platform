(() => {
  const form = document.querySelector('#initiate-form');
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;

    const payload = Object.fromEntries(new FormData(form).entries());
    const r = await fetch('/api/backstage/governance/expulsions', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    if (r.ok) {
      const body = await r.json();
      const id = body.expulsion_id;
      if (id) {
        location.href = '/backstage/governance/expulsions/detail?id=' + encodeURIComponent(id);
      } else {
        location.href = '/backstage/governance/expulsions';
      }
    } else {
      btn.disabled = false;
      const err = await r.json().catch(() => ({}));
      alert(err.error || ('Virhe: HTTP ' + r.status));
    }
  });
})();
