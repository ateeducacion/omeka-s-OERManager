import { test } from 'node:test';
import assert from 'node:assert/strict';
import { panelAreas, AREA_LABELS, PANEL_ERROR_TEXT, MEDIA_EMPTY_TEXT, ALIGNMENT_EMPTY_TEXT } from '../../asset/js/core/detailAreas.js';

const panel = (extra = {}) => ({
    identity: { id: 1, title: 'REA', isPublic: true, thumbnail: null, editUrl: '/edit/1' },
    record: {},
    alignment: { groups: [], axes: [], orphans: [] },
    media: [],
    ...extra
});

const areaById = (areas, id) => areas.find((a) => a.id === id);

test('sin panel, todas las áreas quedan en estado desconocido', () => {
    const areas = panelAreas({ panel: null, integrity: null });
    assert.ok(areas.length > 0);
    assert.ok(areas.every((a) => a.state === 'unknown'));
});

test('un panel vacío da áreas vacías, no desconocidas', () => {
    const areas = panelAreas({ panel: panel(), integrity: { status: 'ok', issues: [] } });
    assert.equal(areaById(areas, 'media').state, 'empty');
    assert.equal(areaById(areas, 'alignment').state, 'empty');
});

test('el anclaje con grupos queda listo', () => {
    const areas = panelAreas({
        panel: panel({ alignment: { groups: [{ courseTitle: '3º ESO' }], axes: [], orphans: [] } }),
        integrity: { status: 'ok', issues: [] }
    });
    assert.equal(areaById(areas, 'alignment').state, 'ready');
});

test('el anclaje solo con huérfanos NO está vacío: hay algo que enseñar', () => {
    const areas = panelAreas({
        panel: panel({ alignment: { groups: [], axes: [], orphans: [{ title: '4º ESO' }] } }),
        integrity: { status: 'ok', issues: [] }
    });
    assert.equal(areaById(areas, 'alignment').state, 'ready');
});

test('los ejes solos también cuentan como contenido', () => {
    const areas = panelAreas({
        panel: panel({ alignment: { groups: [], axes: ['Patrimonio'], orphans: [] } }),
        integrity: { status: 'ok', issues: [] }
    });
    assert.equal(areaById(areas, 'alignment').state, 'ready');
});

test('con medios, el área de medios queda lista y conserva su lista', () => {
    const media = [{ title: 'guia.pdf', type: 'application/pdf', size: 10, url: '/f/guia.pdf' }];
    const areas = panelAreas({ panel: panel({ media }), integrity: { status: 'ok', issues: [] } });
    const area = areaById(areas, 'media');
    assert.equal(area.state, 'ready');
    assert.deepEqual(area.media, media);
});

test('la integridad no comprobada es desconocida, no sana', () => {
    const areas = panelAreas({ panel: panel(), integrity: null });
    assert.equal(areaById(areas, 'integrity').state, 'unknown');
});

test('el orden de las áreas es estable y empieza por el anclaje', () => {
    const areas = panelAreas({ panel: panel(), integrity: { status: 'ok', issues: [] } });
    assert.deepEqual(areas.map((a) => a.id), ['alignment', 'media', 'record', 'integrity']);
});

test('cada área tiene etiqueta traducible', () => {
    panelAreas({ panel: panel(), integrity: null }).forEach((area) => {
        assert.equal(typeof AREA_LABELS[area.id], 'string');
        assert.ok(AREA_LABELS[area.id].length > 0);
    });
});

test('el texto de error del panel existe', () => {
    assert.equal(typeof PANEL_ERROR_TEXT, 'string');
    assert.ok(PANEL_ERROR_TEXT.length > 0);
});

test('el texto vacío de medios existe', () => {
    assert.equal(typeof MEDIA_EMPTY_TEXT, 'string');
    assert.ok(MEDIA_EMPTY_TEXT.length > 0);
});

test('el texto vacío de anclaje existe', () => {
    assert.equal(typeof ALIGNMENT_EMPTY_TEXT, 'string');
    assert.ok(ALIGNMENT_EMPTY_TEXT.length > 0);
});
