import { test } from 'node:test';
import assert from 'node:assert/strict';
import { integrityGroups, INTEGRITY_OK_TEXT } from '../../asset/js/core/integrityModel.js';

const issue = (severity, code) => ({ severity, code, field: 'dcterms:rights', message: 'msg ' + code });

test('sin integridad devuelve lista vacía', () => {
    assert.deepEqual(integrityGroups(null), []);
    assert.deepEqual(integrityGroups(undefined), []);
});

test('sin incidencias devuelve lista vacía', () => {
    assert.deepEqual(integrityGroups({ status: 'ok', issues: [] }), []);
});

test('agrupa por severidad', () => {
    const groups = integrityGroups({ status: 'warning', issues: [issue('warning', 'a'), issue('warning', 'b')] });
    assert.equal(groups.length, 1);
    assert.equal(groups[0].severity, 'warning');
    assert.deepEqual(groups[0].issues.map((i) => i.code), ['a', 'b']);
});

test('cada incidencia agrupada tiene solo code, field, message (sin severity redundante)', () => {
    const groups = integrityGroups({ status: 'warning', issues: [issue('warning', 'a')] });
    const incidencia = groups[0].issues[0];
    assert.deepEqual(Object.keys(incidencia).sort(), ['code', 'field', 'message']);
});

test('los errores van antes que los avisos', () => {
    const groups = integrityGroups({
        status: 'error',
        issues: [issue('warning', 'w'), issue('error', 'e')]
    });
    assert.deepEqual(groups.map((g) => g.severity), ['error', 'warning']);
});

test('una severidad desconocida no se pierde: va al final', () => {
    const groups = integrityGroups({ status: 'warning', issues: [issue('rara', 'x'), issue('error', 'e')] });
    assert.deepEqual(groups.map((g) => g.severity), ['error', 'rara']);
});

test('el texto de ficha sana existe y no está vacío', () => {
    assert.equal(typeof INTEGRITY_OK_TEXT, 'string');
    assert.ok(INTEGRITY_OK_TEXT.length > 0);
});
