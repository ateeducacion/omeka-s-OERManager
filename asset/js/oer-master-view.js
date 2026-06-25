/**
 * Vista maestra del catálogo REA (TASK-003, ADR-0005 §6-7). Capa jQuery
 * fina: drawer de detalle (GET /api/items/{id}) y curación de visibilidad
 * individual/lote (POST a la acción set-visibility del controlador).
 */
(function ($) {
    'use strict';

    var DRAWER_FIELDS = [
        ['dcterms:description', 'Descripción'],
        ['lrmi:educationalLevel', 'Etapa'],
        ['schema:about', 'Materia'],
        ['lrmi:assesses', 'Criterios de evaluación'],
        ['lrmi:teaches', 'Saberes básicos'],
        ['dcterms:relation', 'Eje temático'],
        ['schema:isPartOf', 'Proyecto'],
        ['lrmi:learningResourceType', 'Tipo de recurso'],
        ['dcterms:rights', 'Licencia']
    ];

    // Properties que el re-catalogador puede escribir (ADR-0004/0009); el
    // proyecto (schema:isPartOf) queda fuera (acción de gestor aparte). El
    // educationalLevel del REA referencia un Curso (ADR-0009).
    var RECATALOG_DIMENSIONS = [
        ['lrmi:educationalLevel', 'Curso'],
        ['schema:about', 'Asignatura'],
        ['lrmi:teaches', 'Saberes básicos'],
        ['lrmi:assesses', 'Criterios de evaluación'],
        ['dcterms:relation', 'Eje temático']
    ];

    function valueText(value) {
        if (value['display_title']) {
            return value['display_title'];
        }
        if (value['@value']) {
            return value['@value'];
        }
        return value['o:label'] || '';
    }

    function canRecatalog() {
        return $('#oer-master-view-table').data('can-recatalog') === 1
            || $('#oer-master-view-table').data('can-recatalog') === '1';
    }

    // Chip de término seleccionado con el marcado de Chosen de Omeka
    // (chosen-container-multi), para integración visual nativa.
    function buildSearchChoice(id, title) {
        return $('<li>')
            .addClass('search-choice')
            .attr('data-id', id)
            .append($('<span>').text(title))
            .append(
                $('<a>')
                    .addClass('search-choice-close')
                    .attr('href', '#')
                    .attr('role', 'button')
                    .attr('aria-label', Omeka.jsTranslate('Quitar'))
            );
    }

    // Selector de términos por dimensión, con la apariencia del widget Chosen de
    // Omeka pero alimentado por búsqueda incremental AJAX (NFR-004: nunca se
    // precarga el árbol). Cada dimensión solo ofrece términos de su propio
    // metadato (la acotación la hace el endpoint search-terms por dimension).
    function buildDimensionSelector(term, label, itemJson, isHelper) {
        var $dim = $('<div>').addClass('oer-recatalog-dim')
            .attr('data-term', term)
            .attr('data-dirty', '0')
            .attr('data-helper', isHelper ? '1' : '0');
        $dim.append($('<label>').text(label));

        var $choices = $('<ul>').addClass('chosen-choices');
        (itemJson[term] || []).forEach(function (value) {
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

    // Panel de re-catalogación (TASK-004): selectores Chosen precargados con el
    // alineamiento actual + preview + confirmación. Solo se envían al servidor
    // las dimensiones que el curador modifica (data-dirty), así que confirmar
    // nunca toca properties no editadas (isPartial las deja intactas).
    function buildRecatalogPanel(itemId, itemJson) {
        var $panel = $('<div>').addClass('oer-recatalog').attr('data-item-id', itemId);
        $panel.append($('<h4>').text(Omeka.jsTranslate('Re-catalogar')));

        // Etapa: ayuda de navegación de la cascada (ADR-0009). No se escribe en
        // el REA; solo acota Curso → Asignatura → Saberes/Criterios.
        $panel.append(
            buildDimensionSelector('etapa', Omeka.jsTranslate('Etapa (ayuda, no se guarda)'), {}, true)
        );
        RECATALOG_DIMENSIONS.forEach(function (dimension) {
            $panel.append(buildDimensionSelector(dimension[0], dimension[1], itemJson, false));
        });

        $panel.append(
            $('<div>').addClass('oer-recatalog-actions')
                .append($('<button>').attr('type', 'button').addClass('oer-recatalog-preview')
                    .text(Omeka.jsTranslate('Previsualizar cambios')))
                .append($('<button>').attr('type', 'button').addClass('oer-recatalog-apply')
                    .prop('disabled', true)
                    .text(Omeka.jsTranslate('Confirmar')))
        );
        $panel.append($('<div>').addClass('oer-recatalog-diff'));
        return $panel;
    }

    function renderDrawer(itemId, itemJson) {
        var $content = $('<div>');
        var title = itemJson['o:title'] || itemJson['dcterms:title'] && itemJson['dcterms:title'][0] && valueText(itemJson['dcterms:title'][0]) || '';
        $content.append($('<h3>').text(title));
        $content.append(
            $('<span>')
                .addClass('oer-drawer-visibility')
                .text(itemJson['o:is_public'] ? 'Público' : 'Privado')
        );

        var $dl = $('<dl>');
        DRAWER_FIELDS.forEach(function (field) {
            var term = field[0];
            var label = field[1];
            var values = itemJson[term] || [];
            if (!values.length) {
                return;
            }
            $dl.append($('<dt>').text(label));
            var text = values.map(valueText).join(', ');
            $dl.append($('<dd>').text(text));
        });
        $content.append($dl);

        if (canRecatalog()) {
            $content.append(buildRecatalogPanel(itemId, itemJson));
        }
        return $content;
    }

    function openDrawer(apiUrl, itemId) {
        var $drawer = $('#oer-drawer');
        var $content = $drawer.find('.oer-drawer-content');
        $content.empty().text(Omeka.jsTranslate('Cargando…'));
        $drawer.prop('hidden', false).attr('aria-hidden', 'false');
        $drawer.data('last-item-id', itemId);
        $drawer.find('.oer-drawer-close').trigger('focus');

        $.getJSON(apiUrl).done(function (itemJson) {
            $content.empty().append(renderDrawer(itemId, itemJson));
        }).fail(function () {
            $content.empty().text(Omeka.jsTranslate('No se ha podido cargar el detalle.'));
        });
    }

    function closeDrawer($trigger) {
        var $drawer = $('#oer-drawer');
        $drawer.prop('hidden', true).attr('aria-hidden', 'true');
        if ($trigger && $trigger.length) {
            $trigger.trigger('focus');
        }
    }

    function setVisibility($table, ids, isPublic, onDone) {
        var url = $table.data('set-visibility-url');
        $.post(url, {
            id: ids.length === 1 ? ids[0] : undefined,
            resource_ids: ids,
            is_public: isPublic ? '1' : ''
        }).done(function (response) {
            onDone(response);
        }).fail(function () {
            onDone({ updated: [], denied: ids });
        });
    }

    $(document).on('click', '.oer-open-drawer', function (e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        openDrawer($row.data('api-url'), $row.data('resource-id'));
    });

    $(document).on('click', '.oer-drawer-close', function () {
        closeDrawer($('.oer-open-drawer[data-item-id="' + $('#oer-drawer').data('last-item-id') + '"]'));
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && !$('#oer-drawer').prop('hidden')) {
            closeDrawer();
        }
    });

    $(document).on('change', '.oer-select-all', function () {
        var checked = $(this).prop('checked');
        $('.oer-row-select').prop('checked', checked);
    });

    $(document).on('click', '.oer-batch-set-public', function () {
        var $table = $('#oer-master-view-table');
        var isPublic = $(this).data('is-public') === 1 || $(this).data('is-public') === '1';
        var ids = $table.find('.oer-row-select:checked').map(function () {
            return $(this).val();
        }).get();
        if (!ids.length) {
            return;
        }
        var count = ids.length;
        var message = isPublic
            ? Omeka.jsTranslate('¿Hacer público %1$s REA?').replace('%1$s', count)
            : Omeka.jsTranslate('¿Hacer privado %1$s REA?').replace('%1$s', count);
        if (!window.confirm(message)) {
            return;
        }
        setVisibility($table, ids, isPublic, function (response) {
            response.updated.forEach(function (id) {
                $table.find('tr[data-resource-id="' + id + '"] .column-is_public').text(isPublic ? 'Sí' : 'No');
            });
            if (response.denied && response.denied.length) {
                window.alert(Omeka.jsTranslate('No se pudieron actualizar %1$s REA por falta de permiso.').replace('%1$s', response.denied.length));
            }
        });
    });

    // --- Re-catalogador (TASK-004): selector Chosen + dirty-tracking + preview.

    function disableApply($panel) {
        $panel.find('.oer-recatalog-apply').prop('disabled', true);
    }

    // Marca una dimensión como modificada: solo las modificadas se envían al
    // confirmar (las demás no se tocan). Invalida el preview anterior.
    function markDirty($dim) {
        $dim.attr('data-dirty', '1');
        disableApply($dim.closest('.oer-recatalog'));
    }

    // Primer término elegido en una dimensión del panel (id), o 0.
    function firstChipId($panel, term) {
        var $chip = $panel.find('.oer-recatalog-dim[data-term="' + term + '"] .chosen-choices .search-choice').first();
        return $chip.length ? $chip.data('id') : 0;
    }

    // Contexto de ancestros para acotar la búsqueda de una dimensión hija
    // (ADR-0009): etapa (ayuda), curso (educationalLevel) y asignatura (about).
    function getContext($panel) {
        return {
            etapa: firstChipId($panel, 'etapa'),
            level: firstChipId($panel, 'lrmi:educationalLevel'),
            about: firstChipId($panel, 'schema:about')
        };
    }

    function collectAlignmentPairs($panel) {
        var pairs = [{ name: 'id', value: $panel.data('item-id') }];
        // La Etapa (data-helper) no se escribe: se excluye de la confirmación.
        $panel.find('.oer-recatalog-dim[data-dirty="1"][data-helper="0"]').each(function () {
            var $dim = $(this);
            var term = $dim.data('term');
            var ids = $dim.find('.chosen-choices .search-choice').map(function () {
                return $(this).data('id');
            }).get();
            if (!ids.length) {
                // Dimensión modificada y vaciada: borrado intencional explícito.
                pairs.push({ name: 'alignment[' + term + '][]', value: '' });
            } else {
                ids.forEach(function (id) {
                    pairs.push({ name: 'alignment[' + term + '][]', value: id });
                });
            }
        });
        return pairs;
    }

    function showDrop($container, results, existingIds) {
        var $results = $container.find('.chosen-results').empty();
        (results || []).forEach(function (result) {
            var selected = existingIds.indexOf(String(result.id)) !== -1;
            $results.append(
                $('<li>')
                    .addClass(selected ? 'result-selected' : 'active-result')
                    .attr('data-id', result.id)
                    .text(result.title)
            );
        });
        if (!results || !results.length) {
            $results.append($('<li>').addClass('no-results').text(Omeka.jsTranslate('Sin resultados')));
        }
        $container.addClass('chosen-container-active chosen-with-drop');
    }

    var searchTimer = null;
    $(document).on('input focus', '.oer-term-search', function () {
        var $input = $(this);
        var $dim = $input.closest('.oer-recatalog-dim');
        var $container = $input.closest('.chosen-container');
        var term = $dim.data('term');
        var text = String($input.val() || '');
        window.clearTimeout(searchTimer);
        if (text.length < 2) {
            $container.find('.chosen-results').empty();
            $container.removeClass('chosen-with-drop');
            return;
        }
        searchTimer = window.setTimeout(function () {
            var url = $('#oer-master-view-table').data('search-terms-url');
            // Acotación contextual (ADR-0009): se envían los ancestros elegidos.
            var params = getContext($dim.closest('.oer-recatalog'));
            params.dimension = term;
            params.q = text;
            $.getJSON(url, params).done(function (response) {
                var existing = $dim.find('.chosen-choices .search-choice').map(function () {
                    return String($(this).data('id'));
                }).get();
                showDrop($container, response.results, existing);
            });
        }, 250);
    });

    $(document).on('blur', '.oer-term-search', function () {
        var $container = $(this).closest('.chosen-container');
        // Retardo para que el click en un resultado se registre antes de cerrar.
        window.setTimeout(function () {
            $container.removeClass('chosen-with-drop chosen-container-active');
        }, 200);
    });

    $(document).on('click', '.chosen-results .active-result', function () {
        var $result = $(this);
        var $dim = $result.closest('.oer-recatalog-dim');
        var $container = $result.closest('.chosen-container');
        var id = $result.data('id');
        if (!$dim.find('.chosen-choices .search-choice[data-id="' + id + '"]').length) {
            $dim.find('.search-field').before(buildSearchChoice(id, $result.text()));
            markDirty($dim);
        }
        $container.find('.oer-term-search').val('').trigger('focus');
        $container.find('.chosen-results').empty();
        $container.removeClass('chosen-with-drop');
    });

    $(document).on('click', '.oer-recatalog .search-choice-close', function (e) {
        e.preventDefault();
        var $choice = $(this).closest('.search-choice');
        markDirty($choice.closest('.oer-recatalog-dim'));
        $choice.remove();
    });

    function renderDiff($panel, diff) {
        var $diff = $panel.find('.oer-recatalog-diff').empty();
        var hasInvalid = false;
        RECATALOG_DIMENSIONS.forEach(function (dimension) {
            var entry = diff[dimension[0]];
            if (!entry) {
                return;
            }
            var $row = $('<p>').addClass('oer-diff-row');
            $row.append($('<strong>').text(dimension[1] + ': '));
            $row.append(document.createTextNode(
                Omeka.jsTranslate('+%1$s / -%2$s')
                    .replace('%1$s', entry.added.length)
                    .replace('%2$s', entry.removed.length)
            ));
            if (entry.invalid && entry.invalid.length) {
                hasInvalid = true;
                $row.append($('<span>').addClass('oer-diff-invalid')
                    .text(' ' + Omeka.jsTranslate('destinos inválidos: ') + entry.invalid.join(', ')));
            }
            $diff.append($row);
        });
        return !hasInvalid;
    }

    $(document).on('click', '.oer-recatalog-preview', function () {
        var $panel = $(this).closest('.oer-recatalog');
        var pairs = collectAlignmentPairs($panel);
        if (pairs.length <= 1) {
            $panel.find('.oer-recatalog-diff').text(Omeka.jsTranslate('No hay cambios que confirmar.'));
            disableApply($panel);
            return;
        }
        var url = $('#oer-master-view-table').data('recatalog-preview-url');
        $.post(url, $.param(pairs)).done(function (response) {
            var ok = renderDiff($panel, response.diff || {});
            $panel.find('.oer-recatalog-apply').prop('disabled', !ok);
        }).fail(function () {
            $panel.find('.oer-recatalog-diff').text(Omeka.jsTranslate('No se pudo previsualizar.'));
        });
    });

    $(document).on('click', '.oer-recatalog-apply', function () {
        var $panel = $(this).closest('.oer-recatalog');
        if (!window.confirm(Omeka.jsTranslate('¿Confirmar la re-catalogación de este REA?'))) {
            return;
        }
        var url = $('#oer-master-view-table').data('recatalog-apply-url');
        var itemId = $panel.data('item-id');
        var apiUrl = $('tr[data-resource-id="' + itemId + '"]').data('api-url');
        var pairs = collectAlignmentPairs($panel);
        pairs.push({ name: 'csrf', value: $('#oer-master-view-table').data('recatalog-csrf') });
        $.post(url, $.param(pairs)).done(function (response) {
            if (response.updated) {
                openDrawer(apiUrl, itemId);
                return;
            }
            var messages = {
                csrf: Omeka.jsTranslate('Token de seguridad caducado: recarga la página.'),
                denied: Omeka.jsTranslate('No tienes permiso para re-catalogar.'),
                unexpected: Omeka.jsTranslate('Error inesperado; inténtalo de nuevo.')
            };
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar: ')
                + (messages[response.error] || response.error || ''));
        }).fail(function () {
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar.'));
        });
    });
})(jQuery);
