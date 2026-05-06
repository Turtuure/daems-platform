/**
 * Daems Backstage — Dashboard Charts (ApexCharts)
 * Loaded only on /backstage. Sparkline data is embedded by PHP.
 * Member growth chart fetches dynamically on period change.
 * Adapted from SIP's sip-dashboard.js.
 */
(function () {
  'use strict';

  var growthChart = null;

  function initDashboard() {
    if (typeof ApexCharts === 'undefined') return;
    if (!window.DaemsDashboard) return;

    var isDark = (document.documentElement.getAttribute('data-theme') || 'light') === 'dark';
    var cs = getComputedStyle(document.documentElement);

    var colors = {
      members:      cs.getPropertyValue('--brand-primary').trim()  || '#3b82f6',
      applications: cs.getPropertyValue('--status-warning').trim() || '#d97706',
      events:       cs.getPropertyValue('--status-success').trim() || '#16a34a',
      projects:     cs.getPropertyValue('--accent').trim()         || '#8b5cf6',
      forum:        cs.getPropertyValue('--brand-secondary').trim() || '#06b6d4',
      insights:     cs.getPropertyValue('--status-info').trim()     || '#0ea5e9',
    };
    var textSecondary = cs.getPropertyValue('--text-secondary').trim() || '#64748b';
    var surfaceBorder = cs.getPropertyValue('--surface-border').trim() || '#e2e8f0';

    // ── Sparklines ───────────────────────────────────────────────────────
    function sparkOpts(color, data, name) {
      return {
        chart: {
          type: 'area', height: 60,
          sparkline: { enabled: true },
          animations: { enabled: true, easing: 'easeinout', speed: 800 },
        },
        series: [{ name: name || '', data: data }],
        colors: [color],
        stroke: { curve: 'smooth', width: 1.5 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 100] } },
        tooltip: { theme: isDark ? 'dark' : 'light', fixed: { enabled: false }, x: { show: false }, marker: { show: false } },
      };
    }

    var sparklines = window.DaemsDashboard.sparklines || {};
    [
      { sparkEl: 'spark-members',      key: 'members',      name: 'Members' },
      { sparkEl: 'spark-applications', key: 'applications', name: 'Applications' },
      { sparkEl: 'spark-events',       key: 'events',       name: 'Upcoming events' },
      { sparkEl: 'spark-projects',     key: 'projects',     name: 'Active projects' },
    ].forEach(function (w) {
      var data = sparklines[w.key];
      if (!data || !data.length) return;
      var el = document.getElementById(w.sparkEl);
      if (el) new ApexCharts(el, sparkOpts(colors[w.key], data, w.name)).render();
    });

    // ── Member growth area chart ─────────────────────────────────────────
    function growthOpts(labels, series) {
      return {
        chart: {
          type: 'area', height: 200,
          background: 'transparent', fontFamily: 'inherit',
          toolbar: { show: false }, zoom: { enabled: false },
          animations: { enabled: true, easing: 'easeinout', speed: 500 },
        },
        theme: { mode: isDark ? 'dark' : 'light' },
        series: [{ name: 'Total members', data: series }],
        colors: [colors.members],
        xaxis: {
          categories: labels,
          tickAmount: 6,
          labels: { style: { colors: textSecondary, fontSize: '11px' }, rotate: 0 },
          axisBorder: { show: false }, axisTicks: { show: false },
        },
        yaxis: {
          labels: { style: { colors: textSecondary, fontSize: '11px' }, formatter: function (v) { return Math.round(v); } },
          min: 0,
        },
        grid: { borderColor: surfaceBorder, strokeDashArray: 4, xaxis: { lines: { show: false } }, padding: { left: 4, right: 4 } },
        stroke: { curve: 'smooth', width: 2 },
        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.05, stops: [0, 100] } },
        dataLabels: { enabled: false },
        tooltip: { theme: isDark ? 'dark' : 'light' },
      };
    }

    var growthEl = document.getElementById('chart-member-growth');
    if (!growthEl) return;

    // Render initial 30d data embedded by PHP
    var initData = window.DaemsDashboard.memberGrowth || {};
    if (initData.series && initData.series.length) {
      growthChart = new ApexCharts(growthEl, growthOpts(initData.labels, initData.series));
      growthChart.render();
    }

    // Period tab switching
    var tabs = document.querySelectorAll('.chart-period-tab');
    tabs.forEach(function (tab) {
      tab.addEventListener('click', function () {
        var period = tab.getAttribute('data-period');

        tabs.forEach(function (t) { t.classList.remove('is-active'); t.setAttribute('aria-selected', 'false'); });
        tab.classList.add('is-active');
        tab.setAttribute('aria-selected', 'true');

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/api/backstage/member-growth?period=' + encodeURIComponent(period), true);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.onload = function () {
          if (xhr.status !== 200) return;
          try {
            var json = JSON.parse(xhr.responseText);
            if (!json.labels || !json.series) return;
            if (growthChart) {
              growthChart.updateOptions(growthOpts(json.labels, json.series), true, true);
            }
          } catch (e) {}
        };
        xhr.send();
      });
    });

    // ── Platform activity multi-series chart ───────────────────────────────
    var activityEl = document.getElementById('chart-platform-activity');
    if (!activityEl) return;

    var activitySeries = [
      { name: 'Applications', data: sparklines.applications || [], color: colors.applications },
      { name: 'Events',       data: sparklines.events || [],       color: colors.events },
      { name: 'Projects',     data: sparklines.projects || [],     color: colors.projects },
      { name: 'Forum',        data: sparklines.forum || [],        color: colors.forum },
      { name: 'Insights',     data: sparklines.insights || [],     color: colors.insights }
    ].filter(function (s) { return Array.isArray(s.data) && s.data.length > 0; });

    if (!activitySeries.length) return;

    var points = activitySeries.reduce(function (max, s) {
      return Math.max(max, s.data.length);
    }, 0);

    var labels = (window.DaemsDashboard.memberGrowth && window.DaemsDashboard.memberGrowth.labels) || [];
    if (!Array.isArray(labels) || labels.length !== points) {
      labels = [];
      for (var i = points - 1; i >= 0; i--) {
        labels.push(i + 'd ago');
      }
    }

    var activityChart = new ApexCharts(activityEl, {
      chart: {
        type: 'line',
        height: 200,
        background: 'transparent',
        fontFamily: 'inherit',
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { enabled: true, easing: 'easeinout', speed: 500 }
      },
      theme: { mode: isDark ? 'dark' : 'light' },
      series: activitySeries.map(function (s) { return { name: s.name, data: s.data }; }),
      colors: activitySeries.map(function (s) { return s.color; }),
      stroke: { curve: 'smooth', width: 2 },
      markers: { size: 0 },
      xaxis: {
        categories: labels,
        tickAmount: 6,
        labels: { style: { colors: textSecondary, fontSize: '11px' }, rotate: 0 },
        axisBorder: { show: false },
        axisTicks: { show: false }
      },
      yaxis: {
        labels: { style: { colors: textSecondary, fontSize: '11px' }, formatter: function (v) { return Math.round(v); } },
        min: 0
      },
      legend: {
        show: true,
        position: 'top',
        horizontalAlign: 'right',
        labels: { colors: textSecondary }
      },
      grid: {
        borderColor: surfaceBorder,
        strokeDashArray: 4,
        xaxis: { lines: { show: false } },
        padding: { left: 4, right: 4 }
      },
      tooltip: { theme: isDark ? 'dark' : 'light' },
      dataLabels: { enabled: false }
    });

    activityChart.render();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initDashboard);
  } else {
    initDashboard();
  }
})();
