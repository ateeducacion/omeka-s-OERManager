# TASK-065 part B: the re-cataloguer as a tree — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the five flat chip selectors of the re-cataloguer with a tree editor. The tree runs Curso → Materia → Saberes/Criterios. Each course and subject is a collapsible group with a summary. Values that do not fit go to «Sin encajar». The AI proposal fills the same tree, and the footer is sticky with a diff grouped by course and subject. The flat RDF and the apply contract do not change.

**Architecture:**
- **The model is pure and does all the deciding.** It lives in `asset/js/core/alignmentTree.js`. It holds the selection as the four flat curricular lists plus three lookup maps: subject → course, leaf → subject, course → title. It decides placement, «Sin encajar», summaries, operations, serialization to the existing `alignment[term][]` pairs, the AI merge and the grouped diff.
- **The UI only renders it.** `ui/alignmentEditor.js` renders the model and keeps which groups are open. `ui/termSearch.js` is a search box over `search-terms` that hands the chosen result to the editor.
- **Server: one additive field.** `curriculum-tree` now returns each subject's `course`.
- **What stays the same:**
  - `recatalog.js` keeps preview, apply, undo and «Confirmar y publicar» (`collectAlignmentPairs`).
  - Etapa (a search helper) and Eje temático keep their chip selectors.
- **What is retired:** `leafTree.js`. Its model (`leafTreeModel.js`) is reused.

**Tech Stack:** PHP 8.4 / Omeka S 4.2 (PHPUnit), ES modules with jQuery (Omeka admin), `node --test`, headless Chrome for one throwaway DOM check.

**Spec:** `docs/superpowers/specs/2026-11-18-task-065-panel-usability-design.md` (Part B, B1–B7)

## Global Constraints

- **The RDF does not change shape.** An REA keeps five flat lists: `lrmi:educationalLevel` (`cur:Curso`), `schema:about` (`cur:MateriaCurso`), `lrmi:teaches`, `lrmi:assesses` and `dcterms:relation`. The `recatalog-apply` contract, audit events, undo and AI justifications stay as they are.
- **Only changed terms are sent.** A term is sent only when its set of ids differs from what the REA had; an emptied term is sent as `alignment[term][]=''`. Confirming without changes writes nothing.
- **Look and feel:** within ADR-0014, using only native admin buttons and inputs and the existing `--oer-*` tokens. The panel stays in the sidebar (ADR-0017); editing may widen it.
- **Accessibility (NFR-006):**
  - Disclosure buttons with `aria-expanded` and `aria-controls`.
  - Labelled native checkboxes.
  - Counts and actions announced in a polite live region.
  - Keyboard as in today's trees: Tab, Space, and ArrowRight/ArrowLeft on a branch.
- **Curriculum items are read-only inputs.** Etapa is never written to the REA.
- **`curriculum-tree` caps:** at most 10 subjects per request (`MAX_TREE_SUBJECTS`) and at most 500 linked ids (`MAX_TREE_LINKED`).
- **Copy:** Spanish UI strings, registered in `js_translate_strings`. English code comments and docs.
- **Commits:** a plain-text `Model: Claude Opus 5.5` line in the body, and no Co-Authored-By trailer (owner's rule).

## Review Focus

1. **A subject whose course is not among the REA's courses** must show in «Sin encajar». Confirming without touching it must send nothing for any term. Pinned in Task 2 (placement) and Task 4 (serialization).
2. **Removing a subject removes only the knowledge and criteria of THAT subject.** Pinned in Task 3. This matters when the same subject name exists in two courses («Matemáticas · 1º ESO» and «Matemáticas · 2º ESO»).
3. **Removing and re-adding the same values is not a change.** Same set means nothing is sent: «No hay cambios que confirmar». Pinned in Task 4.
4. **An REA with more than 10 subjects** loads its trees in batches of 10, which is the server cap. Pinned in Task 2 (`treeBatches`).
5. **An AI proposal with a subject whose course is not chosen** must show in «Sin encajar» with its «IA» mark, and nothing is written until «Confirmar». Pinned in Task 4.

## File structure

| File | Responsibility |
| --- | --- |
| `src/Service/Curriculum/ApiCurriculumTerms.php` (modify) | `courseSubjectTree()` adds `course` {id, title} |
| `src/Service/Curriculum/CurriculumTerms.php` (modify) | Interface doc of that shape |
| `asset/js/core/alignmentTree.js` (create) | Pure model: state, placement, summaries, operations, pairs, AI merge, grouped diff, batches |
| `asset/js/ui/termSearch.js` (create) | Search box over `search-terms` with an `onPick` callback |
| `asset/js/ui/alignmentEditor.js` (create) | Renders the tree editor from the model; loads trees; toggles, checks, removals, filters |
| `asset/js/ui/recatalog.js` (modify) | Builds the panel with the editor; `collectAlignmentPairs` from the model; grouped diff; opens the editor |
| `asset/js/ui/aiPropose.js` (modify) | AI proposal into the model (curricular) and chips (axis) |
| `asset/js/ui/termPicker.js` (modify) | Result-click handler scoped to `.oer-recatalog-dim` |
| `asset/js/ui/drawerDetails.js` (modify) | Widens the sidebar while editing; focuses the course search |
| `asset/js/main.js` (modify) | `initAlignmentEditor` replaces `initLeafTree` |
| `asset/js/ui/leafTree.js` (delete) | Replaced by the editor (its model stays) |
| `asset/css/oer-master-view.css` (modify) | Editor layout, sticky footer, wide sidebar while editing |
| `config/module.config.php` (modify) | New client strings |
| `test/js/alignmentTree.test.js` (create) | Model tests |
| `test/Service/Curriculum/ApiCurriculumTermsTest.php` (modify) | `course` in the tree |

---

### Task 1: `curriculum-tree` names each subject's course

**Files:**
- Modify: `src/Service/Curriculum/ApiCurriculumTerms.php` (`courseSubjectTree`, around line 112)
- Modify: `src/Service/Curriculum/CurriculumTerms.php` (doc of `courseSubjectTree`)
- Test: `test/Service/Curriculum/ApiCurriculumTermsTest.php`

**Interfaces:**
- Produces: each `trees[]` entry of `curriculum-tree` has `course: {id:int, title:string}`. When the subject has no `cur:curso`, it is `{id: 0, title: ''}`.

- [ ] **Step 1: Write the failing test**

In `treeFixture()`, move the `$course = $this->curItem(502, …)` line ABOVE `$subject`, and give the subject its course:

```php
        $course = $this->curItem(502, 'cur:Curso', '1º ESO', [], [46259]);
        $subject = $this->curItem(500, 'cur:MateriaCurso', 'Matemáticas · 1º ESO', [
            'skos:prefLabel' => [$this->value('Matemáticas · 1º ESO')],
            'cur:curso' => [$this->value('', $course, 'resource:item')],
        ], [46259]);
```

Add to the test class:

```php
    /** TASK-065 part B: the tree editor places each subject under its course. */
    public function testCourseSubjectTreeNamesTheCourseOfTheSubject(): void
    {
        [$terms] = $this->treeFixture();
        $tree = $terms->courseSubjectTree(500);

        $this->assertSame(['id' => 502, 'title' => '1º ESO'], $tree['course']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter ApiCurriculumTermsTest`
Expected: FAIL — `Undefined array key "course"`.

- [ ] **Step 3: Implement**

In `ApiCurriculumTerms::courseSubjectTree()` replace the `$tree = [...]` line with:

```php
        $tree = [
            'subject' => ['id' => $courseSubjectId, 'title' => CurModel::label($subject)],
            // TASK-065 part B: the tree editor groups subjects under their course.
            'course' => CurModel::ref($subject, CurModel::EDGE_CURSO),
        ];
```

In `CurriculumTerms.php`, change the `@return` of `courseSubjectTree` to:

```php
     * @return array{subject:array{id:int,title:string}, course:array{id:int,title:string},
     *     'lrmi:teaches':list<array<string,mixed>>, 'lrmi:assesses':list<array<string,mixed>>}|null
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit -c test/phpunit.xml --filter 'ApiCurriculumTermsTest|IndexControllerTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Service/Curriculum test/Service/Curriculum
git commit -m "feat: curriculum-tree names each subject's course (TASK-065 part B)

Model: Claude Opus 5.5"
```

---

### Task 2: Model — state, placement, summaries, tree batches

**Files:**
- Create: `asset/js/core/alignmentTree.js`
- Test: `test/js/alignmentTree.test.js`

**Interfaces:**
- Consumes: `recatalog.current`, which is `{term: [{id, title}]}` (`core/anchorData.js`), and the `curriculum-tree` response from Task 1.
- Produces:
  - Constants: `COURSE`, `SUBJECT`, `TEACHES`, `ASSESSES`, `RELATION`, `CURRICULAR_TERMS`, `LEAF_TERMS`, `TERM_LABELS`.
  - `createState(current) → state`. The state is `{values: {term: [{id, title, why, ai}]}, initial: {term: [id]}, courseOf: {subjectId: courseId}, subjectOf: {leafId: subjectId}, courseTitles: {courseId: title}, loaded: {subjectId: true}}`. All ids are strings.
  - `learnTrees(state, response) → state`
  - `learnSubject(state, result) → state`
  - `subjectName(title, courseTitle) → string`
  - `placement(state) → {courses: [course & {subjects: [subjectView]}], unfitted: {subjects: [subjectView], 'lrmi:teaches': [...], 'lrmi:assesses': [...]}, pending: number}`. A `subjectView` is `{id, title, name, ai, why, loaded, 'lrmi:teaches': [...], 'lrmi:assesses': [...]}`.
  - `summaryText(kind, view, t) → string`
  - `treeBatches(ids, size = 10) → id[][]`

- [ ] **Step 1: Write the failing tests**

`test/js/alignmentTree.test.js`:

```js
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `node --test test/js/alignmentTree.test.js`
Expected: FAIL — `ERR_MODULE_NOT_FOUND` for `alignmentTree.js`.

- [ ] **Step 3: Implement**

`asset/js/core/alignmentTree.js`:

```js
/**
 * The curricular alignment of one REA as a tree (TASK-065 part B, spec B2).
 * Pure core: no DOM, no jQuery, so `node --test` covers it.
 *
 * The RDF does not change shape: an REA keeps flat lists of courses, course
 * subjects, knowledge and criteria. The tree is a VIEW over them. A subject
 * belongs to one course (its `cur:curso`) and a knowledge item or criterion to
 * one subject (the tree it was found in). Whatever does not fit is never
 * dropped: it goes to «Sin encajar» (spec B3).
 *
 * State: the selection as flat lists, plus what the editor has learned about
 * where each value hangs. Operations return a new state; nothing is mutated.
 */

export const COURSE = 'lrmi:educationalLevel';
export const SUBJECT = 'schema:about';
export const TEACHES = 'lrmi:teaches';
export const ASSESSES = 'lrmi:assesses';
export const RELATION = 'dcterms:relation';

export const CURRICULAR_TERMS = Object.freeze([COURSE, SUBJECT, TEACHES, ASSESSES]);
export const LEAF_TERMS = Object.freeze([TEACHES, ASSESSES]);

/** Labels of the terms, untranslated (the UI passes them through jsTranslate). */
export const TERM_LABELS = Object.freeze({
    [COURSE]: 'Curso',
    [SUBJECT]: 'Materia',
    [TEACHES]: 'Saberes básicos',
    [ASSESSES]: 'Criterios de evaluación',
    [RELATION]: 'Eje temático'
});

const ID = /^[1-9][0-9]*$/;

function key(id) {
    return String(id == null ? '' : id).trim();
}

function entry(value, extra = {}) {
    return {
        id: key(value.id),
        title: String(value.title || ''),
        why: String(extra.why || ''),
        ai: !!extra.ai
    };
}

function walk(nodes, visit) {
    (nodes || []).forEach((node) => {
        visit(node);
        walk(node.children, visit);
    });
}

/** @param {object} current `recatalog.current`: term → [{id, title}] */
export function createState(current) {
    const values = {};
    const initial = {};
    const courseTitles = {};
    CURRICULAR_TERMS.forEach((term) => {
        const list = [];
        ((current && current[term]) || []).forEach((value) => {
            const id = key(value && value.id);
            if (ID.test(id) && !list.some((one) => one.id === id)) {
                list.push(entry(value));
            }
        });
        values[term] = list;
        initial[term] = list.map((one) => one.id);
    });
    values[COURSE].forEach((course) => {
        courseTitles[course.id] = course.title;
    });
    return { values, initial, courseOf: {}, subjectOf: {}, courseTitles, loaded: {} };
}

/** What a `curriculum-tree` answer teaches: each subject's course and leaves. */
export function learnTrees(state, response) {
    const courseOf = { ...state.courseOf };
    const subjectOf = { ...state.subjectOf };
    const courseTitles = { ...state.courseTitles };
    const loaded = { ...state.loaded };
    ((response && response.trees) || []).forEach((tree) => {
        const subjectId = key(tree.subject && tree.subject.id);
        if (!ID.test(subjectId)) {
            return;
        }
        loaded[subjectId] = true;
        const courseId = key(tree.course && tree.course.id);
        if (ID.test(courseId)) {
            courseOf[subjectId] = courseId;
            courseTitles[courseId] = courseTitles[courseId] || String(tree.course.title || '');
        }
        LEAF_TERMS.forEach((term) => walk(tree[term], (node) => {
            if (node.selectable) {
                subjectOf[key(node.id)] = subjectId;
            }
        }));
    });
    // A subject the server refused has no tree and no course: it is loaded,
    // and it shows in «Sin encajar».
    ((response && response.invalid) || []).forEach((id) => {
        loaded[key(id)] = true;
    });
    return { ...state, courseOf, subjectOf, courseTitles, loaded };
}

/** A subject picked in the search box carries its course (`parentId`). */
export function learnSubject(state, result) {
    const subjectId = key(result && result.id);
    const courseId = key(result && result.parentId);
    if (!ID.test(subjectId) || !ID.test(courseId)) {
        return state;
    }
    return {
        ...state,
        courseOf: { ...state.courseOf, [subjectId]: courseId },
        courseTitles: { ...state.courseTitles, [courseId]: state.courseTitles[courseId] || String(result.parentTitle || '') }
    };
}

/** «Matemáticas · 1º ESO» under «1º ESO» is «Matemáticas». */
export function subjectName(title, courseTitle) {
    const suffix = ' · ' + String(courseTitle || '');
    const text = String(title || '');
    return courseTitle && text.endsWith(suffix) ? text.slice(0, -suffix.length) : text;
}

/**
 * Where every value goes. A subject waits (counts as pending) while its tree
 * is not loaded and its course is unknown; a leaf waits while it has no known
 * subject and some subject tree is still loading. After that, what does not
 * hang from a chosen parent is unfitted.
 */
export function placement(state) {
    const values = state.values;
    const courseIds = new Set(values[COURSE].map((one) => one.id));
    const subjectIds = new Set(values[SUBJECT].map((one) => one.id));
    const anyLoading = values[SUBJECT].some((one) => !state.loaded[one.id]);

    const subjectView = (subject) => {
        const view = {
            ...subject,
            name: subjectName(subject.title, state.courseTitles[state.courseOf[subject.id]] || ''),
            loaded: !!state.loaded[subject.id]
        };
        LEAF_TERMS.forEach((term) => {
            view[term] = values[term].filter((leaf) => state.subjectOf[leaf.id] === subject.id);
        });
        return view;
    };

    let pending = 0;
    const unfitted = { subjects: [] };
    values[SUBJECT].forEach((subject) => {
        if (courseIds.has(state.courseOf[subject.id])) {
            return;
        }
        if (!state.loaded[subject.id] && !state.courseOf[subject.id]) {
            pending += 1;
            return;
        }
        unfitted.subjects.push(subjectView(subject));
    });
    LEAF_TERMS.forEach((term) => {
        unfitted[term] = [];
        values[term].forEach((leaf) => {
            const subject = state.subjectOf[leaf.id];
            if (subject && subjectIds.has(subject)) {
                return;
            }
            if (!subject && anyLoading) {
                pending += 1;
                return;
            }
            unfitted[term].push(leaf);
        });
    });

    const courses = values[COURSE].map((course) => ({
        ...course,
        subjects: values[SUBJECT]
            .filter((subject) => state.courseOf[subject.id] === course.id)
            .map(subjectView)
    }));
    return { courses, unfitted, pending };
}

function plural(count, one, many, t) {
    return `${count} ${t(1 === count ? one : many)}`;
}

/** «1 materia» for a course; «3 saberes, 2 criterios» for a subject. */
export function summaryText(kind, view, t) {
    if ('course' === kind) {
        return plural((view.subjects || []).length, 'materia', 'materias', t);
    }
    return plural((view[TEACHES] || []).length, 'saber', 'saberes', t)
        + ', ' + plural((view[ASSESSES] || []).length, 'criterio', 'criterios', t);
}

/** `curriculum-tree` takes at most 10 subjects per request (MAX_TREE_SUBJECTS). */
export function treeBatches(ids, size = 10) {
    const batches = [];
    for (let i = 0; i < ids.length; i += size) {
        batches.push(ids.slice(i, i + size));
    }
    return batches;
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `node --test test/js/alignmentTree.test.js`
Expected: PASS (10 tests).

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/alignmentTree.js test/js/alignmentTree.test.js
git commit -m "feat: alignment tree model — state, placement, summaries (TASK-065 part B)

Model: Claude Opus 5.5"
```

---

### Task 3: Model — operations

**Files:**
- Modify: `asset/js/core/alignmentTree.js`
- Test: `test/js/alignmentTree.test.js`

**Interfaces:**
- Consumes: Task 2 state.
- Produces:
  - `addCourse(state, {id, title}, extra?) → state`
  - `addSubject(state, {id, title}, courseId, extra?) → state`
  - `setLeaf(state, term, {id, title, subjectId}, checked, extra?) → state`
  - `removeValue(state, term, id) → state`
  - `removeSubject(state, subjectId) → {state, removed}`, where `removed` counts the leaves taken with the subject.
  - `removeCourse(state, courseId) → {state, removed}`, where `removed` counts the subjects plus their leaves. Both also remove the group's own value.
  - `extra` is `{ai?: boolean, why?: string}`.

- [ ] **Step 1: Write the failing tests** (append; extend the import with `addCourse, addSubject, setLeaf, removeValue, removeSubject, removeCourse`)

```js
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `node --test test/js/alignmentTree.test.js`
Expected: FAIL — `addCourse` is not exported.

- [ ] **Step 3: Implement** (append to `alignmentTree.js`)

```js
function withValues(state, term, list) {
    return { ...state, values: { ...state.values, [term]: list } };
}

function addValue(state, term, value, extra = {}) {
    const id = key(value && value.id);
    if (!ID.test(id) || state.values[term].some((one) => one.id === id)) {
        return state;
    }
    return withValues(state, term, state.values[term].concat([entry(value, extra)]));
}

export function removeValue(state, term, id) {
    const target = key(id);
    return withValues(state, term, state.values[term].filter((one) => one.id !== target));
}

export function addCourse(state, course, extra = {}) {
    const next = addValue(state, COURSE, course, extra);
    const id = key(course && course.id);
    if (next === state || !ID.test(id)) {
        return next;
    }
    return { ...next, courseTitles: { ...next.courseTitles, [id]: String(course.title || '') } };
}

export function addSubject(state, subject, courseId, extra = {}) {
    const next = addValue(state, SUBJECT, subject, extra);
    const id = key(subject && subject.id);
    const course = key(courseId);
    if (!ID.test(id) || !ID.test(course)) {
        return next;
    }
    return { ...next, courseOf: { ...next.courseOf, [id]: course } };
}

/** Checks or unchecks a knowledge item or criterion found in a subject's tree. */
export function setLeaf(state, term, leaf, checked, extra = {}) {
    if (!checked) {
        return removeValue(state, term, leaf.id);
    }
    const next = addValue(state, term, leaf, extra);
    const id = key(leaf.id);
    const subject = key(leaf.subjectId);
    if (!ID.test(subject)) {
        return next;
    }
    return { ...next, subjectOf: { ...next.subjectOf, [id]: subject } };
}

/** Removes a subject and the knowledge and criteria found in ITS tree. */
export function removeSubject(state, subjectId) {
    const target = key(subjectId);
    let next = removeValue(state, SUBJECT, target);
    let removed = 0;
    LEAF_TERMS.forEach((term) => {
        const keep = next.values[term].filter((leaf) => next.subjectOf[leaf.id] !== target);
        removed += next.values[term].length - keep.length;
        next = withValues(next, term, keep);
    });
    return { state: next, removed };
}

/** Removes a course, its subjects and their knowledge and criteria. */
export function removeCourse(state, courseId) {
    const target = key(courseId);
    let next = removeValue(state, COURSE, target);
    let removed = 0;
    state.values[SUBJECT]
        .filter((subject) => state.courseOf[subject.id] === target)
        .forEach((subject) => {
            const result = removeSubject(next, subject.id);
            next = result.state;
            removed += 1 + result.removed;
        });
    return { state: next, removed };
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `node --test test/js/alignmentTree.test.js`
Expected: PASS (16 tests).

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/alignmentTree.js test/js/alignmentTree.test.js
git commit -m "feat: alignment tree model — add and remove courses, subjects and leaves (TASK-065 part B)

Model: Claude Opus 5.5"
```

---

### Task 4: Model — serialization, AI merge, grouped diff

**Files:**
- Modify: `asset/js/core/alignmentTree.js`
- Test: `test/js/alignmentTree.test.js`

**Interfaces:**
- Consumes: Task 2–3 state. The preview `diff` is `{term: {added, removed, invalid, titles}}` (`RecatalogService::preview`). The AI payload is `alignment: {term: [{id, title}]}` and `justifications: {term: {id: why}}`.
- Produces:
  - `changedTerms(state) → term[]`
  - `alignmentPairs(state) → [{name, value}]`. These are the `alignment[term][]` pairs, plus `justification[term][id]` for leaf terms. They contain no `id` pair; the caller adds it.
  - `mergeProposal(state, alignment, justifications) → {state, added}`. It covers the four curricular terms only.
  - `groupedDiff(diff, state) → {sections: [{kind: 'course'|'relation'|'unfitted', title, lines: [{sign, term, subject, title}]}], hasInvalid}`. `sign` is one of `'+'`, `'−'` or `'!'`.

- [ ] **Step 1: Write the failing tests** (append; extend the import with `RELATION, changedTerms, alignmentPairs, mergeProposal, groupedDiff`)

```js
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
    assert.deepEqual(view.unfitted.subjects.map((s) => [s.id, s.ai]), [['30', true]]);
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
```

- [ ] **Step 2: Run them to verify they fail**

Run: `node --test test/js/alignmentTree.test.js`
Expected: FAIL — `changedTerms` is not exported.

- [ ] **Step 3: Implement** (append to `alignmentTree.js`)

```js
function sameSet(ids, others) {
    return ids.length === others.length && ids.every((id) => others.includes(id));
}

/** Terms whose set of ids differs from the REA's: the only ones sent. */
export function changedTerms(state) {
    return CURRICULAR_TERMS.filter((term) => !sameSet(state.values[term].map((one) => one.id), state.initial[term]));
}

/**
 * The `recatalog-preview`/`recatalog-apply` pairs of the curricular terms.
 * The same contract the chips produced: the whole list of a changed term, an
 * empty value for an emptied one, and the AI's reason of each knowledge item
 * or criterion that has one.
 */
export function alignmentPairs(state) {
    const pairs = [];
    changedTerms(state).forEach((term) => {
        const list = state.values[term];
        if (!list.length) {
            pairs.push({ name: `alignment[${term}][]`, value: '' });
        }
        list.forEach((one) => pairs.push({ name: `alignment[${term}][]`, value: one.id }));
        if (LEAF_TERMS.includes(term)) {
            list.filter((one) => one.why).forEach((one) => {
                pairs.push({ name: `justification[${term}][${one.id}]`, value: one.why });
            });
        }
    });
    return pairs;
}

/** Adds the AI proposal's curricular values that are missing, marked as AI. */
export function mergeProposal(state, alignment, justifications) {
    let next = state;
    let added = 0;
    const reasons = justifications || {};
    CURRICULAR_TERMS.forEach((term) => {
        ((alignment && alignment[term]) || []).forEach((candidate) => {
            const before = next;
            const extra = { ai: true, why: (reasons[term] || {})[candidate.id] || '' };
            next = COURSE === term ? addCourse(next, candidate, extra) : addValue(next, term, candidate, extra);
            if (next !== before) {
                added += 1;
            }
        });
    });
    return { state: next, added };
}

/**
 * The preview's flat diff, grouped by course (each leaf with its subject),
 * then what does not fit, then the thematic axis.
 */
export function groupedDiff(diff, state) {
    const sections = new Map();
    let hasInvalid = false;
    const section = (sectionKey, kind, title) => {
        if (!sections.has(sectionKey)) {
            sections.set(sectionKey, { kind, title, lines: [] });
        }
        return sections.get(sectionKey);
    };
    const unfittedSection = () => section('unfitted', 'unfitted', '');
    const courseSection = (courseId) => (courseId && undefined !== state.courseTitles[courseId]
        ? section('course:' + courseId, 'course', state.courseTitles[courseId])
        : unfittedSection());
    const subjectTitles = {};
    state.values[SUBJECT].forEach((subject) => {
        subjectTitles[subject.id] = subject.title;
    });
    Object.entries((diff && diff[SUBJECT] && diff[SUBJECT].titles) || {}).forEach(([id, title]) => {
        subjectTitles[key(id)] = subjectTitles[key(id)] || title;
    });

    [COURSE, SUBJECT, ...LEAF_TERMS, RELATION].forEach((term) => {
        const change = diff && diff[term];
        if (!change) {
            return;
        }
        const titles = change.titles || {};
        [['+', change.added], ['−', change.removed], ['!', change.invalid]].forEach(([sign, ids]) => {
            (ids || []).forEach((raw) => {
                const id = key(raw);
                if ('!' === sign) {
                    hasInvalid = true;
                }
                let target;
                let subject = '';
                if (RELATION === term) {
                    target = section('relation', 'relation', '');
                } else if ('!' === sign) {
                    target = unfittedSection();
                } else if (COURSE === term) {
                    target = courseSection(id);
                } else if (SUBJECT === term) {
                    target = courseSection(state.courseOf[id]);
                } else {
                    const subjectId = state.subjectOf[id];
                    target = subjectId ? courseSection(state.courseOf[subjectId]) : unfittedSection();
                    subject = subjectId
                        ? subjectName(subjectTitles[subjectId] || '', state.courseTitles[state.courseOf[subjectId]] || '')
                        : '';
                }
                target.lines.push({ sign, term, subject, title: titles[raw] || titles[id] || '#' + id });
            });
        });
    });
    // Courses first (in the order met), then «Sin encajar», then the axis.
    const rank = { course: 0, unfitted: 1, relation: 2 };
    return { sections: Array.from(sections.values()).sort((a, b) => rank[a.kind] - rank[b.kind]), hasInvalid };
}
```

- [ ] **Step 4: Run them to verify they pass**

Run: `node --test test/js/alignmentTree.test.js`, then `make test-js`.
Expected: PASS (23 tests in the file), and the whole JS suite has 0 fail.

- [ ] **Step 5: Commit**

```bash
git add asset/js/core/alignmentTree.js test/js/alignmentTree.test.js
git commit -m "feat: alignment tree model — flat pairs, AI merge, diff by course (TASK-065 part B)

Model: Claude Opus 5.5"
```

---

### Task 5: A search box for courses and subjects

**Files:**
- Create: `asset/js/ui/termSearch.js`
- Modify: `asset/js/ui/termPicker.js` (scope the result click handler)

**Interfaces:**
- Consumes: `config.searchTermsUrl`. Results are `[{id, title, notation, description, block, parentId, parentTitle}]`.
- Produces: `buildTermSearch({config, dimension, label, placeholder, context, onPick}) → jQuery element`.
  - `context()` returns the extra query, for example `{level: ['1']}` or `{etapa: [...]}`.
  - `onPick(result)` is called with the chosen result.

- [ ] **Step 1: Scope termPicker's result handler**

In `termPicker.js` change

```js
    $(document).on('click', '.chosen-results .active-result', function () {
```

to

```js
    // Scoped to the chip selectors (Etapa, Eje temático): the tree editor's
    // search boxes (ui/termSearch.js) handle their own results.
    $(document).on('click', '.oer-recatalog-dim .chosen-results .active-result', function () {
```

- [ ] **Step 2: Create `asset/js/ui/termSearch.js`**

```js
/**
 * Search box of the tree editor (TASK-065 part B, spec B4): one input over
 * `search-terms` that calls `onPick` with the chosen result instead of adding
 * a chip. The course box is narrowed by the Etapa helper; a subject box by its
 * own course.
 *
 * Accessibility (NFR-006): a labelled native input, results as native
 * buttons, the count in a polite live region. ArrowDown goes from the input
 * to the results, ArrowUp/ArrowDown move between them, Escape closes them and
 * returns to the input (it does not close the drawer).
 */

let boxSeq = 0;

function t(text) {
    return Omeka.jsTranslate(text);
}

function lineageOf(result) {
    return [result.parentTitle, result.block, result.description]
        .filter((part) => part && String(part).trim())
        .join(' · ');
}

export function buildTermSearch({ config, dimension, label, placeholder, context, onPick }) {
    boxSeq += 1;
    const id = 'oer-term-searchbox-' + boxSeq;
    const $box = $('<div>').addClass('oer-term-searchbox');
    const $input = $('<input>').attr({
        type: 'search',
        id,
        autocomplete: 'off',
        spellcheck: 'false',
        placeholder: placeholder || t('Buscar término…')
    }).addClass('oer-term-searchbox-input');
    const $results = $('<ul>').addClass('oer-term-searchbox-results').prop('hidden', true);
    const $status = $('<p>').addClass('oer-visually-hidden').attr({ role: 'status', 'aria-live': 'polite' });
    let timer = null;
    let request = 0;

    const close = () => {
        $results.empty().prop('hidden', true);
    };

    const pick = (result) => {
        $input.val('');
        close();
        $status.text('');
        onPick(result);
    };

    $input.on('input', () => {
        window.clearTimeout(timer);
        const text = String($input.val() || '').trim();
        if (text.length < 2) {
            close();
            $status.text('');
            return;
        }
        timer = window.setTimeout(() => {
            const mine = ++request;
            const params = Object.assign({}, context ? context() : {}, { dimension, q: text });
            $.getJSON(config.searchTermsUrl, params).done((response) => {
                if (mine !== request) {
                    return;
                }
                const results = (response && response.results) || [];
                $results.empty();
                results.forEach((result) => {
                    const $button = $('<button>').attr('type', 'button').addClass('oer-term-searchbox-result')
                        .append($('<span>').addClass('oer-term-title').text(result.title));
                    const lineage = lineageOf(result);
                    if (lineage) {
                        $button.append($('<span>').addClass('oer-term-lineage').text(lineage));
                    }
                    $button.on('click', () => pick(result));
                    $results.append($('<li>').append($button));
                });
                if (!results.length) {
                    $results.append($('<li>').addClass('no-results').text(t('Sin resultados')));
                }
                $results.prop('hidden', false);
                $status.text(1 === results.length ? t('1 resultado') : results.length + ' ' + t('resultados'));
            });
        }, 250);
    });

    $input.on('keydown', (event) => {
        if ('Enter' === event.key) {
            event.preventDefault();
        }
        if ('ArrowDown' === event.key && !$results.prop('hidden')) {
            event.preventDefault();
            $results.find('button').first().trigger('focus');
        }
        if ('Escape' === event.key && !$results.prop('hidden')) {
            // Closes the results, not the drawer (drawer.js closes on Escape).
            event.stopPropagation();
            close();
        }
    });

    $results.on('keydown', 'button', function (event) {
        const $buttons = $results.find('button');
        const index = $buttons.index(this);
        if ('ArrowDown' === event.key || 'ArrowUp' === event.key) {
            event.preventDefault();
            const next = 'ArrowDown' === event.key ? index + 1 : index - 1;
            if (next < 0) {
                $input.trigger('focus');
            } else if (next < $buttons.length) {
                $buttons.eq(next).trigger('focus');
            }
        }
        if ('Escape' === event.key) {
            event.stopPropagation();
            close();
            $input.trigger('focus');
        }
    });

    return $box.append($('<label>').attr('for', id).text(label), $input, $results, $status);
}
```

- [ ] **Step 3: Verify**

Run: `node --check asset/js/ui/termSearch.js && node --check asset/js/ui/termPicker.js && make test-js`
Expected: no syntax errors and the suite passes. Its behaviour in the browser is checked in Task 8.

- [ ] **Step 4: Commit**

```bash
git add asset/js/ui/termSearch.js asset/js/ui/termPicker.js
git commit -m "feat: search box for courses and subjects of the tree editor (TASK-065 part B)

Model: Claude Opus 5.5"
```

---

### Task 6: The tree editor

**Files:**
- Create: `asset/js/ui/alignmentEditor.js`

**Interfaces:**
- Consumes:
  - Task 2–4 model.
  - `leafTreeModel.js`: `treeViews`, `filterTrees`, `filterStatus`, `retiredById`.
  - Task 5 `buildTermSearch`.
  - `termPicker.js`: `buildDimensionSelector`, `chipIdsByTerm`, `disableApply`.
- Produces:
  - `buildAlignmentEditor(config, $panel, recatalog) → jQuery element`. It also stores the model on `$panel`.
  - `openAlignmentEditor(config, $panel)`, which loads the trees of the current subjects.
  - `editorPairs($panel) → [{name, value}]`
  - `editorState($panel) → state|null`
  - `proposeIntoEditor(config, $panel, alignment, justifications) → number`, the count of values added.
  - `initAlignmentEditor(config)`, which sets up the delegated handlers once.

- [ ] **Step 1: Create `asset/js/ui/alignmentEditor.js`**

```js
import {
    COURSE, SUBJECT, LEAF_TERMS, TERM_LABELS,
    createState, learnTrees, learnSubject, placement, summaryText, treeBatches,
    addCourse, addSubject, setLeaf, removeValue, removeSubject, removeCourse,
    alignmentPairs, mergeProposal
} from '../core/alignmentTree.js';
import { treeViews, filterTrees, filterStatus, retiredById } from '../core/leafTreeModel.js';
import { buildTermSearch } from './termSearch.js';
import { buildDimensionSelector, chipIdsByTerm, disableApply } from './termPicker.js';

/**
 * The re-cataloguer as a tree (TASK-065 part B, spec B1–B7): Curso → Materia
 * → Saberes/Criterios, each course and subject a collapsible group with its
 * summary, and «Sin encajar» for what does not fit. Every decision is the
 * model's (core/alignmentTree.js); this file renders it and keeps which groups
 * are open.
 *
 * The model is stored on the panel (`$panel.data`). Structural changes (add,
 * remove, trees loaded, AI) re-render the editor and restore the focus by id;
 * a checkbox only updates the counts, so a long tree keeps its scroll.
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
    // Any change invalidates the last preview, as markDirty() did for chips.
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
            setState($panel, learnTrees(editorState($panel), response));
            renderEditor($panel);
        }).fail(() => {
            batch.forEach((id) => {
                view.trees[id] = null;
                view.errors[id] = true;
            });
            setState($panel, learnTrees(editorState($panel), { trees: [], invalid: batch }));
            renderEditor($panel);
        });
    });
}

export function buildAlignmentEditor(config, $panel, recatalog) {
    $panel.data(STATE, createState(recatalog.current));
    $panel.data(UI, { config, expanded: {}, trees: {}, errors: {}, retired: new Map(), filters: {}, focus: null });

    const $editor = $('<div>').addClass('oer-align-editor');
    const $head = $('<div>').addClass('oer-align-head');
    $head.append($('<h5>').addClass('oer-align-heading').text(t('Cursos')));
    // Etapa narrows the course search; it is never written (ADR-0022).
    const $etapa = buildDimensionSelector('etapa', t('Etapa'), [], true);
    $etapa.append($('<p>').addClass('oer-dim-hint').text(t('Solo acota la búsqueda. No se guarda en el REA.')));
    $head.append($etapa);
    $head.append(buildTermSearch({
        config,
        dimension: COURSE,
        label: t('Añadir curso'),
        context: () => ({ etapa: chipIdsByTerm($panel)['etapa'] || [] }),
        onPick: (result) => {
            setState($panel, addCourse(editorState($panel), result));
            ui($panel).expanded['course-' + result.id] = true;
            ui($panel).focus = domId($panel, ['course', result.id, 'search']);
            renderEditor($panel);
            announce($panel, t('Curso añadido:') + ' ' + result.title);
        }
    }).addClass('oer-align-course-search'));
    $editor.append($head);
    $editor.append($('<p>').addClass('oer-align-status oer-visually-hidden').attr({ role: 'status', 'aria-live': 'polite' }));
    return $editor;
}

export function openAlignmentEditor(config, $panel) {
    const state = editorState($panel);
    if (!state) {
        return;
    }
    // Courses start open; a subject opens by itself only when it is the only one.
    const view = ui($panel);
    state.values[COURSE].forEach((course) => {
        view.expanded['course-' + course.id] = true;
    });
    if (1 === state.values[SUBJECT].length) {
        view.expanded['subject-' + state.values[SUBJECT][0].id] = true;
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
    next.values[COURSE].forEach((course) => {
        ui($panel).expanded['course-' + course.id] = true;
    });
    loadTrees($panel, next.values[SUBJECT].map((subject) => subject.id));
    return added;
}

function aiMark(why) {
    const $mark = $('<span>').addClass('oer-ai-mark').text(t('IA'));
    if (!why) {
        return $mark;
    }
    // The reason is there, collapsed, so it does not anchor the curator (PEND-013).
    return $('<span>').addClass('oer-ai-wrap').append($mark, $('<details>').addClass('oer-ai-why')
        .append($('<summary>').text(t('Ver porqué')), $('<p>').text(why)));
}

function groupRow($panel, kind, item, summary, bodyId, open) {
    const name = 'course' === kind ? item.title : item.name;
    const removeText = 'course' === kind ? t('Quitar curso') : t('Quitar materia');
    const $row = $('<div>').addClass('oer-align-row');
    $row.append($('<button>').attr({
        type: 'button',
        id: domId($panel, [kind, item.id, 'toggle']),
        'aria-expanded': open ? 'true' : 'false',
        'aria-controls': bodyId,
        'data-group': kind + '-' + item.id
    }).addClass('oer-tree-toggle oer-align-toggle')
        .append($('<span>').addClass('oer-align-name').text(name))
        .append($('<span>').addClass('oer-align-summary').text(' · ' + summary)));
    if (item.ai) {
        $row.append(aiMark(''));
    }
    $row.append($('<button>').attr({
        type: 'button',
        id: domId($panel, [kind, item.id, 'remove']),
        'data-kind': kind,
        'data-id': item.id,
        'data-name': name,
        'aria-label': removeText + ' ' + name
    }).addClass('button oer-align-remove').text(removeText));
    return $row;
}

function buildNode($panel, term, subjectId, node, aiById) {
    const $li = $('<li>').addClass('oer-tree-node').attr('data-id', node.id);
    const childrenId = domId($panel, [term, subjectId, node.id, 'children']);
    const hasChildren = node.children.length > 0;
    if (!node.selectable) {
        $li.addClass('oer-tree-block-node').append($('<button>').attr({
            type: 'button', 'aria-expanded': node.expanded ? 'true' : 'false', 'aria-controls': childrenId
        }).addClass('oer-tree-toggle oer-tree-block')
            .append($('<span>').addClass('oer-tree-block-title').text(node.title))
            .append($('<span>').addClass('oer-tree-count')
                .text(node.selectedBelow ? ' (' + node.selectedBelow + ' ' + t('seleccionados') + ')' : '')));
    } else {
        const checkId = domId($panel, [term, subjectId, node.id]);
        if (hasChildren) {
            $li.append($('<button>').attr({
                type: 'button', 'aria-expanded': node.expanded ? 'true' : 'false', 'aria-controls': childrenId,
                'aria-label': t('Desplegar') + ' ' + (node.notation || node.title)
            }).addClass('oer-tree-toggle oer-tree-twisty'));
        }
        const $check = $('<input>').attr({ type: 'checkbox', id: checkId, value: node.id })
            .addClass('oer-tree-check')
            .attr({ 'data-title': node.title, 'data-term': term, 'data-subject': subjectId })
            .prop('checked', node.checked);
        const $label = $('<label>').attr('for', checkId).text(node.title);
        $li.append($check, $label);
        if (node.definition) {
            const defId = checkId + '-def';
            $label.attr('title', node.definition);
            $check.attr('aria-describedby', defId);
            $li.append($('<span>').attr('id', defId).addClass('oer-visually-hidden').text(node.definition));
        }
        const ai = aiById.get(node.id);
        if (ai && node.checked) {
            $li.append(aiMark(ai.why));
        }
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
    const labelId = domId($panel, [term, subject.id, 'label']);
    $box.append($('<p>').addClass('oer-align-leaves-label').attr('id', labelId).text(t(TERM_LABELS[term])));
    const response = { trees: [tree] };
    const [one] = treeViews(response, term, state.values[term].map((leaf) => leaf.id)).trees;
    if (!one || !one.nodes.length) {
        return $box.append($('<p>').addClass('oer-leaf-tree-note').text(t('Sin términos vigentes en esta asignatura.')));
    }
    const inputId = domId($panel, [term, subject.id, 'filter']);
    const query = view.filters[fkey] || '';
    const result = filterTrees(response, term, query);
    $box.append($('<div>').addClass('oer-leaf-tree-filter')
        .append($('<label>').attr('for', inputId).text(t('Filtrar:') + ' ' + t(TERM_LABELS[term])))
        .append($('<input>').attr({ type: 'search', id: inputId, autocomplete: 'off', spellcheck: 'false' })
            .addClass('oer-leaf-tree-filter-input oer-align-filter').attr('data-fkey', fkey).val(query))
        .append($('<p>').addClass('oer-leaf-tree-filter-status').attr({ role: 'status', 'aria-live': 'polite' })
            .text(filterStatus(result, t))));
    const aiById = new Map(state.values[term].filter((leaf) => leaf.ai).map((leaf) => [leaf.id, leaf]));
    const $list = $('<ul>').addClass('oer-tree-root').attr('aria-labelledby', labelId);
    one.nodes.forEach((node) => $list.append(buildNode($panel, term, subject.id, node, aiById)));
    const $host = $('<div>').addClass('oer-leaf-tree').append($list);
    if (result.active) {
        $host.find('.oer-tree-node').each(function () {
            this.hidden = !result.visible.has(String($(this).attr('data-id')));
        });
        $host.find('.oer-tree-toggle').each(function () {
            const open = result.expanded.has(String($(this).closest('.oer-tree-node').attr('data-id')));
            $(this).attr('aria-expanded', open ? 'true' : 'false');
            $host.find('#' + $(this).attr('aria-controls')).prop('hidden', !open);
        });
    }
    return $box.append($host);
}

function renderSubject($panel, subject) {
    const view = ui($panel);
    const bodyId = domId($panel, ['subject', subject.id, 'body']);
    const open = !!view.expanded['subject-' + subject.id];
    const $li = $('<li>').addClass('oer-align-subject').attr('data-id', subject.id);
    $li.append(groupRow($panel, 'subject', subject, summaryText('subject', subject, t), bodyId, open));
    const $body = $('<div>').addClass('oer-align-body').attr('id', bodyId).prop('hidden', !open);
    if (!subject.loaded || undefined === view.trees[subject.id]) {
        $body.append($('<p>').addClass('oer-leaf-tree-note').text(t('Cargando…')));
    } else if (null === view.trees[subject.id]) {
        $body.append($('<p>').addClass('oer-leaf-tree-note').text(view.errors[subject.id]
            ? t('No se pudo cargar el árbol del currículo.')
            : t('La asignatura elegida no es una materia en curso del currículo.')));
    } else {
        LEAF_TERMS.forEach((term) => $body.append(renderLeaves($panel, subject, term)));
    }
    return $li.append($body);
}

function renderCourse($panel, course) {
    const view = ui($panel);
    const bodyId = domId($panel, ['course', course.id, 'body']);
    const open = !!view.expanded['course-' + course.id];
    const $li = $('<li>').addClass('oer-align-course').attr('data-id', course.id);
    $li.append(groupRow($panel, 'course', course, summaryText('course', course, t), bodyId, open));
    const $body = $('<div>').addClass('oer-align-body').attr('id', bodyId).prop('hidden', !open);
    const $subjects = $('<ul>').addClass('oer-align-subjects');
    course.subjects.forEach((subject) => $subjects.append(renderSubject($panel, subject)));
    $body.append($subjects);
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
            view.expanded['subject-' + result.id] = true;
            view.focus = domId($panel, ['subject', result.id, 'toggle']);
            loadTrees($panel, [String(result.id)]);
            announce($panel, t('Materia añadida:') + ' ' + result.title);
        }
    });
    $search.find('input').attr('id', searchId);
    $search.find('label').attr('for', searchId);
    $body.append($search.addClass('oer-align-subject-search'));
    return $li.append($body);
}

function renderUnfitted($panel, unfitted) {
    const state = editorState($panel);
    const view = ui($panel);
    const items = [];
    unfitted.subjects.forEach((subject) => {
        const courseId = state.courseOf[subject.id];
        const $li = $('<li>').text(t('Materia') + ': ' + subject.title + ' ');
        if (subject.ai) {
            $li.append(aiMark(''));
        }
        if (courseId && state.courseTitles[courseId]) {
            $li.append($('<button>').attr({ type: 'button', 'data-id': courseId, 'data-title': state.courseTitles[courseId] })
                .addClass('button oer-align-add-course')
                .text(t('Añadir su curso') + ' (' + state.courseTitles[courseId] + ')'));
        }
        $li.append($('<button>').attr({ type: 'button', 'data-kind': 'subject', 'data-id': subject.id, 'data-name': subject.title })
            .addClass('button oer-align-remove').text(t('Quitar')));
        items.push($li);
    });
    LEAF_TERMS.forEach((term) => unfitted[term].forEach((leaf) => {
        const retired = view.retired.get(leaf.id);
        const $li = $('<li>').text(t(TERM_LABELS[term]) + ': ' + leaf.title);
        if (retired) {
            $li.append($('<span>').addClass('oer-chip-mark').text(' · ' + t('retirado')
                + (retired.replacedBy.length ? ' (' + t('Sustituto:') + ' ' + retired.replacedBy.join(', ') + ')' : '')));
        }
        if (leaf.ai) {
            $li.append(' ', aiMark(leaf.why));
        }
        $li.append(' ', $('<button>').attr({ type: 'button', 'data-kind': term, 'data-id': leaf.id, 'data-name': leaf.title })
            .addClass('button oer-align-remove').text(t('Quitar')));
        items.push($li);
    }));
    if (!items.length) {
        return null;
    }
    return $('<section>').addClass('oer-align-unfitted')
        .append($('<h5>').text(t('Sin encajar')))
        .append($('<p>').addClass('oer-dim-hint')
            .text(t('No cuelgan de un curso o una materia elegidos. Quítalos o añade lo que les falta.')))
        .append($('<ul>').append(items));
}

function renderEditor($panel) {
    const $editor = $panel.find('.oer-align-editor');
    const view = ui($panel);
    const focus = view.focus || (document.activeElement && document.activeElement.id);
    view.focus = null;
    const layout = placement(editorState($panel));
    $editor.children('.oer-align-tree, .oer-align-unfitted, .oer-align-pending, .oer-align-empty').remove();
    const $parts = [];
    if (!layout.courses.length) {
        $parts.push($('<p>').addClass('oer-align-empty oer-leaf-tree-note').text(t('Añade un curso para empezar.')));
    } else {
        const $tree = $('<ul>').addClass('oer-align-tree');
        layout.courses.forEach((course) => $tree.append(renderCourse($panel, course)));
        $parts.push($tree);
    }
    const $unfitted = renderUnfitted($panel, layout.unfitted);
    if ($unfitted) {
        $parts.push($unfitted);
    }
    if (layout.pending) {
        $parts.push($('<p>').addClass('oer-align-pending oer-leaf-tree-note').text(t('Cargando…')));
    }
    $editor.children('.oer-align-head').after($parts);
    if (focus) {
        const element = document.getElementById(focus);
        if (element) {
            element.focus();
        }
    }
}

/** A checkbox changes only counts: the tree keeps its scroll and focus. */
function refreshCounts($panel) {
    const layout = placement(editorState($panel));
    const $editor = $panel.find('.oer-align-editor');
    layout.courses.forEach((course) => {
        $editor.find(`.oer-align-course[data-id="${course.id}"] > .oer-align-row .oer-align-summary`)
            .text(' · ' + summaryText('course', course, t));
        course.subjects.forEach((subject) => {
            $editor.find(`.oer-align-subject[data-id="${subject.id}"] > .oer-align-row .oer-align-summary`)
                .text(' · ' + summaryText('subject', subject, t));
        });
    });
    $editor.find('.oer-tree-block-node').each(function () {
        const below = $(this).find('.oer-tree-children .oer-tree-check:checked').length;
        $(this).children('.oer-tree-block').find('.oer-tree-count')
            .text(below ? ' (' + below + ' ' + t('seleccionados') + ')' : '');
    });
}

function toggle($button, open) {
    $button.attr('aria-expanded', open ? 'true' : 'false');
    $('#' + $button.attr('aria-controls')).prop('hidden', !open);
}

export function initAlignmentEditor(config) {
    if (!config.curriculumTreeUrl) {
        return;
    }
    $(document).on('click', '.oer-align-editor .oer-tree-toggle', function () {
        const $button = $(this);
        const open = 'true' !== $button.attr('aria-expanded');
        toggle($button, open);
        const group = $button.attr('data-group');
        if (group) {
            ui($button.closest('.oer-recatalog')).expanded[group] = open;
        }
    });

    // ArrowRight opens a branch, ArrowLeft closes it (as in today's trees).
    $(document).on('keydown', '.oer-align-editor .oer-tree-toggle', function (event) {
        if ('ArrowRight' !== event.key && 'ArrowLeft' !== event.key) {
            return;
        }
        event.preventDefault();
        const open = 'true' === $(this).attr('aria-expanded');
        if (('ArrowRight' === event.key) !== open) {
            $(this).trigger('click');
        }
    });

    $(document).on('change', '.oer-align-editor .oer-tree-check', function () {
        const $check = $(this);
        const $panel = $check.closest('.oer-recatalog');
        setState($panel, setLeaf(editorState($panel), $check.attr('data-term'), {
            id: $check.val(), title: $check.attr('data-title'), subjectId: $check.attr('data-subject')
        }, this.checked));
        refreshCounts($panel);
    });

    $(document).on('input', '.oer-align-editor .oer-align-filter', function () {
        const $input = $(this);
        const $panel = $input.closest('.oer-recatalog');
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
        const $panel = $button.closest('.oer-recatalog');
        const course = { id: $button.attr('data-id'), title: $button.attr('data-title') };
        setState($panel, addCourse(editorState($panel), course));
        ui($panel).expanded['course-' + course.id] = true;
        renderEditor($panel);
        announce($panel, t('Curso añadido:') + ' ' + course.title);
    });

    $(document).on('click', '.oer-align-editor .oer-align-remove', function () {
        const $button = $(this);
        const $panel = $button.closest('.oer-recatalog');
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
        // A whole group asks first, with what goes with it (spec B4); one
        // value is removed without asking.
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
}
```

- [ ] **Step 2: Verify the syntax**

Run: `node --check asset/js/ui/alignmentEditor.js`
Expected: no output. The behaviour is checked in Task 8.

- [ ] **Step 3: Commit**

```bash
git add asset/js/ui/alignmentEditor.js
git commit -m "feat: the re-cataloguer's tree editor (TASK-065 part B)

Model: Claude Opus 5.5"
```

---

### Task 7: Wire the editor into the panel; sticky footer; wide sidebar; strings

**Files:**
- Modify: `asset/js/ui/recatalog.js`, `asset/js/ui/aiPropose.js`, `asset/js/ui/drawerDetails.js`, `asset/js/main.js`
- Delete: `asset/js/ui/leafTree.js`
- Modify: `asset/css/oer-master-view.css`, `config/module.config.php`

**Interfaces:**
- Consumes: the Task 6 exports and `groupedDiff`/`RELATION`/`TERM_LABELS` (Task 4).
- Produces: unchanged exports of `recatalog.js`, namely `collectAlignmentPairs($panel)` and `refusalText`. `workflowDrawer.js` keeps using them for «Confirmar y publicar».

- [ ] **Step 1: `recatalog.js`**

Replace the imports at the top with:

```js
import { groupedDiff, RELATION, TERM_LABELS } from '../core/alignmentTree.js';
import { undoLabel } from '../core/curationEvent.js';
import { messageFor } from '../core/messages.js';
import { valuesFor } from '../core/anchorData.js';
import { buildDimensionSelector, disableApply } from './termPicker.js';
import { buildAlignmentEditor, openAlignmentEditor, editorPairs, editorState } from './alignmentEditor.js';
import { reopenDrawer } from './drawer.js';
import { ANCHOR_SLOT } from './drawerDetails.js';
```

Replace `buildRecatalogPanel` with:

```js
function buildRecatalogPanel(config, itemId, recatalog) {
    const $panel = $('<div>').addClass('oer-recatalog').attr('data-item-id', itemId);

    // TASK-065 part B (spec B5): the AI proposal fills the same tree, so it
    // sits at the top of the editor.
    if (config.aiEnabled) {
        $panel.append($('<div>').addClass('oer-recatalog-ai-bar')
            .append($('<button>').attr('type', 'button').addClass('button oer-recatalog-ai')
                .text(Omeka.jsTranslate('Proponer con IA')))
            .append($('<button>').attr('type', 'button').addClass('button oer-recatalog-ai-cancel')
                .text(Omeka.jsTranslate('Detener la propuesta'))));
    }

    // Courses, subjects, knowledge and criteria: the tree (spec B1).
    $panel.append(buildAlignmentEditor(config, $panel, recatalog));

    // The thematic axis stays a flat selector, as today.
    const $dims = $('<div>').addClass('oer-recatalog-dims');
    $dims.append(buildDimensionSelector(
        RELATION,
        Omeka.jsTranslate(TERM_LABELS[RELATION]),
        valuesFor(recatalog, RELATION),
        false
    ));
    $panel.append($dims);

    // Sticky footer (spec B6): the actions and the diff stay in sight.
    const $footer = $('<div>').addClass('oer-recatalog-footer');
    $footer.append($('<div>').addClass('oer-recatalog-actions')
        .append($('<button>').attr('type', 'button').addClass('button oer-recatalog-preview')
            .text(Omeka.jsTranslate('Previsualizar cambios')))
        .append($('<button>').attr('type', 'button').addClass('button oer-recatalog-apply')
            .prop('disabled', true)
            .text(Omeka.jsTranslate('Confirmar')))
        .append($('<button>').attr('type', 'button').addClass('button oer-recatalog-cancel')
            .text(Omeka.jsTranslate('Cancelar'))));
    $footer.append($('<div>').addClass('oer-recatalog-diff').attr({ role: 'status', 'aria-live': 'polite' }));
    $panel.append($footer);
    return $panel;
}
```

Replace `collectAlignmentPairs` with:

```js
export function collectAlignmentPairs($panel) {
    const pairs = [{ name: 'id', value: $panel.data('item-id') }];
    // Courses, subjects, knowledge and criteria: only the terms whose set
    // changed, from the tree's model (TASK-065 part B).
    editorPairs($panel).forEach((pair) => pairs.push(pair));
    // The thematic axis: its chips, when modified, as before.
    $panel.find(`.oer-recatalog-dim[data-term="${RELATION}"][data-dirty="1"]`).each(function () {
        const ids = $(this).find('.chosen-choices .search-choice').map(function () {
            return $(this).data('id');
        }).get();
        if (!ids.length) {
            pairs.push({ name: `alignment[${RELATION}][]`, value: '' });
        }
        ids.forEach((id) => pairs.push({ name: `alignment[${RELATION}][]`, value: id }));
    });
    return pairs;
}
```

Replace `renderDiff` with:

```js
/**
 * D7 and spec B6: the diff says WHAT changes, by name, grouped by course and
 * subject, with the thematic axis as its own row.
 */
function renderDiff($panel, diff) {
    const { sections, hasInvalid } = groupedDiff(diff, editorState($panel));
    const $diff = $panel.find('.oer-recatalog-diff').empty();
    if (!sections.length) {
        $diff.text(Omeka.jsTranslate('sin cambios'));
        return true;
    }
    const titleOf = (section) => ('course' === section.kind
        ? section.title
        : Omeka.jsTranslate('relation' === section.kind ? TERM_LABELS[RELATION] : 'Sin encajar'));
    const classOf = { '+': 'oer-diff-added', '−': 'oer-diff-removed', '!': 'oer-diff-invalid' };
    sections.forEach((section) => {
        const $section = $('<div>').addClass('oer-diff-section');
        $section.append($('<p>').addClass('oer-diff-heading').text(titleOf(section)));
        section.lines.forEach((line) => {
            const label = Omeka.jsTranslate(TERM_LABELS[line.term]);
            const where = line.subject ? `${label} (${line.subject})` : label;
            const sign = '!' === line.sign ? Omeka.jsTranslate('inválido:') : line.sign;
            $section.append($('<p>').addClass('oer-diff-row ' + classOf[line.sign]).text(`${sign} ${where}: ${line.title}`));
        });
        $diff.append($section);
    });
    return !hasInvalid;
}
```

In `initRecatalog`, after the `ANCHOR_SLOT` listener, add:

```js
    // Opening the editor is the first moment the trees are needed.
    $(document).on('click', '.oer-anchor-edit', function () {
        const $panel = $(this).closest('.oer-area-alignment').find('.oer-recatalog');
        if ($panel.length) {
            openAlignmentEditor(config, $panel);
        }
    });
```

- [ ] **Step 2: `aiPropose.js`**

Replace the line `import { addChip, chipIdsByTerm } from './termPicker.js';` with:

```js
import { RELATION } from '../core/alignmentTree.js';
import { addChip, chipIdsByTerm } from './termPicker.js';
import { proposeIntoEditor } from './alignmentEditor.js';
```

Replace `applyAiProposal` (the whole function and its docblock) with:

```js
/**
 * Pre-fills the panel with the AI proposal (TASK-010; TASK-065 part B): the
 * curricular terms go into the tree, marked as AI with their reason; the
 * thematic axis into its chips, as before. Nothing is written until
 * «Confirmar».
 */
function applyAiProposal(ctx, alignment, justifications) {
    let added = proposeIntoEditor(ctx.config, ctx.$panel, alignment, justifications);
    const axis = pendingChips({ [RELATION]: (alignment || {})[RELATION] || [] }, chipIdsByTerm(ctx.$panel), {});
    const $dim = ctx.$panel.find(`.oer-recatalog-dim[data-term="${RELATION}"]`);
    (axis[RELATION] || []).forEach((candidate) => {
        if ($dim.length && addChip($dim, candidate.id, candidate.title)) {
            added += 1;
        }
    });
    return added;
}
```

In `handleProposalPayload`, change the first line to:

```js
    const added = applyAiProposal(ctx, response.alignment, response.justifications);
```

- [ ] **Step 3: `main.js`, `drawerDetails.js`, delete `leafTree.js`**

In `main.js`:
- Replace `import { initLeafTree } from './ui/leafTree.js';` with `import { initAlignmentEditor } from './ui/alignmentEditor.js';`
- Replace `initLeafTree(config);` with `initAlignmentEditor(config);`

In `drawerDetails.js`, in the `.oer-anchor-edit` click handler, after `section.dataset.mode = 'edit';` add:

```js
        // TASK-065 part B (spec B1): the sidebar widens while editing; the
        // next render (Confirm, Cancel, undo) brings it back.
        const sidebar = document.getElementById('oer-detail-sidebar');
        if (sidebar) {
            sidebar.classList.add('oer-editing');
        }
```

and change `const first = section.querySelector('.oer-term-search');` to
`const first = section.querySelector('.oer-align-course-search input, .oer-term-search');`.

At the top of the `DRAWER_RENDERED` listener, add:

```js
        const sidebar = document.getElementById('oer-detail-sidebar');
        if (sidebar) {
            sidebar.classList.remove('oer-editing');
        }
```

Then run `git rm asset/js/ui/leafTree.js`. Check that `grep -rn "leafTree.js\|mountLeafTrees\|initLeafTree\|refreshTrees" asset/js` prints nothing.

- [ ] **Step 4: CSS** (append to `asset/css/oer-master-view.css`)

```css
/* ------------------------------------------------------------------
   TASK-065 part B: the re-cataloguer as a tree (spec B1–B7). Native buttons,
   inputs and checkboxes; only the panel's own tokens.
   ------------------------------------------------------------------ */

/* The sidebar widens while editing (spec B1); any re-render brings it back. */
#oer-detail-sidebar.oer-editing {
    width: clamp(32rem, 62vw, 64rem);
}

.oer-recatalog-ai-bar {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5em;
    margin-bottom: 1em;
}

.oer-recatalog-ai-bar .button,
.oer-align-editor .button,
.oer-recatalog-footer .button {
    margin: 0;
}

.oer-align-heading {
    margin: 0 0 0.5em;
    font-size: 0.8em;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--oer-ink);
}

.oer-term-searchbox {
    position: relative;
    max-width: 34em;
    margin-bottom: 0.75em;
}

.oer-term-searchbox > label {
    display: block;
    margin-bottom: 0.3em;
    color: var(--oer-muted);
    font-size: 0.9em;
}

.oer-term-searchbox-input {
    width: 100%;
}

.oer-term-searchbox-results {
    position: absolute;
    z-index: 1100;
    left: 0;
    right: 0;
    max-height: 18em;
    overflow-y: auto;
    margin: 0;
    padding: 0;
    list-style: none;
    background: #fff;
    box-shadow: 0 0 0 1px var(--oer-rule), 0 6px 16px rgba(43, 50, 64, 0.12);
}

.oer-term-searchbox-result {
    display: block;
    width: 100%;
    margin: 0;
    padding: 0.4em 0.6em;
    text-align: left;
    background: transparent;
    box-shadow: none;
    border-radius: 0;
}

.oer-term-searchbox-result:hover,
.oer-term-searchbox-result:focus {
    background: rgba(0, 0, 0, 0.06);
}

.oer-term-searchbox-result .oer-term-lineage {
    display: block;
    font-size: 0.85em;
    color: var(--oer-muted);
}

.oer-align-tree,
.oer-align-subjects {
    list-style: none;
    margin: 0;
    padding: 0;
}

.oer-align-course {
    border-top: 1px solid var(--oer-rule);
    padding: 0.5em 0;
}

.oer-align-subjects {
    margin-left: 1.2em;
}

.oer-align-subject {
    padding: 0.35em 0;
}

.oer-align-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5em;
}

.oer-align-toggle {
    margin: 0;
    padding: 0;
    min-height: 0;
    background: transparent;
    box-shadow: none;
    color: var(--oer-ink);
    text-align: left;
}

.oer-align-toggle::before {
    content: "▸";
    display: inline-block;
    width: 1.1em;
    color: var(--oer-muted);
}

.oer-align-toggle[aria-expanded="true"]::before {
    content: "▾";
}

.oer-align-course > .oer-align-row .oer-align-name {
    font-weight: 600;
}

.oer-align-summary {
    color: var(--oer-muted);
}

/* Secondary, like «Deshacer»: the native height, an inset outline. */
.oer-align-remove,
.oer-align-add-course,
.oer-recatalog-cancel,
.oer-recatalog-ai-cancel {
    background: transparent;
    border: 0;
    box-shadow: 0 0 0 1px var(--oer-rule) inset;
    color: var(--oer-ink);
}

.oer-align-row .oer-align-remove {
    margin-left: auto;
    min-height: 0;
    padding: 2px 8px;
    font-size: 0.85em;
}

.oer-align-body {
    margin: 0.4em 0 0.2em 1.1em;
}

.oer-align-leaves {
    margin-bottom: 0.75em;
}

.oer-align-leaves-label {
    margin: 0 0 0.3em;
    color: var(--oer-muted);
    font-size: 0.9em;
}

.oer-align-unfitted {
    margin: 1em 0;
    padding: 0.5em 0.9em;
    background: #fff;
    border-left: 3px solid var(--oer-warn);
}

.oer-align-unfitted h5 {
    margin: 0 0 0.3em;
    font-size: 0.8em;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--oer-warn);
}

.oer-align-unfitted ul {
    margin: 0;
    padding-left: 1.2em;
}

.oer-align-unfitted li {
    margin-bottom: 0.35em;
}

.oer-align-unfitted .button {
    min-height: 0;
    padding: 2px 8px;
    font-size: 0.85em;
    margin-left: 0.4em;
}

/* «IA» on what the proposal added (spec B5): shape and text, not colour only. */
.oer-ai-mark {
    display: inline-block;
    margin-left: 0.4em;
    padding: 0 5px;
    border: 1px solid currentColor;
    border-radius: 9px;
    font-size: 11px;
    font-weight: 600;
    color: var(--oer-accent);
}

.oer-ai-wrap {
    display: inline;
}

.oer-ai-why {
    display: inline-block;
    margin-left: 0.4em;
    font-size: 0.85em;
}

.oer-ai-why > summary {
    cursor: pointer;
    color: var(--oer-muted);
}

/* Sticky footer (spec B6): Previsualizar, Confirmar, Cancelar and the diff
   stay in sight while the tree scrolls. */
.oer-recatalog-footer {
    position: sticky;
    bottom: 0;
    z-index: 2;
    margin-top: 1em;
    padding: 0.6em 0;
    background: #fff;
    border-top: 1px solid var(--oer-rule);
}

.oer-recatalog-footer .oer-recatalog-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5em;
}

.oer-recatalog-footer .oer-recatalog-diff {
    max-height: 14em;
    overflow-y: auto;
}

.oer-diff-section {
    margin-top: 0.5em;
}

.oer-diff-heading {
    margin: 0;
    font-weight: 600;
}

.oer-diff-section .oer-diff-row {
    margin: 0 0 0 1em;
}
```

- [ ] **Step 5: Strings**

In `config/module.config.php`, in `js_translate_strings`, before the `// TASK-035` line, add the ones that are not there yet. Check each with `grep -n "'<string>'" config/module.config.php`.

```php
        // TASK-065 part B: the re-cataloguer as a tree.
        'Cursos', // @translate
        'Añadir curso', // @translate
        'Añadir materia de', // @translate
        'Curso añadido:', // @translate
        'Materia añadida:', // @translate
        'Quitar curso', // @translate
        'Quitar materia', // @translate
        'Quitar', // @translate
        'Quitado:', // @translate
        '¿Quitar', // @translate
        'y sus', // @translate
        'términos?', // @translate
        'materia', // @translate
        'materias', // @translate
        'saber', // @translate
        'saberes', // @translate
        'criterio', // @translate
        'criterios', // @translate
        'Sin encajar', // @translate
        'No cuelgan de un curso o una materia elegidos. Quítalos o añade lo que les falta.', // @translate
        'Añadir su curso', // @translate
        'Añade un curso para empezar.', // @translate
        'IA', // @translate
        'Sustituto:', // @translate
        '1 resultado', // @translate
        'resultados', // @translate
        'inválido:', // @translate
```

- [ ] **Step 6: Verify**

Run: `node --check asset/js/ui/recatalog.js && node --check asset/js/ui/aiPropose.js && node --check asset/js/main.js && make lint && make test && make test-js`
Expected: all pass. There are no `leafTree.js` references left.

- [ ] **Step 7: Commit**

```bash
git add -A asset config
git commit -m "feat: the re-cataloguer uses the tree editor; sticky footer, grouped diff, wide sidebar (TASK-065 part B)

Model: Claude Opus 5.5"
```

---

### Task 8: Browser check, docs

**Files:**
- Throwaway (not committed): `test/tmp-alignment-editor.html`
- Modify: `docs/superpowers/specs/2026-11-18-task-065-panel-usability-design.md`, `docs/backlog.md`, `docs/project-memory.md`

- [ ] **Step 1: Headless check of the editor with stubbed endpoints**

Create `test/tmp-alignment-editor.html`:

```html
<!doctype html><html><head><meta charset="utf-8">
<link rel="stylesheet" href="/application/asset/css/style.css">
<link rel="stylesheet" href="/modules/OERManager/asset/css/oer-master-view.css">
<script src="/application/asset/vendor/jquery/jquery.min.js"></script>
<script>
window.Omeka = { jsTranslate: (s) => s };
const TREE = { trees: [
  { subject: { id: 10, title: 'Matemáticas · 1º ESO' }, course: { id: 1, title: '1º ESO' },
    'lrmi:teaches': [{ id: 99, title: 'Bloque', selectable: false, children: [{ id: 100, title: 'Números', selectable: true, children: [] }] }],
    'lrmi:assesses': [{ id: 98, title: 'Bloque C', selectable: false, children: [{ id: 200, title: 'CE1', selectable: true, children: [] }] }] },
  { subject: { id: 20, title: 'Lengua · 2º ESO' }, course: { id: 2, title: '2º ESO' }, 'lrmi:teaches': [], 'lrmi:assesses': [] }
], invalid: [], retired: [] };
$.getJSON = (url) => $.Deferred().resolve(url === '/tree' ? TREE : { results: [] }).promise();
</script>
</head><body><div id="host"></div><pre id="result"></pre>
<script type="module">
import { buildAlignmentEditor, openAlignmentEditor, editorPairs, initAlignmentEditor } from '/modules/OERManager/asset/js/ui/alignmentEditor.js';
const config = { curriculumTreeUrl: '/tree', searchTermsUrl: '/search' };
initAlignmentEditor(config);
const recatalog = { allowed: true, current: {
  'lrmi:educationalLevel': [{ id: 1, title: '1º ESO' }],
  'schema:about': [{ id: 10, title: 'Matemáticas · 1º ESO' }, { id: 20, title: 'Lengua · 2º ESO' }],
  'lrmi:teaches': [{ id: 100, title: 'Números' }, { id: 300, title: 'Retirado' }],
  'lrmi:assesses': [{ id: 200, title: 'CE1' }] } };
const $panel = $('<div class="oer-recatalog" data-item-id="7">');
$('#host').append($panel);
$panel.append(buildAlignmentEditor(config, $panel, recatalog));
openAlignmentEditor(config, $panel);
await new Promise((resolve) => setTimeout(resolve, 50));
const out = [];
out.push('courses=' + $panel.find('.oer-align-course').length + ' subjects=' + $panel.find('.oer-align-subject').length);
out.push('summary=' + $panel.find('.oer-align-subject .oer-align-summary').first().text());
out.push('unfitted=' + $panel.find('.oer-align-unfitted li').length);
out.push('pairs0=' + JSON.stringify(editorPairs($panel)));
const box = $panel.find('.oer-tree-check[value="100"]')[0];
box.checked = false;
$(box).trigger('change');
out.push('pairs1=' + JSON.stringify(editorPairs($panel)));
out.push('summary1=' + $panel.find('.oer-align-subject .oer-align-summary').first().text());
window.confirm = () => true;
$panel.find('.oer-align-subject .oer-align-remove').first().trigger('click');
out.push('subjects2=' + $panel.find('.oer-align-subject').length + ' pairs2=' + JSON.stringify(editorPairs($panel)));
document.getElementById('result').textContent = out.join('\n');
</script></body></html>
```

Run it with a fresh headless profile and read `#result`:

```bash
P=$(mktemp -d); "/Applications/Google Chrome.app/Contents/MacOS/Google Chrome" --headless=new --disable-gpu \
  --user-data-dir=$P --virtual-time-budget=5000 --dump-dom \
  http://localhost:8080/modules/OERManager/test/tmp-alignment-editor.html | sed -n '/<pre id="result">/,/<\/pre>/p'
```

Expected:
- `courses=1 subjects=1`;
- `summary= · 1 saber, 1 criterio`;
- `unfitted=2` (Lengua, and «Retirado»);
- `pairs0=[]`;
- `pairs1=[{"name":"alignment[lrmi:teaches][]","value":"300"}]`;
- `summary1= · 0 saberes, 1 criterio`;
- `subjects2=0`, and `pairs2` sends `schema:about` `20`, teaches `300` and an empty `lrmi:assesses`.

Then `rm test/tmp-alignment-editor.html`.

- [ ] **Step 2: Docs**

- Spec: add «Status (2026-11-20): part B implemented», and the rulings taken during implementation.
- Backlog TASK-065: «part B implemented on branch `feature/task-065b-alignment-tree` … pending the owner's review in the admin».
- `docs/project-memory.md`: one entry with the non-obvious lessons.

- [ ] **Step 3: Final checks and commit**

Run: `make lint && make test && make test-js`
Expected: all pass.

```bash
git add docs
git commit -m "docs: TASK-065 part B implemented; pending the owner's review

Model: Claude Opus 5.5"
```
