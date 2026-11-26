import { test } from 'node:test';
import assert from 'node:assert/strict';
import { previewModel, formatCost, progressModel, storedAiJob } from '../../asset/js/core/aiBatchModel.js';

test('formatCost writes the decimals with a comma', () => {
    assert.equal(formatCost(0), '0,00 $');
    assert.equal(formatCost(15.6), '15,60 $');
});

test('previewModel enables launching only when something enters and no batch runs', () => {
    const base = { total: 4, enter: 2, outOfQueue: 1, hasProposal: 1, cost: 0.01, running: false };
    assert.deepEqual(previewModel(base), {
        lines: [
            ['Entran', '2'],
            ['Se saltan: fuera de la cola', '1'],
            ['Se saltan: ya tienen propuesta', '1'],
            ['Coste estimado', '0,01 $']
        ],
        canLaunch: true,
        notice: ''
    });
    assert.equal(previewModel({ ...base, enter: 0 }).canLaunch, false);
    assert.equal(previewModel({ ...base, enter: 0 }).notice, 'Ningún REA de la selección necesita propuesta.');
    assert.equal(previewModel({ ...base, running: true }).canLaunch, false);
    assert.equal(previewModel({ ...base, running: true }).notice, 'Ya hay un lote de IA en marcha. Espera a que termine.');
});

test('progressModel tells polling from the end', () => {
    assert.deepEqual(progressModel({ status: 'in_progress', done: 3, total: 9 }), { action: 'poll', text: 'Procesados 3 de 9…' });
    const done = progressModel({ status: 'completed', tallies: { done: 9, total: 9, proposed: 7, skipped: 1, failed: [{ id: 4, code: 'llm' }], aborted: false } });
    assert.equal(done.action, 'done');
    assert.equal(done.text, 'Terminado: 7 con propuesta, 1 saltados, 1 con error.');
    assert.deepEqual(done.failed, [{ id: 4, code: 'llm' }]);
    assert.equal(progressModel({ status: 'stopped', tallies: { done: 2, total: 9, proposed: 2, skipped: 0, failed: [] } }).text, 'Cancelado: 2 con propuesta, 0 saltados, 0 con error.');
    assert.equal(progressModel({ status: 'error', tallies: { proposed: 0, skipped: 0, failed: [], aborted: true } }).text, 'Detenido tras varios errores seguidos de la IA: revisa la conexión con el proveedor. 0 con propuesta.');
    assert.equal(progressModel({ status: 'error', code: 'job_died' }).text, 'El proceso del lote se interrumpió. Revisa el registro de trabajos.');
});

test('storedAiJob reads only a positive job id', () => {
    assert.equal(storedAiJob('12'), 12);
    assert.equal(storedAiJob('0'), null);
    assert.equal(storedAiJob('x'), null);
    assert.equal(storedAiJob(null), null);
});
