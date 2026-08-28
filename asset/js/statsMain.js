import { renderBarChart, renderCrossTable, renderCompletenessBar } from './stats/charts.js';

function readStatsData() {
    const el = document.getElementById('oer-stats-data');
    return el ? JSON.parse(el.textContent) : null;
}

function paintCounts(data) {
    for (const dimension of Object.keys(data.counts)) {
        const target = document.querySelector(`[data-oer-stats-chart="${dimension}"]`);
        if (target) {
            target.innerHTML = renderBarChart(data.counts[dimension]);
        }
    }
}

function paintCompleteness(data) {
    const target = document.querySelector('[data-oer-stats-chart="completeness"]');
    if (target) {
        target.innerHTML = renderCompletenessBar(data.completeness);
    }
}

function wireCross(data) {
    const selectA = document.getElementById('oer-stats-cross-a');
    const selectB = document.getElementById('oer-stats-cross-b');
    const target = document.querySelector('[data-oer-stats-chart="cross"]');
    const exportLink = document.getElementById('oer-stats-cross-export');
    if (!selectA || !selectB || !target) {
        return;
    }

    function paint() {
        const key = `${selectA.value}/${selectB.value}`;
        const table = data.cross[key];
        if (table) {
            target.innerHTML = renderCrossTable(table);
        }
        if (exportLink) {
            const url = new URL(exportLink.href, window.location.href);
            url.searchParams.set('dimension1', selectA.value);
            url.searchParams.set('dimension2', selectB.value);
            exportLink.href = url.toString();
        }
    }

    selectA.addEventListener('change', paint);
    selectB.addEventListener('change', paint);
    paint();
}

export function initStatsPage() {
    const data = readStatsData();
    if (!data) {
        return;
    }
    paintCounts(data);
    paintCompleteness(data);
    wireCross(data);
}

document.addEventListener('DOMContentLoaded', initStatsPage);
