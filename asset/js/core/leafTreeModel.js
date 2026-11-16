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
