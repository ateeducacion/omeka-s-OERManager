/**
 * Curación de visibilidad de la vista maestra (ADR-0005 §7), extraída de
 * oer-master-view.js en TASK-028. Capa de UI: toca el DOM y la red; las
 * decisiones puras viven en core/.
 */

/** La celda que pinta la visibilidad la genera el column type `oerIsPublic`. */
const VISIBILITY_CELL = '.column-oerIsPublic';

function postVisibility(config, ids, isPublic) {
    const body = new URLSearchParams();
    if (1 === ids.length) {
        body.append('id', ids[0]);
    }
    ids.forEach((id) => body.append('resource_ids[]', id));
    body.append('is_public', isPublic ? '1' : '');
    body.append('oer_visibility_csrf', config.csrfToken);

    return fetch(config.setVisibilityUrl, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body
    })
        .then((response) => response.json())
        .catch(() => ({ updated: [], denied: ids }));
}

function paintUpdated(ids, isPublic) {
    const table = document.getElementById('oer-master-view-table');
    if (!table) {
        return;
    }
    ids.forEach((id) => {
        const cell = table.querySelector(`tr[data-resource-id="${id}"] ${VISIBILITY_CELL}`);
        if (cell) {
            cell.textContent = isPublic ? 'Sí' : 'No';
        }
    });
}

function selectedIds() {
    return Array.from(document.querySelectorAll('.oer-row-select:checked')).map((input) => input.value);
}

export function initVisibility(config) {
    document.addEventListener('change', (event) => {
        if (!event.target.classList.contains('oer-select-all')) {
            return;
        }
        const checked = event.target.checked;
        document.querySelectorAll('.oer-row-select').forEach((input) => {
            input.checked = checked;
        });
    });

    document.addEventListener('click', (event) => {
        const button = event.target.closest('.oer-batch-set-public');
        if (!button) {
            return;
        }
        const isPublic = '1' === String(button.dataset.isPublic);
        const ids = selectedIds();
        if (!ids.length) {
            return;
        }
        const message = isPublic
            ? Omeka.jsTranslate('¿Hacer público %1$s REA?').replace('%1$s', ids.length)
            : Omeka.jsTranslate('¿Hacer privado %1$s REA?').replace('%1$s', ids.length);
        if (!window.confirm(message)) {
            return;
        }
        postVisibility(config, ids, isPublic).then((response) => {
            paintUpdated(response.updated || [], isPublic);
            if (response.denied && response.denied.length) {
                window.alert(
                    Omeka.jsTranslate('No se pudieron actualizar %1$s REA por falta de permiso.')
                        .replace('%1$s', response.denied.length)
                );
            }
        });
    });
}
