import { DRAWER_RENDERED } from './drawer.js';
import { WORKFLOW_CHANGED } from './workflowDrawer.js';
import { leavesQueue, nextItemId, lowerCount } from '../core/queueNav.js';

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

function rowIds() {
    return Array.from(document.querySelectorAll(`${TABLE} .oer-open-drawer[data-item-id]`))
        .map((opener) => opener.dataset.itemId);
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
    const next = nextItemId(rowIds(), itemId);
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

export function initQueueFlow() {
    let pending = null;

    document.addEventListener(WORKFLOW_CHANGED, (event) => {
        const { itemId, action, status } = event.detail;
        if (!itemId || !leavesQueue(action)) {
            return;
        }
        markRow(itemId, status);
        lowerQueueLabel();
        pending = String(itemId);
    });

    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, content } = event.detail;
        if (null === pending || String(itemId) !== pending) {
            return;
        }
        pending = null;
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
