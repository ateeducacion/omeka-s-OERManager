/**
 * Panel de detalle del REA sobre el sidebar nativo del admin (TASK-034,
 * ADR-0017). Antes este módulo abría una fila expandida dentro de la tabla y
 * gestionaba apertura, cierre, colspan, foco y conmutación: **todo eso lo hace
 * ahora el core**. `admin.js` delega en `#content` sobre `a.sidebar-content`,
 * pide `data-sidebar-content-url` y la inyecta en `.sidebar-content`.
 *
 * Lo que queda aquí es lo que el core no puede saber:
 *
 * 1. Re-emitir `o:sidebar-content-loaded` como evento del módulo, añadiéndole
 *    el `itemJson` que el re-catalogador necesita para prellenar sus selectores
 *    y que viene de una llamada aparte.
 * 2. `reopenDrawer()`, que hace falta tras aplicar o deshacer.
 * 3. `Escape` y el retorno de foco, que Omeka 4.2 **no** trae para el sidebar
 *    (no hay ningún manejador de teclado en `admin.js`). Ceñirse al core sería
 *    defendible con NFR-006 en la mano, pero sería regresar sobre algo que ya
 *    funcionaba en TASK-032.
 */
export const DRAWER_RENDERED = 'oer:drawer-rendered';

const SIDEBAR_SELECTOR = '#oer-detail-sidebar';

/** Disparador de la última fila abierta, para devolverle el foco al cerrar. */
let lastOpener = null;

function sidebar() {
    return document.querySelector(SIDEBAR_SELECTOR);
}

function unmarkRow() {
    document.querySelectorAll('.oer-row-open').forEach((row) => row.classList.remove('oer-row-open'));
}

/**
 * Vuelve a pedir el panel del REA. Se usa tras una escritura del re-catalogador
 * (aplicar o deshacer): el sidebar ya está abierto, así que basta con repoblarlo
 * —no hay conmutador que desambiguar, que era la trampa de `openDrawer` cuando
 * el panel era una fila.
 */
export function reopenDrawer(itemId) {
    const opener = document.querySelector(`.oer-open-drawer[data-item-id="${itemId}"]`);
    if (!opener) {
        return;
    }
    Omeka.populateSidebarContent($(sidebar()), opener.dataset.sidebarContentUrl);
}

export function initDrawer(config) {
    const panel = sidebar();
    if (!panel) {
        return;
    }

    // La compensación de anchura del `#content` es del core y está calibrada
    // para un sidebar del 25%; el nuestro mide otra cosa, así que la regla que
    // la corrige se acota con esta clase. Ponerla desde JS evita depender de
    // `:has()` para saber que estamos en la vista maestra.
    document.body.classList.add('oer-master-view');

    document.addEventListener('click', (event) => {
        const opener = event.target.closest('.oer-open-drawer');
        if (opener) {
            lastOpener = opener;
        }
    });

    // El core dispara esto tras inyectar el HTML. Aquí se le engancha el
    // `itemJson` y se re-emite con el contrato que ya conocen los consumidores.
    $(panel).on('o:sidebar-content-loaded', () => {
        const content = panel.querySelector('.sidebar-content');
        const itemId = lastOpener ? lastOpener.dataset.itemId : null;
        if (!content || !itemId) {
            return;
        }

        // El riel sube del panel al canto del sidebar (ADR-0014 §4). El valor lo
        // calcula el servidor y lo escribe el partial; aquí solo se propaga,
        // para no colgar la regla CSS de `:has()`.
        const rendered = content.querySelector('.oer-detail-panel');
        panel.dataset.integrity = (rendered && rendered.dataset.integrity) || 'ok';

        // Con el detalle despegado de la tabla, saber a qué fila pertenece
        // importa más que cuando ERA la fila.
        document.querySelectorAll('.oer-row-open').forEach((open) => open.classList.remove('oer-row-open'));
        const openRow = document.querySelector(`tr[data-resource-id="${itemId}"]`);
        if (openRow) {
            openRow.classList.add('oer-row-open');
        }

        // El foco entra en el panel: quien abrió con el teclado se quedaría si
        // no en un enlace de la tabla, con el panel abierto detrás y sin forma
        // de llegar a él.
        const close = panel.querySelector('.sidebar-close');
        if (close) {
            close.focus();
        }

        const row = document.querySelector(`tr[data-resource-id="${itemId}"]`);
        const apiUrl = row ? row.dataset.apiUrl : '';

        // Este `fetch` va SIN autenticar (contra `/api`, que conmuta a
        // NonPersistent+KeyAdapter y no ve la sesión del admin), así que un REA
        // privado responde 403/404 aquí SIEMPRE. El panel de LECTURA ya no
        // depende de él —lo sirve `drawer-details`, que sí autentica—; solo lo
        // necesita el re-catalogador para prellenar sus selectores. El fallo se
        // degrada a `itemJson: null`, no aborta el evento.
        fetch(apiUrl, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`item respondió ${response.status}`);
                }
                return response.json();
            })
            .catch(() => null)
            .then((itemJson) => {
                // Si el curador cerró el panel o abrió otro REA mientras el
                // fetch estaba en vuelo, no se gastan las peticiones que hacen
                // los consumidores de este evento sobre algo que ya no está.
                if (!content.isConnected) {
                    return;
                }
                document.dispatchEvent(new CustomEvent(DRAWER_RENDERED, {
                    detail: { itemId, itemJson, content }
                }));
            });
    });

    document.addEventListener('keydown', (event) => {
        if ('Escape' !== event.key || !panel.classList.contains('active')) {
            return;
        }
        Omeka.closeSidebar($(panel));
        unmarkRow();
        if (lastOpener) {
            lastOpener.focus();
        }
    });

    // Cerrar con el aspa también devuelve el foco a la fila que lo abrió.
    document.addEventListener('click', (event) => {
        if (!event.target.closest(`${SIDEBAR_SELECTOR} .sidebar-close`)) {
            return;
        }
        unmarkRow();
        if (lastOpener) {
            lastOpener.focus();
        }
    });
}
