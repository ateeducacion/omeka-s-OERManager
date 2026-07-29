import test from 'node:test';
import assert from 'node:assert/strict';
import { pendingChips } from '../../asset/js/core/proposalMerge.js';

test('propone solo lo que no está ya puesto', () => {
    const pending = pendingChips(
        { 'schema:about': [{ id: 7, title: 'Matemáticas' }, { id: 9, title: 'Física' }] },
        { 'schema:about': ['7'] },
        {}
    );
    assert.deepEqual(pending['schema:about'], [{ id: 9, title: 'Física', justification: '' }]);
});

test('compara ids con independencia del tipo', () => {
    const pending = pendingChips(
        { 'lrmi:teaches': [{ id: 12, title: 'Saber' }] },
        { 'lrmi:teaches': [12] },
        {}
    );
    assert.deepEqual(pending['lrmi:teaches'], []);
});

test('adjunta la justificación de saberes y criterios', () => {
    const pending = pendingChips(
        { 'lrmi:teaches': [{ id: 4, title: 'La célula' }] },
        {},
        { 'lrmi:teaches': { 4: 'Aborda estructuras celulares.' } }
    );
    assert.equal(pending['lrmi:teaches'][0].justification, 'Aborda estructuras celulares.');
});

test('sin justificación devuelve cadena vacía, nunca undefined', () => {
    const pending = pendingChips({ 'schema:about': [{ id: 1, title: 'X' }] }, {}, {});
    assert.equal(pending['schema:about'][0].justification, '');
});

test('tolera alineamiento ausente', () => {
    assert.deepEqual(pendingChips(null, {}, {}), {});
});
