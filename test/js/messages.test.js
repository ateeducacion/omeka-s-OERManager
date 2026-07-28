import test from 'node:test';
import assert from 'node:assert/strict';
import { messageFor } from '../../asset/js/core/messages.js';

test('devuelve el mensaje del código conocido', () => {
    assert.equal(messageFor('csrf'), 'Token de seguridad caducado: recarga la página.');
});

test('cae al texto por defecto cuando el código es desconocido', () => {
    assert.equal(messageFor('lo-que-sea', 'Error genérico.'), 'Error genérico.');
});

test('cae al texto por defecto cuando no hay código', () => {
    assert.equal(messageFor('', 'Error genérico.'), 'Error genérico.');
    assert.equal(messageFor(undefined, 'Error genérico.'), 'Error genérico.');
});

test('cubre los códigos que hoy emite el backend', () => {
    ['csrf', 'denied', 'not_found', 'disabled', 'dispatch', 'llm', 'invalid', 'unexpected']
        .forEach((code) => {
            assert.notEqual(messageFor(code), '', `falta mensaje para ${code}`);
        });
});
