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
    return value['o:label'] || '';
}
