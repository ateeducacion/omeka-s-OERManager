import test from 'node:test';
import assert from 'node:assert/strict';
import { parseRecatalog, valuesFor } from '../../asset/js/core/anchorData.js';

const closed = { allowed: false, reason: '', current: {} };

test('lee la decisión y el prellenado que sirve drawer-details', () => {
    const raw = JSON.stringify({
        allowed: true,
        reason: '',
        current: { 'lrmi:teaches': [{ id: 11, title: 'Saber' }], 'lrmi:assesses': [] }
    });
    assert.deepEqual(parseRecatalog(raw), {
        allowed: true,
        reason: '',
        current: { 'lrmi:teaches': [{ id: 11, title: 'Saber' }], 'lrmi:assesses': [] }
    });
});

test('una negativa trae su motivo y nunca abre el editor', () => {
    const raw = JSON.stringify({ allowed: false, reason: 'REA descartado.', current: {} });
    assert.deepEqual(parseRecatalog(raw), { allowed: false, reason: 'REA descartado.', current: {} });
});

test('sin datos o con datos rotos, cerrado: nunca una excepción', () => {
    assert.deepEqual(parseRecatalog(undefined), closed);
    assert.deepEqual(parseRecatalog(''), closed);
    assert.deepEqual(parseRecatalog('{no es json'), closed);
    assert.deepEqual(parseRecatalog('null'), closed);
    assert.deepEqual(parseRecatalog('[1,2]'), closed);
    // Solo `true` abre: un "1" o "yes" no.
    assert.equal(parseRecatalog(JSON.stringify({ allowed: 'yes' })).allowed, false);
});

test('normaliza el prellenado: ids positivos, título en texto, términos con lista', () => {
    const raw = JSON.stringify({
        allowed: true,
        reason: 7,
        current: {
            'schema:about': [{ id: '3', title: 'Matemáticas' }, { id: 0, title: 'x' }, null, { title: 'sin id' }],
            'lrmi:teaches': 'no es lista',
            'dcterms:relation': [{ id: 4 }]
        }
    });
    assert.deepEqual(parseRecatalog(raw), {
        allowed: true,
        reason: '',
        current: {
            'schema:about': [{ id: 3, title: 'Matemáticas' }],
            'lrmi:teaches': [],
            'dcterms:relation': [{ id: 4, title: '' }]
        }
    });
});

test('valuesFor devuelve la lista de un término o una vacía', () => {
    const data = parseRecatalog(JSON.stringify({
        allowed: true, current: { 'schema:about': [{ id: 3, title: 'M' }] }
    }));
    assert.deepEqual(valuesFor(data, 'schema:about'), [{ id: 3, title: 'M' }]);
    assert.deepEqual(valuesFor(data, 'lrmi:teaches'), []);
    assert.deepEqual(valuesFor(null, 'lrmi:teaches'), []);
});
