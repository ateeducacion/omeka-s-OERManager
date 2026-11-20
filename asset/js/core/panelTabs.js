/**
 * Tabs of the detail panel (TASK-065, spec A1–A2 revised 2026-11-19). Pure:
 * no DOM, so the choice of tab and the keyboard model are tested with
 * `node --test`; `ui/panelTabs.js` applies them.
 */

/** Tab ids, in the order the partial renders them. */
export const PANEL_TABS = Object.freeze(['curriculum', 'governance', 'resource', 'history']);

export const DEFAULT_TAB = 'curriculum';

/**
 * The tab to open: the remembered one when this panel has it, else the first.
 * @param {string|null} remembered
 * @param {string[]} available tab ids present in this panel, in order
 * @returns {string|null}
 */
export function initialTab(remembered, available) {
    if (remembered && available.includes(remembered)) {
        return remembered;
    }
    return available.length ? available[0] : null;
}

/**
 * ARIA tabs keyboard model: arrows wrap around, Home and End go to the ends.
 * @param {string} key KeyboardEvent.key
 * @param {number} current index of the focused tab
 * @param {number} count number of tabs
 * @returns {number|null} index to move to, or null when the key is not ours
 */
export function keyTarget(key, current, count) {
    if (count < 1) {
        return null;
    }
    switch (key) {
        case 'ArrowRight':
            return (current + 1) % count;
        case 'ArrowLeft':
            return (current - 1 + count) % count;
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}
