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
// token de superficie, y una fila seleccionada usa --oer-select, más oscuro
// que ambos (--oer-muted solo pasa con 0.14 de margen sobre ese fondo). El
// estado debe leerse sobre las tres.
const BACKGROUNDS = ['#ffffff', tokens['--oer-surface'], tokens['--oer-select']];
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
// --oer-accent: color de la insignia «Propuesto» en la tabla (RF-017).
for (const token of ['--oer-ok', '--oer-warn', '--oer-bad', '--oer-muted', '--oer-accent']) {
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

// ADR-0014, addendum 2026-10-03 (TASK-046): los tintes de las píldoras
// curriculares son decorativos, pero la materia se lee SOBRE ellos y el texto
// de cursos sobre blanco, así que ambos tienen que cumplir AA (NFR-006).
const tintTokens = Object.keys(tokens).filter((name) => /^--oer-tint-\d+$/.test(name));

test('la paleta de tintes tiene los 8 tonos que asigna SubjectTint', () => {
  assert.equal(tintTokens.length, 8);
});

for (const token of tintTokens) {
  test(`la tinta del módulo cumple AA sobre ${token}`, () => {
    const ratio = contrastRatio(tokens['--oer-ink'], tokens[token]);
    assert.ok(ratio >= AA, `--oer-ink sobre ${token} (${tokens[token]}) da ${ratio.toFixed(2)}:1`);
  });
}

// Los tintes no pueden confundirse con un estado (ADR-0014 regla 2): ni rojo ni ámbar.
function hue(hex) {
  const [r, g, b] = [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16) / 255);
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  if (max === min) {
    return null;
  }
  const d = max - min;
  const h = max === r ? ((g - b) / d) % 6 : max === g ? (b - r) / d + 2 : (r - g) / d + 4;
  return (h * 60 + 360) % 360;
}

for (const token of tintTokens) {
  test(`${token} no usa el matiz de los estados rojo ni ámbar`, () => {
    const h = hue(tokens[token]);
    assert.ok(h === null || (h > 70 && h < 330), `${token} tiene matiz ${h}`);
  });
}

// TASK-047 (spec D4): escala de calor de Estadísticas. Tinta sobre los niveles
// claros, blanco sobre los oscuros, siempre AA; y nunca rojo ni ámbar.
const heatTokens = Object.keys(tokens).filter((name) => /^--oer-heat-\d+$/.test(name));

test('la escala de calor tiene los 5 niveles que asigna heatLevel()', () => {
  assert.equal(heatTokens.length, 5);
});

for (const token of heatTokens) {
  const level = Number(token.match(/(\d+)$/)[1]);
  const text = level <= 3 ? tokens['--oer-ink'] : '#ffffff';
  test(`el texto de la celda cumple AA sobre ${token}`, () => {
    const ratio = contrastRatio(text, tokens[token]);
    assert.ok(ratio >= AA, `${text} sobre ${token} (${tokens[token]}) da ${ratio.toFixed(2)}:1`);
  });
  test(`${token} no usa el matiz de los estados rojo ni ámbar`, () => {
    const h = hue(tokens[token]);
    assert.ok(h === null || (h > 70 && h < 330), `${token} tiene matiz ${h}`);
  });
}
