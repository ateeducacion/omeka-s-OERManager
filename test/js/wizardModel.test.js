import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  missing, missingItems, firstIncomplete, summary, savePayload, recoverable, storageKey,
  subjectGroups, treeGroups, licenceFor, formatBytes, emptyState, fromItem, isWebLink, withFirstValue, typeGroups, blockOpen, axisList,
} from '../../asset/js/core/wizardModel.js';

const schema = { data: [
  { term: 'dcterms:title', label: 'Título', required: true, control: 'text' },
  { term: 'lrmi:learningResourceType', label: 'Tipo de recurso', required: true, control: 'cards' },
  { term: 'dcterms:license', label: 'Licencia', required: false, control: 'licence' },
] };
const required = ['dcterms:title', 'lrmi:learningResourceType'];

function complete() {
  const state = emptyState();
  state.resource = { kind: 'upload', token: 'a'.repeat(32), name: 'clase.elpx' };
  state.values = { 'dcterms:title': ['Fracciones'], 'lrmi:learningResourceType': ['Vídeo'],
    'dcterms:license': ['https://creativecommons.org/licenses/by-sa/4.0/'] };
  state.stage = { id: 3, title: 'ESO' };
  state.courses = [{ id: 11, title: '1.º ESO' }, { id: 12, title: '2.º ESO' }];
  state.subject = { name: 'Matemáticas', ids: [21, 22] };
  state.teaches = [{ id: 31, title: 'Fracciones', code: 'MAT.1.A.1' }, { id: 32, title: 'b' }, { id: 33, title: 'c' }];
  state.assesses = [{ id: 41, title: 'Criterio' }];
  return state;
}

test('missing mirrors MinimumRule codes and order', () => {
  assert.deepEqual(missing(complete(), required), []);
  assert.deepEqual(missing(emptyState(), required),
    ['resource', 'field:dcterms:title', 'field:lrmi:learningResourceType', 'licence', 'course', 'subject']);
  const blank = complete();
  blank.values['dcterms:title'] = ['   '];
  assert.deepEqual(missing(blank, required), ['field:dcterms:title']);
  assert.deepEqual(missing(complete(), [...required, 'lrmi:assesses']), []);
  const noCriteria = complete();
  noCriteria.assesses = [];
  assert.deepEqual(missing(noCriteria, ['lrmi:assesses']), ['field:lrmi:assesses']);
});

test('«Le falta» items name the field and point at its step', () => {
  const items = missingItems(['resource', 'field:dcterms:title', 'licence', 'course', 'subject', 'field:lrmi:assesses'], schema);
  assert.deepEqual(items.map((i) => [i.label, i.step, i.sub]), [
    ['El recurso: un fichero o un enlace', 1, null],
    ['Título', 2, null],
    ['La licencia', 2, null],
    ['El curso', 3, 2],
    ['La materia', 3, 3],
    ['Los criterios de evaluación', 3, 5],
  ]);
});

test('the wizard reopens at the first incomplete step', () => {
  assert.deepEqual(firstIncomplete(emptyState(), required), { step: 1, sub: 1 });
  const s = complete();
  s.courses = [];
  s.subject = null;
  assert.deepEqual(firstIncomplete(s, required), { step: 3, sub: 2 });
  assert.deepEqual(firstIncomplete(complete(), required), { step: 4, sub: 1 });
});

test('the summary reads like the prototype', () => {
  assert.equal(summary(complete()), 'Para 1.º ESO y 2.º ESO · Matemáticas · 3 saberes · 1 criterio');
  assert.equal(summary(emptyState()), 'Todavía no has elegido etapa, curso ni materia.');
  const s = emptyState();
  s.stage = { id: 3, title: 'ESO' };
  s.axes = [{ id: 70, title: 'Sostenibilidad' }];
  assert.equal(summary(s), 'ESO · 1 eje temático');
});

test('the save payload carries values, resource and every anchoring term', () => {
  const s = complete();
  s.id = 100;
  s.axes = [{ id: 70, title: 'x' }];
  assert.deepEqual(savePayload(s, true), {
    id: 100,
    values: s.values,
    resource: { kind: 'upload', token: 'a'.repeat(32) },
    alignment: {
      'lrmi:educationalLevel': [11, 12], 'schema:about': [21, 22], 'lrmi:teaches': [31, 32, 33],
      'lrmi:assesses': [41], 'dcterms:relation': [70],
    },
    propose: true,
  });
  const kept = complete();
  kept.resource = { kind: 'keep', name: 'old.pdf' };
  assert.deepEqual(savePayload(kept, false).resource, { kind: 'keep' });
  const url = complete();
  url.resource = { kind: 'url', url: ' https://example.org/r ' };
  assert.deepEqual(savePayload(url, false).resource, { kind: 'url', url: 'https://example.org/r' });
  assert.equal(savePayload(emptyState(), false).resource, null);
});

test('a newer local state is offered, an older or identical one is not', () => {
  const local = { savedAt: 2000, state: complete() };
  assert.equal(recoverable(local, 1000), true);
  assert.equal(recoverable(local, 3000), false);
  assert.equal(recoverable(null, 0), false);
  assert.equal(recoverable({ savedAt: 'x' }, 0), false);
  assert.equal(storageKey(7, null), 'oer-wizard:7:new');
  assert.equal(storageKey(7, 100), 'oer-wizard:7:100');
});

test('subjects of several courses are grouped by name', () => {
  const rows = [
    { id: 21, title: 'Matemáticas · 1.º ESO', parentTitle: '1.º ESO' },
    { id: 22, title: 'Matemáticas · 2.º ESO', parentTitle: '2.º ESO' },
    { id: 23, title: 'Lengua · 1.º ESO', parentTitle: '1.º ESO' },
  ];
  assert.deepEqual(subjectGroups(rows), [
    { name: 'Lengua', ids: [23] },
    { name: 'Matemáticas', ids: [21, 22] },
  ]);
});

test('curriculum trees flatten into blocks of selectable terms', () => {
  const trees = [{ subject: { id: 21, title: 'M' }, 'lrmi:teaches': [
    { id: 1, notation: 'A', title: 'Sentido numérico', selectable: false, children: [
      { id: 31, notation: 'MAT.1.A.1', title: 'Fracciones', selectable: true, children: [
        { id: 35, notation: 'MAT.1.A.1.1', title: 'Equivalencia', selectable: true, children: [] },
      ] },
    ] },
  ] }, { subject: { id: 22, title: 'M2' }, 'lrmi:teaches': [
    { id: 2, notation: 'A', title: 'Sentido numérico', selectable: false, children: [
      { id: 31, notation: 'MAT.1.A.1', title: 'Fracciones', selectable: true, children: [] },
    ] },
  ] }];
  assert.deepEqual(treeGroups(trees, 'lrmi:teaches'), [
    { block: 'A · Sentido numérico', items: [
      { id: 31, code: 'MAT.1.A.1', title: 'Fracciones' },
      { id: 35, code: 'MAT.1.A.1.1', title: 'Equivalencia' },
    ] },
  ]);
  assert.deepEqual(treeGroups(trees, 'lrmi:teaches', 'equiv'), [
    { block: 'A · Sentido numérico', items: [{ id: 35, code: 'MAT.1.A.1.1', title: 'Equivalencia' }] },
  ]);
});

test('a licence declared in an eXeLearning package maps to the vocabulary', () => {
  const options = [
    { uri: 'https://creativecommons.org/licenses/by/4.0/', label: 'CC BY 4.0' },
    { uri: 'https://creativecommons.org/licenses/by-sa/4.0/', label: 'CC BY-SA 4.0' },
    { uri: 'https://creativecommons.org/licenses/by-nc-sa/4.0/', label: 'CC BY-NC-SA 4.0' },
    { uri: 'https://creativecommons.org/publicdomain/zero/1.0/', label: 'CC0' },
  ];
  assert.equal(licenceFor('creative commons: attribution - share alike 4.0', options), options[1].uri);
  assert.equal(licenceFor('creative commons: attribution - non commercial - share alike 4.0', options), options[2].uri);
  assert.equal(licenceFor('creative commons: attribution 4.0', options), options[0].uri);
  assert.equal(licenceFor('public domain', options), options[3].uri);
  assert.equal(licenceFor('CC BY-SA 4.0', options), options[1].uri);
  assert.equal(licenceFor('propietary license', options), '');
  assert.equal(licenceFor('', options), '');
});

test('bytes read in the teacher’s units', () => {
  assert.equal(formatBytes(4200000), '4,2 MB');
  assert.equal(formatBytes(64 * 1024 * 1024), '67,1 MB');
  assert.equal(formatBytes(900), '900 bytes');
  assert.equal(formatBytes(0), '');
});

test('a resumed REA becomes the wizard state', () => {
  const state = fromItem({
    id: 100, status: 'Rechazado', rejection: 'Anclaje incompleto', stage: 3,
    values: { 'dcterms:title': ['Fracciones'] },
    media: { name: 'clase.elpx', size: 4200, mediaType: 'application/zip' },
    alignment: {
      'lrmi:educationalLevel': [{ id: 11, title: '2.º ESO' }],
      'schema:about': [{ id: 21, title: 'Matemáticas · 2.º ESO' }],
      'lrmi:teaches': [{ id: 31, title: 'Fracciones' }],
      'dcterms:relation': [{ id: 70, title: 'Sostenibilidad' }],
    },
  });
  assert.equal(state.id, 100);
  assert.equal(state.rejection, 'Anclaje incompleto');
  assert.deepEqual(state.resource, { kind: 'keep', name: 'clase.elpx', size: 4200, mediaType: 'application/zip' });
  assert.deepEqual(state.stage, { id: 3, title: '' });
  assert.deepEqual(state.courses, [{ id: 11, title: '2.º ESO' }]);
  assert.deepEqual(state.subject, { name: 'Matemáticas', ids: [21] });
  assert.deepEqual(state.teaches, [{ id: 31, title: 'Fracciones' }]);
  assert.deepEqual(state.axes, [{ id: 70, title: 'Sostenibilidad' }]);
});

test('a licence is only read from Creative Commons or public-domain wording', () => {
  const options = [{ uri: 'https://creativecommons.org/licenses/by/4.0/' }];
  assert.equal(licenceFor('Copyright, all rights reserved by Departamento X', options), '');
  assert.equal(licenceFor('todos los derechos reservados por el centro', options), '');
  assert.equal(licenceFor('Reconocimiento 4.0', options), options[0].uri);
});

test('a resumed REA without data or with a link keeps a usable state', () => {
  const state = fromItem({ id: 5, values: [], alignment: [], link: 'https://example.org/r', media: null });
  assert.deepEqual(state.values, {});
  state.values['dcterms:title'] = ['Nuevo'];
  assert.match(JSON.stringify(savePayload(state, false)), /"dcterms:title":\["Nuevo"\]/);
  assert.deepEqual(state.resource, { kind: 'url', url: 'https://example.org/r' });
});

test('links are checked as the server checks them', () => {
  assert.equal(isWebLink('https://ejemplo.es/recursos/matemáticas/'), true);
  assert.equal(isWebLink('http://example.org'), true);
  for (const bad of ['https://', 'javascript:alert(1)', 'https://a b', 'ftp://x.org', 'https://x.org/<b>']) {
    assert.equal(isWebLink(bad), false, bad);
  }
});

test('editing the first value keeps the others', () => {
  assert.deepEqual(withFirstValue(['Ana', 'Luis', 'Eva'], 'Ana María'), ['Ana María', 'Luis', 'Eva']);
  assert.deepEqual(withFirstValue(['Ana', 'Luis'], ''), ['Luis']);
  assert.deepEqual(withFirstValue(undefined, 'Ana'), ['Ana']);
  assert.deepEqual(withFirstValue([], ''), []);
});

test('resource types are grouped by the text before the first colon', () => {
  const groups = typeGroups([
    { value: 'Contenidos multimedia: Vídeos (tutoriales, lecciones, documentales)' },
    { uri: 'https://example.org/t/2', label: 'Actividades: Cuestionario' },
    { value: 'Contenidos multimedia: Podcast' },
    { value: 'Sin categoría' },
    { value: 'Actividades: Juego: de rol' },
  ]);
  assert.deepEqual(groups, [
    { group: 'Contenidos multimedia', items: [
      { value: 'Contenidos multimedia: Vídeos (tutoriales, lecciones, documentales)', label: 'Vídeos (tutoriales, lecciones, documentales)' },
      { value: 'Contenidos multimedia: Podcast', label: 'Podcast' },
    ] },
    { group: 'Actividades', items: [
      { value: 'https://example.org/t/2', label: 'Cuestionario' },
      { value: 'Actividades: Juego: de rol', label: 'Juego: de rol' },
    ] },
    { group: 'Otros', items: [{ value: 'Sin categoría', label: 'Sin categoría' }] },
  ]);
  assert.deepEqual(typeGroups([]), []);
});

test('a block opens with a ticked term, with matches of a search, or when it is the only one', () => {
  const block = { block: 'I. Comunicación', items: [{ id: 1 }, { id: 2 }] };
  assert.equal(blockOpen(block, { checked: [2], query: '', single: false }), true);
  assert.equal(blockOpen(block, { checked: [], query: 'poder', single: false }), true);
  assert.equal(blockOpen(block, { checked: [], query: '', single: true }), true);
  assert.equal(blockOpen(block, { checked: [9], query: '  ', single: false }), false);
  // What the user did with the block wins, except that a search shows its matches.
  assert.equal(blockOpen(block, { checked: [], query: '', single: false, user: true }), true);
  assert.equal(blockOpen(block, { checked: [2], query: '', single: false, user: false }), false);
  assert.equal(blockOpen(block, { checked: [], query: 'x', single: false, user: false }), true);
});

test('axes are filtered without accents, sorted and split by initial letter', () => {
  const axes = [
    { id: 3, title: 'Sostenibilidad', description: 'Medio ambiente' },
    { id: 1, title: 'Arte y acción cultural' },
    { id: 2, title: 'Ámbito digital' },
    { id: 4, title: 'Salud emocional' },
  ];
  assert.deepEqual(axisList(axes, '').map((g) => [g.letter, g.items.map((a) => a.id)]), [['A', [2, 1]], ['S', [4, 3]]]);
  assert.deepEqual(axisList(axes, 'ambito').map((g) => g.items.map((a) => a.id)), [[2]]);
  assert.deepEqual(axisList(axes, 'medio').map((g) => g.items.map((a) => a.id)), [[3]]);
  assert.deepEqual(axisList(axes, 'zzz'), []);
});
