import { groupedDiff, RELATION, TERM_LABELS } from '../core/alignmentTree.js';
import { undoLabel } from '../core/curationEvent.js';
import { messageFor } from '../core/messages.js';
import { disableApply } from './termPicker.js';
import { buildAlignmentEditor, openAlignmentEditor, editorPairs, editorState, showReview } from './alignmentEditor.js';
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

function buildRecatalogPanel(config, itemId, recatalog) {
    const $panel = $('<div>').addClass('oer-recatalog').attr('data-item-id', itemId);
    // TASK-065 B8: header, navigator, detail, footer and review step are the
    // editor's (ui/alignmentEditor.js); this file keeps preview/apply/cancel.
    $panel.append(buildAlignmentEditor(config, $panel, recatalog));
    return $panel;
}

/**
 * Barra en reposo del área de anclaje: la entrada a la edición y, si el REA
 * tiene algo que revertir, el deshacer.
 *
 * `.oer-anchor-edit` es el contrato con `ui/drawerDetails.js`, que es quien
 * cambia el modo del área. Aquí solo se decide SI el botón existe, que es lo
 * que este fichero sabe y aquel no: si la regla de re-catalogación lo niega
 * (TASK-035) no se pinta, y la barra vacía la colapsa el CSS.
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

function postUndo(config, itemId, force) {
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
            // El sidebar ya está abierto: basta con repoblarlo para que el
            // curador vea escrito lo que acaba de confirmar (TASK-034).
            reopenDrawer(itemId);
            return;
        }
        // El REA cambió por otra vía después de esa re-catalogación: deshacer
        // descartaría ese trabajo, así que se pide una segunda confirmación.
        if ('stale' === response.error && !force) {
            if (window.confirm(Omeka.jsTranslate(messageFor('stale'))
                + '\n' + Omeka.jsTranslate('¿Deshacer de todos modos?'))) {
                postUndo(config, itemId, true);
            }
            return;
        }
        window.alert(Omeka.jsTranslate('No se pudo deshacer: ')
            + refusalText(response, Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.')));
    }).fail(() => {
        window.alert(Omeka.jsTranslate('No se pudo deshacer.'));
    });
}

export function collectAlignmentPairs($panel) {
    const pairs = [{ name: 'id', value: $panel.data('item-id') }];
    // Courses, subjects, knowledge and criteria: only the terms whose set
    // changed, from the tree's model (TASK-065 part B).
    editorPairs($panel).forEach((pair) => pairs.push(pair));
    // The thematic axis: its chips, when modified, as before.
    $panel.find(`.oer-recatalog-dim[data-term="${RELATION}"][data-dirty="1"]`).each(function () {
        const ids = $(this).find('.chosen-choices .search-choice').map(function () {
            return $(this).data('id');
        }).get();
        if (!ids.length) {
            pairs.push({ name: `alignment[${RELATION}][]`, value: '' });
        }
        ids.forEach((id) => pairs.push({ name: `alignment[${RELATION}][]`, value: id }));
    });
    return pairs;
}

/**
 * D7 and spec B6: the diff says WHAT changes, by name, grouped by course and
 * subject, with the thematic axis as its own row.
 */
function renderDiff($panel, diff) {
    const { sections, hasInvalid } = groupedDiff(diff, editorState($panel));
    const $diff = $panel.find('.oer-ae-review-body').empty();
    if (!sections.length) {
        $diff.text(Omeka.jsTranslate('sin cambios'));
        return true;
    }
    const titleOf = (section) => ('course' === section.kind
        ? section.title
        : Omeka.jsTranslate('relation' === section.kind ? TERM_LABELS[RELATION] : 'Sin encajar'));
    const classOf = { '+': 'oer-diff-added', '−': 'oer-diff-removed', '!': 'oer-diff-invalid' };
    sections.forEach((section) => {
        const $section = $('<div>').addClass('oer-diff-section');
        $section.append($('<p>').addClass('oer-diff-heading').text(titleOf(section)));
        section.lines.forEach((line) => {
            const label = Omeka.jsTranslate(TERM_LABELS[line.term]);
            const where = line.subject ? `${label} (${line.subject})` : label;
            const sign = '!' === line.sign ? Omeka.jsTranslate('inválido:') : line.sign;
            $section.append($('<p>').addClass('oer-diff-row ' + classOf[line.sign]).text(`${sign} ${where}: ${line.title}`));
        });
        $diff.append($section);
    });
    return !hasInvalid;
}

/**
 * Texto de un rechazo del servidor: el motivo que trae (RecatalogRule y demás
 * negativas de TASK-035, ya en el catálogo de traducción) o el del código.
 */
export function refusalText(response, fallback) {
    if (response && response.message) {
        return Omeka.jsTranslate(response.message);
    }
    return messageFor(response && response.error, fallback);
}

export function initRecatalog(config) {
    document.addEventListener(ANCHOR_SLOT, (event) => {
        const { itemId, recatalog, slot, bar } = event.detail;
        // TASK-035: el servidor decide por REA y usuario (RecatalogRule) y lo
        // vuelve a decidir en cada escritura. Si no se puede, se dice por qué
        // dentro del área de anclaje, que es donde el curador lo busca, y no
        // se pinta un botón de entrada que no llevaría a ninguna parte. Sin
        // privilegio de re-catalogar no llega motivo y no se pinta nada.
        if (!recatalog || !recatalog.allowed) {
            if (recatalog && recatalog.reason) {
                $(slot).append($('<p>').addClass('oer-drawer-empty oer-recatalog-refused').text(recatalog.reason));
            }
            return;
        }
        $(slot).append(buildRecatalogPanel(config, itemId, recatalog));
        const $bar = $(bar);
        fillAnchorBar($bar, itemId);
        renderUndo(config, $bar, itemId);
    });

    // Opening the editor is the first moment the trees are needed.
    $(document).on('click', '.oer-anchor-edit', function () {
        const $panel = $(this).closest('.oer-area-alignment').find('.oer-recatalog');
        if ($panel.length) {
            openAlignmentEditor(config, $panel);
        }
    });

    // Salir de la edición reconstruye la fila entera en vez de solo esconder
    // los selectores: así los chips puestos y no confirmados se van de verdad.
    // Es el mismo camino que ya se recorre tras confirmar o deshacer, y en la
    // superficie de escritura de alto riesgo del módulo vale más reconstruir
    // que arrastrar un borrador invisible hasta la próxima vez que se abra.
    $(document).on('click', '.oer-recatalog-cancel', function () {
        const itemId = $(this).closest('.oer-recatalog').data('item-id');
        reopenDrawer(itemId);
    });

    $(document).on('click', '.oer-recatalog-undo-btn', function () {
        if (!window.confirm(Omeka.jsTranslate('¿Deshacer la última re-catalogación de este REA?'))) {
            return;
        }
        // `[data-item-id]` y no `.oer-recatalog`: desde TASK-033 el deshacer
        // vive en la barra de reposo, fuera del panel de selectores.
        const itemId = $(this).closest('[data-item-id]').data('item-id');
        postUndo(config, itemId, false);
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
            if (response.error) {
                $panel.find('.oer-recatalog-diff').text(refusalText(response, ''));
                disableApply($panel);
                return;
            }
            const ok = renderDiff($panel, response.diff || {});
            $panel.find('.oer-recatalog-apply').prop('disabled', !ok);
            showReview($panel);
        }).fail(() => {
            $panel.find('.oer-recatalog-diff').text(Omeka.jsTranslate('No se pudo previsualizar.'));
        });
    });

    $(document).on('click', '.oer-recatalog-apply', function () {
        const $panel = $(this).closest('.oer-recatalog');
        // The review step is the confirmation now (spec B8): no second dialog.
        const itemId = $panel.data('item-id');
        const pairs = collectAlignmentPairs($panel);
        pairs.push({ name: 'csrf', value: config.recatalogCsrf });
        $.post(config.recatalogApplyUrl, $.param(pairs)).done((response) => {
            if (response.updated) {
                reopenDrawer(itemId);
                return;
            }
            // Confirmar sin cambios no escribe (habría re-sellado las
            // anotaciones con fecha nueva): no es un error, es un no-op.
            if (response.unchanged) {
                window.alert(Omeka.jsTranslate(messageFor('unchanged')));
                return;
            }
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar: ')
                + refusalText(response, Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.')));
        }).fail(() => {
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar.'));
        });
    });
}
