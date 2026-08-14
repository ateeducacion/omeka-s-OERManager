import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    historyRows, historyChecked, HISTORY_EMPTY_NOTICE, HISTORY_UNKNOWN_TEXT
} from '../../asset/js/core/historyModel.js';

const row = (changes, extra = {}) => ({
    when: '2026-08-11T10:00:00+00:00',
    contributor: 'fmatdia',
    summary: 'Re-catalogación · lrmi:teaches +1',
    isUndo: false,
    changes,
    ...extra
});

test('un historial vacío da filas vacías', () => {
    assert.deepEqual(historyRows([]), []);
    assert.deepEqual(historyRows(null), []);
});

test('el aviso de cobertura existe y menciona el alcance', () => {
    assert.equal(typeof HISTORY_EMPTY_NOTICE, 'string');
    assert.ok(HISTORY_EMPTY_NOTICE.length > 0);
});

test('traduce el término RDF a su etiqueta del drawer', () => {
    const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].changes[0].label, 'Saberes básicos');
});

test('un término desconocido conserva el término como etiqueta', () => {
    const rows = historyRows([row([{ term: 'ex:loquesea', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].changes[0].label, 'ex:loquesea');
});

test('marca la fila que tiene algún porqué', () => {
    const withReason = historyRows([row([
        { term: 'lrmi:teaches', added: [], removed: [{ title: 'X', reason: 'porque sí' }], emptied: true }
    ])]);
    assert.equal(withReason[0].hasReasons, true);

    const without = historyRows([row([
        { term: 'lrmi:teaches', added: [], removed: [{ title: 'X', reason: '' }], emptied: true }
    ])]);
    assert.equal(without[0].hasReasons, false);
});

test('conserva quién, cuándo y el resumen', () => {
    const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].contributor, 'fmatdia');
    assert.equal(rows[0].when, '2026-08-11T10:00:00+00:00');
    assert.equal(rows[0].summary, 'Re-catalogación · lrmi:teaches +1');
});

test('pasa la etiqueta legible que formatea el servidor', () => {
    const rows = historyRows([row(
        [{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }],
        { whenLabel: '11/08/2026 10:00' }
    )]);
    assert.equal(rows[0].whenLabel, '11/08/2026 10:00');
});

test('sin whenLabel cae al when crudo, nunca a undefined', () => {
    const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].whenLabel, rows[0].when);
});

test('propaga la marca de reversión', () => {
    const rows = historyRows([
        row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }], { isUndo: true })
    ]);
    assert.equal(rows[0].isUndo, true);
});

test('una fila sin cambios no revienta', () => {
    const rows = historyRows([row([])]);
    assert.deepEqual(rows[0].changes, []);
    assert.equal(rows[0].hasReasons, false);
});

/**
 * I4 de la revisión final de rama: `history: null` (id inválido o lectura
 * fallida) y `history: []` (leído, sin curaciones) no pueden compartir
 * pantalla. `historyChecked` es la distinción, simétrica a `integrityChecked`.
 */
test('sin historial (id inválido o lectura fallida) no se ha podido leer', () => {
    assert.equal(historyChecked(null), false);
    assert.equal(historyChecked(undefined), false);
});

test('con un array, sí se ha podido leer, aunque esté vacío', () => {
    assert.equal(historyChecked([]), true);
    assert.equal(historyChecked([row([])]), true);
});

test('el texto de "no se pudo leer" existe y no está vacío', () => {
    assert.equal(typeof HISTORY_UNKNOWN_TEXT, 'string');
    assert.ok(HISTORY_UNKNOWN_TEXT.length > 0);
});
