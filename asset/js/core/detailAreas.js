/**
 * Áreas del panel de detalle y el estado de cada una (TASK-032).
 *
 * Codifica el principio que la rebanada 3a pagó caro: «no pude leer» y «no hay
 * nada» NUNCA comparten pantalla. De ahí tres estados y no dos.
 *
 * Núcleo puro, sin DOM: la frontera que estableció la rebanada 1. Los literales
 * viajan sin traducir; traduce la capa de UI.
 */

/** Orden de presentación. Anclaje y medios primero: son las dos mitades de la
 *  comparación que el curador viene a hacer (spec §6). */
const AREA_ORDER = ['alignment', 'media', 'record', 'integrity'];

export const AREA_LABELS = {
    alignment: 'Anclaje curricular',
    media: 'Medios',
    record: 'Información',
    integrity: 'Integridad'
};

export const PANEL_ERROR_TEXT = 'No se ha podido cargar el detalle de este REA.';
export const MEDIA_EMPTY_TEXT = 'Este REA no tiene ningún medio.';
export const ALIGNMENT_EMPTY_TEXT = 'Este REA no tiene anclaje curricular.';

const READY = 'ready';
const EMPTY = 'empty';
const UNKNOWN = 'unknown';

function alignmentState(alignment) {
    const hasSomething = Boolean(
        (alignment.groups || []).length
        || (alignment.axes || []).length
        || (alignment.orphans || []).length
    );
    return hasSomething ? READY : EMPTY;
}

/**
 * @param {{panel: object|null, integrity: object|null}} details
 * @returns {Array<{id: string, state: string}>}
 */
export function panelAreas(details) {
    const panel = details && details.panel;
    if (!panel) {
        return AREA_ORDER.map((id) => ({ id, state: UNKNOWN }));
    }

    const alignment = panel.alignment || { groups: [], axes: [], orphans: [] };
    const media = panel.media || [];
    const record = panel.record || {};
    const integrity = details.integrity;

    const byId = {
        alignment: { id: 'alignment', state: alignmentState(alignment), alignment },
        media: {
            id: 'media',
            state: media.length ? READY : EMPTY,
            media
        },
        record: {
            id: 'record',
            state: Object.keys(record).length ? READY : EMPTY,
            record
        },
        integrity: {
            id: 'integrity',
            state: integrity ? READY : UNKNOWN,
            integrity
        }
    };

    return AREA_ORDER.map((id) => byId[id]);
}
