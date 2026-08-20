import { diffRows, RECATALOG_DIMENSIONS } from '../core/diffModel.js';
import { undoLabel } from '../core/curationEvent.js';
import { messageFor } from '../core/messages.js';
import { valueText } from '../core/values.js';
import { buildDimensionSelector, disableApply } from './termPicker.js';
import { reopenDrawer } from './drawer.js';
import { ANCHOR_SLOT } from './drawerDetails.js';

/**
 * Panel de re-catalogación (TASK-004), extraído de oer-master-view.js en
 * TASK-028. Patrón obligatorio (skill recatalogador): preview + confirmación +
 * auditoría. Solo se envían las dimensiones que el curador modifica
 * (data-dirty), así que confirmar nunca toca properties no editadas.
 *
 * Desde TASK-033 se monta DENTRO del área de anclaje curricular, no al final
 * del `<td>`: el curador leía el currículo en un sitio y lo corregía en otro,
 * con dos secciones en medio. Deja de tener encabezado propio —el área ya se
 * llama «Anclaje curricular», y dos nombres para la misma cosa era justo lo
 * que sobraba—, y se engancha a `oer:anchor-slot` en vez de a
 * `oer:drawer-rendered`.
 */

function buildRecatalogPanel(config, itemId, itemJson) {
    const $panel = $('<div>').addClass('oer-recatalog').attr('data-item-id', itemId);

    const $dims = $('<div>').addClass('oer-recatalog-dims');
    // Etapa: ayuda de navegación de la cascada (ADR-0009). No se escribe en
    // el REA; solo acota Curso → Asignatura → Saberes/Criterios. La advertencia
    // va en su propia línea: una etiqueta etiqueta, y antes hacía dos trabajos.
    const $etapa = buildDimensionSelector('etapa', Omeka.jsTranslate('Etapa'), {}, true, valueText);
    $etapa.append($('<p>').addClass('oer-dim-hint')
        .text(Omeka.jsTranslate('Solo acota la búsqueda. No se guarda en el REA.')));
    $dims.append($etapa);
    RECATALOG_DIMENSIONS.forEach(([term, label]) => {
        $dims.append(buildDimensionSelector(term, label, itemJson, false, valueText));
    });
    $panel.append($dims);

    const $actions = $('<div>').addClass('oer-recatalog-actions');
    // Pre-relleno IA (TASK-010): propone, el curador revisa y confirma.
    if (config.aiEnabled) {
        $actions.append($('<button>').attr('type', 'button').addClass('oer-recatalog-ai')
            .text(Omeka.jsTranslate('Proponer con IA')));
        // Cancelar el propose asíncrono en marcha (TASK-020). Se llamaba
        // «Cancelar», que desde TASK-033 chocaría con el «Cancelar» que sale
        // de la edición: dos botones vecinos, mismo nombre, distinto efecto.
        $actions.append($('<button>').attr('type', 'button').addClass('oer-recatalog-ai-cancel')
            .text(Omeka.jsTranslate('Detener la propuesta')));
    }
    $actions
        .append($('<button>').attr('type', 'button').addClass('oer-recatalog-preview')
            .text(Omeka.jsTranslate('Previsualizar cambios')))
        .append($('<button>').attr('type', 'button').addClass('oer-recatalog-apply')
            .prop('disabled', true)
            .text(Omeka.jsTranslate('Confirmar')))
        .append($('<button>').attr('type', 'button').addClass('oer-recatalog-cancel')
            .text(Omeka.jsTranslate('Cancelar')));
    $panel.append($actions);
    $panel.append($('<div>').addClass('oer-recatalog-diff'));
    return $panel;
}

/**
 * Barra en reposo del área de anclaje: la entrada a la edición y, si el REA
 * tiene algo que revertir, el deshacer.
 *
 * `.oer-anchor-edit` es el contrato con `ui/drawerDetails.js`, que es quien
 * cambia el modo del área. Aquí solo se decide SI el botón existe, que es lo
 * que este fichero sabe y aquel no: sin permiso de curación o sin `itemJson`
 * no se pinta, y la barra vacía la colapsa el CSS.
 */
function fillAnchorBar($bar, itemId) {
    $bar.attr('data-item-id', itemId)
        .append($('<button>').attr('type', 'button').addClass('oer-anchor-edit')
            .text(Omeka.jsTranslate('Re-catalogar')))
        // Deshacer (TASK-007). Se pinta solo si el REA tiene un evento que
        // revertir, así que el hueco queda vacío hasta que el servidor conteste.
        .append($('<div>').addClass('oer-recatalog-undo'));
}

/**
 * Pinta el botón de deshacer si hay última re-catalogación (TASK-007). El evento
 * lo resuelve el servidor: el payload que hace posible la reversión vive en una
 * anotación de un valor privado, y no se le pide al cliente que lo interprete.
 *
 * Vive en la barra de reposo y no dentro del editor (TASK-033): deshacer una
 * curación ya escrita no exige abrir los selectores.
 */
function renderUndo(config, $bar, itemId) {
    const $slot = $bar.find('.oer-recatalog-undo').empty();
    $.post(config.recatalogLastEventUrl, { id: itemId }).done((response) => {
        const label = undoLabel(response.event);
        if (!label) {
            return;
        }
        $slot.append($('<button>').attr('type', 'button').addClass('oer-recatalog-undo-btn')
            .text(Omeka.jsTranslate(label)));
    });
}

function postUndo(config, itemId, apiUrl, force) {
    $.post(config.recatalogUndoUrl, {
        id: itemId,
        csrf: config.recatalogCsrf,
        force: force ? '1' : ''
    }).done((response) => {
        if (response.updated) {
            if (response.dropped && response.dropped.length) {
                window.alert(Omeka.jsTranslate('Deshecho. Algunos términos ya no existen y no se pudieron restaurar: ')
                    + response.dropped.join(', '));
            }
            // C2 (revisión final de rama): `openDrawer` es un conmutador y la
            // fila ya está abierta, así que llamarlo aquí la plegaría en vez de
            // recargarla. `reopenDrawer` cierra y vuelve a abrir sin ambigüedad.
            reopenDrawer(apiUrl, itemId);
            return;
        }
        // El REA cambió por otra vía después de esa re-catalogación: deshacer
        // descartaría ese trabajo, así que se pide una segunda confirmación.
        if ('stale' === response.error && !force) {
            if (window.confirm(Omeka.jsTranslate(messageFor('stale'))
                + '\n' + Omeka.jsTranslate('¿Deshacer de todos modos?'))) {
                postUndo(config, itemId, apiUrl, true);
            }
            return;
        }
        window.alert(Omeka.jsTranslate('No se pudo deshacer: ')
            + messageFor(response.error, Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.')));
    }).fail(() => {
        window.alert(Omeka.jsTranslate('No se pudo deshacer.'));
    });
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
    document.addEventListener(ANCHOR_SLOT, (event) => {
        if (!config.canRecatalog) {
            return;
        }
        const { itemId, itemJson, slot, bar } = event.detail;
        // C1 (revisión final de rama): `openDrawer` ya no cuelga DRAWER_RENDERED
        // del JSON-LD anónimo, así que `itemJson` puede llegar `null` (REA
        // privado: `/api` no autentica, ver drawer.js). Este panel SÍ lo
        // necesita para prellenar los selectores con lo ya elegido —
        // `buildDimensionSelector` lee `itemJson[term]` sin guarda—, así que se
        // avisa con un motivo legible en vez de reventar. Unificarlo en una
        // sola llamada autenticada es trabajo propio (no se rediseña aquí el
        // flujo de datos del re-catalogador).
        if (!itemJson) {
            // El motivo va dentro del área de anclaje, bajo la lectura: es ahí
            // donde el curador busca por qué no puede corregir lo que ve. Y no
            // se pinta el botón de entrada, que no llevaría a ninguna parte.
            $(slot).append(
                $('<p>').addClass('oer-drawer-empty').text(Omeka.jsTranslate(
                    'Re-catalogación no disponible: no se ha podido leer este REA sin autenticar '
                    + '(puede estar en privado).'
                ))
            );
            return;
        }
        $(slot).append(buildRecatalogPanel(config, itemId, itemJson));
        const $bar = $(bar);
        fillAnchorBar($bar, itemId);
        renderUndo(config, $bar, itemId);
    });

    // Salir de la edición reconstruye la fila entera en vez de solo esconder
    // los selectores: así los chips puestos y no confirmados se van de verdad.
    // Es el mismo camino que ya se recorre tras confirmar o deshacer, y en la
    // superficie de escritura de alto riesgo del módulo vale más reconstruir
    // que arrastrar un borrador invisible hasta la próxima vez que se abra.
    $(document).on('click', '.oer-recatalog-cancel', function () {
        const itemId = $(this).closest('.oer-recatalog').data('item-id');
        reopenDrawer($(`tr[data-resource-id="${itemId}"]`).data('api-url'), itemId);
    });

    $(document).on('click', '.oer-recatalog-undo-btn', function () {
        if (!window.confirm(Omeka.jsTranslate('¿Deshacer la última re-catalogación de este REA?'))) {
            return;
        }
        // `[data-item-id]` y no `.oer-recatalog`: desde TASK-033 el deshacer
        // vive en la barra de reposo, fuera del panel de selectores.
        const itemId = $(this).closest('[data-item-id]').data('item-id');
        const apiUrl = $(`tr[data-resource-id="${itemId}"]`).data('api-url');
        postUndo(config, itemId, apiUrl, false);
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
                // C2: mismo motivo que en postUndo — recargar una fila abierta
                // con `openDrawer` la plegaría; `reopenDrawer` no.
                reopenDrawer(apiUrl, itemId);
                return;
            }
            // Confirmar sin cambios no escribe (habría re-sellado las
            // anotaciones con fecha nueva): no es un error, es un no-op.
            if (response.unchanged) {
                window.alert(Omeka.jsTranslate(messageFor('unchanged')));
                return;
            }
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar: ')
                + messageFor(response.error, Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.')));
        }).fail(() => {
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar.'));
        });
    });
}
