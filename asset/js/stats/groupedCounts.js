/**
 * Dimension-count cards of the statistics (RF-019, TASK-047, spec D9-D11).
 * Pure: data in, HTML string out. HTML bars, not SVG, so the text keeps its
 * size whatever the card width (spec D5).
 */
import { escapeHtml } from './escape.js';
import { stageKey } from './layout.js';

function toId(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const id = Number(value);
    return Number.isInteger(id) ? id : null;
}

/** Horizontal HTML bars. `rows` = [{label, count}], order already decided by the server. */
export function renderBarList(rows, max = null) {
    if (!rows.length) {
        return '<p class="oer-stats-empty">—</p>';
    }
    const top = max ?? rows.reduce((m, row) => Math.max(m, row.count), 0);
    const items = rows.map((row) => {
        const width = top > 0 ? Math.round((row.count / top) * 100) : 0;
        return '<li>'
            + `<span class="oer-stats-bar-label" title="${escapeHtml(row.label)}">${escapeHtml(row.label)}</span>`
            + `<span class="oer-stats-bar-track"><span class="oer-stats-bar-fill" style="width: ${width}%"></span></span>`
            + `<span class="oer-stats-bar-count">${row.count}</span>`
            + '</li>';
    }).join('');
    return `<ul class="oer-stats-bars">${items}</ul>`;
}

/**
 * A stage as a collapsible section (TASK-047 revision): the summary keeps the
 * stage name and its total visible when the section is folded.
 */
function stageSection(stageId, heading, body, collapsed) {
    const key = stageKey(stageId);
    const open = collapsed.includes(key) ? '' : ' open';
    return `<details class="oer-stats-group" data-stage-key="${escapeHtml(key)}"${open}>`
        + `<summary><h4>${heading}</h4></summary>${body}</details>`;
}

function totalBadge(total, reaLabel) {
    return ` <span class="oer-stats-total">${total} ${escapeHtml(reaLabel)}</span>`;
}

/** «Etapa y curso»: one block per stage with its distinct REA total, courses in level order. */
export function renderStageCounts(groups, reaLabel = 'REA', collapsed = []) {
    if (!groups.length) {
        return '<p class="oer-stats-empty">—</p>';
    }
    const max = groups.reduce((m, g) => Math.max(m, ...g.courses.map((c) => c.count)), 0);
    return groups.map((group) => stageSection(
        group.stageId,
        escapeHtml(group.label) + totalBadge(group.total, reaLabel),
        renderBarList(group.courses, max),
        collapsed
    )).join('');
}

/** Same rule as SubjectCounts::filter() on the server, so the CSV matches the screen. */
export function filterSubjectGroups(groups, stageId, courseId) {
    const stage = toId(stageId);
    const course = toId(courseId);
    const out = [];
    for (const group of groups) {
        if (stage !== null && group.stageId !== stage) {
            continue;
        }
        const courses = course === null ? group.courses : group.courses.filter((c) => c.courseId === course);
        if (courses.length) {
            out.push({ ...group, courses });
        }
    }
    return out;
}

/** Stages that can be chosen in the Materia filter (the stageless group cannot). */
export function stageOptions(groups) {
    return groups.filter((g) => g.stageId !== null).map((g) => ({ id: g.stageId, label: g.label }));
}

/** Courses offered for the chosen stage (every stage when none is chosen). */
export function courseOptions(groups, stageId) {
    const stage = toId(stageId);
    return groups
        .filter((g) => g.stageId !== null && (stage === null || g.stageId === stage))
        .flatMap((g) => g.courses.filter((c) => c.courseId !== null).map((c) => ({ id: c.courseId, label: c.label })));
}

/**
 * «Materia»: stage → course → subjects; a subject is never shown without its
 * course. `totals` = distinct REA per stage key (from «Etapa y curso»), shown in
 * the summary so a folded stage still says how much it holds.
 */
export function renderSubjectCounts(groups, collapsed = [], totals = {}, reaLabel = 'REA') {
    if (!groups.length) {
        return '<p class="oer-stats-empty">—</p>';
    }
    const max = groups.reduce(
        (m, g) => Math.max(m, ...g.courses.flatMap((c) => c.subjects.map((s) => s.count))),
        0
    );
    return groups.map((group) => {
        const total = totals[stageKey(group.stageId)];
        return stageSection(
            group.stageId,
            escapeHtml(group.label) + (total === undefined ? '' : totalBadge(total, reaLabel)),
            group.courses.map((course) => `<h5>${escapeHtml(course.label)}</h5>${renderBarList(course.subjects, max)}`).join(''),
            collapsed
        );
    }).join('');
}
