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
