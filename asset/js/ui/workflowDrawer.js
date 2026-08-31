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

function messageFor(result) {
    if (result.issues && result.issues.length) {
        return result.issues.map((issue) => issue.message).join(' · ');
    }
    return result.message || Omeka.jsTranslate('No se pudo completar la acción.');
}

export function initWorkflowDrawer() {
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
                if (itemId) {
                    reopenDrawer(itemId);
                }
            })
            .catch(() => {
                showError(form, Omeka.jsTranslate('No se pudo completar la acción.'));
            });
    });
}
