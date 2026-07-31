import test from 'node:test';
import assert from 'node:assert/strict';
import { undoLabel } from '../../asset/js/core/curationEvent.js';

test('el botón dice de cuándo y de quién es lo que se va a deshacer', () => {
    assert.equal(
        undoLabel({ when: '2026-07-30T10:31:00+00:00', contributor: 'fmatdia' }),
        'Deshacer la última re-catalogación (30/07/2026 10:31 · fmatdia)'
    );
});

test('sin contribuyente no se pinta un separador huérfano', () => {
    assert.equal(
        undoLabel({ when: '2026-07-30T10:31:00+00:00', contributor: '' }),
        'Deshacer la última re-catalogación (30/07/2026 10:31)'
    );
});

test('una fecha ilegible no rompe el botón', () => {
    assert.equal(
        undoLabel({ when: 'no soy una fecha', contributor: 'fmatdia' }),
        'Deshacer la última re-catalogación (fmatdia)'
    );
});

test('sin evento no hay etiqueta', () => {
    assert.equal(undoLabel(null), '');
});
