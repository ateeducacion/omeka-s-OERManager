import test from 'node:test';
import assert from 'node:assert/strict';
import { TERM_LABELS } from '../../asset/js/core/drawerModel.js';

test('TERM_LABELS nombra los nueve términos del panel, en orden', () => {
    assert.deepEqual(Object.keys(TERM_LABELS), [
        'dcterms:description', 'lrmi:educationalLevel', 'schema:about', 'lrmi:assesses',
        'lrmi:teaches', 'dcterms:relation', 'schema:isPartOf', 'lrmi:learningResourceType',
        'dcterms:license'
    ]);
    assert.equal(TERM_LABELS['lrmi:teaches'], 'Saberes básicos');
    assert.equal(TERM_LABELS['dcterms:license'], 'Licencia');
    assert.ok(Object.isFrozen(TERM_LABELS));
});
