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
