import test from 'node:test';
import assert from 'node:assert/strict';
import { TERM_LABELS } from '../../asset/js/core/drawerModel.js';

test('TERM_LABELS nombra los términos del panel y de gobernanza, en orden', () => {
    assert.deepEqual(Object.keys(TERM_LABELS), [
        'dcterms:description', 'lrmi:educationalLevel', 'schema:about', 'lrmi:assesses',
        'lrmi:teaches', 'dcterms:relation', 'schema:isPartOf', 'lrmi:learningResourceType',
        'dcterms:license', 'dcterms:creator', 'dcterms:publisher', 'dcterms:rightsHolder', 'dcterms:source'
    ]);
    assert.equal(TERM_LABELS['lrmi:educationalLevel'], 'Curso', 'ADR-0022: el REA enlaza cursos, no etapas');
    assert.equal(TERM_LABELS['lrmi:teaches'], 'Saberes básicos');
    assert.equal(TERM_LABELS['dcterms:license'], 'Licencia');
    assert.ok(Object.isFrozen(TERM_LABELS));
});
