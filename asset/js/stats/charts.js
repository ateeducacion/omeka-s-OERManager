/**
 * Gráficos de Estadísticas (RF-007, TASK-006, TASK-047). Puras: dato de
 * entrada → SVG o tabla HTML como string. Sin librería de terceros (spec §5).
 */
import { escapeHtml as escapeXml } from './escape.js';
import { heatLevel } from './heatmap.js';

/**
 * Free 2-dimension cross (TASK-047 spec D12): `model` = {rows, columns, cells}
 * with labels already ordered and resolved by the server. Same heat scale as
 * the coverage heatmap; the number is always printed.
 */
export function renderCrossTable(model) {
    const max = model.cells.reduce((m, row) => Math.max(m, ...row, 0), 0);
    const header = `<tr><th></th>${model.columns.map((b) => `<th scope="col">${escapeXml(b)}</th>`).join('')}</tr>`;
    const rows = model.rows.map((a, i) => {
        const cells = model.columns.map((b, j) => {
            const count = model.cells[i][j] || 0;
            const level = heatLevel(count, max);
            return `<td class="oer-heat-${level === 0 ? 'zero' : level}">${count}</td>`;
        }).join('');
        return `<tr><th scope="row">${escapeXml(a)}</th>${cells}</tr>`;
    }).join('');

    return `<table class="oer-stats-cross">${header}${rows}</table>`;
}

/** Barra apilada ok/warning/error (mismos tokens de color que ADR-0014). */
export function renderCompletenessBar(completeness) {
    const width = 480;
    const height = 32;
    const total = completeness.total || 1;
    const segments = [
        { count: completeness.ok, label: 'ok', className: 'oer-stats-ok' },
        { count: completeness.warning, label: 'warning', className: 'oer-stats-warning' },
        { count: completeness.error, label: 'error', className: 'oer-stats-error' }
    ];

    let x = 0;
    const rects = segments.map((segment) => {
        const segWidth = Math.round((segment.count / total) * width);
        const rect = `<rect x="${x}" y="0" width="${segWidth}" height="${height}" class="${segment.className}"><title>${segment.label}: ${segment.count}</title></rect>`;
        x += segWidth;
        return rect;
    }).join('');

    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Completitud del catálogo" xmlns="http://www.w3.org/2000/svg">${rects}</svg>`;
}
