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
 * @param {{status: string, issues: Array<{severity: string}>}|null|undefined} integrity
 * @returns {Array<{severity: string, issues: Array<object>}>}
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
    bySeverity.get(severity).push(issue);
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
