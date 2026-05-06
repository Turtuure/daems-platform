/**
 * Daems Backstage Design System — JS controllers
 *
 * Exposes:
 *   window.SlidePanel.open({ title, body, footer, onClose })
 *   window.SlidePanel.close()
 *   window.ConfirmDialog.open({ title, body, danger, confirmLabel, cancelLabel }) -> Promise<boolean>
 *   window.Sparkline.init(el, dataPoints, color, name?)  // dataPoints: [{date, value}, ...]
 *
 * No transform-based animations (per design system rule).
 *   - SlidePanel: translateX (one-axis, geometric layout, not a hover effect)
 *   - ConfirmDialog: opacity + scale on the dialog itself (open animation, not hover)
 *   - No tile/row/card has a hover transform anywhere in this file.
 */
(function () {
  'use strict';

  // ── SlidePanel ──────────────────────────────────────────────────────────
  var slidePanelEl     = null;
  var slidePanelTrigger = null;
  var slidePanelOnClose = null;
  var slidePanelLastFocus = null;

  function buildSlidePanel() {
    var el = document.createElement('div');
    el.className = 'slide-panel';
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML =
      '<div class="slide-panel__backdrop" data-act="close"></div>' +
      '<aside class="slide-panel__panel" tabindex="-1">' +
      '  <header class="slide-panel__header">' +
      '    <h2 class="slide-panel__title" id="slide-panel-title"></h2>' +
      '    <button class="slide-panel__close" aria-label="Close" data-act="close">×</button>' +
      '  </header>' +
      '  <div class="slide-panel__body"></div>' +
      '  <footer class="slide-panel__footer"></footer>' +
      '</aside>';
    document.body.appendChild(el);
    return el;
  }

  function openSlidePanel(opts) {
    if (!slidePanelEl) slidePanelEl = buildSlidePanel();
    slidePanelLastFocus = document.activeElement;

    var title  = slidePanelEl.querySelector('.slide-panel__title');
    var body   = slidePanelEl.querySelector('.slide-panel__body');
    var footer = slidePanelEl.querySelector('.slide-panel__footer');

    title.textContent = opts.title || '';
    body.innerHTML    = '';
    footer.innerHTML  = '';
    if (opts.body)   { body.appendChild(opts.body instanceof Node ? opts.body : asNode(opts.body)); }
    if (opts.footer) { footer.appendChild(opts.footer instanceof Node ? opts.footer : asNode(opts.footer)); }

    slidePanelOnClose = opts.onClose || null;

    // Click backdrop / X
    slidePanelEl.addEventListener('click', slidePanelClickHandler);
    document.addEventListener('keydown', slidePanelKeyHandler);

    document.body.style.overflow = 'hidden';
    requestAnimationFrame(function () {
      slidePanelEl.classList.add('is-open');
      var panel = slidePanelEl.querySelector('.slide-panel__panel');
      panel.focus();
    });
  }

  function slidePanelClickHandler(e) {
    if (e.target.getAttribute('data-act') === 'close') closeSlidePanel();
  }
  function slidePanelKeyHandler(e) {
    if (e.key === 'Escape') closeSlidePanel();
  }
  function closeSlidePanel() {
    if (!slidePanelEl) return;
    slidePanelEl.classList.remove('is-open');
    slidePanelEl.removeEventListener('click', slidePanelClickHandler);
    document.removeEventListener('keydown', slidePanelKeyHandler);
    document.body.style.overflow = '';
    var cb = slidePanelOnClose; slidePanelOnClose = null;
    if (slidePanelLastFocus) slidePanelLastFocus.focus();
    slidePanelLastFocus = null;
    if (cb) cb();
  }

  function asNode(html) {
    var t = document.createElement('template');
    t.innerHTML = String(html).trim();
    return t.content;
  }

  window.SlidePanel = { open: openSlidePanel, close: closeSlidePanel };

  // ── ConfirmDialog ───────────────────────────────────────────────────────
  var confirmEl = null;

  function buildConfirmDialog() {
    var el = document.createElement('div');
    el.className = 'confirm-dialog';
    el.setAttribute('role', 'alertdialog');
    el.setAttribute('aria-modal', 'true');
    el.innerHTML =
      '<div class="confirm-dialog__backdrop" data-act="close"></div>' +
      '<div class="confirm-dialog__panel">' +
      '  <h2 class="confirm-dialog__title"></h2>' +
      '  <p  class="confirm-dialog__body"></p>' +
      '  <footer class="confirm-dialog__footer">' +
      '    <button class="btn btn--secondary" data-act="cancel"></button>' +
      '    <button class="btn" data-act="confirm"></button>' +
      '  </footer>' +
      '</div>';
    document.body.appendChild(el);
    return el;
  }

  function openConfirmDialog(opts) {
    if (!confirmEl) confirmEl = buildConfirmDialog();
    var title   = confirmEl.querySelector('.confirm-dialog__title');
    var body    = confirmEl.querySelector('.confirm-dialog__body');
    var cancel  = confirmEl.querySelector('[data-act="cancel"]');
    var confirm = confirmEl.querySelector('[data-act="confirm"]');

    title.textContent  = opts.title || 'Confirm?';
    body.textContent   = opts.body  || '';
    cancel.textContent = opts.cancelLabel  || 'Cancel';
    confirm.textContent = opts.confirmLabel || 'Confirm';
    confirm.className = 'btn ' + (opts.danger ? 'btn--danger' : 'btn--primary');

    return new Promise(function (resolve) {
      function done(result) {
        confirmEl.classList.remove('is-open');
        confirmEl.removeEventListener('click', clickHandler);
        document.removeEventListener('keydown', keyHandler);
        setTimeout(function () { resolve(result); }, 200);
      }
      function clickHandler(e) {
        var act = e.target.getAttribute('data-act');
        if (act === 'confirm') done(true);
        else if (act === 'cancel' || act === 'close') done(false);
      }
      function keyHandler(e) {
        if (e.key === 'Escape') done(false);
        else if (e.key === 'Enter') { done(true); }
      }
      confirmEl.addEventListener('click', clickHandler);
      document.addEventListener('keydown', keyHandler);

      requestAnimationFrame(function () { confirmEl.classList.add('is-open'); });
    });
  }

  window.ConfirmDialog = { open: openConfirmDialog };

  // ── Sparkline (ApexCharts) ──────────────────────────────────────────────
  // Accepts EITHER a single series of [{date,value}] points (legacy shape)
  // OR an array-of-series for multi-line sparklines. Color arg is either a
  // single string or an array of strings, one per series. Name arg is either
  // a single string or an array of strings — labels each series in the
  // tooltip. When omitted we suppress the series name from the tooltip
  // entirely (a single-series KPI sparkline does not benefit from the
  // ApexCharts default placeholder, which renders as "series-1").
  function initSparkline(el, seriesOrPoints, colorOrColors, nameOrNames) {
    if (typeof ApexCharts === 'undefined') return;
    if (!el || !seriesOrPoints || !seriesOrPoints.length) return;

    // Detect multi-series by inspecting the first element. A single-series
    // payload's first element is an object with a .date field; a multi-series
    // payload's first element is an array.
    var isMulti = Array.isArray(seriesOrPoints[0]);
    var seriesList = isMulti ? seriesOrPoints : [seriesOrPoints];
    var colors     = Array.isArray(colorOrColors) ? colorOrColors
                   : [colorOrColors || '#3b82f6'];
    var names      = Array.isArray(nameOrNames) ? nameOrNames
                   : (typeof nameOrNames === 'string' ? [nameOrNames] : []);
    var hasNames   = names.some(function (n) { return typeof n === 'string' && n.length > 0; });

    // Bail if every series is empty.
    var anyData = seriesList.some(function (s) { return s && s.length; });
    if (!anyData) return;

    // Use the longest series' dates as the x-axis (keeps tooltip dates aligned).
    var longest = seriesList.reduce(function (acc, s) {
      return (s && s.length > acc.length) ? s : acc;
    }, []);
    var dates  = longest.map(function (p) { return p.date; });
    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';

    var apexSeries = seriesList.map(function (s, idx) {
      // Empty-string name suppresses the "series-N" default in the tooltip
      // header. Per-card meaningful names are passed in via nameOrNames.
      return {
        name: (typeof names[idx] === 'string') ? names[idx] : '',
        data: (s || []).map(function (p) { return { x: p.date, y: p.value }; }),
      };
    });

    var opts = {
      chart: {
        type: 'area', height: 36,
        sparkline: { enabled: true },
        animations: { enabled: true, easing: 'easeinout', speed: 800 },
      },
      series: apexSeries,
      xaxis:  { type: 'category', categories: dates },
      colors: colors,
      stroke: { curve: 'smooth', width: 1.6 },
      fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 100] } },
      tooltip: {
        theme: isDark ? 'dark' : 'light',
        fixed: { enabled: false },
        x: { show: true, formatter: function (_v, op) {
          var s = op && typeof op.seriesIndex === 'number' ? apexSeries[op.seriesIndex] : null;
          var p = s && typeof op.dataPointIndex === 'number' ? s.data[op.dataPointIndex] : null;
          return (p && p.x) || '';
        } },
        marker: { show: hasNames },
        // When no names were supplied, render the tooltip body as just the
        // value — no "series-N: 12" prefix. With names, fall back to Apex's
        // default formatter which honors the series name.
        y: hasNames
          ? { formatter: function (v) { return String(v); } }
          : { formatter: function (v) { return String(v); }, title: { formatter: function () { return ''; } } },
      },
    };

    new ApexCharts(el, opts).render();
  }

  window.Sparkline = { init: initSparkline };
})();
