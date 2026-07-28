/**
 * Qué chips de la propuesta IA faltan por añadir al panel (TASK-010/023).
 * La justificación viaja CON el chip y no se pinta: la decisión vigente es no
 * anclar al curador antes de que revise (TASK-023). Núcleo puro.
 */
export function pendingChips(alignment, currentIdsByTerm, justifications) {
    const pending = {};
    const just = justifications || {};
    Object.keys(alignment || {}).forEach((term) => {
        const current = (currentIdsByTerm[term] || []).map(String);
        const termJust = just[term] || {};
        pending[term] = (alignment[term] || [])
            .filter((candidate) => -1 === current.indexOf(String(candidate.id)))
            .map((candidate) => ({
                id: candidate.id,
                title: candidate.title,
                justification: termJust[candidate.id] || ''
            }));
    });
    return pending;
}
