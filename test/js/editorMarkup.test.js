import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * TASK-065 B8: the admin's style.css styles bare `header` (fixed 18.75%
 * sidebar, 100vh) and `footer` (18.75% left margin, right-aligned 12px), so
 * the editor must not build them: they would inherit the admin's page layout.
 */
test('el editor no crea <header> ni <footer> sueltos', () => {
    const js = readFileSync(new URL('../../asset/js/ui/alignmentEditor.js', import.meta.url), 'utf8');
    assert.ok(!/\$\('<header>'\)/.test(js), 'no $(\'<header>\')');
    assert.ok(!/\$\('<footer>'\)/.test(js), 'no $(\'<footer>\')');
});
