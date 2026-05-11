/**
 * Dashboard sparklines — scans the DOM for [data-spark] elements and renders
 * an ApexCharts area sparkline in each. Widget renderer emits these:
 *
 *   <div id="spark-core-members_kpi"
 *        class="metric-spark"
 *        data-spark="[1,2,3,...]"
 *        data-spark-color="blue"></div>
 *
 * Runs on DOMContentLoaded so static page loads (server-rendered widgets) get
 * sparklines without any explicit init call from the page template.
 */
(function () {
    'use strict';

    function colorFor(variant) {
        var cs = getComputedStyle(document.documentElement);
        var map = {
            blue:   cs.getPropertyValue('--brand-primary').trim()   || '#3b82f6',
            amber:  cs.getPropertyValue('--status-warning').trim()  || '#d97706',
            green:  cs.getPropertyValue('--status-success').trim()  || '#16a34a',
            purple: cs.getPropertyValue('--accent').trim()          || '#8b5cf6',
            red:    cs.getPropertyValue('--status-error').trim()    || '#dc2626',
            cyan:   cs.getPropertyValue('--brand-secondary').trim() || '#06b6d4',
        };
        return map[variant] || map.blue;
    }

    function sparkOpts(color, data) {
        var isDark = (document.documentElement.getAttribute('data-theme') || 'light') === 'dark';
        return {
            chart: {
                type: 'area', height: 60,
                sparkline: { enabled: true },
                animations: { enabled: true, easing: 'easeinout', speed: 600 },
            },
            series: [{ name: '', data: data }],
            colors: [color],
            stroke: { curve: 'smooth', width: 1.5 },
            fill: {
                type: 'gradient',
                gradient: { shadeIntensity: 1, opacityFrom: 0.3, opacityTo: 0.02, stops: [0, 100] },
            },
            tooltip: {
                theme: isDark ? 'dark' : 'light',
                fixed: { enabled: false },
                x: { show: false },
                marker: { show: false },
            },
        };
    }

    function renderSparklines() {
        var els = document.querySelectorAll('.metric-spark[data-spark]');
        els.forEach(function (el) {
            if (el.dataset.sparkRendered === '1') return;
            var raw = el.getAttribute('data-spark');
            if (!raw) return;
            var data;
            try {
                data = JSON.parse(raw);
            } catch (e) {
                return;
            }
            if (!Array.isArray(data) || data.length === 0) return;
            var color = colorFor(el.getAttribute('data-spark-color') || 'blue');
            new ApexCharts(el, sparkOpts(color, data)).render();
            el.dataset.sparkRendered = '1';
        });
    }

    function chartOpts(color, labels, series) {
        var isDark = (document.documentElement.getAttribute('data-theme') || 'light') === 'dark';
        var cs = getComputedStyle(document.documentElement);
        var textSecondary = cs.getPropertyValue('--text-secondary').trim() || '#64748b';
        return {
            chart: {
                type: 'area', height: 200,
                background: 'transparent', fontFamily: 'inherit',
                toolbar: { show: false }, zoom: { enabled: false },
                animations: { enabled: true, easing: 'easeinout', speed: 500 },
            },
            theme: { mode: isDark ? 'dark' : 'light' },
            series: [{ name: '', data: series }],
            colors: [color],
            xaxis: {
                categories: labels,
                labels: { style: { colors: textSecondary, fontSize: '11px' }, rotate: 0 },
                axisBorder: { show: false }, axisTicks: { show: false },
            },
            yaxis: {
                labels: { style: { colors: textSecondary, fontSize: '11px' }, formatter: function (v) { return Math.round(v); } },
                min: 0,
            },
            stroke: { curve: 'smooth', width: 2 },
            fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05, stops: [0, 100] } },
            grid: { borderColor: cs.getPropertyValue('--surface-border').trim() || '#e2e8f0', strokeDashArray: 3 },
            dataLabels: { enabled: false },
        };
    }

    function renderCharts() {
        var els = document.querySelectorAll('.chart-container[data-chart-labels]');
        els.forEach(function (el) {
            if (el.dataset.chartRendered === '1') return;
            var rawL = el.getAttribute('data-chart-labels');
            var rawS = el.getAttribute('data-chart-series');
            if (!rawL || !rawS) return;
            var labels, series;
            try {
                labels = JSON.parse(rawL);
                series = JSON.parse(rawS);
            } catch (e) {
                return;
            }
            if (!Array.isArray(series) || series.length === 0) return;
            var color = colorFor(el.getAttribute('data-chart-color') || 'blue');
            new ApexCharts(el, chartOpts(color, labels, series)).render();
            el.dataset.chartRendered = '1';
        });
    }

    function renderAll() {
        if (typeof ApexCharts === 'undefined') return;
        renderSparklines();
        renderCharts();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderAll);
    } else {
        renderAll();
    }
})();
