/**
 * Batch AI proposal (TASK-011): pure decisions for ui/aiBatch.js. Texts are
 * Spanish msgids; whoever paints them passes them through Omeka.jsTranslate.
 */

export function formatCost(usd) {
    return Number(usd || 0).toFixed(2).replace('.', ',') + ' $';
}

export function previewModel(response) {
    const running = true === response.running;
    const enter = Number(response.enter || 0);
    let notice = '';
    if (running) {
        notice = 'Ya hay un lote de IA en marcha. Espera a que termine.';
    } else if (0 === enter) {
        notice = 'Ningún REA de la selección necesita propuesta.';
    }
    return {
        lines: [
            ['Entran', String(enter)],
            ['Se saltan: fuera de la cola', String(Number(response.outOfQueue || 0))],
            ['Se saltan: ya tienen propuesta', String(Number(response.hasProposal || 0))],
            ['Coste estimado', formatCost(response.cost)]
        ],
        canLaunch: !running && enter > 0,
        notice
    };
}

function counts(tallies) {
    const t = tallies || {};
    return Number(t.proposed || 0) + ' con propuesta, ' + Number(t.skipped || 0) + ' saltados, '
        + (t.failed || []).length + ' con error.';
}

export function progressModel(state) {
    if ('in_progress' === state.status) {
        return { action: 'poll', text: 'Procesados ' + Number(state.done || 0) + ' de ' + Number(state.total || 0) + '…' };
    }
    const tallies = state.tallies || {};
    const failed = tallies.failed || [];
    if ('completed' === state.status) {
        return { action: 'done', text: 'Terminado: ' + counts(tallies), failed };
    }
    if ('stopped' === state.status) {
        return { action: 'done', text: 'Cancelado: ' + counts(tallies), failed };
    }
    if (tallies.aborted) {
        return {
            action: 'done',
            text: 'Detenido tras varios errores seguidos de la IA: revisa la conexión con el proveedor. '
                + Number(tallies.proposed || 0) + ' con propuesta.',
            failed
        };
    }
    return { action: 'done', text: 'El proceso del lote se interrumpió. Revisa el registro de trabajos.', failed };
}

export function storedAiJob(raw) {
    const id = Number(raw);
    return Number.isInteger(id) && id > 0 ? id : null;
}
