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
    const $header = $('<div>').addClass('oer-ae-header')
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
            placeholder: t('Añadir curso…'),
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
    $editor.append($('<div>').addClass('oer-ae-footer oer-recatalog-footer')
        .append($('<p>').addClass('oer-ae-changes'))
        .append($('<p>').addClass('oer-recatalog-diff oer-ae-footer-status').attr({ role: 'status', 'aria-live': 'polite' }))
        .append($('<div>').addClass('oer-ae-footer-actions')
            .append(button('button oer-recatalog-cancel oer-ae-quiet', t('Cancelar')))
            .append(button('button oer-recatalog-preview oer-ae-primary', t('Revisar cambios')))));

    // Review step (spec B8): the grouped diff and the only «Confirmar».
    const reviewHeadingId = domId($panel, ['review', 'heading']);
    $editor.append($('<section>').addClass('oer-ae-review').attr('aria-labelledby', reviewHeadingId).prop('hidden', true)
        .append($('<div>').addClass('oer-ae-review-head')
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
    // The focused subject is decided before anything is drawn: the navigator
    // marks it (aria-current) and the detail shows it.
    view.subject = focusSubject(layout, view.subject);
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
    view.subject = focusSubject(layout, view.subject);
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
