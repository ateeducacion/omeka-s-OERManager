import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    COURSE, SUBJECT, TEACHES, ASSESSES, CURRICULAR_TERMS, LEAF_TERMS,
    createState, learnTrees, learnSubject, subjectName, placement, summaryText, treeBatches
} from '../../asset/js/core/alignmentTree.js';

const t = (text) => text;

const current = {
    [COURSE]: [{ id: 1, title: '1º ESO' }],
    [SUBJECT]: [{ id: 10, title: 'Matemáticas · 1º ESO' }, { id: 20, title: 'Lengua · 2º ESO' }],
    [TEACHES]: [{ id: 100, title: 'Números' }, { id: 300, title: 'Retirado' }],
    [ASSESSES]: [{ id: 200, title: 'CE1' }]
};

const trees = {
    trees: [
        {
            subject: { id: 10, title: 'Matemáticas · 1º ESO' },
            course: { id: 1, title: '1º ESO' },
            [TEACHES]: [{ id: 99, selectable: false, children: [{ id: 100, selectable: true, children: [] }] }],
            [ASSESSES]: [{ id: 98, selectable: false, children: [{ id: 200, selectable: true, children: [] }] }]
        },
        {
            subject: { id: 20, title: 'Lengua · 2º ESO' },
            course: { id: 2, title: '2º ESO' },
            [TEACHES]: [],
            [ASSESSES]: []
        }
    ],
    invalid: [],
    retired: []
};

test('los cuatro términos curriculares y las hojas', () => {
    assert.deepEqual(CURRICULAR_TERMS, [COURSE, SUBJECT, TEACHES, ASSESSES]);
    assert.deepEqual(LEAF_TERMS, [TEACHES, ASSESSES]);
});

test('el estado parte de los valores del REA, con ids como texto y sin repetidos', () => {
    const state = createState({ [COURSE]: [{ id: 1, title: 'A' }, { id: '1', title: 'A' }, { id: 'x', title: 'no' }] });
    assert.deepEqual(state.values[COURSE], [{ id: '1', title: 'A', why: '', ai: false }]);
    assert.deepEqual(state.initial[COURSE], ['1']);
    assert.deepEqual(state.values[TEACHES], []);
    assert.equal(state.courseTitles['1'], 'A');
});

test('sin árboles cargados, las materias y hojas esperan: no van a «Sin encajar» todavía', () => {
    const view = placement(createState(current));
    assert.equal(view.courses.length, 1);
    assert.deepEqual(view.courses[0].subjects, []);
    assert.deepEqual(view.unfitted.subjects, []);
    assert.deepEqual(view.unfitted[TEACHES], []);
    assert.equal(view.pending, 5);
});

test('con los árboles, cada materia va bajo su curso y cada hoja bajo su materia', () => {
    const view = placement(learnTrees(createState(current), trees));
    const [course] = view.courses;
    assert.equal(course.id, '1');
    assert.deepEqual(course.subjects.map((s) => s.id), ['10']);
    assert.equal(course.subjects[0].name, 'Matemáticas');
    assert.deepEqual(course.subjects[0][TEACHES].map((l) => l.id), ['100']);
    assert.deepEqual(course.subjects[0][ASSESSES].map((l) => l.id), ['200']);
    assert.equal(view.pending, 0);
});

/** Review Focus 1: a subject whose course is not chosen is shown, never dropped. */
test('una materia sin su curso y una hoja sin su materia van a «Sin encajar»', () => {
    const view = placement(learnTrees(createState(current), trees));
    assert.deepEqual(view.unfitted.subjects.map((s) => s.id), ['20']);
    assert.equal(view.unfitted.subjects[0].name, 'Lengua');
    assert.deepEqual(view.unfitted[TEACHES].map((l) => l.id), ['300']);
});

test('una materia del buscador trae su curso', () => {
    const state = learnSubject(createState({}), { id: 30, title: 'Física · 1º ESO', parentId: 1, parentTitle: '1º ESO' });
    assert.equal(state.courseOf['30'], '1');
    assert.equal(state.courseTitles['1'], '1º ESO');
});

test('una materia inválida cuenta como cargada y sin curso', () => {
    const state = learnTrees(createState({ [SUBJECT]: [{ id: 40, title: 'X' }] }), { trees: [], invalid: [40], retired: [] });
    assert.equal(state.loaded['40'], true);
    assert.deepEqual(placement(state).unfitted.subjects.map((s) => s.id), ['40']);
});

test('el nombre de la materia sin el curso que ya la encabeza', () => {
    assert.equal(subjectName('Matemáticas · 1º ESO', '1º ESO'), 'Matemáticas');
    assert.equal(subjectName('Matemáticas · 1º ESO', ''), 'Matemáticas · 1º ESO');
    assert.equal(subjectName('Física', '1º ESO'), 'Física');
});

test('los resúmenes de grupo, en singular y plural', () => {
    const view = placement(learnTrees(createState(current), trees));
    assert.equal(summaryText('course', view.courses[0], t), '1 materia');
    assert.equal(summaryText('subject', view.courses[0].subjects[0], t), '1 saber, 1 criterio');
    assert.equal(summaryText('subject', { [TEACHES]: [1, 2, 3], [ASSESSES]: [] }, t), '3 saberes, 0 criterios');
    assert.equal(summaryText('course', { subjects: [] }, t), '0 materias');
});

/** Review Focus 4: the server answers at most 10 subjects per request. */
test('los árboles se piden en lotes de 10 materias', () => {
    const ids = Array.from({ length: 23 }, (_, i) => String(i + 1));
    assert.deepEqual(treeBatches(ids).map((batch) => batch.length), [10, 10, 3]);
    assert.deepEqual(treeBatches([]), []);
});
