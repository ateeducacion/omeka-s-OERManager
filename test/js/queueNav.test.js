import { test } from 'node:test';
import assert from 'node:assert/strict';
import { actionOf, leavesQueue, nextItemId, nextOpenItemId, lowerCount, countDelta, adjustCount } from '../../asset/js/core/queueNav.js';

test('la acción es el último tramo de la URL del formulario', () => {
    assert.equal(actionOf('https://x.test/admin/oer-manager/publish-proposal'), 'publish-proposal');
    assert.equal(actionOf('/admin/oer-manager/discard/?a=1'), 'discard');
    assert.equal(actionOf(''), '');
});

test('publicar, rechazar y descartar sacan el REA de la cola; el resto no', () => {
    ['publish-proposal', 'reject-proposal', 'discard'].forEach((action) => assert.ok(leavesQueue(action)));
    ['propose', 'unpublish', 'reopen', ''].forEach((action) => assert.ok(!leavesQueue(action)));
});

test('el siguiente REA de la página, o null al final', () => {
    const ids = ['4', '9', '2'];
    assert.equal(nextItemId(ids, '4'), '9');
    assert.equal(nextItemId(ids, 9), '2');
    assert.equal(nextItemId(ids, '2'), null);
    assert.equal(nextItemId(ids, '77'), null);
    assert.equal(nextItemId(['4'], '4'), null);
    assert.equal(nextItemId([], '4'), null);
});

test('«Cola (N)» baja en uno y nunca de cero', () => {
    assert.equal(lowerCount('Cola (12)'), 'Cola (11)');
    assert.equal(lowerCount('Queue (1)'), 'Queue (0)');
    assert.equal(lowerCount('Cola (0)'), 'Cola (0)');
    assert.equal(lowerCount('Cola'), 'Cola');
});

test('«Siguiente» salta las filas ya tratadas (revisión TASK-070)', () => {
    const rows = [
        { id: '4', done: true },
        { id: '9', done: true },
        { id: '2', done: false },
        { id: '5', done: true }
    ];
    assert.equal(nextOpenItemId(rows, '4'), '2', 'una fila ya tratada no es la siguiente');
    assert.equal(nextOpenItemId(rows, 4), '2');
    assert.equal(nextOpenItemId(rows, '2'), null, 'solo quedan filas tratadas detrás');
    assert.equal(nextOpenItemId(rows, '77'), null);
    assert.equal(nextOpenItemId([], '4'), null);
});

test('«Cola (N)» sube o baja según lo que dice el servidor (revisión de código TASK-070)', () => {
    assert.equal(countDelta({ leftQueue: true, enteredQueue: false }), -1);
    assert.equal(countDelta({ leftQueue: false, enteredQueue: true }), 1);
    assert.equal(countDelta({ leftQueue: false, enteredQueue: false }), 0);
    assert.equal(countDelta({}), 0);
    assert.equal(adjustCount('Cola (9)', 1), 'Cola (10)');
    assert.equal(adjustCount('Cola (9)', -1), 'Cola (8)');
    assert.equal(adjustCount('Cola (0)', -1), 'Cola (0)');
    assert.equal(adjustCount('Cola', 1), 'Cola');
});
