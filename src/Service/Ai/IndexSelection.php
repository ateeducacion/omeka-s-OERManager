<?php

namespace OERManager\Service\Ai;

/**
 * Mapea los índices (1-based) devueltos por el LLM a ids de la lista CERRADA de
 * candidatos que se le mostró, por POSICIÓN. Esto evita la ambigüedad de títulos
 * repetidos (revisión adversaria, finding #4: dos candidatos homónimos ya no
 * colapsan) y es robusto al truncado de la respuesta (finding #1). Los índices
 * fuera de rango se descartan; el destino se revalida después en RecatalogService
 * (defensa en profundidad).
 */
trait IndexSelection
{
    /**
     * @param int[] $indices índices 1-based
     * @param array<int,array{id:int,title:string}> $candidates lista 0-based
     * @return int[]
     */
    private function mapIndicesToIds(array $indices, array $candidates): array
    {
        $candidates = array_values($candidates);
        $ids = [];
        foreach ($indices as $index) {
            $position = $index - 1;
            if (!isset($candidates[$position])) {
                continue;
            }
            $id = (int) $candidates[$position]['id'];
            if (!in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
