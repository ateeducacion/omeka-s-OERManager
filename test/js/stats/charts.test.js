import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderCrossTable, renderCompletenessBar } from '../../../asset/js/stats/charts.js';

const cross = { rows: ['Matemáticas · 1º ESO'], columns: ['CC BY', 'CC BY-SA'], cells: [[2, 0]] };

test('renderCrossTable pinta una tabla con las celdas del cruce en el orden recibido', () => {
    const html = renderCrossTable(cross);
    assert.match(html, /<table/);
    assert.match(html, /Matemáticas · 1º ESO/);
    assert.ok(html.indexOf('CC BY<') < html.indexOf('CC BY-SA<'));
    assert.match(html, /class="oer-heat-5">2</);
    assert.match(html, /class="oer-heat-zero">0</);
});

test('renderCrossTable no usa colores en línea (escala por tokens, TASK-047 D4)', () => {
    assert.doesNotMatch(renderCrossTable(cross), /style=/);
});

test('renderCrossTable con tabla vacía no lanza', () => {
    assert.match(renderCrossTable({ rows: [], columns: [], cells: [] }), /<table/);
});

test('renderCrossTable escapa las etiquetas', () => {
    const html = renderCrossTable({ rows: ['<b>'], columns: ['x'], cells: [[1]] });
    assert.doesNotMatch(html, /<b>/);
});

test('renderCompletenessBar pinta los 3 segmentos', () => {
    const svg = renderCompletenessBar({ ok: 3, warning: 1, error: 0, total: 4, okPercent: 75.0 });
    assert.match(svg, /oer-stats-ok/);
    assert.match(svg, /oer-stats-warning/);
    assert.match(svg, /oer-stats-error/);
});

test('renderCompletenessBar con total 0 no lanza', () => {
    const svg = renderCompletenessBar({ ok: 0, warning: 0, error: 0, total: 0, okPercent: 0 });
    assert.match(svg, /<svg/);
});
