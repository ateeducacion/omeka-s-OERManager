/**
 * Resumen «leídos / saltados» de la extracción de medios (TASK-017). Es lo que
 * explica por qué una propuesta de IA sale pobre. Núcleo puro.
 */
export function extractionSummary(content) {
    if (!content) {
        return '';
    }
    const sources = content.sources || [];
    const skipped = content.skipped || {};
    const names = Object.keys(skipped);
    const parts = ['leídos: ' + (sources.length ? sources.join(', ') : '(ninguno)')];
    if (names.length) {
        parts.push('saltados: ' + names.map((n) => n + ' → ' + skipped[n]).join('; '));
    }
    return parts.join(' | ');
}
