import { TERM_LABELS } from './drawerModel.js';

/**
 * Filas del historial de curación para el drawer (rebanada 3a, ADR-0015).
 *
 * El servidor ya entrega los cambios resueltos a título; aquí solo se traduce
 * el término RDF a su etiqueta y se marca qué filas traen justificación, que es
 * lo que decide si se pinta el «ver porqué» colapsado (PEND-013).
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
            contributor: entry.contributor,
            summary: entry.summary,
            isUndo: Boolean(entry.isUndo),
            changes,
            hasReasons
        };
    });
}
