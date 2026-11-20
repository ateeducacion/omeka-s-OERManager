import { TERM_LABELS } from './drawerModel.js';

/**
 * Filas del historial de curación para el drawer (rebanada 3a, ADR-0015).
 *
 * El servidor ya entrega los cambios resueltos a título y `when` formateado
 * para pintar (`whenLabel`); aquí solo se traduce el término RDF a su etiqueta
 * y se marca qué filas traen justificación, que es lo que decide si se pinta
 * el «ver porqué» colapsado (PEND-013).
 *
 * Núcleo puro, sin DOM.
 */

/**
 * El historial cubre desde que existe el registro de curación, no desde que
 * existe el catálogo. Sin este aviso, un curador que abra un REA sin eventos
 * leería el vacío como «nunca se tocó», y es falso: hay 386 anotaciones en el
 * catálogo diciendo lo contrario. Declararlo es requisito del diseño, no un
 * adorno.
 */
export const HISTORY_EMPTY_NOTICE =
    'Sin curaciones registradas. El historial recoge los cambios hechos desde el módulo, '
    + 'no los anteriores al registro de curación.';

/**
 * El servidor manda `history: null` cuando el id no vale o la lectura del item
 * falla (`drawerHistoryAction`, catch de `api()->read`) — igual que `integrity`
 * (I4, revisión final de rama): «no se pudo leer» y «no hay nada» no pueden
 * compartir pantalla, y antes de este arreglo el cliente los pintaba igual.
 */
export const HISTORY_UNKNOWN_TEXT = 'No se ha podido leer el historial.';

/**
 * ¿Llegó a leerse el historial? Simétrico a `integrityChecked`.
 * @param {Array<object>|null|undefined} history
 * @returns {boolean}
 */
export function historyChecked(history) {
    return Array.isArray(history);
}

/**
 * @param {Array<object>|null|undefined} history
 * @returns {Array<object>}
 */
export function historyRows(history) {
    if (!Array.isArray(history)) {
        return [];
    }

    return history.map((entry) => {
        const changes = (entry.changes || []).map((change) => ({
            ...change,
            label: TERM_LABELS[change.term] || change.term
        }));

        const hasReasons = changes.some(
            (change) => (change.removed || []).some((value) => Boolean(value.reason))
        );

        return {
            when: entry.when,
            // `whenLabel` la formatea CurationHistory::rows() (PHP); si un
            // payload viejo no la trajera, cae al crudo antes que a 'undefined'.
            whenLabel: entry.whenLabel || entry.when,
            contributor: entry.contributor,
            summary: entry.summary,
            isUndo: Boolean(entry.isUndo),
            changes,
            hasReasons
        };
    });
}

/** Events shown at first and added by each «Ver 5 más». */
export const HISTORY_PAGE = 5;

/**
 * Text for a term the server could not name (`CurationHistory::UNRESOLVED_PREFIX`):
 * the event predates stored labels and the term can no longer be read.
 */
export const RETIRED_TEXT = 'término retirado (#%s)';

/**
 * @param {string} title
 * @returns {string|null} the id of an unresolved `#<id>` title, or null
 */
export function retiredId(title) {
    const match = /^#([1-9][0-9]*)$/.exec(String(title));
    return match ? match[1] : null;
}

/**
 * One part per changed dimension, for the one-line summary of an event.
 * @param {object} row a row from historyRows()
 * @returns {Array<{label: string, added: number, removed: number, emptied: boolean}>}
 */
export function summaryParts(row) {
    return (row.changes || []).map((change) => ({
        label: change.label,
        added: (change.added || []).length,
        removed: (change.removed || []).length,
        emptied: Boolean(change.emptied)
    }));
}

/**
 * @param {number} total events in the history
 * @param {number} shown events shown now
 * @returns {number} events to show after one more page
 */
export function nextShown(total, shown) {
    return Math.min(total, shown + HISTORY_PAGE);
}

/**
 * One more page: rows `from`..`to` to append, `from` is the entry that takes
 * the focus after «Ver 5 más» (NFR-006: the button may vanish under the
 * keyboard), and `done` hides the button.
 * @param {number} total
 * @param {number} shown
 * @returns {{from: number, to: number, done: boolean}}
 */
export function historyPage(total, shown) {
    const to = nextShown(total, shown);
    return { from: shown, to, done: to >= total };
}
