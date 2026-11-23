import test from 'node:test';
import assert from 'node:assert/strict';
import { browseRequest, browseKey, browseRows, browseStatus } from '../../asset/js/core/curriculumBrowse.js';
import { twistyNote } from '../../asset/js/core/leafTreeModel.js';

const t = (text) => text;

test('browseRequest pide los hijos de UN padre, sin texto (spec B8, adenda)', () => {
    assert.deepEqual(browseRequest('etapa'), { dimension: 'etapa', browse: 1 });
    assert.deepEqual(browseRequest('lrmi:educationalLevel', 3), { dimension: 'lrmi:educationalLevel', browse: 1, etapa: ['3'] });
    assert.deepEqual(browseRequest('schema:about', '40'), { dimension: 'schema:about', browse: 1, level: ['40'] });
    // Without a valid parent there is nothing to ask (the server would refuse it).
    assert.equal(browseRequest('schema:about'), null);
    assert.equal(browseRequest('schema:about', 'x'), null);
    assert.equal(browseRequest('dcterms:relation', 3), null);
});

test('browseKey distingue dimensión y padre', () => {
    assert.equal(browseKey('etapa'), 'etapa|');
    assert.equal(browseKey('schema:about', 40), 'schema:about|40');
    assert.notEqual(browseKey('schema:about', 40), browseKey('lrmi:educationalLevel', 40));
});

test('browseRows marca lo ya añadido y acorta el nombre de la materia', () => {
    const results = [
        { id: 500, title: 'Matemáticas · 1º ESO', parentId: 40, parentTitle: '1º ESO' },
        { id: 501, title: 'Física y Química · 1º ESO', parentId: 40, parentTitle: '1º ESO' }
    ];
    const rows = browseRows(results, ['501', '999']);
    assert.deepEqual(rows.map((row) => [row.id, row.name, row.added]), [
        ['500', 'Matemáticas', false],
        ['501', 'Física y Química', true]
    ]);
    assert.equal(rows[0].result, results[0]);
    // Ids may come as numbers; rows without a valid id are dropped.
    assert.deepEqual(browseRows([{ id: 7, title: '1º ESO' }, { title: 'sin id' }], [7]).map((row) => [row.id, row.name, row.added]), [
        ['7', '1º ESO', true]
    ]);
    assert.deepEqual(browseRows(null, []), []);
});

test('browseStatus explica carga, error y lista vacía', () => {
    assert.equal(browseStatus(undefined, t), '');
    assert.equal(browseStatus({ status: 'loading' }, t), 'Cargando…');
    assert.equal(browseStatus({ status: 'error' }, t), 'No se pudo cargar la lista.');
    assert.equal(browseStatus({ status: 'loaded', results: [] }, t), 'Sin resultados');
    assert.equal(browseStatus({ status: 'loaded', results: [{ id: 1 }] }, t), '');
});

test('twistyNote: cuántos sub-saberes agrupa y cuántos hay elegidos dentro', () => {
    const node = (children, selectedBelow) => ({ children: Array(children).fill({}), selectedBelow });
    assert.equal(twistyNote(node(4, 2), 'lrmi:teaches', t), '4 sub-saberes · 2 elegidos');
    assert.equal(twistyNote(node(3, 0), 'lrmi:teaches', t), '3 sub-saberes');
    assert.equal(twistyNote(node(1, 1), 'lrmi:teaches', t), '1 sub-saber · 1 elegido');
    assert.equal(twistyNote(node(2, 0), 'lrmi:assesses', t), '2 sub-criterios');
    assert.equal(twistyNote(node(0, 0), 'lrmi:teaches', t), '');
});
