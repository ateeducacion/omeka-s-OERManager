import { DRAWER_RENDERED } from './drawer.js';
import { parseRecatalog } from '../core/anchorData.js';
import { wireTabs, PANEL_TAB_SHOWN } from './panelTabs.js';
import {
    historyRows, historyChecked, HISTORY_EMPTY_NOTICE, HISTORY_UNKNOWN_TEXT,
    summaryParts, retiredId, historyPage, RETIRED_TEXT
} from '../core/historyModel.js';

/**
 * Lo que queda del panel en cliente después de TASK-034 (ADR-0017 §1).
 *
 * El pintado se fue a `view/oer-manager/admin/index/drawer-details.phtml`: el
 * sidebar del core inyecta HTML servido, así que el markup vive en PHP y su
 * copy entra por fin en `make generate-pot`. Aquí sobrevive solo lo que no se
 * puede servir con el panel:
 *
 * 1. **El historial**, que es la parte cara —una lectura de API por id
 *    referenciado—: su petición no se lanza hasta abrir su pestaña (TASK-065).
 *    Las pestañas las cambia `ui/panelTabs.js`, que este fichero engancha.
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
 * Contrato con `ui/recatalog.js`: este fichero entrega `{ itemId, recatalog,
 * slot, bar }` y lleva el modo; aquel puebla `slot` y `bar`, y marca su botón
 * de entrada con `.oer-anchor-edit`, lo único que este fichero le escucha.
 * `recatalog` es `{ allowed, reason, current }` (`core/anchorData.js`), que el
 * partial sirve en `data-recatalog` del hueco (TASK-035): ni JSON-LD crudo ni
 * una llamada aparte.
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

function t(text) {
    return Omeka.jsTranslate(text);
}

/** A title the server could not resolve reads as a retired term, not as a bare id. */
function displayTitle(title) {
    const id = retiredId(title);
    return null === id ? title : t(RETIRED_TEXT).replace('%s', id);
}

/** «Saberes básicos +2 −1 · Licencia +1», with translated labels. */
function summaryText(row) {
    return summaryParts(row).map((part) => {
        const counts = [];
        if (part.added) {
            counts.push(`+${part.added}`);
        }
        if (part.removed) {
            counts.push(`−${part.removed}`);
        }
        const tail = part.emptied ? ` (${t('vaciada')})` : '';
        return `${t(part.label)} ${counts.join(' ')}${tail}`;
    }).join(' · ');
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
        ? `${t(change.label)} (${t('vaciada')})`
        : t(change.label);
    block.appendChild(label);

    (change.added || []).forEach((title) => {
        const line = document.createElement('span');
        line.className = 'oer-history-added';
        line.textContent = `+ ${displayTitle(title)}`;
        block.appendChild(line);
    });

    (change.removed || []).forEach((value) => {
        const line = document.createElement('span');
        line.className = 'oer-history-removed';
        line.textContent = `− ${displayTitle(value.title)}`;
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
    // Sin encabezado propio: la pestaña «Historial» ya lo nombra.
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

    // TASK-065: one line per event (newest first), a disclosure with the
    // detail, and client-side pages of HISTORY_PAGE. `row.summary` (RDF jargon)
    // is still not shown: `summaryText()` says the same with human labels.
    const list = document.createElement('ol');
    list.className = 'oer-history-list';
    section.appendChild(list);

    const entry = (row) => {
        const item = document.createElement('li');
        item.className = row.isUndo ? 'oer-history-entry oer-history-undo' : 'oer-history-entry';
        const details = document.createElement('details');
        const line = document.createElement('summary');
        line.className = 'oer-history-line';
        const head = document.createElement('span');
        head.className = 'oer-history-header';
        head.textContent = row.isUndo
            ? `${row.whenLabel} · ${row.contributor} · ${t('Deshecho')}`
            : `${row.whenLabel} · ${row.contributor}`;
        const what = document.createElement('span');
        what.className = 'oer-history-what';
        what.textContent = summaryText(row);
        line.append(head, what);
        details.appendChild(line);
        row.changes.forEach((change) => details.appendChild(renderChange(change, row.hasReasons)));
        item.appendChild(details);
        return item;
    };

    let shown = 0;
    const more = document.createElement('button');
    more.type = 'button';
    more.className = 'button oer-history-more';
    more.textContent = t('Ver 5 más');
    const showMore = () => {
        const page = historyPage(rows.length, shown);
        const added = rows.slice(page.from, page.to).map((row) => list.appendChild(entry(row)));
        shown = page.to;
        more.hidden = page.done;
        return added;
    };
    more.addEventListener('click', () => {
        // The button can disappear under the keyboard: focus follows the first
        // new entry, so neither the place nor the new rows are lost (NFR-006).
        const added = showMore();
        if (added.length) {
            added[0].querySelector('summary').focus();
        }
    });
    showMore();
    section.appendChild(more);

    return section;
}

/**
 * Engancha el panel «Historial», que el partial deja vacío. La petición no
 * sale hasta que su pestaña se muestra por primera vez (P-6, NFR-004).
 */
function wireHistory(content, historyUrl) {
    const box = content.querySelector('.oer-history-box');
    const body = box && box.querySelector('.oer-history-body');
    if (!box || !body || !historyUrl) {
        return;
    }

    const itemId = box.dataset.itemId;
    let loaded = false;
    box.addEventListener(PANEL_TAB_SHOWN, () => {
        if (loaded) {
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
        const { itemId, content } = event.detail;

        // Before the tabs: a remembered «Historial» tab is shown while the tabs
        // are wired, and its panel must already be listening.
        wireHistory(content, config.drawerHistoryUrl);
        wireTabs(content);

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
        // el hueco llega sin `data-recatalog` y se lee como «cerrado».
        const slot = content.querySelector('.oer-anchor-slot');
        const bar = content.querySelector('.oer-anchor-bar');
        if (!slot || !bar) {
            return;
        }
        const recatalog = parseRecatalog(slot.dataset.recatalog);

        document.dispatchEvent(new CustomEvent(ANCHOR_SLOT, {
            detail: { itemId, recatalog, slot, bar }
        }));
    });
}
