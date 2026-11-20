import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

/**
 * TASK-065 part B, final review C1: in read mode nothing of the editor may be
 * visible or usable. Otherwise a curator could add a course or run the AI
 * there, and «Confirmar y publicar» would send it without a preview.
 */
test('el modo lectura oculta todo el editor del anclaje', () => {
    const css = readFileSync(new URL('../../asset/css/oer-master-view.css', import.meta.url), 'utf8');
    const rule = css.match(/((?:\.oer-area-alignment\[data-mode="read"\][^{,]*,\s*)+[^{]*)\{\s*display:\s*none;/);
    assert.ok(rule, 'there is a read-mode hiding rule');
    ['.oer-align-editor', '.oer-recatalog-ai-bar', '.oer-recatalog-footer', '.oer-recatalog-dims'].forEach((part) => {
        assert.ok(rule[1].includes(`.oer-area-alignment[data-mode="read"] ${part}`), `${part} is hidden in read mode`);
    });
});
