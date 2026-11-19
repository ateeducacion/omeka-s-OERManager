import { test } from 'node:test';
import assert from 'node:assert/strict';
import { PANEL_TABS, DEFAULT_TAB, initialTab, keyTarget } from '../../asset/js/core/panelTabs.js';

test('las cuatro pestañas del panel, en orden, y Currículo por defecto', () => {
    assert.deepEqual(PANEL_TABS, ['curriculum', 'governance', 'resource', 'history']);
    assert.equal(DEFAULT_TAB, 'curriculum');
    assert.ok(Object.isFrozen(PANEL_TABS));
});

test('la pestaña recordada se reabre si este panel la tiene', () => {
    assert.equal(initialTab('history', ['curriculum', 'governance', 'resource', 'history']), 'history');
});

test('sin recuerdo, o con uno que este panel no tiene, se abre la primera', () => {
    const all = ['curriculum', 'governance', 'resource', 'history'];
    assert.equal(initialTab(null, all), 'curriculum');
    assert.equal(initialTab('', all), 'curriculum');
    assert.equal(initialTab('inventada', all), 'curriculum');
    assert.equal(initialTab('history', ['governance']), 'governance');
    assert.equal(initialTab('history', []), null);
});

test('las flechas recorren las pestañas en círculo; Inicio y Fin van a los extremos', () => {
    assert.equal(keyTarget('ArrowRight', 0, 4), 1);
    assert.equal(keyTarget('ArrowRight', 3, 4), 0);
    assert.equal(keyTarget('ArrowLeft', 0, 4), 3);
    assert.equal(keyTarget('ArrowLeft', 2, 4), 1);
    assert.equal(keyTarget('Home', 2, 4), 0);
    assert.equal(keyTarget('End', 1, 4), 3);
});

test('cualquier otra tecla no mueve la selección', () => {
    assert.equal(keyTarget('Enter', 1, 4), null);
    assert.equal(keyTarget('ArrowDown', 1, 4), null);
    assert.equal(keyTarget('ArrowRight', 0, 0), null);
});
