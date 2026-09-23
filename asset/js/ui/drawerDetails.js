import { DRAWER_RENDERED } from './drawer.js';
import { historyRows, historyChecked, HISTORY_EMPTY_NOTICE, HISTORY_UNKNOWN_TEXT } from '../core/historyModel.js';
import { TERM_LABELS } from '../core/drawerModel.js';

/**
 * Lo que queda del panel en cliente después de TASK-034 (ADR-0017 §1).
 *
 * El pintado se fue a `view/oer-manager/admin/index/drawer-details.phtml`: el
 * sidebar del core inyecta HTML servido, así que el markup vive en PHP y su
 * copy entra por fin en `make generate-pot`. Aquí sobrevive solo lo que no se
 * puede servir con el panel:
 *
 * 1. **El historial**, que es la parte cara —una lectura de API por id
 *    referenciado— y nace plegado: su petición no se lanza hasta desplegarlo.
 * 2. **El hueco de edición del anclaje**, que el partial deja vacío y rellena
 *    el re-catalogador. Este módulo es dueño del modo (`data-mode`) y le pasa
 *    el hueco; aquel decide si hay algo que montar.
 * 3. **The governance form's slot** (Task 11, fix round 1): same indirection
 *    as point 2, not a direct import. This file does not know `governance.js`
 *    exists — it dispatches `GOVERNANCE_SLOT` whenever `.oer-area-governance`
 *    is present, and `governance.js` (initialised once from `main.js`, like
 *    `ui/recatalog.js`) is what subscribes. The point of the indirection: the
 *    next slice adds a second, batch editor of these same fields to this same
 *    panel, and a `drawerDetails.js` that imports every editor by name would
 *    turn into exactly the hub this pattern avoids.
 */

/**
 * Evento con el que el panel ofrece su hueco de edición del anclaje. El
 * re-catalogador se engancha aquí, y no al contenido entero del sidebar, para
 * que los selectores nazcan DENTRO del área que ya cuenta el anclaje.
 *
 * Contrato con `ui/recatalog.js`: este fichero entrega `{ itemId, itemJson,
 * slot, bar }` y lleva el modo; aquel puebla `slot` y `bar`, y marca su botón
 * de entrada con `.oer-anchor-edit`, lo único que este fichero le escucha.
 */
export const ANCHOR_SLOT = 'oer:anchor-slot';

/**
 * Same shape of indirection as `ANCHOR_SLOT`, for the governance area (Task
 * 11, fix round 1): this file only announces that `.oer-area-governance`
 * exists in the freshly-rendered content, and does not import or call
 * whoever fills it. Unlike `ANCHOR_SLOT`, there is no separate slot/bar pair
 * to hand over — the read rows, notices, «Editar» button and the empty
 * `.oer-governance-form` it fills are all already part of `section` — so the
 * contract with `ui/governance.js` is simply `{ itemId, section }`.
 */
export const GOVERNANCE_SLOT = 'oer:governance-slot';

function note(text, className) {
    const element = document.createElement('p');
    element.className = className;
    element.textContent = text;
    return element;
}

/**
 * @param {object} change
 * @param {boolean} hasReasons Viene de `historyModel::historyRows` (fila
 *   completa, no solo este `change`): si la fila entera no trae ningún
 *   porqué, no hace falta mirar `value.reason` en cada valor retirado.
 */
function renderChange(change, hasReasons) {
    const block = document.createElement('div');
    block.className = 'oer-history-change';

    const label = document.createElement('span');
    label.className = 'oer-history-term';
    label.textContent = change.emptied
        ? `${change.label} (${Omeka.jsTranslate('vaciada')})`
        : change.label;
    block.appendChild(label);

    (change.added || []).forEach((title) => {
        const line = document.createElement('span');
        line.className = 'oer-history-added';
        line.textContent = `+ ${title}`;
        block.appendChild(line);
    });

    (change.removed || []).forEach((value) => {
        const line = document.createElement('span');
        line.className = 'oer-history-removed';
        line.textContent = `− ${value.title}`;
        block.appendChild(line);

        // PEND-013: la justificación de la IA se muestra, pero COLAPSADA. Aquí ya
        // está decidido y escrito, así que no ancla la decisión del curador; y
        // regenerarla costaría otra pasada de LLM.
        if (hasReasons && value.reason) {
            const why = document.createElement('details');
            why.className = 'oer-history-why';
            const toggle = document.createElement('summary');
            toggle.textContent = Omeka.jsTranslate('Ver porqué');
            why.appendChild(toggle);
            const reason = document.createElement('p');
            reason.textContent = value.reason;
            why.appendChild(reason);
            block.appendChild(why);
        }
    });

    return block;
}

function renderHistory(history) {
    // Sin encabezado propio: el <summary> del <details> que envuelve esto ya
    // dice «Historial de curación», y pintarlo dos veces era ruido.
    const section = document.createElement('section');
    section.className = 'oer-drawer-section oer-drawer-history';

    // «No se pudo leer» y «no hay nada» no pueden compartir pantalla: antes
    // `drawerHistoryAction` mandaba `[]` en los dos casos y el cliente pintaba
    // el mismo aviso de cobertura para ambos.
    if (!historyChecked(history)) {
        section.appendChild(note(Omeka.jsTranslate(HISTORY_UNKNOWN_TEXT), 'oer-drawer-empty'));
        return section;
    }

    const rows = historyRows(history);
    if (!rows.length) {
        section.appendChild(note(Omeka.jsTranslate(HISTORY_EMPTY_NOTICE), 'oer-drawer-empty'));
        return section;
    }

    rows.forEach((row) => {
        const entry = document.createElement('article');
        entry.className = row.isUndo ? 'oer-history-entry oer-history-undo' : 'oer-history-entry';

        const header = document.createElement('p');
        header.className = 'oer-history-header';
        header.textContent = `${row.whenLabel} · ${row.contributor}`;
        entry.appendChild(header);

        // `row.summary` (jerga RDF: «lrmi:teaches +1 −2 (vaciada)») se omite a
        // propósito: el diff de abajo ya dice lo mismo con etiquetas humanas.
        row.changes.forEach((change) => entry.appendChild(renderChange(change, row.hasReasons)));
        section.appendChild(entry);
    });

    return section;
}

/**
 * Engancha el `<details>` que el partial deja plegado y vacío. La petición no
 * sale hasta el primer despliegue (P-6).
 */
function wireHistory(content, historyUrl) {
    const box = content.querySelector('.oer-history-box');
    const body = box && box.querySelector('.oer-history-body');
    if (!box || !body || !historyUrl) {
        return;
    }

    const itemId = box.dataset.itemId;
    let loaded = false;
    box.addEventListener('toggle', () => {
        if (!box.open || loaded) {
            return;
        }
        loaded = true;
        body.textContent = Omeka.jsTranslate('Cargando historial…');
        fetch(`${historyUrl}?id=${encodeURIComponent(itemId)}`, { headers: { Accept: 'application/json' } })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(`drawer-history respondió ${response.status}`);
                }
                return response.json();
            })
            .then((data) => {
                body.textContent = '';
                body.appendChild(renderHistory(data.history));
            })
            .catch(() => {
                body.textContent = Omeka.jsTranslate('No se ha podido cargar el historial.');
                loaded = false;
            });
    });
}

export function initDrawerDetails(config) {
    // Entrar en edición es un cambio de composición, así que lo lleva este
    // fichero; el botón lo pone el re-catalogador, que es quien sabe si el
    // curador puede tocar el REA. El camino de vuelta NO está aquí: «Cancelar»
    // repuebla el sidebar desde el re-catalogador, para que salir de la edición
    // descarte de verdad lo que no se confirmó.
    document.addEventListener('click', (event) => {
        const button = event.target.closest('.oer-anchor-edit');
        if (!button) {
            return;
        }
        const section = button.closest('.oer-area-alignment');
        if (!section) {
            return;
        }
        section.dataset.mode = 'edit';
        // El foco sigue a la composición: quien llegó al botón con el teclado
        // se quedaría mirando un botón que acaba de desaparecer.
        const first = section.querySelector('.oer-term-search');
        if (first) {
            first.focus();
        }
    });

    document.addEventListener(DRAWER_RENDERED, (event) => {
        const { itemId, itemJson, content } = event.detail;

        wireHistory(content, config.drawerHistoryUrl);

        // `.oer-area-governance` is absent entirely when `drawer-details`
        // could not read the item (same branch the anchor slot check below
        // guards against) — no event, nothing for `governance.js` to mount.
        const governanceSection = content.querySelector('.oer-area-governance');
        if (governanceSection) {
            document.dispatchEvent(new CustomEvent(GOVERNANCE_SLOT, {
                detail: { itemId, section: governanceSection }
            }));
        }

        // Huecos que deja el partial. Si `drawer-details` no pudo leer el REA,
        // el partial los pinta igual junto al aviso, así que el re-catalogador
        // se sigue montando en vez de desaparecer con la lectura.
        const slot = content.querySelector('.oer-anchor-slot');
        const bar = content.querySelector('.oer-anchor-bar');
        if (!slot || !bar) {
            return;
        }

        document.dispatchEvent(new CustomEvent(ANCHOR_SLOT, {
            detail: { itemId, itemJson, slot, bar }
        }));
    });
}
