import { valueText } from './values.js';

/**
 * Campos del drawer de detalle (ADR-0005 v1). Se conservan los nueve de la v1;
 * el catálogo ampliado de ADR-0013 entra en la rebanada siguiente.
 */
export const DRAWER_FIELDS = [
    ['dcterms:description', 'Descripción'],
    ['lrmi:educationalLevel', 'Etapa'],
    ['schema:about', 'Materia'],
    ['lrmi:assesses', 'Criterios de evaluación'],
    ['lrmi:teaches', 'Saberes básicos'],
    ['dcterms:relation', 'Eje temático'],
    ['schema:isPartOf', 'Proyecto'],
    ['lrmi:learningResourceType', 'Tipo de recurso'],
    ['dcterms:license', 'Licencia']
];

/**
 * Término RDF → etiqueta legible. Se deriva de DRAWER_FIELDS para que el
 * historial y el drawer no puedan discrepar en cómo llaman a una dimensión.
 */
export const TERM_LABELS = Object.fromEntries(DRAWER_FIELDS);

export function drawerTitle(itemJson) {
    if (itemJson['o:title']) {
        return itemJson['o:title'];
    }
    const title = itemJson['dcterms:title'];
    return (title && title[0]) ? valueText(title[0]) : '';
}

/**
 * Filas del drawer. Omite los campos sin valor, igual que la v1.
 */
export function drawerRows(itemJson, fields = DRAWER_FIELDS) {
    const rows = [];
    fields.forEach(([term, label]) => {
        const values = itemJson[term] || [];
        if (!values.length) {
            return;
        }
        rows.push({ term, label, text: values.map(valueText).join(', ') });
    });
    return rows;
}
