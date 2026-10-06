import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    renderBarList,
    renderStageCounts,
    filterSubjectGroups,
    stageOptions,
    courseOptions,
    renderSubjectCounts
} from '../../../asset/js/stats/groupedCounts.js';

// Shape of SubjectCounts::build().
const subjects = [
    {
        stageId: 3,
        label: 'ESO',
        courses: [
            { courseId: 30, label: '1º ESO', subjects: [{ label: 'Educación física', count: 1 }] },
            { courseId: 31, label: '2º ESO', subjects: [{ label: 'Matemáticas', count: 2 }, { label: 'Educación física', count: 1 }] }
        ]
    },
    { stageId: 2, label: 'Primaria', courses: [{ courseId: 20, label: '1º Primaria', subjects: [{ label: 'Matemáticas', count: 4 }] }] },
    { stageId: null, label: 'Sin curso', courses: [{ courseId: null, label: 'Sin curso', subjects: [{ label: 'Huérfana', count: 1 }] }] }
];

test('renderBarList pinta etiqueta, barra proporcional y número en HTML', () => {
    const html = renderBarList([{ label: 'CC BY', count: 4 }, { label: 'CC0', count: 1 }]);
    assert.match(html, /<ul class="oer-stats-bars">/);
    assert.match(html, /width: 100%/);
    assert.match(html, /width: 25%/);
    assert.match(html, /oer-stats-bar-count">4</);
    assert.doesNotMatch(html, /<svg/);
    assert.match(renderBarList([]), /oer-stats-empty/);
    assert.doesNotMatch(renderBarList([{ label: '<i>', count: 1 }]), /<i>/);
});

test('renderStageCounts agrupa por etapa en el orden recibido, con su total de REA', () => {
    const html = renderStageCounts([
        { stageId: 2, label: 'Primaria', total: 1, courses: [{ courseId: 20, label: '1º Primaria', count: 1 }] },
        { stageId: 3, label: 'ESO', total: 3, courses: [{ courseId: 30, label: '1º ESO', count: 0 }, { courseId: 31, label: '2º ESO', count: 3 }] }
    ]);
    assert.ok(html.indexOf('Primaria') < html.indexOf('ESO'));
    assert.ok(html.indexOf('1º ESO') < html.indexOf('2º ESO'));
    assert.match(html, /ESO <span class="oer-stats-total">3 REA</);
});

test('filterSubjectGroups por etapa y por curso, como SubjectCounts::filter()', () => {
    assert.deepEqual(filterSubjectGroups(subjects, '', ''), subjects);
    assert.deepEqual(filterSubjectGroups(subjects, '3', '').map((g) => g.label), ['ESO']);
    const byCourse = filterSubjectGroups(subjects, '', '31');
    assert.equal(byCourse.length, 1);
    assert.deepEqual(byCourse[0].courses.map((c) => c.label), ['2º ESO']);
    assert.deepEqual(filterSubjectGroups(subjects, '2', '31'), []);
});

test('stageOptions y courseOptions ignoran el grupo sin curso y siguen la etapa', () => {
    assert.deepEqual(stageOptions(subjects), [{ id: 3, label: 'ESO' }, { id: 2, label: 'Primaria' }]);
    assert.deepEqual(courseOptions(subjects, '3').map((c) => c.id), [30, 31]);
    assert.deepEqual(courseOptions(subjects, '').map((c) => c.id), [30, 31, 20]);
});

test('renderSubjectCounts nunca muestra una materia sin su curso', () => {
    const html = renderSubjectCounts(subjects);
    const first = html.indexOf('Educación física');
    assert.ok(html.indexOf('<h5>1º ESO</h5>') < first);
    assert.equal(html.split('>Educación física</span>').length - 1, 2);
    assert.match(renderSubjectCounts([]), /oer-stats-empty/);
});
