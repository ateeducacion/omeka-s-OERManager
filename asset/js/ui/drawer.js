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

/**
 * @param {{restoreFocus?: boolean}} [options] `restoreFocus` solo debe ser
 *   `true` cuando el cierre lo PIDE el curador (botón × o Esc, I1 de la
 *   revisión final de rama). Cuando el cierre es efecto colateral de abrir
 *   otra fila (o de `reopenDrawer`), devolver el foco al disparador de LA
 *   FILA QUE SE CIERRA rompe la navegación por teclado: el curador activa el
 *   título de B y el foco salta al título de A, que ya no está en pantalla.
 */
function closeDetail({ restoreFocus = true } = {}) {
    if (!openRow) {
        return;
    }
    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${openRow.dataset.itemId}"]`);
    const masterRow = document.querySelector(`tr[data-resource-id="${openRow.dataset.itemId}"]`);
    if (masterRow) {
        masterRow.classList.remove('oer-row-open');
    }
    openRow.remove();
    openRow = null;
    if (opener) {
        opener.setAttribute('aria-expanded', 'false');
        opener.removeAttribute('aria-controls');
        if (restoreFocus) {
            opener.focus();
        }
    }
}

/**
 * Inserta la fila de detalle justo debajo de su fila madre.
 *
 * El colspan se cuenta de la propia fila: la tabla monta sus columnas con
 * `renderHeaderRow('oer_items')`, que las toma de la configuración de Omeka, y
 * fijarlo a mano rompería en cuanto alguien añada o quite una columna.
 *
 * La celda de detalle lleva `role="region"` + `aria-label` con el título del
 * REA (I2 de la revisión final de rama, spec §6): el contrato cambiado dejó de
 * atrapar el foco a cambio de que la fila se anuncie como región con nombre, y
 * sin esto el `<td>` era mudo para un lector de pantalla.
 */
function detailRowFor(row, itemId, title) {
    const detail = document.createElement('tr');
    detail.className = 'oer-detail-row';
    detail.dataset.itemId = itemId;
    detail.id = `oer-detail-${itemId}`;
    // El riel de la fila madre continúa en la de detalle (ADR-0014 regla 4):
    // cortarlo dejaría el canto roto justo en la fila que se está mirando.
    detail.dataset.integrity = row.dataset.integrity || 'ok';

    const cell = document.createElement('td');
    cell.colSpan = row.cells.length;
    cell.className = 'oer-detail-cell';
    cell.setAttribute('role', 'region');
    cell.setAttribute('aria-label', title || Omeka.jsTranslate('Detalle del REA'));
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
    // `restoreFocus: false`: este cierre no lo pide el curador con × o Esc, es
    // efecto colateral de abrir (o replegar) una fila — I1.
    closeDetail({ restoreFocus: false });
    if (wasOpen) {
        return; // segundo clic sobre la misma fila: se pliega
    }

    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${itemId}"]`);
    const title = opener ? opener.textContent.trim() : '';
    const { detail, cell } = detailRowFor(row, itemId, title);
    openRow = detail;
    row.classList.add('oer-row-open');

    if (opener) {
        opener.setAttribute('aria-expanded', 'true');
        opener.setAttribute('aria-controls', detail.id);
    }

    cell.textContent = Omeka.jsTranslate('Cargando…');

    // C1 de la revisión final de rama: este `fetch` es SIN autenticar (va
    // contra `/api`, que conmuta a NonPersistent+KeyAdapter y no ve la sesión
    // del admin), así que un REA privado responde 403/404 aquí SIEMPRE. Antes
    // DRAWER_RENDERED solo se despachaba si esta llamada respondía `ok`, y con
    // ella colgaba todo el panel — el primer REA puesto en privado dejaba de
    // poder abrir su detalle. El panel de LECTURA (drawerDetails.js) no
    // depende de este JSON: viene de `drawer-details`, que sí autentica. Solo
    // el panel del re-catalogador (recatalog.js) sigue necesitando este
    // `itemJson` para prellenar los selectores con lo ya elegido; unificarlo
    // en una sola llamada autenticada queda anotado como deuda, no es tarea de
    // esta oleada de arreglo (no se rediseña el flujo de datos del
    // re-catalogador). Aquí el fallo se degrada a `itemJson: null` en vez de
    // abortar el despacho del evento.
    fetch(apiUrl, { headers: { Accept: 'application/json' } })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`item respondió ${response.status}`);
            }
            return response.json();
        })
        .catch(() => null)
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
        });
}

/**
 * Reabre la fila de detalle tras una escritura del re-catalogador (aplicar o
 * deshacer, C2 de la revisión final de rama). `openDrawer()` es un CONMUTADOR
 * (P-7: mismo clic en el título, abre/pliega), semántica correcta para el
 * disparador de la tabla — pero llamarlo para RECARGAR una fila que ya está
 * abierta hace que la lea como «segundo clic» y la pliegue: el curador pierde
 * la confirmación visual de lo que acaba de escribir en el componente de alto
 * riesgo del módulo. `reopenDrawer()` cierra primero (sin robar el foco, I1) y
 * siempre vuelve a abrir, sin la ambigüedad del conmutador.
 */
export function reopenDrawer(apiUrl, itemId) {
    if (openRow && openRow.dataset.itemId === String(itemId)) {
        closeDetail({ restoreFocus: false });
    }
    openDrawer(apiUrl, itemId);
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
