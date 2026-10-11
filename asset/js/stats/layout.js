/**
 * Layout of «Conteo por dimensión» (TASK-047 revision, 2026-10-11): card order
 * and collapsed stages per card. Pure: every function returns a new value.
 * sanitizeLayout() follows StatsLayout::sanitize() on the server, which is the
 * one that decides what is stored.
 */

export const DEFAULT_ORDER = ['etapa', 'materia', 'eje', 'proyecto', 'licencia', 'completitud'];
export const COLLAPSIBLE = ['etapa', 'materia'];

const STAGE_KEY = /^(?:[1-9]\d{0,8}|none)$/;

export function defaultLayout() {
    return { order: [...DEFAULT_ORDER], collapsed: Object.fromEntries(COLLAPSIBLE.map((card) => [card, []])) };
}

export function sanitizeLayout(raw) {
    if (!raw || typeof raw !== 'object') {
        return defaultLayout();
    }
    const order = [];
    for (const card of Array.isArray(raw.order) ? raw.order : []) {
        if (typeof card === 'string' && DEFAULT_ORDER.includes(card) && !order.includes(card)) {
            order.push(card);
        }
    }
    for (const card of DEFAULT_ORDER) {
        if (!order.includes(card)) {
            order.push(card);
        }
    }
    const collapsed = {};
    for (const card of COLLAPSIBLE) {
        const keys = [];
        const given = raw.collapsed && Array.isArray(raw.collapsed[card]) ? raw.collapsed[card] : [];
        for (const value of given) {
            const key = Number.isInteger(value) ? String(value) : value;
            if (typeof key === 'string' && STAGE_KEY.test(key) && !keys.includes(key)) {
                keys.push(key);
            }
        }
        collapsed[card] = keys;
    }
    return { order, collapsed };
}

/** Moves a card one place earlier (delta -1) or later (+1); unchanged at the edges. */
export function moveCard(order, card, delta) {
    const from = order.indexOf(card);
    const to = from + delta;
    if (from < 0 || to < 0 || to >= order.length) {
        return [...order];
    }
    const out = [...order];
    out.splice(from, 1);
    out.splice(to, 0, card);
    return out;
}

/** Puts the dragged card right before the target card, or last when the target is null. */
export function moveCardBefore(order, card, target) {
    if (!order.includes(card) || card === target) {
        return [...order];
    }
    const out = order.filter((c) => c !== card);
    const at = target === null ? -1 : out.indexOf(target);
    out.splice(at < 0 ? out.length : at, 0, card);
    return out;
}

/** Key of a stage group: its id, or «none» for the group without a stage. */
export function stageKey(stageId) {
    return stageId === null || stageId === undefined ? 'none' : String(stageId);
}

export function isCollapsed(layout, card, key) {
    return (layout.collapsed[card] || []).includes(key);
}

export function toggleStage(layout, card, key) {
    const current = layout.collapsed[card] || [];
    const keys = current.includes(key) ? current.filter((k) => k !== key) : [...current, key];
    return { ...layout, collapsed: { ...layout.collapsed, [card]: keys } };
}

export function setAllStages(layout, card, keys, collapsed) {
    return { ...layout, collapsed: { ...layout.collapsed, [card]: collapsed ? [...keys] : [] } };
}
