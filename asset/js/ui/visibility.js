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
    const label = isPublic
        ? Omeka.jsTranslate('Público')
        : Omeka.jsTranslate('Privado');
    ids.forEach((id) => {
        const cell = table.querySelector(`tr[data-resource-id="${id}"] ${VISIBILITY_CELL}`);
        if (!cell) {
            return;
        }
        // Mismo marcado que OERManager\ColumnType\IsPublic::renderContent().
        let mark = cell.querySelector('.oer-visibility');
        if (!mark) {
            mark = document.createElement('span');
            mark.className = 'oer-visibility';
            cell.textContent = '';
            cell.appendChild(mark);
        }
        mark.classList.toggle('oer-visibility-public', isPublic);
        mark.classList.toggle('oer-visibility-private', !isPublic);
        mark.textContent = label;
    });
}

function selectedInputs() {
    return Array.from(document.querySelectorAll('.oer-row-select:checked'));
}

function selectedIds() {
    return selectedInputs().map((input) => input.value);
}

/**
 * La barra de lote solo existe cuando hay selección: enseñarla apagada de
 * continuo la convierte en ruido, y sus dos acciones no significan nada sin
 * filas marcadas.
 */
function refreshSelectionUi() {
    const all = Array.from(document.querySelectorAll('.oer-row-select'));
    const selected = all.filter((input) => input.checked);
    const bar = document.querySelector('.oer-selection-bar');

    all.forEach((input) => {
        const row = input.closest('tr');
        if (row) {
            row.classList.toggle('is-selected', input.checked);
        }
    });

    const selectAll = document.querySelector('.oer-select-all');
    if (selectAll) {
        selectAll.checked = all.length > 0 && selected.length === all.length;
        selectAll.indeterminate = selected.length > 0 && selected.length < all.length;
    }

    if (!bar) {
        return;
    }
    bar.hidden = 0 === selected.length;
    const counter = bar.querySelector('.oer-selection-count');
    if (counter && selected.length) {
        const template = 1 === selected.length ? counter.dataset.singular : counter.dataset.plural;
        counter.textContent = (template || '%s').replace('%s', selected.length);
    }
}

export function initVisibility(config) {
    document.addEventListener('change', (event) => {
        if (event.target.classList.contains('oer-select-all')) {
            const checked = event.target.checked;
            document.querySelectorAll('.oer-row-select').forEach((input) => {
                input.checked = checked;
            });
            refreshSelectionUi();
            return;
        }
        if (event.target.classList.contains('oer-row-select')) {
            refreshSelectionUi();
        }
    });

    document.addEventListener('click', (event) => {
        if (event.target.closest('.oer-selection-clear')) {
            document.querySelectorAll('.oer-row-select').forEach((input) => {
                input.checked = false;
            });
            refreshSelectionUi();
            return;
        }

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

    refreshSelectionUi();
}
