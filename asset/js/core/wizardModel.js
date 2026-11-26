/**
 * The wizard to add a REA (TASK-077): its pure model. No DOM, no network, so
 * `node --test` covers it (test/js/wizardModel.test.js).
 *
 * The state is what the teacher has chosen so far:
 * - `resource`: `{kind:'upload', token, name, size}`, `{kind:'url', url}`,
 *   `{kind:'keep', name}` (a resumed REA's media) or null;
 * - `values`: term => list of strings (step ②);
 * - `stage`, `courses`, `subject` ({name, ids}: one course subject per
 *   course), `teaches`, `assesses`, `axes` (step ③). The stage is navigation
 *   and is never written (ADR-0022).
 *
 * `missing()` mirrors the server's `MinimumRule` code for code: the server
 * checks again, this only lets «Le falta» and the disabled «Guardar y
 * proponer» tell the truth before sending.
 */
import { subjectName } from './alignmentTree.js';

export const COURSE_TERM = 'lrmi:educationalLevel';
export const SUBJECT_TERM = 'schema:about';
export const TEACHES_TERM = 'lrmi:teaches';
export const ASSESSES_TERM = 'lrmi:assesses';
export const AXES_TERM = 'dcterms:relation';
export const LICENCE_TERM = 'dcterms:license';
export const TITLE_TERM = 'dcterms:title';

/** Sub-steps of step ③, in order. */
export const SUBS = Object.freeze(['stage', 'course', 'subject', 'teaches', 'assesses', 'axes']);

const ANCHORING = Object.freeze({
  [COURSE_TERM]: (s) => s.courses,
  [SUBJECT_TERM]: (s) => (s.subject ? s.subject.ids.map((id) => ({ id })) : []),
  [TEACHES_TERM]: (s) => s.teaches,
  [ASSESSES_TERM]: (s) => s.assesses,
  [AXES_TERM]: (s) => s.axes,
});

/** Where each anchoring term is chosen: [step, sub-step]. */
const ANCHOR_PLACE = Object.freeze({
  [COURSE_TERM]: 2, [SUBJECT_TERM]: 3, [TEACHES_TERM]: 4, [ASSESSES_TERM]: 5, [AXES_TERM]: 6,
});

const ANCHOR_LABEL = Object.freeze({
  [TEACHES_TERM]: 'Los saberes que trabaja',
  [ASSESSES_TERM]: 'Los criterios de evaluación',
  [AXES_TERM]: 'Los ejes temáticos',
});

export function emptyState() {
  return {
    id: null, status: null, rejection: '', resource: null, values: {},
    stage: null, courses: [], subject: null, teaches: [], assesses: [], axes: [],
    suggested: {},
  };
}

function filled(list) {
  return (list || []).some((value) => String(value ?? '').trim() !== '');
}

function hasTerm(state, term) {
  if (ANCHORING[term]) {
    return ANCHORING[term](state).length > 0;
  }
  return filled(state.values[term]);
}

/** Missing codes, in MinimumRule's order. */
export function missing(state, requiredTerms) {
  const out = [];
  const anchoring = [];
  if (!state.resource) {
    out.push('resource');
  }
  for (const term of requiredTerms || []) {
    if ([LICENCE_TERM, COURSE_TERM, SUBJECT_TERM].includes(term) || hasTerm(state, term)) {
      continue;
    }
    (ANCHORING[term] ? anchoring : out).push('field:' + term);
  }
  if (!hasTerm(state, LICENCE_TERM)) {
    out.push('licence');
  }
  if (!hasTerm(state, COURSE_TERM)) {
    out.push('course');
  }
  if (!hasTerm(state, SUBJECT_TERM)) {
    out.push('subject');
  }
  return out.concat(anchoring);
}

/** «Le falta» rows: what is missing, in the teacher's words, and where to fix it. */
export function missingItems(codes, schema) {
  const labels = Object.fromEntries((schema?.data || []).map((field) => [field.term, field.label]));
  return codes.map((code) => {
    if (code === 'resource') {
      return { code, label: 'El recurso: un fichero o un enlace', step: 1, sub: null };
    }
    if (code === 'licence') {
      return { code, label: 'La licencia', step: 2, sub: null };
    }
    if (code === 'course') {
      return { code, label: 'El curso', step: 3, sub: 2 };
    }
    if (code === 'subject') {
      return { code, label: 'La materia', step: 3, sub: 3 };
    }
    const term = code.slice('field:'.length);
    if (ANCHOR_PLACE[term]) {
      return { code, label: ANCHOR_LABEL[term] || term, step: 3, sub: ANCHOR_PLACE[term] };
    }
    return { code, label: labels[term] || term, step: 2, sub: null };
  });
}

/** Where a resumed wizard opens: the first incomplete step, else «Revisar». */
export function firstIncomplete(state, requiredTerms) {
  const first = missingItems(missing(state, requiredTerms), null)[0];
  if (!first) {
    return { step: 4, sub: 1 };
  }
  if (first.step === 3 && !state.stage) {
    return { step: 3, sub: 1 };
  }
  return { step: first.step, sub: first.sub || 1 };
}

function joinAnd(items) {
  if (items.length < 2) {
    return items.join('');
  }
  return items.slice(0, -1).join(', ') + ' y ' + items[items.length - 1];
}

function count(n, one, many) {
  return n + ' ' + (n === 1 ? one : many);
}

/** «Para 1.º y 2.º de ESO · Matemáticas · 3 saberes · 1 criterio». */
export function summary(state) {
  const parts = [];
  if (state.courses.length) {
    parts.push('Para ' + joinAnd(state.courses.map((c) => c.title)));
  } else if (state.stage?.title) {
    parts.push(state.stage.title);
  }
  if (state.subject) {
    parts.push(state.subject.name);
  }
  if (state.teaches.length) {
    parts.push(count(state.teaches.length, 'saber', 'saberes'));
  }
  if (state.assesses.length) {
    parts.push(count(state.assesses.length, 'criterio', 'criterios'));
  }
  if (state.axes.length) {
    parts.push(count(state.axes.length, 'eje temático', 'ejes temáticos'));
  }
  return parts.join(' · ') || 'Todavía no has elegido etapa, curso ni materia.';
}

function ids(list) {
  return [...new Set((list || []).map((x) => Number(x.id)).filter((id) => id > 0))];
}

/** The body of `save`: never the schema, never the status. */
export function savePayload(state, propose) {
  let resource = null;
  if (state.resource?.kind === 'upload') {
    resource = { kind: 'upload', token: state.resource.token };
  } else if (state.resource?.kind === 'url') {
    resource = { kind: 'url', url: String(state.resource.url || '').trim() };
  } else if (state.resource?.kind === 'keep') {
    resource = { kind: 'keep' };
  }
  return {
    id: state.id,
    values: state.values,
    resource,
    alignment: {
      [COURSE_TERM]: ids(state.courses),
      [SUBJECT_TERM]: state.subject ? [...new Set(state.subject.ids.map(Number))] : [],
      [TEACHES_TERM]: ids(state.teaches),
      [ASSESSES_TERM]: ids(state.assesses),
      [AXES_TERM]: ids(state.axes),
    },
    propose: Boolean(propose),
  };
}

/** localStorage key per user and REA (or «new»). */
export function storageKey(userId, itemId) {
  return 'oer-wizard:' + userId + ':' + (itemId || 'new');
}

/** Whether a locally kept state is newer than what the server has. */
export function recoverable(local, serverTime) {
  return Boolean(local && local.state && Number.isFinite(local.savedAt) && local.savedAt > Number(serverTime || 0));
}

/** Course subjects of several courses, grouped by subject name («Matemáticas» of 1.º and 2.º). */
export function subjectGroups(rows) {
  const byName = new Map();
  for (const row of rows || []) {
    const name = subjectName(row.title, row.parentTitle);
    if (!byName.has(name)) {
      byName.set(name, []);
    }
    byName.get(name).push(Number(row.id));
  }
  return [...byName.entries()]
    .map(([name, list]) => ({ name, ids: list }))
    .sort((a, b) => a.name.localeCompare(b.name, 'es'));
}

function normalise(text) {
  return String(text || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

/**
 * Knowledge or criteria of the chosen course subjects, by block, as tick
 * boxes: every selectable term at any depth, once, filtered by `query`.
 */
export function treeGroups(trees, dimension, query = '') {
  const needle = normalise(query).trim();
  const blocks = new Map();
  const seen = new Set();
  const visit = (node, block) => {
    if (node.selectable && !seen.has(node.id)) {
      const text = normalise(node.title + ' ' + (node.notation || ''));
      if (!needle || text.includes(needle)) {
        seen.add(node.id);
        block.items.push({ id: node.id, code: node.notation || '', title: node.title });
      }
    }
    for (const child of node.children || []) {
      visit(child, block);
    }
  };
  for (const tree of trees || []) {
    for (const root of tree[dimension] || []) {
      const name = [root.notation, root.title].filter(Boolean).join(' · ');
      if (!blocks.has(name)) {
        blocks.set(name, { block: name, items: [] });
      }
      if (root.selectable) {
        visit({ ...root, children: [] }, blocks.get(name));
      }
      for (const child of root.children || []) {
        visit(child, blocks.get(name));
      }
    }
  }
  return [...blocks.values()].filter((block) => block.items.length > 0);
}

/** The vocabulary URI for a licence an eXeLearning package declares in words; '' when unsure. */
export function licenceFor(declared, options) {
  const text = normalise(declared);
  if (!text.trim()) {
    return '';
  }
  let code = '';
  if (/all rights reserved|derechos reservados|copyright/.test(text)) {
    return '';
  }
  if (/public domain|dominio publico|cc0/.test(text)) {
    code = 'zero';
  } else if (/creative commons|attribution|reconocimiento|\bcc[ -]?by/.test(text)) {
    const nc = /non.?commercial|no comercial|\bnc\b/.test(text);
    const sa = /share.?alike|compartir igual|\bsa\b/.test(text);
    const nd = /no.?deriv|sin obra derivada|\bnd\b/.test(text);
    code = ['by', nc ? 'nc' : '', sa ? 'sa' : '', nd ? 'nd' : ''].filter(Boolean).join('-');
  }
  if (!code) {
    return '';
  }
  const version = (text.match(/\b(\d\.\d)\b/) || [])[1] || '';
  const matches = (options || []).filter((option) => {
    const uri = String(option.uri || '').toLowerCase();
    return code === 'zero' ? uri.includes('/publicdomain/zero/') : uri.includes('/licenses/' + code + '/');
  });
  const exact = matches.find((option) => !version || String(option.uri).includes('/' + version));
  return (exact || matches[0] || {}).uri || '';
}

export function formatBytes(bytes) {
  const n = Number(bytes) || 0;
  if (n <= 0) {
    return '';
  }
  if (n < 1000) {
    return n + ' bytes';
  }
  const [value, unit] = n < 1e6 ? [n / 1e3, 'KB'] : n < 1e9 ? [n / 1e6, 'MB'] : [n / 1e9, 'GB'];
  return value.toFixed(1).replace('.', ',') + ' ' + unit;
}

/** A resumed REA (the page config's `item`) as wizard state. */
export function fromItem(item) {
  const state = emptyState();
  if (!item) {
    return state;
  }
  const align = item.alignment && !Array.isArray(item.alignment) ? item.alignment : {};
  state.id = item.id;
  state.status = item.status || null;
  state.rejection = item.rejection || '';
  // PHP sends an empty map as []: string keys set on an Array are lost by JSON.stringify.
  state.values = item.values && !Array.isArray(item.values) ? item.values : {};
  if (item.media) {
    state.resource = { kind: 'keep', ...item.media };
  } else if (item.link) {
    state.resource = { kind: 'url', url: item.link };
  }
  state.stage = item.stage ? { id: item.stage, title: '' } : null;
  state.courses = (align[COURSE_TERM] || []).map((c) => ({ id: c.id, title: c.title }));
  const subjects = align[SUBJECT_TERM] || [];
  if (subjects.length) {
    const courseTitles = state.courses.map((c) => c.title);
    const name = courseTitles.reduce(
      (title, course) => subjectName(title, course),
      subjects[0].title
    );
    state.subject = { name, ids: subjects.map((s) => s.id) };
  }
  state.teaches = (align[TEACHES_TERM] || []).map((t) => ({ id: t.id, title: t.title }));
  state.assesses = (align[ASSESSES_TERM] || []).map((t) => ({ id: t.id, title: t.title }));
  state.axes = (align[AXES_TERM] || []).map((t) => ({ id: t.id, title: t.title }));
  return state;
}

/** An http(s) link as ReaWizardSave::isWebLink() accepts it (IRIs included). */
export function isWebLink(url) {
  const text = String(url || '').trim();
  return text.length <= 2000 && /^https?:\/\/[^\s/?#]+\S*$/iu.test(text) && !/[\u0000-\u001f\u007f<>"]/u.test(text);
}

/** The field shows one value: editing it replaces the first and keeps the others. */
export function withFirstValue(list, value) {
  const rest = (list || []).slice(1);
  return String(value).trim() === '' ? rest : [value, ...rest];
}

/**
 * Resource type options grouped by category (owner's review of PR #100): every
 * option of the «Tipos de recursos» vocabulary reads «Categoría: tipo». The card
 * shows the part after the first `: `; the stored value is the whole option.
 */
export function typeGroups(options) {
  const groups = new Map();
  let others = null;
  for (const option of options || []) {
    const text = String(option.label || option.value || option.uri || '');
    const value = option.uri || option.value;
    const at = text.indexOf(': ');
    const name = at > 0 ? text.slice(0, at).trim() : '';
    const item = { value, label: at > 0 ? text.slice(at + 2).trim() : text };
    if (!name) {
      others ??= { group: 'Otros', items: [] };
      others.items.push(item);
      continue;
    }
    if (!groups.has(name)) {
      groups.set(name, { group: name, items: [] });
    }
    groups.get(name).items.push(item);
  }
  return [...groups.values(), ...(others ? [others] : [])];
}

/**
 * The resource type the file or link suggests (second review of PR #100), as
 * `{group, value, label}` of the first option whose text matches, or null. An
 * eXeLearning project (.elpx) can be any kind of resource: no suggestion.
 */
export function suggestedType(resource, options) {
  if (!resource) {
    return null;
  }
  const name = String(resource.name || '').toLowerCase();
  const media = String(resource.mediaType || '').toLowerCase();
  let pattern = null;
  if (resource.kind === 'url') {
    pattern = /sitios? web/;
  } else if (name.endsWith('.elpx')) {
    return null;
  } else if (media.startsWith('video/')) {
    pattern = /v[ií]deo/;
  } else if (media.startsWith('audio/')) {
    pattern = /audio/;
  } else if (media.startsWith('image/')) {
    pattern = /fotograf/;
  } else if (media === 'application/pdf') {
    pattern = /pdf/;
  } else if (/presentation|powerpoint/.test(media) || /\.(odp|pptx?)$/.test(name)) {
    pattern = /presentaci/;
  } else if (/zip|compressed/.test(media) || name.endsWith('.zip')) {
    pattern = /comprimid/;
  } else if (/wordprocessing|msword|opendocument\.text/.test(media) || /\.(odt|docx?)$/.test(name)) {
    pattern = /documentos? de texto/;
  }
  if (!pattern) {
    return null;
  }
  for (const group of typeGroups(options)) {
    const item = group.items.find((i) => pattern.test(i.label.toLowerCase()));
    if (item) {
      return { group: group.group, value: item.value, label: item.label };
    }
  }
  return null;
}

/**
 * Resource types whose name or category contains `query`, ignoring case and
 * accents, from every category, as `{group, value, label}`.
 */
export function typeMatches(options, query) {
  const needle = normalise(query).trim();
  if (!needle) {
    return [];
  }
  return typeGroups(options).flatMap((group) => group.items
    .filter((item) => normalise(group.group + ' ' + item.label).includes(needle))
    .map((item) => ({ group: group.group, value: item.value, label: item.label })));
}

/**
 * Whether a block of knowledge or criteria is open: with a ticked term, with
 * matches of an active search, or when it is the subject's only block. What
 * the user did with it (`user`) wins, except that a search shows its matches.
 */
export function blockOpen(group, { checked = [], query = '', single = false, user } = {}) {
  if (String(query).trim() && group.items.length > 0) {
    return true;
  }
  if (user !== undefined) {
    return Boolean(user);
  }
  const ticked = new Set((checked || []).map(Number));
  return single || group.items.some((item) => ticked.has(Number(item.id)));
}

/** Thematic axes filtered as you type (accents ignored), sorted and split by initial letter. */
export function axisList(axes, query = '') {
  const needle = normalise(query).trim();
  const sorted = (axes || [])
    .filter((axis) => !needle || normalise(axis.title + ' ' + (axis.description || '')).includes(needle))
    .sort((a, b) => String(a.title).localeCompare(String(b.title), 'es', { sensitivity: 'base' }));
  const letters = new Map();
  for (const axis of sorted) {
    const letter = normalise(axis.title).trim().charAt(0).toUpperCase() || '#';
    if (!letters.has(letter)) {
      letters.set(letter, { letter, items: [] });
    }
    letters.get(letter).items.push(axis);
  }
  return [...letters.values()];
}
