import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    COURSE, SUBJECT, TEACHES, ASSESSES, RELATION, CURRICULAR_TERMS, LEAF_TERMS,
    changedTerms, alignmentPairs, mergeProposal, groupedDiff, changeCounts, aiCount, focusSubject, setDelta, diffCounts,
    createState, learnTrees, learnSubject, subjectName, placement, summaryText, treeBatches,
    addCourse, addSubject, setLeaf, removeValue, removeSubject, removeCourse
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

const base = () => learnTrees(createState(current), trees);

test('añadir un curso, una materia y una hoja; repetir no duplica', () => {
    let state = addCourse(createState({}), { id: 2, title: '2º ESO' });
    state = addCourse(state, { id: 2, title: '2º ESO' });
    state = addSubject(state, { id: 20, title: 'Lengua · 2º ESO' }, '2');
    state = setLeaf(state, TEACHES, { id: 500, title: 'Oración', subjectId: '20' }, true);
    assert.deepEqual(state.values[COURSE].map((c) => c.id), ['2']);
    assert.equal(state.courseOf['20'], '2');
    assert.equal(state.subjectOf['500'], '20');
    const view = placement({ ...state, loaded: { 20: true } });
    assert.deepEqual(view.courses[0].subjects[0][TEACHES].map((l) => l.id), ['500']);
});

test('desmarcar una hoja la quita y no toca las demás', () => {
    const state = setLeaf(base(), TEACHES, { id: 100, title: 'Números', subjectId: '10' }, false);
    assert.deepEqual(state.values[TEACHES].map((l) => l.id), ['300']);
    assert.deepEqual(state.values[ASSESSES].map((l) => l.id), ['200']);
});

/** Review Focus 2: only the leaves of THAT subject go with it. */
test('quitar una materia se lleva solo sus hojas', () => {
    let state = base();
    state = addSubject(state, { id: 11, title: 'Matemáticas · 2º ESO' }, '2');
    state = setLeaf(state, TEACHES, { id: 110, title: 'Números 2', subjectId: '11' }, true);
    const { state: next, removed } = removeSubject(state, '10');
    assert.equal(removed, 2);
    assert.deepEqual(next.values[SUBJECT].map((s) => s.id), ['20', '11']);
    assert.deepEqual(next.values[TEACHES].map((l) => l.id), ['300', '110']);
    assert.deepEqual(next.values[ASSESSES], []);
});

test('quitar un curso se lleva sus materias y las hojas de ellas', () => {
    const { state, removed } = removeCourse(base(), '1');
    assert.equal(removed, 3);
    assert.deepEqual(state.values[COURSE], []);
    assert.deepEqual(state.values[SUBJECT].map((s) => s.id), ['20']);
    assert.deepEqual(state.values[TEACHES].map((l) => l.id), ['300']);
});

test('quitar un valor suelto no toca a los demás términos', () => {
    const state = removeValue(base(), TEACHES, '300');
    assert.deepEqual(state.values[TEACHES].map((l) => l.id), ['100']);
    assert.deepEqual(state.values[SUBJECT].map((s) => s.id), ['10', '20']);
});

test('lo añadido por la IA lleva su marca y su porqué', () => {
    const state = setLeaf(createState({}), ASSESSES, { id: 7, title: 'CE7' }, true, { ai: true, why: 'Porque sí' });
    assert.deepEqual(state.values[ASSESSES], [{ id: '7', title: 'CE7', why: 'Porque sí', ai: true }]);
});

/** Review Focus 1 and 3: nothing touched, nothing sent; same set, nothing sent. */
test('sin cambios no se envía nada, aunque se quite y se vuelva a poner', () => {
    let state = base();
    assert.deepEqual(changedTerms(state), []);
    assert.deepEqual(alignmentPairs(state), []);
    state = setLeaf(state, TEACHES, { id: 100, title: 'Números', subjectId: '10' }, false);
    state = setLeaf(state, TEACHES, { id: 100, title: 'Números', subjectId: '10' }, true);
    assert.deepEqual(alignmentPairs(state), []);
});

test('solo se envían los términos que cambian, con su lista entera', () => {
    const state = setLeaf(base(), TEACHES, { id: 101, title: 'Fracciones', subjectId: '10' }, true);
    assert.deepEqual(alignmentPairs(state), [
        { name: 'alignment[lrmi:teaches][]', value: '100' },
        { name: 'alignment[lrmi:teaches][]', value: '300' },
        { name: 'alignment[lrmi:teaches][]', value: '101' }
    ]);
});

test('un término vaciado se envía vacío, como borrado explícito', () => {
    const { state } = removeCourse(base(), '1');
    assert.deepEqual(
        alignmentPairs(state).filter((pair) => pair.name === 'alignment[lrmi:educationalLevel][]'),
        [{ name: 'alignment[lrmi:educationalLevel][]', value: '' }]
    );
});

test('el porqué de la IA viaja solo con saberes y criterios que lo tienen', () => {
    const state = setLeaf(base(), ASSESSES, { id: 201, title: 'CE2', subjectId: '10' }, true, { ai: true, why: 'Evalúa X' });
    assert.deepEqual(alignmentPairs(state), [
        { name: 'alignment[lrmi:assesses][]', value: '200' },
        { name: 'alignment[lrmi:assesses][]', value: '201' },
        { name: 'justification[lrmi:assesses][201]', value: 'Evalúa X' }
    ]);
});

/** Review Focus 5: the AI fills the same tree; what does not fit is still shown. */
test('la propuesta de la IA crea lo que falta, con su marca, sin duplicar', () => {
    const { state, added } = mergeProposal(base(), {
        [COURSE]: [{ id: 1, title: '1º ESO' }],
        [SUBJECT]: [{ id: 30, title: 'Física · 3º ESO' }],
        [TEACHES]: [{ id: 400, title: 'Energía' }],
        [RELATION]: [{ id: 9, title: 'Eje' }]
    }, { [TEACHES]: { 400: 'Trata la energía' } });
    assert.equal(added, 2);
    assert.deepEqual(state.values[SUBJECT].slice(-1), [{ id: '30', title: 'Física · 3º ESO', why: '', ai: true }]);
    assert.deepEqual(state.values[TEACHES].slice(-1), [{ id: '400', title: 'Energía', why: 'Trata la energía', ai: true }]);
    const view = placement(learnTrees(state, { trees: [{
        subject: { id: 30, title: 'Física · 3º ESO' }, course: { id: 3, title: '3º ESO' },
        [TEACHES]: [{ id: 400, selectable: true, children: [] }], [ASSESSES]: []
    }], invalid: [], retired: [] }));
    // Lengua (20) was already unfitted in base(); the proposed Física joins it, marked.
    assert.deepEqual(view.unfitted.subjects.map((s) => [s.id, s.ai]), [['20', false], ['30', true]]);
});

test('el diff se agrupa por curso, con la materia de cada hoja y el eje aparte', () => {
    let state = setLeaf(base(), TEACHES, { id: 101, title: 'Fracciones', subjectId: '10' }, true);
    state = removeValue(state, TEACHES, '300');
    const { sections, hasInvalid } = groupedDiff({
        [TEACHES]: { added: [101], removed: [300], invalid: [], titles: { 101: 'Fracciones', 300: 'Retirado' } },
        [RELATION]: { added: [9], removed: [], invalid: [], titles: { 9: 'Eje' } }
    }, state);
    assert.equal(hasInvalid, false);
    assert.deepEqual(sections, [
        { kind: 'course', title: '1º ESO', lines: [{ sign: '+', term: TEACHES, subject: 'Matemáticas', title: 'Fracciones' }] },
        { kind: 'unfitted', title: '', lines: [{ sign: '−', term: TEACHES, subject: '', title: 'Retirado' }] },
        { kind: 'relation', title: '', lines: [{ sign: '+', term: RELATION, subject: '', title: 'Eje' }] }
    ]);
});

test('un id inválido se señala y bloquea la confirmación', () => {
    const { hasInvalid, sections } = groupedDiff({ [COURSE]: { added: [], removed: [], invalid: [5], titles: {} } }, base());
    assert.equal(hasInvalid, true);
    assert.deepEqual(sections[0].lines, [{ sign: '!', term: COURSE, subject: '', title: '#5' }]);
});

/** Review Focus 4: the footer counts what will be sent, by set. */
test('el pie cuenta añadidos y quitados como conjuntos', () => {
    let state = base();
    assert.deepEqual(changeCounts(state), { added: 0, removed: 0 });
    state = setLeaf(state, TEACHES, { id: 101, title: 'Fracciones', subjectId: '10' }, true);
    state = setLeaf(state, ASSESSES, { id: 200, title: 'CE1', subjectId: '10' }, false);
    assert.deepEqual(changeCounts(state), { added: 1, removed: 1 });
    state = setLeaf(state, ASSESSES, { id: 200, title: 'CE1', subjectId: '10' }, true);
    assert.deepEqual(changeCounts(state), { added: 1, removed: 0 });
});

test('la cabecera cuenta lo que propuso la IA', () => {
    const { state } = mergeProposal(base(), { [TEACHES]: [{ id: 400, title: 'Energía' }], [SUBJECT]: [{ id: 30, title: 'Física' }] }, {});
    assert.equal(aiCount(state), 2);
    assert.equal(aiCount(base()), 0);
});

/** Review Focus 5: no subject, no focus; a removed focus moves to the first subject. */
test('la materia en foco sigue mientras exista y si no pasa a la primera', () => {
    const layout = placement(base());
    assert.equal(focusSubject(layout, '10'), '10');
    assert.equal(focusSubject(layout, '99'), '10');
    assert.equal(focusSubject(layout, null), '10');
    assert.equal(focusSubject(placement(createState({})), null), null);
});

/** Final review B8, I3: the axis is counted like the rest, by set. */
test('la diferencia entre dos conjuntos de ids', () => {
    assert.deepEqual(setDelta(['1', '2'], ['2', '3', '4']), { added: 2, removed: 1 });
    assert.deepEqual(setDelta([1, 2], ['2', '1']), { added: 0, removed: 0 });
    assert.deepEqual(setDelta([], []), { added: 0, removed: 0 });
});

/** Final review B8, I3: the review summary is the server's diff, axis included. */
test('el resumen de la revisión suma el diff del servidor, eje incluido', () => {
    assert.deepEqual(diffCounts({
        [TEACHES]: { added: [1, 2], removed: [3], invalid: [] },
        [RELATION]: { added: [9], removed: [], invalid: [] }
    }), { added: 3, removed: 1 });
    assert.deepEqual(diffCounts(null), { added: 0, removed: 0 });
});

