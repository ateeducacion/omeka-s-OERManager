import test from 'node:test';
import assert from 'node:assert/strict';
import { parseRecatalog, valuesFor, proposalNotice } from '../../asset/js/core/anchorData.js';

const closed = { allowed: false, reason: '', current: {}, aiProposal: null };

test('lee la decisión y el prellenado que sirve drawer-details', () => {
    const raw = JSON.stringify({
        allowed: true,
        reason: '',
        current: { 'lrmi:teaches': [{ id: 11, title: 'Saber' }], 'lrmi:assesses': [] }
    });
    assert.deepEqual(parseRecatalog(raw), {
        allowed: true,
        reason: '',
        current: { 'lrmi:teaches': [{ id: 11, title: 'Saber' }], 'lrmi:assesses': [] },
        aiProposal: null
    });
});

test('una negativa trae su motivo y nunca abre el editor', () => {
    const raw = JSON.stringify({ allowed: false, reason: 'REA descartado.', current: {} });
    assert.deepEqual(parseRecatalog(raw), { allowed: false, reason: 'REA descartado.', current: {}, aiProposal: null });
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
        },
        aiProposal: null
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

test('parseRecatalog keeps a well-formed stored AI proposal and drops anything else', () => {
    const raw = (aiProposal) => JSON.stringify({ allowed: true, reason: '', current: {}, aiProposal });
    const good = {
        created: '2026-11-25T10:31:07+00:00',
        changed: true,
        alignment: { 'lrmi:teaches': [{ id: 7, title: 'Saber' }, { id: 'x', title: 'mal' }] },
        justifications: { 'lrmi:teaches': { 7: 'Porque sí' } }
    };
    assert.deepEqual(parseRecatalog(raw(good)).aiProposal, {
        created: '2026-11-25T10:31:07+00:00',
        changed: true,
        alignment: { 'lrmi:teaches': [{ id: 7, title: 'Saber' }] },
        justifications: { 'lrmi:teaches': { 7: 'Porque sí' } }
    });
    assert.equal(parseRecatalog(raw(null)).aiProposal, null);
    assert.equal(parseRecatalog(raw({ alignment: {} })).aiProposal, null);
    assert.equal(parseRecatalog(raw('x')).aiProposal, null);
    assert.equal(parseRecatalog('').aiProposal, null);
});

test('proposalNotice dates the proposal and warns when the REA changed', () => {
    assert.equal(proposalNotice({ created: '2026-11-25T10:31:07+00:00', changed: false }), 'Propuesta IA del 25/11/2026.');
    assert.equal(
        proposalNotice({ created: '2026-11-25T10:31:07+00:00', changed: true }),
        'Propuesta IA del 25/11/2026. El REA ha cambiado desde la propuesta.'
    );
});
