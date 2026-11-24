import { DRAWER_RENDERED } from './drawer.js';
import { WORKFLOW_CHANGED } from './workflowDrawer.js';
import { leavesQueue, nextOpenItemId, lowerCount } from '../core/queueNav.js';

/**
 * TASK-070: after «Confirmar y publicar», «Rechazar» or «Descartar» the row is
 * marked with its new state, «Cola (N)» drops by one, and the re-rendered
 * panel offers the next REA of the page («Siguiente»), or «Recargar la
 * lista» on the last row. Works under any filter, not only «En cola».
 */
const TABLE = '#oer-master-view-table';
// The «Cola (N)» entry of the admin navigation. Its href carries the queue
// filter (config/module.config.php, page `oer-queue`).
const QUEUE_LINK = '#menu a[href*="status=queue"]';

function rows() {
    return Array.from(document.querySelectorAll(`${TABLE} .oer-open-drawer[data-item-id]`))
        .map((opener) => ({
            id: opener.dataset.itemId,
            done: Boolean(opener.closest('tr')?.classList.contains('oer-row-done'))
        }));
}

function markRow(itemId, status) {
    const row = document.querySelector(`${TABLE} tr[data-resource-id="${itemId}"]`);
    if (!row) {
        return;
    }
    row.classList.add('oer-row-done');
    let note = row.querySelector('.oer-row-done-status');
    if (!note) {
        note = document.createElement('span');
        note.className = 'oer-row-done-status';
        const opener = row.querySelector('.oer-open-drawer');
        if (opener) {
            opener.after(note);
        }
    }
    note.textContent = status ? Omeka.jsTranslate(status) : '';
}

function lowerQueueLabel() {
    document.querySelectorAll(QUEUE_LINK).forEach((link) => {
        link.textContent = lowerCount(link.textContent);
    });
}

function nextButton(itemId) {
    const next = nextOpenItemId(rows(), itemId);
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'button oer-queue-next';
    if (null === next) {
        button.textContent = Omeka.jsTranslate('Recargar la lista');
        button.addEventListener('click', () => window.location.reload());
        return button;
    }
    button.textContent = Omeka.jsTranslate('Siguiente');
    button.addEventListener('click', () => {
        // The row's own trigger: the core opens the panel as for any click.
        const opener = document.querySelector(`${TABLE} .oer-open-drawer[data-item-id="${next}"]`);
        if (opener) {
            opener.click();
        }
    });
    return button;
}

// How long the panel has to re-render after an action before «Siguiente» is
// no longer offered (the re-render can fail, or find no row to reopen).
const PENDING_MS = 10000;

export function initQueueFlow() {
    let pending = null;

    document.addEventListener(WORKFLOW_CHANGED, (event) => {
        const { itemId, action, status, leftQueue } = event.detail;
        if (!itemId || !leavesQueue(action)) {
            return;
        }
        markRow(itemId, status);
        // The server says whether the REA left the queue: a public REA that
        // is rejected stays in it, a private draft discarded was never in it.
        if (leftQueue) {
            lowerQueueLabel();
        }
        pending = { itemId: String(itemId), at: Date.now() };
    });

    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, content } = event.detail;
        // One shot: whatever renders next consumes it, so a stale action never
        // adds «Siguiente» to a panel opened later.
        const wanted = pending;
        pending = null;
        if (null === wanted || String(itemId) !== wanted.itemId || Date.now() - wanted.at > PENDING_MS) {
            return;
        }
        const workflow = content.querySelector('.oer-panel-workflow');
        const button = nextButton(itemId);
        if (workflow) {
            workflow.appendChild(button);
        } else {
            content.prepend(button);
        }
        // Next tick: something else in the sidebar's synchronous load path
        // moves focus to the body after this listener (seen in the browser).
        window.setTimeout(() => button.focus(), 0);
    });
}
