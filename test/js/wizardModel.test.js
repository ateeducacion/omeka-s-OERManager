import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  missing, missingItems, firstIncomplete, summary, savePayload, recoverable, storageKey,
  subjectGroups, treeGroups, licenceFor, formatBytes, emptyState, fromItem, isWebLink, withFirstValue, typeGroups, blockOpen, axisList, suggestedType, typeMatches, typeLabelParts, moveResource, withResources, mainFileName,
} from '../../asset/js/core/wizardModel.js';

const schema = { data: [
  { term: 'dcterms:title', label: 'Título', required: true, control: 'text' },
  { term: 'lrmi:learningResourceType', label: 'Tipo de recurso', required: true, control: 'cards' },
  { term: 'dcterms:license', label: 'Licencia', required: false, control: 'licence' },
] };
const required = ['dcterms:title', 'lrmi:learningResourceType'];

function complete() {
  const state = emptyState();
  state.resources = [{ key: 'r1', kind: 'upload', token: 'a'.repeat(32), name: 'clase.elpx' }];
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

test('the save payload carries values, the resources in order and every anchoring term', () => {
  const s = complete();
  s.id = 100;
  s.axes = [{ id: 70, title: 'x' }];
  assert.deepEqual(savePayload(s, true), {
    id: 100,
    values: s.values,
    resources: [{ key: 'r1', kind: 'upload', token: 'a'.repeat(32) }],
    alignment: {
      'lrmi:educationalLevel': [11, 12], 'schema:about': [21, 22], 'lrmi:teaches': [31, 32, 33],
      'lrmi:assesses': [41], 'dcterms:relation': [70],
    },
    propose: true,
  });
  const many = complete();
  many.resources = [
    { key: 'm55', kind: 'media', id: 55, name: 'old.pdf' },
    { key: 'l1', kind: 'url', url: ' https://example.org/r ', error: 'x' },
    many.resources[0],
  ];
  assert.deepEqual(savePayload(many, false).resources, [
    { key: 'm55', kind: 'media', id: 55 },
    { key: 'l1', kind: 'url', url: 'https://example.org/r' },
    { key: 'r1', kind: 'upload', token: 'a'.repeat(32) },
  ]);
  assert.deepEqual(savePayload(emptyState(), false).resources, []);
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
    media: [
      { id: 55, name: 'clase.elpx', size: 4200, mediaType: 'application/zip', link: false },
      { id: 56, name: 'https://youtu.be/abc', size: 0, mediaType: '', link: true },
    ],
    alignment: {
      'lrmi:educationalLevel': [{ id: 11, title: '2.º ESO' }],
      'schema:about': [{ id: 21, title: 'Matemáticas · 2.º ESO' }],
      'lrmi:teaches': [{ id: 31, title: 'Fracciones' }],
      'dcterms:relation': [{ id: 70, title: 'Sostenibilidad' }],
    },
  });
  assert.equal(state.id, 100);
  assert.equal(state.rejection, 'Anclaje incompleto');
  assert.deepEqual(state.resources, [
    { key: 'm55', kind: 'media', id: 55, name: 'clase.elpx', size: 4200, mediaType: 'application/zip', link: false },
    { key: 'm56', kind: 'media', id: 56, name: 'https://youtu.be/abc', size: 0, mediaType: '', link: true },
  ]);
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

test('a resumed REA without data or media keeps a usable state', () => {
  const state = fromItem({ id: 5, values: [], alignment: [], media: [] });
  assert.deepEqual(state.values, {});
  state.values['dcterms:title'] = ['Nuevo'];
  assert.match(JSON.stringify(savePayload(state, false)), /"dcterms:title":\["Nuevo"\]/);
  assert.deepEqual(state.resources, []);
  assert.deepEqual(missing(state, []).slice(0, 1), ['resource']);
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

// Second review of PR #100: the type is chosen category first, with a suggestion from the file and a search.
const TYPES = [
  { value: 'Contenidos multimedia: Vídeos (tutoriales, lecciones, documentales)' },
  { value: 'Contenidos multimedia: Audio (podcasts educativos, audiolibros, canciones)' },
  { value: 'Documentos y textos: Documentos de texto y PDF' },
  { value: 'Materiales visuales: Infografías' },
  { value: 'Materiales visuales: Fotografías y galerías' },
  { value: 'Contenidos didácticos estructurados: Presentaciones' },
  { value: 'Recursos web: Sitios web recomendados' },
  { value: 'Otros recursos: Archivos comprimidos (conjuntos de recursos)' },
];

test('suggestedType reads the uploaded file or the link', () => {
  const s = (resource) => suggestedType(resource, TYPES)?.label;
  assert.equal(s({ kind: 'upload', name: 'clase.mp4', mediaType: 'video/mp4' }), 'Vídeos (tutoriales, lecciones, documentales)');
  assert.equal(s({ kind: 'upload', name: 'podcast.mp3', mediaType: 'audio/mpeg' }), 'Audio (podcasts educativos, audiolibros, canciones)');
  assert.equal(s({ kind: 'upload', name: 'guia.pdf', mediaType: 'application/pdf' }), 'Documentos de texto y PDF');
  assert.equal(s({ kind: 'upload', name: 'foto.jpg', mediaType: 'image/jpeg' }), 'Fotografías y galerías');
  assert.equal(s({ kind: 'upload', name: 'tema.odp', mediaType: 'application/vnd.oasis.opendocument.presentation' }), 'Presentaciones');
  assert.equal(s({ kind: 'upload', name: 'pack.zip', mediaType: 'application/zip' }), 'Archivos comprimidos (conjuntos de recursos)');
  assert.equal(s({ kind: 'url', url: 'https://example.org' }), 'Sitios web recomendados');
  const picked = suggestedType({ kind: 'upload', name: 'clase.mp4', mediaType: 'video/mp4' }, TYPES);
  assert.deepEqual(picked, { group: 'Contenidos multimedia', value: TYPES[0].value, label: 'Vídeos (tutoriales, lecciones, documentales)' });
});

test('suggestedType suggests nothing it cannot tell', () => {
  assert.equal(suggestedType(null, TYPES), null);
  // An eXeLearning project can be any kind of resource.
  assert.equal(suggestedType({ kind: 'upload', name: 'clase.elpx', mediaType: 'application/zip' }, TYPES), null);
  assert.equal(suggestedType({ kind: 'keep', name: 'x.bin', mediaType: 'application/octet-stream' }, TYPES), null);
  // A vocabulary without a matching option.
  assert.equal(suggestedType({ kind: 'upload', name: 'a.mp4', mediaType: 'video/mp4' }, [{ value: 'Otros: Varios' }]), null);
});

test('typeMatches finds types in every category, ignoring case and accents', () => {
  assert.deepEqual(typeMatches(TYPES, 'infografia').map((m) => m.label), ['Infografías']);
  assert.deepEqual(typeMatches(TYPES, 'VIDEO').map((m) => [m.group, m.label]),
    [['Contenidos multimedia', 'Vídeos (tutoriales, lecciones, documentales)']]);
  // The category name also matches.
  assert.equal(typeMatches(TYPES, 'visuales').length, 2);
  assert.deepEqual(typeMatches(TYPES, '  '), []);
  assert.deepEqual(typeMatches(TYPES, 'zzz'), []);
});

test('typeLabelParts moves the text in brackets to a second line', () => {
  assert.deepEqual(typeLabelParts('Vídeos (tutoriales, lecciones, documentales)'),
    { title: 'Vídeos', hint: 'tutoriales, lecciones, documentales' });
  assert.deepEqual(typeLabelParts('Herramientas autor (Exelearning, etc.)'), { title: 'Herramientas autor', hint: 'Exelearning, etc.' });
  assert.deepEqual(typeLabelParts('Infografías'), { title: 'Infografías', hint: '' });
  // Brackets in the middle stay in the title.
  assert.deepEqual(typeLabelParts('Mapas (conceptuales) y esquemas'), { title: 'Mapas (conceptuales) y esquemas', hint: '' });
});

// Owner's review of PR #100: several files and links per REA, the first one the main one.
test('moveResource moves one entry up or down and never out of the list', () => {
  const list = [{ key: 'a' }, { key: 'b' }, { key: 'c' }];
  assert.deepEqual(moveResource(list, 1, -1).map((r) => r.key), ['b', 'a', 'c']);
  assert.deepEqual(moveResource(list, 1, 1).map((r) => r.key), ['a', 'c', 'b']);
  assert.deepEqual(moveResource(list, 0, -1).map((r) => r.key), ['a', 'b', 'c']);
  assert.deepEqual(moveResource(list, 2, 1).map((r) => r.key), ['a', 'b', 'c']);
  assert.deepEqual(list.map((r) => r.key), ['a', 'b', 'c'], 'the list itself is not changed');
});

test('withResources upgrades a state kept by an earlier version of the wizard', () => {
  const old = { ...emptyState(), resource: { kind: 'upload', token: 't', name: 'a.pdf' } };
  delete old.resources;
  const upgraded = withResources(old);
  assert.deepEqual(upgraded.resources, [{ key: 'r0', kind: 'upload', token: 't', name: 'a.pdf' }]);
  assert.equal('resource' in upgraded, false);
  const keep = { ...emptyState(), resource: { kind: 'keep', name: 'x' } };
  delete keep.resources;
  assert.deepEqual(withResources(keep).resources, [], 'a kept media comes back from the server, by id');
  const current = complete();
  assert.equal(withResources(current), current);
});

test('mainFileName is the first file, for the duplicate warning', () => {
  const s = emptyState();
  assert.equal(mainFileName(s), '');
  s.resources = [{ kind: 'url', url: 'https://a.es' }, { kind: 'upload', name: 'b.pdf' }, { kind: 'media', name: 'c.pdf' }];
  assert.equal(mainFileName(s), 'b.pdf');
  s.resources = [{ kind: 'media', name: 'https://youtu.be/x', link: true }];
  assert.equal(mainFileName(s), '');
});

test('suggestedType reads a link kept as a media like a link', () => {
  assert.equal(suggestedType({ kind: 'media', name: 'https://ejemplo.es', link: true }, TYPES)?.label, 'Sitios web recomendados');
  assert.equal(suggestedType({ kind: 'media', name: 'foto.jpg', mediaType: 'image/jpeg' }, TYPES)?.label, 'Fotografías y galerías');
});

// Security review (parser differential): an ambiguous authority could name one host to the
// check and another to Omeka's HTTP client, so it is refused on both sides.
test('a link whose host could be read two ways is refused', () => {
  for (const bad of ['http://ejemplo.es@127.0.0.1/', 'http://user:pw@ejemplo.es/', 'http://ejemplo.es\\@127.0.0.1/',
    'http://127.0.0.1\\.ejemplo.es/', 'http://%31%32%37.0.0.1/', 'https://ejemplo.es:8443\\x']) {
    assert.equal(isWebLink(bad), false, bad);
  }
  // An @ after the host (a path, a query) is fine.
  assert.equal(isWebLink('https://ejemplo.es/perfil/@ana?mail=a@b.es'), true);
  assert.equal(isWebLink('https://[2001:4860::8888]/x'), true);
});
