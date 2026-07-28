import test from 'node:test';
import assert from 'node:assert/strict';
import { decide, POLL_MS, POLL_MAX } from '../../asset/js/core/proposalState.js';

test('conserva la cadencia y el techo del código actual', () => {
    assert.equal(POLL_MS, 3000);
    assert.equal(POLL_MAX, 240);
});

test('in_progress reintenta', () => {
    const r = decide({ status: 'in_progress', attempt: 0, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'retry');
});

test('in_progress agotado da timeout, no reintento infinito', () => {
    const r = decide({ status: 'in_progress', attempt: POLL_MAX + 1, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'timeout');
    assert.equal(r.message, 'La propuesta tarda demasiado. Reintenta más tarde.');
});

test('stopped no se confunde con error', () => {
    const r = decide({ status: 'stopped', attempt: 3, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'stopped');
    assert.equal(r.message, 'Propuesta cancelada.');
});

test('status de error y error en el cuerpo dan ambos error', () => {
    assert.equal(decide({ status: 'error', attempt: 1, maxAttempts: POLL_MAX }).action, 'error');
    assert.equal(decide({ status: 'completed', error: 'llm', attempt: 1, maxAttempts: POLL_MAX }).action, 'error');
});

test('completed sin error entrega el payload', () => {
    const payload = { alignment: {} };
    const r = decide({ status: 'completed', payload, attempt: 5, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'done');
    assert.equal(r.payload, payload);
});

test('un fallo de red reintenta en vez de abortar', () => {
    // Comportamiento deliberado del código actual (rama .fail del sondeo):
    // un corte puntual no debe tirar un job que sigue vivo en el servidor.
    const r = decide({ status: 'network_error', attempt: 2, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'retry');
});

test('un fallo de red también respeta el techo', () => {
    const r = decide({ status: 'network_error', attempt: POLL_MAX + 1, maxAttempts: POLL_MAX });
    assert.equal(r.action, 'timeout');
});
