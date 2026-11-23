/**
 * Knowledge and criteria trees of the re-cataloguing panel (TASK-064 slice 4).
 * Pure core: no DOM, no jQuery, so `node --test` covers it.
 *
 * The server (`curriculum-tree`) sends one tree per chosen course subject:
 * blocks as roots (never selectable) and knowledge or criteria below them,
 * selectable at any depth, only current terms. The chips of the panel stay the
 * single source of the selection — preview, apply, undo and the AI pre-fill
 * read and write chips — and the tree is a view over them: it checks what the
 * chips hold, and a selected id that is in no tree (a retired term, an AI
 * proposal for another subject, a legacy link) is reported, never dropped.
 */

/** The dimensions chosen in a tree, in panel order. */
export const TREE_DIMENSIONS = ['lrmi:teaches', 'lrmi:assesses'];

/** Unique, positive ids as strings (chips keep ids as strings). */
export function normalizeIds(ids) {
    const out = [];
    (ids || []).forEach((raw) => {
        const id = String(raw).trim();
        if (/^[1-9][0-9]*$/.test(id) && -1 === out.indexOf(id)) {
            out.push(id);
        }
    });
    return out;
}

/**
 * Query of the tree request: the chosen course subjects and the linked
 * knowledge/criteria (the server marks the retired ones).
 */
export function treeRequest(aboutIds, linkedIds) {
    return { about: normalizeIds(aboutIds), linked: normalizeIds(linkedIds) };
}

/** Stable key of a request, to skip a reload when nothing relevant changed. */
export function requestKey(request) {
    const sorted = (ids) => ids.slice().sort((a, b) => Number(a) - Number(b)).join(',');
    return sorted(request.about) + '|' + sorted(request.linked);
}

function walk(nodes, visit, depth = 1) {
    (nodes || []).forEach((node) => {
        visit(node, depth);
        walk(node.children, visit, depth + 1);
    });
}

/** Ids (strings) of the selectable nodes of one dimension, across every tree. */
export function selectableIds(response, dimension) {
    const ids = new Set();
    ((response && response.trees) || []).forEach((tree) => {
        walk(tree[dimension], (node) => {
            if (node.selectable) {
                ids.add(String(node.id));
            }
        });
    });
    return ids;
}

/**
 * Counts of one tree: blocks (roots that are not selectable), selectable
 * nodes, and the deepest selectable level below its block.
 */
export function treeStats(nodes) {
    let blocks = 0;
    let selectable = 0;
    let maxDepth = 0;
    walk(nodes, (node, depth) => {
        if (node.selectable) {
            selectable += 1;
            maxDepth = Math.max(maxDepth, depth - 1);
        } else {
            blocks += 1;
        }
    });
    return { blocks, selectable, maxDepth };
}

function viewNode(node, selected) {
    const children = (node.children || []).map((child) => viewNode(child, selected));
    const id = String(node.id);
    const checked = !!node.selectable && selected.has(id);
    const selectedBelow = children.reduce(
        (sum, child) => sum + child.selectedBelow + (child.checked ? 1 : 0),
        0
    );
    return {
        id,
        title: String(node.title || ''),
        notation: String(node.notation || ''),
        definition: String(node.definition || ''),
        selectable: !!node.selectable,
        checked,
        selectedBelow,
        // A branch with something chosen opens by itself: the curator sees
        // what the REA already has without unfolding every block.
        expanded: selectedBelow > 0,
        children
    };
}

/**
 * What to draw for one dimension: one entry per course subject, its nodes
 * with their checked state. `multiple` says whether to name each subject.
 */
export function treeViews(response, dimension, selectedIds) {
    const selected = new Set(normalizeIds(selectedIds));
    const trees = ((response && response.trees) || []).map((tree) => ({
        subject: {
            id: String((tree.subject && tree.subject.id) || ''),
            title: String((tree.subject && tree.subject.title) || '')
        },
        nodes: (tree[dimension] || []).map((node) => viewNode(node, selected))
    }));
    return { multiple: trees.length > 1, trees };
}

/** Retired linked terms by id: {title, replacedBy}. */
export function retiredById(response) {
    const map = new Map();
    ((response && response.retired) || []).forEach((term) => {
        map.set(String(term.id), {
            title: String(term.title || ''),
            replacedBy: (term.replacedBy || []).map(String).filter((label) => '' !== label.trim())
        });
    });
    return map;
}

/**
 * Marks of the chips of one dimension: a selected id that no loaded tree
 * holds is `external`; a retired one also carries its successors. Only marks
 * what needs one; without a loaded tree nothing is external (there is nothing
 * to compare against), but a retired term is still marked.
 */
export function chipMarks(response, dimension, selectedIds) {
    const marks = new Map();
    if (!response) {
        return marks;
    }
    const hasTrees = ((response.trees) || []).length > 0;
    const inTree = selectableIds(response, dimension);
    const retired = retiredById(response);
    normalizeIds(selectedIds).forEach((id) => {
        const term = retired.get(id);
        if (term) {
            marks.set(id, { external: true, retired: term });
        } else if (hasTrees && !inTree.has(id)) {
            marks.set(id, { external: true, retired: null });
        }
    });
    return marks;
}

/**
 * Text of a filter or of a node, compared without case, accents or extra
 * spaces (owner decision 2026-11-17: «ángulos» finds «Ángulos»).
 */
export function normalizeFilterText(text) {
    return String(text == null ? '' : text)
        .normalize('NFD')
        .replace(/[̀-ͯ]/g, '')
        .toLowerCase()
        .replace(/\s+/g, ' ')
        .trim();
}

function nodeMatches(node, words) {
    const haystack = normalizeFilterText(
        [node.notation, node.title, node.definition].filter((part) => part != null).join(' ')
    );
    return words.every((word) => -1 !== haystack.indexOf(word));
}

/**
 * Text filter of one dimension over the trees already loaded (no request).
 * A node matches when every word of the filter appears in its notation,
 * label (`skos:prefLabel`) or definition. What stays visible: the matches,
 * their ancestors (block and parent knowledge, opened to keep the context)
 * and the descendants of a match (closed: they belong to what was found).
 * Everything else is hidden; hiding never changes the selection.
 *
 * Returns {active, matches, visible: Set<id>, expanded: Set<id>}; an empty
 * filter is inactive and the caller restores the tree as it was.
 */
export function filterTrees(response, dimension, query) {
    const words = normalizeFilterText(query).split(' ').filter((word) => '' !== word);
    const result = { active: words.length > 0, matches: 0, visible: new Set(), expanded: new Set() };
    if (!result.active) {
        return result;
    }
    const showSubtree = (node) => walk([node], (inner) => result.visible.add(String(inner.id)));
    // True when the node or something below it matches.
    const visit = (node) => {
        const id = String(node.id);
        if (nodeMatches(node, words)) {
            result.matches += 1;
            showSubtree(node);
            // A match below a match still opens the path to it.
            const below = (node.children || []).map(visit).some(Boolean);
            if (below) {
                result.expanded.add(id);
            }
            return true;
        }
        const below = (node.children || []).map(visit).some(Boolean);
        if (below) {
            result.visible.add(id);
            result.expanded.add(id);
        }
        return below;
    };
    ((response && response.trees) || []).forEach((tree) => (tree[dimension] || []).forEach(visit));
    return result;
}

/** Text of the filter's live region; `t` translates (Omeka.jsTranslate). */
export function filterStatus(result, t) {
    if (!result || !result.active) {
        return '';
    }
    if (0 === result.matches) {
        return t('Ningún término coincide con el filtro.');
    }
    return 1 === result.matches ? t('1 coincidencia') : result.matches + ' ' + t('coincidencias');
}

const SUB_LABELS = Object.freeze({
    'lrmi:teaches': ['sub-saber', 'sub-saberes'],
    'lrmi:assesses': ['sub-criterio', 'sub-criterios']
});

/**
 * Under a knowledge item that groups others (TASK-065 B8 addendum, option B):
 * «4 sub-saberes · 2 elegidos», so what is chosen inside a closed group shows.
 * '' for a node without children.
 */
export function twistyNote(node, dimension, t) {
    const count = ((node && node.children) || []).length;
    if (!count) {
        return '';
    }
    const [one, many] = SUB_LABELS[dimension] || SUB_LABELS['lrmi:teaches'];
    const below = node.selectedBelow || 0;
    const text = `${count} ${t(1 === count ? one : many)}`;
    return below ? `${text} · ${below} ${t(1 === below ? 'elegido' : 'elegidos')}` : text;
}
