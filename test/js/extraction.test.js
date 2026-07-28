import test from 'node:test';
import assert from 'node:assert/strict';
import { extractionSummary } from '../../asset/js/core/extraction.js';

test('sin datos devuelve cadena vacía', () => {
    assert.equal(extractionSummary(null), '');
});

test('sin fuentes lo dice explícitamente', () => {
    assert.equal(extractionSummary({ sources: [], skipped: {} }), 'leídos: (ninguno)');
});

test('lista fuentes y saltados con su motivo', () => {
    const summary = extractionSummary({
        sources: ['guia.pdf'],
        skipped: { 'foto.png': 'unsupported', 'escaneo.pdf': 'pdf_iconv_unsupported' }
    });
    assert.equal(
        summary,
        'leídos: guia.pdf | saltados: foto.png → unsupported; escaneo.pdf → pdf_iconv_unsupported'
    );
});
