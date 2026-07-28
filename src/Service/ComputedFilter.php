<?php

namespace OERManager\Service;

/**
 * Patrón obligatorio de los filtros computados (ADR-0013): resolver los ids del
 * conjunto completo con tope duro, evaluar el predicado sobre la lista entera y
 * paginar después.
 *
 * El defecto que corrige (D4) es cribar la página ya paginada, que da un total
 * aproximado y un número de filas variable entre páginas. El tope protege el
 * crecimiento del catálogo; cuando se alcanza, el resultado se marca como
 * truncado para que la UI pueda decirlo en vez de mentir en silencio.
 */
class ComputedFilter
{
    public const HARD_CAP = 2000;

    private int $cap;

    public function __construct(int $cap = self::HARD_CAP)
    {
        $this->cap = max(1, $cap);
    }

    /**
     * @param int[] $ids Conjunto completo, sin paginar
     * @param callable(int):bool $predicate
     * @return array{ids:int[],total:int,truncated:bool}
     */
    public function apply(array $ids, callable $predicate, int $page, int $perPage): array
    {
        $truncated = count($ids) > $this->cap;
        $ids = array_slice(array_values($ids), 0, $this->cap);

        $matching = array_values(array_filter($ids, $predicate));

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        return [
            'ids' => array_slice($matching, $offset, $perPage),
            'total' => count($matching),
            'truncated' => $truncated,
        ];
    }
}
