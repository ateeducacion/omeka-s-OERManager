/**
 * Mensajes de error del módulo (TASK-028). Único mapa: antes había tres
 * duplicados con distinto juego de claves. Núcleo puro: sin DOM ni traducción
 * (traduce quien pinta, en ui/).
 */
const MESSAGES = {
    csrf: 'Token de seguridad caducado: recarga la página.',
    denied: 'No tienes permiso para re-catalogar.',
    not_found: 'No se encontró el recurso.',
    disabled: 'La asistencia IA no está configurada.',
    dispatch: 'No se pudo iniciar el análisis en segundo plano.',
    llm: 'El proveedor de IA falló. Revisa el log de Omeka.',
    invalid: 'Hay destinos inválidos en la propuesta.',
    unexpected: 'Error inesperado; inténtalo de nuevo.',
    // Reversibilidad (TASK-007).
    'no-event': 'Este REA no tiene ninguna re-catalogación registrada que deshacer.',
    stale: 'El REA cambió después de esa re-catalogación: deshacer descartaría lo hecho luego.',
    unchanged: 'No había cambios que aplicar.'
};

export function messageFor(code, fallback = '') {
    if (!code) {
        return fallback;
    }
    return MESSAGES[code] || fallback;
}
