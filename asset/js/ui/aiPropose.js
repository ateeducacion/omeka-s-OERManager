import { decide, POLL_MS, POLL_MAX } from '../core/proposalState.js';
import { pendingChips } from '../core/proposalMerge.js';
import { extractionSummary } from '../core/extraction.js';
import { messageFor } from '../core/messages.js';
import { addChip, chipIdsByTerm } from './termPicker.js';

/**
 * Pre-relleno con IA del panel de re-catalogación (TASK-010/020), extraído de
 * oer-master-view.js en TASK-028. El propose corre como Job en segundo plano y
 * el navegador sondea; qué hacer en cada respuesta lo decide
 * core/proposalState.js.
 *
 * No escribe nada en el catálogo: propone chips que el curador revisa y confirma
 * con el preview/apply del re-catalogador.
 */

function jobKey(itemId) {
    return 'oer-ai-job-' + itemId;
}

/** Vuelca el intercambio completo al console (grupos colapsables por llamada LLM). */
function logAiDebug(itemId, debug, content) {
    console.group('OERManager AI [item #' + itemId + ']');
    // Extracción de medios (TASK-017): qué fuentes se leyeron y cuáles se
    // saltaron con su motivo, antes de ver qué texto llegó al LLM.
    console.log('[Extracción de medios] ' + extractionSummary(content));
    if (debug.content_text) {
        console.log('[Contenido enviado al LLM]\n' + debug.content_text);
    }
    const allSteps = (debug.curricular || []).concat(debug.tags || []);
    allSteps.forEach((entry, i) => {
        console.group('[Llamada ' + (i + 1) + '] ' + entry.step + ' (' + entry.candidates + ' candidatos)');
        console.log('[SYSTEM]\n' + entry.system);
        console.log('[USER]\n' + entry.user);
        console.log('[RESPUESTA]\n' + entry.response);
        console.log('[Índices elegidos]', entry.selected_indices);
        console.groupEnd();
    });
    console.groupEnd();
}

/** Panel <details> colapsable en pantalla con resumen del intercambio LLM. */
function buildAiDebugPanel(debug, content) {
    const allSteps = (debug.curricular || []).concat(debug.tags || []);
    const $details = $('<details>').addClass('oer-ai-debug');
    $details.append(
        $('<summary>').text('Intercambio LLM (' + allSteps.length + ' llamadas) — ver consola para detalle completo')
    );

    // Extracción de medios (TASK-017): fuentes leídas y saltadas con motivo.
    const $extract = $('<div>').addClass('oer-ai-debug-section');
    $extract.append($('<strong>').text('Extracción de medios:'));
    $extract.append($('<pre>').text(extractionSummary(content) || '(sin datos de extracción)'));
    $details.append($extract);

    let contentPreview = (debug.content_text || '').substring(0, 400);
    if (debug.content_text && debug.content_text.length > 400) {
        contentPreview += '…';
    }
    if (contentPreview) {
        const $section = $('<div>').addClass('oer-ai-debug-section');
        $section.append($('<strong>').text('Contenido extraído (' + (debug.content_text || '').length + ' chars):'));
        $section.append($('<pre>').text(contentPreview));
        $details.append($section);
    }

    allSteps.forEach((entry, i) => {
        const $step = $('<div>').addClass('oer-ai-debug-step');
        $step.append(
            $('<strong>').text((i + 1) + '. ' + entry.step + ' — ' + entry.candidates + ' candidatos → elegidos: ' + JSON.stringify(entry.selected_indices))
        );
        $step.append($('<pre>').text(entry.response));
        $details.append($step);
    });

    return $details;
}

/**
 * Pre-rellena el panel con la propuesta IA. Qué falta por añadir lo decide
 * core/proposalMerge.js; aquí solo se insertan los chips.
 *
 * Se salta las dimensiones que no existen en el panel: el núcleo no conoce el
 * DOM y devuelve una entrada por cada término de la propuesta, pero inyectar
 * chips en dimensiones que el panel no muestra sería un cambio de comportamiento
 * respecto al original (`if (!$dim.length) return;`).
 */
function applyAiProposal($panel, alignment, justifications) {
    const proposal = pendingChips(alignment, chipIdsByTerm($panel), justifications);
    let added = 0;
    Object.keys(proposal).forEach((term) => {
        const $dim = $panel.find(`.oer-recatalog-dim[data-term="${term}"]`);
        if (!$dim.length) {
            return;
        }
        proposal[term].forEach((candidate) => {
            // La justificación (solo saberes/criterios) viaja oculta en el chip.
            if (addChip($dim, candidate.id, candidate.title, candidate.justification)) {
                added += 1;
            }
        });
    });
    return added;
}

function handleProposalPayload(ctx, response) {
    const added = applyAiProposal(ctx.$panel, response.alignment, response.justifications);
    let note = added
        ? Omeka.jsTranslate('Propuesta de IA añadida: revísala y previsualiza antes de confirmar.')
        : Omeka.jsTranslate('La IA no propuso cambios nuevos.');
    if (response.content && response.content.truncated) {
        note += ' ' + Omeka.jsTranslate('(contenido truncado al límite configurado).');
    }
    if (response.content && response.content.empty) {
        note = Omeka.jsTranslate('Sin contenido textual que clasificar (metadatos/medios vacíos).');
    }
    ctx.$diff.text(note);
    if (response.debug) {
        logAiDebug(ctx.itemId, response.debug, response.content);
        ctx.$panel.find('.oer-ai-debug').remove();
        ctx.$panel.find('.oer-recatalog-diff').after(buildAiDebugPanel(response.debug, response.content));
    }
}

function handleDecision(ctx, jobId, attempt, next) {
    if ('retry' === next.action) {
        setTimeout(() => pollStatus(ctx, jobId, attempt + 1), POLL_MS);
        return;
    }
    // El timeout NO borra el jobId: el original lo dejaba puesto para que el
    // curador pudiera reenganchar al job, que sigue vivo en el servidor.
    if ('timeout' !== next.action) {
        localStorage.removeItem(jobKey(ctx.itemId));
    }
    ctx.$button.prop('disabled', false);
    if ('done' === next.action) {
        handleProposalPayload(ctx, next.payload);
        return;
    }
    ctx.$diff.text(Omeka.jsTranslate(next.message));
}

function pollStatus(ctx, jobId, attempt) {
    $.post(ctx.config.aiProposeStatusUrl, { jobId, csrf: ctx.config.recatalogCsrf })
        .done((r) => {
            // El progreso por fases lo pinta quien sondea: el núcleo decide si
            // seguir, no qué texto mostrar mientras tanto.
            if ('in_progress' === r.status) {
                ctx.$diff.text(Omeka.jsTranslate('Analizando… ') + (r.step || '') +
                    (r.total ? ' (' + r.done + '/' + r.total + ')' : ''));
            }
            handleDecision(ctx, jobId, attempt, decide({
                status: r.status, error: r.error, payload: r.payload,
                attempt, maxAttempts: POLL_MAX
            }));
        })
        .fail(() => {
            handleDecision(ctx, jobId, attempt, decide({
                status: 'network_error', attempt, maxAttempts: POLL_MAX
            }));
        });
}

function startProposal(ctx) {
    ctx.$diff.text(Omeka.jsTranslate('Enviando…'));
    ctx.$button.prop('disabled', true);
    $.post(ctx.config.aiProposeUrl, { id: ctx.itemId, csrf: ctx.config.recatalogCsrf })
        .done((r) => {
            if (r.error) {
                // Traduce quien pinta: el mapa de core/messages.js está en
                // español sin traducir, igual que los literales de las vistas.
                ctx.$diff.text(Omeka.jsTranslate(messageFor(r.error, r.error)));
                ctx.$button.prop('disabled', false);
                return;
            }
            localStorage.setItem(jobKey(ctx.itemId), r.jobId);
            pollStatus(ctx, r.jobId, 0);
        })
        .fail(() => {
            ctx.$diff.text(Omeka.jsTranslate('No se pudo consultar a la IA.'));
            ctx.$button.prop('disabled', false);
        });
}

function contextFor(config, $button) {
    const $panel = $button.closest('.oer-recatalog');
    return {
        config,
        $panel,
        $button,
        $diff: $panel.find('.oer-recatalog-diff'),
        itemId: $panel.data('item-id')
    };
}

export function initAiPropose(config) {
    // Botón «Cancelar» del propose en marcha (TASK-020).
    $(document).on('click', '.oer-recatalog-ai-cancel', function () {
        const $panel = $(this).closest('.oer-recatalog');
        const jobId = localStorage.getItem(jobKey($panel.data('item-id')));
        if (!jobId) {
            return;
        }
        $.post(config.aiProposeCancelUrl, { jobId, csrf: config.recatalogCsrf });
        $panel.find('.oer-recatalog-diff').text(Omeka.jsTranslate('Cancelando…'));
    });

    $(document).on('click', '.oer-recatalog-ai', function () {
        const ctx = contextFor(config, $(this));
        // Guardia de doble arranque: si ya hay un job pendiente, reengancha en
        // vez de duplicar (doble clic, reapertura del panel).
        const pending = localStorage.getItem(jobKey(ctx.itemId));
        if (pending) {
            ctx.$button.prop('disabled', true);
            pollStatus(ctx, pending, 0);
            return;
        }
        startProposal(ctx);
    });
}
