/**
 * The wizard to add a REA (TASK-077, ADR-0025): the DOM layer. The rules live
 * in `core/wizardModel.js`; this file draws the four steps and the anchoring
 * sub-steps, talks to the wizard's endpoints and keeps a local copy of the
 * state so nothing typed is lost.
 *
 * Every text from the catalogue, a file or the teacher is set with
 * `textContent`: never parsed as HTML.
 */
import {
  emptyState, fromItem, missing, missingItems, firstIncomplete, summary, savePayload,
  storageKey, recoverable, subjectGroups, treeGroups, licenceFor, formatBytes, isWebLink, withFirstValue,
  typeGroups, blockOpen, axisList, suggestedType, typeMatches, typeLabelParts, moveResource, withResources, mainFileName,
  TITLE_TERM, LICENCE_TERM, TEACHES_TERM, ASSESSES_TERM,
} from './core/wizardModel.js';

const root = document.getElementById('oer-wizard');
const configNode = document.getElementById('oer-wizard-config');
const cfg = configNode ? JSON.parse(configNode.textContent) : null;

/** Translated copy (the page passes it), with `%s`/`%d` filled in order. */
function t(text, ...args) {
  let out = (cfg?.strings && cfg.strings[text]) || text;
  for (const arg of args) {
    out = out.replace(/%[sd]/, String(arg));
  }
  return out;
}

// ---- DOM helpers -----------------------------------------------------------

const SVG = 'http://www.w3.org/2000/svg';
const ICONS = {
  upload: '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
  link: '<path d="M10 14a5 5 0 0 0 7 0l3-3a5 5 0 0 0-7-7l-1 1"/><path d="M14 10a5 5 0 0 0-7 0l-3 3a5 5 0 0 0 7 7l1-1"/>',
  file: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
  check: '<path d="M5 12l5 5L20 7"/>',
  alert: '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
  error: '<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>',
  history: '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/><path d="M12 7v5l3 2"/>',
  back: '<path d="M15 6l-6 6 6 6"/>',
  next: '<path d="M9 6l6 6-6 6"/>',
  edit: '<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M13 7l4 4"/>',
  copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="M20 20l-4-4"/>',
  close: '<path d="M6 6l12 12M18 6L6 18"/>',
  up: '<path d="M6 15l6-6 6 6"/>',
  down: '<path d="M6 9l6 6 6-6"/>',
  bulb: '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-4 10.5c.7.7 1 1.5 1 2.5h6c0-1 .3-1.8 1-2.5A6 6 0 0 0 12 3z"/>',
  returned: '<path d="M9 14l-4-4 4-4"/><path d="M5 10h11a4 4 0 0 1 0 8h-1"/>',
  school: '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 3 3 6 3s6-1.5 6-3v-5"/>',
  touch: '<path d="M9 11V5a2 2 0 0 1 4 0v6"/><path d="M13 10a2 2 0 0 1 4 0v3a7 7 0 0 1-7 7 6 6 0 0 1-5-3l-2-4a2 2 0 0 1 3-2l2 2"/>',
  video: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M10 9l5 3-5 3z"/>',
  slides: '<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M12 16v4M8 20h8"/>',
  doc: '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M9 13h6M9 17h6"/>',
  game: '<rect x="2" y="7" width="20" height="10" rx="5"/><path d="M7 10v4M5 12h4M15 11h.01M18 13h.01"/>',
  resource: '<path d="M4 19V6a2 2 0 0 1 2-2h12v15H6a2 2 0 0 0-2 2"/><path d="M8 8h6"/>',
};

function icon(name) {
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('class', 'oer-wz-icon');
  svg.setAttribute('viewBox', '0 0 24 24');
  svg.setAttribute('aria-hidden', 'true');
  svg.setAttribute('focusable', 'false');
  // Fixed markup of this file, never data.
  const doc = new DOMParser().parseFromString('<svg xmlns="' + SVG + '">' + ICONS[name] + '</svg>', 'image/svg+xml');
  for (const node of [...doc.documentElement.childNodes]) {
    svg.appendChild(document.importNode(node, true));
  }
  return svg;
}

/** `h('p', {class: 'x', onclick: fn}, 'text', child)`. Strings become text nodes. */
function h(tag, attrs = {}, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (value === null || value === undefined || value === false) {
      continue;
    }
    if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.slice(2), value);
    } else if (key === 'class') {
      el.className = value;
    } else if (key in el && typeof value !== 'string') {
      el[key] = value;
    } else {
      el.setAttribute(key, value === true ? '' : String(value));
    }
  }
  for (const child of children.flat(Infinity)) {
    if (child === null || child === undefined || child === false) {
      continue;
    }
    el.appendChild(typeof child === 'string' || typeof child === 'number' ? document.createTextNode(String(child)) : child);
  }
  return el;
}

let uid = 0;
const nextId = (prefix) => prefix + '-' + (++uid);

// ---- State -----------------------------------------------------------------

const STEPS = ['Tu recurso', 'Datos', 'Curso y materia', 'Revisar y proponer'];
const SUB_NAMES = ['Etapa', 'Curso', 'Materia', 'Saberes', 'Criterios', 'Ejes'];

const fields = cfg ? cfg.schema.data : [];
const fieldOf = (term) => fields.find((field) => field.term === term);
const licenceOptions = (fieldOf(LICENCE_TERM) || {}).options || [];

let state = cfg ? fromItem(cfg.item) : emptyState();
const ui = {
  step: 1, sub: 1, maxStep: 1, busy: false, error: null, uploadError: null, uploading: null,
  prefilled: false, recover: null, lists: {}, loading: {}, failed: {}, q: '', axisQ: '', userBlocks: {}, typeOpen: false, typeCat: null, typeQ: '',
  duplicates: null, focus: true,
};

const storage = {
  key: () => storageKey(cfg.user, state.id),
  read(key) {
    try {
      return JSON.parse(window.localStorage.getItem(key) || 'null');
    } catch (e) {
      return null;
    }
  },
  write() {
    try {
      window.localStorage.setItem(storage.key(), JSON.stringify({ savedAt: Date.now(), state, step: ui.step, sub: ui.sub }));
    } catch (e) {
      // Private window or blocked storage: the wizard works without it.
    }
  },
  clear(...keys) {
    for (const key of keys) {
      try {
        window.localStorage.removeItem(key);
      } catch (e) {
        // Nothing to clear.
      }
    }
  },
};

function changed() {
  storage.write();
}

// ---- Network ---------------------------------------------------------------

function withParams(url, params) {
  const out = new URL(url, window.location.href);
  for (const [key, value] of Object.entries(params || {})) {
    if (Array.isArray(value)) {
      value.forEach((v) => out.searchParams.append(key + '[]', String(v)));
    } else if (value !== null && value !== undefined && value !== '') {
      out.searchParams.set(key, String(value));
    }
  }
  return out;
}

async function getJson(url, params) {
  const response = await fetch(withParams(url, params), {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  });
  if (!response.ok) {
    throw new Error('HTTP ' + response.status);
  }
  return response.json();
}

async function postForm(url, form) {
  form.append('csrf', cfg.csrf);
  const response = await fetch(url, {
    method: 'POST',
    body: form,
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  });
  if (response.status === 413) {
    return { ok: false, error: 'too_large', message: t('El fichero es demasiado grande para el catálogo.') };
  }
  return response.json();
}

/** One cached list per key; re-renders when it arrives. */
function list(key, load) {
  if (key in ui.lists) {
    return ui.lists[key];
  }
  // A failed load waits for «Volver a intentarlo»: never a retry loop (code review of PR #100).
  if (!ui.loading[key] && !ui.failed[key]) {
    ui.loading[key] = true;
    load().then((rows) => {
      ui.lists[key] = rows;
    }).catch(() => {
      ui.failed[key] = true;
    }).finally(() => {
      ui.loading[key] = false;
      ui.focus = false;
      render();
    });
  }
  return null;
}

const urls = cfg ? cfg.urls : {};
const rows = (data) => (data.results || []).map((r) => ({
  id: Number(r.id), title: r.title, parentTitle: r.parentTitle || '', description: r.description || '',
}));
const loaders = {
  stages: () => list('stages', () => getJson(urls.searchTerms, { dimension: 'etapa', browse: 1 }).then(rows)),
  courses: (stageId) => list('courses:' + stageId, () =>
    getJson(urls.searchTerms, { dimension: 'lrmi:educationalLevel', browse: 1, etapa: [stageId] }).then(rows)),
  subjects: (courseIds) => list('subjects:' + courseIds.join(','), () =>
    getJson(urls.searchTerms, { dimension: 'schema:about', browse: 1, level: courseIds }).then(rows)),
  trees: (subjectIds) => list('trees:' + subjectIds.join(','), () =>
    getJson(urls.curriculumTree, { about: subjectIds }).then((data) => data.trees || [])),
  axes: () => list('axes', () => getJson(urls.searchTerms, { dimension: 'dcterms:relation', browse: 1 }).then(rows)),
};

// ---- Navigation ------------------------------------------------------------

function go(step, sub) {
  ui.step = Math.min(4, Math.max(1, Number(step) || 1));
  ui.sub = ui.step === 3 ? Math.min(6, Math.max(1, Number(sub) || ui.sub || 1)) : 1;
  ui.maxStep = Math.max(ui.maxStep, step);
  ui.error = null;
  ui.q = '';
  Object.assign(ui, { typeOpen: false, typeCat: null, typeQ: '' });
  ui.focus = true;
  if (ui.step === 4) {
    loadDuplicates();
  }
  render();
}

function next() {
  if (ui.step === 3 && ui.sub < 6) {
    return go(3, ui.sub + 1);
  }
  go(ui.step + 1, 1);
}

function back() {
  if (ui.step === 3 && ui.sub > 1) {
    return go(3, ui.sub - 1);
  }
  if (ui.step > 1) {
    go(ui.step - 1, ui.step - 1 === 3 ? 6 : 1);
  }
}

// ---- Pieces ----------------------------------------------------------------

function notice(kind, iconName, ...body) {
  return h('div', { class: 'oer-wz-notice' + (kind ? ' is-' + kind : '') }, icon(iconName), h('div', { class: 'oer-wz-notice-body' }, ...body));
}

function heading(text, lead) {
  return h('div', { class: 'oer-wz-field' },
    h('p', { class: 'oer-wz-cap' }, t('Paso %d de 4', ui.step)),
    h('h2', { tabindex: '-1', 'data-wz-heading': '' }, text),
    lead ? h('p', { class: 'oer-wz-lead' }, lead) : null);
}

function choice(type, name, checked, onchange, title, hint, iconName, extra = '') {
  return h('label', { class: 'oer-wz-choice' + extra },
    h('input', { type, name, checked: Boolean(checked), onchange }),
    iconName ? icon(iconName) : null,
    h('span', { class: 'oer-wz-choice-text' },
      h('span', { class: 'oer-wz-choice-title' }, title),
      hint ? h('span', { class: 'oer-wz-choice-hint' }, hint) : null));
}

function loadingOrRetry(key) {
  if (ui.failed[key]) {
    const retry = () => { delete ui.failed[key]; render(); };
    return notice('error', 'error', h('p', {}, t('No se pudo cargar el currículo. Vuelve a intentarlo.')),
      h('div', { class: 'oer-wz-notice-actions' }, h('button', { type: 'button', class: 'oer-wz-btn', onclick: retry }, t('Volver a intentarlo'))));
  }
  return h('p', { class: 'oer-wz-busy', role: 'status' }, t('Cargando…'));
}

// ---- Step ①: the resource ---------------------------------------------------

let resourceSeq = 0;

/** A new entry of step ①'s list, with a key the server's answer names it by. */
function addResource(entry) {
  state.resources = [...state.resources, { key: 'n' + Date.now().toString(36) + (++resourceSeq), ...entry }];
}

/** Several files, one after another (each goes to the private upload store). */
async function uploadFiles(files) {
  for (const file of [...(files || [])]) {
    await uploadFile(file);
  }
}

async function uploadFile(file) {
  if (!file) {
    return;
  }
  if (cfg.uploadLimit && file.size > cfg.uploadLimit) {
    ui.uploadError = { name: file.name, message: t('El fichero es demasiado grande para el catálogo.') };
    return render();
  }
  ui.uploading = file.name;
  ui.uploadError = null;
  render();
  const form = new FormData();
  form.append('file', file);
  let result;
  try {
    result = await postForm(urls.upload, form);
  } catch (e) {
    result = { ok: false, message: t('No se pudo subir el fichero. Vuelve a intentarlo.') };
  }
  ui.uploading = null;
  if (!result.ok) {
    ui.uploadError = { name: file.name, message: t(result.message || 'No se pudo subir el fichero. Vuelve a intentarlo.') };
    return render();
  }
  addResource({ kind: 'upload', token: result.token, name: result.name, size: result.size, mediaType: result.mediaType });
  suggest(result.metadata || {});
  changed();
  render();
}

/** File metadata → editable suggestions, only where the teacher typed nothing. */
function suggest(meta) {
  const map = { title: TITLE_TERM, description: 'dcterms:description', author: 'dcterms:creator' };
  let any = false;
  for (const [key, term] of Object.entries(map)) {
    if (meta[key] && fieldOf(term) && !(state.values[term] || []).some((v) => String(v).trim())) {
      state.values[term] = [meta[key]];
      state.suggested[term] = true;
      any = true;
    }
  }
  const current = (state.values[LICENCE_TERM] || [])[0] || '';
  const uri = meta.licence ? licenceFor(meta.licence, licenceOptions) : '';
  if (uri && fieldOf(LICENCE_TERM) && (!current || current === cfg.defaultLicence)) {
    state.values[LICENCE_TERM] = [uri];
    state.suggested[LICENCE_TERM] = true;
    any = true;
  }
  ui.prefilled = any;
}

function moveEntry(index, delta) {
  state.resources = moveResource(state.resources, index, delta);
  changed();
  ui.focus = false;
  render();
  // Keep the focus on the moved entry's same button, or the other one at the ends.
  const row = root.querySelectorAll('[data-wz-res]')[index + delta];
  (row?.querySelector(delta < 0 ? '[data-wz-up]:not(:disabled)' : '[data-wz-down]:not(:disabled)')
    || row?.querySelector('button:not(:disabled)'))?.focus();
}

function removeEntry(index) {
  state.resources = state.resources.filter((_, i) => i !== index);
  changed();
  ui.focus = false;
  render();
  root.querySelector('[data-wz-file-pick]')?.focus();
}

/** One file or link of the REA: name, what it is, its error if the catalogue refused it, and its actions. */
function resourceRow(entry, index, count) {
  const isLink = entry.kind === 'url' || Boolean(entry.link);
  const name = entry.kind === 'url' ? entry.url : entry.name;
  const meta = entry.kind === 'url' ? t('Enlace · se añadirá al guardar')
    : entry.kind === 'upload' ? [formatBytes(entry.size), t('subido')].filter(Boolean).join(' · ')
      : isLink ? t('Enlace del REA') : [formatBytes(entry.size), t('guardado en el REA')].filter(Boolean).join(' · ');
  return h('li', { class: 'oer-wz-res' + (index === 0 ? ' is-main' : '') + (entry.error ? ' is-error' : ''), 'data-wz-res': '' },
    h('span', { class: 'oer-wz-badge' + (isLink ? ' is-soft' : '') }, icon(isLink ? 'link' : 'file')),
    h('div', { class: 'oer-wz-file-text' },
      h('p', { class: 'oer-wz-file-name' }, name, index === 0 && count > 1 ? h('span', { class: 'oer-wz-main-tag' }, t('Principal')) : null),
      h('p', { class: 'oer-wz-file-meta' }, meta),
      entry.error ? h('p', { class: 'oer-wz-res-error' }, icon('error'), h('span', {}, entry.error)) : null),
    h('div', { class: 'oer-wz-res-actions' },
      count > 1 ? h('button', { type: 'button', class: 'oer-wz-icon-btn', 'data-wz-up': '', disabled: index === 0,
        'aria-label': t('Subir «%s»', name), title: t('Subir'), onclick: () => moveEntry(index, -1) }, icon('up')) : null,
      count > 1 ? h('button', { type: 'button', class: 'oer-wz-icon-btn', 'data-wz-down': '', disabled: index === count - 1,
        'aria-label': t('Bajar «%s»', name), title: t('Bajar'), onclick: () => moveEntry(index, 1) }, icon('down')) : null,
      h('button', { type: 'button', class: 'oer-wz-link', 'aria-label': t('Quitar «%s»', name), onclick: () => removeEntry(index) }, t('Quitar'))));
}

function stepResource() {
  const list = state.resources;
  const parts = [heading(t('Tu recurso'), t('Sube los ficheros de tu recurso o pega enlaces. Puedes añadir varios: el primero de la lista es el principal.'))];
  if (ui.uploadError) {
    parts.push(h('div', { role: 'alert' }, notice('error', 'error',
      h('p', { class: 'oer-wz-notice-title' }, t('No podemos usar «%s»', ui.uploadError.name)),
      h('p', {}, ui.uploadError.message))));
  }
  if (list.length) {
    parts.push(h('ol', { class: 'oer-wz-reslist', 'aria-label': t('Ficheros y enlaces del REA') },
      list.map((entry, i) => resourceRow(entry, i, list.length))));
  }
  if (ui.prefilled) {
    parts.push(h('p', { role: 'status' }, notice('', 'edit', h('span', {}, t('Hemos rellenado algunos datos a partir del fichero. Revísalos en el paso siguiente.')))));
  }
  const inputId = nextId('wz-file');
  const fileInput = h('input', { type: 'file', id: inputId, class: 'oer-wz-visually-hidden', tabindex: '-1', multiple: true,
    onchange: (e) => { uploadFiles(e.target.files); e.target.value = ''; } });
  const drop = h('div', { class: 'oer-wz-source oer-wz-drop' + (list.length ? ' is-compact' : '') },
    h('span', { class: 'oer-wz-badge' }, icon('upload')),
    h('p', { class: 'oer-wz-source-title' }, list.length ? t('Añadir más ficheros') : t('Sube tus ficheros')),
    h('p', { class: 'oer-wz-choice-hint' }, t('Arrástralos hasta aquí o búscalos en tu equipo. Puedes elegir varios a la vez.')),
    fileInput,
    h('button', { type: 'button', class: 'oer-wz-btn is-primary', 'data-wz-file-pick': '', disabled: Boolean(ui.uploading),
      onclick: () => fileInput.click() }, t('Elegir ficheros')),
    ui.uploading ? h('p', { class: 'oer-wz-busy', role: 'status' }, t('Subiendo «%s»…', ui.uploading)) : null,
    h('p', { class: 'oer-wz-help' }, t('Hasta %s por fichero · eXeLearning (.elpx), .zip, PDF, imagen, audio, vídeo u ofimática', formatBytes(cfg.uploadLimit) || '—')));
  drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('is-over'); });
  drop.addEventListener('dragleave', () => drop.classList.remove('is-over'));
  drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('is-over'); uploadFiles(e.dataTransfer.files); });
  const urlId = nextId('wz-url');
  const urlHelp = nextId('wz-url-help');
  const urlInput = h('input', { id: urlId, class: 'oer-wz-input', type: 'url', placeholder: 'https://', 'aria-describedby': urlHelp });
  const urlError = h('p', { class: 'oer-wz-help', role: 'alert' });
  const useUrl = () => {
    const value = urlInput.value.trim();
    if (!isWebLink(value)) {
      urlError.textContent = t('Escribe un enlace que empiece por http:// o https://.');
      return;
    }
    addResource({ kind: 'url', url: value });
    ui.prefilled = false;
    changed();
    ui.focus = false;
    render();
    root.querySelector('#' + CSS.escape(urlId))?.focus();
  };
  urlInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') { e.preventDefault(); useUrl(); } });
  const link = h('div', { class: 'oer-wz-source' + (list.length ? ' is-compact' : '') },
    h('span', { class: 'oer-wz-badge is-soft' }, icon('link')),
    h('label', { for: urlId, class: 'oer-wz-source-title' }, t('O pega un enlace')),
    h('p', { id: urlHelp, class: 'oer-wz-choice-hint' }, t('Un vídeo de YouTube o Vimeo se enlaza; cualquier otro enlace se descarga al guardar y el catálogo comprueba que se admite.')),
    urlInput, urlError,
    h('button', { type: 'button', class: 'oer-wz-btn', onclick: useUrl }, t('Añadir enlace')));
  parts.push(h('div', { class: 'oer-wz-sources' }, drop, link));
  return parts;
}

// ---- Step ②: data ----------------------------------------------------------

function typeIcon(label) {
  const text = String(label).toLowerCase();
  const rules = [[/v[ií]deo|multimedia|audio|podcast/, 'video'], [/juego|gamif/, 'game'], [/presentaci/, 'slides'],
    [/interactiv|actividad|ejercicio|cuestionario/, 'touch'], [/documento|ficha|texto|lectura/, 'doc'],
    [/situaci|secuencia|unidad|proyecto|did[aá]ctic/, 'school']];
  return (rules.find(([re]) => re.test(text)) || [null, 'resource'])[1];
}

function fieldHead(field, forId) {
  return h('div', { class: 'oer-wz-field-head' },
    h(forId ? 'label' : 'span', { class: 'oer-wz-label', for: forId || null }, ...labelParts(field)),
    state.suggested[field.term] ? h('span', { class: 'oer-wz-tag', 'data-wz-tag': field.term }, t('Del fichero')) : null);
}

function setValue(term, value) {
  state.values[term] = withFirstValue(state.values[term], value);
  if (state.suggested[term]) {
    delete state.suggested[term];
    root.querySelector('[data-wz-tag="' + CSS.escape(term) + '"]')?.remove();
  }
  changed();
}

function dataField(field) {
  const id = nextId('wz-field');
  const current = (state.values[field.term] || [])[0] || '';
  const others = (state.values[field.term] || []).length - 1;
  const helpId = field.help || others > 0 ? id + '-help' : null;
  const help = field.help || others > 0
    ? h('p', { id: helpId, class: 'oer-wz-help' }, field.help ? t(field.help) : null,
      others > 0 ? ' ' + t(others === 1 ? 'Se conserva %d valor más de este campo.' : 'Se conservan %d valores más de este campo.', others) : null)
    : null;
  const optionValue = (o) => o.uri || o.value;
  const optionLabel = (o) => o.label || o.value || o.uri;
  if (field.control === 'cards') {
    return typePicker(field, id, help);
  }
  let control;
  if (field.control === 'textarea') {
    control = h('textarea', { id, class: 'oer-wz-input', rows: '4', 'aria-describedby': helpId,
      oninput: (e) => setValue(field.term, e.target.value) });
    control.value = current;
  } else if (field.control === 'select' || field.control === 'licence') {
    const placeholder = field.control === 'licence' ? t('Elige una licencia…') : t('Elige una opción…');
    control = h('select', { id, class: 'oer-wz-input', 'aria-describedby': helpId, onchange: (e) => setValue(field.term, e.target.value) },
      h('option', { value: '' }, placeholder),
      field.options.map((o) => h('option', { value: optionValue(o), selected: optionValue(o) === current }, optionLabel(o))));
  } else {
    control = h('input', { id, class: 'oer-wz-input', type: field.control === 'url' ? 'url' : 'text', 'aria-describedby': helpId,
      oninput: (e) => setValue(field.term, e.target.value) });
    control.value = current;
  }
  return h('div', { class: 'oer-wz-field' }, fieldHead(field, id), help, control);
}

/** The field's name, a red asterisk when required (the admin's convention) and «obligatorio» in words. */
function labelParts(field) {
  return [field.label, field.required ? [h('span', { class: 'oer-wz-req', 'aria-hidden': 'true' }, ' *'),
    h('span', { class: 'oer-wz-optional' }, ' · ' + t('obligatorio'))] : null];
}

/**
 * The resource type (third review of PR #100): the file's suggestion, a search
 * with a clear button, and the categories as a menu beside their types (an
 * accordion on narrow screens). Once chosen, it shrinks to one line.
 */
function typePicker(field, id, help) {
  const current = (state.values[field.term] || [])[0] || '';
  const groups = typeGroups(field.options);
  const chosen = groups.flatMap((g) => g.items.map((item) => ({ group: g.group, ...item }))).find((item) => item.value === current);
  const close = (focusChange) => {
    Object.assign(ui, { typeOpen: false, typeQ: '', typeCat: null, focus: false });
    render();
    if (focusChange) {
      root.querySelector('[data-wz-type-change]')?.focus();
    }
  };
  const pick = (value) => {
    setValue(field.term, value);
    close(true);
  };
  if (chosen && !ui.typeOpen) {
    const parts = typeLabelParts(chosen.label);
    return h('fieldset', { class: 'oer-wz-field' }, h('legend', { class: 'oer-wz-label' }, ...labelParts(field)), help,
      h('div', { class: 'oer-wz-type-chosen' }, icon('check'),
        h('p', { class: 'oer-wz-type-path' }, h('span', { class: 'oer-wz-type-group-name' }, t(chosen.group) + ' › '), h('strong', {}, parts.title)),
        h('button', { type: 'button', class: 'oer-wz-btn', 'data-wz-type-change': '', onclick: () => {
          Object.assign(ui, { typeOpen: true, typeCat: chosen.group, focus: false });
          render();
          root.querySelector('[data-wz-type-search]')?.focus();
        } }, t('Cambiar'))));
  }
  const main = state.resources[0] || null;
  const suggestion = suggestedType(main, field.options);
  const card = (item, group) => {
    const parts = typeLabelParts(item.label);
    const hint = [group ? t(group) : null, parts.hint || null].filter(Boolean).join(' · ');
    return h('label', { class: 'oer-wz-typecard' },
      h('input', { type: 'radio', name: id, checked: current === item.value, onchange: () => pick(item.value) }),
      h('span', { class: 'oer-wz-typecard-text' }, h('span', { class: 'oer-wz-typecard-title' }, parts.title),
        hint ? h('span', { class: 'oer-wz-typecard-hint' }, hint) : null),
      current === item.value ? h('span', { class: 'oer-wz-typecard-check' }, icon('check')) : null);
  };
  const body = h('div', { class: 'oer-wz-typepick' });
  const paint = () => {
    if (ui.typeQ.trim()) {
      const matches = typeMatches(field.options, ui.typeQ);
      body.replaceChildren(matches.length
        ? h('div', { class: 'oer-wz-typecards is-results', role: 'group', 'aria-label': t('Resultados') }, matches.map((m) => card(m, m.group)))
        : h('p', { class: 'oer-wz-help' }, t('Nada coincide con «%s». Prueba con otra palabra.', ui.typeQ)));
      return;
    }
    const open = ui.typeCat || chosen?.group || suggestion?.group || groups[0]?.group || null;
    body.replaceChildren(h('ul', { class: 'oer-wz-typemenu' }, groups.map((g, i) => {
      const isOpen = g.group === open;
      const panelId = id + '-panel-' + i;
      return h('li', { class: isOpen ? 'is-open' : '' },
        h('button', { type: 'button', class: 'oer-wz-typemenu-cat', 'aria-expanded': isOpen ? 'true' : 'false', 'aria-controls': panelId,
          onclick: () => { ui.typeCat = g.group; paint(); body.querySelector('[aria-expanded="true"]')?.focus(); } },
        h('span', {}, t(g.group)), h('span', { class: 'oer-wz-cat-count' }, String(g.items.length))),
        isOpen ? h('div', { id: panelId, class: 'oer-wz-typecards', role: 'group', 'aria-label': t(g.group) }, g.items.map((item) => card(item, null))) : null);
    })));
  };
  paint();
  const searchId = nextId('wz-type-q');
  const input = h('input', { id: searchId, class: 'oer-wz-input', type: 'text', 'data-wz-type-search': '', value: ui.typeQ, autocomplete: 'off',
    placeholder: t('Buscar un tipo, por ejemplo: infografía'), oninput: (e) => { ui.typeQ = e.target.value; clear.hidden = !ui.typeQ; paint(); } });
  const clear = h('button', { type: 'button', class: 'oer-wz-search-clear', 'aria-label': t('Borrar la búsqueda'), hidden: !ui.typeQ,
    onclick: () => { ui.typeQ = ''; input.value = ''; clear.hidden = true; paint(); input.focus(); } }, icon('close'));
  return h('fieldset', { class: 'oer-wz-field oer-wz-typefield' },
    h('div', { class: 'oer-wz-typehead' }, h('legend', { class: 'oer-wz-label' }, ...labelParts(field)),
      chosen ? h('button', { type: 'button', class: 'oer-wz-link', onclick: () => close(true) }, t('Cancelar')) : null),
    help,
    suggestion && suggestion.value !== current
      ? h('div', { class: 'oer-wz-suggest' }, icon('bulb'),
        h('p', {}, (main?.kind === 'url' || main?.link) ? t('Por tu enlace, parece «%s».', typeLabelParts(suggestion.label).title)
          : t('Por tu fichero, parece «%s».', typeLabelParts(suggestion.label).title),
        h('span', { class: 'oer-wz-type-group-name' }, ' ' + t(suggestion.group))),
        h('button', { type: 'button', class: 'oer-wz-btn is-primary', onclick: () => pick(suggestion.value) }, t('Usar este')))
      : null,
    h('div', { class: 'oer-wz-search is-clearable', role: 'search' },
      h('label', { for: searchId, class: 'oer-wz-visually-hidden' }, t('Buscar un tipo de recurso')),
      icon('search'), input, clear),
    body);
}

function stepData() {
  return [heading(t('Datos del recurso'), t('Cuéntanos qué es. Lo marcado como obligatorio hace falta para proponerlo.')), ...fields.map(dataField)];
}

// ---- Step ③: anchoring -----------------------------------------------------

function subValue(i) {
  const marked = (n) => (n ? t(n === 1 ? '%d marcado' : '%d marcados', n) : null);
  return [
    state.stage?.title || (state.stage ? '…' : t('Sin elegir')),
    state.courses.length ? state.courses.map((c) => c.title).join(', ') : t('Sin elegir'),
    state.subject ? state.subject.name : t('Sin elegir'),
    marked(state.teaches.length) || t('Ninguno'),
    marked(state.assesses.length) || t('Opcional'),
    marked(state.axes.length) || t('Opcional'),
  ][i];
}

function subTabs() {
  const locked = [false, !state.stage, !state.courses.length, !state.subject, !state.subject, false];
  return h('nav', { class: 'oer-wz-tabs', 'aria-label': t('Curso y materia') }, h('ol', {}, SUB_NAMES.map((name, i) =>
    h('li', {}, h('button', { type: 'button', class: 'oer-wz-tab', disabled: locked[i], 'aria-current': ui.sub === i + 1 ? 'step' : 'false',
      onclick: () => go(3, i + 1) }, h('span', {}, t(name)), h('span', { class: 'oer-wz-tab-value', 'data-wz-sub': String(i) }, subValue(i)))))));
}

/** After a tick that does not repaint: the sub-step tabs and «Hasta ahora» follow. */
function refreshCounts() {
  root.querySelectorAll('[data-wz-sub]').forEach((el) => { el.textContent = subValue(Number(el.dataset.wzSub)); });
  root.querySelector('[data-wz-summary]').textContent = summary(state);
}

function subStage() {
  const stages = loaders.stages();
  if (!stages) {
    return loadingOrRetry('stages');
  }
  if (state.stage && !state.stage.title) {
    state.stage.title = (stages.find((s) => s.id === state.stage.id) || {}).title || '';
  }
  const name = nextId('wz-stage');
  if (!stages.length) {
    // No curriculum this user can read (TASK-069 for isolated users): say so, never an empty list.
    return notice('important', 'alert', h('p', { class: 'oer-wz-notice-title' }, t('No hay currículo disponible para anclar el recurso.')),
      h('p', {}, t('Puedes guardar el borrador y continuar más tarde. Si el problema sigue, avisa al equipo de la Mediateca.')));
  }
  return h('fieldset', { class: 'oer-wz-field' }, h('legend', {}, h('h3', { style: 'margin-bottom: 10px' }, t('¿En qué etapa se usa?'))),
    h('div', { class: 'oer-wz-grid' }, stages.map((stage) => choice('radio', name, state.stage?.id === stage.id, () => {
      if (state.stage?.id !== stage.id) {
        Object.assign(state, { stage: { id: stage.id, title: stage.title }, courses: [], subject: null, teaches: [], assesses: [] });
      }
      changed();
      go(3, 2);
    }, stage.title, null, 'school'))));
}

function subCourse() {
  const courses = loaders.courses(state.stage.id);
  if (!courses) {
    return loadingOrRetry('courses:' + state.stage.id);
  }
  return h('fieldset', { class: 'oer-wz-field' }, h('legend', {}, h('h3', {}, t('¿En qué curso o cursos?'))),
    h('p', { class: 'oer-wz-help' }, t('Puedes marcar más de uno si sirve para varios.')),
    h('div', { class: 'oer-wz-grid' }, courses.map((course) => choice('checkbox', 'wz-course', state.courses.some((c) => c.id === course.id), (e) => {
      state.courses = e.target.checked
        ? [...state.courses, { id: course.id, title: course.title }]
        : state.courses.filter((c) => c.id !== course.id);
      state.subject = null;
      state.teaches = [];
      state.assesses = [];
      changed();
      ui.focus = false;
      render();
    }, course.title))));
}

function subSubject() {
  const ids = state.courses.map((c) => c.id);
  const subjects = loaders.subjects(ids);
  if (!subjects) {
    return loadingOrRetry('subjects:' + ids.join(','));
  }
  const groups = subjectGroups(subjects);
  const name = nextId('wz-subject');
  return h('fieldset', { class: 'oer-wz-field' }, h('legend', {}, h('h3', {}, t('¿De qué materia?'))),
    h('p', { class: 'oer-wz-help' }, t('Materias de %s.', state.courses.map((c) => c.title).join(', '))),
    groups.length ? h('div', { class: 'oer-wz-grid' }, groups.map((group) => choice('radio', name, state.subject?.name === group.name, () => {
      if (state.subject?.name !== group.name) {
        Object.assign(state, { subject: { name: group.name, ids: group.ids }, teaches: [], assesses: [] });
      }
      changed();
      go(3, 4);
    }, group.name))) : h('p', { class: 'oer-wz-help' }, t('No hay nada que elegir aquí todavía.')));
}

function subTerms(dimension) {
  const key = dimension === TEACHES_TERM ? 'teaches' : 'assesses';
  const ids = state.subject.ids;
  const trees = loaders.trees(ids);
  const isCriteria = dimension === ASSESSES_TERM;
  const title = isCriteria ? t('Criterios de evaluación') : t('¿Qué saberes trabaja?');
  const count = state[key].length;
  const head = h('div', { class: 'oer-wz-list-head' }, h('h3', {}, title,
    isCriteria ? h('span', { class: 'oer-wz-optional', style: 'font-size: 15px' }, ' · ' + t('opcional')) : null),
  h('span', { class: 'oer-wz-count', role: 'status' }, t(count === 1 ? '%d marcado' : '%d marcados', count)));
  const help = h('p', { class: 'oer-wz-help', style: 'font-size: 15px' }, isCriteria
    ? t('Opcional. Marca los criterios con los que se puede evaluar lo que hace el alumnado. Puedes saltar esta parte.')
    : t('Marca los saberes de %s que el alumnado trabaja con tu recurso. Debajo de cada uno, su código en el currículo.', state.subject.name));
  if (!trees) {
    return h('div', { class: 'oer-wz-field' }, head, help, loadingOrRetry('trees:' + ids.join(',')));
  }
  const listBox = h('div', { class: 'oer-wz-panel' });
  const blockCount = (group) => {
    const ticked = group.items.filter((item) => state[key].some((x) => x.id === item.id)).length;
    return t(group.items.length === 1 ? '%d término' : '%d términos', group.items.length)
      + (ticked ? ' · ' + t(ticked === 1 ? '%d marcado' : '%d marcados', ticked) : '');
  };
  const paint = () => {
    const groups = treeGroups(trees, dimension, ui.q);
    const all = treeGroups(trees, dimension);
    // Owner's review of PR #100: each block folds; blockOpen() decides which open on their own.
    listBox.replaceChildren(...(all.length === 0
      ? [h('p', { class: 'oer-wz-help' }, t('Esta materia no tiene términos vigentes en el currículo.'))]
      : groups.length === 0
        ? [h('p', { class: 'oer-wz-help' }, t('Nada coincide con «%s». Prueba con otra palabra.', ui.q))]
        : groups.map((group) => {
          const userKey = dimension + '|' + group.block;
          const counter = h('span', { class: 'oer-wz-block-count' }, blockCount(group));
          const details = h('details', { class: 'oer-wz-fold', open: blockOpen(group, {
            checked: state[key].map((x) => x.id), query: ui.q, single: all.length === 1, user: ui.userBlocks[userKey],
          }) },
          h('summary', { onclick: (e) => { ui.userBlocks[userKey] = !e.currentTarget.parentElement.open; } },
            h('span', { class: 'oer-wz-cap oer-wz-block' }, group.block), counter),
          h('div', { class: 'oer-wz-fold-body', role: 'group', 'aria-label': group.block }, group.items.map((item) => h('label', { class: 'oer-wz-choice is-term' },
            h('input', { type: 'checkbox', checked: state[key].some((x) => x.id === item.id), onchange: (e) => {
              state[key] = e.target.checked
                ? [...state[key], { id: item.id, title: item.title, code: item.code }]
                : state[key].filter((x) => x.id !== item.id);
              changed();
              // No repaint: only the counters change, so an open block stays open.
              counter.textContent = blockCount(group);
              head.querySelector('.oer-wz-count').textContent = t(state[key].length === 1 ? '%d marcado' : '%d marcados', state[key].length);
              refreshCounts();
            } }),
            h('span', { class: 'oer-wz-choice-text' }, h('span', {}, item.title), item.code ? h('span', { class: 'oer-wz-code' }, item.code) : null)))));
          return details;
        })));
  };
  paint();
  const searchId = nextId('wz-q');
  const search = h('div', { class: 'oer-wz-search' },
    h('label', { for: searchId, class: 'oer-wz-visually-hidden' }, t('Buscar en la lista')),
    icon('search'),
    h('input', { id: searchId, class: 'oer-wz-input', type: 'search', placeholder: t('Buscar, por ejemplo: fracciones'), value: ui.q,
      oninput: (e) => { ui.q = e.target.value; paint(); } }));
  return h('div', { class: 'oer-wz-field', style: 'gap: 14px' }, head, help, search, listBox);
}

function subAxes() {
  const axes = loaders.axes();
  if (!axes) {
    return loadingOrRetry('axes');
  }
  if (!axes.length) {
    return h('p', { class: 'oer-wz-help' }, t('No hay nada que elegir aquí todavía.'));
  }
  // Owner's review of PR #100: 72 flat axes, so a search, the ticked ones as chips and
  // an alphabetical checklist. No invented categories: the data has none.
  const chips = h('div', { class: 'oer-wz-chips', 'aria-live': 'polite' });
  const listBox = h('div', { class: 'oer-wz-axes' });
  const toggle = (axis, on) => {
    state.axes = on ? [...state.axes, { id: axis.id, title: axis.title }] : state.axes.filter((a) => a.id !== axis.id);
    changed();
    paintChips();
    refreshCounts();
  };
  const paintChips = () => {
    chips.replaceChildren(...(state.axes.length
      ? [h('span', { class: 'oer-wz-cap oer-wz-block' }, t('Marcados')), ...[...state.axes]
        .sort((a, b) => a.title.localeCompare(b.title, 'es', { sensitivity: 'base' }))
        .map((axis) => h('span', { class: 'oer-wz-chip' }, axis.title,
          h('button', { type: 'button', class: 'oer-wz-chip-x', 'aria-label': t('Quitar «%s»', axis.title), onclick: () => {
            toggle(axis, false);
            const box = listBox.querySelector('input[data-axis="' + axis.id + '"]');
            if (box) {
              box.checked = false;
            }
          } }, '✕')))]
      : []));
  };
  const paintList = () => {
    const letters = axisList(axes, ui.axisQ);
    listBox.replaceChildren(...(letters.length
      ? letters.map((group) => h('div', { class: 'oer-wz-axes-letter' },
        h('p', { class: 'oer-wz-letter', 'aria-hidden': 'true' }, group.letter),
        group.items.map((axis) => h('label', { class: 'oer-wz-check', title: axis.description || null },
          h('input', { type: 'checkbox', 'data-axis': String(axis.id), checked: state.axes.some((a) => a.id === axis.id),
            onchange: (e) => toggle(axis, e.target.checked) }),
          h('span', { class: 'oer-wz-choice-text' }, h('span', {}, axis.title),
            axis.description ? h('span', { class: 'oer-wz-check-desc' }, axis.description) : null)))))
      : [h('p', { class: 'oer-wz-help' }, t('Nada coincide con «%s». Prueba con otra palabra.', ui.axisQ))]));
  };
  paintChips();
  paintList();
  const searchId = nextId('wz-axis-q');
  return h('fieldset', { class: 'oer-wz-field', style: 'gap: 14px' },
    h('legend', {}, h('h3', {}, t('Ejes temáticos'), h('span', { class: 'oer-wz-optional', style: 'font-size: 15px' }, ' · ' + t('opcional')))),
    h('p', { class: 'oer-wz-help', style: 'font-size: 15px' }, t('Marca los ejes que trabaja tu recurso. Puedes dejarlo en blanco.')),
    h('div', { class: 'oer-wz-search' },
      h('label', { for: searchId, class: 'oer-wz-visually-hidden' }, t('Buscar un eje temático')),
      icon('search'),
      h('input', { id: searchId, class: 'oer-wz-input', type: 'search', placeholder: t('Buscar, por ejemplo: sostenibilidad'), value: ui.axisQ,
        oninput: (e) => { ui.axisQ = e.target.value; paintList(); } })),
    chips, listBox);
}

function stepAnchoring() {
  const subs = [subStage, () => (state.stage ? subCourse() : subStage()), () => (state.courses.length ? subSubject() : subCourse()),
    () => (state.subject ? subTerms(TEACHES_TERM) : subSubject()), () => (state.subject ? subTerms(ASSESSES_TERM) : subSubject()), subAxes];
  return [heading(t('¿Para qué curso y materia?'), t('Así lo encontrará el profesorado que busca recursos para su clase.')),
    subTabs(), subs[ui.sub - 1](),
    h('div', { class: 'oer-wz-summary' }, h('span', { class: 'oer-wz-cap' }, t('Hasta ahora')), h('span', { 'data-wz-summary': '' }, summary(state)))];
}

// ---- Step ④: review --------------------------------------------------------

function loadDuplicates() {
  const title = ((state.values[TITLE_TERM] || [])[0] || '').trim();
  const name = mainFileName(state);
  ui.duplicates = null;
  if (!title && !name) {
    return;
  }
  getJson(urls.duplicates, { title, name, id: state.id || '' })
    .then((data) => { ui.duplicates = data.duplicates || []; })
    .catch(() => { ui.duplicates = []; })
    .finally(() => { if (ui.step === 4) { ui.focus = false; render(); } });
}

function valueText(term) {
  const field = fieldOf(term);
  const values = state.values[term] || [];
  if (!field || !values.length) {
    return '—';
  }
  return values.map((value) => {
    const option = (field.options || []).find((o) => (o.uri || o.value) === value);
    return option ? option.label || option.value || option.uri : value;
  }).join(', ');
}

function stepReview() {
  const codes = missing(state, cfg.requiredTerms);
  const parts = [heading(t('Revisar y proponer'), t('Comprueba que todo está bien. Al proponerlo, el equipo de curación lo revisará antes de publicarlo.'))];
  if (codes.length) {
    parts.push(h('div', { role: 'region', 'aria-label': t('Le falta') }, notice('important', 'alert',
      h('p', { class: 'oer-wz-cap' }, t('Le falta')),
      h('ul', {}, missingItems(codes, cfg.schema).map((item) => h('li', {}, h('span', {}, t(item.label)),
        h('button', { type: 'button', class: 'oer-wz-link', onclick: () => go(item.step, item.sub) }, t('Completar'), icon('next'))))))));
  } else {
    parts.push(h('p', { role: 'status' }, notice('ok', 'check', h('span', {}, t('Está todo lo necesario para proponerlo.')))));
  }
  if (ui.duplicates && ui.duplicates.length) {
    parts.push(h('div', { role: 'region', 'aria-label': t('Puede que ya esté en la Mediateca') }, notice('', 'copy',
      h('p', { class: 'oer-wz-notice-title' }, t('Puede que ya esté en la Mediateca')),
      h('p', {}, t('Hay otro REA con el mismo título o con un fichero del mismo nombre. Ábrelo para comprobarlo. Si es distinto, puedes proponer el tuyo igualmente.')),
      h('ul', {}, ui.duplicates.map((d) => h('li', {}, h('a', { href: d.url, target: '_blank', rel: 'noopener' }, d.title),
        h('span', { class: 'oer-wz-help' }, d.reason === 'name' ? t('mismo nombre de fichero') : t('mismo título'))))))));
  }
  const resourceList = state.resources.length
    ? h('ol', { class: 'oer-wz-review-res' }, state.resources.map((r, i) => h('li', {},
      r.kind === 'url' ? r.url : [r.name, formatBytes(r.size)].filter(Boolean).join(' · '),
      i === 0 && state.resources.length > 1 ? h('span', { class: 'oer-wz-main-tag' }, t('Principal')) : null)))
    : h('p', { class: 'oer-wz-file-name' }, t('Todavía no has añadido ningún fichero ni enlace.'));
  const section = (title, body, step) => h('section', {},
    h('div', { class: 'oer-wz-record-body' }, h('h3', {}, title), ...body),
    h('button', { type: 'button', class: 'oer-wz-link', style: 'align-self: flex-start', onclick: () => go(step, 1) }, t('Cambiar')));
  const picked = [...state.teaches, ...state.assesses];
  parts.push(h('div', { class: 'oer-wz-record' },
    section(t('Tu recurso'), [resourceList], 1),
    section(t('Datos'), [h('dl', {}, fields.flatMap((field) => [h('dt', {}, field.label), h('dd', {}, valueText(field.term))]))], 2),
    section(t('Curso y materia'), [h('p', { style: 'font-weight: 600; color: var(--wz-ink)' }, summary(state)),
      picked.length ? h('ul', {}, picked.map((p) => h('li', {}, p.code ? h('span', { class: 'oer-wz-code' }, p.code) : null, h('span', {}, p.title)))) : null], 3)));
  return parts;
}

// ---- Saving ----------------------------------------------------------------

async function save(propose) {
  if (!((state.values[TITLE_TERM] || [])[0] || '').trim()) {
    ui.error = { message: t('Para guardar un borrador basta con el título. Escríbelo en el paso «Datos».') };
    return render();
  }
  const startKey = storage.key();
  ui.busy = true;
  ui.error = null;
  render();
  const form = new FormData();
  form.append('state', JSON.stringify(savePayload(state, propose)));
  let result;
  try {
    result = await postForm(urls.save, form);
  } catch (e) {
    result = { ok: false, message: t('No se pudo guardar') };
  }
  if (result.ok) {
    storage.clear(startKey, storageKey(cfg.user, result.id));
    window.location.assign(result.redirect);
    return;
  }
  ui.busy = false;
  // The media the save created are the REA's now: a retry keeps them instead of adding them again.
  const created = new Map((result.created || []).map((c) => [c.key, c.id]));
  const failed = new Map((result.failed || []).map((f) => [f.key, (f.messages || []).join(' ') || t('El catálogo no lo ha aceptado.')]));
  if (result.key && ['upload_expired', 'url_invalid', 'url_private'].includes(result.error)) {
    failed.set(result.key, t(result.message || 'No se pudo guardar'));
  }
  state.resources = state.resources.map((r) => {
    if (created.has(r.key)) {
      const { token, error, ...rest } = r;
      return { ...rest, kind: 'media', id: created.get(r.key), name: r.kind === 'url' ? r.url : r.name, link: r.kind === 'url' };
    }
    return failed.has(r.key) ? { ...r, error: failed.get(r.key) } : r;
  });
  if (result.id && !state.id) {
    // A later step failed: the REA exists as a draft; the next save updates it.
    storage.clear(startKey);
    state.id = result.id;
    const url = new URL(window.location.href);
    url.searchParams.set('id', String(result.id));
    window.history.replaceState(null, '', url);
  }
  if (result.id) {
    storage.write();
  }
  if (failed.size) {
    ui.step = 1;
    ui.sub = 1;
  }
  ui.error = { message: t(result.message || 'No se pudo guardar'), details: result.messages || [], missing: result.missing || [] };
  ui.focus = true;
  render();
  root.querySelector('[data-wz-error]')?.focus();
}

// ---- Frame -----------------------------------------------------------------

function renderSteps() {
  const ol = root.querySelector('[data-wz-steps]');
  const done = (n) => n < 4 && n <= ui.maxStep && ui.step !== n && (
    n === 1 ? state.resources.length > 0
      : n === 2 ? !missing(state, cfg.requiredTerms).some((c) => c === 'licence' || (c.startsWith('field:') && fieldOf(c.slice(6))))
        : !missing(state, []).some((c) => c === 'course' || c === 'subject'));
  ol.replaceChildren(...STEPS.map((label, i) => {
    const n = i + 1;
    const current = ui.step === n;
    const locked = n > ui.maxStep;
    const isDone = done(n);
    return h('li', {}, h('button', { type: 'button', class: 'oer-wz-step', disabled: locked, 'aria-current': current ? 'step' : 'false', onclick: () => go(n, 1) },
      h('span', { class: 'oer-wz-step-name' }, h('span', { class: 'oer-wz-step-num' }, n + '.'), h('span', {}, t(label)),
        isDone ? h('span', { class: 'oer-wz-done' }, icon('check')) : null),
      h('span', { class: 'oer-wz-step-state' }, current ? t('Estás aquí') : isDone ? t('Hecho') : locked ? t('Más adelante') : t('Por completar'))));
  }));
}

function banners() {
  const out = [];
  if (ui.recover) {
    out.push(h('section', { role: 'region', 'aria-label': t('¿Recuperar lo que no guardaste?') }, notice('', 'history',
      h('p', { class: 'oer-wz-notice-title' }, t('¿Recuperar lo que no guardaste?')),
      h('p', {}, t('En este equipo hay cambios que no llegaste a guardar.')),
      h('div', { class: 'oer-wz-notice-actions' },
        h('button', { type: 'button', class: 'oer-wz-btn', onclick: () => { storage.clear(storage.key()); ui.recover = null; render(); } }, t('Empezar de cero')),
        h('button', { type: 'button', class: 'oer-wz-btn is-primary', onclick: () => {
          const local = ui.recover;
          state = { ...emptyState(), ...withResources(local.state), id: state.id, status: state.status, rejection: state.rejection };
          ui.recover = null;
          ui.maxStep = 4;
          go(Number(local.step) || 1, Number(local.sub) || 1);
        } }, t('Recuperar'))))));
  }
  if (state.rejection) {
    out.push(h('section', { role: 'region', 'aria-label': t('Devuelto para revisar') }, notice('important', 'returned',
      h('p', { class: 'oer-wz-cap' }, t('Devuelto para revisar')),
      h('p', {}, state.rejection),
      h('p', {}, t('Corrige lo que se pide y vuelve a proponerlo.')))));
  }
  return out;
}

const canGoBack = () => ui.step > 1 || (ui.step === 3 && ui.sub > 1);

function backButton() {
  return h('button', { type: 'button', class: 'oer-wz-btn', disabled: ui.busy, onclick: back }, icon('back'), t('Atrás'));
}

function nextButton() {
  return h('button', { type: 'button', class: 'oer-wz-btn is-primary', disabled: ui.busy, onclick: next },
    ui.step === 3 && ui.sub === 6 ? t('Revisar') : t('Siguiente'), icon('next'));
}

function navigation() {
  const codes = missing(state, cfg.requiredTerms);
  const helpId = nextId('wz-propose-help');
  const end = [h('button', { type: 'button', class: 'oer-wz-btn', disabled: ui.busy, onclick: () => save(false) }, t('Guardar borrador'))];
  if (ui.step < 4) {
    end.push(nextButton());
  } else {
    end.push(h('button', { type: 'button', class: 'oer-wz-btn is-primary', disabled: ui.busy || codes.length > 0,
      'aria-describedby': codes.length ? helpId : null, onclick: () => save(true) }, t('Guardar y proponer')));
  }
  const nav = [h('div', { class: 'oer-wz-nav' },
    h('div', {}, canGoBack() ? backButton() : null),
    h('div', { class: 'oer-wz-nav-end' }, ui.busy ? h('span', { class: 'oer-wz-busy', role: 'status' }, t('Guardando…')) : null, ...end))];
  if (ui.step === 4 && codes.length) {
    nav.push(h('p', { id: helpId, class: 'oer-wz-help oer-wz-nav-help' }, t('Completa lo que falta para poder proponerlo. El borrador solo necesita el título.')));
  }
  return nav;
}

/**
 * «Atrás» and «Siguiente» in the admin's fixed action bar (second review of
 * PR #100), like «Añadir» on a new item. «Guardar y proponer» stays at the
 * bottom of step ④, after the review's warnings.
 */
function renderActions() {
  const bar = root.querySelector('[data-wz-actions]');
  if (!bar) {
    return;
  }
  bar.replaceChildren(...[canGoBack() ? backButton() : null, ui.step < 4 ? nextButton() : null].filter(Boolean));
}

function errorBox() {
  if (!ui.error) {
    return null;
  }
  const items = ui.error.missing?.length ? missingItems(ui.error.missing, cfg.schema) : [];
  return h('div', { role: 'alert', tabindex: '-1', 'data-wz-error': '' }, notice('error', 'error',
    h('p', { class: 'oer-wz-notice-title' }, ui.error.message),
    ...(ui.error.details || []).map((d) => h('p', {}, d)),
    items.length ? h('ul', {}, items.map((item) => h('li', {}, h('span', {}, t(item.label)),
      h('button', { type: 'button', class: 'oer-wz-link', onclick: () => go(item.step, item.sub) }, t('Completar'))))) : null));
}

function render() {
  renderSteps();
  const body = [stepResource, stepData, stepAnchoring, stepReview][ui.step - 1]();
  const main = root.querySelector('[data-wz-main]');
  renderActions();
  main.replaceChildren(...banners(), h('div', { class: 'oer-wz-panel' }, ...body), errorBox() || '', ...navigation());
  if (ui.focus) {
    ui.focus = false;
    main.querySelector('[data-wz-heading]')?.focus();
  }
}

// ---- Start -----------------------------------------------------------------

function start() {
  if (!cfg || !root) {
    return;
  }
  if (cfg.item) {
    const place = firstIncomplete(state, cfg.requiredTerms);
    Object.assign(ui, { step: place.step, sub: place.sub, maxStep: 4 });
  } else if (cfg.defaultLicence && fieldOf(LICENCE_TERM)) {
    state.values[LICENCE_TERM] = [cfg.defaultLicence];
  }
  const local = storage.read(storage.key());
  if (recoverable(local, cfg.item ? cfg.item.modified : 0)) {
    ui.recover = local;
  }
  ui.focus = false;
  if (ui.step === 4) {
    loadDuplicates();
  }
  render();
}

start();
