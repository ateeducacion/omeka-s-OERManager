import {
    TREE_DIMENSIONS,
    treeRequest,
    requestKey,
    treeViews,
    chipMarks
} from '../core/leafTreeModel.js';
import { addChip, chipIdsByTerm, markDirty, CHIPS_CHANGED } from './termPicker.js';

/**
 * Knowledge and criteria trees of the re-cataloguing panel (TASK-064 slice 4,
 * owner decision): once the course subject (`schema:about`) is chosen, its
 * knowledge and criteria are chosen with checkboxes in a tree per block. What
 * to draw is decided by core/leafTreeModel.js; this file only builds the DOM.
 *
 * The chips stay the selection: a checkbox adds or removes a chip, and every
 * change of chips (picker, AI pre-fill, a chip's ×) re-checks the tree. So
 * preview, apply, undo and the AI keep reading and writing chips as before.
 *
 * Accessibility (NFR-006, the admin's level): nested lists of labelled native
 * checkboxes, and disclosure buttons with `aria-expanded`/`aria-controls`.
 * Tab and Space work natively; ArrowRight/ArrowLeft open and close a branch
 * from its button. Loaded lazily (NFR-004): only when the editor opens and
 * the course subjects change, one bounded request per change.
 */

const TREE_LOADED = 'oerTreeKey';

let requestSeq = 0;

function t(text) {
    return Omeka.jsTranslate(text);
}

/** Replaces the autocomplete of knowledge and criteria with a tree host. */
export function mountLeafTrees($panel) {
    TREE_DIMENSIONS.forEach((term) => {
        const $dim = $panel.find(`.oer-recatalog-dim[data-term="${term}"]`);
        if (!$dim.length) {
            return;
        }
        $dim.find('.search-field').remove();
        $dim.find('.chosen-drop').remove();
        $dim.append($('<div>').addClass('oer-leaf-tree')
            .append($('<p>').addClass('oer-leaf-tree-note').text(t(
                'Elige primero la asignatura (materia en curso) para ver sus saberes y criterios.'
            ))));
    });
}

function setNote($host, text) {
    $host.empty().append($('<p>').addClass('oer-leaf-tree-note').text(text));
}

function domId($panel, parts) {
    return ['oer-tree', $panel.data('item-id')].concat(parts).join('-');
}

function toggleBranch($button, open) {
    $button.attr('aria-expanded', open ? 'true' : 'false');
    $('#' + $button.attr('aria-controls')).prop('hidden', !open);
}

function countLabel(count) {
    return count ? ' (' + count + ' ' + t('seleccionados') + ')' : '';
}

function buildNode($panel, term, subjectId, node) {
    const $li = $('<li>').addClass('oer-tree-node').attr('data-id', node.id);
    const childrenId = domId($panel, [term.replace(/\W/g, ''), subjectId, node.id, 'children']);
    const hasChildren = node.children.length > 0;

    if (!node.selectable) {
        // A block is a heading that opens and closes, never a choice.
        const $button = $('<button>').attr('type', 'button')
            .addClass('oer-tree-toggle oer-tree-block')
            .attr('aria-expanded', node.expanded ? 'true' : 'false')
            .attr('aria-controls', childrenId)
            .append($('<span>').addClass('oer-tree-block-title').text(node.title))
            .append($('<span>').addClass('oer-tree-count').text(countLabel(node.selectedBelow)));
        $li.addClass('oer-tree-block-node').append($button);
    } else {
        const checkId = domId($panel, [term.replace(/\W/g, ''), subjectId, node.id]);
        if (hasChildren) {
            $li.append($('<button>').attr('type', 'button').addClass('oer-tree-toggle oer-tree-twisty')
                .attr('aria-expanded', node.expanded ? 'true' : 'false')
                .attr('aria-controls', childrenId)
                .attr('aria-label', t('Desplegar') + ' ' + (node.notation || node.title)));
        }
        const $check = $('<input>').attr({ type: 'checkbox', id: checkId })
            .addClass('oer-tree-check')
            .attr('value', node.id)
            .attr('data-title', node.title)
            .prop('checked', node.checked);
        const $label = $('<label>').attr('for', checkId).text(node.title);
        if (node.definition) {
            // The prefLabel is truncated; the whole text is the definition.
            const defId = checkId + '-def';
            $label.attr('title', node.definition);
            $check.attr('aria-describedby', defId);
            $li.append($check, $label, $('<span>').attr('id', defId).addClass('oer-visually-hidden')
                .text(node.definition));
        } else {
            $li.append($check, $label);
        }
    }
    if (hasChildren) {
        const $ul = $('<ul>').addClass('oer-tree-children').attr('id', childrenId)
            .prop('hidden', !node.expanded);
        node.children.forEach((child) => $ul.append(buildNode($panel, term, subjectId, child)));
        $li.append($ul);
    }
    return $li;
}

function renderDimension($panel, term, response) {
    const $dim = $panel.find(`.oer-recatalog-dim[data-term="${term}"]`);
    const $host = $dim.find('.oer-leaf-tree');
    if (!$host.length) {
        return;
    }
    const selected = chipIdsByTerm($panel)[term] || [];
    if (response.error) {
        setNote($host, t('No se pudo cargar el árbol del currículo.'));
        return;
    }
    const { multiple, trees } = treeViews(response, term, selected);
    if (!trees.length) {
        setNote($host, (response.invalid || []).length
            ? t('La asignatura elegida no es una materia en curso del currículo.')
            : t('Elige primero la asignatura (materia en curso) para ver sus saberes y criterios.'));
        return;
    }
    $host.empty();
    trees.forEach((tree) => {
        const $group = $('<div>').addClass('oer-leaf-tree-group');
        const listLabel = $dim.children('label').first().text() + ' · ' + tree.subject.title;
        if (multiple) {
            $group.append($('<p>').addClass('oer-leaf-tree-subject').text(tree.subject.title));
        }
        if (!tree.nodes.length) {
            $group.append($('<p>').addClass('oer-leaf-tree-note')
                .text(t('Sin términos vigentes en esta asignatura.')));
        } else {
            const $list = $('<ul>').addClass('oer-tree-root').attr('aria-label', listLabel);
            tree.nodes.forEach((node) => $list.append(buildNode($panel, term, tree.subject.id, node)));
            $group.append($list);
        }
        $host.append($group);
    });
    if ((response.invalid || []).length) {
        $host.append($('<p>').addClass('oer-leaf-tree-note')
            .text(t('Alguna asignatura elegida no es una materia en curso del currículo y no tiene árbol.')));
    }
}

/**
 * Marks the chips the tree cannot show: retired terms (with the successor,
 * like the integrity warning) and ids outside every loaded tree.
 */
function markChips($panel, term, response) {
    const $dim = $panel.find(`.oer-recatalog-dim[data-term="${term}"]`);
    const selected = chipIdsByTerm($panel)[term] || [];
    const marks = chipMarks(response, term, selected);
    $dim.find('.chosen-choices .search-choice').each(function () {
        const $chip = $(this);
        const mark = marks.get(String($chip.attr('data-id')));
        $chip.removeClass('oer-chip-retired oer-chip-external').removeAttr('title');
        $chip.find('.oer-chip-mark').remove();
        if (!mark) {
            return;
        }
        if (mark.retired) {
            const note = mark.retired.replacedBy.length
                ? t('Término retirado del currículo. Sustituto:') + ' ' + mark.retired.replacedBy.join(', ')
                : t('Término retirado del currículo sin sustituto.');
            $chip.addClass('oer-chip-retired').attr('title', note);
            $chip.children('span').first().after($('<span>').addClass('oer-chip-mark')
                .text(' · ' + t('retirado'))
                .append($('<span>').addClass('oer-visually-hidden').text('. ' + note)));
        } else {
            $chip.addClass('oer-chip-external').attr('title', t('No está en el árbol de la asignatura elegida.'));
            $chip.children('span').first().after($('<span>').addClass('oer-chip-mark')
                .text(' · ' + t('fuera del árbol')));
        }
    });
}

/** Re-checks the boxes and counters of one dimension from its chips. */
function syncDimension($panel, term) {
    const response = $panel.data('oerTreeResponse');
    if (!response) {
        return;
    }
    const $dim = $panel.find(`.oer-recatalog-dim[data-term="${term}"]`);
    const selected = new Set((chipIdsByTerm($panel)[term] || []).map(String));
    $dim.find('.oer-tree-check').each(function () {
        this.checked = selected.has(String(this.value));
    });
    $dim.find('.oer-tree-node').each(function () {
        const $node = $(this);
        const below = $node.find('.oer-tree-children .oer-tree-check:checked').length;
        $node.children('.oer-tree-block').find('.oer-tree-count').text(countLabel(below));
    });
    markChips($panel, term, response);
}

/** Loads the trees for the course subjects the chips hold now. */
export function refreshTrees(config, $panel) {
    const ids = chipIdsByTerm($panel);
    const request = treeRequest(
        ids['schema:about'],
        TREE_DIMENSIONS.reduce((all, term) => all.concat(ids[term] || []), [])
    );
    const key = requestKey(request);
    if ($panel.data(TREE_LOADED) === key) {
        return;
    }
    $panel.data(TREE_LOADED, key);
    const seq = ++requestSeq;
    $panel.attr('data-tree-seq', seq);
    if (request.about.length) {
        TREE_DIMENSIONS.forEach((term) => setNote(
            $panel.find(`.oer-recatalog-dim[data-term="${term}"] .oer-leaf-tree`),
            t('Cargando…')
        ));
    }
    const done = (response) => {
        // A later change of subjects won: this answer is stale.
        if (String($panel.attr('data-tree-seq')) !== String(seq)) {
            return;
        }
        $panel.data('oerTreeResponse', response);
        TREE_DIMENSIONS.forEach((term) => {
            renderDimension($panel, term, response);
            markChips($panel, term, response);
        });
    };
    if (!request.about.length && !request.linked.length) {
        done({ trees: [], invalid: [], retired: [] });
        return;
    }
    $.getJSON(config.curriculumTreeUrl, request).done(done).fail(() => {
        $panel.removeData(TREE_LOADED);
        done({ error: 'network', trees: [], invalid: [], retired: [] });
    });
}

export function initLeafTree(config) {
    if (!config.curriculumTreeUrl) {
        return;
    }
    // Opening the editor is the first moment the trees are needed.
    $(document).on('click', '.oer-anchor-edit', function () {
        const $panel = $(this).closest('.oer-area-alignment').find('.oer-recatalog');
        if ($panel.length) {
            $panel.data('oerTreeActive', true);
            refreshTrees(config, $panel);
        }
    });

    $(document).on(CHIPS_CHANGED, '.oer-recatalog-dim', function () {
        const $dim = $(this);
        const $panel = $dim.closest('.oer-recatalog');
        if (!$panel.data('oerTreeActive')) {
            return;
        }
        const term = $dim.data('term');
        if ('schema:about' === term) {
            refreshTrees(config, $panel);
        } else if (-1 !== TREE_DIMENSIONS.indexOf(term)) {
            syncDimension($panel, term);
        }
    });

    $(document).on('change', '.oer-recatalog .oer-tree-check', function () {
        const $check = $(this);
        const $dim = $check.closest('.oer-recatalog-dim');
        const id = String($check.val());
        if (this.checked) {
            addChip($dim, id, $check.attr('data-title'));
            return;
        }
        $dim.find('.chosen-choices .search-choice').filter(function () {
            return String($(this).attr('data-id')) === id;
        }).remove();
        markDirty($dim);
        $dim.trigger(CHIPS_CHANGED);
    });

    $(document).on('click', '.oer-recatalog .oer-tree-toggle', function () {
        const $button = $(this);
        toggleBranch($button, 'true' !== $button.attr('aria-expanded'));
    });

    $(document).on('keydown', '.oer-recatalog .oer-tree-toggle', function (event) {
        if ('ArrowRight' === event.key || 'ArrowLeft' === event.key) {
            event.preventDefault();
            toggleBranch($(this), 'ArrowRight' === event.key);
        }
    });
}
