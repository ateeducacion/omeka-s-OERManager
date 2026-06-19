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

    function valueText(value) {
        if (value['display_title']) {
            return value['display_title'];
        }
        if (value['@value']) {
            return value['@value'];
        }
        return value['o:label'] || '';
    }

    function renderDrawer(itemJson) {
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
        return $content;
    }

    function openDrawer(apiUrl, itemId) {
        var $drawer = $('#oer-drawer');
        var $content = $drawer.find('.oer-drawer-content');
        $drawer.data('last-item-id', itemId);
        $content.empty().text(Omeka.jsTranslate('Cargando…'));
        $drawer.prop('hidden', false).attr('aria-hidden', 'false');
        $drawer.find('.oer-drawer-close').trigger('focus');

        $.getJSON(apiUrl).done(function (itemJson) {
            $content.empty().append(renderDrawer(itemJson));
        }).fail(function () {
            $content.empty().text(Omeka.jsTranslate('No se ha podido cargar el detalle.'));
        });
    }

    function closeDrawer() {
        var $drawer = $('#oer-drawer');
        var lastId = $drawer.data('last-item-id');
        $drawer.prop('hidden', true).attr('aria-hidden', 'true');
        if (lastId) {
            $('.oer-open-drawer[data-item-id="' + lastId + '"]').trigger('focus');
        }
    }

    function setVisibility($table, ids, isPublic, onDone) {
        var url = $table.data('set-visibility-url');
        $.post(url, {
            id: ids.length === 1 ? ids[0] : undefined,
            resource_ids: ids,
            is_public: isPublic ? '1' : '',
            oer_visibility_csrf: $table.data('csrf-token')
        }).done(function (response) {
            onDone(response);
        }).fail(function () {
            onDone({ updated: [], denied: ids });
        });
    }

    $(document).on('click', '.oer-open-drawer', function (e) {
        e.preventDefault();
        var $row = $(this).closest('tr');
        openDrawer($row.data('api-url'), $(this).data('item-id'));
    });

    $(document).on('click', '.oer-drawer-close', function () {
        closeDrawer();
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
})(jQuery);
