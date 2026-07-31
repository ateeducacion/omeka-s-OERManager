/**
 * Etiqueta del botón de deshacer (TASK-007). Núcleo puro: sin DOM ni traducción
 * (traduce quien pinta, en ui/).
 *
 * La fecha se formatea recortando la cadena ISO, NO con Date: el servidor la
 * escribe con su propio huso (PHP `format('c')`), así que reinterpretarla en el
 * del navegador mostraría una hora distinta de la que quedó anotada en el RDF.
 */
const ISO = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/;

export function undoLabel(event) {
    if (!event) {
        return '';
    }
    const parts = [];
    const match = ISO.exec(event.when || '');
    if (match) {
        const [, year, month, day, hour, minute] = match;
        parts.push(`${day}/${month}/${year} ${hour}:${minute}`);
    }
    if (event.contributor) {
        parts.push(event.contributor);
    }
    const detail = parts.join(' · ');
    return detail
        ? `Deshacer la última re-catalogación (${detail})`
        : 'Deshacer la última re-catalogación';
}
