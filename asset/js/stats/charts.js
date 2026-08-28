/**
 * Gráficos de Estadísticas (RF-007, TASK-006). Puras: dato de entrada → SVG o
 * tabla HTML como string. Sin librería de terceros (spec §5) — el módulo no
 * declara ninguna en package.json y no toca añadir una dependencia nueva solo
 * para esto.
 */

function escapeXml(text) {
    return String(text)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/** Barras horizontales de conteo simple. `rows` = [{label, count}], orden ya decidido por el servidor. */
export function renderBarChart(rows) {
    const rowHeight = 28;
    const width = 480;
    const labelWidth = 160;
    const barMaxWidth = width - labelWidth - 48;
    const max = rows.reduce((m, row) => Math.max(m, row.count), 0) || 1;
    const height = (rows.length || 1) * rowHeight;

    const bars = rows.map((row, index) => {
        const y = index * rowHeight;
        const barWidth = Math.round((row.count / max) * barMaxWidth);
        return `<g transform="translate(0, ${y})">`
            + `<text x="${labelWidth - 8}" y="${Math.round(rowHeight / 2) + 4}" text-anchor="end" class="oer-stats-label">${escapeXml(row.label)}</text>`
            + `<rect x="${labelWidth}" y="4" width="${barWidth}" height="${rowHeight - 8}" class="oer-stats-bar"></rect>`
            + `<text x="${labelWidth + barWidth + 8}" y="${Math.round(rowHeight / 2) + 4}" class="oer-stats-count">${row.count}</text>`
            + '</g>';
    }).join('');

    return `<svg viewBox="0 0 ${width} ${height}" role="img" aria-label="Gráfico de barras" xmlns="http://www.w3.org/2000/svg">${bars}</svg>`;
}

/** Rejilla del cruce de 2 dimensiones. `table` = {labelA: {labelB: count}}, ya con las etiquetas resueltas por el servidor. */
export function renderCrossTable(table) {
    const keysA = Object.keys(table);
    const keysB = [...new Set(keysA.flatMap((a) => Object.keys(table[a])))];
    const max = keysA.reduce(
        (m, a) => Math.max(m, ...keysB.map((b) => table[a][b] || 0)),
        0
    ) || 1;

    const header = `<tr><th></th>${keysB.map((b) => `<th>${escapeXml(b)}</th>`).join('')}</tr>`;
    const rows = keysA.map((a) => {
        const cells = keysB.map((b) => {
            const count = table[a][b] || 0;
            const alpha = (count / max).toFixed(2);
            return `<td style="background-color: rgba(29, 122, 95, ${alpha})">${count}</td>`;
        }).join('');
        return `<tr><th>${escapeXml(a)}</th>${cells}</tr>`;
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
