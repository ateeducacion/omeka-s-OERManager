/**
 * Fila de detalle del REA (ADR-0005 §6, TASK-032), extraído de oer-master-view.js
 * en TASK-028. Deja de ser un cajón fijo (`role="dialog"`) y pasa a abrirse como
 * fila expandida dentro de la propia tabla: el curador no pierde el sitio en el
 * listado.
 *
 * Tras pintar emite `oer:drawer-rendered` con la celda de detalle y el JSON del
 * item. El panel del re-catalogador se engancha ahí en vez de estar cableado
 * dentro: así este módulo no depende del re-catalogador y la transición de
 * TASK-028 puede mover uno sin romper el otro.
 */
export const DRAWER_RENDERED = 'oer:drawer-rendered';

/** Fila de detalle abierta ahora mismo, o null. Solo una a la vez (P-7). */
let openRow = null;

function closeDetail() {
    if (!openRow) {
        return;
    }
    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${openRow.dataset.itemId}"]`);
    openRow.remove();
    openRow = null;
    if (opener) {
        opener.setAttribute('aria-expanded', 'false');
        opener.focus();
    }
}

/**
 * Inserta la fila de detalle justo debajo de su fila madre.
 *
 * El colspan se cuenta de la propia fila: la tabla monta sus columnas con
 * `renderHeaderRow('oer_items')`, que las toma de la configuración de Omeka, y
 * fijarlo a mano rompería en cuanto alguien añada o quite una columna.
 */
function detailRowFor(row, itemId) {
    const detail = document.createElement('tr');
    detail.className = 'oer-detail-row';
    detail.dataset.itemId = itemId;
    // El riel de la fila madre continúa en la de detalle (ADR-0014 regla 4):
    // cortarlo dejaría el canto roto justo en la fila que se está mirando.
    detail.dataset.integrity = row.dataset.integrity || 'ok';

    const cell = document.createElement('td');
    cell.colSpan = row.cells.length;
    cell.className = 'oer-detail-cell';
    detail.appendChild(cell);

    row.parentNode.insertBefore(detail, row.nextSibling);
    return { detail, cell };
}

export function openDrawer(apiUrl, itemId) {
    const row = document.querySelector(`tr[data-resource-id="${itemId}"]`);
    if (!row) {
        return;
    }

    const wasOpen = openRow && openRow.dataset.itemId === String(itemId);
    closeDetail();
    if (wasOpen) {
        return; // segundo clic sobre la misma fila: se pliega
    }

    const { detail, cell } = detailRowFor(row, itemId);
    openRow = detail;

    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${itemId}"]`);
    if (opener) {
        opener.setAttribute('aria-expanded', 'true');
    }

    cell.textContent = Omeka.jsTranslate('Cargando…');

    fetch(apiUrl, { headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`item respondió ${response.status}`);
            }
            return response.json();
        })
        .then((itemJson) => {
            // Si el curador plegó esta fila (o abrió otra) mientras el fetch
            // estaba en vuelo, `cell` ya no está en el documento: pintar aquí
            // sería inofensivo, pero DRAWER_RENDERED tiene consumidores que
            // reaccionan con peticiones reales al servidor (undo, historial).
            // Abandonar en silencio evita gastar esas peticiones para una
            // fila que ya no existe.
            if (!cell.isConnected) {
                return;
            }
            cell.textContent = '';
            document.dispatchEvent(new CustomEvent(DRAWER_RENDERED, {
                detail: { itemId, itemJson, content: cell }
            }));
        })
        .catch(() => {
            if (!cell.isConnected) {
                return;
            }
            cell.textContent = Omeka.jsTranslate('No se ha podido cargar el detalle.');
        });
}

export function initDrawer(config) {
    document.addEventListener('click', (event) => {
        const opener = event.target.closest('.oer-open-drawer');
        if (opener) {
            event.preventDefault();
            const row = opener.closest('tr');
            openDrawer(row ? row.dataset.apiUrl : '', opener.dataset.itemId);
            return;
        }
        if (event.target.closest('.oer-detail-close')) {
            closeDetail();
        }
    });

    document.addEventListener('keydown', (event) => {
        if ('Escape' === event.key) {
            closeDetail();
        }
    });
}
