/**
 * Agrupación de las incidencias de integridad para el drawer (rebanada 3a).
 *
 * Estrena en la UI el detalle de un comprobador que desde TASK-005 se ejecuta
 * en cada guardado y va SOLO al log de Omeka. La columna de la rebanada 2 dice
 * cuántas; esto dice cuáles.
 *
 * Núcleo puro, sin DOM: la frontera que estableció la rebanada 1.
 */

/** Severidades conocidas, en el orden en que se presentan. */
const SEVERITY_ORDER = ['error', 'warning'];

export const INTEGRITY_OK_TEXT = 'Sin incidencias detectadas.';

/**
 * El servidor manda `integrity: null` cuando el id no vale o la lectura del
 * item falla (`drawerDetailsAction`, catch de `api()->read`). Ese estado —no
 * se pudo comprobar— no es lo mismo que «se comprobó y está bien», y ambos no
 * pueden compartir el mismo texto sin que la UI mienta.
 */
export const INTEGRITY_UNKNOWN_TEXT = 'No se ha podido comprobar la integridad.';

/**
 * ¿Llegó a calcularse la integridad? Se apoya en `status`, que
 * `IntegrityResult` rellena siempre que sí hubo comprobación ('ok', 'warning'
 * o 'error'): más fiable que mirar solo si `integrity` es null, porque no
 * asume la forma exacta del payload de fallo.
 * @param {{status: string}|null|undefined} integrity
 * @returns {boolean}
 */
export function integrityChecked(integrity) {
    return Boolean(integrity && integrity.status);
}

/**
 * @param {{status: string, issues: Array<{severity: string, code: string, field: string, message: string}>}|null|undefined} integrity
 * @returns {Array<{severity: string, issues: Array<{code: string, field: string, message: string}>}>}
 */
export function integrityGroups(integrity) {
    const issues = (integrity && integrity.issues) || [];
    if (!issues.length) {
        return [];
    }

    const bySeverity = new Map();
    issues.forEach((issue) => {
        const severity = issue.severity || 'warning';
        if (!bySeverity.has(severity)) {
            bySeverity.set(severity, []);
        }
        bySeverity.get(severity).push({ code: issue.code, field: issue.field, message: issue.message });
    });

    // Una severidad que no conozcamos no se descarta: se muestra al final. Es
    // preferible enseñar algo sin ordenar a esconder una incidencia real.
    const rank = (severity) => {
        const index = SEVERITY_ORDER.indexOf(severity);
        return index === -1 ? SEVERITY_ORDER.length : index;
    };

    return [...bySeverity.entries()]
        .map(([severity, list]) => ({ severity, issues: list }))
        .sort((a, b) => rank(a.severity) - rank(b.severity));
}
