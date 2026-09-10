import test from 'node:test';
import assert from 'node:assert/strict';
import { valueText } from '../../asset/js/core/values.js';
import { drawerRows, drawerTitle, DRAWER_FIELDS } from '../../asset/js/core/drawerModel.js';

test('valueText prefiere el título del recurso enlazado', () => {
    assert.equal(valueText({ display_title: 'Matemáticas', '@value': 'x' }), 'Matemáticas');
});

test('valueText cae al literal y luego a la etiqueta de la URI', () => {
    assert.equal(valueText({ '@value': 'ccbysa' }), 'ccbysa');
    assert.equal(valueText({ 'o:label': 'CC BY-SA' }), 'CC BY-SA');
    assert.equal(valueText({}), '');
});

test('los nueve campos del drawer se conservan y en orden', () => {
    assert.equal(DRAWER_FIELDS.length, 9);
    assert.deepEqual(DRAWER_FIELDS[0], ['dcterms:description', 'Descripción']);
    assert.deepEqual(DRAWER_FIELDS[8], ['dcterms:license', 'Licencia']);
});

test('valueText enseña la URI de un valor URI sin etiqueta (ADR-0019)', () => {
    const uri = 'https://creativecommons.org/licenses/by/4.0/';
    assert.equal(valueText({ '@id': uri }), uri);
    assert.equal(valueText({ '@id': uri, 'o:label': 'CC BY 4.0' }), 'CC BY 4.0');
    // Un valor de recurso también trae @id (la URL de la API): no es texto.
    assert.equal(valueText({ '@id': 'http://x/api/items/5', value_resource_id: 5, display_title: '' }), '');
});

test('drawerRows une los valores múltiples con coma', () => {
    const rows = drawerRows({
        'schema:about': [{ display_title: 'Matemáticas' }, { display_title: 'Física' }]
    });
    assert.equal(rows.length, 1);
    assert.equal(rows[0].term, 'schema:about');
    assert.equal(rows[0].label, 'Materia');
    assert.equal(rows[0].text, 'Matemáticas, Física');
});

test('drawerRows omite los campos vacíos (comportamiento actual)', () => {
    const rows = drawerRows({ 'dcterms:description': [] });
    assert.deepEqual(rows, []);
});

test('drawerTitle usa o:title y cae a dcterms:title', () => {
    assert.equal(drawerTitle({ 'o:title': 'Célula' }), 'Célula');
    assert.equal(drawerTitle({ 'dcterms:title': [{ '@value': 'Célula' }] }), 'Célula');
    assert.equal(drawerTitle({}), '');
});
