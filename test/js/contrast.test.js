import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { contrastRatio, parseTokens } from '../../asset/js/core/contrast.js';

const css = readFileSync(
  fileURLToPath(new URL('../../asset/css/oer-master-view.css', import.meta.url)),
  'utf8'
);
const tokens = parseTokens(css);

// El admin de Omeka pinta la tabla sobre blanco; las filas alternas usan el
// token de superficie. El estado debe leerse sobre las dos.
const BACKGROUNDS = ['#ffffff', tokens['--oer-surface']];
const AA = 4.5;

test('parseTokens lee los tokens del CSS real', () => {
  assert.equal(typeof tokens['--oer-ok'], 'string');
  assert.match(tokens['--oer-ok'], /^#[0-9a-f]{6}$/i);
});

test('contrastRatio devuelve los extremos conocidos', () => {
  assert.equal(Math.round(contrastRatio('#000000', '#ffffff')), 21);
  assert.equal(contrastRatio('#ffffff', '#ffffff'), 1);
});

test('contrastRatio es simétrico', () => {
  assert.equal(
    contrastRatio('#1d7a5f', '#ffffff').toFixed(4),
    contrastRatio('#ffffff', '#1d7a5f').toFixed(4)
  );
});

// ADR-0014: la tríada de estado es exigible a WCAG AA. Los valores se eligieron
// por criterio en la rebanada 1 y NO se habían medido nunca.
for (const token of ['--oer-ok', '--oer-warn', '--oer-bad', '--oer-muted']) {
  for (const background of BACKGROUNDS) {
    test(`${token} cumple AA sobre ${background}`, () => {
      const ratio = contrastRatio(tokens[token], background);
      assert.ok(
        ratio >= AA,
        `${token} (${tokens[token]}) da ${ratio.toFixed(2)}:1 sobre ${background}, por debajo de ${AA}:1`
      );
    });
  }
}
