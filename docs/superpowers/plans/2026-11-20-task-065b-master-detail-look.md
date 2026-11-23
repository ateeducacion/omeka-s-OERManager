# TASK-065 part B (B8): master-detail editor with its own look — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the tree editor of part B as the master-detail editor of spec B8.
- A navigator holds the courses, their subjects with counts, «Sin encajar» and the thematic axis.
- One subject is in focus, with tabs for Saberes and Criterios.
- A footer counts the pending changes, and an in-panel review step comes before «Confirmar».
- The editor has its own look (ADR-0024): bundled Public Sans and IBM Plex Mono, its own tokens, and its
  own stylesheet.

**Architecture:**
- **The model stays.** `core/alignmentTree.js` gains three pure helpers: `changeCounts`, `aiCount` and
  `focusSubject`.
- **The view is rewritten.** `ui/alignmentEditor.js` is rewritten for the new DOM, and keeps its exports
  plus `showReview`/`showEdit`. `recatalog.js` keeps the preview, apply and cancel handlers, keyed by the
  same classes, and renders the grouped diff into the review step.
- **The look has its own stylesheet.** It lives in `asset/css/oer-alignment-editor.css`, with every
  selector under `.oer-align-editor` and the tokens declared once there. A test measures their WCAG
  contrast.
- **The fonts ship as woff2** under `asset/fonts/`, with their OFL licences.

**Tech Stack:** ES modules + jQuery (Omeka admin), `node --test`, PHPUnit (unchanged), headless Chrome for one throwaway DOM check.

**Spec:** `docs/superpowers/specs/2026-11-18-task-065-panel-usability-design.md` §B8 (and B2–B7, which stand); ADR-0024.

## Global Constraints

- **The RDF and the apply contract do not change.** `collectAlignmentPairs`, the preview and apply
  endpoints, the audit, undo and the AI justifications behave as in part B.
- **ADR-0024:**
  - the look is scoped to `.oer-align-editor`, in `asset/css/oer-alignment-editor.css`;
  - fonts are bundled under `asset/fonts/` with their licences, and never loaded from a CDN;
  - tokens are declared once, and every text pair is ≥ 4.5:1 and every control boundary ≥ 3:1 (a test
    pins this);
  - ADR-0014 rules 2–4 hold inside the editor;
  - controls are native.
- **Outside the editor nothing changes look.** The drawer's read mode, tabs, flow bar and master view keep
  ADR-0014.
- **Accessibility (NFR-006):**
  - the navigator rows are buttons with `aria-current`;
  - the tabs follow the ARIA tabs pattern;
  - the review step moves the focus to its heading, and «Seguir editando» returns it;
  - touch targets are at least 44px;
  - a polite live region announces changes.
- **Copy:** Spanish UI strings, registered in `js_translate_strings`. English code comments and docs.
- **Commits:** a plain-text `Model: Claude Opus 5.5` line in the body, and no Co-Authored-By trailer.

## Review Focus

1. **The review step must never show «Confirmar» enabled over a diff that no longer matches the
   selection.** Any change made after «Revisar cambios» must disable it. This is `setState` →
   `disableApply`, pinned in the Task 5 headless check.
2. **Going back with «Seguir editando» keeps everything**: selection, focused subject, tab and filters.
   Pinned in Task 5.
3. **An unfitted subject with terms** lists those terms in the navigator's «Sin encajar» (part B review
   I1). Pinned in Task 5.
4. **The footer counts match what will be sent.** `changeCounts` compares sets, as `alignmentPairs`
   does. Pinned in Task 1.
5. **With no subject chosen**, the detail invites the curator to add one instead of staying blank, and
   `focusSubject` returns null. Pinned in Task 1.

## File structure

| File | Responsibility |
| --- | --- |
| `asset/js/core/alignmentTree.js` (modify) | + `changeCounts`, `aiCount`, `focusSubject` |
| `asset/fonts/` (create) | `public-sans-latin-wght-normal.woff2`, `ibm-plex-mono-latin-500-normal.woff2`, `OFL-*.txt`, `README.md` (source, versions) |
| `asset/css/oer-alignment-editor.css` (create) | `@font-face`, tokens, the editor's whole look, narrow layout by container query |
| `asset/css/oer-master-view.css` (modify) | remove the part-B tree block (moved), keep the read-mode rule, widen the sidebar while editing |
| `view/oer-manager/admin/index/index.phtml` (modify) | link the new stylesheet |
| `asset/js/ui/alignmentEditor.js` (rewrite) | master-detail DOM: header, navigator, detail, footer, review step |
| `asset/js/ui/recatalog.js` (modify) | panel = editor; preview opens the review step; grouped diff into it |
| `config/module.config.php` (modify) | new strings |
| `test/js/alignmentTree.test.js` (modify) | model helpers |
| `test/js/editorContrast.test.js` (create) | WCAG contrast of the editor tokens |

---

### Task 1: Model helpers for the footer, the header and the focused subject

**Files:**
- Modify: `asset/js/core/alignmentTree.js`
- Test: `test/js/alignmentTree.test.js`

**Interfaces:**
- Produces:
  - `changeCounts(state) → {added, removed}`, over the four curricular terms, by set against `initial`.
  - `aiCount(state) → number`, the curricular values marked `ai`.
  - `focusSubject(layout, current) → id|null`. It returns `current` if `current` is still placed under a
    chosen course, otherwise the first placed subject, otherwise null.

- [ ] **Step 1: Write the failing tests** (append; extend the import with `changeCounts, aiCount, focusSubject`)

```js
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `node --test test/js/alignmentTree.test.js`
Expected: FAIL — `changeCounts` is not exported.

- [ ] **Step 3: Implement** (append to `alignmentTree.js`)

```js
/** Pending changes for the footer, counted by set as `alignmentPairs` sends them. */
export function changeCounts(state) {
    let added = 0;
    let removed = 0;
    CURRICULAR_TERMS.forEach((term) => {
        const now = state.values[term].map((one) => one.id);
        const before = state.initial[term];
        added += now.filter((id) => !before.includes(id)).length;
        removed += before.filter((id) => !now.includes(id)).length;
    });
    return { added, removed };
}

/** Curricular values the AI proposed and the curator has not removed. */
export function aiCount(state) {
    return CURRICULAR_TERMS.reduce((sum, term) => sum + state.values[term].filter((one) => one.ai).length, 0);
}

/** The subject the detail shows: the current one while it is placed, else the first placed. */
export function focusSubject(layout, current) {
    const ids = layout.courses.flatMap((course) => course.subjects.map((subject) => subject.id));
    if (current && ids.includes(String(current))) {
        return String(current);
    }
    return ids.length ? ids[0] : null;
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `node --test test/js/alignmentTree.test.js`
Expected: PASS (26 tests).

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/alignmentTree.js test/js/alignmentTree.test.js
git commit -m "feat: alignment model counts pending and AI changes and picks the focused subject (TASK-065 B8)

Model: Claude Opus 5.5"
```

---

### Task 2: Bundled fonts, the editor stylesheet and its measured tokens

**Files:**
- Create: `asset/fonts/public-sans-latin-wght-normal.woff2`, `asset/fonts/ibm-plex-mono-latin-500-normal.woff2`, `asset/fonts/OFL-PublicSans.txt`, `asset/fonts/OFL-IBMPlexMono.txt`, `asset/fonts/README.md`
- Create: `asset/css/oer-alignment-editor.css` (first part: fonts and tokens)
- Create: `test/js/editorContrast.test.js`
- Modify: `view/oer-manager/admin/index/index.phtml`

**Interfaces:**
- Produces: the CSS variables declared on `.oer-align-editor`, used by Task 4: `--ae-ground`, `--ae-surface`, `--ae-ink`, `--ae-muted`, `--ae-rule`, `--ae-control`, `--ae-accent`, `--ae-accent-ink`, `--ae-accent-soft`, `--ae-on-accent`, `--ae-ai`, `--ae-ai-soft`, `--ae-ai-line`, `--ae-unfit`, `--ae-unfit-soft`, `--ae-added`, `--ae-added-soft`, `--ae-removed`, `--ae-removed-soft`, `--ae-danger`, `--ae-sans`, `--ae-mono`.

- [ ] **Step 1: Write the failing contrast test** `test/js/editorContrast.test.js`

```js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { contrastRatio, parseTokens } from '../../asset/js/core/contrast.js';

/**
 * ADR-0024 point 3: the editor's own palette is measured, not chosen by eye.
 * Text pairs need 4.5:1 (WCAG 1.4.3); control boundaries 3:1 (WCAG 1.4.11).
 */
const css = readFileSync(new URL('../../asset/css/oer-alignment-editor.css', import.meta.url), 'utf8');
const t = parseTokens(css);

const TEXT = [
    ['--ae-ink', '--ae-surface'], ['--ae-ink', '--ae-ground'], ['--ae-ink', '--ae-accent-soft'],
    ['--ae-muted', '--ae-surface'], ['--ae-muted', '--ae-ground'],
    ['--ae-accent-ink', '--ae-surface'], ['--ae-accent-ink', '--ae-accent-soft'], ['--ae-on-accent', '--ae-accent'],
    ['--ae-ai', '--ae-ai-soft'], ['--ae-ai', '--ae-surface'],
    ['--ae-unfit', '--ae-unfit-soft'],
    ['--ae-added', '--ae-added-soft'], ['--ae-removed', '--ae-removed-soft'],
    ['--ae-danger', '--ae-surface']
];
const BOUNDARY = [['--ae-control', '--ae-surface'], ['--ae-control', '--ae-ground'], ['--ae-accent', '--ae-surface']];

test('los tokens del editor existen', () => {
    TEXT.concat(BOUNDARY).flat().forEach((name) => assert.match(t[name] || '', /^#[0-9a-f]{6}$/i, name));
});

test('todo texto del editor pasa AA (4.5:1)', () => {
    TEXT.forEach(([fg, bg]) => assert.ok(contrastRatio(t[fg], t[bg]) >= 4.5, `${fg} on ${bg}: ${contrastRatio(t[fg], t[bg]).toFixed(2)}`));
});

test('el contorno de los controles pasa 3:1', () => {
    BOUNDARY.forEach(([fg, bg]) => assert.ok(contrastRatio(t[fg], t[bg]) >= 3, `${fg} on ${bg}: ${contrastRatio(t[fg], t[bg]).toFixed(2)}`));
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node --test test/js/editorContrast.test.js`
Expected: FAIL — `ENOENT` for `oer-alignment-editor.css`.

- [ ] **Step 3: Download the fonts (build time only; never at runtime)**

```bash
PS=$(curl -s https://data.jsdelivr.com/v1/packages/npm/@fontsource-variable/public-sans/resolved | python3 -c 'import json,sys;print(json.load(sys.stdin)["version"])')
PM=$(curl -s https://data.jsdelivr.com/v1/packages/npm/@fontsource/ibm-plex-mono/resolved | python3 -c 'import json,sys;print(json.load(sys.stdin)["version"])')
mkdir -p asset/fonts
curl -sfL -o asset/fonts/public-sans-latin-wght-normal.woff2 "https://cdn.jsdelivr.net/npm/@fontsource-variable/public-sans@$PS/files/public-sans-latin-wght-normal.woff2"
curl -sfL -o asset/fonts/ibm-plex-mono-latin-500-normal.woff2 "https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-mono@$PM/files/ibm-plex-mono-latin-500-normal.woff2"
curl -sfL -o asset/fonts/OFL-PublicSans.txt "https://cdn.jsdelivr.net/npm/@fontsource-variable/public-sans@$PS/LICENSE"
curl -sfL -o asset/fonts/OFL-IBMPlexMono.txt "https://cdn.jsdelivr.net/npm/@fontsource/ibm-plex-mono@$PM/LICENSE"
file asset/fonts/*.woff2; head -3 asset/fonts/OFL-*.txt; echo "$PS $PM"
```

Expected: two `Web Open Font Format (Version 2)` files and two licence texts naming the SIL Open Font License. The latin subset covers Spanish (á é í ó ú ñ ü ¿ ¡ º ª « »).

Write `asset/fonts/README.md`, replacing `<PS>` and `<PM>` with the versions printed above:

```markdown
# Bundled fonts (ADR-0024)

Served from the module, never from a CDN: loading them from a third party would send each curator's IP
to it from the production admin. Used only inside the curricular alignment editor.

| File | Family | Source | Licence |
| --- | --- | --- | --- |
| `public-sans-latin-wght-normal.woff2` | Public Sans (variable, 100–900) | `@fontsource-variable/public-sans@<PS>` | SIL OFL 1.1 (`OFL-PublicSans.txt`) |
| `ibm-plex-mono-latin-500-normal.woff2` | IBM Plex Mono 500 | `@fontsource/ibm-plex-mono@<PM>` | SIL OFL 1.1 (`OFL-IBMPlexMono.txt`) |

Latin subset: it covers Spanish. To update, download the same files from a newer version and
replace them.
```

- [ ] **Step 4: Create `asset/css/oer-alignment-editor.css`** (first part; Task 4 appends the rest)

```css
/*
 * The curricular alignment editor's own look (ADR-0024, spec B8 of TASK-065).
 * Scope: every rule hangs from .oer-align-editor; nothing here may style the
 * drawer outside the editor, which keeps ADR-0014. Fonts are bundled
 * (asset/fonts/README.md); the tokens are declared once and their contrast is
 * pinned by test/js/editorContrast.test.js.
 */

@font-face {
    font-family: "OER Public Sans";
    src: url("../fonts/public-sans-latin-wght-normal.woff2") format("woff2");
    font-weight: 100 900;
    font-style: normal;
    font-display: swap;
}

@font-face {
    font-family: "OER Plex Mono";
    src: url("../fonts/ibm-plex-mono-latin-500-normal.woff2") format("woff2");
    font-weight: 500;
    font-style: normal;
    font-display: swap;
}

.oer-align-editor {
    --ae-ground: #F4F6F8;
    --ae-surface: #FFFFFF;
    --ae-ink: #18212F;
    --ae-muted: #5B6474;
    --ae-rule: #DCE1E8;
    --ae-control: #7C8695;
    --ae-accent: #0B6B5E;
    --ae-accent-ink: #0B6B5E;
    --ae-accent-soft: #E3F2EF;
    --ae-on-accent: #FFFFFF;
    --ae-ai: #4A3196;
    --ae-ai-soft: #EEE9FB;
    --ae-ai-line: #CFC4F2;
    --ae-unfit: #7A3A00;
    --ae-unfit-soft: #FDF0E1;
    --ae-added: #08493F;
    --ae-added-soft: #E3F2EF;
    --ae-removed: #8A2222;
    --ae-removed-soft: #FBE9E9;
    --ae-danger: #A12C2C;
    --ae-sans: "OER Public Sans", system-ui, -apple-system, "Segoe UI", sans-serif;
    --ae-mono: "OER Plex Mono", ui-monospace, "SFMono-Regular", Menlo, monospace;
}
```

- [ ] **Step 5: Link the stylesheet**

In `view/oer-manager/admin/index/index.phtml`, after the `oer-master-view.css` line:

```php
// ADR-0024: the alignment editor's own look (fonts load only when it renders).
$this->headLink()->appendStylesheet($this->assetUrl('css/oer-alignment-editor.css', 'OERManager'));
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `node --test test/js/editorContrast.test.js && make test-js`
Expected: PASS. If a pair fails, move that token until it passes, and record the change as a ruling. ADR-0024 governs the criterion, not the hex values.

- [ ] **Step 7: Commit**

```bash
git add asset/fonts asset/css/oer-alignment-editor.css test/js/editorContrast.test.js view/oer-manager/admin/index/index.phtml
git commit -m "feat: bundled fonts and measured tokens for the alignment editor (ADR-0024)

Model: Claude Opus 5.5"
```

---

### Task 3: The master-detail editor

**Files:**
- Rewrite: `asset/js/ui/alignmentEditor.js`

**Interfaces:**
- Consumes:
  - Task 1 helpers plus the part-B model.
  - `leafTreeModel.js`: `treeViews`, `filterTrees`, `filterStatus`, `retiredById`, `selectableIds`.
  - `termSearch.js`: `buildTermSearch`.
  - `termPicker.js`: `buildDimensionSelector`, `chipIdsByTerm`, `disableApply`.
  - `anchorData.js`: `valuesFor`.
- Produces: unchanged exports — `buildAlignmentEditor(config, $panel, recatalog)`,
  `openAlignmentEditor(config, $panel)`, `editorPairs($panel)`, `editorState($panel)`,
  `proposeIntoEditor(config, $panel, alignment, justifications)`, `initAlignmentEditor(config)`. Plus:
  - `showReview($panel)`, which switches to the review step, fills its summary and focuses its heading;
  - `showEdit($panel)`, which goes back and focuses «Revisar cambios».
- DOM contract with `recatalog.js` and `aiPropose.js`. These classes stay; they now all live inside
  `.oer-align-editor`:
  - `.oer-recatalog-ai`, `.oer-recatalog-ai-cancel` in the header;
  - `.oer-recatalog-diff`, the footer status line for AI and preview messages;
  - `.oer-recatalog-cancel`, `.oer-recatalog-preview` («Revisar cambios») in the footer;
  - `.oer-recatalog-apply` («Confirmar re-catalogación») in the review step;
  - `.oer-ae-review-body`, where `recatalog.js` renders the grouped diff;
  - `.oer-recatalog-dim[data-term="dcterms:relation"]`, the axis chips, in the navigator.

- [ ] **Step 1: Replace `asset/js/ui/alignmentEditor.js` with:**

```js
import {
    COURSE, SUBJECT, TEACHES, LEAF_TERMS, RELATION, TERM_LABELS,
    createState, learnTrees, learnSubject, placement, summaryText, treeBatches,
    addCourse, addSubject, setLeaf, removeValue, removeSubject, removeCourse,
    alignmentPairs, mergeProposal, changeCounts, aiCount, focusSubject
} from '../core/alignmentTree.js';
import { treeViews, filterTrees, filterStatus, retiredById, selectableIds } from '../core/leafTreeModel.js';
import { valuesFor } from '../core/anchorData.js';
import { buildTermSearch } from './termSearch.js';
import { buildDimensionSelector, chipIdsByTerm, disableApply } from './termPicker.js';

/**
 * The curricular alignment editor (TASK-065 part B, spec B8; look: ADR-0024).
 *
 * Master-detail: a navigator (courses → subjects with their counts, «Sin
 * encajar», the thematic axis) selects the ONE subject the detail shows, with
 * tabs for knowledge and criteria. A footer counts the pending changes and
 * «Revisar cambios» opens the review step in the same panel, where the
 * grouped diff (rendered by recatalog.js) and «Confirmar re-catalogación»
 * live. Every decision about values is the model's (core/alignmentTree.js).
 *
 * Re-renders keep what the curator opened, closed and typed; a checkbox only
 * refreshes counts, so a long list keeps its scroll.
 */

const STATE = 'oerAlignment';
const UI = 'oerAlignmentUi';

function t(text) {
    return Omeka.jsTranslate(text);
}

function domId($panel, parts) {
    return ['oer-align', $panel.data('item-id')].concat(parts).join('-').replace(/[^\w-]/g, '');
}

export function editorState($panel) {
    return $panel.data(STATE) || null;
}

function setState($panel, state) {
    $panel.data(STATE, state);
    // A change of selection invalidates the last preview.
    disableApply($panel);
}

function ui($panel) {
    return $panel.data(UI);
}

function announce($panel, text) {
    $panel.find('.oer-align-status').text(text);
}

export function editorPairs($panel) {
    const state = editorState($panel);
    return state ? alignmentPairs(state) : [];
}

/** Loads the trees of these subjects (in batches of 10) and re-renders. */
function loadTrees($panel, subjectIds) {
    const view = ui($panel);
    const ids = subjectIds.filter((id) => !(id in view.trees));
    if (!ids.length) {
        renderEditor($panel);
        return;
    }
    ids.forEach((id) => {
        view.trees[id] = undefined;
    });
    const linked = LEAF_TERMS
        .reduce((all, term) => all.concat(editorState($panel).values[term].map((one) => one.id)), [])
        .slice(0, 500);
    treeBatches(ids).forEach((batch) => {
        $.getJSON(view.config.curriculumTreeUrl, { about: batch, linked }).done((response) => {
            (response.trees || []).forEach((tree) => {
                view.trees[String(tree.subject.id)] = tree;
            });
            (response.invalid || []).forEach((id) => {
                view.trees[String(id)] = null;
            });
            retiredById(response).forEach((value, id) => view.retired.set(id, value));
            // Learning where values hang is not a change of selection.
            $panel.data(STATE, learnTrees(editorState($panel), response));
            renderEditor($panel);
        }).fail(() => {
            batch.forEach((id) => {
                view.trees[id] = null;
                view.errors[id] = true;
            });
            $panel.data(STATE, learnTrees(editorState($panel), { trees: [], invalid: batch }));
            renderEditor($panel);
        });
    });
}

function button(classes, text, attrs = {}) {
    return $('<button>').attr(Object.assign({ type: 'button' }, attrs)).addClass(classes).text(text);
}

export function buildAlignmentEditor(config, $panel, recatalog) {
    $panel.data(STATE, createState(recatalog.current));
    $panel.data(UI, {
        config, subject: null, tab: TEACHES, trees: {}, errors: {},
        retired: new Map(), filters: {}, focus: null
    });

    const $editor = $('<div>').addClass('oer-align-editor');

    // Header: the AI entry point and how much it has proposed.
    const $header = $('<header>').addClass('oer-ae-header')
        .append($('<h4>').addClass('oer-ae-title').text(t('Anclaje curricular')))
        .append($('<p>').addClass('oer-ae-ai-count').prop('hidden', true));
    if (config.aiEnabled) {
        $header.append(button('button oer-recatalog-ai oer-ae-ai', t('Proponer con IA')))
            .append(button('button oer-recatalog-ai-cancel oer-ae-quiet', t('Detener la propuesta')));
    }
    $editor.append($header);

    // Edit view: navigator + detail.
    const $nav = $('<nav>').addClass('oer-ae-nav').attr('aria-label', t('Estructura del anclaje'));
    const $courseHead = $('<div>').addClass('oer-ae-nav-head')
        .append($('<h5>').addClass('oer-ae-label').text(t('Cursos')))
        .append(buildTermSearch({
            config,
            dimension: COURSE,
            label: t('Añadir curso'),
            context: () => ({ etapa: chipIdsByTerm($panel)['etapa'] || [] }),
            onPick: (result) => {
                setState($panel, addCourse(editorState($panel), result));
                ui($panel).focus = domId($panel, ['course', result.id, 'search']);
                renderEditor($panel);
                announce($panel, t('Curso añadido:') + ' ' + result.title);
            }
        }).addClass('oer-align-course-search'));
    // Etapa narrows the course search and is never written (ADR-0022).
    const $etapa = buildDimensionSelector('etapa', t('Etapa'), [], true);
    $etapa.append($('<p>').addClass('oer-dim-hint').text(t('Solo acota la búsqueda. No se guarda en el REA.')));
    $courseHead.append($('<details>').addClass('oer-ae-etapa')
        .append($('<summary>').text(t('Acotar por etapa')), $etapa));
    $nav.append($courseHead)
        .append($('<div>').addClass('oer-ae-courses'))
        .append($('<div>').addClass('oer-ae-unfitted-slot'))
        .append($('<section>').addClass('oer-ae-axis')
            .append($('<h5>').addClass('oer-ae-label').text(t(TERM_LABELS[RELATION])))
            .append($('<div>').addClass('oer-recatalog-dims')
                .append(buildDimensionSelector(RELATION, t(TERM_LABELS[RELATION]), valuesFor(recatalog, RELATION), false))));
    $editor.append($('<div>').addClass('oer-ae-body')
        .append($nav)
        .append($('<section>').addClass('oer-ae-detail')));

    // Footer: pending changes and the way to the review step.
    $editor.append($('<footer>').addClass('oer-ae-footer oer-recatalog-footer')
        .append($('<p>').addClass('oer-ae-changes'))
        .append($('<p>').addClass('oer-recatalog-diff oer-ae-footer-status').attr({ role: 'status', 'aria-live': 'polite' }))
        .append($('<div>').addClass('oer-ae-footer-actions')
            .append(button('button oer-recatalog-cancel oer-ae-quiet', t('Cancelar')))
            .append(button('button oer-recatalog-preview oer-ae-primary', t('Revisar cambios')))));

    // Review step (spec B8): the grouped diff and the only «Confirmar».
    const reviewHeadingId = domId($panel, ['review', 'heading']);
    $editor.append($('<section>').addClass('oer-ae-review').attr('aria-labelledby', reviewHeadingId).prop('hidden', true)
        .append($('<header>').addClass('oer-ae-review-head')
            .append(button('button oer-ae-back oer-ae-quiet', t('Seguir editando')))
            .append($('<div>')
                .append($('<h4>').attr({ id: reviewHeadingId, tabindex: '-1' }).addClass('oer-ae-title').text(t('Revisar los cambios')))
                .append($('<p>').addClass('oer-ae-muted').text(t('Nada se escribe en el REA hasta que confirmes.')))))
        .append($('<div>').addClass('oer-ae-review-grid')
            .append($('<div>').addClass('oer-ae-review-body'))
            .append($('<aside>').addClass('oer-ae-review-aside')
                .append($('<h5>').addClass('oer-ae-label').text(t('Resumen')))
                .append($('<dl>').addClass('oer-ae-summary'))
                .append($('<p>').addClass('oer-ae-note').text(t('Se guarda quién y cuándo, y podrás deshacerlo desde el historial.')))
                .append(button('button oer-recatalog-apply oer-ae-primary', t('Confirmar re-catalogación'), { disabled: 'disabled' }))
                .append(button('button oer-ae-back oer-ae-quiet', t('Seguir editando'))))));

    $editor.append($('<p>').addClass('oer-align-status oer-visually-hidden').attr({ role: 'status', 'aria-live': 'polite' }));
    return $editor;
}

export function openAlignmentEditor(config, $panel) {
    const state = editorState($panel);
    if (!state) {
        return;
    }
    renderEditor($panel);
    loadTrees($panel, state.values[SUBJECT].map((subject) => subject.id));
}

export function proposeIntoEditor(config, $panel, alignment, justifications) {
    const state = editorState($panel);
    if (!state) {
        return 0;
    }
    const { state: next, added } = mergeProposal(state, alignment, justifications);
    setState($panel, next);
    loadTrees($panel, next.values[SUBJECT].map((subject) => subject.id));
    return added;
}

/** Switches to the review step, fills its summary, focuses its heading. */
export function showReview($panel) {
    const state = editorState($panel);
    const counts = changeCounts(state);
    const $editor = $panel.find('.oer-align-editor');
    $editor.find('.oer-ae-summary').empty().append(
        $('<dt>').text(t('Se añaden')), $('<dd>').addClass('oer-ae-added').text('+' + counts.added),
        $('<dt>').text(t('Se quitan')), $('<dd>').addClass('oer-ae-removed').text('−' + counts.removed),
        $('<dt>').text(t('Propuestos por la IA')), $('<dd>').addClass('oer-ae-ai-text').text(String(aiCount(state)))
    );
    $editor.find('.oer-ae-body, .oer-ae-footer').prop('hidden', true);
    $editor.find('.oer-ae-review').prop('hidden', false);
    $editor.find('.oer-ae-review [tabindex="-1"]').first().trigger('focus');
}

/** Back to editing, everything kept; the focus returns to «Revisar cambios». */
export function showEdit($panel) {
    const $editor = $panel.find('.oer-align-editor');
    $editor.find('.oer-ae-review').prop('hidden', true);
    $editor.find('.oer-ae-body, .oer-ae-footer').prop('hidden', false);
    $editor.find('.oer-recatalog-preview').trigger('focus');
}

function aiMark(why, $panel, key) {
    const $mark = $('<span>').addClass('oer-ae-ai-mark').text(t('IA'));
    if (!why) {
        return $mark;
    }
    // The reason is there, collapsed, so it does not anchor the curator (PEND-013).
    return $('<details>').addClass('oer-ae-why').attr('id', domId($panel, ['why', key]))
        .append($('<summary>').append($mark, $('<span>').text(t('Ver porqué'))), $('<p>').text(why));
}

function counts(subject) {
    return 'S ' + subject[TEACHES].length + ' · C ' + subject[LEAF_TERMS[1]].length;
}

function renderNav($panel, layout) {
    const view = ui($panel);
    const $courses = $panel.find('.oer-ae-courses').empty();
    layout.courses.forEach((course) => {
        const $course = $('<section>').addClass('oer-ae-course').attr('data-id', course.id);
        $course.append($('<div>').addClass('oer-ae-course-head')
            .append($('<h5>').addClass('oer-ae-course-title').text(course.title))
            .append($('<span>').addClass('oer-ae-muted').text(summaryText('course', course, t)))
            .append(button('oer-ae-link oer-ae-danger-link oer-align-remove', t('Quitar curso'), {
                id: domId($panel, ['course', course.id, 'remove']), 'data-kind': 'course', 'data-id': course.id,
                'data-name': course.title, 'aria-label': t('Quitar curso') + ' ' + course.title
            })));
        const $list = $('<ul>').addClass('oer-ae-subjects');
        course.subjects.forEach((subject) => {
            const current = subject.id === view.subject;
            const $row = button('oer-ae-subject', '', {
                id: domId($panel, ['subject', subject.id, 'pick']), 'data-id': subject.id,
                'aria-current': current ? 'true' : 'false'
            }).append($('<span>').addClass('oer-ae-subject-name').text(subject.name))
                .append($('<span>').addClass('oer-ae-counts').text(counts(subject)));
            if (subject.ai) {
                $row.append(aiMark('', $panel, 'subject-' + subject.id));
            }
            $list.append($('<li>').append($row));
        });
        $course.append($list);
        const searchId = domId($panel, ['course', course.id, 'search']);
        const $search = buildTermSearch({
            config: view.config,
            dimension: SUBJECT,
            label: t('Añadir materia de') + ' ' + course.title,
            // A subject search narrowed to THIS course (spec B4).
            context: () => ({ level: [course.id] }),
            onPick: (result) => {
                let state = learnSubject(editorState($panel), result);
                state = addSubject(state, result, result.parentId || course.id);
                setState($panel, state);
                view.subject = String(result.id);
                view.focus = domId($panel, ['subject', result.id, 'pick']);
                loadTrees($panel, [String(result.id)]);
                announce($panel, t('Materia añadida:') + ' ' + result.title);
            }
        });
        $search.find('input').attr('id', searchId);
        $search.find('label').attr('for', searchId);
        $course.append($search.addClass('oer-ae-subject-search'));
        $courses.append($course);
    });
    if (!layout.courses.length) {
        $courses.append($('<p>').addClass('oer-ae-muted').text(t('Añade un curso para empezar.')));
    }

    const $slot = $panel.find('.oer-ae-unfitted-slot').empty();
    const $unfitted = renderUnfitted($panel, layout.unfitted);
    if ($unfitted) {
        $slot.append($unfitted);
    }
    if (layout.pending) {
        $slot.append($('<p>').addClass('oer-ae-muted').text(t('Cargando…')));
    }
}

function renderUnfitted($panel, unfitted) {
    const state = editorState($panel);
    const view = ui($panel);
    const $items = $('<ul>').addClass('oer-ae-unfitted-list');
    const removeButton = (kind, id, name) => button('oer-ae-link oer-ae-danger-link oer-align-remove', t('Quitar'), {
        'data-kind': kind, 'data-id': id, 'data-name': name, 'aria-label': t('Quitar') + ' ' + name
    });
    unfitted.subjects.forEach((subject) => {
        const courseId = state.courseOf[subject.id];
        const $li = $('<li>').append($('<span>').addClass('oer-ae-unfitted-name')
            .text(t('Materia') + ': ' + subject.title));
        if (subject.ai) {
            $li.append(aiMark('', $panel, 'unfit-' + subject.id));
        }
        const $actions = $('<span>').addClass('oer-ae-unfitted-actions');
        if (courseId && state.courseTitles[courseId]) {
            $actions.append(button('button oer-ae-small oer-align-add-course',
                t('Añadir') + ' ' + state.courseTitles[courseId], { 'data-id': courseId, 'data-title': state.courseTitles[courseId] }));
        }
        $actions.append(removeButton('subject', subject.id, subject.title));
        $li.append($actions);
        // Its knowledge and criteria are listed too, never hidden (spec B3).
        const $leaves = $('<ul>').addClass('oer-ae-unfitted-leaves');
        LEAF_TERMS.forEach((term) => subject[term].forEach((leaf) => {
            $leaves.append($('<li>').append($('<span>').text(t(TERM_LABELS[term]) + ': ' + leaf.title), removeButton(term, leaf.id, leaf.title)));
        }));
        if ($leaves.children().length) {
            $li.append($leaves);
        }
        $items.append($li);
    });
    LEAF_TERMS.forEach((term) => unfitted[term].forEach((leaf) => {
        const retired = view.retired.get(leaf.id);
        const $li = $('<li>').append($('<span>').addClass('oer-ae-unfitted-name').text(t(TERM_LABELS[term]) + ': ' + leaf.title));
        if (retired) {
            $li.append($('<span>').addClass('oer-ae-muted').text(t('retirado')
                + (retired.replacedBy.length ? ' · ' + t('Sustituto:') + ' ' + retired.replacedBy.join(', ') : '')));
        }
        if (leaf.ai) {
            $li.append(aiMark(leaf.why, $panel, 'unfit-' + leaf.id));
        }
        $li.append($('<span>').addClass('oer-ae-unfitted-actions').append(removeButton(term, leaf.id, leaf.title)));
        $items.append($li);
    }));
    if (!$items.children().length) {
        return null;
    }
    const total = $items.children().length;
    return $('<section>').addClass('oer-ae-unfitted')
        .append($('<h5>').addClass('oer-ae-unfitted-title').text(t('Sin encajar') + ' · ' + total))
        .append($('<p>').addClass('oer-ae-unfitted-hint')
            .text(t('No cuelgan de un curso o una materia elegidos. Quítalos o añade lo que les falta.')))
        .append($items);
}

function buildNode($panel, term, subjectId, node, aiById) {
    const $li = $('<li>').addClass('oer-tree-node').attr('data-id', node.id);
    const childrenId = domId($panel, [term, subjectId, node.id, 'children']);
    const hasChildren = node.children.length > 0;
    if (!node.selectable) {
        $li.addClass('oer-tree-block-node oer-ae-block').append($('<button>').attr({
            type: 'button', 'aria-expanded': node.expanded ? 'true' : 'false', 'aria-controls': childrenId
        }).addClass('oer-tree-toggle oer-tree-block oer-ae-block-toggle')
            .append($('<span>').addClass('oer-ae-code').text(node.notation))
            .append($('<span>').addClass('oer-ae-block-title').text(node.title.replace(/^\S+\s·\s/, '')))
            .append($('<span>').addClass('oer-tree-count oer-ae-block-count')
                .text(node.selectedBelow ? node.selectedBelow + ' ' + t('elegidos') : '')));
    } else {
        const checkId = domId($panel, [term, subjectId, node.id]);
        const ai = aiById.get(node.id);
        const $row = $('<div>').addClass('oer-ae-leaf').toggleClass('is-checked', node.checked).toggleClass('is-ai', !!(ai && node.checked));
        if (hasChildren) {
            $row.append($('<button>').attr({
                type: 'button', 'aria-expanded': node.expanded ? 'true' : 'false', 'aria-controls': childrenId,
                'aria-label': t('Desplegar') + ' ' + (node.notation || node.title)
            }).addClass('oer-tree-toggle oer-tree-twisty'));
        }
        const $check = $('<input>').attr({ type: 'checkbox', id: checkId, value: node.id })
            .addClass('oer-tree-check')
            .attr({ 'data-title': node.title, 'data-term': term, 'data-subject': subjectId })
            .prop('checked', node.checked);
        const $label = $('<label>').attr('for', checkId)
            .append($('<span>').addClass('oer-ae-code').text(node.notation))
            .append($('<span>').addClass('oer-ae-leaf-text').text(node.definition || node.title));
        $row.append($check, $label);
        if (ai && node.checked) {
            $row.append(aiMark(ai.why, $panel, term + '-' + node.id));
        }
        $li.append($row);
    }
    if (hasChildren) {
        const $ul = $('<ul>').addClass('oer-tree-children').attr('id', childrenId).prop('hidden', !node.expanded);
        node.children.forEach((child) => $ul.append(buildNode($panel, term, subjectId, child, aiById)));
        $li.append($ul);
    }
    return $li;
}

function renderLeaves($panel, subject, term) {
    const state = editorState($panel);
    const view = ui($panel);
    const tree = view.trees[subject.id];
    const fkey = subject.id + '|' + term;
    const $box = $('<div>').addClass('oer-align-leaves').attr({ 'data-term': term, 'data-subject': subject.id });
    const response = { trees: [tree] };
    const [one] = treeViews(response, term, state.values[term].map((leaf) => leaf.id)).trees;
    if (!one || !one.nodes.length) {
        return $box.append($('<p>').addClass('oer-ae-muted').text(t('Sin términos vigentes en esta asignatura.')));
    }
    const inputId = domId($panel, [term, subject.id, 'filter']);
    const query = view.filters[fkey] || '';
    const result = filterTrees(response, term, query);
    $box.append($('<div>').addClass('oer-ae-filter')
        .append($('<label>').attr('for', inputId).text(t('Filtrar')))
        .append($('<input>').attr({ type: 'search', id: inputId, autocomplete: 'off', spellcheck: 'false', placeholder: t('Código o texto…') })
            .addClass('oer-align-filter').attr('data-fkey', fkey).val(query))
        .append($('<p>').addClass('oer-ae-muted oer-ae-filter-status').attr({ role: 'status', 'aria-live': 'polite' })
            .text(filterStatus(result, t))));
    const aiById = new Map(state.values[term].filter((leaf) => leaf.ai).map((leaf) => [leaf.id, leaf]));
    const $list = $('<ul>').addClass('oer-tree-root oer-ae-blocks').attr('aria-label', t(TERM_LABELS[term]) + ' · ' + subject.name);
    one.nodes.forEach((node) => $list.append(buildNode($panel, term, subject.id, node, aiById)));
    if (result.active) {
        $list.find('.oer-tree-node').each(function () {
            this.hidden = !result.visible.has(String($(this).attr('data-id')));
        });
        $list.find('.oer-tree-toggle').each(function () {
            const open = result.expanded.has(String($(this).closest('.oer-tree-node').attr('data-id')));
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $list.find('#' + $(this).attr('aria-controls')).prop('hidden', !open);
        });
    }
    return $box.append($list);
}

function tabLabel($panel, subject, term) {
    const tree = ui($panel).trees[subject.id];
    const total = tree ? selectableIds({ trees: [tree] }, term).size : 0;
    return t(TERM_LABELS[term]) + ' ' + subject[term].length + '/' + total;
}

function renderDetail($panel, layout) {
    const view = ui($panel);
    view.subject = focusSubject(layout, view.subject);
    const $detail = $panel.find('.oer-ae-detail').empty();
    const course = layout.courses.find((one) => one.subjects.some((subject) => subject.id === view.subject));
    const subject = course && course.subjects.find((one) => one.id === view.subject);
    if (!subject) {
        $detail.append($('<p>').addClass('oer-ae-empty')
            .text(layout.courses.length
                ? t('Añade una materia a un curso para elegir sus saberes y criterios.')
                : t('Añade un curso y una materia para elegir sus saberes y criterios.')));
        return;
    }
    $detail.append($('<div>').addClass('oer-ae-detail-head')
        .append($('<div>')
            .append($('<p>').addClass('oer-ae-muted oer-ae-detail-course').text(course.title))
            .append($('<h4>').addClass('oer-ae-detail-title').text(subject.name)))
        .append(button('button oer-ae-small oer-ae-danger-button oer-align-remove', t('Quitar materia'), {
            id: domId($panel, ['subject', subject.id, 'remove']), 'data-kind': 'subject', 'data-id': subject.id,
            'data-name': subject.name, 'aria-label': t('Quitar materia') + ' ' + subject.name
        })));
    const $tabs = $('<div>').addClass('oer-ae-tabs').attr({ role: 'tablist', 'aria-label': t('Términos de la materia') });
    const panelId = domId($panel, ['subject', subject.id, 'panel']);
    LEAF_TERMS.forEach((term) => {
        const on = term === view.tab;
        $tabs.append(button('oer-ae-tab', tabLabel($panel, subject, term), {
            role: 'tab', id: domId($panel, ['tab', term]), 'data-term': term,
            'aria-selected': on ? 'true' : 'false', 'aria-controls': panelId, tabindex: on ? '0' : '-1'
        }));
    });
    const $panelBody = $('<div>').addClass('oer-ae-tabpanel').attr({ role: 'tabpanel', id: panelId, 'aria-labelledby': domId($panel, ['tab', view.tab]) });
    if (!subject.loaded || undefined === view.trees[subject.id]) {
        $panelBody.append($('<p>').addClass('oer-ae-muted').text(t('Cargando…')));
    } else if (null === view.trees[subject.id]) {
        $panelBody.append($('<p>').addClass('oer-ae-muted').text(view.errors[subject.id]
            ? t('No se pudo cargar el árbol del currículo.')
            : t('La asignatura elegida no es una materia en curso del currículo.')));
    } else {
        $panelBody.append(renderLeaves($panel, subject, view.tab));
    }
    $detail.append($tabs, $panelBody);
}

function renderChrome($panel) {
    const state = editorState($panel);
    const { added, removed } = changeCounts(state);
    const total = added + removed;
    const $changes = $panel.find('.oer-ae-changes').empty();
    $changes.append($('<strong>').text(total
        ? (1 === total ? t('1 cambio sin confirmar') : total + ' ' + t('cambios sin confirmar'))
        : t('Sin cambios')));
    $changes.append($('<span>').addClass('oer-ae-delta oer-ae-added').text('+' + added));
    $changes.append($('<span>').addClass('oer-ae-delta oer-ae-removed').text('−' + removed));
    const ai = aiCount(state);
    $panel.find('.oer-ae-ai-count').prop('hidden', 0 === ai).empty()
        .append($('<span>').addClass('oer-ae-ai-badge').text(String(ai)))
        .append(document.createTextNode(' ' + t('propuestas de la IA')));
}

function renderEditor($panel) {
    const $editor = $panel.find('.oer-align-editor');
    const view = ui($panel);
    const focus = view.focus || (document.activeElement && document.activeElement.id);
    view.focus = null;
    const opened = {};
    $editor.find('.oer-tree-toggle[aria-controls]').each(function () {
        opened[$(this).attr('aria-controls')] = 'true' === $(this).attr('aria-expanded');
    });
    const typed = {};
    $editor.find('.oer-ae-courses .oer-term-searchbox-input').each(function () {
        if (this.id && this.value) {
            typed[this.id] = this.value;
        }
    });
    const scroll = $editor.find('.oer-ae-tabpanel').scrollTop() || 0;
    const layout = placement(editorState($panel));
    renderNav($panel, layout);
    renderDetail($panel, layout);
    renderChrome($panel);
    $editor.find('.oer-tree-toggle[aria-controls]').each(function () {
        const target = $(this).attr('aria-controls');
        if (Object.prototype.hasOwnProperty.call(opened, target)) {
            toggle($(this), opened[target]);
        }
    });
    Object.entries(typed).forEach(([id, value]) => {
        const input = document.getElementById(id);
        if (input) {
            input.value = value;
        }
    });
    $editor.find('.oer-ae-tabpanel').scrollTop(scroll);
    if (focus) {
        const element = document.getElementById(focus);
        if (element) {
            element.focus();
        }
    }
}

/** A checkbox refreshes counts only: the list keeps its scroll and focus. */
function refreshCounts($panel) {
    const layout = placement(editorState($panel));
    const view = ui($panel);
    const focus = document.activeElement && document.activeElement.id;
    renderNav($panel, layout);
    renderChrome($panel);
    const subject = layout.courses.flatMap((course) => course.subjects).find((one) => one.id === view.subject);
    if (subject) {
        LEAF_TERMS.forEach((term) => $panel.find(`.oer-ae-tab[data-term="${term}"]`).text(tabLabel($panel, subject, term)));
    }
    $panel.find('.oer-ae-detail .oer-tree-block-node').each(function () {
        const below = $(this).find('.oer-tree-children .oer-tree-check:checked').length;
        $(this).children('.oer-tree-block').find('.oer-tree-count').text(below ? below + ' ' + t('elegidos') : '');
    });
    $panel.find('.oer-ae-detail .oer-tree-check').each(function () {
        $(this).closest('.oer-ae-leaf').toggleClass('is-checked', this.checked);
    });
    if (focus) {
        const element = document.getElementById(focus);
        if (element) {
            element.focus();
        }
    }
}

function toggle($button, open) {
    $button.attr('aria-expanded', open ? 'true' : 'false');
    $('#' + $button.attr('aria-controls')).prop('hidden', !open);
}

export function initAlignmentEditor(config) {
    if (!config.curriculumTreeUrl) {
        return;
    }
    const panelOf = (element) => $(element).closest('.oer-recatalog');

    $(document).on('click', '.oer-align-editor .oer-tree-toggle', function () {
        toggle($(this), 'true' !== $(this).attr('aria-expanded'));
    });

    // ArrowRight opens a branch, ArrowLeft closes it (as in today's trees).
    $(document).on('keydown', '.oer-align-editor .oer-tree-toggle', function (event) {
        if ('ArrowRight' !== event.key && 'ArrowLeft' !== event.key) {
            return;
        }
        event.preventDefault();
        if (('ArrowRight' === event.key) !== ('true' === $(this).attr('aria-expanded'))) {
            $(this).trigger('click');
        }
    });

    $(document).on('click', '.oer-align-editor .oer-ae-subject', function () {
        const $panel = panelOf(this);
        ui($panel).subject = String($(this).attr('data-id'));
        ui($panel).focus = this.id;
        renderEditor($panel);
    });

    $(document).on('click', '.oer-align-editor .oer-ae-tab', function () {
        const $panel = panelOf(this);
        ui($panel).tab = $(this).attr('data-term');
        ui($panel).focus = this.id;
        renderEditor($panel);
    });

    $(document).on('keydown', '.oer-align-editor .oer-ae-tab', function (event) {
        if ('ArrowRight' !== event.key && 'ArrowLeft' !== event.key) {
            return;
        }
        event.preventDefault();
        const $tabs = $(this).closest('[role="tablist"]').find('.oer-ae-tab');
        const next = $tabs.eq(($tabs.index(this) + ('ArrowRight' === event.key ? 1 : -1) + $tabs.length) % $tabs.length);
        next.trigger('click');
    });

    $(document).on('change', '.oer-align-editor .oer-tree-check', function () {
        const $check = $(this);
        const $panel = panelOf(this);
        setState($panel, setLeaf(editorState($panel), $check.attr('data-term'), {
            id: $check.val(), title: $check.attr('data-title'), subjectId: $check.attr('data-subject')
        }, this.checked));
        refreshCounts($panel);
    });

    $(document).on('input', '.oer-align-editor .oer-align-filter', function () {
        const $input = $(this);
        const $panel = panelOf(this);
        ui($panel).filters[$input.attr('data-fkey')] = String($input.val() || '');
        const $box = $input.closest('.oer-align-leaves');
        const subject = placement(editorState($panel)).courses
            .flatMap((course) => course.subjects)
            .find((one) => one.id === String($box.attr('data-subject')));
        if (!subject) {
            return;
        }
        const id = $input.attr('id');
        const caret = this.selectionStart;
        $box.replaceWith(renderLeaves($panel, subject, String($box.attr('data-term'))));
        const input = document.getElementById(id);
        if (input) {
            input.focus();
            input.setSelectionRange(caret, caret);
        }
    });

    $(document).on('keydown', '.oer-align-editor .oer-align-filter', function (event) {
        if ('Enter' === event.key) {
            event.preventDefault();
        }
    });

    $(document).on('click', '.oer-align-editor .oer-align-add-course', function () {
        const $button = $(this);
        const $panel = panelOf(this);
        const course = { id: $button.attr('data-id'), title: $button.attr('data-title') };
        setState($panel, addCourse(editorState($panel), course));
        renderEditor($panel);
        announce($panel, t('Curso añadido:') + ' ' + course.title);
    });

    $(document).on('click', '.oer-align-editor .oer-align-remove', function () {
        const $button = $(this);
        const $panel = panelOf(this);
        const kind = $button.attr('data-kind');
        const id = String($button.attr('data-id'));
        const name = $button.attr('data-name');
        let next;
        let removed = 0;
        if ('course' === kind) {
            ({ state: next, removed } = removeCourse(editorState($panel), id));
        } else if ('subject' === kind) {
            ({ state: next, removed } = removeSubject(editorState($panel), id));
        } else {
            next = removeValue(editorState($panel), kind, id);
        }
        // A whole group asks first, with what goes with it (spec B4).
        if (removed > 0 && !window.confirm(
            t('¿Quitar') + ' ' + name + ' ' + t('y sus') + ' ' + removed + ' ' + t('términos?')
        )) {
            return;
        }
        setState($panel, next);
        ui($panel).focus = $panel.find('.oer-align-course-search input').attr('id');
        renderEditor($panel);
        announce($panel, t('Quitado:') + ' ' + name + (removed ? ' (+' + removed + ')' : ''));
    });

    $(document).on('click', '.oer-align-editor .oer-ae-back', function () {
        showEdit(panelOf(this));
    });
}
```

- [ ] **Step 2: Verify the syntax**

Run: `node --check asset/js/ui/alignmentEditor.js && make test-js`
Expected: no syntax error, and the suite passes. The behaviour is checked in Task 5.

- [ ] **Step 3: Commit**

```bash
git add asset/js/ui/alignmentEditor.js
git commit -m "feat: the alignment editor becomes master-detail with a review step (TASK-065 B8)

Model: Claude Opus 5.5"
```

---

### Task 4: Wire it in; the editor's look; strings

**Files:**
- Modify: `asset/js/ui/recatalog.js`, `asset/css/oer-alignment-editor.css`, `asset/css/oer-master-view.css`, `config/module.config.php`

**Interfaces:**
- Consumes: `showReview` (Task 3); the tokens and families (Task 2).
- Produces: unchanged `collectAlignmentPairs` and `refusalText`.

- [ ] **Step 1: `recatalog.js`**

Change the `./alignmentEditor.js` import to:

```js
import { buildAlignmentEditor, openAlignmentEditor, editorPairs, editorState, showReview } from './alignmentEditor.js';
```

Remove `buildDimensionSelector` (keep `disableApply`) and the `valuesFor` import; the editor builds the axis now.

Replace `buildRecatalogPanel` with:

```js
function buildRecatalogPanel(config, itemId, recatalog) {
    const $panel = $('<div>').addClass('oer-recatalog').attr('data-item-id', itemId);
    // TASK-065 B8: header, navigator, detail, footer and review step are the
    // editor's (ui/alignmentEditor.js); this file keeps preview/apply/cancel.
    $panel.append(buildAlignmentEditor(config, $panel, recatalog));
    return $panel;
}
```

In `renderDiff`, change `const $diff = $panel.find('.oer-recatalog-diff').empty();` to
`const $diff = $panel.find('.oer-ae-review-body').empty();`.

In the `.oer-recatalog-preview` handler, replace the body of `.done((response) => { ... })` with:

```js
            if (response.error) {
                $panel.find('.oer-recatalog-diff').text(refusalText(response, ''));
                disableApply($panel);
                return;
            }
            const ok = renderDiff($panel, response.diff || {});
            $panel.find('.oer-recatalog-apply').prop('disabled', !ok);
            showReview($panel);
```

In the `.oer-recatalog-apply` handler, remove the
`if (!window.confirm(Omeka.jsTranslate('¿Confirmar la re-catalogación de este REA?'))) { return; }` guard.
The review step is now the confirmation (spec B8).

- [ ] **Step 2: Move the look into `oer-alignment-editor.css`**

In `asset/css/oer-master-view.css`:
- Delete the whole block that starts with the comment line `TASK-065 part B: the re-cataloguer as a tree (spec B1–B7)` (the `/* ----…` banner above it included) and runs to the end of the file.
- Then append:

```css
/* TASK-065 B8: the sidebar widens while editing for the master-detail editor
   (ADR-0024); any re-render brings it back. The editor's look lives in
   oer-alignment-editor.css. */
#oer-detail-sidebar.oer-editing {
    width: clamp(36rem, 76vw, 82rem);
}
```

Keep the read-mode hiding rule as it is: `recatalogReadMode.test.js` pins it.

Append to `asset/css/oer-alignment-editor.css`:

```css
/* ---- Frame ---------------------------------------------------------- */

.oer-align-editor {
    container-type: inline-size;
    display: flex;
    flex-direction: column;
    margin: 0.5em 0 0;
    background: var(--ae-ground);
    color: var(--ae-ink);
    font-family: var(--ae-sans);
    font-size: 14px;
    line-height: 1.45;
    border: 1px solid var(--ae-rule);
    border-radius: 10px;
    overflow: hidden;
}

.oer-align-editor [hidden] {
    display: none !important;
}

.oer-align-editor h4,
.oer-align-editor h5 {
    font-family: var(--ae-sans);
    text-transform: none;
    letter-spacing: normal;
    color: var(--ae-ink);
}

.oer-align-editor .oer-ae-muted {
    margin: 0;
    color: var(--ae-muted);
    font-size: 13px;
}

.oer-align-editor .oer-ae-code,
.oer-align-editor .oer-ae-counts,
.oer-align-editor .oer-ae-delta,
.oer-align-editor .oer-ae-summary dd {
    font-family: var(--ae-mono);
    font-weight: 500;
    font-size: 12px;
}

.oer-align-editor .oer-ae-label {
    margin: 0 0 0.5em;
    font-size: 12px;
    font-weight: 700;
    color: var(--ae-muted);
}

/* ---- Controls: native, restyled --------------------------------------- */

.oer-align-editor input[type="search"],
.oer-align-editor input[type="text"] {
    box-sizing: border-box;
    width: 100%;
    min-height: 40px;
    padding: 0 12px;
    border: 1px solid var(--ae-control);
    border-radius: 8px;
    background: var(--ae-surface);
    color: var(--ae-ink);
    font: 14px var(--ae-sans);
}

.oer-align-editor button,
.oer-align-editor .button {
    margin: 0;
    font-family: var(--ae-sans);
}

.oer-align-editor .button {
    min-height: 40px;
    padding: 0 16px;
    border: 0;
    border-radius: 8px;
    background: var(--ae-surface);
    box-shadow: 0 0 0 1px var(--ae-control) inset;
    color: var(--ae-ink);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
}

.oer-align-editor .button.oer-ae-primary {
    background: var(--ae-accent);
    box-shadow: none;
    color: var(--ae-on-accent);
    font-weight: 700;
}

.oer-align-editor .button.oer-ae-primary:disabled {
    background: var(--ae-rule);
    color: var(--ae-muted);
    cursor: not-allowed;
}

.oer-align-editor .button.oer-ae-ai {
    box-shadow: 0 0 0 1.5px var(--ae-ai) inset;
    color: var(--ae-ai);
}

.oer-align-editor .button.oer-ae-small {
    min-height: 32px;
    padding: 0 10px;
    font-size: 12px;
}

.oer-align-editor .button.oer-ae-danger-button {
    color: var(--ae-danger);
}

.oer-align-editor .oer-ae-link {
    min-height: 32px;
    padding: 0 4px;
    border: 0;
    background: transparent;
    box-shadow: none;
    color: var(--ae-accent-ink);
    font-weight: 600;
    font-size: 13px;
    cursor: pointer;
}

.oer-align-editor .oer-ae-danger-link {
    color: var(--ae-danger);
}

.oer-align-editor :focus-visible {
    outline: 2px solid var(--ae-accent);
    outline-offset: 2px;
}

.oer-align-editor input[type="checkbox"] {
    width: 18px;
    height: 18px;
    margin: 2px 0 0;
    accent-color: var(--ae-accent);
    flex: 0 0 auto;
}

/* ---- Header ---------------------------------------------------------- */

.oer-ae-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px;
    padding: 14px 20px;
    background: var(--ae-surface);
    border-bottom: 1px solid var(--ae-rule);
}

.oer-align-editor .oer-ae-title {
    flex: 1 1 auto;
    margin: 0;
    font-size: 18px;
    font-weight: 700;
}

.oer-ae-ai-count {
    margin: 0;
    color: var(--ae-ai);
    font-size: 13px;
    font-weight: 600;
}

.oer-ae-ai-badge {
    display: inline-block;
    min-width: 22px;
    padding: 1px 7px;
    box-sizing: border-box;
    border-radius: 11px;
    background: var(--ae-ai-soft);
    text-align: center;
}

/* ---- Body: navigator + detail ----------------------------------------- */

.oer-ae-body {
    display: grid;
    grid-template-columns: 300px minmax(0, 1fr);
    height: min(68vh, 760px);
}

.oer-ae-nav {
    display: flex;
    flex-direction: column;
    gap: 18px;
    padding: 18px 16px;
    overflow-y: auto;
    border-right: 1px solid var(--ae-rule);
}

.oer-ae-etapa > summary {
    margin-top: 6px;
    color: var(--ae-muted);
    font-size: 13px;
    cursor: pointer;
}

.oer-ae-course {
    display: flex;
    flex-direction: column;
    gap: 6px;
}

.oer-ae-course-head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 8px;
}

.oer-align-editor .oer-ae-course-title {
    flex: 1 1 auto;
    margin: 0;
    font-size: 15px;
    font-weight: 700;
}

.oer-ae-subjects {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.oer-align-editor .oer-ae-subject {
    display: flex;
    align-items: center;
    gap: 8px;
    width: 100%;
    min-height: 44px;
    padding: 8px 10px;
    box-sizing: border-box;
    border: 1px solid var(--ae-rule);
    border-radius: 8px;
    background: var(--ae-surface);
    color: var(--ae-ink);
    font-size: 14px;
    text-align: left;
    cursor: pointer;
}

.oer-align-editor .oer-ae-subject[aria-current="true"] {
    border: 1.5px solid var(--ae-accent);
    background: var(--ae-accent-soft);
}

.oer-ae-subject-name {
    flex: 1 1 auto;
    font-weight: 600;
}

.oer-ae-counts {
    color: var(--ae-muted);
}

.oer-ae-ai-mark {
    display: inline-block;
    padding: 1px 7px;
    border-radius: 10px;
    background: var(--ae-ai-soft);
    color: var(--ae-ai);
    font-size: 11px;
    font-weight: 700;
}

.oer-ae-why {
    flex: 0 0 auto;
    max-width: 18em;
    font-size: 13px;
}

.oer-ae-why > summary {
    display: inline-flex;
    gap: 6px;
    align-items: center;
    color: var(--ae-ai);
    font-weight: 600;
    cursor: pointer;
}

.oer-ae-why > p {
    margin: 6px 0 0;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--ae-ai-soft);
    color: var(--ae-ai);
}

.oer-ae-unfitted {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 12px;
    border-radius: 10px;
    background: var(--ae-unfit-soft);
}

.oer-align-editor .oer-ae-unfitted-title {
    margin: 0;
    color: var(--ae-unfit);
    font-size: 13px;
    font-weight: 700;
}

.oer-align-editor .oer-ae-unfitted-title::before {
    content: "⚠ ";
}

.oer-ae-unfitted-hint {
    margin: 0;
    color: var(--ae-unfit);
    font-size: 12px;
}

.oer-ae-unfitted-list,
.oer-ae-unfitted-leaves {
    display: flex;
    flex-direction: column;
    gap: 6px;
    margin: 0;
    padding: 0;
    list-style: none;
}

.oer-ae-unfitted-list > li {
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding: 8px 10px;
    border-radius: 8px;
    background: var(--ae-surface);
    font-size: 13px;
}

.oer-ae-unfitted-leaves {
    padding-left: 10px;
    border-left: 2px solid var(--ae-rule);
}

.oer-ae-unfitted-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.oer-ae-detail {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    background: var(--ae-surface);
}

.oer-ae-empty {
    margin: 32px 24px;
    color: var(--ae-muted);
}

.oer-ae-detail-head {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 16px 22px 0;
}

.oer-ae-detail-head > div {
    flex: 1 1 auto;
}

.oer-align-editor .oer-ae-detail-title {
    margin: 0;
    font-size: 20px;
    font-weight: 700;
}

.oer-ae-tabs {
    display: flex;
    gap: 4px;
    padding: 12px 22px 0;
    border-bottom: 1px solid var(--ae-rule);
}

.oer-align-editor .oer-ae-tab {
    min-height: 42px;
    padding: 0 14px;
    border: 0;
    border-bottom: 3px solid transparent;
    background: transparent;
    color: var(--ae-muted);
    font-weight: 600;
    font-size: 14px;
    cursor: pointer;
}

.oer-align-editor .oer-ae-tab[aria-selected="true"] {
    border-bottom-color: var(--ae-accent);
    color: var(--ae-ink);
    font-weight: 700;
}

.oer-ae-tabpanel {
    flex: 1 1 auto;
    min-height: 0;
    overflow-y: auto;
    padding: 12px 22px 20px;
}

.oer-ae-filter {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px 12px;
    margin-bottom: 12px;
}

.oer-ae-filter > label {
    color: var(--ae-muted);
    font-size: 13px;
    font-weight: 600;
}

.oer-ae-filter > input {
    flex: 1 1 14em;
    width: auto;
}

.oer-ae-blocks,
.oer-ae-blocks .oer-tree-children {
    margin: 0;
    padding: 0;
    list-style: none;
}

.oer-ae-blocks > .oer-ae-block {
    margin-bottom: 14px;
}

.oer-align-editor .oer-ae-block-toggle {
    display: flex;
    align-items: baseline;
    gap: 10px;
    width: 100%;
    min-height: 32px;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--ae-ink);
    font-weight: 700;
    font-size: 13px;
    text-align: left;
    cursor: pointer;
}

.oer-ae-block-toggle::before {
    content: "▸";
    color: var(--ae-muted);
}

.oer-ae-block-toggle[aria-expanded="true"]::before {
    content: "▾";
}

.oer-ae-block-title {
    flex: 1 1 auto;
}

.oer-ae-block-count {
    color: var(--ae-accent-ink);
    font-size: 12px;
}

.oer-ae-leaf {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 10px 12px;
    border-radius: 8px;
}

.oer-ae-leaf.is-checked {
    background: var(--ae-accent-soft);
}

.oer-ae-leaf.is-ai {
    box-shadow: inset 0 0 0 1px var(--ae-ai-line);
}

.oer-ae-leaf > label {
    flex: 1 1 auto;
    display: flex;
    flex-direction: column;
    gap: 2px;
    cursor: pointer;
}

.oer-ae-leaf .oer-ae-code {
    color: var(--ae-muted);
}

.oer-ae-blocks .oer-tree-children .oer-tree-children {
    margin-left: 22px;
}

/* ---- Footer ---------------------------------------------------------- */

.oer-ae-footer {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 12px 16px;
    padding: 12px 20px;
    background: var(--ae-surface);
    border-top: 1px solid var(--ae-rule);
}

.oer-ae-changes {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 0;
    flex: 1 1 auto;
}

.oer-ae-delta {
    padding: 2px 8px;
    border-radius: 6px;
}

.oer-ae-added {
    background: var(--ae-added-soft);
    color: var(--ae-added);
}

.oer-ae-removed {
    background: var(--ae-removed-soft);
    color: var(--ae-removed);
}

.oer-ae-footer-status {
    flex: 1 1 100%;
    order: 3;
    margin: 0;
    color: var(--ae-muted);
    font-size: 13px;
}

.oer-ae-footer-status:empty {
    display: none;
}

.oer-ae-footer-actions {
    display: flex;
    gap: 8px;
}

/* ---- Review step ------------------------------------------------------ */

.oer-ae-review {
    display: flex;
    flex-direction: column;
}

.oer-ae-review-head {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 14px 20px;
    background: var(--ae-surface);
    border-bottom: 1px solid var(--ae-rule);
}

.oer-ae-review-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 280px;
    gap: 20px;
    align-items: start;
    padding: 20px;
    max-height: min(70vh, 780px);
    overflow-y: auto;
}

.oer-ae-review-body .oer-diff-section {
    margin: 0 0 14px;
    padding: 16px 18px;
    border: 1px solid var(--ae-rule);
    border-radius: 12px;
    background: var(--ae-surface);
}

.oer-ae-review-body .oer-diff-heading {
    margin: 0 0 8px;
    font-size: 16px;
    font-weight: 700;
}

.oer-ae-review-body .oer-diff-row {
    margin: 0 0 6px;
    padding: 8px 12px;
    border-radius: 8px;
}

.oer-ae-review-body .oer-diff-added {
    background: var(--ae-added-soft);
    color: var(--ae-added);
}

.oer-ae-review-body .oer-diff-removed {
    background: var(--ae-removed-soft);
    color: var(--ae-removed);
}

.oer-ae-review-body .oer-diff-invalid {
    background: var(--ae-unfit-soft);
    color: var(--ae-unfit);
}

.oer-ae-review-aside {
    position: sticky;
    top: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
    padding: 18px;
    border: 1px solid var(--ae-rule);
    border-radius: 12px;
    background: var(--ae-surface);
}

.oer-ae-summary {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 8px 12px;
    margin: 0;
}

.oer-ae-summary dt {
    color: var(--ae-muted);
}

.oer-ae-summary dd {
    margin: 0;
    padding: 0 6px;
    border-radius: 6px;
}

.oer-ae-ai-text {
    color: var(--ae-ai);
}

.oer-ae-note {
    margin: 0;
    padding: 10px 12px;
    border-radius: 8px;
    background: var(--ae-ground);
    color: var(--ae-muted);
    font-size: 13px;
}

/* ---- Search boxes (ui/termSearch.js) inside the editor ----------------- */

.oer-align-editor .oer-term-searchbox {
    position: relative;
}

.oer-align-editor .oer-term-searchbox > label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.oer-align-editor .oer-ae-subject-search > label {
    position: static;
    width: auto;
    height: auto;
    overflow: visible;
    clip: auto;
    display: block;
    margin-bottom: 4px;
    color: var(--ae-accent-ink);
    font-size: 13px;
    font-weight: 600;
}

.oer-align-editor .oer-term-searchbox-results {
    position: absolute;
    z-index: 1100;
    left: 0;
    right: 0;
    max-height: 18em;
    overflow-y: auto;
    margin: 4px 0 0;
    padding: 4px;
    list-style: none;
    border-radius: 8px;
    background: var(--ae-surface);
    box-shadow: 0 0 0 1px var(--ae-control), 0 8px 20px rgba(24, 33, 47, 0.14);
}

.oer-align-editor .oer-term-searchbox-result {
    display: block;
    width: 100%;
    padding: 8px 10px;
    border: 0;
    border-radius: 6px;
    background: transparent;
    color: var(--ae-ink);
    text-align: left;
    cursor: pointer;
}

.oer-align-editor .oer-term-searchbox-result:hover,
.oer-align-editor .oer-term-searchbox-result:focus {
    background: var(--ae-accent-soft);
}

.oer-align-editor .oer-term-lineage {
    display: block;
    color: var(--ae-muted);
    font-size: 12px;
}

/* ---- Narrow: the navigator becomes a strip above the detail ------------ */

@container (max-width: 720px) {
    .oer-ae-body {
        grid-template-columns: minmax(0, 1fr);
        height: auto;
    }

    .oer-ae-nav {
        border-right: 0;
        border-bottom: 1px solid var(--ae-rule);
    }

    .oer-ae-subjects {
        flex-direction: row;
        overflow-x: auto;
    }

    .oer-align-editor .oer-ae-subject {
        width: auto;
        flex: 0 0 auto;
        flex-direction: column;
        align-items: flex-start;
    }

    .oer-ae-tabpanel {
        max-height: 60vh;
    }

    .oer-ae-footer-actions {
        flex: 1 1 100%;
    }

    .oer-ae-footer-actions .button {
        flex: 1 1 0;
    }

    .oer-ae-review-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
```

- [ ] **Step 3: Strings**

In `config/module.config.php` `js_translate_strings`, before `// TASK-035`, add those not already present:

```php
        // TASK-065 B8: master-detail editor.
        'Anclaje curricular', // @translate
        'Estructura del anclaje', // @translate
        'Acotar por etapa', // @translate
        'propuestas de la IA', // @translate
        'Añadir', // @translate
        'Añade una materia a un curso para elegir sus saberes y criterios.', // @translate
        'Añade un curso y una materia para elegir sus saberes y criterios.', // @translate
        'Términos de la materia', // @translate
        'Filtrar', // @translate
        'Código o texto…', // @translate
        'elegidos', // @translate
        '1 cambio sin confirmar', // @translate
        'cambios sin confirmar', // @translate
        'Sin cambios', // @translate
        'Revisar cambios', // @translate
        'Revisar los cambios', // @translate
        'Nada se escribe en el REA hasta que confirmes.', // @translate
        'Seguir editando', // @translate
        'Resumen', // @translate
        'Se añaden', // @translate
        'Se quitan', // @translate
        'Propuestos por la IA', // @translate
        'Se guarda quién y cuándo, y podrás deshacerlo desde el historial.', // @translate
        'Confirmar re-catalogación', // @translate
        'Detener la propuesta', // @translate
        'Proponer con IA', // @translate
```

- [ ] **Step 4: Verify**

Run: `node --check asset/js/ui/recatalog.js && make lint && make test && make test-js`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add asset config
git commit -m "feat: the alignment editor's own look and review step wired into the re-cataloguer (TASK-065 B8, ADR-0024)

Model: Claude Opus 5.5"
```

---

### Task 5: Browser check of the editor; docs

**Files:**
- Throwaway (not committed): `test/tmp-b8.html`
- Modify: spec (B8 status, measured contrasts), backlog (TASK-065), `docs/project-memory.md`, `docs/traceability.md` (ADR-0024)

- [ ] **Step 1: Headless check** — create `test/tmp-b8.html`:

```html
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="/application/asset/css/style.css">
<link rel="stylesheet" href="/modules/OERManager/asset/css/oer-master-view.css">
<link rel="stylesheet" href="/modules/OERManager/asset/css/oer-alignment-editor.css">
<script src="/application/asset/vendor/jquery/jquery.min.js"></script>
<script>
window.Omeka = { jsTranslate: (s) => s };
window.TREE = { trees: [
  { subject: { id: 10, title: 'Matemáticas · 1º ESO' }, course: { id: 1, title: '1º ESO' },
    'lrmi:teaches': [{ id: 99, title: 'SMAT01SB-A · Sentido numérico', notation: 'SMAT01SB-A', selectable: false, children: [
      { id: 100, title: 'Conteo', notation: 'SMAT01SB-A.1', definition: 'Conteo: estrategias…', selectable: true, children: [] },
      { id: 101, title: 'Cantidad', notation: 'SMAT01SB-A.2', definition: 'Cantidad: números…', selectable: true, children: [] }] }],
    'lrmi:assesses': [{ id: 98, title: 'SMAT01C1', notation: 'SMAT01C1', selectable: false, children: [{ id: 200, title: 'CE1.1', notation: 'SMAT01CE1.1', selectable: true, children: [] }] }] },
  { subject: { id: 20, title: 'Lengua · 3º ESO' }, course: { id: 3, title: '3º ESO' },
    'lrmi:teaches': [{ id: 209, title: 'Bloque L', notation: 'SLCL03SB-C', selectable: false, children: [{ id: 210, title: 'Oración', notation: 'SLCL03SB-C.1', selectable: true, children: [] }] }],
    'lrmi:assesses': [] }
], invalid: [], retired: [] };
$.getJSON = (url) => $.Deferred().resolve(url === '/tree' ? window.TREE : { results: [] }).promise();
</script></head><body><div id="host" style="width:1100px"></div><pre id="result"></pre>
<script type="module">
import { buildAlignmentEditor, openAlignmentEditor, editorPairs, initAlignmentEditor, showReview } from '/modules/OERManager/asset/js/ui/alignmentEditor.js';
const config = { curriculumTreeUrl: '/tree', searchTermsUrl: '/search', aiEnabled: true };
initAlignmentEditor(config);
const recatalog = { allowed: true, current: {
  'lrmi:educationalLevel': [{ id: 1, title: '1º ESO' }],
  'schema:about': [{ id: 10, title: 'Matemáticas · 1º ESO' }, { id: 20, title: 'Lengua · 3º ESO' }],
  'lrmi:teaches': [{ id: 100, title: 'Conteo' }, { id: 210, title: 'Oración' }],
  'lrmi:assesses': [{ id: 200, title: 'CE1.1' }] } };
const $panel = $('<div class="oer-recatalog" data-item-id="7">');
$('#host').append($panel);
$panel.append(buildAlignmentEditor(config, $panel, recatalog));
openAlignmentEditor(config, $panel);
const out = [];
out.push('nav subjects=' + $panel.find('.oer-ae-subject').length + ' current=' + $panel.find('.oer-ae-subject[aria-current="true"] .oer-ae-subject-name').text());
out.push('detail=' + $panel.find('.oer-ae-detail-title').text() + ' tab=' + $panel.find('.oer-ae-tab[aria-selected="true"]').text());
out.push('I1 unfitted lists Oración=' + $panel.find('.oer-ae-unfitted').text().includes('Oración'));
$panel.find('.oer-tree-check[value="101"]').prop('checked', true).trigger('change');
out.push('footer=' + $panel.find('.oer-ae-changes').text() + ' navcounts=' + $panel.find('.oer-ae-subject .oer-ae-counts').text());
$panel.find('.oer-ae-tab[data-term="lrmi:assesses"]').trigger('click');
out.push('tab after click=' + $panel.find('.oer-ae-tab[aria-selected="true"]').text());
$panel.find('.oer-recatalog-apply').prop('disabled', false);
showReview($panel);
out.push('review shown=' + !$panel.find('.oer-ae-review').prop('hidden') + ' body hidden=' + $panel.find('.oer-ae-body').prop('hidden') + ' summary=' + $panel.find('.oer-ae-summary').text());
out.push('focus on heading=' + (document.activeElement && document.activeElement.textContent));
$panel.find('.oer-ae-back').first().trigger('click');
out.push('back: body shown=' + !$panel.find('.oer-ae-body').prop('hidden') + ' tab kept=' + $panel.find('.oer-ae-tab[aria-selected="true"]').attr('data-term') + ' focus=' + (document.activeElement && document.activeElement.textContent));
$panel.find('.oer-ae-tab[data-term="lrmi:teaches"]').trigger('click');
$panel.find('.oer-tree-check[value="101"]').prop('checked', false).trigger('change');
out.push('RF1 change after review disables Confirmar=' + $panel.find('.oer-recatalog-apply').prop('disabled'));
out.push('pairs=' + JSON.stringify(editorPairs($panel)));
out.push('font=' + getComputedStyle($panel.find('.oer-align-editor')[0]).fontFamily);
document.getElementById('result').textContent = out.join('\n');
</script></body></html>
```

Run it with a fresh headless profile (in a git-ignored folder):

```bash
P=$(mktemp -d .superpowers/chrome-XXXX); "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu \
  --user-data-dir=$P --virtual-time-budget=5000 --dump-dom \
  http://localhost:8080/modules/OERManager/test/tmp-b8.html | sed -n '/<pre id="result">/,/<\/pre>/p'
```

Expected:
- `nav subjects=1 current=Matemáticas`;
- `detail=Matemáticas tab=Saberes básicos 1/2`;
- `I1 unfitted lists Oración=true`;
- `footer=1 cambio sin confirmar+1−0` and `navcounts=S 2 · C 1`;
- `tab after click=Criterios de evaluación 1/1`;
- `review shown=true body hidden=true` with a summary `Se añaden+1Se quitan−0Propuestos por la IA0`;
- `focus on heading=Revisar los cambios`;
- `back: body shown=true tab kept=lrmi:assesses focus=Revisar cambios`;
- `RF1 change after review disables Confirmar=true`;
- `pairs=[]`;
- `font` starts with `"OER Public Sans"`.

Also take one screenshot for the owner: the same command with `--screenshot=.superpowers/b8.png --window-size=1200,1000` instead of `--dump-dom`. Then `rm test/tmp-b8.html`.

- [ ] **Step 2: Docs**

- **Spec B8:** «Status (date): implemented», plus the contrast table that `editorContrast.test.js` measures. Print the ratios with a node one-liner over `contrastRatio`/`parseTokens` and record them.
- **Backlog TASK-065:** B8 implemented with ADR-0024 accepted; pending the owner's review in the admin after a hard reload.
- **`docs/traceability.md`:** a note for ADR-0024 next to ADR-0014.
- **`docs/project-memory.md`:** one entry. Fonts are bundled because of GDPR; the contrast test caught the control border; the master-detail layout keeps the part-B model.

- [ ] **Step 3: Final checks and commit**

Run: `make lint && make test && make test-js`
Expected: all pass.

```bash
git add docs
git commit -m "docs: TASK-065 B8 implemented (ADR-0024); pending the owner's review

Model: Claude Opus 5.5"
```
