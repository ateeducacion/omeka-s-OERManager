/**
 * Working through the list after a workflow action (TASK-070). Pure: no DOM,
 * so `node --test` covers it; `ui/queueFlow.js` applies it to the page.
 */

/** Actions that take a REA out of the queue (ADR-0023 §2). */
export const QUEUE_ACTIONS = ['publish-proposal', 'reject-proposal', 'discard'];

/** Each panel form posts to `…/oer-manager/<action>`. */
export function actionOf(formAction) {
    const path = String(formAction || '').split('?')[0].replace(/\/+$/, '');
    return path.slice(path.lastIndexOf('/') + 1);
}

export function leavesQueue(action) {
    return QUEUE_ACTIONS.includes(action);
}

/**
 * @param {string[]} ids item ids of the page, in display order
 * @param {string|number} currentId
 * @returns {string|null} null on the last row or when the item is not listed
 */
export function nextItemId(ids, currentId) {
    const at = ids.indexOf(String(currentId));
    if (at < 0 || at === ids.length - 1) {
        return null;
    }
    return ids[at + 1];
}

/**
 * Like `nextItemId()`, but skips rows the curator has already handled on this
 * page (TASK-070 review): going back to an earlier row must not send
 * «Siguiente» to one that is done.
 *
 * @param {{id: string, done: boolean}[]} rows rows of the page, in display order
 * @param {string|number} currentId
 * @returns {string|null} null when no row after the current one is still open
 */
export function nextOpenItemId(rows, currentId) {
    const at = rows.findIndex((row) => row.id === String(currentId));
    if (at < 0) {
        return null;
    }
    const next = rows.slice(at + 1).find((row) => !row.done);
    return next ? next.id : null;
}

/**
 * How «Cola (N)» moves after a workflow action, from what the server says
 * (TASK-070): -1 when the REA left the queue, +1 when «Reabrir» or
 * «Despublicar» brought it back, 0 otherwise.
 */
export function countDelta({ leftQueue = false, enteredQueue = false } = {}) {
    if (leftQueue) {
        return -1;
    }
    return enteredQueue ? 1 : 0;
}

/** «Cola (12)» → «Cola (13)» with +1; never below zero; a label without a trailing count is kept. */
export function adjustCount(label, delta) {
    return String(label).replace(/\((\d+)\)\s*$/, (match, count) => `(${Math.max(0, Number(count) + delta)})`);
}

/** «Cola (12)» → «Cola (11)». */
export function lowerCount(label) {
    return adjustCount(label, -1);
}
