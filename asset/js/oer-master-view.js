/**
 * Propose IA de la vista maestra (TASK-010/020), lo último que queda de este
 * fichero: en TASK-028 el drawer, la visibilidad, el selector de términos y el
 * panel de re-catalogación se fueron a módulos ES (asset/js/main.js).
 *
 * Se comunica con ellos por eventos (`oer:add-chip`) porque un IIFE clásico no
 * puede importar. Desaparece entero en la tarea 14.
 */
(function ($) {
    'use strict';

    // Properties que el re-catalogador puede escribir (ADR-0004/0009); el
    // proyecto (schema:isPartOf) queda fuera (acción de gestor aparte). El
    // educationalLevel del REA referencia un Curso (ADR-0009).
    function aiEnabled() {
        return $('#oer-master-view-table').data('ai-enabled') === 1
            || $('#oer-master-view-table').data('ai-enabled') === '1';
    }

    // Resumen "leídos N / saltados M (motivos)" de la extracción de medios.
    function extractionSummary(content) {
        if (!content) {
            return '';
        }
        var sources = content.sources || [];
        var skipped = content.skipped || {};
        var skippedNames = Object.keys(skipped);
        var parts = ['leídos: ' + (sources.length ? sources.join(', ') : '(ninguno)')];
        if (skippedNames.length) {
            parts.push('saltados: ' + skippedNames.map(function (name) {
                return name + ' → ' + skipped[name];
            }).join('; '));
        }
        return parts.join(' | ');
    }

    // Vuelca el intercambio completo al console (grupos colapsables por llamada LLM).
    function logAiDebug(itemId, debug, content) {
        console.group('OERManager AI [item #' + itemId + ']');
        // Extracción de medios (TASK-017): qué fuentes se leyeron y cuáles se
        // saltaron con su motivo, antes de ver qué texto llegó al LLM.
        console.log('[Extracción de medios] ' + extractionSummary(content));
        if (debug.content_text) {
            console.log('[Contenido enviado al LLM]\n' + debug.content_text);
        }
        var allSteps = (debug.curricular || []).concat(debug.tags || []);
        allSteps.forEach(function (entry, i) {
            console.group('[Llamada ' + (i + 1) + '] ' + entry.step + ' (' + entry.candidates + ' candidatos)');
            console.log('[SYSTEM]\n' + entry.system);
            console.log('[USER]\n' + entry.user);
            console.log('[RESPUESTA]\n' + entry.response);
            console.log('[Índices elegidos]', entry.selected_indices);
            console.groupEnd();
        });
        console.groupEnd();
    }

    // Panel <details> colapsable en pantalla con resumen del intercambio LLM.
    function buildAiDebugPanel(debug, content) {
        var allSteps = (debug.curricular || []).concat(debug.tags || []);
        var $details = $('<details>').addClass('oer-ai-debug');
        $details.append(
            $('<summary>').text('Intercambio LLM (' + allSteps.length + ' llamadas) — ver consola para detalle completo')
        );

        // Extracción de medios (TASK-017): fuentes leídas y saltadas con motivo.
        var $extract = $('<div>').addClass('oer-ai-debug-section');
        $extract.append($('<strong>').text('Extracción de medios:'));
        $extract.append($('<pre>').text(extractionSummary(content) || '(sin datos de extracción)'));
        $details.append($extract);

        var contentPreview = (debug.content_text || '').substring(0, 400);
        if (debug.content_text && debug.content_text.length > 400) {
            contentPreview += '…';
        }
        if (contentPreview) {
            var $section = $('<div>').addClass('oer-ai-debug-section');
            $section.append($('<strong>').text('Contenido extraído (' + (debug.content_text || '').length + ' chars):'));
            $section.append($('<pre>').text(contentPreview));
            $details.append($section);
        }

        allSteps.forEach(function (entry, i) {
            var $step = $('<div>').addClass('oer-ai-debug-step');
            $step.append(
                $('<strong>').text((i + 1) + '. ' + entry.step + ' — ' + entry.candidates + ' candidatos → elegidos: ' + JSON.stringify(entry.selected_indices))
            );
            $step.append($('<pre>').text(entry.response));
            $details.append($step);
        });

        return $details;
    }

    // Pre-rellena el panel con la propuesta IA: por dimensión, añade los chips
    // propuestos que no estén ya seleccionados y marca la dimensión modificada.
    // No escribe nada: el curador revisa y confirma (preview/apply de 4a).
    function applyAiProposal($panel, alignment, justifications) {
        var justMap = justifications || {};
        var added = 0;
        Object.keys(alignment || {}).forEach(function (term) {
            var $dim = $panel.find('.oer-recatalog-dim[data-term="' + term + '"]');
            if (!$dim.length) {
                return;
            }
            var termJust = justMap[term] || {};
            (alignment[term] || []).forEach(function (candidate) {
                if ($dim.find('.chosen-choices .search-choice[data-id="' + candidate.id + '"]').length) {
                    return;
                }
                // La justificación (solo saberes/criterios) viaja oculta en el chip.
                // El chip lo construye ui/termPicker.js: este fichero ya no puede
                // importarlo. Puente de transición, muere en la tarea 14.
                document.dispatchEvent(new CustomEvent('oer:add-chip', {
                    detail: {
                        dim: $dim.get(0),
                        id: candidate.id,
                        title: candidate.title,
                        justification: termJust[candidate.id]
                    }
                }));
                added += 1;
            });
        });
        return added;
    }

    // Async (TASK-020): el propose corre como Job en 2º plano; el navegador sondea.
    var POLL_MS = 3000;
    var POLL_MAX = 240; // ~12 min de techo de sondeo

    function jobKey(itemId) { return 'oer-ai-job-' + itemId; }

    function pollStatus($panel, $button, $diff, jobId, attempt) {
        if (attempt > POLL_MAX) {
            $diff.text(Omeka.jsTranslate('La propuesta tarda demasiado. Reintenta más tarde.'));
            $button.prop('disabled', false);
            return;
        }
        $.post($('#oer-master-view-table').data('ai-propose-status-url'), {
            jobId: jobId,
            csrf: $('#oer-master-view-table').data('recatalog-csrf')
        }).done(function (r) {
            if (r.status === 'in_progress') {
                $diff.text(Omeka.jsTranslate('Analizando… ') + (r.step || '') +
                    (r.total ? ' (' + r.done + '/' + r.total + ')' : ''));
                setTimeout(function () { pollStatus($panel, $button, $diff, jobId, attempt + 1); }, POLL_MS);
                return;
            }
            localStorage.removeItem(jobKey($panel.data('item-id')));
            $button.prop('disabled', false);
            if (r.status === 'stopped') { $diff.text(Omeka.jsTranslate('Propuesta cancelada.')); return; }
            if (r.status === 'error' || r.error) {
                $diff.text(Omeka.jsTranslate('El proveedor de IA falló. Revisa el log de Omeka.'));
                return;
            }
            handleProposalPayload($panel, $button, $diff, r.payload);
        }).fail(function () {
            setTimeout(function () { pollStatus($panel, $button, $diff, jobId, attempt + 1); }, POLL_MS);
        });
    }

    function handleProposalPayload($panel, $button, $diff, response) {
        var added = applyAiProposal($panel, response.alignment, response.justifications);
        var note = added
            ? Omeka.jsTranslate('Propuesta de IA añadida: revísala y previsualiza antes de confirmar.')
            : Omeka.jsTranslate('La IA no propuso cambios nuevos.');
        if (response.content && response.content.truncated) {
            note += ' ' + Omeka.jsTranslate('(contenido truncado al límite configurado).');
        }
        if (response.content && response.content.empty) {
            note = Omeka.jsTranslate('Sin contenido textual que clasificar (metadatos/medios vacíos).');
        }
        $diff.text(note);
        if (response.debug) {
            logAiDebug($panel.data('item-id'), response.debug, response.content);
            $panel.find('.oer-ai-debug').remove();
            $panel.find('.oer-recatalog-diff').after(buildAiDebugPanel(response.debug, response.content));
        }
    }

    function startProposal($panel, $button, $diff) {
        var itemId = $panel.data('item-id');
        $diff.text(Omeka.jsTranslate('Enviando…'));
        $button.prop('disabled', true);
        $.post($('#oer-master-view-table').data('ai-propose-url'), {
            id: itemId,
            csrf: $('#oer-master-view-table').data('recatalog-csrf')
        }).done(function (r) {
            if (r.error) {
                var messages = {
                    csrf: Omeka.jsTranslate('Token de seguridad caducado: recarga la página.'),
                    disabled: Omeka.jsTranslate('La asistencia IA no está configurada.'),
                    not_found: Omeka.jsTranslate('No se encontró el recurso.'),
                    dispatch: Omeka.jsTranslate('No se pudo iniciar el análisis en segundo plano.')
                };
                $diff.text(messages[r.error] || r.error);
                $button.prop('disabled', false);
                return;
            }
            localStorage.setItem(jobKey(itemId), r.jobId);
            pollStatus($panel, $button, $diff, r.jobId, 0);
        }).fail(function () {
            $diff.text(Omeka.jsTranslate('No se pudo consultar a la IA.'));
            $button.prop('disabled', false);
        });
    }

    // Botón «Cancelar» del propose en marcha (TASK-020).
    $(document).on('click', '.oer-recatalog-ai-cancel', function () {
        var $panel = $(this).closest('.oer-recatalog');
        var jobId = localStorage.getItem(jobKey($panel.data('item-id')));
        if (!jobId) { return; }
        $.post($('#oer-master-view-table').data('ai-propose-cancel-url'), {
            jobId: jobId,
            csrf: $('#oer-master-view-table').data('recatalog-csrf')
        });
        $panel.find('.oer-recatalog-diff').text(Omeka.jsTranslate('Cancelando…'));
    });

    $(document).on('click', '.oer-recatalog-ai', function () {
        var $button = $(this);
        var $panel = $button.closest('.oer-recatalog');
        var $diff = $panel.find('.oer-recatalog-diff');
        var itemId = $panel.data('item-id');
        // Guardia de doble arranque: si ya hay un job pendiente, reengancha en
        // vez de duplicar (doble clic, reapertura del panel).
        var pending = localStorage.getItem(jobKey(itemId));
        if (pending) {
            $button.prop('disabled', true);
            pollStatus($panel, $button, $diff, pending, 0);
            return;
        }
        startProposal($panel, $button, $diff);
    });
})(jQuery);
