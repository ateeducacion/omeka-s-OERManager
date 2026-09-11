<?php

declare(strict_types=1);

namespace OERManager\Service\Governance;

/**
 * Texto mostrable de un valor RDF: su etiqueta y, si no la tiene, su URI.
 *
 * Existe por ADR-0019: la licencia se guarda como URI, y en el core 4.2 un
 * valor `uri`/`customvocab` convierte a cadena su `value()` —la etiqueta—, que
 * puede ir vacía. Leerlo con `(string) $value` hacía pasar un REA licenciado por
 * «sin licencia». Pura para poder probarse en el host.
 */
final class ValueText
{
    public static function of(?string $label, ?string $uri): string
    {
        $label = trim((string) $label);
        return '' !== $label ? $label : trim((string) $uri);
    }
}
