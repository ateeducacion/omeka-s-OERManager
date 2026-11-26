import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { contrastRatio, parseTokens } from '../../asset/js/core/contrast.js';

// ADR-0025 addendum 1: the wizard inside the admin, with the module's tokens
// (ADR-0014), every text and state pair the stylesheet paints measured against
// WCAG 2.2 AA.
const css = readFileSync(fileURLToPath(new URL('../../asset/css/oer-wizard.css', import.meta.url)), 'utf8');
const tokens = parseTokens(css);
const TEXT = 4.5;
const UI = 3;

const PAIRS = [
  // Body text on every light ground.
  ...['--wz-ink', '--wz-body', '--wz-body-soft', '--wz-muted'].flatMap((fg) =>
    ['--wz-canvas', '--wz-surface', '--wz-card'].map((bg) => [fg, bg, TEXT])),
  // Disabled buttons: exempt in WCAG, measured anyway.
  ['--wz-body-soft', '--wz-disabled', TEXT],
  // The admin's grey button (rgba(0,0,0,.08) on white ≈ #EBEBEB): measured on the disabled grey, darker still.
  ['--wz-body', '--wz-disabled', TEXT],
  // Filled buttons, the chosen category and the step badge.
  ['--wz-on-dark', '--wz-strong', TEXT],
  ['--wz-on-dark', '--wz-strong-hover', TEXT],
  // The admin's red: focus, hover and what needs action.
  ['--wz-accent', '--wz-canvas', TEXT],
  ['--wz-mark', '--wz-surface-mark', TEXT],
  // States.
  ['--wz-error-ink', '--wz-error-soft', TEXT],
  ['--wz-ok-ink', '--wz-ok-soft', UI],
  ['--wz-ok-ink', '--wz-canvas', UI],
  // Control borders, selected borders and focus rings (UI boundaries).
  ['--wz-control', '--wz-canvas', UI],
  ['--wz-control', '--wz-surface', UI],
  ['--wz-strong', '--wz-card', UI],
  ['--wz-accent', '--wz-surface', UI],
];

test('every token the pairs use is declared once on .oer-wizard', () => {
  for (const [fg, bg] of PAIRS) {
    assert.match(tokens[fg] || '', /^#[0-9a-f]{6}$/i, fg);
    assert.match(tokens[bg] || '', /^#[0-9a-f]{6}$/i, bg);
  }
});

for (const [fg, bg, min] of PAIRS) {
  test(`${fg} on ${bg} reaches ${min}:1`, () => {
    const ratio = contrastRatio(tokens[fg], tokens[bg]);
    assert.ok(ratio >= min, `${fg} (${tokens[fg]}) on ${bg} (${tokens[bg]}) is ${ratio.toFixed(2)}:1, below ${min}:1`);
  });
}

test('the stylesheet brings no typeface of its own: it inherits the admin\'s Lato', () => {
  assert.doesNotMatch(css, /@font-face|@import|fonts\.(googleapis|gstatic)\.com/);
  assert.match(css, /--wz-sans:\s*"Lato", sans-serif;/);
});
