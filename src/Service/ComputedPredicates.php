<?php

declare(strict_types=1);

namespace OERManager\Service;

use OERManager\Service\Governance\AlignmentStatusValue;

/**
 * Resuelve qué predicados computados pide una query de la vista maestra.
 *
 * Existe para que el controlador tenga UNA sola rama computada: el bloque de
 * ADR-0013 (buscar con tope duro → evaluar predicado → paginar) es largo y
 * duplicarlo por filtro es cómo se propagan los defectos tipo D4.
 *
 * Un valor no reconocido se ignora en vez de tratarse como filtro: un barrido
 * de hasta 2 000 items es demasiado caro para dispararlo desde la URL.
 */
final class ComputedPredicates
{
    public const ALIGNMENT_PARTIAL = 'alignment_partial';
    public const INTEGRITY = 'integrity';

    /** Estados del semáforo que la UI ofrece (D-5: «error» no tiene productor). */
    public const INTEGRITY_OK = 'ok';
    public const INTEGRITY_WARNING = 'warning';

    /** @return list<string> */
    public static function activeKeys(array $query): array
    {
        $keys = [];

        $alignment = $query['alignment'] ?? '';
        if (is_string($alignment) && AlignmentStatusValue::PARTIAL === $alignment) {
            $keys[] = self::ALIGNMENT_PARTIAL;
        }

        $integrity = $query['integrity'] ?? '';
        if (is_string($integrity) && in_array($integrity, [self::INTEGRITY_OK, self::INTEGRITY_WARNING], true)) {
            $keys[] = self::INTEGRITY;
        }

        return $keys;
    }
}
