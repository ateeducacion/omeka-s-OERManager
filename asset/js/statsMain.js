/**
 * Wiring of the statistics page (RF-007, RF-019, TASK-006, TASK-047). Only
 * DOM glue: every rule (filters, levels, order) lives in the pure modules
 * under ./stats/ and on the server, and the CSV links carry the same filter
 * as the screen.
 */
import { renderHeatmap, visibleCoverage, DEFAULT_LABELS } from './stats/heatmap.js';
import {
    renderBarList,
    renderStageCounts,
    renderSubjectCounts,
    filterSubjectGroups,
    stageOptions,
    courseOptions
} from './stats/groupedCounts.js';
import { renderCrossTable, renderCompletenessBar } from './stats/charts.js';

function readJson(id) {
    const el = document.getElementById(id);
    if (!el) {
        return null;
    }
    try {
        return JSON.parse(el.textContent);
    } catch (error) {
        return null;
    }
}

function paint(name, html) {
    const target = document.querySelector(`[data-oer-stats-chart="${name}"]`);
    if (target) {
        target.innerHTML = html;
    }
}

/** Sets (or removes, when empty) query parameters of a link, keeping the rest (year). */
function setQuery(link, params) {
    if (!link) {
        return;
    }
    const url = new URL(link.href, window.location.href);
    for (const [key, value] of Object.entries(params)) {
        if (value === null || value === undefined || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
    }
    link.href = url.toString();
}

function fillSelect(select, options) {
    const current = select.value;
    const first = select.options[0];
    select.replaceChildren(first);
    for (const option of options) {
        const el = document.createElement('option');
        el.value = String(option.id);
        el.textContent = option.label;
        select.append(el);
    }
    select.value = options.some((o) => String(o.id) === current) ? current : '';
}

function wireYear() {
    const select = document.getElementById('oer-stats-year');
    if (select && select.form) {
        select.addEventListener('change', () => select.form.submit());
    }
}

function wireHeatmap(data, labels) {
    const select = document.getElementById('oer-stats-heat-stage');
    const exportLink = document.getElementById('oer-stats-heat-export');
    if (select) {
        fillSelect(select, data.coverage.stages.filter((s) => s.id !== null));
    }
    function repaint() {
        const stage = select ? select.value : '';
        paint('coverage', renderHeatmap(visibleCoverage(data.coverage, stage), labels));
        setQuery(exportLink, { stage });
    }
    if (select) {
        select.addEventListener('change', repaint);
    }
    repaint();
}

function wireSubjects(data) {
    const stageSelect = document.getElementById('oer-stats-subject-stage');
    const courseSelect = document.getElementById('oer-stats-subject-course');
    const exportLink = document.getElementById('oer-stats-subject-export');
    const groups = data.subjectCounts;
    if (stageSelect) {
        fillSelect(stageSelect, stageOptions(groups));
    }
    function repaint() {
        const stage = stageSelect ? stageSelect.value : '';
        if (courseSelect) {
            fillSelect(courseSelect, courseOptions(groups, stage));
        }
        const course = courseSelect ? courseSelect.value : '';
        paint('materia', renderSubjectCounts(filterSubjectGroups(groups, stage, course)));
        setQuery(exportLink, { stage, course });
    }
    stageSelect?.addEventListener('change', repaint);
    courseSelect?.addEventListener('change', repaint);
    repaint();
}

function wireCross(data) {
    const selectA = document.getElementById('oer-stats-cross-a');
    const selectB = document.getElementById('oer-stats-cross-b');
    const exportLink = document.getElementById('oer-stats-cross-export');
    if (!selectA || !selectB) {
        return;
    }
    function repaint() {
        for (const option of selectB.options) {
            option.disabled = option.value === selectA.value;
        }
        for (const option of selectA.options) {
            option.disabled = option.value === selectB.value;
        }
        const model = data.cross[`${selectA.value}/${selectB.value}`];
        if (model) {
            paint('cross', renderCrossTable(model));
        }
        setQuery(exportLink, { dimension1: selectA.value, dimension2: selectB.value });
    }
    selectA.addEventListener('change', repaint);
    selectB.addEventListener('change', repaint);
    repaint();
}

export function initStatsPage() {
    const data = readJson('oer-stats-data');
    if (!data) {
        return;
    }
    const labels = { ...DEFAULT_LABELS, ...(readJson('oer-stats-i18n') || {}) };
    wireYear();
    wireHeatmap(data, labels);
    paint('etapa', renderStageCounts(data.stageCounts, labels.rea));
    wireSubjects(data);
    for (const dimension of Object.keys(data.counts)) {
        paint(dimension, renderBarList(data.counts[dimension]));
    }
    paint('completeness', renderCompletenessBar(data.completeness));
    wireCross(data);
}

document.addEventListener('DOMContentLoaded', initStatsPage);
