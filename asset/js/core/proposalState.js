/**
 * Máquina de estados del sondeo del propose asíncrono (TASK-020, extraída en
 * TASK-028). Núcleo puro: decide, no ejecuta. Quien temporiza, guarda el jobId
 * y pinta es ui/aiPropose.js.
 */
export const POLL_MS = 3000;
export const POLL_MAX = 240; // ~12 min de techo de sondeo

export function decide({ status, error, payload, attempt, maxAttempts = POLL_MAX }) {
    if (attempt > maxAttempts) {
        return { action: 'timeout', message: 'La propuesta tarda demasiado. Reintenta más tarde.' };
    }
    // Un corte de red puntual no tumba un job que sigue vivo en el servidor.
    if ('in_progress' === status || 'network_error' === status) {
        return { action: 'retry' };
    }
    if ('stopped' === status) {
        return { action: 'stopped', message: 'Propuesta cancelada.' };
    }
    if ('error' === status || error) {
        return { action: 'error', message: 'El proveedor de IA falló. Revisa el log de Omeka.' };
    }
    return { action: 'done', payload };
}
