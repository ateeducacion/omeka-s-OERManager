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
import {
    defaultLayout,
    sanitizeLayout,
    moveCard,
    moveCardBefore,
    stageKey,
    isCollapsed,
    toggleStage,
    setAllStages
} from './stats/layout.js';

/** Layout of «Conteo por dimensión» (TASK-047 revision): card order and folded stages, per user. */
let layout = defaultLayout();

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

function stageTotals(data) {
    return Object.fromEntries(data.stageCounts.map((g) => [stageKey(g.stageId), g.total]));
}

function paintStages(data, labels) {
    paint('etapa', renderStageCounts(data.stageCounts, labels.rea, layout.collapsed.etapa));
}

function wireSubjects(data, labels) {
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
        paint('materia', renderSubjectCounts(
            filterSubjectGroups(groups, stage, course),
            layout.collapsed.materia,
            stageTotals(data),
            labels.rea
        ));
        setQuery(exportLink, { stage, course });
    }
    stageSelect?.addEventListener('change', repaint);
    courseSelect?.addEventListener('change', repaint);
    repaint();
    return repaint;
}

/**
 * Movable cards and collapsible stages (TASK-047 revision, 2026-10-11). The
 * layout is saved as a user setting a moment after the last change; if saving
 * fails the page keeps working and says so.
 */
function wireLayout(data, labels, repaintSubjects) {
    const config = readJson('oer-stats-layout');
    const grid = document.getElementById('oer-stats-cards');
    const status = document.getElementById('oer-stats-layout-status');
    if (!config || !grid) {
        return;
    }
    layout = sanitizeLayout(config.layout);
    let saveTimer = null;
    let dragged = null;

    const repaintCard = {
        etapa: () => paintStages(data, labels),
        materia: () => repaintSubjects()
    };
    const stageKeysOf = {
        etapa: () => data.stageCounts.map((g) => stageKey(g.stageId)),
        materia: () => data.subjectCounts.map((g) => stageKey(g.stageId))
    };

    function applyOrder() {
        for (const id of layout.order) {
            const card = grid.querySelector(`[data-oer-stats-card="${id}"]`);
            if (card) {
                grid.append(card);
            }
        }
    }

    function save() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(() => {
            const body = new URLSearchParams({ csrf: config.csrf, layout: JSON.stringify(layout) });
            fetch(config.url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body })
                .then((response) => (response.ok ? response.json() : { saved: false }))
                .then((result) => {
                    if (status) {
                        status.textContent = result.saved ? '' : labels.layoutSaveFailed;
                    }
                })
                .catch(() => {
                    if (status) {
                        status.textContent = labels.layoutSaveFailed;
                    }
                });
        }, 500);
    }

    function update(next, repaint = []) {
        layout = next;
        applyOrder();
        repaint.forEach((card) => repaintCard[card]());
        save();
    }

    grid.addEventListener('click', (event) => {
        const card = event.target.closest('[data-oer-stats-card]');
        const move = event.target.closest('[data-oer-stats-move]');
        const fold = event.target.closest('[data-oer-stats-fold]');
        if (!card) {
            return;
        }
        const id = card.dataset.oerStatsCard;
        if (move) {
            update({ ...layout, order: moveCard(layout.order, id, Number(move.dataset.oerStatsMove)) });
            move.focus();
        } else if (fold && stageKeysOf[id]) {
            update(setAllStages(layout, id, stageKeysOf[id](), fold.dataset.oerStatsFold === 'collapse'), [id]);
        }
    });

    // `toggle` does not bubble: listen in the capture phase. A section inserted
    // already open also fires it, with no change to save.
    grid.addEventListener('toggle', (event) => {
        const section = event.target;
        const card = section.closest?.('[data-oer-stats-card]');
        if (!card || !section.dataset?.stageKey) {
            return;
        }
        const id = card.dataset.oerStatsCard;
        const key = section.dataset.stageKey;
        if (isCollapsed(layout, id, key) === !section.open) {
            return;
        }
        update(toggleStage(layout, id, key));
    }, true);

    grid.addEventListener('dragstart', (event) => {
        const handle = event.target.closest?.('.oer-stats-handle');
        const card = handle?.closest('[data-oer-stats-card]');
        if (!card) {
            return;
        }
        dragged = card.dataset.oerStatsCard;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', dragged);
        event.dataTransfer.setDragImage(card, 16, 16);
        card.classList.add('oer-stats-dragging');
    });
    grid.addEventListener('dragover', (event) => {
        if (dragged) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }
    });
    grid.addEventListener('drop', (event) => {
        if (!dragged) {
            return;
        }
        event.preventDefault();
        const target = event.target.closest('[data-oer-stats-card]');
        let before = null;
        if (target) {
            const rect = target.getBoundingClientRect();
            const after = event.clientX > rect.left + rect.width / 2;
            const id = target.dataset.oerStatsCard;
            before = after ? (layout.order[layout.order.indexOf(id) + 1] ?? null) : id;
        }
        update({ ...layout, order: moveCardBefore(layout.order, dragged, before === dragged ? null : before) });
    });
    grid.addEventListener('dragend', () => {
        grid.querySelector('.oer-stats-dragging')?.classList.remove('oer-stats-dragging');
        dragged = null;
    });

    document.getElementById('oer-stats-layout-reset')?.addEventListener('click', () => {
        update(defaultLayout(), ['etapa', 'materia']);
    });

    applyOrder();
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
    layout = sanitizeLayout(readJson('oer-stats-layout')?.layout);
    paintStages(data, labels);
    const repaintSubjects = wireSubjects(data, labels);
    for (const dimension of Object.keys(data.counts)) {
        paint(dimension, renderBarList(data.counts[dimension]));
    }
    paint('completeness', renderCompletenessBar(data.completeness));
    wireCross(data);
    wireLayout(data, labels, repaintSubjects);
}

document.addEventListener('DOMContentLoaded', initStatsPage);
