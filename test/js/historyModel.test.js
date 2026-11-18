import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    historyRows, historyChecked, HISTORY_EMPTY_NOTICE, HISTORY_UNKNOWN_TEXT,
    summaryParts, retiredId, nextShown, historyPage, HISTORY_PAGE, RETIRED_TEXT
} from '../../asset/js/core/historyModel.js';

const row = (changes, extra = {}) => ({
    when: '2026-08-11T10:00:00+00:00',
    contributor: 'fmatdia',
    summary: 'Re-catalogación · lrmi:teaches +1',
    isUndo: false,
    changes,
    ...extra
});

test('un historial vacío da filas vacías', () => {
    assert.deepEqual(historyRows([]), []);
    assert.deepEqual(historyRows(null), []);
});

test('el aviso de cobertura existe y menciona el alcance', () => {
    assert.equal(typeof HISTORY_EMPTY_NOTICE, 'string');
    assert.ok(HISTORY_EMPTY_NOTICE.length > 0);
});

test('traduce el término RDF a su etiqueta del drawer', () => {
    const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].changes[0].label, 'Saberes básicos');
});

test('un término desconocido conserva el término como etiqueta', () => {
    const rows = historyRows([row([{ term: 'ex:loquesea', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].changes[0].label, 'ex:loquesea');
});

test('marca la fila que tiene algún porqué', () => {
    const withReason = historyRows([row([
        { term: 'lrmi:teaches', added: [], removed: [{ title: 'X', reason: 'porque sí' }], emptied: true }
    ])]);
    assert.equal(withReason[0].hasReasons, true);

    const without = historyRows([row([
        { term: 'lrmi:teaches', added: [], removed: [{ title: 'X', reason: '' }], emptied: true }
    ])]);
    assert.equal(without[0].hasReasons, false);
});

test('conserva quién, cuándo y el resumen', () => {
    const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].contributor, 'fmatdia');
    assert.equal(rows[0].when, '2026-08-11T10:00:00+00:00');
    assert.equal(rows[0].summary, 'Re-catalogación · lrmi:teaches +1');
});

test('pasa la etiqueta legible que formatea el servidor', () => {
    const rows = historyRows([row(
        [{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }],
        { whenLabel: '11/08/2026 10:00' }
    )]);
    assert.equal(rows[0].whenLabel, '11/08/2026 10:00');
});

test('sin whenLabel cae al when crudo, nunca a undefined', () => {
    const rows = historyRows([row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }])]);
    assert.equal(rows[0].whenLabel, rows[0].when);
});

test('propaga la marca de reversión', () => {
    const rows = historyRows([
        row([{ term: 'lrmi:teaches', added: ['A'], removed: [], emptied: false }], { isUndo: true })
    ]);
    assert.equal(rows[0].isUndo, true);
});

test('una fila sin cambios no revienta', () => {
    const rows = historyRows([row([])]);
    assert.deepEqual(rows[0].changes, []);
    assert.equal(rows[0].hasReasons, false);
});

/**
 * I4 de la revisión final de rama: `history: null` (id inválido o lectura
 * fallida) y `history: []` (leído, sin curaciones) no pueden compartir
 * pantalla. `historyChecked` es la distinción, simétrica a `integrityChecked`.
 */
test('sin historial (id inválido o lectura fallida) no se ha podido leer', () => {
    assert.equal(historyChecked(null), false);
    assert.equal(historyChecked(undefined), false);
});

test('con un array, sí se ha podido leer, aunque esté vacío', () => {
    assert.equal(historyChecked([]), true);
    assert.equal(historyChecked([row([])]), true);
});

test('el texto de "no se pudo leer" existe y no está vacío', () => {
    assert.equal(typeof HISTORY_UNKNOWN_TEXT, 'string');
    assert.ok(HISTORY_UNKNOWN_TEXT.length > 0);
});

test('el resumen de una fila cuenta añadidos y quitados por dimensión, con etiqueta humana', () => {
    const [r] = historyRows([row([
        { term: 'lrmi:teaches', added: ['A', 'B'], removed: [{ title: 'C', reason: '' }], emptied: false },
        { term: 'dcterms:license', added: ['https://x.test/l'], removed: [], emptied: false }
    ])]);
    assert.deepEqual(summaryParts(r), [
        { label: 'Saberes básicos', added: 2, removed: 1, emptied: false },
        { label: 'Licencia', added: 1, removed: 0, emptied: false }
    ]);
});

test('el curso se llama Curso y los campos de gobernanza tienen etiqueta', () => {
    const [r] = historyRows([row([
        { term: 'lrmi:educationalLevel', added: ['1º ESO'], removed: [], emptied: false },
        { term: 'dcterms:creator', added: ['Ana'], removed: [], emptied: false }
    ])]);
    assert.deepEqual(r.changes.map((c) => c.label), ['Curso', 'Autoría']);
});

test('un título #id es un término retirado; un título normal no', () => {
    assert.equal(retiredId('#404'), '404');
    assert.equal(retiredId('Fracciones (2º ESO)'), null);
    assert.equal(retiredId('#12 cosas'), null);
    assert.ok(RETIRED_TEXT.includes('%s'));
});

test('paginación de 5 en 5 (Review Focus 4)', () => {
    assert.equal(HISTORY_PAGE, 5);
    assert.equal(nextShown(0, 0), 0);
    assert.equal(nextShown(5, 0), 5);
    assert.equal(nextShown(6, 0), 5);
    assert.equal(nextShown(6, 5), 6);
    assert.equal(nextShown(12, 5), 10);
});

test('cada página dice qué filas añadir, dónde poner el foco y si se acabó (revisión final, NFR-006)', () => {
    assert.deepEqual(historyPage(6, 0), { from: 0, to: 5, done: false });
    assert.deepEqual(historyPage(6, 5), { from: 5, to: 6, done: true });
    assert.deepEqual(historyPage(5, 0), { from: 0, to: 5, done: true });
    assert.deepEqual(historyPage(0, 0), { from: 0, to: 0, done: true });
});
