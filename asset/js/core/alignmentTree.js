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

/** Ids added and removed between two sets (ids compared as strings). */
export function setDelta(before, after) {
    const was = (before || []).map(key);
    const now = (after || []).map(key);
    return {
        added: now.filter((id) => !was.includes(id)).length,
        removed: was.filter((id) => !now.includes(id)).length
    };
}

/**
 * What the preview says will change, every term included (the thematic axis
 * too): the review step's summary is the server's diff, not a local guess.
 */
export function diffCounts(diff) {
    let added = 0;
    let removed = 0;
    Object.values(diff || {}).forEach((entry) => {
        added += ((entry && entry.added) || []).length;
        removed += ((entry && entry.removed) || []).length;
    });
    return { added, removed };
}
