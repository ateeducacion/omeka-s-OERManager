<?php

namespace OERManager\Service\Ai;

/**
 * Mapea las etiquetas devueltas por el LLM a ids de la lista CERRADA de
 * candidatos que se le mostró (comparación por título, insensible a
 * mayúsculas/espacios). Las etiquetas que no correspondan a ningún candidato se
 * descartan: el LLM solo puede elegir de lo que se le ofreció, y el destino se
 * revalida después en RecatalogService (defensa en profundidad).
 */
trait LabelMatching
{
    /**
     * @param string[] $labels
     * @param array<int,array{id:int,title:string}> $candidates
     * @return int[]
     */
    private function mapLabelsToIds(array $labels, array $candidates): array
    {
        $byTitle = [];
        foreach ($candidates as $candidate) {
            $byTitle[$this->normalize((string) $candidate['title'])] = (int) $candidate['id'];
        }
        $ids = [];
        foreach ($labels as $label) {
            $key = $this->normalize($label);
            if (isset($byTitle[$key]) && !in_array($byTitle[$key], $ids, true)) {
                $ids[] = $byTitle[$key];
            }
        }
        return $ids;
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim($title));
    }
}
