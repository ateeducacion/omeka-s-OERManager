import { test } from 'node:test';
import assert from 'node:assert/strict';
import { actionOf, leavesQueue, nextItemId, lowerCount } from '../../asset/js/core/queueNav.js';

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
