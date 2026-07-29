import { diffRows, RECATALOG_DIMENSIONS } from '../core/diffModel.js';
import { messageFor } from '../core/messages.js';
import { valueText } from '../core/values.js';
import { buildDimensionSelector, disableApply } from './termPicker.js';
import { DRAWER_RENDERED, OPEN_DRAWER } from './drawer.js';

/**
 * Panel de re-catalogación (TASK-004), extraído de oer-master-view.js en
 * TASK-028. Patrón obligatorio (skill recatalogador): preview + confirmación +
 * auditoría. Solo se envían las dimensiones que el curador modifica
 * (data-dirty), así que confirmar nunca toca properties no editadas.
 */

function buildRecatalogPanel(config, itemId, itemJson) {
    const $panel = $('<div>').addClass('oer-recatalog').attr('data-item-id', itemId);
    $panel.append($('<h4>').text(Omeka.jsTranslate('Re-catalogar')));

    // Etapa: ayuda de navegación de la cascada (ADR-0009). No se escribe en
    // el REA; solo acota Curso → Asignatura → Saberes/Criterios.
    $panel.append(
        buildDimensionSelector('etapa', Omeka.jsTranslate('Etapa (ayuda, no se guarda)'), {}, true, valueText)
    );
    RECATALOG_DIMENSIONS.forEach(([term, label]) => {
        $panel.append(buildDimensionSelector(term, label, itemJson, false, valueText));
    });

    const $actions = $('<div>').addClass('oer-recatalog-actions');
    // Pre-relleno IA (TASK-010): propone, el curador revisa y confirma.
    if (config.aiEnabled) {
        $actions.append($('<button>').attr('type', 'button').addClass('oer-recatalog-ai')
            .text(Omeka.jsTranslate('Proponer con IA')));
        // Cancelar el propose asíncrono en marcha (TASK-020).
        $actions.append($('<button>').attr('type', 'button').addClass('oer-recatalog-ai-cancel')
            .text(Omeka.jsTranslate('Cancelar')));
    }
    $actions
        .append($('<button>').attr('type', 'button').addClass('oer-recatalog-preview')
            .text(Omeka.jsTranslate('Previsualizar cambios')))
        .append($('<button>').attr('type', 'button').addClass('oer-recatalog-apply')
            .prop('disabled', true)
            .text(Omeka.jsTranslate('Confirmar')));
    $panel.append($actions);
    $panel.append($('<div>').addClass('oer-recatalog-diff'));
    return $panel;
}

function collectAlignmentPairs($panel) {
    const pairs = [{ name: 'id', value: $panel.data('item-id') }];
    // La Etapa (data-helper) no se escribe: se excluye de la confirmación.
    $panel.find('.oer-recatalog-dim[data-dirty="1"][data-helper="0"]').each(function () {
        const $dim = $(this);
        const term = $dim.data('term');
        const ids = $dim.find('.chosen-choices .search-choice').map(function () {
            return $(this).data('id');
        }).get();
        if (!ids.length) {
            // Dimensión modificada y vaciada: borrado intencional explícito.
            pairs.push({ name: `alignment[${term}][]`, value: '' });
        } else {
            ids.forEach((id) => {
                pairs.push({ name: `alignment[${term}][]`, value: id });
            });
        }
        // Justificación IA (TASK-023): solo saberes/criterios; se emite el
        // texto oculto de cada chip que la lleve (los añadidos a mano no).
        if ('lrmi:teaches' === term || 'lrmi:assesses' === term) {
            $dim.find('.chosen-choices .search-choice').each(function () {
                const $choice = $(this);
                const why = $choice.attr('data-justification');
                if (why) {
                    pairs.push({
                        name: `justification[${term}][${$choice.data('id')}]`,
                        value: why
                    });
                }
            });
        }
    });
    return pairs;
}

/**
 * D7: el diff muestra QUÉ cambia, no cuántos. Antes se pintaba «+N/−M», que
 * obligaba a confirmar una escritura RDF a ciegas.
 */
function renderDiff($panel, diff) {
    const { rows, hasInvalid } = diffRows(diff);
    const $diff = $panel.find('.oer-recatalog-diff').empty();
    rows.forEach((row) => {
        const $row = $('<p>').addClass('oer-diff-row');
        $row.append($('<strong>').text(row.label + ': '));
        if (row.unchanged) {
            $row.append($('<span>').text(Omeka.jsTranslate('sin cambios')));
        }
        if (row.added.length) {
            $row.append($('<span>').addClass('oer-diff-added')
                .text('+ ' + row.added.join(', ')));
        }
        if (row.removed.length) {
            $row.append($('<span>').addClass('oer-diff-removed')
                .text('− ' + row.removed.join(', ')));
        }
        if (row.invalid.length) {
            $row.append($('<span>').addClass('oer-diff-invalid')
                .text(Omeka.jsTranslate('inválidos: ') + row.invalid.join(', ')));
        }
        $diff.append($row);
    });
    return !hasInvalid;
}

export function initRecatalog(config) {
    document.addEventListener(DRAWER_RENDERED, (event) => {
        if (!config.canRecatalog) {
            return;
        }
        const { itemId, itemJson, content } = event.detail;
        $(content).append(buildRecatalogPanel(config, itemId, itemJson));
    });

    $(document).on('click', '.oer-recatalog-preview', function () {
        const $panel = $(this).closest('.oer-recatalog');
        const pairs = collectAlignmentPairs($panel);
        if (pairs.length <= 1) {
            $panel.find('.oer-recatalog-diff').text(Omeka.jsTranslate('No hay cambios que confirmar.'));
            disableApply($panel);
            return;
        }
        $.post(config.recatalogPreviewUrl, $.param(pairs)).done((response) => {
            const ok = renderDiff($panel, response.diff || {});
            $panel.find('.oer-recatalog-apply').prop('disabled', !ok);
        }).fail(() => {
            $panel.find('.oer-recatalog-diff').text(Omeka.jsTranslate('No se pudo previsualizar.'));
        });
    });

    $(document).on('click', '.oer-recatalog-apply', function () {
        const $panel = $(this).closest('.oer-recatalog');
        if (!window.confirm(Omeka.jsTranslate('¿Confirmar la re-catalogación de este REA?'))) {
            return;
        }
        const itemId = $panel.data('item-id');
        const apiUrl = $(`tr[data-resource-id="${itemId}"]`).data('api-url');
        const pairs = collectAlignmentPairs($panel);
        pairs.push({ name: 'csrf', value: config.recatalogCsrf });
        $.post(config.recatalogApplyUrl, $.param(pairs)).done((response) => {
            if (response.updated) {
                document.dispatchEvent(new CustomEvent(OPEN_DRAWER, {
                    detail: { apiUrl, itemId }
                }));
                return;
            }
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar: ')
                + messageFor(response.error, Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.')));
        }).fail(() => {
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar.'));
        });
    });
}
