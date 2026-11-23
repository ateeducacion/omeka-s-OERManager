import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { contrastRatio, parseTokens } from '../../asset/js/core/contrast.js';

/**
 * ADR-0024 point 3: the editor's own palette is measured, not chosen by eye.
 * Text pairs need 4.5:1 (WCAG 1.4.3); control boundaries 3:1 (WCAG 1.4.11).
 */
const css = readFileSync(new URL('../../asset/css/oer-alignment-editor.css', import.meta.url), 'utf8');
const t = parseTokens(css);

const TEXT = [
    ['--ae-ink', '--ae-surface'], ['--ae-ink', '--ae-ground'], ['--ae-ink', '--ae-accent-soft'],
    ['--ae-muted', '--ae-surface'], ['--ae-muted', '--ae-ground'], ['--ae-muted', '--ae-accent-soft'],
    ['--ae-accent-ink', '--ae-surface'], ['--ae-accent-ink', '--ae-accent-soft'], ['--ae-on-accent', '--ae-accent'],
    ['--ae-ai', '--ae-ai-soft'], ['--ae-ai', '--ae-surface'],
    ['--ae-unfit', '--ae-unfit-soft'],
    ['--ae-added', '--ae-added-soft'], ['--ae-removed', '--ae-removed-soft'],
    ['--ae-danger', '--ae-surface']
];
const BOUNDARY = [['--ae-control', '--ae-surface'], ['--ae-control', '--ae-ground'], ['--ae-accent', '--ae-surface']];

test('los tokens del editor existen', () => {
    TEXT.concat(BOUNDARY).flat().forEach((name) => assert.match(t[name] || '', /^#[0-9a-f]{6}$/i, name));
});

test('todo texto del editor pasa AA (4.5:1)', () => {
    TEXT.forEach(([fg, bg]) => assert.ok(contrastRatio(t[fg], t[bg]) >= 4.5, `${fg} on ${bg}: ${contrastRatio(t[fg], t[bg]).toFixed(2)}`));
});

test('el contorno de los controles pasa 3:1', () => {
    BOUNDARY.forEach(([fg, bg]) => assert.ok(contrastRatio(t[fg], t[bg]) >= 3, `${fg} on ${bg}: ${contrastRatio(t[fg], t[bg]).toFixed(2)}`));
});
