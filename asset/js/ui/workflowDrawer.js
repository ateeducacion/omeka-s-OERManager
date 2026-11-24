/**
 * Rechazar/publicar una propuesta desde el drawer de la vista maestra
 * (RF-016, extensión post-PR#38). El drawer es 100% AJAX (ADR-0017 §1,
 * mismo patrón que visibility.js) — a diferencia de la página nativa del
 * item, aquí NO se navega: perdería el filtro activo del curador. Los
 * mismos controladores (reject-proposal/publish-proposal) responden JSON
 * cuando la petición lleva `X-Requested-With` y mensaje+redirect en el
 * resto de casos (IndexController::rejectProposalAction/publishProposalAction).
 */
import { reopenDrawer } from './drawer.js';
import { collectAlignmentPairs } from './recatalog.js';
import { nextOpen } from '../core/workflowReason.js';
import { actionOf } from '../core/queueNav.js';

/**
 * A workflow action succeeded in the panel (TASK-070). Detail:
 * `{ itemId, action, status, leftQueue, enteredQueue }`; the last two come from
 * the server.
 */
export const WORKFLOW_CHANGED = 'oer:workflow-changed';

function errorBox(form) {
    return form.closest('.oer-panel-workflow')?.querySelector('.oer-workflow-error') || null;
}

function showError(form, message) {
    const box = errorBox(form);
    if (!box) {
        window.alert(message);
        return;
    }
    box.textContent = message;
    box.hidden = false;
}

function clearError(form) {
    const box = errorBox(form);
    if (box) {
        box.hidden = true;
        box.textContent = '';
    }
}

/**
 * TASK-065 (spec A6): the actions that need a reason are buttons in the flow
 * bar; each opens its own form under the bar, one at a time. The form keeps
 * its class, so the submit below still sends it.
 */
function reasonForms(section) {
    return Array.from(section.querySelectorAll('.oer-workflow-reason'));
}

/**
 * The form's id ATTRIBUTE. `form.id` is not it here: each form holds an
 * `<input name="id">` (the REA id it posts), and a named control shadows the
 * form's own property, so `form.id` is that input.
 */
function formId(form) {
    return form.getAttribute('id');
}

function openReason(section) {
    const form = reasonForms(section).find((one) => !one.hidden);
    return form ? formId(form) : null;
}

function setReason(section, id) {
    reasonForms(section).forEach((form) => {
        form.hidden = formId(form) !== id;
    });
    section.querySelectorAll('.oer-workflow-reason-toggle').forEach((button) => {
        button.setAttribute('aria-expanded', button.getAttribute('aria-controls') === id ? 'true' : 'false');
    });
    if (id) {
        const field = section.querySelector(`#${id} [name="reason"]`);
        if (field) {
            field.focus();
        }
    }
}

/** Closes the open reason form and gives the focus back to its button. */
function closeReason(section) {
    const open = openReason(section);
    setReason(section, null);
    const toggle = open && section.querySelector(`[aria-controls="${open}"]`);
    if (toggle) {
        toggle.focus();
    }
}

function messageFor(result) {
    if (result.issues && result.issues.length) {
        return result.issues.map((issue) => issue.message).join(' · ');
    }
    return result.message || Omeka.jsTranslate('No se pudo completar la acción.');
}

export function initWorkflowDrawer() {
    document.addEventListener('click', (event) => {
        const toggle = event.target.closest('.oer-workflow-reason-toggle');
        const cancel = event.target.closest('.oer-workflow-reason-cancel');
        const section = (toggle || cancel)?.closest('.oer-panel-workflow');
        if (!section) {
            return;
        }
        const next = nextOpen(openReason(section), toggle ? toggle.getAttribute('aria-controls') : null);
        if (null === next) {
            closeReason(section);
            return;
        }
        setReason(section, next);
    });

    // Escape inside a reason closes the reason, not the whole drawer: this runs
    // in the capture phase, before `drawer.js` closes the sidebar on Escape.
    document.addEventListener('keydown', (event) => {
        if ('Escape' !== event.key) {
            return;
        }
        const form = event.target.closest && event.target.closest('.oer-workflow-reason');
        const section = form && form.closest('.oer-panel-workflow');
        if (!section) {
            return;
        }
        event.stopPropagation();
        closeReason(section);
    }, true);

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('.oer-drawer-workflow-form');
        if (!form) {
            return;
        }
        event.preventDefault();
        clearError(form);

        const idField = form.querySelector('[name="id"]');
        const itemId = idField ? idField.value : null;
        const body = new URLSearchParams(new FormData(form));
        // «Confirmar y publicar» (ADR-0023 §3): the re-cataloguing still open
        // in the panel travels with the publication; the server writes it
        // first and publishes nothing if that write fails.
        if (form.classList.contains('oer-drawer-publish-form')) {
            const $editor = $(form).closest('.sidebar-content, .sidebar, body').find('.oer-recatalog').first();
            if ($editor.length) {
                collectAlignmentPairs($editor)
                    .filter((pair) => 'id' !== pair.name)
                    .forEach((pair) => body.append(pair.name, pair.value));
            }
        }

        fetch(form.action, {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body
        })
            .then((response) => response.json())
            .then((result) => {
                if (!result.updated) {
                    showError(form, messageFor(result));
                    return;
                }
                // TASK-070: before reopenDrawer(), so queueFlow.js is listening
                // when the panel re-renders.
                document.dispatchEvent(new CustomEvent(WORKFLOW_CHANGED, {
                    detail: {
                        itemId,
                        action: actionOf(form.action),
                        status: result.status || null,
                        leftQueue: true === result.leftQueue,
                        enteredQueue: true === result.enteredQueue
                    }
                }));
                if (itemId) {
                    reopenDrawer(itemId);
                }
            })
            .catch(() => {
                showError(form, Omeka.jsTranslate('No se pudo completar la acción.'));
            });
    });
}
