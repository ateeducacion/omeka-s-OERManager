/**
 * Modelo del diff del re-catalogador (TASK-028, D7). Antes se pintaba «+N/−M»:
 * confirmar una escritura RDF viendo solo un número era el punto débil del
 * flujo preview→confirmar. Núcleo puro.
 */
export const RECATALOG_DIMENSIONS = [
    ['lrmi:educationalLevel', 'Curso'],
    ['schema:about', 'Asignatura'],
    ['lrmi:teaches', 'Saberes básicos'],
    ['lrmi:assesses', 'Criterios de evaluación'],
    ['dcterms:relation', 'Eje temático']
];

function label(titles, id) {
    return (titles && titles[id]) ? titles[id] : '#' + id;
}

export function diffRows(diff, dimensions = RECATALOG_DIMENSIONS) {
    const rows = [];
    let hasInvalid = false;
    dimensions.forEach(([term, dimensionLabel]) => {
        const entry = diff[term];
        if (!entry) {
            return;
        }
        const titles = entry.titles || {};
        const invalid = (entry.invalid || []).map((id) => label(titles, id));
        if (invalid.length) {
            hasInvalid = true;
        }
        const added = (entry.added || []).map((id) => label(titles, id));
        const removed = (entry.removed || []).map((id) => label(titles, id));
        rows.push({
            term,
            label: dimensionLabel,
            added,
            removed,
            invalid,
            unchanged: !added.length && !removed.length
        });
    });
    return { rows, hasInvalid };
}
