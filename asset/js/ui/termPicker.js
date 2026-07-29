/**
 * Selector de términos del currículo (TASK-004), extraído de oer-master-view.js
 * en TASK-028. Mantiene el marcado de Chosen de Omeka para integrarse
 * visualmente, pero se alimenta de búsqueda incremental (NFR-004: el árbol no se
 * precarga nunca).
 *
 * `markDirty` y `disableApply` viven aquí, y no en ui/recatalog.js como decía el
 * brief, para que los imports no queden circulares: quien marca una dimensión
 * como modificada es este widget, y el panel solo lo consume.
 */

/** Pide insertar un chip desde fuera del módulo. Lo usa el propose IA, que aún
 * vive en oer-master-view.js; desaparece con él en la tarea 14. */
export const ADD_CHIP = 'oer:add-chip';

export function disableApply($panel) {
    $panel.find('.oer-recatalog-apply').prop('disabled', true);
}

/**
 * Marca una dimensión como modificada: solo las modificadas se envían al
 * confirmar (las demás no se tocan). Invalida el preview anterior.
 */
export function markDirty($dim) {
    $dim.attr('data-dirty', '1');
    disableApply($dim.closest('.oer-recatalog'));
}

/**
 * Chip de término seleccionado con el marcado de Chosen de Omeka
 * (chosen-container-multi), para integración visual nativa.
 */
export function buildSearchChoice(id, title, justification) {
    const $choice = $('<li>')
        .addClass('search-choice')
        .attr('data-id', id);
    // Justificación IA (TASK-023): viaja oculta con el chip hasta el apply;
    // no se muestra (decisión del propietario).
    if (justification) {
        $choice.attr('data-justification', justification);
    }
    return $choice
        .append($('<span>').text(title))
        .append(
            $('<a>')
                .addClass('search-choice-close')
                .attr('href', '#')
                .attr('role', 'button')
                .attr('aria-label', Omeka.jsTranslate('Quitar'))
        );
}

/**
 * Selector de términos por dimensión. Cada dimensión solo ofrece términos de su
 * propio metadato (la acotación la hace el endpoint search-terms por dimension).
 */
export function buildDimensionSelector(term, label, itemJson, isHelper, valueText) {
    const $dim = $('<div>').addClass('oer-recatalog-dim')
        .attr('data-term', term)
        .attr('data-dirty', '0')
        .attr('data-helper', isHelper ? '1' : '0');
    $dim.append($('<label>').text(label));

    const $choices = $('<ul>').addClass('chosen-choices');
    (itemJson[term] || []).forEach((value) => {
        if (value['value_resource_id']) {
            $choices.append(buildSearchChoice(value['value_resource_id'], valueText(value)));
        }
    });
    $choices.append(
        $('<li>').addClass('search-field').append(
            $('<input>')
                .addClass('chosen-search-input oer-term-search')
                .attr('type', 'text')
                .attr('autocomplete', 'off')
                .attr('placeholder', Omeka.jsTranslate('Buscar término…'))
        )
    );

    $dim.append(
        $('<div>').addClass('chosen-container chosen-container-multi')
            .css('width', '100%')
            .append($choices)
            .append($('<div>').addClass('chosen-drop').append($('<ul>').addClass('chosen-results')))
    );
    return $dim;
}

/** Primer término elegido en una dimensión del panel (id), o 0. */
export function firstChipId($panel, term) {
    const $chip = $panel.find(`.oer-recatalog-dim[data-term="${term}"] .chosen-choices .search-choice`).first();
    return $chip.length ? $chip.data('id') : 0;
}

/**
 * Contexto de ancestros para acotar la búsqueda de una dimensión hija
 * (ADR-0009): etapa (ayuda), curso (educationalLevel) y asignatura (about).
 */
export function getContext($panel) {
    return {
        etapa: firstChipId($panel, 'etapa'),
        level: firstChipId($panel, 'lrmi:educationalLevel'),
        about: firstChipId($panel, 'schema:about')
    };
}

/** Ids de los chips puestos hoy en cada dimensión del panel. */
export function chipIdsByTerm($panel) {
    const ids = {};
    $panel.find('.oer-recatalog-dim').each(function () {
        const $dim = $(this);
        ids[$dim.data('term')] = $dim.find('.chosen-choices .search-choice')
            .map(function () { return String($(this).attr('data-id')); })
            .get();
    });
    return ids;
}

/**
 * Segunda línea de un resultado: el linaje que lo desambigua (TASK-028). Los
 * nueve «Matemáticas» del catálogo solo se distinguen por su curso.
 */
function lineageOf(result) {
    return [result.parentTitle, result.block, result.description]
        .filter((part) => part && String(part).trim())
        .join(' · ');
}

function showDrop($container, results, existingIds) {
    const $results = $container.find('.chosen-results').empty();
    (results || []).forEach((result) => {
        const selected = -1 !== existingIds.indexOf(String(result.id));
        // El título va en data-title: el <li> puede llevar segunda línea y
        // tomar su .text() metería el linaje dentro del chip.
        const $item = $('<li>')
            .addClass(selected ? 'result-selected' : 'active-result')
            .attr('data-id', result.id)
            .attr('data-title', result.title)
            .append($('<span>').addClass('oer-term-title').text(result.title));
        const lineage = lineageOf(result);
        if (lineage) {
            $item.append($('<span>').addClass('oer-term-lineage').text(lineage));
        }
        $results.append($item);
    });
    if (!results || !results.length) {
        $results.append($('<li>').addClass('no-results').text(Omeka.jsTranslate('Sin resultados')));
    }
    $container.addClass('chosen-container-active chosen-with-drop');
}

function addChip($dim, id, title, justification) {
    if ($dim.find(`.chosen-choices .search-choice[data-id="${id}"]`).length) {
        return false;
    }
    $dim.find('.search-field').before(buildSearchChoice(id, title, justification));
    markDirty($dim);
    return true;
}

export function initTermPicker(config) {
    let searchTimer = null;

    $(document).on('input focus', '.oer-term-search', function () {
        const $input = $(this);
        const $dim = $input.closest('.oer-recatalog-dim');
        const $container = $input.closest('.chosen-container');
        const term = $dim.data('term');
        const text = String($input.val() || '');
        window.clearTimeout(searchTimer);
        if (text.length < 2) {
            $container.find('.chosen-results').empty();
            $container.removeClass('chosen-with-drop');
            return;
        }
        searchTimer = window.setTimeout(() => {
            // Acotación contextual (ADR-0009): se envían los ancestros elegidos.
            const params = getContext($dim.closest('.oer-recatalog'));
            params.dimension = term;
            params.q = text;
            $.getJSON(config.searchTermsUrl, params).done((response) => {
                const existing = $dim.find('.chosen-choices .search-choice')
                    .map(function () { return String($(this).data('id')); })
                    .get();
                showDrop($container, response.results, existing);
            });
        }, 250);
    });

    $(document).on('blur', '.oer-term-search', function () {
        const $container = $(this).closest('.chosen-container');
        // Retardo para que el click en un resultado se registre antes de cerrar.
        window.setTimeout(() => {
            $container.removeClass('chosen-with-drop chosen-container-active');
        }, 200);
    });

    $(document).on('click', '.chosen-results .active-result', function () {
        const $result = $(this);
        const $dim = $result.closest('.oer-recatalog-dim');
        const $container = $result.closest('.chosen-container');
        addChip($dim, $result.data('id'), $result.attr('data-title'));
        $container.find('.oer-term-search').val('').trigger('focus');
        $container.find('.chosen-results').empty();
        $container.removeClass('chosen-with-drop');
    });

    $(document).on('click', '.oer-recatalog .search-choice-close', function (e) {
        e.preventDefault();
        const $choice = $(this).closest('.search-choice');
        markDirty($choice.closest('.oer-recatalog-dim'));
        $choice.remove();
    });

    document.addEventListener(ADD_CHIP, (event) => {
        const { dim, id, title, justification } = event.detail;
        addChip($(dim), id, title, justification);
    });
}
