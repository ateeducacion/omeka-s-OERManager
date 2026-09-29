import test from 'node:test';
import assert from 'node:assert/strict';
import {
    validateBatch,
    previewPairs,
    selectionAfterToggle,
    stripModel,
    previewModel,
    resultModel
} from '../../asset/js/core/governanceBatchModel.js';

test('a batch needs at least one ticked field, each with a value', () => {
    assert.deepEqual(validateBatch({}), { _: 'no-field' });
    assert.deepEqual(validateBatch({ licence: '  ' }), { 'dcterms:license': 'required' });
    assert.deepEqual(validateBatch({ creator: ['', ' '] }), { 'dcterms:creator': 'required' });
    assert.deepEqual(validateBatch({ licence: 'ccbysa' }), { 'dcterms:license': 'not-http-uri' });
    assert.deepEqual(validateBatch({ creator: ['Ana'], publisher: 'ACME' }), {});
});

test('preview pairs carry only ticked fields, the mode and the selection', () => {
    const ids = previewPairs({
        ticked: { creator: ['Ana', ' ', 'Luis'], licence: 'https://x/by/4.0/' },
        mode: 'replace',
        csrf: 'h',
        selection: { scope: 'ids', ids: ['4', '2'] }
    });
    assert.deepEqual(ids, [
        ['governance[dcterms:license]', 'https://x/by/4.0/'],
        ['governance[dcterms:creator][]', 'Ana'],
        ['governance[dcterms:creator][]', 'Luis'],
        ['mode', 'replace'],
        ['csrf', 'h'],
        ['ids[]', '4'],
        ['ids[]', '2']
    ]);

    const matching = previewPairs({
        ticked: { publisher: 'ACME' },
        mode: 'fill',
        csrf: 'h',
        selection: { scope: 'matching', query: 'title=x&integrity=warning' }
    });
    assert.deepEqual(matching.slice(-2), [['scope', 'matching'], ['query', 'title=x&integrity=warning']]);
});

test('selecting every row on the page offers every matching REA, and back', () => {
    let state = { scope: 'ids', pageCount: 25, checked: 0, totalMatching: 2480 };
    assert.equal(stripModel(state).visible, false);

    state = selectionAfterToggle(state, { type: 'check', checked: 25 });
    assert.deepEqual(stripModel(state), { visible: true, text: 'page-all', action: 'select-matching', count: 2480 });

    state = selectionAfterToggle(state, { type: 'select-matching' });
    assert.equal(state.scope, 'matching');
    assert.deepEqual(stripModel(state), { visible: true, text: 'matching-all', action: 'clear', count: 2480 });

    state = selectionAfterToggle(state, { type: 'check', checked: 24 });
    assert.equal(state.scope, 'ids', 'unticking a row falls back to ids');

    state = selectionAfterToggle({ ...state, scope: 'matching' }, { type: 'clear' });
    assert.deepEqual(state, { scope: 'ids', pageCount: 25, checked: 0, totalMatching: 2480 });
});

test('no strip when the page already holds every matching REA', () => {
    const state = selectionAfterToggle({ scope: 'ids', pageCount: 19, checked: 0, totalMatching: 19 }, { type: 'check', checked: 19 });
    assert.equal(stripModel(state).visible, false);
});

test('preview model gates apply and the overwrite confirmation', () => {
    const fill = previewModel({
        mode: 'fill',
        writeTotal: 3,
        summary: { 'dcterms:license': { total: 4, write: 3, skipped_has_value: 1 } }
    });
    assert.deepEqual(fill.rows, [{ term: 'dcterms:license', write: 3, other: 1, otherKind: 'skipped' }]);
    assert.equal(fill.canApply, true);
    assert.equal(fill.needsOverwriteConfirm, false);

    const replace = previewModel({
        mode: 'replace',
        writeTotal: 4,
        summary: {
            'dcterms:license': { total: 4, write: 4, overwrite: 2 },
            'dcterms:creator': { total: 4, write: 4, overwrite: 1 }
        }
    });
    assert.equal(replace.needsOverwriteConfirm, true);
    assert.equal(replace.overwriteTotal, 3);

    assert.equal(previewModel({ mode: 'fill', writeTotal: 0, summary: {} }).canApply, false);
});

test('result model follows the job state', () => {
    assert.deepEqual(resultModel({ status: 'in_progress', done: 25, total: 100 }), { finished: false, percent: 25, status: 'in_progress' });
    assert.deepEqual(resultModel({ status: 'in_progress', done: 0, total: 0 }), { finished: false, percent: 0, status: 'in_progress' });
    const done = resultModel({
        status: 'completed',
        batch: 'batch-9',
        tallies: { written: 2, skipped: 1, unchanged: 0, failed: [{ id: 7, code: 'denied' }], done: 4, total: 4 }
    });
    assert.equal(done.finished, true);
    assert.equal(done.batch, 'batch-9');
    assert.deepEqual(done.failed, [{ id: 7, code: 'denied' }]);
    assert.equal(resultModel({ status: 'error', code: 'job_died' }).finished, true);
});
