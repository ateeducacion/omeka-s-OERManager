<?php

namespace OERManager\Service\Ai;

/**
 * Clasificador de un recurso a partir de su contenido textual: devuelve, por
 * property RDF de alineamiento, los ids de item-término propuestos. La IA
 * propone; el curador confirma (ADR-0007). Implementado por CurricularClassifier
 * (cascada jerárquica) y TagClassifier (ejes en un único prompt).
 */
interface ClassifierInterface
{
    /**
     * @return array<string,int[]> property RDF => ids de item-término propuestos
     *   (solo las dimensiones con propuesta; las vacías se omiten).
     */
    public function classify(string $content): array;
}
