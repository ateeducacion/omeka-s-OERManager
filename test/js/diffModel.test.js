import test from 'node:test';
import assert from 'node:assert/strict';
import { diffRows, RECATALOG_DIMENSIONS } from '../../asset/js/core/diffModel.js';

const DIFF = {
    'schema:about': {
        current: [7], next: [9], added: [9], removed: [7], invalid: [],
        titles: { 7: 'Matemáticas (1º ESO)', 9: 'Física y Química (3º ESO)' }
    }
};

test('las cinco dimensiones del re-catalogador se conservan', () => {
    assert.equal(RECATALOG_DIMENSIONS.length, 5);
    assert.deepEqual(RECATALOG_DIMENSIONS[0], ['lrmi:educationalLevel', 'Curso']);
});

test('muestra títulos, no recuentos (D7)', () => {
    const { rows } = diffRows(DIFF);
    const row = rows.find((r) => r.term === 'schema:about');
    assert.deepEqual(row.added, ['Física y Química (3º ESO)']);
    assert.deepEqual(row.removed, ['Matemáticas (1º ESO)']);
    assert.equal(row.unchanged, false);
});

test('cae al id cuando falta el título', () => {
    const { rows } = diffRows({
        'schema:about': { current: [], next: [42], added: [42], removed: [], invalid: [], titles: {} }
    });
    assert.deepEqual(rows[0].added, ['#42']);
});

test('marca los destinos inválidos y lo propaga', () => {
    const { rows, hasInvalid } = diffRows({
        'lrmi:teaches': { current: [], next: [3], added: [3], removed: [], invalid: [3], titles: {} }
    });
    assert.equal(hasInvalid, true);
    assert.deepEqual(rows[0].invalid, ['#3']);
});

test('una dimensión sin cambios se marca como tal', () => {
    const { rows } = diffRows({
        'schema:about': { current: [7], next: [7], added: [], removed: [], invalid: [], titles: { 7: 'X' } }
    });
    assert.equal(rows[0].unchanged, true);
});

test('omite las dimensiones que el preview no devuelve', () => {
    const { rows } = diffRows(DIFF);
    assert.equal(rows.length, 1);
});

test('tolera un diff vacío', () => {
    assert.deepEqual(diffRows({}), { rows: [], hasInvalid: false });
});
