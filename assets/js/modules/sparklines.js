/* TNSVT Reino v2 — Sparkline animations + KPI count-up */
(function () {
    'use strict';

    function animateCount(el, target, duration, prefix, suffix) {
        if (!el) return;
        prefix = prefix || '';
        suffix = suffix || '';
        const start = parseFloat(el.textContent.replace(/[^0-9.-]/g, '')) || 0;
        const startTime = performance.now();

        function update(now) {
            const elapsed = now - startTime;
            const progress = Math.min(elapsed / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            const current = start + (target - start) * eased;
            el.textContent = prefix + current.toFixed(target % 1 === 0 ? 0 : 2) + suffix;
            if (progress < 1) requestAnimationFrame(update);
        }
        requestAnimationFrame(update);
    }

    function drawSparkline(svgId, data, color) {
        const svg = document.getElementById(svgId);
        if (!svg || !data || data.length < 2) return;

        const w = 100, h = 30;
        const min = Math.min(...data);
        const max = Math.max(...data);
        const range = max - min || 1;

        const points = data.map((v, i) => {
            const x = (i / (data.length - 1)) * w;
            const y = h - ((v - min) / range) * h;
            return `${x.toFixed(1)},${y.toFixed(1)}`;
        }).join(' ');

        const line = svg.querySelector('polyline:not([fill])');
        if (line) line.setAttribute('points', points);

        const fill = svg.querySelector('polyline[fill]');
        if (fill) fill.setAttribute('points', points + ` ${w},${h} 0,${h}`);
    }

    // Initialize dashboard animations
    window.initDashboardAnimations = function () {
        // Animate KPI values on load
        const kpiPnl = document.getElementById('kpi-pnl-value');
        const kpiSeekers = document.getElementById('kpi-seekers-value');
        const kpiServer = document.getElementById('kpi-server-value');
        const kpiMacro = document.getElementById('kpi-macro-value');

        if (kpiPnl && window._kpiData) {
            const d = window._kpiData;
            animateCount(kpiPnl, d.pnl || 0, 800, '$');
            animateCount(kpiSeekers, d.activeSeekers || 0, 600);
            animateCount(kpiServer, d.uptime || 99.9, 700, '', '%');
            animateCount(kpiMacro, d.macroSignals || 0, 500);
        }

        // Draw sparklines
        if (window._sparklineData) {
            drawSparkline('kpi-pnl-spark-svg', window._sparklineData.pnl, '#d4af37');
            drawSparkline('kpi-seekers-spark-svg', window._sparklineData.seekers, '#4ade80');
            drawSparkline('kpi-server-spark-svg', window._sparklineData.server, '#4ade80');
            drawSparkline('kpi-macro-spark-svg', window._sparklineData.macro, '#8a3cff');
        }
    };

    // Auto-init on dashboard page
    document.addEventListener('DOMContentLoaded', () => {
        if (document.querySelector('#journal-stats')) {
            // Journal page - animate stats
            document.querySelectorAll('#journal-stats .kpi-value').forEach(el => {
                const target = parseFloat(el.dataset.value || el.textContent.replace(/[^0-9.-]/g, ''));
                if (!isNaN(target)) animateCount(el, target, 800);
            });
        }
    });
})();