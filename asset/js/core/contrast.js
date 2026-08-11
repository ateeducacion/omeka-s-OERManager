/**
 * Contraste WCAG de los tokens de color del módulo.
 *
 * ADR-0014 declara la tríada de estado exigible a WCAG AA y deja la medición
 * como obligación de la rebanada que estrene la columna de integridad. Se hace
 * por test y no a ojo: así la obligación se rompe sola si alguien mueve un
 * hexadecimal, en vez de quedarse como nota en un documento.
 *
 * Núcleo puro, sin DOM: la frontera que la rebanada 1 estableció para el JS.
 */

/** @param {string} hex `#rgb` o `#rrggbb` @returns {[number,number,number]} */
function toRgb(hex) {
  const value = String(hex).trim().replace(/^#/, '');
  const full = value.length === 3
    ? value.split('').map((c) => c + c).join('')
    : value;
  if (!/^[0-9a-f]{6}$/i.test(full)) {
    throw new TypeError(`Color no reconocido: ${hex}`);
  }
  return [0, 2, 4].map((i) => parseInt(full.slice(i, i + 2), 16));
}

/** Luminancia relativa, WCAG 2.1 §relative luminance. */
function luminance(hex) {
  const [r, g, b] = toRgb(hex).map((channel) => {
    const c = channel / 255;
    return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
  });
  return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}

/** Ratio de contraste entre dos colores. 1 = idénticos, 21 = negro sobre blanco. */
export function contrastRatio(a, b) {
  const la = luminance(a);
  const lb = luminance(b);
  const lighter = Math.max(la, lb);
  const darker = Math.min(la, lb);
  return (lighter + 0.05) / (darker + 0.05);
}

/**
 * Extrae las custom properties de un texto CSS.
 * Una sola fuente de verdad: los colores viven en el CSS, no duplicados aquí.
 *
 * @param {string} cssText
 * @returns {Record<string,string>}
 */
export function parseTokens(cssText) {
  const tokens = {};
  const pattern = /(--[\w-]+)\s*:\s*(#[0-9a-fA-F]{3,8})\s*;/g;
  let match = pattern.exec(cssText);
  while (match !== null) {
    tokens[match[1]] = match[2];
    match = pattern.exec(cssText);
  }
  return tokens;
}
