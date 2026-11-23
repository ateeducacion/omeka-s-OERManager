import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readdirSync, readFileSync } from 'node:fs';

/**
 * TASK-065: every admin view that opens the detail panel must load the
 * stylesheets the panel is drawn with. «Mis propuestas» opened the same panel
 * as the master view but without the alignment editor's own look (ADR-0024),
 * so the proposer saw the editor unstyled.
 */
const VIEWS = new URL('../../view/oer-manager/admin/index/', import.meta.url);
const PANEL_STYLESHEETS = ['css/oer-master-view.css', 'css/oer-alignment-editor.css'];

const panelViews = readdirSync(VIEWS)
    .filter((name) => name.endsWith('.phtml'))
    .map((name) => ({ name, source: readFileSync(new URL(name, VIEWS), 'utf8') }))
    .filter(({ source }) => source.includes('data-sidebar-content-url'));

test('hay vistas que abren el panel de detalle', () => {
    const names = panelViews.map(({ name }) => name);
    assert.ok(names.includes('index.phtml'), 'the master view opens the panel');
    assert.ok(names.includes('my-proposals.phtml'), '«Mis propuestas» opens the panel');
});

for (const { name, source } of panelViews) {
    test(`${name} carga las hojas de estilo del panel`, () => {
        PANEL_STYLESHEETS.forEach((sheet) => {
            assert.ok(
                source.includes(`assetUrl('${sheet}', 'OERManager')`),
                `${name} loads ${sheet}`
            );
        });
    });
}
