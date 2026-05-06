/**
 * Notifications KPI strip — fetches /api/backstage/notifications.php?op=stats and
 * populates KPI values + sparklines on the notifications page.
 */
(function () {
  'use strict';

  if (!document.querySelector('.kpis-grid .kpi-card[data-kpi="pending_you"]')) return;

  var KPI_COLORS = {
    pending_you:       '#d97706',
    pending_all:       '#64748b',
    cleared_30d:       '#16a34a',
    oldest_pending_d:  '#dc2626',
  };

  // Per-card series labels surfaced in the sparkline tooltip.
  var KPI_NAMES = {
    pending_you:       'Pending (you)',
    pending_all:       'Pending (all)',
    cleared_30d:       'Cleared (30d)',
    oldest_pending_d:  'Oldest pending (d)',
  };

  var KPI_SUFFIX = { oldest_pending_d: 'd' };

  fetch('/api/backstage/notifications.php?op=stats')
    .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); })
    .then(function (j) { render(j && j.data ? j.data : null); })
    .catch(function (e) { console.error('notifications stats failed', e); });

  function render(data) {
    if (!data) return;
    document.querySelectorAll('.kpi-card').forEach(function (c) { c.classList.remove('is-loading'); });

    Object.keys(KPI_COLORS).forEach(function (id) {
      setKpi(id, data[id], KPI_SUFFIX[id] || '');
      initSpark(id, data[id] && data[id].sparkline);
    });
  }

  function setKpi(id, payload, suffix) {
    var el = document.querySelector('.kpi-card[data-kpi="' + id + '"] .kpi-card__value');
    if (el && payload) el.textContent = String(payload.value) + suffix;
  }

  function initSpark(id, points) {
    var el = document.getElementById('spark-' + id);
    if (el && window.Sparkline) window.Sparkline.init(el, points || [], KPI_COLORS[id], KPI_NAMES[id]);
  }
})();
