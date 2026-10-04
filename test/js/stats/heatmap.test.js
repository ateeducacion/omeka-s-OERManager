import { test } from 'node:test';
import assert from 'node:assert/strict';
import { heatLevel, visibleCoverage, renderHeatmap } from '../../../asset/js/stats/heatmap.js';

// Shape of CoverageMatrix::build(): columns 1º Primaria, 1º ESO, 2º ESO.
const coverage = {
    stages: [{ id: 2, label: 'Primaria', span: 1 }, { id: 3, label: 'ESO', span: 2 }],
    columns: [
        { id: 20, label: '1º Primaria', stageId: 2 },
        { id: 30, label: '1º ESO', stageId: 3 },
        { id: 31, label: '2º ESO', stageId: 3 }
    ],
    rows: [
        { label: 'Educación física', cells: [null, 0, 2] },
        { label: 'Matemáticas', cells: [7, null, 1] },
        { label: 'Solo primaria', cells: [3, null, null] }
    ],
    max: 7,
    unplaced: 0
};

test('heatLevel: n/a, hueco y escala logarítmica de 1 a 5', () => {
    assert.equal(heatLevel(null, 10), null);
    assert.equal(heatLevel(0, 10), 0);
    assert.equal(heatLevel(1, 1), 5);
    assert.equal(heatLevel(10, 10), 5);
    assert.equal(heatLevel(1, 1000), 1);
    // Logarithmic: 30 of 1000 is already mid-scale, not level 1.
    assert.equal(heatLevel(30, 1000), 3);
    for (let n = 1; n <= 1000; n += 37) {
        const level = heatLevel(n, 1000);
        assert.ok(level >= 1 && level <= 5);
    }
});

test('visibleCoverage sin etapa devuelve la matriz tal cual', () => {
    assert.equal(visibleCoverage(coverage, ''), coverage);
    assert.equal(visibleCoverage(coverage, null), coverage);
});

test('visibleCoverage deja las columnas de la etapa y quita las filas que quedan sin celdas', () => {
    const eso = visibleCoverage(coverage, '3');
    assert.deepEqual(eso.columns.map((c) => c.id), [30, 31]);
    assert.deepEqual(eso.rows.map((r) => r.label), ['Educación física', 'Matemáticas']);
    assert.deepEqual(eso.rows[1].cells, [null, 1]);
    assert.equal(eso.max, 2);
    assert.deepEqual(eso.stages, [{ id: 3, label: 'ESO', span: 2 }]);
});

test('renderHeatmap: cabecera de dos niveles con etapas y cursos en el orden recibido', () => {
    const html = renderHeatmap(coverage);
    assert.match(html, /colspan="2" class="oer-heat-stage">ESO</);
    assert.ok(html.indexOf('1º Primaria') < html.indexOf('1º ESO'));
    assert.ok(html.indexOf('1º ESO') < html.indexOf('2º ESO'));
});

test('renderHeatmap distingue n/a, hueco (0) y conteo sin depender del color', () => {
    const html = renderHeatmap(coverage);
    assert.match(html, /class="oer-heat-na"[^>]*><span aria-hidden="true">·<\/span><span class="oer-visually-hidden">No existe en este curso</);
    assert.match(html, /class="oer-heat-gap"[^>]*>0</);
    assert.match(html, /class="oer-heat-5" title="Matemáticas · 1º Primaria: 7 REA">7</);
});

test('renderHeatmap escapa etiquetas y no falla vacío', () => {
    const html = renderHeatmap({ ...coverage, rows: [{ label: '<script>', cells: [1, null, null] }] });
    assert.doesNotMatch(html, /<script>/);
    assert.match(renderHeatmap({ stages: [], columns: [], rows: [], max: 0 }), /oer-stats-empty/);
});
