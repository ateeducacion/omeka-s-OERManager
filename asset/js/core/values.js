/**
 * Texto mostrable de un valor del JSON-LD de Omeka (TASK-028). Núcleo puro.
 */
export function valueText(value) {
    if (!value) {
        return '';
    }
    if (value['display_title']) {
        return value['display_title'];
    }
    if (value['@value']) {
        return value['@value'];
    }
    if (value['o:label']) {
        return value['o:label'];
    }
    // Un valor URI sin etiqueta (ADR-0019: la licencia se guarda como URI) no
    // tiene más texto que la propia URI. Un valor de recurso también trae
    // `@id` —la URL de la API—, que no es texto que enseñar: lo delata
    // `value_resource_id`.
    if (value['@id'] && !value['value_resource_id']) {
        return value['@id'];
    }
    return '';
}
