/**
 * Término RDF → etiqueta legible, compartido por el historial, la gobernanza y
 * el lote para que no puedan discrepar en cómo llaman a una dimensión.
 *
 * TASK-035 retiró `drawerTitle()`, `drawerRows()` y `DRAWER_FIELDS`: leían el
 * JSON-LD crudo de `/api` y nadie los usaba desde TASK-034 salvo su test.
 */
export const TERM_LABELS = Object.freeze({
    'dcterms:description': 'Descripción',
    'lrmi:educationalLevel': 'Curso',
    'schema:about': 'Materia',
    'lrmi:assesses': 'Criterios de evaluación',
    'lrmi:teaches': 'Saberes básicos',
    'dcterms:relation': 'Eje temático',
    'schema:isPartOf': 'Proyecto',
    'lrmi:learningResourceType': 'Tipo de recurso',
    'dcterms:license': 'Licencia',
    'dcterms:creator': 'Autoría',
    'dcterms:publisher': 'Editor',
    'dcterms:rightsHolder': 'Titular de derechos',
    'dcterms:source': 'Fuente'
});
