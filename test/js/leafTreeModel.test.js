import test from 'node:test';
import assert from 'node:assert/strict';
import {
    TREE_DIMENSIONS,
    normalizeIds,
    treeRequest,
    requestKey,
    selectableIds,
    treeStats,
    treeViews,
    retiredById,
    chipMarks,
    normalizeFilterText,
    filterTrees,
    filterStatus
} from '../../asset/js/core/leafTreeModel.js';

const leaf = (id, notation, children = []) => ({
    id, parentId: 0, notation, title: notation + ' · texto', definition: 'Def ' + notation, selectable: true, children
});
const block = (id, notation, children) => ({
    id, parentId: 0, notation, title: notation + ' · bloque', definition: '', selectable: false, children
});

/** One course subject: two knowledge blocks (nested to depth 3) and one competence block. */
function maths() {
    return {
        subject: { id: 500, title: 'Matemáticas · 1º ESO' },
        'lrmi:teaches': [
            block(10, 'SMAT01SBI', [
                leaf(20, 'SMAT01SBI.1', [leaf(21, 'SMAT01SBI.1.1', [leaf(23, 'SMAT01SBI.1.1.1')])]),
                leaf(22, 'SMAT01SBI.2')
            ]),
            block(11, 'SMAT01SBII', [leaf(24, 'SMAT01SBII.1')])
        ],
        'lrmi:assesses': [block(30, 'SMAT01C1', [leaf(40, 'SMAT01CE1.1'), leaf(41, 'SMAT01CE1.2')])]
    };
}

function physics() {
    return {
        subject: { id: 600, title: 'Física · 1º BAC' },
        'lrmi:teaches': [block(70, 'BFIS01SBI', [leaf(80, 'BFIS01SBI.1')])],
        'lrmi:assesses': []
    };
}

test('the tree dimensions are knowledge and criteria', () => {
    assert.deepEqual(TREE_DIMENSIONS, ['lrmi:teaches', 'lrmi:assesses']);
});

test('ids are normalised to unique positive strings', () => {
    assert.deepEqual(normalizeIds([5, '5', ' 7 ', 0, -2, 'x', '08', null, 9]), ['5', '7', '9']);
    assert.deepEqual(normalizeIds(undefined), []);
});

test('the request carries the course subjects and the linked terms', () => {
    const request = treeRequest(['500', 500, 600], [40, '21', 'nope']);
    assert.deepEqual(request, { about: ['500', '600'], linked: ['40', '21'] });
    assert.equal(requestKey(request), requestKey(treeRequest([600, 500], [21, 40])));
    assert.notEqual(requestKey(request), requestKey(treeRequest([500], [21, 40])));
});

test('tree stats count blocks, selectable nodes and depth', () => {
    assert.deepEqual(treeStats(maths()['lrmi:teaches']), { blocks: 2, selectable: 5, maxDepth: 3 });
    assert.deepEqual(treeStats(maths()['lrmi:assesses']), { blocks: 1, selectable: 2, maxDepth: 1 });
    assert.deepEqual(treeStats([]), { blocks: 0, selectable: 0, maxDepth: 0 });
});

test('blocks are not selectable; any knowledge level is', () => {
    const ids = selectableIds({ trees: [maths()] }, 'lrmi:teaches');
    assert.deepEqual([...ids].sort(), ['20', '21', '22', '23', '24']);
    assert.ok(!ids.has('10'));
    assert.equal(selectableIds(null, 'lrmi:teaches').size, 0);
});

test('the view checks what the chips hold and opens the branches that have it', () => {
    const { multiple, trees } = treeViews({ trees: [maths()] }, 'lrmi:teaches', ['23', 22, '10']);
    assert.equal(multiple, false);
    assert.equal(trees[0].subject.title, 'Matemáticas · 1º ESO');
    const [first, second] = trees[0].nodes;
    // The block id among the chips does not check the block: blocks are never chosen.
    assert.equal(first.checked, false);
    assert.equal(first.selectable, false);
    assert.equal(first.selectedBelow, 2);
    assert.equal(first.expanded, true);
    const [s1, s2] = first.children;
    assert.equal(s1.checked, false);
    assert.equal(s1.expanded, true, 'a deep selection opens every ancestor');
    assert.equal(s1.children[0].children[0].checked, true);
    assert.equal(s2.checked, true);
    assert.equal(s2.expanded, false);
    assert.equal(second.expanded, false);
    assert.equal(second.selectedBelow, 0);
    assert.equal(s1.definition, 'Def SMAT01SBI.1');
    assert.equal(s1.title, 'SMAT01SBI.1 · texto');
});

test('several course subjects give one tree each, in request order', () => {
    const response = { trees: [maths(), physics()] };
    const views = treeViews(response, 'lrmi:teaches', ['80']);
    assert.equal(views.multiple, true);
    assert.deepEqual(views.trees.map((t) => t.subject.id), ['500', '600']);
    assert.equal(views.trees[1].nodes[0].children[0].checked, true);
    const criteria = treeViews(response, 'lrmi:assesses', []);
    assert.equal(criteria.trees[1].nodes.length, 0);
    assert.deepEqual(selectableIds(response, 'lrmi:teaches').has('80'), true);
});

test('no response means no trees', () => {
    assert.deepEqual(treeViews(null, 'lrmi:teaches', ['1']), { multiple: false, trees: [] });
});

test('retired linked terms keep their label and successors', () => {
    const retired = retiredById({ retired: [
        { id: 90, title: 'SMAT01SBI.9 · viejo', replacedBy: ['SMAT01SBI.2 · nuevo', ''] },
        { id: 91, title: 'Sin sustituto' }
    ] });
    assert.deepEqual(retired.get('90'), { title: 'SMAT01SBI.9 · viejo', replacedBy: ['SMAT01SBI.2 · nuevo'] });
    assert.deepEqual(retired.get('91'), { title: 'Sin sustituto', replacedBy: [] });
    assert.equal(retiredById(undefined).size, 0);
});

test('chips outside every tree are marked external, never dropped', () => {
    const response = {
        trees: [maths()],
        retired: [{ id: 90, title: 'Viejo', replacedBy: ['Nuevo'] }]
    };
    // 22 is in the tree; 90 is retired; 555 is an AI proposal of another subject.
    const marks = chipMarks(response, 'lrmi:teaches', ['22', '90', '555']);
    assert.equal(marks.has('22'), false);
    assert.deepEqual(marks.get('90'), { external: true, retired: { title: 'Viejo', replacedBy: ['Nuevo'] } });
    assert.deepEqual(marks.get('555'), { external: true, retired: null });
    // A criterion id is external to the knowledge tree and vice versa.
    assert.equal(chipMarks(response, 'lrmi:assesses', ['22']).get('22').external, true);
});

test('without a course subject only retired terms are marked', () => {
    const response = { trees: [], retired: [{ id: 90, title: 'Viejo', replacedBy: [] }] };
    const marks = chipMarks(response, 'lrmi:teaches', ['90', '555']);
    assert.equal(marks.get('90').retired.title, 'Viejo');
    assert.equal(marks.has('555'), false);
    assert.equal(chipMarks(null, 'lrmi:teaches', ['90']).size, 0);
});

// Text filter inside each tree (owner decision 2026-11-17).

test('filter text ignores case, accents and extra spaces', () => {
    assert.equal(normalizeFilterText('  Ácidos  y BASES Ñ '), 'acidos y bases n');
    assert.equal(normalizeFilterText(null), '');
});

test('an empty filter is inactive and hides nothing', () => {
    const result = filterTrees({ trees: [maths()] }, 'lrmi:teaches', '   ');
    assert.equal(result.active, false);
    assert.equal(result.matches, 0);
    assert.equal(result.visible.size, 0);
    assert.equal(result.expanded.size, 0);
});

test('a match shows its ancestors opened and hides the rest', () => {
    const response = { trees: [maths()] };
    // Notation of a depth-3 node, typed without the dots' case.
    const result = filterTrees(response, 'lrmi:teaches', 'smat01sbi.1.1.1');
    assert.equal(result.active, true);
    assert.equal(result.matches, 1);
    assert.deepEqual([...result.visible].sort(), ['10', '20', '21', '23']);
    assert.deepEqual([...result.expanded].sort(), ['10', '20', '21']);
});

test('the filter reads notation, label and definition, accent-insensitive', () => {
    const tree = {
        subject: { id: 1, title: 'S' },
        'lrmi:teaches': [{
            id: 10, notation: 'B1', title: 'B1 · Números', definition: '', selectable: false, children: [
                { id: 11, notation: 'X1', title: 'X1 · Fracciones', definition: 'Operación con números', selectable: true, children: [] },
                { id: 12, notation: 'X2', title: 'X2 · Geometría', definition: 'Ángulos', selectable: true, children: [] }
            ]
        }]
    };
    const response = { trees: [tree] };
    assert.deepEqual([...filterTrees(response, 'lrmi:teaches', 'ANGULOS').visible].sort(), ['10', '12']);
    assert.deepEqual([...filterTrees(response, 'lrmi:teaches', 'fracciones').visible].sort(), ['10', '11']);
    // Every word must appear somewhere in the node's text.
    assert.equal(filterTrees(response, 'lrmi:teaches', 'operacion numeros').matches, 1);
    assert.equal(filterTrees(response, 'lrmi:teaches', 'operacion angulos').matches, 0);
});

test('a matching branch keeps its subtree visible, closed, for context', () => {
    // The block label matches: its children stay reachable but the block is not opened.
    const result = filterTrees({ trees: [maths()] }, 'lrmi:teaches', 'SMAT01SBII');
    assert.equal(result.matches, 2, 'the block and its knowledge item both match');
    assert.deepEqual([...result.visible].sort(), ['11', '24']);
    assert.deepEqual([...result.expanded], ['11']);
    const block = filterTrees({ trees: [maths()] }, 'lrmi:teaches', 'bloque');
    assert.equal(block.matches, 2);
    assert.ok(block.visible.has('23'), 'descendants of a match are visible');
    assert.equal(block.expanded.size, 0, 'a match alone opens nothing');
});

test('no match hides everything; several subjects are searched together', () => {
    const response = { trees: [maths(), physics()] };
    const none = filterTrees(response, 'lrmi:teaches', 'zzz');
    assert.deepEqual([none.active, none.matches, none.visible.size], [true, 0, 0]);
    const both = filterTrees(response, 'lrmi:teaches', '.1');
    assert.ok(both.visible.has('80') && both.visible.has('20'));
    assert.equal(filterTrees(response, 'lrmi:assesses', 'CE1.2').matches, 1);
    assert.equal(filterTrees(null, 'lrmi:teaches', 'x').matches, 0);
});

test('the filter status counts matches or says there are none', () => {
    const t = (s) => '[' + s + ']';
    assert.equal(filterStatus({ active: false, matches: 0 }, t), '');
    assert.equal(filterStatus({ active: true, matches: 0 }, t), '[Ningún término coincide con el filtro.]');
    assert.equal(filterStatus({ active: true, matches: 1 }, t), '[1 coincidencia]');
    assert.equal(filterStatus({ active: true, matches: 12 }, t), '12 [coincidencias]');
});
