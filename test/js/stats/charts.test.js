import { test } from 'node:test';
import assert from 'node:assert/strict';
import { renderBarChart, renderCrossTable, renderCompletenessBar } from '../../../asset/js/stats/charts.js';

test('renderBarChart pinta una barra por fila con su etiqueta y conteo', () => {
    const svg = renderBarChart([{ label: 'Matemáticas', count: 3 }, { label: 'Lengua', count: 1 }]);
    assert.match(svg, /<svg/);
    assert.match(svg, /Matemáticas/);
    assert.match(svg, />3</);
    assert.match(svg, /Lengua/);
});

test('renderBarChart con lista vacía no lanza y da un SVG vacío de barras', () => {
    const svg = renderBarChart([]);
    assert.match(svg, /<svg/);
    assert.doesNotMatch(svg, /<rect/);
});

test('renderBarChart escapa el HTML de la etiqueta', () => {
    const svg = renderBarChart([{ label: '<script>', count: 1 }]);
    assert.doesNotMatch(svg, /<script>/);
    assert.match(svg, /&lt;script&gt;/);
});

test('renderCrossTable pinta una tabla con las celdas del cruce', () => {
    const html = renderCrossTable({ Matemáticas: { '1º ESO': 2, '2º ESO': 1 } });
    assert.match(html, /<table/);
    assert.match(html, /Matemáticas/);
    assert.match(html, /1º ESO/);
    assert.match(html, />2</);
});

test('renderCrossTable con tabla vacía no lanza', () => {
    const html = renderCrossTable({});
    assert.match(html, /<table/);
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
