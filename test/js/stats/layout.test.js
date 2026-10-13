import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
    DEFAULT_ORDER,
    defaultLayout,
    sanitizeLayout,
    moveCard,
    moveCardBefore,
    stageKey,
    isCollapsed,
    toggleStage,
    setAllStages
} from '../../../asset/js/stats/layout.js';

// TASK-047 revision (2026-10-11): movable cards and collapsible stages in «Conteo por dimensión».

test('defaultLayout es el orden actual con todo desplegado', () => {
    assert.deepEqual(DEFAULT_ORDER, ['etapa', 'materia', 'eje', 'proyecto', 'licencia', 'completitud']);
    assert.deepEqual(defaultLayout(), { order: DEFAULT_ORDER, collapsed: { etapa: [], materia: [] } });
});

test('sanitizeLayout sigue la regla de StatsLayout::sanitize() del servidor', () => {
    const layout = sanitizeLayout({
        order: ['licencia', 'x', 'licencia', 3],
        collapsed: { etapa: ['3', 7, 'none', '0', '<b>'], eje: ['1'] }
    });
    assert.deepEqual(layout.order, ['licencia', 'etapa', 'materia', 'eje', 'proyecto', 'completitud']);
    assert.deepEqual(layout.collapsed, { etapa: ['3', '7', 'none'], materia: [] });
    assert.deepEqual(sanitizeLayout(null), defaultLayout());
});

test('moveCard sube o baja una tarjeta y no sale de los extremos', () => {
    assert.deepEqual(moveCard(DEFAULT_ORDER, 'eje', -1).slice(0, 3), ['etapa', 'eje', 'materia']);
    assert.deepEqual(moveCard(DEFAULT_ORDER, 'eje', 1).slice(2, 4), ['proyecto', 'eje']);
    assert.deepEqual(moveCard(DEFAULT_ORDER, 'etapa', -1), DEFAULT_ORDER);
    assert.deepEqual(moveCard(DEFAULT_ORDER, 'completitud', 1), DEFAULT_ORDER);
    assert.deepEqual(moveCard(DEFAULT_ORDER, 'nope', 1), DEFAULT_ORDER);
});

test('moveCardBefore coloca la tarjeta arrastrada delante de la de destino (o al final)', () => {
    assert.deepEqual(moveCardBefore(DEFAULT_ORDER, 'licencia', 'materia'),
        ['etapa', 'licencia', 'materia', 'eje', 'proyecto', 'completitud']);
    assert.deepEqual(moveCardBefore(DEFAULT_ORDER, 'etapa', null),
        ['materia', 'eje', 'proyecto', 'licencia', 'completitud', 'etapa']);
    assert.deepEqual(moveCardBefore(DEFAULT_ORDER, 'eje', 'eje'), DEFAULT_ORDER);
});

test('stageKey usa el id de la etapa y «none» para el grupo sin etapa', () => {
    assert.equal(stageKey(3), '3');
    assert.equal(stageKey(null), 'none');
});

test('toggleStage y setAllStages no mutan y solo tocan la tarjeta indicada', () => {
    const start = defaultLayout();
    const folded = toggleStage(start, 'etapa', '3');
    assert.ok(isCollapsed(folded, 'etapa', '3'));
    assert.ok(!isCollapsed(start, 'etapa', '3'));
    assert.ok(!isCollapsed(folded, 'materia', '3'));
    assert.ok(!isCollapsed(toggleStage(folded, 'etapa', '3'), 'etapa', '3'));

    const all = setAllStages(folded, 'materia', ['2', '3', 'none'], true);
    assert.deepEqual(all.collapsed.materia, ['2', '3', 'none']);
    assert.deepEqual(all.collapsed.etapa, ['3']);
    assert.deepEqual(setAllStages(all, 'materia', ['2', '3', 'none'], false).collapsed.materia, []);
});
