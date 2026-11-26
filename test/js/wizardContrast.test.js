import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { contrastRatio, parseTokens } from '../../asset/js/core/contrast.js';

// ADR-0025: the wizard's own palette (the public theme's), every text and
// state pair the stylesheet paints measured against WCAG 2.2 AA.
const css = readFileSync(fileURLToPath(new URL('../../asset/css/oer-wizard.css', import.meta.url)), 'utf8');
const tokens = parseTokens(css);
const TEXT = 4.5;
const UI = 3;

const PAIRS = [
  // Body text on every light ground.
  ...['--wz-ink', '--wz-body', '--wz-body-soft'].flatMap((fg) =>
    ['--wz-canvas', '--wz-surface', '--wz-card', '--wz-surface-yellow'].map((bg) => [fg, bg, TEXT])),
  // Help and optional marks: only on white and the light grey (on --wz-card it gives 4.4:1).
  ['--wz-muted', '--wz-canvas', TEXT],
  ['--wz-muted', '--wz-surface', TEXT],
  // Disabled buttons: exempt in WCAG, measured anyway.
  ['--wz-body-soft', '--wz-disabled', TEXT],
  // Links and the institutional blue.
  ['--wz-atlantic', '--wz-canvas', TEXT],
  ['--wz-atlantic', '--wz-card', TEXT],
  ['--wz-blue', '--wz-canvas', TEXT],
  ['--wz-blue', '--wz-card', TEXT],
  ['--wz-blue', '--wz-surface', TEXT],
  ['--wz-blue', '--wz-surface-yellow', TEXT],
  ['--wz-blue', '--wz-yellow', TEXT],
  ['--wz-blue', '--wz-yellow-hover', TEXT],
  // The hero band and the footer.
  ['--wz-on-dark', '--wz-blue', TEXT],
  ['--wz-on-dark', '--wz-blue-hover', TEXT],
  ['--wz-on-dark-soft', '--wz-blue', TEXT],
  ['--wz-yellow', '--wz-blue', TEXT],
  // Axis chips (owner's review of PR #100) and the hover ground of the axis checklist.
  ['--wz-blue', '--wz-card', UI],
  ['--wz-ink', '--wz-surface', TEXT],
  // States.
  ['--wz-error-ink', '--wz-error-soft', TEXT],
  ['--wz-ok-ink', '--wz-ok-soft', UI],
  // Control borders and focus rings (UI boundaries).
  ['--wz-control', '--wz-canvas', UI],
  ['--wz-control', '--wz-surface', UI],
  ['--wz-atlantic', '--wz-surface', UI],
  ['--wz-yellow', '--wz-blue', UI],
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

test('the stylesheet never loads a font from a third party', () => {
  assert.doesNotMatch(css, /fonts\.(googleapis|gstatic)\.com|@import/);
  assert.match(css, /url\("\.\.\/fonts\/inter-latin-wght-normal\.woff2"\)/);
});
