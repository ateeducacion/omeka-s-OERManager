/**
 * Curricular coverage heatmap (RF-019, TASK-047, spec D2-D5). Pure: data in,
 * HTML string out, tested with `node --test`.
 *
 * `coverage` is CoverageMatrix::build() from the server: {stages:[{id,label,span}],
 * columns:[{id,label,stageId}], rows:[{label,cells:[null|int]}], max, unplaced}.
 * A null cell means the subject does not exist in that course; 0 means it
 * exists and no REA covers it (the investment signal). Both are told apart by
 * text and border, not by colour (ADR-0014 rule 3).
 */
import { escapeHtml } from './escape.js';

export const DEFAULT_LABELS = {
    subject: 'Materia',
    notApplicable: 'No existe en este curso',
    gap: 'Sin REA',
    rea: 'REA'
};

/** 0 for a gap, 1..5 on a logarithmic scale for a count, null for n/a. */
export function heatLevel(count, max) {
    if (count === null || count === undefined) {
        return null;
    }
    if (count <= 0 || max <= 0) {
        return 0;
    }
    const level = Math.ceil((5 * Math.log1p(count)) / Math.log1p(max));
    return Math.min(5, Math.max(1, level));
}

function toId(value) {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const id = Number(value);
    return Number.isInteger(id) ? id : null;
}

/**
 * Same rule as CoverageMatrix::filterStage() on the server, so the CSV link
 * exports exactly what is on screen: keep the columns of one stage and drop
 * the rows that become all n/a.
 */
export function visibleCoverage(coverage, stageId) {
    const stage = toId(stageId);
    if (stage === null) {
        return coverage;
    }
    const keep = [];
    coverage.columns.forEach((column, index) => {
        if (column.stageId === stage) {
            keep.push(index);
        }
    });
    let max = 0;
    const rows = [];
    for (const row of coverage.rows) {
        const cells = keep.map((index) => row.cells[index]);
        if (cells.every((cell) => cell === null)) {
            continue;
        }
        for (const cell of cells) {
            max = Math.max(max, cell || 0);
        }
        rows.push({ label: row.label, cells });
    }
    return {
        ...coverage,
        stages: coverage.stages.filter((s) => s.id === stage),
        columns: keep.map((index) => coverage.columns[index]),
        rows,
        max
    };
}

function cell(count, max, rowLabel, column, labels) {
    const level = heatLevel(count, max);
    const where = `${rowLabel} · ${column.label}`;
    if (level === null) {
        return `<td class="oer-heat-na" title="${escapeHtml(`${where}: ${labels.notApplicable}`)}">`
            + `<span aria-hidden="true">·</span><span class="oer-visually-hidden">${escapeHtml(labels.notApplicable)}</span></td>`;
    }
    if (level === 0) {
        return `<td class="oer-heat-gap" title="${escapeHtml(`${where}: ${labels.gap}`)}">0</td>`;
    }
    return `<td class="oer-heat-${level}" title="${escapeHtml(`${where}: ${count} ${labels.rea}`)}">${count}</td>`;
}

export function renderHeatmap(coverage, labels = DEFAULT_LABELS) {
    if (!coverage.columns.length || !coverage.rows.length) {
        return '<p class="oer-stats-empty">—</p>';
    }
    const stageRow = coverage.stages
        .map((stage) => `<th scope="colgroup" colspan="${stage.span}" class="oer-heat-stage">${escapeHtml(stage.label)}</th>`)
        .join('');
    const courseRow = coverage.columns
        .map((column) => `<th scope="col" class="oer-heat-course"><span>${escapeHtml(column.label)}</span></th>`)
        .join('');
    const body = coverage.rows.map((row) => {
        const cells = row.cells.map((count, index) => cell(count, coverage.max, row.label, coverage.columns[index], labels)).join('');
        return `<tr><th scope="row">${escapeHtml(row.label)}</th>${cells}</tr>`;
    }).join('');

    return '<table class="oer-heatmap">'
        + `<thead><tr><th rowspan="2" scope="col" class="oer-heat-corner">${escapeHtml(labels.subject)}</th>${stageRow}</tr>`
        + `<tr>${courseRow}</tr></thead>`
        + `<tbody>${body}</tbody></table>`;
}
