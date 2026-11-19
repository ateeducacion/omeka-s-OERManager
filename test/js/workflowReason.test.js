import { test } from 'node:test';
import assert from 'node:assert/strict';
import { nextOpen } from '../../asset/js/core/workflowReason.js';

test('pulsar una acción con motivo abre su campo', () => {
    assert.equal(nextOpen(null, 'reject'), 'reject');
});

test('pulsar otra acción cambia de campo: solo hay uno abierto', () => {
    assert.equal(nextOpen('reject', 'discard'), 'discard');
});

test('pulsar la misma acción, o Cancelar, lo cierra', () => {
    assert.equal(nextOpen('reject', 'reject'), null);
    assert.equal(nextOpen('reject', null), null);
});
