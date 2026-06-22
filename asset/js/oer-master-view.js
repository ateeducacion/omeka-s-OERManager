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

    // Properties que el re-catalogador puede escribir (ADR-0004); el proyecto
    // (schema:isPartOf) queda fuera a propósito (acción de gestor aparte).
    var RECATALOG_DIMENSIONS = [
        ['lrmi:educationalLevel', 'Etapa'],
        ['schema:about', 'Materia'],
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

    function buildChip(id, title) {
        return $('<li>')
            .addClass('oer-chip')
            .attr('data-id', id)
            .text(title + ' ')
            .append(
                $('<button>')
                    .attr('type', 'button')
                    .addClass('oer-chip-remove')
                    .attr('aria-label', Omeka.jsTranslate('Quitar'))
                    .text('×')
            );
    }

    // Panel de re-catalogación (TASK-004): chips precargados con el alineamiento
    // actual + autocomplete por dimensión + preview + confirmación.
    function buildRecatalogPanel(itemId, itemJson) {
        var $panel = $('<div>').addClass('oer-recatalog').attr('data-item-id', itemId);
        $panel.append($('<h4>').text(Omeka.jsTranslate('Re-catalogar')));

        RECATALOG_DIMENSIONS.forEach(function (dimension) {
            var term = dimension[0];
            var label = dimension[1];
            var $dim = $('<div>').addClass('oer-recatalog-dim').attr('data-term', term);
            $dim.append($('<label>').text(label));

            var $chips = $('<ul>').addClass('oer-chips');
            (itemJson[term] || []).forEach(function (value) {
                if (value['value_resource_id']) {
                    $chips.append(buildChip(value['value_resource_id'], valueText(value)));
                }
            });
            $dim.append($chips);
            $dim.append(
                $('<input>')
                    .attr('type', 'text')
                    .addClass('oer-term-search')
                    .attr('placeholder', Omeka.jsTranslate('Buscar término…'))
            );
            $dim.append($('<ul>').addClass('oer-suggestions'));
            $panel.append($dim);
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

    // --- Re-catalogador (TASK-004): autocomplete + chips + preview + confirmar.

    function collectAlignmentPairs($panel) {
        var pairs = [{ name: 'id', value: $panel.data('item-id') }];
        RECATALOG_DIMENSIONS.forEach(function (dimension) {
            var term = dimension[0];
            var $dim = $panel.find('.oer-recatalog-dim[data-term="' + term + '"]');
            var ids = $dim.find('.oer-chips .oer-chip').map(function () {
                return $(this).data('id');
            }).get();
            if (!ids.length) {
                // Dimensión presente pero vacía: borrado intencional de la property.
                pairs.push({ name: 'alignment[' + term + '][]', value: '' });
            } else {
                ids.forEach(function (id) {
                    pairs.push({ name: 'alignment[' + term + '][]', value: id });
                });
            }
        });
        return pairs;
    }

    function disableApply($panel) {
        $panel.find('.oer-recatalog-apply').prop('disabled', true);
    }

    var searchTimer = null;
    $(document).on('input', '.oer-term-search', function () {
        var $input = $(this);
        var $dim = $input.closest('.oer-recatalog-dim');
        var term = $dim.data('term');
        var text = $input.val();
        var $suggestions = $dim.find('.oer-suggestions');
        window.clearTimeout(searchTimer);
        if (!text || text.length < 2) {
            $suggestions.empty();
            return;
        }
        searchTimer = window.setTimeout(function () {
            var url = $('#oer-master-view-table').data('search-terms-url');
            $.getJSON(url, { dimension: term, q: text }).done(function (response) {
                $suggestions.empty();
                (response.results || []).forEach(function (result) {
                    $suggestions.append(
                        $('<li>').addClass('oer-suggestion').attr('data-id', result.id).text(result.title)
                    );
                });
            });
        }, 250);
    });

    $(document).on('click', '.oer-suggestion', function () {
        var $suggestion = $(this);
        var $dim = $suggestion.closest('.oer-recatalog-dim');
        var id = $suggestion.data('id');
        if (!$dim.find('.oer-chips .oer-chip[data-id="' + id + '"]').length) {
            $dim.find('.oer-chips').append(buildChip(id, $suggestion.text()));
        }
        $dim.find('.oer-term-search').val('');
        $dim.find('.oer-suggestions').empty();
        disableApply($dim.closest('.oer-recatalog'));
    });

    $(document).on('click', '.oer-chip-remove', function () {
        var $chip = $(this).closest('.oer-chip');
        disableApply($chip.closest('.oer-recatalog'));
        $chip.remove();
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
        var url = $('#oer-master-view-table').data('recatalog-preview-url');
        $.post(url, $.param(collectAlignmentPairs($panel))).done(function (response) {
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
        $.post(url, $.param(collectAlignmentPairs($panel))).done(function (response) {
            if (response.updated) {
                openDrawer(apiUrl, itemId);
            } else {
                window.alert(Omeka.jsTranslate('No se pudo re-catalogar: ') + (response.error || ''));
            }
        }).fail(function () {
            window.alert(Omeka.jsTranslate('No se pudo re-catalogar.'));
        });
    });
})(jQuery);
